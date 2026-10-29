<?php

declare(strict_types=1);

namespace CentralVet\Tests\Support;

use CentralVet\Billing\BillingOrder;
use CentralVet\Billing\Contract\BillingOrderRepositoryInterface;
use CentralVet\Billing\ProvisioningConflict;
use LogicException;
use RuntimeException;

/**
 * In-memory double for BillingOrderRepositoryInterface (T-09). Orders are
 * stored as rows (toRow()) and rebuilt with fromRow(), so callers never
 * share an instance with the store, like the PDO repository. The
 * *ForUpdate reads demand an "open transaction": $inTransaction starts true
 * so services can be tested directly; set it to false to exercise the
 * LogicException path.
 */
final class FakeBillingOrderRepository implements BillingOrderRepositoryInterface
{
    private const MAX_PROVISIONING_ATTEMPTS = 10;

    public bool $inTransaction = true;

    /** @var array<int, array<string, int|string|null>> */
    private array $rows = [];

    private int $nextId = 1;

    public function insert(BillingOrder $order): int
    {
        if ($order->id() !== null) {
            throw new LogicException('Billing order already has an id; use save()');
        }

        $row = $order->toRow();
        foreach ($this->rows as $stored) {
            if ($stored['public_id'] === $row['public_id']) {
                throw new RuntimeException('Duplicate billing_order.public_id');
            }
            if ($row['stripe_checkout_session_id'] !== null && $stored['stripe_checkout_session_id'] === $row['stripe_checkout_session_id']) {
                throw new RuntimeException('Duplicate billing_order.stripe_checkout_session_id');
            }
        }

        $id = $this->nextId++;
        $this->rows[$id] = ['id' => $id] + $row;

        return $id;
    }

    public function save(BillingOrder $order): void
    {
        $id = $order->id();
        if ($id === null) {
            throw new LogicException('Billing order has no id; use insert()');
        }
        if (!isset($this->rows[$id])) {
            throw new RuntimeException(sprintf('Billing order %d not found', $id));
        }

        $row = $order->toRow();
        $row['public_id'] = $this->rows[$id]['public_id'];
        $this->rows[$id] = $row;
    }

    public function findByPublicId(string $publicId): ?BillingOrder
    {
        return $this->findBy('public_id', $publicId);
    }

    public function findByIdForUpdate(int $id): ?BillingOrder
    {
        $this->assertTransaction('findByIdForUpdate');

        return isset($this->rows[$id]) ? BillingOrder::fromRow($this->rows[$id]) : null;
    }

    public function findByCheckoutSessionIdForUpdate(string $checkoutSessionId): ?BillingOrder
    {
        $this->assertTransaction('findByCheckoutSessionIdForUpdate');

        return $this->findBy('stripe_checkout_session_id', $checkoutSessionId);
    }

    public function listPendingFulfillment(int $limit): array
    {
        $ids = [];
        ksort($this->rows);
        $rows = $this->rows;
        // provisioning_attempts ascending, then id (uasort is stable).
        uasort($rows, static fn (array $a, array $b): int => (int) $a['provisioning_attempts'] <=> (int) $b['provisioning_attempts']);

        foreach ($rows as $id => $row) {
            if (count($ids) >= $limit) {
                break;
            }

            $pending = $row['status'] === BillingOrder::STATUS_PAID
                || ($row['status'] === BillingOrder::STATUS_PROVISIONING_FAILED && (int) $row['provisioning_attempts'] < self::MAX_PROVISIONING_ATTEMPTS
                    && $row['provisioning_error'] !== ProvisioningConflict::LOGIN_CONFLICT)
                || ($row['status'] === BillingOrder::STATUS_PROVISIONED && $row['invite_sent_at'] === null && (int) $row['provisioning_attempts'] < self::MAX_PROVISIONING_ATTEMPTS);

            if ($pending) {
                $ids[] = (int) $id;
            }
        }

        return $ids;
    }

    public function listLoginConflictsToRefund(int $limit): array
    {
        if ($limit <= 0) {
            return [];
        }

        ksort($this->rows);
        $ids = [];
        foreach ($this->rows as $id => $row) {
            if ($row['status'] === BillingOrder::STATUS_PROVISIONING_FAILED
                && $row['provisioning_error'] === ProvisioningConflict::LOGIN_CONFLICT) {
                $ids[] = (int) $id;
                if (count($ids) >= $limit) {
                    break;
                }
            }
        }

        return $ids;
    }

    private function findBy(string $column, string $value): ?BillingOrder
    {
        foreach ($this->rows as $row) {
            if ($row[$column] === $value) {
                return BillingOrder::fromRow($row);
            }
        }

        return null;
    }

    private function assertTransaction(string $method): void
    {
        if (!$this->inTransaction) {
            throw new LogicException($method . ' requires an open transaction');
        }
    }
}
