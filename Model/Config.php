<?php

declare(strict_types=1);

namespace Hachitechs\Hideguard\Model;

use Hachitechs\HideguardPhp\Configuration\ProtectionPolicy;
use Hachitechs\HideguardPhp\Exceptions\FailureReason;
use Hachitechs\HideguardPhp\Exceptions\GuardException;
use Hachitechs\Hideguard\Model\Config\Source\Points;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Store\Model\ScopeInterface;

class Config
{
    public const SECTION = 'hachitech_antidetectbrowser';

    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param EncryptorInterface $encryptor
     */
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly EncryptorInterface $encryptor
    ) {
    }

    /**
     * Diagnostic settings are separate from the access policy and its fingerprint.
     *
     * @param int $storeId
     * @return bool
     */
    public function debugEnabled(int $storeId): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::SECTION . '/general/debug',
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Read the active server credential separately from browser-facing settings.
     *
     * @param int $storeId
     * @return string
     */
    public function apiKey(int $storeId): string
    {
        $environment = $this->settings($storeId)['environment'];
        $encrypted = (string)$this->scopeConfig->getValue(
            self::SECTION . '/' . $environment . '/api_key',
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
        return $this->encryptor->decrypt($encrypted);
    }

    /**
     * Read effective settings for a store view, including inherited values.
     *
     * @param int|null $storeId
     * @return array<string,mixed>
     */
    public function settings(?int $storeId = null): array
    {
        return $this->read(ScopeInterface::SCOPE_STORE, $storeId);
    }

    /**
     * Normalize settings from the requested Magento configuration scope.
     *
     * @param string $scope
     * @param int|string|null $scopeId
     * @return array<string,mixed>
     */
    public function read(string $scope, $scopeId): array
    {
        $read = fn(string $path) => $this->scopeConfig->getValue(self::SECTION . '/' . $path, $scope, $scopeId);
        $keys = [];
        foreach (ProtectionPolicy::ENVIRONMENTS as $environment) {
            $keys[$environment] = [
                'endpointUrl' => trim((string)($read($environment . '/endpoint_url')
                    ?? ProtectionPolicy::DEFAULT_ENDPOINT_URL)),
                'publicKey' => trim((string)$read($environment . '/public_key')),
                'signaturePublicKey' => trim((string)$read($environment . '/signature_public_key')),
            ];
        }
        return [
            'enabled' => (bool)$read('general/enabled'),
            'environment' => (string)($read('general/environment') ?: 'development'),
            'points' => array_values(array_filter(explode(',', (string)$read('general/protected_points')))),
            'pageRules' => $this->normalizePaths((string)$read('general/protected_paths')),
            'blockDuration' => (int)($read('general/block_duration') ?? 3600),
            'passDuration' => filter_var(
                $read('general/pass_duration') ?? ProtectionPolicy::DEFAULT_PASS_DURATION,
                FILTER_VALIDATE_INT,
                ['options' => ['default' => -1]]
            ),
            'keys' => $keys,
        ];
    }

    /**
     * Build the effective core policy for a store view.
     *
     * @param ?int $storeId
     * @return ProtectionPolicy
     */
    public function policy(?int $storeId = null): ProtectionPolicy
    {
        return $this->toPolicy($this->settings($storeId));
    }

    /**
     * Normalize the admin textarea identically when reading or validating settings.
     *
     * @param string $value
     * @return string[]
     */
    public function normalizePaths(string $value): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\R/', $value)), 'strlen'));
    }

    /**
     * Map admin field data to a validated core policy.
     *
     * @param array<string,mixed> $settings
     * @return ProtectionPolicy
     */
    public function toPolicy(array $settings): ProtectionPolicy
    {
        if (array_diff($settings['points'], Points::VALUES)) {
            throw new GuardException(FailureReason::InvalidConfiguration, 'Invalid Magento access point.');
        }
        $keys = $settings['keys'][$settings['environment']] ?? ['publicKey' => '', 'signaturePublicKey' => ''];
        return new ProtectionPolicy(
            enabled: $settings['enabled'],
            environment: $settings['environment'],
            publicKey: $keys['publicKey'],
            signaturePublicKey: $keys['signaturePublicKey'],
            accessPoints: $settings['points'],
            pageRules: $settings['pageRules'],
            blockDuration: $settings['blockDuration'],
            passDuration: $settings['passDuration'],
            endpointUrl: $keys['endpointUrl'] ?? ProtectionPolicy::DEFAULT_ENDPOINT_URL
        );
    }
}
