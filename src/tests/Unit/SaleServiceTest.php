<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Application\ProcedureCatalogService;
use CentralVet\Application\ProductService;
use CentralVet\Application\SaleService;
use CentralVet\Application\StockService;
use CentralVet\Domain\Encounter;
use CentralVet\Domain\Exception\CrossTenantReferenceException;
use CentralVet\Domain\Exception\InsufficientStockException;
use CentralVet\Domain\Exception\ProductWithoutSalePriceException;
use CentralVet\Domain\Patient;
use CentralVet\Domain\Product;
use CentralVet\Domain\StockBatch;
use CentralVet\Domain\StockMovement;
use CentralVet\Domain\Tutor;
use CentralVet\Tenancy\TenantContext;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\FakeAuthorizationPolicy;
use CentralVet\Tests\Support\FakeEncounterRepository;
use CentralVet\Tests\Support\FakePatientRepository;
use CentralVet\Tests\Support\FakeProcedureCatalogItemInputRepository;
use CentralVet\Tests\Support\FakeProcedureCatalogRepository;
use CentralVet\Tests\Support\FakeProductRepository;
use CentralVet\Tests\Support\FakeSaleItemRepository;
use CentralVet\Tests\Support\FakeSaleRepository;
use CentralVet\Tests\Support\FakeStockBatchRepository;
use CentralVet\Tests\Support\FakeStockMovementRepository;
use CentralVet\Tests\Support\FakeTutorRepository;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Unit tests for SaleService::create() (T-06), against fake repositories —
 * no database involved, since `sale`/`sale_item`/`product`/`stock_batch`
 * do not exist yet (migration T-01 not applied).
 *
 * Covers create()'s "no partial sale" guarantee (see its own class
 * docblock, phase 4): when a product item's stock is short, consume()
 * throws InsufficientStockException and the Sale + every SaleItem already
 * persisted in phase 3 are compensated away (deleted) before the exception
 * is rethrown — proven here by reloading both fakes and finding nothing
 * left. Also covers the mixed product+procedure happy path, where the
 * total is the exact sum of every line and stock is consumed only for
 * product-type items.
 *
 * Etapa 1 (S4 + F1): product lines are priced by Product::salePriceCents()
 * (never the unit cost) and a product without a sale price is refused;
 * tutor/patient/encounter are resolved in the tenant and checked for
 * coherence before any write. Seeded fixtures (makeService()):
 *   tutors     #1, #2 (tenant 1), #3 (tenant 2)
 *   patients   #1 (tutor 1), #2 (tutor 2), #3 (tenant 2)
 *   encounters #1 (patient 1, unit 1), #2 (patient 2, unit 1),
 *              #3 (patient 1, unit 2), #4 (tenant 2)
 */
final class SaleServiceTest
{
    private const TENANT_ID = 1;
    private const SYSTEM_UNIT_ID = 1;
    private const TUTOR_ID = 1;
    private const SYSTEM_USER_ID = 10;
    private const OTHER_TENANT_TUTOR_ID = 3;
    private const PATIENT_ID = 1;
    private const OTHER_TUTOR_PATIENT_ID = 2;
    private const OTHER_TENANT_PATIENT_ID = 3;
    private const ENCOUNTER_ID = 1;
    private const OTHER_PATIENT_ENCOUNTER_ID = 2;
    private const OTHER_UNIT_ENCOUNTER_ID = 3;
    private const OTHER_TENANT_ENCOUNTER_ID = 4;

    /**
     * A single product item requests more (5) than the batch actually has
     * (2): create() must throw InsufficientStockException, and — the
     * acceptance criterion — neither the Sale row nor its SaleItem may
     * remain persisted afterwards (the compensating delete described in the
     * class docblock), and the batch itself must be untouched since
     * consume() never writes anything on a short balance.
     */
    public function testCreateThrowsInsufficientStockExceptionAndPersistsNeitherSaleNorSaleItems(): void
    {
        [$service, $products, $sales, $saleItems, $batches, $movements] = $this->makeService();

        $product = Product::create(self::TENANT_ID, 'Ração Premium', null, 'kg', 1500, 0, 2500);
        $products->save($product);
        $productId = $product->id();

        $batch = StockBatch::receive(
            tenantId: self::TENANT_ID,
            systemUnitId: self::SYSTEM_UNIT_ID,
            productId: $productId,
            lot: null,
            expiryDate: null,
            quantity: 2,
            receivedAt: new DateTimeImmutable(),
        );
        $batches->save($batch);

        $caught = null;

        try {
            $service->create(
                tenantId: self::TENANT_ID,
                systemUnitId: self::SYSTEM_UNIT_ID,
                tutorId: self::TUTOR_ID,
                patientId: null,
                encounterId: null,
                systemUserId: self::SYSTEM_USER_ID,
                items: [
                    ['type' => 'product', 'referenceId' => $productId, 'quantity' => 5],
                ],
                action: 'SaleServiceTest::create',
            );
        } catch (InsufficientStockException $exception) {
            $caught = $exception;
        }

        Assert::notNull($caught, 'Expected InsufficientStockException to be thrown');
        Assert::same($productId, $caught->productId);
        Assert::same(3, $caught->shortfall);

        Assert::count(0, $sales->listByTutor(self::TUTOR_ID), 'No Sale row may remain persisted');
        Assert::null($sales->findById(1), 'The Sale written during phase 3 must have been compensated away');
        Assert::count(0, $saleItems->listBySale(1), 'No SaleItem row may remain persisted');

        $reloadedBatch = $batches->findById($batch->id());
        Assert::notNull($reloadedBatch);
        Assert::same(2, $reloadedBatch->quantity(), 'consume() writes nothing at all when the balance is short');
        Assert::count(0, $movements->listByProduct($productId));
    }

