<?php

declare(strict_types=1);

namespace Hachitechs\Hideguard\Test\Unit\Model;

use Hachitechs\Hideguard\Model\{Access, Config, BrowserIdentity, DebugTransport};
use Hachitechs\HideguardPhp\Contracts\{GuardContract, BatchGuardContract, CircuitBreakerStoreContract, HttpTransport};
use Hachitechs\HideguardPhp\Configuration\{ProtectionPolicy, CircuitBreakerPolicy};
use Hachitechs\HideguardPhp\Access\CleanupResult;
use Hachitechs\HideguardPhp\Exceptions\ApiException;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Store\Model\{Store, StoreManagerInterface};
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class HighTrafficIntegrationTest extends TestCase
{
    private function runtime(): \Hachitechs\Hideguard\Model\Config\RuntimeSettings
    {
        $deployment = $this->createStub(\Magento\Framework\App\DeploymentConfig::class);
        $deployment->method('get')->willReturnCallback(fn($key, $default = null) => $default);
        return new \Hachitechs\Hideguard\Model\Config\RuntimeSettings($deployment,
            $this->createStub(\Magento\Store\Model\StoreManagerInterface::class));
    }

    public function testCleanupContinuesOtherStoresAndReportsFailureAfterwards(): void
    {
        $stores = $this->createStub(StoreManagerInterface::class);
        $one = $this->createStub(Store::class);
        $one->method('getId')->willReturn(1);
        $two = $this->createStub(Store::class);
        $two->method('getId')->willReturn(2);
        $stores->method('getStores')->willReturn([$one, $two]);
        $config = $this->createStub(Config::class);
        $config->method('policy')->willReturn(new ProtectionPolicy());
        $visited = [];
        $guard = $this->getMockBuilder(GuardAndBatchFixture::class)->getMock();
        $guard->method('supportsBatchCleanup')->willReturn(true);
        $guard->expects(self::never())->method('cleanup');
        $guard->expects(self::exactly(2))->method('cleanupBatch')->willReturnCallback(function ($id) use (&$visited) {
            $visited[] = $id;
            if ($id === '1') {
                throw new \RuntimeException('busy');
            }
            return new CleanupResult(0, 0, 0, false);
        });
        try {
            (new Access($config, $guard, $this->createStub(BrowserIdentity::class), $stores))->cleanup();
            self::fail('Cron failure must remain visible.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('stores: 1', $error->getMessage());
        }
        self::assertSame(['1', '2'], $visited);
    }

    public function testCleanupBudgetStopsAtTenAndBrokenLoggerCannotStopNextStore(): void
    {
        $stores = $this->createStub(StoreManagerInterface::class);
        $one = $this->createStub(Store::class);
        $one->method('getId')->willReturn(1);
        $two = $this->createStub(Store::class);
        $two->method('getId')->willReturn(2);
        $stores->method('getStores')->willReturn([$one, $two]);
        $config = $this->createStub(Config::class);
        $config->method('policy')->willReturn(new ProtectionPolicy());
        $guard = $this->getMockBuilder(GuardAndBatchFixture::class)->getMock();
        $guard->method('supportsBatchCleanup')->willReturn(true);
        $guard->expects(self::exactly(11))->method('cleanupBatch')->willReturnCallback(
            fn($id) => new CleanupResult(0, 0, $id === '1' ? 500 : 0, $id === '1')
        );
        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $logger->expects(self::once())->method('notice')->willThrowException(new \RuntimeException('disk full'));
        (new Access($config, $guard, $this->createStub(BrowserIdentity::class), $stores, $logger))->cleanup();
    }

    public function testModuleCircuitOpenPreventsTransportRegardlessOfDebugFlag(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('circuitSettings')->with(7)->willReturn(['enabled' => true, 'policy' => new CircuitBreakerPolicy()]);
        $store = $this->createMock(CircuitBreakerStoreContract::class);
        $store->method('acquire')->willThrowException(new ApiException('Open', 503));
        $transport = $this->createMock(HttpTransport::class);
        $transport->expects(self::never())->method('post');
        $this->expectException(ApiException::class);
        (new DebugTransport($transport, $config, new NullLogger(), 7, $store))->post('https://fixture', [], '{}');
    }

    public function testCircuitConstantsIgnoreLegacyStoreOverridesAndKeepFingerprintIndependent(): void
    {
        $scope = $this->createStub(ScopeConfigInterface::class);
        $scope->method('getValue')->willReturnCallback(fn($path, $type, $id) =>
            $id === 7 && $path === Config::SECTION . '/general/circuit_threshold' ? '00012' : null);
        $config = new Config($scope, $this->createStub(EncryptorInterface::class), $this->runtime());
        self::assertTrue($config->circuitSettings(1)['enabled']);
        self::assertSame(5, $config->circuitSettings(1)['policy']->threshold);
        self::assertSame(5, $config->circuitSettings(7)['policy']->threshold);
        self::assertSame($config->policy(1)->fingerprint(), $config->policy(7)->fingerprint());
        self::assertSame(15, $config->circuitSettings(7)['policy']->probeLeaseSeconds);
    }
}

abstract class GuardAndBatchFixture implements GuardContract, BatchGuardContract
{
}
