<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Application\EncounterDocumentService;
use CentralVet\Storage\StorageInterface;
use CentralVet\Storage\StoredObjectMetadata;
use CentralVet\Tenancy\TenantContext;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\FakeStorage;
use CentralVet\Tests\Support\FakeStoredObjectRepository;
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
        $service = new EncounterDocumentService($storage, TenantContext::authenticated(7, 3, 5), $objects);

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
        $service = new EncounterDocumentService($storage, TenantContext::authenticated(7, 3, 5), $objects);

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
        $service = new EncounterDocumentService($storage, TenantContext::authenticated(7, 3, 5), $objects);

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
