<?php

declare(strict_types=1);

namespace CentralVet\Billing;

use CentralVet\Domain\Exception\InvalidStatusTransitionException;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Pedido de compra feito pela landing (tabela `billing_order`, T-02): plano,
 * responsável, aceite dos termos e o vínculo com o Checkout do Stripe. O
 * preço vem sempre de PlanEntitlements::forPlan()->priceCents(), nunca do
 * cliente.
 *
 * Ciclo: pending → checkout_open → paid → provisioned, com
 * provisioning_failed (retomável) e expired (checkout abandonado). Pagamento
 * que chega depois de expirado ainda é aceito (o dinheiro foi cobrado).
 */
final class BillingOrder
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_CHECKOUT_OPEN = 'checkout_open';
    public const STATUS_PAID = 'paid';
    public const STATUS_PROVISIONED = 'provisioned';
    public const STATUS_PROVISIONING_FAILED = 'provisioning_failed';
    public const STATUS_EXPIRED = 'expired';

    public const METHOD_CARD = 'card';
    public const METHOD_PIX = 'pix';

    private const STATUSES = [
        self::STATUS_PENDING, self::STATUS_CHECKOUT_OPEN, self::STATUS_PAID,
        self::STATUS_PROVISIONED, self::STATUS_PROVISIONING_FAILED, self::STATUS_EXPIRED,
    ];

    /** Status em que o pagamento já foi registrado. */
    private const PAID_STATUSES = [self::STATUS_PAID, self::STATUS_PROVISIONED, self::STATUS_PROVISIONING_FAILED];

    private const DATE_FORMAT = 'Y-m-d H:i:s.u';

    private function __construct(
        private ?int $id,
        private readonly string $publicId,
        private readonly string $planId,
        private readonly int $priceCents,
        private readonly string $currency,
        private readonly string $paymentMethod,
        private readonly string $responsibleName,
        private readonly string $responsibleEmail,
        private readonly string $responsiblePhone,
        private readonly string $clinicName,
        private readonly string $termsVersion,
        private readonly DateTimeImmutable $termsAcceptedAt,
        private readonly string $termsIp,
        private readonly bool $contactConsent,
        private string $status,
        private ?string $stripeCustomerId,
        private ?string $stripeCheckoutSessionId,
        private ?string $stripeSubscriptionId,
        private ?DateTimeImmutable $paidAt,
        private ?int $tenantId,
        private int $provisioningAttempts,
        private ?string $provisioningError,
        private ?DateTimeImmutable $inviteSentAt,
    ) {
    }

    public static function place(
        string $publicId,
        string $planId,
        string $paymentMethod,
        string $responsibleName,
        string $responsibleEmail,
        string $responsiblePhone,
        string $clinicName,
        string $termsVersion,
        DateTimeImmutable $termsAcceptedAt,
        string $termsIp,
        bool $contactConsent,
    ): self {
        $plan = PlanEntitlements::forPlan($planId);

        if (!in_array($paymentMethod, [self::METHOD_CARD, self::METHOD_PIX], true)) {
            throw new InvalidArgumentException(sprintf('Unknown payment method "%s"', $paymentMethod));
        }

        self::required('public_id', $publicId, 36);
        self::required('responsible_name', $responsibleName, 160);
        self::required('responsible_email', $responsibleEmail, 190);
        self::required('responsible_phone', $responsiblePhone, 30);
        self::required('clinic_name', $clinicName, 190);
        self::required('terms_version', $termsVersion, 40);
        self::required('terms_ip', $termsIp, 45);

        if (filter_var($responsibleEmail, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('responsible_email is invalid');
        }

        return new self(
            id: null,
            publicId: $publicId,
            planId: $planId,
            priceCents: $plan->priceCents(),
            currency: 'brl',
            paymentMethod: $paymentMethod,
            responsibleName: trim($responsibleName),
            responsibleEmail: trim($responsibleEmail),
            responsiblePhone: trim($responsiblePhone),
            clinicName: trim($clinicName),
            termsVersion: $termsVersion,
            termsAcceptedAt: $termsAcceptedAt,
            termsIp: $termsIp,
            contactConsent: $contactConsent,
            status: self::STATUS_PENDING,
            stripeCustomerId: null,
            stripeCheckoutSessionId: null,
            stripeSubscriptionId: null,
            paidAt: null,
            tenantId: null,
            provisioningAttempts: 0,
            provisioningError: null,
            inviteSentAt: null,
        );
    }

    /**
     * Liga o pedido à sessão de Checkout criada no Stripe.
     *
     * @throws InvalidStatusTransitionException fora de pending/checkout_open
     */
    public function attachCheckout(string $checkoutSessionId, string $stripeCustomerId): void
    {
        $this->assertStatus([self::STATUS_PENDING, self::STATUS_CHECKOUT_OPEN], 'attach a checkout');
        self::required('stripe_checkout_session_id', $checkoutSessionId, 100);
        self::required('stripe_customer_id', $stripeCustomerId, 64);

        $this->stripeCheckoutSessionId = $checkoutSessionId;
        $this->stripeCustomerId = $stripeCustomerId;
        $this->status = self::STATUS_CHECKOUT_OPEN;
    }

    /** Checkout concluído; false sem mudança se o pagamento já foi registrado. */
    public function markPaid(?string $stripeSubscriptionId, DateTimeImmutable $paidAt): bool
    {
        if (in_array($this->status, self::PAID_STATUSES, true)) {
            return false;
        }

        $this->status = self::STATUS_PAID;
        $this->paidAt = $paidAt;
        $this->stripeSubscriptionId = $stripeSubscriptionId ?? $this->stripeSubscriptionId;

        return true;
    }

    /** Checkout abandonado; false se já pago ou já expirado. */
    public function expire(): bool
    {
        if (in_array($this->status, self::PAID_STATUSES, true) || $this->status === self::STATUS_EXPIRED) {
            return false;
        }

        $this->status = self::STATUS_EXPIRED;

        return true;
    }

    /** @throws InvalidStatusTransitionException fora de paid/provisioning_failed */
    public function markProvisioned(int $tenantId): void
    {
        if ($tenantId <= 0) {
            throw new InvalidArgumentException('Tenant id must be positive');
        }

        $this->assertStatus([self::STATUS_PAID, self::STATUS_PROVISIONING_FAILED], 'be provisioned');

        $this->status = self::STATUS_PROVISIONED;
        $this->tenantId = $tenantId;
        $this->provisioningError = null;
    }

    /** @throws InvalidStatusTransitionException fora de paid/provisioning_failed */
    public function markProvisioningFailed(string $errorCode): void
    {
        $this->assertStatus([self::STATUS_PAID, self::STATUS_PROVISIONING_FAILED], 'fail provisioning');

        $this->status = self::STATUS_PROVISIONING_FAILED;
        $this->provisioningAttempts++;
        $this->provisioningError = mb_substr(trim($errorCode) !== '' ? trim($errorCode) : 'unknown', 0, 120);
    }

    public function markInviteSent(DateTimeImmutable $at): void
    {
        $this->inviteSentAt = $at;
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        $status = (string) $row['status'];
        if (!in_array($status, self::STATUSES, true)) {
            throw new InvalidArgumentException(sprintf('Unknown billing order status "%s"', $status));
        }

        return new self(
            id: self::nullableInt($row['id'] ?? null),
            publicId: (string) $row['public_id'],
            planId: (string) $row['plan_id'],
            priceCents: (int) $row['price_cents'],
            currency: (string) ($row['currency'] ?? 'brl'),
            paymentMethod: (string) $row['payment_method'],
            responsibleName: (string) $row['responsible_name'],
            responsibleEmail: (string) $row['responsible_email'],
            responsiblePhone: (string) $row['responsible_phone'],
            clinicName: (string) $row['clinic_name'],
            termsVersion: (string) $row['terms_version'],
            termsAcceptedAt: new DateTimeImmutable((string) $row['terms_accepted_at']),
            termsIp: (string) $row['terms_ip'],
            contactConsent: (bool) (int) ($row['contact_consent'] ?? 0),
            status: $status,
            stripeCustomerId: self::nullableString($row['stripe_customer_id'] ?? null),
            stripeCheckoutSessionId: self::nullableString($row['stripe_checkout_session_id'] ?? null),
            stripeSubscriptionId: self::nullableString($row['stripe_subscription_id'] ?? null),
            paidAt: self::parseDate($row['paid_at'] ?? null),
            tenantId: self::nullableInt($row['tenant_id'] ?? null),
            provisioningAttempts: (int) ($row['provisioning_attempts'] ?? 0),
            provisioningError: self::nullableString($row['provisioning_error'] ?? null),
            inviteSentAt: self::parseDate($row['invite_sent_at'] ?? null),
        );
    }

    /**
     * Colunas de `billing_order` (T-02), sem `created_at`/`updated_at`.
     *
     * @return array<string, int|string|null>
     */
    public function toRow(): array
    {
        return [
            'id' => $this->id,
            'public_id' => $this->publicId,
            'plan_id' => $this->planId,
            'price_cents' => $this->priceCents,
            'currency' => $this->currency,
            'payment_method' => $this->paymentMethod,
            'responsible_name' => $this->responsibleName,
            'responsible_email' => $this->responsibleEmail,
            'responsible_phone' => $this->responsiblePhone,
            'clinic_name' => $this->clinicName,
            'terms_version' => $this->termsVersion,
            'terms_accepted_at' => $this->termsAcceptedAt->format(self::DATE_FORMAT),
            'terms_ip' => $this->termsIp,
            'contact_consent' => $this->contactConsent ? 1 : 0,
            'status' => $this->status,
            'stripe_customer_id' => $this->stripeCustomerId,
            'stripe_checkout_session_id' => $this->stripeCheckoutSessionId,
            'stripe_subscription_id' => $this->stripeSubscriptionId,
            'paid_at' => $this->paidAt?->format(self::DATE_FORMAT),
            'tenant_id' => $this->tenantId,
            'provisioning_attempts' => $this->provisioningAttempts,
            'provisioning_error' => $this->provisioningError,
            'invite_sent_at' => $this->inviteSentAt?->format(self::DATE_FORMAT),
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

    public function publicId(): string
    {
        return $this->publicId;
    }

    public function planId(): string
    {
        return $this->planId;
    }

    public function priceCents(): int
    {
        return $this->priceCents;
    }

    public function currency(): string
    {
        return $this->currency;
    }

    public function paymentMethod(): string
    {
        return $this->paymentMethod;
    }

    public function responsibleName(): string
    {
        return $this->responsibleName;
    }

    public function responsibleEmail(): string
    {
        return $this->responsibleEmail;
    }

    public function responsiblePhone(): string
    {
        return $this->responsiblePhone;
    }

    public function clinicName(): string
    {
        return $this->clinicName;
    }

    public function termsVersion(): string
    {
        return $this->termsVersion;
    }

    public function termsAcceptedAt(): DateTimeImmutable
    {
        return $this->termsAcceptedAt;
    }

    public function termsIp(): string
    {
        return $this->termsIp;
    }

    public function contactConsent(): bool
    {
        return $this->contactConsent;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function stripeCustomerId(): ?string
    {
        return $this->stripeCustomerId;
    }

    public function stripeCheckoutSessionId(): ?string
    {
        return $this->stripeCheckoutSessionId;
    }

    public function stripeSubscriptionId(): ?string
    {
        return $this->stripeSubscriptionId;
    }

    public function paidAt(): ?DateTimeImmutable
    {
        return $this->paidAt;
    }

    public function tenantId(): ?int
    {
        return $this->tenantId;
    }

    public function provisioningAttempts(): int
    {
        return $this->provisioningAttempts;
    }

    public function provisioningError(): ?string
    {
        return $this->provisioningError;
    }

    public function inviteSentAt(): ?DateTimeImmutable
    {
        return $this->inviteSentAt;
    }

    /** @param list<string> $allowed */
    private function assertStatus(array $allowed, string $action): void
    {
        if (!in_array($this->status, $allowed, true)) {
            throw new InvalidStatusTransitionException(sprintf(
                'Billing order %s cannot %s: current status is "%s"',
                $this->id !== null ? (string) $this->id : $this->publicId,
                $action,
                $this->status,
            ));
        }
    }

    private static function required(string $field, string $value, int $maxLength): void
    {
        $value = trim($value);
        if ($value === '') {
            throw new InvalidArgumentException($field . ' is required');
        }

        if (mb_strlen($value) > $maxLength) {
            throw new InvalidArgumentException(sprintf('%s exceeds %d characters', $field, $maxLength));
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
