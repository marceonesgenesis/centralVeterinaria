<?php

declare(strict_types=1);

namespace CentralVet\Tests\Support;

use CentralVet\Operations\Contract\HeartbeatRepositoryInterface;
use DateTimeImmutable;

/** In-memory HeartbeatRepositoryInterface; records() lists every call. */
final class FakeHeartbeatRepository implements HeartbeatRepositoryInterface
{
    /** @var list<array{job: string, at: DateTimeImmutable, detail: string}> */
    private array $records = [];

    /** @var array<string, DateTimeImmutable> */
    private array $last = [];

    public function record(string $job, DateTimeImmutable $at, string $detail): void
    {
        $this->records[] = ['job' => $job, 'at' => $at, 'detail' => $detail];
        $this->last[$job] = $at;
    }

    public function lastSuccess(string $job): ?DateTimeImmutable
    {
        return $this->last[$job] ?? null;
    }

    /** @return list<array{job: string, at: DateTimeImmutable, detail: string}> */
    public function records(): array
    {
        return $this->records;
    }
}
