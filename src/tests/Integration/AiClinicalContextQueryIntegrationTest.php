<?php

declare(strict_types=1);

namespace CentralVet\Tests\Integration;

use CentralVet\Domain\Contract\AiClinicalContextQueryInterface;
use CentralVet\Persistence\AiClinicalContextQuery;
use CentralVet\Tenancy\TenantContext;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\MysqlIntegrationTestCase;

/**
 * Fase 8A, T-09: the clinical context the AI may receive (encounter,
 * history, exam result) read against the real schema in centralvet_test.
 * Every row belongs to throwaway tenants created inside the test
 * transaction, rolled back in tearDown().
 *
 * Tenant A: tutor with phone/e-mail/document/address, patient, 7 encounters
 * (the last one, in unit B, is the "current" one), one prescription per
 * encounter, one exam request + result per encounter (6 results in older
 * encounters, plus one in the current encounter with a stored object).
 * Odd encounters carry a draft prescription and an exam result pending
 * review; even ones an issued prescription and a reviewed result (T-06).
 * Tenant B: tutor, patient and one encounter with one exam result.
 */
final class AiClinicalContextQueryIntegrationTest extends MysqlIntegrationTestCase
{
    private const ENCOUNTER_KEYS = ['encounter_id', 'system_unit_id', 'patient_id', 'status', 'anamnesis_text', 'patient_name', 'tutor_name', 'species', 'breed', 'sex', 'birth_date', 'weight_kg'];
    private const HISTORY_ENCOUNTER_KEYS = ['started_at', 'anamnesis_text', 'physical_exam_text', 'diagnosis_text', 'clinical_plan_text'];
    private const HISTORY_EXAM_KEYS = ['received_at', 'exam_name', 'result_text', 'pending_review'];
    private const EXAM_KEYS = ['exam_result_id', 'encounter_id', 'system_unit_id', 'patient_id', 'exam_name', 'result_text', 'received_at', 'pending_review', 'patient_name', 'tutor_name', 'species', 'breed', 'sex', 'birth_date'];

    private int $userId;
    private int $unitA;
    private int $unitB;
    private int $tenantA;
    private int $tenantB;

    /** @var array<string, mixed> */
    private array $a = [];

    /** @var array<string, mixed> */
    private array $b = [];

    public function setUp(): void
    {
        parent::setUp();

        $this->userId = (int) $this->pdo->query('SELECT id FROM system_users ORDER BY id LIMIT 1')->fetchColumn();
        Assert::true($this->userId > 0, 'Fixture requires at least one existing system_users row');

        $units = array_map('intval', $this->pdo->query('SELECT id FROM system_unit ORDER BY id LIMIT 2')->fetchAll(\PDO::FETCH_COLUMN));
        Assert::count(2, $units, 'Fixture requires two existing system_unit rows');
        [$this->unitA, $this->unitB] = $units;

        $this->tenantA = $this->createTenant('f8a-ctx-a');
        $this->tenantB = $this->createTenant('f8a-ctx-b');

        $this->a = $this->seedTenant($this->tenantA, 'A', 7);
        $this->b = $this->seedTenant($this->tenantB, 'B', 1);
    }

    public function testImplementsTheContract(): void
    {
        Assert::instanceOf(AiClinicalContextQueryInterface::class, $this->query($this->tenantA));
    }

    public function testEncounterContextHasTheExactKeysAndEncounterWeightWins(): void
    {
        $current = $this->a['current'];
        $context = $this->query($this->tenantA)->encounterContext($current);

        Assert::same([
            'encounter_id' => $current,
            'system_unit_id' => $this->unitB,
            'patient_id' => $this->a['patient'],
            'status' => 'in_progress',
            'anamnesis_text' => 'F8A teste anamnese A 7',
            'patient_name' => 'F8A teste Rex A',
            'tutor_name' => 'F8A teste Tutor A',
            'species' => 'canino',
            'breed' => 'SRD',
            'sex' => 'M',
            'birth_date' => '2024-03-15',
            'weight_kg' => '12.40',
        ], $context);
        Assert::same(self::ENCOUNTER_KEYS, array_keys($context));
    }

