<?php

declare(strict_types=1);

namespace CentralVet\Domain;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Tenant-scoped catalog entry for a billable/executable procedure (domain
 * entity for the `procedure_catalog_item` table): name, price and an
 * optional duration/preparation note. Its list of consumed products (the
 * BOM) is a separate aggregate, {@see ProcedureCatalogItemInput}.
 *
 * PENDING / DO NOT WIRE YET: `procedure_catalog_item` is created by the
 * not-yet-applied migration
 * src/app/database/migrations/20260924_0005_phase4_procedure_stock_sale.sql.
 * This class is prepared and syntax-checked (php -l) only; no query runs
 * against it until that migration has explicit SQL execution approval and
 * has actually been applied.
 */
final class ProcedureCatalogItem
{
    private function __construct(
        private ?int $id,
        private readonly int $tenantId,
        private string $name,
        private int $priceCents,
        private ?int $durationMinutes,
        private ?string $preparationText,
        private bool $active,
        private readonly ?DateTimeImmutable $createdAt = null,
        private readonly ?DateTimeImmutable $updatedAt = null,
    ) {
    }

    public static function create(
        int $tenantId,
        string $name,
        int $priceCents,
        ?int $durationMinutes,
        ?string $preparationText,
    ): self {
        if ($tenantId <= 0) {
            throw new InvalidArgumentException('Tenant id must be positive');
        }

        if (trim($name) === '') {
            throw new InvalidArgumentException('name is required');
        }

        if ($priceCents < 0) {
            throw new InvalidArgumentException('price_cents cannot be negative');
        }

        if ($durationMinutes !== null && $durationMinutes < 1) {
            throw new InvalidArgumentException('duration_minutes must be >= 1 when informed');
        }

        return new self(
            id: null,
            tenantId: $tenantId,
            name: $name,
            priceCents: $priceCents,
            durationMinutes: $durationMinutes,
            preparationText: $preparationText !== null && trim($preparationText) !== '' ? $preparationText : null,
            active: true,
        );
    }

    /**
     * Rebuilds an existing catalog item from persisted data. Repositories
     * use this to hydrate rows; application code should use create() instead.
     *
     * @param array<string, mixed> $row raw column values, keyed exactly like
     *        the `procedure_catalog_item` table.
     */
    public static function reconstitute(array $row): self
    {
        return new self(
            id: (int) $row['id'],
            tenantId: (int) $row['tenant_id'],
            name: (string) $row['name'],
            priceCents: (int) $row['price_cents'],
            durationMinutes: $row['duration_minutes'] !== null ? (int) $row['duration_minutes'] : null,
            preparationText: $row['preparation_text'] !== null ? (string) $row['preparation_text'] : null,
            active: (bool) $row['active'],
            createdAt: isset($row['created_at']) && $row['created_at'] !== null
                ? new DateTimeImmutable((string) $row['created_at'])
                : null,
            updatedAt: isset($row['updated_at']) && $row['updated_at'] !== null
                ? new DateTimeImmutable((string) $row['updated_at'])
                : null,
        );
    }

    /** Assigns the identifier generated on first persistence. */
    public function assignId(int $id): void
    {
        if ($this->id !== null) {
            throw new InvalidArgumentException('ProcedureCatalogItem already has an id');
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

    public function priceCents(): int
    {
        return $this->priceCents;
    }

    public function durationMinutes(): ?int
    {
        return $this->durationMinutes;
    }

    public function preparationText(): ?string
    {
        return $this->preparationText;
    }

    public function active(): bool
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
}
