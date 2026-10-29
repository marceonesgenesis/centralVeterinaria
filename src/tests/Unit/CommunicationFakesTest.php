<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Communication\MessageDeliveryFailed;
use CentralVet\Communication\OutgoingMessage;
use CentralVet\Domain\CommunicationChannel;
use CentralVet\Domain\CommunicationPreference;
use CentralVet\Domain\MessagePurpose;
use CentralVet\Domain\MessageTemplate;
use CentralVet\Domain\OutboundMessage;
use CentralVet\Domain\PendingItem;
use CentralVet\Domain\ReminderCandidate;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\FakeAppointmentFollowupRepository;
use CentralVet\Tests\Support\FakeCommunicationPreferenceRepository;
use CentralVet\Tests\Support\FakeEmailProvider;
use CentralVet\Tests\Support\FakeMessageTemplateRepository;
use CentralVet\Tests\Support\FakeOutboundMessageRepository;
use CentralVet\Tests\Support\FakePendingItemQuery;
use CentralVet\Tests\Support\FakeQueue;
use CentralVet\Tests\Support\FakeReminderSourceQuery;
use DateTimeImmutable;

/**
 * Communication test doubles (T-06): the semantics the services of the
 * Fase 7A rely on (dedupe, conditional transitions, recorded calls).
 */
final class CommunicationFakesTest
{
    private const TENANT_ID = 1;
    private const UNIT_ID = 5;
    private const USER_ID = 3;

    private static function message(string $channel, ?string $dedupeKey, int $tenantId = self::TENANT_ID): OutboundMessage
    {
        return OutboundMessage::compose(
            tenantId: $tenantId,
            systemUnitId: self::UNIT_ID,
            tutorId: 7,
            patientId: null,
            templateId: null,
            purpose: MessagePurpose::APPOINTMENT_CONFIRMATION,
            channel: $channel,
            origin: OutboundMessage::ORIGIN_AUTOMATION,
            legalBasis: MessagePurpose::LEGAL_BASIS_LEGITIMATE_INTEREST,
            sourceType: OutboundMessage::SOURCE_APPOINTMENT,
            sourceId: 11,
            dedupeKey: $dedupeKey,
            recipient: $channel === CommunicationChannel::EMAIL ? 'f7a.teste@example.invalid' : '5585999990000',
            subject: $channel === CommunicationChannel::EMAIL ? 'F7A teste' : null,
            bodyText: 'F7A teste',
            createdBySystemUserId: null,
        );
    }

    public function testInsertIfNewReturnsNullForTheSameDedupeKey(): void
    {
        $repo = new FakeOutboundMessageRepository(self::TENANT_ID);
        $key = OutboundMessage::buildDedupeKey(MessagePurpose::APPOINTMENT_CONFIRMATION, OutboundMessage::SOURCE_APPOINTMENT, 11, CommunicationChannel::EMAIL);

        $first = $repo->insertIfNew(self::message(CommunicationChannel::EMAIL, $key));
        Assert::notNull($first);
        Assert::same(1, $first->id());

        Assert::null($repo->insertIfNew(self::message(CommunicationChannel::EMAIL, $key)));
        Assert::notNull($repo->insertIfNew(self::message(CommunicationChannel::EMAIL, null)));
        Assert::notNull($repo->insertIfNew(self::message(CommunicationChannel::EMAIL, null)), 'null dedupe key never collides');
        Assert::count(3, $repo->all());
    }

    public function testDedupeIsPerTenant(): void
    {
        $repo = new FakeOutboundMessageRepository(self::TENANT_ID);
        $other = self::message(CommunicationChannel::EMAIL, 'k', 2);
        $repo->seed($other);

        Assert::notNull($repo->insertIfNew(self::message(CommunicationChannel::EMAIL, 'k')));
        Assert::null($repo->findById((int) $other->id()), 'other tenant is invisible');
    }

    public function testFindByIdReturnsAFreshCopy(): void
    {
        $repo = new FakeOutboundMessageRepository(self::TENANT_ID);
        $id = (int) $repo->insertIfNew(self::message(CommunicationChannel::EMAIL, null))->id();
        $before = $repo->findById($id);

        Assert::true($repo->claim($id, new DateTimeImmutable('2026-10-06 10:00:00')));
        Assert::null($before->claimedAt());
        Assert::notNull($repo->findById($id)->claimedAt());
        Assert::false($before === $repo->findById($id));
    }

