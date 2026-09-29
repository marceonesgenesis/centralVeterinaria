<?php

declare(strict_types=1);

namespace CentralVet\Domain;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Tenant-scoped catalog entry for a vaccine (domain entity for the
 * `vaccine_catalog_item` table): name, manufacturer and a simple stock
 * counter — not a full inventory module (per this phase's plan, the
 * migration's own rationale and ADR-less convention already used for
 * `exam_catalog_item`).
 *
 * PENDING / DO NOT WIRE YET: `vaccine_catalog_item` is created by the
 * not-yet-applied migration
 * src/app/database/migrations/20260922_0004_phase3_prescription_exam_vaccine.sql.
 * This class is prepared and syntax-checked (php -l) only; no query runs
 * against it until that migration has explicit SQL execution approval and
 * has actually been applied.
 *
 * `stockQuantity` is application-managed (mirrors the migration comment: no
 * DB CHECK preventing it from going negative, since `stock_quantity` is an
 * unsigned column and the defensive floor belongs here, in
 * {@see self::decrementStock()}, not the schema).
 */
final class VaccineCatalogItem
{
    private function __construct(
        private ?int $id,
        private readonly int $tenantId,
        private string $name,
        private ?string $manufacturer,
        private int $stockQuantity,
        private bool $active,
        private readonly ?DateTimeImmutable $createdAt = null,
        private readonly ?DateTimeImmutable $updatedAt = null,
    ) {
    }

    public static function create(
        int $tenantId,
        string $name,
        ?string $manufacturer,
        int $stockQuantity,
    ): self {
        if ($tenantId <= 0) {
            throw new InvalidArgumentException('Tenant id must be positive');
        }

        if (trim($name) === '') {
            throw new InvalidArgumentException('name is required');
        }

        if ($stockQuantity < 0) {
            throw new InvalidArgumentException('stock_quantity cannot be negative');
        }

        return new self(
            id: null,
            tenantId: $tenantId,
            name: $name,
            manufacturer: $manufacturer !== null && trim($manufacturer) !== '' ? $manufacturer : null,
            stockQuantity: $stockQuantity,
            active: true,
        );
    }

    /**
     * Rebuilds an existing catalog item from persisted data. Repositories
     * use this to hydrate rows; application code should use create() instead.
     *
     * @param array<string, mixed> $row raw column values, keyed exactly like
     *        the `vaccine_catalog_item` table.
     */
    public static function reconstitute(array $row): self
    {
        return new self(
            id: (int) $row['id'],
            tenantId: (int) $row['tenant_id'],
            name: (string) $row['name'],
            manufacturer: $row['manufacturer'] !== null ? (string) $row['manufacturer'] : null,
            stockQuantity: (int) $row['stock_quantity'],
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
            throw new InvalidArgumentException('VaccineCatalogItem already has an id');
        }

        if ($id <= 0) {
            throw new InvalidArgumentException('Id must be positive');
        }

        $this->id = $id;
    }

    /**
     * Decrements the stock counter by one application of this vaccine.
     * Defensive floor: refuses to take the counter below zero (the
     * `vaccine_catalog_item.stock_quantity` column is unsigned and has no
     * DB-level CHECK against it, per the migration's own rationale, so this
     * guard is the only thing standing between a vaccination and a negative
     * count).
     *
     * @throws InvalidArgumentException when $by is not positive or would
     *         drive stock below zero.
     */
    public function decrementStock(int $by = 1): void
    {
        if ($by <= 0) {
            throw new InvalidArgumentException('Stock decrement must be positive');
        }

        if ($this->stockQuantity - $by < 0) {
            throw new InvalidArgumentException('Insufficient vaccine stock');
        }

        $this->stockQuantity -= $by;
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

    public function manufacturer(): ?string
    {
        return $this->manufacturer;
    }

    public function stockQuantity(): int
    {
        return $this->stockQuantity;
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
