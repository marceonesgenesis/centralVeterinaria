<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Application\ProductService;
use CentralVet\Domain\Product;
use CentralVet\Tenancy\TenantContext;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\FakeProductRepository;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Unit tests for ProductService::update() (T-34), against
 * FakeProductRepository — the edit rule moved out of ProductForm.
 */
final class ProductServiceTest
{
    private const TENANT_ID = 1;

    /** @return array{0: ProductService, 1: FakeProductRepository, 2: int, 3: int} */
    private function fixture(): array
    {
        $createdAt = new DateTimeImmutable('2026-01-02 03:04:05');
        $a = Product::reconstitute(10, self::TENANT_ID, 'A', 'racao', 'un', 1000, 2, true, $createdAt, $createdAt);
        $b = Product::reconstitute(20, self::TENANT_ID, 'B', null, 'kg', 2000, 0, true, $createdAt, $createdAt);

        $repository = new FakeProductRepository(self::TENANT_ID, $a, $b);
        $service = new ProductService($repository, TenantContext::authenticated(self::TENANT_ID, 1));

        return [$service, $repository, 10, 20];
    }

    public function testUpdateChangesNameAndCostKeepingIdAndCreatedAt(): void
    {
        [$service, $repository, $idA] = $this->fixture();
        $before = count($repository->findActive());

        $updated = $service->update($idA, '  A2  ', 'racao', 'un', 1234, 3, true);

        Assert::same($idA, $updated->id());
        Assert::same('A2', $updated->name());
        Assert::same(1234, $updated->unitCostCents());
        Assert::same(3, $updated->minimumStockQuantity());
        Assert::same('2026-01-02 03:04:05', $updated->createdAt()?->format('Y-m-d H:i:s'));
        Assert::count($before, $repository->findActive());
        Assert::same('A2', $repository->findById($idA)?->name());
    }

    public function testUpdateKeepingOwnNameIsAllowed(): void
    {
        [$service, , $idA] = $this->fixture();

        $updated = $service->update($idA, 'A', null, 'un', 1001, 2, false);

        Assert::same('A', $updated->name());
        Assert::false($updated->isActive());
    }

    public function testUpdateRejectsNameOfAnotherProductOfTheTenant(): void
    {
        [$service, $repository, $idA] = $this->fixture();

        Assert::throws(InvalidArgumentException::class, static fn () => $service->update($idA, 'B', null, 'un', 1000, 2, true));
        Assert::same('A', $repository->findById($idA)?->name());
    }

    public function testUpdateRejectsUnknownId(): void
    {
        [$service] = $this->fixture();

        Assert::throws(InvalidArgumentException::class, static fn () => $service->update(999, 'X', null, 'un', 1000, 0, true));
    }

    public function testUpdateAppliesProductCreateRules(): void
    {
        [$service, , $idA] = $this->fixture();

        Assert::throws(InvalidArgumentException::class, static fn () => $service->update($idA, '   ', null, 'un', 1000, 0, true));
    }
}
