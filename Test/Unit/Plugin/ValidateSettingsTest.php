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

    private function validator(array $groups, ?Config $config = null): array
    {
        $settings = ['enabled' => false, 'environment' => 'development', 'points' => ['all_pages'],
            'pageRules' => [], 'blockDuration' => 3600, 'passDuration' => 0,
            'keys' => array_fill_keys(ProtectionPolicy::ENVIRONMENTS, [
                'publicKey' => '', 'signaturePublicKey' => '', 'endpointUrl' => ProtectionPolicy::DEFAULT_ENDPOINT_URL,
            ])];
        $config ??= $this->getMockBuilder(Config::class)->disableOriginalConstructor()->onlyMethods(['read'])->getMock();
        $config->method('read')->willReturn($settings);
        $subject = $this->getMockBuilder(SystemConfig::class)->disableOriginalConstructor()
            ->onlyMethods([])->getMock();
        $subject->setData(['section' => Config::SECTION, 'groups' => $groups]);
        return [new ValidateSettings($config, $this->createMock(StoreManagerInterface::class)), $subject];
    }

    public function testEndpointUrlCanBeSavedInANestedEnvironmentProfile(): void
    {
        [$validator, $subject] = $this->validator(['general' => ['groups' => ['staging' => ['fields' => [
            'endpoint_url' => ['value' => 'https://staging.hideguard.example:8443/'],
        ]]]]]);
        $validator->beforeSave($subject);
        self::assertTrue(true);
    }

    public function testInvalidEndpointInADormantProfileCannotBeSaved(): void
    {
        [$validator, $subject] = $this->validator(['general' => ['groups' => ['production' => ['fields' => [
            'endpoint_url' => ['value' => 'http://insecure.hideguard.example/path'],
        ]]]]]);
        $this->expectException(LocalizedException::class);
        $validator->beforeSave($subject);
    }

    public function testEndpointUrlCanInheritTheParentValue(): void
    {
        [$validator, $subject] = $this->validator(['general' => ['groups' => ['development' => ['fields' => [
            'endpoint_url' => ['inherit' => '1', 'value' => 'invalid-local-value'],
        ]]]]]);
        $subject->setWebsite(2);
        $validator->beforeSave($subject);
        self::assertTrue(true);
    }

    public function testEnableWithoutPublicKeyIsRejectedOnServer(): void
    {
        [$validator, $subject] = $this->validator(['general' => ['fields' => ['enabled' => ['value' => '1']]]]);
        $this->expectException(LocalizedException::class);
        $validator->beforeSave($subject);
    }

    public function testEnableWithNewDevelopmentKeyInSameSaveIsAccepted(): void
    {
        [$validator, $subject] = $this->validator([
            'general' => [
                'fields' => ['enabled' => ['value' => '1']],
                'groups' => ['development' => ['fields' => [
                    'public_key' => ['value' => 'site-key'], 'signature_public_key' => ['value' => self::KEY],
                ]]],
            ],
        ]);
        $validator->beforeSave($subject);
        self::assertTrue(true);
    }

    public function testProductionRequiresItsOwnSignatureKey(): void
    {
        [$validator, $subject] = $this->validator([
            'general' => [
                'fields' => ['enabled' => ['value' => '1'], 'environment' => ['value' => 'production']],
                'groups' => [
                    'production' => ['fields' => ['public_key' => ['value' => 'site-key']]],
                    'development' => ['fields' => ['signature_public_key' => ['value' => 'different-profile']]],
                ],
            ],
        ]);
        $this->expectException(LocalizedException::class);
        $validator->beforeSave($subject);
    }

    public function testEnableProductionWithBothNestedKeysInSameSaveIsAccepted(): void
    {
        [$validator, $subject] = $this->validator([
            'general' => [
                'fields' => ['enabled' => ['value' => '1'], 'environment' => ['value' => 'production']],
                'groups' => ['production' => ['fields' => [
                    'public_key' => ['value' => 'site-key'],
                    'signature_public_key' => ['value' => self::KEY],
                ]]],
            ],
        ]);
        $validator->beforeSave($subject);
        self::assertTrue(true);
    }

    /** @dataProvider invalidSignatureProfiles */
    public function testEveryEnabledEnvironmentRequiresAnEd25519PemKey(string $environment, string $key): void
    {
        [$validator, $subject] = $this->validator(['general' => [
            'fields' => ['enabled' => ['value' => '1'], 'environment' => ['value' => $environment]],
            'groups' => [$environment => ['fields' => [
                'public_key' => ['value' => 'site-key'], 'signature_public_key' => ['value' => $key],
            ]]],
        ]]);
        $this->expectException(LocalizedException::class);
        $validator->beforeSave($subject);
    }

    public static function invalidSignatureProfiles(): array
    {
        $cases = [];
        foreach (ProtectionPolicy::ENVIRONMENTS as $environment) {
            foreach (['', 'invalid-key', str_replace('PUBLIC KEY', 'PRIVATE KEY', self::KEY)] as $key) {
                $cases[] = [$environment, $key];
            }
        }
        return $cases;
    }

    public function testUnknownPointCannotBeSaved(): void
    {
        [$validator, $subject] = $this->validator(['general' => ['fields' => ['protected_points' => ['value' => ['shopify_rule']]]]]);
        $this->expectException(LocalizedException::class);
        $validator->beforeSave($subject);
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

    public function testPostedPathsKeepZeroInEffectiveAndDormantProfiles(): void
    {
        $config = $this->getMockBuilder(Config::class)->disableOriginalConstructor()
            ->onlyMethods(['read', 'toPolicy'])->getMock();
        $config->expects(self::exactly(4))->method('toPolicy')->with(self::callback(
            fn(array $settings) => $settings['pageRules'] === ['0', 'Campaign/*']
        ))->willReturn(new ProtectionPolicy());
        [$validator, $subject] = $this->validator([
            'general' => ['fields' => ['protected_paths' => ['value' => " 0 \r\n\n Campaign/* \n\t"]]],
        ], $config);
        $validator->beforeSave($subject);
    }
}
