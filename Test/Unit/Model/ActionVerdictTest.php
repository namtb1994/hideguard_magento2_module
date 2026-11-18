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
    private function fixture(
        string $area = 'frontend',
        bool $enabled = true,
        string $response = 'allow',
        ?HttpTransport $transportOverride = null,
        ?Config $configOverride = null
    ): array {
        $policy = new ProtectionPolicy(enabled: $enabled, publicKey: 'fixture-key');
        $config = $configOverride ?? $this->createMock(Config::class);
        if ($configOverride === null) {
            $config->method('policy')->willReturn($policy);
            $config->method('settings')->willReturn(['enabled' => $enabled]);
            $config->method('apiKey')->willReturn('fixture-server-key');
        }
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
        $transport = $transportOverride ?? new class ($count, $response) implements HttpTransport {
            public function __construct(private object $count, private string $verdict)
            {
            }
            public function post(string $url, array $headers, string $body): HttpResponse
            {
                $this->count->requests++;
                return new HttpResponse(200, json_encode(['visitorId' => 'visitor', 'verdict' => $this->verdict,
                    'score' => 0, 'reasons' => []], JSON_THROW_ON_ERROR));
            }
        };
        $clients = $this->createMock(VerdictClientFactory::class);
        $clients->method('create')->willReturnCallback(function (array $arguments) use ($transport, $count) {
            $count->baseUrl = $arguments['baseUrl'];
            return new VerdictClient($arguments['baseUrl'], $arguments['apiKey'], $transport);
        });
        $transports = $this->createMock(DebugTransportFactory::class);
        $transports->method('create')->willReturn($this->createMock(DebugTransport::class));
        $app = $this->createMock(State::class);
        $app->method('getAreaCode')->willReturn($area);
        return [new ActionVerdict($config, $guard, $storage, $identity, $clients, $transports, $app, new NullLogger()),
            $count, $current, $storage, $guard];
    }

    /** @dataProvider modeEndpoints */
    public function testLegacyModesStillCallFreshBackendVerdictsAtTheLiveHost(string $mode, string $host): void
    {
        $scope = $this->createStub(\Magento\Framework\App\Config\ScopeConfigInterface::class);
        $values = ['general/enabled' => '1', 'general/environment' => $mode,
            'production/public_key' => 'fixture-key', 'production/signature_public_key' => 'fixture-signature',
            'production/api_key' => 'encrypted-fixture', $mode . '/endpoint_url' => 'https://legacy.example'];
        $scope->method('getValue')->willReturnCallback(
            fn($path) => $values[substr($path, strlen(Config::SECTION) + 1)] ?? null
        );
        $deployment = $this->createStub(\Magento\Framework\App\DeploymentConfig::class);
        $deployment->method('get')->willReturnCallback(fn($key, $default = null) => $default);
        $encryptor = $this->createStub(\Magento\Framework\Encryption\EncryptorInterface::class);
        $encryptor->method('decrypt')->willReturn('server-fixture-key');
        $config = new Config($scope, $encryptor, new \Hachitechs\Hideguard\Model\Config\RuntimeSettings(
            $deployment,
            $this->createStub(\Magento\Store\Model\StoreManagerInterface::class)
        ));
        [$action, $count] = $this->fixture(configOverride: $config);
        foreach (['form', 'checkout', 'payment', 'order'] as $index => $operation) {
            $action->runBrowser(1, $operation, fn() => true);
            self::assertSame($host, $count->baseUrl);
            self::assertSame($index + 1, $count->requests);
        }
    }

    public static function modeEndpoints(): array
    {
        return [['development', 'https://hideguard.io'], ['staging', 'https://hideguard.io'],
            ['production', 'https://hideguard.io']];
    }

    public function testNativeCartPredispatchAndServiceUseOneFreshApiCallPerInvocation(): void
    {
        [$action, $count] = $this->fixture();
        $request = $this->createStub(\Magento\Framework\App\Request\Http::class);
        $request->method('getFullActionName')->willReturn('checkout_cart_add');
        $request->method('isPost')->willReturn(true);
        $rules = $this->createStub(\Hachitechs\Hideguard\Model\PageRules::class);
        $rules->method('protects')->willReturn(true);
        $config = $this->createStub(Config::class);
        $config->method('settings')->willReturn(['enabled' => true]);
        $store = $this->createStub(\Magento\Store\Model\Store::class);
        $store->method('getId')->willReturn(1);
        $stores = $this->createStub(\Magento\Store\Model\StoreManagerInterface::class);
        $stores->method('getStore')->willReturn($store);
        (new \Hachitechs\Hideguard\Observer\CheckAction(
            $action,
            $config,
            $stores,
            $this->createStub(\Magento\Framework\App\ActionFlag::class),
            $rules
        ))->execute(
            new \Magento\Framework\Event\Observer(['event' => new \Magento\Framework\Event([
                    'name' => 'controller_action_predispatch', 'request' => $request,
                    'controller_action' => (new \ReflectionClass(\Magento\Checkout\Controller\Cart\Add::class))
                        ->newInstanceWithoutConstructor(),
                ])])
        );
        $quote = $this->getMockBuilder(Quote::class)->disableOriginalConstructor()->onlyMethods([])->getMock();
        $quote->setId(1)->setStoreId(1);
        $plugin = new \Hachitechs\Hideguard\Plugin\QuoteAccess($action);
        for ($i = 1; $i <= 2; $i++) {
            $called = 0;
            self::assertSame('native', $plugin->aroundAddProduct($quote, function () use ($action, $quote, &$called) {
                $action->assertQuote($quote, 'cart');
                $called++;
                return 'native';
            }));
            self::assertSame(1, $called);
            self::assertSame($i, $count->requests);
        }
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
                $action->runBrowser(1, 'login', function () use (&$called) {
                    $called = true;
                });
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

    public function testOpenCircuitRevokesOldPassAndStopsNativeOrderWithoutHttp(): void
    {
        $store = $this->createMock(\Hachitechs\HideguardPhp\Contracts\CircuitBreakerStoreContract::class);
        $store->expects(self::once())->method('acquire')
            ->willThrowException(new \Hachitechs\HideguardPhp\Exceptions\ApiException('Open', 503));
        $nativeHttp = $this->createMock(HttpTransport::class);
        $nativeHttp->expects(self::never())->method('post');
        $clock = new \Hachitechs\HideguardPhp\Support\SystemClock();
        $transport = new \Hachitechs\HideguardPhp\Http\CircuitBreakingTransport(
            $nativeHttp,
            $store,
            $clock,
            new \Hachitechs\HideguardPhp\Configuration\CircuitBreakerPolicy(),
            '1'
        );
        [$action, , , , $guard] = $this->fixture(transportOverride: $transport);
        $guard->expects(self::once())->method('report')->with(self::anything(), self::anything(), [], 'attempt');
        $guard->expects(self::never())->method('bindGrant');
        $quote = $this->getMockBuilder(Quote::class)->disableOriginalConstructor()->onlyMethods([])->getMock();
        $quote->setId(1)->setStoreId(1);
        $called = false;
        try {
            $action->runQuote($quote, 'order', function () use (&$called) {
                $called = true;
            });
            self::fail('An old pass allowed order submission during outage.');
        } catch (LocalizedException) {
            self::assertFalse($called);
            self::assertSame('error', $action->denial()['status']);
        }
    }

    public function testBlockStopsNativeOrderAndPaymentBeforeInvocation(): void
    {
        [$action, $count] = $this->fixture(response: 'block');
        $quote = $this->getMockBuilder(Quote::class)->disableOriginalConstructor()->onlyMethods([])->getMock();
        $quote->setId(1)->setStoreId(1);
        $called = false;
        try {
            $action->runQuote($quote, 'order', function () use (&$called) {
                $called = true;
            });
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
