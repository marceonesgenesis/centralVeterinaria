<?php

declare(strict_types=1);

namespace CentralVet\Persistence;

use CentralVet\Tenancy\TenantContext;
use LogicException;
use PDO;

/**
 * Read-only queries behind the sale list and the sale receipt (Etapa 2, T-10):
 * each sale with its receivable (receivable.sale_id, migration 0016), the money
 * actually received for it and its traceable cancellation. Received money is the
 * sum of the receivable's payments minus the sum of their payment_reversal rows;
 * a reversed payment no longer lists its payment method. Every statement starts
 * from TenantQuery::forTenant() (via tenantQuery(), ADR 0002) on `sale` and
 * every join repeats the tenant of the sale, as in FinancialOverviewReader.
 */
final class SaleFinanceReader extends AbstractTenantRepository
{
    /**
     * Net received cents of receivable `r`, correlated to the outer query; a
     * sale without receivable yields 0.
     */
    private const RECEIVED_CENTS_SQL = '(SELECT COALESCE(SUM(p.amount_cents - COALESCE(pr.amount_cents, 0)), 0) FROM payment p '
        . 'LEFT JOIN payment_reversal pr ON pr.payment_id = p.id AND pr.tenant_id = p.tenant_id '
        . 'WHERE p.receivable_id = r.id AND p.tenant_id = s.tenant_id)';

    public function __construct(TenantContext $context, private readonly PDO $connection)
    {
        parent::__construct($context);
    }

    /** Read model only: there is no aggregate to load (RepositoryInterface contract). */
    public function findById(int|string $id): ?object
    {
        throw new LogicException('SaleFinanceReader is read-only and has no aggregate to load');
    }

    /** Read model only: writes go through the sale checkout/cancellation services. */
    public function save(object $entity): object
    {
        throw new LogicException('SaleFinanceReader is read-only');
    }

    /** Read model only: writes go through the sale checkout/cancellation services. */
    public function remove(object $entity): void
    {
        throw new LogicException('SaleFinanceReader is read-only');
    }

    /**
     * Sales of the tenant (completed and cancelled), newest first.
     *
     * @return list<array{id: int, sold_at: string, tutor_name: string, total_cents: int, status: string, receivable_id: ?int, receivable_status: ?string, received_cents: int}>
     */
    public function listSales(int $limit, int $offset): array
    {
        if ($limit <= 0) {
            return [];
        }

        $offset = max(0, $offset);
        $query = $this->tenantQuery('s');

        $statement = $this->connection->prepare(
            "SELECT s.id, DATE_FORMAT(s.sold_at, '%Y-%m-%d %H:%i:%s') AS sold_at, t.full_name AS tutor_name, "
            . 's.total_amount_cents, s.status, r.id AS receivable_id, r.status AS receivable_status, '
            . self::RECEIVED_CENTS_SQL . ' AS received_cents '
            . 'FROM sale s '
            . 'INNER JOIN tutor t ON t.id = s.tutor_id AND t.tenant_id = s.tenant_id '
            . 'LEFT JOIN receivable r ON r.sale_id = s.id AND r.tenant_id = s.tenant_id '
            . "WHERE {$query->whereSql()} "
            . "ORDER BY s.sold_at DESC, s.id DESC LIMIT {$limit} OFFSET {$offset}"
        );
        $statement->execute($query->parameters());

        $rows = [];

        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rows[] = [
                'id' => (int) $row['id'],
                'sold_at' => (string) $row['sold_at'],
                'tutor_name' => (string) $row['tutor_name'],
                'total_cents' => (int) $row['total_amount_cents'],
                'status' => (string) $row['status'],
                'receivable_id' => $row['receivable_id'] === null ? null : (int) $row['receivable_id'],
                'receivable_status' => $row['receivable_status'] === null ? null : (string) $row['receivable_status'],
                'received_cents' => (int) $row['received_cents'],
            ];
        }

        return $rows;
    }

    public function countSales(): int
    {
        $query = $this->tenantQuery();

        $statement = $this->connection->prepare("SELECT COUNT(*) FROM sale WHERE {$query->whereSql()}");
        $statement->execute($query->parameters());

        return (int) $statement->fetchColumn();
    }

    /**
     * Financial summary printed on the receipt, from the stored rows only;
     * null when the sale does not exist or belongs to another tenant.
     *
     * @return array{sale_id: int, status: string, total_cents: int, receivable_status: ?string, received_cents: int, payment_methods: list<string>, cancelled_at: ?string, cancelled_by_name: ?string, cancellation_reason: ?string}|null
     */
    public function receiptSummary(int $saleId): ?array
    {
        $query = $this->tenantQuery('s')->andEquals('id', $saleId, 's');

        $statement = $this->connection->prepare(
            'SELECT s.id, s.status, s.total_amount_cents, r.id AS receivable_id, r.status AS receivable_status, '
            . self::RECEIVED_CENTS_SQL . ' AS received_cents, '
            . "DATE_FORMAT(s.cancelled_at, '%Y-%m-%d %H:%i:%s') AS cancelled_at, u.name AS cancelled_by_name, s.cancellation_reason "
            . 'FROM sale s '
            . 'LEFT JOIN receivable r ON r.sale_id = s.id AND r.tenant_id = s.tenant_id '
            . 'LEFT JOIN system_users u ON u.id = s.cancelled_by_system_user_id '
            . "WHERE {$query->whereSql()}"
        );
        $statement->execute($query->parameters());
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            return null;
        }

        $receivableId = $row['receivable_id'] === null ? null : (int) $row['receivable_id'];

        return [
            'sale_id' => (int) $row['id'],
            'status' => (string) $row['status'],
            'total_cents' => (int) $row['total_amount_cents'],
            'receivable_status' => $row['receivable_status'] === null ? null : (string) $row['receivable_status'],
            'received_cents' => (int) $row['received_cents'],
            'payment_methods' => $receivableId === null ? [] : $this->unreversedPaymentMethods($receivableId),
            'cancelled_at' => $row['cancelled_at'] === null ? null : (string) $row['cancelled_at'],
            'cancelled_by_name' => $row['cancelled_by_name'] === null ? null : (string) $row['cancelled_by_name'],
            'cancellation_reason' => $row['cancellation_reason'] === null ? null : (string) $row['cancellation_reason'],
        ];
    }

    /** @return list<string> distinct methods of the receivable's payments without reversal, sorted */
    private function unreversedPaymentMethods(int $receivableId): array
    {
        $query = $this->tenantQuery('p')->andEquals('receivable_id', $receivableId, 'p');

        $statement = $this->connection->prepare(
            'SELECT DISTINCT p.payment_method FROM payment p '
            . 'LEFT JOIN payment_reversal pr ON pr.payment_id = p.id AND pr.tenant_id = p.tenant_id '
            . "WHERE {$query->whereSql()} AND pr.id IS NULL ORDER BY p.payment_method ASC"
        );
        $statement->execute($query->parameters());

        return array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }
}
