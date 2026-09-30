<?php

declare(strict_types=1);

namespace CentralVet\Application;

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
 * Known limitation: without the repository (the 2-argument constructor, e.g.
 * ExamResultForm) there is still no index to read, so list() returns [] and
 * download() returns null. Objects uploaded before T-52 were never recorded
 * and are not listed.
 */
final class EncounterDocumentService
{
    public function __construct(
        private readonly StorageInterface $storage,
        private readonly TenantContext $tenant,
        private readonly ?StoredObjectRepositoryInterface $objects = null,
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
     * unknown for the tenant or belongs to another encounter.
     *
     * @return array{contents: string, content_type: string, original_name: string}|null
     */
    public function download(int $encounterId, string $publicId): ?array
    {
        if ($this->objects === null) {
            return null;
        }

        $row = $this->objects->findByPublicId($publicId);

        if ($row === null) {
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
