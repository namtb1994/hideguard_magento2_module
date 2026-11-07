<?php

declare(strict_types=1);

namespace Hachitechs\Hideguard\Model\Cache;

use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\App\Filesystem\DirectoryList;

/** A coherent shared POSIX directory is required; proxies mount it read-only. */
class PolicyFiles
{
    private int $depth = 0;

    public function __construct(
        private readonly DirectoryList $directories,
        private readonly DeploymentConfig $deployment
    ) {
    }

    public function enabled(): bool
    {
        return (bool)$this->deployment->get('hideguard/cache/managed', false);
    }

    public function directory(): string
    {
        $path = (string)$this->deployment->get(
            'hideguard/cache/shared_path',
            $this->directories->getPath(DirectoryList::VAR_DIR) . '/hideguard-cache'
        );
        if (!str_starts_with($path, '/') || preg_match('/[\x00-\x20"\\\\]/', $path)) {
            throw new \RuntimeException('HideGuard shared_path must be an absolute POSIX path without whitespace.');
        }
        return rtrim($path, '/');
    }

    public function locked(callable $work): mixed
    {
        if ($this->depth > 0) {
            return $work();
        }
        $directory = $this->directory();
        if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new \RuntimeException('Cannot create HideGuard policy directory.');
        }
        $lock = @fopen($directory . '/policy.lock', 'c');
        if ($lock === false) {
            throw new \RuntimeException('Cannot lock HideGuard policy directory.');
        }
        $deadline = hrtime(true) + 3000000000;
        try {
            while (!flock($lock, LOCK_EX | LOCK_NB)) {
                if (hrtime(true) >= $deadline) {
                    throw new \RuntimeException('HideGuard cache publication is busy. Retry the mutation/export.');
                }
                usleep(10000);
            }
            $this->depth++;
            try {
                return $work();
            } finally {
                $this->depth--;
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function generation(): string
    {
        $path = $this->directory() . '/generation';
        $value = is_file($path) ? trim((string)file_get_contents($path)) : '';
        if ($value === '') {
            $value = bin2hex(random_bytes(16));
            $this->write('generation', $value);
        }
        if (!preg_match('/^[a-f0-9]{32}$/D', $value)) {
            throw new \RuntimeException('Invalid HideGuard cache generation.');
        }
        return $value;
    }

    public function retire(): void
    {
        $markers = glob($this->directory() . '/ready-*', GLOB_ERR);
        if ($markers === false) {
            throw new \RuntimeException('Cannot enumerate HideGuard readiness markers.');
        }
        foreach ($markers as $file) {
            if (!@unlink($file)) {
                throw new \RuntimeException('Cannot retire HideGuard cache readiness; mutation stopped.');
            }
        }
        clearstatcache();
    }

    public function mutate(callable $work): mixed
    {
        // Before the first managed export there can be no readiness marker to retire.
        if (!$this->enabled() && !is_dir($this->directory())) {
            return $work();
        }
        return $this->locked(function () use ($work) {
            $this->invalidate();
            try {
                return $work();
            } finally {
                $this->invalidate();
            }
        });
    }

    public function invalidate(): void
    {
        $this->retire();
        $this->write('generation', bin2hex(random_bytes(16)));
    }

    public function marker(string $id): string
    {
        $this->validateId($id);
        return $this->directory() . '/ready-' . $id;
    }

    public function descriptor(string $id): array
    {
        $this->validateId($id);
        $path = $this->directory() . '/snapshot-' . $id . '.json';
        if (!is_file($path)) {
            throw new \RuntimeException('Snapshot descriptor was not found. Export VCL first.');
        }
        $data = json_decode((string)file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($data) || hash('sha256', json_encode($data, JSON_THROW_ON_ERROR)) !== $id) {
            throw new \RuntimeException('Invalid HideGuard snapshot descriptor.');
        }
        return $data;
    }

    public function write(string $name, string $body): void
    {
        if (!preg_match('/^[a-zA-Z0-9_.-]+$/D', $name)) {
            throw new \InvalidArgumentException('Unsafe policy filename.');
        }
        $temporary = tempnam($this->directory(), '.write-');
        try {
            if (
                $temporary === false || file_put_contents($temporary, $body) !== strlen($body)
                || !chmod($temporary, 0644) || !rename($temporary, $this->directory() . '/' . $name)
            ) {
                throw new \RuntimeException('Cannot publish HideGuard policy file.');
            }
        } finally {
            if (is_string($temporary) && is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    private function validateId(string $id): void
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $id)) {
            throw new \InvalidArgumentException('Use a SHA-256 snapshot ID.');
        }
    }
}
