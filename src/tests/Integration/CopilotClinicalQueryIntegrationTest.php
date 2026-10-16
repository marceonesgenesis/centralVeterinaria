<?php

declare(strict_types=1);

namespace CentralVet\Tests\Integration;

use CentralVet\Domain\Contract\CopilotClinicalQueryInterface;
use CentralVet\Persistence\CopilotClinicalQuery;
use CentralVet\Tenancy\TenantContext;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\MysqlIntegrationTestCase;

/**
 * Fase 8B, T-06: clinical reads of the copilot scoped by tenant AND unit.
 * A patient belongs to the active unit when it has an encounter or an
 * appointment there; clinical records come only from encounters of that
 * unit. Every row lives in throwaway tenants created inside the test
 * transaction (rolled back in tearDown()).
 *
 * Tenant A, unit A = context unit, unit B = any other system_unit:
 * - "only_b": patient with an encounter only in unit B;
 * - "both": patient with encounters in A and B, prescriptions, exams and
 *   vaccinations in both units;
 * - "appointment": patient with only an appointment in unit A;
 * - "match1..3": patients sharing a search token, encounter in unit A.
 * Tenant B: one patient with an encounter in unit A.
 */
final class CopilotClinicalQueryIntegrationTest extends MysqlIntegrationTestCase
{
    private const PATIENT_KEYS = ['id', 'name', 'species', 'breed', 'sex', 'birth_date', 'allergies', 'weight_kg', 'weight_measured_at', 'tutor_full_name'];
    private const SEARCH_KEYS = ['id', 'name', 'species', 'breed', 'birth_date', 'tutor_full_name'];
    private const ENCOUNTERS_KEYS = ['id', 'started_at', 'status', 'anamnesis_text', 'diagnosis_text', 'clinical_plan_text'];
    private const ENCOUNTER_KEYS = ['id', 'patient_id', 'started_at', 'status', 'anamnesis_text', 'physical_exam_text', 'diagnosis_text', 'clinical_plan_text', 'weight_kg'];
    private const PRESCRIPTION_KEYS = ['prescription_id', 'created_at', 'valid_until', 'status', 'medication_name', 'dose', 'dose_unit', 'route', 'frequency', 'duration'];
    private const EXAM_KEYS = ['exam_request_id', 'exam_name', 'requested_at', 'status', 'pending_review', 'structured_result_text', 'received_at'];
    private const VACCINATION_KEYS = ['vaccine_catalog_item_id', 'vaccine_name', 'applied_at', 'next_dose_at', 'superseded'];

    private int $userId;
    private int $unitA;
    private int $unitB;
    private int $tenantA;
    private int $tenantB;
    private string $token;

    /** @var array<string, int> */
    private array $ids = [];

    public function setUp(): void
    {
        parent::setUp();

        $this->userId = (int) $this->pdo->query('SELECT id FROM system_users ORDER BY id LIMIT 1')->fetchColumn();
        $this->unitA = (int) $this->pdo->query('SELECT id FROM system_unit ORDER BY id LIMIT 1')->fetchColumn();
        Assert::true($this->userId > 0 && $this->unitA > 0, 'Fixture requires a system_users and a system_unit row');

        $this->tenantA = $this->createTenant('f8b-copilot-a');
        $this->tenantB = $this->createTenant('f8b-copilot-b');
        $this->unitB = $this->otherUnitId();
        $this->token = 'F8Bq' . bin2hex(random_bytes(4));

        $this->seed();
    }

    public function testImplementsTheContract(): void
    {
        Assert::instanceOf(CopilotClinicalQueryInterface::class, $this->query($this->tenantA));
    }

    public function testPatientOnlyInAnotherUnitIsNotFound(): void
    {
        $query = $this->query($this->tenantA);
        $patient = $this->ids['only_b'];

        Assert::false($query->patientInUnit($patient), 'encounter only in unit B: not a patient of unit A');
        Assert::same(null, $query->patient($patient));
        Assert::same([], $query->encounters($patient, 20));
        Assert::same([], $query->prescriptions($patient, false, $this->today(), 20));
        Assert::same([], $query->exams($patient, 'todos', 20));
        Assert::same([], $query->vaccinations($patient, 20));
        Assert::same([], $query->tutorNames($patient));
        Assert::false(
            in_array($patient, array_column($query->searchPatients('Rex OnlyB ' . $this->token, 20), 'id'), true),
            'search must not find a patient of another unit',
        );
        Assert::false($query->encounterInUnit($this->ids['only_b_encounter']));
        Assert::same(null, $query->encounter($this->ids['only_b_encounter']));

        Assert::true($this->query($this->tenantA, $this->unitB)->patientInUnit($patient), 'unit B context sees its own patient');
    }

