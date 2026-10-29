<?php

declare(strict_types=1);

namespace CentralVet\Tests\Integration;

use CentralVet\Domain\PendingItem;
use CentralVet\Domain\ReminderCandidate;
use CentralVet\Persistence\PendingItemQuery;
use CentralVet\Persistence\ReminderSourceQuery;
use CentralVet\Tenancy\TenantContext;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\MysqlIntegrationTestCase;
use DateTimeImmutable;

/**
 * Fase 7A, T-08: the read models of the Central de Pendências
 * (PendingItemQuery) and of the automatic reminders (ReminderSourceQuery)
 * against the real schema (0012 applied in centralvet_test). Every row
 * belongs to throwaway tenants created inside the test transaction, rolled
 * back in tearDown().
 *
 * Fixture, tenant A, unit A, now = 2031-10-10 12:00:
 *   - exam request "requested" (exam_result) and one with result pending
 *     review (exam_review);
 *   - appointment 2031-10-11 10:00 "agendado" linked by appointment_followup
 *     (return), 2031-10-11 15:00 "agendado" unlinked (confirmation),
 *     2031-10-11 16:00 "confirmado" linked by surgery.followup_appointment_id
 *     (return), 2031-10-11 17:00 "confirmado" unlinked (neither);
 *   - V10 next dose 2031-10-15 (due); Raiva dose 1 next 2031-10-01 already
 *     re-applied by dose 2 (next 2032-10-02): neither Raiva row is due;
 *   - admitted hospitalization with an administration pending since 10:00;
 *   - failed e-mail, queued WhatsApp and queued e-mail (not pending);
 *   - receivable open, total 1.500,00 paid 265,44, created 2031-10-01;
 *   - generated document (prescription v1) failed at 2031-10-09 20:00
 *     (document_failed, Fase 7B T-09).
 * Tenant A, unit B: admitted hospitalization with a late administration and
 * a requested exam. Tenant B (same unit A): one row of every source.
 */
final class CommunicationReadModelIntegrationTest extends MysqlIntegrationTestCase
{
    private const NOW = '2031-10-10 12:00:00';

    private int $userId;
    private int $unitA;
    private int $unitB;
    private int $tenantA;
    private int $tenantB;

    /** @var array<string, int> */
    private array $a = [];

    /** @var array<string, int> */
    private array $b = [];

    public function setUp(): void
    {
        parent::setUp();

        $this->userId = (int) $this->pdo->query('SELECT MIN(id) FROM system_users')->fetchColumn();
        Assert::true($this->userId > 0, 'Fixture requires at least one existing system_users row');

        $units = array_map('intval', $this->pdo->query('SELECT id FROM system_unit ORDER BY id LIMIT 2')->fetchAll(\PDO::FETCH_COLUMN));
        Assert::count(2, $units, 'Fixture requires two existing system_unit rows');
        [$this->unitA, $this->unitB] = $units;

        $this->tenantA = $this->createTenant('f7a-read-a', 'F7A teste Clinica A');
        $this->tenantB = $this->createTenant('f7a-read-b', null);

        $this->a = $this->seedTenant($this->tenantA, 'A');
        $this->b = $this->seedTenant($this->tenantB, 'B');

        // Tenant A, unit B: late administration and requested exam.
        $encounterUnitB = $this->createEncounter($this->tenantA, $this->unitB, $this->a['patient']);
        $this->a['unit_b_hospitalization'] = $this->createHospitalization($this->tenantA, $this->unitB, $this->a['patient'], $encounterUnitB);
        $this->a['unit_b_administration'] = $this->createAdministration(
            $this->tenantA,
            $this->a['unit_b_hospitalization'],
            '2031-10-10 09:00:00',
        );
        $this->a['unit_b_exam_request'] = $this->createExamRequest(
            $this->tenantA,
            $encounterUnitB,
            $this->a['patient'],
            $this->a['exam_item'],
            'requested',
            '2031-10-09 07:00:00',
        );
    }

