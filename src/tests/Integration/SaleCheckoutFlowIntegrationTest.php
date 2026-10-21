<?php

declare(strict_types=1);

namespace CentralVet\Tests\Integration;

use CentralVet\Application\CashSessionService;
use CentralVet\Application\FinancialEntryService;
use CentralVet\Application\PaymentReversalService;
use CentralVet\Application\PaymentService;
use CentralVet\Application\ProcedureCatalogService;
use CentralVet\Application\ProductService;
use CentralVet\Application\SaleCancellationService;
use CentralVet\Application\SaleCheckoutResult;
use CentralVet\Application\SaleCheckoutService;
use CentralVet\Application\SaleService;
use CentralVet\Application\StockService;
use CentralVet\Authorization\AuthorizationDecision;
use CentralVet\Authorization\AuthorizationRequest;
use CentralVet\Authorization\Contract\AuthorizationPolicyInterface;
use CentralVet\Authorization\Exception\AuthorizationDenied;
use CentralVet\Domain\Exception\CashSessionRequiredException;
use CentralVet\Domain\Exception\DuplicateSubmissionException;
use CentralVet\Domain\Payment;
use CentralVet\Domain\StockMovement;
use CentralVet\Domain\SubmissionToken;
use CentralVet\Persistence\CashSessionRepository;
use CentralVet\Persistence\EncounterRepository;
use CentralVet\Persistence\FinancialEntryRepository;
use CentralVet\Persistence\FinancialOverviewReader;
use CentralVet\Persistence\PatientRepository;
use CentralVet\Persistence\PaymentRepository;
use CentralVet\Persistence\PaymentReversalRepository;
use CentralVet\Persistence\ProcedureCatalogItemInputRepository;
use CentralVet\Persistence\ProcedureCatalogRepository;
use CentralVet\Persistence\ProductRepository;
use CentralVet\Persistence\ReceivableRepository;
use CentralVet\Persistence\SaleFinanceReader;
use CentralVet\Persistence\SaleItemRepository;
use CentralVet\Persistence\SaleRepository;
use CentralVet\Persistence\StockBatchRepository;
use CentralVet\Persistence\StockMovementRepository;
use CentralVet\Persistence\SubmissionKeyRepository;
use CentralVet\Persistence\TutorRepository;
use CentralVet\Tenancy\TenantContext;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\FakeAuthorizationPolicy;
use CentralVet\Tests\Support\MysqlIntegrationTestCase;
use CentralVet\Tests\Support\TestDatabase;

/**
 * Etapa 2, T-17: end-to-end checkout and cancellation against the real
 * schema (migration 0016) on centralvet_test. Services are wired with the
 * real repositories exactly as SaleForm::buildSaleCheckoutService() and
 * SaleCancelForm::buildCancellationService() do, with FakeAuthorizationPolicy
 * in place of the Adianti-session RBAC. Fixture: one product with sale
 * price 5000 and one batch of 2 units. Everything runs inside the test
 * transaction and is rolled back; the second connection never commits.
 */
final class SaleCheckoutFlowIntegrationTest extends MysqlIntegrationTestCase
{
    private const CHECKOUT_ACTION = 'SaleForm::onSave';
    private const CANCEL_ACTION = 'SaleCancelForm::onCancel';
    private const PAYMENT_ACTION = 'PaymentForm::onSave';
    private const PRICE_CENTS = 5000;

    private int $userId;
    private int $unitId;
    private int $tenantId;
    private int $tutorId;
    private int $productId;
    private int $batchId;

