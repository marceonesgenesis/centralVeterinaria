<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Application\EncounterDocumentService;
use CentralVet\Domain\Encounter;
use CentralVet\Storage\StorageInterface;
use CentralVet\Storage\StoredObjectMetadata;
use CentralVet\Tenancy\TenantContext;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\FakeEncounterRepository;
use CentralVet\Tests\Support\FakeStorage;
use CentralVet\Tests\Support\FakeStoredObjectRepository;
use DateTimeImmutable;
use RuntimeException;

/**
 * Unit tests for EncounterDocumentService (T-08), against FakeStorage — an
 * in-memory StorageInterface double that does no key namespacing of its own,
 * so a passing test here proves EncounterDocumentService itself (not the
 * storage backend) prefixes every key by tenant and by encounterId. Covers
 * T-05's own acceptance criterion.
 */
final class EncounterDocumentServiceTest
{
    public function testAttachGeneratesKeyContainingTenantIdAndEncounterId(): void
    {
        $storage = new FakeStorage();
        $tenant = TenantContext::authenticated(tenantId: 7, userId: 1, unitId: 1);
        $service = new EncounterDocumentService($storage, $tenant);

        $metadata = $service->attach(42, 'exame-sangue.pdf', 'conteudo binario', 'application/pdf');

        Assert::stringContains('tenant/7/', $metadata->objectKey);
        Assert::stringContains('encounter/42/', $metadata->objectKey);
        Assert::true($storage->exists($metadata->objectKey));
    }

    /**
     * Proves the tenant/encounterId prefixing is not decorative: attaching
     * under two different tenants (same encounterId) and, separately, two
     * different encounters (same tenant) never collide on the same storage
     * key — the class docblock's own claim.
     */
    public function testAttachKeysNeverCollideAcrossTenantsOrEncounters(): void
    {
        $storage = new FakeStorage();
        $tenantA = new EncounterDocumentService($storage, TenantContext::authenticated(1, 1, 1));
        $tenantB = new EncounterDocumentService($storage, TenantContext::authenticated(2, 1, 1));

        $metaA = $tenantA->attach(42, 'laudo.pdf', 'a', 'application/pdf');
        $metaB = $tenantB->attach(42, 'laudo.pdf', 'b', 'application/pdf');

        Assert::true($metaA->objectKey !== $metaB->objectKey, 'Keys for different tenants must not collide');

        $otherEncounter = $tenantA->attach(43, 'laudo.pdf', 'c', 'application/pdf');
        Assert::true($metaA->objectKey !== $otherEncounter->objectKey, 'Keys for different encounters must not collide');
    }

    /**
     * Without a StoredObjectRepositoryInterface (callers with 2 arguments,
     * e.g. ExamResultForm) there is still no index to read from, so list()
     * keeps returning [] — the documented gap only for that wiring.
     */
    public function testListReturnsEmptyArrayPerDocumentedGap(): void
    {
        $storage = new FakeStorage();
        $service = new EncounterDocumentService($storage, TenantContext::authenticated(1, 1, 1));

        $service->attach(42, 'laudo.pdf', 'conteudo', 'application/pdf');

        Assert::count(0, $service->list(42));
    }

    public function testAttachRecordsStoredObjectAndListIsScopedToEncounter(): void
    {
        $storage = new FakeStorage();
        $objects = new FakeStoredObjectRepository(7);
        $service = new EncounterDocumentService($storage, TenantContext::authenticated(7, 3, 5), $objects);

        $metadata = $service->attach(10, 'r2.pdf', '%PDF-bytes', 'application/pdf');
        Assert::true($storage->exists($metadata->objectKey));

        $rows = $objects->allRows();
        Assert::count(1, $rows);
        Assert::same(7, $rows[0]['tenant_id']);
        Assert::same(5, $rows[0]['system_unit_id']);
        Assert::same(3, $rows[0]['created_by']);
        Assert::same($metadata->objectKey, $rows[0]['object_key']);

        $listed = $service->list(10);
        Assert::count(1, $listed);
        Assert::same('r2.pdf', $listed[0]['original_name']);
        Assert::same('application/pdf', $listed[0]['content_type']);
        Assert::same(strlen('%PDF-bytes'), $listed[0]['size_bytes']);

        Assert::same([], $service->list(11));
        Assert::same([], $service->list(1), 'encounter 1 must not match encounter 10 by prefix');
    }

