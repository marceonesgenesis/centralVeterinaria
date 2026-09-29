<?php

declare(strict_types=1);

namespace CentralVet\Storage;

/**
 * Metadata returned after a successful upload. Field names deliberately
 * mirror the `stored_object` table columns created by the (not yet applied)
 * migration `20260920_0001_foundation_multitenancy.sql`
 * (storage_provider, bucket, object_key, version_id, content_type,
 * size_bytes, sha256), so a future repository can persist this value object
 * with a straight column mapping. This class does not touch the database
 * itself — no migration has been run and no row is written by T-08.
 */
final class StoredObjectMetadata
{
    public function __construct(
        public readonly string $storageProvider,
        public readonly string $bucket,
        public readonly string $objectKey,
        public readonly ?string $versionId,
        public readonly string $contentType,
        public readonly int $sizeBytes,
        public readonly string $sha256,
    ) {
    }

    /** @return array<string, mixed> Column => value map matching the `stored_object` table shape. */
    public function toStoredObjectRow(): array
    {
        return [
            'storage_provider' => $this->storageProvider,
            'bucket' => $this->bucket,
            'object_key' => $this->objectKey,
            'version_id' => $this->versionId,
            'content_type' => $this->contentType,
            'size_bytes' => $this->sizeBytes,
            'sha256' => $this->sha256,
        ];
    }
}