    public function testEachTypeAppearsWithTheDeepLinkOfTheDomain(): void
    {
        $items = $this->pendingFor($this->tenantA, $this->unitA);
        $now = new DateTimeImmutable(self::NOW);

        Assert::same(PendingItem::TYPES, array_values(array_unique(array_map(static fn (PendingItem $i): string => $i->type(), $items))), 'all 9 types, in the order of PendingItem::TYPES');

        $examResult = $this->single($items, PendingItem::TYPE_EXAM_RESULT);
        Assert::same($this->a['exam_request'], $examResult->sourceId());
        Assert::same('F7A teste Hemograma A', $examResult->subjectLabel());
        Assert::same('F7A teste Rex A', $examResult->patientName());
        Assert::same($this->userId, $examResult->responsibleSystemUserId());
        Assert::same('2031-10-12 08:00:00', $examResult->dueAt()->format('Y-m-d H:i:s'), 'requested_at + 72 h');
        Assert::same(
            'index.php?class=ExamResultForm&exam_request_id=' . $this->a['exam_request'] . '&encounter_id=' . $this->a['encounter'],
            $examResult->deepLinkUrl(),
        );

        $examReview = $this->single($items, PendingItem::TYPE_EXAM_REVIEW);
        Assert::same($this->a['exam_result'], $examReview->sourceId());
        Assert::same('2031-10-11 09:00:00', $examReview->dueAt()->format('Y-m-d H:i:s'), 'received_at + 24 h');
        Assert::same(
            'index.php?class=ExamResultForm&exam_request_id=' . $this->a['exam_request_reviewed'] . '&encounter_id=' . $this->a['encounter'],
            $examReview->deepLinkUrl(),
        );

        $returns = $this->ofType($items, PendingItem::TYPE_RETURN_APPOINTMENT);
        Assert::same(
            [$this->a['appointment_return'], $this->a['appointment_surgery_return']],
            array_map(static fn (PendingItem $i): int => $i->sourceId(), $returns),
            'only appointments linked as return (followup or surgery), ordered by schedule',
        );
        Assert::same('F7A teste Consulta A', $returns[0]->subjectLabel());
        Assert::same('2031-10-11 10:00:00', $returns[0]->dueAt()->format('Y-m-d H:i:s'));
        Assert::same('index.php?class=AgendaView&date=2031-10-11', $returns[0]->deepLinkUrl());

        $vaccine = $this->single($items, PendingItem::TYPE_VACCINE_DUE);
        Assert::same($this->a['vaccination_due'], $vaccine->sourceId());
        Assert::same('F7A teste V10 A', $vaccine->subjectLabel());
        Assert::same('2031-10-15 00:00:00', $vaccine->dueAt()->format('Y-m-d H:i:s'));
        Assert::same('index.php?class=VaccinationCardView&patient_id=' . $this->a['patient'], $vaccine->deepLinkUrl());

        $administration = $this->single($items, PendingItem::TYPE_HOSPITALIZATION_ADMINISTRATION);
        Assert::same($this->a['administration'], $administration->sourceId());
        Assert::same('2031-10-10 10:30:00', $administration->dueAt()->format('Y-m-d H:i:s'), 'scheduled_at + 30 min');
        Assert::same('urgent', $administration->priority($now));
        Assert::same(
            'index.php?class=HospitalizationView&id=' . $this->a['hospitalization'] . '&tab=administrations',
            $administration->deepLinkUrl(),
        );

        $failed = $this->single($items, PendingItem::TYPE_MESSAGE_FAILED);
        Assert::same($this->a['message_failed'], $failed->sourceId());
        Assert::same('appointment_confirmation', $failed->subjectLabel());
        Assert::same('2031-10-09 18:00:00', $failed->dueAt()->format('Y-m-d H:i:s'), 'failed_at');
        Assert::same('index.php?class=CommunicationMessageView&id=' . $this->a['message_failed'], $failed->deepLinkUrl());

        $manual = $this->single($items, PendingItem::TYPE_MESSAGE_WHATSAPP_MANUAL);
        Assert::same($this->a['message_whatsapp'], $manual->sourceId(), 'queued e-mail is not a manual WhatsApp pending item');
        Assert::same('2031-10-10 13:00:00', $manual->dueAt()->format('Y-m-d H:i:s'), 'created_at + 4 h');
        Assert::same('index.php?class=CommunicationMessageView&id=' . $this->a['message_whatsapp'], $manual->deepLinkUrl());

        $receivable = $this->single($items, PendingItem::TYPE_RECEIVABLE_OPEN);
        Assert::same($this->a['receivable'], $receivable->sourceId());
        Assert::same('2031-10-08 08:00:00', $receivable->dueAt()->format('Y-m-d H:i:s'), 'created_at + 7 days');
        Assert::same('index.php?class=PaymentForm&receivable_id=' . $this->a['receivable'], $receivable->deepLinkUrl());

        $document = $this->single($items, PendingItem::TYPE_DOCUMENT_FAILED);
        Assert::same($this->a['document_failed'], $document->sourceId());
        Assert::same('Receita v1', $document->subjectLabel());
        Assert::same('2031-10-09 20:00:00', $document->dueAt()->format('Y-m-d H:i:s'), 'failed_at');
        Assert::same('index.php?class=DocumentList&patient_id=' . $this->a['patient'], $document->deepLinkUrl());
    }

