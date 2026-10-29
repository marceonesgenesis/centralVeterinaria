<?php

declare(strict_types=1);

namespace CentralVet\Persistence;

use CentralVet\Domain\AiInteraction;
use CentralVet\Domain\AiPurpose;
use CentralVet\Domain\Contract\AiInteractionRepositoryInterface;
use CentralVet\Tenancy\TenantContext;
use DateTimeImmutable;
use InvalidArgumentException;
use PDO;

/**
 * PDO-backed persistence of `ai_interaction` (migration 0014). Every query
 * starts from TenantQuery::forTenant() with the tenant of the context, never
 * from input (ADR 0002); inserting a row of another tenant throws
 * TenantBoundaryViolation.
 *
 * Only an interaction with an outcome (recordSuggestion or recordFailure)
 * can be inserted: `ai_interaction_failed_ck` rejects a `pending_review`
 * row without suggestion. The decision is one conditional UPDATE
 * (`status = 'pending_review'`), so two concurrent decisions cannot both
 * win. Monthly sums read `ai_interaction_tenant_created_idx` over
 * `[monthStart, next month)`.
 *
 * Clinical texts (suggestion, final) are never put into exception messages.
 */
final class AiInteractionRepository implements AiInteractionRepositoryInterface
{
    private const DECIDED = [AiInteraction::STATUS_ACCEPTED, AiInteraction::STATUS_EDITED];

    public function __construct(private readonly TenantContext $context, private readonly PDO $connection)
    {
    }

    public function insert(AiInteraction $interaction): AiInteraction
    {
        if ($interaction->id() !== null) {
            throw new InvalidArgumentException('AI interaction already has an id');
        }

        $this->context->assertTenant($interaction->tenantId());

        $row = $interaction->toRow();
        unset($row['id']);
        $row['tenant_id'] = $this->context->tenantId();

        $columns = array_keys($row);
        $placeholders = array_map(static fn (string $column): string => ':' . $column, $columns);

        $statement = $this->connection->prepare(
            'INSERT INTO ai_interaction (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $placeholders) . ')',
        );
        $statement->execute(array_combine($placeholders, array_values($row)));

        $interaction->assignId((int) $this->connection->lastInsertId());

        return $interaction;
    }

    public function saveDecision(AiInteraction $interaction): bool
    {
        $id = $interaction->id();

        if ($id === null) {
            return false;
        }

        $row = $interaction->toRow();
        $query = TenantQuery::forTenant($this->context->tenantId())
            ->andEquals('id', $id)
            ->andEquals('status', AiInteraction::STATUS_PENDING_REVIEW);

        $statement = $this->connection->prepare(
            'UPDATE ai_interaction SET status = :new_status, final_text = :final_text, '
            . 'decided_by_system_user_id = :decided_by, decided_at = :decided_at '
            . "WHERE {$query->whereSql()}",
        );
        $statement->execute([
            ...$query->parameters(),
            ':new_status' => $row['status'],
            ':final_text' => $row['final_text'],
            ':decided_by' => $row['decided_by_system_user_id'],
            ':decided_at' => $row['decided_at'],
        ]);

        return $statement->rowCount() === 1;
    }

    public function find(int $id): ?AiInteraction
    {
        $query = TenantQuery::forTenant($this->context->tenantId())->andEquals('id', $id);

        $statement = $this->connection->prepare("SELECT * FROM ai_interaction WHERE {$query->whereSql()} LIMIT 1");
        $statement->execute($query->parameters());

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : AiInteraction::reconstitute($row);
    }

    public function monthlyTokenUsage(DateTimeImmutable $monthStart): int
    {
        $query = TenantQuery::forTenant($this->context->tenantId());

        $statement = $this->connection->prepare(
            'SELECT COALESCE(SUM(input_tokens + output_tokens + cache_read_tokens + cache_write_tokens), 0) '
            . 'FROM ai_interaction '
            . "WHERE {$query->whereSql()} AND created_at >= :month_start AND created_at < :next_month",
        );
        $statement->execute([...$query->parameters(), ...self::monthRange($monthStart)]);

        return (int) $statement->fetchColumn();
    }

    public function monthlySummary(DateTimeImmutable $monthStart): array
    {
        $query = TenantQuery::forTenant($this->context->tenantId());

        $statement = $this->connection->prepare(
            <<<SQL
            SELECT purpose,
                   COUNT(*) AS requests,
                   COALESCE(SUM(input_tokens + output_tokens + cache_read_tokens + cache_write_tokens), 0) AS tokens,
                   COALESCE(SUM(cost_micro_usd), 0) AS cost_micro_usd
            FROM ai_interaction
            WHERE {$query->whereSql()} AND created_at >= :month_start AND created_at < :next_month
            GROUP BY purpose
            ORDER BY purpose
            SQL
        );
        $statement->execute([...$query->parameters(), ...self::monthRange($monthStart)]);

        return array_map(
            static fn (array $row): array => [
                'purpose' => (string) $row['purpose'],
                'requests' => (int) $row['requests'],
                'tokens' => (int) $row['tokens'],
                'cost_micro_usd' => (int) $row['cost_micro_usd'],
            ],
            $statement->fetchAll(PDO::FETCH_ASSOC),
        );
    }

    public function latestDecidedFor(string $purpose, int $subjectId): ?AiInteraction
    {
        $query = TenantQuery::forTenant($this->context->tenantId())
            ->andEquals('subject_type', AiPurpose::subjectType($purpose))
            ->andEquals('subject_id', $subjectId)
            ->andEquals('purpose', $purpose);

        $statement = $this->connection->prepare(
            "SELECT * FROM ai_interaction WHERE {$query->whereSql()} AND status IN (:decided_a, :decided_b) "
            . 'ORDER BY created_at DESC, id DESC LIMIT 1',
        );
        $statement->execute([
            ...$query->parameters(),
            ':decided_a' => self::DECIDED[0],
            ':decided_b' => self::DECIDED[1],
        ]);

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : AiInteraction::reconstitute($row);
    }

    public function listRecent(int $limit): array
    {
        $query = TenantQuery::forTenant($this->context->tenantId());
        $limit = max(1, $limit);

        $statement = $this->connection->prepare(
            "SELECT * FROM ai_interaction WHERE {$query->whereSql()} ORDER BY created_at DESC, id DESC LIMIT {$limit}",
        );
        $statement->execute($query->parameters());

        return array_map(
            static fn (array $row): AiInteraction => AiInteraction::reconstitute($row),
            $statement->fetchAll(PDO::FETCH_ASSOC),
        );
    }

    /** @return array{':month_start': string, ':next_month': string} */
    private static function monthRange(DateTimeImmutable $monthStart): array
    {
        $start = $monthStart->setTime(0, 0)->modify('first day of this month');

        return [
            ':month_start' => $start->format('Y-m-d H:i:s.u'),
            ':next_month' => $start->modify('first day of next month')->format('Y-m-d H:i:s.u'),
        ];
    }
}
