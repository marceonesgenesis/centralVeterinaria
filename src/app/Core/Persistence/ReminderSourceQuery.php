<?php

declare(strict_types=1);

namespace CentralVet\Persistence;

use CentralVet\Domain\Contract\ReminderSourceQueryInterface;
use CentralVet\Domain\MessagePurpose;
use CentralVet\Domain\OutboundMessage;
use CentralVet\Domain\ReminderCandidate;
use CentralVet\Tenancy\TenantContext;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

/**
 * Read model of the automatic reminders (Fase 7A, T-08): read-only SELECTs
 * over the whole current tenant (no fixed unit; each candidate carries the
 * unit of its source).
 *
 * Every table of every join is filtered by the current tenant; `system_unit`
 * (Adianti, no tenant column) is joined by the source's unit id and
 * `tenant` by the source's tenant id. Instants are converted to the
 * application timezone and compared as wall-clock strings, the convention
 * of {@see AppointmentRepository::listByUnitAndDate()}.
 *
 * Each candidate carries the tutor contact in memory only and the variables
 * `tutor_name`, `patient_name`, `unit_name`, `clinic_name` (`trade_name`,
 * or `legal_name`) plus the source ones, already formatted.
 */
final class ReminderSourceQuery implements ReminderSourceQueryInterface
{
    private const SQL_DATETIME = 'Y-m-d H:i:s.u';

    public function __construct(
        private readonly TenantContext $context,
        private readonly PDO $connection,
    ) {
    }

    /**
     * `$from` inclusive, `$to` exclusive. A return appointment (linked by
     * appointment_followup or surgery.followup_appointment_id) is a
     * `return_reminder` while `agendado` or `confirmado`; any other
     * appointment is an `appointment_confirmation` only while `agendado`.
     */
    public function appointmentsBetween(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $query = $this->tenantQuery('a');
        $rows = $this->fetch(
            <<<SQL
            SELECT src.* FROM (
                SELECT a.id, a.system_unit_id, a.scheduled_at, a.status, a.patient_id,
                       t.id AS tutor_id, t.full_name AS tutor_name, t.email AS tutor_email, t.phone AS tutor_phone,
                       p.name AS patient_name, su.name AS unit_name,
                       COALESCE(NULLIF(tn.trade_name, ''), tn.legal_name) AS clinic_name,
                       (
                           EXISTS (
                               SELECT 1 FROM appointment_followup af
                               WHERE af.appointment_id = a.id AND af.tenant_id = a.tenant_id
                           )
                           OR EXISTS (
                               SELECT 1 FROM surgery sg
                               WHERE sg.followup_appointment_id = a.id AND sg.tenant_id = a.tenant_id
                           )
                       ) AS is_return
                FROM appointment a
                INNER JOIN patient p ON p.id = a.patient_id AND p.tenant_id = a.tenant_id
                INNER JOIN tutor t ON t.id = p.tutor_id AND t.tenant_id = a.tenant_id
                INNER JOIN tenant tn ON tn.id = a.tenant_id
                INNER JOIN system_unit su ON su.id = a.system_unit_id
                WHERE {$query->whereSql()}
                  AND a.scheduled_at >= :window_start AND a.scheduled_at < :window_end
                  AND a.status IN ('agendado', 'confirmado')
            ) src
            WHERE src.status = 'agendado' OR src.is_return = 1
            ORDER BY src.scheduled_at, src.id
            SQL,
            [
                ...$query->parameters(),
                ':window_start' => self::toApplicationTime($from)->format(self::SQL_DATETIME),
                ':window_end' => self::toApplicationTime($to)->format(self::SQL_DATETIME),
            ],
        );

        return array_map(
            static function (array $row): ReminderCandidate {
                $scheduledAt = new DateTimeImmutable((string) $row['scheduled_at']);

                return self::candidate(
                    (int) $row['is_return'] === 1 ? MessagePurpose::RETURN_REMINDER : MessagePurpose::APPOINTMENT_CONFIRMATION,
                    OutboundMessage::SOURCE_APPOINTMENT,
                    $row,
                    [
                        'appointment_date' => $scheduledAt->format('d/m/Y'),
                        'appointment_time' => $scheduledAt->format('H:i'),
                    ],
                );
            },
            $rows,
        );
    }

