<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Application\FinancialEntryService;
use CentralVet\Application\PaymentReversalService;
use CentralVet\Application\SaleCancellationResult;
use CentralVet\Application\SaleCancellationService;
use CentralVet\Application\StockService;
use CentralVet\Authorization\Exception\AuthorizationDenied;
use CentralVet\Domain\CashSession;
use CentralVet\Domain\Payment;
use CentralVet\Domain\PaymentReversal;
use CentralVet\Domain\Receivable;
use CentralVet\Domain\Sale;
use CentralVet\Domain\StockBatch;
use CentralVet\Domain\StockMovement;
use CentralVet\Tenancy\TenantContext;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\FakeAuthorizationPolicy;
use CentralVet\Tests\Support\FakeCashSessionRepository;
use CentralVet\Tests\Support\FakeFinancialEntryRepository;
use CentralVet\Tests\Support\FakePaymentRepository;
use CentralVet\Tests\Support\FakePaymentReversalRepository;
use CentralVet\Tests\Support\FakeReceivableRepository;
use CentralVet\Tests\Support\FakeSaleRepository;
use CentralVet\Tests\Support\FakeStockBatchRepository;
use CentralVet\Tests\Support\FakeStockMovementRepository;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Unit tests for SaleCancellationService::cancel() (Etapa 2, T-14), against
 * fakes: cancelling a sale returns the consumed stock, reverses the
 * payments of its receivable in the open cash session of the SALE's unit,
 * cancels the receivable and records reason/user/date on the sale. A
 * repeated call is a no-op; authorization is checked before any write.
 *
 * The session unit (CONTEXT_UNIT_ID) differs from the sale unit on purpose:
 * authorization and the reversal must use the sale's unit.
 */
final class SaleCancellationServiceTest
{
    private const ACTION = 'test::sale-cancel';
    private const TENANT_ID = 1;
    private const CONTEXT_UNIT_ID = 1;
    private const SALE_UNIT_ID = 5;
    private const USER_ID = 7;
    private const SELLER_ID = 3;
    private const PRODUCT_ID = 11;
    private const TUTOR_ID = 9;
    private const OPEN_SESSION_ID = 77;
    private const REASON = 'Cliente desistiu';

    private FakeSaleRepository $sales;
    private FakeReceivableRepository $receivables;
    private FakePaymentRepository $payments;
    private FakePaymentReversalRepository $reversals;
    private FakeCashSessionRepository $cashSessions;
    private FakeFinancialEntryRepository $entries;
    private FakeStockBatchRepository $batches;
    private FakeStockMovementRepository $movements;
    private FakeAuthorizationPolicy $authorization;
    private StockService $stock;

    private function buildService(): SaleCancellationService
    {
        $context = TenantContext::authenticated(self::TENANT_ID, self::USER_ID, self::CONTEXT_UNIT_ID);

        $this->sales = new FakeSaleRepository(self::TENANT_ID);
        $this->receivables = new FakeReceivableRepository(self::TENANT_ID);
        $this->payments = new FakePaymentRepository(self::TENANT_ID);
        $this->reversals = new FakePaymentReversalRepository();
        $this->cashSessions = new FakeCashSessionRepository(self::TENANT_ID);
        $this->entries = new FakeFinancialEntryRepository(self::TENANT_ID);
        $this->batches = new FakeStockBatchRepository(self::TENANT_ID);
        $this->movements = new FakeStockMovementRepository(self::TENANT_ID);
        $this->authorization = new FakeAuthorizationPolicy(allowed: true);
        $this->stock = new StockService($this->batches, $this->movements, $this->authorization, $context);

        $reversalService = new PaymentReversalService(
            $this->payments,
            $this->reversals,
            $this->receivables,
            $this->cashSessions,
            new FinancialEntryService($this->entries, $this->authorization, $context),
            $this->authorization,
            $context,
        );

        return new SaleCancellationService(
            $this->sales,
            $this->receivables,
            $this->stock,
            $reversalService,
            $this->authorization,
            $context,
        );
    }

    private function openCashSession(): void
    {
        $session = CashSession::open(
            tenantId: self::TENANT_ID,
            systemUnitId: self::SALE_UNIT_ID,
            openingBalanceCents: 0,
            openedBySystemUserId: self::USER_ID,
            openedAt: new DateTimeImmutable('-1 hour'),
        );
        $session->assignId(self::OPEN_SESSION_ID);
        $this->cashSessions->save($session);
    }

