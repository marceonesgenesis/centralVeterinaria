<?php

declare(strict_types=1);

namespace CentralVet\Domain\Contract;

use CentralVet\Domain\SurgeryMaterial;
use CentralVet\Persistence\TenantRepositoryInterface;

/**
 * Persistence boundary for SurgeryMaterial (materials are removable until
 * the surgery is completed).
 *
 * @template TEntity of object
 * @extends TenantRepositoryInterface<TEntity>
 */
interface SurgeryMaterialRepositoryInterface extends TenantRepositoryInterface
{
    /**
     * Lists a surgery's materials ordered by recorded_at, id, within the
     * current tenant.
     *
     * @return list<TEntity>
     */
    public function listBySurgery(int $surgeryId): array;

    /**
     * Deletes the material within the current tenant (and its own surgery)
     * and returns how many rows were deleted: 0 when another request already
     * removed it, so the caller can refuse instead of recording a second
     * removal. remove() keeps the void contract of RepositoryInterface.
     */
    public function delete(SurgeryMaterial $material): int;
}
