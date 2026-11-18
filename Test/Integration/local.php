<?php

declare(strict_types=1);

// Isolated MySQL temporary tables and process-only module/config overrides; no live records are changed.
require dirname(__DIR__, 6) . '/app/bootstrap.php';

use Hachitechs\HideguardPhp\Exceptions\GuardException;
use Hachitechs\Hideguard\Model\Access;
use Hachitechs\Hideguard\Model\BrowserIdentity;
use Hachitechs\Hideguard\Model\Config;
use Hachitechs\HideguardPhp\Guard;
use Hachitechs\HideguardPhp\Access\BrowserState;
use Hachitechs\HideguardPhp\Exceptions\FailureReason;
use Hachitechs\HideguardPhp\Verification\VerdictPolicy;
use Hachitechs\HideguardPhp\Configuration\ProtectionPolicy;
use Hachitechs\Hideguard\Model\StateRepository;
use Hachitechs\Hideguard\Model\StoreLock;
use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Ddl\Table;

function checkAb(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
}

$registrar = new \Magento\Framework\Component\ComponentRegistrar();
if ($registrar->getPath($registrar::MODULE, 'Hachitechs_Hideguard') === null) {
    $registrar::register($registrar::MODULE, 'Hachitechs_Hideguard', dirname(__DIR__, 2));
}
$deployment = require BP . '/app/etc/config.php';
$deployment['modules']['Hachitechs_Hideguard'] = 1;
$cachePath = BP . '/var/hachitech-hideguard-test-' . bin2hex(random_bytes(5));
// Load current XML in this process even when the checkout has compiled DI for other modules.
class LocalAbEnvironmentFactory extends \Magento\Framework\App\EnvironmentFactory
{
    public function createEnvironment()
    {
        return new \Magento\Framework\App\ObjectManager\Environment\Developer($this);
    }
}
$parameters = array_merge($_SERVER, [
    'MAGE_CONFIG' => ['modules' => $deployment['modules'], 'cache' => ['frontend' => [
        'default' => ['id_prefix' => 'ab_test_' . bin2hex(random_bytes(5))],
    ]]],
    'MAGE_MODE' => 'developer',
    'MAGE_DIRS' => [DirectoryList::CACHE => ['path' => $cachePath],
        DirectoryList::GENERATED_CODE => ['path' => $cachePath . '/code'],
        DirectoryList::GENERATED_METADATA => ['path' => $cachePath . '/metadata']],
]);
$factory = new class(
    Bootstrap::createFilesystemDirectoryList(BP, $parameters),
    Bootstrap::createFilesystemDriverPool($parameters),
    Bootstrap::createConfigFilePool()
) extends \Magento\Framework\App\ObjectManagerFactory {
    protected $envFactoryClassName = LocalAbEnvironmentFactory::class;
};
$om = Bootstrap::create(BP, $parameters, $factory)->getObjectManager();
$om->get(\Magento\Framework\App\State::class)->setAreaCode('frontend');
$om->configure($om->get(\Magento\Framework\ObjectManager\ConfigLoaderInterface::class)->load('frontend'));
checkAb($om->get(\Magento\Framework\Module\ModuleListInterface::class)->has('Hachitechs_Hideguard'),
    'Fixture deployment must register the module');
checkAb($om->get(\Magento\Framework\ObjectManager\ConfigInterface::class)
    ->getInstanceType('HachitechsHideguardGuard') === Guard::class, 'Fixture must load the current module DI XML');