    public function testEncounterContextFallsBackToThePatientWeight(): void
    {
        $older = $this->a['encounters'][0];
        $context = $this->query($this->tenantA)->encounterContext($older);

        Assert::notNull($context);
        Assert::same('10.00', $context['weight_kg'], 'encounter without weight uses the patient weight');
        Assert::same($this->unitA, $context['system_unit_id']);
        Assert::same('finished', $context['status']);
    }

    public function testEncounterOfAnotherTenantIsNull(): void
    {
        Assert::same(null, $this->query($this->tenantA)->encounterContext($this->b['current']));
        Assert::same(null, $this->query($this->tenantB)->encounterContext($this->a['current']));
        Assert::same(null, $this->query($this->tenantA)->encounterContext(0));
        Assert::notNull($this->query($this->tenantB)->encounterContext($this->b['current']), 'tenant B reads its own encounter');
    }

    public function testPatientHistoryBringsFiveOfEachMostRecentFirstWithoutTheCurrentEncounter(): void
    {
        $history = $this->query($this->tenantA)->patientHistory($this->a['patient'], $this->a['current']);

        Assert::same(['encounters', 'prescriptions', 'exam_results'], array_keys($history));

        Assert::count(5, $history['encounters']);
        Assert::same(
            ['2031-09-06 09:00:00', '2031-09-05 09:00:00', '2031-09-04 09:00:00', '2031-09-03 09:00:00', '2031-09-02 09:00:00'],
            array_column($history['encounters'], 'started_at'),
            'current encounter (2031-09-07) excluded; most recent first; limit 5',
        );
        Assert::same([
            'started_at' => '2031-09-06 09:00:00',
            'anamnesis_text' => 'F8A teste anamnese A 6',
            'physical_exam_text' => 'F8A teste exame fisico A 6',
            'diagnosis_text' => 'F8A teste diagnostico A 6',
            'clinical_plan_text' => 'F8A teste plano A 6',
        ], $history['encounters'][0]);
        foreach ($history['encounters'] as $row) {
            Assert::same(self::HISTORY_ENCOUNTER_KEYS, array_keys($row));
        }

        Assert::count(5, $history['prescriptions']);
        Assert::same(['created_at', 'status', 'items'], array_keys($history['prescriptions'][0]));
        Assert::same('2031-09-06 10:00:00', $history['prescriptions'][0]['created_at']);
        Assert::same(['issued', 'draft', 'issued', 'draft', 'issued'], array_column($history['prescriptions'], 'status'));
        Assert::same(
            ['F8A teste Amoxicilina 250 mg, oral, 12/12 h, 7 dias', 'F8A teste Dipirona 1 ml, oral, 8/8 h, 3 dias'],
            $history['prescriptions'][0]['items'],
        );

        Assert::count(5, $history['exam_results']);
        Assert::same([
            'received_at' => '2031-09-06 15:00:00',
            'exam_name' => 'F8A teste Hemograma A',
            'result_text' => 'F8A teste resultado A 6',
            'pending_review' => false,
        ], $history['exam_results'][0]);
        Assert::same([false, true, false, true, false], array_column($history['exam_results'], 'pending_review'));
        foreach ($history['exam_results'] as $row) {
            Assert::same(self::HISTORY_EXAM_KEYS, array_keys($row));
        }
    }

    public function testPatientHistoryWithoutExclusionStartsAtTheCurrentEncounter(): void
    {
        $history = $this->query($this->tenantA)->patientHistory($this->a['patient'], null);

        Assert::count(5, $history['encounters']);
        Assert::same('2031-09-07 09:00:00', $history['encounters'][0]['started_at']);
        Assert::same('2031-09-07 15:00:00', $history['exam_results'][0]['received_at']);
    }

    public function testPatientHistoryOfAnotherTenantIsEmpty(): void
    {
        Assert::same(
            ['encounters' => [], 'prescriptions' => [], 'exam_results' => []],
            $this->query($this->tenantA)->patientHistory($this->b['patient'], null),
        );
    }

