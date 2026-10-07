<?php

declare(strict_types=1);

namespace CentralVet\Tests\Integration;

use CentralVet\Application\FinancialOverviewService;
use CentralVet\Persistence\FinancialOverviewReader;
use CentralVet\Tenancy\TenantContext;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\MysqlIntegrationTestCase;
use DateTimeImmutable;

/**
 * Proves the financial overview reads (Phase 10, T-05) against the real
 * MySQL schema. All rows belong to throwaway tenants created inside the
 * test's transaction, rolled back in tearDown() — nothing is committed.
 *
 * Fixture, period 2031-05-01..2031-05-30 (30 days; previous period is
 * 2031-04-01..2031-04-30), tenant A / unit U1:
 *   income  2031-05-02 10:00 Consultas 10000
 *   income  2031-05-02 18:00 Vendas     5000
 *   income  2031-05-15 09:00 Consultas  5000
 *   expense 2031-05-10 12:00 Aluguel    7000
 *   income  2031-05-31 08:00 Consultas  4444 (outside the period)
 *   income  2031-04-20 Consultas 3000, expense 2031-04-05 Aluguel 2000 (previous period)
 * Noise that must never be summed for A/U1: tenant A / unit U2 income
 * 99999 and tenant B / unit U1 income 88888, both on 2031-05-02.
 */
final class FinancialOverviewIntegrationTest extends MysqlIntegrationTestCase
{
    private int $userId;
    private int $tenantA;
    private int $tenantB;
    private int $unit1;
    private int $unit2;
    private DateTimeImmutable $from;
    private DateTimeImmutable $to;

    public function setUp(): void
    {
        parent::setUp();

        $this->userId = (int) $this->pdo->query('SELECT MIN(id) FROM system_users')->fetchColumn();
        Assert::true($this->userId > 0, 'Fixture requires at least one existing system_users row');

        // system_unit.id has no AUTO_INCREMENT (Adianti table): reuse two existing units.
        $units = array_map('intval', $this->pdo->query('SELECT id FROM system_unit ORDER BY id LIMIT 2')->fetchAll(\PDO::FETCH_COLUMN));
        Assert::count(2, $units, 'Fixture requires two existing system_unit rows');
        [$this->unit1, $this->unit2] = $units;

        $this->tenantA = $this->createTenant('f10-fin-a');
        $this->tenantB = $this->createTenant('f10-fin-b');
        $this->from = new DateTimeImmutable('2031-05-01');
        $this->to = new DateTimeImmutable('2031-05-30');

        $this->entry($this->tenantA, $this->unit1, 'income', 'Consultas', 10000, '2031-05-02 10:00:00');
        $this->entry($this->tenantA, $this->unit1, 'income', 'Vendas', 5000, '2031-05-02 18:00:00', 'sale', 42);
        $this->entry($this->tenantA, $this->unit1, 'income', 'Consultas', 5000, '2031-05-15 09:00:00');
        $this->entry($this->tenantA, $this->unit1, 'expense', 'Aluguel', 7000, '2031-05-10 12:00:00', 'payable', 7);
        $this->entry($this->tenantA, $this->unit1, 'income', 'Consultas', 4444, '2031-05-31 08:00:00');
        $this->entry($this->tenantA, $this->unit1, 'income', 'Consultas', 3000, '2031-04-20 10:00:00');
        $this->entry($this->tenantA, $this->unit1, 'expense', 'Aluguel', 2000, '2031-04-05 10:00:00');
        $this->entry($this->tenantA, $this->unit2, 'income', 'Consultas', 99999, '2031-05-02 11:00:00');
        $this->entry($this->tenantB, $this->unit1, 'income', 'Consultas', 88888, '2031-05-02 11:00:00');
    }

    public function testTotalsSumOnlyRequestedUnitAndTenant(): void
    {
        $totals = $this->serviceFor($this->tenantA)->totals($this->unit1, $this->from, $this->to);

        Assert::same([
            'revenue_cents' => 20000,
            'expense_cents' => 7000,
            'result_cents' => 13000,
            'prev_revenue_cents' => 3000,
            'prev_expense_cents' => 2000,
            'prev_result_cents' => 1000,
        ], $totals, 'Other unit, other tenant and out-of-period entries must not change the totals');

        $otherTenant = $this->serviceFor($this->tenantB)->totals($this->unit1, $this->from, $this->to);
        Assert::same(88888, $otherTenant['revenue_cents']);
        Assert::same(0, $otherTenant['prev_revenue_cents']);
    }

