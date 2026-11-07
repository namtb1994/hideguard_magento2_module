<?php

declare(strict_types=1);

namespace Hachitechs\Hideguard\Model\Cache;

use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\PageCache\Model\Cache\Server;

class Activation
{
    public function __construct(
        private readonly PolicyFiles $files,
        private readonly Server $servers,
        private readonly CurlFactory $clients,
        private readonly DeploymentConfig $deployment
    ) {
    }

    public function activate(string $id): void
    {
        $this->files->locked(function () use ($id): void {
            $this->files->retire();
            try {
                if (!$this->files->enabled()) {
                    throw new \RuntimeException('Managed cache is not enabled in deployment config.');
                }
                $data = $this->files->descriptor($id);
                if (($data['generation'] ?? null) !== $this->files->generation()) {
                    throw new \RuntimeException('Snapshot is stale. Export and load a fresh VCL.');
                }
                if (!$this->deployment->get('http_cache_hosts')) {
                    throw new \RuntimeException('Explicit http_cache_hosts are required for every proxy.');
                }
                $servers = $this->servers->getUris();
                if ($servers === []) {
                    throw new \RuntimeException('No cache servers configured.');
                }
                foreach ($servers as $server) {
                    $this->acknowledge((string)$server, $id, false);
                }
                $this->files->write('ready-' . $id, 'ready');
                foreach ($servers as $server) {
                    $this->acknowledge((string)$server, $id, true);
                }
            } catch (\Throwable $error) {
                $this->files->retire();
                throw $error;
            }
        });
    }

    private function acknowledge(string $url, string $id, bool $ready): void
    {
        $client = $this->clients->create();
        $client->setTimeout(3);
        $client->setOptions([CURLOPT_CONNECTTIMEOUT => 1, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CUSTOMREQUEST => 'PURGE']);
        $client->setHeaders(['X-Hideguard-Snapshot' => $id, 'X-Hideguard-Stage' => $ready ? 'verify' : 'purge']);
        $client->get($url);
        $headers = array_change_key_case($client->getHeaders(), CASE_LOWER);
        if (
            $client->getStatus() !== 200 || ($headers['x-hideguard-snapshot'] ?? '') !== $id
            || ($headers['x-hideguard-ready'] ?? '') !== ($ready ? '1' : '0')
        ) {
            throw new \RuntimeException(
                'Proxy did not acknowledge the active snapshot, purge and shared marker: ' . $url
            );
        }
    }
}
