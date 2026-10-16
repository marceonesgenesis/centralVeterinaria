<?php

declare(strict_types=1);

namespace CentralVet\Persistence;

use CentralVet\Domain\Appointment;
use CentralVet\Domain\Contract\CopilotAgendaQueryInterface;
use CentralVet\Tenancy\TenantContext;
use DateTimeImmutable;
use PDO;

/**
 * Read-only agenda reads behind the management copilot tools (T-19).
 * Every statement filters by the tenant of the session; appointments() also
 * by the active unit (TenantContext::requireUnitId(), never caller input).
 * busy() mirrors AppointmentService::assertNoConflict: blocking statuses
 * only, service duration > 0, the professional's appointments of the whole
 * tenant on that calendar day (appointments are assumed not to cross
 * midnight). Professionals are active system users that are members of the
 * tenant, as AgendaView::listProfessionals; schedulableProfessionals()
 * narrows them to those with an appointment in the active unit in the 90
 * days up to the day asked (T-27). Only `patient.name` and
 * `system_users.name` are read as names; no contact column leaves here.
 * Every top-level statement carries the 5 s execution-time optimizer hint.
 */
final class CopilotAgendaQuery implements CopilotAgendaQueryInterface
{
    private const BLOCKING_STATUSES = [
        Appointment::STATUS_SCHEDULED,
        Appointment::STATUS_CONFIRMED,
        Appointment::STATUS_IN_PROGRESS,
        Appointment::STATUS_DONE,
    ];

    public function __construct(
        private readonly TenantContext $context,
        private readonly PDO $connection,
    ) {
    }

    public function appointments(DateTimeImmutable $from, DateTimeImmutable $to, ?int $professionalId, int $limit): array
    {
        $query = TenantQuery::forTenant($this->context->tenantId(), 'a')
            ->andEquals('system_unit_id', $this->context->requireUnitId(), 'a');
        if ($professionalId !== null) {
            $query = $query->andEquals('professional_system_user_id', $professionalId, 'a');
        }

        $rows = $this->fetchAll(
            "SELECT /*+ MAX_EXECUTION_TIME(5000) */ a.scheduled_at, p.name AS patient_name, s.name AS service_name,
                    s.duration_minutes, a.status, a.professional_system_user_id AS professional_id, u.name AS professional_name
               FROM appointment a
               JOIN patient p ON p.id = a.patient_id AND p.tenant_id = a.tenant_id
               LEFT JOIN service s ON s.id = a.service_id AND s.tenant_id = a.tenant_id
               LEFT JOIN system_users u ON u.id = a.professional_system_user_id
              WHERE {$query->whereSql()}
                AND a.scheduled_at >= :range_from AND a.scheduled_at < :range_to
              ORDER BY a.scheduled_at ASC, a.id ASC
              LIMIT " . max(1, $limit),
            [
                ...$query->parameters(),
                ':range_from' => $from->format('Y-m-d H:i:s'),
                ':range_to' => $to->format('Y-m-d H:i:s'),
            ],
        );

        return array_map(static fn (array $row): array => [
            'scheduled_at' => substr((string) $row['scheduled_at'], 0, 19),
            'patient_name' => (string) $row['patient_name'],
            'service_name' => $row['service_name'] === null ? null : (string) $row['service_name'],
            'duration_minutes' => (int) ($row['duration_minutes'] ?? 0),
            'status' => (string) $row['status'],
            'professional_id' => (int) $row['professional_id'],
            'professional_name' => (string) ($row['professional_name'] ?? ''),
        ], $rows);
    }

