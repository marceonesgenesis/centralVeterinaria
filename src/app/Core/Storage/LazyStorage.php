<?php

declare(strict_types=1);

namespace CentralVet\Storage;

use Closure;

/**
 * Defers building the real storage to the first operation (memoized), so a
 * misconfigured driver (e.g. a missing local root) never breaks a screen
 * that merely holds a storage; the factory's exception surfaces from the
 * operation instead.
 */
final class LazyStorage implements StorageInterface
{
    private ?StorageInterface $storage = null;

    /** @param Closure(): StorageInterface $factory */
    public function __construct(private readonly Closure $factory)
    {
    }

    public function put(string $key, string $contents, string $contentType = 'application/octet-stream'): StoredObjectMetadata
    {
        return $this->storage()->put($key, $contents, $contentType);
    }

    public function get(string $key): string
    {
        return $this->storage()->get($key);
    }

    public function exists(string $key): bool
    {
        return $this->storage()->exists($key);
    }

    public function delete(string $key): void
    {
        $this->storage()->delete($key);
    }

    public function presignedUrl(string $key, int $ttlSeconds = 300): string
    {
        return $this->storage()->presignedUrl($key, $ttlSeconds);
    }

    private function storage(): StorageInterface
    {
        return $this->storage ??= ($this->factory)();
    }
}
