<?php

declare(strict_types=1);

namespace CentralVet\Persistence;

use CentralVet\Domain\Contract\AiClinicalContextQueryInterface;
use CentralVet\Tenancy\TenantContext;
use PDO;

/**
 * Read-only, tenant-scoped reads of the clinical data the AI may receive:
 * the current encounter, the patient history and one exam result. Every
 * table in every query is filtered by the tenant from TenantContext (joins
 * repeat the tenant predicate), so an id of another tenant yields null or
 * empty lists. Only clinical columns plus patient/tutor names are selected:
 * tutor contact, document and address columns and the stored file key of
 * an exam result are never read. Names are returned raw so the caller can
 * authorize by unit and minimize PII before anything leaves the system.
 */
final class AiClinicalContextQuery implements AiClinicalContextQueryInterface
{
    private const HISTORY_LIMIT = 5;

    public function __construct(
        private readonly TenantContext $context,
        private readonly PDO $connection,
    ) {
    }

    public function encounterContext(int $encounterId): ?array
    {
        $query = $this->tenantQuery('e')->andEquals('id', $encounterId, 'e');

        $row = $this->fetchOne(
            "SELECT e.id, e.system_unit_id, e.patient_id, e.status, e.anamnesis_text,
                    p.name AS patient_name, t.full_name AS tutor_name, p.species, p.breed, p.sex, p.birth_date,
                    COALESCE(e.weight_kg, p.weight_kg) AS weight_kg
               FROM encounter e
               JOIN patient p ON p.id = e.patient_id AND p.tenant_id = e.tenant_id
               LEFT JOIN tutor t ON t.id = p.tutor_id AND t.tenant_id = p.tenant_id
              WHERE {$query->whereSql()}
              LIMIT 1",
            $query->parameters(),
        );

        if ($row === null) {
            return null;
        }

        return [
            'encounter_id' => (int) $row['id'],
            'system_unit_id' => (int) $row['system_unit_id'],
            'patient_id' => (int) $row['patient_id'],
            'status' => (string) $row['status'],
            'anamnesis_text' => self::nullableString($row['anamnesis_text']),
            ...self::patientFields($row),
            'weight_kg' => self::nullableString($row['weight_kg']),
        ];
    }

    public function patientHistory(int $patientId, ?int $excludeEncounterId): array
    {
        return [
            'encounters' => $this->historyEncounters($patientId, $excludeEncounterId),
            'prescriptions' => $this->historyPrescriptions($patientId, $excludeEncounterId),
            'exam_results' => $this->historyExamResults($patientId, $excludeEncounterId),
        ];
    }

    public function examResultContext(int $examResultId): ?array
    {
        $query = $this->tenantQuery('r')->andEquals('id', $examResultId, 'r');

        $row = $this->fetchOne(
            "SELECT r.id, r.structured_result_text, r.received_at,
                    er.encounter_id, e.system_unit_id, er.patient_id, c.name AS exam_name,
                    p.name AS patient_name, t.full_name AS tutor_name, p.species, p.breed, p.sex, p.birth_date
               FROM exam_result r
               JOIN exam_request er ON er.id = r.exam_request_id AND er.tenant_id = r.tenant_id
               JOIN encounter e ON e.id = er.encounter_id AND e.tenant_id = er.tenant_id
               JOIN exam_catalog_item c ON c.id = er.exam_catalog_item_id AND c.tenant_id = er.tenant_id
               JOIN patient p ON p.id = er.patient_id AND p.tenant_id = er.tenant_id
               LEFT JOIN tutor t ON t.id = p.tutor_id AND t.tenant_id = p.tenant_id
              WHERE {$query->whereSql()}
              LIMIT 1",
            $query->parameters(),
        );

        if ($row === null) {
            return null;
        }

        return [
            'exam_result_id' => (int) $row['id'],
            'encounter_id' => (int) $row['encounter_id'],
            'system_unit_id' => (int) $row['system_unit_id'],
            'patient_id' => (int) $row['patient_id'],
            'exam_name' => (string) $row['exam_name'],
            'result_text' => self::nullableString($row['structured_result_text']),
            'received_at' => self::dateTime($row['received_at']),
            ...self::patientFields($row),
        ];
    }

    /** @return list<array{started_at: string, anamnesis_text: ?string, physical_exam_text: ?string, diagnosis_text: ?string, clinical_plan_text: ?string}> */
    private function historyEncounters(int $patientId, ?int $excludeEncounterId): array
    {
        $query = $this->tenantQuery('e')->andEquals('patient_id', $patientId, 'e');
        [$exclude, $parameters] = $this->excludeEncounter('e.id', $excludeEncounterId, $query->parameters());

        $rows = $this->fetchAll(
            "SELECT e.started_at, e.anamnesis_text, e.physical_exam_text, e.diagnosis_text, e.clinical_plan_text
               FROM encounter e
              WHERE {$query->whereSql()}{$exclude}
              ORDER BY e.started_at DESC, e.id DESC
              LIMIT " . self::HISTORY_LIMIT,
            $parameters,
        );

        return array_map(static fn (array $row): array => [
            'started_at' => self::dateTime($row['started_at']),
            'anamnesis_text' => self::nullableString($row['anamnesis_text']),
            'physical_exam_text' => self::nullableString($row['physical_exam_text']),
            'diagnosis_text' => self::nullableString($row['diagnosis_text']),
            'clinical_plan_text' => self::nullableString($row['clinical_plan_text']),
        ], $rows);
    }

