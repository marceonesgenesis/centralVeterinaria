<?php

declare(strict_types=1);

namespace CentralVet\Billing;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Assinatura mensal de uma clínica (tabela `subscription`, T-02).
 *
 * Cartão: Subscription do Stripe em `charge_automatically`; o estado segue os
 * eventos do Stripe (applyProviderStatus, markInvoicePaid, markPaymentFailed).
 * Pix: sem Subscription no Stripe (`stripe_subscription_id` nulo); o ciclo é
 * local — fatura por período (T-23), markOverdue no vencimento e
 * suspendIfGraceExpired no fim da tolerância (T-18).
 *
 * Guarda contra evento antigo: todo método que recebe `$eventCreated`
 * devolve false e não muda nada quando ele é menor que lastEventCreated().
 * markOverdue e suspendIfGraceExpired são locais e não tocam
 * `last_event_created`, para não descartar o pagamento que chegar depois.
 */
final class Subscription
{
    public const STATUS_INCOMPLETE = 'incomplete';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_PAST_DUE = 'past_due';
    public const STATUS_SUSPENDED = 'suspended';
    public const STATUS_CANCELLED = 'cancelled';

    public const METHOD_CARD = 'card';
    public const METHOD_PIX = 'pix';

    public const GRACE_DAYS = 7;

    public const ACCESS_FULL = 'full';
    public const ACCESS_GRACE = 'grace';
    public const ACCESS_BLOCKED = 'blocked';

    private const DATE_FORMAT = 'Y-m-d H:i:s.u';

    /** Status da Subscription no Stripe → status local. */
    private const PROVIDER_STATUS = [
        'trialing' => self::STATUS_ACTIVE,
        'active' => self::STATUS_ACTIVE,
        'past_due' => self::STATUS_PAST_DUE,
        'unpaid' => self::STATUS_SUSPENDED,
        'paused' => self::STATUS_SUSPENDED,
        'canceled' => self::STATUS_CANCELLED,
        'incomplete_expired' => self::STATUS_CANCELLED,
        'incomplete' => self::STATUS_INCOMPLETE,
    ];

    private const STATUSES = [
        self::STATUS_INCOMPLETE, self::STATUS_ACTIVE, self::STATUS_PAST_DUE,
        self::STATUS_SUSPENDED, self::STATUS_CANCELLED,
    ];

    private function __construct(
        private ?int $id,
        private readonly int $billingOrderId,
        private ?int $tenantId,
        private readonly string $planId,
        private string $status,
        private readonly string $paymentMethod,
        private readonly string $stripeCustomerId,
        private readonly ?string $stripeSubscriptionId,
        private ?DateTimeImmutable $currentPeriodStart,
        private ?DateTimeImmutable $currentPeriodEnd,
        private ?DateTimeImmutable $graceUntil,
        private ?DateTimeImmutable $cancelledAt,
        private int $lastEventCreated,
    ) {
    }