    public function busy(DateTimeImmutable $day, ?int $professionalId): array
    {
        // The tool is unit-scoped; the conflict rule itself is tenant-wide.
        $this->context->requireUnitId();

        $query = TenantQuery::forTenant($this->context->tenantId(), 'a');
        if ($professionalId !== null) {
            $query = $query->andEquals('professional_system_user_id', $professionalId, 'a');
        }

        $statuses = [];
        foreach (self::BLOCKING_STATUSES as $index => $status) {
            $statuses[':busy_status_' . $index] = $status;
        }

        $midnight = $day->setTime(0, 0);
        $rows = $this->fetchAll(
            "SELECT /*+ MAX_EXECUTION_TIME(5000) */ a.professional_system_user_id AS professional_id, a.scheduled_at, s.duration_minutes
               FROM appointment a
               JOIN service s ON s.id = a.service_id AND s.tenant_id = a.tenant_id
              WHERE {$query->whereSql()}
                AND a.status IN (" . implode(', ', array_keys($statuses)) . ")
                AND s.duration_minutes > 0
                AND a.scheduled_at >= :day_start AND a.scheduled_at < :day_end
              ORDER BY a.professional_system_user_id ASC, a.scheduled_at ASC, a.id ASC",
            [
                ...$query->parameters(),
                ...$statuses,
                ':day_start' => $midnight->format('Y-m-d H:i:s'),
                ':day_end' => $midnight->modify('+1 day')->format('Y-m-d H:i:s'),
            ],
        );

        return array_map(static function (array $row): array {
            $start = new DateTimeImmutable(substr((string) $row['scheduled_at'], 0, 19));

            return [
                'professional_id' => (int) $row['professional_id'],
                'start' => $start->format('Y-m-d H:i:s'),
                'end' => $start->modify('+' . (int) $row['duration_minutes'] . ' minutes')->format('Y-m-d H:i:s'),
            ];
        }, $rows);
    }

    public function professionals(): array
    {
        $rows = $this->fetchAll(
            "SELECT /*+ MAX_EXECUTION_TIME(5000) */ u.id, u.name
               FROM system_users u
              WHERE u.active = 'Y'{$this->tenantMemberSql('u')}
              ORDER BY u.name ASC, u.id ASC",
            [':member_tenant_id' => $this->context->tenantId()],
        );

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'name' => (string) ($row['name'] ?? ''),
        ], $rows);
    }

    public function schedulableProfessionals(DateTimeImmutable $day): array
    {
        $query = TenantQuery::forTenant($this->context->tenantId(), 'a')
            ->andEquals('system_unit_id', $this->context->requireUnitId(), 'a');

        $midnight = $day->setTime(0, 0);
        $rows = $this->fetchAll(
            "SELECT /*+ MAX_EXECUTION_TIME(5000) */ u.id, u.name
               FROM system_users u
              WHERE u.active = 'Y'{$this->tenantMemberSql('u')}
                AND EXISTS (SELECT 1 FROM appointment a
                             WHERE {$query->whereSql()}
                               AND a.professional_system_user_id = u.id
                               AND a.scheduled_at >= :recent_from AND a.scheduled_at < :recent_to)
              ORDER BY u.name ASC, u.id ASC",
            [
                ...$query->parameters(),
                ':member_tenant_id' => $this->context->tenantId(),
                ':recent_from' => $midnight->modify('-90 days')->format('Y-m-d H:i:s'),
                ':recent_to' => $midnight->modify('+1 day')->format('Y-m-d H:i:s'),
            ],
        );

        if ($rows === []) {
            return $this->professionals();
        }

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'name' => (string) ($row['name'] ?? ''),
        ], $rows);
    }

    public function professionalExists(int $id): bool
    {
        return $this->fetchAll(
            "SELECT /*+ MAX_EXECUTION_TIME(5000) */ 1 AS found
               FROM system_users u
              WHERE u.id = :professional_id AND u.active = 'Y'{$this->tenantMemberSql('u')}
              LIMIT 1",
            [':professional_id' => $id, ':member_tenant_id' => $this->context->tenantId()],
        ) !== [];
    }

    private function tenantMemberSql(string $alias): string
    {
        return " AND EXISTS (SELECT 1 FROM tenant_user tu"
            . " WHERE tu.system_user_id = {$alias}.id AND tu.tenant_id = :member_tenant_id)";
    }

    /**
     * @param array<string, int|string|float|bool|null> $parameters
     * @return list<array<string, mixed>>
     */
    private function fetchAll(string $sql, array $parameters): array
    {
        $statement = $this->connection->prepare($sql);
        $statement->execute($parameters);

        return array_values($statement->fetchAll(PDO::FETCH_ASSOC));
    }
}
