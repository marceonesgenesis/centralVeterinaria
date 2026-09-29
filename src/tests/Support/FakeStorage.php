<?php

declare(strict_types=1);

namespace CentralVet\Tests\Support;

use CentralVet\Storage\StorageInterface;
use CentralVet\Storage\StoredObjectMetadata;
use RuntimeException;

/**
 * In-memory double for StorageInterface (T-08): no S3-compatible backend or
 * network call involved. Unlike a real implementation, it does no key
 * namespacing/prefixing of its own — it stores exactly the logical key it is
 * given — which is precisely what lets EncounterDocumentServiceTest prove
 * that EncounterDocumentService itself (not the storage backend) is what
 * prefixes every key by tenant and by encounterId.
 */
final class FakeStorage implements StorageInterface
{
    /** @var array<string, array{contents: string, contentType: string}> */
    private array $objects = [];

    public function put(string $key, string $contents, string $contentType = 'application/octet-stream'): StoredObjectMetadata
    {
        $this->objects[$key] = ['contents' => $contents, 'contentType' => $contentType];

        return new StoredObjectMetadata(
            storageProvider: 'fake',
            bucket: 'fake-bucket',
            objectKey: $key,
            versionId: null,
            contentType: $contentType,
            sizeBytes: strlen($contents),
            sha256: hash('sha256', $contents),
        );
    }

    public function get(string $key): string
    {
        if (!isset($this->objects[$key])) {
            throw new RuntimeException("FakeStorage has no object under key \"{$key}\"");
        }

        return $this->objects[$key]['contents'];
    }

    public function exists(string $key): bool
    {
        return isset($this->objects[$key]);
    }

    public function delete(string $key): void
    {
        unset($this->objects[$key]);
    }

    public function presignedUrl(string $key, int $ttlSeconds = 300): string
    {
        return "https://fake-storage.test/{$key}?ttl={$ttlSeconds}";
    }
}
