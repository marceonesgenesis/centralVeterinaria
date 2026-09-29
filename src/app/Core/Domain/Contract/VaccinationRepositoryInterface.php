<?php

declare(strict_types=1);

namespace CentralVet\Domain\Contract;

use CentralVet\Persistence\TenantRepositoryInterface;

/**
 * Persistence boundary for the Vaccination aggregate.
 *
 * @template TEntity of object
 * @extends TenantRepositoryInterface<TEntity>
 */
interface VaccinationRepositoryInterface extends TenantRepositoryInterface
{
    /**
     * Lists a patient's vaccination history (the vaccination card) within the current tenant.
     *
     * @return list<TEntity>
     */
    public function listByPatient(int $patientId): array;

    /**
     * Lists vaccinations for an encounter within the current tenant.
     *
     * @return list<TEntity>
     */
    public function listByEncounter(int $encounterId): array;
}
