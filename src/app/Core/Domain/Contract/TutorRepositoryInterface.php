<?php

declare(strict_types=1);

namespace CentralVet\Domain\Contract;

use CentralVet\Persistence\TenantRepositoryInterface;

/**
 * Persistence boundary for the Tutor aggregate.
 *
 * @template TEntity of object
 * @extends TenantRepositoryInterface<TEntity>
 */
interface TutorRepositoryInterface extends TenantRepositoryInterface
{
    /**
     * Finds a tutor by their document (CPF/CNPJ) within the current tenant.
     *
     * @return TEntity|null
     */
    public function findByDocument(string $document): ?object;

    /**
     * Searches tutors by name, document or phone within the current tenant.
     *
     * @return list<TEntity>
     */
    public function search(string $term): array;
}