    /**
     * Happy path: a mixed cart (one product line, one procedure line) is
     * persisted with an exact total, and stock is consumed only for the
     * product-type item — reason=sale_consumption, referenced back to the
     * now-real sale id.
     */
    public function testCreatePersistsSaleAndSaleItemsAndConsumesStockOnlyForProductItems(): void
    {
        [$service, $products, $sales, $saleItems, $batches, $movements, $procedures, $inputs, $context] = $this->makeService();

        $product = Product::create(self::TENANT_ID, 'Vermífugo', null, 'un', 1500, 0, 2500);
        $products->save($product);
        $productId = $product->id();

        $batch = StockBatch::receive(
            tenantId: self::TENANT_ID,
            systemUnitId: self::SYSTEM_UNIT_ID,
            productId: $productId,
            lot: null,
            expiryDate: null,
            quantity: 10,
            receivedAt: new DateTimeImmutable(),
        );
        $batches->save($batch);

        $procedureCatalogService = new ProcedureCatalogService($procedures, $inputs, $context);
        $procedureItem = $procedureCatalogService->create('Consulta', 8000, null, null);
        $procedureItemId = $procedureItem->id();

        $sale = $service->create(
            tenantId: self::TENANT_ID,
            systemUnitId: self::SYSTEM_UNIT_ID,
            tutorId: self::TUTOR_ID,
            patientId: null,
            encounterId: null,
            systemUserId: self::SYSTEM_USER_ID,
            items: [
                ['type' => 'product', 'referenceId' => $productId, 'quantity' => 2],
                ['type' => 'procedure', 'referenceId' => $procedureItemId, 'quantity' => 1],
            ],
            action: 'SaleServiceTest::create',
        );

        Assert::notNull($sale->id());
        Assert::same(2500 * 2 + 8000 * 1, $sale->totalAmountCents(), 'Product lines are priced by sale price, not cost');

        Assert::count(2, $saleItems->listBySale($sale->id()));

        $reloadedBatch = $batches->findById($batch->id());
        Assert::notNull($reloadedBatch);
        Assert::same(8, $reloadedBatch->quantity());

        $productMovements = $movements->listByProduct($productId);
        Assert::count(1, $productMovements);
        Assert::same(StockMovement::REASON_SALE_CONSUMPTION, $productMovements[0]->reason());
        Assert::same('sale', $productMovements[0]->referenceType());
        Assert::same($sale->id(), $productMovements[0]->referenceId());
    }

    /**
     * F1: a product with cost 2000 and sale price 3500 is sold at 3500 —
     * the SaleItem unit price and the Sale total both come from the sale
     * price, never from the unit cost.
     */
    public function testCreatePricesProductItemBySalePriceNotByCost(): void
    {
        [$service, $products, , $saleItems, $batches] = $this->makeService();
        $productId = $this->seedProductWithStock($products, $batches, 3500);

        $sale = $service->create(
            tenantId: self::TENANT_ID,
            systemUnitId: self::SYSTEM_UNIT_ID,
            tutorId: self::TUTOR_ID,
            patientId: null,
            encounterId: null,
            systemUserId: self::SYSTEM_USER_ID,
            items: [['type' => 'product', 'referenceId' => $productId, 'quantity' => 1]],
            action: 'SaleServiceTest::create',
        );

        $items = $saleItems->listBySale((int) $sale->id());
        Assert::count(1, $items);
        Assert::same(3500, $items[0]->unitPriceCents());
        Assert::same(3500, $items[0]->totalCents());
        Assert::same(3500, $sale->totalAmountCents());
    }