    public function setUp(): void
    {
        parent::setUp();

        $this->userId = (int) $this->pdo->query('SELECT MIN(id) FROM system_users')->fetchColumn();
        Assert::true($this->userId > 0, 'Fixture requires at least one existing system_users row');
        $this->unitId = (int) $this->pdo->query('SELECT MIN(id) FROM system_unit')->fetchColumn();
        Assert::true($this->unitId > 0, 'Fixture requires at least one existing system_unit row');

        $slug = 'e2-t17-' . bin2hex(random_bytes(4));
        $this->pdo->prepare(
            'INSERT INTO tenant (public_id, slug, legal_name, status) VALUES (UUID(), :slug, :legal_name, :status)',
        )->execute(['slug' => $slug, 'legal_name' => 'E2 teste tenant (' . $slug . ')', 'status' => 'active']);
        $this->tenantId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare(
            'INSERT INTO tutor (tenant_id, public_id, full_name, phone) VALUES (:tenant_id, UUID(), :full_name, :phone)',
        )->execute(['tenant_id' => $this->tenantId, 'full_name' => 'E2 T17 Tutor', 'phone' => '11999990000']);
        $this->tutorId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare(
            'INSERT INTO product (tenant_id, name, category, unit_of_measure, unit_cost_cents, sale_price_cents, minimum_stock_quantity, active) '
            . 'VALUES (:t, :n, :c, :u, 1000, :price, 0, 1)',
        )->execute(['t' => $this->tenantId, 'n' => 'E2 T17 Ração', 'c' => 'material', 'u' => 'un', 'price' => self::PRICE_CENTS]);
        $this->productId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare(
            "INSERT INTO stock_batch (tenant_id, system_unit_id, product_id, lot, expiry_date, quantity, received_at) "
            . "VALUES (:t, :u, :p, :lot, '2031-01-01', 2, '2030-12-01 10:00:00')",
        )->execute(['t' => $this->tenantId, 'u' => $this->unitId, 'p' => $this->productId, 'lot' => 'E2-T17']);
        $this->batchId = (int) $this->pdo->lastInsertId();
    }

    /** (a) cash sale of 2 units: sale, paid receivable, payment, income entry, cash balance and receipt. */
    public function testCashCheckoutRecordsSalePaidReceivablePaymentAndIncome(): void
    {
        $cashSessionId = $this->openCashSession();
        $balanceBefore = $this->openCashBalance();

        $result = $this->checkout(2, Payment::METHOD_CASH);
        $saleId = (int) $result->sale->id();

        Assert::same(1, $this->countWhere('sale', 'tenant_id = ?', [$this->tenantId]));
        Assert::same(10000, $result->sale->totalAmountCents());

        $receivable = $this->row('SELECT id, sale_id, status, paid_cents FROM receivable WHERE sale_id = ?', [$saleId]);
        Assert::same($saleId, (int) $receivable['sale_id']);
        Assert::same('paid', $receivable['status']);

        $payments = $this->rows('SELECT id, amount_cents, payment_method, cash_session_id FROM payment WHERE receivable_id = ?', [(int) $receivable['id']]);
        Assert::count(1, $payments);
        Assert::same(10000, (int) $payments[0]['amount_cents']);
        Assert::same($cashSessionId, (int) $payments[0]['cash_session_id']);

        $entries = $this->rows(
            "SELECT entry_type, amount_cents FROM financial_entry WHERE tenant_id = ? AND reference_type = 'payment' AND reference_id = ?",
            [$this->tenantId, (int) $payments[0]['id']],
        );
        Assert::count(1, $entries);
        Assert::same('income', $entries[0]['entry_type']);
        Assert::same(10000, (int) $entries[0]['amount_cents']);

        Assert::same($balanceBefore + 10000, $this->openCashBalance());
        Assert::same(0, $this->batchQuantity());

        $summary = $this->reader()->receiptSummary($saleId);
        Assert::notNull($summary);
        Assert::same(10000, $summary['received_cents']);
        Assert::same('paid', $summary['receivable_status']);
        Assert::same(['cash'], $summary['payment_methods']);
    }

    /** (b) credit sale: open receivable, then 4000 (partially_paid) and 6000 (paid) through PaymentService. */
    public function testCreditCheckoutLeavesOpenReceivablePaidLaterInTwoPayments(): void
    {
        $cashSessionId = $this->openCashSession();

        $result = $this->checkout(2, null);
        $saleId = (int) $result->sale->id();
        $receivableId = (int) $result->receivable->id();

        Assert::null($result->payment);
        Assert::same('open', $this->receivableStatus($receivableId));
        Assert::same(0, $this->countWhere('payment', 'receivable_id = ?', [$receivableId]));
        Assert::same(0, $this->reader()->receiptSummary($saleId)['received_cents']);

        $payments = $this->paymentService($this->pdo, new FakeAuthorizationPolicy());
        $payments->register($receivableId, $cashSessionId, Payment::METHOD_CASH, 4000, $this->userId, self::PAYMENT_ACTION, SubmissionToken::generate());
        Assert::same('partially_paid', $this->receivableStatus($receivableId));
        Assert::same(4000, $this->reader()->receiptSummary($saleId)['received_cents']);

        $payments->register($receivableId, $cashSessionId, Payment::METHOD_CASH, 6000, $this->userId, self::PAYMENT_ACTION, SubmissionToken::generate());
        Assert::same('paid', $this->receivableStatus($receivableId));
        Assert::same(10000, $this->reader()->receiptSummary($saleId)['received_cents']);
        Assert::same(2, $this->countWhere('payment', 'receivable_id = ?', [$receivableId]));
    }

