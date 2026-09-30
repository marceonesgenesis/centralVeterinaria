<?php

declare(strict_types=1);

namespace CentralVet\Application;

use CentralVet\Domain\Contract\ProductRepositoryInterface;
use CentralVet\Domain\Product;
use CentralVet\Tenancy\TenantContext;
use InvalidArgumentException;

/**
 * Use cases for the Product aggregate (T-03): registering a new catalog
 * entry and listing the ones currently offered by the tenant. Stock-level
 * operations (receiving a batch, consuming stock across batches in expiry
 * order) live in the sibling CentralVet\Application\StockService, not here
 * — Product only owns the catalog entry itself (name, unit of measure,
 * cost, minimum stock threshold).
 *
 * $tenantId is accepted explicitly on every method, matching this task's
 * own Interface spec (tasks.md T-03), but is always cross-checked against
 * the injected TenantContext via TenantContext::assertTenant() before
 * touching the repository. CentralVet\Domain\Contract\
 * ProductRepositoryInterface itself takes no tenantId parameter on any
 * method — same pattern as TutorRepositoryInterface/ServiceRepositoryInterface
 * — and resolves its own tenant scope from the TenantContext injected into
 * the concrete Repository (ADR 0002). So $tenantId here can never widen
 * that scope, only be rejected when it disagrees with it.
 */
final class ProductService
{
    public function __construct(
        private readonly ProductRepositoryInterface $products,
        private readonly TenantContext $context,
    ) {
    }

    public function create(
        int $tenantId,
        string $name,
        ?string $category,
        string $unitOfMeasure,
        int $unitCostCents,
        int $minimumStockQuantity,
    ): Product {
        $this->context->assertTenant($tenantId);

        $name = trim($name);

        if ($name === '') {
            throw new InvalidArgumentException('name is required');
        }

        if ($this->products->findByName($name) !== null) {
            throw new InvalidArgumentException("A product named \"{$name}\" already exists for this tenant");
        }

        $product = Product::create(
            tenantId: $tenantId,
            name: $name,
            category: $category,
            unitOfMeasure: $unitOfMeasure,
            unitCostCents: $unitCostCents,
            minimumStockQuantity: $minimumStockQuantity,
        );

        /** @var Product $saved */
        $saved = $this->products->save($product);

        return $saved;
    }

    /**
     * Edits a catalog entry of the session tenant (T-34): same rules as
     * Product::create() (name trimmed), name unique within the tenant
     * (keeping its own name is allowed), id/tenantId/createdAt preserved.
     */
    public function update(
        int $productId,
        string $name,
        ?string $category,
        string $unitOfMeasure,
        int $unitCostCents,
        int $minimumStockQuantity,
        bool $active,
    ): Product {
        /** @var Product|null $current */
        $current = $this->products->findById($productId);

        if (!$current instanceof Product) {
            throw new InvalidArgumentException("Product {$productId} not found for this tenant");
        }

        $validated = Product::create(
            tenantId: $current->tenantId(),
            name: $name,
            category: $category,
            unitOfMeasure: $unitOfMeasure,
            unitCostCents: $unitCostCents,
            minimumStockQuantity: $minimumStockQuantity,
        );

        $name = $validated->name();
        $sameName = $this->products->findByName($name);

        if ($sameName instanceof Product && $sameName->id() !== $current->id()) {
            throw new InvalidArgumentException("A product named \"{$name}\" already exists for this tenant");
        }

        $product = Product::reconstitute(
            (int) $current->id(),
            $current->tenantId(),
            $validated->name(),
            $validated->category(),
            $validated->unitOfMeasure(),
            $validated->unitCostCents(),
            $validated->minimumStockQuantity(),
            $active,
            $current->createdAt(),
            $current->updatedAt(),
        );

        /** @var Product $saved */
        $saved = $this->products->save($product);

        return $saved;
    }

    /** @return list<Product> */
    public function listActive(int $tenantId): array
    {
        $this->context->assertTenant($tenantId);

        /** @var list<Product> $products */
        $products = $this->products->findActive();

        return $products;
    }

    public function findById(int $id): ?Product
    {
        /** @var Product|null $product */
        $product = $this->products->findById($id);

        return $product;
    }
}
