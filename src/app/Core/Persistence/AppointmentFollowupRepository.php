<?php

declare(strict_types=1);

namespace CentralVet\Persistence;

use CentralVet\Domain\Contract\AppointmentFollowupRepositoryInterface;
use CentralVet\Tenancy\Exception\TenantBoundaryViolation;
use CentralVet\Tenancy\TenantContext;
use PDO;

/**
 * PDO-backed persistence for `appointment_followup` (migration
 * 20261006_0012_phase7a_communication): marks an appointment as the return
 * visit scheduled from an encounter, always within the current tenant
 * (TenantQuery::forTenant(), ADR 0002). link() inserts only when both the
 * appointment and the encounter belong to the tenant (otherwise
 * TenantBoundaryViolation); the UNIQUE on appointment_id makes a second
 * link of the same appointment fail loudly.
 */
final class AppointmentFollowupRepository implements AppointmentFollowupRepositoryInterface
{
    public function __construct(private readonly TenantContext $context, private readonly PDO $connection)
    {
    }

    public function link(int $appointmentId, int $encounterId, int $createdBySystemUserId): void
    {
        $statement = $this->connection->prepare(
            <<<'SQL'
            INSERT INTO appointment_followup (tenant_id, appointment_id, encounter_id, created_by_system_user_id)
            SELECT a.tenant_id, a.id, e.id, :created_by_system_user_id
            FROM appointment a
            JOIN encounter e ON e.id = :encounter_id AND e.tenant_id = :encounter_tenant_id
            WHERE a.id = :appointment_id AND a.tenant_id = :appointment_tenant_id
            SQL
        );
        $tenantId = $this->context->tenantId();
        $statement->execute([
            ':created_by_system_user_id' => $createdBySystemUserId,
            ':encounter_id' => $encounterId,
            ':encounter_tenant_id' => $tenantId,
            ':appointment_id' => $appointmentId,
            ':appointment_tenant_id' => $tenantId,
        ]);

        // Nothing inserted: the appointment or the encounter is not a row
        // of the current tenant.
        if ($statement->rowCount() !== 1) {
            throw new TenantBoundaryViolation('Resource does not belong to the authenticated tenant');
        }
    }

    public function isFollowup(int $appointmentId): bool
    {
        $query = TenantQuery::forTenant($this->context->tenantId())->andEquals('appointment_id', $appointmentId);

        $statement = $this->connection->prepare("SELECT 1 FROM appointment_followup WHERE {$query->whereSql()} LIMIT 1");
        $statement->execute($query->parameters());

        return $statement->fetchColumn() !== false;
    }
}
