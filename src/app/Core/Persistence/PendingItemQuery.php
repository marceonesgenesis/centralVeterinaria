<?php

declare(strict_types=1);

namespace CentralVet\Persistence;

use CentralVet\Domain\Contract\PendingItemQueryInterface;
use CentralVet\Domain\DocumentKind;
use CentralVet\Domain\PendingItem;
use CentralVet\Tenancy\TenantContext;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

/**
 * Read model of the Central de Pendências (Fase 7A, T-08): one SELECT per
 * source, read-only, limited per type in SQL.
 *
 * Every table of every join is filtered by the current tenant (the driving
 * table through {@see TenantQuery}, the joined ones by `<alias>.tenant_id =
 * <driver>.tenant_id`), and every source is restricted to the given unit.
 * Dates follow the convention of
 * {@see AppointmentRepository::listByUnitAndDate()}: wall-clock strings in
 * the application timezone, parsed back with `new DateTimeImmutable()`.
 *
 * `dueAt` follows {@see PendingItem}; `subjectLabel` is the exam, vaccine,
 * service or order name, or the purpose code, never translated (the screen
 * translates and escapes it).
 */
final class PendingItemQuery implements PendingItemQueryInterface
{
    private const SQL_DATETIME = 'Y-m-d H:i:s.u';

    public function __construct(
        private readonly TenantContext $context,
        private readonly PDO $connection,
    ) {
    }

    public function listForUnit(int $systemUnitId, DateTimeImmutable $now, int $limitPerType): array
    {
        if ($limitPerType <= 0) {
            return [];
        }

        $now = $now->setTimezone(new DateTimeZone(date_default_timezone_get()));

        return [
            ...$this->examResults($systemUnitId, $limitPerType),
            ...$this->examReviews($systemUnitId, $limitPerType),
            ...$this->returnAppointments($systemUnitId, $now, $limitPerType),
            ...$this->vaccinesDue($systemUnitId, $now, $limitPerType),
            ...$this->lateAdministrations($systemUnitId, $now, $limitPerType),
            ...$this->failedMessages($systemUnitId, $limitPerType),
            ...$this->manualWhatsAppMessages($systemUnitId, $limitPerType),
            ...$this->openReceivables($systemUnitId, $limitPerType),
            ...$this->failedDocuments($systemUnitId, $limitPerType),
        ];
    }

    /**
     * @return list<PendingItem>
     */
    private function examResults(int $unitId, int $limit): array
    {
        $query = $this->tenantQuery('er')->andEquals('status', 'requested', 'er')->andEquals('system_unit_id', $unitId, 'e');
        $rows = $this->fetch(
            <<<SQL
            SELECT er.id, er.encounter_id, er.professional_system_user_id, er.requested_at,
                   p.name AS patient_name, ec.name AS exam_name
            FROM exam_request er
            INNER JOIN encounter e ON e.id = er.encounter_id AND e.tenant_id = er.tenant_id
            INNER JOIN patient p ON p.id = er.patient_id AND p.tenant_id = er.tenant_id
            INNER JOIN exam_catalog_item ec ON ec.id = er.exam_catalog_item_id AND ec.tenant_id = er.tenant_id
            WHERE {$query->whereSql()}
            ORDER BY er.requested_at, er.id
            LIMIT {$limit}
            SQL,
            $query->parameters(),
        );

        return array_map(
            static fn (array $row): PendingItem => new PendingItem(
                PendingItem::TYPE_EXAM_RESULT,
                (int) $row['id'],
                (string) $row['patient_name'],
                (string) $row['exam_name'],
                (new DateTimeImmutable((string) $row['requested_at']))->modify('+72 hours'),
                (int) $row['professional_system_user_id'],
                'ExamResultForm',
                ['exam_request_id' => (int) $row['id'], 'encounter_id' => (int) $row['encounter_id']],
            ),
            $rows,
        );
    }

