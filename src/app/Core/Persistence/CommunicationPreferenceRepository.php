<?php

declare(strict_types=1);

namespace CentralVet\Persistence;

use CentralVet\Domain\CommunicationPreference;
use CentralVet\Domain\Contract\CommunicationPreferenceRepositoryInterface;
use CentralVet\Tenancy\TenantContext;
use DateTimeImmutable;
use PDO;

/**
 * PDO-backed persistence for tutors' channel preferences
 * (`communication_preference`, migration
 * 20261006_0012_phase7a_communication), always within the current tenant
 * (TenantQuery::forTenant(), ADR 0002).
 *
 * upsert() is a single `INSERT ... ON DUPLICATE KEY UPDATE` on the UNIQUE
 * (tenant_id, tutor_id, channel), identical on MySQL 8 and 5.7: the first
 * choice inserts the row, later ones overwrite status, consent source,
 * author and change time.
 */
final class CommunicationPreferenceRepository implements CommunicationPreferenceRepositoryInterface
{
    public function __construct(private readonly TenantContext $context, private readonly PDO $connection)
    {
    }

    public function findForTutor(int $tutorId): array
    {
        $query = TenantQuery::forTenant($this->context->tenantId())->andEquals('tutor_id', $tutorId);

        $statement = $this->connection->prepare(
            "SELECT * FROM communication_preference WHERE {$query->whereSql()} ORDER BY channel ASC",
        );
        $statement->execute($query->parameters());

        $preferences = [];

        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $preference = CommunicationPreference::reconstitute($row);
            $preferences[$preference->channel()] = $preference;
        }

        return $preferences;
    }

    public function upsert(CommunicationPreference $preference): void
    {
        $this->context->assertTenant($preference->tenantId());

        $statement = $this->connection->prepare(
            <<<'SQL'
            INSERT INTO communication_preference (
                tenant_id, tutor_id, channel, status, consent_source,
                changed_by_system_user_id, changed_at
            ) VALUES (
                :tenant_id, :tutor_id, :channel, :status, :consent_source,
                :changed_by_system_user_id, :changed_at
            )
            ON DUPLICATE KEY UPDATE
                status = VALUES(status),
                consent_source = VALUES(consent_source),
                changed_by_system_user_id = VALUES(changed_by_system_user_id),
                changed_at = VALUES(changed_at)
            SQL
        );
        $statement->execute([
            ':tenant_id' => $preference->tenantId(),
            ':tutor_id' => $preference->tutorId(),
            ':channel' => $preference->channel(),
            ':status' => $preference->status(),
            ':consent_source' => $preference->consentSource(),
            ':changed_by_system_user_id' => $preference->changedBySystemUserId(),
            ':changed_at' => self::timestamp($preference->changedAt()),
        ]);
    }

    private static function timestamp(DateTimeImmutable $value): string
    {
        return $value->format('Y-m-d H:i:s.u');
    }
}
