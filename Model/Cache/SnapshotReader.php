<?php

declare(strict_types=1);

namespace Hachitechs\Hideguard\Model\Cache;

use Hachitechs\Hideguard\Model\Config;
use Hachitechs\Hideguard\Model\PageRules;
use Magento\Framework\App\Config\ReinitableConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;

class SnapshotReader
{
    public const MAX_REWRITES = 2000;

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly Config $config,
        private readonly PageRules $rules,
        private readonly StoreManagerInterface $stores,
        private readonly ReinitableConfigInterface $scopeConfig
    ) {
    }

    /** Locking reads see pending outer commits; the caller also holds the policy file lock. */
    public function read(): array
    {
        /** @var \Magento\Framework\DB\Adapter\Pdo\Mysql $connection */
        $connection = $this->resource->getConnection();
        $pdo = $connection->getConnection();
        if (!$pdo instanceof \PDO) {
            throw new \LogicException('Snapshot export requires a MySQL PDO connection.');
        }
        if ($pdo->inTransaction()) {
            throw new \LogicException('Export requires a dedicated connection.');
        }
        $wait = (int)$pdo->query('SELECT @@SESSION.innodb_lock_wait_timeout')->fetchColumn();
        $pdo->exec('SET SESSION innodb_lock_wait_timeout=2');
        try {
            $pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
            $pdo->beginTransaction();
            foreach (['core_config_data', 'store', 'store_group', 'store_website'] as $table) {
                // Do not use a consistent snapshot of data written by a still-open outer transaction.
                $statement = $pdo->query('SELECT * FROM ' . $this->table($table) . ' FOR SHARE');
                while ($statement->fetch(\PDO::FETCH_ASSOC) !== false) {
                }
                $statement->closeCursor();
            }
            $this->scopeConfig->reinit();
            $this->stores->reinitStores();
            $snapshot = [];
            /** @var \Magento\Store\Model\Store $store */
            foreach ($this->stores->getStores() as $store) {
                $id = (int)$store->getId();
                $settings = $this->config->settings($id);
                $patterns = ['antidetectbrowser/*'];
                if ($settings['enabled']) {
                    $patterns = [...$patterns, ...$settings['pageRules'],
                        ...$this->rules->nativePatterns($settings['points'])];
                    if (
                        !in_array('*', $patterns, true)
                        && array_intersect(['product', 'cart', 'checkout'], $settings['points']) !== []
                    ) {
                        $statement = $pdo->prepare('SELECT request_path,target_path FROM ' . $this->table('url_rewrite')
                            . ' WHERE store_id=? LIMIT ' . (self::MAX_REWRITES + 1) . ' FOR SHARE');
                        $statement->execute([$id]);
                        $rewrites = $statement->fetchAll(\PDO::FETCH_ASSOC);
                        if (count($rewrites) > self::MAX_REWRITES) {
                            $patterns[] = '*'; // Never publish a partial alias list.
                        } else {
                            foreach ($rewrites as $rewrite) {
                                if ($this->rules->protectsTarget($rewrite['target_path'], $settings['points'])) {
                                    // An asterisk in a literal alias overmatches safely in the exported glob.
                                    $patterns[] = $rewrite['request_path'];
                                }
                            }
                        }
                    }
                }
                $snapshot[] = ['store_id' => $id, 'patterns' => array_values(array_unique($patterns)),
                    'bases' => array_values(array_unique([
                    $store->getBaseUrl(UrlInterface::URL_TYPE_LINK, false),
                    $store->getBaseUrl(UrlInterface::URL_TYPE_LINK, true),
                ]))];
            }
            $pdo->commit();
            return $snapshot;
        } finally {
            $this->rollbackIfActive($pdo);
            $pdo->exec('SET SESSION innodb_lock_wait_timeout=' . $wait);
        }
    }

    private function rollbackIfActive(\PDO $pdo): void
    {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }

    private function table(string $name): string
    {
        return $this->resource->getConnection()->quoteIdentifier($this->resource->getTableName($name));
    }
}
