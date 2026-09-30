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
        [$service, $repository, $idA, $idB] = $this->fixture();

        self::assertThrowsMessage(
            'A product named "B" already exists for this tenant',
            static fn () => $service->update($idA, 'B', null, 'un', 1000, 2, true),
        );
        Assert::same('A', $repository->findById($idA)?->name());
        Assert::same(1000, $repository->findById($idA)?->unitCostCents());
        Assert::same('B', $repository->findById($idB)?->name(), 'Product B must stay untouched');
        Assert::same(2000, $repository->findById($idB)?->unitCostCents(), 'Product B cost must stay untouched');
    }

    public function testUpdateRejectsUnknownId(): void
    {
        [$service] = $this->fixture();

        self::assertThrowsMessage(
            'Product 999 not found for this tenant',
            static fn () => $service->update(999, 'X', null, 'un', 1000, 0, true),
        );
    }

    public function testUpdateRejectsProductOfAnotherTenant(): void
    {
        $createdAt = new DateTimeImmutable('2026-01-02 03:04:05');
        $foreign = Product::reconstitute(30, 2, 'Outro', null, 'un', 500, 0, true, $createdAt, $createdAt, 700, 'T2-1');
        $repository = new FakeProductRepository(self::TENANT_ID, $foreign);
        $service = new ProductService($repository, TenantContext::authenticated(self::TENANT_ID, 1));

        self::assertThrowsMessage(
            'Product 30 not found for this tenant',
            static fn () => $service->update(30, 'Invadido', null, 'un', 1, 0, true),
        );
        Assert::null($repository->findByCode('T2-1'), 'Code of another tenant must not be visible');
        Assert::same('Outro', $foreign->name(), 'Tenant 2 product must stay untouched');
    }

    public function testCreateStoresSalePriceAndCode(): void
    {
        [$service, $repository] = $this->fixture();

        $created = $service->create(self::TENANT_ID, 'C', null, 'un', 1000, 0, salePriceCents: 1990, code: '  SKU-1  ');

        Assert::same(1990, $created->salePriceCents());
        Assert::same('SKU-1', $created->code());
        Assert::same($created->id(), $repository->findByCode('SKU-1')?->id());
    }

    public function testCreateRejectsCodeOfAnotherProduct(): void
    {
        [$service] = $this->fixture();
        $service->create(self::TENANT_ID, 'C', null, 'un', 1000, 0, salePriceCents: 1990, code: 'SKU-1');

        self::assertThrowsMessage(
            'A product with code "SKU-1" already exists for this tenant',
            static fn () => $service->create(self::TENANT_ID, 'D', null, 'un', 1000, 0, salePriceCents: null, code: 'SKU-1'),
        );
    }

    public function testUpdateCodeRulesAndKeepingOwnCode(): void
    {
        [$service, $repository, $idA, $idB] = $this->fixture();

        $a = $service->update($idA, 'A', null, 'un', 1000, 2, true, 2500, 'SKU-A');
        Assert::same(2500, $a->salePriceCents());
        Assert::same('SKU-A', $a->code());

        $again = $service->update($idA, 'A', null, 'un', 1000, 2, true, 2600, 'SKU-A');
        Assert::same(2600, $again->salePriceCents(), 'Keeping its own code is allowed');

        self::assertThrowsMessage(
            'A product with code "SKU-A" already exists for this tenant',
            static fn () => $service->update($idB, 'B', null, 'kg', 2000, 0, true, null, 'SKU-A'),
        );
        Assert::null($repository->findById($idB)?->code());

        $cleared = $service->update($idA, 'A', null, 'un', 1000, 2, true, null, '   ');
        Assert::null($cleared->code(), 'Blank code becomes null');
        Assert::null($cleared->salePriceCents());
    }

    public function testSalePriceAndCodeValidation(): void
    {
        [$service, , $idA] = $this->fixture();

        self::assertThrowsMessage(
            'sale_price_cents cannot be negative',
            static fn () => $service->update($idA, 'A', null, 'un', 1000, 2, true, -1, null),
        );
        self::assertThrowsMessage(
            'code must have at most 60 characters',
            static fn () => $service->update($idA, 'A', null, 'un', 1000, 2, true, null, str_repeat('x', 61)),
        );
    }

    public function testExistingCallersKeepDefaults(): void
    {
        $product = Product::create(self::TENANT_ID, 'E', null, 'un', 100, 0);

        Assert::null($product->salePriceCents());
        Assert::null($product->code());
    }

    private static function assertThrowsMessage(string $expected, callable $callback): void
    {
        try {
            $callback();
        } catch (InvalidArgumentException $e) {
            Assert::same($expected, $e->getMessage());

            return;
        }

        Assert::true(false, "Expected InvalidArgumentException \"{$expected}\" was not thrown");
    }

    public function testUpdateAppliesProductCreateRules(): void
    {
        [$service, , $idA] = $this->fixture();

        Assert::throws(InvalidArgumentException::class, static fn () => $service->update($idA, '   ', null, 'un', 1000, 0, true));
    }
}
