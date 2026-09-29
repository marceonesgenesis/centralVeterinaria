<?php

declare(strict_types=1);

namespace CentralVet\Domain\Contract;

use CentralVet\Persistence\TenantRepositoryInterface;

/**
 * Persistence boundary for the Patient aggregate.
 *
 * @template TEntity of object
 * @extends TenantRepositoryInterface<TEntity>
 */
interface PatientRepositoryInterface extends TenantRepositoryInterface
{
    /**
     * Lists patients belonging to a given tutor within the current tenant.
     *
     * @return list<TEntity>
     */
    public function findByTutor(int $tutorId): array;

    /**
     * Searches patients by name within the current tenant.
     *
     * @return list<TEntity>
     */
    public function search(string $term): array;
}