    /**
     * @return list<PendingItem>
     */
    private function examReviews(int $unitId, int $limit): array
    {
        $query = $this->tenantQuery('xr')->andEquals('pending_review', 1, 'xr')->andEquals('system_unit_id', $unitId, 'e');
        $rows = $this->fetch(
            <<<SQL
            SELECT xr.id, xr.received_at, er.id AS exam_request_id, er.encounter_id,
                   er.professional_system_user_id, p.name AS patient_name, ec.name AS exam_name
            FROM exam_result xr
            INNER JOIN exam_request er ON er.id = xr.exam_request_id AND er.tenant_id = xr.tenant_id
            INNER JOIN encounter e ON e.id = er.encounter_id AND e.tenant_id = xr.tenant_id
            INNER JOIN patient p ON p.id = er.patient_id AND p.tenant_id = xr.tenant_id
            INNER JOIN exam_catalog_item ec ON ec.id = er.exam_catalog_item_id AND ec.tenant_id = xr.tenant_id
            WHERE {$query->whereSql()}
            ORDER BY xr.received_at, xr.id
            LIMIT {$limit}
            SQL,
            $query->parameters(),
        );

        return array_map(
            static fn (array $row): PendingItem => new PendingItem(
                PendingItem::TYPE_EXAM_REVIEW,
                (int) $row['id'],
                (string) $row['patient_name'],
                (string) $row['exam_name'],
                (new DateTimeImmutable((string) $row['received_at']))->modify('+24 hours'),
                (int) $row['professional_system_user_id'],
                'ExamResultForm',
                ['exam_request_id' => (int) $row['exam_request_id'], 'encounter_id' => (int) $row['encounter_id']],
            ),
            $rows,
        );
    }

    /**
     * Appointments linked as return (appointment_followup or
     * surgery.followup_appointment_id), still scheduled or confirmed, from
     * 7 days ago up to 2 days ahead.
     *
     * @return list<PendingItem>
     */
    private function returnAppointments(int $unitId, DateTimeImmutable $now, int $limit): array
    {
        $query = $this->tenantQuery('a')->andEquals('system_unit_id', $unitId, 'a');
        $rows = $this->fetch(
            <<<SQL
            SELECT a.id, a.scheduled_at, a.professional_system_user_id,
                   p.name AS patient_name, s.name AS service_name
            FROM appointment a
            INNER JOIN patient p ON p.id = a.patient_id AND p.tenant_id = a.tenant_id
            INNER JOIN service s ON s.id = a.service_id AND s.tenant_id = a.tenant_id
            WHERE {$query->whereSql()}
              AND a.status IN ('agendado', 'confirmado')
              AND a.scheduled_at >= :window_start AND a.scheduled_at <= :window_end
              AND (
                  EXISTS (
                      SELECT 1 FROM appointment_followup af
                      WHERE af.appointment_id = a.id AND af.tenant_id = a.tenant_id
                  )
                  OR EXISTS (
                      SELECT 1 FROM surgery sg
                      WHERE sg.followup_appointment_id = a.id AND sg.tenant_id = a.tenant_id
                  )
              )
            ORDER BY a.scheduled_at, a.id
            LIMIT {$limit}
            SQL,
            [
                ...$query->parameters(),
                ':window_start' => $now->modify('-7 days')->format(self::SQL_DATETIME),
                ':window_end' => $now->modify('+2 days')->format(self::SQL_DATETIME),
            ],
        );

        return array_map(
            static function (array $row): PendingItem {
                $scheduledAt = new DateTimeImmutable((string) $row['scheduled_at']);

                return new PendingItem(
                    PendingItem::TYPE_RETURN_APPOINTMENT,
                    (int) $row['id'],
                    (string) $row['patient_name'],
                    (string) $row['service_name'],
                    $scheduledAt,
                    (int) $row['professional_system_user_id'],
                    'AgendaView',
                    ['date' => $scheduledAt->format('Y-m-d')],
                );
            },
            $rows,
        );
    }

