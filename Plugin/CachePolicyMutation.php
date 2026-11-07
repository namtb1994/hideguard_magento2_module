<?php

declare(strict_types=1);

namespace Hachitechs\Hideguard\Plugin;

use Hachitechs\Hideguard\Model\Cache\PolicyFiles;

/** Native persistence boundaries only; direct SQL/deployment writes require explicit invalidation. */
class CachePolicyMutation
{
    public function __construct(private readonly PolicyFiles $files)
    {
    }

    public function aroundSaveConfig($subject, callable $proceed, ...$arguments): mixed
    {
        return $this->files->mutate(fn() => $proceed(...$arguments));
    }

    public function aroundDeleteConfig($subject, callable $proceed, ...$arguments): mixed
    {
        return $this->files->mutate(fn() => $proceed(...$arguments));
    }

    public function aroundSave($subject, callable $proceed, ...$arguments): mixed
    {
        return $this->files->mutate(fn() => $proceed(...$arguments));
    }

    public function aroundDelete($subject, callable $proceed, ...$arguments): mixed
    {
        return $this->files->mutate(fn() => $proceed(...$arguments));
    }

    public function aroundReplace($subject, callable $proceed, ...$arguments): mixed
    {
        return $this->files->mutate(fn() => $proceed(...$arguments));
    }

    public function aroundDeleteByData($subject, callable $proceed, ...$arguments): mixed
    {
        return $this->files->mutate(fn() => $proceed(...$arguments));
    }
    public function aroundImport($subject, callable $proceed, ...$arguments): mixed
    {
        return $this->files->mutate(fn() => $proceed(...$arguments));
    }
}
