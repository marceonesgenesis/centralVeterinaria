<?php

declare(strict_types=1);

namespace CentralVet\Tests\Integration;

use CentralVet\Domain\Product;
use CentralVet\Persistence\ProductRepository;
use CentralVet\Tenancy\TenantContext;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\MysqlIntegrationTestCase;

/**
 * ProductRepository against the real MySQL schema (rodada 2, T-37: the
 * `sale_price_cents`/`code` columns of migration 0007, delivered by T-11).
 * Every row belongs to throwaway tenants created inside the test's
 * transaction, rolled back in tearDown() — nothing is committed.
 */
final class ProductRepositoryIntegrationTest extends MysqlIntegrationTestCase
{
    private int $userId;
    private int $tenantA;
    private int $tenantB;

    public function setUp(): void
    {
        parent::setUp();

        $this->userId = (int) $this->pdo->query('SELECT MIN(id) FROM system_users')->fetchColumn();
        Assert::true($this->userId > 0, 'Fixture requires at least one existing system_users row');

        $this->tenantA = $this->createTenant('r2-product-a');
        $this->tenantB = $this->createTenant('r2-product-b');
    }

    public function testSaveAndFindByIdPreserveSalePriceAndCode(): void
    {
        $repository = $this->repositoryFor($this->tenantA);

        $product = Product::create($this->tenantA, 'Ração R2', 'Alimentos', 'un', 1500, 3, 2190, 'R2-X');
        $repository->save($product);
        Assert::true(($product->id() ?? 0) > 0, 'insert assigns the generated id');

        /** @var Product $found */
        $found = $repository->findById((int) $product->id());
        Assert::instanceOf(Product::class, $found);
        Assert::same(2190, $found->salePriceCents());
        Assert::same('R2-X', $found->code());
        Assert::same(1500, $found->unitCostCents());
        Assert::same('Ração R2', $found->name());

        // UPDATE branch: new sale price and code persist on the same row
        $changed = Product::reconstitute(
            id: (int) $found->id(),
            tenantId: $found->tenantId(),
            name: $found->name(),
            category: $found->category(),
            unitOfMeasure: $found->unitOfMeasure(),
            unitCostCents: $found->unitCostCents(),
            minimumStockQuantity: $found->minimumStockQuantity(),
            active: $found->isActive(),
            createdAt: $found->createdAt(),
            updatedAt: $found->updatedAt(),
            salePriceCents: 2590,
            code: 'R2-Y',
        );
        $repository->save($changed);

        /** @var Product $again */
        $again = $repository->findById((int) $product->id());
        Assert::same(2590, $again->salePriceCents());
        Assert::same('R2-Y', $again->code());

        Assert::null($this->repositoryFor($this->tenantB)->findById((int) $product->id()), 'other tenant cannot find it');
    }

    public function testNullSalePriceAndCodeRoundTripAsNull(): void
    {
        $repository = $this->repositoryFor($this->tenantA);

        $product = Product::create($this->tenantA, 'Seringa R2', null, 'un', 100, 0);
        $repository->save($product);

        /** @var Product $found */
        $found = $repository->findById((int) $product->id());
        Assert::null($found->salePriceCents());
        Assert::null($found->code());
    }

    public function testFindByCodeIsCaseInsensitiveAndTenantScoped(): void
    {
        $product = Product::create($this->tenantA, 'Vermífugo R2', null, 'un', 800, 0, 1200, 'R2-X');
        $this->repositoryFor($this->tenantA)->save($product);

        /** @var Product $found */
        $found = $this->repositoryFor($this->tenantA)->findByCode('r2-x');
        Assert::notNull($found, "findByCode('r2-x') must find 'R2-X' (utf8mb4 ai_ci collation)");
        Assert::same($product->id(), $found->id());
        Assert::same('R2-X', $found->code());

        Assert::null($this->repositoryFor($this->tenantA)->findByCode('R2-Z'), 'unknown code yields null');
        Assert::null($this->repositoryFor($this->tenantB)->findByCode('R2-X'), 'other tenant cannot find it by code');
    }

    private function repositoryFor(int $tenantId): ProductRepository
    {
        return new ProductRepository(TenantContext::authenticated($tenantId, $this->userId), $this->pdo);
    }

    private function createTenant(string $slugPrefix): int
    {
        $slug = $slugPrefix . '-' . bin2hex(random_bytes(4));
        $statement = $this->pdo->prepare(
            'INSERT INTO tenant (public_id, slug, legal_name, status) VALUES (UUID(), :slug, :legal_name, :status)',
        );
        $statement->execute(['slug' => $slug, 'legal_name' => 'R2 test tenant (' . $slug . ')', 'status' => 'active']);

        return (int) $this->pdo->lastInsertId();
    }
}
