<?php

declare(strict_types=1);

namespace Sc\Service;

/**
 * Simple static storage for caching scalar data
 */
class StaticCache
{
    private array $cache = [];

    public function get(string $key): mixed
    {
        return $this->cache[$key] ?? null;
    }

    public function set(string $key, mixed $value): void
    {
        $this->cache[$key] = $value;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->cache);
    }

    public function clear(): void
    {
        $this->cache = [];
    }
}
