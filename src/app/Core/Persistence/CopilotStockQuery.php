<?php

declare(strict_types=1);

namespace CentralVet\Persistence;

use CentralVet\Domain\Contract\CopilotStockQueryInterface;
use CentralVet\Tenancy\TenantContext;
use DateTimeImmutable;
use PDO;

/**
 * Read-only stock reads behind `consultar_estoque` (T-20), scoped by tenant
 * AND by the active unit (TenantContext::requireUnitId(), never caller
 * input). Unlike StockSalesOverviewReader::productStocks (all units), the
 * balance here sums only the batches of the active unit. Only active
 * products. Every statement carries the 5 s execution-time optimizer hint.
 */
final class CopilotStockQuery implements CopilotStockQueryInterface
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly PDO $connection,
    ) {
    }

    public function belowMinimum(int $limit): array
    {
        $rows = $this->fetchAll(
            'SELECT /*+ MAX_EXECUTION_TIME(5000) */ p.id, p.name, p.minimum_stock_quantity,
                    COALESCE(SUM(b.quantity), 0) AS balance
               FROM product p
               LEFT JOIN stock_batch b
                 ON b.product_id = p.id AND b.tenant_id = p.tenant_id AND b.system_unit_id = :unit_id
              WHERE p.tenant_id = :tenant_id AND p.active = 1 AND p.minimum_stock_quantity > 0
              GROUP BY p.id, p.name, p.minimum_stock_quantity
             HAVING COALESCE(SUM(b.quantity), 0) <= p.minimum_stock_quantity
              ORDER BY balance ASC, p.name ASC, p.id ASC
              LIMIT ' . self::limit($limit),
            $this->scope(),
        );

        return $this->withNextExpiry($rows);
    }

    public function expiring(DateTimeImmutable $today, int $days, int $limit): array
    {
        $from = $today->setTime(0, 0);
        $to = $from->modify('+' . max(0, $days) . ' days');

        $rows = $this->fetchAll(
            'SELECT /*+ MAX_EXECUTION_TIME(5000) */ p.id, p.name, p.minimum_stock_quantity, b.lot, b.expiry_date,
                    (SELECT COALESCE(SUM(t.quantity), 0)
                       FROM stock_batch t
                      WHERE t.tenant_id = b.tenant_id AND t.system_unit_id = b.system_unit_id
                        AND t.product_id = b.product_id) AS balance
               FROM stock_batch b
               JOIN product p ON p.id = b.product_id AND p.tenant_id = b.tenant_id
              WHERE b.tenant_id = :tenant_id AND b.system_unit_id = :unit_id AND p.active = 1
                AND b.quantity > 0 AND b.expiry_date BETWEEN :expiry_from AND :expiry_to
              ORDER BY b.expiry_date ASC, p.name ASC, b.id ASC
              LIMIT ' . self::limit($limit),
            [...$this->scope(), ':expiry_from' => $from->format('Y-m-d'), ':expiry_to' => $to->format('Y-m-d')],
        );

        return array_map(
            static fn (array $row): array => self::shape($row, self::date($row['expiry_date']), self::nullableString($row['lot'])),
            $rows,
        );
    }

    public function search(string $term, int $limit): array
    {
        $term = trim($term);
        if ($term === '') {
            return [];
        }

        $like = '%' . addcslashes($term, '\\%_') . '%';
        $rows = $this->fetchAll(
            'SELECT /*+ MAX_EXECUTION_TIME(5000) */ p.id, p.name, p.minimum_stock_quantity,
                    COALESCE(SUM(b.quantity), 0) AS balance
               FROM product p
               LEFT JOIN stock_batch b
                 ON b.product_id = p.id AND b.tenant_id = p.tenant_id AND b.system_unit_id = :unit_id
              WHERE p.tenant_id = :tenant_id AND p.active = 1
                AND (p.name LIKE :search_name OR p.code LIKE :search_code)
              GROUP BY p.id, p.name, p.minimum_stock_quantity
              ORDER BY p.name ASC, p.id ASC
              LIMIT ' . self::limit($limit),
            [...$this->scope(), ':search_name' => $like, ':search_code' => $like],
        );

        return $this->withNextExpiry($rows);
    }

    /**
     * Adds the earliest-expiring batch with stock of the unit to each product row.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array{product_id: int, name: string, balance: string, minimum: ?string, next_expiry: ?string, lot: ?string}>
     */
    private function withNextExpiry(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $parameters = $this->scope();
        $placeholders = [];
        foreach (array_values($rows) as $index => $row) {
            $placeholders[] = ':product_' . $index;
            $parameters[':product_' . $index] = (int) $row['id'];
        }

        $batches = $this->fetchAll(
            'SELECT /*+ MAX_EXECUTION_TIME(5000) */ b.product_id, b.lot, b.expiry_date
               FROM stock_batch b
              WHERE b.tenant_id = :tenant_id AND b.system_unit_id = :unit_id
                AND b.quantity > 0 AND b.expiry_date IS NOT NULL
                AND b.product_id IN (' . implode(', ', $placeholders) . ')
              ORDER BY b.expiry_date ASC, b.id ASC',
            $parameters,
        );

        $next = [];
        foreach ($batches as $batch) {
            $next[(int) $batch['product_id']] ??= $batch;
        }

        return array_map(static function (array $row) use ($next): array {
            $batch = $next[(int) $row['id']] ?? null;

            return self::shape(
                $row,
                $batch === null ? null : self::date($batch['expiry_date']),
                $batch === null ? null : self::nullableString($batch['lot']),
            );
        }, $rows);
    }

    /**
     * @param array<string, mixed> $row
     * @return array{product_id: int, name: string, balance: string, minimum: ?string, next_expiry: ?string, lot: ?string}
     */
    private static function shape(array $row, ?string $nextExpiry, ?string $lot): array
    {
        $minimum = (int) $row['minimum_stock_quantity'];

        return [
            'product_id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'balance' => (string) (int) $row['balance'],
            'minimum' => $minimum > 0 ? (string) $minimum : null,
            'next_expiry' => $nextExpiry,
            'lot' => $lot,
        ];
    }

    /** @return array<string, int> */
    private function scope(): array
    {
        return [':tenant_id' => $this->context->tenantId(), ':unit_id' => $this->context->requireUnitId()];
    }

    private static function limit(int $limit): int
    {
        return max(1, $limit);
    }

    private static function date(mixed $value): ?string
    {
        return $value === null ? null : substr((string) $value, 0, 10);
    }

    private static function nullableString(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }

    /**
     * @param array<string, int|string> $parameters
     * @return list<array<string, mixed>>
     */
    private function fetchAll(string $sql, array $parameters): array
    {
        $statement = $this->connection->prepare($sql);
        $statement->execute($parameters);

        return array_values($statement->fetchAll(PDO::FETCH_ASSOC));
    }
}
