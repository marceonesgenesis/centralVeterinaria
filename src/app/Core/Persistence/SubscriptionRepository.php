<?php

declare(strict_types=1);

namespace CentralVet\Persistence;

use CentralVet\Billing\Contract\SubscriptionRepositoryInterface;
use CentralVet\Billing\Subscription;
use DateTimeImmutable;
use InvalidArgumentException;
use LogicException;
use PDO;

/**
 * PDO de `subscription` (migration 0017, T-02). Tabela da plataforma (a
 * assinatura nasce antes do tenant), por isso não estende
 * AbstractTenantRepository: o isolamento vem do filtro por chave exata —
 * findByTenantId nunca devolve a assinatura de outro tenant.
 */
final class SubscriptionRepository implements SubscriptionRepositoryInterface
{
    private const DATE_FORMAT = 'Y-m-d H:i:s.u';

    /** Colunas gravadas por save(); billing_order_id, plano, forma e ids do Stripe não mudam. */
    private const MUTABLE = [
        'tenant_id', 'status', 'current_period_start', 'current_period_end',
        'grace_until', 'cancelled_at', 'last_event_created',
    ];

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function insert(Subscription $subscription): int
    {
        if ($subscription->id() !== null) {
            throw new InvalidArgumentException('Subscription already persisted; use save()');
        }

        $row = $subscription->toRow();
        unset($row['id']);

        $columns = array_keys($row);
        $statement = $this->pdo->prepare(sprintf(
            'INSERT INTO subscription (%s) VALUES (%s)',
            implode(', ', $columns),
            implode(', ', array_map(static fn (string $c): string => ':' . $c, $columns)),
        ));
        $statement->execute($row);

        return (int) $this->pdo->lastInsertId();
    }

    public function save(Subscription $subscription): void
    {
        $id = $subscription->id();
        if ($id === null) {
            throw new InvalidArgumentException('Subscription not persisted yet; use insert()');
        }

        $row = $subscription->toRow();
        $params = ['id' => $id];
        $sets = [];
        foreach (self::MUTABLE as $column) {
            $sets[] = "{$column} = :{$column}";
            $params[$column] = $row[$column];
        }

        $this->pdo->prepare('UPDATE subscription SET ' . implode(', ', $sets) . ' WHERE id = :id')
            ->execute($params);
    }

    public function findByTenantId(int $tenantId): ?Subscription
    {
        return $this->fetchOne('SELECT * FROM subscription WHERE tenant_id = :v LIMIT 1', $tenantId);
    }

    public function findByBillingOrderId(int $billingOrderId): ?Subscription
    {
        return $this->fetchOne('SELECT * FROM subscription WHERE billing_order_id = :v LIMIT 1', $billingOrderId);
    }

    public function findByStripeSubscriptionIdForUpdate(string $stripeSubscriptionId): ?Subscription
    {
        $this->assertTransaction('findByStripeSubscriptionIdForUpdate');

        return $this->fetchOne(
            'SELECT * FROM subscription WHERE stripe_subscription_id = :v LIMIT 1 FOR UPDATE',
            $stripeSubscriptionId,
        );
    }

    public function findByIdForUpdate(int $id): ?Subscription
    {
        $this->assertTransaction('findByIdForUpdate');

        return $this->fetchOne('SELECT * FROM subscription WHERE id = :v LIMIT 1 FOR UPDATE', $id);
    }

    public function listGraceExpired(DateTimeImmutable $now, int $limit): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id FROM subscription WHERE status = :status AND grace_until IS NOT NULL '
            . 'AND grace_until <= :now ORDER BY id ASC LIMIT ' . self::limit($limit),
        );
        $statement->execute(['status' => Subscription::STATUS_PAST_DUE, 'now' => $now->format(self::DATE_FORMAT)]);

        return self::ids($statement);
    }

    public function listPixDueForIssue(DateTimeImmutable $until, int $limit): array
    {
        $statement = $this->pdo->prepare(
            'SELECT s.id FROM subscription s '
            . 'WHERE s.payment_method = :method AND s.status IN (:active, :past_due) '
            . 'AND s.current_period_end IS NOT NULL AND s.current_period_end <= :until '
            . 'AND NOT EXISTS (SELECT 1 FROM subscription_invoice i '
            . 'WHERE i.subscription_id = s.id AND i.period_start >= s.current_period_end) '
            . 'ORDER BY s.current_period_end ASC, s.id ASC LIMIT ' . self::limit($limit),
        );
        $statement->execute([
            'method' => Subscription::METHOD_PIX,
            'active' => Subscription::STATUS_ACTIVE,
            'past_due' => Subscription::STATUS_PAST_DUE,
            'until' => $until->format(self::DATE_FORMAT),
        ]);

        return self::ids($statement);
    }

    private function fetchOne(string $sql, int|string $value): ?Subscription
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute(['v' => $value]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : Subscription::fromRow($row);
    }

    private function assertTransaction(string $method): void
    {
        if (!$this->pdo->inTransaction()) {
            throw new LogicException($method . ' requires an open transaction');
        }
    }

    private static function limit(int $limit): int
    {
        if ($limit <= 0) {
            throw new InvalidArgumentException('Limit must be positive');
        }

        return $limit;
    }

    /** @return list<int> */
    private static function ids(\PDOStatement $statement): array
    {
        return array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }
}