    /**
     * Next doses due between the two dates (inclusive) not yet followed by a
     * later application of the same vaccine to the same patient; unit of the
     * vaccination's encounter.
     */
    public function vaccinesDueBetween(DateTimeImmutable $fromDate, DateTimeImmutable $toDate): array
    {
        $query = $this->tenantQuery('v');
        $rows = $this->fetch(
            <<<SQL
            SELECT v.id, v.patient_id, v.next_dose_at, e.system_unit_id,
                   t.id AS tutor_id, t.full_name AS tutor_name, t.email AS tutor_email, t.phone AS tutor_phone,
                   p.name AS patient_name, vc.name AS vaccine_name, su.name AS unit_name,
                   COALESCE(NULLIF(tn.trade_name, ''), tn.legal_name) AS clinic_name
            FROM vaccination v
            INNER JOIN encounter e ON e.id = v.encounter_id AND e.tenant_id = v.tenant_id
            INNER JOIN patient p ON p.id = v.patient_id AND p.tenant_id = v.tenant_id
            INNER JOIN tutor t ON t.id = p.tutor_id AND t.tenant_id = v.tenant_id
            INNER JOIN vaccine_catalog_item vc ON vc.id = v.vaccine_catalog_item_id AND vc.tenant_id = v.tenant_id
            INNER JOIN tenant tn ON tn.id = v.tenant_id
            INNER JOIN system_unit su ON su.id = e.system_unit_id
            WHERE {$query->whereSql()}
              AND v.next_dose_at >= :from_date AND v.next_dose_at <= :to_date
              AND NOT EXISTS (
                  SELECT 1 FROM vaccination later
                  WHERE later.tenant_id = v.tenant_id
                    AND later.patient_id = v.patient_id
                    AND later.vaccine_catalog_item_id = v.vaccine_catalog_item_id
                    AND (later.applied_at > v.applied_at OR (later.applied_at = v.applied_at AND later.id > v.id))
              )
            ORDER BY v.next_dose_at, v.id
            SQL,
            [
                ...$query->parameters(),
                ':from_date' => $fromDate->format('Y-m-d'),
                ':to_date' => $toDate->format('Y-m-d'),
            ],
        );

        return array_map(
            static fn (array $row): ReminderCandidate => self::candidate(
                MessagePurpose::VACCINE_DUE,
                OutboundMessage::SOURCE_VACCINATION,
                $row,
                [
                    'vaccine_name' => (string) $row['vaccine_name'],
                    'due_date' => (new DateTimeImmutable((string) $row['next_dose_at']))->format('d/m/Y'),
                ],
            ),
            $rows,
        );
    }

    /**
     * Receivables `open` or `partially_paid` created strictly before
     * `$before`; unit of the encounter account; `amount_due` = total − paid.
     */
    public function openReceivablesCreatedBefore(DateTimeImmutable $before): array
    {
        $query = $this->tenantQuery('r');
        $rows = $this->fetch(
            <<<SQL
            SELECT r.id, r.total_cents, r.paid_cents, ea.system_unit_id, ea.patient_id,
                   t.id AS tutor_id, t.full_name AS tutor_name, t.email AS tutor_email, t.phone AS tutor_phone,
                   p.name AS patient_name, su.name AS unit_name,
                   COALESCE(NULLIF(tn.trade_name, ''), tn.legal_name) AS clinic_name
            FROM receivable r
            INNER JOIN encounter_account ea ON ea.id = r.encounter_account_id AND ea.tenant_id = r.tenant_id
            INNER JOIN tutor t ON t.id = r.tutor_id AND t.tenant_id = r.tenant_id
            INNER JOIN patient p ON p.id = ea.patient_id AND p.tenant_id = r.tenant_id
            INNER JOIN tenant tn ON tn.id = r.tenant_id
            INNER JOIN system_unit su ON su.id = ea.system_unit_id
            WHERE {$query->whereSql()}
              AND r.status IN ('open', 'partially_paid')
              AND r.created_at < :created_before
            ORDER BY r.created_at, r.id
            SQL,
            [...$query->parameters(), ':created_before' => self::toApplicationTime($before)->format(self::SQL_DATETIME)],
        );

        return array_map(
            static fn (array $row): ReminderCandidate => self::candidate(
                MessagePurpose::RECEIVABLE_OPEN,
                OutboundMessage::SOURCE_RECEIVABLE,
                $row,
                ['amount_due' => self::formatCents((int) $row['total_cents'] - (int) $row['paid_cents'])],
            ),
            $rows,
        );
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, string> $sourceVariables
     */
    private static function candidate(string $purpose, string $sourceType, array $row, array $sourceVariables): ReminderCandidate
    {
        $email = $row['tutor_email'] !== null ? trim((string) $row['tutor_email']) : '';

        return new ReminderCandidate(
            $purpose,
            $sourceType,
            (int) $row['id'],
            (int) $row['system_unit_id'],
            (int) $row['tutor_id'],
            $row['patient_id'] !== null ? (int) $row['patient_id'] : null,
            $email !== '' ? $email : null,
            (string) $row['tutor_phone'],
            [
                'tutor_name' => (string) $row['tutor_name'],
                'patient_name' => (string) $row['patient_name'],
                'unit_name' => (string) $row['unit_name'],
                'clinic_name' => (string) $row['clinic_name'],
                ...$sourceVariables,
            ],
        );
    }

    private static function formatCents(int $cents): string
    {
        return 'R$ ' . number_format($cents / 100, 2, ',', '.');
    }

    private static function toApplicationTime(DateTimeImmutable $instant): DateTimeImmutable
    {
        return $instant->setTimezone(new DateTimeZone(date_default_timezone_get()));
    }

    /**
     * Tenant predicate of the driving table, never overridable by input
     * (ADR 0002).
     */
    private function tenantQuery(string $alias): TenantQuery
    {
        return TenantQuery::forTenant($this->context->tenantId(), $alias);
    }

    /**
     * @param array<string, mixed> $parameters
     * @return list<array<string, mixed>>
     */
    private function fetch(string $sql, array $parameters): array
    {
        $statement = $this->connection->prepare($sql);
        $statement->execute($parameters);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }
}