    /** Batch of 10 units; returns it after the sale consumed $units. */
    private function addBatch(): StockBatch
    {
        $batch = StockBatch::receive(
            tenantId: self::TENANT_ID,
            systemUnitId: self::SALE_UNIT_ID,
            productId: self::PRODUCT_ID,
            lot: 'LOTE-V',
            expiryDate: new DateTimeImmutable('+1 year'),
            quantity: 10,
            receivedAt: new DateTimeImmutable('-1 month'),
        );
        $this->batches->save($batch);

        return $batch;
    }

    /**
     * Sale of $units units (1000 cents each) that consumed stock and opened
     * a receivable; with $paidCash the total was received in cash in the
     * open cash session.
     */
    private function addSale(int $units, bool $paidCash): Sale
    {
        $total = $units * 1000;
        $sale = Sale::create(
            tenantId: self::TENANT_ID,
            systemUnitId: self::SALE_UNIT_ID,
            tutorId: self::TUTOR_ID,
            patientId: null,
            encounterId: null,
            systemUserId: self::SELLER_ID,
            totalAmountCents: $total,
            soldAt: new DateTimeImmutable('-10 minutes'),
        );
        $this->sales->save($sale);
        $saleId = (int) $sale->id();

        $this->stock->consume(
            self::TENANT_ID,
            self::SALE_UNIT_ID,
            self::PRODUCT_ID,
            $units,
            StockMovement::REASON_SALE_CONSUMPTION,
            'sale',
            $saleId,
            self::SELLER_ID,
        );

        $receivable = Receivable::openForSale(self::TENANT_ID, $saleId, self::TUTOR_ID, $total);

        if ($paidCash) {
            $receivable->recordPayment($total);
        }

        $this->receivables->save($receivable);

        if ($paidCash) {
            $payment = Payment::register(
                tenantId: self::TENANT_ID,
                receivableId: (int) $receivable->id(),
                paymentMethod: Payment::METHOD_CASH,
                amountCents: $total,
                cashSessionId: self::OPEN_SESSION_ID,
                systemUserId: self::SELLER_ID,
                paidAt: new DateTimeImmutable('-10 minutes'),
            );
            $this->payments->save($payment);
            $this->reversals->linkPayment((int) $payment->id(), (int) $receivable->id());
        }

        $this->authorization->requests = [];

        return $sale;
    }

    public function testCancelCashSaleReturnsStockReversesPaymentAndRecordsCancellation(): void
    {
        $service = $this->buildService();
        $this->openCashSession();
        $batch = $this->addBatch();
        $sale = $this->addSale(2, true);
        $saleId = (int) $sale->id();
        Assert::same(8, $this->batches->findById($batch->id())->quantity());

        $result = $service->cancel($saleId, self::REASON, self::USER_ID, self::ACTION);

        Assert::instanceOf(SaleCancellationResult::class, $result);
        Assert::false($result->alreadyCancelled);
        Assert::same(2, $result->returnedUnits);
        Assert::count(1, $result->reversals);
        Assert::instanceOf(PaymentReversal::class, $result->reversals[0]);
        Assert::same(self::OPEN_SESSION_ID, $result->reversals[0]->cashSessionId());
        Assert::same(2000, $result->reversals[0]->amountCents());
        Assert::same(self::REASON, $result->reversals[0]->reason());

        Assert::same(10, $this->batches->findById($batch->id())->quantity(), 'Batch must return to its previous quantity');

        $receivable = $this->receivables->findBySaleId($saleId);
        Assert::notNull($receivable);
        Assert::same(Receivable::STATUS_CANCELLED, $receivable->status());

        $reloaded = $this->sales->findById($saleId);
        Assert::notNull($reloaded);
        Assert::true($reloaded->isCancelled());
        Assert::same(self::REASON, $reloaded->cancellationReason());
        Assert::same(self::USER_ID, $reloaded->cancelledBySystemUserId());
        Assert::notNull($reloaded->cancelledAt());
        Assert::same($reloaded, $result->sale);

        $first = $this->authorization->requests[0];
        Assert::same(self::SALE_UNIT_ID, $first->resourceUnitId());
        Assert::same('sale', $first->entityType());
        Assert::same($saleId, $first->entityId());

        $receivableRequests = array_values(array_filter(
            $this->authorization->requests,
            static fn ($request): bool => $request->entityType() === 'receivable',
        ));
        Assert::count(1, $receivableRequests);
        Assert::same(self::SALE_UNIT_ID, $receivableRequests[0]->resourceUnitId(), 'Reversal must authorize the sale unit');
    }

