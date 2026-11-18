<?php

declare(strict_types=1);

// Read-only DI/XSD/config smoke. No order, credentials, security records or saved configuration are changed.
require dirname(__DIR__, 6) . '/app/bootstrap.php';

use Hachitechs\Hideguard\Model\Config;
use Hachitechs\HideguardPhp\Configuration\ProtectionPolicy;
use Hachitechs\Hideguard\Model\Config\RuntimeSettings;
use Hachitechs\HideguardPhp\Access\BrowserState;
use Magento\Framework\App\Bootstrap;

function checkPhaseTwo(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$om = Bootstrap::create(BP, $_SERVER)->getObjectManager();
$om->get(Magento\Framework\App\State::class)->setAreaCode('frontend');
$om->configure($om->get(Magento\Framework\ObjectManager\ConfigLoaderInterface::class)->load('frontend'));
$plugins = $om->get(Magento\Framework\Interception\PluginListInterface::class);
foreach ([
    [Magento\Quote\Model\Quote::class, 'addProduct', 'hachitech_hideguard_cart', Hachitechs\Hideguard\Plugin\QuoteAccess::class],
    [Magento\Quote\Model\QuoteManagement::class, 'submit', 'hachitech_hideguard_order', Hachitechs\Hideguard\Plugin\OrderAccess::class],
    [Magento\Customer\Model\AccountManagement::class, 'authenticate', 'hachitechs_hideguard_account', Hachitechs\Hideguard\Plugin\AuthAccess::class],
    [Magento\Customer\Model\AccountManagement::class, 'createAccount', 'hachitechs_hideguard_account', Hachitechs\Hideguard\Plugin\AuthAccess::class],
] as [$type, $method, $name, $expected]) {
    checkPhaseTwo($plugins->getNext($type, $method) !== null, 'Missing plugin chain: ' . $type . '::' . $method);
    checkPhaseTwo($plugins->getPlugin($type, $name) instanceof $expected, 'Missing enforcement: ' . $name);
}
echo "PASS: real frontend DI resolves quote/order/auth enforcement plugins.\n";
$config = $om->get(Config::class);
$scope = $om->get(Magento\Framework\App\Config\ScopeConfigInterface::class);
$store = (int)$om->get(Magento\Store\Model\StoreManagerInterface::class)->getStore()->getId();
$settings = $config->settings($store);
$policy = $config->toPolicy($settings);
checkPhaseTwo($config->policy()->limits == $policy->limits, 'Implicit current-store runtime limits differ');
$runtime = $om->get(RuntimeSettings::class);
foreach ($runtime->limits($store) as $property => $value) {
    checkPhaseTwo($policy->limits->$property === $value, 'Runtime limit mismatch: ' . $property);
}
$next = $policy->limits->scopeRequests === 2147483647 ? 2147483646 : $policy->limits->scopeRequests + 1;
$overrideDeployment = $om->create(Magento\Framework\App\DeploymentConfig::class, ['overrideData' => [
    'hideguard' => ['store_limits' => [$store => ['scope_requests' => $next]]],
]]);
$overrideRuntime = new RuntimeSettings($overrideDeployment, $om->get(Magento\Store\Model\StoreManagerInterface::class));
$overrideConfig = new Config($scope, $om->get(Magento\Framework\Encryption\EncryptorInterface::class), $overrideRuntime);
$changed = $overrideConfig->policy($store);
checkPhaseTwo($changed->limits->scopeRequests === $next, 'Deployment store override did not reach policy');
checkPhaseTwo($policy->fingerprint() !== $changed->fingerprint(), 'Limits must change policy fingerprint');
if ($policy->enabled) {
    $state = new BrowserState('fixture', grantedUntil: time() + 300, policyHash: $policy->fingerprint(), verification: 'verified');
    checkPhaseTwo($policy->grants($state, null, time()), 'Fixture grant invalid');
    checkPhaseTwo(!$changed->grants($state, null, time()), 'Old policy grant survived limits change');
}
echo "PASS: deployment runtime limits reach policy; in-memory override changes fingerprint and rejects old-policy grant.\n";
$urn = $om->get(Magento\Framework\Config\Dom\UrnResolver::class);
libxml_set_external_entity_loader([$urn, 'registerEntityLoader']);
foreach (['etc/adminhtml/system.xml', 'etc/config.xml', 'etc/di.xml', 'etc/db_schema.xml'] as $name) {
    $dom = new DOMDocument();
    checkPhaseTwo($dom->load(dirname(__DIR__, 2) . '/' . $name), 'Cannot load XML');
    $schema = $dom->documentElement->getAttributeNS('http://www.w3.org/2001/XMLSchema-instance', 'noNamespaceSchemaLocation');
    checkPhaseTwo($dom->schemaValidate($urn->getRealPath($schema)), 'Invalid XSD: ' . $name);
}
$om->get(Magento\Framework\Config\ScopeInterface::class)->setCurrentScope('adminhtml');
$structure = $om->get(Magento\Config\Model\Config\Structure::class);
$general = $structure->getElement(Config::SECTION . '/general')->getData();
$fields = $general['children'] ?? [];
checkPhaseTwo($fields !== [], 'Admin Protection group must not be empty');
foreach ([...RuntimeSettings::LEGACY_ADMIN_FIELDS, 'protected_points', 'environment', 'development', 'production'] as $field) {
    checkPhaseTwo(!isset($fields[$field]), 'Legacy technical field still visible: ' . $field);
}
foreach (['enabled', 'public_key', 'api_key', 'signature_public_key', 'protected_paths', 'block_duration', 'pass_duration',
    'debug', 'reset_blocks'] as $field) {
    $data = $structure->getElement(Config::SECTION . '/general/' . $field)->getData();
    foreach (['showInDefault', 'showInWebsite', 'showInStore'] as $flag) {
        checkPhaseTwo((int)($data[$flag] ?? 0) === 1, 'Missing business inheritance flag: ' . $field . ':' . $flag);
    }
}
echo "PASS: XML/XSD valid; technical fields and access point selector absent; remaining business fields keep scope inheritance.\n";

foreach (['public_key', 'api_key', 'signature_public_key'] as $field) {
    checkPhaseTwo(($fields[$field]['config_path'] ?? null) === Config::SECTION . '/production/' . $field,
        'Credential storage path changed: ' . $field);
}
$apiKeyField = $structure->getElement(Config::SECTION . '/general/api_key');
checkPhaseTwo(str_contains($apiKeyField->getComment(), rtrim(ProtectionPolicy::DEFAULT_ENDPOINT_URL, '/') . '/dashboard/'),
    'API key dashboard link is missing');
foreach (['development', 'staging', 'production'] as $mode) {
    $scopeFixture = new class ($mode) implements Magento\Framework\App\Config\ScopeConfigInterface {
        public function __construct(private readonly string $mode) {}
        public function getValue($path, $scopeType = self::SCOPE_TYPE_DEFAULT, $scopeCode = null) {
            checkPhaseTwo(!str_ends_with($path, '/endpoint_url') && !str_ends_with($path, '/protected_points')
                && !str_ends_with($path, '/environment'),
                'Legacy UI configuration was read');
            return match ($path) {
                Config::SECTION . '/general/enabled' => '1',
                Config::SECTION . '/general/environment' => $this->mode,
                Config::SECTION . '/production/public_key' => 'fixture-public',
                Config::SECTION . '/production/signature_public_key' => 'fixture-signature',
                default => null,
            };
        }
        public function isSetFlag($path, $scopeType = self::SCOPE_TYPE_DEFAULT, $scopeCode = null) {
            return (bool)$this->getValue($path, $scopeType, $scopeCode);
        }
    };
    $candidate = new Config($scopeFixture, $om->get(Magento\Framework\Encryption\EncryptorInterface::class), $runtime);
    $expected = ProtectionPolicy::DEFAULT_ENDPOINT_URL;
    checkPhaseTwo($candidate->policy($store)->endpointUrl === $expected, 'Policy mode endpoint mismatch');
    $candidateRules = $om->create(Hachitechs\Hideguard\Model\PageRules::class, ['config' => $candidate]);
    checkPhaseTwo($candidateRules->protects('/some/custom/form', $store), 'All-page protection must be fixed');
    checkPhaseTwo(!$candidateRules->protects('/hideguard/access/check', $store), 'Gate exemption lost');
    $fakeAccess = new class extends Hachitechs\Hideguard\Model\Access {
        public function __construct() {}
        public function pageStatus(int $storeId): array { return ['status' => 'allowed']; }
    };
    $browser = $om->create(Hachitechs\Hideguard\Block\Bootstrap::class, [
        'config' => $candidate, 'rules' => $candidateRules, 'access' => $fakeAccess,
    ])->getBrowserConfig();
    checkPhaseTwo($browser['endpointUrl'] === $expected && $browser['points'] === Config::ACCESS_POINTS,
        'Browser endpoint or points differ from policy');
    $csp = $om->create(Hachitechs\Hideguard\Model\CspCollector::class, ['config' => $candidate])->collect();
    checkPhaseTwo(count($csp) === 2, 'Missing SDK/CSP directives');
    foreach ($csp as $directive) {
        checkPhaseTwo($directive->getHostSources() === [$expected], 'CSP host differs from policy/browser');
    }
}
echo "PASS: flat inherited credentials and dashboard link; policy/browser/CSP use the live host regardless of legacy mode.\n";

$guardResource = $om->get('HachitechsHideguardResource')->getConnection()->getConnection();
$circuitResource = $om->get('HachitechsHideguardCircuitResource')->getConnection()->getConnection();
$businessResource = $om->get(Magento\Framework\App\ResourceConnection::class)->getConnection()->getConnection();
checkPhaseTwo($guardResource !== $circuitResource && $guardResource !== $businessResource
    && $circuitResource !== $businessResource, 'Guard, circuit and native checkout connections must be separate');
$circuit = $config->circuitSettings($store);
checkPhaseTwo($circuit['policy']->probeLeaseSeconds > 8, 'Probe lease must exceed transport timeout');
checkPhaseTwo($om->create(Hachitechs\Hideguard\Model\DebugTransport::class, ['storeId' => $store])
    instanceof Hachitechs\HideguardPhp\Contracts\HttpTransport, 'Circuit transport DI failed');
echo "PASS: distinct guard/circuit/business PDOs; effective circuit policy and decorated transport DI.\n";
