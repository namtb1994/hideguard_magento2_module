<?php

declare(strict_types=1);

namespace Hachitechs\Hideguard\Test\Unit\Plugin;

use Hachitechs\Hideguard\Model\Config;
use Hachitechs\HideguardPhp\Configuration\ProtectionPolicy;
use Hachitechs\Hideguard\Plugin\ValidateSettings;
use Magento\Config\Model\Config as SystemConfig;
use Magento\Framework\Exception\LocalizedException;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

class ValidateSettingsTest extends TestCase
{
    private const KEY = "-----BEGIN PUBLIC KEY-----\n"
        . "MCowBQYDK2VwAyEAQXLS7j+tppqbZp00nnr78na3G6llNj62E3QG9zDCFaI=\n-----END PUBLIC KEY-----";

    private function validator(array $groups, ?Config $config = null, ?array $parentSettings = null): array
    {
        $settings = ['enabled' => false, 'environment' => Config::ENVIRONMENT, 'points' => ['all_pages'],
            'pageRules' => [], 'blockDuration' => 3600, 'passDuration' => 0,
            'keys' => [Config::ENVIRONMENT => [
                'publicKey' => '', 'signaturePublicKey' => '', 'endpointUrl' => ProtectionPolicy::DEFAULT_ENDPOINT_URL,
            ]]];
        if ($config === null) {
            $deployment = $this->createStub(\Magento\Framework\App\DeploymentConfig::class);
            $deployment->method('get')->willReturnCallback(fn($key, $default = null) => $default);
            $runtime = new \Hachitechs\Hideguard\Model\Config\RuntimeSettings(
                $deployment,
                $this->createStub(StoreManagerInterface::class)
            );
            $config = $this->getMockBuilder(Config::class)->setConstructorArgs([
                $this->createStub(\Magento\Framework\App\Config\ScopeConfigInterface::class),
                $this->createStub(\Magento\Framework\Encryption\EncryptorInterface::class), $runtime,
            ])->onlyMethods(['read'])->getMock();
        }
        $config->method('read')->willReturnCallback(
            fn($scope) => $scope === 'default' && $parentSettings !== null ? $parentSettings : $settings
        );
        $subject = $this->getMockBuilder(SystemConfig::class)->disableOriginalConstructor()
            ->onlyMethods([])->getMock();
        $subject->setData(['section' => Config::SECTION, 'groups' => $groups]);
        return [new ValidateSettings($config, $this->createMock(StoreManagerInterface::class)), $subject];
    }

    public function testLegacyEnvironmentEndpointAndPointsPostAreIgnored(): void
    {
        $config = $this->getMockBuilder(Config::class)->disableOriginalConstructor()
            ->onlyMethods(['read', 'toPolicy'])->getMock();
        $config->expects(self::once())->method('toPolicy')->with(self::callback(
            fn(array $settings) => $settings['points'] === ['all_pages']
                && $settings['environment'] === 'production'
                && $settings['keys']['production']['endpointUrl'] === ProtectionPolicy::DEFAULT_ENDPOINT_URL
        ))->willReturn(new ProtectionPolicy());
        [$validator, $subject] = $this->validator(['general' => [
            'fields' => ['protected_points' => ['value' => []], 'environment' => ['value' => 'staging']],
            'groups' => ['development' => ['fields' => [
                'endpoint_url' => ['value' => 'http://forged.example/path'],
            ]]],
        ]], $config);
        $validator->beforeSave($subject);
    }

    public function testEnableWithoutPublicKeyIsRejectedOnServer(): void
    {
        [$validator, $subject] = $this->validator(['general' => ['fields' => ['enabled' => ['value' => '1']]]]);
        $this->expectException(LocalizedException::class);
        $validator->beforeSave($subject);
    }

    public function testEnableWithBothKeysInSameSaveIsAccepted(): void
    {
        [$validator, $subject] = $this->validator(['general' => ['fields' => [
            'enabled' => ['value' => '1'],
            'public_key' => ['value' => 'site-key'],
            'signature_public_key' => ['value' => self::KEY],
        ]]]);
        $validator->beforeSave($subject);
        self::assertTrue(true);
    }

