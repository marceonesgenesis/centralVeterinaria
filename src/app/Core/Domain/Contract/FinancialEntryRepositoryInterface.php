<?php

declare(strict_types=1);

namespace CentralVet\Domain\Contract;

use CentralVet\Persistence\TenantRepositoryInterface;

/**
 * Persistence boundary for the FinancialEntry aggregate.
 *
 * @template TEntity of object
 * @extends TenantRepositoryInterface<TEntity>
 */
interface FinancialEntryRepositoryInterface extends TenantRepositoryInterface
{
    /**
     * Lists financial entries for a unit within a period (inclusive) within the current tenant.
     *
     * @return list<TEntity>
     */
    public function listBySystemUnitAndPeriod(int $systemUnitId, string $from, string $to): array;
}
