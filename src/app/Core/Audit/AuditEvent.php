<?php

declare(strict_types=1);

namespace CentralVet\Audit;

use DateTimeImmutable;

/**
 * One row of the business audit trail (`audit_log`, ADR 0003) — the tenant
 * "who did what to which entity" record, deliberately separate from the
 * technical correlation id handled by
 * CentralVet\Observability\CorrelationId\CorrelationIdContext (T-10). The
 * two are joined only by sharing the same correlation id value, never by a
 * code dependency in either direction.
 *
 * Immutable and storage-agnostic on purpose: building one never touches a
 * database, so callers can construct it even before the foundation
 * migration (src/app/database/migrations/20260920_0001_foundation_multitenancy.sql)
 * has been applied. Only actually persisting it (see
 * Contract\AuditLogWriterInterface) depends on that table existing.
 *
 * Callers must redact before/after/metadata (see AuditRedactor) before
 * constructing an event — this class does not redact on its own, so nothing
 * that reaches here should still contain passwords, tokens, cookies or
 * unnecessary PII.
 */
final class AuditEvent
{
    /**
     * @param array<string, mixed>|null $beforeData
     * @param array<string, mixed>|null $afterData
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public readonly int $tenantId,
        public readonly ?int $unitId,
        public readonly ?int $userId,
        public readonly string $correlationId,
        public readonly string $action,
        public readonly string $entityType,
        public readonly string|int|null $entityId,
        public readonly ?array $beforeData,
        public readonly ?array $afterData,
        public readonly array $metadata,
        public readonly ?string $ipAddress,
        public readonly ?string $userAgent,
        public readonly DateTimeImmutable $occurredAt = new DateTimeImmutable(),
    ) {
    }
}
