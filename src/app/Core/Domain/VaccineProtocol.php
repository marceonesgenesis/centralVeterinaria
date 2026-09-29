<?php

declare(strict_types=1);

namespace CentralVet\Domain;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Configurable dose schedule entry of a vaccine catalog item (domain entity
 * for the `vaccine_protocol` table): one row per dose number, with an
 * optional interval (in days) counted from the previous dose.
 *
 * PENDING / DO NOT WIRE YET: `vaccine_protocol` is created by the
 * not-yet-applied migration
 * src/app/database/migrations/20260922_0004_phase3_prescription_exam_vaccine.sql.
 * This class is prepared and syntax-checked (php -l) only; no query runs
 * against it until that migration has explicit SQL execution approval and
 * has actually been applied.
 *
 * `intervalDaysFromPrevious` is nullable by design (mirrors the migration
 * column): a protocol entry with no interval configured means "this dose
 * number exists but has no fixed schedule", which
 * `VaccinationService::apply()` treats as "no next_dose_at can be computed".
 */
final class VaccineProtocol
{
    private function __construct(
        private ?int $id,
        private readonly int $tenantId,
        private readonly int $vaccineCatalogItemId,
        private readonly int $doseNumber,
        private readonly ?int $intervalDaysFromPrevious,
        private readonly ?DateTimeImmutable $createdAt = null,
    ) {
    }

    public static function create(
        int $tenantId,
        int $vaccineCatalogItemId,
        int $doseNumber,
        ?int $intervalDaysFromPrevious,
    ): self {
        if ($tenantId <= 0) {
            throw new InvalidArgumentException('Tenant id must be positive');
        }

        if ($vaccineCatalogItemId <= 0) {
            throw new InvalidArgumentException('vaccine_catalog_item_id must be positive');
        }

        if ($doseNumber < 1) {
            throw new InvalidArgumentException('dose_number must be >= 1');
        }

        if ($intervalDaysFromPrevious !== null && $intervalDaysFromPrevious < 0) {
            throw new InvalidArgumentException('interval_days_from_previous cannot be negative');
        }

        return new self(
            id: null,
            tenantId: $tenantId,
            vaccineCatalogItemId: $vaccineCatalogItemId,
            doseNumber: $doseNumber,
            intervalDaysFromPrevious: $intervalDaysFromPrevious,
        );
    }

    /**
     * Rebuilds an existing protocol entry from persisted data. Repositories
     * use this to hydrate rows; application code should use create() instead.
     *
     * @param array<string, mixed> $row raw column values, keyed exactly like
     *        the `vaccine_protocol` table.
     */
    public static function reconstitute(array $row): self
    {
        return new self(
            id: (int) $row['id'],
            tenantId: (int) $row['tenant_id'],
            vaccineCatalogItemId: (int) $row['vaccine_catalog_item_id'],
            doseNumber: (int) $row['dose_number'],
            intervalDaysFromPrevious: $row['interval_days_from_previous'] !== null
                ? (int) $row['interval_days_from_previous']
                : null,
            createdAt: isset($row['created_at']) && $row['created_at'] !== null
                ? new DateTimeImmutable((string) $row['created_at'])
                : null,
        );
    }

    /** Assigns the identifier generated on first persistence. */
    public function assignId(int $id): void
    {
        if ($this->id !== null) {
            throw new InvalidArgumentException('VaccineProtocol already has an id');
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

    public function vaccineCatalogItemId(): int
    {
        return $this->vaccineCatalogItemId;
    }

    public function doseNumber(): int
    {
        return $this->doseNumber;
    }

    public function intervalDaysFromPrevious(): ?int
    {
        return $this->intervalDaysFromPrevious;
    }

    public function createdAt(): ?DateTimeImmutable
    {
        return $this->createdAt;
    }
}
