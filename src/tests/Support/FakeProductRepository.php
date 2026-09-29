<?php

declare(strict_types=1);

namespace CentralVet\Tests\Support;

use CentralVet\Domain\Contract\ProductRepositoryInterface;
use CentralVet\Domain\Product;
use InvalidArgumentException;

/**
 * In-memory double for ProductRepositoryInterface (T-03): the `product`
 * table does not exist yet (migration T-01 not applied), so
 * StockServiceTest/SaleServiceTest exercise their services against this
 * instead of a real database. Tenant-scoped like the real
 * ProductRepository (ADR 0002): findById()/findActive()/findByName() only
 * ever return a product whose tenantId() matches this instance's own
 * $tenantId.
 */
final class FakeProductRepository implements ProductRepositoryInterface
{
    /** @var array<int, Product> */
    private array $products = [];
    private int $nextId = 1;

    public function __construct(private readonly int $tenantId, Product ...$seed)
    {
        foreach ($seed as $product) {
            $this->save($product);
        }
    }

    public function tenantId(): int
    {
        return $this->tenantId;
    }

    public function findById(int|string $id): ?object
    {
        $product = $this->products[(int) $id] ?? null;

        if ($product === null || $product->tenantId() !== $this->tenantId) {
            return null;
        }

        return $product;
    }

    /** @return list<Product> */
    public function findActive(): array
    {
        return array_values(array_filter(
            $this->products,
            fn (Product $product): bool => $product->tenantId() === $this->tenantId && $product->isActive(),
        ));
    }

    public function findByName(string $name): ?object
    {
        foreach ($this->products as $product) {
            if ($product->tenantId() === $this->tenantId && $product->name() === $name) {
                return $product;
            }
        }

        return null;
    }

    public function save(object $entity): object
    {
        if (!$entity instanceof Product) {
            throw new InvalidArgumentException('FakeProductRepository only stores Product entities');
        }

        if ($entity->id() === null) {
            $entity->assignId($this->nextId++);
        }

        $this->products[$entity->id()] = $entity;

        return $entity;
    }

    public function remove(object $entity): void
    {
        if ($entity instanceof Product && $entity->id() !== null) {
            unset($this->products[$entity->id()]);
        }
    }
}
