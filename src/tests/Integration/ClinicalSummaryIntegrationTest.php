<?php

declare(strict_types=1);

namespace CentralVet\Tests\Integration;

use CentralVet\Application\ClinicalSummaryService;
use CentralVet\Persistence\ClinicalSummaryReader;
use CentralVet\Tenancy\TenantContext;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\MysqlIntegrationTestCase;

/**
 * Proves ClinicalSummaryService (patient card, last encounter, prescription
 * history and encounter plan items) against the real, already-applied
 * schema. Every fixture row (two throwaway tenants, tutor, patient, two
 * encounters, prescriptions with items, an exam request, a procedure
 * execution and a vaccination) lives only inside this test's transaction and
 * is rolled back in tearDown() (MysqlIntegrationTestCase).
 */
final class ClinicalSummaryIntegrationTest extends MysqlIntegrationTestCase
{
    private int $tenantId;
    private int $foreignTenantId;
    private int $systemUnitId;
    private int $userId;
    private int $tutorId;
    private int $patientId;
    private int $foreignPatientId;
    private int $previousEncounterId;
    private int $currentEncounterId;
    private int $oldPrescriptionId;
    private int $newPrescriptionId;

    public function setUp(): void
    {
        parent::setUp();

        $this->tenantId = $this->createTenant('clinical-summary-a');
        $this->foreignTenantId = $this->createTenant('clinical-summary-b');

        $unitId = $this->pdo->query('SELECT id FROM system_unit LIMIT 1')->fetchColumn();
        Assert::true($unitId !== false, 'Fixture requires at least one existing system_unit row');
        $this->systemUnitId = (int) $unitId;

        $userId = $this->pdo->query('SELECT id FROM system_users LIMIT 1')->fetchColumn();
        Assert::true($userId !== false, 'Fixture requires at least one existing system_users row');
        $this->userId = (int) $userId;

        $this->tutorId = $this->insert('tutor', [
            'tenant_id' => $this->tenantId,
            'public_id' => $this->uuid(),
            'full_name' => 'Maria Resumo Clínico',
            'phone' => '11988887777',
            'email' => 'maria.resumo@example.test',
        ]);
        $this->patientId = $this->insert('patient', [
            'tenant_id' => $this->tenantId,
            'tutor_id' => $this->tutorId,
            'name' => 'Thor (resumo)',
            'species' => 'canino',
            'breed' => 'Labrador',
            'sex' => 'M',
            'birth_date' => '2021-03-10',
            'weight_kg' => '32.50',
        ]);

        $foreignTutorId = $this->insert('tutor', [
            'tenant_id' => $this->foreignTenantId,
            'public_id' => $this->uuid(),
            'full_name' => 'Tutor de outro tenant',
            'phone' => '11900000000',
        ]);
        $this->foreignPatientId = $this->insert('patient', [
            'tenant_id' => $this->foreignTenantId,
            'tutor_id' => $foreignTutorId,
            'name' => 'Paciente alheio',
            'species' => 'felino',
        ]);

        $longAnamnesis = str_repeat('Vômito recorrente há três dias, apetite reduzido. ', 5);
        $this->previousEncounterId = $this->insert('encounter', [
            'tenant_id' => $this->tenantId,
            'system_unit_id' => $this->systemUnitId,
            'patient_id' => $this->patientId,
            'professional_system_user_id' => $this->userId,
            'status' => 'finished',
            'started_at' => '2026-05-10 09:00:00.000000',
            'finished_at' => '2026-05-10 09:40:00.000000',
            'anamnesis_text' => $longAnamnesis,
            'diagnosis_text' => 'Gastrite aguda',
        ]);
        $this->currentEncounterId = $this->insert('encounter', [
            'tenant_id' => $this->tenantId,
            'system_unit_id' => $this->systemUnitId,
            'patient_id' => $this->patientId,
            'professional_system_user_id' => $this->userId,
            'status' => 'in_progress',
            'started_at' => '2026-09-20 10:00:00.000000',
        ]);

        $this->oldPrescriptionId = $this->insert('prescription', [
            'tenant_id' => $this->tenantId,
            'encounter_id' => $this->previousEncounterId,
            'patient_id' => $this->patientId,
            'professional_system_user_id' => $this->userId,
            'status' => 'issued',
            'created_at' => '2026-05-10 09:30:00.000000',
        ]);
        $this->insertPrescriptionItem($this->oldPrescriptionId, 'Omeprazol');

        $this->newPrescriptionId = $this->insert('prescription', [
            'tenant_id' => $this->tenantId,
            'encounter_id' => $this->currentEncounterId,
            'patient_id' => $this->patientId,
            'professional_system_user_id' => $this->userId,
            'status' => 'draft',
            'created_at' => '2026-09-20 10:20:00.000000',
        ]);
        $this->insertPrescriptionItem($this->newPrescriptionId, 'Amoxicilina');
        $this->insertPrescriptionItem($this->newPrescriptionId, 'Dipirona');

        $examCatalogId = $this->insert('exam_catalog_item', [
            'tenant_id' => $this->tenantId,
            'name' => 'Hemograma completo',
            'price_cents' => 9000,
        ]);
        $this->insert('exam_request', [
            'tenant_id' => $this->tenantId,
            'encounter_id' => $this->currentEncounterId,
            'patient_id' => $this->patientId,
            'exam_catalog_item_id' => $examCatalogId,
            'professional_system_user_id' => $this->userId,
            'status' => 'requested',
            'requested_at' => '2026-09-20 10:25:00.000000',
        ]);

        $procedureCatalogId = $this->insert('procedure_catalog_item', [
            'tenant_id' => $this->tenantId,
            'name' => 'Fluidoterapia',
            'price_cents' => 12000,
        ]);
        $this->insert('procedure_execution', [
            'tenant_id' => $this->tenantId,
            'encounter_id' => $this->currentEncounterId,
            'patient_id' => $this->patientId,
            'procedure_catalog_item_id' => $procedureCatalogId,
            'professional_system_user_id' => $this->userId,
            'notes_text' => 'Ringer lactato 500 ml',
            'executed_at' => '2026-09-20 10:30:00.000000',
        ]);

        $vaccineCatalogId = $this->insert('vaccine_catalog_item', [
            'tenant_id' => $this->tenantId,
            'name' => 'V10',
        ]);
        $this->insert('vaccination', [
            'tenant_id' => $this->tenantId,
            'encounter_id' => $this->currentEncounterId,
            'patient_id' => $this->patientId,
            'vaccine_catalog_item_id' => $vaccineCatalogId,
            'lot' => 'L123',
            'dose_number' => 2,
            'professional_system_user_id' => $this->userId,
            'applied_at' => '2026-09-20 10:35:00.000000',
        ]);
    }

