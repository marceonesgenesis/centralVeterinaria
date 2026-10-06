<?php

declare(strict_types=1);

namespace CentralVet\Tests\Integration;

use CentralVet\Persistence\DocumentSourceQuery;
use CentralVet\Tenancy\TenantContext;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\MysqlIntegrationTestCase;

/**
 * Fase 7B, T-07: the document sources (patient, vaccinations, prescription,
 * surgery, tutor contact) read against the real schema in centralvet_test.
 * Every row belongs to throwaway tenants created inside the test
 * transaction, rolled back in tearDown().
 *
 * Fixture per tenant (A and B, same shape): tutor, patient, encounter in
 * unit B, two vaccinations inserted out of order (dose 2 first), one
 * prescription with two items and one surgery in unit A with consent.
 */
final class DocumentSourceQueryIntegrationTest extends MysqlIntegrationTestCase
{
    private int $userId;
    private string $userName;
    private int $unitA;
    private int $unitB;
    private int $tenantA;
    private int $tenantB;

    /** @var array<string, int> */
    private array $a = [];

    /** @var array<string, int> */
    private array $b = [];

    public function setUp(): void
    {
        parent::setUp();

        $user = $this->pdo->query('SELECT id, name FROM system_users ORDER BY id LIMIT 1')->fetch(\PDO::FETCH_ASSOC);
        Assert::true(is_array($user), 'Fixture requires at least one existing system_users row');
        $this->userId = (int) $user['id'];
        $this->userName = (string) $user['name'];

        $units = array_map('intval', $this->pdo->query('SELECT id FROM system_unit ORDER BY id LIMIT 2')->fetchAll(\PDO::FETCH_COLUMN));
        Assert::count(2, $units, 'Fixture requires two existing system_unit rows');
        [$this->unitA, $this->unitB] = $units;

        $this->tenantA = $this->createTenant('f7b-src-a');
        $this->tenantB = $this->createTenant('f7b-src-b');

        $this->a = $this->seedTenant($this->tenantA, 'A');
        $this->b = $this->seedTenant($this->tenantB, 'B');
    }

    public function testPatientSummaryCarriesTheTutorName(): void
    {
        $summary = $this->query($this->tenantA)->patientSummary($this->a['patient']);

        Assert::same([
            'patient_id' => $this->a['patient'],
            'patient_name' => 'F7B teste Rex A',
            'species' => 'canino',
            'breed' => null,
            'tutor_id' => $this->a['tutor'],
            'tutor_name' => 'F7B teste Tutor A',
        ], $summary);
    }

    public function testVaccinationsAreOrderedByAppliedAtWithTheVaccineName(): void
    {
        $doses = $this->query($this->tenantA)->vaccinations($this->a['patient']);

        Assert::same([
            [
                'vaccine_name' => 'F7B teste V10 A',
                'dose_number' => 1,
                'applied_at' => '2031-09-01 10:00:00',
                'lot' => 'L-001',
                'next_dose_at' => '2031-10-01',
                'professional_name' => $this->userName,
            ],
            [
                'vaccine_name' => 'F7B teste V10 A',
                'dose_number' => 2,
                'applied_at' => '2031-10-01 10:30:00',
                'lot' => null,
                'next_dose_at' => null,
                'professional_name' => $this->userName,
            ],
        ], $doses);
    }

    public function testPrescriptionCarriesItemsAndTheUnitOfTheEncounter(): void
    {
        $prescription = $this->query($this->tenantA)->prescription($this->a['prescription']);

        Assert::notNull($prescription);
        Assert::same($this->a['prescription'], $prescription['prescription_id']);
        Assert::same($this->a['patient'], $prescription['patient_id']);
        Assert::same($this->unitB, $prescription['system_unit_id'], 'unit of the encounter');
        Assert::same($this->userName, $prescription['professional_name']);
        Assert::same('F7B teste orientacao', $prescription['orientation_text']);
        Assert::same('2031-10-01 11:00:00', $prescription['created_at']);
        Assert::same([
            ['medication_name' => 'F7B teste Amoxicilina', 'dose' => '250', 'dose_unit' => 'mg', 'route' => 'oral', 'frequency' => '12/12 h', 'duration' => '7 dias'],
            ['medication_name' => 'F7B teste Dipirona', 'dose' => '1', 'dose_unit' => 'ml', 'route' => 'oral', 'frequency' => '8/8 h', 'duration' => '3 dias'],
        ], $prescription['items']);
    }

