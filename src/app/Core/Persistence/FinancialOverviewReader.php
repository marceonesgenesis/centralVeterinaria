<?php

declare(strict_types=1);

namespace CentralVet\Persistence;

use CentralVet\Tenancy\TenantContext;
use LogicException;
use PDO;

/**
 * Read-only queries behind the FinancialOverview screen (Phase 10, T-05):
 * aggregates of `financial_entry` per system unit and the balance of the
 * unit's open `cash_session`. Every statement starts from
 * TenantQuery::forTenant() (via tenantQuery(), ADR 0002) and the date range
 * is appended after whereSql(), as in
 * FinancialEntryRepository::listBySystemUnitAndPeriod(). Periods are
 * half-open: occurred_at >= $from AND occurred_at < $to.
 */
final class FinancialOverviewReader extends AbstractTenantRepository
{
    public function __construct(TenantContext $context, private readonly PDO $connection)
    {
        parent::__construct($context);
    }

    /** Read model only: there is no aggregate to load (RepositoryInterface contract). */
    public function findById(int|string $id): ?object
    {
        throw new LogicException('FinancialOverviewReader is read-only and has no aggregate to load');
    }

    /** Read model only: writes go through FinancialEntryService/CashSessionService. */
    public function save(object $entity): object
    {
        throw new LogicException('FinancialOverviewReader is read-only');
    }

    /** Read model only: writes go through FinancialEntryService/CashSessionService. */
    public function remove(object $entity): void
    {
        throw new LogicException('FinancialOverviewReader is read-only');
    }

    /** @return array{income: int, expense: int} */
    public function totalsByType(int $systemUnitId, string $from, string $to): array
    {
        $query = $this->tenantQuery()->andEquals('system_unit_id', $systemUnitId);

        $statement = $this->connection->prepare(
            "SELECT entry_type, COALESCE(SUM(amount_cents), 0) AS total FROM financial_entry WHERE {$query->whereSql()} "
            . 'AND occurred_at >= :period_from AND occurred_at < :period_to GROUP BY entry_type'
        );
        $statement->execute([...$query->parameters(), ':period_from' => $from, ':period_to' => $to]);

        $totals = ['income' => 0, 'expense' => 0];

        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $totals[(string) $row['entry_type']] = (int) $row['total'];
        }

        return $totals;
    }

    /** @return list<array{date: string, entry_type: string, total: int}> */
    public function dailyTotals(int $systemUnitId, string $from, string $to): array
    {
        $query = $this->tenantQuery()->andEquals('system_unit_id', $systemUnitId);

        $statement = $this->connection->prepare(
            "SELECT DATE_FORMAT(occurred_at, '%Y-%m-%d') AS day, entry_type, SUM(amount_cents) AS total "
            . "FROM financial_entry WHERE {$query->whereSql()} "
            . 'AND occurred_at >= :period_from AND occurred_at < :period_to '
            . 'GROUP BY day, entry_type ORDER BY day ASC'
        );
        $statement->execute([...$query->parameters(), ':period_from' => $from, ':period_to' => $to]);

        $rows = [];

        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rows[] = ['date' => (string) $row['day'], 'entry_type' => (string) $row['entry_type'], 'total' => (int) $row['total']];
        }

        return $rows;
    }

    /** @return list<array{category: string, amount_cents: int}> ordered by amount descending */
    public function incomeByCategory(int $systemUnitId, string $from, string $to): array
    {
        $query = $this->tenantQuery()
            ->andEquals('system_unit_id', $systemUnitId)
            ->andEquals('entry_type', 'income');

        $statement = $this->connection->prepare(
            "SELECT category, SUM(amount_cents) AS total FROM financial_entry WHERE {$query->whereSql()} "
            . 'AND occurred_at >= :period_from AND occurred_at < :period_to '
            . 'GROUP BY category ORDER BY total DESC, category ASC'
        );
        $statement->execute([...$query->parameters(), ':period_from' => $from, ':period_to' => $to]);

        $rows = [];

        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rows[] = ['category' => (string) $row['category'], 'amount_cents' => (int) $row['total']];
        }

        return $rows;
    }

    /**
     * @return list<array{id: int, occurred_at: string, entry_type: string, category: string, reference: ?string, amount_cents: int}>
     */
    public function recentEntries(int $systemUnitId, int $limit): array
    {
        $limit = max(1, $limit);
        $query = $this->tenantQuery()->andEquals('system_unit_id', $systemUnitId);

        $statement = $this->connection->prepare(
            "SELECT id, DATE_FORMAT(occurred_at, '%Y-%m-%d %H:%i:%s') AS occurred_at, entry_type, category, "
            . 'reference_type, reference_id, amount_cents '
            . "FROM financial_entry WHERE {$query->whereSql()} ORDER BY occurred_at DESC, id DESC LIMIT {$limit}"
        );
        $statement->execute($query->parameters());

        $rows = [];

        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $reference = null;

            if ($row['reference_type'] !== null) {
                $reference = (string) $row['reference_type']
                    . ($row['reference_id'] !== null ? ' #' . (int) $row['reference_id'] : '');
            }

            $rows[] = [
                'id' => (int) $row['id'],
                'occurred_at' => (string) $row['occurred_at'],
                'entry_type' => (string) $row['entry_type'],
                'category' => (string) $row['category'],
                'reference' => $reference,
                'amount_cents' => (int) $row['amount_cents'],
            ];
        }

        return $rows;
    }

    /**
     * Balance of the unit's open cash session: opening balance plus the
     * payments registered in it (payment.cash_session_id). The schema links
     * no outflow to a cash session, so there is nothing to subtract.
     * Null when the unit has no open session.
     */
    public function openCashBalance(int $systemUnitId): ?int
    {
        $query = $this->tenantQuery('cs')
            ->andEquals('system_unit_id', $systemUnitId, 'cs')
            ->andEquals('status', 'open', 'cs');

        $statement = $this->connection->prepare(
            'SELECT cs.opening_balance_cents, '
            . '(SELECT COALESCE(SUM(p.amount_cents), 0) FROM payment p '
            . ' WHERE p.cash_session_id = cs.id AND p.tenant_id = cs.tenant_id) AS paid '
            . "FROM cash_session cs WHERE {$query->whereSql()} ORDER BY cs.id DESC LIMIT 1"
        );
        $statement->execute($query->parameters());

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : (int) $row['opening_balance_cents'] + (int) $row['paid'];
    }
}
