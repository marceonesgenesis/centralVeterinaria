<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Application\FinancialEntryService;
use CentralVet\Application\PaymentService;
use CentralVet\Application\ProcedureCatalogService;
use CentralVet\Application\ProductService;
use CentralVet\Application\SaleCheckoutService;
use CentralVet\Application\SaleService;
use CentralVet\Application\StockService;
use CentralVet\Domain\CashSession;
use CentralVet\Domain\Exception\CashSessionRequiredException;
use CentralVet\Domain\Exception\DuplicateSubmissionException;
use CentralVet\Domain\Patient;
use CentralVet\Domain\Payment;
use CentralVet\Domain\Product;
use CentralVet\Domain\Receivable;
use CentralVet\Domain\StockBatch;
use CentralVet\Domain\SubmissionToken;
use CentralVet\Domain\Tutor;
use CentralVet\Tenancy\TenantContext;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\FakeAuthorizationPolicy;
use CentralVet\Tests\Support\FakeCashSessionRepository;
use CentralVet\Tests\Support\FakeEncounterRepository;
use CentralVet\Tests\Support\FakeFinancialEntryRepository;
use CentralVet\Tests\Support\FakePatientRepository;
use CentralVet\Tests\Support\FakePaymentRepository;
use CentralVet\Tests\Support\FakeProcedureCatalogItemInputRepository;
use CentralVet\Tests\Support\FakeProcedureCatalogRepository;
use CentralVet\Tests\Support\FakeProductRepository;
use CentralVet\Tests\Support\FakeReceivableRepository;
use CentralVet\Tests\Support\FakeSaleItemRepository;
use CentralVet\Tests\Support\FakeSaleRepository;
use CentralVet\Tests\Support\FakeStockBatchRepository;
use CentralVet\Tests\Support\FakeStockMovementRepository;
use CentralVet\Tests\Support\FakeSubmissionKeyRepository;
use CentralVet\Tests\Support\FakeTutorRepository;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Unit tests for SaleCheckoutService::checkout() (Etapa 2, T-13), against
 * fakes: sale + sale receivable + cash payment in one use case, deduplicated
 * by the form submission token.
 */
final class SaleCheckoutServiceTest
{
    private const TENANT_ID = 1;
    private const SYSTEM_UNIT_ID = 1;
    private const TUTOR_ID = 1;
    private const SYSTEM_USER_ID = 10;
    private const ACTION = 'SaleCheckoutServiceTest::checkout';

    public function testCashCheckoutRegistersPaymentAndPaysTheSaleReceivable(): void
    {
        $f = $this->build(withOpenCashSession: true);
        $procedureId = $this->procedure($f, 'Consulta', 10000);

        $result = $this->checkout($f, $procedureId, Payment::METHOD_CASH, SubmissionToken::generate());

        Assert::same(10000, $result->sale->totalAmountCents());
        Assert::notNull($result->payment);
        Assert::same(10000, $result->payment->amountCents());
        Assert::same(Payment::METHOD_CASH, $result->payment->paymentMethod());
        Assert::same($f['cashSessionId'], $result->payment->cashSessionId());
        Assert::same(Receivable::STATUS_PAID, $result->receivable->status());
        Assert::same(10000, $result->receivable->paidCents());
        Assert::same($result->sale->id(), $result->receivable->saleId());
        Assert::same($result->receivable->id(), $result->payment->receivableId());
        Assert::count(1, $f['payments']->listByReceivable((int) $result->receivable->id()));
    }

    public function testCreditCheckoutLeavesTheReceivableOpenWithoutPayment(): void
    {
        $f = $this->build(withOpenCashSession: false);
        $procedureId = $this->procedure($f, 'Consulta', 10000);

        $result = $this->checkout($f, $procedureId, null, SubmissionToken::generate());

        Assert::null($result->payment);
        Assert::same(Receivable::STATUS_OPEN, $result->receivable->status());
        Assert::same(10000, $result->receivable->totalCents());
        Assert::same(0, $result->receivable->paidCents());
        Assert::same($result->sale->id(), $result->receivable->saleId());
        Assert::notNull($f['receivables']->findBySaleId((int) $result->sale->id()));
    }

