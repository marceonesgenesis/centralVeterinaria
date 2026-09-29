<?php

declare(strict_types=1);

namespace CentralVet\Domain\Contract;

use CentralVet\Persistence\TenantRepositoryInterface;

/**
 * Persistence boundary for the ExamResult aggregate.
 *
 * @template TEntity of object
 * @extends TenantRepositoryInterface<TEntity>
 */
interface ExamResultRepositoryInterface extends TenantRepositoryInterface
{
    /**
     * Finds the result of an exam request within the current tenant, if any.
     *
     * @return TEntity|null
     */
    public function findByExamRequest(int $examRequestId): ?object;

    /**
     * Lists results still pending review within the current tenant.
     *
     * @return list<TEntity>
     */
    public function listPendingReview(): array;
}
