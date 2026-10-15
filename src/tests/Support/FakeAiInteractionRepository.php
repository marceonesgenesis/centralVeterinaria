<?php

declare(strict_types=1);

namespace CentralVet\Tests\Support;

use CentralVet\Domain\AiInteraction;
use CentralVet\Domain\Contract\AiInteractionRepositoryInterface;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * In-memory double for AiInteractionRepositoryInterface (T-05). Stores rows
 * keyed like `ai_interaction` and returns fresh AiInteraction::reconstitute()
 * copies, like the PDO repository. Ids are sequential across tenants; reads
 * only see the tenant given to the constructor. saveDecision() mirrors the
 * conditional UPDATE: false when the stored row is gone, foreign or no
 * longer `pending_review`. monthlyTokenUsage() = sum of the tenant's rows
 * created since the month start + setMonthlyTokenUsage().
 */
final class FakeAiInteractionRepository implements AiInteractionRepositoryInterface
{
    private const DECIDED = [AiInteraction::STATUS_ACCEPTED, AiInteraction::STATUS_EDITED];

    /** @var array<int, array<string, mixed>> */
    private array $rows = [];
    private int $nextId = 1;
    private int $extraMonthlyTokens = 0;

    public function __construct(private readonly int $tenantId)
    {
    }

    /** Stores the interaction as it is (any tenant); one without id gets the next id. */
    public function seed(AiInteraction $interaction): void
    {
        if ($interaction->id() === null) {
            $interaction->assignId($this->nextId++);
        } else {
            $this->nextId = max($this->nextId, (int) $interaction->id() + 1);
        }

        $now = self::date(new DateTimeImmutable());
        $this->rows[(int) $interaction->id()] = $interaction->toRow() + [
            'created_at' => self::date($interaction->createdAt()) ?? $now,
            'updated_at' => $now,
        ];
    }

    public function insert(AiInteraction $interaction): AiInteraction
    {
        if ($interaction->tenantId() !== $this->tenantId) {
            throw new InvalidArgumentException('AI interaction belongs to another tenant');
        }

        if ($interaction->id() !== null) {
            throw new InvalidArgumentException('AI interaction already has an id');
        }

        $this->seed($interaction);

        return $interaction;
    }

    public function saveDecision(AiInteraction $interaction): bool
    {
        $id = $interaction->id();
        $row = $id === null ? null : $this->ownRow($id);

        if ($row === null || $row['status'] !== AiInteraction::STATUS_PENDING_REVIEW) {
            return false;
        }

        $decision = $interaction->toRow();
        $this->rows[$id] = array_merge($row, [
            'status' => $decision['status'],
            'final_text' => $decision['final_text'],
            'decided_by_system_user_id' => $decision['decided_by_system_user_id'],
            'decided_at' => $decision['decided_at'],
            'updated_at' => self::date(new DateTimeImmutable()),
        ]);

        return true;
    }

    public function find(int $id): ?AiInteraction
    {
        $row = $this->ownRow($id);

        return $row === null ? null : AiInteraction::reconstitute($row);
    }

    public function monthlyTokenUsage(DateTimeImmutable $monthStart): int
    {
        $total = $this->extraMonthlyTokens;

        foreach ($this->monthRows($monthStart) as $row) {
            $total += self::tokens($row);
        }

        return $total;
    }

    public function monthlySummary(DateTimeImmutable $monthStart): array
    {
        $summary = [];

        foreach ($this->monthRows($monthStart) as $row) {
            $purpose = (string) $row['purpose'];
            $summary[$purpose] ??= ['purpose' => $purpose, 'requests' => 0, 'tokens' => 0, 'cost_micro_usd' => 0];
            $summary[$purpose]['requests']++;
            $summary[$purpose]['tokens'] += self::tokens($row);
            $summary[$purpose]['cost_micro_usd'] += (int) ($row['cost_micro_usd'] ?? 0);
        }

        ksort($summary);

        return array_values($summary);
    }

    public function latestDecidedFor(string $purpose, int $subjectId): ?AiInteraction
    {
        $rows = array_filter(
            $this->ownRows(),
            static fn (array $row): bool => $row['purpose'] === $purpose
                && $row['subject_id'] === $subjectId
                && in_array($row['status'], self::DECIDED, true),
        );
        usort($rows, static fn (array $a, array $b): int => [$b['decided_at'], $b['id']] <=> [$a['decided_at'], $a['id']]);

        return $rows === [] ? null : AiInteraction::reconstitute($rows[0]);
    }

    public function listRecent(int $limit): array
    {
        $rows = $this->ownRows();
        usort($rows, static fn (array $a, array $b): int => [$b['created_at'], $b['id']] <=> [$a['created_at'], $a['id']]);

        return array_map(
            static fn (array $row): AiInteraction => AiInteraction::reconstitute($row),
            array_slice($rows, 0, max(0, $limit)),
        );
    }

    /**
     * Every stored interaction (all tenants), as fresh copies ordered by id.
     *
     * @return list<AiInteraction>
     */
    public function all(): array
    {
        ksort($this->rows);

        return array_values(array_map(static fn (array $row): AiInteraction => AiInteraction::reconstitute($row), $this->rows));
    }

    /** Tokens already used this month by interactions the fake does not hold. */
    public function setMonthlyTokenUsage(int $tokens): void
    {
        $this->extraMonthlyTokens = $tokens;
    }

    /** @return list<array<string, mixed>> */
    private function monthRows(DateTimeImmutable $monthStart): array
    {
        $since = self::date($monthStart);

        return array_values(array_filter($this->ownRows(), static fn (array $row): bool => $row['created_at'] >= $since));
    }

    /** @param array<string, mixed> $row */
    private static function tokens(array $row): int
    {
        return (int) $row['input_tokens'] + (int) $row['output_tokens'] + (int) $row['cache_read_tokens'] + (int) $row['cache_write_tokens'];
    }

    /** @return array<string, mixed>|null */
    private function ownRow(int $id): ?array
    {
        $row = $this->rows[$id] ?? null;

        return $row !== null && $row['tenant_id'] === $this->tenantId ? $row : null;
    }

    /** @return list<array<string, mixed>> */
    private function ownRows(): array
    {
        ksort($this->rows);

        return array_values(array_filter($this->rows, fn (array $row): bool => $row['tenant_id'] === $this->tenantId));
    }

    private static function date(?DateTimeImmutable $date): ?string
    {
        return $date?->format('Y-m-d H:i:s.u');
    }
}
