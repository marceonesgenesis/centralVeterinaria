<?php

declare(strict_types=1);

namespace CentralVet\Domain\Contract;

use CentralVet\Persistence\TenantRepositoryInterface;

/**
 * Persistence boundary for the BankAccount aggregate (rodada 2, T-15).
 * Every lookup is scoped to the current tenant (ADR 0002).
 *
 * @template TEntity of object
 * @extends TenantRepositoryInterface<TEntity>
 */
interface BankAccountRepositoryInterface extends TenantRepositoryInterface
{
    /**
     * Finds the account of the unit with this name (trimmed, compared with
     * the column collation, i.e. case-insensitive), or null.
     *
     * @return TEntity|null
     */
    public function findByName(int $systemUnitId, string $name): ?object;

    /**
     * Lists every account (active and inactive) of the unit within the
     * current tenant, ordered by name then id.
     *
     * @return list<TEntity>
     */
    public function listBySystemUnit(int $systemUnitId): array;
}
