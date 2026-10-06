<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Storage\DocumentStorageFactory;
use CentralVet\Storage\Exception\StorageException;
use CentralVet\Storage\LocalFilesystemStorage;
use CentralVet\Storage\ObjectKeyNamespace;
use CentralVet\Tenancy\TenantContext;
use CentralVet\Tests\Support\Assert;

final class LocalFilesystemStorageTest
{
    public function testPutThenGetReturnsSameBytesAndSha256(): void
    {
        $this->withTempRoot(function (string $root): void {
            $storage = $this->storage($root, 101);
            $bytes = "%PDF-1.4\x00\x01binary";

            $metadata = $storage->put('documents/1/v1.pdf', $bytes, 'application/pdf');

            Assert::same($bytes, $storage->get('documents/1/v1.pdf'));
            Assert::same(hash('sha256', $bytes), $metadata->sha256);
            Assert::same(strlen($bytes), $metadata->sizeBytes);
            Assert::same('local', $metadata->storageProvider);
            Assert::same('local', LocalFilesystemStorage::PROVIDER);
            Assert::same('application/pdf', $metadata->contentType);
            Assert::same('cv/testing/tenant/101/objects/documents/1/v1.pdf', $metadata->objectKey);
            Assert::true(is_file($root . '/' . $metadata->objectKey));
            Assert::true($storage->exists('documents/1/v1.pdf'));
        });
    }

    public function testTwoTenantsWithSameLogicalKeyDoNotReadEachOther(): void
    {
        $this->withTempRoot(function (string $root): void {
            $tenantA = $this->storage($root, 101);
            $tenantB = $this->storage($root, 202);

            $tenantA->put('documents/1/v1.pdf', 'tenant-a');

            Assert::false($tenantB->exists('documents/1/v1.pdf'));
            Assert::throws(StorageException::class, static fn () => $tenantB->get('documents/1/v1.pdf'));

            $tenantB->put('documents/1/v1.pdf', 'tenant-b');

            Assert::same('tenant-a', $tenantA->get('documents/1/v1.pdf'));
            Assert::same('tenant-b', $tenantB->get('documents/1/v1.pdf'));
        });
    }

    public function testPathTraversalKeyIsWrittenInsideRoot(): void
    {
        $this->withTempRoot(function (string $root): void {
            $storage = $this->storage($root, 101);

            $metadata = $storage->put('../../etc/passwd', 'payload');

            $physical = realpath($root . '/' . $metadata->objectKey);
            Assert::true($physical !== false);
            Assert::true(str_starts_with((string) $physical, (string) realpath($root) . '/'));
            Assert::same('payload', $storage->get('../../etc/passwd'));
        });
    }

    /**
     * A symlink planted inside the root that points outside it must not be
     * followed: neither a linked directory nor a linked file is read,
     * reported as existing, deleted or written through.
     */
    public function testSymlinkLeavingTheRootIsNotFollowed(): void
    {
        $this->withTempRoot(function (string $root): void {
            $this->withTempRoot(function (string $outside) use ($root): void {
                file_put_contents($outside . '/v1.pdf', 'outside-secret');
                $storage = $this->storage($root, 101);
                $objects = $root . '/cv/testing/tenant/101/objects';
                mkdir($objects, 0750, true);
                symlink($outside, $objects . '/linked-dir');
                symlink($outside . '/v1.pdf', $objects . '/linked-file.pdf');

                foreach (['linked-dir/v1.pdf', 'linked-file.pdf'] as $key) {
                    Assert::false($storage->exists($key), "exists() must not follow {$key}");
                    Assert::throws(StorageException::class, static fn () => $storage->get($key), "get() must not follow {$key}");
                    Assert::throws(StorageException::class, static fn () => $storage->delete($key), "delete() must not follow {$key}");
                }

                Assert::throws(StorageException::class, static fn () => $storage->put('linked-dir/v2.pdf', 'x'));
                Assert::same('outside-secret', file_get_contents($outside . '/v1.pdf'));
                Assert::false(file_exists($outside . '/v2.pdf'));
                Assert::true(is_link($objects . '/linked-file.pdf'), 'the link itself is left alone');
            });
        });
    }

