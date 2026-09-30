<?php

declare(strict_types=1);

namespace CentralVet\Tests\Integration;

use CentralVet\Application\StockSalesOverviewService;
use CentralVet\Persistence\StockSalesOverviewReader;
use CentralVet\Tenancy\TenantContext;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\MysqlIntegrationTestCase;
use DateTimeImmutable;

/**
 * Proves the stock/sales overview reads (Phase 10, T-04) against the real
 * MySQL schema: tenant isolation, low/out status rule and month totals.
 * Every row belongs to throwaway tenants created inside the test's own
 * transaction, rolled back in tearDown() — nothing is ever committed.
 *
 * Fixture (tenant A, month 2031-05):
 *   - "F10 Racao"     (Alimentos)  min 5, batches 1 + 2 = 3  -> low
 *   - "F10 Vermifugo" (Farmacia)   min 2, batch 10           -> normal
 *   - "F10 Coleira"   (Acessorios) min 1, no batch           -> out
 *   - sale 2031-05-10 total 5000 (2 + 1 items), sale 2031-05-20 total 3000
 *     (3 items), cancelled sale 2031-05-21 total 9999 (ignored);
 *     no sale in 2031-04.
 * Tenant B has one product with stock 100 and one sale in 2031-05.
 */
final class StockSalesOverviewIntegrationTest extends MysqlIntegrationTestCase
{
    private int $userId;
    private int $tenantA;
    private int $tenantB;

    public function setUp(): void
    {
        parent::setUp();

        $this->userId = (int) $this->pdo->query('SELECT MIN(id) FROM system_users')->fetchColumn();
        Assert::true($this->userId > 0, 'Fixture requires at least one existing system_users row');

        $this->tenantA = $this->createTenant('f10-stock-a');
        $this->tenantB = $this->createTenant('f10-stock-b');

        $unitA = $this->existingUnitId();
        $unitB = $unitA;
        $tutorA = $this->createTutor($this->tenantA);
        $tutorB = $this->createTutor($this->tenantB);
        $patientA = $this->createPatient($this->tenantA, $tutorA, 'F10 Rex');

        $racao = $this->createProduct($this->tenantA, 'F10 Racao', 'Alimentos', 5);
        $vermifugo = $this->createProduct($this->tenantA, 'F10 Vermifugo', 'Farmacia', 2);
        $this->createProduct($this->tenantA, 'F10 Coleira', 'Acessorios', 1);
        $productB = $this->createProduct($this->tenantB, 'F10 Produto B', 'Alimentos', 1);

        $this->createBatch($this->tenantA, $unitA, $racao, 1);
        $this->createBatch($this->tenantA, $unitA, $racao, 2);
        $this->createBatch($this->tenantA, $unitA, $vermifugo, 10);
        $this->createBatch($this->tenantB, $unitB, $productB, 100);

        $sale1 = $this->createSale($this->tenantA, $unitA, $tutorA, $patientA, 5000, '2031-05-10 10:00:00', 'completed');
        $this->createSaleItem($this->tenantA, $sale1, 'product', $racao, 'F10 Racao', 2, 4000);
        $this->createSaleItem($this->tenantA, $sale1, 'procedure', 1, 'F10 Banho', 1, 1000);

        $sale2 = $this->createSale($this->tenantA, $unitA, $tutorA, null, 3000, '2031-05-20 15:30:00', 'completed');
        $this->createSaleItem($this->tenantA, $sale2, 'product', $vermifugo, 'F10 Vermifugo', 3, 3000);

        $cancelled = $this->createSale($this->tenantA, $unitA, $tutorA, null, 9999, '2031-05-21 09:00:00', 'cancelled');
        $this->createSaleItem($this->tenantA, $cancelled, 'product', $racao, 'F10 Racao', 9, 9999);

        $saleB = $this->createSale($this->tenantB, $unitB, $tutorB, null, 777, '2031-05-15 12:00:00', 'completed');
        $this->createSaleItem($this->tenantB, $saleB, 'product', $productB, 'F10 Produto B', 7, 777);
    }

    public function testSummaryCountsOnlyTheCurrentTenant(): void
    {
        $summary = $this->serviceFor($this->tenantA)->summary(new DateTimeImmutable('2031-05-15'));

        Assert::same([
            'products_in_stock' => 2,
            'low_stock' => 1,
            'out_of_stock' => 1,
            'sales_month_cents' => 8000,
            'sales_prev_month_cents' => 0,
            'items_sold_month' => 6,
            'items_sold_prev_month' => 0,
        ], $summary, 'Summary of tenant A must ignore tenant B and cancelled sales');

        $next = $this->serviceFor($this->tenantA)->summary(new DateTimeImmutable('2031-06-01'));
        Assert::same(0, $next['sales_month_cents']);
        Assert::same(8000, $next['sales_prev_month_cents'], 'Previous month of June is May');
        Assert::same(6, $next['items_sold_prev_month']);
    }

