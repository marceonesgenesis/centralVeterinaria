<?php

declare(strict_types=1);

namespace CentralVet\Application;

use CentralVet\Persistence\ClinicalSummaryReader;
use DateTimeImmutable;

/**
 * Read-only clinical summary used by PrescriptionForm and EncounterView:
 * patient card, previous encounter, prescription history and the items
 * recorded in one encounter (clinical plan). Data comes from
 * ClinicalSummaryReader (tenant-scoped); this class only shapes it.
 * `$today` exists so tests get a deterministic `age_label`.
 */
final class ClinicalSummaryService
{
    private const EXCERPT_LENGTH = 80;

    public function __construct(
        private readonly ClinicalSummaryReader $reader,
        private readonly ?DateTimeImmutable $today = null,
    ) {
    }

    /** @return array<string, mixed>|null */
    public function patientCard(int $patientId): ?array
    {
        $row = $this->reader->findPatientWithTutor($patientId);

        if ($row === null) {
            return null;
        }

        $birthDate = self::nullableString($row['birth_date']);

        return [
            'patient_id' => (int) $row['patient_id'],
            'name' => (string) $row['name'],
            'species' => (string) $row['species'],
            'breed' => self::nullableString($row['breed']),
            'sex' => self::nullableString($row['sex']),
            'birth_date' => $birthDate,
            'age_label' => $this->ageLabel($birthDate),
            'weight_kg' => $row['weight_kg'] === null ? null : (float) $row['weight_kg'],
            'tutor_id' => (int) $row['tutor_id'],
            'tutor_name' => (string) $row['tutor_name'],
            'tutor_phone' => self::nullableString($row['tutor_phone']),
            'tutor_email' => self::nullableString($row['tutor_email']),
        ];
    }

    /** @return array<string, mixed>|null */
    public function lastEncounter(int $patientId, ?int $excludeEncounterId = null): ?array
    {
        $row = $this->reader->findLatestEncounter($patientId, $excludeEncounterId);

        if ($row === null) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'started_at' => (string) $row['started_at'],
            'status' => (string) $row['status'],
            'professional_system_user_id' => self::nullableInt($row['professional_system_user_id']),
            'anamnesis_excerpt' => self::excerpt($row['anamnesis_text']),
            'diagnosis_excerpt' => self::excerpt($row['diagnosis_text']),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function prescriptionHistory(int $patientId, int $limit = 5): array
    {
        return array_map(
            static fn (array $row): array => [
                'id' => (int) $row['id'],
                'created_at' => (string) $row['created_at'],
                'status' => (string) $row['status'],
                'first_medication' => self::nullableString($row['first_medication']),
                'items_count' => (int) $row['items_count'],
                'professional_system_user_id' => self::nullableInt($row['professional_system_user_id']),
            ],
            $this->reader->listPrescriptionsByPatient($patientId, $limit),
        );
    }

    /** @return array{prescriptions: list<array<string, mixed>>, exams: list<array<string, mixed>>, procedures: list<array<string, mixed>>, vaccines: list<array<string, mixed>>} */
    public function encounterPlanItems(int $encounterId): array
    {
        return [
            'prescriptions' => array_map(
                static fn (array $row): array => self::item(
                    $row,
                    self::nullableString($row['medications']) ?? 'Prescrição #' . $row['id'],
                    self::nullableString($row['status']),
                ),
                $this->reader->listPrescriptionsByEncounter($encounterId),
            ),
            'exams' => array_map(
                static fn (array $row): array => self::item($row, (string) $row['name'], self::nullableString($row['status'])),
                $this->reader->listExamRequestsByEncounter($encounterId),
            ),
            'procedures' => array_map(
                static fn (array $row): array => self::item($row, (string) $row['name'], self::excerpt($row['notes_text'])),
                $this->reader->listProcedureExecutionsByEncounter($encounterId),
            ),
            'vaccines' => array_map(
                static fn (array $row): array => self::item(
                    $row,
                    (string) $row['name'],
                    'Dose ' . (int) $row['dose_number']
                        . (self::nullableString($row['lot']) !== null ? ' · Lote ' . $row['lot'] : ''),
                ),
                $this->reader->listVaccinationsByEncounter($encounterId),
            ),
        ];
    }

    private function ageLabel(?string $birthDate): ?string
    {
        if ($birthDate === null) {
            return null;
        }

        $birth = DateTimeImmutable::createFromFormat('!Y-m-d', substr($birthDate, 0, 10));

        if ($birth === false) {
            return null;
        }

        $today = ($this->today ?? new DateTimeImmutable('today'))->setTime(0, 0);

        if ($birth > $today) {
            return null;
        }

        $diff = $birth->diff($today);

        if ($diff->y >= 1) {
            return $diff->y . ($diff->y === 1 ? ' ano' : ' anos');
        }

        return $diff->m . ($diff->m === 1 ? ' mês' : ' meses');
    }

    /** @param array<string, mixed> $row */
    private static function item(array $row, string $title, ?string $detail): array
    {
        return [
            'id' => (int) $row['id'],
            'title' => $title,
            'detail' => $detail,
            'created_at' => (string) $row['created_at'],
        ];
    }

    private static function excerpt(mixed $text): ?string
    {
        $value = self::nullableString($text);

        if ($value === null) {
            return null;
        }

        $value = trim((string) preg_replace('/\s+/u', ' ', $value));

        if ($value === '') {
            return null;
        }

        if (mb_strlen($value) <= self::EXCERPT_LENGTH) {
            return $value;
        }

        return rtrim(mb_substr($value, 0, self::EXCERPT_LENGTH - 1)) . '…';
    }

    private static function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = (string) $value;

        return trim($value) === '' ? null : $value;
    }

    private static function nullableInt(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }
}
