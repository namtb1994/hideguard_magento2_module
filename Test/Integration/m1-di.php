<?php

declare(strict_types=1);

require dirname(__DIR__, 6) . '/app/bootstrap.php';
use Magento\Framework\App\Bootstrap;
use Hachitechs\Hideguard\Model\Cache\{SnapshotReader, ManagedVcl};

$om = Bootstrap::create(BP, $_SERVER)->getObjectManager();
$plugins = $om->get(Magento\Framework\Interception\PluginListInterface::class);
foreach ([
    Magento\Config\Model\ResourceModel\Config::class => ['saveConfig', 'deleteConfig'],
    Magento\Config\Model\ResourceModel\Config\Data::class => ['save', 'delete'],
    Magento\Store\Model\ResourceModel\Store::class => ['save', 'delete'],
    Magento\Store\Model\ResourceModel\Group::class => ['save', 'delete'],
    Magento\Store\Model\ResourceModel\Website::class => ['save', 'delete'],
    Magento\UrlRewrite\Model\Storage\DbStorage::class => ['replace', 'deleteByData'],
    Magento\CatalogUrlRewrite\Model\Storage\DbStorage::class => ['replace', 'deleteByData'],
    Magento\CatalogUrlRewrite\Model\Storage\DynamicStorage::class => ['replace', 'deleteByData'],
    Magento\UrlRewrite\Model\ResourceModel\UrlRewrite::class => ['save', 'delete'],
    Magento\Config\Model\Config\Importer::class => ['import'],
    Magento\Store\Model\Config\Importer::class => ['import'],
] as $type => $methods) {
    foreach ($methods as $method) {
        $chain = $plugins->getNext($type, $method);
        if (($chain[Magento\Framework\Interception\DefinitionInterface::LISTENER_AROUND] ?? '') !== 'hideguard_cache_policy_mutation') {
            throw new RuntimeException('Missing cache mutation fence: ' . $type . '::' . $method);
        }
    }
    if (!$plugins->getPlugin($type, 'hideguard_cache_policy_mutation') instanceof Hachitechs\Hideguard\Plugin\CachePolicyMutation) {
        throw new RuntimeException('Cannot construct mutation plugin.');
    }
}
echo "PASS real native DI: config/config data, store/group/website, rewrite persistence and config/store import fences.\n";
$data = $om->get(SnapshotReader::class)->read();
if ($data === []) { throw new RuntimeException('Missing native stores'); }
$raw = (new Magento\PageCache\Model\Varnish\VclGenerator(
    $om->get(Magento\PageCache\Model\VclTemplateLocatorInterface::class), 'php', 8099,
    ['127.0.0.1', '172.16.0.0/12'], 300, 'X-Forwarded-Proto'
))->generateVcl(6);
$id = str_repeat('f', 64);
$directory = BP . '/var/hg_m1_293a9f51cd87';
if (!is_dir($directory)) { throw new RuntimeException('Prepare proxy fixture directory first.'); }
file_put_contents($directory . '/native.vcl', $om->get(ManagedVcl::class)->render($raw, $data, $id,
    $directory . '/ready-' . $id));
echo 'PASS native effective config/store snapshot (' . count($data) . ") and full Magento VCL rendered.\n";
