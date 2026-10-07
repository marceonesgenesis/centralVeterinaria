<?php

declare(strict_types=1);

namespace CentralVet\Storage;

use CentralVet\Tenancy\TenantContext;

/**
 * Picks the storage for attachments (patient photo, encounter attachment,
 * exam result) from STORAGE_DRIVER, the value also recorded as
 * stored_object.storage_provider:
 *
 * - "local" → LocalFilesystemStorage under STORAGE_LOCAL_ROOT, else
 *   DOCUMENT_STORAGE_LOCAL_ROOT, else DocumentStorageFactory::DEFAULT_LOCAL_ROOT;
 * - any other non-empty value → S3CompatibleStorage with that provider;
 * - empty or unset → "s3" when S3_ENDPOINT and S3_BUCKET are both set,
 *   otherwise "local".
 *
 * Existing objects are read through forProvider() with the provider stored
 * on their row, so changing the driver never orphans older attachments.
 */
final class StorageFactory
{
    private function __construct()
    {
    }

    public static function driverFromEnvironment(): string
    {
        $driver = strtolower(trim((string) getenv('STORAGE_DRIVER')));
        if ($driver !== '') {
            return $driver;
        }

        return self::s3Configured() ? 's3' : LocalFilesystemStorage::PROVIDER;
    }

    /** Storage for new writes; built lazily so a misconfigured driver only fails on use. */
    public static function forWrites(TenantContext $tenant): StorageInterface
    {
        return new LazyStorage(
            static fn (): StorageInterface => self::forProvider(self::driverFromEnvironment(), $tenant),
        );
    }

    /** Storage that holds objects recorded with the given storage_provider. */
    public static function forProvider(string $storageProvider, TenantContext $tenant): StorageInterface
    {
        if ($storageProvider === LocalFilesystemStorage::PROVIDER) {
            return new LocalFilesystemStorage(
                self::localRoot(),
                ObjectKeyNamespace::fromEnvironmentVariable(),
                $tenant,
            );
        }

        return S3CompatibleStorage::fromEnvironment($tenant);
    }

    /**
     * Patient photos have no stored provider: with the local driver and S3
     * still configured, keys missing locally are read (and deleted) on S3.
     */
    public static function forPatientPhotos(TenantContext $tenant): StorageInterface
    {
        if (self::driverFromEnvironment() === LocalFilesystemStorage::PROVIDER && self::s3Configured()) {
            return new FallbackReadStorage(
                self::forWrites($tenant),
                static fn (): StorageInterface => S3CompatibleStorage::fromEnvironment($tenant),
            );
        }

        return self::forWrites($tenant);
    }

    private static function localRoot(): string
    {
        return (string) (getenv('STORAGE_LOCAL_ROOT')
            ?: getenv('DOCUMENT_STORAGE_LOCAL_ROOT')
            ?: DocumentStorageFactory::DEFAULT_LOCAL_ROOT);
    }

    private static function s3Configured(): bool
    {
        return (string) getenv('S3_ENDPOINT') !== '' && (string) getenv('S3_BUCKET') !== '';
    }
}
