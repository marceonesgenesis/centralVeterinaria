<?php

declare(strict_types=1);

namespace CentralVet\Billing;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Fatura de um período da assinatura (tabela `subscription_invoice`, T-02).
 *
 * Cartão: espelha a Invoice do Stripe (fromStripe/applyStripe), com
 * `stripe_invoice_id`. Pix: fatura local (issuePix) com `local_key`
 * `pix:<subscriptionId>:<periodStart Y-m-d>` e um PaymentIntent Pix por
 * tentativa (attachPaymentIntent); `hosted_invoice_url` guarda o link de
 * pagamento (fatura Stripe no cartão, `hosted_instructions_url` no Pix).
 *
 * Guarda contra evento antigo: métodos com `$eventCreated` devolvem false e
 * não mudam nada quando ele é menor que lastEventCreated().
 */
final class SubscriptionInvoice
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_OPEN = 'open';
    public const STATUS_PAID = 'paid';
    public const STATUS_UNCOLLECTIBLE = 'uncollectible';
    public const STATUS_VOID = 'void';
    public const STATUS_REFUNDED = 'refunded';

    private const STATUSES = [
        self::STATUS_DRAFT, self::STATUS_OPEN, self::STATUS_PAID,
        self::STATUS_UNCOLLECTIBLE, self::STATUS_VOID, self::STATUS_REFUNDED,
    ];

    /** Status que a Invoice do Stripe pode ter (o `refunded` é só local). */
    private const STRIPE_STATUSES = [
        self::STATUS_DRAFT, self::STATUS_OPEN, self::STATUS_PAID, self::STATUS_UNCOLLECTIBLE, self::STATUS_VOID,
    ];

    private const DATE_FORMAT = 'Y-m-d H:i:s.u';

    private function __construct(
        private ?int $id,
        private readonly int $subscriptionId,
        private readonly ?string $stripeInvoiceId,
        private readonly ?string $localKey,
        private ?string $stripePaymentIntentId,
        private readonly ?DateTimeImmutable $dueAt,
        private ?DateTimeImmutable $pixExpiresAt,
        private int $paymentIntentAttempts,
        private string $status,
        private int $amountDueCents,
        private int $amountPaidCents,
        private int $amountRefundedCents,
        private string $currency,
        private ?DateTimeImmutable $periodStart,
        private ?DateTimeImmutable $periodEnd,
        private ?string $hostedInvoiceUrl,
        private int $lastEventCreated,
    ) {
    }

    /** @param array<string, mixed> $invoice objeto Invoice do Stripe */
    public static function fromStripe(int $subscriptionId, array $invoice, int $eventCreated): self
    {
        self::assertSubscriptionId($subscriptionId);

        $stripeInvoiceId = (string) ($invoice['id'] ?? '');
        if ($stripeInvoiceId === '') {
            throw new InvalidArgumentException('Stripe invoice id is required');
        }

        $period = $invoice['lines']['data'][0]['period'] ?? [];

        return new self(
            id: null,
            subscriptionId: $subscriptionId,
            stripeInvoiceId: $stripeInvoiceId,
            localKey: null,
            stripePaymentIntentId: null,
            dueAt: null,
            pixExpiresAt: null,
            paymentIntentAttempts: 0,
            status: self::stripeStatus($invoice),
            amountDueCents: max(0, (int) ($invoice['amount_due'] ?? 0)),
            amountPaidCents: max(0, (int) ($invoice['amount_paid'] ?? 0)),
            amountRefundedCents: 0,
            currency: strtolower((string) ($invoice['currency'] ?? 'brl')),
            periodStart: self::fromUnix($period['start'] ?? null),
            periodEnd: self::fromUnix($period['end'] ?? null),
            hostedInvoiceUrl: self::nullableString($invoice['hosted_invoice_url'] ?? null),
            lastEventCreated: max(0, $eventCreated),
        );
    }

    /**
     * Atualiza a partir da Invoice do Stripe (`invoice.*`). Fatura já
     * estornada por inteiro continua `refunded` (a Invoice do Stripe segue
     * `paid` depois do estorno).
     *
     * @param array<string, mixed> $invoice
     */
    public function applyStripe(array $invoice, int $eventCreated): bool
    {
        $status = self::stripeStatus($invoice);

        if ($this->isStale($eventCreated)) {
            return false;
        }

        $this->amountDueCents = max(0, (int) ($invoice['amount_due'] ?? $this->amountDueCents));
        $this->amountPaidCents = max(0, (int) ($invoice['amount_paid'] ?? $this->amountPaidCents));
        if (isset($invoice['currency'])) {
            $this->currency = strtolower((string) $invoice['currency']);
        }
        $this->hostedInvoiceUrl = self::nullableString($invoice['hosted_invoice_url'] ?? null) ?? $this->hostedInvoiceUrl;

        $period = $invoice['lines']['data'][0]['period'] ?? [];
        $this->periodStart = self::fromUnix($period['start'] ?? null) ?? $this->periodStart;
        $this->periodEnd = self::fromUnix($period['end'] ?? null) ?? $this->periodEnd;

        $this->status = $status === self::STATUS_PAID && $this->isFullyRefunded() ? self::STATUS_REFUNDED : $status;
        $this->lastEventCreated = $eventCreated;

        return true;
    }

    /** `charge.refunded`: total estornado até agora; `refunded` quando iguala o pago. */
    public function applyRefund(int $amountRefundedCents, int $eventCreated): bool
    {
        if ($amountRefundedCents < 0) {
            throw new InvalidArgumentException('amount_refunded_cents cannot be negative');
        }

        if ($this->isStale($eventCreated)) {
            return false;
        }

        $this->amountRefundedCents = $amountRefundedCents;
        if ($this->isFullyRefunded()) {
            $this->status = self::STATUS_REFUNDED;
        }
        $this->lastEventCreated = $eventCreated;

        return true;
    }

    /** Fatura local do Pix para o período, aberta, em BRL. */
    public static function issuePix(
        int $subscriptionId,
        int $amountCents,
        DateTimeImmutable $periodStart,
        DateTimeImmutable $periodEnd,
        DateTimeImmutable $dueAt,
    ): self {
        self::assertSubscriptionId($subscriptionId);

        if ($amountCents <= 0) {
            throw new InvalidArgumentException('Pix invoice amount must be positive');
        }

        if ($periodEnd <= $periodStart) {
            throw new InvalidArgumentException('Period end must be after period start');
        }

        return new self(
            id: null,
            subscriptionId: $subscriptionId,
            stripeInvoiceId: null,
            localKey: sprintf('pix:%d:%s', $subscriptionId, $periodStart->format('Y-m-d')),
            stripePaymentIntentId: null,
            dueAt: $dueAt,
            pixExpiresAt: null,
            paymentIntentAttempts: 0,
            status: self::STATUS_OPEN,
            amountDueCents: $amountCents,
            amountPaidCents: 0,
            amountRefundedCents: 0,
            currency: 'brl',
            periodStart: $periodStart,
            periodEnd: $periodEnd,
            hostedInvoiceUrl: null,
            lastEventCreated: 0,
        );
    }

    /** Registra o PaymentIntent Pix da tentativa (incrementa `payment_intent_attempts`). */
    public function attachPaymentIntent(string $paymentIntentId, ?string $payUrl, ?DateTimeImmutable $expiresAt): void
    {
        if (trim($paymentIntentId) === '') {
            throw new InvalidArgumentException('PaymentIntent id is required');
        }

        $this->stripePaymentIntentId = $paymentIntentId;
        $this->hostedInvoiceUrl = $payUrl;
        $this->pixExpiresAt = $expiresAt;
        $this->paymentIntentAttempts++;
    }

    /** `payment_intent.succeeded`: false sem mudança se já `paid` ou `refunded`. */
    public function markPaidByPaymentIntent(int $amountPaidCents, int $eventCreated): bool
    {
        if ($amountPaidCents < 0) {
            throw new InvalidArgumentException('amount_paid_cents cannot be negative');
        }

        if ($this->isStale($eventCreated) || in_array($this->status, [self::STATUS_PAID, self::STATUS_REFUNDED], true)) {
            return false;
        }

        $this->status = self::STATUS_PAID;
        $this->amountPaidCents = $amountPaidCents;
        $this->lastEventCreated = $eventCreated;

        return true;
    }

    /**
     * Cancelamento agendado (T-21): fatura local (Pix) `open` vira `void` e
     * devolve true; em qualquer outro caso devolve false sem mudar nada.
     */
    public function voidForCancellation(): bool
    {
        if ($this->localKey === null || $this->status !== self::STATUS_OPEN) {
            return false;
        }

        $this->status = self::STATUS_VOID;

        return true;
    }

    /** `open` com `due_at <= now`. */
    public function isOverdue(DateTimeImmutable $now): bool
    {
        return $this->status === self::STATUS_OPEN && $this->dueAt !== null && $this->dueAt <= $now;
    }

    /** Fatura local aberta sem PaymentIntent ou com o Pix expirado. */
    public function needsPaymentIntent(DateTimeImmutable $now): bool
    {
        if ($this->status !== self::STATUS_OPEN || $this->localKey === null) {
            return false;
        }

        return $this->stripePaymentIntentId === null
            || ($this->pixExpiresAt !== null && $this->pixExpiresAt <= $now);
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        $status = (string) $row['status'];
        if (!in_array($status, self::STATUSES, true)) {
            throw new InvalidArgumentException(sprintf('Unknown invoice status "%s"', $status));
        }

        return new self(
            id: self::nullableInt($row['id'] ?? null),
            subscriptionId: (int) $row['subscription_id'],
            stripeInvoiceId: self::nullableString($row['stripe_invoice_id'] ?? null),
            localKey: self::nullableString($row['local_key'] ?? null),
            stripePaymentIntentId: self::nullableString($row['stripe_payment_intent_id'] ?? null),
            dueAt: self::parseDate($row['due_at'] ?? null),
            pixExpiresAt: self::parseDate($row['pix_expires_at'] ?? null),
            paymentIntentAttempts: (int) ($row['payment_intent_attempts'] ?? 0),
            status: $status,
            amountDueCents: (int) $row['amount_due_cents'],
            amountPaidCents: (int) $row['amount_paid_cents'],
            amountRefundedCents: (int) ($row['amount_refunded_cents'] ?? 0),
            currency: (string) $row['currency'],
            periodStart: self::parseDate($row['period_start'] ?? null),
            periodEnd: self::parseDate($row['period_end'] ?? null),
            hostedInvoiceUrl: self::nullableString($row['hosted_invoice_url'] ?? null),
            lastEventCreated: (int) ($row['last_event_created'] ?? 0),
        );
    }

    /**
     * Colunas de `subscription_invoice` (T-02), sem `created_at`/`updated_at`.
     *
     * @return array<string, int|string|null>
     */
    public function toRow(): array
    {
        return [
            'id' => $this->id,
            'subscription_id' => $this->subscriptionId,
            'stripe_invoice_id' => $this->stripeInvoiceId,
            'local_key' => $this->localKey,
            'stripe_payment_intent_id' => $this->stripePaymentIntentId,
            'due_at' => $this->dueAt?->format(self::DATE_FORMAT),
            'pix_expires_at' => $this->pixExpiresAt?->format(self::DATE_FORMAT),
            'payment_intent_attempts' => $this->paymentIntentAttempts,
            'status' => $this->status,
            'amount_due_cents' => $this->amountDueCents,
            'amount_paid_cents' => $this->amountPaidCents,
            'amount_refunded_cents' => $this->amountRefundedCents,
            'currency' => $this->currency,
            'period_start' => $this->periodStart?->format(self::DATE_FORMAT),
            'period_end' => $this->periodEnd?->format(self::DATE_FORMAT),
            'hosted_invoice_url' => $this->hostedInvoiceUrl,
            'last_event_created' => $this->lastEventCreated,
        ];
    }

    public function withId(int $id): self
    {
        if ($id <= 0) {
            throw new InvalidArgumentException('Id must be positive');
        }

        $copy = clone $this;
        $copy->id = $id;

        return $copy;
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function subscriptionId(): int
    {
        return $this->subscriptionId;
    }

    public function stripeInvoiceId(): ?string
    {
        return $this->stripeInvoiceId;
    }

    public function localKey(): ?string
    {
        return $this->localKey;
    }

    public function stripePaymentIntentId(): ?string
    {
        return $this->stripePaymentIntentId;
    }

    public function dueAt(): ?DateTimeImmutable
    {
        return $this->dueAt;
    }

    public function pixExpiresAt(): ?DateTimeImmutable
    {
        return $this->pixExpiresAt;
    }

    public function paymentIntentAttempts(): int
    {
        return $this->paymentIntentAttempts;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function amountDueCents(): int
    {
        return $this->amountDueCents;
    }

    public function amountPaidCents(): int
    {
        return $this->amountPaidCents;
    }

    public function amountRefundedCents(): int
    {
        return $this->amountRefundedCents;
    }

    public function currency(): string
    {
        return $this->currency;
    }

    public function hostedInvoiceUrl(): ?string
    {
        return $this->hostedInvoiceUrl;
    }

    public function periodStart(): ?DateTimeImmutable
    {
        return $this->periodStart;
    }

    public function periodEnd(): ?DateTimeImmutable
    {
        return $this->periodEnd;
    }

    public function lastEventCreated(): int
    {
        return $this->lastEventCreated;
    }

    private function isStale(int $eventCreated): bool
    {
        return $eventCreated < $this->lastEventCreated;
    }

    private function isFullyRefunded(): bool
    {
        return $this->amountPaidCents > 0 && $this->amountRefundedCents >= $this->amountPaidCents;
    }

    /** @param array<string, mixed> $invoice */
    private static function stripeStatus(array $invoice): string
    {
        $status = (string) ($invoice['status'] ?? '');
        if (!in_array($status, self::STRIPE_STATUSES, true)) {
            throw new InvalidArgumentException(sprintf('Unknown Stripe invoice status "%s"', $status));
        }

        return $status;
    }

    private static function assertSubscriptionId(int $subscriptionId): void
    {
        if ($subscriptionId <= 0) {
            throw new InvalidArgumentException('subscription_id must be positive');
        }
    }

    private static function fromUnix(mixed $timestamp): ?DateTimeImmutable
    {
        if (!is_int($timestamp) && !(is_string($timestamp) && ctype_digit($timestamp))) {
            return null;
        }

        return (new DateTimeImmutable('@' . $timestamp))->setTimezone(new DateTimeZone(date_default_timezone_get()));
    }

    private static function parseDate(mixed $value): ?DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $value instanceof DateTimeImmutable ? $value : new DateTimeImmutable((string) $value);
    }

    private static function nullableInt(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }

    private static function nullableString(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : (string) $value;
    }
}