    /** F1: a product without sale price (null) is refused before any write. */
    public function testCreateRefusesProductWithoutSalePriceAndWritesNothing(): void
    {
        $this->assertRefusedWithoutWrites(
            ProductWithoutSalePriceException::class,
            'Product without sale price. Set the sale price in the product registration before selling.',
            salePriceCents: null,
        );
    }

    /** S4: tutor of another tenant (indistinguishable from nonexistent). */
    public function testCreateRefusesTutorFromAnotherTenant(): void
    {
        $this->assertRefusedWithoutWrites(
            CrossTenantReferenceException::class,
            null,
            tutorId: self::OTHER_TENANT_TUTOR_ID,
        );
    }

    /** S4: patient that does not resolve in the tenant. */
    public function testCreateRefusesPatientFromAnotherTenant(): void
    {
        $this->assertRefusedWithoutWrites(
            CrossTenantReferenceException::class,
            null,
            patientId: self::OTHER_TENANT_PATIENT_ID,
        );
    }

    /** S4: patient of the tenant but owned by another tutor. */
    public function testCreateRefusesPatientOfAnotherTutor(): void
    {
        $this->assertRefusedWithoutWrites(
            InvalidArgumentException::class,
            'The patient does not belong to the selected tutor',
            patientId: self::OTHER_TUTOR_PATIENT_ID,
        );
    }

    /** S4: encounter informed without a patient. */
    public function testCreateRefusesEncounterWithoutPatient(): void
    {
        $this->assertRefusedWithoutWrites(
            InvalidArgumentException::class,
            'patient_id is required when encounter_id is informed',
            patientId: null,
            encounterId: self::ENCOUNTER_ID,
        );
    }

    /** S4: encounter that does not resolve in the tenant. */
    public function testCreateRefusesEncounterFromAnotherTenant(): void
    {
        $this->assertRefusedWithoutWrites(
            CrossTenantReferenceException::class,
            null,
            patientId: self::PATIENT_ID,
            encounterId: self::OTHER_TENANT_ENCOUNTER_ID,
        );
    }

    /** S4: encounter of another patient of the same tenant. */
    public function testCreateRefusesEncounterOfAnotherPatient(): void
    {
        $this->assertRefusedWithoutWrites(
            InvalidArgumentException::class,
            'The encounter does not belong to the selected patient and unit',
            patientId: self::PATIENT_ID,
            encounterId: self::OTHER_PATIENT_ENCOUNTER_ID,
        );
    }

    /** S4: encounter of the right patient but another system unit. */
    public function testCreateRefusesEncounterOfAnotherUnit(): void
    {
        $this->assertRefusedWithoutWrites(
            InvalidArgumentException::class,
            'The encounter does not belong to the selected patient and unit',
            patientId: self::PATIENT_ID,
            encounterId: self::OTHER_UNIT_ENCOUNTER_ID,
        );
    }

    /** S4: coherent tutor + patient + encounter of the tenant are accepted. */
    public function testCreateAcceptsCoherentTutorPatientAndEncounter(): void
    {
        [$service, $products, , , $batches] = $this->makeService();
        $productId = $this->seedProductWithStock($products, $batches, 3500);

        $sale = $service->create(
            tenantId: self::TENANT_ID,
            systemUnitId: self::SYSTEM_UNIT_ID,
            tutorId: self::TUTOR_ID,
            patientId: self::PATIENT_ID,
            encounterId: self::ENCOUNTER_ID,
            systemUserId: self::SYSTEM_USER_ID,
            items: [['type' => 'product', 'referenceId' => $productId, 'quantity' => 1]],
            action: 'SaleServiceTest::create',
        );

        Assert::notNull($sale->id());
        Assert::same(self::PATIENT_ID, $sale->patientId());
        Assert::same(self::ENCOUNTER_ID, $sale->encounterId());
    }

    /**
     * Runs create() with one valid-stock product line and the given
     * overrides, expects $exceptionClass (and $message, when given), and
     * proves nothing was written: no Sale, no SaleItem, no stock movement,
     * batch untouched.
     */
    private function assertRefusedWithoutWrites(
        string $exceptionClass,
        ?string $message,
        int $tutorId = self::TUTOR_ID,
        ?int $patientId = null,
        ?int $encounterId = null,
        ?int $salePriceCents = 3500,
    ): void {
        [$service, $products, $sales, $saleItems, $batches, $movements] = $this->makeService();
        $productId = $this->seedProductWithStock($products, $batches, $salePriceCents);

        $caught = null;

        try {
            $service->create(
                tenantId: self::TENANT_ID,
                systemUnitId: self::SYSTEM_UNIT_ID,
                tutorId: $tutorId,
                patientId: $patientId,
                encounterId: $encounterId,
                systemUserId: self::SYSTEM_USER_ID,
                items: [['type' => 'product', 'referenceId' => $productId, 'quantity' => 1]],
                action: 'SaleServiceTest::create',
            );
        } catch (\Throwable $exception) {
            $caught = $exception;
        }

        Assert::notNull($caught, "Expected {$exceptionClass} to be thrown");
        Assert::true(
            $caught instanceof $exceptionClass,
            "Expected {$exceptionClass}, got " . $caught::class . ': ' . $caught->getMessage(),
        );

        if ($message !== null) {
            Assert::same($message, $caught->getMessage());
        }

        Assert::null($sales->findById(1), 'No Sale may be persisted');
        Assert::count(0, $sales->listByTutor($tutorId), 'No Sale may be persisted for the tutor');
        Assert::count(0, $saleItems->listBySale(1), 'No SaleItem may be persisted');
        Assert::count(0, $movements->listByProduct($productId), 'No stock movement may be written');
        Assert::same(10, $batches->findById(1)?->quantity(), 'Batch must be untouched');
    }