    public function testWhatsAppCancelledByOptOutLeavesThePendingCenter(): void
    {
        $at = new DateTimeImmutable(self::NOW);
        $cancelled = (new \CentralVet\Persistence\OutboundMessageRepository(TenantContext::authenticated($this->tenantA, $this->userId), $this->pdo))
            ->cancelQueuedForTutor($this->a['tutor'], 'whatsapp', $this->userId, 'opted_out', $at);
        Assert::same(1, $cancelled);

        Assert::count(0, $this->ofType($this->pendingFor($this->tenantA, $this->unitA), PendingItem::TYPE_MESSAGE_WHATSAPP_MANUAL), 'cancelled WhatsApp is not pending');
        Assert::count(1, $this->ofType($this->pendingFor($this->tenantB, $this->unitA), PendingItem::TYPE_MESSAGE_WHATSAPP_MANUAL), 'tenant B keeps its queued WhatsApp');
    }

    public function testUnitIsolationKeepsTheOtherUnitOut(): void
    {
        $unitA = $this->pendingFor($this->tenantA, $this->unitA);
        $administrationIds = array_map(static fn (PendingItem $i): int => $i->sourceId(), $this->ofType($unitA, PendingItem::TYPE_HOSPITALIZATION_ADMINISTRATION));
        Assert::false(in_array($this->a['unit_b_administration'], $administrationIds, true), 'late administration of unit B must not appear in unit A');
        Assert::false(
            in_array($this->a['unit_b_exam_request'], array_map(static fn (PendingItem $i): int => $i->sourceId(), $this->ofType($unitA, PendingItem::TYPE_EXAM_RESULT)), true),
            'exam of unit B must not appear in unit A',
        );

        $unitB = $this->pendingFor($this->tenantA, $this->unitB);
        Assert::same(
            [PendingItem::TYPE_EXAM_RESULT, PendingItem::TYPE_HOSPITALIZATION_ADMINISTRATION],
            array_map(static fn (PendingItem $i): string => $i->type(), $unitB),
        );
        Assert::same($this->a['unit_b_administration'], $unitB[1]->sourceId());
    }

