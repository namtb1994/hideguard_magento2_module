<?php

declare(strict_types=1);

// Controlled origin and activation driver for the real-proxy test. Never edits live configuration/security data.
require dirname(__DIR__, 6) . '/app/bootstrap.php';
require_once __DIR__ . '/FixtureVerdictVerifier.php';

use Hachitechs\Hideguard\Model\Cache\{PolicyFiles, SnapshotReader, ManagedVcl, Activation};
use Hachitechs\Hideguard\Model\{Config, PageRules};
use Hachitechs\Hideguard\Plugin\{CachePolicyMutation, PrivatePages};
use Hachitechs\HideguardPhp\Rules\PathMatcher;
use Hachitechs\HideguardPhp\Access\AccessContext;
use Hachitechs\HideguardPhp\Configuration\ProtectionPolicy;
use Hachitechs\HideguardPhp\Guard;
use Hachitechs\HideguardPhp\Storage\PdoMySqlStorage;
use Hachitechs\HideguardPhp\Configuration\RateLimits;
use Hachitechs\Hideguard\Test\Integration\FixtureVerdictVerifier;
use Magento\Framework\App\{DeploymentConfig, Filesystem\DirectoryList};
use Magento\Framework\HTTP\Client\{Curl, CurlFactory};
use Magento\PageCache\Model\Cache\Server;
use PHPUnit\Framework\TestCase;

$directory = (string)getenv('HG_M1_DIR');
if (!preg_match('#^' . preg_quote(BP, '#') . '/var/hg_m1_[a-f0-9]{12}$#D', $directory)) {
    throw new RuntimeException('Use a random isolated var/hg_m1_<12 hex> directory.');
}
$test = new class('fixture') extends TestCase {
    public function stub(string $type): object { return $this->createMock($type); }
};
$deployment = $test->stub(DeploymentConfig::class);
$deployment->method('get')->willReturnCallback(fn($key, $default = null) => match ($key) {
    'hideguard/cache/managed' => true, 'hideguard/cache/shared_path' => $directory,
    'http_cache_hosts' => [['host' => 'hideguard-m1-proxy', 'port' => 80]], default => $default,
});
$files = new PolicyFiles($test->stub(DirectoryList::class), $deployment);
$stateFile = $directory . '/origin.json';

