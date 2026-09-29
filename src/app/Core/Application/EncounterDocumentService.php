<?php

declare(strict_types=1);

namespace CentralVet\Application;

use CentralVet\Storage\StorageInterface;
use CentralVet\Tenancy\TenantContext;

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
 * instead of inventing a new one: "tenant/<tenantId>/encounter/<encounterId>/<fileName>".
 * This is on top of whatever tenant-scoping the concrete StorageInterface
 * implementation itself applies (e.g. S3CompatibleStorage, which further
 * wraps the key via ObjectKeyNamespace::tenantKey using the same
 * TenantContext), so two tenants — or two encounters of the same tenant —
 * can never collide on or be confused with the same logical key, even
 * against a simple in-memory fake used in tests that does no scoping of its
 * own.
 *
 * Known limitation: list() has no index of "which keys belong to this
 * encounterId" to query yet — StorageInterface only exposes
 * get/put/exists/delete/presignedUrl for a single known key, it has no
 * listing operation, and no `stored_object` repository exists in this plan
 * (the migration that would create that table is not part of Phase 2). So
 * list() returns an empty array until such an index/listing capability is
 * introduced; this is a documented gap, not a bug.
 */
final class EncounterDocumentService
{
    public function __construct(
        private readonly StorageInterface $storage,
        private readonly TenantContext $tenant,
    ) {
    }

    public function attach(int $encounterId, string $fileName, string $contents, string $contentType): object
    {
        return $this->storage->put(
            $this->key($encounterId, $fileName),
            $contents,
            $contentType,
        );
    }

    /**
     * @return list<object> Always empty today — see class docblock.
     */
    public function list(int $encounterId): array
    {
        return [];
    }

    private function key(int $encounterId, string $fileName): string
    {
        return sprintf(
            'tenant/%d/encounter/%d/%s',
            $this->tenant->tenantId(),
            $encounterId,
            self::sanitizeFileName($fileName),
        );
    }

    private static function sanitizeFileName(string $fileName): string
    {
        $safe = (string) preg_replace('/[^A-Za-z0-9_.\-]+/', '_', $fileName);

        return $safe === '' ? '_' : $safe;
    }
}
