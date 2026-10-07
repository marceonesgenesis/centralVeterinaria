<?php

declare(strict_types=1);

namespace CentralVet\Domain;

use CentralVet\Domain\Exception\InvalidStatusTransitionException;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * A vendor/operational bill owed by the tenant at a system unit (domain
 * entity for the `payable` table). Created open by
 * CentralVet\Application\PayableService::create() and settled once by
 * ::pay(), which also writes the matching CentralVet\Domain\FinancialEntry
 * (entry_type='expense', reference_type='payable') — see that service's
 * docblock for the documented, accepted limitation around the two writes
 * not sharing a database transaction (same limitation already accepted for
 * CentralVet\Application\ProcedureExecutionService/SaleService in Phase 4,
 * since this project has no cross-service transaction primitive).
 *
 * PENDING / DO NOT WIRE YET: `payable` is created by the not-yet-applied
 * migration src/app/database/migrations/20260925_0006_phase5_financial.sql.
 * This class is prepared and syntax-checked (php -l) only; no query runs
 * against it until that migration has explicit SQL execution approval and
 * has actually been applied.
 */
final class Payable
{
    public const STATUS_OPEN = 'open';
    public const STATUS_PAID = 'paid';
    public const STATUS_CANCELLED = 'cancelled';

    private const STATUSES = [self::STATUS_OPEN, self::STATUS_PAID, self::STATUS_CANCELLED];

    private function __construct(
        private ?int $id,
        private readonly int $tenantId,
        private readonly int $systemUnitId,
        private string $descriptionText,
        private string $category,
        private int $amountCents,
        private ?DateTimeImmutable $dueDate,
        private string $status,
        private ?DateTimeImmutable $paidAt,
        private readonly int $systemUserId,
        private readonly ?DateTimeImmutable $createdAt = null,
        private readonly ?DateTimeImmutable $updatedAt = null,
    ) {
    }

    public static function create(
        int $tenantId,
        int $systemUnitId,
        string $descriptionText,
        string $category,
        int $amountCents,
        ?DateTimeImmutable $dueDate,
        int $systemUserId,
    ): self {
        if ($tenantId <= 0) {
            throw new InvalidArgumentException('Tenant id must be positive');
        }

        if ($systemUnitId <= 0) {
            throw new InvalidArgumentException('system_unit_id must be positive');
        }

        $descriptionText = trim($descriptionText);

        if ($descriptionText === '') {
            throw new InvalidArgumentException('description_text must not be empty');
        }

        $category = trim($category);

        if ($category === '') {
            throw new InvalidArgumentException('category must not be empty');
        }

        if ($amountCents < 1) {
            throw new InvalidArgumentException('amount_cents must be >= 1');
        }

        if ($systemUserId <= 0) {
            throw new InvalidArgumentException('system_user_id must be positive');
        }

        return new self(
            id: null,
            tenantId: $tenantId,
            systemUnitId: $systemUnitId,
            descriptionText: $descriptionText,
            category: $category,
            amountCents: $amountCents,
            dueDate: $dueDate,
            status: self::STATUS_OPEN,
            paidAt: null,
            systemUserId: $systemUserId,
        );
    }

    /**
     * Rebuilds an existing payable from persisted data. Repositories use
     * this to hydrate rows; application code should use create() instead.
     */
    public static function reconstitute(
        int $id,
        int $tenantId,
        int $systemUnitId,
        string $descriptionText,
        string $category,
        int $amountCents,
        ?DateTimeImmutable $dueDate,
        string $status,
        ?DateTimeImmutable $paidAt,
        int $systemUserId,
        ?DateTimeImmutable $createdAt,
        ?DateTimeImmutable $updatedAt,
    ): self {
        if (!in_array($status, self::STATUSES, true)) {
            throw new InvalidArgumentException('Invalid status');
        }

        return new self(
            $id,
            $tenantId,
            $systemUnitId,
            $descriptionText,
            $category,
            $amountCents,
            $dueDate,
            $status,
            $paidAt,
            $systemUserId,
            $createdAt,
            $updatedAt,
        );
    }

    /** Assigns the identifier generated on first persistence. */
    public function assignId(int $id): void
    {
        if ($this->id !== null) {
            throw new InvalidArgumentException('Payable already has an id');
        }

        if ($id <= 0) {
            throw new InvalidArgumentException('Id must be positive');
        }

        $this->id = $id;
    }

    /**
     * Settles the payable. Only legal from status='open' — throws
     * InvalidStatusTransitionException (already used elsewhere in this
     * codebase for the same "current status has no legal transition here"
     * rule, see that class's own docblock) and leaves both `status` and
     * `paid_at` untouched when it throws, so a payable already 'paid' or
     * 'cancelled' can never be paid a second time.
     */
    public function markPaid(DateTimeImmutable $paidAt): void
    {
        if ($this->status !== self::STATUS_OPEN) {
            throw new InvalidStatusTransitionException(
                "Payable {$this->id} cannot be paid from status '{$this->status}'"
            );
        }

        $this->status = self::STATUS_PAID;
        $this->paidAt = $paidAt;
    }

    /**
     * Edita os dados da conta (descrição, categoria, valor, vencimento).
     * Só é legal com status='open': conta paga/cancelada já gerou (ou não
     * gera mais) lançamento financeiro, então não muda.
     */
    public function changeDetails(string $descriptionText, string $category, int $amountCents, ?DateTimeImmutable $dueDate): void
    {
        if ($this->status !== self::STATUS_OPEN) {
            throw new InvalidStatusTransitionException(
                "Payable {$this->id} cannot be edited from status '{$this->status}'"
            );
        }

        $descriptionText = trim($descriptionText);

        if ($descriptionText === '') {
            throw new InvalidArgumentException('description_text must not be empty');
        }

        $category = trim($category);

        if ($category === '') {
            throw new InvalidArgumentException('category must not be empty');
        }

        if ($amountCents < 1) {
            throw new InvalidArgumentException('amount_cents must be >= 1');
        }

        $this->descriptionText = $descriptionText;
        $this->category = $category;
        $this->amountCents = $amountCents;
        $this->dueDate = $dueDate;
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

    public function descriptionText(): string
    {
        return $this->descriptionText;
    }

    public function category(): string
    {
        return $this->category;
    }

    public function amountCents(): int
    {
        return $this->amountCents;
    }

    public function dueDate(): ?DateTimeImmutable
    {
        return $this->dueDate;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function paidAt(): ?DateTimeImmutable
    {
        return $this->paidAt;
    }

    public function systemUserId(): int
    {
        return $this->systemUserId;
    }

    public function createdAt(): ?DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): ?DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
