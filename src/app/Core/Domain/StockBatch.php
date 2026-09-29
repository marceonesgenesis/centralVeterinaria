<?php

declare(strict_types=1);

namespace CentralVet\Domain;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * A physical lot of a product received at a system unit (domain entity for
 * the `stock_batch` table). `quantity` is the batch's current on-hand
 * balance, decremented in place by CentralVet\Application\StockService as it
 * consumes batches earliest-expiry-first (see decrease()); the application,
 * not this schema, keeps it consistent with the sum of the batch's
 * CentralVet\Domain\StockMovement rows (the migration's own documented
 * risk).
 *
 * PENDING / DO NOT WIRE YET: `stock_batch` is created by the not-yet-applied
 * migration
 * src/app/database/migrations/20260924_0005_phase4_procedure_stock_sale.sql.
 * This class is prepared and syntax-checked (php -l) only; no query runs
 * against it until that migration has explicit SQL execution approval and
 * has actually been applied.
 */
final class StockBatch
{
    private function __construct(
        private ?int $id,
        private readonly int $tenantId,
        private readonly int $systemUnitId,
        private readonly int $productId,
        private readonly ?string $lot,
        private readonly ?DateTimeImmutable $expiryDate,
        private int $quantity,
        private readonly DateTimeImmutable $receivedAt,
        private readonly ?DateTimeImmutable $createdAt = null,
    ) {
    }

    public static function receive(
        int $tenantId,
        int $systemUnitId,
        int $productId,
        ?string $lot,
        ?DateTimeImmutable $expiryDate,
        int $quantity,
        DateTimeImmutable $receivedAt,
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

        if ($quantity < 1) {
            throw new InvalidArgumentException('quantity must be >= 1');
        }

        $lot = $lot !== null ? trim($lot) : null;

        return new self(
            id: null,
            tenantId: $tenantId,
            systemUnitId: $systemUnitId,
            productId: $productId,
            lot: $lot !== null && $lot !== '' ? $lot : null,
            expiryDate: $expiryDate,
            quantity: $quantity,
            receivedAt: $receivedAt,
        );
    }

    /**
     * Rebuilds an existing batch from persisted data. Repositories use this
     * to hydrate rows; application code should use receive() instead.
     */
    public static function reconstitute(
        int $id,
        int $tenantId,
        int $systemUnitId,
        int $productId,
        ?string $lot,
        ?DateTimeImmutable $expiryDate,
        int $quantity,
        DateTimeImmutable $receivedAt,
        ?DateTimeImmutable $createdAt,
    ): self {
        return new self($id, $tenantId, $systemUnitId, $productId, $lot, $expiryDate, $quantity, $receivedAt, $createdAt);
    }

    /** Assigns the identifier generated on first persistence. */
    public function assignId(int $id): void
    {
        if ($this->id !== null) {
            throw new InvalidArgumentException('Stock batch already has an id');
        }

        if ($id <= 0) {
            throw new InvalidArgumentException('Id must be positive');
        }

        $this->id = $id;
    }

    /**
     * Decrements the batch's on-hand quantity by $amount. Called once per
     * affected batch by CentralVet\Application\StockService::consume(),
     * earliest expiry first, always with $amount <= $this->quantity() (the
     * caller only ever passes min($this->quantity(), $remaining)) — but the
     * floor is enforced here too, as the batch's own invariant, not just at
     * the call site.
     */
    public function decrease(int $amount): void
    {
        if ($amount < 1) {
            throw new InvalidArgumentException('amount must be >= 1');
        }

        if ($amount > $this->quantity) {
            throw new InvalidArgumentException('Cannot decrease a stock batch below zero');
        }

        $this->quantity -= $amount;
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

    public function lot(): ?string
    {
        return $this->lot;
    }

    public function expiryDate(): ?DateTimeImmutable
    {
        return $this->expiryDate;
    }

    public function quantity(): int
    {
        return $this->quantity;
    }

    public function receivedAt(): DateTimeImmutable
    {
        return $this->receivedAt;
    }

    public function createdAt(): ?DateTimeImmutable
    {
        return $this->createdAt;
    }
}