    /**
     * Next doses due up to today + 7 days, not yet followed by a later
     * application of the same vaccine to the same patient.
     *
     * @return list<PendingItem>
     */
    private function vaccinesDue(int $unitId, DateTimeImmutable $now, int $limit): array
    {
        $query = $this->tenantQuery('v')->andEquals('system_unit_id', $unitId, 'e');
        $rows = $this->fetch(
            <<<SQL
            SELECT v.id, v.patient_id, v.next_dose_at, v.professional_system_user_id,
                   p.name AS patient_name, vc.name AS vaccine_name
            FROM vaccination v
            INNER JOIN encounter e ON e.id = v.encounter_id AND e.tenant_id = v.tenant_id
            INNER JOIN patient p ON p.id = v.patient_id AND p.tenant_id = v.tenant_id
            INNER JOIN vaccine_catalog_item vc ON vc.id = v.vaccine_catalog_item_id AND vc.tenant_id = v.tenant_id
            WHERE {$query->whereSql()}
              AND v.next_dose_at IS NOT NULL
              AND v.next_dose_at <= :due_limit
              AND NOT EXISTS (
                  SELECT 1 FROM vaccination later
                  WHERE later.tenant_id = v.tenant_id
                    AND later.patient_id = v.patient_id
                    AND later.vaccine_catalog_item_id = v.vaccine_catalog_item_id
                    AND (later.applied_at > v.applied_at OR (later.applied_at = v.applied_at AND later.id > v.id))
              )
            ORDER BY v.next_dose_at, v.id
            LIMIT {$limit}
            SQL,
            [...$query->parameters(), ':due_limit' => $now->modify('+7 days')->format('Y-m-d')],
        );

        return array_map(
            static fn (array $row): PendingItem => new PendingItem(
                PendingItem::TYPE_VACCINE_DUE,
                (int) $row['id'],
                (string) $row['patient_name'],
                (string) $row['vaccine_name'],
                new DateTimeImmutable((string) $row['next_dose_at'] . ' 00:00:00'),
                (int) $row['professional_system_user_id'],
                'VaccinationCardView',
                ['patient_id' => (int) $row['patient_id']],
            ),
            $rows,
        );
    }

    /**
     * Pending administrations more than 30 minutes late, of admitted
     * hospitalizations of the unit.
     *
     * @return list<PendingItem>
     */
    private function lateAdministrations(int $unitId, DateTimeImmutable $now, int $limit): array
    {
        $query = $this->tenantQuery('ha')
            ->andEquals('status', 'pending', 'ha')
            ->andEquals('status', 'admitted', 'h')
            ->andEquals('system_unit_id', $unitId, 'h');
        $rows = $this->fetch(
            <<<SQL
            SELECT ha.id, ha.scheduled_at, h.id AS hospitalization_id, h.responsible_system_user_id,
                   p.name AS patient_name, o.description_text
            FROM hospitalization_administration ha
            INNER JOIN hospitalization h ON h.id = ha.hospitalization_id AND h.tenant_id = ha.tenant_id
            INNER JOIN hospitalization_order o ON o.id = ha.order_id AND o.tenant_id = ha.tenant_id
            INNER JOIN patient p ON p.id = h.patient_id AND p.tenant_id = ha.tenant_id
            WHERE {$query->whereSql()}
              AND ha.scheduled_at < :late_before
            ORDER BY ha.scheduled_at, ha.id
            LIMIT {$limit}
            SQL,
            [...$query->parameters(), ':late_before' => $now->modify('-30 minutes')->format(self::SQL_DATETIME)],
        );

        return array_map(
            static fn (array $row): PendingItem => new PendingItem(
                PendingItem::TYPE_HOSPITALIZATION_ADMINISTRATION,
                (int) $row['id'],
                (string) $row['patient_name'],
                (string) $row['description_text'],
                (new DateTimeImmutable((string) $row['scheduled_at']))->modify('+30 minutes'),
                (int) $row['responsible_system_user_id'],
                'HospitalizationView',
                ['id' => (int) $row['hospitalization_id'], 'tab' => 'administrations'],
            ),
            $rows,
        );
    }

    /**
     * @return list<PendingItem>
     */
    private function failedMessages(int $unitId, int $limit): array
    {
        $query = $this->tenantQuery('m')->andEquals('status', 'failed', 'm')->andEquals('system_unit_id', $unitId, 'm');

        return array_map(
            static fn (array $row): PendingItem => self::messageItem(
                PendingItem::TYPE_MESSAGE_FAILED,
                $row,
                new DateTimeImmutable((string) $row['failed_at']),
            ),
            $this->fetch($this->messageSql($query, 'm.failed_at', $limit), $query->parameters()),
        );
    }

