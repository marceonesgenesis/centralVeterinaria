<?php

declare(strict_types=1);

namespace CentralVet\Persistence;

use CentralVet\Domain\Contract\CopilotClinicalQueryInterface;
use CentralVet\Tenancy\TenantContext;
use DateTimeImmutable;
use InvalidArgumentException;
use PDO;

/**
 * Read-only clinical reads behind the copilot tools, scoped by tenant AND
 * by the active unit (TenantContext::requireUnitId(), never caller input).
 * `patient` has no unit column, so a patient belongs to the unit when it
 * has an encounter or an appointment there; every clinical record is read
 * through an encounter of the unit. Every table is filtered by the tenant
 * (joins repeat the tenant predicate), so an id of another tenant or unit
 * yields null or an empty list. Only `tutor.full_name` is read from tutor:
 * contact, document and address columns never leave this class. Every
 * top-level statement carries the 5 s execution-time optimizer hint
 * (MySQL ignores it in subqueries).
 */
final class CopilotClinicalQuery implements CopilotClinicalQueryInterface
{
    private const ACTIVE_WITHOUT_VALIDITY_DAYS = 30;

    public function __construct(
        private readonly TenantContext $context,
        private readonly PDO $connection,
    ) {
    }

    public function patientInUnit(int $patientId): bool
    {
        $query = $this->tenantQuery('p')->andEquals('id', $patientId, 'p');

        return $this->fetchOne(
            "SELECT /*+ MAX_EXECUTION_TIME(5000) */ 1 AS found
               FROM patient p
              WHERE {$query->whereSql()}{$this->patientInUnitSql('p')}
              LIMIT 1",
            [...$query->parameters(), ...$this->patientInUnitParameters()],
        ) !== null;
    }

    public function encounterInUnit(int $encounterId): bool
    {
        $query = $this->encounterQuery('e')->andEquals('id', $encounterId, 'e');

        return $this->fetchOne(
            "SELECT /*+ MAX_EXECUTION_TIME(5000) */ 1 AS found
               FROM encounter e
              WHERE {$query->whereSql()}
              LIMIT 1",
            $query->parameters(),
        ) !== null;
    }

