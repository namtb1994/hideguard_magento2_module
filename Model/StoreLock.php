<?php

declare(strict_types=1);

namespace Hachitechs\Hideguard\Model;

use Magento\Framework\ObjectManager\ResetAfterRequestInterface;
use Hachitechs\HideguardPhp\Access\ResourceRef;
use Hachitechs\HideguardPhp\Contracts\PartitionedAtomicMutationContract;
use Hachitechs\HideguardPhp\Storage\MySqlControl;
use Hachitechs\HideguardPhp\Storage\MySqlCoordinator;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Framework\App\ResourceConnection;

class StoreLock implements PartitionedAtomicMutationContract, ResetAfterRequestInterface
{
    private ?MySqlCoordinator $coordinator = null;

    /** Keep the lock-manager constructor argument compatible; new locks live on the guard PDO connection. */
    public function __construct(LockManagerInterface $locks, private readonly ResourceConnection $resource)
    {
    }

    private function coordinator(): MySqlCoordinator
    {
        if ($this->resource->getConnection()->getTransactionLevel() !== 0) {
            throw new \LogicException('External access transactions are unsupported.');
        }
        if ($this->coordinator === null) {
            $pdo = $this->resource->getConnection()->getConnection();
            $control = new MySqlControl(
                $pdo,
                $this->resource->getTableName('hachitech_antidetect_control'),
                'store_id',
                $this->resource->getTableName('hachitech_antidetect_session')
            );
            $this->coordinator = new MySqlCoordinator(
                $pdo,
                $control,
                fn(string $scope, string $browser) => (new StateRepository($this->resource))->session($scope, $browser)
            );
        }
        return $this->coordinator;
    }

    private function scope(string $scopeId): string
    {
        if (!ctype_digit($scopeId) || (int)$scopeId < 1 || (int)$scopeId > 65535) {
            throw new \InvalidArgumentException('Magento locks require a storefront store ID.');
        }
        return (string)(int)$scopeId;
    }

    private function quote(ResourceRef $resource): ResourceRef
    {
        if (
            $resource->type !== 'quote' || !ctype_digit($resource->id)
            || (int)$resource->id < 1 || (int)$resource->id > 4294967295
        ) {
            throw new \InvalidArgumentException('Magento locks require a persisted quote.');
        }
        return new ResourceRef('quote', (string)(int)$resource->id, $resource->actorId);
    }

    public function run(string $scopeId, callable $work): mixed
    {
        return $this->coordinator()->run($this->scope($scopeId), $work);
    }

    public function runBrowser(string $scopeId, string $browserId, ?ResourceRef $resource, callable $work): mixed
    {
        return $this->coordinator()->runBrowser(
            $this->scope($scopeId),
            strtolower($browserId),
            $resource === null ? null : $this->quote($resource),
            $work
        );
    }

    public function runResource(string $scopeId, ResourceRef $resource, callable $work): mixed
    {
        return $this->coordinator()->runResource($this->scope($scopeId), $this->quote($resource), $work);
    }

    /** Release PDO-bound helpers when Magento closes its resource connection between requests. */
    // phpcs:ignore PSR2.Methods.MethodDeclaration.Underscore -- Required by Magento reset contract.
    public function _resetState(): void
    {
        $this->coordinator = null;
    }
}
