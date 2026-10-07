<?php

declare(strict_types=1);

namespace CentralVet\Domain;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * An append-only ledger row for one stock movement (domain entity for the
 * `stock_movement` table), written once by
 * CentralVet\Application\StockService for every stock_batch affected by a
 * receiveBatch()/consume() call — never updated after creation.
 *
 * PENDING / DO NOT WIRE YET: `stock_movement` is created by the
 * not-yet-applied migration
 * src/app/database/migrations/20260924_0005_phase4_procedure_stock_sale.sql.
 * This class is prepared and syntax-checked (php -l) only; no query runs
 * against it until that migration has explicit SQL execution approval and
 * has actually been applied.
 */
final class StockMovement
{
    public const TYPE_IN = 'in';
    public const TYPE_OUT = 'out';
    public const TYPE_ADJUSTMENT = 'adjustment';

    private const TYPES = [self::TYPE_IN, self::TYPE_OUT, self::TYPE_ADJUSTMENT];

    public const REASON_PURCHASE_ENTRY = 'purchase_entry';
    public const REASON_PROCEDURE_CONSUMPTION = 'procedure_consumption';
    public const REASON_SALE_CONSUMPTION = 'sale_consumption';
    public const REASON_MANUAL_ADJUSTMENT = 'manual_adjustment';
    public const REASON_HOSPITALIZATION_CONSUMPTION = 'hospitalization_consumption';
    public const REASON_SURGERY_CONSUMPTION = 'surgery_consumption';

    private const REASONS = [
        self::REASON_PURCHASE_ENTRY,
        self::REASON_PROCEDURE_CONSUMPTION,
        self::REASON_SALE_CONSUMPTION,
        self::REASON_MANUAL_ADJUSTMENT,
        self::REASON_HOSPITALIZATION_CONSUMPTION,
        self::REASON_SURGERY_CONSUMPTION,
    ];

    private function __construct(
        private ?int $id,
        private readonly int $tenantId,
        private readonly int $systemUnitId,
        private readonly int $productId,
        private readonly int $stockBatchId,
        private readonly string $movementType,
        private readonly int $quantity,
        private readonly string $reason,
        private readonly ?string $referenceType,
        private readonly ?int $referenceId,
        private readonly int $professionalSystemUserId,
        private readonly ?DateTimeImmutable $createdAt = null,
    ) {
    }

    public static function record(
        int $tenantId,
        int $systemUnitId,
        int $productId,
        int $stockBatchId,
        string $movementType,
        int $quantity,
        string $reason,
        ?string $referenceType,
        ?int $referenceId,
        int $professionalSystemUserId,
    ): self {
        if ($tenantId <= 0) {
            throw new InvalidArgumentException('Tenant id must be positive');
        }

        if ($systemUnitId <= 0) {
            throw new InvalidArgumentException('system_unit_id must be positive');
        }

        if ($productId <= 0) {
            throw new InvalidArgumentException('product_id must be positive');
        }

        if ($stockBatchId <= 0) {
            throw new InvalidArgumentException('stock_batch_id must be positive');
        }

        if (!in_array($movementType, self::TYPES, true)) {
            throw new InvalidArgumentException('Invalid movement_type');
        }

        if ($quantity < 1) {
            throw new InvalidArgumentException('quantity must be >= 1');
        }

        if (!in_array($reason, self::REASONS, true)) {
            throw new InvalidArgumentException('Invalid reason');
        }

        if ($professionalSystemUserId <= 0) {
            throw new InvalidArgumentException('professional_system_user_id must be positive');
        }

        return new self(
            id: null,
            tenantId: $tenantId,
            systemUnitId: $systemUnitId,
            productId: $productId,
            stockBatchId: $stockBatchId,
            movementType: $movementType,
            quantity: $quantity,
            reason: $reason,
            referenceType: $referenceType,
            referenceId: $referenceId,
            professionalSystemUserId: $professionalSystemUserId,
        );
    }

    /**
     * Rebuilds an existing movement from persisted data. Repositories use
     * this to hydrate rows; application code should use record() instead.
     */
    public static function reconstitute(
        int $id,
        int $tenantId,
        int $systemUnitId,
        int $productId,
        int $stockBatchId,
        string $movementType,
        int $quantity,
        string $reason,
        ?string $referenceType,
        ?int $referenceId,
        int $professionalSystemUserId,
        ?DateTimeImmutable $createdAt,
    ): self {
        return new self(
            $id,
            $tenantId,
            $systemUnitId,
            $productId,
            $stockBatchId,
            $movementType,
            $quantity,
            $reason,
            $referenceType,
            $referenceId,
            $professionalSystemUserId,
            $createdAt,
        );
    }

    /** Assigns the identifier generated on first persistence. */
    public function assignId(int $id): void
    {
        if ($this->id !== null) {
            throw new InvalidArgumentException('Stock movement already has an id');
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

    public function productId(): int
    {
        return $this->productId;
    }

    public function stockBatchId(): int
    {
        return $this->stockBatchId;
    }

    public function movementType(): string
    {
        return $this->movementType;
    }

    public function quantity(): int
    {
        return $this->quantity;
    }

    public function reason(): string
    {
        return $this->reason;
    }

    public function referenceType(): ?string
    {
        return $this->referenceType;
    }

    public function referenceId(): ?int
    {
        return $this->referenceId;
    }

    public function professionalSystemUserId(): int
    {
        return $this->professionalSystemUserId;
    }

    public function createdAt(): ?DateTimeImmutable
    {
        return $this->createdAt;
    }
}