    public function testPatientCardBringsTutorAndAgeLabel(): void
    {
        $card = $this->service($this->tenantId)->patientCard($this->patientId);

        Assert::notNull($card, 'patientCard() must find the tenant\'s own patient');
        Assert::same($this->patientId, $card['patient_id']);
        Assert::same('Thor (resumo)', $card['name']);
        Assert::same('canino', $card['species']);
        Assert::same('Labrador', $card['breed']);
        Assert::same('M', $card['sex']);
        Assert::same('2021-03-10', $card['birth_date']);
        Assert::same('5 anos', $card['age_label']);
        Assert::same(32.5, $card['weight_kg']);
        Assert::same($this->tutorId, $card['tutor_id']);
        Assert::same('Maria Resumo Clínico', $card['tutor_name']);
        Assert::same('11988887777', $card['tutor_phone']);
        Assert::same('maria.resumo@example.test', $card['tutor_email']);
    }

    public function testAgeLabelInMonthsAndNullWithoutBirthDate(): void
    {
        $puppyId = $this->insert('patient', [
            'tenant_id' => $this->tenantId,
            'tutor_id' => $this->tutorId,
            'name' => 'Filhote',
            'species' => 'felino',
            'birth_date' => '2026-01-15',
        ]);
        $unknownId = $this->insert('patient', [
            'tenant_id' => $this->tenantId,
            'tutor_id' => $this->tutorId,
            'name' => 'Sem data',
            'species' => 'felino',
        ]);

        $service = $this->service($this->tenantId);
        $puppy = $service->patientCard($puppyId);
        $unknown = $service->patientCard($unknownId);

        Assert::same('8 meses', $puppy['age_label']);
        Assert::null($unknown['age_label']);
        Assert::null($unknown['breed']);
        Assert::null($unknown['weight_kg']);
    }

    public function testPatientCardOfForeignTenantReturnsNull(): void
    {
        Assert::null(
            $this->service($this->tenantId)->patientCard($this->foreignPatientId),
            'patientCard() must return null for a patient of another tenant',
        );
        Assert::null(
            $this->service($this->foreignTenantId)->patientCard($this->patientId),
            'patientCard() must return null when the current tenant does not own the patient',
        );
    }

    public function testLastEncounterExcludesCurrentAndTruncatesExcerpts(): void
    {
        $service = $this->service($this->tenantId);

        $last = $service->lastEncounter($this->patientId, $this->currentEncounterId);

        Assert::notNull($last);
        Assert::same($this->previousEncounterId, $last['id']);
        Assert::same('finished', $last['status']);
        Assert::stringContains('2026-05-10 09:00:00', $last['started_at']);
        Assert::same($this->userId, $last['professional_system_user_id']);
        Assert::true(mb_strlen((string) $last['anamnesis_excerpt']) <= 80, 'anamnesis_excerpt must have at most 80 characters');
        Assert::true(str_starts_with((string) $last['anamnesis_excerpt'], 'Vômito recorrente'));
        Assert::same('Gastrite aguda', $last['diagnosis_excerpt']);

        $latest = $service->lastEncounter($this->patientId);
        Assert::same($this->currentEncounterId, $latest['id'], 'Without exclusion the most recent encounter is returned');
        Assert::null($latest['anamnesis_excerpt']);

        Assert::null($this->service($this->foreignTenantId)->lastEncounter($this->patientId));
    }