$stores = $om->get(\Magento\Store\Model\StoreManagerInterface::class);
checkAb(in_array(parse_url($stores->getStore()->getBaseUrl(), PHP_URL_HOST), ['troyclancy.local', 'troyclancylocal.com', 'magentotest.com'], true), 'Allowlisted local Magento host required');
$resource = $om->get('HachitechsHideguardResource');
$db = $resource->getConnection();
$schema = simplexml_load_file(dirname(__DIR__, 2) . '/etc/db_schema.xml');
$tables = [];
try {
foreach ($schema->table as $declaration) {
    $name = 'tmp_ab_' . bin2hex(random_bytes(5)) . '_' . str_replace('hachitech_hideguard_', '', (string)$declaration['name']);
    $table = $db->newTable($name)->setComment('Isolated HideGuard test');
    foreach ($declaration->column as $column) {
        $type = (string)$column->attributes('http://www.w3.org/2001/XMLSchema-instance')['type'];
        $options = ['nullable' => (string)$column['nullable'] !== 'false'];
        if (isset($column['unsigned'])) { $options['unsigned'] = (string)$column['unsigned'] === 'true'; }
        if (isset($column['default'])) { $options['default'] = (string)$column['default']; }
        $table->addColumn((string)$column['name'], match ($type) {
            'varchar' => Table::TYPE_TEXT, 'smallint' => Table::TYPE_SMALLINT, 'int' => Table::TYPE_INTEGER,
            'bigint' => Table::TYPE_BIGINT,
        }, $type === 'varchar' ? (int)$column['length'] : null, $options, (string)$column['comment']);
    }
    $primary = [];
    foreach ($declaration->constraint->column as $column) { $primary[] = (string)$column['name']; }
    $table->addIndex($db->getIndexName($name, $primary, \Magento\Framework\DB\Adapter\AdapterInterface::INDEX_TYPE_PRIMARY), $primary, ['type' => \Magento\Framework\DB\Adapter\AdapterInterface::INDEX_TYPE_PRIMARY]);
    $db->createTemporaryTable($table);
    $tables[] = $name;
    $resource->setMappedTableName((string)$declaration['name'], $name);
}

$config = new class($om->get(\Magento\Framework\App\Config\ScopeConfigInterface::class), $om->get(\Magento\Framework\Encryption\EncryptorInterface::class), $om->get(\Hachitechs\Hideguard\Model\Config\RuntimeSettings::class)) extends Config {
    public bool $debug = true;
    public array $value = [
        'enabled' => true, 'points' => ['all_pages', 'complete_order'],
        'pageRules' => [], 'blockDuration' => 900, 'passDuration' => 0, 'keys' => [
            'publicKey' => 'production-key', 'signaturePublicKey' => '',
        ],
    ];
    public function settings(?int $storeId = null): array { return $this->value; }
    public function apiKey(int $storeId): string { return 'fixture-server-key'; }
    public function debugEnabled(int $storeId): bool { return $this->debug; }
};
$identity = new class($om->get(\Magento\Customer\Model\Session::class)) extends BrowserIdentity {
    public string $browser = 'browser-a';
    public int $customer = 0;
    public function hash(): string { return hash('sha256', $this->browser); }
    public function actorId(): ?string { return $this->customer ? (string)$this->customer : null; }
};
$states = new StateRepository($resource);
$lock = new StoreLock($om->get(\Magento\Framework\Lock\LockManagerInterface::class), $resource);
$guard = Guard::factory()->withStorage($states)->withAtomicMutation($lock)
    ->withVerifier(new \Hachitechs\HideguardPhp\Verification\Ed25519Verifier())->make();
$signingPair = sodium_crypto_sign_keypair();
$signingSecret = sodium_crypto_sign_secretkey($signingPair);
$signatureKey = "-----BEGIN PUBLIC KEY-----\n" . base64_encode(
    hex2bin('302a300506032b6570032100') . sodium_crypto_sign_publickey($signingPair)
) . "\n-----END PUBLIC KEY-----";
$config->value['keys']['signaturePublicKey'] = $signatureKey;
$signed = function (string $verdict, string $visitor) use ($signingSecret): array {
    $payload = ['siteId' => 'site_local', 'environment' => 'production',
        'requestId' => bin2hex(random_bytes(16)), 'visitorId' => $visitor,
        'verdict' => $verdict, 'score' => 35, 'reasons' => [['code' => 'fixture']]];
    $timestamp = time();
    $message = implode("\n", ['hideguard-collect-ed25519-v2', 'alg=ed25519', 'kid=fixture', 't=' . $timestamp,
        'site=' . $payload['siteId'], 'environment=' . $payload['environment'],
        'request=' . $payload['requestId'], 'visitor=' . $visitor, 'verdict=' . $verdict,
        'score=35', 'reasons=["fixture"]']);
    $payload['signature'] = 'v=2,alg=ed25519,kid=fixture,t=' . $timestamp . ',sig='
        . rtrim(strtr(base64_encode(sodium_crypto_sign_detached($message, $signingSecret)), '+/', '-_'), '=');
    return $payload;
};
$access = new Access($config, $guard, $identity, $stores);
$challengeStatus = in_array('challenge', VerdictPolicy::BLOCK_VERDICTS, true) ? 'blocked' : 'allowed';
function reportAb(Access $access, int $storeId, array $payload): array
{
    $decision = $access->pageStatus($storeId);
    if ($decision['status'] === 'verification_failed'
        || ($decision['status'] === 'verification_required' && !isset($decision['verificationId']))
    ) {
        $decision = $access->status($storeId, true);
    }
    return $access->report($storeId, $payload, $decision['verificationId'] ?? null);
}

function saveAbState(StateRepository $states, StoreLock $lock, string $hash, int $storeId, array $values): void
{
    $lock->run((string)$storeId, function () use ($states, $hash, $storeId, $values): void {
        $state = $states->session((string)$storeId, $hash) ?? new BrowserState($hash);
        $state->grantedUntil = $values['granted_until'] ?? $state->grantedUntil;
        $state->blockedUntil = $values['blocked_until'] ?? $state->blockedUntil;
        $states->saveSession((string)$storeId, $state);
    });
}
$storeId = (int)$stores->getStore()->getId();
$quote = $om->create(\Magento\Quote\Model\Quote::class)->setId(987654321)->setStoreId($storeId)->setCustomerId(null);

    // Existing customer hashes and deadlines must survive the core cutover.
    $identity->customer = 42;
    $identity->browser = 'legacy-block';
    $legacyUntil = time() + 700;
    $db->insertOnDuplicate($resource->getTableName('hachitech_hideguard_block'), [
        'identity_hash' => hash('sha256', 'customer:42'), 'store_id' => $storeId, 'blocked_until' => $legacyUntil,
    ]);
    checkAb($access->pageStatus($storeId)['blockedUntil'] === $legacyUntil * 1000, 'Legacy customer ban survives mapping');
    checkAb(reportAb($access, $storeId, [])['blockedUntil'] === $legacyUntil * 1000,
        'Active legacy ban skips malformed verdict and keeps deadline');
    $identity->customer = 0;
    checkAb($access->pageStatus($storeId)['status'] === 'blocked', 'Recorded legacy ban survives logout');
    $access->resetBlocks($storeId);
    $identity->browser = 'browser-a';
    $legacyHash = $identity->hash();
    $db->insertOnDuplicate($resource->getTableName('hachitech_hideguard_session'), [
        'session_hash' => $legacyHash, 'store_id' => $storeId, 'customer_id' => 0,
        'last_seen' => time(), 'granted_until' => time() + 300, 'policy_hash' => str_repeat('f', 64),
    ]);
    checkAb($access->pageStatus($storeId)['status'] === 'verification_required', 'Old fingerprint cannot silently become Allow');

    // Magento atomic adapter must commit domain revocation and roll back infrastructure failure.
    try {
        $lock->run((string)$storeId, function () use ($states, $storeId, $legacyHash): void {
            $state = $states->session((string)$storeId, $legacyHash);
            $state->revoke();
            $states->saveSession((string)$storeId, $state);
            throw new GuardException(\Hachitechs\HideguardPhp\Exceptions\FailureReason::InvalidVerdict, 'Rejected');
        });
    } catch (GuardException) {}
    checkAb($states->session((string)$storeId, $legacyHash)->grantedUntil === 0, 'Domain revocation committed');
    try {
        $lock->run((string)$storeId, function () use ($states, $storeId, $legacyHash): void {
            $state = $states->session((string)$storeId, $legacyHash);
            $state->grantedUntil = time() + 300;
            $states->saveSession((string)$storeId, $state);
            throw new RuntimeException('Storage failure');
        });
    } catch (RuntimeException) {}
    checkAb($states->session((string)$storeId, $legacyHash)->grantedUntil === 0, 'Infrastructure failure rolled back');
    $db->beginTransaction();
    try {
        try { $lock->run((string)$storeId, fn() => null); checkAb(false, 'External transaction must be rejected'); }
        catch (LogicException) {}
        checkAb($db->getTransactionLevel() === 1, 'Rejected nesting leaves caller transaction untouched');
    } finally { $db->rollBack(); }

    $options = $om->create(\Hachitechs\Hideguard\Model\Config\Source\PassDuration::class)->toOptionArray();
    checkAb(array_column($options, 'value') === [0, 60, 300, 900, 3600], 'Dropdown exposes exactly the five requested recheck intervals');
    foreach ([60, 300, 900, 3600] as $duration) {
        $identity->browser = 'duration-' . $duration;
        $visitor = 'duration-visitor-' . $duration;
        $config->value['passDuration'] = $duration;
        $before = time();
        $timedGrant = reportAb($access, $storeId, $signed('allow', $visitor));
        checkAb($timedGrant['expiresAt'] >= ($before + $duration) * 1000
            && $timedGrant['expiresAt'] <= (time() + $duration) * 1000, 'Pass expiry uses selected duration ' . $duration);
        checkAb($timedGrant['passDuration'] === $duration, 'Response exposes authoritative recheck interval');
        $status = $access->status($storeId);
        checkAb($status['expiresAt'] === $timedGrant['expiresAt'] && $status['passDuration'] === $duration,
            'Preflight reuses pass only until its original deadline');
        checkAb(reportAb($access, $storeId, $signed('allow', $visitor))['expiresAt'] === $timedGrant['expiresAt'],
            'Repeated accepted verdict does not slide the pass expiry');
        $access->bindQuote($quote);
        saveAbState($states, $lock, $identity->hash(), $storeId, ['granted_until' => time()]);
        checkAb($access->status($storeId)['status'] === 'verification_required', 'Expired pass requires a new SDK verdict');
        try { $access->assertQuote($quote, 'complete_order'); checkAb(false, 'Expired pass cannot authorize protected order action'); }
        catch (GuardException) {}
    }
    $config->value['passDuration'] = 0;
    $identity->browser = 'browser-a';

    checkAb($access->status($storeId)['status'] === 'verification_required', 'New browser requires verification');
    $grant = reportAb($access, $storeId, $signed('allow', 'visitor-a'));
    checkAb($grant['status'] === 'allowed', 'Signed Allow grants access');
    checkAb($grant['passDuration'] === 0 && $grant['expiresAt'] <= (time() + 300) * 1000,
        'Always mode keeps a short grant for current-page protected actions');
    $access->bindQuote($quote);
    $access->assertQuote($quote, 'cart');
    $access->assertQuote($quote, 'complete_order');
    checkAb($access->pageStatus($storeId)['status'] === 'allowed', 'Page permission uses accepted session grant');
    checkAb(reportAb($access, $storeId, $signed('allow', 'visitor-a'))['expiresAt'] === $grant['expiresAt'], 'Repeated Allow does not extend grant');
    $access->bindQuote($quote);
    $otherQuote = clone $quote;
    $otherQuote->setId(987654322);
    try { $access->assertQuote($otherQuote, 'complete_order'); checkAb(false, 'Grant must not transfer to another quote'); }
    catch (GuardException) {}

    $ban = reportAb($access, $storeId, $signed('block', 'visitor-a'));
    checkAb($ban['status'] === 'blocked', 'Block report is persisted');
    checkAb($access->status($storeId)['blockedUntil'] === $ban['blockedUntil'], 'Preflight reuses block expiry');
    checkAb(reportAb($access, $storeId, $signed('block', 'visitor-b'))['blockedUntil'] === $ban['blockedUntil'], 'Repeated block does not extend ban');
    checkAb(reportAb($access, $storeId, $signed('allow', 'visitor-b'))['status'] === 'blocked', 'Changing visitor cannot clear a session block');
    try { $access->assertQuote($quote, 'complete_order'); checkAb(false, 'Blocked quote must fail order enforcement'); }
    catch (GuardException) {}
    $identity->browser = 'browser-b';
    checkAb(reportAb($access, $storeId, $signed('allow', 'visitor-a'))['status'] === 'blocked', 'Same visitor remains blocked in another session');

    $access->resetBlocks($storeId);
    $identity->browser = 'browser-a';
    checkAb($access->status($storeId)['status'] === 'verification_required', 'Reset requires a new verdict for a previously blocked browser');
    reportAb($access, $storeId, $signed('allow', 'visitor-a'));
    try { reportAb($access, $storeId, $signed('forged', 'visitor-a')); checkAb(false, 'Unknown verdict rejected'); }
    catch (GuardException $error) { checkAb($error->reason === FailureReason::InvalidVerdict, 'Invalid verdict is rejected'); }
    checkAb($access->pageStatus($storeId)['status'] === 'verification_failed', 'Invalid verdict fails the current attempt');

    reportAb($access, $storeId, $signed('allow', 'visitor-a'));
    $identity->customer = 42;
    checkAb($access->status($storeId)['status'] === 'verification_required', 'Login does not reuse guest grant');
    $quote->setCustomerId(42);
    reportAb($access, $storeId, $signed('allow', 'visitor-a'));
    $access->bindQuote($quote);
    $access->assertQuote($quote, 'complete_order');
    $quote->setCustomerId(43);
    try { $access->assertQuote($quote, 'complete_order'); checkAb(false, 'Customer-bound quote rejects another customer'); }
    catch (GuardException) {}
    $quote->setCustomerId(42);
    $access->consumeQuote($quote);
    checkAb($access->pageStatus($storeId)['status'] === 'verification_required', 'Order success consumes grant');

    reportAb($access, $storeId, $signed('allow', 'visitor-a'));
    saveAbState($states, $lock, $identity->hash(), $storeId, ['granted_until' => time()]);
    checkAb($access->pageStatus($storeId)['status'] === 'verification_required', 'Expiry is checked on server without cron');
    reportAb($access, $storeId, $signed('allow', 'visitor-a'));
    $config->value['passDuration'] = 60;
    checkAb($access->pageStatus($storeId)['status'] === 'verification_required', 'Settings fingerprint revokes grants');
    $config->value['passDuration'] = 0;

    $identity->customer = 0;
    $identity->browser = 'signed-browser';
    checkAb(reportAb($access, $storeId, $signed('allow', 'signed-visitor'))['status'] === 'allowed',
        'Signed Allow is accepted');
    $tampered = $signed('allow', 'signed-visitor');
    $tampered['score'] = 0;
    try { reportAb($access, $storeId, $tampered); checkAb(false, 'Tampered signed verdict must fail'); }
    catch (GuardException $error) { checkAb($error->reason === FailureReason::InvalidVerdict, 'Signature tampering rejected'); }
    checkAb($access->pageStatus($storeId)['status'] === 'verification_failed', 'Signature rejection revokes the pass');
    $challenge = reportAb($access, $storeId, $signed('challenge', 'signed-visitor'));
    checkAb($challenge['status'] === $challengeStatus && $access->pageStatus($storeId)['status'] === $challengeStatus,
        'Signed Challenge follows the configured verdict group');
    $identity->browser = 'rate-test';
    $identity->customer = 0;
    for ($i = 0; $i < 60; $i++) { $access->status($storeId); }
    try { $access->status($storeId); checkAb(false, '61st session request must be limited'); }
    catch (GuardException $error) { checkAb($error->reason === FailureReason::RateLimited, 'Per-session rate limit'); }

    $shared = new ReflectionProperty(\Magento\Framework\ObjectManager\ObjectManager::class, '_sharedInstances');
    $instances = $shared->getValue($om);
    $config->value['points'] = ['all_pages', 'complete_order'];
    $instances[Config::class] = $config;
    $instances[Access::class] = $access;
    $shared->setValue($om, $instances);

    $policies = $om->get(\Magento\Csp\Api\PolicyCollectorInterface::class)->collect();
    checkAb(in_array('base-uri', array_map(fn($policy) => $policy->getId(), $policies), true),
        'Native Magento CSP collectors remain registered alongside the HideGuard collector');
    $endpoint = ProtectionPolicy::DEFAULT_ENDPOINT_URL;
    foreach (['script-src', 'connect-src'] as $directive) {
        $matches = array_values(array_filter($policies, fn($policy) => $policy->getId() === $directive));
        checkAb(count($matches) === 1 && in_array($endpoint, $matches[0]->getHostSources(), true),
            'Native CSP allows the library endpoint for ' . $directive);
    }
    $browserConfig = $om->create(\Hachitechs\Hideguard\Block\Bootstrap::class)->getBrowserConfig();
    checkAb($browserConfig['endpointUrl'] === $endpoint, 'Native browser config uses the library endpoint');
    checkAb(!isset($browserConfig['signaturePublicKey']), 'Signature public key remains server-side');

    // DI, real schema validation and rendered gate, with module enabled only in this process.
    foreach ([\Hachitechs\Hideguard\Plugin\PageAccess::class,
        \Hachitechs\Hideguard\Plugin\PrivatePages::class,
        \Hachitechs\Hideguard\Plugin\QuoteAccess::class,
        \Hachitechs\Hideguard\Plugin\OrderAccess::class,
        \Hachitechs\Hideguard\Plugin\ValidateSettings::class,
        \Hachitechs\Hideguard\Controller\Access\Check::class,
        \Hachitechs\Hideguard\Controller\Access\Verdict::class,
        \Hachitechs\Hideguard\Controller\Access\Fail::class,
        \Hachitechs\Hideguard\Controller\Gate\Index::class,
        \Hachitechs\Hideguard\Controller\Adminhtml\Blocks\Reset::class,
    ] as $class) { $om->create($class); }
    $nativeGuard = $om->create('HachitechsHideguardGuard');
    checkAb($nativeGuard instanceof \Hachitechs\HideguardPhp\Contracts\GuardContract, 'Native DI creates core Guard contract');
    $nativeContext = new \Hachitechs\HideguardPhp\Access\AccessContext((string)$storeId, 'signed-native');
    $nativeAttempt = $nativeGuard->preflight($nativeContext, $config->policy($storeId));
    $nativeDecision = $nativeGuard->report(
        $nativeContext, $config->policy($storeId), $signed('allow', 'signed-native-visitor'), $nativeAttempt->verificationId
    );
    checkAb($nativeDecision->verification === 'verified', 'Native Magento DI uses Ed25519 verification');
    checkAb($nativeGuard->inspect(new \Hachitechs\HideguardPhp\Access\AccessContext(
        (string)$storeId, $identity->hash(), $identity->actorId()
    ), $config->policy($storeId))->status === $access->pageStatus($storeId)['status'],
        'Native DI Guard reads the same mapped storage as the platform adapter');
    $structure = $om->get('adminhtmlConfigStructure');
    checkAb(!isset($structure->getElement(Config::SECTION . '/general')->getData()['children']['environment']),
        'Environment selector is absent from Admin');
    $pointsOrder = (int)$structure->getElement(Config::SECTION . '/general/protected_paths')->getData()['sortOrder'];
    $debugField = $structure->getElement(Config::SECTION . '/general/debug')->getData();
    checkAb(($debugField['type'] ?? null) === 'select'
        && ($debugField['source_model'] ?? null) === \Magento\Config\Model\Config\Source\Yesno::class
        && (string)simplexml_load_file(dirname(__DIR__, 2) . '/etc/config.xml')
            ->default->hachitech_hideguard->general->debug === '0',
        'Debug Log uses the native Yes/No field and defaults to off');
    foreach (['showInDefault', 'showInWebsite', 'showInStore'] as $scope) {
        checkAb(($debugField[$scope] ?? null) === '1', 'Debug Log supports ' . $scope);
    }
    foreach (['public_key', 'api_key', 'signature_public_key'] as $name) {
        $field = $structure->getElement(Config::SECTION . '/general/' . $name)->getData();
        checkAb(($field['config_path'] ?? null) === Config::SECTION . '/production/' . $name,
            'Admin credential keeps its existing storage path');
        checkAb((int)$field['sortOrder'] < $pointsOrder, 'Credentials appear before path rules');
        foreach (['showInDefault', 'showInWebsite', 'showInStore'] as $scope) {
            checkAb(($field[$scope] ?? null) === '1', 'Admin credential supports ' . $scope);
        }
    }
    checkAb(str_contains((string)$structure->getElement(Config::SECTION . '/general/api_key')->getComment(),
        rtrim(ProtectionPolicy::DEFAULT_ENDPOINT_URL, '/') . '/dashboard/'), 'API Key links to the HideGuard dashboard');
    $urn = $om->get(\Magento\Framework\Config\Dom\UrnResolver::class);
    libxml_set_external_entity_loader([$urn, 'registerEntityLoader']);
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2))) as $file) {
        if ($file->getExtension() !== 'xml') { continue; }
        $dom = new DOMDocument();
        $dom->load($file->getPathname());
        $location = $dom->documentElement->getAttributeNS('http://www.w3.org/2001/XMLSchema-instance', 'noNamespaceSchemaLocation');
        checkAb($dom->schemaValidate($urn->getRealPath($location)), 'XSD validates ' . $file->getFilename());
    }
    $plugins = $om->get(\Magento\Framework\Interception\PluginListInterface::class);
    $next = $plugins->getNext(\Magento\Framework\App\FrontController::class, 'dispatch');
    checkAb(($next[\Magento\Framework\Interception\DefinitionInterface::LISTENER_AROUND] ?? '') === 'hachitech_hideguard_page', 'Backend gate precedes built-in/Varnish cache plugins');
    $request = $om->get(\Magento\Framework\App\Request\Http::class);
    $request->setRouteName('cms')->setControllerName('index')->setActionName('index')->setPathInfo('/');
    $config->value['points'] = ['product'];
    $publicBootstrap = $om->create(\Hachitechs\Hideguard\Block\Bootstrap::class)
        ->setTemplate('Hachitechs_Hideguard::bootstrap.phtml');
    checkAb(str_contains($publicBootstrap->toHtml(), 'text/x-magento-init')
        && !$publicBootstrap->getBrowserConfig()['pageProtected'],
        'Product-only home page listens for action denials without running page verification');
    $config->value['points'] = ['complete_order'];
    $actionBootstrap = $om->create(\Hachitechs\Hideguard\Block\Bootstrap::class)
        ->setTemplate('Hachitechs_Hideguard::bootstrap.phtml');
    checkAb(str_contains($actionBootstrap->toHtml(), 'text/x-magento-init'),
        'Unprotected page retains deferred verification for a selected order action');
    $config->value['points'] = ['all_pages', 'complete_order'];
    $layout = $om->get(\Magento\Framework\View\LayoutInterface::class);
    $requestGate = $layout->createBlock(\Hachitechs\Hideguard\Block\RequestGate::class)
        ->setTemplate('Hachitechs_Hideguard::request-gate.phtml');
    $require = $layout->createBlock(\Magento\Framework\View\Element\Template::class)
        ->setTemplate('Hachitechs_Hideguard::require-js.phtml')
        ->setChild('hideguard_request_gate', $requestGate);
    $earlyHtml = $require->toHtml();
    $queuePosition = strpos(html_entity_decode($earlyHtml), '/js/request-gate.js');
    $nativePosition = strpos($earlyHtml, 'var LOCALE');
    checkAb($queuePosition !== false && $nativePosition !== false && $queuePosition < $nativePosition,
        'Synchronous transport barrier precedes native RequireJS configuration');
    checkAb(str_contains($earlyHtml, 'var BASE_URL') && str_contains($earlyHtml, 'var require'),
        'Wrapper retains the native Magento RequireJS template and variables');
    checkAb(str_contains($earlyHtml, 'assetUrls') && !str_contains($earlyHtml, 'production-key')
        && !str_contains($earlyHtml, 'BEGIN PUBLIC KEY'), 'Early barrier exposes routing only, no keys or access grant');
    $config->value['enabled'] = false;
    checkAb($requestGate->toHtml() === '', 'Disabled module installs no transport barrier');
    $config->value['enabled'] = true;
    $config->value['points'] = ['product'];
    checkAb($requestGate->toHtml() === '', 'Unprotected home installs no transport barrier');
    $config->value['points'] = ['all_pages', 'complete_order'];
    $request->setRouteName('hideguard')->setControllerName('gate')->setActionName('index')
        ->setPathInfo('/hideguard/gate/index/');
    checkAb($requestGate->toHtml() === '', 'Gate stays exempt even with All pages');
    echo "PASS: native early script ordering, RequireJS variables, enabled/protected scope, gate exemption and public config.\n";
    if (in_array('--request-gate-only', $argv, true)) {
        return;
    }
    $page = $om->create(\Hachitechs\Hideguard\Controller\Gate\Index::class)->execute();
    $page->addHandle('hideguard_gate_index');
    $response = $om->create(\Magento\Framework\App\Response\Http::class);
    $page->renderResult($response);
    checkAb(str_contains($response->getBody(), 'id="hachitech-hideguard-gate"'), 'Server renders standalone gate HTML');
    checkAb(str_contains($response->getBody(), 'data-state="pending"'), 'Initial gate remains pending until the backend confirms a verdict');
    checkAb(str_contains($response->getBody(), 'data-page="gate"'), 'Server marks standalone gate before AMD initialization');
    checkAb(!str_contains($response->getBody(), 'page-title-wrapper'), 'Gate does not render a background page heading');
    checkAb((bool)preg_match('/<body\b[^>]*\binert=""[^>]*>/', $response->getBody()), 'Server locks the body before AMD/SDK initialization');
    checkAb(str_contains($response->getBody(), 'data-hachitech-hideguard-inert=""'), 'Server marks its own initial body lock');
    checkAb(!str_contains($response->getBody(), 'interaction-lock.js'), 'Interaction lock is loaded through AMD rather than a direct script tag');
    checkAb(str_contains($response->getBody(), 'type="text/x-magento-init"'), 'Frontend uses Magento declarative initialization');
    checkAb(str_contains($response->getBody(), 'Hachitechs_Hideguard\\/js\\/access'), 'Gate initializes frontend verification');
    checkAb(str_contains($response->getBody(), 'production-key'), 'Only active SDK public key is rendered');
    checkAb(!str_contains($response->getBody(), 'stage-key') && !str_contains($response->getBody(), 'BEGIN PUBLIC KEY'),
        'Inactive and signature keys stay off storefront');
    checkAb(str_contains($response->getHeader('Cache-Control')->getFieldValue(), 'no-store'), 'Gate HTML cannot be cached');
    $renderPlugins = $plugins->getNext(\Magento\Framework\View\Result\Page::class, 'renderResult');
    checkAb(($renderPlugins[\Magento\Framework\Interception\DefinitionInterface::LISTENER_AFTER][0] ?? '') === 'hachitech_hideguard_private_pages', 'No-store runs before result caching');
    checkAb(!str_contains($response->getBody(), 'product-info-main'), 'Gate contains no product page content');

    $identity->browser = 'http-block-test';
    $blocked = reportAb($access, $storeId, $signed('block', 'http-block-visitor'));
    $returnPath = '/custom-protected-page?source=regression';
    $request->setMethod('GET')->setPathInfo('/custom-protected-page')->setRequestUri($returnPath);
    $pageAccess = $om->get(\Hachitechs\Hideguard\Plugin\PageAccess::class);
    $frontController = $om->get(\Magento\Framework\App\FrontControllerInterface::class);
    $redirect = $pageAccess->aroundDispatch(
        $frontController,
        function () { throw new RuntimeException('Blocked GET must never dispatch its original action.'); }, $request
    );
    checkAb($redirect->getHttpResponseCode() === 302, 'Blocked navigation redirects to the canonical gate');
    $destination = parse_url($redirect->getHeader('Location')->getFieldValue());
    checkAb(str_contains($destination['path'], '/hideguard/gate/index'), 'Redirect targets the native gate action');
    parse_str($destination['query'] ?? '', $query);
    checkAb(base64_decode($query['return'], true) === $returnPath, 'Redirect preserves the original internal path and query');
    checkAb($redirect->getBody() === '', 'Blocked navigation sends no original page HTML');
    checkAb(str_contains($redirect->getHeader('Cache-Control')->getFieldValue(), 'no-store'), 'Block redirect cannot be cached');
    $request->setPathInfo($destination['path'])->setRequestUri($destination['path'] . '?' . $destination['query'])
        ->setRouteName('hideguard')->setControllerName('gate')->setActionName('index')
        ->setParam('return', $query['return']);
    $blockedPage = $pageAccess->aroundDispatch($frontController,
        fn() => $om->create(\Hachitechs\Hideguard\Controller\Gate\Index::class)->execute(), $request);
    checkAb($blockedPage instanceof \Magento\Framework\View\Result\Page, 'Gate is exempt from redirects even with all_pages enabled');
    $blockedResponse = $om->create(\Magento\Framework\App\Response\Http::class);
    // Simulate a second request without reusing the first render's per-request HTML cache.
    (new ReflectionProperty(\Magento\Framework\View\Layout::class, '_renderElementCache'))
        ->setValue($blockedPage->getLayout(), []);
    $blockedPage->renderResult($blockedResponse);
    checkAb($blockedResponse->getHttpResponseCode() === 401, 'Canonical gate returns actual HTTP 401');
    checkAb(str_contains($blockedResponse->getBody(), 'data-state="blocked"'), 'Blocked page uses the confirmed white block state');
    checkAb(str_contains($blockedResponse->getBody(), 'Access temporarily blocked'), 'Blocked page renders the access message');
    checkAb(str_contains($blockedResponse->getHeader('Cache-Control')->getFieldValue(), 'no-store'), '401 page is private and cannot be cached');
    checkAb((int)$blockedResponse->getHeader('Retry-After')->getFieldValue() > 0, '401 page reports remaining block duration');
    checkAb(!$blockedResponse->getHeader('Location'), 'Blocked gate does not redirect again');
    checkAb(str_contains($blockedResponse->getBody(), '/css/styles-m.css')
        && !str_contains($blockedResponse->getBody(), '/frontend/_view/'), 'Gate renders stylesheet URLs for the native theme');
    checkAb($access->pageStatus($storeId)['blockedUntil'] === $blocked['blockedUntil'], '401 does not extend the block');

    foreach ([
        'check' => \Hachitechs\Hideguard\Controller\Access\Check::class,
        'Verdict' => \Hachitechs\Hideguard\Controller\Access\Verdict::class,
    ] as $action => $controller) {
        $request->setMethod('POST')->setPathInfo('/hideguard/access/' . $action)->setActionName($action);
        $request->setParam('form_key', $om->get(\Magento\Framework\Data\Form\FormKey::class)->getFormKey());
        $request->setParam('verdict', 'block')->setParam('visitorId', 'http-block-visitor');
        $json = $om->create($controller)->execute();
        $jsonResponse = $om->create(\Magento\Framework\App\Response\Http::class);
        $json->renderResult($jsonResponse);
        $body = json_decode($jsonResponse->getBody(), true, 512, JSON_THROW_ON_ERROR);
        checkAb($jsonResponse->getHttpResponseCode() === 401 && $body['status'] === 'blocked', 'Endpoint returns 401 with a usable Block result');
        checkAb($body['blockedUntil'] === $blocked['blockedUntil'], 'Endpoint 401 keeps the original ban expiry');
    }

    // Expire only the temporary test records; no cron or live records are involved.
    $lock->run((string)$storeId, fn() => $states->block((string)$storeId, 'http-block-visitor', null, time() - 1));
    saveAbState($states, $lock, $identity->hash(), $storeId, ['blocked_until' => time() - 1]);
    checkAb($access->pageStatus($storeId)['status'] === 'verification_required', 'Expired block returns to verification without cron');
    $request->setPathInfo('/custom-protected-page')->setMethod('GET');
    $pending = $om->get(\Hachitechs\Hideguard\Plugin\PageAccess::class)->aroundDispatch(
        $om->get(\Magento\Framework\App\FrontControllerInterface::class), fn() => 'visible page beneath lock', $request
    );
    checkAb($pending === 'visible page beneath lock', 'Expired block allows pending page rendering rather than another 401');

    // Native HTTP controller must decode the complete signed SDK response and bind only a verified grant.
    $identity->browser = 'signed-http';
    $checkoutFixture = $om->get(\Magento\Checkout\Model\Session::class);
    $checkoutFixture->replaceQuote((clone $quote)->setCustomerId(null));
    // Avoid inserting a guest mask for the fixture's synthetic quote ID.
    (new ReflectionProperty(\Magento\Checkout\Model\Session::class, 'isQuoteMasked'))
        ->setValue($checkoutFixture, true);
    $request->setParam('verificationId', $access->status($storeId)['verificationId']);
    $request->setMethod('POST')->setActionName('Verdict')->setParam('response', json_encode(
        $signed('allow', 'signed-http-visitor'), JSON_THROW_ON_ERROR
    ));
    $request->setParam('verdict', 'block');
    $verified = $om->create(\Hachitechs\Hideguard\Controller\Access\Verdict::class)->execute();
    $verifiedResponse = $om->create(\Magento\Framework\App\Response\Http::class);
    $verified->renderResult($verifiedResponse);
    $verifiedBody = json_decode($verifiedResponse->getBody(), true, 512, JSON_THROW_ON_ERROR);
    checkAb($verifiedResponse->getHttpResponseCode() === 200 && $verifiedBody['status'] === 'allowed',
        'Signed production Allow passes through native HTTP independently of unsigned parameters: '
        . $verifiedResponse->getHttpResponseCode() . ' ' . json_encode($verifiedBody));
    checkAb($states->session((string)$storeId, $identity->hash())->verification === 'verified',
        'Native signed HTTP persists a verified grant');
    $access->assertQuote((clone $quote)->setCustomerId(null), 'complete_order');
    $tampered = $signed('allow', 'signed-http-visitor');
    $tampered['score'] = 0;
    $request->setParam('response', json_encode($tampered, JSON_THROW_ON_ERROR));
    $rejected = $om->create(\Hachitechs\Hideguard\Controller\Access\Verdict::class)->execute();
    $rejectedResponse = $om->create(\Magento\Framework\App\Response\Http::class);
    $rejected->renderResult($rejectedResponse);
    checkAb($rejectedResponse->getHttpResponseCode() === 400, 'Signature tampering returns HTTP 400');
    checkAb($access->pageStatus($storeId)['status'] === 'verification_failed',
        'Native HTTP rejection commits revocation');
    $request->setParam('response', '{');
    $request->setMethod('POST')->setActionName('Verdict')->setParam('verdict', 'invalid');
    $invalid = $om->create(\Hachitechs\Hideguard\Controller\Access\Verdict::class)->execute();
    $invalidResponse = $om->create(\Magento\Framework\App\Response\Http::class);
    $invalid->renderResult($invalidResponse);
    checkAb($invalidResponse->getHttpResponseCode() === 403,
        'Verdict action validates its posted payload independently of action name casing');
    $request->setParam('verificationId', $access->status($storeId, true)['verificationId']);
    $request->setParam('response', json_encode($signed('challenge', 'signed-http-visitor'), JSON_THROW_ON_ERROR));
    $challengeResult = $om->create(\Hachitechs\Hideguard\Controller\Access\Verdict::class)->execute();
    $challengeResponse = $om->create(\Magento\Framework\App\Response\Http::class);
    $challengeResult->renderResult($challengeResponse);
    checkAb($challengeResponse->getHttpResponseCode() === ($challengeStatus === 'blocked' ? 401 : 200)
        && json_decode($challengeResponse->getBody(), true, 512, JSON_THROW_ON_ERROR)['status'] === $challengeStatus,
        'Signed production Challenge follows the configured verdict group');
    // A server-owned preview deadline still works when the browser never loads the SDK.
    $identity->browser = 'no-sdk-native';
    $config->value['points'] = ['all_pages'];
    $request->setMethod('GET')->setPathInfo('/custom-preview')->setRequestUri('/custom-preview');
    $pageAccess = $om->get(\Hachitechs\Hideguard\Plugin\PageAccess::class);
    $front = $om->get(\Magento\Framework\App\FrontControllerInterface::class);
    checkAb($pageAccess->aroundDispatch($front, fn() => 'preview', $request) === 'preview',
        'First protected GET renders the normal page while verification is pending');
    $pending = $access->pageStatus($storeId);
    checkAb(isset($pending['verificationId'], $pending['verificationUntil']), 'GET persists attempt before JavaScript');
    $pageAccess->aroundDispatch($front, fn() => 'preview', $request);
    checkAb($access->pageStatus($storeId)['verificationUntil'] === $pending['verificationUntil'],
        'Reload cannot extend the server verification deadline');
    $lock->run((string)$storeId, function () use ($states, $storeId, $identity): void {
        $state = $states->session((string)$storeId, $identity->hash());
        $state->verificationUntil = time() - 1;
        $states->saveSession((string)$storeId, $state);
    });
    $denied = $pageAccess->aroundDispatch($front, function () { throw new RuntimeException('Expired preview reached action'); }, $request);
    checkAb($denied->getHttpResponseCode() === 302, 'Expired preview redirects before original action');
    $retry = $access->status($storeId, true);
    checkAb($retry['verificationId'] !== $pending['verificationId'], 'Explicit retry rotates the persisted attempt');
    $request->setMethod('POST')->setParam('verificationId', $retry['verificationId']);
    $failed = $om->create(\Hachitechs\Hideguard\Controller\Access\Fail::class)->execute();
    $failedResponse = $om->create(\Magento\Framework\App\Response\Http::class);
    $failed->renderResult($failedResponse);
    checkAb($failedResponse->getHttpResponseCode() === 403
        && $access->pageStatus($storeId)['status'] === 'verification_failed', 'SDK failure persists denial through native HTTP');
    // The API is authenticated HTTPS and returns the unsigned schema supplied by HideGuard.
    $transport = new class implements \Hachitechs\HideguardPhp\Contracts\HttpTransport {
        public array $requests = [];
        public array $payload = [];
        public int $code = 200;
        public $onPost = null;
        public function post(string $url, array $headers, string $body): \Hachitechs\HideguardPhp\Http\HttpResponse
        {
            $this->requests[] = [$url, $headers, json_decode($body, true, 512, JSON_THROW_ON_ERROR)];
            if ($this->onPost !== null) {
                ($this->onPost)();
            }
            return new \Hachitechs\HideguardPhp\Http\HttpResponse($this->code, json_encode($this->payload, JSON_THROW_ON_ERROR));
        }
    };
    $om->get(\Hachitechs\HideguardPhp\VerdictClientFactory::class);
    $nativeClient = $om->get(\Hachitechs\HideguardPhp\VerdictClientFactory::class)
        ->create(['baseUrl' => 'https://fixture.hideguard.example', 'apiKey' => 'fixture-server-key']);
    $nativeTransport = (new ReflectionProperty($nativeClient, 'transport'))->getValue($nativeClient);
    checkAb($nativeTransport instanceof \Hachitechs\HideguardPhp\Http\CurlTransport
        && (new ReflectionProperty($nativeTransport, 'timeoutSeconds'))->getValue($nativeTransport) === 8
        && (new ReflectionProperty($nativeTransport, 'connectTimeoutSeconds'))->getValue($nativeTransport) === 3,
        'Native client factory resolves the configured transport and finite timeouts');
    $nativeDebugTransport = $om->get(\Hachitechs\Hideguard\Model\DebugTransportFactory::class)->create(['storeId' => $storeId]);
    checkAb((new ReflectionProperty($nativeDebugTransport, 'transport'))->getValue($nativeDebugTransport) === $nativeTransport,
        'The native debug factory wraps the configured finite-timeout transport');
    $nativeLog = $om->get('HachitechsHideguardDebugLogger');
    checkAb($nativeLog instanceof \Magento\Framework\Logger\Monolog
        && count(array_filter($nativeLog->getHandlers(), fn($handler) => $handler instanceof \Magento\Framework\Logger\Handler\Base
            && $handler->getUrl() === BP . '/var/log/hachitechs_hideguard_debug.log')) === 1,
        'Native logger resolves a dedicated Magento stream handler');
    $debugFile = $cachePath . '/hideguard-test.log';
    $debugHandler = $om->create('HachitechsHideguardDebugHandler', ['filePath' => $cachePath . '/', 'fileName' => 'hideguard-test.log']);
    $debugLogger = $om->create('HachitechsHideguardDebugLogger', ['handlers' => [$debugHandler]]);
    $transports = new class($om, $transport, $config, $debugLogger) extends \Hachitechs\Hideguard\Model\DebugTransportFactory {
        public function __construct($om,
            private readonly \Hachitechs\HideguardPhp\Contracts\HttpTransport $transport,
            private readonly Config $config,
            private readonly \Psr\Log\LoggerInterface $logger)
        {
            parent::__construct($om);
        }
        public function create(array $data = []): \Hachitechs\Hideguard\Model\DebugTransport
        {
            return parent::create($data + ['transport' => $this->transport, 'config' => $this->config, 'logger' => $this->logger]);
        }
    };
    $clients = new class($om) extends \Hachitechs\HideguardPhp\VerdictClientFactory {
        public function create(array $data = []): \Hachitechs\HideguardPhp\VerdictClient
        {
            return new \Hachitechs\HideguardPhp\VerdictClient($data['baseUrl'], $data['apiKey'], $data['transport']);
        }
    };
    $apiGuard = $om->create('HachitechsHideguardApiGuard', ['storage' => $states, 'atomic' => $lock]);
    $makeAction = fn() => $om->create(\Hachitechs\Hideguard\Model\ActionVerdict::class,
        ['config' => $config, 'identity' => $identity, 'storage' => $states, 'guard' => $apiGuard,
            'clients' => $clients, 'transports' => $transports]);
    $config->value['passDuration'] = 300;
    $identity->browser = 'backend-action-visitor';
    $identity->customer = 0;
    reportAb($access, $storeId, $signed('allow', 'backend-visitor'));
    $transport->payload = ['visitorId' => 'backend-visitor', 'verdict' => 'block', 'score' => 100,
        'source' => 'visit', 'reasons' => [['category' => 'integrity', 'code' => 'browserTampering',
            'detail' => null, 'severity' => 'critical', 'weight' => 70]]];
    $action = $makeAction();
    $backendBlock = $action->checkBrowser($storeId);
    checkAb($backendBlock['status'] === 'blocked' && count($transport->requests) === 1,
        'A cached pass still calls VerdictClient and applies the unsigned backend Block through Guard');
    checkAb($transport->requests[0] === [rtrim(ProtectionPolicy::DEFAULT_ENDPOINT_URL, '/') . '/api/v1/verdict',
        ['Accept' => 'application/json', 'Content-Type' => 'application/json', 'x-api-key' => 'fixture-server-key'],
        ['visitorId' => 'backend-visitor']], 'VerdictClient sends the server-owned visitor, active URL and secret key');
    checkAb($access->pageStatus($storeId)['blockedUntil'] === $backendBlock['blockedUntil'],
        'Backend API Block is visible to the same browser/SDK Guard without a separate block store');
    checkAb($action->checkBrowser($storeId) === $backendBlock && $makeAction()->checkBrowser($storeId) === $backendBlock
        && count($transport->requests) === 1, 'Repeated events and later blocked requests skip HideGuard and preserve deadline');
    $request->setMethod('POST')->setPathInfo('/checkout/cart/add')->setRequestUri('/checkout/cart/add');
    $request->getHeaders()->addHeaderLine('X-Requested-With', 'XMLHttpRequest');
    $blockedAction = $om->create(\Hachitechs\Hideguard\Model\DeniedResponse::class)->create($request, $backendBlock);
    checkAb($blockedAction->getHttpResponseCode() === 401
        && $blockedAction->getHeader('X-Hachitech-HideGuard')->getFieldValue() === 'denied',
        'An action Block returns 401 and the common frontend gate signal');
    $request->getHeaders()->removeHeader($request->getHeaders()->get('X-Requested-With'));
    $request->getServer()->set('HTTP_REFERER', $stores->getStore()->getBaseUrl() . 'customer/account/login/');
    $formBlock = $om->create(\Hachitechs\Hideguard\Model\DeniedResponse::class)
        ->create($request, $backendBlock, true);
    parse_str((string)parse_url($formBlock->getHeader('Location')->getFieldValue(), PHP_URL_QUERY), $formQuery);
    checkAb($formBlock->getHttpResponseCode() === 303 && base64_decode($formQuery['return'], true) === '/customer/account/login/',
        'Blocked form POST redirects to the same gate and returns to its form page, never replays its action');
    $request->getServer()->set('HTTP_REFERER', 'https://foreign.example/customer/account/login/');
    $formBlock = $om->create(\Hachitechs\Hideguard\Model\DeniedResponse::class)
        ->create($request, $backendBlock, true);
    parse_str((string)parse_url($formBlock->getHeader('Location')->getFieldValue(), PHP_URL_QUERY), $formQuery);
    checkAb(base64_decode($formQuery['return'], true) === '/', 'Foreign form referrer is never used as a return destination');
    $identity->browser = 'backend-allow-visitor';
    reportAb($access, $storeId, $signed('allow', 'backend-allow'));
    $beforeGrant = $states->session((string)$storeId, $identity->hash())->grantedUntil;
    $transport->payload = ['visitorId' => 'backend-allow', 'verdict' => 'allow', 'score' => 0, 'reasons' => []];
    $action = $makeAction();
    $quote->setCustomerId(null);
    $action->runQuote($quote, 'order', function () use ($action, $quote) {
        $action->assertQuote($quote, 'order');
        $action->assertQuote($quote, 'order');
    });
    checkAb(count($transport->requests) === 2 && $action->denial() === null,
        'Nested hooks in one order invocation share exactly one backend API check');
    checkAb($states->session((string)$storeId, $identity->hash())->grantedUntil === $beforeGrant,
        'Backend Allow rebinds the quote without extending the cached pass');
    $access->assertQuote($quote, 'complete_order');
    $action->checkBrowser($storeId);
    checkAb(count($transport->requests) === 3,
        'A separate browser action cannot borrow the order invocation verdict');
    $transport->code = 503;
    $action->_resetState();
    checkAb($action->checkBrowser($storeId)['status'] === 'error'
        && $access->pageStatus($storeId)['status'] === 'verification_failed',
        'Request reset calls the API again; outage denies the action and revokes a previous pass');
    $transport->code = 200;
    $identity->browser = 'backend-wrong-visitor';
    reportAb($access, $storeId, $signed('allow', 'expected-visitor'));
    checkAb($makeAction()->checkBrowser($storeId)['status'] === 'error'
        && $access->pageStatus($storeId)['status'] === 'verification_failed',
        'A response for another visitor cannot grant access or change this browser identity');
    $identity->browser = 'unsigned-browser-report';
    $pending = $access->status($storeId);
    try {
        $access->report($storeId, $transport->payload, $pending['verificationId']);
        throw new RuntimeException('Unsigned API payload must never authorize the browser report endpoint');
    } catch (GuardException $error) {
        checkAb($error->reason === FailureReason::InvalidVerdict, 'Browser reports still require Ed25519 signatures');
    }
    $identity->browser = 'unknown-backend-visitor';
    $requestsBefore = count($transport->requests);
    checkAb($makeAction()->checkBrowser($storeId)['status'] === 'verification_required'
        && count($transport->requests) === $requestsBefore, 'Missing trusted visitor requires SDK verification, never a client-selected ID');
    $debugHandler->close();
    $log = file_get_contents($debugFile);
    checkAb(substr_count($log, 'HideGuard request ') === $requestsBefore
        && substr_count($log, 'HideGuard response ') === $requestsBefore
        && str_contains($log, '"http_status":503') && str_contains($log, '"verdict":"block"')
        && !str_contains($log, 'fixture-server-key') && !str_contains($log, 'backend-visitor'),
        'Every actual API request is logged with its response/status, without the API key or raw visitor');
    $config->debug = false;
    $identity->browser = 'backend-debug-off';
    reportAb($access, $storeId, $signed('allow', 'debug-off-visitor'));
    $transport->payload = ['visitorId' => 'debug-off-visitor', 'verdict' => 'allow', 'score' => 0, 'reasons' => []];
    checkAb($makeAction()->checkBrowser($storeId)['status'] === 'allowed'
        && count($transport->requests) === $requestsBefore + 1 && file_get_contents($debugFile) === $log,
        'Disabling Debug Log preserves API checks and access behavior without writing more log records');

    // Independent protection state commits even when native checkout has an open transaction.
    $businessDb = $om->get(ResourceConnection::class)->getConnection();
    checkAb((int)$businessDb->fetchOne('SELECT CONNECTION_ID()') !== (int)$db->fetchOne('SELECT CONNECTION_ID()'),
        'Protection uses a separate native connection from checkout');
    $identity->browser = 'transaction-block-visitor';
    reportAb($access, $storeId, $signed('allow', 'transaction-block'));
    $transport->payload = ['visitorId' => 'transaction-block', 'verdict' => 'block', 'score' => 100, 'reasons' => []];
    $businessDb->beginTransaction();
    try {
        $result = $makeAction()->checkBrowser($storeId);
        checkAb($result['status'] === 'blocked' && $businessDb->getTransactionLevel() === 1,
            'Block commits without committing the surrounding checkout transaction');
    } finally {
        $businessDb->rollBack();
    }
    checkAb($access->pageStatus($storeId)['status'] === 'blocked', 'Checkout rollback cannot erase a block');

    // Real Guard/client plus native plugin callback: no order/payment side effect on Block or API outage.
    foreach (['block', 'outage', 'challenge', 'invalid', 'wrong-visitor'] as $kind) {
        $identity->browser = 'place-order-' . $kind;
        $visitor = 'place-order-visitor-' . $kind;
        reportAb($access, $storeId, $signed('allow', $visitor));
        $transport->payload = ['visitorId' => $visitor, 'verdict' => $kind === 'challenge' ? 'challenge' : 'block',
            'score' => 0, 'reasons' => []];
        if ($kind === 'invalid') {
            $transport->payload['verdict'] = 'allow';
            $transport->payload['reasons'] = [null];
        } elseif ($kind === 'wrong-visitor') {
            $transport->payload['visitorId'] = 'another-visitor';
            $transport->payload['verdict'] = 'allow';
        }
        $transport->code = $kind === 'outage' ? 503 : 200;
        $nativeCalled = false;
        $plugin = new \Hachitechs\Hideguard\Plugin\OrderAccess($makeAction());
        try {
            $plugin->aroundSubmit($om->get(\Magento\Quote\Model\QuoteManagement::class),
                function () use (&$nativeCalled) { $nativeCalled = true; return 'order-payment-started'; }, $quote);
            checkAb($kind === 'challenge', 'Only authenticated non-block results can enter order/payment');
        } catch (\Magento\Framework\Exception\LocalizedException) {
            checkAb($kind !== 'challenge', 'Challenge must pass current policy');
        }
        checkAb($nativeCalled === ($kind === 'challenge'), 'Block/outage stop before native order and payment');
    }
    $transport->code = 200;


    $identity->browser = 'revoked-inflight-order';
    reportAb($access, $storeId, $signed('allow', 'inflight-visitor'));
    $transport->payload = ['visitorId' => 'inflight-visitor', 'verdict' => 'allow', 'score' => 0, 'reasons' => []];
    $transport->onPost = fn() => $guard->revokeScope((string)$storeId);
    $nativeCalled = false;
    try {
        (new \Hachitechs\Hideguard\Plugin\OrderAccess($makeAction()))->aroundSubmit(
            $om->get(\Magento\Quote\Model\QuoteManagement::class),
            function () use (&$nativeCalled) { $nativeCalled = true; }, $quote
        );
        throw new RuntimeException('Revoked in-flight verdict started order/payment');
    } catch (\Magento\Framework\Exception\LocalizedException) {
        checkAb(!$nativeCalled && $access->pageStatus($storeId)['status'] !== 'allowed',
            'Scope revocation while API is in flight prevents late allowance and native order/payment');
    } finally {
        $transport->onPost = null;
    }

    $identity->browser = 'consumed-order-operation';
    reportAb($access, $storeId, $signed('allow', 'consumed-visitor'));
    $transport->payload = ['visitorId' => 'consumed-visitor', 'verdict' => 'allow', 'score' => 0, 'reasons' => []];
    $action = $makeAction();
    $action->runQuote($quote, 'order', function () use ($action, $access, $quote): void {
        $access->consumeQuote($quote);
        try {
            $action->assertQuote($quote);
            throw new RuntimeException('Consumed grant must not authorize a nested side effect');
        } catch (\Magento\Framework\Exception\LocalizedException) {
            checkAb($action->denial()['status'] !== 'allowed', 'Consumed grant invalidates the operation cache');
        }
    });

    // Use Magento's own placeOrder mutex, with a second process and no order/payment creation.
    $lockWorker = <<<'WORKER'
