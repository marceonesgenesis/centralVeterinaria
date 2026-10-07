<?php

declare(strict_types=1);

namespace CentralVet\Domain;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Tenant-scoped catalog entry for a sellable/consumable item (domain entity
 * for the `product` table): a product tracked by physical lots
 * (CentralVet\Domain\StockBatch), consumed either directly in a sale
 * (Phase 4, T-06) or as an input of a procedure execution (Phase 4, T-05).
 *
 * Same mutable-id / assignId() shape as CentralVet\Domain\Service and
 * CentralVet\Domain\VaccineCatalogItem (create() for a brand-new instance,
 * reconstitute() for repositories hydrating a row).
 *
 * PENDING / DO NOT WIRE YET: `product` is created by the not-yet-applied
 * migration
 * src/app/database/migrations/20260924_0005_phase4_procedure_stock_sale.sql.
 * This class is prepared and syntax-checked (php -l) only; no query runs
 * against it until that migration has explicit SQL execution approval and
 * has actually been applied.
 */
final class Product
{
    private function __construct(
        private ?int $id,
        private readonly int $tenantId,
        private string $name,
        private ?string $category,
        private string $unitOfMeasure,
        private int $unitCostCents,
        private int $minimumStockQuantity,
        private bool $active,
        private readonly ?DateTimeImmutable $createdAt = null,
        private readonly ?DateTimeImmutable $updatedAt = null,
        private ?int $salePriceCents = null,
        private ?string $code = null,
    ) {
    }

    public static function create(
        int $tenantId,
        string $name,
        ?string $category,
        string $unitOfMeasure,
        int $unitCostCents,
        int $minimumStockQuantity,
        ?int $salePriceCents = null,
        ?string $code = null,
    ): self {
        $name = trim($name);
        $category = $category !== null ? trim($category) : null;
        $unitOfMeasure = trim($unitOfMeasure);

        if ($tenantId <= 0) {
            throw new InvalidArgumentException('Tenant id must be positive');
        }

        if ($name === '') {
            throw new InvalidArgumentException('Product name is required');
        }

        if ($unitOfMeasure === '') {
            throw new InvalidArgumentException('unit_of_measure is required');
        }

        if ($unitCostCents < 0) {
            throw new InvalidArgumentException('unit_cost_cents cannot be negative');
        }

        if ($minimumStockQuantity < 0) {
            throw new InvalidArgumentException('minimum_stock_quantity cannot be negative');
        }

        if ($salePriceCents !== null && $salePriceCents < 0) {
            throw new InvalidArgumentException('sale_price_cents cannot be negative');
        }

        $code = self::normalizeCode($code);

        return new self(
            id: null,
            tenantId: $tenantId,
            name: $name,
            category: $category !== null && $category !== '' ? $category : null,
            unitOfMeasure: $unitOfMeasure,
            unitCostCents: $unitCostCents,
            minimumStockQuantity: $minimumStockQuantity,
            active: true,
            salePriceCents: $salePriceCents,
            code: $code,
        );
    }

    /**
     * Rebuilds an existing product from persisted data. Repositories use
     * this to hydrate rows; application code should use create() instead.
     */
    public static function reconstitute(
        int $id,
        int $tenantId,
        string $name,
        ?string $category,
        string $unitOfMeasure,
        int $unitCostCents,
        int $minimumStockQuantity,
        bool $active,
        ?DateTimeImmutable $createdAt,
        ?DateTimeImmutable $updatedAt,
        ?int $salePriceCents = null,
        ?string $code = null,
    ): self {
        return new self(
            $id,
            $tenantId,
            $name,
            $category,
            $unitOfMeasure,
            $unitCostCents,
            $minimumStockQuantity,
            $active,
            $createdAt,
            $updatedAt,
            $salePriceCents,
            $code,
        );
    }

    /**
     * Trims the product code; blank becomes null; at most 60 characters
     * (product.code is varchar(60), migration 0007).
     */
    private static function normalizeCode(?string $code): ?string
    {
        if ($code === null) {
            return null;
        }

        $code = trim($code);

        if ($code === '') {
            return null;
        }

        if (mb_strlen($code) > 60) {
            throw new InvalidArgumentException('code must have at most 60 characters');
        }

        return $code;
    }

    /** Assigns the identifier generated on first persistence. */
    public function assignId(int $id): void
    {
        if ($this->id !== null) {
            throw new InvalidArgumentException('Product already has an id');
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

    public function name(): string
    {
        return $this->name;
    }

    public function category(): ?string
    {
        return $this->category;
    }

    public function unitOfMeasure(): string
    {
        return $this->unitOfMeasure;
    }

    public function unitCostCents(): int
    {
        return $this->unitCostCents;
    }

    /** Suggested sale price in cents, or null when not informed. */
    public function salePriceCents(): ?int
    {
        return $this->salePriceCents;
    }

    /** Tenant-unique product code (SKU), or null when not informed. */
    public function code(): ?string
    {
        return $this->code;
    }

    public function minimumStockQuantity(): int
    {
        return $this->minimumStockQuantity;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function createdAt(): ?DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): ?DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function activate(): void
    {
        $this->active = true;
    }

    public function deactivate(): void
    {
        $this->active = false;
    }
}