    public function testAppointmentAloneMakesThePatientBelongToTheUnit(): void
    {
        $query = $this->query($this->tenantA);
        $patient = $this->ids['appointment'];

        Assert::true($query->patientInUnit($patient));
        $row = $query->patient($patient);
        Assert::notNull($row);
        Assert::same(self::PATIENT_KEYS, array_keys($row));
        Assert::same('7.50', $row['weight_kg'], 'no encounter in the unit: patient weight');
        Assert::same(null, $row['weight_measured_at']);
        Assert::same([], $query->encounters($patient, 20));
        Assert::false($this->query($this->tenantA, $this->unitB)->patientInUnit($patient));
    }

    public function testEncountersComeOnlyFromTheActiveUnit(): void
    {
        $query = $this->query($this->tenantA);
        $patient = $this->ids['both'];

        $encounters = $query->encounters($patient, 20);
        Assert::same([$this->ids['both_a2'], $this->ids['both_a1']], array_column($encounters, 'id'), 'unit A only, most recent first');
        Assert::same(self::ENCOUNTERS_KEYS, array_keys($encounters[0]));
        Assert::same([
            'id' => $this->ids['both_a2'],
            'started_at' => '2031-09-10 09:00:00',
            'status' => 'finished',
            'anamnesis_text' => 'F8B teste anamnese A2',
            'diagnosis_text' => 'F8B teste diagnostico A2',
            'clinical_plan_text' => 'F8B teste plano A2',
        ], $encounters[0]);
        Assert::count(1, $query->encounters($patient, 1), 'limit is respected');

        Assert::true($query->encounterInUnit($this->ids['both_a1']));
        Assert::false($query->encounterInUnit($this->ids['both_b']));
        Assert::same(null, $query->encounter($this->ids['both_b']));

        $encounter = $query->encounter($this->ids['both_a1']);
        Assert::same(self::ENCOUNTER_KEYS, array_keys($encounter));
        Assert::same([
            'id' => $this->ids['both_a1'],
            'patient_id' => $patient,
            'started_at' => '2031-09-05 09:00:00',
            'status' => 'finished',
            'anamnesis_text' => 'F8B teste anamnese A1',
            'physical_exam_text' => 'F8B teste exame fisico A1',
            'diagnosis_text' => 'F8B teste diagnostico A1',
            'clinical_plan_text' => 'F8B teste plano A1',
            'weight_kg' => '11.20',
        ], $encounter);
    }

    public function testPatientWeightIsTheLatestOfTheUnit(): void
    {
        $row = $this->query($this->tenantA)->patient($this->ids['both']);

        Assert::same([
            'id' => $this->ids['both'],
            'name' => 'F8B teste Rex Both ' . $this->token,
            'species' => 'canino',
            'breed' => 'SRD',
            'sex' => 'M',
            'birth_date' => '2024-03-15',
            'allergies' => 'F8B teste dipirona',
            'weight_kg' => '11.20',
            'weight_measured_at' => '2031-09-05 09:00:00',
            'tutor_full_name' => 'F8B teste Tutor A',
        ], $row, 'encounter A2 has no weight; the newer one in unit B (13.90) is ignored');
        Assert::same(['F8B teste Tutor A'], $this->query($this->tenantA)->tutorNames($this->ids['both']));
    }

    public function testAnotherTenantIsNotFound(): void
    {
        $query = $this->query($this->tenantA);
        $other = $this->ids['tenant_b_patient'];

        Assert::false($query->patientInUnit($other), 'same unit, other tenant');
        Assert::same(null, $query->patient($other));
        Assert::same([], $query->encounters($other, 20));
        Assert::same([], $query->tutorNames($other));
        Assert::false($query->encounterInUnit($this->ids['tenant_b_encounter']));
        Assert::same(null, $query->encounter($this->ids['tenant_b_encounter']));
        Assert::same([], $query->searchPatients((string) $other, 20));
        Assert::same([], $query->searchPatients('Rex TenantB ' . $this->token, 20));

        Assert::notNull($this->query($this->tenantB)->patient($other), 'tenant B reads its own patient');
        Assert::same(null, $this->query($this->tenantB)->patient($this->ids['both']));
    }