    public function testDownloadReturnsBytesOnlyForTheSameEncounter(): void
    {
        $storage = new FakeStorage();
        $objects = new FakeStoredObjectRepository(7);
        $service = new EncounterDocumentService($storage, TenantContext::authenticated(7, 3, 5), $objects, self::encounters(10, 11));

        $service->attach(10, 'r2 laudo.pdf', '%PDF-bytes', 'application/pdf');
        $publicId = $service->list(10)[0]['public_id'];

        $download = $service->download(10, $publicId);
        Assert::notNull($download);
        Assert::same('%PDF-bytes', $download['contents']);
        Assert::same('application/pdf', $download['content_type']);
        Assert::same('r2 laudo.pdf', $download['original_name']);

        Assert::null($service->download(11, $publicId), 'another encounter cannot download it');
        Assert::null($service->download(10, '00000000-0000-4000-8000-999999999999'), 'unknown public_id');
    }

    public function testRecordFailureDeletesTheStoredObjectAndRethrows(): void
    {
        $storage = self::recordingStorage(new FakeStorage());
        $objects = new FakeStoredObjectRepository(7);
        $objects->failNextRecordWith(new RuntimeException('insert failed'));
        $service = new EncounterDocumentService($storage, TenantContext::authenticated(7, 3, 5), $objects);

        $thrown = null;

        try {
            $service->attach(10, 'r2.pdf', 'bytes', 'application/pdf');
        } catch (RuntimeException $e) {
            $thrown = $e;
        }

        Assert::notNull($thrown, 'record() failure must be rethrown');
        Assert::same('insert failed', $thrown->getMessage());
        Assert::count(1, $storage->putKeys);
        Assert::false($storage->exists($storage->putKeys[0]), 'the object just written must be deleted');
        Assert::count(0, $objects->allRows());
    }

    /**
     * Re-attaching a file with the same name to the same encounter keeps
     * both objects: each attachment gets its own key (unique segment), each
     * download returns its own bytes and the original name.
     */
    public function testReattachingSameNameKeepsDistinctObjectsAndBytes(): void
    {
        $storage = new FakeStorage();
        $objects = new FakeStoredObjectRepository(7);
        $service = new EncounterDocumentService($storage, TenantContext::authenticated(7, 3, 5), $objects, self::encounters(10, 11));

        $first = $service->attach(10, 'laudo.pdf', 'PRIMEIRO', 'application/pdf');
        $second = $service->attach(10, 'laudo.pdf', 'SEGUNDO-MAIOR', 'application/pdf');

        Assert::true($first->objectKey !== $second->objectKey, 'same name must not reuse the storage key');
        foreach ([$first, $second] as $metadata) {
            Assert::true(
                preg_match('#^tenant/7/encounter/10/[0-9a-f]{12}-laudo\.pdf$#D', $metadata->objectKey) === 1,
                'key keeps the encounter prefix plus a unique segment: ' . $metadata->objectKey,
            );
        }
        Assert::same('PRIMEIRO', $storage->get($first->objectKey));
        Assert::same('SEGUNDO-MAIOR', $storage->get($second->objectKey));

        $listed = $service->list(10);
        Assert::count(2, $listed);
        $bytesByKey = [];
        foreach ($listed as $row) {
            $download = $service->download(10, $row['public_id']);
            Assert::notNull($download);
            Assert::same('laudo.pdf', $download['original_name']);
            Assert::same($row['size_bytes'], strlen($download['contents']));
            $bytesByKey[$row['object_key']] = $download['contents'];
        }
        Assert::same('PRIMEIRO', $bytesByKey[$first->objectKey] ?? null);
        Assert::same('SEGUNDO-MAIOR', $bytesByKey[$second->objectKey] ?? null);
    }

