<?php

declare(strict_types=1);

namespace CentralVet\Tests\Support;

use CentralVet\Domain\Contract\CopilotAgendaQueryInterface;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * In-memory double for CopilotAgendaQueryInterface (T-19).
 *
 * seed('appointments' | 'busy' | 'professionals' | 'schedulableProfessionals',
 * $rows) stores the list the method returns; schedulableProfessionals() falls
 * back to the seeded professionals when it was not seeded or seeded empty
 * (same fallback as the real query). appointments() is cut to $limit; busy() keeps only the
 * rows of $professionalId when one is given; professionalExists() answers
 * from the seeded professionals and, with $includeInactive, also from the
 * rows seeded as 'inactive_professionals' (members with active = 'N'). Nothing is filtered by date or unit here:
 * the caller decides what to seed. calls() records every call, in order.
 */
final class FakeCopilotAgendaQuery implements CopilotAgendaQueryInterface
{
    private const METHODS = ['appointments', 'busy', 'professionals', 'schedulableProfessionals', 'inactive_professionals'];

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

    public function appointments(DateTimeImmutable $from, DateTimeImmutable $to, ?int $professionalId, int $limit): array
    {
        $this->record(__FUNCTION__, [$from->format('Y-m-d H:i:s'), $to->format('Y-m-d H:i:s'), $professionalId, $limit]);

        return array_slice($this->seeded['appointments'] ?? [], 0, max(1, $limit));
    }

    public function busy(DateTimeImmutable $day, ?int $professionalId): array
    {
        $this->record(__FUNCTION__, [$day->format('Y-m-d'), $professionalId]);

        return array_values(array_filter(
            $this->seeded['busy'] ?? [],
            static fn (array $row): bool => $professionalId === null || (int) $row['professional_id'] === $professionalId,
        ));
    }

    public function professionals(): array
    {
        $this->record(__FUNCTION__, []);

        return $this->seeded['professionals'] ?? [];
    }

    public function schedulableProfessionals(DateTimeImmutable $day): array
    {
        $this->record(__FUNCTION__, [$day->format('Y-m-d')]);

        $rows = $this->seeded['schedulableProfessionals'] ?? [];

        return $rows !== [] ? $rows : ($this->seeded['professionals'] ?? []);
    }

    public function professionalExists(int $id, bool $includeInactive = false): bool
    {
        $this->record(__FUNCTION__, [$id, $includeInactive]);

        $rows = $this->seeded['professionals'] ?? [];
        if ($includeInactive) {
            $rows = [...$rows, ...($this->seeded['inactive_professionals'] ?? [])];
        }

        foreach ($rows as $row) {
            if ((int) $row['id'] === $id) {
                return true;
            }
        }

        return false;
    }

    /** @param list<mixed> $args */
    private function record(string $method, array $args): void
    {
        $this->calls[] = ['method' => $method, 'args' => $args];
    }
}
