<?php

declare(strict_types=1);

namespace CentralVet\Storage;

/**
 * Tenant-scoped object storage contract. Implementations must physically
 * prefix every key by environment and tenant (mirroring
 * CentralVet\Redis\KeyNamespace / CentralVet\Cache\RedisCache), so two
 * tenants sharing the same bucket can never read or overwrite each other's
 * objects.
 *
 * $key is the caller-facing logical key (e.g. "avatars/<uuid>.png"); the
 * implementation is responsible for turning it into the physical object key
 * actually stored in the backend.
 */
interface StorageInterface
{
    public function put(string $key, string $contents, string $contentType = 'application/octet-stream'): StoredObjectMetadata;

    public function get(string $key): string;

    public function exists(string $key): bool;

    public function delete(string $key): void;

    /** Time-limited URL for direct (unauthenticated) read access to the object. */
    public function presignedUrl(string $key, int $ttlSeconds = 300): string;
}
