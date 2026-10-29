<?php

declare(strict_types=1);

namespace CentralVet\Tests\Support;

use CentralVet\Domain\Contract\PendingItemQueryInterface;
use CentralVet\Domain\PendingItem;
use DateTimeImmutable;

/**
 * Double for PendingItemQueryInterface (T-06): returns the items given in the
 * constructor, at most `$limitPerType` per type, and records every call as
 * `['listForUnit', $systemUnitId, $now, $limitPerType]`.
 */
final class FakePendingItemQuery implements PendingItemQueryInterface
{
    /** @var list<array{0: string, 1: int, 2: DateTimeImmutable, 3: int}> */
    private array $calls = [];

    /** @param list<PendingItem> $items */
    public function __construct(private readonly array $items = [])
    {
    }

    public function listForUnit(int $systemUnitId, DateTimeImmutable $now, int $limitPerType): array
    {
        $this->calls[] = ['listForUnit', $systemUnitId, $now, $limitPerType];
        $perType = [];
        $result = [];

        foreach ($this->items as $item) {
            $perType[$item->type()] = ($perType[$item->type()] ?? 0) + 1;

            if ($perType[$item->type()] <= $limitPerType) {
                $result[] = $item;
            }
        }

        return $result;
    }

    /** @return list<array{0: string, 1: int, 2: DateTimeImmutable, 3: int}> */
    public function calls(): array
    {
        return $this->calls;
    }
}
