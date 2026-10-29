<?php

declare(strict_types=1);

namespace CentralVet\Domain;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Audit row of one copilot tool call (`mcp_tool_call`, migration 0015).
 *
 * `allowed` has no deny reason; `denied` requires one of DENY_REASONS
 * (mirrors `mcp_tool_call_decision_ck`). The input arrives already
 * minimized; it never goes into exception messages.
 */
final class McpToolCall
{
    public const DECISION_ALLOWED = 'allowed';
    public const DECISION_DENIED = 'denied';
    public const DECISIONS = [self::DECISION_ALLOWED, self::DECISION_DENIED];
    public const DENY_REASONS = ['not_offered', 'invalid_input', 'forbidden', 'not_found', 'rate_limited', 'error', 'timeout'];

    private function __construct(
        private ?int $id,
        private readonly int $tenantId,
        private readonly int $systemUnitId,
        private readonly int $systemUserId,
        private readonly ?int $conversationId,
        private readonly string $correlationId,
        private readonly string $toolName,
        private readonly string $inputMinimized,
        private readonly string $decision,
        private readonly ?string $denyReason,
        private readonly int $rowCount,
        private readonly bool $truncated,
        private readonly int $durationMs,
        private readonly ?DateTimeImmutable $createdAt,
    ) {
        foreach ([
            'tenant_id' => $tenantId,
            'system_unit_id' => $systemUnitId,
            'system_user_id' => $systemUserId,
        ] as $field => $value) {
            if ($value <= 0) {
                throw new InvalidArgumentException("{$field} must be positive");
            }
        }

        if ($conversationId !== null && $conversationId <= 0) {
            throw new InvalidArgumentException('conversation_id must be positive');
        }

        if (trim($toolName) === '' || strlen($toolName) > 60) {
            throw new InvalidArgumentException('Tool name must have 1 to 60 characters');
        }

        if ($correlationId === '' || strlen($correlationId) > 64) {
            throw new InvalidArgumentException('Correlation id must have 1 to 64 characters');
        }

        if (!in_array($decision, self::DECISIONS, true)) {
            throw new InvalidArgumentException('Unknown tool call decision');
        }

        if ($decision === self::DECISION_ALLOWED && $denyReason !== null) {
            throw new InvalidArgumentException('An allowed call has no deny reason');
        }

        if ($decision === self::DECISION_DENIED && !in_array($denyReason, self::DENY_REASONS, true)) {
            throw new InvalidArgumentException('A denied call needs a known deny reason');
        }

        if ($rowCount < 0 || $durationMs < 0) {
            throw new InvalidArgumentException('Row count and duration must not be negative');
        }
    }

    public static function record(
        int $tenantId,
        int $systemUnitId,
        int $systemUserId,
        ?int $conversationId,
        string $correlationId,
        string $toolName,
        string $inputMinimized,
        string $decision,
        ?string $denyReason,
        int $rowCount,
        bool $truncated,
        int $durationMs,
    ): self {
        return new self(
            null,
            $tenantId,
            $systemUnitId,
            $systemUserId,
            $conversationId,
            $correlationId,
            $toolName,
            $inputMinimized,
            $decision,
            $denyReason,
            $rowCount,
            $truncated,
            $durationMs,
            null,
        );
    }

    /** @param array<string, mixed> $row raw column values of `mcp_tool_call`. */
    public static function reconstitute(array $row): self
    {
        $call = new self(
            id: null,
            tenantId: (int) $row['tenant_id'],
            systemUnitId: (int) $row['system_unit_id'],
            systemUserId: (int) $row['system_user_id'],
            conversationId: isset($row['conversation_id']) ? (int) $row['conversation_id'] : null,
            correlationId: (string) $row['correlation_id'],
            toolName: (string) $row['tool_name'],
            inputMinimized: (string) ($row['input_minimized'] ?? ''),
            decision: (string) $row['decision'],
            denyReason: isset($row['deny_reason']) ? (string) $row['deny_reason'] : null,
            rowCount: (int) ($row['row_count'] ?? 0),
            truncated: (bool) (int) ($row['truncated'] ?? 0),
            durationMs: (int) ($row['duration_ms'] ?? 0),
            createdAt: isset($row['created_at']) ? new DateTimeImmutable((string) $row['created_at']) : null,
        );

        if (isset($row['id'])) {
            $call->assignId((int) $row['id']);
        }

        return $call;
    }

    /** Used by the repository after the insert. */
    public function assignId(int $id): void
    {
        if ($this->id !== null) {
            throw new InvalidArgumentException('Tool call already has an id');
        }

        if ($id <= 0) {
            throw new InvalidArgumentException('Id must be positive');
        }

        $this->id = $id;
    }

    /**
     * Column values for `mcp_tool_call` (without `created_at`, filled by
     * the database).
     *
     * @return array<string, mixed>
     */
    public function toRow(): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenantId,
            'system_unit_id' => $this->systemUnitId,
            'system_user_id' => $this->systemUserId,
            'conversation_id' => $this->conversationId,
            'correlation_id' => $this->correlationId,
            'tool_name' => $this->toolName,
            'input_minimized' => $this->inputMinimized,
            'decision' => $this->decision,
            'deny_reason' => $this->denyReason,
            'row_count' => $this->rowCount,
            'truncated' => $this->truncated ? 1 : 0,
            'duration_ms' => $this->durationMs,
        ];
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function tenantId(): int
    {
        return $this->tenantId;
    }

    public function systemUnitId(): int
    {
        return $this->systemUnitId;
    }

    public function systemUserId(): int
    {
        return $this->systemUserId;
    }

    public function conversationId(): ?int
    {
        return $this->conversationId;
    }

    public function correlationId(): string
    {
        return $this->correlationId;
    }

    public function toolName(): string
    {
        return $this->toolName;
    }

    public function inputMinimized(): string
    {
        return $this->inputMinimized;
    }

    public function decision(): string
    {
        return $this->decision;
    }

    public function denyReason(): ?string
    {
        return $this->denyReason;
    }

    public function rowCount(): int
    {
        return $this->rowCount;
    }

    public function truncated(): bool
    {
        return $this->truncated;
    }

    public function durationMs(): int
    {
        return $this->durationMs;
    }

    public function createdAt(): ?DateTimeImmutable
    {
        return $this->createdAt;
    }
}
