<?php

declare(strict_types=1);

namespace CentralVet\Application;

use CentralVet\Persistence\FinancialOverviewReader;
use DateTimeImmutable;

/**
 * Indicators for the FinancialOverview screen (Phase 10, T-05): period
 * totals against the previous period, daily series, revenue by category,
 * recent entries and open cash balance of a system unit. Read-only; tenant
 * scope comes from the reader's TenantContext.
 *
 * Periods are whole days: $from and $to are inclusive dates (time of day is
 * ignored). The previous period has the same number of days and ends the
 * day before $from.
 */
final class FinancialOverviewService
{
    public function __construct(private readonly FinancialOverviewReader $reader)
    {
    }

    /**
     * @return array{revenue_cents: int, expense_cents: int, result_cents: int, prev_revenue_cents: int, prev_expense_cents: int, prev_result_cents: int}
     */
    public function totals(int $systemUnitId, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        [$start, $end] = self::bounds($from, $to);
        $days = self::dayCount($start, $end);
        $prevStart = $start->modify("-{$days} days");

        $current = $this->reader->totalsByType($systemUnitId, self::sql($start), self::sql($end));
        $previous = $this->reader->totalsByType($systemUnitId, self::sql($prevStart), self::sql($start));

        return [
            'revenue_cents' => $current['income'],
            'expense_cents' => $current['expense'],
            'result_cents' => $current['income'] - $current['expense'],
            'prev_revenue_cents' => $previous['income'],
            'prev_expense_cents' => $previous['expense'],
            'prev_result_cents' => $previous['income'] - $previous['expense'],
        ];
    }

    /** @return list<array{date: string, revenue_cents: int, expense_cents: int}> */
    public function dailySeries(int $systemUnitId, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        [$start, $end] = self::bounds($from, $to);

        $series = [];

        for ($day = $start; $day < $end; $day = $day->modify('+1 day')) {
            $key = $day->format('Y-m-d');
            $series[$key] = ['date' => $key, 'revenue_cents' => 0, 'expense_cents' => 0];
        }

        foreach ($this->reader->dailyTotals($systemUnitId, self::sql($start), self::sql($end)) as $row) {
            if (!isset($series[$row['date']])) {
                continue;
            }

            $field = $row['entry_type'] === 'expense' ? 'expense_cents' : 'revenue_cents';
            $series[$row['date']][$field] += $row['total'];
        }

        return array_values($series);
    }

    /** @return list<array{category: string, amount_cents: int, share: float}> */
    public function revenueByCategory(int $systemUnitId, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        [$start, $end] = self::bounds($from, $to);
        $rows = $this->reader->incomeByCategory($systemUnitId, self::sql($start), self::sql($end));
        $total = array_sum(array_column($rows, 'amount_cents'));

        if ($total <= 0) {
            return [];
        }

        $result = [];
        $accumulated = 0.0;
        $last = count($rows) - 1;

        foreach ($rows as $index => $row) {
            // The last share absorbs rounding so the list sums exactly to 1.0.
            $share = $index === $last ? 1.0 - $accumulated : $row['amount_cents'] / $total;
            $accumulated += $share;
            $result[] = ['category' => $row['category'], 'amount_cents' => $row['amount_cents'], 'share' => $share];
        }

        return $result;
    }

    /**
     * Newest entries of the unit. With $from and $to (rodada 2, T-20) only
     * entries inside the period count, with the same inclusive day bounds
     * as totals(); without them (both null) the whole history is read. A
     * single bound limits only that side.
     *
     * @return list<array{id: int, occurred_at: string, entry_type: string, category: string, payment_method: ?string, reference: ?string, amount_cents: int}>
     */
    public function recentEntries(int $systemUnitId, int $limit = 5, ?DateTimeImmutable $from = null, ?DateTimeImmutable $to = null): array
    {
        if ($from !== null && $to !== null) {
            [$start, $end] = self::bounds($from, $to);

            return $this->reader->recentEntries($systemUnitId, $limit, self::sql($start), self::sql($end));
        }

        return $this->reader->recentEntries(
            $systemUnitId,
            $limit,
            $from !== null ? self::sql($from->setTime(0, 0)) : null,
            $to !== null ? self::sql($to->setTime(0, 0)->modify('+1 day')) : null,
        );
    }

    public function openCashBalanceCents(int $systemUnitId): ?int
    {
        return $this->reader->openCashBalance($systemUnitId);
    }

    /** @return array{0: DateTimeImmutable, 1: DateTimeImmutable} [start of $from, start of the day after $to) */
    private static function bounds(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $start = $from->setTime(0, 0);
        $end = $to->setTime(0, 0)->modify('+1 day');

        if ($end <= $start) {
            $end = $start->modify('+1 day');
        }

        return [$start, $end];
    }

    private static function dayCount(DateTimeImmutable $start, DateTimeImmutable $end): int
    {
        return max(1, (int) $start->diff($end)->days);
    }

    private static function sql(DateTimeImmutable $moment): string
    {
        return $moment->format('Y-m-d H:i:s');
    }
}