    /**
     * (c) A takes the last units without committing; B's checkout on a second
     * connection waits and ends in 1205. B's first write (claim) already
     * waits on A's uncommitted rows: base data cannot be committed in an
     * integration test, so the lock B meets first is not isolated to the
     * stock batch (same limitation recorded for T-06/T-08/T-09).
     */
    public function testSecondCheckoutOfTheLastUnitOnAnotherConnectionEndsInLockWaitTimeout(): void
    {
        $this->checkout(2, null);
        Assert::same(0, $this->batchQuantity());

        $other = $this->secondConnection();
        $other->exec('SET SESSION innodb_lock_wait_timeout = 1');
        $other->beginTransaction();

        try {
            $errorCode = 0;
            $answer = 'no exception';

            try {
                $this->checkoutService($other, new FakeAuthorizationPolicy())->checkout(
                    $this->tenantId, $this->unitId, $this->tutorId, null, null, $this->userId,
                    $this->items(1), null, SubmissionToken::generate(), self::CHECKOUT_ACTION,
                );
            } catch (\PDOException $e) {
                $errorCode = (int) ($e->errorInfo[1] ?? 0);
                $answer = $e->getMessage();
            } catch (\Throwable $e) {
                $answer = get_class($e) . ': ' . $e->getMessage();
            }

            Assert::same(1205, $errorCode, "B must end in lock wait timeout (answered: {$answer})");
        } finally {
            if ($other->inTransaction()) {
                $other->rollBack();
            }
        }

        Assert::same(1, $this->countWhere('sale', 'tenant_id = ?', [$this->tenantId]), 'Only A sold');
        Assert::same(0, $this->batchQuantity());
    }

    /** (d) the financial entry is denied mid-checkout: everything after the SAVEPOINT disappears. */
    public function testDeniedFinancialEntryRollsTheWholeCheckoutBackToTheSavepoint(): void
    {
        $this->openCashSession();
        $before = $this->flowCounts();

        $policy = new class implements AuthorizationPolicyInterface {
            public function decide(AuthorizationRequest $request): AuthorizationDecision
            {
                $allowed = $request->entityType() !== 'financial_entry';

                return new AuthorizationDecision($allowed, $allowed ? 'granted' : 'denied by test', 'test-correlation-id');
            }
        };

        $this->pdo->exec('SAVEPOINT t17_checkout');
        $denied = null;

        try {
            $this->checkoutService($this->pdo, $policy)->checkout(
                $this->tenantId, $this->unitId, $this->tutorId, null, null, $this->userId,
                $this->items(2), Payment::METHOD_CASH, SubmissionToken::generate(), self::CHECKOUT_ACTION,
            );
        } catch (AuthorizationDenied $e) {
            $denied = $e;
        }

        Assert::notNull($denied, 'Checkout must throw AuthorizationDenied when the financial entry is denied');
        Assert::same(0, $this->batchQuantity(), 'Before the rollback the stock was already taken');

        $this->pdo->exec('ROLLBACK TO SAVEPOINT t17_checkout');

        Assert::same($before, $this->flowCounts());
        Assert::same(2, $this->batchQuantity());
    }

    /** (e) the same token twice: DuplicateSubmissionException, one sale and one stock write-off. */
    public function testRepeatedTokenThrowsDuplicateAndKeepsOneSaleAndOneStockMovement(): void
    {
        $token = SubmissionToken::generate();
        $service = $this->checkoutService($this->pdo, new FakeAuthorizationPolicy());

        $first = $service->checkout(
            $this->tenantId, $this->unitId, $this->tutorId, null, null, $this->userId,
            $this->items(1), null, $token, self::CHECKOUT_ACTION,
        );
        $duplicate = null;

        try {
            $service->checkout(
                $this->tenantId, $this->unitId, $this->tutorId, null, null, $this->userId,
                $this->items(1), null, $token, self::CHECKOUT_ACTION,
            );
        } catch (DuplicateSubmissionException $e) {
            $duplicate = $e;
        }

        Assert::notNull($duplicate, 'Second checkout with the same token must throw DuplicateSubmissionException');
        Assert::same($first->sale->id(), $duplicate->referenceId());
        Assert::same(1, $this->countWhere('sale', 'tenant_id = ?', [$this->tenantId]));
        Assert::same(1, $this->countWhere(
            'stock_movement',
            'tenant_id = ? AND reason = ?',
            [$this->tenantId, StockMovement::REASON_SALE_CONSUMPTION],
        ));
        Assert::same(1, $this->batchQuantity());
    }