    public function testSurgeryCarriesTheConsent(): void
    {
        $surgery = $this->query($this->tenantA)->surgery($this->a['surgery']);

        Assert::same([
            'surgery_id' => $this->a['surgery'],
            'patient_id' => $this->a['patient'],
            'system_unit_id' => $this->unitA,
            'procedure_name' => 'F7B teste Orquiectomia A',
            'scheduled_start_at' => '2031-10-05 08:00:00',
            'surgeon_name' => $this->userName,
            'consent_signer_name' => 'F7B teste Tutor A',
            'consent_text' => 'F7B teste termo A',
            'consent_recorded_at' => '2031-10-04 17:00:00',
        ], $surgery);
    }

    public function testTutorContact(): void
    {
        Assert::same([
            'tutor_name' => 'F7B teste Tutor A',
            'email' => 'f7b.teste@example.invalid',
            'phone' => '85999990011',
        ], $this->query($this->tenantA)->tutorContact($this->a['tutor']));
    }

    public function testRowsOfAnotherTenantAreNotFound(): void
    {
        $query = $this->query($this->tenantA);

        Assert::same(null, $query->patientSummary($this->b['patient']));
        Assert::same([], $query->vaccinations($this->b['patient']));
        Assert::same(null, $query->prescription($this->b['prescription']));
        Assert::same(null, $query->surgery($this->b['surgery']));
        Assert::same(null, $query->tutorContact($this->b['tutor']));

        Assert::same('F7B teste Rex B', $this->query($this->tenantB)->patientSummary($this->b['patient'])['patient_name'] ?? null, 'tenant B reads its own patient');
    }

    public function testMissingIdsAreNotFound(): void
    {
        $query = $this->query($this->tenantA);

        Assert::same(null, $query->patientSummary(0));
        Assert::same([], $query->vaccinations(0));
        Assert::same(null, $query->prescription(0));
        Assert::same(null, $query->surgery(0));
        Assert::same(null, $query->tutorContact(0));
    }

