<?php

declare(strict_types=1);

namespace CentralVet\Domain\Contract;

use CentralVet\Persistence\TenantRepositoryInterface;

/**
 * Persistence boundary for the Prescription aggregate.
 *
 * @template TEntity of object
 * @extends TenantRepositoryInterface<TEntity>
 */
interface PrescriptionRepositoryInterface extends TenantRepositoryInterface
{
    /**
     * Lists prescriptions for a patient within the current tenant.
     *
     * @return list<TEntity>
     */
    public function listByPatient(int $patientId): array;

    /**
     * Lists prescriptions for an encounter within the current tenant.
     *
     * @return list<TEntity>
     */
    public function listByEncounter(int $encounterId): array;
}
