<?php

declare(strict_types=1);

namespace CentralVet\Domain;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * One product consumed by a procedure catalog item's execution (domain
 * entity for the `procedure_catalog_item_input` table) — the procedure's
 * bill of materials (BOM). `quantityPerExecution` is how many units of
 * `productId` T-05's ProcedureExecutionService::execute() will ask
 * StockService::consume() to take, per execution, for this input.
 *
 * The `quantity_per_execution >= 1` rule is enforced here, in create(), as a
 * domain InvalidArgumentException — not a `TMessage` — so it fires (and
 * refuses to persist) regardless of caller (Application service or a future
 * direct repository use), mirroring the migration's own
 * `procedure_catalog_item_input_qty_ck` CHECK constraint at the DB layer.
 *
 * PENDING / DO NOT WIRE YET: `procedure_catalog_item_input` is created by
 * the not-yet-applied migration
 * src/app/database/migrations/20260924_0005_phase4_procedure_stock_sale.sql.
 * This class is prepared and syntax-checked (php -l) only; no query runs
 * against it until that migration has explicit SQL execution approval and
 * has actually been applied.
 */
final class ProcedureCatalogItemInput
{
    private function __construct(
        private ?int $id,
        private readonly int $tenantId,
        private readonly int $procedureCatalogItemId,
        private readonly int $productId,
        private int $quantityPerExecution,
        private readonly ?DateTimeImmutable $createdAt = null,
    ) {
    }

    public static function create(
        int $tenantId,
        int $procedureCatalogItemId,
        int $productId,
        int $quantityPerExecution,
    ): self {
        if ($tenantId <= 0) {
            throw new InvalidArgumentException('Tenant id must be positive');
        }

        if ($procedureCatalogItemId <= 0) {
            throw new InvalidArgumentException('procedure_catalog_item_id must be positive');
        }

        if ($productId <= 0) {
            throw new InvalidArgumentException('product_id must be positive');
        }

        if ($quantityPerExecution < 1) {
            throw new InvalidArgumentException('quantity_per_execution must be >= 1');
        }

        return new self(
            id: null,
            tenantId: $tenantId,
            procedureCatalogItemId: $procedureCatalogItemId,
            productId: $productId,
            quantityPerExecution: $quantityPerExecution,
        );
    }

    /**
     * Rebuilds an existing input from persisted data. Repositories use this
     * to hydrate rows; application code should use create() instead.
     *
     * @param array<string, mixed> $row raw column values, keyed exactly like
     *        the `procedure_catalog_item_input` table.
     */
    public static function reconstitute(array $row): self
    {
        return new self(
            id: (int) $row['id'],
            tenantId: (int) $row['tenant_id'],
            procedureCatalogItemId: (int) $row['procedure_catalog_item_id'],
            productId: (int) $row['product_id'],
            quantityPerExecution: (int) $row['quantity_per_execution'],
            createdAt: isset($row['created_at']) && $row['created_at'] !== null
                ? new DateTimeImmutable((string) $row['created_at'])
                : null,
        );
    }

    /** Assigns the identifier generated on first persistence. */
    public function assignId(int $id): void
    {
        if ($this->id !== null) {
            throw new InvalidArgumentException('ProcedureCatalogItemInput already has an id');
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

    public function procedureCatalogItemId(): int
    {
        return $this->procedureCatalogItemId;
    }

    public function productId(): int
    {
        return $this->productId;
    }

    public function quantityPerExecution(): int
    {
        return $this->quantityPerExecution;
    }

    public function createdAt(): ?DateTimeImmutable
    {
        return $this->createdAt;
    }
}