    public function testDailySeriesHasOneItemPerDayWithZeros(): void
    {
        $series = $this->serviceFor($this->tenantA)->dailySeries($this->unit1, $this->from, $this->to);

        Assert::true(count($series) === 30, 'A 30-day period yields 30 items');
        Assert::same('2031-05-01', $series[0]['date']);
        Assert::same('2031-05-30', $series[29]['date']);
        Assert::same(['date' => '2031-05-01', 'revenue_cents' => 0, 'expense_cents' => 0], $series[0]);
        Assert::same(['date' => '2031-05-02', 'revenue_cents' => 15000, 'expense_cents' => 0], $series[1]);
        Assert::same(['date' => '2031-05-10', 'revenue_cents' => 0, 'expense_cents' => 7000], $series[9]);
        Assert::same(20000, array_sum(array_column($series, 'revenue_cents')));
    }

    public function testRevenueByCategorySharesSumToOne(): void
    {
        $categories = $this->serviceFor($this->tenantA)->revenueByCategory($this->unit1, $this->from, $this->to);

        Assert::same(['Consultas', 'Vendas'], array_column($categories, 'category'));
        Assert::same([15000, 5000], array_column($categories, 'amount_cents'));
        Assert::same(0.75, $categories[0]['share']);
        Assert::true(abs(array_sum(array_column($categories, 'share')) - 1.0) < 1e-9, 'Shares must sum to 1.0');

        Assert::same([], $this->serviceFor($this->tenantB)->revenueByCategory($this->unit2, $this->from, $this->to));
    }

    public function testRecentEntriesAreNewestFirstAndScoped(): void
    {
        $entries = $this->serviceFor($this->tenantA)->recentEntries($this->unit1, 3);

        Assert::count(3, $entries);
        Assert::same([4444, 5000, 7000], array_column($entries, 'amount_cents'));
        Assert::same('2031-05-31 08:00:00', $entries[0]['occurred_at']);
        Assert::same('expense', $entries[2]['entry_type']);
        Assert::same('Aluguel', $entries[2]['category']);
        Assert::notNull($entries[2]['reference']);
        Assert::null($entries[0]['reference']);
        Assert::false(in_array(88888, array_column($this->serviceFor($this->tenantA)->recentEntries($this->unit1, 50), 'amount_cents'), true));
    }

    public function testOpenCashBalanceIsNullWithoutOpenSession(): void
    {
        $this->cashSession($this->tenantB, $this->unit1, 50000, 'open');
        $this->cashSession($this->tenantA, $this->unit1, 1000, 'closed');

        Assert::null(
            $this->serviceFor($this->tenantA)->openCashBalanceCents($this->unit1),
            'Closed session of A and open session of B must not count',
        );
    }

    public function testOpenCashBalanceAddsPaymentsOfTheOpenSession(): void
    {
        $session = $this->cashSession($this->tenantA, $this->unit1, 10000, 'open');
        $this->payment($this->tenantA, $session, 2500);

        Assert::same(12500, $this->serviceFor($this->tenantA)->openCashBalanceCents($this->unit1));
        Assert::null($this->serviceFor($this->tenantA)->openCashBalanceCents($this->unit2));
    }

    private function serviceFor(int $tenantId): FinancialOverviewService
    {
        return new FinancialOverviewService(
            new FinancialOverviewReader(TenantContext::authenticated($tenantId, $this->userId), $this->pdo),
        );
    }

    private function createTenant(string $slugPrefix): int
    {
        $slug = $slugPrefix . '-' . bin2hex(random_bytes(4));
        $statement = $this->pdo->prepare(
            'INSERT INTO tenant (public_id, slug, legal_name, status) VALUES (UUID(), :slug, :legal_name, :status)',
        );
        $statement->execute(['slug' => $slug, 'legal_name' => 'F10 test tenant (' . $slug . ')', 'status' => 'active']);

        return (int) $this->pdo->lastInsertId();
    }

