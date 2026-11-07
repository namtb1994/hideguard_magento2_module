<?php

declare(strict_types=1);

// Standalone P1/P2/P3 regression. All DB tables and barriers are randomly named and removed in finally.
require dirname(__DIR__, 6) . '/app/bootstrap.php';

use Hachitechs\HideguardPhp\Access\AccessContext;
use Hachitechs\HideguardPhp\Access\ResourceRef;
use Hachitechs\HideguardPhp\Configuration\ProtectionPolicy;
use Hachitechs\HideguardPhp\Configuration\RateLimits;
use Hachitechs\HideguardPhp\Exceptions\FailureReason;
use Hachitechs\HideguardPhp\Exceptions\GuardException;
use Hachitechs\HideguardPhp\Guard;
use Hachitechs\HideguardPhp\Storage\PdoMySqlStorage;
use Hachitechs\HideguardPhp\Verification\DevelopmentVerifier;
use Hachitechs\Hideguard\Model\StateRepository;
use Hachitechs\Hideguard\Model\StoreLock;
use Magento\Framework\App\Bootstrap;
use Magento\Framework\DB\Ddl\Table;

function ensureTraffic(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$om = Bootstrap::create(BP, $_SERVER)->getObjectManager();
$connection = $om->get(Magento\Framework\App\DeploymentConfig::class)->get('db/connection/default');
ensureTraffic(in_array($connection['host'], ['mysql', '127.0.0.1', 'localhost'], true), 'Local DB only');
$resource = $om->get('HachitechsHideguardResource');
$db = $resource->getConnection();
ensureTraffic((int)$db->fetchOne('SELECT COUNT(*) FROM ' . $resource->getTableName('store')
    . ' WHERE store_id IN (64999, 65000)') === 0, 'Reserved test scopes must not be live stores');
$worker = ($argv[1] ?? '') === '--worker';
$prefix = $worker ? $argv[3] : 'hg_load_' . bin2hex(random_bytes(6)) . '_';
ensureTraffic((bool)preg_match('/^hg_load_[a-f0-9]{12}_$/D', $prefix), 'Unsafe test prefix');
$pdo = new PDO('mysql:host=' . $connection['host'] . ';dbname=' . $connection['dbname'],
    $connection['username'], $connection['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$tables = [];
$children = [];
$barrier = BP . '/var/' . $prefix;
$make = function (string $adapter) use ($pdo, $prefix, $resource, $om): Guard {
    if ($adapter === 'pdo') {
        $storage = new PdoMySqlStorage($pdo, $prefix . 'pdo_');
        $atomic = $storage;
    } else {
        foreach (['session', 'block', 'rate', 'control', 'circuit'] as $suffix) {
            $resource->setMappedTableName('hachitech_antidetect_' . $suffix, $prefix . 'mag_' . $suffix);
        }
        $storage = new StateRepository($resource);
        $atomic = new StoreLock($om->get(Magento\Framework\Lock\LockManagerInterface::class), $resource);
    }
    $clock = new class implements Psr\Clock\ClockInterface {
        public function now(): DateTimeImmutable { return new DateTimeImmutable('@1000'); }
    };
    return Guard::factory()->withStorage($storage)->withAtomicMutation($atomic)->withClock($clock)
        ->withVerifier(new DevelopmentVerifier())->make();
};
$policy = new ProtectionPolicy(enabled: true, publicKey: 'fixture',
    limits: new RateLimits(scopeRequests: 100, identityRequests: 1000));
if ($worker) {
    $adapter = $argv[2];
    $id = $argv[4];
    $guard = $make($adapter);
    file_put_contents($barrier . '/' . $id, 'ready');
    $deadline = microtime(true) + 30;
    while (!file_exists($barrier . '/go')) {
        ensureTraffic(microtime(true) < $deadline, 'Barrier timeout');
        usleep(10000);
    }
    $mode = $argv[5] ?? 'quota';
    if ($mode !== 'quota') {
        try {
            if ($mode === 'capacity') {
                $guard->preflight(new AccessContext('64997', 'cap-' . $id),
                    new ProtectionPolicy(enabled: true, publicKey: 'fixture', limits: new RateLimits(sessionCapacity: 2)));
            } elseif ($mode === 'bind') {
                $guard->bindGrant(new AccessContext('64996', 'bind-' . $id),
                    new ProtectionPolicy(enabled: true, publicKey: 'fixture'), new ResourceRef('quote', '123'));
            } elseif ($mode === 'probe') {
                $circuit = $adapter === 'pdo'
                    ? new \Hachitechs\HideguardPhp\Storage\PdoCircuitBreakerStore($pdo, $prefix . 'pdo_circuit')
                    : new \Hachitechs\Hideguard\Model\CircuitStore($resource);
                $circuit->acquire(hash('sha256', 'shared'), 1030,
                    new \Hachitechs\HideguardPhp\Configuration\CircuitBreakerPolicy(threshold: 2));
            } else { throw new RuntimeException('Unknown worker mode'); }
            echo '1';
        } catch (GuardException $error) {
            ensureTraffic($mode === 'capacity' && $error->reason === FailureReason::CapacityReached, 'Unexpected denial');
            echo '0';
        } catch (\Hachitechs\HideguardPhp\Exceptions\ApiException $error) {
            ensureTraffic($mode === 'probe' && $error->statusCode === 503, 'Unexpected circuit error');
            echo '0';
        }
        exit;
    }
    $accepted = 0;
    for ($i = 0; $i < 50; $i++) {
        try {
            $guard->preflight(new AccessContext('65000', 'worker-' . $id), $policy);
            $accepted++;
        } catch (GuardException $error) {
            ensureTraffic($error->reason === FailureReason::RateLimited, 'Unexpected failure: ' . $error->reason->value);
        }
    }
    echo $accepted;
    exit;
}
function trafficWorkers(string $adapter, string $prefix, string $mode, string $barrier, array &$children): int
{
    mkdir($barrier);
    for ($i = 0; $i < 20; $i++) {
        $process = proc_open([PHP_BINARY, __FILE__, '--worker', $adapter, $prefix, (string)$i, $mode],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, BP);
        ensureTraffic(is_resource($process), 'Cannot start worker');
        fclose($pipes[0]); $children[] = [$process, $pipes];
    }
    $deadline = microtime(true) + 30;
    while (count(glob($barrier . '/*')) < 20) {
        foreach ($children as [$child, $streams]) {
            $status = proc_get_status($child);
            if (!$status['running']) {
                throw new RuntimeException('Worker exited before barrier: ' . stream_get_contents($streams[1]) . stream_get_contents($streams[2]));
            }
        }
        ensureTraffic(microtime(true) < $deadline, 'Workers not ready'); usleep(10000);
    }
    file_put_contents($barrier . '/go', 'go');
    $accepted = 0;
    foreach ($children as [$process, $pipes]) {
        $output = stream_get_contents($pipes[1]); $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        ensureTraffic(proc_close($process) === 0, $errors . $output);
        ensureTraffic(in_array($output, ['0', '1'], true), 'Invalid worker output: ' . $output);
        $accepted += (int)$output;
    }
    $children = [];
    foreach (glob($barrier . '/*') as $file) { unlink($file); } rmdir($barrier);
    return $accepted;
}
try {
    (new PdoMySqlStorage($pdo, $prefix . 'pdo_'))->initializeSchema();
    foreach (['session', 'block', 'rate', 'control'] as $suffix) {
        $tables[] = $prefix . 'pdo_' . $suffix;
    }
    (new \Hachitechs\HideguardPhp\Storage\PdoCircuitBreakerStore($pdo, $prefix . 'pdo_circuit'))->initializeSchema();
    $tables[] = $prefix . 'pdo_circuit';
    $schema = simplexml_load_file(dirname(__DIR__, 2) . '/etc/db_schema.xml');
    foreach ($schema->table as $declaration) {
        $name = $prefix . 'mag_' . str_replace('hachitech_antidetect_', '', (string)$declaration['name']);
        $table = $db->newTable($name)->setComment('Isolated high traffic test');
        foreach ($declaration->column as $column) {
            $type = (string)$column->attributes('http://www.w3.org/2001/XMLSchema-instance')['type'];
            $options = ['nullable' => (string)$column['nullable'] !== 'false'];
            if (isset($column['unsigned'])) { $options['unsigned'] = (string)$column['unsigned'] === 'true'; }
            if (isset($column['default'])) { $options['default'] = (string)$column['default']; }
            $table->addColumn((string)$column['name'], match ($type) {
                'varchar' => Table::TYPE_TEXT, 'smallint' => Table::TYPE_SMALLINT, 'int' => Table::TYPE_INTEGER, 'bigint' => Table::TYPE_BIGINT,
            }, $type === 'varchar' ? (int)$column['length'] : null, $options, (string)$column['comment']);
        }
        foreach ($declaration->constraint as $constraint) {
            $kind = (string)$constraint->attributes('http://www.w3.org/2001/XMLSchema-instance')['type'];
            if (!in_array($kind, ['primary', 'unique'], true)) { continue; }
            $columns = [];
            foreach ($constraint->column as $column) { $columns[] = (string)$column['name']; }
            $table->addIndex($db->getIndexName($name, $columns, $kind), $columns, ['type' => $kind]);
        }
        foreach ($declaration->index as $index) {
            $columns = [];
            foreach ($index->column as $column) { $columns[] = (string)$column['name']; }
            $table->addIndex($db->getIndexName($name, $columns), $columns);
        }
        $db->createTable($table);
        $tables[] = $name;
    }
    foreach (['pdo', 'magento'] as $adapter) {
        mkdir($barrier);
        for ($i = 0; $i < 20; $i++) {
            $pipes = [];
            $process = proc_open([PHP_BINARY, __FILE__, '--worker', $adapter, $prefix, (string)$i],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, BP);
            ensureTraffic(is_resource($process), 'Cannot start worker');
            fclose($pipes[0]);
            $children[] = [$process, $pipes];
        }
        $deadline = microtime(true) + 30;
        while (count(glob($barrier . '/*')) < 20) {
            ensureTraffic(microtime(true) < $deadline, 'Workers not ready');
            usleep(10000);
        }
        file_put_contents($barrier . '/go', 'go');
        $accepted = 0;
        foreach ($children as [$process, $pipes]) {
            $output = stream_get_contents($pipes[1]);
            $errors = stream_get_contents($pipes[2]);
            fclose($pipes[1]); fclose($pipes[2]);
            ensureTraffic(proc_close($process) === 0, $errors);
            ensureTraffic(ctype_digit($output), 'Invalid worker output: ' . $output);
            $accepted += (int)$output;
        }
        $children = [];
        ensureTraffic($accepted === 100, $adapter . ': wrong admissions: ' . $accepted);
        $table = $prefix . ($adapter === 'pdo' ? 'pdo_' : 'mag_') . 'rate';
        $scopeColumn = $adapter === 'pdo' ? 'scope_id' : 'store_id';
        $statement = $pdo->prepare('SELECT requests FROM `' . $table . '` WHERE ' . $scopeColumn . '=? AND bucket_hash=?');
        $statement->execute(['65000', hash('sha256', 'scope')]);
        ensureTraffic((int)$statement->fetchColumn() === 100, 'Persisted counter mismatch');
        echo 'PASS ' . $adapter . ': 20 processes, 1000 attempts, exactly 100 admissions; persisted counter=100.' . PHP_EOL;
        foreach (glob($barrier . '/*') as $file) { unlink($file); }
        rmdir($barrier);
        // Separate scope: an actor ban must revoke/bind durably and remain sticky after logout.
        $guard = $make($adapter);
        $context = new AccessContext('64999', 'sticky', '7');
        $attempt = $guard->preflight($context, $policy);
        $guard->report($context, $policy, ['visitorId' => 'visitor', 'verdict' => 'allow'], $attempt->verificationId);
        $guard->bindGrant($context, $policy, new ResourceRef('quote', '99', '7'));
        if ($adapter === 'pdo') {
            $storage = new PdoMySqlStorage($pdo, $prefix . 'pdo_');
        } else {
            $storage = new StateRepository($resource);
        }
        $storage->block('64999', 'visitor', '7', 2000);
        ensureTraffic($guard->preflight($context, $policy)->status === 'blocked', 'Missing block');
        // A different connection must see the revocation, not an in-memory object.
        $other = new PDO('mysql:host=' . $connection['host'] . ';dbname=' . $connection['dbname'],
            $connection['username'], $connection['password']);
        $sessionTable = $prefix . ($adapter === 'pdo' ? 'pdo_' : 'mag_') . 'session';
        $row = $other->query('SELECT * FROM `' . $sessionTable . '` WHERE ' . $scopeColumn . '=64999')->fetch(PDO::FETCH_ASSOC);
        ensureTraffic((int)$row['granted_until'] === 0 && $row['verification_id'] === null, 'Revocation not committed');
        ensureTraffic(($adapter === 'pdo' ? $row['resource_id'] : $row['quote_id']) === null, 'Binding not revoked');
        ensureTraffic($guard->inspect(new AccessContext('64999', 'sticky'), $policy)->status === 'blocked', 'Logout bypass');
        $storage->resetBlocks('64999');
        ensureTraffic($guard->inspect($context, $policy)->status !== 'blocked', 'Reset failed');
        echo 'PASS ' . $adapter . ': block revocation visible on second connection; logout and reset correct.' . PHP_EOL;
        $warmPolicy = new ProtectionPolicy(enabled: true, publicKey: 'fixture',
            limits: new RateLimits(scopeRequests: 1000, identityRequests: 1000));
        $warm = new AccessContext('64999', 'warm-p3');
        $attempt = $guard->preflight($warm, $warmPolicy);
        $guard->report($warm, $warmPolicy, ['visitorId' => 'warm-visitor', 'verdict' => 'allow'], $attempt->verificationId);
        $beforeState = $storage->session('64999', 'warm-p3');
        $beforeQuota = $storage->rate('64999', hash('sha256', 'scope'))->requests;
        $profiler = $db->getProfiler();
        $wasEnabled = $profiler->getEnabled();
        if ($adapter === 'magento') {
            $profiler->clear();
            $profiler->setEnabled(true);
        }
        for ($i = 0; $i < 100; $i++) {
            ensureTraffic($guard->preflight($warm, $warmPolicy)->status === 'allowed', 'Warm pass lost');
        }
        if ($adapter === 'magento') {
            $writes = 0;
            $rateWrites = 0;
            $profiles = $profiler->getQueryProfiles() ?: [];
            ensureTraffic(count($profiles) > 0, 'Profiler must record actual SQL');
            foreach ($profiles as $query) {
                if (str_contains($query->getQuery(), $sessionTable)
                    && preg_match('/^\s*INSERT\s+INTO\b/i', $query->getQuery()) === 1
                ) {
                    $writes++;
                }
                if (str_contains($query->getQuery(), $prefix . 'mag_rate')
                    && preg_match('/^\s*INSERT\s+INTO\b/i', $query->getQuery()) === 1
                ) {
                    $rateWrites++;
                }
            }
            $profiler->setEnabled($wasEnabled);
            ensureTraffic($writes === 0, 'Warm session still writes: ' . $writes);
            ensureTraffic($rateWrites === 200, 'Warm admission counters must still persist: ' . $rateWrites);
        }
        ensureTraffic($beforeState == $storage->session('64999', 'warm-p3'), 'Warm state changed');
        ensureTraffic($beforeQuota + 100 === $storage->rate('64999', hash('sha256', 'scope'))->requests, 'Warm quota skipped');
        $atomic = $adapter === 'pdo' ? $storage : new StoreLock(
            $om->get(Magento\Framework\Lock\LockManagerInterface::class), $resource
        );
        $atomic->run('64999', function () use ($storage): void {
            $storage->saveSession('64999', new \Hachitechs\HideguardPhp\Access\BrowserState('grace-boundary', lastSeen: 900));
            $storage->saveSession('64999', new \Hachitechs\HideguardPhp\Access\BrowserState('grace-old', lastSeen: 899));
            $storage->saveSession('64999', new \Hachitechs\HideguardPhp\Access\BrowserState('grace-block', blockedUntil: 2000, lastSeen: 1));
            $storage->saveSession('64999', new \Hachitechs\HideguardPhp\Access\BrowserState('grace-grant', grantedUntil: 2000, lastSeen: 1));
        });
        $guard->cleanup('64999', new ProtectionPolicy(limits: new RateLimits(sessionRetentionSeconds: 50)));
        ensureTraffic($storage->session('64999', 'grace-boundary') !== null, 'Cleanup too early');
        ensureTraffic($storage->session('64999', 'grace-old') === null, 'Cleanup failed');
        ensureTraffic($storage->session('64999', 'grace-block') !== null, 'Cleanup deleted active block');
        ensureTraffic($storage->session('64999', 'grace-grant') !== null, 'Cleanup deleted active grant');
        echo 'PASS ' . $adapter . ': 100 warm admissions preserve state and consume quota; cleanup grace protects boundary/grant/block.' . PHP_EOL;

        // Independent connections prove the fence protocol without timing guesses.
        if ($adapter === 'pdo') {
            $otherStorage = new PdoMySqlStorage($other, $prefix . 'pdo_', 0);
            $otherAtomic = $otherStorage;
        } else {
            $otherResource = $om->create(Magento\Framework\App\ResourceConnection::class);
            foreach (['session', 'block', 'rate', 'control', 'circuit'] as $suffix) {
                $otherResource->setMappedTableName('hachitech_antidetect_' . $suffix, $prefix . 'mag_' . $suffix);
            }
            ensureTraffic($otherResource->getConnection()->getConnection() !== $db->getConnection(), 'Connections must differ');
            $otherStorage = new StateRepository($otherResource);
            $otherAtomic = new StoreLock($om->get(Magento\Framework\Lock\LockManagerInterface::class), $otherResource);
        }
        $atomic->run('64995', function () use ($storage): void {
            $storage->saveSession('64995', new \Hachitechs\HideguardPhp\Access\BrowserState('A', grantedUntil: 2000));
            $storage->saveSession('64995', new \Hachitechs\HideguardPhp\Access\BrowserState('B', grantedUntil: 2000));
        });
        $q1 = new ResourceRef('quote', '501'); $q2 = new ResourceRef('quote', '502');
        $atomic->runBrowser('64995', 'A', $q1, function () use ($otherAtomic, $otherStorage, $storage, $q2, $adapter): void {
            $otherAtomic->runBrowser('64995', 'B', $q2, fn() => $otherStorage->bindResource('64995', 'B', $q2));
            ensureTraffic($storage->resource('64995', $q2)?->browserId === 'B', 'Independent binding waited for A');
            if ($adapter === 'magento') {
                try { $otherAtomic->runResource('064995', new ResourceRef('quote', '000501'), fn() => null);
                    throw new RuntimeException('Numeric alias bypassed quote mutex'); }
                catch (GuardException $error) { ensureTraffic($error->reason === FailureReason::Busy, 'Wrong alias error'); }
            }
            try { $otherAtomic->run('64995', fn() => null); throw new RuntimeException('Exclusive fence bypassed'); }
            catch (GuardException $error) { ensureTraffic($error->reason === FailureReason::Busy, 'Wrong fence error'); }
        });
        $atomic->run('64997', fn() => $storage->saveSession('64997', new \Hachitechs\HideguardPhp\Access\BrowserState('existing')));
        ensureTraffic(trafficWorkers($adapter, $prefix, 'capacity', $barrier, $children) === 1, 'Capacity overshoot');
        ensureTraffic($storage->sessionCount('64997') === 2, 'Capacity counter mismatch');
        try {
            $atomic->run('64997', function () use ($storage): void {
                $storage->saveSession('64997', new \Hachitechs\HideguardPhp\Access\BrowserState('rollback'));
                throw new RuntimeException('rollback fixture');
            });
        } catch (RuntimeException $error) { ensureTraffic($error->getMessage() === 'rollback fixture', 'Unexpected error'); }
        ensureTraffic($storage->sessionCount('64997') === 2 && $storage->session('64997', 'rollback') === null, 'Counter rollback failed');
        $controlTable = $prefix . ($adapter === 'pdo' ? 'pdo_' : 'mag_') . 'control';
        $pdo->exec('UPDATE `' . $controlTable . '` SET value=99 WHERE ' . $scopeColumn . "=64997 AND name='sessions'");
        ensureTraffic($atomic->run('64997', fn() => $storage->reconcileSessionCount('64997')) === 2, 'Counter repair failed');
        $bindingPolicy = new ProtectionPolicy(enabled: true, publicKey: 'fixture');
        $atomic->run('64996', function () use ($storage, $bindingPolicy): void {
            for ($i = 0; $i < 20; $i++) {
                $storage->saveSession('64996', new \Hachitechs\HideguardPhp\Access\BrowserState('bind-' . $i,
                    grantedUntil: 2000, policyHash: $bindingPolicy->fingerprint(), verification: 'verified'));
            }
        });
        ensureTraffic(trafficWorkers($adapter, $prefix, 'bind', $barrier, $children) === 20, 'Binding failed');
        $bound = new ResourceRef('quote', '123');
        ensureTraffic($storage->resource('64996', $bound) !== null, 'Binding missing');
        $bindingColumn = $adapter === 'pdo' ? 'resource_id' : 'quote_id';
        ensureTraffic((int)$pdo->query('SELECT COUNT(*) FROM `' . $sessionTable . '` WHERE '
            . $scopeColumn . '=64996 AND ' . $bindingColumn . '=123')->fetchColumn() === 1, 'Duplicate binding');
        $guard->consumeGrant('64996', $bound);
        ensureTraffic($storage->resource('64996', $bound) === null, 'Consumed binding survived');
        $atomic->run('64998', function () use ($storage): void {
            for ($i = 0; $i < 7; $i++) { $storage->saveSession('64998', new \Hachitechs\HideguardPhp\Access\BrowserState('old-' . $i)); }
            $storage->saveSession('64998', new \Hachitechs\HideguardPhp\Access\BrowserState('attempt', verificationUntil: 2000));
        });
        $batchPolicy = new ProtectionPolicy(limits: new RateLimits(sessionRetentionSeconds: 10));
        $totals = [];
        do { $result = $guard->cleanupBatch('64998', $batchPolicy, 3); $totals[] = $result->total(); }
        while ($result->hasMore);
        ensureTraffic($totals === [3, 3, 1] && $storage->sessionCount('64998') === 1, 'Batch/counter mismatch');
        ensureTraffic($storage->session('64998', 'attempt') !== null, 'Active attempt was deleted');
        $circuit = $adapter === 'pdo'
            ? new \Hachitechs\HideguardPhp\Storage\PdoCircuitBreakerStore($pdo, $prefix . 'pdo_circuit')
            : new \Hachitechs\Hideguard\Model\CircuitStore($resource);
        $cbPolicy = new \Hachitechs\HideguardPhp\Configuration\CircuitBreakerPolicy(threshold: 2);
        $key = hash('sha256', 'shared');
        for ($i = 0; $i < 2; $i++) { $permit = $circuit->acquire($key, 1000, $cbPolicy); $circuit->record($key, $permit, true, 1000, $cbPolicy); }
        ensureTraffic(trafficWorkers($adapter, $prefix, 'probe', $barrier, $children) === 1, 'Parallel half-open probes');
        if ($adapter === 'magento') {
            $resource->_resetState();
            $storage->_resetState(); $atomic->_resetState(); $circuit->_resetState();
            foreach (['session', 'block', 'rate', 'control', 'circuit'] as $suffix) {
                $resource->setMappedTableName('hachitech_antidetect_' . $suffix, $prefix . 'mag_' . $suffix);
            }
            $atomic->run('64997', fn() => $storage->saveSession('64997',
                new \Hachitechs\HideguardPhp\Access\BrowserState('after-reset')));
            ensureTraffic($storage->sessionCount('64997') === 3, 'Request reset left counter on a stale PDO');
            $circuit->acquire(hash('sha256', 'after-reset'), 1000, $cbPolicy);
        }
        echo 'PASS ' . $adapter . ': B1 independent binding + exclusive fence; 20-process capacity/binding/probe; B2 rollback/repair; B3 [3,3,1] and active attempt.' . PHP_EOL;


    }
} finally {
    foreach ($children as [$process, $pipes]) {
        if (is_resource($process)) { proc_terminate($process); }
        foreach ($pipes as $pipe) { if (is_resource($pipe)) { fclose($pipe); } }
        if (is_resource($process)) { proc_close($process); }
    }
    foreach (glob($barrier . '/*') as $file) { unlink($file); }
    if (is_dir($barrier)) { rmdir($barrier); }
    foreach ($tables as $table) { $pdo->exec('DROP TABLE IF EXISTS `' . $table . '`'); }
}
