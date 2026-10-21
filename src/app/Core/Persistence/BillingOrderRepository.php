<?php

declare(strict_types=1);

namespace CentralVet\Persistence;

use CentralVet\Billing\BillingOrder;
use CentralVet\Billing\Contract\BillingOrderRepositoryInterface;
use LogicException;
use PDO;
use RuntimeException;

/**
 * PDO-backed `billing_order` (migration 0017). No TenantContext: the order
 * is placed on the landing before any tenant exists; `tenant_id` is only
 * filled by provisioning. Columns come from BillingOrder::toRow(), so the
 * mapping lives in one place; created_at/updated_at are left to MySQL.
 */
final class BillingOrderRepository implements BillingOrderRepositoryInterface
{
    private const MAX_PROVISIONING_ATTEMPTS = 10;

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function insert(BillingOrder $order): int
    {
        if ($order->id() !== null) {
            throw new LogicException('Billing order already has an id; use save()');
        }

        $row = $order->toRow();
        unset($row['id']);

        $columns = array_keys($row);
        $statement = $this->pdo->prepare(sprintf(
            'INSERT INTO billing_order (%s) VALUES (%s)',
            implode(', ', $columns),
            implode(', ', array_map(static fn (string $column): string => ':' . $column, $columns)),
        ));
        $statement->execute($row);

        return (int) $this->pdo->lastInsertId();
    }

    public function save(BillingOrder $order): void
    {
        $id = $order->id();
        if ($id === null) {
            throw new LogicException('Billing order has no id; use insert()');
        }

        $row = $order->toRow();
        unset($row['id'], $row['public_id']);

        $assignments = implode(', ', array_map(
            static fn (string $column): string => $column . ' = :' . $column,
            array_keys($row),
        ));
        $statement = $this->pdo->prepare("UPDATE billing_order SET {$assignments} WHERE id = :id");
        $statement->execute([...$row, 'id' => $id]);

        if ($statement->rowCount() === 0 && !$this->exists($id)) {
            throw new RuntimeException(sprintf('Billing order %d not found', $id));
        }
    }

    public function findByPublicId(string $publicId): ?BillingOrder
    {
        return $this->findOne('public_id = :value', $publicId, false);
    }

    public function findByIdForUpdate(int $id): ?BillingOrder
    {
        $this->assertTransaction('findByIdForUpdate');

        return $this->findOne('id = :value', $id, true);
    }

    public function findByCheckoutSessionIdForUpdate(string $checkoutSessionId): ?BillingOrder
    {
        $this->assertTransaction('findByCheckoutSessionIdForUpdate');

        return $this->findOne('stripe_checkout_session_id = :value', $checkoutSessionId, true);
    }

    public function listPendingFulfillment(int $limit): array
    {
        if ($limit <= 0) {
            return [];
        }

        $statement = $this->pdo->prepare(
            'SELECT id FROM billing_order '
            . "WHERE status = 'paid' "
            . "OR (status = 'provisioning_failed' AND provisioning_attempts < :max_attempts) "
            . "OR (status = 'provisioned' AND invite_sent_at IS NULL) "
            . 'ORDER BY id LIMIT :limit'
        );
        $statement->bindValue('max_attempts', self::MAX_PROVISIONING_ATTEMPTS, PDO::PARAM_INT);
        $statement->bindValue('limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        return array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    private function findOne(string $where, int|string $value, bool $forUpdate): ?BillingOrder
    {
        $statement = $this->pdo->prepare(
            "SELECT * FROM billing_order WHERE {$where} LIMIT 1" . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $statement->execute(['value' => $value]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : BillingOrder::fromRow($row);
    }

    private function exists(int $id): bool
    {
        $statement = $this->pdo->prepare('SELECT 1 FROM billing_order WHERE id = :id');
        $statement->execute(['id' => $id]);

        return $statement->fetchColumn() !== false;
    }

    private function assertTransaction(string $method): void
    {
        if (!$this->pdo->inTransaction()) {
            throw new LogicException($method . ' requires an open transaction');
        }
    }
}
