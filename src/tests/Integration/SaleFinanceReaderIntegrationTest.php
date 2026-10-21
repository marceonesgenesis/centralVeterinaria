<?php

declare(strict_types=1);

namespace CentralVet\Tests\Integration;

use CentralVet\Persistence\SaleFinanceReader;
use CentralVet\Tenancy\TenantContext;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\MysqlIntegrationTestCase;

/**
 * Proves the sale finance reads (Etapa 2, T-10) against the real MySQL schema
 * with migration 0016: the receivable of a sale (receivable.sale_id), payments
 * net of payment_reversal, the traceable cancellation and tenant isolation.
 * Every row belongs to throwaway tenants created inside the test's own
 * transaction, rolled back in tearDown() — nothing is ever committed.
 *
 * Fixture (tenant A):
 *   - sale 2031-07-10 total 10000, receivable partially_paid, payment pix 4000;
 *   - cancelled sale 2031-07-12 total 2500, no receivable;
 *   - legacy sale 2031-07-08 total 700, no receivable.
 * Tenant B has one sale with its own receivable.
 */
final class SaleFinanceReaderIntegrationTest extends MysqlIntegrationTestCase
{
    private int $userId;
    private string $userName;
    private int $unitId;
    private int $tenantA;
    private int $tenantB;
    private int $cashA;
    private int $saleA;
    private int $receivableA;
    private int $paymentA;
    private int $cancelledA;
    private int $legacyA;
    private int $saleB;

    public function setUp(): void
    {
        parent::setUp();

        $user = $this->pdo->query('SELECT id, name FROM system_users ORDER BY id LIMIT 1')->fetch(\PDO::FETCH_ASSOC);
        Assert::true(is_array($user), 'Fixture requires at least one existing system_users row');
        $this->userId = (int) $user['id'];
        $this->userName = (string) $user['name'];

        $this->unitId = (int) $this->pdo->query('SELECT MIN(id) FROM system_unit')->fetchColumn();
        Assert::true($this->unitId > 0, 'Fixture requires at least one existing system_unit row');

        $this->tenantA = $this->createTenant('e2-sfr-a');
        $this->tenantB = $this->createTenant('e2-sfr-b');
        $tutorA = $this->createTutor($this->tenantA, 'E2 Tutor A');
        $tutorB = $this->createTutor($this->tenantB, 'E2 Tutor B');
        $this->cashA = $this->createCashSession($this->tenantA);
        $cashB = $this->createCashSession($this->tenantB);

        $this->saleA = $this->createSale($this->tenantA, $tutorA, 10000, '2031-07-10 10:00:00');
        $this->receivableA = $this->createReceivable($this->tenantA, $this->saleA, $tutorA, 10000, 4000, 'partially_paid');
        $this->paymentA = $this->createPayment($this->tenantA, $this->receivableA, $this->cashA, 'pix', 4000);

        $this->cancelledA = $this->createSale($this->tenantA, $tutorA, 2500, '2031-07-12 09:00:00', 'Cliente desistiu');
        $this->legacyA = $this->createSale($this->tenantA, $tutorA, 700, '2031-07-08 08:00:00');

        $this->saleB = $this->createSale($this->tenantB, $tutorB, 5000, '2031-07-11 11:00:00');
        $receivableB = $this->createReceivable($this->tenantB, $this->saleB, $tutorB, 5000, 5000, 'paid');
        $this->createPayment($this->tenantB, $receivableB, $cashB, 'cash', 5000);
    }

    public function testReceiptSummaryNetsPaymentsOfTheSaleReceivable(): void
    {
        $summary = $this->readerFor($this->tenantA)->receiptSummary($this->saleA);

        Assert::same([
            'sale_id' => $this->saleA,
            'status' => 'completed',
            'total_cents' => 10000,
            'receivable_status' => 'partially_paid',
            'received_cents' => 4000,
            'payment_methods' => ['pix'],
            'cancelled_at' => null,
            'cancelled_by_name' => null,
            'cancellation_reason' => null,
        ], $summary);
    }