    public function testNoItemOfAnotherTenant(): void
    {
        $itemsA = $this->pendingFor($this->tenantA, $this->unitA);
        $itemsB = $this->pendingFor($this->tenantB, $this->unitA);

        Assert::count(10, $itemsA, 'tenant A: 9 types, 2 returns');
        Assert::count(10, $itemsB, 'tenant B has its own 10 items');

        $keysA = array_map(static fn (PendingItem $i): string => $i->type() . ':' . $i->sourceId(), $itemsA);
        $keysB = array_map(static fn (PendingItem $i): string => $i->type() . ':' . $i->sourceId(), $itemsB);
        Assert::same([], array_values(array_intersect($keysA, $keysB)), 'no pending item is shared between tenants');
        Assert::same($this->b['exam_request'], $this->single($itemsB, PendingItem::TYPE_EXAM_RESULT)->sourceId());

        $remindersA = $this->reminderQuery($this->tenantA);
        $sourceIds = array_map(
            static fn (ReminderCandidate $c): string => $c->sourceType() . ':' . $c->sourceId(),
            [
                ...$remindersA->appointmentsBetween(new DateTimeImmutable('2031-10-11 00:00:00'), new DateTimeImmutable('2031-10-12 00:00:00')),
                ...$remindersA->vaccinesDueBetween(new DateTimeImmutable('2031-10-10'), new DateTimeImmutable('2031-10-17')),
                ...$remindersA->openReceivablesCreatedBefore(new DateTimeImmutable('2031-10-03 12:00:00')),
            ],
        );
        foreach (['appointment' => 'appointment_return', 'vaccination' => 'vaccination_due', 'receivable' => 'receivable'] as $type => $key) {
            Assert::false(in_array($type . ':' . $this->b[$key], $sourceIds, true), "tenant B {$type} must not reach tenant A reminders");
            Assert::true(in_array($type . ':' . $this->a[$key], $sourceIds, true), "tenant A {$type} must be a reminder");
        }
    }

    public function testReappliedVaccineIsNotDue(): void
    {
        $vaccineIds = array_map(
            static fn (PendingItem $i): int => $i->sourceId(),
            $this->ofType($this->pendingFor($this->tenantA, $this->unitA), PendingItem::TYPE_VACCINE_DUE),
        );
        Assert::false(in_array($this->a['vaccination_reapplied'], $vaccineIds, true), 'dose already re-applied is not pending');

        $candidates = $this->reminderQuery($this->tenantA)->vaccinesDueBetween(
            new DateTimeImmutable('2031-09-01'),
            new DateTimeImmutable('2031-10-17'),
        );
        Assert::same([$this->a['vaccination_due']], array_map(static fn (ReminderCandidate $c): int => $c->sourceId(), $candidates), 'only the V10 dose; Raiva was re-applied');

        $candidate = $candidates[0];
        Assert::same('vaccine_due', $candidate->purpose());
        Assert::same('vaccination', $candidate->sourceType());
        Assert::same($this->unitA, $candidate->systemUnitId());
        Assert::same($this->a['tutor'], $candidate->tutorId());
        Assert::same($this->a['patient'], $candidate->patientId());
        Assert::same('F7A teste V10 A', $candidate->variables()['vaccine_name']);
        Assert::same('15/10/2031', $candidate->variables()['due_date']);
    }

    public function testAppointmentsAreReturnRemindersOrConfirmations(): void
    {
        $candidates = $this->reminderQuery($this->tenantA)->appointmentsBetween(
            new DateTimeImmutable('2031-10-11 00:00:00'),
            new DateTimeImmutable('2031-10-12 00:00:00'),
        );

        $bySource = [];
        foreach ($candidates as $candidate) {
            $bySource[$candidate->sourceId()] = $candidate->purpose();
        }

        Assert::same([
            $this->a['appointment_return'] => 'return_reminder',
            $this->a['appointment_confirmation'] => 'appointment_confirmation',
            $this->a['appointment_surgery_return'] => 'return_reminder',
        ], $bySource, 'confirmed unlinked appointment is neither confirmation nor return');

        $confirmation = $candidates[1];
        Assert::same('appointment', $confirmation->sourceType());
        Assert::same($this->unitA, $confirmation->systemUnitId());
        Assert::same($this->a['tutor'], $confirmation->tutorId());
        Assert::same($this->a['patient'], $confirmation->patientId());
        Assert::same('f7a.teste+a@example.invalid', $confirmation->tutorEmail());
        Assert::same('85999990001', $confirmation->tutorPhone());
        Assert::same('11/10/2031', $confirmation->variables()['appointment_date']);
        Assert::same('15:00', $confirmation->variables()['appointment_time']);
        Assert::same('F7A teste Rex A', $confirmation->variables()['patient_name']);
        Assert::same('F7A teste Clinica A', $confirmation->variables()['clinic_name'], 'trade_name when present');
        Assert::notNull($confirmation->variables()['unit_name'] ?? null);

        $other = $this->reminderQuery($this->tenantB)->appointmentsBetween(
            new DateTimeImmutable('2031-10-11 00:00:00'),
            new DateTimeImmutable('2031-10-12 00:00:00'),
        );
        Assert::stringContains('F7A teste tenant', $other[0]->variables()['clinic_name'], 'legal_name without trade_name');
    }

