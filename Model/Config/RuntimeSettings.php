<?php

declare(strict_types=1);

namespace Hachitechs\Hideguard\Model\Config;

use Hachitechs\HideguardPhp\Configuration\{CircuitBreakerPolicy, RateLimits};
use Hachitechs\HideguardPhp\Exceptions\{FailureReason, GuardException};
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\ObjectManager\ResetAfterRequestInterface;
use Magento\Store\Model\{ScopeInterface, StoreManagerInterface};

/** Technical settings belong to the Magento adapter, not the framework-independent PHP package. */
class RuntimeSettings implements ResetAfterRequestInterface
{
    /** @var array{global:array<string,int>,stores:array<int,array<string,int>>}|null */
    private ?array $normalized = null;

    public const RATE_WINDOW_SECONDS = 60;
    public const CIRCUIT_ENABLED = true;
    public const CIRCUIT_THRESHOLD = 5;
    public const CIRCUIT_WINDOW_SECONDS = 60;
    public const CIRCUIT_OPEN_SECONDS = 30;
    public const CIRCUIT_PROBE_LEASE_SECONDS = 15;

    public const DEFAULT_SCOPE_REQUESTS = 600;
    public const DEFAULT_IDENTITY_REQUESTS = 60;
    public const DEFAULT_SESSION_CAPACITY = 10000;
    public const DEFAULT_SESSION_RETENTION_SECONDS = 2592000;

    public const LIMIT_FIELDS = [
        'scope_requests' => 'scopeRequests',
        'identity_requests' => 'identityRequests',
        'session_capacity' => 'sessionCapacity',
        'session_retention_seconds' => 'sessionRetentionSeconds',
    ];
    public const DEFAULT_LIMITS = [
        'scopeRequests' => self::DEFAULT_SCOPE_REQUESTS,
        'identityRequests' => self::DEFAULT_IDENTITY_REQUESTS,
        'windowSeconds' => self::RATE_WINDOW_SECONDS,
        'sessionCapacity' => self::DEFAULT_SESSION_CAPACITY,
        'sessionRetentionSeconds' => self::DEFAULT_SESSION_RETENTION_SECONDS,
    ];
    public const LEGACY_ADMIN_FIELDS = [
        'scope_requests', 'identity_requests', 'rate_window_seconds', 'session_capacity', 'session_retention_seconds',
        'circuit_enabled', 'circuit_threshold', 'circuit_window_seconds', 'circuit_open_seconds',
        'circuit_probe_lease_seconds',
    ];

    public function __construct(
        private readonly DeploymentConfig $deployment,
        private readonly StoreManagerInterface $stores
    ) {
    }

    public function storeIdForScope(string $scope, int|string|null $scopeId): ?int
    {
        if (!in_array($scope, [ScopeInterface::SCOPE_STORE, ScopeInterface::SCOPE_STORES], true)) {
            return null;
        }
        if ($scopeId === null || $scopeId === '') {
            return (int)$this->stores->getStore()->getId();
        }
        if (ctype_digit((string)$scopeId)) {
            $id = (int)$scopeId;
            if ($id < 0 || $id > 65535) {
                $this->invalid('Invalid runtime store ID.');
            }
            return $id;
        }
        return (int)$this->stores->getStore($scopeId)->getId();
    }

    /** @return array<string,int> */
    public function limits(?int $storeId = null): array
    {
        if ($this->normalized === null) {
            $global = $this->normalize($this->deployment->get('hideguard/limits', []));
            $overrides = $this->deployment->get('hideguard/store_limits', []);
            if (!is_array($overrides)) {
                $this->invalid('hideguard/store_limits must be an array.');
            }
            $stores = [];
            foreach ($overrides as $id => $fields) {
                if (!preg_match('/^[1-9][0-9]{0,4}$/D', (string)$id) || (int)$id > 65535) {
                    $this->invalid('hideguard/store_limits keys must be canonical store IDs from 1 to 65535.');
                }
                // Validate once per request, including inactive stores, then use O(1) scoped lookups.
                $stores[(int)$id] = $this->normalize($fields);
            }
            $this->normalized = ['global' => $global, 'stores' => $stores];
        }
        $local = $storeId === null ? [] : ($this->normalized['stores'][$storeId] ?? []);
        return array_replace(self::DEFAULT_LIMITS, $this->normalized['global'], $local);
    }

    // phpcs:ignore PSR2.Methods.MethodDeclaration.Underscore -- Required by Magento reset contract.
    public function _resetState(): void
    {
        $this->normalized = null;
    }

    public function rateLimits(?int $storeId = null): RateLimits
    {
        return new RateLimits(...$this->limits($storeId));
    }

    /** @return array{enabled:bool,threshold:int,windowSeconds:int,openSeconds:int,probeLeaseSeconds:int} */
    public function circuit(): array
    {
        return ['enabled' => self::CIRCUIT_ENABLED, 'threshold' => self::CIRCUIT_THRESHOLD,
            'windowSeconds' => self::CIRCUIT_WINDOW_SECONDS, 'openSeconds' => self::CIRCUIT_OPEN_SECONDS,
            'probeLeaseSeconds' => self::CIRCUIT_PROBE_LEASE_SECONDS];
    }

    public function circuitPolicy(): CircuitBreakerPolicy
    {
        return new CircuitBreakerPolicy(
            self::CIRCUIT_THRESHOLD,
            self::CIRCUIT_WINDOW_SECONDS,
            self::CIRCUIT_OPEN_SECONDS,
            self::CIRCUIT_PROBE_LEASE_SECONDS
        );
    }

    /** @return array<string,int> */
    private function normalize(mixed $fields): array
    {
        if (!is_array($fields) || array_diff(array_keys($fields), array_keys(self::LIMIT_FIELDS)) !== []) {
            $this->invalid('HideGuard deployment limits accept only: ' . implode(', ', array_keys(self::LIMIT_FIELDS)));
        }
        $values = [];
        foreach ($fields as $field => $value) {
            $digits = is_string($value) || is_int($value) ? ltrim(trim((string)$value), '0') : '';
            if (
                $digits === '' || !ctype_digit($digits) || strlen($digits) > 10
                || (strlen($digits) === 10 && strcmp($digits, '2147483647') > 0)
            ) {
                $this->invalid('HideGuard ' . $field . ' must be an integer from 1 to 2147483647.');
            }
            $values[self::LIMIT_FIELDS[$field]] = (int)$digits;
        }
        return $values;
    }

    private function invalid(string $message): never
    {
        throw new GuardException(FailureReason::InvalidConfiguration, $message);
    }
}