$database = (require BP . '/app/etc/env.php')['db']['connection']['default'];
if (!in_array($database['host'], ['mysql', 'localhost', '127.0.0.1'], true)) {
    throw new RuntimeException('Fixture requires a local MySQL host.');
}
$pdo = new PDO('mysql:host=' . $database['host'] . ';dbname=' . $database['dbname'],
    $database['username'], $database['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$tablePrefix = basename($directory) . '_guard_';
$storage = new PdoMySqlStorage($pdo, $tablePrefix);
$clock = new class implements Psr\Clock\ClockInterface {
    public function now(): DateTimeImmutable { return new DateTimeImmutable('@1000'); }
};
$guard = Guard::factory()->withStorage($storage)->withClock($clock)->withVerifier(new FixtureVerdictVerifier())->make();
$policy = new ProtectionPolicy(enabled: true, publicKey: 'fixture', signaturePublicKey: 'fixture-signature',
    limits: new RateLimits(scopeRequests: 100000, identityRequests: 100000));

if (PHP_SAPI === 'cli') {
    $action = $argv[1] ?? 'prepare';
    if ($action === 'prepare') {
        $files->locked(function () use ($files): void {
            $files->invalidate();
            $files->write('origin.json', json_encode(['offerProtected' => false]));
        });
        foreach (['session', 'block', 'rate', 'control'] as $suffix) { $pdo->exec('DROP TABLE IF EXISTS `' . $tablePrefix . $suffix . '`'); }
        $storage->initializeSchema();
        foreach (['1', '2'] as $scope) {
            foreach (['A', 'B'] as $browser) {
                $context = new AccessContext($scope, $browser);
                $attempt = $guard->preflight($context, $policy);
                $guard->report($context, $policy, ['visitorId' => 'fixture-' . $browser,
                    'verdict' => $browser === 'B' ? 'block' : 'allow'], $attempt->verificationId);
            }
        }
    }
    if ($action === 'cleanup') {
        foreach (['session', 'block', 'rate', 'control'] as $suffix) { $pdo->exec('DROP TABLE IF EXISTS `' . $tablePrefix . $suffix . '`'); }
        echo 'fixture tables removed'; exit;
    }
    if ($action === 'activate') {
        $servers = $test->stub(Server::class);
        $servers->method('getUris')->willReturn(['http://hideguard-m1-proxy:80/']);
        $clients = $test->stub(CurlFactory::class);
        $clients->method('create')->willReturnCallback(fn() => new Curl());
        (new Activation($files, $servers, $clients, $deployment))->activate($argv[2]);
        echo 'activated'; exit;
    }
    if ($action === 'mutate') {
        (new CachePolicyMutation($files))->aroundReplace(new stdClass(), fn() =>
            file_put_contents($stateFile, json_encode(['offerProtected' => true])));
        echo 'mutated'; exit;
    }
    if ($action === 'invalidate') { $files->locked(fn() => $files->invalidate()); echo 'invalidated'; exit; }
    $origin = json_decode(file_get_contents($stateFile), true, 16, JSON_THROW_ON_ERROR);
    $patterns = ['hideguard/*', 'catalog/product/*', 'diamond.html', 'secret/*'];
    if ($origin['offerProtected']) { $patterns[] = 'offer.html'; }
    $reader = $test->stub(SnapshotReader::class);
    $reader->method('read')->willReturn([
        ['store_id' => 1, 'bases' => ['https://shop.example/en/', 'https://secure.example/en/'], 'patterns' => $patterns],
        ['store_id' => 2, 'bases' => ['https://other.example/fr/'], 'patterns' => ['*']],
    ]);
    $template = <<<'VCL'
vcl 4.0;
import std;
backend default { .host="php"; .port="8099"; }
acl purge { "127.0.0.1"; "172.16.0.0"/12; }
sub vcl_recv {
    if (req.method == "PURGE") { if (client.ip !~ purge) { return (synth(405)); } return (synth(200)); }
    unset req.http.Cookie;
    return (hash);
}
sub vcl_backend_response {
    set beresp.ttl = 1m;
    if (beresp.http.Cache-Control ~ "private|no-store" || beresp.status != 200) {
        set beresp.uncacheable = true;
        set beresp.ttl = 0s;
    }
}
sub vcl_deliver {
    if (obj.hits > 0) { set resp.http.X-Cache = "HIT"; }
    else { set resp.http.X-Cache = "MISS"; }
}
VCL;
    if ($action === 'baseline') {
        $config = $test->stub(Config::class);
        $config->method('settings')->willReturn(['enabled' => true, 'points' => ['product'], 'pageRules' => []]);
        $store = $test->stub(Magento\Store\Model\Store::class);
        $store->method('getId')->willReturn(1); $store->method('getBaseUrl')->willReturn('https://shop.example/en/');
        $stores = $test->stub(Magento\Store\Model\StoreManagerInterface::class);
        $stores->method('getStores')->willReturn([$store]);
        $legacy = new Hachitechs\Hideguard\Plugin\VarnishAccess($config, $stores, new PathMatcher());
        file_put_contents($directory . '/baseline.vcl', $legacy->afterGenerateVcl(
            $test->stub(Magento\PageCache\Model\VclGeneratorInterface::class), $template));
        echo 'baseline exported'; exit;
    }
    $vcl = (new ManagedVcl($files, $reader, new PathMatcher()))->generate($template);
    file_put_contents($directory . '/test.vcl', $vcl);
    preg_match('/managed snapshot: ([a-f0-9]{64})/', $vcl, $match);
    file_put_contents($directory . '/id', $match[1]);
    echo $match[1]; exit;
}

// The origin uses the real PageRules/PrivatePages and a real Guard with server-seeded fixture verdicts.
$origin = json_decode(file_get_contents($stateFile), true, 16, JSON_THROW_ON_ERROR);
$storeId = str_starts_with($_SERVER['HTTP_HOST'] ?? '', 'other.example') ? 2 : 1;
$path = preg_replace('#^/(en|fr)/#i', '/', parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
$config = $test->stub(Config::class);
$config->method('settings')->willReturn(['enabled' => true, 'points' => $storeId === 2 ? ['all_pages'] : ['product'],
    'pageRules' => ['secret/*']]);
$finder = $test->stub(Magento\UrlRewrite\Model\UrlFinderInterface::class);
$finder->method('findOneByData')->willReturnCallback(function ($data) use ($origin) {
    $target = match ($data['request_path']) {
        'diamond.html' => 'catalog/product/view/id/1',
        'offer.html' => $origin['offerProtected'] ? 'catalog/product/view/id/2' : 'cms/page/view/page_id/2',
        default => null,
    };
    return $target === null ? null : new Magento\UrlRewrite\Service\V1\Data\UrlRewrite(['target_path' => $target],
        new Magento\Framework\Serialize\Serializer\Json());
});
$rules = new PageRules($config, $finder, new PathMatcher());
$request = $test->stub(Magento\Framework\App\Request\Http::class);
$request->method('getPathInfo')->willReturn($path);
$stores = $test->stub(Magento\Store\Model\StoreManagerInterface::class);
$store = $test->stub(Magento\Store\Model\Store::class); $store->method('getId')->willReturn($storeId);
$stores->method('getStore')->willReturn($store);
$response = $test->stub(Magento\Framework\App\Response\Http::class);
$response->method('setHeader')->willReturnCallback(function ($name, $value, $replace = true) use ($response) {
    header($name . ': ' . $value, $replace); return $response;
});
(new PrivatePages($rules, $request, $stores))->beforeSendResponse($response);
$counter = fopen($directory . '/backend-count', 'c+'); flock($counter, LOCK_EX);
$count = (int)stream_get_contents($counter) + 1; rewind($counter); ftruncate($counter, 0); fwrite($counter, (string)$count);
flock($counter, LOCK_UN); fclose($counter);
header('X-Backend-Count: ' . $count);
if ($rules->protects($path, $storeId)) {
    $browser = ($_COOKIE['browser'] ?? '') === 'B' ? 'B' : 'A';
    $context = new AccessContext((string)$storeId, $browser);
    $decision = $guard->preflight($context, $policy);
    if ($decision->status === 'blocked') { http_response_code(401); echo 'BLOCKED:B'; }
    else { echo 'PROTECTED:A verificationId=' . $decision->verificationId; }
} elseif ($rules->isGatePath($path)) { echo 'PRIVATE-GATE'; }
else { header('Cache-Control: public, max-age=60'); echo 'PUBLIC:' . $path; }
