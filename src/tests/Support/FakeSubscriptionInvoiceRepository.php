<?php

declare(strict_types=1);

namespace CentralVet\Tests\Support;

use CentralVet\Billing\Contract\SubscriptionInvoiceRepositoryInterface;
use CentralVet\Billing\DuplicateInvoice;
use CentralVet\Billing\SubscriptionInvoice;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Em memória, com o contrato de SubscriptionInvoiceRepository (T-10):
 * linhas guardadas como toRow() e reidratadas por fromRow(); local_key,
 * stripe_invoice_id ou stripe_payment_intent_id repetido no insert lança
 * DuplicateInvoice. Os *ForUpdate exigem "transação aberta" ($inTransaction começa true;
 * em false lançam LogicException como o real) e ficam em $lockCalls.
 */
final class FakeSubscriptionInvoiceRepository implements SubscriptionInvoiceRepositoryInterface
{
    private const UNIQUE = ['stripe_invoice_id', 'local_key', 'stripe_payment_intent_id'];

    /** @var array<int, array<string, int|string|null>> */
    public array $rows = [];

    public bool $inTransaction = true;

    /**
     * Opcional: com ele, listOpenPix ignora faturas de assinatura fora de
     * active/past_due, como o JOIN do repositório real.
     */
    public ?FakeSubscriptionRepository $subscriptions = null;

    /** @var list<string> */
    public array $lockCalls = [];

    private int $nextId = 1;

    public function findByStripeInvoiceIdForUpdate(string $stripeInvoiceId): ?SubscriptionInvoice
    {
        $this->assertTransaction('findByStripeInvoiceIdForUpdate');
        $this->lockCalls[] = 'findByStripeInvoiceIdForUpdate';

        return $this->findBy('stripe_invoice_id', $stripeInvoiceId);
    }

    public function insert(SubscriptionInvoice $invoice): int
    {
        if ($invoice->id() !== null) {
            throw new InvalidArgumentException('Invoice already persisted; use save()');
        }

        $row = $invoice->toRow();
        if ($this->collides($row, null)) {
            throw DuplicateInvoice::forInvoice($invoice);
        }

        $id = $this->nextId++;
        $row['id'] = $id;
        $this->rows[$id] = $row;

        return $id;
    }

    public function save(SubscriptionInvoice $invoice): void
    {
        $id = $invoice->id();
        if ($id === null || !isset($this->rows[$id])) {
            throw new InvalidArgumentException('Invoice not persisted yet; use insert()');
        }

        $row = $invoice->toRow();
        if ($this->collides($row, $id)) {
            throw new \RuntimeException('Duplicate subscription invoice key');
        }

        $this->rows[$id] = $row;
    }

    public function listBySubscription(int $subscriptionId, int $limit): array
    {
        $rows = array_values(array_filter(
            $this->rows,
            static fn (array $row): bool => (int) $row['subscription_id'] === $subscriptionId,
        ));

        // period_start DESC (nulos por último, como no MySQL), depois id DESC.
        usort($rows, static fn (array $a, array $b): int => [(string) $b['period_start'], $b['id']] <=> [(string) $a['period_start'], $a['id']]);

        return array_map(
            static fn (array $row): SubscriptionInvoice => SubscriptionInvoice::fromRow($row),
            array_slice($rows, 0, self::limit($limit)),
        );
    }

    public function findByIdForUpdate(int $id): ?SubscriptionInvoice
    {
        $this->assertTransaction('findByIdForUpdate');
        $this->lockCalls[] = 'findByIdForUpdate';

        return isset($this->rows[$id]) ? SubscriptionInvoice::fromRow($this->rows[$id]) : null;
    }

    public function findByPaymentIntentIdForUpdate(string $paymentIntentId): ?SubscriptionInvoice
    {
        $this->assertTransaction('findByPaymentIntentIdForUpdate');
        $this->lockCalls[] = 'findByPaymentIntentIdForUpdate';

        return $this->findBy('stripe_payment_intent_id', $paymentIntentId);
    }

    public function findByLocalKey(string $localKey): ?SubscriptionInvoice
    {
        return $this->findBy('local_key', $localKey);
    }

    public function listOpenPix(DateTimeImmutable $now, int $limit): array
    {
        $rows = $this->rows;
        ksort($rows);

        $ids = [];
        foreach ($rows as $id => $row) {
            $invoice = SubscriptionInvoice::fromRow($row);
            if ($invoice->status() !== SubscriptionInvoice::STATUS_OPEN || $invoice->localKey() === null) {
                continue;
            }

            $subscriptionStatus = $this->subscriptions?->rows[$invoice->subscriptionId()]['status'] ?? null;
            if ($subscriptionStatus !== null && !in_array($subscriptionStatus, ['active', 'past_due'], true)) {
                continue;
            }

            if (
                ($invoice->dueAt() !== null && $invoice->dueAt() <= $now)
                || ($invoice->pixExpiresAt() !== null && $invoice->pixExpiresAt() <= $now)
                || $invoice->stripePaymentIntentId() === null
            ) {
                $ids[] = $id;
            }
        }

        return array_slice($ids, 0, self::limit($limit));
    }

    /**
     * Como o real (T-22); exige $subscriptions para ler o cancelamento e o
     * período da assinatura (sem ele, devolve []).
     */
    public function listPaidPixAfterCancellation(int $limit): array
    {
        $rows = $this->rows;
        ksort($rows);

        $ids = [];
        foreach ($rows as $id => $row) {
            $subscription = $this->subscriptions?->rows[(int) $row['subscription_id']] ?? null;
            if ($subscription === null) {
                continue;
            }

            $cancelled = (int) ($subscription['cancel_at_period_end'] ?? 0) === 1 || $subscription['status'] === 'cancelled';
            if (
                $row['local_key'] !== null
                && $row['status'] === SubscriptionInvoice::STATUS_PAID
                && (int) $row['amount_refunded_cents'] === 0
                && $row['stripe_payment_intent_id'] !== null
                && $cancelled
                && $row['period_start'] !== null
                && $subscription['current_period_end'] !== null
                && (string) $row['period_start'] >= (string) $subscription['current_period_end']
            ) {
                $ids[] = $id;
            }
        }

        return array_slice($ids, 0, self::limit($limit));
    }

    private function findBy(string $column, string $value): ?SubscriptionInvoice
    {
        foreach ($this->rows as $row) {
            if ($row[$column] !== null && (string) $row[$column] === $value) {
                return SubscriptionInvoice::fromRow($row);
            }
        }

        return null;
    }

    /** @param array<string, int|string|null> $row */
    private function collides(array $row, ?int $ownId): bool
    {
        foreach (self::UNIQUE as $column) {
            if ($row[$column] === null) {
                continue;
            }

            foreach ($this->rows as $id => $existing) {
                if ($id !== $ownId && $existing[$column] !== null && (string) $existing[$column] === (string) $row[$column]) {
                    return true;
                }
            }
        }

        return false;
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