    /** A failed re-attach only removes its own new object, never the one already recorded. */
    public function testFailedReattachKeepsThePreviousObject(): void
    {
        $storage = self::recordingStorage(new FakeStorage());
        $objects = new FakeStoredObjectRepository(7);
        $service = new EncounterDocumentService($storage, TenantContext::authenticated(7, 3, 5), $objects, self::encounters(10, 11));

        $first = $service->attach(10, 'laudo.pdf', 'PRIMEIRO', 'application/pdf');
        $objects->failNextRecordWith(new RuntimeException('insert failed'));

        try {
            $service->attach(10, 'laudo.pdf', 'SEGUNDO', 'application/pdf');
            Assert::true(false, 'record() failure must be rethrown');
        } catch (RuntimeException $e) {
            Assert::same('insert failed', $e->getMessage());
        }

        Assert::count(2, $storage->putKeys);
        Assert::true($storage->exists($first->objectKey), 'the previous object must survive');
        Assert::false($storage->exists($storage->putKeys[1]), 'only the new object is rolled back');

        $listed = $service->list(10);
        Assert::count(1, $listed);
        Assert::same('PRIMEIRO', $service->download(10, $listed[0]['public_id'])['contents'] ?? null);
    }

    /**
     * T-03 (rodada 3): download also checks the object's status, the unit of
     * the row and of the encounter, and that the encounter exists.
     */
    public function testDownloadRefusesAnotherUnitDeletedObjectOrMissingEncounter(): void
    {
        $storage = new FakeStorage();
        $objects = new FakeStoredObjectRepository(7);
        $encounters = self::encounters(10);
        $service = new EncounterDocumentService($storage, TenantContext::authenticated(7, 3, 5), $objects, $encounters);
        $otherUnit = new EncounterDocumentService($storage, TenantContext::authenticated(7, 3, 9), $objects, $encounters);

        $service->attach(10, 'ok.pdf', 'OK-BYTES', 'application/pdf');
        $otherUnit->attach(10, 'unit9.pdf', 'UNIT-9', 'application/pdf');
        $service->attach(10, 'deleted.pdf', 'DELETED', 'application/pdf');
        $service->attach(12, 'orphan.pdf', 'ORPHAN', 'application/pdf');

        $byName = [];
        foreach ($objects->allRows() as $row) {
            $byName[$row['original_name']] = (string) $row['public_id'];
        }
        $objects->updateRow($byName['deleted.pdf'], ['status' => 'deleted']);

        Assert::null($service->download(10, $byName['unit9.pdf']), 'row of unit 9 is refused in unit 5');
        Assert::null($service->download(10, $byName['deleted.pdf']), 'deleted row is refused');
        Assert::null($service->download(12, $byName['orphan.pdf']), 'missing encounter is refused');
        Assert::same('OK-BYTES', $service->download(10, $byName['ok.pdf'])['contents'] ?? null);

        $noEncounters = new EncounterDocumentService($storage, TenantContext::authenticated(7, 3, 5), $objects);
        Assert::null($noEncounters->download(10, $byName['ok.pdf']), 'without the encounter repository there is no download');
        $noUnit = new EncounterDocumentService($storage, TenantContext::authenticated(7, 3), $objects, $encounters);
        Assert::null($noUnit->download(10, $byName['ok.pdf']), 'without a selected unit there is no download');
        $encounterOfUnit9 = new EncounterDocumentService($storage, TenantContext::authenticated(7, 3, 9), $objects, $encounters);
        Assert::null($encounterOfUnit9->download(10, $byName['unit9.pdf']), 'encounter of unit 5 is refused in unit 9');
    }

    /**
     * Rodada 4 (T-02): with a reader closure, download() reads through the
     * storage of the row's storage_provider, not through the write storage.
     */
    public function testDownloadReadsThroughTheStorageOfTheRowProvider(): void
    {
        $original = new FakeStorage();
        $objects = new FakeStoredObjectRepository(7);
        $encounters = self::encounters(10);
        $writer = new EncounterDocumentService($original, TenantContext::authenticated(7, 3, 5), $objects, $encounters);
        $writer->attach(10, 'antigo.pdf', 'OLD-BYTES', 'application/pdf');
        $publicId = $writer->list(10)[0]['public_id'];

        $providers = [];
        $reader = static function (string $storageProvider) use (&$providers, $original): StorageInterface {
            $providers[] = $storageProvider;

            return $original;
        };
        $service = new EncounterDocumentService(new FakeStorage(), TenantContext::authenticated(7, 3, 5), $objects, $encounters, $reader);

        $contents = null;
        try {
            $contents = $service->download(10, $publicId)['contents'] ?? null;
        } catch (RuntimeException) {
            // the read went to the (empty) write storage
        }

        Assert::same('OLD-BYTES', $contents, 'download must read through the storage of the row provider');
        Assert::same(['fake'], $providers);
    }