    public function testExamResultContextCarriesTheUnitOfTheRequestEncounter(): void
    {
        $result = $this->a['current_exam_result'];
        $context = $this->query($this->tenantA)->examResultContext($result);

        Assert::same([
            'exam_result_id' => $result,
            'encounter_id' => $this->a['current'],
            'system_unit_id' => $this->unitB,
            'patient_id' => $this->a['patient'],
            'exam_name' => 'F8A teste Hemograma A',
            'result_text' => 'F8A teste resultado A 7',
            'received_at' => '2031-09-07 15:00:00',
            'pending_review' => true,
            'patient_name' => 'F8A teste Rex A',
            'tutor_name' => 'F8A teste Tutor A',
            'species' => 'canino',
            'breed' => 'SRD',
            'sex' => 'M',
            'birth_date' => '2024-03-15',
        ], $context);
        Assert::same(self::EXAM_KEYS, array_keys($context));
    }

    public function testDraftPrescriptionsAndExamsPendingReviewStayInTheHistory(): void
    {
        $tenant = $this->createTenant('f8a-ctx-c');
        $seeded = $this->seedTenant($tenant, 'C', 2);
        $patient = $seeded['patient'];

        $prescriptions = $this->countRows('SELECT COUNT(*) FROM prescription WHERE tenant_id = ? AND patient_id = ?', $tenant, $patient);
        $exams = $this->countRows(
            'SELECT COUNT(*) FROM exam_result r JOIN exam_request er ON er.id = r.exam_request_id AND er.tenant_id = r.tenant_id WHERE r.tenant_id = ? AND er.patient_id = ?',
            $tenant,
            $patient,
        );
        Assert::same(2, $prescriptions);
        Assert::same(2, $exams);
        Assert::same(1, $this->countRows("SELECT COUNT(*) FROM prescription WHERE tenant_id = ? AND patient_id = ? AND status = 'draft'", $tenant, $patient));

        $history = $this->query($tenant)->patientHistory($patient, null);

        Assert::count($prescriptions, $history['prescriptions'], 'draft prescriptions are not dropped from the history');
        Assert::count($exams, $history['exam_results'], 'exam results pending review are not dropped from the history');
        Assert::same(['issued', 'draft'], array_column($history['prescriptions'], 'status'));
        Assert::same([false, true], array_column($history['exam_results'], 'pending_review'));
    }

    public function testExamResultOfAnotherTenantIsNull(): void
    {
        Assert::same(null, $this->query($this->tenantA)->examResultContext($this->b['current_exam_result']));
        Assert::same(null, $this->query($this->tenantA)->examResultContext(0));
        Assert::notNull($this->query($this->tenantB)->examResultContext($this->b['current_exam_result']));
    }

    public function testNoContactOrStorageKeyLeaks(): void
    {
        $query = $this->query($this->tenantA);
        $payloads = [
            $query->encounterContext($this->a['current']),
            $query->patientHistory($this->a['patient'], null),
            $query->examResultContext($this->a['current_exam_result']),
        ];

        foreach ($payloads as $payload) {
            $keys = self::nestedKeys($payload);
            foreach (['phone', 'email', 'stored_object_key', 'document', 'address'] as $forbidden) {
                foreach ($keys as $key) {
                    Assert::false(str_contains($key, $forbidden), "key {$key} must not be returned");
                }
            }
            $flat = json_encode($payload, JSON_THROW_ON_ERROR);
            foreach (['85999990011', 'f8a.teste@example.invalid', 'F8A-DOC-A', 'F8A teste Rua A', 'f8a/teste/laudo'] as $value) {
                Assert::false(str_contains($flat, $value), 'contact or storage value must not be returned');
            }
        }
    }

    /**
     * @param array<int|string, mixed> $payload
     * @return list<string>
     */
    private static function nestedKeys(array $payload): array
    {
        $keys = [];
        foreach ($payload as $key => $value) {
            $keys[] = (string) $key;
            if (is_array($value)) {
                $keys = array_merge($keys, self::nestedKeys($value));
            }
        }

        return $keys;
    }

