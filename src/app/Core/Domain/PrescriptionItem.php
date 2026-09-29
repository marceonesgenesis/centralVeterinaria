<?php

declare(strict_types=1);

namespace CentralVet\Domain;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Single medication line of a Prescription (child row of `prescription_item`,
 * free text only — no medication catalog in this phase, per the migration's
 * own docblock).
 *
 * Always owned by exactly one Prescription: it never exists on its own, so
 * it has no Repository/Service of its own and is only ever created, saved
 * and loaded through the Prescription aggregate (PrescriptionRepository).
 * `prescriptionId` is null until the parent Prescription itself has been
 * persisted, exactly like `Encounter::appointmentId` mirrors an
 * already-persisted foreign key while `id` mirrors a not-yet-assigned one.
 *
 * PENDING / DO NOT WIRE YET: `prescription_item` is created by the
 * not-yet-applied migration
 * src/app/database/migrations/20260922_0004_phase3_prescription_exam_vaccine.sql.
 * This class is prepared and syntax-checked (php -l) only; no query runs
 * against it until that migration has explicit SQL execution approval and
 * has actually been applied.
 *
 * No Adianti dependency (ADR 0001).
 */
final class PrescriptionItem
{
    private function __construct(
        private ?int $id,
        private readonly int $tenantId,
        private ?int $prescriptionId,
        private readonly string $medicationName,
        private readonly string $dose,
        private readonly string $doseUnit,
        private readonly string $route,
        private readonly string $frequency,
        private readonly string $duration,
        private readonly ?DateTimeImmutable $createdAt = null,
    ) {
    }

    /**
     * Builds a new, not-yet-persisted item for a Prescription that is itself
     * still being assembled (prescriptionId is filled in once the parent is
     * saved, via {@see self::assignPrescriptionId()}).
     */
    public static function create(
        int $tenantId,
        string $medicationName,
        string $dose,
        string $doseUnit,
        string $route,
        string $frequency,
        string $duration,
    ): self {
        if ($tenantId <= 0) {
            throw new InvalidArgumentException('Tenant id must be positive');
        }

        foreach ([
            'medication_name' => $medicationName,
            'dose' => $dose,
            'dose_unit' => $doseUnit,
            'route' => $route,
            'frequency' => $frequency,
            'duration' => $duration,
        ] as $field => $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException("{$field} must not be blank");
            }
        }

        return new self(
            id: null,
            tenantId: $tenantId,
            prescriptionId: null,
            medicationName: $medicationName,
            dose: $dose,
            doseUnit: $doseUnit,
            route: $route,
            frequency: $frequency,
            duration: $duration,
        );
    }

    /**
     * Rebuilds an existing item from persisted data. Repositories use this to
     * hydrate rows; application code should use create() instead.
     *
     * @param array<string, mixed> $row raw column values, keyed exactly like
     *        the `prescription_item` table.
     */
    public static function reconstitute(array $row): self
    {
        return new self(
            id: (int) $row['id'],
            tenantId: (int) $row['tenant_id'],
            prescriptionId: (int) $row['prescription_id'],
            medicationName: (string) $row['medication_name'],
            dose: (string) $row['dose'],
            doseUnit: (string) $row['dose_unit'],
            route: (string) $row['route'],
            frequency: (string) $row['frequency'],
            duration: (string) $row['duration'],
            createdAt: isset($row['created_at']) && $row['created_at'] !== null
                ? new DateTimeImmutable((string) $row['created_at'])
                : null,
        );
    }

    /** Assigns the identifier generated on first persistence. */
    public function assignId(int $id): void
    {
        if ($this->id !== null) {
            throw new InvalidArgumentException('PrescriptionItem already has an id');
        }

        if ($id <= 0) {
            throw new InvalidArgumentException('Id must be positive');
        }

        $this->id = $id;
    }

    /** Assigns the owning prescription's identifier once it has been persisted. */
    public function assignPrescriptionId(int $prescriptionId): void
    {
        if ($this->prescriptionId !== null) {
            throw new InvalidArgumentException('PrescriptionItem already belongs to a prescription');
        }

        if ($prescriptionId <= 0) {
            throw new InvalidArgumentException('prescription_id must be positive');
        }

        $this->prescriptionId = $prescriptionId;
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function tenantId(): int
    {
        return $this->tenantId;
    }

    public function prescriptionId(): ?int
    {
        return $this->prescriptionId;
    }

    public function medicationName(): string
    {
        return $this->medicationName;
    }

    public function dose(): string
    {
        return $this->dose;
    }

    public function doseUnit(): string
    {
        return $this->doseUnit;
    }

    public function route(): string
    {
        return $this->route;
    }

    public function frequency(): string
    {
        return $this->frequency;
    }

    public function duration(): string
    {
        return $this->duration;
    }

    public function createdAt(): ?DateTimeImmutable
    {
        return $this->createdAt;
    }
}
