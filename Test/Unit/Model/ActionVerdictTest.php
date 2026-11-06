<?php

declare(strict_types=1);

namespace Hachitechs\Hideguard\Test\Unit\Model;

use Hachitechs\Hideguard\Model\ActionVerdict;
use Hachitechs\Hideguard\Model\BrowserIdentity;
use Hachitechs\Hideguard\Model\Config;
use Hachitechs\Hideguard\Model\DebugTransport;
use Hachitechs\Hideguard\Model\DebugTransportFactory;
use Hachitechs\Hideguard\Model\StateRepository;
use Hachitechs\HideguardPhp\Access\AccessDecision;
use Hachitechs\HideguardPhp\Access\BrowserState;
use Hachitechs\HideguardPhp\Configuration\ProtectionPolicy;
use Hachitechs\HideguardPhp\Contracts\GuardContract;
use Hachitechs\HideguardPhp\Contracts\HttpTransport;
use Hachitechs\HideguardPhp\Http\HttpResponse;
use Hachitechs\HideguardPhp\VerdictClient;
use Hachitechs\HideguardPhp\VerdictClientFactory;
use Magento\Framework\App\State;
use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Model\Quote;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class ActionVerdictTest extends TestCase
{
    private function fixture(string $area = 'frontend', bool $enabled = true, string $response = 'allow'): array
    {
        $policy = new ProtectionPolicy(enabled: $enabled, publicKey: 'fixture-key');
        $config = $this->createMock(Config::class);
        $config->method('policy')->willReturn($policy);
        $config->method('settings')->willReturn(['enabled' => $enabled]);
        $config->method('apiKey')->willReturn('fixture-server-key');
        $identity = $this->createMock(BrowserIdentity::class);
        $identity->method('hash')->willReturn('browser');
        $identity->method('actorId')->willReturn(null);
        $state = new BrowserState('browser', visitorId: 'visitor', verificationId: 'attempt');
        $storage = $this->createMock(StateRepository::class);
        $storage->method('session')->willReturn($state);
        $storage->method('resource')->willReturn($state);
        $guard = $this->createMock(GuardContract::class);
        $allowed = AccessDecision::allowed(time() + 300, 300, 'verified', 'attempt');
        $current = (object)['decision' => $allowed];
        $guard->method('inspect')->willReturnCallback(fn() => $current->decision);
        $guard->method('preflight')->willReturn($allowed);
        $guard->method('report')->willReturn($response === 'block' ? AccessDecision::blocked(time() + 900, time()) : $allowed);
        $count = (object)['requests' => 0];
        $transport = new class($count, $response) implements HttpTransport {
            public function __construct(private object $count, private string $verdict) {}
            public function post(string $url, array $headers, string $body): HttpResponse
            {
                $this->count->requests++;
                return new HttpResponse(200, json_encode(['visitorId' => 'visitor', 'verdict' => $this->verdict,
                    'score' => 0, 'reasons' => []], JSON_THROW_ON_ERROR));
            }
        };
        $clients = $this->createMock(VerdictClientFactory::class);
        $clients->method('create')->willReturn(new VerdictClient('https://fixture.example', 'fixture-key', $transport));
        $transports = $this->createMock(DebugTransportFactory::class);
        $transports->method('create')->willReturn($this->createMock(DebugTransport::class));
        $app = $this->createMock(State::class);
        $app->method('getAreaCode')->willReturn($area);
        return [new ActionVerdict($config, $guard, $storage, $identity, $clients, $transports, $app, new NullLogger()),
            $count, $current, $storage, $guard];
    }

    public function testNestedSameActionSharesVerdictButSequentialAttemptsAlwaysRefresh(): void
    {
        [$action, $count] = $this->fixture();
        $action->runBrowser(1, 'login', fn() => $action->runBrowser(1, 'login', fn() => 'token'));
        self::assertSame(1, $count->requests);
        $action->runBrowser(1, 'login', fn() => 'another-token');
        self::assertSame(2, $count->requests);
        $action->runBrowser(1, 'register', fn() => true);
        self::assertSame(3, $count->requests);
    }

    public function testRevocationDuringAnInvocationStopsTheNestedSideEffect(): void
    {
        [$action, $count, $current] = $this->fixture();
        $called = false;
        try {
            $action->runBrowser(1, 'login', function () use ($action, $current, &$called) {
                $current->decision = AccessDecision::pending();
                $action->runBrowser(1, 'login', function () use (&$called) { $called = true; });
            });
            self::fail('A revoked operation must be denied');
        } catch (LocalizedException) {
            self::assertFalse($called);
            self::assertSame('verification_required', $action->denial()['status']);
            self::assertSame(1, $count->requests);
        }
    }

    public function testDifferentResourcesAndActionsCannotBorrowAnOperationVerdict(): void
    {
        [$action, $count] = $this->fixture();
        $quoteA = $this->getMockBuilder(Quote::class)->disableOriginalConstructor()->onlyMethods([])->getMock();
        $quoteA->setId(1)->setStoreId(1);
        $quoteB = clone $quoteA;
        $quoteB->setId(2);
        $action->runQuote($quoteA, 'order', function () use ($action, $quoteA, $quoteB) {
            $action->assertQuote($quoteA, 'order');
            $action->assertQuote($quoteB, 'order');
            $action->assertQuote($quoteA, 'payment');
        });
        self::assertSame(3, $count->requests);
        $action->runQuote($quoteA, 'order', fn() => true);
        self::assertSame(4, $count->requests);
    }

    /** @dataProvider areas */
    public function testPublicServicesCheckWhileAdminCronAndDisabledBypass(string $area, bool $enabled, bool $checked): void
    {
        [$action, $count] = $this->fixture($area, $enabled);
        self::assertSame('native', $action->runBrowser(1, 'register', fn() => 'native'));
        self::assertSame($checked ? 1 : 0, $count->requests);
    }

    public static function areas(): array
    {
        return [['frontend', true, true], ['webapi_rest', true, true], ['webapi_soap', true, true],
            ['graphql', true, true], ['adminhtml', true, false], ['crontab', true, false], ['frontend', false, false]];
    }

    public function testBlockStopsNativeOrderAndPaymentBeforeInvocation(): void
    {
        [$action, $count] = $this->fixture(response: 'block');
        $quote = $this->getMockBuilder(Quote::class)->disableOriginalConstructor()->onlyMethods([])->getMock();
        $quote->setId(1)->setStoreId(1);
        $called = false;
        try {
            $action->runQuote($quote, 'order', function () use (&$called) { $called = true; });
            self::fail('Block must stop order submission');
        } catch (LocalizedException) {
            self::assertFalse($called);
            self::assertSame('blocked', $action->denial()['status']);
        }
        self::assertSame(1, $count->requests);
    }

    public function testHeadlessQuoteCannotUseAnUnboundBrowserIdentity(): void
    {
        [$action, $count, , $storage] = $this->fixture('graphql');
        $quote = $this->getMockBuilder(Quote::class)->disableOriginalConstructor()->onlyMethods([])->getMock();
        $quote->setStoreId(1);
        $storage->expects(self::never())->method('session');
        try {
            $action->runQuote($quote, 'order', fn() => self::fail('Unbound order ran'));
            self::fail('Missing binding must be denied');
        } catch (LocalizedException) {
            self::assertSame(0, $count->requests);
            self::assertSame('verification_required', $action->denial()['status']);
        }
    }
}