    public function testSameTokenTwiceRecordsOneSale(): void
    {
        $f = $this->build(withOpenCashSession: true);
        $procedureId = $this->procedure($f, 'Consulta', 10000);
        $token = SubmissionToken::generate();

        $first = $this->checkout($f, $procedureId, Payment::METHOD_CASH, $token);

        $duplicate = null;
        try {
            $this->checkout($f, $procedureId, Payment::METHOD_CASH, $token);
        } catch (DuplicateSubmissionException $e) {
            $duplicate = $e;
        }

        Assert::notNull($duplicate, 'The second submission of the same token must be refused');
        Assert::same(SubmissionToken::SCOPE_SALE, $duplicate->scope());
        Assert::same($first->sale->id(), $duplicate->referenceId());
        Assert::count(1, $f['sales']->listByTutor(self::TUTOR_ID));
        Assert::count(1, $f['payments']->listByReceivable((int) $first->receivable->id()));
    }

    public function testCashCheckoutWithoutOpenCashSessionWritesNothing(): void
    {
        $f = $this->build(withOpenCashSession: false);
        $productId = $this->productWithStock($f, 2500, 10);

        $caught = null;
        try {
            $f['service']->checkout(
                tenantId: self::TENANT_ID,
                systemUnitId: self::SYSTEM_UNIT_ID,
                tutorId: self::TUTOR_ID,
                patientId: null,
                encounterId: null,
                systemUserId: self::SYSTEM_USER_ID,
                items: [['type' => 'product', 'referenceId' => $productId, 'quantity' => 4]],
                paymentMethod: Payment::METHOD_CASH,
                submissionToken: SubmissionToken::generate(),
                action: self::ACTION,
            );
        } catch (CashSessionRequiredException $e) {
            $caught = $e;
        }

        Assert::notNull($caught, 'A cash checkout without an open cash session must be refused');
        Assert::same('Open a cash session for this unit before receiving payment', $caught->getMessage());
        Assert::count(0, $f['sales']->listByTutor(self::TUTOR_ID));
        Assert::count(0, $f['movements']->listByProduct($productId));
        Assert::count(0, $f['receivables']->listOpen());
    }

    public function testZeroTotalCheckoutWithMethodPaysTheReceivableWithoutPayment(): void
    {
        $f = $this->build(withOpenCashSession: true);
        $procedureId = $this->procedure($f, 'Retorno', 0);

        $result = $this->checkout($f, $procedureId, Payment::METHOD_PIX, SubmissionToken::generate());

        Assert::same(0, $result->sale->totalAmountCents());
        Assert::null($result->payment);
        Assert::same(Receivable::STATUS_PAID, $result->receivable->status());
        Assert::same(0, $result->receivable->totalCents());
        Assert::count(0, $f['payments']->listByReceivable((int) $result->receivable->id()));
        Assert::count(0, $f['payments']->listByCashSession($f['cashSessionId']));
    }

    public function testInvalidTokenIsRefusedBeforeAnyWrite(): void
    {
        $f = $this->build(withOpenCashSession: true);
        $procedureId = $this->procedure($f, 'Consulta', 10000);

        $caught = null;
        try {
            $this->checkout($f, $procedureId, Payment::METHOD_CASH, 'not-a-token');
        } catch (InvalidArgumentException $e) {
            $caught = $e;
        }

        Assert::notNull($caught);
        Assert::same('Invalid submission token', $caught->getMessage());
        Assert::count(0, $f['sales']->listByTutor(self::TUTOR_ID));
        Assert::count(0, $f['receivables']->listOpen());
    }

    public function testUnknownPaymentMethodIsRefusedBeforeCreatingTheSale(): void
    {
        $f = $this->build(withOpenCashSession: true);
        $procedureId = $this->procedure($f, 'Consulta', 10000);

        Assert::throws(
            InvalidArgumentException::class,
            fn () => $this->checkout($f, $procedureId, 'barter', SubmissionToken::generate()),
        );
        Assert::count(0, $f['sales']->listByTutor(self::TUTOR_ID));
    }