    /** (f) cancelling the cash sale of (a) returns stock, reverses, cancels; a second cancel changes nothing. */
    public function testCancellingTheCashSaleReturnsStockReversesPaymentAndIsIdempotent(): void
    {
        $this->openCashSession();
        $balanceBefore = $this->openCashBalance();
        $saleId = (int) $this->checkout(2, Payment::METHOD_CASH)->sale->id();

        $result = $this->cancellationService(new FakeAuthorizationPolicy())->cancel($saleId, 'Cliente desistiu', $this->userId, self::CANCEL_ACTION);

        Assert::false($result->alreadyCancelled);
        Assert::same(2, $result->returnedUnits);
        Assert::same(2, $this->batchQuantity());

        $in = $this->rows(
            "SELECT movement_type, quantity FROM stock_movement WHERE tenant_id = ? AND reference_type = 'sale' AND reference_id = ? AND reason = ?",
            [$this->tenantId, $saleId, StockMovement::REASON_SALE_CANCELLATION],
        );
        Assert::count(1, $in);
        Assert::same(StockMovement::TYPE_IN, $in[0]['movement_type']);
        Assert::same(2, (int) $in[0]['quantity']);

        $receivable = $this->row('SELECT id, status FROM receivable WHERE sale_id = ?', [$saleId]);
        Assert::same('cancelled', $receivable['status']);
        Assert::same(1, $this->countWhere('payment_reversal', 'tenant_id = ?', [$this->tenantId]));
        Assert::same(1, $this->countWhere(
            'financial_entry',
            "tenant_id = ? AND entry_type = 'expense' AND reference_type = 'payment_reversal'",
            [$this->tenantId],
        ));
        Assert::same($balanceBefore, $this->openCashBalance());

        $sale = $this->row('SELECT status, cancelled_at, cancelled_by_system_user_id, cancellation_reason FROM sale WHERE id = ?', [$saleId]);
        Assert::same('cancelled', $sale['status']);
        Assert::notNull($sale['cancelled_at']);
        Assert::same($this->userId, (int) $sale['cancelled_by_system_user_id']);
        Assert::same('Cliente desistiu', $sale['cancellation_reason']);

        $countsAfterFirst = $this->flowCounts() + ['payment_reversal' => $this->countWhere('payment_reversal', 'tenant_id = ?', [$this->tenantId])];

        $again = $this->cancellationService(new FakeAuthorizationPolicy())->cancel($saleId, 'De novo', $this->userId, self::CANCEL_ACTION);

        Assert::true($again->alreadyCancelled);
        Assert::same(
            $countsAfterFirst,
            $this->flowCounts() + ['payment_reversal' => $this->countWhere('payment_reversal', 'tenant_id = ?', [$this->tenantId])],
        );
        Assert::same(2, $this->batchQuantity());
        Assert::same('Cliente desistiu', $this->row('SELECT cancellation_reason FROM sale WHERE id = ?', [$saleId])['cancellation_reason']);
    }

