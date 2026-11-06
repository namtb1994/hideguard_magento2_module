<?php

declare(strict_types=1);

namespace Hachitechs\Hideguard\Model;

use Hachitechs\HideguardPhp\Contracts\AtomicMutationContract;
use Hachitechs\HideguardPhp\Exceptions\FailureReason;
use Hachitechs\HideguardPhp\Exceptions\GuardException;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Framework\App\ResourceConnection;

class StoreLock implements AtomicMutationContract
{
    /**
     * Initialize platform dependencies.
     *
     * @param LockManagerInterface $locks
     * @param ResourceConnection $resource
     */
    public function __construct(
        private readonly LockManagerInterface $locks,
        private readonly ResourceConnection $resource
    ) {
    }

    /**
     * @inheritdoc
     */
    public function run(string $scopeId, callable $work): mixed
    {
        $db = $this->resource->getConnection();
        if ($db->getTransactionLevel() !== 0) {
            throw new \LogicException('Nested access mutations or external transactions are unsupported.');
        }
        $name = 'hachitech_antidetect_' . $scopeId;
        if (!$this->locks->lock($name, 1)) {
            throw new GuardException(FailureReason::Busy, 'Access protection is busy. Try again shortly.', 1);
        }
        try {
            $db->beginTransaction();
            try {
                try {
                    $result = $work();
                } catch (GuardException $error) {
                    $db->commit();
                    throw $error;
                }
                $db->commit();
                return $result;
            } catch (\Throwable $error) {
                if ($db->getTransactionLevel() > 0) {
                    $db->rollBack();
                }
                throw $error;
            }
        } finally {
            $this->locks->unlock($name);
        }
    }
}