    public function testOpenReceivablesCarryTheAmountDue(): void
    {
        $query = $this->reminderQuery($this->tenantA);

        Assert::same([], $query->openReceivablesCreatedBefore(new DateTimeImmutable('2031-10-01 08:00:00')), 'created exactly at the limit is not before it');

        $candidates = $query->openReceivablesCreatedBefore(new DateTimeImmutable('2031-10-03 12:00:00'));
        Assert::count(1, $candidates);
        Assert::same('receivable_open', $candidates[0]->purpose());
        Assert::same('receivable', $candidates[0]->sourceType());
        Assert::same($this->a['receivable'], $candidates[0]->sourceId());
        Assert::same($this->unitA, $candidates[0]->systemUnitId());
        Assert::same('R$ 1.234,56', $candidates[0]->variables()['amount_due']);
    }

    public function testLimitPerTypeIsApplied(): void
    {
        $items = $this->pendingFor($this->tenantA, $this->unitA, 1);

        Assert::count(9, $items, 'one item per type');
        Assert::same($this->a['appointment_return'], $this->single($items, PendingItem::TYPE_RETURN_APPOINTMENT)->sourceId());
        Assert::same([], $this->pendingFor($this->tenantA, $this->unitA, 0));
    }

    /**
     * @return array<string, int>
     */
    private function seedTenant(int $tenantId, string $suffix): array
    {
        $ids = [];
        $ids['tutor'] = $this->insert('tutor', [
            'tenant_id' => $tenantId,
            'public_id' => $this->uuid(),
            'full_name' => 'F7A teste Tutor ' . $suffix,
            'phone' => $suffix === 'A' ? '85999990001' : '85999990002',
            'email' => 'f7a.teste+' . strtolower($suffix) . '@example.invalid',
        ]);
        $ids['patient'] = $this->insert('patient', [
            'tenant_id' => $tenantId,
            'tutor_id' => $ids['tutor'],
            'name' => 'F7A teste Rex ' . $suffix,
            'species' => 'canino',
        ]);
        $ids['encounter'] = $this->createEncounter($tenantId, $this->unitA, $ids['patient']);

        $ids['exam_item'] = $this->insert('exam_catalog_item', [
            'tenant_id' => $tenantId,
            'name' => 'F7A teste Hemograma ' . $suffix,
            'price_cents' => 5000,
        ]);
        $ids['exam_request'] = $this->createExamRequest($tenantId, $ids['encounter'], $ids['patient'], $ids['exam_item'], 'requested', '2031-10-09 08:00:00');
        $ids['exam_request_reviewed'] = $this->createExamRequest($tenantId, $ids['encounter'], $ids['patient'], $ids['exam_item'], 'result_available', '2031-10-08 08:00:00');
        $ids['exam_result'] = $this->insert('exam_result', [
            'tenant_id' => $tenantId,
            'exam_request_id' => $ids['exam_request_reviewed'],
            'structured_result_text' => 'F7A teste resultado',
            'pending_review' => 1,
            'received_at' => '2031-10-10 09:00:00',
        ]);

        $service = $this->insert('service', [
            'tenant_id' => $tenantId,
            'name' => 'F7A teste Consulta ' . $suffix,
            'duration_minutes' => 30,
            'price_cents' => 10000,
        ]);
        $ids['appointment_return'] = $this->createAppointment($tenantId, $ids['patient'], $service, '2031-10-11 10:00:00', 'agendado');
        $ids['appointment_confirmation'] = $this->createAppointment($tenantId, $ids['patient'], $service, '2031-10-11 15:00:00', 'agendado');
        $ids['appointment_surgery_return'] = $this->createAppointment($tenantId, $ids['patient'], $service, '2031-10-11 16:00:00', 'confirmado');
        $ids['appointment_confirmed'] = $this->createAppointment($tenantId, $ids['patient'], $service, '2031-10-11 17:00:00', 'confirmado');
        $this->insert('appointment_followup', [
            'tenant_id' => $tenantId,
            'appointment_id' => $ids['appointment_return'],
            'encounter_id' => $ids['encounter'],
            'created_by_system_user_id' => $this->userId,
        ]);
        $room = $this->insert('surgery_room', [
            'tenant_id' => $tenantId,
            'system_unit_id' => $this->unitA,
            'code' => 'F7A-' . $suffix,
            'name' => 'F7A teste Sala ' . $suffix,
        ]);
        $procedure = $this->insert('procedure_catalog_item', [
            'tenant_id' => $tenantId,
            'name' => 'F7A teste Orquiectomia ' . $suffix,
            'price_cents' => 45000,
        ]);
        $this->insert('surgery', [
            'tenant_id' => $tenantId,
            'system_unit_id' => $this->unitA,
            'patient_id' => $ids['patient'],
            'encounter_id' => $ids['encounter'],
            'room_id' => $room,
            'procedure_catalog_item_id' => $procedure,
            'procedure_name' => 'F7A teste Orquiectomia ' . $suffix,
            'procedure_price_cents' => 45000,
            'surgeon_system_user_id' => $this->userId,
            'scheduled_by_system_user_id' => $this->userId,
            'scheduled_start_at' => '2031-10-01 08:00:00',
            'scheduled_end_at' => '2031-10-01 09:00:00',
            'status' => 'scheduled',
            'followup_appointment_id' => $ids['appointment_surgery_return'],
        ]);

        $v10 = $this->insert('vaccine_catalog_item', ['tenant_id' => $tenantId, 'name' => 'F7A teste V10 ' . $suffix]);
        $raiva = $this->insert('vaccine_catalog_item', ['tenant_id' => $tenantId, 'name' => 'F7A teste Raiva ' . $suffix]);
        $ids['vaccination_due'] = $this->createVaccination($tenantId, $ids['encounter'], $ids['patient'], $v10, 1, '2031-09-15 10:00:00', '2031-10-15');
        $ids['vaccination_reapplied'] = $this->createVaccination($tenantId, $ids['encounter'], $ids['patient'], $raiva, 1, '2030-10-01 10:00:00', '2031-10-01');
        $this->createVaccination($tenantId, $ids['encounter'], $ids['patient'], $raiva, 2, '2031-10-02 10:00:00', '2032-10-02');

        $ids['hospitalization'] = $this->createHospitalization($tenantId, $this->unitA, $ids['patient'], $ids['encounter']);
        $ids['administration'] = $this->createAdministration($tenantId, $ids['hospitalization'], '2031-10-10 10:00:00');

        $ids['message_failed'] = $this->insert('communication_message', [
            'tenant_id' => $tenantId,
            'system_unit_id' => $this->unitA,
            'tutor_id' => $ids['tutor'],
            'patient_id' => $ids['patient'],
            'purpose' => 'appointment_confirmation',
            'channel' => 'email',
            'origin' => 'automation',
            'legal_basis' => 'legitimate_interest',
            'recipient' => 'f7a.teste@example.invalid',
            'subject' => 'F7A teste',
            'body_text' => 'F7A teste',
            'status' => 'failed',
            'last_error_code' => 'smtp_error',
            'failed_at' => '2031-10-09 18:00:00',
            'created_at' => '2031-10-09 17:00:00',
        ]);
        $ids['message_whatsapp'] = $this->insert('communication_message', [
            'tenant_id' => $tenantId,
            'system_unit_id' => $this->unitA,
            'tutor_id' => $ids['tutor'],
            'patient_id' => $ids['patient'],
            'purpose' => 'return_reminder',
            'channel' => 'whatsapp',
            'origin' => 'automation',
            'legal_basis' => 'legitimate_interest',
            'recipient' => '5585999990001',
            'body_text' => 'F7A teste',
            'status' => 'queued',
            'created_at' => '2031-10-10 09:00:00',
        ]);
        $this->insert('communication_message', [
            'tenant_id' => $tenantId,
            'system_unit_id' => $this->unitA,
            'tutor_id' => $ids['tutor'],
            'purpose' => 'custom',
            'channel' => 'email',
            'origin' => 'manual',
            'legal_basis' => 'consent',
            'recipient' => 'f7a.teste@example.invalid',
            'subject' => 'F7A teste',
            'body_text' => 'F7A teste',
            'status' => 'queued',
            'created_by_system_user_id' => $this->userId,
        ]);

        $account = $this->insert('encounter_account', [
            'tenant_id' => $tenantId,
            'encounter_id' => $ids['encounter'],
            'patient_id' => $ids['patient'],
            'tutor_id' => $ids['tutor'],
            'system_unit_id' => $this->unitA,
            'subtotal_cents' => 150000,
            'total_cents' => 150000,
        ]);
        $ids['receivable'] = $this->insert('receivable', [
            'tenant_id' => $tenantId,
            'encounter_account_id' => $account,
            'tutor_id' => $ids['tutor'],
            'total_cents' => 150000,
            'paid_cents' => 26544,
            'status' => 'partially_paid',
            'created_at' => '2031-10-01 08:00:00',
        ]);

        $ids['document_failed'] = $this->insert('generated_document', [
            'tenant_id' => $tenantId,
            'system_unit_id' => $this->unitA,
            'patient_id' => $ids['patient'],
            'tutor_id' => $ids['tutor'],
            'kind' => 'prescription',
            'source_type' => 'prescription',
            'source_id' => $ids['patient'],
            'version' => 1,
            'title' => 'F7A teste documento',
            'status' => 'failed',
            'attempt_count' => 3,
            'last_error_code' => 'render_failed',
            'failed_at' => '2031-10-09 20:00:00',
            'requested_by_system_user_id' => $this->userId,
        ]);

        return $ids;
    }

