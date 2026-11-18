<?php

declare(strict_types=1);

namespace Hachitechs\Hideguard\Test\Unit\Model;

use Hachitechs\HideguardPhp\Exceptions\GuardException;
use Hachitechs\Hideguard\Model\Config;
use Hachitechs\Hideguard\Model\Config\Comment\ApiKey;
use Hachitechs\HideguardPhp\Configuration\ProtectionPolicy;
use Magento\Framework\Escaper;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Store\Model\ScopeInterface;
use PHPUnit\Framework\TestCase;

class ConfigTest extends TestCase
{
    private function runtime(): \Hachitechs\Hideguard\Model\Config\RuntimeSettings
    {
        $deployment = $this->createStub(\Magento\Framework\App\DeploymentConfig::class);
        $deployment->method('get')->willReturnCallback(fn($key, $default = null) => $default);
        return new \Hachitechs\Hideguard\Model\Config\RuntimeSettings(
            $deployment,
            $this->createStub(\Magento\Store\Model\StoreManagerInterface::class)
        );
    }

    /** @dataProvider debugFlags */
    public function testDebugFlagUsesTheRequestedStoreScope(bool $enabled): void
    {
        $scope = $this->createMock(ScopeConfigInterface::class);
        $scope->expects(self::once())->method('isSetFlag')
            ->with(Config::SECTION . '/general/debug', ScopeInterface::SCOPE_STORE, 7)
            ->willReturn($enabled);
        self::assertSame($enabled, $this->config($scope)->debugEnabled(7));
    }

    public static function debugFlags(): array
    {
        return [[false], [true]];
    }

    public function testApiKeyIsDecryptedOnlyForActiveStoreAndKeptOutOfPublicSettings(): void
    {
        $scope = $this->createMock(ScopeConfigInterface::class);
        $scope->method('getValue')->willReturnMap([
            [Config::SECTION . '/general/environment', ScopeInterface::SCOPE_STORE, 7, 'development'],
            [Config::SECTION . '/production/api_key', ScopeInterface::SCOPE_STORE, 7, 'encrypted-secret'],
        ]);
        $encryptor = $this->createMock(EncryptorInterface::class);
        $encryptor->expects(self::once())->method('decrypt')->with('encrypted-secret')->willReturn('server-secret');
        $config = new Config($scope, $encryptor, $this->runtime());
        self::assertStringNotContainsString('server-secret', json_encode($config->settings(7)));
        self::assertSame('server-secret', $config->apiKey(7));
    }

    /** @dataProvider environments */
    public function testStoredEnvironmentCannotChangeLivePolicy(string $environment): void
    {
        $values = [
            'general/enabled' => '1',
            'general/environment' => $environment,
        ];
        foreach (['development', 'staging', 'production'] as $profile) {
            $values[$profile . '/endpoint_url'] = ' HTTPS://' . strtoupper($profile) . '.hideguard.example:443/ ';
            $values[$profile . '/public_key'] = ' sdk-' . $profile . ' ';
            $values[$profile . '/signature_public_key'] = "\n signature-" . $profile . "\n";
        }
        $scope = $this->createMock(ScopeConfigInterface::class);
        $scope->expects(self::atLeastOnce())->method('getValue')
            ->with(self::isType('string'), ScopeInterface::SCOPE_STORE, 7)
            ->willReturnCallback(fn(string $path) => $values[substr($path, strlen(Config::SECTION) + 1)] ?? null);

        $policy = ($this->config($scope))->policy(7);

        self::assertSame(ProtectionPolicy::DEFAULT_ENDPOINT_URL, $policy->endpointUrl);
        self::assertSame(Config::ACCESS_POINTS, $policy->accessPoints);
        self::assertSame('sdk-production', $policy->publicKey);
        self::assertSame('signature-production', $policy->signaturePublicKey);
    }

    public static function environments(): array
    {
        return [['development'], ['staging'], ['production'], [''], ['invalid']];
    }

    public function testLegacyUrlsPointsAndSettingsInjectionCannotChangeEffectiveProtection(): void
    {
        $scope = $this->createMock(ScopeConfigInterface::class);
        $scope->method('getValue')->willReturnCallback(function (string $path) {
            self::assertFalse(str_ends_with($path, '/endpoint_url'), 'Legacy URL must not be read.');
            self::assertFalse(str_ends_with($path, '/protected_points'), 'Legacy points must not be read.');
            self::assertFalse(str_ends_with($path, '/environment'), 'Removed environment must not be read.');
            self::assertStringNotContainsString('/development/', $path);
            return match ($path) {
                Config::SECTION . '/general/enabled' => '1',
                Config::SECTION . '/production/public_key' => 'fixture',
                Config::SECTION . '/production/signature_public_key' => 'signature',
                Config::SECTION . '/general/protected_paths' => "contact\nCampaign/*",
                default => null,
            };
        });
        $config = $this->config($scope);
        $settings = $config->settings(7);
        self::assertSame(['contact', 'Campaign/*'], $settings['pageRules']);
        self::assertSame(Config::ACCESS_POINTS, $settings['points']);
        $settings['points'] = [];
        $settings['keys']['endpointUrl'] = 'https://forged.example';
        $policy = $config->toPolicy($settings);
        self::assertSame(ProtectionPolicy::DEFAULT_ENDPOINT_URL, $policy->endpointUrl);
        self::assertSame(Config::ACCESS_POINTS, $policy->accessPoints);
        foreach (['cart', 'checkout', 'payment', 'complete_order', 'login', 'form.submit'] as $action) {
            self::assertTrue($policy->protects($action));
        }
    }

