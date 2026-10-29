<?php

declare(strict_types=1);

namespace CentralVet\Tests\Integration;

use CentralVet\Application\ProcedureCatalogService;
use CentralVet\Application\ProductService;
use CentralVet\Application\SaleService;
use CentralVet\Application\StockService;
use CentralVet\Domain\Exception\CrossTenantReferenceException;
use CentralVet\Domain\Product;
use CentralVet\Persistence\EncounterRepository;
use CentralVet\Persistence\PatientRepository;
use CentralVet\Persistence\ProcedureCatalogItemInputRepository;
use CentralVet\Persistence\ProcedureCatalogRepository;
use CentralVet\Persistence\ProductRepository;
use CentralVet\Persistence\SaleItemRepository;
use CentralVet\Persistence\SaleRepository;
use CentralVet\Persistence\StockBatchRepository;
use CentralVet\Persistence\StockMovementRepository;
use CentralVet\Persistence\TutorRepository;
use CentralVet\Tenancy\TenantContext;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\FakeAuthorizationPolicy;
use CentralVet\Tests\Support\MysqlIntegrationTestCase;

/**
 * Etapa 1 / S4 + F1 regression (T-10): SaleService wired with the real
 * MySQL repositories, the same set SaleForm::buildSaleService() builds.
 * A sale whose tutor, patient or encounter belongs to another tenant is
 * rejected with CrossTenantReferenceException and leaves COUNT(*) of
 * `sale`, `sale_item` and `stock_movement` unchanged; a valid sale records
 * the product's sale price (3500), never its unit cost (2000).
 *
 * Only the authorization policy is a double (always allow): the real
 * RbacAuthorizationService reads the Adianti session. Every row lives in
 * throwaway tenants created inside the test transaction and rolled back in
 * tearDown() — nothing is committed.
 */
final class SaleServiceTenantReferencesIntegrationTest extends MysqlIntegrationTestCase
{
    private const ACTION = 'SaleForm::onSave';

    private int $userId;
    private int $tenantA;
    private int $tenantB;
    private int $unitA;
    private int $unitB;
    private int $tutorA;
    private int $patientA;
    private int $encounterA;
    private int $tutorB;
    private int $patientB;
    private int $encounterB;
    private int $productA;

    public function setUp(): void
    {
        parent::setUp();

        $this->userId = (int) $this->pdo->query('SELECT MIN(id) FROM system_users')->fetchColumn();
        Assert::true($this->userId > 0, 'Fixture requires at least one existing system_users row');

        $this->tenantA = $this->createTenant('t10-sale-a');
        $this->tenantB = $this->createTenant('t10-sale-b');
        $this->unitA = $this->createSystemUnit($this->tenantA, 'T-10 unit A');
        $this->unitB = $this->createSystemUnit($this->tenantB, 'T-10 unit B');

        $this->tutorA = $this->createTutor($this->tenantA, 'T-10 tutor A');
        $this->patientA = $this->createPatient($this->tenantA, $this->tutorA, 'T-10 patient A');
        $this->encounterA = $this->createEncounter($this->tenantA, $this->unitA, $this->patientA);

        $this->tutorB = $this->createTutor($this->tenantB, 'T-10 tutor B');
        $this->patientB = $this->createPatient($this->tenantB, $this->tutorB, 'T-10 patient B');
        $this->encounterB = $this->createEncounter($this->tenantB, $this->unitB, $this->patientB);

        $product = Product::create($this->tenantA, 'T-10 ração', null, 'un', 2000, 0, 3500, null);
        (new ProductRepository($this->contextA(), $this->pdo))->save($product);
        $this->productA = (int) $product->id();
        Assert::true($this->productA > 0, 'product insert assigns the generated id');

        $this->createStockBatch($this->tenantA, $this->unitA, $this->productA, 10);
    }

    public function testTutorOfAnotherTenantIsRejectedAndNothingIsWritten(): void
    {
        $this->assertRejectedWithoutWrites($this->tutorB, null, null);
    }

    public function testPatientOfAnotherTenantIsRejectedAndNothingIsWritten(): void
    {
        $this->assertRejectedWithoutWrites($this->tutorA, $this->patientB, null);
    }

    public function testEncounterOfAnotherTenantIsRejectedAndNothingIsWritten(): void
    {
        $this->assertRejectedWithoutWrites($this->tutorA, $this->patientA, $this->encounterB);
    }

    public function testValidSaleRecordsTheSalePriceNotTheUnitCost(): void
    {
        $before = $this->counts();

        $sale = $this->service()->create(
            $this->tenantA,
            $this->unitA,
            $this->tutorA,
            $this->patientA,
            $this->encounterA,
            $this->userId,
            [['type' => 'product', 'referenceId' => $this->productA, 'quantity' => 2]],
            self::ACTION,
        );

        $saleId = (int) $sale->id();
        Assert::true($saleId > 0, 'the valid sale is persisted');

        $statement = $this->pdo->prepare(
            'SELECT unit_price_cents, quantity, total_cents FROM sale_item WHERE sale_id = :sale_id',
        );
        $statement->execute(['sale_id' => $saleId]);
        $rows = $statement->fetchAll(\PDO::FETCH_ASSOC);

        Assert::count(1, $rows, 'one sale_item row for the one cart line');
        Assert::same(3500, (int) $rows[0]['unit_price_cents'], 'sale_item.unit_price_cents is the sale price, not the cost 2000');
        Assert::same(7000, (int) $rows[0]['total_cents']);

        $total = $this->pdo->prepare('SELECT total_amount_cents FROM sale WHERE id = :id');
        $total->execute(['id' => $saleId]);
        Assert::same(7000, (int) $total->fetchColumn(), 'sale.total_amount_cents sums the sale price');

        $after = $this->counts();
        Assert::same($before['sale'] + 1, $after['sale']);
        Assert::same($before['sale_item'] + 1, $after['sale_item']);
        Assert::same($before['stock_movement'] + 1, $after['stock_movement'], 'stock is consumed for the valid sale');
    }

