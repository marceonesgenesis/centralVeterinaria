<?php

declare(strict_types=1);

namespace CentralVet\Cache;

interface CacheInterface
{
    public function get(string $key): mixed;

    public function set(string $key, mixed $value, int $ttlSeconds = 0): void;

    public function has(string $key): bool;

    public function delete(string $key): void;

    /**
     * Returns the cached value, or computes it via $callback, stores it and
     * returns it when missing.
     */
    public function remember(string $key, int $ttlSeconds, callable $callback): mixed;
}
