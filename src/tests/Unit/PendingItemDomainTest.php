<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Domain\PendingItem;
use CentralVet\Domain\PendingItemPriority;
use CentralVet\Domain\ReminderCandidate;
use CentralVet\Tests\Support\Assert;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Fase 7A, T-03: Central de Pendências rules (priority, status, deep-link)
 * and the reminder candidate value object.
 */
final class PendingItemDomainTest
{
    private static function at(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value);
    }

    private static function messageOf(callable $callback): ?string
    {
        try {
            $callback();
        } catch (\Throwable $e) {
            return $e->getMessage();
        }

        return null;
    }

    /**
     * @param array<string, int|string> $params
     */
    private static function item(
        string $type = PendingItem::TYPE_EXAM_RESULT,
        string $dueAt = '2026-10-06 12:00:00',
        string $class = 'ExamResultForm',
        array $params = ['exam_request_id' => 1],
    ): PendingItem {
        return new PendingItem($type, 1, 'Rex', 'Hemograma', self::at($dueAt), 7, $class, $params);
    }

    public function testTypeAndStatusConstants(): void
    {
        Assert::same([
            'exam_result',
            'exam_review',
            'return_appointment',
            'vaccine_due',
            'hospitalization_administration',
            'message_failed',
            'message_whatsapp_manual',
            'receivable_open',
        ], PendingItem::TYPES);
        Assert::same('overdue', PendingItem::STATUS_OVERDUE);
        Assert::same('open', PendingItem::STATUS_OPEN);
    }

    public function testClassifyUrgentForHospitalizationAdministration(): void
    {
        $now = self::at('2026-10-06 10:00:00');
        Assert::same(
            PendingItemPriority::URGENT,
            PendingItemPriority::classify(PendingItem::TYPE_HOSPITALIZATION_ADMINISTRATION, self::at('2026-10-10 10:00:00'), $now),
        );
    }

    public function testClassifyHighWhenOverdue(): void
    {
        $now = self::at('2026-10-06 10:00:00');
        Assert::same(
            PendingItemPriority::HIGH,
            PendingItemPriority::classify(PendingItem::TYPE_EXAM_RESULT, self::at('2026-10-06 09:59:59'), $now),
        );
    }

    public function testClassifyNormalWithin24Hours(): void
    {
        $now = self::at('2026-10-06 10:00:00');
        Assert::same('normal', PendingItemPriority::classify(PendingItem::TYPE_EXAM_RESULT, $now, $now));
        Assert::same(
            PendingItemPriority::NORMAL,
            PendingItemPriority::classify(PendingItem::TYPE_VACCINE_DUE, self::at('2026-10-07 10:00:00'), $now),
        );
    }

    public function testClassifyLowAfter24Hours(): void
    {
        $now = self::at('2026-10-06 10:00:00');
        Assert::same(
            PendingItemPriority::LOW,
            PendingItemPriority::classify(PendingItem::TYPE_RECEIVABLE_OPEN, self::at('2026-10-07 10:00:01'), $now),
        );
    }

    public function testRankOrdersPriorities(): void
    {
        Assert::same(0, PendingItemPriority::rank('urgent'));
        Assert::same(1, PendingItemPriority::rank('high'));
        Assert::same(2, PendingItemPriority::rank('normal'));
        Assert::same(3, PendingItemPriority::rank('low'));
    }

    public function testItemPriorityDelegatesToClassify(): void
    {
        $item = self::item(dueAt: '2026-10-06 12:00:00');
        Assert::same('high', $item->priority(self::at('2026-10-06 12:00:01')));
        Assert::same('low', $item->priority(self::at('2026-10-04 12:00:00')));
    }

    public function testStatusOverdueAfterDueAt(): void
    {
        Assert::same('overdue', self::item()->status(self::at('2026-10-06 12:00:01')));
    }

    public function testStatusOpenUntilDueAt(): void
    {
        Assert::same('open', self::item()->status(self::at('2026-10-06 12:00:00')));
    }

    public function testGetters(): void
    {
        $item = self::item();
        Assert::same('exam_result', $item->type());
        Assert::same(1, $item->sourceId());
        Assert::same('Rex', $item->patientName());
        Assert::same('Hemograma', $item->subjectLabel());
        Assert::same('2026-10-06 12:00:00', $item->dueAt()->format('Y-m-d H:i:s'));
        Assert::same(7, $item->responsibleSystemUserId());
    }

    public function testDeepLinkHospitalizationView(): void
    {
        $item = self::item(
            PendingItem::TYPE_HOSPITALIZATION_ADMINISTRATION,
            class: 'HospitalizationView',
            params: ['id' => 5, 'tab' => 'administrations'],
        );
        Assert::same('index.php?class=HospitalizationView&id=5&tab=administrations', $item->deepLinkUrl());
    }

    public function testDeepLinkExamResultForm(): void
    {
        Assert::same(
            'index.php?class=ExamResultForm&exam_request_id=12&encounter_id=4',
            self::item(class: 'ExamResultForm', params: ['exam_request_id' => 12, 'encounter_id' => '4'])->deepLinkUrl(),
        );
    }

    public function testDeepLinkAgendaView(): void
    {
        Assert::same(
            'index.php?class=AgendaView&date=2026-10-07',
            self::item(class: 'AgendaView', params: ['date' => '2026-10-07'])->deepLinkUrl(),
        );
    }

    public function testDeepLinkVaccinationCardView(): void
    {
        Assert::same(
            'index.php?class=VaccinationCardView&patient_id=3',
            self::item(class: 'VaccinationCardView', params: ['patient_id' => 3])->deepLinkUrl(),
        );
    }

    public function testDeepLinkCommunicationMessageView(): void
    {
        Assert::same(
            'index.php?class=CommunicationMessageView&id=44',
            self::item(class: 'CommunicationMessageView', params: ['id' => '44'])->deepLinkUrl(),
        );
    }

    public function testDeepLinkPaymentForm(): void
    {
        Assert::same(
            'index.php?class=PaymentForm&receivable_id=8',
            self::item(class: 'PaymentForm', params: ['receivable_id' => 8])->deepLinkUrl(),
        );
    }

    public function testDeepLinkRejectsFreeText(): void
    {
        Assert::throws(InvalidArgumentException::class, fn () => self::item(params: ['q' => 'joao@example.invalid'])->deepLinkUrl());
        Assert::same(
            'Invalid deep-link parameter q',
            self::messageOf(fn () => self::item(params: ['q' => 'Rex da Silva'])->deepLinkUrl()),
        );
        Assert::throws(InvalidArgumentException::class, fn () => self::item(params: ['exam_request_id' => 0])->deepLinkUrl());
        Assert::throws(InvalidArgumentException::class, fn () => self::item(params: ['exam_request_id' => -3])->deepLinkUrl());
        Assert::throws(InvalidArgumentException::class, fn () => self::item(class: 'AgendaView', params: ['date' => '2026-02-30'])->deepLinkUrl());
        Assert::throws(InvalidArgumentException::class, fn () => self::item(class: 'AgendaView', params: ['date' => '2026-10-07 10:00'])->deepLinkUrl());
    }

    public function testDeepLinkRejectsKeysOutsideTheAllowListEvenWithNumericValues(): void
    {
        foreach (['phone' => '5585999990000', 'document' => '12345678909', 'cpf' => 12345678909, 'birth_date' => '1990-05-01'] as $key => $value) {
            Assert::same(
                'Invalid deep-link parameter ' . $key,
                self::messageOf(fn () => self::item(params: ['exam_request_id' => 1, $key => $value])->deepLinkUrl()),
                $key,
            );
        }

        // A key allowed for another class is still refused.
        Assert::throws(InvalidArgumentException::class, fn () => self::item(class: 'AgendaView', params: ['patient_id' => 3])->deepLinkUrl());
        Assert::throws(InvalidArgumentException::class, fn () => self::item(class: 'PaymentForm', params: ['id' => 3])->deepLinkUrl());
        // Value kind is fixed per key: no date in an id, no id in a date, only the literal in tab.
        Assert::throws(InvalidArgumentException::class, fn () => self::item(class: 'VaccinationCardView', params: ['patient_id' => '1990-05-01'])->deepLinkUrl());
        Assert::throws(InvalidArgumentException::class, fn () => self::item(class: 'AgendaView', params: ['date' => 20261007])->deepLinkUrl());
        Assert::throws(InvalidArgumentException::class, fn () => self::item(class: 'HospitalizationView', params: ['id' => 5, 'tab' => 5])->deepLinkUrl());
        Assert::throws(InvalidArgumentException::class, fn () => self::item(class: 'HospitalizationView', params: ['id' => 'administrations'])->deepLinkUrl());
    }

    public function testDeepLinkAllowListMatchesTheDestinations(): void
    {
        Assert::same([
            'ExamResultForm' => ['exam_request_id', 'encounter_id'],
            'AgendaView' => ['date'],
            'VaccinationCardView' => ['patient_id'],
            'HospitalizationView' => ['id', 'tab'],
            'CommunicationMessageView' => ['id'],
            'PaymentForm' => ['receivable_id'],
        ], PendingItem::DEEP_LINK_KEYS);
    }

    public function testDeepLinkRejectsUnknownClassAndType(): void
    {
        Assert::throws(InvalidArgumentException::class, fn () => self::item(class: 'SystemUserForm'));
        Assert::throws(InvalidArgumentException::class, fn () => self::item(type: 'unknown'));
    }

    public function testReminderCandidateGetters(): void
    {
        $candidate = new ReminderCandidate(
            'appointment_confirmation',
            'appointment',
            15,
            2,
            30,
            null,
            null,
            '5585999990000',
            ['appointment_date' => '07/10/2026', 'appointment_time' => '09:30'],
        );

        Assert::same('appointment_confirmation', $candidate->purpose());
        Assert::same('appointment', $candidate->sourceType());
        Assert::same(15, $candidate->sourceId());
        Assert::same(2, $candidate->systemUnitId());
        Assert::same(30, $candidate->tutorId());
        Assert::null($candidate->patientId());
        Assert::null($candidate->tutorEmail());
        Assert::same('5585999990000', $candidate->tutorPhone());
        Assert::same(['appointment_date' => '07/10/2026', 'appointment_time' => '09:30'], $candidate->variables());

        $withEmail = new ReminderCandidate('vaccine_reminder', 'vaccination', 1, 2, 3, 4, 'f7a.teste@example.invalid', '5585999990000', []);
        $dump = print_r($withEmail, true);
        Assert::false(str_contains($dump, 'f7a.teste@example.invalid'), 'print_r must not expose the e-mail');
        Assert::false(str_contains($dump, '5585999990000'), 'print_r must not expose the phone');
    }
}
