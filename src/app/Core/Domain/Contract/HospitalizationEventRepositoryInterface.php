<?php

declare(strict_types=1);

namespace CentralVet\Domain\Contract;

use CentralVet\Persistence\TenantRepositoryInterface;

/**
 * Persistence boundary for HospitalizationEvent (append-only timeline).
 *
 * @template TEntity of object
 * @extends TenantRepositoryInterface<TEntity>
 */
interface HospitalizationEventRepositoryInterface extends TenantRepositoryInterface
{
    /**
     * Lists a hospitalization's events, most recent first (recorded_at
     * DESC, id DESC), within the current tenant.
     *
     * @return list<TEntity>
     */
    public function listByHospitalization(int $hospitalizationId): array;
}
