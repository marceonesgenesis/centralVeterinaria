<?php

declare(strict_types=1);

namespace CentralVet\Domain;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * A single application of a vaccine catalog item to a patient inside an
 * encounter (domain entity for the `vaccination` table): lot, expiry, dose
 * number and the computed next dose date.
 *
 * PENDING / DO NOT WIRE YET: `vaccination` is created by the not-yet-applied
 * migration
 * src/app/database/migrations/20260922_0004_phase3_prescription_exam_vaccine.sql.
 * This class is prepared and syntax-checked (php -l) only; no query runs
 * against it until that migration has explicit SQL execution approval and
 * has actually been applied.
 *
 * `nextDoseAt` is computed once, by `VaccinationService::apply()`, from the
 * `VaccineProtocol` row (if any) matching `dose_number + 1` for the same
 * vaccine_catalog_item_id, and stored here at creation time — this entity
 * itself does not know about VaccineProtocol (that lookup is an Application
 * concern, mirroring how Encounter does not know about Appointment).
 */
final class Vaccination
{
    private function __construct(
        private ?int $id,
        private readonly int $tenantId,
        private readonly int $encounterId,
        private readonly int $patientId,
        private readonly int $vaccineCatalogItemId,
        private readonly ?string $lot,
        private readonly ?DateTimeImmutable $expiryDate,
        private readonly int $doseNumber,
        private readonly int $professionalSystemUserId,
        private readonly DateTimeImmutable $appliedAt,
        private readonly ?DateTimeImmutable $nextDoseAt,
        private readonly ?DateTimeImmutable $createdAt = null,
    ) {
    }

    public static function record(
        int $tenantId,
        int $encounterId,
        int $patientId,
        int $vaccineCatalogItemId,
        ?string $lot,
        ?DateTimeImmutable $expiryDate,
        int $doseNumber,
        int $professionalSystemUserId,
        DateTimeImmutable $appliedAt,
        ?DateTimeImmutable $nextDoseAt,
    ): self {
        if ($tenantId <= 0) {
            throw new InvalidArgumentException('Tenant id must be positive');
        }

        if ($encounterId <= 0) {
            throw new InvalidArgumentException('encounter_id must be positive');
        }

        if ($patientId <= 0) {
            throw new InvalidArgumentException('patient_id must be positive');
        }

        if ($vaccineCatalogItemId <= 0) {
            throw new InvalidArgumentException('vaccine_catalog_item_id must be positive');
        }

        if ($doseNumber < 1) {
            throw new InvalidArgumentException('dose_number must be >= 1');
        }

        if ($professionalSystemUserId <= 0) {
            throw new InvalidArgumentException('professional_system_user_id must be positive');
        }

        return new self(
            id: null,
            tenantId: $tenantId,
            encounterId: $encounterId,
            patientId: $patientId,
            vaccineCatalogItemId: $vaccineCatalogItemId,
            lot: $lot !== null && trim($lot) !== '' ? $lot : null,
            expiryDate: $expiryDate,
            doseNumber: $doseNumber,
            professionalSystemUserId: $professionalSystemUserId,
            appliedAt: $appliedAt,
            nextDoseAt: $nextDoseAt,
        );
    }

    /**
     * Rebuilds an existing vaccination from persisted data. Repositories use
     * this to hydrate rows; application code should use record() instead.
     *
     * @param array<string, mixed> $row raw column values, keyed exactly like
     *        the `vaccination` table.
     */
    public static function reconstitute(array $row): self
    {
        return new self(
            id: (int) $row['id'],
            tenantId: (int) $row['tenant_id'],
            encounterId: (int) $row['encounter_id'],
            patientId: (int) $row['patient_id'],
            vaccineCatalogItemId: (int) $row['vaccine_catalog_item_id'],
            lot: $row['lot'] !== null ? (string) $row['lot'] : null,
            expiryDate: $row['expiry_date'] !== null ? new DateTimeImmutable((string) $row['expiry_date']) : null,
            doseNumber: (int) $row['dose_number'],
            professionalSystemUserId: (int) $row['professional_system_user_id'],
            appliedAt: new DateTimeImmutable((string) $row['applied_at']),
            nextDoseAt: $row['next_dose_at'] !== null ? new DateTimeImmutable((string) $row['next_dose_at']) : null,
            createdAt: isset($row['created_at']) && $row['created_at'] !== null
                ? new DateTimeImmutable((string) $row['created_at'])
                : null,
        );
    }

    /** Assigns the identifier generated on first persistence. */
    public function assignId(int $id): void
    {
        if ($this->id !== null) {
            throw new InvalidArgumentException('Vaccination already has an id');
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

    public function encounterId(): int
    {
        return $this->encounterId;
    }

    public function patientId(): int
    {
        return $this->patientId;
    }

    public function vaccineCatalogItemId(): int
    {
        return $this->vaccineCatalogItemId;
    }

    public function lot(): ?string
    {
        return $this->lot;
    }

    public function expiryDate(): ?DateTimeImmutable
    {
        return $this->expiryDate;
    }

    public function doseNumber(): int
    {
        return $this->doseNumber;
    }

    public function professionalSystemUserId(): int
    {
        return $this->professionalSystemUserId;
    }

    public function appliedAt(): DateTimeImmutable
    {
        return $this->appliedAt;
    }

    public function nextDoseAt(): ?DateTimeImmutable
    {
        return $this->nextDoseAt;
    }

    public function createdAt(): ?DateTimeImmutable
    {
        return $this->createdAt;
    }
}