    public function searchPatients(string $term, int $limit): array
    {
        $term = trim($term);

        if ($term === '') {
            return [];
        }

        $query = $this->tenantQuery('p');
        $parameters = [...$query->parameters(), ...$this->patientInUnitParameters()];

        if (ctype_digit($term)) {
            $match = ' AND p.id = :search_id';
            $parameters[':search_id'] = $term;
        } else {
            $match = ' AND (p.name LIKE :search_name OR t.full_name LIKE :search_tutor)';
            $like = '%' . addcslashes($term, '\\%_') . '%';
            $parameters[':search_name'] = $like;
            $parameters[':search_tutor'] = $like;
        }

        $rows = $this->fetchAll(
            "SELECT /*+ MAX_EXECUTION_TIME(5000) */ p.id, p.name, p.species, p.breed, p.birth_date, t.full_name AS tutor_full_name
               FROM patient p
               JOIN tutor t ON t.id = p.tutor_id AND t.tenant_id = p.tenant_id
              WHERE {$query->whereSql()}{$match}{$this->patientInUnitSql('p')}
              ORDER BY p.name ASC, p.id ASC
              LIMIT " . self::limit($limit),
            $parameters,
        );

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'species' => (string) $row['species'],
            'breed' => self::nullableString($row['breed']),
            'birth_date' => self::date($row['birth_date']),
            'tutor_full_name' => (string) $row['tutor_full_name'],
        ], $rows);
    }

    public function patient(int $patientId): ?array
    {
        $query = $this->tenantQuery('p')->andEquals('id', $patientId, 'p');

        $row = $this->fetchOne(
            "SELECT /*+ MAX_EXECUTION_TIME(5000) */ p.id, p.name, p.species, p.breed, p.sex, p.birth_date, p.allergies,
                    p.weight_kg AS patient_weight_kg, w.weight_kg AS unit_weight_kg, w.started_at AS unit_weight_at,
                    t.full_name AS tutor_full_name
               FROM patient p
               JOIN tutor t ON t.id = p.tutor_id AND t.tenant_id = p.tenant_id
               LEFT JOIN encounter w ON w.tenant_id = p.tenant_id AND w.id = (
                    SELECT we.id FROM encounter we
                     WHERE we.tenant_id = p.tenant_id AND we.patient_id = p.id
                       AND we.system_unit_id = :weight_unit_id AND we.weight_kg IS NOT NULL
                     ORDER BY we.started_at DESC, we.id DESC
                     LIMIT 1)
              WHERE {$query->whereSql()}{$this->patientInUnitSql('p')}
              LIMIT 1",
            [...$query->parameters(), ...$this->patientInUnitParameters(), ':weight_unit_id' => $this->unitId()],
        );

        if ($row === null) {
            return null;
        }

        $fromUnit = $row['unit_weight_kg'] !== null;

        return [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'species' => (string) $row['species'],
            'breed' => self::nullableString($row['breed']),
            'sex' => self::nullableString($row['sex']),
            'birth_date' => self::date($row['birth_date']),
            'allergies' => self::nullableString($row['allergies']),
            'weight_kg' => self::nullableString($fromUnit ? $row['unit_weight_kg'] : $row['patient_weight_kg']),
            'weight_measured_at' => $fromUnit ? self::dateTime($row['unit_weight_at']) : null,
            'tutor_full_name' => (string) $row['tutor_full_name'],
        ];
    }

    public function encounters(int $patientId, int $limit): array
    {
        $query = $this->encounterQuery('e')->andEquals('patient_id', $patientId, 'e');

        $rows = $this->fetchAll(
            "SELECT /*+ MAX_EXECUTION_TIME(5000) */ e.id, e.started_at, e.status, e.anamnesis_text, e.diagnosis_text, e.clinical_plan_text
               FROM encounter e
              WHERE {$query->whereSql()}
              ORDER BY e.started_at DESC, e.id DESC
              LIMIT " . self::limit($limit),
            $query->parameters(),
        );

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'started_at' => self::dateTime($row['started_at']),
            'status' => (string) $row['status'],
            'anamnesis_text' => self::nullableString($row['anamnesis_text']),
            'diagnosis_text' => self::nullableString($row['diagnosis_text']),
            'clinical_plan_text' => self::nullableString($row['clinical_plan_text']),
        ], $rows);
    }

    public function encounter(int $encounterId): ?array
    {
        $query = $this->encounterQuery('e')->andEquals('id', $encounterId, 'e');

        $row = $this->fetchOne(
            "SELECT /*+ MAX_EXECUTION_TIME(5000) */ e.id, e.patient_id, e.started_at, e.status, e.anamnesis_text, e.physical_exam_text,
                    e.diagnosis_text, e.clinical_plan_text, e.weight_kg
               FROM encounter e
              WHERE {$query->whereSql()}
              LIMIT 1",
            $query->parameters(),
        );

        if ($row === null) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'patient_id' => (int) $row['patient_id'],
            'started_at' => self::dateTime($row['started_at']),
            'status' => (string) $row['status'],
            'anamnesis_text' => self::nullableString($row['anamnesis_text']),
            'physical_exam_text' => self::nullableString($row['physical_exam_text']),
            'diagnosis_text' => self::nullableString($row['diagnosis_text']),
            'clinical_plan_text' => self::nullableString($row['clinical_plan_text']),
            'weight_kg' => self::nullableString($row['weight_kg']),
        ];
    }

    public function prescriptions(int $patientId, bool $onlyActive, DateTimeImmutable $today, int $limit): array
    {
        $query = $this->tenantQuery('pr')
            ->andEquals('patient_id', $patientId, 'pr')
            ->andEquals('system_unit_id', $this->unitId(), 'e');
        $parameters = $query->parameters();
        $active = '';

        if ($onlyActive) {
            $active = ' AND (pr.valid_until >= :active_today'
                . ' OR (pr.valid_until IS NULL AND pr.created_at >= :active_since))';
            $parameters[':active_today'] = $today->format('Y-m-d');
            $parameters[':active_since'] = $today->setTime(0, 0)
                ->modify('-' . self::ACTIVE_WITHOUT_VALIDITY_DAYS . ' days')
                ->format('Y-m-d H:i:s');
        }

        $rows = $this->fetchAll(
            "SELECT /*+ MAX_EXECUTION_TIME(5000) */ pr.id AS prescription_id, pr.created_at, pr.valid_until, pr.status,
                    pi.medication_name, pi.dose, pi.dose_unit, pi.route, pi.frequency, pi.duration
               FROM prescription pr
               JOIN encounter e ON e.id = pr.encounter_id AND e.tenant_id = pr.tenant_id
               JOIN prescription_item pi ON pi.prescription_id = pr.id AND pi.tenant_id = pr.tenant_id
              WHERE {$query->whereSql()}{$active}
              ORDER BY pr.created_at DESC, pr.id DESC, pi.id ASC
              LIMIT " . self::limit($limit),
            $parameters,
        );

        return array_map(static fn (array $row): array => [
            'prescription_id' => (int) $row['prescription_id'],
            'created_at' => self::dateTime($row['created_at']),
            'valid_until' => self::date($row['valid_until']),
            'status' => (string) $row['status'],
            'medication_name' => (string) $row['medication_name'],
            'dose' => self::nullableString($row['dose']),
            'dose_unit' => self::nullableString($row['dose_unit']),
            'route' => self::nullableString($row['route']),
            'frequency' => self::nullableString($row['frequency']),
            'duration' => self::nullableString($row['duration']),
        ], $rows);
    }

    public function exams(int $patientId, string $situacao, int $limit): array
    {
        $situation = match ($situacao) {
            self::EXAMS_PENDING => ' AND (r.id IS NULL OR r.pending_review = 1)',
            self::EXAMS_RELEASED => ' AND r.id IS NOT NULL AND r.pending_review = 0',
            self::EXAMS_ALL => '',
            default => throw new InvalidArgumentException('Unknown exam situation'),
        };
        $query = $this->tenantQuery('er')
            ->andEquals('patient_id', $patientId, 'er')
            ->andEquals('system_unit_id', $this->unitId(), 'e');

        $rows = $this->fetchAll(
            "SELECT /*+ MAX_EXECUTION_TIME(5000) */ er.id AS exam_request_id, c.name AS exam_name, er.requested_at, er.status,
                    r.id AS result_id, r.pending_review, r.structured_result_text, r.received_at
               FROM exam_request er
               JOIN encounter e ON e.id = er.encounter_id AND e.tenant_id = er.tenant_id
               JOIN exam_catalog_item c ON c.id = er.exam_catalog_item_id AND c.tenant_id = er.tenant_id
               LEFT JOIN exam_result r ON r.tenant_id = er.tenant_id AND r.id = (
                    SELECT MAX(rr.id) FROM exam_result rr
                     WHERE rr.exam_request_id = er.id AND rr.tenant_id = er.tenant_id)
              WHERE {$query->whereSql()}{$situation}
              ORDER BY er.requested_at DESC, er.id DESC
              LIMIT " . self::limit($limit),
            $query->parameters(),
        );

        return array_map(static function (array $row): array {
            $hasResult = $row['result_id'] !== null;

            return [
                'exam_request_id' => (int) $row['exam_request_id'],
                'exam_name' => (string) $row['exam_name'],
                'requested_at' => self::dateTime($row['requested_at']),
                'status' => (string) $row['status'],
                'pending_review' => $hasResult ? (int) $row['pending_review'] === 1 : null,
                'structured_result_text' => $hasResult ? self::nullableString($row['structured_result_text']) : null,
                'received_at' => $hasResult ? self::dateTime($row['received_at']) : null,
            ];
        }, $rows);
    }

    public function vaccinations(int $patientId, int $limit): array
    {
        $query = $this->tenantQuery('v')
            ->andEquals('patient_id', $patientId, 'v')
            ->andEquals('system_unit_id', $this->unitId(), 'e');

        $rows = $this->fetchAll(
            "SELECT /*+ MAX_EXECUTION_TIME(5000) */ v.vaccine_catalog_item_id, vc.name AS vaccine_name, v.applied_at, v.next_dose_at,
                    EXISTS (
                        SELECT 1 FROM vaccination later
                          JOIN encounter le ON le.id = later.encounter_id AND le.tenant_id = later.tenant_id
                         WHERE later.tenant_id = v.tenant_id
                           AND later.patient_id = v.patient_id
                           AND later.vaccine_catalog_item_id = v.vaccine_catalog_item_id
                           AND le.system_unit_id = e.system_unit_id
                           AND (later.applied_at > v.applied_at OR (later.applied_at = v.applied_at AND later.id > v.id))
                    ) AS superseded
               FROM vaccination v
               JOIN encounter e ON e.id = v.encounter_id AND e.tenant_id = v.tenant_id
               JOIN vaccine_catalog_item vc ON vc.id = v.vaccine_catalog_item_id AND vc.tenant_id = v.tenant_id
              WHERE {$query->whereSql()}
              ORDER BY v.applied_at DESC, v.id DESC
              LIMIT " . self::limit($limit),
            $query->parameters(),
        );

        return array_map(static fn (array $row): array => [
            'vaccine_catalog_item_id' => (int) $row['vaccine_catalog_item_id'],
            'vaccine_name' => (string) $row['vaccine_name'],
            'applied_at' => self::dateTime($row['applied_at']),
            'next_dose_at' => self::date($row['next_dose_at']),
            'superseded' => (int) $row['superseded'] === 1,
        ], $rows);
    }

    public function tutorNames(int $patientId): array
    {
        $query = $this->tenantQuery('p')->andEquals('id', $patientId, 'p');

        $rows = $this->fetchAll(
            "SELECT /*+ MAX_EXECUTION_TIME(5000) */ t.full_name
               FROM patient p
               JOIN tutor t ON t.id = p.tutor_id AND t.tenant_id = p.tenant_id
              WHERE {$query->whereSql()}{$this->patientInUnitSql('p')}
              LIMIT 1",
            [...$query->parameters(), ...$this->patientInUnitParameters()],
        );

        return array_values(array_filter(
            array_map(static fn (array $row): string => trim((string) $row['full_name']), $rows),
            static fn (string $name): bool => $name !== '',
        ));
    }

    /**
     * Patient of the unit: an encounter or an appointment of the patient in
     * the active unit (same tenant).
     */
    private function patientInUnitSql(string $alias): string
    {
        return " AND (EXISTS (SELECT 1 FROM encounter ue"
            . " WHERE ue.tenant_id = {$alias}.tenant_id AND ue.patient_id = {$alias}.id AND ue.system_unit_id = :unit_encounter_id)"
            . " OR EXISTS (SELECT 1 FROM appointment ua"
            . " WHERE ua.tenant_id = {$alias}.tenant_id AND ua.patient_id = {$alias}.id AND ua.system_unit_id = :unit_appointment_id))";
    }

    /** @return array<string, int> */
    private function patientInUnitParameters(): array
    {
        $unitId = $this->unitId();

        return [':unit_encounter_id' => $unitId, ':unit_appointment_id' => $unitId];
    }

    private function encounterQuery(string $alias): TenantQuery
    {
        return $this->tenantQuery($alias)->andEquals('system_unit_id', $this->unitId(), $alias);
    }

    private function tenantQuery(string $alias): TenantQuery
    {
        return TenantQuery::forTenant($this->context->tenantId(), $alias);
    }

    private function unitId(): int
    {
        return $this->context->requireUnitId();
    }

    private static function limit(int $limit): int
    {
        return max(1, $limit);
    }

    private static function dateTime(mixed $value): string
    {
        return substr((string) $value, 0, 19);
    }

    private static function date(mixed $value): ?string
    {
        return $value === null ? null : substr((string) $value, 0, 10);
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
