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
        ?int $salePriceCents = null,
        ?string $code = null,
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
            salePriceCents: $salePriceCents,
            code: $code,
        );

        $this->assertCodeAvailable($product->code(), null);

        /** @var Product $saved */
        $saved = $this->products->save($product);

        return $saved;
    }

    /**
     * Edits a catalog entry of the session tenant (T-34): same rules as
     * Product::create() (name trimmed), name unique within the tenant
     * (keeping its own name is allowed), id/tenantId/createdAt preserved.
     * Rodada 2 (T-11): sale price and code (code unique within the tenant,
     * keeping its own code is allowed). The id is resolved through the
     * tenant-scoped repository, so another tenant's id is "not found".
     */
    public function update(
        int $productId,
        string $name,
        ?string $category,
        string $unitOfMeasure,
        int $unitCostCents,
        int $minimumStockQuantity,
        bool $active,
        ?int $salePriceCents = null,
        ?string $code = null,
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
            salePriceCents: $salePriceCents,
            code: $code,
        );

        $name = $validated->name();
        $sameName = $this->products->findByName($name);

        if ($sameName instanceof Product && $sameName->id() !== $current->id()) {
            throw new InvalidArgumentException("A product named \"{$name}\" already exists for this tenant");
        }

        $this->assertCodeAvailable($validated->code(), (int) $current->id());

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
            $validated->salePriceCents(),
            $validated->code(),
        );

        /** @var Product $saved */
        $saved = $this->products->save($product);

        return $saved;
    }

    /**
     * Rejects a code already used by another product of the tenant
     * ($ownId = the product being edited, whose own code is allowed).
     */
    private function assertCodeAvailable(?string $code, ?int $ownId): void
    {
        if ($code === null) {
            return;
        }

        $sameCode = $this->products->findByCode($code);

        if ($sameCode instanceof Product && $sameCode->id() !== $ownId) {
            throw new InvalidArgumentException("A product with code \"{$code}\" already exists for this tenant");
        }
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
