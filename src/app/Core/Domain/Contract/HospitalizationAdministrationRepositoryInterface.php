<?php

declare(strict_types=1);

namespace CentralVet\Domain\Contract;

use CentralVet\Persistence\TenantRepositoryInterface;
use DateTimeImmutable;

/**
 * Persistence boundary for the HospitalizationAdministration aggregate
 * (`hospitalization_administration`, migration 0010).
 *
 * @template TEntity of object
 * @extends TenantRepositoryInterface<TEntity>
 */
interface HospitalizationAdministrationRepositoryInterface extends TenantRepositoryInterface
{
    /**
     * Lists every administration of a hospitalization within the current
     * tenant, ordered by `scheduled_at`.
     *
     * @return list<TEntity>
     */
    public function listByHospitalization(int $hospitalizationId): array;

    /**
     * Lists the `pending` administrations of an order within the current tenant.
     *
     * @return list<TEntity>
     */
    public function listPendingByOrder(int $orderId): array;

    /**
     * Lists the `pending` administrations of a hospitalization within the
     * current tenant.
     *
     * @return list<TEntity>
     */
    public function listPendingByHospitalization(int $hospitalizationId): array;

    /**
     * Flowboard rows of a unit's shift, within the current tenant. Only
     * `admitted` hospitalizations of `$systemUnitId` count: `pending` rows
     * with `scheduled_at <= $to` (late ones before `$from` included) and
     * `done`/`skipped` rows with `scheduled_at` between `$from` and `$to`,
     * ordered by `scheduled_at`. Dates as `Y-m-d H:i:s`.
     *
     * @return list<array{administration_id: int, hospitalization_id: int, patient_name: string, bed_code: string, order_type: string, description_text: string, dose_text: string, route: string, scheduled_at: string, status: string, performed_at: ?string}>
     */
    public function listBoardRows(int $systemUnitId, DateTimeImmutable $from, DateTimeImmutable $to): array;
}