    public function testMarkManualSentOnEmailReturnsFalse(): void
    {
        $repo = new FakeOutboundMessageRepository(self::TENANT_ID);
        $email = (int) $repo->insertIfNew(self::message(CommunicationChannel::EMAIL, null))->id();
        $whatsapp = (int) $repo->insertIfNew(self::message(CommunicationChannel::WHATSAPP, null))->id();
        $now = new DateTimeImmutable('2026-10-06 10:00:00');

        Assert::false($repo->markManualSent($email, self::USER_ID, $now));
        Assert::same(OutboundMessage::STATUS_QUEUED, $repo->findById($email)->status());
        Assert::true($repo->markManualSent($whatsapp, self::USER_ID, $now));
        Assert::same(OutboundMessage::STATUS_MANUAL, $repo->findById($whatsapp)->status());
        Assert::same(self::USER_ID, $repo->findById($whatsapp)->manualSentBySystemUserId());
        Assert::false($repo->markManualSent($whatsapp, self::USER_ID, $now), 'already manual');
    }

    public function testRequeueOnlyChangesFailed(): void
    {
        $repo = new FakeOutboundMessageRepository(self::TENANT_ID);
        $id = (int) $repo->insertIfNew(self::message(CommunicationChannel::EMAIL, null))->id();
        $now = new DateTimeImmutable('2026-10-06 10:00:00');

        Assert::false($repo->requeue($id), 'queued cannot be requeued');
        Assert::true($repo->claim($id, $now));
        Assert::true($repo->markFailed($id, 'smtp_connect', $now));
        $failed = $repo->findById($id);
        Assert::same(OutboundMessage::STATUS_FAILED, $failed->status());
        Assert::same(1, $failed->attemptCount());
        Assert::same('smtp_connect', $failed->lastErrorCode());

        Assert::true($repo->requeue($id));
        $requeued = $repo->findById($id);
        Assert::same(OutboundMessage::STATUS_QUEUED, $requeued->status());
        Assert::null($requeued->failedAt());
        Assert::null($requeued->claimedAt());
        Assert::false($repo->requeue($id));
    }

    public function testClaimSendAndConcurrentTransition(): void
    {
        $repo = new FakeOutboundMessageRepository(self::TENANT_ID);
        $id = (int) $repo->insertIfNew(self::message(CommunicationChannel::EMAIL, null))->id();
        $whatsapp = (int) $repo->insertIfNew(self::message(CommunicationChannel::WHATSAPP, null))->id();
        $now = new DateTimeImmutable('2026-10-06 10:00:00');

        Assert::false($repo->claim($whatsapp, $now), 'only e-mail is claimed');
        Assert::false($repo->markSent($id, 'log', null, $now), 'markSent needs a claim');
        Assert::true($repo->claim($id, $now));
        Assert::false($repo->claim($id, $now->modify('+5 minutes')), 'claim still fresh');
        Assert::true($repo->claim($id, $now->modify('+11 minutes')), 'stale claim is taken over');
        Assert::true($repo->releaseClaim($id, 'smtp_auth'));
        Assert::null($repo->findById($id)->claimedAt());
        Assert::same(1, $repo->findById($id)->attemptCount());

        Assert::true($repo->claim($id, $now));
        $repo->simulateConcurrentTransition($id, OutboundMessage::STATUS_CANCELLED);
        Assert::false($repo->markSent($id, 'log', 'abc', $now));
        Assert::same(OutboundMessage::STATUS_CANCELLED, $repo->findById($id)->status());
    }

    public function testCancelRecordsReasonAndActor(): void
    {
        $repo = new FakeOutboundMessageRepository(self::TENANT_ID);
        $id = (int) $repo->insertIfNew(self::message(CommunicationChannel::WHATSAPP, null))->id();
        $now = new DateTimeImmutable('2026-10-06 10:00:00');

        Assert::true($repo->cancel($id, null, 'opted_out', $now));
        $cancelled = $repo->findById($id);
        Assert::same(OutboundMessage::STATUS_CANCELLED, $cancelled->status());
        Assert::same('opted_out', $cancelled->lastErrorCode());
        Assert::null($cancelled->cancelledBySystemUserId());
        Assert::false($repo->cancel($id, self::USER_ID, 'discarded', $now));
    }

    public function testListForUnitAndStaleQueuedEmails(): void
    {
        $repo = new FakeOutboundMessageRepository(self::TENANT_ID);
        $email = (int) $repo->insertIfNew(self::message(CommunicationChannel::EMAIL, null))->id();
        $repo->insertIfNew(self::message(CommunicationChannel::WHATSAPP, null));

        Assert::count(2, $repo->listForUnit(self::UNIT_ID, [], 10));
        Assert::count(1, $repo->listForUnit(self::UNIT_ID, ['channel' => CommunicationChannel::WHATSAPP], 10));
        Assert::count(0, $repo->listForUnit(99, [], 10));
        Assert::same([$email], $repo->listStaleQueuedEmailIds(new DateTimeImmutable('+1 day'), 10));
        Assert::same([], $repo->listStaleQueuedEmailIds(new DateTimeImmutable('-1 day'), 10));
    }

