<?php

declare(strict_types=1);

namespace Hachitechs\Hideguard\Model;

use Magento\Framework\ObjectManager\ResetAfterRequestInterface;
use Hachitechs\HideguardPhp\Storage\MySqlControl;
use Hachitechs\HideguardPhp\Access\CleanupResult;
use Hachitechs\HideguardPhp\Contracts\SessionCounterContract;
use Hachitechs\HideguardPhp\Contracts\BatchCleanupContract;
use Hachitechs\HideguardPhp\Access\BrowserState;
use Hachitechs\HideguardPhp\Access\RateWindow;
use Hachitechs\HideguardPhp\Access\ResourceRef;
use Hachitechs\HideguardPhp\Contracts\StorageContract;
use Magento\Framework\App\ResourceConnection;

/** Persistence mapping for the existing Magento tables; policy lives in Guard. */
class StateRepository implements
    StorageContract,
    BatchCleanupContract,
    SessionCounterContract,
    ResetAfterRequestInterface
{
    private ?MySqlControl $counter = null;

    /**
     * Initialize platform dependencies.
     *
     * @param ResourceConnection $resource
     */
    public function __construct(private readonly ResourceConnection $resource)
    {
    }

    /**
     * @inheritdoc
     */
    public function session(string $scopeId, string $browserId): ?BrowserState
    {
        $db = $this->resource->getConnection();
        return $this->state($db->fetchRow($db->select()->from($this->table('session'))
            ->where('store_id = ?', $this->storeId($scopeId))->where('session_hash = ?', $browserId)) ?: []);
    }

    /**
     * @inheritdoc
     */
    public function saveSession(string $scopeId, BrowserState $state): void
    {
        $new = $this->control()->beforeSave((string)$this->storeId($scopeId), $state->browserId, 'session_hash');
        $quoteId = $state->resource === null ? null : $this->quoteId($state->resource);
        $this->resource->getConnection()->insertOnDuplicate($this->table('session'), [
            'store_id' => $this->storeId($scopeId), 'session_hash' => $state->browserId,
            'customer_id' => $state->actorId === null ? 0 : (int)$state->actorId,
            'visitor_id' => $state->visitorId, 'granted_until' => $state->grantedUntil,
            'blocked_until' => $state->blockedUntil, 'policy_hash' => $state->policyHash,
            'quote_id' => $quoteId,
            'last_seen' => $state->lastSeen, 'verification' => $state->verification,
            'verification_id' => $state->verificationId, 'verification_until' => $state->verificationUntil,
        ]);
        if ($new) {
            $this->control()->inserted((string)$this->storeId($scopeId));
        }
    }

    /**
     * @inheritdoc
     */
    public function sessionCount(string $scopeId): int
    {
        return $this->control()->count((string)$this->storeId($scopeId));
    }

    public function reconcileSessionCount(string $scopeId): int
    {
        return $this->control()->reconcile((string)$this->storeId($scopeId));
    }

    protected function control(): MySqlControl
    {
        return $this->counter ??= new MySqlControl(
            $this->resource->getConnection()->getConnection(),
            $this->table('control'),
            'store_id',
            $this->table('session')
        );
    }

    /**
     * @inheritdoc
     */
    public function blockExpiry(string $scopeId, ?string $visitorId, ?string $actorId): int
    {
        $identities = $this->identities($visitorId, $actorId);
        if ($identities === []) {
            return 0;
        }
        $db = $this->resource->getConnection();
        return (int)$db->fetchOne($db->select()->from($this->table('block'), ['expiry' => 'MAX(blocked_until)'])
            ->where('store_id = ?', $this->storeId($scopeId))->where('identity_hash IN (?)', $identities));
    }

    /**
     * @inheritdoc
     */
    public function block(string $scopeId, string $visitorId, ?string $actorId, int $until): void
    {
        foreach ($this->identities($visitorId, $actorId) as $identity) {
            $this->resource->getConnection()->insertOnDuplicate($this->table('block'), [
                'identity_hash' => $identity, 'store_id' => $this->storeId($scopeId), 'blocked_until' => $until,
            ]);
        }
    }

    /**
     * @inheritdoc
     */
    public function revokeIdentity(string $scopeId, ?string $visitorId, ?string $actorId): void
    {
        $db = $this->resource->getConnection();
        $conditions = [];
        if ($visitorId !== null) {
            $conditions[] = $db->quoteInto('visitor_id = ?', $visitorId);
        }
        if ($actorId !== null) {
            $conditions[] = $db->quoteInto('customer_id = ?', $actorId);
        }
        if ($conditions !== []) {
            $this->revoke(['store_id = ?' => $this->storeId($scopeId), '(' . implode(' OR ', $conditions) . ')']);
        }
    }

    /**
     * @inheritdoc
     */
    public function bindResource(string $scopeId, string $browserId, ResourceRef $resource): void
    {
        $storeId = $this->storeId($scopeId);
        $quoteId = $this->quoteId($resource);
        $db = $this->resource->getConnection();
        $db->update($this->table('session'), ['quote_id' => null], [
            'store_id = ?' => $storeId, 'quote_id = ?' => $quoteId, 'session_hash <> ?' => $browserId,
        ]);
        $db->update($this->table('session'), ['quote_id' => $quoteId], [
            'store_id = ?' => $storeId, 'session_hash = ?' => $browserId,
        ]);
    }

    /**
     * @inheritdoc
     */
    public function resource(string $scopeId, ResourceRef $resource): ?BrowserState
    {
        $db = $this->resource->getConnection();
        return $this->state($db->fetchRow($db->select()->from($this->table('session'))
            ->where('store_id = ?', $this->storeId($scopeId))->where('quote_id = ?', $this->quoteId($resource))
            ->limit(1)) ?: []);
    }

    /**
     * @inheritdoc
     */
    public function revokeResource(string $scopeId, ResourceRef $resource): void
    {
        $this->revoke(['store_id = ?' => $this->storeId($scopeId), 'quote_id = ?' => $this->quoteId($resource)]);
    }

    /**
     * @inheritdoc
     */
    public function revokeScope(string $scopeId): void
    {
        $this->revoke(['store_id = ?' => $this->storeId($scopeId)]);
    }

    /**
     * @inheritdoc
     */
    public function resetBlocks(string $scopeId): int
    {
        $db = $this->resource->getConnection();
        $storeId = $this->storeId($scopeId);
        $count = $db->delete($this->table('block'), ['store_id = ?' => $storeId]);
        $db->update($this->table('session'), ['blocked_until' => 0], ['store_id = ?' => $storeId]);
        return $count;
    }

    /**
     * @inheritdoc
     */
    public function rate(string $scopeId, string $bucketHash): ?RateWindow
    {
        if (
            $bucketHash === hash('sha256', 'scope')
            && $this->resource->getConnection()->getConnection()->inTransaction()
        ) {
            $row = $this->control()->lockScopeRate($this->table('rate'), (string)$this->storeId($scopeId), $bucketHash);
            return $row === null ? null : new RateWindow((int)$row['requests'], (int)$row['expires_at']);
        }
        $db = $this->resource->getConnection();
        $row = $db->fetchRow($db->select()->from($this->table('rate'))
            ->where('store_id = ?', $this->storeId($scopeId))->where('bucket_hash = ?', $bucketHash));
        return $row ? new RateWindow((int)$row['requests'], (int)$row['expires_at']) : null;
    }

    /**
     * @inheritdoc
     */
    public function saveRate(string $scopeId, string $bucketHash, RateWindow $window): void
    {
        $this->resource->getConnection()->insertOnDuplicate($this->table('rate'), [
            'store_id' => $this->storeId($scopeId), 'bucket_hash' => $bucketHash,
            'requests' => $window->requests, 'expires_at' => $window->expiresAt,
        ]);
    }

    /**
     * @inheritdoc
     */
    public function cleanup(string $scopeId, int $now, int $inactiveBefore): void
    {
        while ($this->cleanupBatch($scopeId, $now, $inactiveBefore, 500)->hasMore) {
        }
    }

    public function cleanupBatch(string $scopeId, int $now, int $inactiveBefore, int $limit): CleanupResult
    {
        if ($limit < 1 || $limit > 10000) {
            throw new \InvalidArgumentException('Batch limit must be between 1 and 10000.');
        }
        $scope = $this->storeId($scopeId);
        $counts = [];
        $conditions = [
            'block' => ['blocked_until <= ?' => $now],
            'rate' => ['expires_at <= ?' => $now],
            'session' => ['last_seen < ?' => $inactiveBefore, 'blocked_until <= ?' => $now,
                'granted_until <= ?' => $now, 'verification_until <= ?' => $now],
        ];
        $db = $this->resource->getConnection();
        $remaining = $limit;
        foreach ($conditions as $suffix => $where) {
            $counts[$suffix] = 0;
            if ($remaining === 0) {
                continue;
            }
            if ($suffix === 'session') {
                $this->control()->count((string)$scope);
            }
            $parts = [$db->quoteInto('store_id = ?', $scope)];
            foreach ($where as $clause => $value) {
                $parts[] = $db->quoteInto($clause, $value);
            }
            $db->query('DELETE FROM ' . $db->quoteIdentifier($this->table($suffix)) . ' WHERE '
                . implode(' AND ', $parts) . ' LIMIT ' . $remaining);
            $counts[$suffix] = (int)$db->fetchOne('SELECT ROW_COUNT()');
            if ($suffix === 'session') {
                $this->control()->removed((string)$scope, $counts[$suffix]);
            }
            $remaining -= $counts[$suffix];
        }
        return new CleanupResult(
            $counts['block'],
            $counts['rate'],
            $counts['session'],
            $remaining === 0
        );
    }

    /**
     * Hydrate a detached core state from a Magento row.
     *
     * @param array<string,mixed> $row
     * @return ?BrowserState
     */
    private function state(array $row): ?BrowserState
    {
        if ($row === []) {
            return null;
        }
        $actorId = (int)$row['customer_id'] > 0 ? (string)$row['customer_id'] : null;
        return new BrowserState(
            (string)$row['session_hash'],
            $actorId,
            $row['visitor_id'],
            (int)$row['blocked_until'],
            (int)$row['granted_until'],
            $row['policy_hash'],
            $row['quote_id'] ? new ResourceRef('quote', (string)$row['quote_id'], $actorId) : null,
            (int)$row['last_seen'],
            (string)$row['verification'],
            $row['verification_id'],
            (int)$row['verification_until']
        );
    }

    /**
     * Clear grants and quote bindings matching native conditions.
     *
     * @param array<int|string,mixed> $where
     * @return void
     */
    private function revoke(array $where): void
    {
        $this->resource->getConnection()->update(
            $this->table('session'),
            ['granted_until' => 0, 'quote_id' => null, 'verification_id' => null, 'verification_until' => 0],
            $where
        );
    }

    /**
     * Preserve legacy visitor and customer hashes during cutover.
     *
     * @param ?string $visitorId
     * @param ?string $actorId
     * @return array<string,mixed>
     */
    private function identities(?string $visitorId, ?string $actorId): array
    {
        $identities = [];
        if ($visitorId !== null) {
            $identities[] = hash('sha256', 'visitor:' . $visitorId);
        }
        if ($actorId !== null) {
            $identities[] = hash('sha256', 'customer:' . $actorId);
        }
        return $identities;
    }

    /**
     * Validate a Magento storefront scope.
     *
     * @param string $scopeId
     * @return int
     */
    private function storeId(string $scopeId): int
    {
        if (!ctype_digit($scopeId) || (int)$scopeId < 1 || (int)$scopeId > 65535) {
            throw new \InvalidArgumentException('Magento storage requires a storefront store ID.');
        }
        return (int)$scopeId;
    }

    /**
     * Validate the quote resource supported by this adapter.
     *
     * @param ResourceRef $resource
     * @return int
     */
    private function quoteId(ResourceRef $resource): int
    {
        if ($resource->type !== 'quote' || !ctype_digit($resource->id) || (int)$resource->id < 1) {
            throw new \InvalidArgumentException('Magento storage supports persisted quotes only.');
        }
        return (int)$resource->id;
    }

    /**
     * Resolve the existing table name and configured prefix.
     *
     * @param string $suffix
     * @return string
     */
    private function table(string $suffix): string
    {
        return $this->resource->getTableName('hachitech_hideguard_' . $suffix);
    }

    /** Release PDO-bound helpers when Magento closes its resource connection between requests. */
    // phpcs:ignore PSR2.Methods.MethodDeclaration.Underscore -- Required by Magento reset contract.
    public function _resetState(): void
    {
        $this->counter = null;
    }
}