    /**
     * @return array<string, mixed>
     */
    private function seedTenant(int $tenantId, string $suffix, int $encounterCount): array
    {
        $ids = [];
        $ids['tutor'] = $this->insert('tutor', [
            'tenant_id' => $tenantId,
            'public_id' => $this->uuid(),
            'full_name' => 'F8A teste Tutor ' . $suffix,
            'document' => 'F8A-DOC-' . $suffix,
            'phone' => $suffix === 'A' ? '85999990011' : '85999990012',
            'email' => 'f8a.teste@example.invalid',
            'address' => 'F8A teste Rua ' . $suffix,
        ]);
        $ids['patient'] = $this->insert('patient', [
            'tenant_id' => $tenantId,
            'tutor_id' => $ids['tutor'],
            'name' => 'F8A teste Rex ' . $suffix,
            'species' => 'canino',
            'breed' => 'SRD',
            'sex' => 'M',
            'birth_date' => '2024-03-15',
            'weight_kg' => '10.00',
        ]);
        $catalog = $this->insert('exam_catalog_item', [
            'tenant_id' => $tenantId,
            'name' => 'F8A teste Hemograma ' . $suffix,
            'price_cents' => 9000,
        ]);

        $ids['encounters'] = [];
        for ($n = 1; $n <= $encounterCount; $n++) {
            $isCurrent = $n === $encounterCount;
            $encounter = $this->insert('encounter', [
                'tenant_id' => $tenantId,
                'system_unit_id' => $isCurrent ? $this->unitB : $this->unitA,
                'patient_id' => $ids['patient'],
                'professional_system_user_id' => $this->userId,
                'status' => $isCurrent ? 'in_progress' : 'finished',
                'started_at' => sprintf('2031-09-%02d 09:00:00', $n),
                'anamnesis_text' => "F8A teste anamnese {$suffix} {$n}",
                'physical_exam_text' => "F8A teste exame fisico {$suffix} {$n}",
                'diagnosis_text' => "F8A teste diagnostico {$suffix} {$n}",
                'clinical_plan_text' => "F8A teste plano {$suffix} {$n}",
                'weight_kg' => $isCurrent ? '12.40' : null,
            ]);
            $ids['encounters'][] = $encounter;

            $prescription = $this->insert('prescription', [
                'tenant_id' => $tenantId,
                'encounter_id' => $encounter,
                'patient_id' => $ids['patient'],
                'professional_system_user_id' => $this->userId,
                'status' => $n % 2 === 1 ? 'draft' : 'issued',
                'created_at' => sprintf('2031-09-%02d 10:00:00', $n),
            ]);
            foreach ([
                ['F8A teste Amoxicilina', '250', 'mg', 'oral', '12/12 h', '7 dias'],
                ['F8A teste Dipirona', '1', 'ml', 'oral', '8/8 h', '3 dias'],
            ] as [$name, $dose, $unit, $route, $frequency, $duration]) {
                $this->insert('prescription_item', [
                    'tenant_id' => $tenantId,
                    'prescription_id' => $prescription,
                    'medication_name' => $name,
                    'dose' => $dose,
                    'dose_unit' => $unit,
                    'route' => $route,
                    'frequency' => $frequency,
                    'duration' => $duration,
                ]);
            }

            $request = $this->insert('exam_request', [
                'tenant_id' => $tenantId,
                'encounter_id' => $encounter,
                'patient_id' => $ids['patient'],
                'exam_catalog_item_id' => $catalog,
                'professional_system_user_id' => $this->userId,
                'status' => 'result_available',
                'requested_at' => sprintf('2031-09-%02d 11:00:00', $n),
            ]);
            $result = $this->insert('exam_result', [
                'tenant_id' => $tenantId,
                'exam_request_id' => $request,
                'structured_result_text' => "F8A teste resultado {$suffix} {$n}",
                'stored_object_key' => $isCurrent ? 'f8a/teste/laudo-' . $suffix . '.pdf' : null,
                'pending_review' => $n % 2 === 1 ? 1 : 0,
                'received_at' => sprintf('2031-09-%02d 15:00:00', $n),
            ]);

            if ($isCurrent) {
                $ids['current'] = $encounter;
                $ids['current_exam_result'] = $result;
            }
        }

        return $ids;
    }

    private function countRows(string $sql, int $tenantId, int $patientId): int
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute([$tenantId, $patientId]);

        return (int) $statement->fetchColumn();
    }

    private function query(int $tenantId): AiClinicalContextQuery
    {
        return new AiClinicalContextQuery(TenantContext::authenticated($tenantId, $this->userId), $this->pdo);
    }

    private function createTenant(string $slugPrefix): int
    {
        $slug = $slugPrefix . '-' . bin2hex(random_bytes(4));

        return $this->insert('tenant', [
            'public_id' => $this->uuid(),
            'slug' => $slug,
            'legal_name' => 'F8A teste tenant (' . $slug . ')',
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
