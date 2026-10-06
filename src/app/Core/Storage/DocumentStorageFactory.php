<?php

declare(strict_types=1);

namespace CentralVet\Storage;

use CentralVet\Storage\Exception\StorageException;
use CentralVet\Tenancy\TenantContext;

/**
 * Picks the storage driver for generated documents from
 * DOCUMENT_STORAGE_DRIVER: "local" (default, LocalFilesystemStorage under
 * DOCUMENT_STORAGE_LOCAL_ROOT) or "s3" (S3CompatibleStorage). Existing
 * attachments keep using STORAGE_DRIVER / S3CompatibleStorage directly.
 */
final class DocumentStorageFactory
{
    public const DEFAULT_LOCAL_ROOT = '/var/www/html/var/documents';

    private function __construct()
    {
    }

    public static function fromEnvironment(TenantContext $tenant): StorageInterface
    {
        $driver = strtolower(trim((string) (getenv('DOCUMENT_STORAGE_DRIVER') ?: 'local')));

        return match ($driver) {
            LocalFilesystemStorage::PROVIDER => new LocalFilesystemStorage(
                (string) (getenv('DOCUMENT_STORAGE_LOCAL_ROOT') ?: self::DEFAULT_LOCAL_ROOT),
                ObjectKeyNamespace::fromEnvironmentVariable(),
                $tenant,
            ),
            's3' => S3CompatibleStorage::fromEnvironment($tenant),
            default => throw new StorageException('Unknown document storage driver'),
        };
    }
}
