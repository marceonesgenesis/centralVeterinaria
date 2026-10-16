<?php

declare(strict_types=1);

namespace CentralVet\Tests\Support;

use CentralVet\Domain\Contract\McpToolCallRepositoryInterface;
use CentralVet\Domain\McpToolCall;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * In-memory double for McpToolCallRepositoryInterface (8B T-05). insert()
 * stores any call (so tests can seed other tenants) with `created_at` =
 * now; search() only sees the constructor tenant, filters by the non-null
 * arguments (period inclusive) and orders by created_at DESC, id DESC.
 */
final class FakeMcpToolCallRepository implements McpToolCallRepositoryInterface
{
    /** @var array<int, array<string, mixed>> */
    private array $rows = [];
    private int $nextId = 1;

    public function __construct(private readonly int $tenantId)
    {
    }

    public function insert(McpToolCall $call): McpToolCall
    {
        if ($call->id() !== null) {
            throw new InvalidArgumentException('Tool call already has an id');
        }

        $call->assignId($this->nextId++);
        $this->rows[(int) $call->id()] = $call->toRow() + [
            'created_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s.u'),
        ];

        return $call;
    }

    public function search(
        ?int $systemUserId,
        ?string $toolName,
        ?string $decision,
        ?DateTimeImmutable $from,
        ?DateTimeImmutable $to,
        int $limit,
    ): array {
        $since = $from?->format('Y-m-d H:i:s.u');
        $until = $to?->format('Y-m-d H:i:s.u');
        $rows = array_values(array_filter(
            $this->rows,
            fn (array $row): bool => $row['tenant_id'] === $this->tenantId
                && ($systemUserId === null || $row['system_user_id'] === $systemUserId)
                && ($toolName === null || $row['tool_name'] === $toolName)
                && ($decision === null || $row['decision'] === $decision)
                && ($since === null || $row['created_at'] >= $since)
                && ($until === null || $row['created_at'] <= $until),
        ));
        usort($rows, static fn (array $a, array $b): int => [$b['created_at'], $b['id']] <=> [$a['created_at'], $a['id']]);

        return array_map(
            static fn (array $row): McpToolCall => McpToolCall::reconstitute($row),
            array_slice($rows, 0, max(0, $limit)),
        );
    }

    /**
     * Every stored call (all tenants), ordered by id.
     *
     * @return list<McpToolCall>
     */
    public function all(): array
    {
        ksort($this->rows);

        return array_values(array_map(static fn (array $row): McpToolCall => McpToolCall::reconstitute($row), $this->rows));
    }
}