    /**
     * @return list<PendingItem>
     */
    private function pendingFor(int $tenantId, int $unitId, int $limit = 50): array
    {
        return (new PendingItemQuery(TenantContext::authenticated($tenantId, $this->userId), $this->pdo))
            ->listForUnit($unitId, new DateTimeImmutable(self::NOW), $limit);
    }

    private function reminderQuery(int $tenantId): ReminderSourceQuery
    {
        return new ReminderSourceQuery(TenantContext::authenticated($tenantId, $this->userId), $this->pdo);
    }

    /**
     * @param list<PendingItem> $items
     * @return list<PendingItem>
     */
    private function ofType(array $items, string $type): array
    {
        return array_values(array_filter($items, static fn (PendingItem $i): bool => $i->type() === $type));
    }

    /**
     * @param list<PendingItem> $items
     */
    private function single(array $items, string $type): PendingItem
    {
        $matches = $this->ofType($items, $type);
        Assert::count(1, $matches, "exactly one {$type} item");

        return $matches[0];
    }

    private function createTenant(string $slugPrefix, ?string $tradeName): int
    {
        $slug = $slugPrefix . '-' . bin2hex(random_bytes(4));

        return $this->insert('tenant', [
            'public_id' => $this->uuid(),
            'slug' => $slug,
            'legal_name' => 'F7A teste tenant (' . $slug . ')',
            'trade_name' => $tradeName,
            'status' => 'active',
        ]);
    }

