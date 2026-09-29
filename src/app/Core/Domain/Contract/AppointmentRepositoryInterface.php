<?php

declare(strict_types=1);

namespace CentralVet\Domain\Contract;

use CentralVet\Persistence\TenantRepositoryInterface;
use DateTimeImmutable;

/**
 * Persistence boundary for the Appointment aggregate.
 *
 * @template TEntity of object
 * @extends TenantRepositoryInterface<TEntity>
 */
interface AppointmentRepositoryInterface extends TenantRepositoryInterface
{
    /**
     * Lists appointments for a professional on a given date within the current tenant.
     *
     * @return list<TEntity>
     */
    public function listByProfessionalAndDate(int $professionalSystemUserId, DateTimeImmutable $date): array;

    /**
     * Lists appointments for a unit on a given date within the current tenant.
     *
     * @return list<TEntity>
     */
    public function listByUnitAndDate(int $systemUnitId, DateTimeImmutable $date): array;
}
