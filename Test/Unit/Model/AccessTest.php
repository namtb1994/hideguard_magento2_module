<?php

declare(strict_types=1);

namespace Hachitechs\Hideguard\Test\Unit\Model;

use Hachitechs\HideguardPhp\Access\AccessContext;
use Hachitechs\HideguardPhp\Access\AccessDecision;
use Hachitechs\HideguardPhp\Access\ResourceRef;
use Hachitechs\HideguardPhp\Configuration\ProtectionPolicy;
use Hachitechs\HideguardPhp\Contracts\GuardContract;
use Hachitechs\Hideguard\Model\Access;
use Hachitechs\Hideguard\Model\BrowserIdentity;
use Hachitechs\Hideguard\Model\Config;
use Magento\Quote\Model\Quote;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

class AccessTest extends TestCase
{
    public function testPageInspectionMapsGuestWithoutGrantingOrConsumingRate(): void
    {
        $policy = new ProtectionPolicy(enabled: true, publicKey: 'key');
        $config = $this->createMock(Config::class);
        $config->method('policy')->with(1)->willReturn($policy);
        $identity = $this->createMock(BrowserIdentity::class);
        $identity->method('hash')->willReturn(str_repeat('a', 64));
        $identity->method('actorId')->willReturn(null);
        $guard = $this->createMock(GuardContract::class);
        $guard->expects(self::never())->method('preflight');
        $guard->expects(self::never())->method('report');
        $guard->expects(self::once())->method('inspect')->with(self::callback(
            fn(AccessContext $context) => $context->scopeId === '1'
                && $context->browserId === str_repeat('a', 64) && $context->actorId === null
        ), $policy)->willReturn(AccessDecision::pending());
        $access = new Access($config, $guard, $identity, $this->createMock(StoreManagerInterface::class));
        self::assertSame(['status' => 'verification_required'], $access->pageStatus(1));
    }

    public function testBackendQuoteOwnerComesFromTrustedQuoteAndDoesNotRequireBrowserSession(): void
    {
        $policy = new ProtectionPolicy(enabled: true, publicKey: 'key', accessPoints: ['complete_order']);
        $config = $this->getMockBuilder(Config::class)->disableOriginalConstructor()->onlyMethods(['policy'])->getMock();
        $config->expects(self::once())->method('policy')->with(2)->willReturn($policy);
        $identity = $this->createMock(BrowserIdentity::class);
        $identity->expects(self::never())->method('hash');
        $guard = $this->createMock(GuardContract::class);
        $guard->expects(self::once())->method('assertResourceAllowed')->with('2', $policy, self::callback(
            fn(ResourceRef $resource) => $resource->type === 'quote' && $resource->id === '123'
                && $resource->actorId === '42'
        ));
        $quote = $this->getMockBuilder(Quote::class)->disableOriginalConstructor()->onlyMethods([])->getMock();
        $quote->setId(123)->setStoreId(2)->setCustomerId(42);
        (new Access($config, $guard, $identity, $this->createMock(StoreManagerInterface::class)))
            ->assertQuote($quote, 'complete_order');
    }

    public function testAdminPresetsAndXmlDefaultMatchCore(): void
    {
        $xml = simplexml_load_file(dirname(__DIR__, 3) . '/etc/config.xml');
        self::assertSame(ProtectionPolicy::DEFAULT_PASS_DURATION,
            (int)$xml->default->hachitech_hideguard->general->pass_duration);
        foreach ([
            \Hachitechs\Hideguard\Model\Config\Source\PassDuration::class => ProtectionPolicy::PASS_DURATIONS,
            \Hachitechs\Hideguard\Model\Config\Source\Duration::class => ProtectionPolicy::BLOCK_DURATIONS,
            \Hachitechs\Hideguard\Model\Config\Source\Environment::class => ProtectionPolicy::ENVIRONMENTS,
        ] as $source => $expected) {
            self::assertSame($expected, array_column((new $source())->toOptionArray(), 'value'));
        }
    }
}
