<?php

declare(strict_types=1);

namespace CentralVet\Persistence;

use CentralVet\Domain\Contract\CopilotFinanceQueryInterface;
use CentralVet\Tenancy\TenantContext;
use DateTimeImmutable;
use InvalidArgumentException;
use PDO;

/**
 * Read-only financial reads behind the management copilot tools (Fase
 * 8B.2), scoped by tenant AND by the active unit
 * (TenantContext::requireUnitId(), never caller input). Receivables reach
 * the unit through encounter_account.system_unit_id (same join as
 * PendingItemQuery::openReceivables()). Revenue repeats
 * FinancialOverviewReader::totalsByType() (income, half-open period) so the
 * statement can carry the 5 s execution-time optimizer hint, like every
 * other top-level statement here.
 */
final class CopilotFinanceQuery implements CopilotFinanceQueryInterface
{
    private const DUE_AFTER_DAYS = 7;

    public function __construct(
        private readonly TenantContext $context,
        private readonly PDO $connection,
    ) {
    }

    public function openReceivables(string $situacao, DateTimeImmutable $today, int $limit): array
    {
        $comparison = match ($situacao) {
            self::OVERDUE => '<',
            self::UPCOMING => '>=',
            default => throw new InvalidArgumentException('Unknown receivable situation'),
        };

        $query = $this->tenantQuery('r')->andEquals('system_unit_id', $this->unitId(), 'ea');
        $due = 'DATE_ADD(DATE(r.created_at), INTERVAL ' . self::DUE_AFTER_DAYS . ' DAY)';

        $rows = $this->fetchAll(
            "SELECT /*+ MAX_EXECUTION_TIME(5000) */ r.id, t.full_name AS tutor_full_name,
                    r.total_cents - r.paid_cents AS open_cents, {$due} AS due_date
               FROM receivable r
               JOIN encounter_account ea ON ea.id = r.encounter_account_id AND ea.tenant_id = r.tenant_id
               JOIN tutor t ON t.id = r.tutor_id AND t.tenant_id = r.tenant_id
              WHERE {$query->whereSql()}
                AND r.status IN ('open', 'partially_paid')
                AND {$due} {$comparison} :today
              ORDER BY r.created_at ASC, r.id ASC
              LIMIT " . max(1, $limit),
            [...$query->parameters(), ':today' => $today->format('Y-m-d')],
        );

        return array_map(static fn (array $row): array => [
            'receivable_id' => (int) $row['id'],
            'tutor_full_name' => (string) $row['tutor_full_name'],
            'open_cents' => (int) $row['open_cents'],
            'due_date' => substr((string) $row['due_date'], 0, 10),
        ], $rows);
    }

    public function indicators(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $period = [':period_from' => $from->format('Y-m-d H:i:s'), ':period_to' => $to->format('Y-m-d H:i:s')];
        $encounters = $this->encounterQuery('e');

        $counts = $this->fetchAll(
            "SELECT /*+ MAX_EXECUTION_TIME(5000) */ COUNT(*) AS encounters,
                    COALESCE(SUM(EXISTS (
                        SELECT 1 FROM encounter prev
                         WHERE prev.tenant_id = e.tenant_id AND prev.system_unit_id = e.system_unit_id
                           AND prev.patient_id = e.patient_id
                           AND (prev.started_at < e.started_at OR (prev.started_at = e.started_at AND prev.id < e.id))
                    )), 0) AS returning_encounters
               FROM encounter e
              WHERE {$encounters->whereSql()}
                AND e.started_at >= :period_from AND e.started_at < :period_to",
            [...$encounters->parameters(), ...$period],
        )[0];

        $newPatients = $this->fetchAll(
            "SELECT /*+ MAX_EXECUTION_TIME(5000) */ COUNT(DISTINCT e.patient_id) AS total
               FROM encounter e
              WHERE {$encounters->whereSql()}
                AND e.started_at >= :period_from AND e.started_at < :period_to
                AND NOT EXISTS (
                    SELECT 1 FROM encounter prev
                     WHERE prev.tenant_id = e.tenant_id AND prev.system_unit_id = e.system_unit_id
                       AND prev.patient_id = e.patient_id AND prev.started_at < :first_before)",
            [...$encounters->parameters(), ...$period, ':first_before' => $period[':period_from']],
        )[0];

        $income = $this->tenantQuery('f')
            ->andEquals('system_unit_id', $this->unitId(), 'f')
            ->andEquals('entry_type', 'income', 'f');

        $revenue = $this->fetchAll(
            "SELECT /*+ MAX_EXECUTION_TIME(5000) */ COALESCE(SUM(f.amount_cents), 0) AS total
               FROM financial_entry f
              WHERE {$income->whereSql()}
                AND f.occurred_at >= :period_from AND f.occurred_at < :period_to",
            [...$income->parameters(), ...$period],
        )[0];

        return [
            'encounters' => (int) $counts['encounters'],
            'revenue_cents' => (int) $revenue['total'],
            'new_patients' => (int) $newPatients['total'],
            'returning_encounters' => (int) $counts['returning_encounters'],
        ];
    }

    private function encounterQuery(string $alias): TenantQuery
    {
        return $this->tenantQuery($alias)->andEquals('system_unit_id', $this->unitId(), $alias);
    }

    private function tenantQuery(string $alias): TenantQuery
    {
        return TenantQuery::forTenant($this->context->tenantId(), $alias);
    }

    private function unitId(): int
    {
        return $this->context->requireUnitId();
    }

    /**
     * @param array<string, int|string|float|bool|null> $parameters
     * @return list<array<string, mixed>>
     */
    private function fetchAll(string $sql, array $parameters): array
    {
        $statement = $this->connection->prepare($sql);
        $statement->execute($parameters);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }
}