    public function testReversedPaymentLeavesNothingReceived(): void
    {
        $this->createReversal($this->tenantA, $this->paymentA, $this->cashA, 'pix', 4000);

        $summary = $this->readerFor($this->tenantA)->receiptSummary($this->saleA);

        Assert::same(0, $summary['received_cents'], 'A reversed payment is not received money');
        Assert::same([], $summary['payment_methods'], 'A reversed payment does not list its method');
    }

    public function testPaymentMethodsAreDistinctAndSkipReversedOnes(): void
    {
        $this->createPayment($this->tenantA, $this->receivableA, $this->cashA, 'pix', 1000);
        $cash = $this->createPayment($this->tenantA, $this->receivableA, $this->cashA, 'cash', 500);
        $this->createPayment($this->tenantA, $this->receivableA, $this->cashA, 'debit_card', 300);
        $this->createReversal($this->tenantA, $cash, $this->cashA, 'cash', 500);

        $summary = $this->readerFor($this->tenantA)->receiptSummary($this->saleA);

        Assert::same(5300, $summary['received_cents'], '4000 + 1000 + 500 + 300 - 500 reversed');
        Assert::same(['debit_card', 'pix'], $summary['payment_methods']);
    }

    public function testCancelledSaleCarriesWhoCancelledAndWhy(): void
    {
        $summary = $this->readerFor($this->tenantA)->receiptSummary($this->cancelledA);

        Assert::same('cancelled', $summary['status']);
        Assert::same('2031-07-12 09:00:00', $summary['cancelled_at']);
        Assert::same($this->userName, $summary['cancelled_by_name'], 'Name comes from system_users');
        Assert::same('Cliente desistiu', $summary['cancellation_reason']);
        Assert::null($summary['receivable_status'], 'Sale without receivable has no receivable status');
        Assert::same(0, $summary['received_cents']);
        Assert::same([], $summary['payment_methods']);
    }

    public function testReceiptSummaryIsNullForOtherTenantOrMissingSale(): void
    {
        $reader = $this->readerFor($this->tenantA);

        Assert::null($reader->receiptSummary($this->saleB), 'Sale of tenant B must not be read by tenant A');
        Assert::null($reader->receiptSummary(PHP_INT_MAX), 'Missing sale yields null');
    }

    public function testListSalesNewestFirstWithReceivableAndNetReceived(): void
    {
        $this->createReversal($this->tenantA, $this->paymentA, $this->cashA, 'pix', 4000);
        $reader = $this->readerFor($this->tenantA);

        $sales = $reader->listSales(10, 0);

        Assert::same([$this->cancelledA, $this->saleA, $this->legacyA], array_column($sales, 'id'), 'sold_at DESC, tenant B excluded');
        Assert::same([
            'id' => $this->saleA,
            'sold_at' => '2031-07-10 10:00:00',
            'tutor_name' => 'E2 Tutor A',
            'total_cents' => 10000,
            'status' => 'completed',
            'receivable_id' => $this->receivableA,
            'receivable_status' => 'partially_paid',
            'received_cents' => 0,
        ], $sales[1], 'Reversed payment is netted out of received_cents');
        Assert::same('cancelled', $sales[0]['status']);
        Assert::null($sales[2]['receivable_id']);
        Assert::null($sales[2]['receivable_status']);
        Assert::same(0, $sales[2]['received_cents']);

        Assert::same(3, $reader->countSales());
        Assert::same(1, $this->readerFor($this->tenantB)->countSales());
        Assert::same([$this->saleA], array_column($reader->listSales(1, 1), 'id'), 'limit/offset paginate');
        Assert::same([], $reader->listSales(0, 0), 'Non-positive limit returns no row');
    }

    private function readerFor(int $tenantId): SaleFinanceReader
    {
        return new SaleFinanceReader(TenantContext::authenticated($tenantId, $this->userId), $this->pdo);
    }

    private function createTenant(string $slugPrefix): int
    {
        $slug = $slugPrefix . '-' . bin2hex(random_bytes(4));
        $this->pdo->prepare('INSERT INTO tenant (public_id, slug, legal_name, status) VALUES (UUID(), :slug, :legal_name, :status)')
            ->execute(['slug' => $slug, 'legal_name' => 'E2 test tenant (' . $slug . ')', 'status' => 'active']);

        return (int) $this->pdo->lastInsertId();
    }

