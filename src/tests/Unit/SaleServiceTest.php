<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Application\ProcedureCatalogService;
use CentralVet\Application\ProductService;
use CentralVet\Application\SaleService;
use CentralVet\Application\StockService;
use CentralVet\Domain\Exception\InsufficientStockException;
use CentralVet\Domain\Product;
use CentralVet\Domain\StockBatch;
use CentralVet\Domain\StockMovement;
use CentralVet\Tenancy\TenantContext;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\FakeAuthorizationPolicy;
use CentralVet\Tests\Support\FakeProcedureCatalogItemInputRepository;
use CentralVet\Tests\Support\FakeProcedureCatalogRepository;
use CentralVet\Tests\Support\FakeProductRepository;
use CentralVet\Tests\Support\FakeSaleItemRepository;
use CentralVet\Tests\Support\FakeSaleRepository;
use CentralVet\Tests\Support\FakeStockBatchRepository;
use CentralVet\Tests\Support\FakeStockMovementRepository;
use DateTimeImmutable;

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
 */
final class SaleServiceTest
{
    private const TENANT_ID = 1;
    private const SYSTEM_UNIT_ID = 1;
    private const TUTOR_ID = 1;
    private const SYSTEM_USER_ID = 10;

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

        $product = Product::create(self::TENANT_ID, 'Ração Premium', null, 'kg', 1500, 0);
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

        $product = Product::create(self::TENANT_ID, 'Vermífugo', null, 'un', 1500, 0);
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
        Assert::same(1500 * 2 + 8000 * 1, $sale->totalAmountCents());

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
     * @return array{0: SaleService, 1: FakeProductRepository, 2: FakeSaleRepository,
     *               3: FakeSaleItemRepository, 4: FakeStockBatchRepository,
     *               5: FakeStockMovementRepository, 6: FakeProcedureCatalogRepository,
     *               7: FakeProcedureCatalogItemInputRepository, 8: TenantContext}
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

        $service = new SaleService(
            $sales,
            $saleItems,
            $productService,
            $stockService,
            $procedureCatalogService,
            $policy,
            $context,
        );

        return [$service, $products, $sales, $saleItems, $batches, $movements, $procedures, $inputs, $context];
    }
}