    public static function start(
        int $billingOrderId,
        string $planId,
        string $paymentMethod,
        string $stripeCustomerId,
        ?string $stripeSubscriptionId,
        string $stripeStatus,
        DateTimeImmutable $periodStart,
        DateTimeImmutable $periodEnd,
        int $eventCreated,
    ): self {
        if ($billingOrderId <= 0) {
            throw new InvalidArgumentException('billing_order_id must be positive');
        }

        PlanEntitlements::forPlan($planId);
        self::assertMethod($paymentMethod);

        if (trim($stripeCustomerId) === '') {
            throw new InvalidArgumentException('stripe_customer_id is required');
        }

        if ($paymentMethod === self::METHOD_PIX && $stripeSubscriptionId !== null) {
            throw new InvalidArgumentException('Pix subscriptions have no Stripe subscription id');
        }

        if ($stripeSubscriptionId !== null && trim($stripeSubscriptionId) === '') {
            throw new InvalidArgumentException('stripe_subscription_id cannot be empty');
        }

        if ($periodEnd <= $periodStart) {
            throw new InvalidArgumentException('Period end must be after period start');
        }

        return new self(
            id: null,
            billingOrderId: $billingOrderId,
            tenantId: null,
            planId: $planId,
            status: self::mapProviderStatus($stripeStatus),
            paymentMethod: $paymentMethod,
            stripeCustomerId: $stripeCustomerId,
            stripeSubscriptionId: $stripeSubscriptionId,
            currentPeriodStart: $periodStart,
            currentPeriodEnd: $periodEnd,
            graceUntil: null,
            cancelledAt: null,
            lastEventCreated: max(0, $eventCreated),
        );
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        $status = (string) $row['status'];
        if (!in_array($status, self::STATUSES, true)) {
            throw new InvalidArgumentException(sprintf('Unknown subscription status "%s"', $status));
        }

        return new self(
            id: self::nullableInt($row['id'] ?? null),
            billingOrderId: (int) $row['billing_order_id'],
            tenantId: self::nullableInt($row['tenant_id'] ?? null),
            planId: (string) $row['plan_id'],
            status: $status,
            paymentMethod: (string) $row['payment_method'],
            stripeCustomerId: (string) $row['stripe_customer_id'],
            stripeSubscriptionId: self::nullableString($row['stripe_subscription_id'] ?? null),
            currentPeriodStart: self::parseDate($row['current_period_start'] ?? null),
            currentPeriodEnd: self::parseDate($row['current_period_end'] ?? null),
            graceUntil: self::parseDate($row['grace_until'] ?? null),
            cancelledAt: self::parseDate($row['cancelled_at'] ?? null),
            lastEventCreated: (int) ($row['last_event_created'] ?? 0),
        );
    }