    public function testSecondCallReturnsAlreadyCancelledWithoutWriting(): void
    {
        $service = $this->buildService();
        $this->openCashSession();
        $batch = $this->addBatch();
        $saleId = (int) $this->addSale(2, true)->id();

        $service->cancel($saleId, self::REASON, self::USER_ID, self::ACTION);
        $movementsAfterFirst = count($this->movements->listByReference('sale', $saleId));

        $second = $service->cancel($saleId, 'Outro motivo', 8, self::ACTION);

        Assert::true($second->alreadyCancelled);
        Assert::same(0, $second->returnedUnits);
        Assert::same([], $second->reversals);
        Assert::same(self::REASON, $second->sale->cancellationReason());
        Assert::same(self::USER_ID, $second->sale->cancelledBySystemUserId());
        Assert::count($movementsAfterFirst, $this->movements->listByReference('sale', $saleId), 'No new stock movement');
        Assert::count(1, $this->reversals->all(), 'No new reversal');
        Assert::same(10, $this->batches->findById($batch->id())->quantity());
    }

    public function testEmptyReasonThrowsWithoutTouchingStock(): void
    {
        $service = $this->buildService();
        $this->openCashSession();
        $batch = $this->addBatch();
        $saleId = (int) $this->addSale(2, true)->id();

        $message = null;
        try {
            $service->cancel($saleId, '   ', self::USER_ID, self::ACTION);
        } catch (InvalidArgumentException $e) {
            $message = $e->getMessage();
        }

        Assert::same('A cancellation reason is required', $message);
        Assert::same(8, $this->batches->findById($batch->id())->quantity());
        Assert::count(1, $this->movements->listByReference('sale', $saleId), 'Only the original consumption');
        Assert::count(0, $this->reversals->all());
        Assert::false($this->receivables->findBySaleId($saleId)->isCancelled());
        Assert::false($this->sales->findById($saleId)->isCancelled());
    }

    public function testUnpaidCreditSaleCancelsWithoutOpenCashSession(): void
    {
        $service = $this->buildService();
        $batch = $this->addBatch();
        $saleId = (int) $this->addSale(3, false)->id();

        $result = $service->cancel($saleId, self::REASON, self::USER_ID, self::ACTION);

        Assert::false($result->alreadyCancelled);
        Assert::same(3, $result->returnedUnits);
        Assert::same([], $result->reversals);
        Assert::same(10, $this->batches->findById($batch->id())->quantity());
        Assert::same(Receivable::STATUS_CANCELLED, $this->receivables->findBySaleId($saleId)->status());
        Assert::true($this->sales->findById($saleId)->isCancelled());
    }

    public function testAuthorizationDeniedThrowsBeforeAnyWrite(): void
    {
        $service = $this->buildService();
        $this->openCashSession();
        $batch = $this->addBatch();
        $saleId = (int) $this->addSale(2, true)->id();
        $this->authorization->setAllowed(false);

        Assert::throws(
            AuthorizationDenied::class,
            fn () => $service->cancel($saleId, self::REASON, self::USER_ID, self::ACTION),
        );

        Assert::count(1, $this->authorization->requests);
        Assert::same(self::SALE_UNIT_ID, $this->authorization->requests[0]->resourceUnitId());
        Assert::same('sale', $this->authorization->requests[0]->entityType());
        Assert::same(8, $this->batches->findById($batch->id())->quantity());
        Assert::count(1, $this->movements->listByReference('sale', $saleId));
        Assert::count(0, $this->reversals->all());
        Assert::false($this->receivables->findBySaleId($saleId)->isCancelled());
        Assert::false($this->sales->findById($saleId)->isCancelled());
    }

    public function testAlreadyCancelledSaleWithDeniedAuthorizationStillThrows(): void
    {
        $service = $this->buildService();
        $this->openCashSession();
        $this->addBatch();
        $saleId = (int) $this->addSale(2, true)->id();
        $service->cancel($saleId, self::REASON, self::USER_ID, self::ACTION);
        $this->authorization->setAllowed(false);

        Assert::throws(
            AuthorizationDenied::class,
            fn () => $service->cancel($saleId, self::REASON, self::USER_ID, self::ACTION),
        );
    }

    public function testUnknownSaleThrows(): void
    {
        $service = $this->buildService();

        $message = null;
        try {
            $service->cancel(999, self::REASON, self::USER_ID, self::ACTION);
        } catch (InvalidArgumentException $e) {
            $message = $e->getMessage();
        }

        Assert::same('Sale 999 not found', $message);
    }
}