    /** @param array<string, mixed> $f */
    private function checkout(array $f, int $procedureId, ?string $paymentMethod, string $token): \CentralVet\Application\SaleCheckoutResult
    {
        return $f['service']->checkout(
            tenantId: self::TENANT_ID,
            systemUnitId: self::SYSTEM_UNIT_ID,
            tutorId: self::TUTOR_ID,
            patientId: null,
            encounterId: null,
            systemUserId: self::SYSTEM_USER_ID,
            items: [['type' => 'procedure', 'referenceId' => $procedureId, 'quantity' => 1]],
            paymentMethod: $paymentMethod,
            submissionToken: $token,
            action: self::ACTION,
        );
    }

    /** @param array<string, mixed> $f */
    private function procedure(array $f, string $name, int $priceCents): int
    {
        return (int) $f['procedureService']->create($name, $priceCents, null, null)->id();
    }

    /** @param array<string, mixed> $f */
    private function productWithStock(array $f, int $salePriceCents, int $quantity): int
    {
        $product = Product::create(self::TENANT_ID, 'Vermífugo', null, 'un', 1500, 0, $salePriceCents);
        $f['products']->save($product);

        $f['batches']->save(StockBatch::receive(
            tenantId: self::TENANT_ID,
            systemUnitId: self::SYSTEM_UNIT_ID,
            productId: (int) $product->id(),
            lot: null,
            expiryDate: null,
            quantity: $quantity,
            receivedAt: new DateTimeImmutable(),
        ));

        return (int) $product->id();
    }

    /** @return array<string, mixed> */
    private function build(bool $withOpenCashSession): array
    {
        $context = TenantContext::authenticated(self::TENANT_ID, 1, self::SYSTEM_UNIT_ID);
        $policy = new FakeAuthorizationPolicy(allowed: true);

        $products = new FakeProductRepository(self::TENANT_ID);
        $batches = new FakeStockBatchRepository(self::TENANT_ID);
        $movements = new FakeStockMovementRepository(self::TENANT_ID);
        $procedures = new FakeProcedureCatalogRepository(self::TENANT_ID);
        $procedureService = new ProcedureCatalogService($procedures, new FakeProcedureCatalogItemInputRepository(self::TENANT_ID), $context);
        $sales = new FakeSaleRepository(self::TENANT_ID);

        $saleService = new SaleService(
            $sales,
            new FakeSaleItemRepository(self::TENANT_ID),
            new ProductService($products, $context),
            new StockService($batches, $movements, $policy, $context),
            $procedureService,
            $policy,
            $context,
            new FakeTutorRepository(self::TENANT_ID, Tutor::register(self::TENANT_ID, 'Maria Tutora', '11999990001')),
            new FakePatientRepository(self::TENANT_ID, new Patient(null, self::TENANT_ID, self::TUTOR_ID, 'Rex', 'dog')),
            new FakeEncounterRepository(self::TENANT_ID),
        );

        $cashSessions = new FakeCashSessionRepository(self::TENANT_ID);
        $cashSessionId = null;

        if ($withOpenCashSession) {
            $cashSession = CashSession::open(
                tenantId: self::TENANT_ID,
                systemUnitId: self::SYSTEM_UNIT_ID,
                openingBalanceCents: 0,
                openedBySystemUserId: self::SYSTEM_USER_ID,
                openedAt: new DateTimeImmutable(),
            );
            $cashSessions->save($cashSession);
            $cashSessionId = $cashSession->id();
        }

        $receivables = new FakeReceivableRepository(self::TENANT_ID);
        $payments = new FakePaymentRepository(self::TENANT_ID);
        $submissionKeys = new FakeSubmissionKeyRepository();

        $paymentService = new PaymentService(
            $payments,
            $receivables,
            $cashSessions,
            new FinancialEntryService(new FakeFinancialEntryRepository(self::TENANT_ID), $policy, $context),
            $policy,
            $context,
            $submissionKeys,
        );

        $service = new SaleCheckoutService($saleService, $receivables, $paymentService, $cashSessions, $submissionKeys, $context);

        return [
            'service' => $service,
            'sales' => $sales,
            'receivables' => $receivables,
            'payments' => $payments,
            'products' => $products,
            'batches' => $batches,
            'movements' => $movements,
            'procedureService' => $procedureService,
            'cashSessionId' => $cashSessionId,
        ];
    }
}
