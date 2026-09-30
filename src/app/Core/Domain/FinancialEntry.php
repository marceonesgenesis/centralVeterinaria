<?php

declare(strict_types=1);

namespace CentralVet\Domain;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * An append-only ledger row for one financial movement (domain entity for
 * the `financial_entry` table), written once by
 * CentralVet\Application\FinancialEntryService::record() — never updated
 * after creation, same shape as CentralVet\Domain\StockMovement (Phase 4).
 * `reference_type`/`reference_id` optionally point back at the entity that
 * caused the entry (e.g. 'payable'/$payableId from
 * CentralVet\Application\PayableService::pay(), or 'payment'/$paymentId
 * from CentralVet\Application\PaymentService::register() in T-06); a
 * manual entry created directly through FinancialEntryForm (T-09) leaves
 * both null.
 *
 * PENDING / DO NOT WIRE YET: `financial_entry` is created by the
 * not-yet-applied migration
 * src/app/database/migrations/20260925_0006_phase5_financial.sql. This
 * class is prepared and syntax-checked (php -l) only; no query runs
 * against it until that migration has explicit SQL execution approval and
 * has actually been applied.
 */
final class FinancialEntry
{
    public const TYPE_INCOME = 'income';
    public const TYPE_EXPENSE = 'expense';

    private const TYPES = [self::TYPE_INCOME, self::TYPE_EXPENSE];

    /** Same list as Payment::METHODS (T-14); null means "not informed". */
    private const PAYMENT_METHODS = [
        Payment::METHOD_CASH,
        Payment::METHOD_DEBIT_CARD,
        Payment::METHOD_CREDIT_CARD,
        Payment::METHOD_PIX,
        Payment::METHOD_BANK_TRANSFER,
    ];

    private function __construct(
        private ?int $id,
        private readonly int $tenantId,
        private readonly int $systemUnitId,
        private readonly string $entryType,
        private readonly string $category,
        private readonly int $amountCents,
        private readonly ?string $referenceType,
        private readonly ?int $referenceId,
        private readonly DateTimeImmutable $occurredAt,
        private readonly int $systemUserId,
        private readonly ?DateTimeImmutable $createdAt = null,
        private readonly ?string $paymentMethod = null,
    ) {
    }

    public static function record(
        int $tenantId,
        int $systemUnitId,
        string $entryType,
        string $category,
        int $amountCents,
        ?string $referenceType,
        ?int $referenceId,
        DateTimeImmutable $occurredAt,
        int $systemUserId,
        ?string $paymentMethod = null,
    ): self {
        if ($tenantId <= 0) {
            throw new InvalidArgumentException('Tenant id must be positive');
        }

        if ($systemUnitId <= 0) {
            throw new InvalidArgumentException('system_unit_id must be positive');
        }

        if (!in_array($entryType, self::TYPES, true)) {
            throw new InvalidArgumentException("entry_type must be 'income' or 'expense'");
        }

        $category = trim($category);

        if ($category === '') {
            throw new InvalidArgumentException('category must not be empty');
        }

        if ($amountCents < 1) {
            throw new InvalidArgumentException('amount_cents must be >= 1');
        }

        if ($referenceType !== null && trim($referenceType) === '') {
            throw new InvalidArgumentException('reference_type must not be blank when given');
        }

        if ($systemUserId <= 0) {
            throw new InvalidArgumentException('system_user_id must be positive');
        }

        if ($paymentMethod !== null && !in_array($paymentMethod, self::PAYMENT_METHODS, true)) {
            throw new InvalidArgumentException(
                'payment_method must be one of: ' . implode(', ', self::PAYMENT_METHODS)
            );
        }

        return new self(
            id: null,
            tenantId: $tenantId,
            systemUnitId: $systemUnitId,
            entryType: $entryType,
            category: $category,
            amountCents: $amountCents,
            referenceType: $referenceType,
            referenceId: $referenceId,
            occurredAt: $occurredAt,
            systemUserId: $systemUserId,
            paymentMethod: $paymentMethod,
        );
    }

    /**
     * Rebuilds an existing entry from persisted data. Repositories use this
     * to hydrate rows; application code should use record() instead.
     */
    public static function reconstitute(
        int $id,
        int $tenantId,
        int $systemUnitId,
        string $entryType,
        string $category,
        int $amountCents,
        ?string $referenceType,
        ?int $referenceId,
        DateTimeImmutable $occurredAt,
        int $systemUserId,
        ?DateTimeImmutable $createdAt,
        ?string $paymentMethod = null,
    ): self {
        return new self(
            $id,
            $tenantId,
            $systemUnitId,
            $entryType,
            $category,
            $amountCents,
            $referenceType,
            $referenceId,
            $occurredAt,
            $systemUserId,
            $createdAt,
            $paymentMethod,
        );
    }

    /** Assigns the identifier generated on first persistence. */
    public function assignId(int $id): void
    {
        if ($this->id !== null) {
            throw new InvalidArgumentException('Financial entry already has an id');
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

    public function systemUnitId(): int
    {
        return $this->systemUnitId;
    }

    public function entryType(): string
    {
        return $this->entryType;
    }

    public function category(): string
    {
        return $this->category;
    }

    public function amountCents(): int
    {
        return $this->amountCents;
    }

    public function referenceType(): ?string
    {
        return $this->referenceType;
    }

    public function referenceId(): ?int
    {
        return $this->referenceId;
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function systemUserId(): int
    {
        return $this->systemUserId;
    }

    public function createdAt(): ?DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * How the money moved (one of Payment::METHOD_*), or null when not
     * informed (manual entries may omit it; payable entries leave it null).
     * Independent of category(), which keeps its own meaning.
     */
    public function paymentMethod(): ?string
    {
        return $this->paymentMethod;
    }
}
