<?php

declare(strict_types=1);

namespace CentralVet\Audit;

use CentralVet\Audit\Contract\AuditLogWriterInterface;
use JsonException;
use PDO;

/**
 * Writes one row per AuditEvent into `audit_log`.
 *
 * PENDING / DO NOT WIRE YET: this class depends on the `audit_log` table
 * created by the not-yet-applied migration
 * src/app/database/migrations/20260920_0001_foundation_multitenancy.sql
 * (ADR 0003). It is prepared and syntax-checked (php -l) but must not be
 * constructed with a real PDO connection or executed against the live
 * database until that migration has explicit SQL execution approval (see
 * plan.md "Checkpoints de autorização" and notes.md "Bloqueios"). Bind
 * NullAuditLogWriter instead until then.
 *
 * before/after/metadata arrive already redacted (see AuditEvent, built from
 * data passed through AuditRedactor by the caller) — this class does not log
 * or re-derive anything sensitive, it only serializes what it is given.
 */
final class PdoAuditLogWriter implements AuditLogWriterInterface
{
    public function __construct(private readonly PDO $connection)
    {
    }

    public function record(AuditEvent $event): void
    {
        $statement = $this->connection->prepare(
            <<<'SQL'
            INSERT INTO audit_log (
                tenant_id, system_unit_id, system_user_id, correlation_id,
                action, entity_type, entity_id, before_data, after_data,
                metadata, ip_address, user_agent, created_at
            ) VALUES (
                :tenant_id, :system_unit_id, :system_user_id, :correlation_id,
                :action, :entity_type, :entity_id, :before_data, :after_data,
                :metadata, :ip_address, :user_agent, :created_at
            )
            SQL
        );

        $statement->execute([
            ':tenant_id' => $event->tenantId,
            ':system_unit_id' => $event->unitId,
            ':system_user_id' => $event->userId,
            ':correlation_id' => $event->correlationId,
            ':action' => $event->action,
            ':entity_type' => $event->entityType,
            ':entity_id' => $event->entityId !== null ? (string) $event->entityId : null,
            ':before_data' => self::toJson($event->beforeData),
            ':after_data' => self::toJson($event->afterData),
            ':metadata' => self::toJson($event->metadata),
            ':ip_address' => $event->ipAddress,
            ':user_agent' => $event->userAgent,
            ':created_at' => $event->occurredAt->format('Y-m-d H:i:s.u'),
        ]);
    }

    /** @param array<string, mixed>|null $data */
    private static function toJson(?array $data): ?string
    {
        if ($data === null) {
            return null;
        }

        try {
            return json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException) {
            return json_encode(['_unserializable' => true]);
        }
    }
}
