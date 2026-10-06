<?php

declare(strict_types=1);

namespace CentralVet\Domain\Contract;

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
}