    private function assertRejectedWithoutWrites(int $tutorId, ?int $patientId, ?int $encounterId): void
    {
        $before = $this->counts();

        Assert::throws(
            CrossTenantReferenceException::class,
            fn () => $this->service()->create(
                $this->tenantA,
                $this->unitA,
                $tutorId,
                $patientId,
                $encounterId,
                $this->userId,
                [['type' => 'product', 'referenceId' => $this->productA, 'quantity' => 1]],
                self::ACTION,
            ),
        );

        Assert::same($before, $this->counts(), 'sale, sale_item and stock_movement COUNT(*) unchanged');
    }

    /** @return array{sale: int, sale_item: int, stock_movement: int} */
    private function counts(): array
    {
        $counts = [];

        foreach (['sale', 'sale_item', 'stock_movement'] as $table) {
            $counts[$table] = (int) $this->pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
        }

        return $counts;
    }

    private function contextA(): TenantContext
    {
        return TenantContext::authenticated($this->tenantA, $this->userId, $this->unitA);
    }

    /** Same wiring as SaleForm::buildSaleService(), except the authorization double. */
    private function service(): SaleService
    {
        $context = $this->contextA();
        $authorization = new FakeAuthorizationPolicy();

        return new SaleService(
            new SaleRepository($context, $this->pdo),
            new SaleItemRepository($context, $this->pdo),
            new ProductService(new ProductRepository($context, $this->pdo), $context),
            new StockService(
                new StockBatchRepository($context, $this->pdo),
                new StockMovementRepository($context, $this->pdo),
                $authorization,
                $context,
            ),
            new ProcedureCatalogService(
                new ProcedureCatalogRepository($context, $this->pdo),
                new ProcedureCatalogItemInputRepository($context, $this->pdo),
                $context,
            ),
            $authorization,
            $context,
            new TutorRepository($context, $this->pdo),
            new PatientRepository($context, $this->pdo),
            new EncounterRepository($context, $this->pdo),
        );
    }

    private function createTenant(string $slugPrefix): int
    {
        $slug = $slugPrefix . '-' . bin2hex(random_bytes(4));
        $statement = $this->pdo->prepare(
            "INSERT INTO tenant (public_id, slug, legal_name, status) VALUES (UUID(), :slug, :legal_name, 'active')",
        );
        $statement->execute(['slug' => $slug, 'legal_name' => 'T-10 test tenant (' . $slug . ')']);

        return (int) $this->pdo->lastInsertId();
    }

    /** system_unit.id is not auto_increment (Adianti manages it). */
    private function createSystemUnit(int $tenantId, string $name): int
    {
        $id = (int) $this->pdo->query('SELECT COALESCE(MAX(id), 0) + 1 FROM system_unit')->fetchColumn();
        $statement = $this->pdo->prepare(
            'INSERT INTO system_unit (id, tenant_id, name, custom_code) VALUES (:id, :tenant_id, :name, :custom_code)',
        );
        $statement->execute(['id' => $id, 'tenant_id' => $tenantId, 'name' => $name, 'custom_code' => 'T10-' . $id]);

        return $id;
    }

    private function createTutor(int $tenantId, string $name): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO tutor (tenant_id, public_id, full_name, phone) VALUES (:tenant_id, UUID(), :full_name, :phone)',
        );
        $statement->execute(['tenant_id' => $tenantId, 'full_name' => $name, 'phone' => '11900000010']);

        return (int) $this->pdo->lastInsertId();
    }

    private function createPatient(int $tenantId, int $tutorId, string $name): int
    {
        $statement = $this->pdo->prepare(
            "INSERT INTO patient (tenant_id, tutor_id, name, species) VALUES (:tenant_id, :tutor_id, :name, 'canina')",
        );
        $statement->execute(['tenant_id' => $tenantId, 'tutor_id' => $tutorId, 'name' => $name]);

        return (int) $this->pdo->lastInsertId();
    }

    private function createEncounter(int $tenantId, int $unitId, int $patientId): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO encounter (tenant_id, system_unit_id, patient_id, professional_system_user_id)
             VALUES (:tenant_id, :unit_id, :patient_id, :user_id)',
        );
        $statement->execute([
            'tenant_id' => $tenantId,
            'unit_id' => $unitId,
            'patient_id' => $patientId,
            'user_id' => $this->userId,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function createStockBatch(int $tenantId, int $unitId, int $productId, int $quantity): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO stock_batch (tenant_id, system_unit_id, product_id, lot, quantity)
             VALUES (:tenant_id, :unit_id, :product_id, :lot, :quantity)',
        );
        $statement->execute([
            'tenant_id' => $tenantId,
            'unit_id' => $unitId,
            'product_id' => $productId,
            'lot' => 'T10-LOT',
            'quantity' => $quantity,
        ]);
    }
}
