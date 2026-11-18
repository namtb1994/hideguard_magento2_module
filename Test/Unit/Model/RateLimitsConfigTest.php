<?php

declare(strict_types=1);

namespace Hachitechs\Hideguard\Test\Unit\Model;

use Hachitechs\Hideguard\Model\Config;
use Hachitechs\Hideguard\Model\Config\RuntimeSettings;
use Hachitechs\Hideguard\Plugin\ValidateSettings;
use Hachitechs\HideguardPhp\Access\BrowserState;
use Hachitechs\HideguardPhp\Configuration\RateLimits;
use Magento\Framework\App\{DeploymentConfig, Config\ScopeConfigInterface};
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

class RateLimitsConfigTest extends TestCase
{
    private function config(array $deploymentValues = []): Config
    {
        $deployment = $this->createStub(DeploymentConfig::class);
        $deployment->method('get')->willReturnCallback(
            fn($path, $default = null) => $deploymentValues[$path] ?? $default
        );
        $runtime = new RuntimeSettings($deployment, $this->createStub(StoreManagerInterface::class));
        $scope = $this->createStub(ScopeConfigInterface::class);
        $scope->method('getValue')->willReturnCallback(function ($path) {
            $field = substr($path, strlen(Config::SECTION . '/general/'));
            self::assertNotContains(
                $field,
                RuntimeSettings::LEGACY_ADMIN_FIELDS,
                'Legacy technical DB reads must stop.'
            );
            return null;
        });
        return new Config($scope, $this->createStub(EncryptorInterface::class), $runtime);
    }

    public function testDefaultsKeepOldFingerprintAndIgnoreSettingsInjection(): void
    {
        $config = $this->config();
        $settings = $config->settings(1);
        $before = new \Hachitechs\HideguardPhp\Configuration\ProtectionPolicy(environment: Config::ENVIRONMENT, accessPoints: Config::ACCESS_POINTS);
        self::assertEquals(new RateLimits(), $config->policy(1)->limits);
        self::assertSame($before->fingerprint(), $config->policy(1)->fingerprint());
        $settings['limits'] = ['scopeRequests' => 999999, 'windowSeconds' => 123];
        $settings['circuit'] = ['enabled' => false, 'threshold' => 1];
        self::assertEquals(new RateLimits(), $config->toPolicy($settings)->limits);
        self::assertSame($before->fingerprint(), $config->toPolicy($settings)->fingerprint());
        self::assertTrue($config->circuitSettings(1)['enabled']);
        self::assertSame(5, $config->circuitSettings(1)['policy']->threshold);
    }

    public function testGlobalStoreIsolationAndFingerprintRevocation(): void
    {
        $config = $this->config([
            'hideguard/limits' => ['scope_requests' => '900'],
            'hideguard/store_limits' => [7 => ['scope_requests' => '000123', 'session_capacity' => 456]],
        ]);
        self::assertSame(900, $config->policy(1)->limits->scopeRequests);
        self::assertSame(123, $config->policy(7)->limits->scopeRequests);
        self::assertSame(456, $config->policy(7)->limits->sessionCapacity);
        self::assertSame(60, $config->policy(7)->limits->windowSeconds);
        self::assertSame(900, $config->policy(8)->limits->scopeRequests);
        self::assertNotSame($config->policy(7)->fingerprint(), $config->policy(8)->fingerprint());
        $settings = $config->settings(7);
        $settings['enabled'] = true;
        $settings['keys']['production']['publicKey'] = 'fixture';
        $settings['keys']['production']['signaturePublicKey'] = 'signature';
        $changed = $config->toPolicy($settings);
        $original = $this->config()->settings(7);
        $original['enabled'] = true;
        $original['keys']['production']['publicKey'] = 'fixture';
        $original['keys']['production']['signaturePublicKey'] = 'signature';
        $old = $this->config()->toPolicy($original);
        $state = new BrowserState('fixture', grantedUntil: 1300, verification: 'verified', policyHash: $old->fingerprint());
        self::assertTrue($old->grants($state, null, 1000));
        self::assertFalse($changed->grants($state, null, 1000));
    }

    public function testAdminLegacyPostCannotOverrideRuntimeAndBusinessInheritanceStillWorks(): void
    {
        $real = $this->config(['hideguard/store_limits' => [7 => ['scope_requests' => 123]]]);
        $spy = $this->getMockBuilder(Config::class)->disableOriginalConstructor()
            ->onlyMethods(['read', 'toPolicy'])->getMock();
        $spy->method('read')->willReturnCallback(function ($scope, $id) use ($real) {
            $settings = $real->read($scope, $id);
            $settings['blockDuration'] = $scope === 'websites' ? 900 : 3600;
            return $settings;
        });
        $captured = null;
        $spy->method('toPolicy')->willReturnCallback(function ($settings) use ($real, &$captured) {
            $captured = $settings;
            return $real->toPolicy($settings);
        });
        $fields = [];
        foreach (RuntimeSettings::LEGACY_ADMIN_FIELDS as $field) {
            $fields[$field] = ['value' => 'bad'];
        }
        $fields['block_duration'] = ['inherit' => '1', 'value' => 'bad'];
        $fields['_runtime_store_id'] = ['value' => 8];
        $subject = $this->getMockBuilder(\Magento\Config\Model\Config::class)
            ->disableOriginalConstructor()->onlyMethods([])->getMock();
        $subject->setData(['section' => Config::SECTION, 'store' => 7,
            'groups' => ['general' => ['fields' => $fields]]]);
        $store = $this->createStub(\Magento\Store\Model\Store::class);
        $store->method('getId')->willReturn(7);
        $store->method('getWebsiteId')->willReturn(2);
        $stores = $this->createStub(StoreManagerInterface::class);
        $stores->method('getStore')->willReturn($store);
        (new ValidateSettings($spy, $stores))->beforeSave($subject);
        self::assertSame(900, $captured['blockDuration']);
        self::assertSame(123, $captured['limits']['scopeRequests']);
        self::assertSame(7, $captured['_runtime_store_id']);
    }

    public function testTechnicalFieldsAreAbsentAndRemainingFieldsKeepInheritance(): void
    {
        $xml = simplexml_load_file(dirname(__DIR__, 3) . '/etc/adminhtml/system.xml');
        $group = $xml->system->section->group;
        $ids = [];
        foreach ($group->field as $field) {
            $ids[] = (string)$field['id'];
            foreach (['showInDefault', 'showInWebsite', 'showInStore'] as $flag) {
                self::assertSame('1', (string)$field[$flag]);
            }
        }
        self::assertSame(['enabled', 'public_key', 'api_key', 'signature_public_key', 'protected_paths', 'block_duration',
            'pass_duration', 'debug', 'reset_blocks'], $ids);
        $defaults = simplexml_load_file(dirname(__DIR__, 3) . '/etc/config.xml')
            ->default->hachitech_hideguard->general;
        foreach (RuntimeSettings::LEGACY_ADMIN_FIELDS as $field) {
            self::assertNotContains($field, $ids);
            self::assertFalse(isset($defaults->$field));
        }
    }
}
