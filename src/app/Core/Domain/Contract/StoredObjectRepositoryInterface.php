<?php

declare(strict_types=1);

namespace CentralVet\Domain\Contract;

use CentralVet\Storage\StoredObjectMetadata;

/**
 * Index of uploaded objects in the `stored_object` table (migration 0001),
 * rodada 2, T-52. Every method is scoped to the tenant of the current
 * TenantContext; the tenant is never taken from caller input.
 */
interface StoredObjectRepositoryInterface
{
    /**
     * Writes one `stored_object` row (UUID v4 public_id, tenant of the
     * context, status 'available', the columns of
     * StoredObjectMetadata::toStoredObjectRow()) and returns it.
     *
     * @return array<string, mixed>
     */
    public function record(StoredObjectMetadata $metadata, string $originalName, ?int $systemUnitId, int $createdBy): array;

    /**
     * Available, non-deleted objects of the tenant whose object_key contains
     * $fragment, newest first (created_at DESC, id DESC).
     *
     * @return list<array{public_id: string, original_name: string, content_type: string, size_bytes: int, created_at: string, object_key: string}>
     */
    public function listByObjectKeyFragment(string $fragment): array;

    /** @return array<string, mixed>|null The tenant's row, or null. */
    public function findByPublicId(string $publicId): ?array;
}
