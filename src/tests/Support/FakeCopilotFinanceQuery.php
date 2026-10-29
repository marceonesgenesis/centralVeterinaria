<?php

declare(strict_types=1);

namespace CentralVet\Tests\Support;

use CentralVet\Domain\Contract\CopilotFinanceQueryInterface;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * In-memory double for CopilotFinanceQueryInterface (T-21).
 *
 * seed('openReceivables', $rows) stores the list openReceivables() returns
 * (cut to $limit, whatever the situation: the caller seeds what it wants);
 * seed('indicators', $row) stores the row indicators() returns (default:
 * every counter 0). calls() records every call with its arguments, in order
 * (dates as 'Y-m-d H:i:s').
 */
final class FakeCopilotFinanceQuery implements CopilotFinanceQueryInterface
{
    private const METHODS = ['openReceivables', 'indicators'];

    /** @var list<array<string, mixed>> */
    private array $receivables = [];

    /** @var array{encounters: int, revenue_cents: int, new_patients: int, returning_encounters: int} */
    private array $indicators = ['encounters' => 0, 'revenue_cents' => 0, 'new_patients' => 0, 'returning_encounters' => 0];

    /** @var list<array{method: string, args: list<mixed>}> */
    private array $calls = [];

    /** @param array<int|string, mixed> $rows */
    public function seed(string $method, array $rows): void
    {
        if (!in_array($method, self::METHODS, true)) {
            throw new InvalidArgumentException("Unknown method to seed: {$method}");
        }

        if ($method === 'openReceivables') {
            $this->receivables = array_values($rows);

            return;
        }

        $this->indicators = [...$this->indicators, ...$rows];
    }

    /** @return list<array{method: string, args: list<mixed>}> */
    public function calls(): array
    {
        return $this->calls;
    }

    public function openReceivables(string $situacao, DateTimeImmutable $today, int $limit): array
    {
        if (!in_array($situacao, [self::OVERDUE, self::UPCOMING], true)) {
            throw new InvalidArgumentException('Unknown receivable situation');
        }

        $this->calls[] = ['method' => __FUNCTION__, 'args' => [$situacao, $today->format('Y-m-d H:i:s'), $limit]];

        return array_slice($this->receivables, 0, max(1, $limit));
    }

    public function indicators(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $this->calls[] = ['method' => __FUNCTION__, 'args' => [$from->format('Y-m-d H:i:s'), $to->format('Y-m-d H:i:s')]];

        return $this->indicators;
    }
}
