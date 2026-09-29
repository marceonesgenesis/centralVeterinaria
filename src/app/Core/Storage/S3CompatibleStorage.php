<?php

declare(strict_types=1);

namespace CentralVet\Storage;

use CentralVet\Storage\S3\S3Client;
use CentralVet\Storage\S3\S3ClientConfig;
use CentralVet\Tenancy\TenantContext;

/**
 * Tenant-scoped S3-compatible storage adapter. Every logical key is
 * physically prefixed by environment and tenant via ObjectKeyNamespace
 * (same convention as CentralVet\Redis\KeyNamespace / RedisCache), so two
 * tenants sharing the same bucket can never collide on or read each
 * other's objects.
 *
 * The low-level HTTP/signing details live in S3Client + S3SignatureV4; this
 * class only owns tenant scoping and translates results into
 * StoredObjectMetadata, whose shape mirrors the (not yet applied)
 * `stored_object` table so a future repository can persist it verbatim.
 * No database writes happen here.
 */
final class S3CompatibleStorage implements StorageInterface
{
    public function __construct(
        private readonly S3Client $client,
        private readonly ObjectKeyNamespace $keys,
        private readonly TenantContext $tenant,
        private readonly string $bucket,
        private readonly string $provider,
    ) {
    }

    public static function fromEnvironment(TenantContext $tenant): self
    {
        $config = S3ClientConfig::fromEnvironment();

        return new self(
            new S3Client($config),
            ObjectKeyNamespace::fromEnvironmentVariable(),
            $tenant,
            $config->bucket,
            (string) (getenv('STORAGE_DRIVER') ?: 's3'),
        );
    }

    public function put(string $key, string $contents, string $contentType = 'application/octet-stream'): StoredObjectMetadata
    {
        $fullKey = $this->fullKey($key);
        $result = $this->client->putObject($fullKey, $contents, $contentType);

        return new StoredObjectMetadata(
            $this->provider,
            $this->bucket,
            $fullKey,
            $result['version_id'],
            $contentType,
            strlen($contents),
            hash('sha256', $contents),
        );
    }

    public function get(string $key): string
    {
        return $this->client->getObject($this->fullKey($key));
    }

    public function exists(string $key): bool
    {
        return $this->client->headObject($this->fullKey($key)) !== null;
    }

    public function delete(string $key): void
    {
        $this->client->deleteObject($this->fullKey($key));
    }

    public function presignedUrl(string $key, int $ttlSeconds = 300): string
    {
        return $this->client->presignedGetUrl($this->fullKey($key), $ttlSeconds);
    }

    private function fullKey(string $key): string
    {
        return $this->keys->tenantKey($this->tenant->tenantId(), 'objects', $key);
    }
}
