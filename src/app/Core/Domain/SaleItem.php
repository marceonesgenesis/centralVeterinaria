<?php

declare(strict_types=1);

namespace CentralVet\Domain;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * One line item of a {@see Sale} (domain entity for the `sale_item` table):
 * either a `product` (item_reference_id = product.id, consumed from stock by
 * CentralVet\Application\StockService::consume()) or a `procedure`
 * (item_reference_id = procedure_catalog_item.id, stock untouched). There is
 * intentionally no database FK on item_reference_id (documented in the T-01
 * migration as a simplification — the two catalogs it can point to cannot
 * both be enforced by a single FK), so item_type + item_reference_id are the
 * only link back to the priced catalog entry.
 *
 * description_text and unit_price_cents are captured at sale time (copied
 * from the referenced Product::name()/unitCostCents() or
 * ProcedureCatalogItem::name()/priceCents() by SaleService::create()), not
 * looked up live — so a sale's receipt still reads correctly even if the
 * catalog entry is later renamed, re-priced or deactivated.
 *
 * total_cents is always unit_price_cents * quantity, computed once here in
 * create() and never independently settable, so it can never drift from
 * that product — which is what lets
 * CentralVet\Application\SaleService::create() sum every item's
 * totalCents() into Sale::totalAmountCents() with an exact match (T-06's
 * acceptance criterion).
 *
 * PENDING / DO NOT WIRE YET: `sale_item` is created by the not-yet-applied
 * migration
 * src/app/database/migrations/20260924_0005_phase4_procedure_stock_sale.sql.
 * This class is prepared and syntax-checked (php -l) only; no query runs
 * against it until that migration has explicit SQL execution approval and
 * has actually been applied.
 */
final class SaleItem
{
    public const TYPE_PRODUCT = 'product';
    public const TYPE_PROCEDURE = 'procedure';

    private const TYPES = [self::TYPE_PRODUCT, self::TYPE_PROCEDURE];

    private function __construct(
        private ?int $id,
        private readonly int $tenantId,
        private readonly int $saleId,
        private readonly string $itemType,
        private readonly int $itemReferenceId,
        private readonly string $descriptionText,
        private readonly int $unitPriceCents,
        private readonly int $quantity,
        private readonly int $totalCents,
        private readonly ?DateTimeImmutable $createdAt = null,
    ) {
    }

    public static function create(
        int $tenantId,
        int $saleId,
        string $itemType,
        int $itemReferenceId,
        string $descriptionText,
        int $unitPriceCents,
        int $quantity,
    ): self {
        if ($tenantId <= 0) {
            throw new InvalidArgumentException('Tenant id must be positive');
        }

        if ($saleId <= 0) {
            throw new InvalidArgumentException('sale_id must be positive');
        }

        if (!in_array($itemType, self::TYPES, true)) {
            throw new InvalidArgumentException("item_type must be 'product' or 'procedure'");
        }

        if ($itemReferenceId <= 0) {
            throw new InvalidArgumentException('item_reference_id must be positive');
        }

        $descriptionText = trim($descriptionText);

        if ($descriptionText === '') {
            throw new InvalidArgumentException('description_text is required');
        }

        if ($unitPriceCents < 0) {
            throw new InvalidArgumentException('unit_price_cents cannot be negative');
        }

        if ($quantity < 1) {
            throw new InvalidArgumentException('quantity must be >= 1');
        }

        return new self(
            id: null,
            tenantId: $tenantId,
            saleId: $saleId,
            itemType: $itemType,
            itemReferenceId: $itemReferenceId,
            descriptionText: $descriptionText,
            unitPriceCents: $unitPriceCents,
            quantity: $quantity,
            totalCents: $unitPriceCents * $quantity,
        );
    }

    /**
     * Rebuilds an existing sale item from persisted data. Repositories use
     * this to hydrate rows; application code should use create() instead.
     */
    public static function reconstitute(
        int $id,
        int $tenantId,
        int $saleId,
        string $itemType,
        int $itemReferenceId,
        string $descriptionText,
        int $unitPriceCents,
        int $quantity,
        int $totalCents,
        ?DateTimeImmutable $createdAt,
    ): self {
        if (!in_array($itemType, self::TYPES, true)) {
            throw new InvalidArgumentException("item_type must be 'product' or 'procedure'");
        }

        return new self(
            $id,
            $tenantId,
            $saleId,
            $itemType,
            $itemReferenceId,
            $descriptionText,
            $unitPriceCents,
            $quantity,
            $totalCents,
            $createdAt,
        );
    }

    /** Assigns the identifier generated on first persistence. */
    public function assignId(int $id): void
    {
        if ($this->id !== null) {
            throw new InvalidArgumentException('SaleItem already has an id');
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

    public function saleId(): int
    {
        return $this->saleId;
    }

    public function itemType(): string
    {
        return $this->itemType;
    }

    public function itemReferenceId(): int
    {
        return $this->itemReferenceId;
    }

    public function descriptionText(): string
    {
        return $this->descriptionText;
    }

    public function unitPriceCents(): int
    {
        return $this->unitPriceCents;
    }

    public function quantity(): int
    {
        return $this->quantity;
    }

    public function totalCents(): int
    {
        return $this->totalCents;
    }

    public function createdAt(): ?DateTimeImmutable
    {
        return $this->createdAt;
    }
}
