<?php

declare(strict_types=1);

namespace CentralVet\Tests\Integration;

use CentralVet\Domain\Contract\CopilotFinanceQueryInterface;
use CentralVet\Persistence\CopilotFinanceQuery;
use CentralVet\Persistence\FinancialOverviewReader;
use CentralVet\Tenancy\TenantContext;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\MysqlIntegrationTestCase;
use DateTimeImmutable;

/**
 * Fase 8B, T-21: open receivables and clinic indicators of the copilot,
 * scoped by tenant AND by the active unit. Every row lives in throwaway
 * tenants created inside the test transaction (rolled back in tearDown()).
 *
 * Receivables (today = 2031-09-20; due = created_at + 7 days):
 * - "overdue": open, unit A, created 2031-09-01 (due 2031-09-08);
 * - "due_today": open, unit A, created 2031-09-13 (due 2031-09-20, not overdue);
 * - "partial": partially_paid, unit A, created 2031-09-18 (due 2031-09-25);
 * - paid and cancelled in unit A; open in unit B; open in tenant B.
 * Indicators (period October 2031): "returning" has an encounter in A in
 * September and another in October; "new" has its first encounter in A in
 * October; unit B and tenant B encounters in October never count.
 */
final class CopilotFinanceQueryIntegrationTest extends MysqlIntegrationTestCase
{
    private const RECEIVABLE_KEYS = ['receivable_id', 'tutor_full_name', 'open_cents', 'due_date'];

    private int $userId;
    private int $unitA;
    private int $unitB;
    private int $tenantA;
    private int $tenantB;

    /** @var array<string, int> */
    private array $ids = [];

    public function setUp(): void
    {
        parent::setUp();

        $this->userId = (int) $this->pdo->query('SELECT id FROM system_users ORDER BY id LIMIT 1')->fetchColumn();
        $this->unitA = (int) $this->pdo->query('SELECT id FROM system_unit ORDER BY id LIMIT 1')->fetchColumn();
        Assert::true($this->userId > 0 && $this->unitA > 0, 'Fixture requires a system_users and a system_unit row');

        $this->tenantA = $this->createTenant('f8b-fin-a');
        $this->tenantB = $this->createTenant('f8b-fin-b');
        $this->unitB = $this->otherUnitId();

        $this->seed();
    }

    public function testImplementsTheContract(): void
    {
        Assert::instanceOf(CopilotFinanceQueryInterface::class, $this->query($this->tenantA));
    }

    public function testOverdueReceivablesOfTheActiveUnitOnly(): void
    {
        $rows = $this->query($this->tenantA)->openReceivables('vencidos', $this->today(), 50);

        Assert::same([[
            'receivable_id' => $this->ids['overdue'],
            'tutor_full_name' => 'F8B teste Tutor Debito',
            'open_cents' => 12000,
            'due_date' => '2031-09-08',
        ]], $rows, 'paid, cancelled, unit B and tenant B receivables never appear');
        Assert::same(self::RECEIVABLE_KEYS, array_keys($rows[0]));
    }

    public function testUpcomingReceivablesIncludeDueTodayAndPartiallyPaid(): void
    {
        $rows = $this->query($this->tenantA)->openReceivables('a_vencer', $this->today(), 50);

        Assert::same([$this->ids['due_today'], $this->ids['partial']], array_column($rows, 'receivable_id'), 'nearest due first');
        Assert::same(['2031-09-20', '2031-09-25'], array_column($rows, 'due_date'));
        Assert::same(7000, $rows[1]['open_cents'], 'total 10000 - paid 3000');
        Assert::count(1, $this->query($this->tenantA)->openReceivables('a_vencer', $this->today(), 1), 'limit is respected');
    }

    public function testReceivableOfAnotherUnitOrTenantIsNotListed(): void
    {
        $all = array_merge(
            $this->query($this->tenantA)->openReceivables('vencidos', $this->today(), 50),
            $this->query($this->tenantA)->openReceivables('a_vencer', $this->today(), 50),
        );
        $ids = array_column($all, 'receivable_id');

        foreach (['paid', 'cancelled', 'unit_b', 'tenant_b'] as $key) {
            Assert::false(in_array($this->ids[$key], $ids, true), $key . ' must not be listed in unit A');
        }

        Assert::same(
            [$this->ids['unit_b']],
            array_column($this->query($this->tenantA, $this->unitB)->openReceivables('vencidos', $this->today(), 50), 'receivable_id'),
            'unit B context sees its own receivable',
        );
    }