    public function testSearchByIdNameOrTutorWithinTheUnitAndRespectsTheLimit(): void
    {
        $query = $this->query($this->tenantA);

        $byId = $query->searchPatients((string) $this->ids['both'], 20);
        Assert::same([$this->ids['both']], array_column($byId, 'id'), 'digits-only term matches patient.id');
        Assert::same(self::SEARCH_KEYS, array_keys($byId[0]));
        Assert::same('F8B teste Tutor A', $byId[0]['tutor_full_name']);

        $byName = array_column($query->searchPatients('Match ' . $this->token, 20), 'id');
        sort($byName);
        Assert::same([$this->ids['match1'], $this->ids['match2'], $this->ids['match3']], $byName);
        Assert::count(2, $query->searchPatients('Match ' . $this->token, 2), 'limit is respected');

        $byTutor = array_column($query->searchPatients('Tutor Busca ' . $this->token, 20), 'id');
        Assert::same([$this->ids['match1']], $byTutor, 'tutor.full_name matches too');

        Assert::same([], $query->searchPatients('%', 20), 'LIKE wildcards are literal');
        Assert::same([], $query->searchPatients('   ', 20));
    }

    public function testActivePrescriptionsExcludeExpiredAndOtherUnits(): void
    {
        $query = $this->query($this->tenantA);
        $patient = $this->ids['both'];

        $all = $query->prescriptions($patient, false, $this->today(), 20);
        Assert::same(
            ['F8B teste Valida', 'F8B teste Recente', 'F8B teste Antiga', 'F8B teste Vencida'],
            array_column($all, 'medication_name'),
            'unit A only (unit B item excluded), most recent first',
        );
        Assert::same(self::PRESCRIPTION_KEYS, array_keys($all[0]));
        Assert::same([
            'prescription_id' => $this->ids['rx_valid'],
            'created_at' => '2031-09-10 10:00:00',
            'valid_until' => '2031-09-30',
            'status' => 'issued',
            'medication_name' => 'F8B teste Valida',
            'dose' => '250',
            'dose_unit' => 'mg',
            'route' => 'oral',
            'frequency' => '12/12 h',
            'duration' => '7 dias',
        ], $all[0]);

        $active = $query->prescriptions($patient, true, $this->today(), 20);
        Assert::same(
            ['F8B teste Valida', 'F8B teste Recente'],
            array_column($active, 'medication_name'),
            'expired valid_until and old prescription without valid_until are not active',
        );
        Assert::count(1, $query->prescriptions($patient, false, $this->today(), 1), 'limit is respected');
    }

    public function testExamsBySituation(): void
    {
        $query = $this->query($this->tenantA);
        $patient = $this->ids['both'];

        $pending = $query->exams($patient, 'pendentes', 20);
        Assert::same([$this->ids['exam_review'], $this->ids['exam_waiting']], array_column($pending, 'exam_request_id'));
        Assert::same([
            'exam_request_id' => $this->ids['exam_review'],
            'exam_name' => 'F8B teste Hemograma',
            'requested_at' => '2031-09-10 11:00:00',
            'status' => 'result_available',
            'pending_review' => true,
            'structured_result_text' => 'F8B teste resultado em revisao',
            'received_at' => '2031-09-10 15:00:00',
        ], $pending[0]);
        Assert::same(self::EXAM_KEYS, array_keys($pending[1]));
        Assert::same(null, $pending[1]['pending_review'], 'no result yet');
        Assert::same(null, $pending[1]['received_at']);

        $released = $query->exams($patient, 'liberados', 20);
        Assert::same([$this->ids['exam_released']], array_column($released, 'exam_request_id'));
        Assert::same(false, $released[0]['pending_review']);

        $all = array_column($query->exams($patient, 'todos', 20), 'exam_request_id');
        sort($all);
        $expected = [$this->ids['exam_review'], $this->ids['exam_waiting'], $this->ids['exam_released']];
        sort($expected);
        Assert::same($expected, $all, 'unit B exam excluded');
        Assert::count(1, $query->exams($patient, 'todos', 1), 'limit is respected');

        Assert::throws(\InvalidArgumentException::class, static fn () => $query->exams($patient, 'outros', 20));
    }

