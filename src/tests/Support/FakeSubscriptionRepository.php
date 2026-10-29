<?php

declare(strict_types=1);

namespace CentralVet\Tests\Support;

use CentralVet\Billing\Contract\SubscriptionRepositoryInterface;
use CentralVet\Billing\Subscription;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Em memória, com o contrato de SubscriptionRepository (T-10): linhas
 * guardadas como toRow() e reidratadas por fromRow() (sem aliasing), chaves
 * únicas de billing_order_id, tenant_id e stripe_subscription_id, e
 * listPixDueForIssue lendo as faturas do FakeSubscriptionInvoiceRepository
 * passado (sem ele, nenhuma fatura conta). Os *ForUpdate exigem "transação aberta":
 * $inTransaction começa true e, em false, eles lançam LogicException como o
 * repositório real; cada chamada fica em $lockCalls.
 */
final class FakeSubscriptionRepository implements SubscriptionRepositoryInterface
{
    /** @var array<int, array<string, int|string|null>> */
    public array $rows = [];

    public bool $inTransaction = true;

    /** @var list<string> */
    public array $lockCalls = [];

    private int $nextId = 1;

    public function __construct(private readonly ?FakeSubscriptionInvoiceRepository $invoices = null)
    {
    }

    public function insert(Subscription $subscription): int
    {
        if ($subscription->id() !== null) {
            throw new InvalidArgumentException('Subscription already persisted; use save()');
        }

        $row = $subscription->toRow();
        $this->assertUnique($row, null);

        $id = $this->nextId++;
        $row['id'] = $id;
        $this->rows[$id] = $row;

        return $id;
    }

    public function save(Subscription $subscription): void
    {
        $id = $subscription->id();
        if ($id === null || !isset($this->rows[$id])) {
            throw new InvalidArgumentException('Subscription not persisted yet; use insert()');
        }

        $row = $subscription->toRow();
        $this->assertUnique($row, $id);
        $this->rows[$id] = $row;
    }

    public function findByTenantId(int $tenantId): ?Subscription
    {
        return $this->findBy('tenant_id', $tenantId);
    }

    public function findByBillingOrderId(int $billingOrderId): ?Subscription
    {
        return $this->findBy('billing_order_id', $billingOrderId);
    }

    public function findByStripeSubscriptionIdForUpdate(string $stripeSubscriptionId): ?Subscription
    {
        $this->assertTransaction('findByStripeSubscriptionIdForUpdate');
        $this->lockCalls[] = 'findByStripeSubscriptionIdForUpdate';

        return $this->findBy('stripe_subscription_id', $stripeSubscriptionId);
    }

    public function findByIdForUpdate(int $id): ?Subscription
    {
        $this->assertTransaction('findByIdForUpdate');
        $this->lockCalls[] = 'findByIdForUpdate';

        return isset($this->rows[$id]) ? Subscription::fromRow($this->rows[$id]) : null;
    }

    public function listGraceExpired(DateTimeImmutable $now, int $limit): array
    {
        $ids = [];
        foreach ($this->sorted() as $row) {
            $subscription = Subscription::fromRow($row);
            if (
                $subscription->status() === Subscription::STATUS_PAST_DUE
                && $subscription->graceUntil() !== null
                && $subscription->graceUntil() <= $now
            ) {
                $ids[] = (int) $row['id'];
            }
        }

        return array_slice($ids, 0, self::limit($limit));
    }

    public function listPixDueForIssue(DateTimeImmutable $until, int $limit): array
    {
        $due = [];
        foreach ($this->sorted() as $row) {
            $subscription = Subscription::fromRow($row);
            $end = $subscription->currentPeriodEnd();
            if (
                $subscription->paymentMethod() !== Subscription::METHOD_PIX
                || !in_array($subscription->status(), [Subscription::STATUS_ACTIVE, Subscription::STATUS_PAST_DUE], true)
                || $subscription->cancelAtPeriodEnd()
                || $end === null
                || $end > $until
            ) {
                continue;
            }

            foreach ($this->invoices?->listBySubscription((int) $row['id'], PHP_INT_MAX) ?? [] as $invoice) {
                if ($invoice->periodStart() !== null && $invoice->periodStart() >= $end) {
                    continue 2;
                }
            }

            $due[] = ['id' => (int) $row['id'], 'end' => $end];
        }

        usort($due, static fn (array $a, array $b): int => [$a['end'], $a['id']] <=> [$b['end'], $b['id']]);

        return array_slice(array_column($due, 'id'), 0, self::limit($limit));
    }

    public function listScheduledCancellationsDue(DateTimeImmutable $now, int $limit): array
    {
        $ids = [];
        foreach ($this->sorted() as $row) {
            $subscription = Subscription::fromRow($row);
            $end = $subscription->currentPeriodEnd();
            if (
                $subscription->paymentMethod() === Subscription::METHOD_PIX
                && $subscription->cancelAtPeriodEnd()
                && $subscription->status() !== Subscription::STATUS_CANCELLED
                && $end !== null
                && $end <= $now
            ) {
                $ids[] = (int) $row['id'];
            }
        }

        return array_slice($ids, 0, self::limit($limit));
    }

    private function findBy(string $column, int|string $value): ?Subscription
    {
        foreach ($this->sorted() as $row) {
            if ($row[$column] !== null && (string) $row[$column] === (string) $value) {
                return Subscription::fromRow($row);
            }
        }

        return null;
    }

    /** @return list<array<string, int|string|null>> */
    private function sorted(): array
    {
        $rows = $this->rows;
        ksort($rows);

        return array_values($rows);
    }

    /** @param array<string, int|string|null> $row */
    private function assertUnique(array $row, ?int $ownId): void
    {
        foreach (['billing_order_id', 'tenant_id', 'stripe_subscription_id'] as $column) {
            if ($row[$column] === null) {
                continue;
            }

            foreach ($this->rows as $id => $existing) {
                if ($id !== $ownId && $existing[$column] !== null && (string) $existing[$column] === (string) $row[$column]) {
                    throw new \RuntimeException(sprintf('Duplicate subscription %s "%s"', $column, $row[$column]));
                }
            }
        }
    }

    private function assertTransaction(string $method): void
    {
        if (!$this->inTransaction) {
            throw new \LogicException($method . ' requires an open transaction');
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