    public function testUnknownSituationIsRejected(): void
    {
        Assert::throws(\InvalidArgumentException::class, fn () => $this->query($this->tenantA)->openReceivables('pagos', $this->today(), 10));
    }

    public function testIndicatorsCountNewPatientAndReturningEncounter(): void
    {
        $from = new DateTimeImmutable('2031-10-01 00:00:00');
        $to = new DateTimeImmutable('2031-11-01 00:00:00');

        $indicators = $this->query($this->tenantA)->indicators($from, $to);

        Assert::same([
            'encounters' => 2,
            'revenue_cents' => 50000,
            'new_patients' => 1,
            'returning_encounters' => 1,
        ], $indicators);

        $reader = new FinancialOverviewReader(TenantContext::authenticated($this->tenantA, $this->userId, $this->unitA), $this->pdo);
        Assert::same($reader->totalsByType($this->unitA, '2031-10-01 00:00:00', '2031-11-01 00:00:00')['income'], $indicators['revenue_cents']);
    }

    public function testIndicatorsOfAnEmptyPeriodAreZero(): void
    {
        Assert::same(
            ['encounters' => 0, 'revenue_cents' => 0, 'new_patients' => 0, 'returning_encounters' => 0],
            $this->query($this->tenantA)->indicators(new DateTimeImmutable('2030-01-01'), new DateTimeImmutable('2030-02-01')),
        );
    }

    private function seed(): void
    {
        $tutor = $this->insertTutor($this->tenantA, 'F8B teste Tutor Debito');
        $tutorB = $this->insertTutor($this->tenantB, 'F8B teste Tutor Outro');

        $returning = $this->insertPatient($this->tenantA, $tutor, 'F8B teste Rex Retorno');
        $this->insertEncounter($this->tenantA, $this->unitA, $returning, '2031-09-10 09:00:00');
        $this->insertEncounter($this->tenantA, $this->unitA, $returning, '2031-10-05 09:00:00');

        $new = $this->insertPatient($this->tenantA, $tutor, 'F8B teste Rex Novo');
        $this->insertEncounter($this->tenantA, $this->unitA, $new, '2031-10-10 09:00:00');

        $onlyB = $this->insertPatient($this->tenantA, $tutor, 'F8B teste Rex Unidade B');
        $this->insertEncounter($this->tenantA, $this->unitB, $onlyB, '2031-10-12 09:00:00');

        $otherTenant = $this->insertPatient($this->tenantB, $tutorB, 'F8B teste Rex Outro Tenant');
        $this->insertEncounter($this->tenantB, $this->unitA, $otherTenant, '2031-10-13 09:00:00');

        $this->ids['overdue'] = $this->receivable($this->tenantA, $this->unitA, $returning, $tutor, 12000, 0, 'open', '2031-09-01 10:00:00');
        $this->ids['due_today'] = $this->receivable($this->tenantA, $this->unitA, $returning, $tutor, 4000, 0, 'open', '2031-09-13 10:00:00');
        $this->ids['partial'] = $this->receivable($this->tenantA, $this->unitA, $returning, $tutor, 10000, 3000, 'partially_paid', '2031-09-18 10:00:00');
        $this->ids['paid'] = $this->receivable($this->tenantA, $this->unitA, $returning, $tutor, 5000, 5000, 'paid', '2031-09-02 10:00:00');
        $this->ids['cancelled'] = $this->receivable($this->tenantA, $this->unitA, $returning, $tutor, 6000, 0, 'cancelled', '2031-09-03 10:00:00');
        $this->ids['unit_b'] = $this->receivable($this->tenantA, $this->unitB, $onlyB, $tutor, 8000, 0, 'open', '2031-09-04 10:00:00');
        $this->ids['tenant_b'] = $this->receivable($this->tenantB, $this->unitA, $otherTenant, $tutorB, 9000, 0, 'open', '2031-09-05 10:00:00');

        $this->insertEntry($this->tenantA, $this->unitA, 'income', 30000, '2031-10-02 10:00:00');
        $this->insertEntry($this->tenantA, $this->unitA, 'income', 20000, '2031-10-31 23:59:59');
        $this->insertEntry($this->tenantA, $this->unitA, 'expense', 5000, '2031-10-03 10:00:00');
        $this->insertEntry($this->tenantA, $this->unitA, 'income', 70000, '2031-11-01 00:00:00');
        $this->insertEntry($this->tenantA, $this->unitB, 'income', 99900, '2031-10-04 10:00:00');
        $this->insertEntry($this->tenantB, $this->unitA, 'income', 88800, '2031-10-04 10:00:00');
    }

