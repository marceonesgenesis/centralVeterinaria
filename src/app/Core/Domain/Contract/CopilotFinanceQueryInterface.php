<?php

declare(strict_types=1);

namespace CentralVet\Domain\Contract;

use DateTimeImmutable;

/**
 * Read-only financial data the management copilot tools may read (Fase
 * 8B.2), scoped to the tenant AND the active unit of the session. Tutor
 * contact, document and address columns are never returned; only
 * `tutor.full_name`.
 */
interface CopilotFinanceQueryInterface
{
    public const OVERDUE = 'vencidos';
    public const UPCOMING = 'a_vencer';

    /**
     * Receivables with status open or partially_paid whose encounter account
     * belongs to the active unit. There is no due-date column: due date =
     * created_at + 7 days (same rule as the pending items screen).
     * 'vencidos' = due before $today; 'a_vencer' = due on $today or later.
     * Oldest due date first.
     *
     * @return list<array{receivable_id: int, tutor_full_name: string, open_cents: int, due_date: string}>
     * @throws \InvalidArgumentException when $situacao is not OVERDUE or UPCOMING
     */
    public function openReceivables(string $situacao, DateTimeImmutable $today, int $limit): array;

    /**
     * Indicators of the active unit in the half-open period [$from, $to):
     * encounters started in the period; income of financial_entry (same as
     * FinancialOverviewReader::totalsByType()['income']); patients whose first
     * encounter in the unit falls in the period; encounters of the period of
     * patients with an earlier encounter in the unit.
     *
     * @return array{encounters: int, revenue_cents: int, new_patients: int, returning_encounters: int}
     */
    public function indicators(DateTimeImmutable $from, DateTimeImmutable $to): array;
}