    public function testVaccinationsMarkSupersededDoses(): void
    {
        $rows = $this->query($this->tenantA)->vaccinations($this->ids['both'], 20);

        Assert::count(2, $rows, 'unit B application excluded');
        Assert::same(self::VACCINATION_KEYS, array_keys($rows[0]));
        Assert::same([
            'vaccine_catalog_item_id' => $this->ids['vaccine'],
            'vaccine_name' => 'F8B teste V10',
            'applied_at' => '2031-09-10 12:00:00',
            'next_dose_at' => '2032-09-10',
            'superseded' => false,
        ], $rows[0]);
        Assert::same('2031-08-10 12:00:00', $rows[1]['applied_at']);
        Assert::true($rows[1]['superseded'], 'a later booster supersedes the first dose');
    }

    public function testNoTutorContactLeaks(): void
    {
        $query = $this->query($this->tenantA);
        $patient = $this->ids['both'];
        $payload = [
            $query->searchPatients((string) $patient, 20),
            $query->patient($patient),
            $query->encounters($patient, 20),
            $query->encounter($this->ids['both_a1']),
            $query->prescriptions($patient, false, $this->today(), 20),
            $query->exams($patient, 'todos', 20),
            $query->vaccinations($patient, 20),
            $query->tutorNames($patient),
        ];

        $flat = json_encode($payload, JSON_THROW_ON_ERROR);
        foreach (['85999990021', 'f8b.teste@example.invalid', 'F8B-DOC-A', 'F8B teste Rua A'] as $value) {
            Assert::false(str_contains($flat, $value), 'tutor contact, document or address must not be returned');
        }
        foreach (['phone', 'email', 'document', 'address', 'cpf'] as $key) {
            Assert::false(str_contains($flat, '"' . $key), "key {$key} must not be returned");
        }
    }

