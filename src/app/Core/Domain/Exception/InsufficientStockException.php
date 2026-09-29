<?php

declare(strict_types=1);

namespace CentralVet\Domain\Exception;

use RuntimeException;

/**
 * Thrown by CentralVet\Application\StockService::consume() when the sum of
 * a product's available stock_batch balances, within the requested system
 * unit, is smaller than the quantity requested. Nothing is persisted when
 * this is thrown: consume() sums the available balance across every ordered
 * batch BEFORE touching any of them, so a shortfall is detected and thrown
 * before a single stock_batch/stock_movement row is written.
 *
 * Kept in Domain\Exception alongside CrossTenantReferenceException /
 * InvalidStatusTransitionException (same rationale: a business rule owned
 * by the Domain layer, reusable from any presentation — Adianti today,
 * REST/worker later).
 */
final class InsufficientStockException extends RuntimeException
{
    private function __construct(
        public readonly int $productId,
        public readonly int $shortfall,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function forShortfall(int $productId, int $shortfall): self
    {
        return new self(
            $productId,
            $shortfall,
            "Insufficient stock for product_id {$productId}: short by {$shortfall} unit(s)",
        );
    }
}
