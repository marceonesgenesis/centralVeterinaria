<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Storage\Exception\StorageException;
use CentralVet\Storage\FallbackReadStorage;
use CentralVet\Storage\LazyStorage;
use CentralVet\Storage\LocalFilesystemStorage;
use CentralVet\Storage\S3CompatibleStorage;
use CentralVet\Storage\StorageFactory;
use CentralVet\Storage\StorageInterface;
use CentralVet\Tenancy\TenantContext;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\FakeStorage;
use RuntimeException;

final class StorageFactoryTest
{
    private const ENV_VARS = [
        'STORAGE_DRIVER',
        'STORAGE_LOCAL_ROOT',
        'DOCUMENT_STORAGE_LOCAL_ROOT',
        'S3_ENDPOINT',
        'S3_BUCKET',
    ];

    public function testDriverWithoutS3DefaultsToLocal(): void
    {
        $this->withEnv(['STORAGE_DRIVER' => '', 'S3_ENDPOINT' => '', 'S3_BUCKET' => ''], function (): void {
            Assert::same('local', StorageFactory::driverFromEnvironment());
        });

        $this->withEnv(['STORAGE_DRIVER' => null, 'S3_ENDPOINT' => null, 'S3_BUCKET' => null], function (): void {
            Assert::same('local', StorageFactory::driverFromEnvironment());
        });
    }

    public function testDriverFromEnvironmentKeepsAnyNonLocalProvider(): void
    {
        $this->withEnv(['STORAGE_DRIVER' => 'minio', 'S3_ENDPOINT' => '', 'S3_BUCKET' => ''], function (): void {
            Assert::same('minio', StorageFactory::driverFromEnvironment());
        });
    }

    public function testDriverFromEnvironmentIsTrimmedAndLowercased(): void
    {
        $this->withEnv(['STORAGE_DRIVER' => '  LOCAL ', 'S3_ENDPOINT' => 'http://minio:9000', 'S3_BUCKET' => 'b'], function (): void {
            Assert::same('local', StorageFactory::driverFromEnvironment());
        });
    }

    public function testEmptyDriverWithS3ConfiguredIsS3(): void
    {
        $this->withEnv(['STORAGE_DRIVER' => '', 'S3_ENDPOINT' => 'http://minio:9000', 'S3_BUCKET' => 'centralvet-local'], function (): void {
            Assert::same('s3', StorageFactory::driverFromEnvironment());
        });
    }

    public function testEmptyDriverWithoutS3BucketFallsBackToLocal(): void
    {
        $this->withEnv(['STORAGE_DRIVER' => '', 'S3_ENDPOINT' => 'http://minio:9000', 'S3_BUCKET' => ''], function (): void {
            Assert::same('local', StorageFactory::driverFromEnvironment());
        });
    }

    public function testForWritesWithLocalDriverWritesUnderLocalRoot(): void
    {
        $this->withTempRoot(function (string $root): void {
            $this->withEnv(['STORAGE_DRIVER' => 'local', 'STORAGE_LOCAL_ROOT' => $root], function () use ($root): void {
                $storage = StorageFactory::forWrites($this->tenant(101));

                Assert::instanceOf(LazyStorage::class, $storage);

                $metadata = $storage->put('attachments/a.txt', 'hello', 'text/plain');

                Assert::same('local', $metadata->storageProvider);
                Assert::true(is_file($root . '/' . $metadata->objectKey));
                Assert::same('hello', $storage->get('attachments/a.txt'));
                Assert::true($storage->exists('attachments/a.txt'));
            });
        });
    }

    public function testLocalRootFallsBackToDocumentStorageLocalRoot(): void
    {
        $this->withTempRoot(function (string $root): void {
            $env = ['STORAGE_DRIVER' => 'local', 'STORAGE_LOCAL_ROOT' => '', 'DOCUMENT_STORAGE_LOCAL_ROOT' => $root];
            $this->withEnv($env, function () use ($root): void {
                $metadata = StorageFactory::forWrites($this->tenant(101))->put('attachments/b.txt', 'bytes');

                Assert::true(is_file($root . '/' . $metadata->objectKey));
            });
        });
    }

    public function testForWritesWithMissingLocalRootThrowsOnlyOnPut(): void
    {
        $missing = sys_get_temp_dir() . '/cv-storage-factory-missing-' . bin2hex(random_bytes(6));
        $this->withEnv(['STORAGE_DRIVER' => 'local', 'STORAGE_LOCAL_ROOT' => $missing], function (): void {
            $storage = StorageFactory::forWrites($this->tenant(101));

            Assert::instanceOf(StorageInterface::class, $storage);
            Assert::throws(StorageException::class, static fn () => $storage->put('attachments/a.txt', 'x'));
        });
    }