    /**
     * Colunas de `subscription` (T-02), sem `created_at`/`updated_at`, que o
     * banco preenche.
     *
     * @return array<string, int|string|null>
     */
    public function toRow(): array
    {
        return [
            'id' => $this->id,
            'billing_order_id' => $this->billingOrderId,
            'tenant_id' => $this->tenantId,
            'plan_id' => $this->planId,
            'status' => $this->status,
            'payment_method' => $this->paymentMethod,
            'stripe_customer_id' => $this->stripeCustomerId,
            'stripe_subscription_id' => $this->stripeSubscriptionId,
            'current_period_start' => $this->currentPeriodStart?->format(self::DATE_FORMAT),
            'current_period_end' => $this->currentPeriodEnd?->format(self::DATE_FORMAT),
            'grace_until' => $this->graceUntil?->format(self::DATE_FORMAT),
            'cancelled_at' => $this->cancelledAt?->format(self::DATE_FORMAT),
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

    /** Liga a assinatura ao tenant provisionado; repetir com o mesmo id é inofensivo. */
    public function assignTenant(int $tenantId): void
    {
        if ($tenantId <= 0) {
            throw new InvalidArgumentException('Tenant id must be positive');
        }

        if ($this->tenantId !== null && $this->tenantId !== $tenantId) {
            throw new InvalidArgumentException('Subscription already belongs to another tenant');
        }

        $this->tenantId = $tenantId;
    }

    /**
     * Aplica o status da Subscription do Stripe (`customer.subscription.*`).
     * Períodos nulos mantêm os atuais.
     *
     * @throws InvalidArgumentException para status do Stripe desconhecido
     */
    public function applyProviderStatus(
        string $stripeStatus,
        ?DateTimeImmutable $periodStart,
        ?DateTimeImmutable $periodEnd,
        int $eventCreated,
        DateTimeImmutable $now,
    ): bool {
        $status = self::mapProviderStatus($stripeStatus);

        if ($this->isStale($eventCreated)) {
            return false;
        }

        $this->moveTo($status, $now);
        $this->currentPeriodStart = $periodStart ?? $this->currentPeriodStart;
        $this->currentPeriodEnd = $periodEnd ?? $this->currentPeriodEnd;
        $this->lastEventCreated = $eventCreated;

        return true;
    }

    /**
     * Fatura do período paga: volta a `active` (também de `past_due` e
     * `suspended`), zera a tolerância e passa o período ao da fatura.
     * Assinatura cancelada não é reativada (devolve false).
     */
    public function markInvoicePaid(DateTimeImmutable $periodStart, DateTimeImmutable $periodEnd, int $eventCreated): bool
    {
        if ($this->isStale($eventCreated) || $this->status === self::STATUS_CANCELLED) {
            return false;
        }

        $this->status = self::STATUS_ACTIVE;
        $this->graceUntil = null;
        $this->currentPeriodStart = $periodStart;
        $this->currentPeriodEnd = $periodEnd;
        $this->lastEventCreated = $eventCreated;

        return true;
    }

    /**
     * `invoice.payment_failed`: `active`/`incomplete`/`past_due` → `past_due`
     * com tolerância a partir da primeira falha. Em `suspended`/`cancelled`
     * o evento é aceito sem mudar o status.
     */
    public function markPaymentFailed(int $eventCreated, DateTimeImmutable $now): bool
    {
        if ($this->isStale($eventCreated)) {
            return false;
        }

        if (in_array($this->status, [self::STATUS_ACTIVE, self::STATUS_INCOMPLETE, self::STATUS_PAST_DUE], true)) {
            $this->moveTo(self::STATUS_PAST_DUE, $now);
        }

        $this->lastEventCreated = $eventCreated;

        return true;
    }

    /** Vencimento local do Pix: `active` → `past_due` com tolerância; false em outro status. */
    public function markOverdue(DateTimeImmutable $now): bool
    {
        if ($this->status !== self::STATUS_ACTIVE) {
            return false;
        }

        $this->moveTo(self::STATUS_PAST_DUE, $now);

        return true;
    }

    /** `past_due` com `grace_until <= now` → `suspended`. */
    public function suspendIfGraceExpired(DateTimeImmutable $now): bool
    {
        if ($this->status !== self::STATUS_PAST_DUE || $this->graceUntil === null || $this->graceUntil > $now) {
            return false;
        }

        $this->status = self::STATUS_SUSPENDED;

        return true;
    }

    /** `full`, `grace` (past_due dentro da tolerância) ou `blocked`. */
    public function accessLevel(DateTimeImmutable $now): string
    {
        if ($this->status === self::STATUS_ACTIVE) {
            return self::ACCESS_FULL;
        }

        if ($this->status === self::STATUS_PAST_DUE && $this->graceUntil !== null && $this->graceUntil > $now) {
            return self::ACCESS_GRACE;
        }

        return self::ACCESS_BLOCKED;
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function billingOrderId(): int
    {
        return $this->billingOrderId;
    }

    public function tenantId(): ?int
    {
        return $this->tenantId;
    }

    public function planId(): string
    {
        return $this->planId;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function paymentMethod(): string
    {
        return $this->paymentMethod;
    }

    public function stripeCustomerId(): string
    {
        return $this->stripeCustomerId;
    }

    /** Nulo no Pix. */
    public function stripeSubscriptionId(): ?string
    {
        return $this->stripeSubscriptionId;
    }

    public function currentPeriodStart(): ?DateTimeImmutable
    {
        return $this->currentPeriodStart;
    }

    public function currentPeriodEnd(): ?DateTimeImmutable
    {
        return $this->currentPeriodEnd;
    }

    public function graceUntil(): ?DateTimeImmutable
    {
        return $this->graceUntil;
    }

    public function cancelledAt(): ?DateTimeImmutable
    {
        return $this->cancelledAt;
    }

    public function lastEventCreated(): int
    {
        return $this->lastEventCreated;
    }

    private function isStale(int $eventCreated): bool
    {
        return $eventCreated < $this->lastEventCreated;
    }

    private function moveTo(string $status, DateTimeImmutable $now): void
    {
        if ($status === self::STATUS_PAST_DUE) {
            $this->graceUntil ??= $now->modify('+' . self::GRACE_DAYS . ' days');
        } elseif ($status === self::STATUS_ACTIVE) {
            $this->graceUntil = null;
        }

        if ($status === self::STATUS_CANCELLED) {
            $this->cancelledAt ??= $now;
        }

        $this->status = $status;
    }

    private static function mapProviderStatus(string $stripeStatus): string
    {
        $status = self::PROVIDER_STATUS[$stripeStatus] ?? null;
        if ($status === null) {
            throw new InvalidArgumentException(sprintf('Unknown Stripe subscription status "%s"', $stripeStatus));
        }

        return $status;
    }

    private static function assertMethod(string $paymentMethod): void
    {
        if (!in_array($paymentMethod, [self::METHOD_CARD, self::METHOD_PIX], true)) {
            throw new InvalidArgumentException(sprintf('Unknown payment method "%s"', $paymentMethod));
        }
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
