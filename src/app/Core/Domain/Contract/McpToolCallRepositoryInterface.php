<?php

declare(strict_types=1);

namespace CentralVet\Domain\Contract;

use CentralVet\Domain\McpToolCall;
use DateTimeImmutable;

/**
 * Persistence of the tool call audit (`mcp_tool_call`), scoped to the
 * current tenant.
 */
interface McpToolCallRepositoryInterface
{
    /** Inserts the call and returns it with the assigned id. */
    public function insert(McpToolCall $call): McpToolCall;

    /**
     * Calls of the tenant filtered by the non-null arguments, most recent
     * first; the period is `[from, to]` on `created_at`.
     *
     * @return list<McpToolCall>
     */
    public function search(
        ?int $systemUserId,
        ?string $toolName,
        ?string $decision,
        ?DateTimeImmutable $from,
        ?DateTimeImmutable $to,
        int $limit,
    ): array;
}