    public function testForProviderLocalBuildsLocalFilesystemStorage(): void
    {
        $this->withTempRoot(function (string $root): void {
            $this->withEnv(['STORAGE_LOCAL_ROOT' => $root], function (): void {
                Assert::instanceOf(LocalFilesystemStorage::class, StorageFactory::forProvider('local', $this->tenant(101)));
            });
        });
    }

    public function testForProviderTreatsAnyNonLocalProviderAsS3(): void
    {
        $this->withEnv(['S3_ENDPOINT' => 'http://minio:9000', 'S3_BUCKET' => 'centralvet-local'], function (): void {
            Assert::instanceOf(S3CompatibleStorage::class, StorageFactory::forProvider('minio', $this->tenant(101)));
            Assert::instanceOf(S3CompatibleStorage::class, StorageFactory::forProvider('s3', $this->tenant(101)));
        });
    }

    public function testForPatientPhotosUsesFallbackOnlyWhenLocalAndS3Configured(): void
    {
        $this->withTempRoot(function (string $root): void {
            $withS3 = ['STORAGE_DRIVER' => 'local', 'STORAGE_LOCAL_ROOT' => $root, 'S3_ENDPOINT' => 'http://minio:9000', 'S3_BUCKET' => 'b'];
            $this->withEnv($withS3, function (): void {
                Assert::instanceOf(FallbackReadStorage::class, StorageFactory::forPatientPhotos($this->tenant(101)));
            });

            $withoutS3 = ['STORAGE_DRIVER' => 'local', 'STORAGE_LOCAL_ROOT' => $root, 'S3_ENDPOINT' => '', 'S3_BUCKET' => ''];
            $this->withEnv($withoutS3, function (): void {
                Assert::instanceOf(LazyStorage::class, StorageFactory::forPatientPhotos($this->tenant(101)));
            });

            $s3Driver = ['STORAGE_DRIVER' => 's3', 'S3_ENDPOINT' => 'http://minio:9000', 'S3_BUCKET' => 'b'];
            $this->withEnv($s3Driver, function (): void {
                Assert::instanceOf(LazyStorage::class, StorageFactory::forPatientPhotos($this->tenant(101)));
            });
        });
    }

    public function testFallbackReadsSecondaryWhenPrimaryLacksTheKey(): void
    {
        $primary = new FakeStorage();
        $secondary = new FakeStorage();
        $secondary->put('photos/old.png', 'legacy-bytes');
        $primary->put('photos/new.png', 'new-bytes');

        $storage = new FallbackReadStorage($primary, static fn (): StorageInterface => $secondary);

        Assert::same('legacy-bytes', $storage->get('photos/old.png'));
        Assert::same('new-bytes', $storage->get('photos/new.png'));
        Assert::true($storage->exists('photos/old.png'));
        Assert::true($storage->exists('photos/new.png'));
        Assert::false($storage->exists('photos/none.png'));
    }

    public function testFallbackPrefersPrimaryWhenBothHaveTheKey(): void
    {
        $primary = new FakeStorage();
        $secondary = new FakeStorage();
        $primary->put('photos/p.png', 'primary');
        $secondary->put('photos/p.png', 'secondary');

        $storage = new FallbackReadStorage($primary, static fn (): StorageInterface => $secondary);

        Assert::same('primary', $storage->get('photos/p.png'));
    }

    public function testFallbackWritesAndPresignsOnPrimary(): void
    {
        $primary = new FakeStorage();
        $secondary = new FakeStorage();
        $storage = new FallbackReadStorage($primary, static fn (): StorageInterface => $secondary);

        $storage->put('photos/x.png', 'x');

        Assert::true($primary->exists('photos/x.png'));
        Assert::false($secondary->exists('photos/x.png'));
        Assert::same($primary->presignedUrl('photos/x.png', 60), $storage->presignedUrl('photos/x.png', 60));
    }

    public function testFallbackDeleteGoesToSecondaryWhenPrimaryLacksTheKey(): void
    {
        $primary = new FakeStorage();
        $secondary = new FakeStorage();
        $secondary->put('photos/old.png', 'legacy');
        $primary->put('photos/new.png', 'new');
        $secondary->put('photos/new.png', 'shadow');

        $storage = new FallbackReadStorage($primary, static fn (): StorageInterface => $secondary);

        $storage->delete('photos/old.png');
        Assert::false($secondary->exists('photos/old.png'));

        $storage->delete('photos/new.png');
        Assert::false($primary->exists('photos/new.png'));
        Assert::true($secondary->exists('photos/new.png'));
    }

