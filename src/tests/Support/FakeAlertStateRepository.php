<?php

declare(strict_types=1);

namespace CentralVet\Tests\Support;

use CentralVet\Operations\Contract\AlertStateRepositoryInterface;
use DateTimeImmutable;

/** In-memory AlertStateRepositoryInterface. */
final class FakeAlertStateRepository implements AlertStateRepositoryInterface
{
    /** @var array<string, array{at: DateTimeImmutable, subject: string, count: int}> */
    private array $state = [];

    public function lastSentAt(string $alertKey): ?DateTimeImmutable
    {
        return $this->state[$alertKey]['at'] ?? null;
    }

    public function markSent(string $alertKey, string $subject, DateTimeImmutable $at): void
    {
        $this->state[$alertKey] = [
            'at' => $at,
            'subject' => $subject,
            'count' => ($this->state[$alertKey]['count'] ?? 0) + 1,
        ];
    }

    public function sentCount(string $alertKey): int
    {
        return $this->state[$alertKey]['count'] ?? 0;
    }
}