    private function seed(): void
    {
        $tutorA = $this->insertTutor($this->tenantA, 'F8B teste Tutor A', 'A');
        $tutorSearch = $this->insertTutor($this->tenantA, 'F8B teste Tutor Busca ' . $this->token, 'S');
        $tutorB = $this->insertTutor($this->tenantB, 'F8B teste Tutor B', 'B');

        $this->ids['only_b'] = $this->insertPatient($this->tenantA, $tutorA, 'F8B teste Rex OnlyB ' . $this->token);
        $this->ids['only_b_encounter'] = $this->insertEncounter($this->tenantA, $this->unitB, $this->ids['only_b'], 'OB', '2031-09-01 09:00:00', null);

        $patient = $this->ids['both'] = $this->insertPatient($this->tenantA, $tutorA, 'F8B teste Rex Both ' . $this->token);
        $a1 = $this->ids['both_a1'] = $this->insertEncounter($this->tenantA, $this->unitA, $patient, 'A1', '2031-09-05 09:00:00', '11.20');
        $a2 = $this->ids['both_a2'] = $this->insertEncounter($this->tenantA, $this->unitA, $patient, 'A2', '2031-09-10 09:00:00', null);
        $b = $this->ids['both_b'] = $this->insertEncounter($this->tenantA, $this->unitB, $patient, 'B', '2031-09-15 09:00:00', '13.90');

        $this->ids['rx_valid'] = $this->insertPrescription($a2, $patient, 'F8B teste Valida', '2031-09-10 10:00:00', '2031-09-30');
        $this->insertPrescription($a2, $patient, 'F8B teste Recente', '2031-09-09 10:00:00', null);
        $this->insertPrescription($a1, $patient, 'F8B teste Antiga', '2031-08-01 10:00:00', null);
        $this->insertPrescription($a1, $patient, 'F8B teste Vencida', '2031-07-01 10:00:00', '2031-09-19');
        $this->insertPrescription($b, $patient, 'F8B teste Outra Unidade', '2031-09-15 10:00:00', '2031-12-31');

        $catalog = $this->insert('exam_catalog_item', ['tenant_id' => $this->tenantA, 'name' => 'F8B teste Hemograma', 'price_cents' => 9000]);
        $this->ids['exam_review'] = $this->insertExam($a2, $patient, $catalog, '2031-09-10 11:00:00', ['F8B teste resultado em revisao', 1, '2031-09-10 15:00:00']);
        $this->ids['exam_waiting'] = $this->insertExam($a2, $patient, $catalog, '2031-09-09 11:00:00', null);
        $this->ids['exam_released'] = $this->insertExam($a1, $patient, $catalog, '2031-09-05 11:00:00', ['F8B teste resultado liberado', 0, '2031-09-06 15:00:00']);
        $this->insertExam($b, $patient, $catalog, '2031-09-15 11:00:00', null);

        $vaccine = $this->ids['vaccine'] = $this->insert('vaccine_catalog_item', ['tenant_id' => $this->tenantA, 'name' => 'F8B teste V10']);
        $this->insertVaccination($a1, $patient, $vaccine, '2031-08-10 12:00:00', '2031-09-10', 1);
        $this->insertVaccination($a2, $patient, $vaccine, '2031-09-10 12:00:00', '2032-09-10', 2);
        $this->insertVaccination($b, $patient, $vaccine, '2031-09-15 12:00:00', null, 3);

        $this->ids['appointment'] = $this->insertPatient($this->tenantA, $tutorA, 'F8B teste Rex Agenda ' . $this->token);
        $service = $this->insert('service', ['tenant_id' => $this->tenantA, 'name' => 'F8B teste Consulta', 'duration_minutes' => 30, 'price_cents' => 10000]);
        $this->insert('appointment', [
            'tenant_id' => $this->tenantA,
            'system_unit_id' => $this->unitA,
            'patient_id' => $this->ids['appointment'],
            'service_id' => $service,
            'professional_system_user_id' => $this->userId,
            'scheduled_at' => '2031-09-25 10:00:00',
        ]);

        foreach ([1, 2, 3] as $n) {
            $tutor = $n === 1 ? $tutorSearch : $tutorA;
            $this->ids['match' . $n] = $this->insertPatient($this->tenantA, $tutor, "F8B teste Match {$this->token} {$n}");
            $this->insertEncounter($this->tenantA, $this->unitA, $this->ids['match' . $n], 'M' . $n, '2031-09-02 09:00:00', null);
        }

        $this->ids['tenant_b_patient'] = $this->insertPatient($this->tenantB, $tutorB, 'F8B teste Rex TenantB ' . $this->token);
        $this->ids['tenant_b_encounter'] = $this->insertEncounter($this->tenantB, $this->unitA, $this->ids['tenant_b_patient'], 'TB', '2031-09-03 09:00:00', null);
    }

    private function insertTutor(int $tenantId, string $name, string $suffix): int
    {
        return $this->insert('tutor', [
            'tenant_id' => $tenantId,
            'public_id' => $this->uuid(),
            'full_name' => $name,
            'document' => 'F8B-DOC-' . $suffix,
            'phone' => '8599999002' . (string) strlen($suffix),
            'email' => 'f8b.teste@example.invalid',
            'address' => 'F8B teste Rua ' . $suffix,
        ]);
    }

    private function insertPatient(int $tenantId, int $tutorId, string $name): int
    {
        return $this->insert('patient', [
            'tenant_id' => $tenantId,
            'tutor_id' => $tutorId,
            'name' => $name,
            'species' => 'canino',
            'breed' => 'SRD',
            'sex' => 'M',
            'birth_date' => '2024-03-15',
            'weight_kg' => '7.50',
            'allergies' => 'F8B teste dipirona',
        ]);
    }

    private function insertEncounter(int $tenantId, int $unitId, int $patientId, string $label, string $startedAt, ?string $weight): int
    {
        return $this->insert('encounter', [
            'tenant_id' => $tenantId,
            'system_unit_id' => $unitId,
            'patient_id' => $patientId,
            'professional_system_user_id' => $this->userId,
            'status' => 'finished',
            'started_at' => $startedAt,
            'anamnesis_text' => "F8B teste anamnese {$label}",
            'physical_exam_text' => "F8B teste exame fisico {$label}",
            'diagnosis_text' => "F8B teste diagnostico {$label}",
            'clinical_plan_text' => "F8B teste plano {$label}",
            'weight_kg' => $weight,
        ]);
    }

