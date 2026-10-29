<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Domain\Exception\ProductWithoutSalePriceException;
use CentralVet\Domain\Product;
use CentralVet\Tests\Support\Assert;

/**
 * Etapa 1 (F1): Product::requireSalePriceCents() is the single rule for
 * "what does this product sell for" — null (field left blank in
 * ProductForm) is refused, 0 informed explicitly is a valid price, and
 * there is no fallback to the unit cost.
 */
final class ProductSalePriceTest
{
    public function testRequireSalePriceCentsReturnsTheSalePrice(): void
    {
        $product = Product::create(1, 'Coleira', null, 'un', 2000, 0, 3500);

        Assert::same(3500, $product->requireSalePriceCents());
    }

    public function testRequireSalePriceCentsAcceptsZero(): void
    {
        $product = Product::create(1, 'Brinde', null, 'un', 2000, 0, 0);

        Assert::same(0, $product->requireSalePriceCents());
    }

    public function testRequireSalePriceCentsThrowsWhenSalePriceIsNull(): void
    {
        $product = Product::create(1, 'Sem preço', null, 'un', 2000, 0, null);

        $caught = null;

        try {
            $product->requireSalePriceCents();
        } catch (ProductWithoutSalePriceException $exception) {
            $caught = $exception;
        }

        Assert::notNull($caught, 'Expected ProductWithoutSalePriceException');
        Assert::true($caught instanceof \DomainException, 'Must extend DomainException');
        Assert::same(
            'Product without sale price. Set the sale price in the product registration before selling.',
            $caught->getMessage(),
        );
    }
}