    /**
     * @return array<string, int>
     */
    private function seedTenant(int $tenantId, string $suffix): array
    {
        $ids = [];
        $ids['tutor'] = $this->insert('tutor', [
            'tenant_id' => $tenantId,
            'public_id' => $this->uuid(),
            'full_name' => 'F7B teste Tutor ' . $suffix,
            'phone' => $suffix === 'A' ? '85999990011' : '85999990012',
            'email' => 'f7b.teste@example.invalid',
        ]);
        $ids['patient'] = $this->insert('patient', [
            'tenant_id' => $tenantId,
            'tutor_id' => $ids['tutor'],
            'name' => 'F7B teste Rex ' . $suffix,
            'species' => 'canino',
        ]);
        $ids['encounter'] = $this->insert('encounter', [
            'tenant_id' => $tenantId,
            'system_unit_id' => $this->unitB,
            'patient_id' => $ids['patient'],
            'professional_system_user_id' => $this->userId,
            'started_at' => '2031-09-01 09:00:00',
        ]);

        $v10 = $this->insert('vaccine_catalog_item', ['tenant_id' => $tenantId, 'name' => 'F7B teste V10 ' . $suffix]);
        $this->createVaccination($tenantId, $ids, $v10, 2, '2031-10-01 10:30:00', null, null);
        $this->createVaccination($tenantId, $ids, $v10, 1, '2031-09-01 10:00:00', '2031-10-01', 'L-001');

        $ids['prescription'] = $this->insert('prescription', [
            'tenant_id' => $tenantId,
            'encounter_id' => $ids['encounter'],
            'patient_id' => $ids['patient'],
            'professional_system_user_id' => $this->userId,
            'orientation_text' => 'F7B teste orientacao',
            'status' => 'issued',
            'created_at' => '2031-10-01 11:00:00',
        ]);
        foreach ([
            ['F7B teste Amoxicilina', '250', 'mg', 'oral', '12/12 h', '7 dias'],
            ['F7B teste Dipirona', '1', 'ml', 'oral', '8/8 h', '3 dias'],
        ] as [$name, $dose, $unit, $route, $frequency, $duration]) {
            $this->insert('prescription_item', [
                'tenant_id' => $tenantId,
                'prescription_id' => $ids['prescription'],
                'medication_name' => $name,
                'dose' => $dose,
                'dose_unit' => $unit,
                'route' => $route,
                'frequency' => $frequency,
                'duration' => $duration,
            ]);
        }

        $room = $this->insert('surgery_room', [
            'tenant_id' => $tenantId,
            'system_unit_id' => $this->unitA,
            'code' => 'F7B-' . $suffix . '-' . bin2hex(random_bytes(3)),
            'name' => 'F7B teste Sala ' . $suffix,
        ]);
        $procedure = $this->insert('procedure_catalog_item', [
            'tenant_id' => $tenantId,
            'name' => 'F7B teste Orquiectomia ' . $suffix,
            'price_cents' => 45000,
        ]);
        $ids['surgery'] = $this->insert('surgery', [
            'tenant_id' => $tenantId,
            'system_unit_id' => $this->unitA,
            'patient_id' => $ids['patient'],
            'encounter_id' => $ids['encounter'],
            'room_id' => $room,
            'procedure_catalog_item_id' => $procedure,
            'procedure_name' => 'F7B teste Orquiectomia ' . $suffix,
            'procedure_price_cents' => 45000,
            'surgeon_system_user_id' => $this->userId,
            'scheduled_by_system_user_id' => $this->userId,
            'scheduled_start_at' => '2031-10-05 08:00:00',
            'scheduled_end_at' => '2031-10-05 09:00:00',
            'status' => 'scheduled',
            'consent_signer_name' => 'F7B teste Tutor ' . $suffix,
            'consent_text' => 'F7B teste termo ' . $suffix,
            'consent_recorded_at' => '2031-10-04 17:00:00',
            'consent_recorded_by_system_user_id' => $this->userId,
        ]);

        return $ids;
    }

    /**
     * @param array<string, int> $ids
     */
    private function createVaccination(int $tenantId, array $ids, int $itemId, int $dose, string $appliedAt, ?string $nextDoseAt, ?string $lot): int
    {
        return $this->insert('vaccination', [
            'tenant_id' => $tenantId,
            'encounter_id' => $ids['encounter'],
            'patient_id' => $ids['patient'],
            'vaccine_catalog_item_id' => $itemId,
            'lot' => $lot,
            'dose_number' => $dose,
            'professional_system_user_id' => $this->userId,
            'applied_at' => $appliedAt,
            'next_dose_at' => $nextDoseAt,
        ]);
    }

    private function query(int $tenantId): DocumentSourceQuery
    {
        return new DocumentSourceQuery(TenantContext::authenticated($tenantId, $this->userId), $this->pdo);
    }

    private function createTenant(string $slugPrefix): int
    {
        $slug = $slugPrefix . '-' . bin2hex(random_bytes(4));

        return $this->insert('tenant', [
            'public_id' => $this->uuid(),
            'slug' => $slug,
            'legal_name' => 'F7B teste tenant (' . $slug . ')',
            'status' => 'active',
        ]);
    }

    /**
     * @param array<string, int|string|null> $row
     */
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