    /** @return list<array{created_at: string, items: list<string>}> */
    private function historyPrescriptions(int $patientId, ?int $excludeEncounterId): array
    {
        $query = $this->tenantQuery('pr')->andEquals('patient_id', $patientId, 'pr');
        [$exclude, $parameters] = $this->excludeEncounter('pr.encounter_id', $excludeEncounterId, $query->parameters());

        $prescriptions = $this->fetchAll(
            "SELECT pr.id, pr.created_at
               FROM prescription pr
              WHERE {$query->whereSql()}{$exclude}
              ORDER BY pr.created_at DESC, pr.id DESC
              LIMIT " . self::HISTORY_LIMIT,
            $parameters,
        );

        $history = [];
        foreach ($prescriptions as $prescription) {
            $itemQuery = $this->tenantQuery('pi')->andEquals('prescription_id', (int) $prescription['id'], 'pi');
            $items = $this->fetchAll(
                "SELECT pi.medication_name, pi.dose, pi.dose_unit, pi.route, pi.frequency, pi.duration
                   FROM prescription_item pi
                  WHERE {$itemQuery->whereSql()}
                  ORDER BY pi.id ASC",
                $itemQuery->parameters(),
            );

            $history[] = [
                'created_at' => self::dateTime($prescription['created_at']),
                'items' => array_map(static fn (array $item): string => sprintf(
                    '%s %s %s, %s, %s, %s',
                    $item['medication_name'],
                    $item['dose'],
                    $item['dose_unit'],
                    $item['route'],
                    $item['frequency'],
                    $item['duration'],
                ), $items),
            ];
        }

        return $history;
    }

    /** @return list<array{received_at: string, exam_name: string, result_text: ?string}> */
    private function historyExamResults(int $patientId, ?int $excludeEncounterId): array
    {
        $query = $this->tenantQuery('er')->andEquals('patient_id', $patientId, 'er');
        [$exclude, $parameters] = $this->excludeEncounter('er.encounter_id', $excludeEncounterId, $query->parameters());

        $rows = $this->fetchAll(
            "SELECT r.received_at, c.name AS exam_name, r.structured_result_text
               FROM exam_request er
               JOIN exam_result r ON r.exam_request_id = er.id AND r.tenant_id = er.tenant_id
               JOIN exam_catalog_item c ON c.id = er.exam_catalog_item_id AND c.tenant_id = er.tenant_id
              WHERE {$query->whereSql()}{$exclude}
              ORDER BY r.received_at DESC, r.id DESC
              LIMIT " . self::HISTORY_LIMIT,
            $parameters,
        );

        return array_map(static fn (array $row): array => [
            'received_at' => self::dateTime($row['received_at']),
            'exam_name' => (string) $row['exam_name'],
            'result_text' => self::nullableString($row['structured_result_text']),
        ], $rows);
    }

    /**
     * @param array<string, int|string|float|bool|null> $parameters
     * @return array{0: string, 1: array<string, int|string|float|bool|null>}
     */
    private function excludeEncounter(string $column, ?int $excludeEncounterId, array $parameters): array
    {
        if ($excludeEncounterId === null) {
            return ['', $parameters];
        }

        $parameters[':exclude_encounter_id'] = $excludeEncounterId;

        return [" AND {$column} <> :exclude_encounter_id", $parameters];
    }

    /**
     * @param array<string, mixed> $row
     * @return array{patient_name: string, tutor_name: ?string, species: string, breed: ?string, sex: ?string, birth_date: ?string}
     */
    private static function patientFields(array $row): array
    {
        return [
            'patient_name' => (string) $row['patient_name'],
            'tutor_name' => self::nullableString($row['tutor_name']),
            'species' => (string) $row['species'],
            'breed' => self::nullableString($row['breed']),
            'sex' => self::nullableString($row['sex']),
            'birth_date' => $row['birth_date'] === null ? null : substr((string) $row['birth_date'], 0, 10),
        ];
    }

    private function tenantQuery(string $alias): TenantQuery
    {
        return TenantQuery::forTenant($this->context->tenantId(), $alias);
    }

    private static function dateTime(mixed $value): string
    {
        return substr((string) $value, 0, 19);
    }

    private static function nullableString(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }

    /**
     * @param array<string, int|string|float|bool|null> $parameters
     * @return array<string, mixed>|null
     */
    private function fetchOne(string $sql, array $parameters): ?array
    {
        $statement = $this->connection->prepare($sql);
        $statement->execute($parameters);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /**
     * @param array<string, int|string|float|bool|null> $parameters
     * @return list<array<string, mixed>>
     */
    private function fetchAll(string $sql, array $parameters): array
    {
        $statement = $this->connection->prepare($sql);
        $statement->execute($parameters);

        return array_values($statement->fetchAll(PDO::FETCH_ASSOC));
    }
}