    /** (g) payment in a session closed afterwards: the reversal goes to the new open session. */
    public function testReversalOfAPaymentFromAClosedSessionLandsInTheNewOpenSession(): void
    {
        $firstSessionId = $this->openCashSession();
        $saleId = (int) $this->checkout(2, Payment::METHOD_CASH)->sale->id();

        $cashSessions = $this->cashSessionService();
        Assert::same(['cash' => 10000], $cashSessions->totalsByPaymentMethod($firstSessionId));
        $cashSessions->close($firstSessionId, 10000, $this->userId, 'CashSessionForm::onClose');

        $secondSessionId = $this->openCashSession();

        $this->cancellationService(new FakeAuthorizationPolicy())->cancel($saleId, 'Troca', $this->userId, self::CANCEL_ACTION);

        $reversals = $this->rows('SELECT cash_session_id, amount_cents FROM payment_reversal WHERE tenant_id = ?', [$this->tenantId]);
        Assert::count(1, $reversals);
        Assert::same($secondSessionId, (int) $reversals[0]['cash_session_id']);
        Assert::same(10000, (int) $reversals[0]['amount_cents']);

        Assert::same(['cash' => 10000], $cashSessions->totalsByPaymentMethod($firstSessionId), 'Closed session totals do not change');
        Assert::same(['cash' => -10000], $cashSessions->totalsByPaymentMethod($secondSessionId));
        Assert::same('closed', $this->row('SELECT status FROM cash_session WHERE id = ?', [$firstSessionId])['status']);
    }

    /**
     * Pending of T-14/T-16: a paid sale cancelled without an open cash
     * session throws CashSessionRequiredException after the stock was
     * returned; the caller's rollback (SaleCancelForm::onCancel) undoes it.
     */
    public function testCancellingAPaidSaleWithoutOpenCashSessionIsUndoneByTheCallerRollback(): void
    {
        $sessionId = $this->openCashSession();
        $saleId = (int) $this->checkout(2, Payment::METHOD_CASH)->sale->id();
        $this->cashSessionService()->close($sessionId, 10000, $this->userId, 'CashSessionForm::onClose');

        $this->pdo->exec('SAVEPOINT t17_cancel');
        $caught = null;

        try {
            $this->cancellationService(new FakeAuthorizationPolicy())->cancel($saleId, 'Sem caixa', $this->userId, self::CANCEL_ACTION);
        } catch (CashSessionRequiredException $e) {
            $caught = $e;
        }

        Assert::notNull($caught, 'Cancelling a paid sale without an open cash session must throw CashSessionRequiredException');

        $this->pdo->exec('ROLLBACK TO SAVEPOINT t17_cancel');

        Assert::same(0, $this->batchQuantity());
        Assert::same('completed', $this->row('SELECT status FROM sale WHERE id = ?', [$saleId])['status']);
        Assert::same('paid', $this->row('SELECT status FROM receivable WHERE sale_id = ?', [$saleId])['status']);
        Assert::same(0, $this->countWhere('payment_reversal', 'tenant_id = ?', [$this->tenantId]));
    }

    private function checkout(int $quantity, ?string $paymentMethod): SaleCheckoutResult
    {
        return $this->checkoutService($this->pdo, new FakeAuthorizationPolicy())->checkout(
            $this->tenantId, $this->unitId, $this->tutorId, null, null, $this->userId,
            $this->items($quantity), $paymentMethod, SubmissionToken::generate(), self::CHECKOUT_ACTION,
        );
    }

    /** @return list<array{type: string, referenceId: int, quantity: int}> */
    private function items(int $quantity): array
    {
        return [['type' => 'product', 'referenceId' => $this->productId, 'quantity' => $quantity]];
    }

    /** Mirrors SaleForm::buildSaleService() + buildSaleCheckoutService(). */
    private function checkoutService(\PDO $connection, AuthorizationPolicyInterface $authorization): SaleCheckoutService
    {
        $context = $this->context();
        $receivables = new ReceivableRepository($context, $connection);
        $cashSessions = new CashSessionRepository($context, $connection);
        $submissionKeys = new SubmissionKeyRepository($context, $connection);

        $sales = new SaleService(
            new SaleRepository($context, $connection),
            new SaleItemRepository($context, $connection),
            new ProductService(new ProductRepository($context, $connection), $context),
            new StockService(
                new StockBatchRepository($context, $connection),
                new StockMovementRepository($context, $connection),
                $authorization,
                $context,
            ),
            new ProcedureCatalogService(
                new ProcedureCatalogRepository($context, $connection),
                new ProcedureCatalogItemInputRepository($context, $connection),
                $context,
            ),
            $authorization,
            $context,
            new TutorRepository($context, $connection),
            new PatientRepository($context, $connection),
            new EncounterRepository($context, $connection),
        );

        return new SaleCheckoutService(
            $sales,
            $receivables,
            $this->paymentService($connection, $authorization),
            $cashSessions,
            $submissionKeys,
            $context,
        );
    }

