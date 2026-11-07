<?php

declare(strict_types=1);

namespace Hachitechs\Hideguard\Model;

use Hachitechs\HideguardPhp\Contracts\BatchGuardContract;
use Hachitechs\HideguardPhp\Access\AccessContext;
use Hachitechs\HideguardPhp\Access\ResourceRef;
use Hachitechs\HideguardPhp\Contracts\GuardContract;
use Hachitechs\HideguardPhp\Exceptions\FailureReason;
use Hachitechs\HideguardPhp\Exceptions\GuardException;
use Magento\Quote\Model\Quote;
use Magento\Store\Model\StoreManagerInterface;

/** Maps Magento identity, quotes and scopes to the framework-independent Guard. */
class Access
{
    /**
     * Initialize platform dependencies.
     *
     * @param Config $config
     * @param GuardContract $guard
     * @param BrowserIdentity $identity
     * @param StoreManagerInterface $stores
     */
    public function __construct(
        private readonly Config $config,
        private readonly GuardContract $guard,
        private readonly BrowserIdentity $identity,
        private readonly StoreManagerInterface $stores,
        private readonly ?\Psr\Log\LoggerInterface $logger = null
    ) {
    }

    /**
     * Map browser preflight to the storefront protocol.
     *
     * @param int $storeId
     * @param bool $retry
     * @return array<string,mixed>
     */
    public function status(int $storeId, bool $retry = false): array
    {
        return $this->guard->preflight(
            $this->context($storeId),
            $this->config->policy($storeId),
            $retry
        )->toBrowserArray();
    }

    /**
     * Inspect access before rendering or full-page cache lookup.
     *
     * @param int $storeId
     * @return array<string,mixed>
     */
    public function pageStatus(int $storeId): array
    {
        return $this->guard->inspect($this->context($storeId), $this->config->policy($storeId))->toBrowserArray();
    }

    /**
     * Submit a verdict to the core verifier.
     *
     * @param int $storeId
     * @param array<string,mixed> $detail
     * @param ?string $verificationId
     * @return array<string,mixed>
     */
    public function report(int $storeId, array $detail, ?string $verificationId = null): array
    {
        return $this->guard->report(
            $this->context($storeId),
            $this->config->policy($storeId),
            $detail,
            $verificationId
        )->toBrowserArray();
    }

    /**
     * Deny an unfinished SDK attempt without applying a timed visitor ban.
     *
     * @param int $storeId
     * @param string $verificationId
     * @return array<string,mixed>
     */
    public function failVerification(int $storeId, string $verificationId): array
    {
        return $this->guard->failVerification(
            $this->context($storeId),
            $this->config->policy($storeId),
            $verificationId
        )->toBrowserArray();
    }

    /**
     * Bind the current browser grant to a server-selected quote.
     *
     * @param Quote $quote
     * @return void
     */
    public function bindQuote(Quote $quote): void
    {
        $storeId = (int)$quote->getStoreId();
        $this->guard->bindGrant($this->context($storeId), $this->config->policy($storeId), $this->resource($quote));
    }

    /**
     * Enforce the selected Magento business point.
     *
     * @param Quote $quote
     * @param string $point
     * @return void
     */
    public function assertQuote(Quote $quote, string $point): void
    {
        $storeId = (int)$quote->getStoreId();
        $policy = $this->config->policy($storeId);
        if ($policy->protects($point)) {
            $this->guard->assertResourceAllowed(
                (string)$storeId,
                $policy,
                $this->resource($quote)
            );
        }
    }

    /**
     * Consume a quote grant after successful order submission.
     *
     * @param Quote $quote
     * @return void
     */
    public function consumeQuote(Quote $quote): void
    {
        if ($quote->getId()) {
            $this->guard->consumeGrant((string)$quote->getStoreId(), $this->resource($quote));
        }
    }

    /**
     * Reset blocks in the selected store.
     *
     * @param int $storeId
     * @return int
     */
    public function resetBlocks(int $storeId): int
    {
        return $this->guard->resetBlocks((string)$storeId);
    }

    /**
     * Revoke grants in affected configuration scopes.
     *
     * @param int[] $storeIds
     * @return void
     */
    public function revokeStores(array $storeIds): void
    {
        foreach ($storeIds as $storeId) {
            $this->guard->revokeScope((string)$storeId);
        }
    }

    /**
     * Run scoped core cleanup through native cron.
     *
     * @return void
     */
    public function cleanup(): void
    {
        $failures = [];
        foreach ($this->stores->getStores() as $store) {
            $id = (string)$store->getId();
            try {
                $policy = $this->config->policy((int)$id);
                if (
                    $this->guard instanceof BatchGuardContract
                    && $this->guard->supportsBatchCleanup()
                ) {
                    for ($batch = 0; $batch < 10; $batch++) {
                        $result = $this->guard->cleanupBatch($id, $policy);
                        if (!$result->hasMore) {
                            break;
                        }
                    }
                    if ($result->hasMore) {
                        try {
                            $this->logger?->notice(
                                'HideGuard cleanup budget exhausted; next cron continues.',
                                ['store_id' => $id]
                            );
                        } catch (\Throwable) {
                        /* Logging cannot stop cleanup for another store. */
                        }
                    }
                } else {
                    $this->guard->cleanup($id, $policy);
                }
            } catch (\Throwable $error) {
                $failures[$id] = $error;
            }
        }
        if ($failures !== []) {
            throw new \RuntimeException(
                'HideGuard cleanup failed for stores: ' . implode(', ', array_keys($failures)),
                0,
                reset($failures)
            );
        }
    }

    /**
     * Map the current Magento browser and customer identity.
     *
     * @param int $storeId
     * @return AccessContext
     */
    private function context(int $storeId): AccessContext
    {
        return new AccessContext((string)$storeId, $this->identity->hash(), $this->identity->actorId());
    }

    /**
     * Map trusted quote ownership to a core resource.
     *
     * @param Quote $quote
     * @return ResourceRef
     */
    private function resource(Quote $quote): ResourceRef
    {
        if (!$quote->getId()) {
            throw new GuardException(FailureReason::PermissionDenied, 'A persisted quote is required.');
        }
        return new ResourceRef('quote', (string)$quote->getId(), $quote->getCustomerId()
            ? (string)$quote->getCustomerId() : null);
    }
}