    private function insertPrescription(int $encounterId, int $patientId, string $medication, string $createdAt, ?string $validUntil): int
    {
        $prescription = $this->insert('prescription', [
            'tenant_id' => $this->tenantA,
            'encounter_id' => $encounterId,
            'patient_id' => $patientId,
            'professional_system_user_id' => $this->userId,
            'status' => 'issued',
            'valid_until' => $validUntil,
            'created_at' => $createdAt,
        ]);
        $this->insert('prescription_item', [
            'tenant_id' => $this->tenantA,
            'prescription_id' => $prescription,
            'medication_name' => $medication,
            'dose' => '250',
            'dose_unit' => 'mg',
            'route' => 'oral',
            'frequency' => '12/12 h',
            'duration' => '7 dias',
        ]);

        return $prescription;
    }

    /** @param array{0: string, 1: int, 2: string}|null $result */
    private function insertExam(int $encounterId, int $patientId, int $catalogId, string $requestedAt, ?array $result): int
    {
        $request = $this->insert('exam_request', [
            'tenant_id' => $this->tenantA,
            'encounter_id' => $encounterId,
            'patient_id' => $patientId,
            'exam_catalog_item_id' => $catalogId,
            'professional_system_user_id' => $this->userId,
            'status' => $result === null ? 'requested' : 'result_available',
            'requested_at' => $requestedAt,
        ]);

        if ($result !== null) {
            $this->insert('exam_result', [
                'tenant_id' => $this->tenantA,
                'exam_request_id' => $request,
                'structured_result_text' => $result[0],
                'pending_review' => $result[1],
                'received_at' => $result[2],
            ]);
        }

        return $request;
    }

    private function insertVaccination(int $encounterId, int $patientId, int $vaccineId, string $appliedAt, ?string $nextDose, int $dose): void
    {
        $this->insert('vaccination', [
            'tenant_id' => $this->tenantA,
            'encounter_id' => $encounterId,
            'patient_id' => $patientId,
            'vaccine_catalog_item_id' => $vaccineId,
            'dose_number' => $dose,
            'professional_system_user_id' => $this->userId,
            'applied_at' => $appliedAt,
            'next_dose_at' => $nextDose,
        ]);
    }

    private function today(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2031-09-20');
    }

    private function query(int $tenantId, ?int $unitId = null): CopilotClinicalQuery
    {
        return new CopilotClinicalQuery(
            TenantContext::authenticated($tenantId, $this->userId, $unitId ?? $this->unitA),
            $this->pdo,
        );
    }

    /**
     * Second system_unit (any other than unit A); when the test database has
     * only one, creates one inside the test transaction (rolled back).
     * system_unit.id is not AUTO_INCREMENT (Adianti).
     */
    private function otherUnitId(): int
    {
        $statement = $this->pdo->prepare('SELECT id FROM system_unit WHERE id <> ? ORDER BY id LIMIT 1');
        $statement->execute([$this->unitA]);
        $id = (int) $statement->fetchColumn();

        if ($id > 0) {
            return $id;
        }

        $id = (int) $this->pdo->query('SELECT COALESCE(MAX(id), 0) + 1 FROM system_unit')->fetchColumn();
        $this->pdo->prepare('INSERT INTO system_unit (id, tenant_id, name) VALUES (:id, :tenant_id, :name)')
            ->execute(['id' => $id, 'tenant_id' => $this->tenantA, 'name' => 'F8B teste outra unidade']);

        return $id;
    }

    private function createTenant(string $slugPrefix): int
    {
        $slug = $slugPrefix . '-' . bin2hex(random_bytes(4));

        return $this->insert('tenant', [
            'public_id' => $this->uuid(),
            'slug' => $slug,
            'legal_name' => 'F8B teste tenant (' . $slug . ')',
            'status' => 'active',
        ]);
    }

    /** @param array<string, int|string|null> $row */
    private function insert(string $table, array $row): int
    {
        $columns = array_keys($row);
        $statement = $this->pdo->prepare(sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $table,
            implode(', ', $columns),
            implode(', ', array_map(static fn (string $c): string => ':' . $c, $columns)),
        ));
        $statement->execute($row);

        return (int) $this->pdo->lastInsertId();
    }

    private function uuid(): string
    {
        return (string) $this->pdo->query('SELECT UUID()')->fetchColumn();
    }
}
