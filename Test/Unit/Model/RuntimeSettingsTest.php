<?php

declare(strict_types=1);

namespace Hachitechs\Hideguard\Test\Unit\Model;

use Hachitechs\Hideguard\Model\Config\RuntimeSettings;
use Hachitechs\HideguardPhp\Configuration\{RateLimits, CircuitBreakerPolicy};
use Hachitechs\HideguardPhp\Exceptions\GuardException;
use Magento\Framework\App\DeploymentConfig;
use Magento\Store\Model\{Store, StoreManagerInterface};
use PHPUnit\Framework\TestCase;

class RuntimeSettingsTest extends TestCase
{
    private function runtime(array $values = []): RuntimeSettings
    {
        $deployment = $this->createStub(DeploymentConfig::class);
        $deployment->method('get')->willReturnCallback(fn($path, $default = null) =>
            array_key_exists($path, $values) ? $values[$path] : $default);
        $store = $this->createStub(Store::class);
        $store->method('getId')->willReturn(7);
        $stores = $this->createStub(StoreManagerInterface::class);
        $stores->method('getStore')->willReturn($store);
        return new RuntimeSettings($deployment, $stores);
    }

    public function testDefaultsAndCircuitMatchTheTestedPackageDefaults(): void
    {
        $runtime = $this->runtime();
        self::assertEquals(new RateLimits(), $runtime->rateLimits());
        self::assertEquals(new CircuitBreakerPolicy(), $runtime->circuitPolicy());
        self::assertTrue($runtime->circuit()['enabled']);
        $di = simplexml_load_file(dirname(__DIR__, 3) . '/etc/di.xml');
        $virtual = $di->xpath('virtualType[@name="HachitechsHideguardHttpTransport"]')[0];
        $timeout = (int)$virtual->arguments->xpath('argument[@name="timeoutSeconds"]')[0];
        self::assertGreaterThan($timeout, RuntimeSettings::CIRCUIT_PROBE_LEASE_SECONDS);
    }

    public function testPartialGlobalAndStoreOverridesPreserveDefaultsAndScopeResolution(): void
    {
        $runtime = $this->runtime([
            'hideguard/limits' => ['scope_requests' => '000900', 'session_retention_seconds' => 86400],
            'hideguard/store_limits' => [7 => ['scope_requests' => 1200]],
        ]);
        self::assertSame(900, $runtime->rateLimits()->scopeRequests);
        self::assertSame(1200, $runtime->rateLimits(7)->scopeRequests);
        self::assertSame(900, $runtime->rateLimits(8)->scopeRequests);
        self::assertSame(86400, $runtime->rateLimits(7)->sessionRetentionSeconds);
        self::assertSame(10000, $runtime->rateLimits(7)->sessionCapacity);
        self::assertSame(60, $runtime->rateLimits(7)->windowSeconds);
        self::assertSame(7, $runtime->storeIdForScope('store', null));
        self::assertSame(7, $runtime->storeIdForScope('stores', 'english'));
        self::assertSame(7, $runtime->storeIdForScope('stores', '0007'));
        self::assertNull($runtime->storeIdForScope('websites', 7));
        self::assertNull($runtime->storeIdForScope('default', null));
    }

    public function testDeploymentMapsAreReadOncePerRequestAndRefreshedAfterReset(): void
    {
        $deployment = $this->createMock(DeploymentConfig::class);
        $generation = 1;
        $deployment->expects(self::exactly(4))->method('get')->willReturnCallback(
            function ($path, $default = null) use (&$generation) {
                return $path === 'hideguard/limits' ? ['scope_requests' => $generation * 600] : [];
            }
        );
        $runtime = new RuntimeSettings($deployment, $this->createStub(StoreManagerInterface::class));
        self::assertSame(600, $runtime->rateLimits(1)->scopeRequests);
        self::assertSame(600, $runtime->rateLimits(2)->scopeRequests);
        self::assertSame(600, $runtime->rateLimits(1)->scopeRequests);
        $generation = 2;
        self::assertSame(600, $runtime->rateLimits(1)->scopeRequests);
        $runtime->_resetState();
        self::assertSame(1200, $runtime->rateLimits(1)->scopeRequests);
    }

    public function testEveryOverrideHasPositiveIntegerValidationAndMaximum(): void
    {
        foreach (RuntimeSettings::LIMIT_FIELDS as $field => $property) {
            self::assertSame(
                2147483647,
                $this->runtime(['hideguard/limits' => [$field => '2147483647']])->rateLimits()->$property
            );
            foreach (
                [null, 0, '0', '000', -1, '-1', 1.5, '1.5', '1e3', true, false, '', '2147483648',
                str_repeat('9', 100), []] as $invalid
            ) {
                try {
                    $this->runtime(['hideguard/limits' => [$field => $invalid]])->rateLimits();
                    self::fail('Invalid value accepted: ' . $field);
                } catch (GuardException $error) {
                    self::assertStringContainsString($field, $error->getMessage());
                }
            }
        }
    }

    /** @dataProvider malformedMaps */
    public function testMalformedOrUnknownSettingsFailExplicitly(array $values): void
    {
        $this->expectException(GuardException::class);
        $this->runtime($values)->rateLimits(1);
    }

    public static function malformedMaps(): array
    {
        return [
            [['hideguard/limits' => false]], [['hideguard/limits' => '600']],
            [['hideguard/limits' => ['rate_window_seconds' => 90]]],
            [['hideguard/limits' => ['circuit_enabled' => false]]],
            [['hideguard/limits' => ['scopeRequests' => 900]]],
            [['hideguard/store_limits' => 'bad']], [['hideguard/store_limits' => [0 => []]]],
            [['hideguard/store_limits' => ['01' => []]]], [['hideguard/store_limits' => [65536 => []]]],
            [['hideguard/store_limits' => ['english' => []]]], [['hideguard/store_limits' => [7 => null]]],
            [['hideguard/store_limits' => [7 => ['unknown' => 123]]]],
            [['hideguard/store_limits' => [7 => ['scope_requests' => false]]]],
        ];
    }
}
