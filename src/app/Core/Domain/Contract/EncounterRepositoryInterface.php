<?php

declare(strict_types=1);

namespace CentralVet\Domain\Contract;

use CentralVet\Persistence\TenantRepositoryInterface;

/**
 * Persistence boundary for the Encounter aggregate.
 *
 * @template TEntity of object
 * @extends TenantRepositoryInterface<TEntity>
 */
interface EncounterRepositoryInterface extends TenantRepositoryInterface
{
    /**
     * Lists in-progress encounters for a patient within the current tenant.
     *
     * @return list<TEntity>
     */
    public function listInProgressByPatient(int $patientId): array;
}