    public function testPrescriptionHistoryIsMostRecentFirst(): void
    {
        $history = $this->service($this->tenantId)->prescriptionHistory($this->patientId);

        Assert::count(2, $history);
        Assert::same($this->newPrescriptionId, $history[0]['id']);
        Assert::same('Amoxicilina', $history[0]['first_medication']);
        Assert::same(2, $history[0]['items_count']);
        Assert::same('draft', $history[0]['status']);
        Assert::same($this->userId, $history[0]['professional_system_user_id']);
        Assert::same($this->oldPrescriptionId, $history[1]['id']);
        Assert::same('Omeprazol', $history[1]['first_medication']);
        Assert::same(1, $history[1]['items_count']);

        Assert::count(1, $this->service($this->tenantId)->prescriptionHistory($this->patientId, 1));
        Assert::same([], $this->service($this->foreignTenantId)->prescriptionHistory($this->patientId));
    }

    public function testEncounterPlanItemsGroupsByType(): void
    {
        $plan = $this->service($this->tenantId)->encounterPlanItems($this->currentEncounterId);

        Assert::same(['prescriptions', 'exams', 'procedures', 'vaccines'], array_keys($plan));
        Assert::count(1, $plan['prescriptions']);
        Assert::same($this->newPrescriptionId, $plan['prescriptions'][0]['id']);
        Assert::stringContains('Amoxicilina', $plan['prescriptions'][0]['title']);
        Assert::count(1, $plan['exams']);
        Assert::same('Hemograma completo', $plan['exams'][0]['title']);
        Assert::count(1, $plan['procedures']);
        Assert::same('Fluidoterapia', $plan['procedures'][0]['title']);
        Assert::same('Ringer lactato 500 ml', $plan['procedures'][0]['detail']);
        Assert::count(1, $plan['vaccines']);
        Assert::same('V10', $plan['vaccines'][0]['title']);
        Assert::stringContains('2', (string) $plan['vaccines'][0]['detail']);

        foreach ($plan as $items) {
            foreach ($items as $item) {
                Assert::same(['id', 'title', 'detail', 'created_at'], array_keys($item));
                Assert::true(is_int($item['id']) && is_string($item['created_at']));
            }
        }

        $previous = $this->service($this->tenantId)->encounterPlanItems($this->previousEncounterId);
        Assert::count(1, $previous['prescriptions']);
        Assert::same([], $previous['exams']);

        $foreign = $this->service($this->foreignTenantId)->encounterPlanItems($this->currentEncounterId);
        Assert::same(['prescriptions' => [], 'exams' => [], 'procedures' => [], 'vaccines' => []], $foreign);
    }

    private function service(int $tenantId): ClinicalSummaryService
    {
        $context = TenantContext::authenticated($tenantId, $this->userId, $this->systemUnitId);

        return new ClinicalSummaryService(
            new ClinicalSummaryReader($context, $this->pdo),
            new \DateTimeImmutable('2026-09-29'),
        );
    }

    private function createTenant(string $slugPrefix): int
    {
        $slug = $slugPrefix . '-' . bin2hex(random_bytes(4));

        return $this->insert('tenant', [
            'public_id' => $this->uuid(),
            'slug' => $slug,
            'legal_name' => 'Clinical summary test tenant (' . $slug . ')',
            'status' => 'active',
        ]);
    }

    private function insertPrescriptionItem(int $prescriptionId, string $medication): void
    {
        $this->insert('prescription_item', [
            'tenant_id' => $this->tenantId,
            'prescription_id' => $prescriptionId,
            'medication_name' => $medication,
            'dose' => '1',
            'dose_unit' => 'comprimido',
            'route' => 'oral',
            'frequency' => '12/12h',
            'duration' => '7 dias',
        ]);
    }

    /** @param array<string, int|string> $values */
    private function insert(string $table, array $values): int
    {
        $columns = array_keys($values);
        $statement = $this->pdo->prepare(sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $table,
            implode(', ', $columns),
            implode(', ', array_map(static fn (string $c): string => ':' . $c, $columns)),
        ));
        $statement->execute($values);

        return (int) $this->pdo->lastInsertId();
    }

    private function uuid(): string
    {
        return (string) $this->pdo->query('SELECT UUID()')->fetchColumn();
    }
}
