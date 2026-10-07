<?php

declare(strict_types=1);

namespace CentralVet\Persistence;

use CentralVet\Tenancy\TenantContext;
use LogicException;
use PDO;

/**
 * Read-only queries behind the "Estoque e Vendas" overview (Phase 10,
 * T-04): per-product stock totals and sale aggregates. Every statement
 * starts from TenantQuery::forTenant() (via tenantQuery(), ADR 0002);
 * joined tables are bound to the driving table's tenant_id, so a row of
 * another tenant can never be reached. Tenant scope never comes from
 * caller input.
 *
 * Stock of a product = SUM(stock_batch.quantity) across the tenant's
 * batches (all units); only active products are listed.
 */
final class StockSalesOverviewReader extends AbstractTenantRepository
{
    public function __construct(TenantContext $context, private readonly PDO $connection)
    {
        parent::__construct($context);
    }

    /** Read model only: there is no aggregate to load (RepositoryInterface contract). */
    public function findById(int|string $id): ?object
    {
        throw new LogicException('StockSalesOverviewReader is read-only and has no aggregate to load');
    }

    /** Read model only: writes go through ProductService/StockService/SaleService. */
    public function save(object $entity): object
    {
        throw new LogicException('StockSalesOverviewReader is read-only');
    }

    /** Read model only: writes go through ProductService/StockService/SaleService. */
    public function remove(object $entity): void
    {
        throw new LogicException('StockSalesOverviewReader is read-only');
    }

    /**
     * @return list<array{id: int, name: string, category: string, unit: string, stock_quantity: float, minimum_stock_quantity: float, code: ?string, sale_price_cents: ?int}>
     */
    public function productStocks(?string $search = null, ?string $category = null): array
    {
        $query = $this->tenantQuery('p')->andEquals('active', 1, 'p');

        if ($category !== null && $category !== '') {
            $query = $query->andEquals('category', $category, 'p');
        }

        $sql = 'SELECT p.id, p.name, p.code, p.category, p.unit_of_measure, p.minimum_stock_quantity, p.sale_price_cents, '
            . 'COALESCE(SUM(b.quantity), 0) AS stock_quantity '
            . 'FROM product p '
            . 'LEFT JOIN stock_batch b ON b.product_id = p.id AND b.tenant_id = p.tenant_id '
            . "WHERE {$query->whereSql()}";
        $parameters = $query->parameters();

        if ($search !== null && trim($search) !== '') {
            $sql .= ' AND p.name LIKE :search_name';
            $parameters[':search_name'] = '%' . addcslashes(trim($search), '%_\\') . '%';
        }

        $sql .= ' GROUP BY p.id, p.name, p.code, p.category, p.unit_of_measure, p.minimum_stock_quantity, p.sale_price_cents ORDER BY p.name ASC, p.id ASC';

        $statement = $this->connection->prepare($sql);
        $statement->execute($parameters);

        $rows = [];

        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rows[] = [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
                'category' => (string) ($row['category'] ?? ''),
                'unit' => (string) $row['unit_of_measure'],
                'stock_quantity' => (float) $row['stock_quantity'],
                'minimum_stock_quantity' => (float) $row['minimum_stock_quantity'],
                'code' => $row['code'] !== null ? (string) $row['code'] : null,
                'sale_price_cents' => $row['sale_price_cents'] !== null ? (int) $row['sale_price_cents'] : null,
            ];
        }

        return $rows;
    }

    /**
     * Completed sales in [$from, $to).
     *
     * @return array{total_cents: int, items: int}
     */
    public function salesTotals(string $from, string $to): array
    {
        $query = $this->tenantQuery('s')->andEquals('status', 'completed', 's');

        $statement = $this->connection->prepare(
            "SELECT COALESCE(SUM(s.total_amount_cents), 0) FROM sale s WHERE {$query->whereSql()} "
            . 'AND s.sold_at >= :period_from AND s.sold_at < :period_to'
        );
        $statement->execute([...$query->parameters(), ':period_from' => $from, ':period_to' => $to]);
        $total = (int) $statement->fetchColumn();

        $statement = $this->connection->prepare(
            'SELECT COALESCE(SUM(i.quantity), 0) FROM sale s '
            . 'JOIN sale_item i ON i.sale_id = s.id AND i.tenant_id = s.tenant_id '
            . "WHERE {$query->whereSql()} AND s.sold_at >= :period_from AND s.sold_at < :period_to"
        );
        $statement->execute([...$query->parameters(), ':period_from' => $from, ':period_to' => $to]);

        return ['total_cents' => $total, 'items' => (int) $statement->fetchColumn()];
    }

    /**
     * Most recent completed sales, newest first. A limit <= 0 returns [].
     *
     * @return list<array{id: int, sold_at: string, total_cents: int, items_label: string, patient_name: ?string}>
     */
    public function recentSales(int $limit): array
    {
        if ($limit <= 0) {
            return [];
        }

        $query = $this->tenantQuery('s')->andEquals('status', 'completed', 's');

        $statement = $this->connection->prepare(
            "SELECT s.id, DATE_FORMAT(s.sold_at, '%Y-%m-%d %H:%i:%s') AS sold_at, s.total_amount_cents, "
            . "GROUP_CONCAT(i.description_text ORDER BY i.id SEPARATOR ', ') AS items_label, pt.name AS patient_name "
            . 'FROM sale s '
            . 'LEFT JOIN sale_item i ON i.sale_id = s.id AND i.tenant_id = s.tenant_id '
            . 'LEFT JOIN patient pt ON pt.id = s.patient_id AND pt.tenant_id = s.tenant_id '
            . "WHERE {$query->whereSql()} "
            . 'GROUP BY s.id, s.sold_at, s.total_amount_cents, pt.name '
            . "ORDER BY s.sold_at DESC, s.id DESC LIMIT {$limit}"
        );
        $statement->execute($query->parameters());

        $rows = [];

        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rows[] = [
                'id' => (int) $row['id'],
                'sold_at' => (string) $row['sold_at'],
                'total_cents' => (int) $row['total_amount_cents'],
                'items_label' => (string) ($row['items_label'] ?? ''),
                'patient_name' => $row['patient_name'] !== null ? (string) $row['patient_name'] : null,
            ];
        }

        return $rows;
    }

    /** @return list<string> */
    public function categories(): array
    {
        $query = $this->tenantQuery()->andEquals('active', 1);

        $statement = $this->connection->prepare(
            "SELECT DISTINCT category FROM product WHERE {$query->whereSql()} "
            . "AND category IS NOT NULL AND category <> '' ORDER BY category ASC"
        );
        $statement->execute($query->parameters());

        return array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }
}