    private function createEncounter(int $tenantId, int $unitId, int $patientId): int
    {
        return $this->insert('encounter', [
            'tenant_id' => $tenantId,
            'system_unit_id' => $unitId,
            'patient_id' => $patientId,
            'professional_system_user_id' => $this->userId,
            'started_at' => '2031-10-01 07:00:00',
        ]);
    }

    private function createExamRequest(int $tenantId, int $encounterId, int $patientId, int $itemId, string $status, string $requestedAt): int
    {
        return $this->insert('exam_request', [
            'tenant_id' => $tenantId,
            'encounter_id' => $encounterId,
            'patient_id' => $patientId,
            'exam_catalog_item_id' => $itemId,
            'professional_system_user_id' => $this->userId,
            'status' => $status,
            'requested_at' => $requestedAt,
        ]);
    }

    private function createAppointment(int $tenantId, int $patientId, int $serviceId, string $scheduledAt, string $status): int
    {
        return $this->insert('appointment', [
            'tenant_id' => $tenantId,
            'system_unit_id' => $this->unitA,
            'patient_id' => $patientId,
            'service_id' => $serviceId,
            'professional_system_user_id' => $this->userId,
            'scheduled_at' => $scheduledAt,
            'status' => $status,
        ]);
    }

    private function createVaccination(int $tenantId, int $encounterId, int $patientId, int $itemId, int $dose, string $appliedAt, string $nextDoseAt): int
    {
        return $this->insert('vaccination', [
            'tenant_id' => $tenantId,
            'encounter_id' => $encounterId,
            'patient_id' => $patientId,
            'vaccine_catalog_item_id' => $itemId,
            'dose_number' => $dose,
            'professional_system_user_id' => $this->userId,
            'applied_at' => $appliedAt,
            'next_dose_at' => $nextDoseAt,
        ]);
    }

