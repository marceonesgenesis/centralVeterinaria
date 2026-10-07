<?php

declare(strict_types=1);

namespace CentralVet\Application;

use CentralVet\Domain\Contract\EncounterRepositoryInterface;
use CentralVet\Domain\Contract\StoredObjectRepositoryInterface;
use CentralVet\Storage\StorageInterface;
use CentralVet\Tenancy\TenantContext;
use Throwable;

/**
 * Attaches documents (exam results, prescriptions, images, etc.) to an
 * encounter, reusing the Phase 0 object storage (StorageInterface) instead
 * of creating any new persistence mechanism — no new table is created; the
 * returned metadata mirrors the `stored_object` table shape already defined
 * by CentralVet\Storage\StoredObjectMetadata.
 *
 * Every logical key handed to StorageInterface::put() is explicitly
 * prefixed by tenant and by encounterId at this layer, mirroring the
 * segment-based convention already used by
 * CentralVet\Storage\ObjectKeyNamespace ("<root>/<env>/tenant/<id>/<ns>/<key>")
 * instead of inventing a new one: "tenant/<tenantId>/encounter/<encounterId>/<12 hex>-<fileName>"
 * (the random segment makes every attachment its own object, T-52).
 * This is on top of whatever tenant-scoping the concrete StorageInterface
 * implementation itself applies (e.g. S3CompatibleStorage, which further
 * wraps the key via ObjectKeyNamespace::tenantKey using the same
 * TenantContext), so two tenants — or two encounters of the same tenant —
 * can never collide on or be confused with the same logical key, even
 * against a simple in-memory fake used in tests that does no scoping of its
 * own.
 *
 * Index (rodada 2, T-52): with a StoredObjectRepositoryInterface, attach()
 * also records the uploaded object in `stored_object` (migration 0001),
 * and list()/download() read that index back. The link to the encounter is
 * the logical prefix "tenant/<tenantId>/encounter/<encounterId>/" inside
 * object_key — no new column or table. If the record fails, the object just
 * written (and only that one, thanks to the unique key) is deleted and the
 * error is rethrown. The original file name is kept in original_name.
 *
 * Without the repository (the 2-argument constructor) there is no index to
 * read, so list() returns [] and download() returns null; EncounterView and
 * ExamResultForm both pass it (T-56). Objects uploaded before T-52 were never
 * recorded and are not listed. When the caller's transaction fails after
 * attach(), discard() removes the object so it is not left orphaned.
 *
 * download() (rodada 3, T-03) also needs the EncounterRepositoryInterface
 * (4th argument) and a selected unit: the encounter must exist in that unit
 * and the row's system_unit_id, when set, must be that unit. ExamResultForm
 * only attaches, so it does not pass the encounter repository.
 */
final class EncounterDocumentService
{
    public function __construct(
        private readonly StorageInterface $storage,
        private readonly TenantContext $tenant,
        private readonly ?StoredObjectRepositoryInterface $objects = null,
        private readonly ?EncounterRepositoryInterface $encounters = null,
    ) {
    }

    public function attach(int $encounterId, string $fileName, string $contents, string $contentType): object
    {
        $key = $this->key($encounterId, $fileName);
        $metadata = $this->storage->put($key, $contents, $contentType);

        if ($this->objects === null) {
            return $metadata;
        }

        try {
            $this->objects->record($metadata, $fileName, $this->tenant->unitId(), $this->tenant->userId());
        } catch (Throwable $e) {
            try {
                $this->storage->delete($key);
            } catch (Throwable) {
                // the record error is the one that matters to the caller
            }

            throw $e;
        }

        return $metadata;
    }

    /**
     * Removes from the storage the object of an attach() whose surrounding
     * transaction failed to commit (T-56), so no orphan is left behind. The
     * metadata carries the key the storage returned, which may wrap the
     * logical key in the storage's own namespace; delete() expects the
     * logical key again. Runs inside a catch: a storage failure is logged
     * and never thrown.
     */
    public function discard(object $metadata): void
    {
        $objectKey = (string) ($metadata->objectKey ?? '');
        $marker = sprintf('tenant/%d/encounter/', $this->tenant->tenantId());
        $position = strpos($objectKey, $marker);
        $key = $position === false ? $objectKey : substr($objectKey, $position);

        try {
            $this->storage->delete($key);
        } catch (Throwable $e) {
            error_log(sprintf('%s: could not delete "%s": %s', __METHOD__, $key, $e->getMessage()));
        }
    }

    /**
     * @return list<array{public_id: string, original_name: string, content_type: string, size_bytes: int, created_at: string, object_key: string}>
     *         Newest first; always empty without a StoredObjectRepositoryInterface.
     */
    public function list(int $encounterId): array
    {
        if ($this->objects === null) {
            return [];
        }

        return $this->objects->listByObjectKeyFragment($this->prefix($encounterId));
    }

    /**
     * Bytes of an attachment of this encounter, or null when the public_id is
     * unknown (or not available) for the tenant, belongs to another encounter
     * or to another unit, when the encounter does not exist in the selected
     * unit, when no unit is selected, or without either repository.
     *
     * @return array{contents: string, content_type: string, original_name: string}|null
     */
    public function download(int $encounterId, string $publicId): ?array
    {
        $unitId = $this->tenant->unitId();

        if ($this->objects === null || $this->encounters === null || $unitId === null) {
            return null;
        }

        $encounter = $this->encounters->findById($encounterId);

        if ($encounter === null || $encounter->systemUnitId() !== $unitId) {
            return null;
        }

        $row = $this->objects->findByPublicId($publicId);

        if ($row === null || ($row['system_unit_id'] !== null && (int) $row['system_unit_id'] !== $unitId)) {
            return null;
        }

        $prefix = $this->prefix($encounterId);
        $objectKey = (string) $row['object_key'];
        $position = strpos($objectKey, $prefix);

        if ($position === false) {
            return null;
        }

        // object_key is the key the storage returned (it may wrap the logical
        // key in its own namespace); get() expects the logical key again.
        return [
            'contents' => $this->storage->get(substr($objectKey, $position)),
            'content_type' => (string) $row['content_type'],
            'original_name' => (string) $row['original_name'],
        ];
    }

    private function prefix(int $encounterId): string
    {
        return sprintf('tenant/%d/encounter/%d/', $this->tenant->tenantId(), $encounterId);
    }

    private function key(int $encounterId, string $fileName): string
    {
        // unique segment per attachment (same pattern as the patient photo,
        // T-12/T-47): re-attaching a file with the same name never overwrites
        // an object an earlier stored_object row still points to
        return $this->prefix($encounterId) . bin2hex(random_bytes(6)) . '-' . self::sanitizeFileName($fileName);
    }

    private static function sanitizeFileName(string $fileName): string
    {
        $safe = (string) preg_replace('/[^A-Za-z0-9_.\-]+/', '_', $fileName);

        return $safe === '' ? '_' : $safe;
    }
}