    /** Revisão final: local driver with a missing root must still serve legacy photos from S3. */
    public function testFallbackReadsSecondaryWhenPrimaryCannotBeQueried(): void
    {
        $missing = sys_get_temp_dir() . '/cv-storage-factory-missing-' . bin2hex(random_bytes(6));
        $this->withEnv(['STORAGE_DRIVER' => 'local', 'STORAGE_LOCAL_ROOT' => $missing], function (): void {
            $secondary = new FakeStorage();
            $secondary->put('photos/old.png', 'legacy');
            $storage = new FallbackReadStorage(StorageFactory::forWrites($this->tenant(101)), static fn (): StorageInterface => $secondary);

            Assert::true($storage->exists('photos/old.png'));
            Assert::same('legacy', $storage->get('photos/old.png'));

            $storage->delete('photos/old.png');
            Assert::false($secondary->exists('photos/old.png'));
        });
    }

    /** Revisão final: the S3 provider recorded on stored_object is normalized like the driver. */
    public function testS3ProviderFromEnvironmentIsTrimmedAndLowercased(): void
    {
        $this->withEnv(['STORAGE_DRIVER' => ' S3 ', 'S3_ENDPOINT' => 'http://minio:9000', 'S3_BUCKET' => 'centralvet-local'], function (): void {
            $storage = S3CompatibleStorage::fromEnvironment($this->tenant(101));
            $provider = (new \ReflectionProperty(S3CompatibleStorage::class, 'provider'))->getValue($storage);

            Assert::same('s3', $provider);
        });
    }

    public function testFallbackTreatsSecondaryFailureAsMissingAndResolvesItOnce(): void
    {
        $calls = 0;
        $storage = new FallbackReadStorage(new FakeStorage(), static function () use (&$calls): StorageInterface {
            $calls++;
            throw new RuntimeException('S3 not reachable');
        });

        Assert::same(0, $calls);
        Assert::false($storage->exists('photos/a.png'));
        Assert::false($storage->exists('photos/b.png'));
        Assert::same(1, $calls);

        $resolved = 0;
        $secondary = new FakeStorage();
        $lazy = new FallbackReadStorage(new FakeStorage(), static function () use (&$resolved, $secondary): StorageInterface {
            $resolved++;

            return $secondary;
        });
        $lazy->exists('a');
        $lazy->exists('b');
        Assert::same(1, $resolved);
    }

    public function testLazyStorageCreatesDelegateOnFirstUseOnly(): void
    {
        $created = 0;
        $inner = new FakeStorage();
        $storage = new LazyStorage(static function () use (&$created, $inner): StorageInterface {
            $created++;

            return $inner;
        });

        Assert::same(0, $created);

        $storage->put('k', 'v', 'text/plain');
        Assert::same('v', $storage->get('k'));
        Assert::true($storage->exists('k'));
        Assert::same($inner->presignedUrl('k', 30), $storage->presignedUrl('k', 30));
        $storage->delete('k');

        Assert::false($inner->exists('k'));
        Assert::same(1, $created);
    }

    public function testLazyStorageFactoryFailureSurfacesFromTheOperation(): void
    {
        $storage = new LazyStorage(static function (): StorageInterface {
            throw new StorageException('Local storage root is not a directory');
        });

        Assert::throws(StorageException::class, static fn () => $storage->get('k'));
    }

    private function tenant(int $tenantId): TenantContext
    {
        return TenantContext::authenticated($tenantId, 1);
    }

    /**
     * Sets the given variables (null = unset) for the callback and restores
     * every variable this test may touch afterwards, even on failure.
     *
     * @param array<string, string|null> $vars
     */
    private function withEnv(array $vars, callable $callback): void
    {
        $previous = [];
        foreach (self::ENV_VARS as $name) {
            $previous[$name] = getenv($name);
        }

        try {
            foreach ($vars as $name => $value) {
                putenv($value === null ? $name : $name . '=' . $value);
            }

            $callback();
        } finally {
            foreach ($previous as $name => $value) {
                putenv($value === false ? $name : $name . '=' . $value);
            }
        }
    }

    private function withTempRoot(callable $callback): void
    {
        $root = sys_get_temp_dir() . '/cv-storage-factory-' . bin2hex(random_bytes(6));
        mkdir($root, 0750, true);

        try {
            $callback($root);
        } finally {
            $this->removeTree($root);
        }
    }

    private function removeTree(string $path): void
    {
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
