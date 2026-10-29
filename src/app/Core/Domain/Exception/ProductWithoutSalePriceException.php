<?php

declare(strict_types=1);

namespace CentralVet\Domain\Exception;

use DomainException;

/**
 * Thrown by CentralVet\Domain\Product::requireSalePriceCents() when a
 * product is about to be sold without a sale price (sale_price_cents NULL,
 * i.e. the field was left blank in ProductForm). Etapa 1 / F1: the PDV
 * never falls back to the unit cost; 0 informed explicitly is a valid
 * price (giveaway) and does not throw.
 */
final class ProductWithoutSalePriceException extends DomainException
{
    public const MESSAGE = 'Product without sale price. Set the sale price in the product registration before selling.';

    public function __construct()
    {
        parent::__construct(self::MESSAGE);
    }
}
