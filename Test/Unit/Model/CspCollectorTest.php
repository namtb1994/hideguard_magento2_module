<?php

declare(strict_types=1);

namespace Hachitechs\Hideguard\Test\Unit\Model;

use Hachitechs\HideguardPhp\Configuration\ProtectionPolicy;
use Hachitechs\Hideguard\Model\Config;
use Hachitechs\Hideguard\Model\CspCollector;
use Magento\Csp\Model\Policy\FetchPolicy;
use Magento\Framework\App\State;
use PHPUnit\Framework\TestCase;

class CspCollectorTest extends TestCase
{
    private function appState(string $area = 'frontend'): State
    {
        $state = $this->createMock(State::class);
        $state->method('getAreaCode')->willReturn($area);
        return $state;
    }

    public function testActiveEndpointIsAddedWithoutDiscardingExistingPolicies(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('policy')->willReturn(new ProtectionPolicy(
            enabled: true, publicKey: 'site-key', endpointUrl: 'https://custom.hideguard.example:8443'
        ));
        $existing = new FetchPolicy('img-src', false, ['https://images.example']);
        $policies = (new CspCollector($config, $this->appState()))->collect([$existing]);
        self::assertSame($existing, $policies[0]);
        self::assertSame(['img-src', 'script-src', 'connect-src'], array_map(fn($policy) => $policy->getId(), $policies));
        foreach (array_slice($policies, 1) as $policy) {
            self::assertSame(['https://custom.hideguard.example:8443'], $policy->getHostSources());
            self::assertSame([], $policy->getSchemeSources());
            self::assertFalse($policy->isInlineAllowed());
            self::assertFalse($policy->isEvalAllowed());
        }
    }

    public function testDisabledProtectionDoesNotAddOrigins(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('policy')->willReturn(new ProtectionPolicy());
        $policies = [new FetchPolicy('img-src')];
        self::assertSame($policies, (new CspCollector($config, $this->appState()))->collect($policies));
    }

    public function testAdminPoliciesDoNotReceiveAStorefrontOrigin(): void
    {
        $config = $this->createMock(Config::class);
        $config->expects(self::never())->method('policy');
        $policies = [new FetchPolicy('script-src')];
        self::assertSame($policies, (new CspCollector($config, $this->appState('adminhtml')))->collect($policies));
    }
}
