<?php

declare(strict_types=1);

namespace CentralVet\Billing\Contract;

use CentralVet\Billing\BillingOrder;

/**
 * Persistence of `billing_order` (migration 0017). Not tenant scoped: the
 * order exists before the tenant it provisions.
 */
interface BillingOrderRepositoryInterface
{
    /** Inserts an order that has no id yet and returns the new id. */
    public function insert(BillingOrder $order): int;

    /** Writes every column of an order that already has an id. */
    public function save(BillingOrder $order): void;

    public function findByPublicId(string $publicId): ?BillingOrder;

    /** @throws \LogicException outside an open transaction */
    public function findByIdForUpdate(int $id): ?BillingOrder;

    /** @throws \LogicException outside an open transaction */
    public function findByCheckoutSessionIdForUpdate(string $checkoutSessionId): ?BillingOrder;

    /**
     * Ids of orders that still need provisioning or the invite: `paid`,
     * `provisioning_failed` with fewer than 10 attempts (except
     * `login_conflict`, which only a refund resolves), or `provisioned`
     * with `invite_sent_at` NULL and fewer than 10 attempts (each failed
     * invite counts one). Ordered by `provisioning_attempts`, then id, so
     * items that keep failing never crowd out new ones.
     *
     * @return list<int>
     */
    public function listPendingFulfillment(int $limit): array;

    /**
     * Ids of paid orders whose responsible e-mail is already a login
     * (`provisioning_failed` with `provisioning_error = 'login_conflict'`),
     * ordered by id, with no attempts cap. `$limit <= 0` returns [].
     *
     * @return list<int>
     */
    public function listLoginConflictsToRefund(int $limit): array;
}
