<?php

declare(strict_types=1);

namespace CentralVet\Domain\Contract;

use DateTimeImmutable;

/**
 * Read-only stock data behind the copilot tool `consultar_estoque` (Fase
 * 8B.2), scoped to the tenant AND the active unit of the session. The
 * balance is `SUM(stock_batch.quantity)` of the active unit only (other
 * units never count). Only active products. `minimum` is null when the
 * product has no minimum (0); `next_expiry`/`lot` describe the earliest
 * batch with stock that has an expiry date (null when none).
 *
 * Every method returns
 * list<array{product_id: int, name: string, balance: string, minimum: ?string, next_expiry: ?string, lot: ?string}>.
 */
interface CopilotStockQueryInterface
{
    /**
     * Products with `minimum_stock_quantity > 0` and unit balance <= minimum
     * (out of stock included), lowest balance first.
     *
     * @return list<array{product_id: int, name: string, balance: string, minimum: ?string, next_expiry: ?string, lot: ?string}>
     */
    public function belowMinimum(int $limit): array;

    /**
     * Batches of the unit with `quantity > 0` and `expiry_date` between
     * $today and $today + $days (inclusive), earliest first; one row per
     * batch (`balance` = the product's unit balance).
     *
     * @return list<array{product_id: int, name: string, balance: string, minimum: ?string, next_expiry: ?string, lot: ?string}>
     */
    public function expiring(DateTimeImmutable $today, int $days, int $limit): array;

    /**
     * Active products whose name or code contains $term, by name.
     *
     * @return list<array{product_id: int, name: string, balance: string, minimum: ?string, next_expiry: ?string, lot: ?string}>
     */
    public function search(string $term, int $limit): array;
}
