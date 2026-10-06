<?php

declare(strict_types=1);

namespace CentralVet\Storage;

use CentralVet\Storage\Exception\StorageException;
use CentralVet\Tenancy\TenantContext;

/**
 * Tenant-scoped storage on the local filesystem, for hosts without S3. The
 * physical path is <root>/<ObjectKeyNamespace::tenantKey(tenant, 'objects', key)>,
 * the same physical key convention as S3CompatibleStorage, so two tenants
 * can never read each other's objects. The root must live outside the web
 * root: files are only ever served through an authorized controller.
 *
 * Exception messages never carry absolute paths or object keys.
 */
final class LocalFilesystemStorage implements StorageInterface
{
    public const PROVIDER = 'local';

    private const DIRECTORY_MODE = 0750;
    private const FILE_MODE = 0640;

    private readonly string $root;

    public function __construct(
        string $rootDirectory,
        private readonly ObjectKeyNamespace $keys,
        private readonly TenantContext $tenant,
        ?string $webRoot = null,
    ) {
        $root = realpath($rootDirectory);
        if ($root === false || !is_dir($root)) {
            throw new StorageException('Local storage root is not a directory');
        }

        $web = realpath($webRoot ?? dirname(__DIR__, 3));
        if ($web !== false && self::isWithin($root, $web)) {
            throw new StorageException('Local storage root must be outside the web root');
        }

        $this->root = $root;
    }

    public function put(string $key, string $contents, string $contentType = 'application/octet-stream'): StoredObjectMetadata
    {
        $objectKey = $this->objectKey($key);
        $path = $this->path($objectKey);
        $directory = dirname($path);

        if (!is_dir($directory) && !@mkdir($directory, self::DIRECTORY_MODE, true) && !is_dir($directory)) {
            throw new StorageException('Could not create local storage directory');
        }

        $resolvedDirectory = realpath($directory);
        if ($resolvedDirectory === false || !self::isWithin($resolvedDirectory, $this->root)) {
            throw new StorageException('Resolved storage path escapes the local storage root');
        }

        // tempnam() silently falls back to the system temp dir when the target
        // is not writable; a temp file elsewhere would break the atomic rename.
        $temporary = @tempnam($resolvedDirectory, '.tmp-');
        if ($temporary === false || dirname($temporary) !== $resolvedDirectory) {
            if ($temporary !== false) {
                @unlink($temporary);
            }

            throw new StorageException('Could not create temporary file in local storage');
        }

        if (@file_put_contents($temporary, $contents) !== strlen($contents)
            || !@chmod($temporary, self::FILE_MODE)
            || !@rename($temporary, $path)
        ) {
            @unlink($temporary);

            throw new StorageException('Could not write object to local storage');
        }

        return new StoredObjectMetadata(
            self::PROVIDER,
            self::PROVIDER,
            $objectKey,
            null,
            $contentType,
            strlen($contents),
            hash('sha256', $contents),
        );
    }

    public function get(string $key): string
    {
        $path = $this->path($this->objectKey($key));
        if (!is_file($path)) {
            throw new StorageException('Stored object not found');
        }

        $contents = @file_get_contents($path);
        if ($contents === false) {
            throw new StorageException('Could not read object from local storage');
        }

        return $contents;
    }

    public function exists(string $key): bool
    {
        return is_file($this->path($this->objectKey($key)));
    }

    public function delete(string $key): void
    {
        $path = $this->path($this->objectKey($key));
        if (is_file($path) && !@unlink($path) && is_file($path)) {
            throw new StorageException('Could not delete object from local storage');
        }
    }

    public function presignedUrl(string $key, int $ttlSeconds = 300): string
    {
        throw new StorageException('Presigned URLs are not supported by the local storage driver');
    }

    private function objectKey(string $key): string
    {
        return $this->keys->tenantKey($this->tenant->tenantId(), 'objects', $key);
    }

    private function path(string $objectKey): string
    {
        $path = $this->root . '/' . $objectKey;
        if (!self::isWithin($path, $this->root) || in_array('..', explode('/', $objectKey), true)) {
            throw new StorageException('Resolved storage path escapes the local storage root');
        }

        return $path;
    }

    private static function isWithin(string $path, string $base): bool
    {
        $base = rtrim($base, '/');

        return $path === $base || str_starts_with($path, $base . '/');
    }
}
