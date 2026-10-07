<?php

declare(strict_types=1);

namespace CentralVet\Tests\Support;

use CentralVet\Domain\Contract\DocumentSourceQueryInterface;

/**
 * Double for DocumentSourceQueryInterface (T-05): returns the rows seeded
 * with the shapes documented in the interface, null/[] for anything not
 * seeded (which is how "another tenant" looks to the caller). Rows are
 * returned as given, with no validation.
 */
final class FakeDocumentSourceQuery implements DocumentSourceQueryInterface
{
    /** @var array<int, array<string, mixed>> */
    private array $patients = [];
    /** @var array<int, list<array<string, mixed>>> */
    private array $vaccinations = [];
    /** @var array<int, array<string, mixed>> */
    private array $prescriptions = [];
    /** @var array<int, array<string, mixed>> */
    private array $surgeries = [];
    /** @var array<int, array<string, mixed>> */
    private array $tutorContacts = [];

    /** @param array<string, mixed> $row patientSummary() shape, keyed by `patient_id` */
    public function seedPatient(array $row): void
    {
        $this->patients[(int) $row['patient_id']] = $row;
    }

    /** @param list<array<string, mixed>> $rows vaccinations() shape, already ordered by `applied_at` */
    public function seedVaccinations(int $patientId, array $rows): void
    {
        $this->vaccinations[$patientId] = array_values($rows);
    }

    /** @param array<string, mixed> $row prescription() shape, keyed by `prescription_id` */
    public function seedPrescription(array $row): void
    {
        $this->prescriptions[(int) $row['prescription_id']] = $row;
    }

    /** @param array<string, mixed> $row surgery() shape, keyed by `surgery_id` */
    public function seedSurgery(array $row): void
    {
        $this->surgeries[(int) $row['surgery_id']] = $row;
    }

    /** @param array<string, mixed> $row tutorContact() shape */
    public function seedTutorContact(int $tutorId, array $row): void
    {
        $this->tutorContacts[$tutorId] = $row;
    }

    public function patientSummary(int $patientId): ?array
    {
        return $this->patients[$patientId] ?? null;
    }

    public function vaccinations(int $patientId): array
    {
        return $this->vaccinations[$patientId] ?? [];
    }

    public function prescription(int $prescriptionId): ?array
    {
        return $this->prescriptions[$prescriptionId] ?? null;
    }

    public function surgery(int $surgeryId): ?array
    {
        return $this->surgeries[$surgeryId] ?? null;
    }

    public function tutorContact(int $tutorId): ?array
    {
        return $this->tutorContacts[$tutorId] ?? null;
    }
}
