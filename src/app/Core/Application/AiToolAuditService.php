<?php

declare(strict_types=1);

namespace CentralVet\Application;

use CentralVet\Audit\PdoAuditLogWriter;
use CentralVet\Authorization\AdiantiProgramPermissionProvider;
use CentralVet\Authorization\AuthorizationRequest;
use CentralVet\Authorization\Contract\AuthorizationPolicyInterface;
use CentralVet\Authorization\RbacAuthorizationService;
use CentralVet\Domain\Contract\McpToolCallRepositoryInterface;
use CentralVet\Domain\McpToolCall;
use CentralVet\Persistence\McpToolCallRepository;
use CentralVet\Tenancy\AdiantiSessionContextSource;
use CentralVet\Tenancy\TenantContext;
use DateTimeImmutable;
use PDO;

/**
 * Copilot tool call audit for the manager (Fase 8B, T-15).
 *
 * `search` checks RBAC of `AiToolCallList::onReload` (tenant-wide, no unit
 * scope) and reads `mcp_tool_call` of the context tenant with the filters
 * `user_id`, `tool_name`, `decision`, `date_from` and `date_to` (dates as
 * `Y-m-d` or `d/m/Y`, inclusive whole days). Values outside the closed
 * lists or malformed are ignored. The minimized input stays in the
 * returned calls; the screen never renders it.
 */
final class AiToolAuditService
{
    public const ACTION = 'AiToolCallList::onReload';
    public const MAX_LIMIT = 200;

    public function __construct(
        private readonly McpToolCallRepositoryInterface $calls,
        private readonly AuthorizationPolicyInterface $authorization,
        private readonly TenantContext $context,
    ) {
    }

    public static function forContext(TenantContext $context, PDO $connection): self
    {
        return new self(
            new McpToolCallRepository($context, $connection),
            new RbacAuthorizationService(
                new AdiantiProgramPermissionProvider(new AdiantiSessionContextSource()),
                new PdoAuditLogWriter($connection),
            ),
            $context,
        );
    }

    /**
     * @param array<string, mixed> $filters
     * @return list<McpToolCall> newest first, at most MAX_LIMIT
     * @throws \CentralVet\Authorization\Exception\AuthorizationDenied without `AiToolCallList::onReload`.
     */
    public function search(array $filters, int $limit): array
    {
        $this->authorization->decide(new AuthorizationRequest(
            context: $this->context,
            action: self::ACTION,
            requiresUnitScope: false,
            resourceTenantId: $this->context->tenantId(),
            entityType: 'mcp_tool_call',
        ))->assertAllowed();

        $normalized = self::normalize($filters);

        return $this->calls->search(
            $normalized['user_id'],
            $normalized['tool_name'],
            $normalized['decision'],
            $normalized['date_from'],
            $normalized['date_to'],
            min(max(1, $limit), self::MAX_LIMIT),
        );
    }

    /**
     * The filters that will be applied (invalid values become null).
     *
     * @param array<string, mixed> $filters
     * @return array{user_id: ?int, tool_name: ?string, decision: ?string, date_from: ?DateTimeImmutable, date_to: ?DateTimeImmutable}
     */
    public static function normalize(array $filters): array
    {
        $userId = filter_var($filters['user_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $toolName = (string) ($filters['tool_name'] ?? '');
        $decision = (string) ($filters['decision'] ?? '');

        return [
            'user_id' => $userId === false ? null : $userId,
            'tool_name' => preg_match('/^[a-z][a-z0-9_]{0,59}$/', $toolName) === 1 ? $toolName : null,
            'decision' => in_array($decision, McpToolCall::DECISIONS, true) ? $decision : null,
            'date_from' => self::day($filters['date_from'] ?? null, '00:00:00.000000'),
            'date_to' => self::day($filters['date_to'] ?? null, '23:59:59.999999'),
        ];
    }

    private static function day(mixed $value, string $time): ?DateTimeImmutable
    {
        $value = trim((string) $value);

        foreach (['Y-m-d', 'd/m/Y'] as $format) {
            $date = DateTimeImmutable::createFromFormat('!' . $format, $value);

            if ($date !== false && $date->format($format) === $value) {
                return new DateTimeImmutable($date->format('Y-m-d') . ' ' . $time);
            }
        }

        return null;
    }
}