    /** @dataProvider environments */
    public function testInjectedEnvironmentCannotChangeLivePolicy(string $environment): void
    {
        $config = $this->config($this->createStub(ScopeConfigInterface::class));
        $settings = $config->settings(7);
        $settings['environment'] = $environment;
        $settings['keys']['endpointUrl'] = 'https://forged.example';
        $policy = $config->toPolicy($settings);
        self::assertSame(ProtectionPolicy::DEFAULT_ENDPOINT_URL, $policy->endpointUrl);
    }

    public function testAdminCredentialsPreservePathsEncryptionAndDashboardAccess(): void
    {
        $xml = simplexml_load_file(dirname(__DIR__, 3) . '/etc/adminhtml/system.xml');
        $group = $xml->system->section->group;
        self::assertCount(0, $group->xpath('field[@id="environment"] | group'));
        foreach (['public_key', 'api_key', 'signature_public_key'] as $name) {
            $field = $group->xpath('field[@id="' . $name . '"]')[0];
            self::assertSame(Config::SECTION . '/production/' . $name, (string)$field->config_path);
            foreach (['showInDefault', 'showInWebsite', 'showInStore'] as $scope) {
                self::assertSame('1', (string)$field[$scope]);
            }
        }
        $apiKey = $group->xpath('field[@id="api_key"]')[0];
        self::assertSame('obscure', (string)$apiKey['type']);
        self::assertSame(\Magento\Config\Model\Config\Backend\Encrypted::class, (string)$apiKey->backend_model);
        self::assertSame(ApiKey::class, (string)$apiKey->comment['model']);
        $dashboard = rtrim(ProtectionPolicy::DEFAULT_ENDPOINT_URL, '/') . '/dashboard/';
        $escaper = $this->createMock(Escaper::class);
        $escaper->expects(self::once())->method('escapeUrl')->with($dashboard)->willReturn($dashboard);
        $comment = (new ApiKey($escaper))->getCommentText('private-fixture');
        self::assertStringContainsString(
            'href="' . $dashboard . '"',
            $comment
        );
        self::assertStringContainsString('rel="noopener noreferrer"', $comment);
        self::assertStringNotContainsString('private-fixture', $comment);
    }

    private function config(ScopeConfigInterface $scope): Config
    {
        return new Config($scope, $this->createMock(EncryptorInterface::class), $this->runtime());
    }

    private function settings($duration): array
    {
        $scope = $this->createMock(ScopeConfigInterface::class);
        $scope->method('getValue')->willReturnCallback(
            fn(string $path) => $path === Config::SECTION . '/general/pass_duration' ? $duration : null
        );
        return ($this->config($scope))->settings(1);
    }

    /** @dataProvider durations */
    public function testPassDurationReadsStoreConfigIncludingZero($stored, int $expected): void
    {
        $settings = $this->settings($stored);
        self::assertSame($expected, $settings['passDuration']);
        ($this->config($this->createMock(ScopeConfigInterface::class)))->toPolicy($settings);
    }

    public static function durations(): array
    {
        return [[null, 300], ['0', 0], ['60', 60], ['300', 300], ['900', 900], ['3600', 3600]];
    }

    /** @dataProvider invalidDurations */
    public function testInvalidConfigCannotBecomeAlwaysMode($stored): void
    {
        $this->expectException(GuardException::class);
        ($this->config($this->createMock(ScopeConfigInterface::class)))->toPolicy($this->settings($stored));
    }

    public static function invalidDurations(): array
    {
        return [['abc'], [''], ['60.5'], ['120']];
    }

    public function testStoredPathsKeepZeroAndDiscardOnlyBlankLines(): void
    {
        $scope = $this->createMock(ScopeConfigInterface::class);
        $scope->method('getValue')->willReturnCallback(
            fn(string $path) => $path === Config::SECTION . '/general/protected_paths'
                ? " 0 \r\n\n Campaign/* \n\t" : null
        );
        self::assertSame(['0', 'Campaign/*'], ($this->config($scope))->settings(1)['pageRules']);
    }
}
