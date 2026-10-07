<?php

declare(strict_types=1);

namespace CentralVet\Application;

use CentralVet\Persistence\StockSalesOverviewReader;
use DateTimeImmutable;

/**
 * Indicators for the "Estoque e Vendas" screen (Phase 10, T-04): summary
 * cards, product table with stock status, recent sales and low-stock
 * column. Read-only; tenant scope comes from the reader's TenantContext.
 *
 * Status rule: out = stock <= 0; low = 0 < stock <= minimum; else normal.
 */
final class StockSalesOverviewService
{
    public const STATUS_NORMAL = 'normal';
    public const STATUS_LOW = 'low';
    public const STATUS_OUT = 'out';

    public function __construct(private readonly StockSalesOverviewReader $reader)
    {
    }

    /**
     * @return array{products_in_stock: int, low_stock: int, out_of_stock: int, sales_month_cents: int, sales_prev_month_cents: int, items_sold_month: int, items_sold_prev_month: int}
     */
    public function summary(DateTimeImmutable $month): array
    {
        return $this->summarize($this->products(), $month);
    }

    /**
     * Summary, product table and low-stock column of one screen load.
     * summary and low_stock always cover the unfiltered product set;
     * products honours $search/$category/$status. The reader's
     * productStocks() runs once unfiltered and, only when $search or
     * $category is given, once more with those filters.
     *
     * @return array{summary: array{products_in_stock: int, low_stock: int, out_of_stock: int, sales_month_cents: int, sales_prev_month_cents: int, items_sold_month: int, items_sold_prev_month: int}, products: list<array{id: int, name: string, category: string, unit: string, stock_quantity: float, minimum_stock_quantity: float, code: ?string, sale_price_cents: ?int, status: string}>, low_stock: list<array{id: int, name: string, category: string, unit: string, stock_quantity: float, minimum_stock_quantity: float, code: ?string, sale_price_cents: ?int, status: string}>}
     */
    public function overview(
        DateTimeImmutable $month,
        ?string $search = null,
        ?string $category = null,
        ?string $status = null,
        int $lowStockLimit = 5,
    ): array {
        $all = $this->withStatus($this->reader->productStocks());
        $hasReaderFilter = ($search !== null && trim($search) !== '') || ($category !== null && $category !== '');
        $listed = $hasReaderFilter ? $this->withStatus($this->reader->productStocks($search, $category)) : $all;

        return [
            'summary' => $this->summarize($all, $month),
            'products' => self::filterByStatus($listed, $status),
            'low_stock' => self::lowestFirst($all, $lowStockLimit),
        ];
    }

    /**
     * @return list<array{id: int, name: string, category: string, unit: string, stock_quantity: float, minimum_stock_quantity: float, code: ?string, sale_price_cents: ?int, status: string}>
     */
    public function products(?string $search = null, ?string $category = null, ?string $status = null): array
    {
        return self::filterByStatus($this->withStatus($this->reader->productStocks($search, $category)), $status);
    }

    /**
     * @return list<array{id: int, sold_at: string, total_cents: int, items_label: string, patient_name: ?string}>
     */
    public function recentSales(int $limit = 5): array
    {
        if ($limit <= 0) {
            return [];
        }

        return $this->reader->recentSales($limit);
    }

    /**
     * Products with status low/out, lowest stock first. A limit <= 0 returns [].
     *
     * @return list<array{id: int, name: string, category: string, unit: string, stock_quantity: float, minimum_stock_quantity: float, code: ?string, sale_price_cents: ?int, status: string}>
     */
    public function lowStock(int $limit = 5): array
    {
        return self::lowestFirst($this->products(), $limit);
    }

    /** @return list<string> */
    public function categories(): array
    {
        return $this->reader->categories();
    }

    /**
     * @param list<array{id: int, name: string, category: string, unit: string, stock_quantity: float, minimum_stock_quantity: float, code: ?string, sale_price_cents: ?int, status: string}> $products
     * @return array{products_in_stock: int, low_stock: int, out_of_stock: int, sales_month_cents: int, sales_prev_month_cents: int, items_sold_month: int, items_sold_prev_month: int}
     */
    private function summarize(array $products, DateTimeImmutable $month): array
    {
        $inStock = 0;
        $low = 0;
        $out = 0;

        foreach ($products as $product) {
            if ($product['status'] === self::STATUS_OUT) {
                $out++;
                continue;
            }

            $inStock++;

            if ($product['status'] === self::STATUS_LOW) {
                $low++;
            }
        }

        $start = $month->modify('first day of this month')->setTime(0, 0);
        $next = $start->modify('+1 month');
        $previous = $start->modify('-1 month');

        $current = $this->reader->salesTotals($start->format('Y-m-d H:i:s'), $next->format('Y-m-d H:i:s'));
        $prior = $this->reader->salesTotals($previous->format('Y-m-d H:i:s'), $start->format('Y-m-d H:i:s'));

        return [
            'products_in_stock' => $inStock,
            'low_stock' => $low,
            'out_of_stock' => $out,
            'sales_month_cents' => $current['total_cents'],
            'sales_prev_month_cents' => $prior['total_cents'],
            'items_sold_month' => $current['items'],
            'items_sold_prev_month' => $prior['items'],
        ];
    }

    /**
     * @param list<array{id: int, name: string, category: string, unit: string, stock_quantity: float, minimum_stock_quantity: float, code: ?string, sale_price_cents: ?int}> $rows
     * @return list<array{id: int, name: string, category: string, unit: string, stock_quantity: float, minimum_stock_quantity: float, code: ?string, sale_price_cents: ?int, status: string}>
     */
    private function withStatus(array $rows): array
    {
        foreach ($rows as $index => $row) {
            $rows[$index]['status'] = self::statusOf($row['stock_quantity'], $row['minimum_stock_quantity']);
        }

        return $rows;
    }

    /**
     * @param list<array{status: string}> $products
     * @return list<array{status: string}>
     */
    private static function filterByStatus(array $products, ?string $status): array
    {
        if ($status === null || $status === '') {
            return $products;
        }

        return array_values(array_filter(
            $products,
            static fn (array $product): bool => $product['status'] === $status,
        ));
    }

    /**
     * @param list<array{name: string, stock_quantity: float, status: string}> $products
     * @return list<array{name: string, stock_quantity: float, status: string}>
     */
    private static function lowestFirst(array $products, int $limit): array
    {
        if ($limit <= 0) {
            return [];
        }

        $rows = array_values(array_filter(
            $products,
            static fn (array $product): bool => $product['status'] !== self::STATUS_NORMAL,
        ));

        usort(
            $rows,
            static fn (array $a, array $b): int => [$a['stock_quantity'], $a['name']] <=> [$b['stock_quantity'], $b['name']],
        );

        return array_slice($rows, 0, $limit);
    }

    private static function statusOf(float $stock, float $minimum): string
    {
        if ($stock <= 0) {
            return self::STATUS_OUT;
        }

        return $stock <= $minimum ? self::STATUS_LOW : self::STATUS_NORMAL;
    }
}
