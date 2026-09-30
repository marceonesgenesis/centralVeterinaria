<?php

declare(strict_types=1);

namespace CentralVet\Domain;

/**
 * Domain entity for a clinic patient (animal) owned by a Tutor, scoped to a
 * tenant. Maps 1:1 to the `patient` table.
 *
 * PENDING / DO NOT WIRE YET: `patient` is created by the not-yet-applied
 * migration src/app/database/migrations/20260921_0002_phase1_clinic_core.sql
 * (ADR 0003). This class is prepared and syntax-checked (php -l) only; no
 * query runs against it until that migration has explicit SQL execution
 * approval and has actually been applied.
 *
 * Plain PHP value object: no Adianti dependency (ADR 0001).
 */
final class Patient
{
    public function __construct(
        public readonly ?int $id,
        public readonly int $tenantId,
        public readonly int $tutorId,
        public readonly string $name,
        public readonly string $species,
        public readonly ?string $breed = null,
        public readonly ?string $sex = null,
        public readonly ?string $birthDate = null,
        public readonly ?float $weightKg = null,
        public readonly ?string $color = null,
        public readonly ?string $notes = null,
        public readonly ?string $createdAt = null,
        public readonly ?string $updatedAt = null,
        public readonly ?string $allergies = null,
        public readonly ?string $photoObjectKey = null,
        public readonly ?string $photoContentType = null,
    ) {
        if ($this->sex !== null && !in_array($this->sex, ['M', 'F', 'U'], true)) {
            throw new \InvalidArgumentException('Patient sex must be one of M, F, U');
        }

        if ($this->weightKg !== null && $this->weightKg < 0) {
            throw new \InvalidArgumentException('Patient weight_kg cannot be negative');
        }
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            id: (int) $row['id'],
            tenantId: (int) $row['tenant_id'],
            tutorId: (int) $row['tutor_id'],
            name: (string) $row['name'],
            species: (string) $row['species'],
            breed: $row['breed'] !== null ? (string) $row['breed'] : null,
            sex: $row['sex'] !== null ? (string) $row['sex'] : null,
            birthDate: $row['birth_date'] !== null ? (string) $row['birth_date'] : null,
            weightKg: $row['weight_kg'] !== null ? (float) $row['weight_kg'] : null,
            color: $row['color'] !== null ? (string) $row['color'] : null,
            notes: $row['notes'] !== null ? (string) $row['notes'] : null,
            createdAt: isset($row['created_at']) ? (string) $row['created_at'] : null,
            updatedAt: isset($row['updated_at']) ? (string) $row['updated_at'] : null,
            allergies: isset($row['allergies']) ? (string) $row['allergies'] : null,
            photoObjectKey: isset($row['photo_object_key']) ? (string) $row['photo_object_key'] : null,
            photoContentType: isset($row['photo_content_type']) ? (string) $row['photo_content_type'] : null,
        );
    }

    /** Returns a copy carrying the id assigned by persistence (e.g. after INSERT). */
    public function withId(int $id): self
    {
        return new self(
            id: $id,
            tenantId: $this->tenantId,
            tutorId: $this->tutorId,
            name: $this->name,
            species: $this->species,
            breed: $this->breed,
            sex: $this->sex,
            birthDate: $this->birthDate,
            weightKg: $this->weightKg,
            color: $this->color,
            notes: $this->notes,
            createdAt: $this->createdAt,
            updatedAt: $this->updatedAt,
            allergies: $this->allergies,
            photoObjectKey: $this->photoObjectKey,
            photoContentType: $this->photoContentType,
        );
    }
}