    private function seedProductWithStock(
        FakeProductRepository $products,
        FakeStockBatchRepository $batches,
        ?int $salePriceCents,
    ): int {
        $product = Product::create(self::TENANT_ID, 'Coleira', null, 'un', 2000, 0, $salePriceCents);
        $products->save($product);
        $productId = (int) $product->id();

        $batches->save(StockBatch::receive(
            tenantId: self::TENANT_ID,
            systemUnitId: self::SYSTEM_UNIT_ID,
            productId: $productId,
            lot: null,
            expiryDate: null,
            quantity: 10,
            receivedAt: new DateTimeImmutable(),
        ));

        return $productId;
    }

    /**
     * @return array{0: SaleService, 1: FakeProductRepository, 2: FakeSaleRepository,
     *               3: FakeSaleItemRepository, 4: FakeStockBatchRepository,
     *               5: FakeStockMovementRepository, 6: FakeProcedureCatalogRepository,
     *               7: FakeProcedureCatalogItemInputRepository, 8: TenantContext,
     *               9: FakeTutorRepository, 10: FakePatientRepository,
     *               11: FakeEncounterRepository}
     */
    private function makeService(): array
    {
        $context = TenantContext::authenticated(self::TENANT_ID, 1, self::SYSTEM_UNIT_ID);

        $products = new FakeProductRepository(self::TENANT_ID);
        $productService = new ProductService($products, $context);

        $batches = new FakeStockBatchRepository(self::TENANT_ID);
        $movements = new FakeStockMovementRepository(self::TENANT_ID);
        $stockService = new StockService($batches, $movements, new FakeAuthorizationPolicy(allowed: true), $context);

        $procedures = new FakeProcedureCatalogRepository(self::TENANT_ID);
        $inputs = new FakeProcedureCatalogItemInputRepository(self::TENANT_ID);
        $procedureCatalogService = new ProcedureCatalogService($procedures, $inputs, $context);

        $sales = new FakeSaleRepository(self::TENANT_ID);
        $saleItems = new FakeSaleItemRepository(self::TENANT_ID);
        $policy = new FakeAuthorizationPolicy(allowed: true);

        $tutors = new FakeTutorRepository(
            self::TENANT_ID,
            Tutor::register(self::TENANT_ID, 'Maria Tutora', '11999990001'),
            Tutor::register(self::TENANT_ID, 'João Tutor', '11999990002'),
            Tutor::register(2, 'Tutor de outra clínica', '11999990003'),
        );
        $patients = new FakePatientRepository(
            self::TENANT_ID,
            new Patient(null, self::TENANT_ID, 1, 'Rex', 'dog'),
            new Patient(null, self::TENANT_ID, 2, 'Mia', 'cat'),
            new Patient(null, 2, 3, 'Thor', 'dog'),
        );
        $now = new DateTimeImmutable('2026-10-21 09:00:00');
        $encounters = new FakeEncounterRepository(
            self::TENANT_ID,
            Encounter::start(self::TENANT_ID, self::SYSTEM_UNIT_ID, 1, null, self::SYSTEM_USER_ID, $now),
            Encounter::start(self::TENANT_ID, self::SYSTEM_UNIT_ID, 2, null, self::SYSTEM_USER_ID, $now),
            Encounter::start(self::TENANT_ID, 2, 1, null, self::SYSTEM_USER_ID, $now),
            Encounter::start(2, self::SYSTEM_UNIT_ID, 3, null, self::SYSTEM_USER_ID, $now),
        );

        $service = new SaleService(
            $sales,
            $saleItems,
            $productService,
            $stockService,
            $procedureCatalogService,
            $policy,
            $context,
            $tutors,
            $patients,
            $encounters,
        );

        return [
            $service, $products, $sales, $saleItems, $batches, $movements, $procedures, $inputs, $context,
            $tutors, $patients, $encounters,
        ];
    }
}
