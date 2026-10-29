<?php

declare(strict_types=1);

namespace CentralVet\Domain;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Reversal of one payment (Etapa 2, T-08; table `payment_reversal`,
 * migration 0016): recorded in the cash session open at the time of the
 * reversal — a closed session is never rewritten — with the same method and
 * amount as the reversed payment. At most one per payment
 * (payment_reversal_payment_uq). Append-only, plain PHP (ADR 0001).
 */
final class PaymentReversal
{
    private function __construct(
        private ?int $id,
        private readonly int $tenantId,
        private readonly int $paymentId,
        private readonly int $cashSessionId,
        private readonly string $paymentMethod,
        private readonly int $amountCents,
        private readonly string $reason,
        private readonly int $systemUserId,
        private readonly DateTimeImmutable $reversedAt,
    ) {
    }

    public static function record(
        int $tenantId,
        int $paymentId,
        int $cashSessionId,
        string $paymentMethod,
        int $amountCents,
        string $reason,
        int $systemUserId,
        DateTimeImmutable $reversedAt,
    ): self {
        if ($tenantId <= 0) {
            throw new InvalidArgumentException('Tenant id must be positive');
        }

        if ($paymentId <= 0) {
            throw new InvalidArgumentException('payment_id must be positive');
        }

        if ($cashSessionId <= 0) {
            throw new InvalidArgumentException('cash_session_id must be positive');
        }

        if (!in_array($paymentMethod, Payment::METHODS, true)) {
            throw new InvalidArgumentException("Unknown payment_method \"{$paymentMethod}\"");
        }

        if ($amountCents < 1) {
            throw new InvalidArgumentException('amount_cents must be >= 1');
        }

        $reason = trim($reason);

        if ($reason === '' || mb_strlen($reason) > 500) {
            throw new InvalidArgumentException('reason must have between 1 and 500 characters');
        }

        if ($systemUserId <= 0) {
            throw new InvalidArgumentException('system_user_id must be positive');
        }

        return new self(null, $tenantId, $paymentId, $cashSessionId, $paymentMethod, $amountCents, $reason, $systemUserId, $reversedAt);
    }

    /**
     * Rebuilds a persisted reversal. Repositories use this to hydrate rows.
     *
     * @param array<string, mixed> $row raw column values of `payment_reversal`
     */
    public static function reconstitute(array $row): self
    {
        return new self(
            (int) $row['id'],
            (int) $row['tenant_id'],
            (int) $row['payment_id'],
            (int) $row['cash_session_id'],
            (string) $row['payment_method'],
            (int) $row['amount_cents'],
            (string) $row['reason'],
            (int) $row['system_user_id'],
            new DateTimeImmutable((string) $row['reversed_at']),
        );
    }

    /** Assigns the identifier generated on first persistence. */
    public function assignId(int $id): void
    {
        if ($this->id !== null) {
            throw new InvalidArgumentException('PaymentReversal already has an id');
        }

        if ($id <= 0) {
            throw new InvalidArgumentException('Id must be positive');
        }

        $this->id = $id;
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function tenantId(): int
    {
        return $this->tenantId;
    }

    public function paymentId(): int
    {
        return $this->paymentId;
    }

    public function cashSessionId(): int
    {
        return $this->cashSessionId;
    }

    public function paymentMethod(): string
    {
        return $this->paymentMethod;
    }

    public function amountCents(): int
    {
        return $this->amountCents;
    }

    public function reason(): string
    {
        return $this->reason;
    }

    public function systemUserId(): int
    {
        return $this->systemUserId;
    }

    public function reversedAt(): DateTimeImmutable
    {
        return $this->reversedAt;
    }
}