    /** T-02: a refused download (another unit, unknown public_id) never resolves a reader. */
    public function testRefusedDownloadNeverResolvesAReader(): void
    {
        $storage = new FakeStorage();
        $objects = new FakeStoredObjectRepository(7);
        $encounters = self::encounters(10, 11);
        $calls = 0;
        $reader = static function (string $storageProvider) use (&$calls, $storage): StorageInterface {
            $calls++;

            return $storage;
        };
        $unit9 = new EncounterDocumentService($storage, TenantContext::authenticated(7, 3, 9), $objects, $encounters, $reader);
        $unit5 = new EncounterDocumentService($storage, TenantContext::authenticated(7, 3, 5), $objects, $encounters, $reader);

        $unit9->attach(10, 'unit9.pdf', 'UNIT-9', 'application/pdf');
        $publicId = $objects->allRows()[0]['public_id'];

        Assert::null($unit5->download(10, $publicId), 'row of unit 9 is refused in unit 5');
        Assert::null($unit5->download(10, '00000000-0000-4000-8000-999999999999'), 'unknown public_id');
        Assert::null($unit9->download(10, $publicId), 'encounter of unit 5 is refused in unit 9');
        $unit5->attach(10, 'unit5.pdf', 'UNIT-5', 'application/pdf');
        $ownPublicId = $objects->allRows()[1]['public_id'];
        Assert::null($unit5->download(11, $ownPublicId), 'attachment of encounter 10 is refused through encounter 11');
        Assert::same(0, $calls, 'a refused download must not resolve a reader');
    }

    /** Encounters of tenant 7, unit 5, with the given ids. */
    private static function encounters(int ...$ids): FakeEncounterRepository
    {
        $repository = new FakeEncounterRepository(7);

        foreach ($ids as $id) {
            $encounter = Encounter::start(7, 5, 1, null, 3, new DateTimeImmutable('2031-01-01 10:00:00'));
            $encounter->assignId($id);
            $repository->save($encounter);
        }

        return $repository;
    }

    /** T-56: a commit that fails after attach() lets the caller remove the object just written. */
    public function testDiscardRemovesTheAttachedObject(): void
    {
        $storage = new FakeStorage();
        $objects = new FakeStoredObjectRepository(7);
        $service = new EncounterDocumentService($storage, TenantContext::authenticated(7, 3, 5), $objects);

        $kept = $service->attach(10, 'anterior.pdf', 'ANTERIOR', 'application/pdf');
        $metadata = $service->attach(10, 'laudo.pdf', 'bytes', 'application/pdf');
        Assert::true($storage->exists($metadata->objectKey));

        $service->discard($metadata);

        Assert::false($storage->exists($metadata->objectKey), 'discard() must delete the object of the failed attach');
        Assert::true($storage->exists($kept->objectKey), 'discard() must not touch other objects');
    }

    /**
     * Real storages return the key wrapped in their own namespace (S3:
     * "<root>/<env>/tenant/<t>/objects/<logical key>") while delete() expects
     * the logical key again: discard() must hand delete() the logical key.
     */
    public function testDiscardUsesTheLogicalKeyWhenTheStorageNamespacesKeys(): void
    {
        $inner = new FakeStorage();
        $storage = self::namespacingStorage($inner);
        $service = new EncounterDocumentService($storage, TenantContext::authenticated(7, 3, 5), new FakeStoredObjectRepository(7));

        $metadata = $service->attach(10, 'laudo.pdf', 'bytes', 'application/pdf');
        Assert::true(str_starts_with($metadata->objectKey, 'centralvet/test/tenant/7/objects/tenant/7/encounter/10/'), $metadata->objectKey);
        Assert::true($inner->exists($metadata->objectKey));

        $service->discard($metadata);

        Assert::false($inner->exists($metadata->objectKey), 'the namespaced object must be gone');
    }

