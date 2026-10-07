<?php

declare(strict_types=1);

namespace CentralVet\Persistence;

use CentralVet\Tenancy\TenantContext;
use PDO;

/**
 * Read-only, tenant-scoped queries behind ClinicalSummaryService: patient
 * card (patient + tutor), encounters, prescription history and the items
 * recorded in one encounter (prescriptions, exam requests, procedure
 * executions, vaccinations). Every table in every query is filtered by the
 * tenant from TenantContext — never by caller input — and joins repeat the
 * tenant predicate so a cross-tenant id can never leak a row. Returns raw
 * rows; formatting lives in ClinicalSummaryService. Not a repository (no
 * aggregate, no writes), so it does not extend AbstractTenantRepository.
 */
final class ClinicalSummaryReader
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly PDO $connection,
    ) {
    }

    private function tenantQuery(?string $alias = null): TenantQuery
    {
        return TenantQuery::forTenant($this->context->tenantId(), $alias);
    }

    /** @return array<string, mixed>|null */
    public function findPatientWithTutor(int $patientId): ?array
    {
        $query = $this->tenantQuery('p')->andEquals('id', $patientId, 'p');

        $row = $this->fetchOne(
            "SELECT p.id AS patient_id, p.name, p.species, p.breed, p.sex, p.birth_date, p.weight_kg,
                    t.id AS tutor_id, t.full_name AS tutor_name, t.phone AS tutor_phone, t.email AS tutor_email
               FROM patient p
               JOIN tutor t ON t.id = p.tutor_id AND t.tenant_id = p.tenant_id
              WHERE {$query->whereSql()}
              LIMIT 1",
            $query->parameters(),
        );

        return $row;
    }

    /**
     * Most recent encounter of the patient. With $excludeEncounterId, the
     * most recent one that precedes the excluded encounter in
     * (started_at, id) order: started earlier, or at the same instant with
     * a lower id. Encounters started after it are never returned; an
     * excluded id outside this tenant/patient yields null.
     *
     * @return array<string, mixed>|null
     */
    public function findLatestEncounter(int $patientId, ?int $excludeEncounterId = null): ?array
    {
        $query = $this->tenantQuery('e')->andEquals('patient_id', $patientId, 'e');
        $parameters = $query->parameters();
        $join = '';
        $before = '';

        if ($excludeEncounterId !== null) {
            $join = ' JOIN encounter x ON x.id = :exclude_encounter_id'
                . ' AND x.tenant_id = e.tenant_id AND x.patient_id = e.patient_id';
            $before = ' AND (e.started_at < x.started_at OR (e.started_at = x.started_at AND e.id < x.id))';
            $parameters[':exclude_encounter_id'] = $excludeEncounterId;
        }

        return $this->fetchOne(
            "SELECT e.id, e.started_at, e.status, e.professional_system_user_id, e.anamnesis_text, e.diagnosis_text
               FROM encounter e{$join}
              WHERE {$query->whereSql()}{$before}
              ORDER BY e.started_at DESC, e.id DESC
              LIMIT 1",
            $parameters,
        );
    }

    /** @return list<array<string, mixed>> */
    public function listPrescriptionsByPatient(int $patientId, int $limit): array
    {
        $query = $this->tenantQuery('pr')->andEquals('patient_id', $patientId, 'pr');

        return $this->fetchAll(
            "SELECT pr.id, pr.created_at, pr.status, pr.professional_system_user_id,
                    (SELECT pi.medication_name FROM prescription_item pi
                      WHERE pi.prescription_id = pr.id AND pi.tenant_id = pr.tenant_id
                      ORDER BY pi.id ASC LIMIT 1) AS first_medication,
                    (SELECT COUNT(*) FROM prescription_item pi
                      WHERE pi.prescription_id = pr.id AND pi.tenant_id = pr.tenant_id) AS items_count
               FROM prescription pr
              WHERE {$query->whereSql()}
              ORDER BY pr.created_at DESC, pr.id DESC
              LIMIT " . max(1, $limit),
            $query->parameters(),
        );
    }

    /** @return list<array<string, mixed>> */
    public function listPrescriptionsByEncounter(int $encounterId): array
    {
        $query = $this->tenantQuery('pr')->andEquals('encounter_id', $encounterId, 'pr');

        return $this->fetchAll(
            "SELECT pr.id, pr.created_at, pr.status,
                    (SELECT GROUP_CONCAT(pi.medication_name ORDER BY pi.id SEPARATOR ', ')
                       FROM prescription_item pi
                      WHERE pi.prescription_id = pr.id AND pi.tenant_id = pr.tenant_id) AS medications
               FROM prescription pr
              WHERE {$query->whereSql()}
              ORDER BY pr.created_at ASC, pr.id ASC",
            $query->parameters(),
        );
    }

    /** @return list<array<string, mixed>> */
    public function listExamRequestsByEncounter(int $encounterId): array
    {
        $query = $this->tenantQuery('er')->andEquals('encounter_id', $encounterId, 'er');

        return $this->fetchAll(
            "SELECT er.id, er.requested_at AS created_at, er.status, c.name
               FROM exam_request er
               JOIN exam_catalog_item c ON c.id = er.exam_catalog_item_id AND c.tenant_id = er.tenant_id
              WHERE {$query->whereSql()}
              ORDER BY er.requested_at ASC, er.id ASC",
            $query->parameters(),
        );
    }

    /** @return list<array<string, mixed>> */
    public function listProcedureExecutionsByEncounter(int $encounterId): array
    {
        $query = $this->tenantQuery('pe')->andEquals('encounter_id', $encounterId, 'pe');

        return $this->fetchAll(
            "SELECT pe.id, pe.executed_at AS created_at, pe.notes_text, c.name
               FROM procedure_execution pe
               JOIN procedure_catalog_item c ON c.id = pe.procedure_catalog_item_id AND c.tenant_id = pe.tenant_id
              WHERE {$query->whereSql()}
              ORDER BY pe.executed_at ASC, pe.id ASC",
            $query->parameters(),
        );
    }

    /** @return list<array<string, mixed>> */
    public function listVaccinationsByEncounter(int $encounterId): array
    {
        $query = $this->tenantQuery('v')->andEquals('encounter_id', $encounterId, 'v');

        return $this->fetchAll(
            "SELECT v.id, v.applied_at AS created_at, v.dose_number, v.lot, c.name
               FROM vaccination v
               JOIN vaccine_catalog_item c ON c.id = v.vaccine_catalog_item_id AND c.tenant_id = v.tenant_id
              WHERE {$query->whereSql()}
              ORDER BY v.applied_at ASC, v.id ASC",
            $query->parameters(),
        );
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