    public function testPreviousMonthWithoutSalesYieldsZero(): void
    {
        $summary = $this->serviceFor($this->tenantA)->summary(new DateTimeImmutable('2031-05-01'));
        Assert::same(0, $summary['sales_prev_month_cents'], 'April has no sale');
        Assert::same(0, $summary['items_sold_prev_month']);

        $empty = $this->serviceFor($this->createTenant('f10-stock-empty'))->summary(new DateTimeImmutable('2031-05-15'));
        Assert::same(0, $empty['products_in_stock'], 'Tenant without products has zero products in stock');
        Assert::same(0, $empty['low_stock']);
        Assert::same(0, $empty['out_of_stock']);
        Assert::same(0, $empty['sales_month_cents']);
        Assert::same(0, $empty['sales_prev_month_cents']);
    }

    public function testProductsApplyStatusRuleAndNeverLeakOtherTenant(): void
    {
        $service = $this->serviceFor($this->tenantA);
        $products = $service->products();

        $byName = [];
        foreach ($products as $product) {
            $byName[$product['name']] = $product;
        }

        Assert::false(isset($byName['F10 Produto B']), 'Tenant B product must never appear for tenant A');
        Assert::same(['F10 Coleira', 'F10 Racao', 'F10 Vermifugo'], array_keys($byName));
        Assert::same('low', $byName['F10 Racao']['status']);
        Assert::same(3.0, $byName['F10 Racao']['stock_quantity']);
        Assert::same(5.0, $byName['F10 Racao']['minimum_stock_quantity']);
        Assert::same('Alimentos', $byName['F10 Racao']['category']);
        Assert::same('normal', $byName['F10 Vermifugo']['status']);
        Assert::same('out', $byName['F10 Coleira']['status']);
        Assert::same(0.0, $byName['F10 Coleira']['stock_quantity']);

        Assert::same(['F10 Racao'], array_column($service->products(null, null, 'low'), 'name'));
        Assert::same(['F10 Vermifugo'], array_column($service->products(null, 'Farmacia'), 'name'));
        Assert::same(['F10 Coleira'], array_column($service->products('colei'), 'name'));
        Assert::same([], $service->products('Produto B'), 'Search must not reach tenant B');
    }

    public function testLowStockListsLowAndOutOrderedByStockAscending(): void
    {
        $low = $this->serviceFor($this->tenantA)->lowStock();

        Assert::same(['F10 Coleira', 'F10 Racao'], array_column($low, 'name'));
        Assert::same(['out', 'low'], array_column($low, 'status'));
        Assert::same([], $this->serviceFor($this->tenantB)->lowStock(), 'Tenant B has no low stock');
    }

    public function testRecentSalesOnlyReturnCurrentTenantNewestFirst(): void
    {
        $sales = $this->serviceFor($this->tenantA)->recentSales();

        Assert::count(2, $sales, 'Cancelled sale and tenant B sale are excluded');
        Assert::same([3000, 5000], array_column($sales, 'total_cents'));
        Assert::same('2031-05-20 15:30:00', $sales[0]['sold_at']);
        Assert::null($sales[0]['patient_name']);
        Assert::same('F10 Rex', $sales[1]['patient_name']);
        Assert::stringContains('F10 Racao', $sales[1]['items_label']);
        Assert::stringContains('F10 Banho', $sales[1]['items_label']);
        Assert::count(1, $this->serviceFor($this->tenantA)->recentSales(1));
    }

    public function testNonPositiveLimitsReturnEmptyLists(): void
    {
        $service = $this->serviceFor($this->tenantA);

        Assert::same([], $service->recentSales(0), 'recentSales(0) must return no row');
        Assert::same([], $service->recentSales(-3), 'recentSales(-3) must return no row');
        Assert::same([], $service->lowStock(0), 'lowStock(0) must return no row');
        Assert::same([], $service->lowStock(-1), 'lowStock(-1) must return no row');
    }

    public function testOverviewMatchesSummaryProductsAndLowStock(): void
    {
        $service = $this->serviceFor($this->tenantA);
        $month = new DateTimeImmutable('2031-05-15');

        $overview = $service->overview($month);
        Assert::same(['summary', 'products', 'low_stock'], array_keys($overview));
        Assert::same($service->summary($month), $overview['summary']);
        Assert::same($service->products(), $overview['products']);
        Assert::same($service->lowStock(), $overview['low_stock']);

        $filtered = $service->overview($month, 'colei', null, null, 1);
        Assert::same($service->products('colei'), $filtered['products'], 'products honours the search filter');
        Assert::same($service->summary($month), $filtered['summary'], 'summary ignores the list filters');
        Assert::same($service->lowStock(1), $filtered['low_stock'], 'low_stock ignores the list filters');

        $byStatus = $service->overview($month, null, 'Alimentos', 'low');
        Assert::same($service->products(null, 'Alimentos', 'low'), $byStatus['products']);
        Assert::same(['F10 Coleira', 'F10 Racao'], array_column($byStatus['low_stock'], 'name'));

        Assert::same([], $service->overview($month, null, null, null, 0)['low_stock']);
    }

