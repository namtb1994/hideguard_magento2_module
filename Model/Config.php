<?php

declare(strict_types=1);

namespace Hachitechs\Hideguard\Model;

use Hachitechs\HideguardPhp\Configuration\ProtectionPolicy;
use Hachitechs\HideguardPhp\Exceptions\FailureReason;
use Hachitechs\HideguardPhp\Exceptions\GuardException;
use Hachitechs\Hideguard\Model\Config\RuntimeSettings;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Store\Model\ScopeInterface;

class Config
{
    public const SECTION = 'hachitech_hideguard';
    public const ACCESS_POINTS = ['all_pages', 'complete_order'];
    private const CREDENTIALS_GROUP = 'production';

    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param EncryptorInterface $encryptor
     * @param RuntimeSettings $runtime
     */
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly EncryptorInterface $encryptor,
        private readonly RuntimeSettings $runtime
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
        $encrypted = (string)$this->scopeConfig->getValue(
            self::SECTION . '/' . self::CREDENTIALS_GROUP . '/api_key',
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
        $keys = [
            'publicKey' => trim((string)$read(self::CREDENTIALS_GROUP . '/public_key')),
            'signaturePublicKey' => trim((string)$read(self::CREDENTIALS_GROUP . '/signature_public_key')),
        ];
        $runtimeStoreId = $this->runtime->storeIdForScope($scope, $scopeId);
        $limits = $this->runtime->limits($runtimeStoreId);
        return [
            '_runtime_store_id' => $runtimeStoreId,
            'circuit' => $this->runtime->circuit(),
            'enabled' => (bool)$read('general/enabled'),
            'points' => self::ACCESS_POINTS,
            'pageRules' => $this->normalizePaths((string)$read('general/protected_paths')),
            'blockDuration' => (int)($read('general/block_duration') ?? 3600),
            'passDuration' => filter_var(
                $read('general/pass_duration') ?? ProtectionPolicy::DEFAULT_PASS_DURATION,
                FILTER_VALIDATE_INT,
                ['options' => ['default' => -1]]
            ),
            'keys' => $keys,
            'limits' => $limits,
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
        $lines = preg_split('/\R/', $value);
        if ($lines === false) {
            throw new GuardException(FailureReason::InvalidConfiguration, 'Cannot read protected path rules.');
        }
        return array_values(array_filter(
            array_map('trim', $lines),
            static fn(string $line): bool => strlen($line) > 0
        ));
    }

    /**
     * Map admin field data to a validated core policy.
     *
     * @param array<string,mixed> $settings
     * @return ProtectionPolicy
     */
    public function toPolicy(array $settings): ProtectionPolicy
    {
        $keys = $settings['keys'];
        return new ProtectionPolicy(
            enabled: $settings['enabled'],
            publicKey: $keys['publicKey'],
            signaturePublicKey: $keys['signaturePublicKey'],
            accessPoints: self::ACCESS_POINTS,
            pageRules: $settings['pageRules'],
            blockDuration: $settings['blockDuration'],
            passDuration: $settings['passDuration'],
            limits: $this->runtime->rateLimits($settings['_runtime_store_id'] ?? null)
        );
    }

    /** @return array{enabled:bool,policy:\Hachitechs\HideguardPhp\Configuration\CircuitBreakerPolicy} */
    public function circuitSettings(int $storeId): array
    {
        return ['enabled' => RuntimeSettings::CIRCUIT_ENABLED, 'policy' => $this->runtime->circuitPolicy()];
    }
}