    /** discard() runs inside a catch: a storage failure is logged, never thrown. */
    public function testDiscardSwallowsStorageFailure(): void
    {
        $inner = new FakeStorage();
        $storage = self::failingDeleteStorage($inner);
        $service = new EncounterDocumentService($storage, TenantContext::authenticated(7, 3, 5), new FakeStoredObjectRepository(7));
        $metadata = $service->attach(10, 'laudo.pdf', 'bytes', 'application/pdf');

        $log = (string) tempnam(sys_get_temp_dir(), 'cv-discard-');
        $previous = ini_set('error_log', $log);

        try {
            $service->discard($metadata);
            $logged = (string) file_get_contents($log);
        } finally {
            ini_set('error_log', $previous === false ? '' : $previous);
            @unlink($log);
        }

        Assert::true(str_contains($logged, $metadata->objectKey), 'error_log must name the key: ' . $logged);
        Assert::true(str_contains($logged, 'delete refused'), 'error_log must carry the storage error: ' . $logged);
        Assert::true($inner->exists($metadata->objectKey));
    }

    /** StorageInterface whose delete() always throws. */
    private static function failingDeleteStorage(FakeStorage $inner): StorageInterface
    {
        return new class ($inner) implements StorageInterface {
            public function __construct(private readonly FakeStorage $inner)
            {
            }

            public function put(string $key, string $contents, string $contentType = 'application/octet-stream'): StoredObjectMetadata
            {
                return $this->inner->put($key, $contents, $contentType);
            }

            public function get(string $key): string
            {
                return $this->inner->get($key);
            }

            public function exists(string $key): bool
            {
                return $this->inner->exists($key);
            }

            public function delete(string $key): void
            {
                throw new RuntimeException('delete refused');
            }

            public function presignedUrl(string $key, int $ttlSeconds = 300): string
            {
                return $this->inner->presignedUrl($key, $ttlSeconds);
            }
        };
    }

    /** StorageInterface that wraps every logical key like S3CompatibleStorage::fullKey(). */
    private static function namespacingStorage(FakeStorage $inner): StorageInterface
    {
        return new class ($inner) implements StorageInterface {
            public function __construct(private readonly FakeStorage $inner)
            {
            }

            public function put(string $key, string $contents, string $contentType = 'application/octet-stream'): StoredObjectMetadata
            {
                return $this->inner->put($this->full($key), $contents, $contentType);
            }

            public function get(string $key): string
            {
                return $this->inner->get($this->full($key));
            }

            public function exists(string $key): bool
            {
                return $this->inner->exists($this->full($key));
            }

            public function delete(string $key): void
            {
                $this->inner->delete($this->full($key));
            }

            public function presignedUrl(string $key, int $ttlSeconds = 300): string
            {
                return $this->inner->presignedUrl($this->full($key), $ttlSeconds);
            }

            private function full(string $key): string
            {
                return 'centralvet/test/tenant/7/objects/' . $key;
            }
        };
    }

    /** StorageInterface decorator that remembers every key passed to put(). */
    private static function recordingStorage(FakeStorage $inner): StorageInterface
    {
        return new class ($inner) implements StorageInterface {
            /** @var list<string> */
            public array $putKeys = [];

            public function __construct(private readonly FakeStorage $inner)
            {
            }

            public function put(string $key, string $contents, string $contentType = 'application/octet-stream'): StoredObjectMetadata
            {
                $this->putKeys[] = $key;

                return $this->inner->put($key, $contents, $contentType);
            }

            public function get(string $key): string
            {
                return $this->inner->get($key);
            }

            public function exists(string $key): bool
            {
                return $this->inner->exists($key);
            }

            public function delete(string $key): void
            {
                $this->inner->delete($key);
            }

            public function presignedUrl(string $key, int $ttlSeconds = 300): string
            {
                return $this->inner->presignedUrl($key, $ttlSeconds);
            }
        };
    }
}
