<?php

declare(strict_types=1);

namespace CentralVet\Persistence;

use CentralVet\Billing\Contract\SubscriptionInvoiceRepositoryInterface;
use CentralVet\Billing\DuplicateInvoice;
use CentralVet\Billing\Subscription;
use CentralVet\Billing\SubscriptionInvoice;
use DateTimeImmutable;
use InvalidArgumentException;
use LogicException;
use PDO;
use PDOException;

/**
 * PDO de `subscription_invoice` (migration 0017, T-02). Chave única repetida
 * no insert (1062) vira DuplicateInvoice; a transação do chamador continua
 * utilizável.
 */
final class SubscriptionInvoiceRepository implements SubscriptionInvoiceRepositoryInterface
{
    private const DATE_FORMAT = 'Y-m-d H:i:s.u';
    private const DUPLICATE_KEY = 1062;

    /** Colunas gravadas por save(); subscription_id, stripe_invoice_id, local_key e due_at não mudam. */
    private const MUTABLE = [
        'stripe_payment_intent_id', 'pix_expires_at', 'payment_intent_attempts', 'status',
        'amount_due_cents', 'amount_paid_cents', 'amount_refunded_cents', 'currency',
        'period_start', 'period_end', 'hosted_invoice_url', 'last_event_created',
    ];

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function findByStripeInvoiceIdForUpdate(string $stripeInvoiceId): ?SubscriptionInvoice
    {
        $this->assertTransaction('findByStripeInvoiceIdForUpdate');

        return $this->fetchOne(
            'SELECT * FROM subscription_invoice WHERE stripe_invoice_id = :v LIMIT 1 FOR UPDATE',
            $stripeInvoiceId,
        );
    }

    public function insert(SubscriptionInvoice $invoice): int
    {
        if ($invoice->id() !== null) {
            throw new InvalidArgumentException('Invoice already persisted; use save()');
        }

        $row = $invoice->toRow();
        unset($row['id']);

        $columns = array_keys($row);
        $statement = $this->pdo->prepare(sprintf(
            'INSERT INTO subscription_invoice (%s) VALUES (%s)',
            implode(', ', $columns),
            implode(', ', array_map(static fn (string $c): string => ':' . $c, $columns)),
        ));

        try {
            $statement->execute($row);
        } catch (PDOException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) === self::DUPLICATE_KEY) {
                throw DuplicateInvoice::forInvoice($invoice, $e);
            }

            throw $e;
        }

        return (int) $this->pdo->lastInsertId();
    }

    public function save(SubscriptionInvoice $invoice): void
    {
        $id = $invoice->id();
        if ($id === null) {
            throw new InvalidArgumentException('Invoice not persisted yet; use insert()');
        }

        $row = $invoice->toRow();
        $params = ['id' => $id];
        $sets = [];
        foreach (self::MUTABLE as $column) {
            $sets[] = "{$column} = :{$column}";
            $params[$column] = $row[$column];
        }

        $this->pdo->prepare('UPDATE subscription_invoice SET ' . implode(', ', $sets) . ' WHERE id = :id')
            ->execute($params);
    }

    public function listBySubscription(int $subscriptionId, int $limit): array
    {
        $statement = $this->pdo->prepare(
            'SELECT * FROM subscription_invoice WHERE subscription_id = :v '
            . 'ORDER BY period_start DESC, id DESC LIMIT ' . self::limit($limit),
        );
        $statement->execute(['v' => $subscriptionId]);

        return array_map(
            static fn (array $row): SubscriptionInvoice => SubscriptionInvoice::fromRow($row),
            $statement->fetchAll(PDO::FETCH_ASSOC),
        );
    }

    public function findByIdForUpdate(int $id): ?SubscriptionInvoice
    {
        $this->assertTransaction('findByIdForUpdate');

        return $this->fetchOne('SELECT * FROM subscription_invoice WHERE id = :v LIMIT 1 FOR UPDATE', $id);
    }

    public function findByPaymentIntentIdForUpdate(string $paymentIntentId): ?SubscriptionInvoice
    {
        $this->assertTransaction('findByPaymentIntentIdForUpdate');

        return $this->fetchOne(
            'SELECT * FROM subscription_invoice WHERE stripe_payment_intent_id = :v LIMIT 1 FOR UPDATE',
            $paymentIntentId,
        );
    }

    public function findByLocalKey(string $localKey): ?SubscriptionInvoice
    {
        return $this->fetchOne('SELECT * FROM subscription_invoice WHERE local_key = :v LIMIT 1', $localKey);
    }

    public function listOpenPix(DateTimeImmutable $now, int $limit): array
    {
        $statement = $this->pdo->prepare(
            'SELECT i.id FROM subscription_invoice i JOIN subscription s ON s.id = i.subscription_id '
            . 'WHERE i.status = :status AND i.local_key IS NOT NULL AND s.status IN (:active, :past_due) '
            . 'AND (i.due_at <= :now_due OR i.pix_expires_at <= :now_pix OR i.stripe_payment_intent_id IS NULL) '
            . 'ORDER BY i.id ASC LIMIT ' . self::limit($limit),
        );
        $formatted = $now->format(self::DATE_FORMAT);
        $statement->execute([
            'status' => SubscriptionInvoice::STATUS_OPEN,
            'active' => Subscription::STATUS_ACTIVE,
            'past_due' => Subscription::STATUS_PAST_DUE,
            'now_due' => $formatted,
            'now_pix' => $formatted,
        ]);

        return array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    private function fetchOne(string $sql, int|string $value): ?SubscriptionInvoice
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute(['v' => $value]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : SubscriptionInvoice::fromRow($row);
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
}