    /** Mirrors PaymentForm/SaleForm wiring of PaymentService. */
    private function paymentService(\PDO $connection, AuthorizationPolicyInterface $authorization): PaymentService
    {
        $context = $this->context();

        return new PaymentService(
            new PaymentRepository($context, $connection),
            new ReceivableRepository($context, $connection),
            new CashSessionRepository($context, $connection),
            new FinancialEntryService(new FinancialEntryRepository($context, $connection), $authorization, $context),
            $authorization,
            $context,
            new SubmissionKeyRepository($context, $connection),
        );
    }

    /** Mirrors SaleCancelForm::buildCancellationService(). */
    private function cancellationService(AuthorizationPolicyInterface $authorization): SaleCancellationService
    {
        $context = $this->context();
        $connection = $this->pdo;
        $receivables = new ReceivableRepository($context, $connection);

        return new SaleCancellationService(
            new SaleRepository($context, $connection),
            $receivables,
            new StockService(
                new StockBatchRepository($context, $connection),
                new StockMovementRepository($context, $connection),
                $authorization,
                $context,
            ),
            new PaymentReversalService(
                new PaymentRepository($context, $connection),
                new PaymentReversalRepository($context, $connection),
                $receivables,
                new CashSessionRepository($context, $connection),
                new FinancialEntryService(new FinancialEntryRepository($context, $connection), $authorization, $context),
                $authorization,
                $context,
            ),
            $authorization,
            $context,
        );
    }

    private function cashSessionService(): CashSessionService
    {
        $context = $this->context();

        return new CashSessionService(new CashSessionRepository($context, $this->pdo), new FakeAuthorizationPolicy(), $context, $this->pdo);
    }

    private function reader(): SaleFinanceReader
    {
        return new SaleFinanceReader($this->context(), $this->pdo);
    }

    private function openCashBalance(): ?int
    {
        return (new FinancialOverviewReader($this->context(), $this->pdo))->openCashBalance($this->unitId);
    }

    private function openCashSession(): int
    {
        $this->pdo->prepare(
            "INSERT INTO cash_session (tenant_id, system_unit_id, opened_by_system_user_id, opening_balance_cents, status)
             VALUES (:tenant_id, :unit_id, :user_id, 0, 'open')",
        )->execute(['tenant_id' => $this->tenantId, 'unit_id' => $this->unitId, 'user_id' => $this->userId]);

        return (int) $this->pdo->lastInsertId();
    }

    /** @return array<string, int> row counts of every table the checkout writes, for this tenant */
    private function flowCounts(): array
    {
        $counts = [];

        foreach (['sale', 'sale_item', 'stock_movement', 'receivable', 'payment', 'financial_entry', 'submission_key'] as $table) {
            $counts[$table] = $this->countWhere($table, 'tenant_id = ?', [$this->tenantId]);
        }

        return $counts;
    }

    private function batchQuantity(): int
    {
        return (int) $this->row('SELECT quantity FROM stock_batch WHERE id = ?', [$this->batchId])['quantity'];
    }

    private function receivableStatus(int $receivableId): string
    {
        return (string) $this->row('SELECT status FROM receivable WHERE id = ?', [$receivableId])['status'];
    }

    /** @param list<int|string> $params */
    private function countWhere(string $table, string $where, array $params): int
    {
        $statement = $this->pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE {$where}");
        $statement->execute($params);

        return (int) $statement->fetchColumn();
    }

    /** @param list<int|string> $params @return array<string, mixed> */
    private function row(string $sql, array $params): array
    {
        $rows = $this->rows($sql, $params);
        Assert::count(1, $rows, "Expected exactly one row for: {$sql}");

        return $rows[0];
    }

    /** @param list<int|string> $params @return list<array<string, mixed>> */
    private function rows(string $sql, array $params): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);

        return $statement->fetchAll(\PDO::FETCH_ASSOC);
    }

    private function context(): TenantContext
    {
        return TenantContext::authenticated($this->tenantId, $this->userId, $this->unitId);
    }

    private function secondConnection(): \PDO
    {
        return new \PDO(
            sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                getenv('DB_HOST') ?: '127.0.0.1',
                getenv('DB_PORT') ?: '3306',
                TestDatabase::resolveName(getenv()),
            ),
            (string) (getenv('DB_USERNAME') ?: 'centralvet'),
            (string) (getenv('DB_PASSWORD') ?: ''),
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION],
        );
    }
}