    private function createHospitalization(int $tenantId, int $unitId, int $patientId, int $encounterId): int
    {
        $bed = $this->insert('bed', [
            'tenant_id' => $tenantId,
            'system_unit_id' => $unitId,
            'code' => 'F7A-' . bin2hex(random_bytes(3)),
            'name' => 'F7A teste Leito',
        ]);

        return $this->insert('hospitalization', [
            'tenant_id' => $tenantId,
            'system_unit_id' => $unitId,
            'patient_id' => $patientId,
            'encounter_id' => $encounterId,
            'bed_id' => $bed,
            'responsible_system_user_id' => $this->userId,
            'admitted_by_system_user_id' => $this->userId,
            'reason_text' => 'F7A teste',
            'daily_rate_cents' => 10000,
            'admitted_at' => '2031-10-09 08:00:00',
        ]);
    }

    private function createAdministration(int $tenantId, int $hospitalizationId, string $scheduledAt): int
    {
        $order = $this->insert('hospitalization_order', [
            'tenant_id' => $tenantId,
            'hospitalization_id' => $hospitalizationId,
            'order_type' => 'medication',
            'description_text' => 'F7A teste Dipirona',
            'dose_text' => '1 ml',
            'route' => 'oral',
            'frequency_hours' => 8,
            'starts_at' => '2031-10-09 08:00:00',
            'ends_at' => '2031-10-12 08:00:00',
            'prescribed_by_system_user_id' => $this->userId,
        ]);

        return $this->insert('hospitalization_administration', [
            'tenant_id' => $tenantId,
            'hospitalization_id' => $hospitalizationId,
            'order_id' => $order,
            'scheduled_at' => $scheduledAt,
            'status' => 'pending',
        ]);
    }

    /**
     * @param array<string, int|string|null> $row
     */
    private function insert(string $table, array $row): int
    {
        $columns = array_keys($row);
        $statement = $this->pdo->prepare(sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $table,
            implode(', ', $columns),
            implode(', ', array_map(static fn (string $c): string => ':' . $c, $columns)),
        ));
        $statement->execute($row);

        return (int) $this->pdo->lastInsertId();
    }

    private function uuid(): string
    {
        return (string) $this->pdo->query('SELECT UUID()')->fetchColumn();
    }
}
