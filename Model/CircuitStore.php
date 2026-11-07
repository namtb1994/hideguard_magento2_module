<?php

declare(strict_types=1);

namespace Hachitechs\Hideguard\Model;

use Magento\Framework\ObjectManager\ResetAfterRequestInterface;
use Hachitechs\HideguardPhp\Access\CircuitPermit;
use Hachitechs\HideguardPhp\Configuration\CircuitBreakerPolicy;
use Hachitechs\HideguardPhp\Contracts\CircuitBreakerStoreContract;
use Hachitechs\HideguardPhp\Storage\PdoCircuitBreakerStore;
use Magento\Framework\App\ResourceConnection;

/** Dedicated connection; no Magento dependency crosses into the reusable PDO implementation. */
class CircuitStore implements CircuitBreakerStoreContract, ResetAfterRequestInterface
{
    private ?PdoCircuitBreakerStore $store = null;

    public function __construct(private readonly ResourceConnection $resource)
    {
    }

    private function store(): PdoCircuitBreakerStore
    {
        return $this->store ??= new PdoCircuitBreakerStore(
            $this->resource->getConnection()->getConnection(),
            $this->resource->getTableName('hachitech_antidetect_circuit')
        );
    }

    public function acquire(string $key, int $now, CircuitBreakerPolicy $policy): CircuitPermit
    {
        return $this->store()->acquire($key, $now, $policy);
    }

    public function record(
        string $key,
        CircuitPermit $permit,
        bool $failed,
        int $now,
        CircuitBreakerPolicy $policy
    ): void {
        $this->store()->record($key, $permit, $failed, $now, $policy);
    }

    /** Release PDO-bound helpers when Magento closes its resource connection between requests. */
    // phpcs:ignore PSR2.Methods.MethodDeclaration.Underscore -- Required by Magento reset contract.
    public function _resetState(): void
    {
        $this->store = null;
    }
}
