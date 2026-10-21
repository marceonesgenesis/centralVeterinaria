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
     * Ids, by id, of orders that still need provisioning or the invite:
     * `paid`, `provisioning_failed` with fewer than 10 attempts, or
     * `provisioned` with `invite_sent_at` NULL.
     *
     * @return list<int>
     */
    public function listPendingFulfillment(int $limit): array;
}
