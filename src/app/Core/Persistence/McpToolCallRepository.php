<?php

declare(strict_types=1);

namespace CentralVet\Persistence;

use CentralVet\Domain\Contract\McpToolCallRepositoryInterface;
use CentralVet\Domain\McpToolCall;
use CentralVet\Tenancy\TenantContext;
use DateTimeImmutable;
use InvalidArgumentException;
use PDO;

/**
 * PDO-backed persistence of the copilot tool call audit (`mcp_tool_call`,
 * migration 0015). Every query starts from TenantQuery::forTenant() with the
 * tenant of the context (ADR 0002); inserting a row of another tenant throws
 * TenantBoundaryViolation. `created_at` is filled by the database. The
 * search reads `mcp_tool_call_tenant_created_idx`, newest first.
 *
 * The minimized input is never put into exception messages.
 */
final class McpToolCallRepository implements McpToolCallRepositoryInterface
{
    public function __construct(private readonly TenantContext $context, private readonly PDO $connection)
    {
    }

    public function insert(McpToolCall $call): McpToolCall
    {
        if ($call->id() !== null) {
            throw new InvalidArgumentException('Tool call already has an id');
        }

        $this->context->assertTenant($call->tenantId());

        $row = $call->toRow();
        unset($row['id']);
        $row['tenant_id'] = $this->context->tenantId();

        $columns = array_keys($row);
        $placeholders = array_map(static fn (string $column): string => ':' . $column, $columns);

        $statement = $this->connection->prepare(
            'INSERT INTO mcp_tool_call (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $placeholders) . ')',
        );
        $statement->execute(array_combine($placeholders, array_values($row)));

        $call->assignId((int) $this->connection->lastInsertId());

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
        $query = TenantQuery::forTenant($this->context->tenantId());

        if ($systemUserId !== null) {
            $query = $query->andEquals('system_user_id', $systemUserId);
        }

        if ($toolName !== null) {
            $query = $query->andEquals('tool_name', $toolName);
        }

        if ($decision !== null) {
            $query = $query->andEquals('decision', $decision);
        }

        $where = $query->whereSql();
        $parameters = $query->parameters();

        if ($from !== null) {
            $where .= ' AND created_at >= :created_from';
            $parameters[':created_from'] = $from->format('Y-m-d H:i:s.u');
        }

        if ($to !== null) {
            $where .= ' AND created_at <= :created_to';
            $parameters[':created_to'] = $to->format('Y-m-d H:i:s.u');
        }

        $limit = max(1, $limit);

        $statement = $this->connection->prepare(
            "SELECT * FROM mcp_tool_call WHERE {$where} ORDER BY created_at DESC, id DESC LIMIT {$limit}",
        );
        $statement->execute($parameters);

        return array_map(
            static fn (array $row): McpToolCall => McpToolCall::reconstitute($row),
            $statement->fetchAll(PDO::FETCH_ASSOC),
        );
    }
}