    public function testSymlinkInsideTheRootIsAllowed(): void
    {
        $this->withTempRoot(function (string $root): void {
            $storage = $this->storage($root, 101);
            $storage->put('documents/1/v1.pdf', 'inside');
            $objects = $root . '/cv/testing/tenant/101/objects';
            symlink($objects . '/documents', $objects . '/alias');

            Assert::true($storage->exists('alias/1/v1.pdf'));
            Assert::same('inside', $storage->get('alias/1/v1.pdf'));
        });
    }

    public function testRootInsideWebRootIsRejected(): void
    {
        $this->withTempRoot(function (string $webRoot): void {
            $inside = $webRoot . '/var/documents';
            mkdir($inside, 0750, true);

            Assert::throws(
                StorageException::class,
                fn () => new LocalFilesystemStorage($inside, new ObjectKeyNamespace('testing'), $this->tenant(101), $webRoot),
            );
        });
    }

    public function testMissingRootDirectoryIsRejected(): void
    {
        $this->withTempRoot(function (string $root): void {
            Assert::throws(
                StorageException::class,
                fn () => new LocalFilesystemStorage($root . '/missing', new ObjectKeyNamespace('testing'), $this->tenant(101)),
            );
        });
    }

    public function testGetMissingObjectThrowsAndDeleteIsIdempotent(): void
    {
        $this->withTempRoot(function (string $root): void {
            $storage = $this->storage($root, 101);

            Assert::throws(StorageException::class, static fn () => $storage->get('documents/none.pdf'));

            $storage->put('documents/2/v1.pdf', 'x');
            $storage->delete('documents/2/v1.pdf');
            $storage->delete('documents/2/v1.pdf');

            Assert::false($storage->exists('documents/2/v1.pdf'));
        });
    }

    public function testPresignedUrlIsNotSupported(): void
    {
        $this->withTempRoot(function (string $root): void {
            $storage = $this->storage($root, 101);

            Assert::throws(StorageException::class, static fn () => $storage->presignedUrl('documents/1/v1.pdf'));
        });
    }

    public function testFactoryRejectsUnknownDriver(): void
    {
        $previous = getenv('DOCUMENT_STORAGE_DRIVER');
        putenv('DOCUMENT_STORAGE_DRIVER=ftp');

        try {
            Assert::throws(StorageException::class, fn () => DocumentStorageFactory::fromEnvironment($this->tenant(101)));
        } finally {
            putenv($previous === false ? 'DOCUMENT_STORAGE_DRIVER' : 'DOCUMENT_STORAGE_DRIVER=' . $previous);
        }
    }

    public function testFactoryBuildsLocalDriverFromEnvironment(): void
    {
        $this->withTempRoot(function (string $root): void {
            $previousDriver = getenv('DOCUMENT_STORAGE_DRIVER');
            $previousRoot = getenv('DOCUMENT_STORAGE_LOCAL_ROOT');
            putenv('DOCUMENT_STORAGE_DRIVER=local');
            putenv('DOCUMENT_STORAGE_LOCAL_ROOT=' . $root);

            try {
                Assert::instanceOf(LocalFilesystemStorage::class, DocumentStorageFactory::fromEnvironment($this->tenant(101)));
            } finally {
                putenv($previousDriver === false ? 'DOCUMENT_STORAGE_DRIVER' : 'DOCUMENT_STORAGE_DRIVER=' . $previousDriver);
                putenv($previousRoot === false ? 'DOCUMENT_STORAGE_LOCAL_ROOT' : 'DOCUMENT_STORAGE_LOCAL_ROOT=' . $previousRoot);
            }
        });
    }

    private function storage(string $root, int $tenantId): LocalFilesystemStorage
    {
        return new LocalFilesystemStorage($root, new ObjectKeyNamespace('testing'), $this->tenant($tenantId));
    }

    private function tenant(int $tenantId): TenantContext
    {
        return TenantContext::authenticated($tenantId, 1);
    }

    private function withTempRoot(callable $callback): void
    {
        $root = sys_get_temp_dir() . '/cv-local-storage-' . bin2hex(random_bytes(6));
        mkdir($root, 0750, true);

        try {
            $callback($root);
        } finally {
            $this->removeTree($root);
        }
    }

    private function removeTree(string $path): void
    {
        // A link is removed itself, never followed (it may point outside the tree).
        if (is_link($path) || !is_dir($path)) {
            if (is_link($path) || file_exists($path)) {
                unlink($path);
            }

            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->removeTree($path . '/' . $entry);
            }
        }

        rmdir($path);
    }
}