    private function createTutor(int $tenantId, string $name): int
    {
        $this->pdo->prepare('INSERT INTO tutor (tenant_id, public_id, full_name, phone) VALUES (:tenant_id, UUID(), :full_name, :phone)')
            ->execute(['tenant_id' => $tenantId, 'full_name' => $name, 'phone' => '11999990000']);

        return (int) $this->pdo->lastInsertId();
    }

    private function createCashSession(int $tenantId): int
    {
        $this->pdo->prepare(
            'INSERT INTO cash_session (tenant_id, system_unit_id, opened_by_system_user_id, opening_balance_cents, status)
             VALUES (:tenant_id, :unit_id, :user_id, 0, :status)',
        )->execute(['tenant_id' => $tenantId, 'unit_id' => $this->unitId, 'user_id' => $this->userId, 'status' => 'open']);

        return (int) $this->pdo->lastInsertId();
    }

    private function createSale(int $tenantId, int $tutorId, int $totalCents, string $soldAt, ?string $cancellationReason = null): int
    {
        $cancelled = $cancellationReason !== null;
        $this->pdo->prepare(
            'INSERT INTO sale (tenant_id, system_unit_id, tutor_id, system_user_id, status, total_amount_cents, sold_at,
                               cancelled_at, cancelled_by_system_user_id, cancellation_reason)
             VALUES (:tenant_id, :unit_id, :tutor_id, :user_id, :status, :total, :sold_at,
                     :cancelled_at, :cancelled_by, :cancellation_reason)',
        )->execute([
            'tenant_id' => $tenantId,
            'unit_id' => $this->unitId,
            'tutor_id' => $tutorId,
            'user_id' => $this->userId,
            'status' => $cancelled ? 'cancelled' : 'completed',
            'total' => $totalCents,
            'sold_at' => $soldAt,
            'cancelled_at' => $cancelled ? $soldAt : null,
            'cancelled_by' => $cancelled ? $this->userId : null,
            'cancellation_reason' => $cancellationReason,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function createReceivable(int $tenantId, int $saleId, int $tutorId, int $totalCents, int $paidCents, string $status): int
    {
        $this->pdo->prepare(
            'INSERT INTO receivable (tenant_id, encounter_account_id, sale_id, tutor_id, total_cents, paid_cents, status)
             VALUES (:tenant_id, NULL, :sale_id, :tutor_id, :total, :paid, :status)',
        )->execute([
            'tenant_id' => $tenantId,
            'sale_id' => $saleId,
            'tutor_id' => $tutorId,
            'total' => $totalCents,
            'paid' => $paidCents,
            'status' => $status,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function createPayment(int $tenantId, int $receivableId, int $cashSessionId, string $method, int $amountCents): int
    {
        $this->pdo->prepare(
            'INSERT INTO payment (tenant_id, receivable_id, payment_method, amount_cents, cash_session_id, system_user_id)
             VALUES (:tenant_id, :receivable_id, :method, :amount, :cash_session_id, :user_id)',
        )->execute([
            'tenant_id' => $tenantId,
            'receivable_id' => $receivableId,
            'method' => $method,
            'amount' => $amountCents,
            'cash_session_id' => $cashSessionId,
            'user_id' => $this->userId,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function createReversal(int $tenantId, int $paymentId, int $cashSessionId, string $method, int $amountCents): void
    {
        $this->pdo->prepare(
            'INSERT INTO payment_reversal (tenant_id, payment_id, cash_session_id, payment_method, amount_cents, reason, system_user_id)
             VALUES (:tenant_id, :payment_id, :cash_session_id, :method, :amount, :reason, :user_id)',
        )->execute([
            'tenant_id' => $tenantId,
            'payment_id' => $paymentId,
            'cash_session_id' => $cashSessionId,
            'method' => $method,
            'amount' => $amountCents,
            'reason' => 'E2 fixture reversal',
            'user_id' => $this->userId,
        ]);
    }
}