    /**
     * WhatsApp messages still queued: they wait for the attendant to send
     * them by the wa.me link (or to discard them).
     *
     * @return list<PendingItem>
     */
    private function manualWhatsAppMessages(int $unitId, int $limit): array
    {
        $query = $this->tenantQuery('m')
            ->andEquals('status', 'queued', 'm')
            ->andEquals('channel', 'whatsapp', 'm')
            ->andEquals('system_unit_id', $unitId, 'm');

        return array_map(
            static fn (array $row): PendingItem => self::messageItem(
                PendingItem::TYPE_MESSAGE_WHATSAPP_MANUAL,
                $row,
                (new DateTimeImmutable((string) $row['created_at']))->modify('+4 hours'),
            ),
            $this->fetch($this->messageSql($query, 'm.created_at', $limit), $query->parameters()),
        );
    }

    private function messageSql(TenantQuery $query, string $orderColumn, int $limit): string
    {
        return <<<SQL
            SELECT m.id, m.purpose, m.failed_at, m.created_at, m.created_by_system_user_id,
                   p.name AS patient_name
            FROM communication_message m
            LEFT JOIN patient p ON p.id = m.patient_id AND p.tenant_id = m.tenant_id
            WHERE {$query->whereSql()}
            ORDER BY {$orderColumn}, m.id
            LIMIT {$limit}
            SQL;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function messageItem(string $type, array $row, DateTimeImmutable $dueAt): PendingItem
    {
        return new PendingItem(
            $type,
            (int) $row['id'],
            $row['patient_name'] !== null ? (string) $row['patient_name'] : null,
            (string) $row['purpose'],
            $dueAt,
            $row['created_by_system_user_id'] !== null ? (int) $row['created_by_system_user_id'] : null,
            'CommunicationMessageView',
            ['id' => (int) $row['id']],
        );
    }

    /**
     * @return list<PendingItem>
     */
    private function openReceivables(int $unitId, int $limit): array
    {
        $query = $this->tenantQuery('r')->andEquals('system_unit_id', $unitId, 'ea');
        $rows = $this->fetch(
            <<<SQL
            SELECT r.id, r.created_at, p.name AS patient_name
            FROM receivable r
            INNER JOIN encounter_account ea ON ea.id = r.encounter_account_id AND ea.tenant_id = r.tenant_id
            INNER JOIN patient p ON p.id = ea.patient_id AND p.tenant_id = r.tenant_id
            WHERE {$query->whereSql()}
              AND r.status IN ('open', 'partially_paid')
            ORDER BY r.created_at, r.id
            LIMIT {$limit}
            SQL,
            $query->parameters(),
        );

        return array_map(
            static fn (array $row): PendingItem => new PendingItem(
                PendingItem::TYPE_RECEIVABLE_OPEN,
                (int) $row['id'],
                (string) $row['patient_name'],
                'receivable_open',
                (new DateTimeImmutable((string) $row['created_at']))->modify('+7 days'),
                null,
                'PaymentForm',
                ['receivable_id' => (int) $row['id']],
            ),
            $rows,
        );
    }

    /**
     * Generated documents (Fase 7B) that ran out of attempts, newest failure
     * first. The subject is `<kind title> v<version>`; the deep-link only
     * carries the patient id (never the name).
     *
     * @return list<PendingItem>
     */
    private function failedDocuments(int $unitId, int $limit): array
    {
        $query = $this->tenantQuery('d')->andEquals('status', 'failed', 'd')->andEquals('system_unit_id', $unitId, 'd');
        $rows = $this->fetch(
            <<<SQL
            SELECT d.id, d.patient_id, d.kind, d.version, d.failed_at, d.requested_by_system_user_id,
                   p.name AS patient_name
            FROM generated_document d
            INNER JOIN patient p ON p.id = d.patient_id AND p.tenant_id = d.tenant_id
            WHERE {$query->whereSql()}
            ORDER BY d.failed_at DESC, d.id DESC
            LIMIT {$limit}
            SQL,
            $query->parameters(),
        );

        return array_map(
            static fn (array $row): PendingItem => new PendingItem(
                PendingItem::TYPE_DOCUMENT_FAILED,
                (int) $row['id'],
                (string) $row['patient_name'],
                DocumentKind::titleFor((string) $row['kind']) . ' v' . (int) $row['version'],
                new DateTimeImmutable((string) $row['failed_at']),
                (int) $row['requested_by_system_user_id'],
                'DocumentList',
                ['patient_id' => (int) $row['patient_id']],
            ),
            $rows,
        );
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