    public function testCategoriesAreDistinctAndSorted(): void
    {
        Assert::same(['Acessorios', 'Alimentos', 'Farmacia'], $this->serviceFor($this->tenantA)->categories());
        Assert::same(['Alimentos'], $this->serviceFor($this->tenantB)->categories());
    }

    private function serviceFor(int $tenantId): StockSalesOverviewService
    {
        return new StockSalesOverviewService(
            new StockSalesOverviewReader(TenantContext::authenticated($tenantId, $this->userId), $this->pdo),
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

    /**
     * system_unit.id has no AUTO_INCREMENT (Adianti table), so the fixture
     * reuses an existing unit instead of inserting one; the overview reads
     * are tenant-wide, so both tenants may share the unit id.
     */
    private function existingUnitId(): int
    {
        $unitId = (int) $this->pdo->query('SELECT MIN(id) FROM system_unit')->fetchColumn();
        Assert::true($unitId > 0, 'Fixture requires at least one existing system_unit row');

        return $unitId;
    }

    private function createTutor(int $tenantId): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO tutor (tenant_id, public_id, full_name, phone) VALUES (:tenant_id, UUID(), :full_name, :phone)',
        );
        $statement->execute(['tenant_id' => $tenantId, 'full_name' => 'F10 Tutor', 'phone' => '11999990000']);

        return (int) $this->pdo->lastInsertId();
    }

    private function createPatient(int $tenantId, int $tutorId, string $name): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO patient (tenant_id, tutor_id, name, species) VALUES (:tenant_id, :tutor_id, :name, :species)',
        );
        $statement->execute(['tenant_id' => $tenantId, 'tutor_id' => $tutorId, 'name' => $name, 'species' => 'Canina']);

        return (int) $this->pdo->lastInsertId();
    }

    private function createProduct(int $tenantId, string $name, string $category, int $minimum): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO product (tenant_id, name, category, unit_of_measure, unit_cost_cents, minimum_stock_quantity)
             VALUES (:tenant_id, :name, :category, :unit, :cost, :minimum)',
        );
        $statement->execute([
            'tenant_id' => $tenantId,
            'name' => $name,
            'category' => $category,
            'unit' => 'un',
            'cost' => 100,
            'minimum' => $minimum,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function createBatch(int $tenantId, int $unitId, int $productId, int $quantity): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO stock_batch (tenant_id, system_unit_id, product_id, quantity)
             VALUES (:tenant_id, :unit_id, :product_id, :quantity)',
        );
        $statement->execute([
            'tenant_id' => $tenantId,
            'unit_id' => $unitId,
            'product_id' => $productId,
            'quantity' => $quantity,
        ]);
    }

    private function createSale(
        int $tenantId,
        int $unitId,
        int $tutorId,
        ?int $patientId,
        int $totalCents,
        string $soldAt,
        string $status,
    ): int {
        $statement = $this->pdo->prepare(
            'INSERT INTO sale (tenant_id, system_unit_id, tutor_id, patient_id, system_user_id, status, total_amount_cents, sold_at)
             VALUES (:tenant_id, :unit_id, :tutor_id, :patient_id, :user_id, :status, :total, :sold_at)',
        );
        $statement->execute([
            'tenant_id' => $tenantId,
            'unit_id' => $unitId,
            'tutor_id' => $tutorId,
            'patient_id' => $patientId,
            'user_id' => $this->userId,
            'status' => $status,
            'total' => $totalCents,
            'sold_at' => $soldAt,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function createSaleItem(
        int $tenantId,
        int $saleId,
        string $type,
        int $referenceId,
        string $description,
        int $quantity,
        int $totalCents,
    ): void {
        $statement = $this->pdo->prepare(
            'INSERT INTO sale_item (tenant_id, sale_id, item_type, item_reference_id, description_text, unit_price_cents, quantity, total_cents)
             VALUES (:tenant_id, :sale_id, :type, :reference_id, :description, :unit_price, :quantity, :total)',
        );
        $statement->execute([
            'tenant_id' => $tenantId,
            'sale_id' => $saleId,
            'type' => $type,
            'reference_id' => $referenceId,
            'description' => $description,
            'unit_price' => intdiv($totalCents, $quantity),
            'quantity' => $quantity,
            'total' => $totalCents,
        ]);
    }
}
