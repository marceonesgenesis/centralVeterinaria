<?php

declare(strict_types=1);

namespace CentralVet\Persistence;

use CentralVet\Domain\Contract\DocumentSourceQueryInterface;
use CentralVet\Tenancy\TenantContext;
use PDO;

/**
 * Read model of the document sources (Fase 7B, T-07): read-only SELECTs
 * over the current tenant only.
 *
 * The driving table of every query is filtered by {@see TenantQuery::forTenant()}
 * (never overridable by input, ADR 0002) and every auxiliary tenant table is
 * joined with the same `tenant_id`, so a row of another tenant reads as not
 * found (null / []). `system_users` has no tenant column: it is a LEFT JOIN
 * by id and a missing professional name reads as ''.
 *
 * Instants are returned as `Y-m-d H:i:s` strings and `next_dose_at` as
 * `Y-m-d`. Nothing read here is logged.
 */
final class DocumentSourceQuery implements DocumentSourceQueryInterface
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly PDO $connection,
    ) {
    }

    public function patientSummary(int $patientId): ?array
    {
        $query = $this->tenantQuery('p')->andEquals('id', $patientId, 'p');
        $row = $this->fetchOne(
            <<<SQL
            SELECT p.id, p.name, p.species, p.breed, t.id AS tutor_id, t.full_name AS tutor_name
            FROM patient p
            INNER JOIN tutor t ON t.id = p.tutor_id AND t.tenant_id = p.tenant_id
            WHERE {$query->whereSql()}
            SQL,
            $query->parameters(),
        );

        if ($row === null) {
            return null;
        }

        return [
            'patient_id' => (int) $row['id'],
            'patient_name' => (string) $row['name'],
            'species' => (string) $row['species'],
            'breed' => self::nullableString($row['breed']),
            'tutor_id' => (int) $row['tutor_id'],
            'tutor_name' => (string) $row['tutor_name'],
        ];
    }

    public function vaccinations(int $patientId): array
    {
        $query = $this->tenantQuery('v')->andEquals('patient_id', $patientId, 'v');
        $rows = $this->fetchAll(
            <<<SQL
            SELECT vc.name AS vaccine_name, v.dose_number, v.applied_at, v.lot, v.next_dose_at,
                   u.name AS professional_name
            FROM vaccination v
            INNER JOIN vaccine_catalog_item vc ON vc.id = v.vaccine_catalog_item_id AND vc.tenant_id = v.tenant_id
            LEFT JOIN system_users u ON u.id = v.professional_system_user_id
            WHERE {$query->whereSql()}
            ORDER BY v.applied_at, v.id
            SQL,
            $query->parameters(),
        );

        return array_map(
            static fn (array $row): array => [
                'vaccine_name' => (string) $row['vaccine_name'],
                'dose_number' => (int) $row['dose_number'],
                'applied_at' => self::dateTime($row['applied_at']),
                'lot' => self::nullableString($row['lot']),
                'next_dose_at' => $row['next_dose_at'] !== null ? substr((string) $row['next_dose_at'], 0, 10) : null,
                'professional_name' => (string) ($row['professional_name'] ?? ''),
            ],
            $rows,
        );
    }

    public function prescription(int $prescriptionId): ?array
    {
        $query = $this->tenantQuery('pr')->andEquals('id', $prescriptionId, 'pr');
        $row = $this->fetchOne(
            <<<SQL
            SELECT pr.id, pr.patient_id, e.system_unit_id, pr.orientation_text, pr.created_at,
                   u.name AS professional_name
            FROM prescription pr
            INNER JOIN encounter e ON e.id = pr.encounter_id AND e.tenant_id = pr.tenant_id
            LEFT JOIN system_users u ON u.id = pr.professional_system_user_id
            WHERE {$query->whereSql()}
            SQL,
            $query->parameters(),
        );

        if ($row === null) {
            return null;
        }

        $itemQuery = $this->tenantQuery('pi')->andEquals('prescription_id', (int) $row['id'], 'pi');
        $items = $this->fetchAll(
            <<<SQL
            SELECT pi.medication_name, pi.dose, pi.dose_unit, pi.route, pi.frequency, pi.duration
            FROM prescription_item pi
            WHERE {$itemQuery->whereSql()}
            ORDER BY pi.id
            SQL,
            $itemQuery->parameters(),
        );

        return [
            'prescription_id' => (int) $row['id'],
            'patient_id' => (int) $row['patient_id'],
            'system_unit_id' => (int) $row['system_unit_id'],
            'professional_name' => (string) ($row['professional_name'] ?? ''),
            'orientation_text' => self::nullableString($row['orientation_text']),
            'created_at' => self::dateTime($row['created_at']),
            'items' => array_map(
                static fn (array $item): array => [
                    'medication_name' => (string) $item['medication_name'],
                    'dose' => (string) $item['dose'],
                    'dose_unit' => (string) $item['dose_unit'],
                    'route' => (string) $item['route'],
                    'frequency' => (string) $item['frequency'],
                    'duration' => (string) $item['duration'],
                ],
                $items,
            ),
        ];
    }

    public function surgery(int $surgeryId): ?array
    {
        $query = $this->tenantQuery('s')->andEquals('id', $surgeryId, 's');
        $row = $this->fetchOne(
            <<<SQL
            SELECT s.id, s.patient_id, s.system_unit_id, s.procedure_name, s.scheduled_start_at,
                   s.consent_signer_name, s.consent_text, s.consent_recorded_at,
                   u.name AS surgeon_name
            FROM surgery s
            LEFT JOIN system_users u ON u.id = s.surgeon_system_user_id
            WHERE {$query->whereSql()}
            SQL,
            $query->parameters(),
        );

        if ($row === null) {
            return null;
        }

        return [
            'surgery_id' => (int) $row['id'],
            'patient_id' => (int) $row['patient_id'],
            'system_unit_id' => (int) $row['system_unit_id'],
            'procedure_name' => (string) $row['procedure_name'],
            'scheduled_start_at' => self::dateTime($row['scheduled_start_at']),
            'surgeon_name' => (string) ($row['surgeon_name'] ?? ''),
            'consent_signer_name' => self::nullableString($row['consent_signer_name']),
            'consent_text' => self::nullableString($row['consent_text']),
            'consent_recorded_at' => $row['consent_recorded_at'] !== null ? self::dateTime($row['consent_recorded_at']) : null,
        ];
    }

    public function tutorContact(int $tutorId): ?array
    {
        $query = $this->tenantQuery('t')->andEquals('id', $tutorId, 't');
        $row = $this->fetchOne(
            "SELECT t.full_name, t.email, t.phone FROM tutor t WHERE {$query->whereSql()}",
            $query->parameters(),
        );

        if ($row === null) {
            return null;
        }

        return [
            'tutor_name' => (string) $row['full_name'],
            'email' => self::nullableString($row['email']),
            'phone' => (string) $row['phone'],
        ];
    }

    /**
     * `Y-m-d H:i:s` from a DATETIME/TIMESTAMP(6) column (fraction dropped).
     */
    private static function dateTime(mixed $value): string
    {
        return substr((string) $value, 0, 19);
    }

    private static function nullableString(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }

    /**
     * Tenant predicate (`tenant_id`) of the driving table, always the
     * context's tenant, never caller input (ADR 0002).
     */
    private function tenantQuery(string $alias): TenantQuery
    {
        return TenantQuery::forTenant($this->context->tenantId(), $alias);
    }

    /**
     * @param array<string, mixed> $parameters
     * @return array<string, mixed>|null
     */
    private function fetchOne(string $sql, array $parameters): ?array
    {
        $statement = $this->connection->prepare($sql);
        $statement->execute($parameters);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string, mixed> $parameters
     * @return list<array<string, mixed>>
     */
    private function fetchAll(string $sql, array $parameters): array
    {
        $statement = $this->connection->prepare($sql);
        $statement->execute($parameters);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }
}