    public function testOldProfilePostsCannotSupplyTheRequiredSignature(): void
    {
        [$validator, $subject] = $this->validator(['general' => [
            'fields' => ['enabled' => ['value' => '1'], 'public_key' => ['value' => 'site-key']],
            'groups' => ['development' => ['fields' => ['signature_public_key' => ['value' => self::KEY]]]],
        ]]);
        $this->expectException(LocalizedException::class);
        $validator->beforeSave($subject);
    }

    /** @dataProvider invalidSignatureKeys */
    public function testEnablingRequiresAnEd25519PemKey(string $key): void
    {
        [$validator, $subject] = $this->validator(['general' => ['fields' => [
            'enabled' => ['value' => '1'],
            'public_key' => ['value' => 'site-key'],
            'signature_public_key' => ['value' => $key],
        ]]]);
        $this->expectException(LocalizedException::class);
        $validator->beforeSave($subject);
    }

    public static function invalidSignatureKeys(): array
    {
        return [[''], ['invalid-key'], [str_replace('PUBLIC KEY', 'PRIVATE KEY', self::KEY)]];
    }

    public function testCredentialsCanInheritParentValuesWhenEnabling(): void
    {
        [$validator, $subject] = $this->validator(['general' => ['fields' => [
            'enabled' => ['value' => '1'],
            'public_key' => ['inherit' => '1', 'value' => ''],
            'signature_public_key' => ['inherit' => '1', 'value' => 'invalid'],
        ]]], parentSettings: ['keys' => ['production' => [
            'publicKey' => 'parent-site-key', 'signaturePublicKey' => self::KEY,
        ]]]);
        $subject->setWebsite(2);
        $validator->beforeSave($subject);
        self::assertTrue(true);
    }

    public function testLegacyUnknownPointIsIgnored(): void
    {
        [$validator, $subject] = $this->validator(['general' => ['fields' => ['protected_points' => ['value' => ['shopify_rule']]]]]);
        $validator->beforeSave($subject);
        self::assertTrue(true);
    }

    /** @dataProvider passDurations */
    public function testRecheckIntervalCanBeSaved(string $duration): void
    {
        [$validator, $subject] = $this->validator(['general' => ['fields' => ['pass_duration' => ['value' => $duration]]]]);
        $validator->beforeSave($subject);
        self::assertTrue(true);
    }

    public static function passDurations(): array
    {
        return [['0'], ['60'], ['300'], ['900'], ['3600']];
    }

    /** @dataProvider invalidPassDurations */
    public function testInvalidRecheckIntervalCannotBeSaved(string $duration): void
    {
        [$validator, $subject] = $this->validator(['general' => ['fields' => ['pass_duration' => ['value' => $duration]]]]);
        $this->expectException(LocalizedException::class);
        $validator->beforeSave($subject);
    }

    public static function invalidPassDurations(): array
    {
        return [['abc'], [''], ['60.5'], ['120'], ['-1']];
    }

    public function testRecheckIntervalCanInheritParentValue(): void
    {
        [$validator, $subject] = $this->validator([
            'general' => ['fields' => ['pass_duration' => ['inherit' => '1', 'value' => 'invalid']]],
        ]);
        $validator->beforeSave($subject);
        self::assertTrue(true);
    }

    public function testPostedPathsKeepZeroInEffectivePolicy(): void
    {
        $config = $this->getMockBuilder(Config::class)->disableOriginalConstructor()
            ->onlyMethods(['read', 'toPolicy'])->getMock();
        $config->expects(self::once())->method('toPolicy')->with(self::callback(
            fn(array $settings) => $settings['pageRules'] === ['0', 'Campaign/*']
        ))->willReturn(new ProtectionPolicy());
        [$validator, $subject] = $this->validator([
            'general' => ['fields' => ['protected_paths' => ['value' => " 0 \r\n\n Campaign/* \n\t"]]],
        ], $config);
        $validator->beforeSave($subject);
    }
}
