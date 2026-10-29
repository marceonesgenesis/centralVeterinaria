<?php

declare(strict_types=1);

namespace CentralVet\Tests\Support;

use CentralVet\Domain\Contract\CopilotStockQueryInterface;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * In-memory double for CopilotStockQueryInterface (T-20).
 *
 * seed(<method>, $rows) stores the list that method returns (cut to
 * $limit). Nothing is filtered: the caller decides what to seed. calls()
 * records every call with its arguments, in order.
 */
final class FakeCopilotStockQuery implements CopilotStockQueryInterface
{
    private const METHODS = ['belowMinimum', 'expiring', 'search'];

    /** @var array<string, list<array<string, mixed>>> */
    private array $seeded = [];

    /** @var list<array{method: string, args: list<mixed>}> */
    private array $calls = [];

    /** @param list<array<string, mixed>> $rows */
    public function seed(string $method, array $rows): void
    {
        if (!in_array($method, self::METHODS, true)) {
            throw new InvalidArgumentException("Unknown method to seed: {$method}");
        }

        $this->seeded[$method] = array_values($rows);
    }

    /** @return list<array{method: string, args: list<mixed>}> */
    public function calls(): array
    {
        return $this->calls;
    }

    public function belowMinimum(int $limit): array
    {
        $this->calls[] = ['method' => __FUNCTION__, 'args' => [$limit]];

        return $this->list(__FUNCTION__, $limit);
    }

    public function expiring(DateTimeImmutable $today, int $days, int $limit): array
    {
        $this->calls[] = ['method' => __FUNCTION__, 'args' => [$today, $days, $limit]];

        return $this->list(__FUNCTION__, $limit);
    }

    public function search(string $term, int $limit): array
    {
        $this->calls[] = ['method' => __FUNCTION__, 'args' => [$term, $limit]];

        return $this->list(__FUNCTION__, $limit);
    }

    /** @return list<array<string, mixed>> */
    private function list(string $method, int $limit): array
    {
        return array_slice($this->seeded[$method] ?? [], 0, max(0, $limit));
    }
}