require getcwd() . '/app/bootstrap.php';
$om = \Magento\Framework\App\Bootstrap::create(BP, $_SERVER)->getObjectManager();
$mutex = $om->create(\Magento\Quote\Model\CartMutex::class, ['logger' => new \Psr\Log\NullLogger()]);
try {
    $mutex->execute((int)$argv[1], function () { echo 'native-side-effect'; });
} catch (\Magento\Quote\Model\CartLockedException) {
    echo 'locked';
}
WORKER;
    $mutex = $om->get(\Magento\Quote\Model\CartMutexInterface::class);
    $mutex->execute(random_int(1000000000, 1900000000), function () use ($lockWorker, $mutex, $om): void {
        // A dedicated test key keeps the contention test independent from real carts.
        $id = random_int(1000000000, 1900000000);
        $mutex->execute($id, function () use ($lockWorker, $id): void {
            $process = proc_open([PHP_BINARY, '-r', $lockWorker, (string)$id],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, BP);
            checkAb(is_resource($process), 'Native mutex worker must start');
            fclose($pipes[0]);
            $output = stream_get_contents($pipes[1]);
            $error = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            checkAb(proc_close($process) === 0 && $output === 'locked',
                'Concurrent placeOrder cannot enter the native side effect while the cart mutex is held: ' . $error);
        });
    });
    echo "PASS: in-flight scope revocation denies order/payment; Magento placeOrder mutex rejects a concurrent process before native side effects.\n";

    // The real service plugin registrations cover REST/GraphQL entry points via native interfaces.
    $pluginList = $om->get(\Magento\Framework\Interception\PluginListInterface::class);
    foreach ([\Magento\Customer\Model\AccountManagement::class => ['authenticate', 'createAccount', 'createAccountWithPasswordHash'],
        \Magento\Integration\Model\CustomerTokenService::class => ['createCustomerAccessToken']] as $type => $methods) {
        foreach ($methods as $method) {
            $chain = $pluginList->getNext($type, $method);
            checkAb($chain !== null, 'Native auth service has an interceptor chain: ' . $method);
            $code = $type === \Magento\Integration\Model\CustomerTokenService::class
                ? 'hachitechs_hideguard_token' : 'hachitechs_hideguard_account';
            checkAb($pluginList->getPlugin($type, $code) instanceof \Hachitechs\Hideguard\Plugin\AuthAccess,
                'The native auth interceptor resolves HideGuard: ' . $method);
        }
    }
    // Compare only Guard/storage work on an already allowed page, with temporary tables and no live API.
    $identity->browser = 'page-storage-benchmark';
    $config->value['passDuration'] = 300;
    reportAb($access, $storeId, $signed('allow', 'benchmark-visitor'));
    $samples = ['before_bind' => [], 'after_page_only' => []];
    for ($sample = 0; $sample < 23; $sample++) {
        foreach (array_keys($samples) as $mode) {
            $start = hrtime(true);
            checkAb($access->status($storeId)['status'] === 'allowed', 'Benchmark uses a valid timed pass');
            if ($mode === 'before_bind') {
                $access->bindQuote($quote);
            }
            if ($sample >= 3) {
                $samples[$mode][] = (hrtime(true) - $start) / 1000000;
            }
        }
    }
    foreach ($samples as $mode => $times) {
        sort($times);
        printf("MEASURE: isolated allowed-page storage %s n=%d p50=%.3fms p95=%.3fms; no SDK/API/quote creation.\n",
            $mode, count($times), $times[9], $times[18]);
    }

    echo "PASS: isolated protection transaction survives checkout rollback; native order/payment callbacks denied on Block/outage; authenticated Challenge allowed; auth services registered.\n";
    echo "PASS: Native dedicated debug log, store-scoped transport factory, request/response correlation, HTTP status, redaction and off switch.\n";
    echo "PASS: VerdictClient authenticated API flow, cached-pass refresh, sticky shared Block, scoped operation reuse, quote binding, outage revocation, visitor mismatch and signed browser isolation.\n";
    echo "PASS: live HideGuard URL, CSP, admin fields, Ed25519 signatures, production HTTP/revocation, grants, blocks, reset, expiry, quote/customer binding, rate limits, DI, XSD, cache plugin order, initial lock rendering, HTTP 401 and block expiry.\n";
} finally {
    foreach ($tables as $name) { $db->dropTemporaryTable($name); }
    $om->get(\Magento\Framework\Filesystem\Driver\File::class)->deleteDirectory($cachePath);
}