    private function entry(
        int $tenantId,
        int $unitId,
        string $type,
        string $category,
        int $amountCents,
        string $occurredAt,
        ?string $referenceType = null,
        ?int $referenceId = null,
    ): void {
        $statement = $this->pdo->prepare(
            'INSERT INTO financial_entry (tenant_id, system_unit_id, entry_type, category, amount_cents, reference_type, reference_id, occurred_at, system_user_id)
             VALUES (:tenant_id, :unit_id, :type, :category, :amount, :reference_type, :reference_id, :occurred_at, :user_id)',
        );
        $statement->execute([
            'tenant_id' => $tenantId,
            'unit_id' => $unitId,
            'type' => $type,
            'category' => $category,
            'amount' => $amountCents,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'occurred_at' => $occurredAt,
            'user_id' => $this->userId,
        ]);
    }

    private function cashSession(int $tenantId, int $unitId, int $openingCents, string $status): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO cash_session (tenant_id, system_unit_id, opened_by_system_user_id, opening_balance_cents, status, closed_by_system_user_id, closing_balance_cents, closed_at)
             VALUES (:tenant_id, :unit_id, :user_id, :opening, :status, :closed_by, :closing, :closed_at)',
        );
        $closed = $status === 'closed';
        $statement->execute([
            'tenant_id' => $tenantId,
            'unit_id' => $unitId,
            'user_id' => $this->userId,
            'opening' => $openingCents,
            'status' => $status,
            'closed_by' => $closed ? $this->userId : null,
            'closing' => $closed ? $openingCents : null,
            'closed_at' => $closed ? '2031-05-01 18:00:00' : null,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /** payment needs receivable -> encounter_account -> encounter -> patient -> tutor. */
    private function payment(int $tenantId, int $cashSessionId, int $amountCents): void
    {
        $this->pdo->prepare('INSERT INTO tutor (tenant_id, public_id, full_name, phone) VALUES (:t, UUID(), :n, :p)')
            ->execute(['t' => $tenantId, 'n' => 'F10 Tutor', 'p' => '11999990000']);
        $tutorId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare('INSERT INTO patient (tenant_id, tutor_id, name, species) VALUES (:t, :tutor, :n, :s)')
            ->execute(['t' => $tenantId, 'tutor' => $tutorId, 'n' => 'F10 Rex', 's' => 'Canina']);
        $patientId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare(
            'INSERT INTO encounter (tenant_id, system_unit_id, patient_id, professional_system_user_id) VALUES (:t, :u, :p, :user)',
        )->execute(['t' => $tenantId, 'u' => $this->unit1, 'p' => $patientId, 'user' => $this->userId]);
        $encounterId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare(
            'INSERT INTO encounter_account (tenant_id, encounter_id, patient_id, tutor_id, system_unit_id, status, subtotal_cents, total_cents)
             VALUES (:t, :e, :p, :tutor, :u, :status, :amount, :amount2)',
        )->execute([
            't' => $tenantId, 'e' => $encounterId, 'p' => $patientId, 'tutor' => $tutorId, 'u' => $this->unit1,
            'status' => 'closed', 'amount' => $amountCents, 'amount2' => $amountCents,
        ]);
        $accountId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare(
            'INSERT INTO receivable (tenant_id, encounter_account_id, tutor_id, total_cents, paid_cents, status)
             VALUES (:t, :a, :tutor, :total, :paid, :status)',
        )->execute(['t' => $tenantId, 'a' => $accountId, 'tutor' => $tutorId, 'total' => $amountCents, 'paid' => $amountCents, 'status' => 'paid']);
        $receivableId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare(
            'INSERT INTO payment (tenant_id, receivable_id, payment_method, amount_cents, cash_session_id, system_user_id)
             VALUES (:t, :r, :m, :amount, :s, :user)',
        )->execute([
            't' => $tenantId, 'r' => $receivableId, 'm' => 'cash', 'amount' => $amountCents,
            's' => $cashSessionId, 'user' => $this->userId,
        ]);
    }
}
