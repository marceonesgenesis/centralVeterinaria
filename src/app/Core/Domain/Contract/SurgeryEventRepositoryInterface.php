<?php

declare(strict_types=1);

namespace CentralVet\Domain\Contract;

use CentralVet\Persistence\TenantRepositoryInterface;

/**
 * Persistence boundary for SurgeryEvent (append-only timeline: `remove`
 * throws \LogicException).
 *
 * @template TEntity of object
 * @extends TenantRepositoryInterface<TEntity>
 */
interface SurgeryEventRepositoryInterface extends TenantRepositoryInterface
{
    /**
     * Lists a surgery's events, most recent first (recorded_at DESC,
     * id DESC), within the current tenant.
     *
     * @return list<TEntity>
     */
    public function listBySurgery(int $surgeryId): array;
}