    private function insertTutor(int $tenantId, string $name): int
    {
        return $this->insert('tutor', [
            'tenant_id' => $tenantId,
            'public_id' => $this->uuid(),
            'full_name' => $name,
            'document' => 'F8B-FIN-' . bin2hex(random_bytes(3)),
            'phone' => '85999990021',
            'email' => 'f8b.fin@example.invalid',
            'address' => 'F8B teste Rua Fin',
        ]);
    }

    private function insertPatient(int $tenantId, int $tutorId, string $name): int
    {
        return $this->insert('patient', [
            'tenant_id' => $tenantId,
            'tutor_id' => $tutorId,
            'name' => $name,
            'species' => 'canino',
        ]);
    }

    private function insertEncounter(int $tenantId, int $unitId, int $patientId, string $startedAt): int
    {
        return $this->insert('encounter', [
            'tenant_id' => $tenantId,
            'system_unit_id' => $unitId,
            'patient_id' => $patientId,
            'professional_system_user_id' => $this->userId,
            'status' => 'finished',
            'started_at' => $startedAt,
        ]);
    }

    /**
     * receivable is unique per encounter_account: each one gets its own
     * encounter (dated with the receivable, before the indicator period)
     * and account.
     */
    private function receivable(int $tenantId, int $unitId, int $patientId, int $tutorId, int $total, int $paid, string $status, string $createdAt): int
    {
        $encounter = $this->insertEncounter($tenantId, $unitId, $patientId, $createdAt);
        $account = $this->insertAccount($tenantId, $unitId, $encounter, $patientId, $tutorId);

        return $this->insertReceivable($tenantId, $account, $tutorId, $total, $paid, $status, $createdAt);
    }

    private function insertAccount(int $tenantId, int $unitId, int $encounterId, int $patientId, int $tutorId): int
    {
        return $this->insert('encounter_account', [
            'tenant_id' => $tenantId,
            'encounter_id' => $encounterId,
            'patient_id' => $patientId,
            'tutor_id' => $tutorId,
            'system_unit_id' => $unitId,
            'status' => 'closed',
            'subtotal_cents' => 50000,
            'total_cents' => 50000,
        ]);
    }

    private function insertReceivable(int $tenantId, int $accountId, int $tutorId, int $total, int $paid, string $status, string $createdAt): int
    {
        return $this->insert('receivable', [
            'tenant_id' => $tenantId,
            'encounter_account_id' => $accountId,
            'tutor_id' => $tutorId,
            'total_cents' => $total,
            'paid_cents' => $paid,
            'status' => $status,
            'created_at' => $createdAt,
        ]);
    }

    private function insertEntry(int $tenantId, int $unitId, string $type, int $amount, string $occurredAt): void
    {
        $this->insert('financial_entry', [
            'tenant_id' => $tenantId,
            'system_unit_id' => $unitId,
            'entry_type' => $type,
            'category' => 'F8B teste',
            'amount_cents' => $amount,
            'occurred_at' => $occurredAt,
            'system_user_id' => $this->userId,
        ]);
    }

    private function query(int $tenantId, ?int $unitId = null): CopilotFinanceQuery
    {
        return new CopilotFinanceQuery(
            TenantContext::authenticated($tenantId, $this->userId, $unitId ?? $this->unitA),
            $this->pdo,
        );
    }

    private function today(): DateTimeImmutable
    {
        return new DateTimeImmutable('2031-09-20 08:00:00');
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
