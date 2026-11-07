<?php

declare(strict_types=1);

// MySQL locking-read/mutation coverage. Only randomly named fixture tables and a private temp directory are used.
require dirname(__DIR__, 6) . '/app/bootstrap.php';

use Hachitechs\Hideguard\Model\Cache\{PolicyFiles, SnapshotReader, ManagedVcl};
use Hachitechs\Hideguard\Model\{Config, PageRules};
use Hachitechs\Hideguard\Plugin\CachePolicyMutation;
use Hachitechs\HideguardPhp\Rules\PathMatcher;
use Magento\Framework\App\{Bootstrap, DeploymentConfig, ResourceConnection, Filesystem\DirectoryList};
use PHPUnit\Framework\TestCase;

function checkM1(bool $condition, string $message): void
{ if (!$condition) { throw new RuntimeException($message); } }
$test = new class('fixture') extends TestCase {
    public function stub(string $type): object { return $this->createMock($type); }
};
$om = Bootstrap::create(BP, $_SERVER)->getObjectManager();
$config = $om->get(DeploymentConfig::class)->get('db/connection/default');
checkM1(in_array($config['host'], ['mysql', 'localhost', '127.0.0.1'], true), 'Local DB only');
$writer = new PDO('mysql:host=' . $config['host'] . ';dbname=' . $config['dbname'], $config['username'], $config['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$resource = $om->create(ResourceConnection::class);
$prefix = 'hg_m1_' . bin2hex(random_bytes(6));
$directory = BP . '/var/' . $prefix;
$deployment = $test->stub(DeploymentConfig::class);
$deployment->method('get')->willReturnCallback(fn($key, $default = null) => match ($key) {
    'hideguard/cache/managed' => true, 'hideguard/cache/shared_path' => $directory, default => $default,
});
$files = new PolicyFiles($om->get(DirectoryList::class), $deployment);
$tables = [];
try {
    foreach (['core_config_data', 'store', 'store_group', 'store_website', 'url_rewrite'] as $name) {
        $table = $prefix . '_' . $name;
        $resource->setMappedTableName($name, $table);
        $writer->exec('CREATE TABLE `' . $table . '` (id INT NOT NULL PRIMARY KEY, store_id INT NOT NULL DEFAULT 1,'
            . ' request_path VARCHAR(200),target_path VARCHAR(200), KEY scope_id(store_id)) ENGINE=InnoDB');
        $tables[] = $table;
        if ($name !== 'url_rewrite') { $writer->exec('INSERT INTO `' . $table . '` (id) VALUES (1)'); }
    }
    $rewriteTable = '`' . $prefix . '_url_rewrite`';
    $writer->exec('INSERT INTO ' . $rewriteTable . " (id,request_path,target_path) VALUES (1,'diamond.html','catalog/product/view/id/1'),"
        . "(2,'offer.html','cms/page/view/page_id/1')");
    $settings = $test->stub(Config::class);
    $settings->method('settings')->willReturn(['enabled' => true, 'points' => ['product'], 'pageRules' => ['private/*']]);
    $stores = $test->stub(Magento\Store\Model\StoreManagerInterface::class);
    $store = $test->stub(Magento\Store\Model\Store::class);
    $store->method('getId')->willReturn(1); $store->method('getBaseUrl')->willReturn('https://fixture.example/en/');
    $stores->method('getStores')->willReturn([$store]);
    $scope = $test->stub(Magento\Framework\App\Config\ReinitableConfigInterface::class);
    $rules = new PageRules($settings, $test->stub(Magento\UrlRewrite\Model\UrlFinderInterface::class), new PathMatcher());
    $reader = new SnapshotReader($resource, $settings, $rules, $stores, $scope);
    $snapshot = $reader->read();
    checkM1(in_array('diamond.html', $snapshot[0]['patterns'], true), 'Protected alias missing');
    checkM1(!in_array('offer.html', $snapshot[0]['patterns'], true), 'Public alias overprotected');
    $vcl = new ManagedVcl($files, $reader, new PathMatcher());
    $template = 'import std; acl purge { "127.0.0.1"; } sub vcl_recv {}';
    $vcl->generate($template);
    $before = count(glob($directory . '/snapshot-*'));
    $writer->beginTransaction();
    (new CachePolicyMutation($files))->aroundReplace(new stdClass(), fn() =>
        $writer->exec('UPDATE ' . $rewriteTable . " SET target_path='catalog/product/view/id/2' WHERE id=2"));
    $start = microtime(true);
    try { $vcl->generate($template); throw new RuntimeException('Uncommitted rewrite exported'); }
    catch (PDOException $error) { checkM1((int)$error->errorInfo[1] === 1205, 'Wrong lock error'); }
    checkM1(microtime(true) - $start < 5, 'Unbounded snapshot wait');
    checkM1(count(glob($directory . '/snapshot-*')) === $before && !glob($directory . '/ready-*'), 'Failed export published state');
    $writer->commit();
    checkM1(in_array('offer.html', $reader->read()[0]['patterns'], true), 'Committed rewrite not in snapshot');
    echo "PASS pending outer UPDATE cannot publish; bounded failure leaves no readiness; committed alias is protected.\n";
    $writer->beginTransaction();
    (new CachePolicyMutation($files))->aroundReplace(new stdClass(), fn() =>
        $writer->exec('INSERT INTO ' . $rewriteTable . " (id,request_path,target_path) VALUES (3,'new.html','catalog/product/view/id/3')"));
    try { $reader->read(); throw new RuntimeException('Uncommitted alias insert exported'); }
    catch (PDOException $error) { checkM1((int)$error->errorInfo[1] === 1205, 'Wrong insert lock error'); }
    $writer->rollBack();
    checkM1(!in_array('new.html', $reader->read()[0]['patterns'], true), 'Rolled back alias persisted');
    echo "PASS pending outer INSERT cannot publish; rollback preserves the previous routes.\n";
    $writer->beginTransaction();
    $insert = $writer->prepare('INSERT INTO ' . $rewriteTable . ' (id,request_path,target_path) VALUES (?,?,?)');
    for ($i = 3; $i <= SnapshotReader::MAX_REWRITES + 1; $i++) {
        $insert->execute([$i, 'product-' . $i . '.html', 'catalog/product/view/id/' . $i]);
    }
    $writer->commit();
    checkM1(in_array('*', $reader->read()[0]['patterns'], true), 'Over-budget aliases must fall back to whole-store pass');
    echo "PASS alias budget falls back to whole-store pass instead of publishing a partial set.\n";
} finally {
    if ($writer->inTransaction()) { $writer->rollBack(); }
    foreach ($tables as $table) { $writer->exec('DROP TABLE IF EXISTS `' . $table . '`'); }
    foreach (glob($directory . '/*') ?: [] as $file) { unlink($file); }
    foreach (glob($directory . '/.write-*') ?: [] as $file) { unlink($file); }
    if (is_dir($directory)) { rmdir($directory); }
}
