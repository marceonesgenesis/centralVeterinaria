<?php

declare(strict_types=1);

namespace CentralVet\Domain;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * One payment recorded against a {@see Receivable} (domain entity for the
 * `payment` table, T-06), captured within a {@see CashSession}. Written
 * once by `CentralVet\Application\PaymentService::register()` — never
 * updated after creation, same shape as `CentralVet\Domain\FinancialEntry`.
 * Registering a payment also increments `Receivable::paidCents()`/moves its
 * `status` (via `Receivable::recordPayment()`) and writes a matching
 * `CentralVet\Domain\FinancialEntry` (entry_type='income',
 * reference_type='payment') — see `PaymentService`'s own docblock for the
 * documented, accepted limitation around those writes not sharing a
 * database transaction (same limitation already accepted for
 * `CentralVet\Application\PayableService::pay()`/Phase 4's
 * `ProcedureExecutionService`/`SaleService`, since this project has no
 * cross-service transaction primitive).
 *
 * Plain PHP entity, mutable like `CentralVet\Domain\ExamRequest`:
 * `register()` for a brand-new instance, `reconstitute()` for repositories
 * hydrating a row. No Adianti dependency (ADR 0001).
 *
 * PENDING / DO NOT WIRE YET: `payment` is created by the not-yet-applied
 * migration src/app/database/migrations/20260925_0006_phase5_financial.sql.
 * This class is prepared and syntax-checked (php -l) only; no query runs
 * against it until that migration has explicit SQL execution approval and
 * has actually been applied.
 */
final class Payment
{
    public const METHOD_CASH = 'cash';
    public const METHOD_DEBIT_CARD = 'debit_card';
    public const METHOD_CREDIT_CARD = 'credit_card';
    public const METHOD_PIX = 'pix';
    public const METHOD_BANK_TRANSFER = 'bank_transfer';

    private const METHODS = [
        self::METHOD_CASH,
        self::METHOD_DEBIT_CARD,
        self::METHOD_CREDIT_CARD,
        self::METHOD_PIX,
        self::METHOD_BANK_TRANSFER,
    ];

    private function __construct(
        private ?int $id,
        private readonly int $tenantId,
        private readonly int $receivableId,
        private readonly string $paymentMethod,
        private readonly int $amountCents,
        private readonly int $cashSessionId,
        private readonly int $systemUserId,
        private readonly DateTimeImmutable $paidAt,
        private readonly ?DateTimeImmutable $createdAt = null,
    ) {
    }

    public static function register(
        int $tenantId,
        int $receivableId,
        string $paymentMethod,
        int $amountCents,
        int $cashSessionId,
        int $systemUserId,
        DateTimeImmutable $paidAt,
    ): self {
        if ($tenantId <= 0) {
            throw new InvalidArgumentException('Tenant id must be positive');
        }

        if ($receivableId <= 0) {
            throw new InvalidArgumentException('receivable_id must be positive');
        }

        if (!in_array($paymentMethod, self::METHODS, true)) {
            throw new InvalidArgumentException("Unknown payment_method \"{$paymentMethod}\"");
        }

        if ($amountCents < 1) {
            throw new InvalidArgumentException('amount_cents must be >= 1');
        }

        if ($cashSessionId <= 0) {
            throw new InvalidArgumentException('cash_session_id must be positive');
        }

        if ($systemUserId <= 0) {
            throw new InvalidArgumentException('system_user_id must be positive');
        }

        return new self(
            id: null,
            tenantId: $tenantId,
            receivableId: $receivableId,
            paymentMethod: $paymentMethod,
            amountCents: $amountCents,
            cashSessionId: $cashSessionId,
            systemUserId: $systemUserId,
            paidAt: $paidAt,
        );
    }

    /**
     * Rebuilds an existing payment from persisted data. Repositories use
     * this to hydrate rows; application code should use register() instead.
     *
     * @param array<string, mixed> $row raw column values, keyed exactly like
     *        the `payment` table.
     */
    public static function reconstitute(array $row): self
    {
        $paymentMethod = (string) $row['payment_method'];

        if (!in_array($paymentMethod, self::METHODS, true)) {
            throw new InvalidArgumentException("Unknown payment_method \"{$paymentMethod}\"");
        }

        return new self(
            id: (int) $row['id'],
            tenantId: (int) $row['tenant_id'],
            receivableId: (int) $row['receivable_id'],
            paymentMethod: $paymentMethod,
            amountCents: (int) $row['amount_cents'],
            cashSessionId: (int) $row['cash_session_id'],
            systemUserId: (int) $row['system_user_id'],
            paidAt: new DateTimeImmutable((string) $row['paid_at']),
            createdAt: isset($row['created_at']) && $row['created_at'] !== null
                ? new DateTimeImmutable((string) $row['created_at'])
                : null,
        );
    }

    /** Assigns the identifier generated on first persistence. */
    public function assignId(int $id): void
    {
        if ($this->id !== null) {
            throw new InvalidArgumentException('Payment already has an id');
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

    public function receivableId(): int
    {
        return $this->receivableId;
    }

    public function paymentMethod(): string
    {
        return $this->paymentMethod;
    }

    public function amountCents(): int
    {
        return $this->amountCents;
    }

    public function cashSessionId(): int
    {
        return $this->cashSessionId;
    }

    public function systemUserId(): int
    {
        return $this->systemUserId;
    }

    public function paidAt(): DateTimeImmutable
    {
        return $this->paidAt;
    }

    public function createdAt(): ?DateTimeImmutable
    {
        return $this->createdAt;
    }
}
