<?php

declare(strict_types=1);

namespace CentralVet\Domain\Contract;

use CentralVet\Persistence\TenantRepositoryInterface;

/**
 * Persistence boundary for PrescriptionTemplate (rodada 2, T-13). Every
 * method is scoped to the authenticated tenant (ADR 0002): another tenant's
 * template is indistinguishable from a nonexistent one (null / absent).
 *
 * @template TEntity of object
 * @extends TenantRepositoryInterface<TEntity>
 */
interface PrescriptionTemplateRepositoryInterface extends TenantRepositoryInterface
{
    /**
     * Finds a template by its name (unique per tenant) within the current tenant.
     *
     * @return TEntity|null
     */
    public function findByName(string $name): ?object;

    /**
     * Lists the current tenant's templates, ordered by name, with their items.
     *
     * @return list<TEntity>
     */
    public function listAll(): array;
}