    public function testPreferenceTemplateAndFollowupFakes(): void
    {
        $prefs = new FakeCommunicationPreferenceRepository(self::TENANT_ID);
        $now = new DateTimeImmutable('2026-10-06 10:00:00');
        $prefs->upsert(CommunicationPreference::record(self::TENANT_ID, 7, CommunicationChannel::EMAIL, CommunicationPreference::STATUS_OPTED_IN, 'in_person', self::USER_ID, $now));
        $prefs->upsert(CommunicationPreference::record(self::TENANT_ID, 7, CommunicationChannel::EMAIL, CommunicationPreference::STATUS_OPTED_OUT, 'phone', self::USER_ID, $now));
        $map = $prefs->findForTutor(7);
        Assert::same([CommunicationChannel::EMAIL], array_keys($map));
        Assert::same(CommunicationPreference::STATUS_OPTED_OUT, $map[CommunicationChannel::EMAIL]->status());
        Assert::same([], $prefs->findForTutor(8));

        $templates = new FakeMessageTemplateRepository(self::TENANT_ID);
        $template = MessageTemplate::create(self::TENANT_ID, MessagePurpose::APPOINTMENT_CONFIRMATION, CommunicationChannel::WHATSAPP, 'F7A teste', null, 'Olá', self::USER_ID);
        $templates->save($template);
        Assert::notNull($templates->findActiveFor(MessagePurpose::APPOINTMENT_CONFIRMATION, CommunicationChannel::WHATSAPP));
        Assert::same(0, $templates->countActiveFor(MessagePurpose::APPOINTMENT_CONFIRMATION, CommunicationChannel::WHATSAPP, $template->id()));
        $template->deactivate();
        $templates->save($template);
        Assert::null($templates->findActiveFor(MessagePurpose::APPOINTMENT_CONFIRMATION, CommunicationChannel::WHATSAPP));

        $followups = new FakeAppointmentFollowupRepository(self::TENANT_ID);
        $followups->link(21, 4, self::USER_ID);
        Assert::true($followups->isFollowup(21));
        Assert::false($followups->isFollowup(22));
    }

    public function testQueriesReturnSeedAndRecordCalls(): void
    {
        $from = new DateTimeImmutable('2026-10-07 00:00:00');
        $to = new DateTimeImmutable('2026-10-08 00:00:00');
        $candidate = new ReminderCandidate(MessagePurpose::APPOINTMENT_CONFIRMATION, OutboundMessage::SOURCE_APPOINTMENT, 11, self::UNIT_ID, 7, null, null, '5585999990000', []);
        $reminders = new FakeReminderSourceQuery(appointments: [$candidate]);

        Assert::same([$candidate], $reminders->appointmentsBetween($from, $to));
        Assert::same([], $reminders->vaccinesDueBetween($from, $to));
        Assert::same(['appointmentsBetween', $from, $to], $reminders->calls()[0]);
        Assert::count(2, $reminders->calls());

        $item = new PendingItem(PendingItem::TYPE_MESSAGE_FAILED, 3, null, 'F7A teste', $from, null, 'CommunicationMessageView', ['id' => 3]);
        $pending = new FakePendingItemQuery([$item]);
        Assert::same([$item], $pending->listForUnit(self::UNIT_ID, $from, 50));
        Assert::same([['listForUnit', self::UNIT_ID, $from, 50]], $pending->calls());
    }

    public function testFakeQueueRecordsThePayload(): void
    {
        $queue = new FakeQueue();
        $id = $queue->push('communication', ['type' => 'communication.deliver', 'message_id' => 9], self::TENANT_ID);

        Assert::true($id !== '');
        Assert::same([[
            'queue' => 'communication',
            'payload' => ['type' => 'communication.deliver', 'message_id' => 9],
            'tenantId' => self::TENANT_ID,
            'maxAttempts' => 5,
        ]], $queue->pushed());
    }

    public function testFakeEmailProviderDeliversAndFails(): void
    {
        $provider = new FakeEmailProvider();
        $message = new OutgoingMessage('f7a.teste@example.invalid', 'F7A teste', 'F7A teste', 'msg-1');

        Assert::same(CommunicationChannel::EMAIL, $provider->channel());
        Assert::notNull($provider->deliver($message));
        Assert::same([$message], $provider->deliveries());

        $provider->failWith('smtp_connect');
        $caught = null;
        try {
            $provider->deliver($message);
        } catch (MessageDeliveryFailed $e) {
            $caught = $e;
        }
        Assert::notNull($caught);
        Assert::same('smtp_connect', $caught->errorCode());
        Assert::count(1, $provider->deliveries());
    }
}
