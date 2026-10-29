<?php

declare(strict_types=1);

namespace CentralVet\Tests\Integration;

use CentralVet\Domain\CommunicationChannel;
use CentralVet\Domain\CommunicationPreference;
use CentralVet\Domain\Encounter;
use CentralVet\Domain\MessagePurpose;
use CentralVet\Domain\MessageTemplate;
use CentralVet\Domain\OutboundMessage;
use CentralVet\Persistence\AppointmentFollowupRepository;
use CentralVet\Persistence\CommunicationPreferenceRepository;
use CentralVet\Persistence\EncounterRepository;
use CentralVet\Persistence\MessageTemplateRepository;
use CentralVet\Persistence\OutboundMessageRepository;
use CentralVet\Persistence\PdoConnectionFactory;
use CentralVet\Tenancy\Exception\TenantBoundaryViolation;
use CentralVet\Tenancy\TenantContext;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\MysqlIntegrationTestCase;
use DateTimeImmutable;

/**
 * The four communication repositories (migration 0012) against the real
 * MySQL schema: dedupe by UNIQUE (tenant_id, dedupe_key), conditional
 * status transitions (rowCount), preference upsert, legal basis round trip
 * and tenant isolation. Every row lives only inside the test transaction,
 * rolled back in tearDown() — nothing is committed.
 */
final class CommunicationRepositoryIntegrationTest extends MysqlIntegrationTestCase
{
    private int $userId;
    private int $unitId;
    private int $tenantA;
    private int $tenantB;
    private int $tutorId;
    private int $patientId;

    public function setUp(): void
    {
        parent::setUp();

        // The worker uses PdoConnectionFactory (native prepares): run the
        // repositories the same way, so a repeated named parameter or a
        // quoted LIMIT would fail here.
        $this->pdo->setAttribute(\PDO::ATTR_EMULATE_PREPARES, false);

        $hasTable = (bool) $this->pdo->query("SHOW TABLES LIKE 'communication_message'")->fetchColumn();
        Assert::true($hasTable, 'Migration 0012 must be applied to the test database');

        $this->userId = (int) $this->pdo->query('SELECT MIN(id) FROM system_users')->fetchColumn();
        Assert::true($this->userId > 0, 'Fixture requires at least one existing system_users row');

        $this->unitId = (int) $this->pdo->query('SELECT MIN(id) FROM system_unit')->fetchColumn();
        Assert::true($this->unitId > 0, 'Fixture requires at least one existing system_unit row');

        $this->tenantA = $this->createTenant('f7a-comm-a');
        $this->tenantB = $this->createTenant('f7a-comm-b');

        $this->pdo->prepare('INSERT INTO tutor (tenant_id, public_id, full_name, phone) VALUES (:t, UUID(), :n, :p)')
            ->execute(['t' => $this->tenantA, 'n' => 'F7A teste Tutor', 'p' => '11999990000']);
        $this->tutorId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare('INSERT INTO patient (tenant_id, tutor_id, name, species) VALUES (:t, :tutor, :n, :s)')
            ->execute(['t' => $this->tenantA, 'tutor' => $this->tutorId, 'n' => 'F7A teste Rex', 's' => 'canino']);
        $this->patientId = (int) $this->pdo->lastInsertId();
    }

    public function testSecondInsertWithSameDedupeKeyReturnsNullAndKeepsOneRow(): void
    {
        $messages = $this->messages($this->tenantA);
        $key = OutboundMessage::buildDedupeKey(MessagePurpose::APPOINTMENT_CONFIRMATION, OutboundMessage::SOURCE_APPOINTMENT, 987654, CommunicationChannel::EMAIL);

        $first = $messages->insertIfNew($this->emailMessage($key));
        Assert::notNull($first);
        Assert::true((int) $first->id() > 0);

        Assert::null($messages->insertIfNew($this->emailMessage($key)));
        Assert::same(1, $this->countWhere('dedupe_key = ' . $this->pdo->quote($key)));
    }

    public function testNullDedupeKeyNeverCollides(): void
    {
        $messages = $this->messages($this->tenantA);

        Assert::notNull($messages->insertIfNew($this->whatsappMessage()));
        Assert::notNull($messages->insertIfNew($this->whatsappMessage()));
        Assert::same(2, $this->countWhere('dedupe_key IS NULL'));
    }

    public function testCheckViolationIsNotSwallowed(): void
    {
        $messages = $this->messages($this->tenantA);
        $message = $this->emailMessage(null, ['patient_id' => 999999999]);

        Assert::throws(\PDOException::class, static fn () => $messages->insertIfNew($message));
    }

    public function testClaimTwiceReturnsTrueThenFalseAndStaleClaimIsRetaken(): void
    {
        $messages = $this->messages($this->tenantA);
        $id = (int) $messages->insertIfNew($this->emailMessage(null))?->id();
        $now = new DateTimeImmutable('2031-10-01 10:00:00.250000');

        Assert::true($messages->claim($id, $now));
        Assert::false($messages->claim($id, $now->modify('+1 minute')));
        Assert::true($messages->claim($id, $now->modify('+11 minutes')));

        /** @var OutboundMessage $claimed */
        $claimed = $messages->findById($id);
        Assert::same('2031-10-01 10:11:00.250000', $claimed->claimedAt()?->format('Y-m-d H:i:s.u'));
    }

    public function testClaimRefusesWhatsappAndOtherTenant(): void
    {
        $messages = $this->messages($this->tenantA);
        $whatsappId = (int) $messages->insertIfNew($this->whatsappMessage())?->id();
        $emailId = (int) $messages->insertIfNew($this->emailMessage(null))?->id();
        $now = new DateTimeImmutable('2031-10-01 10:00:00');

        Assert::false($messages->claim($whatsappId, $now));
        Assert::false($this->messages($this->tenantB)->claim($emailId, $now));
        Assert::true($messages->claim($emailId, $now));
    }

    public function testMarkSentOnlyAfterClaimAndOnlyOnce(): void
    {
        $messages = $this->messages($this->tenantA);
        $id = (int) $messages->insertIfNew($this->emailMessage(null))?->id();
        $now = new DateTimeImmutable('2031-10-01 10:00:00');

        Assert::false($messages->markSent($id, 'log', null, $now));
        Assert::true($messages->claim($id, $now));
        Assert::true($messages->markSent($id, 'smtp', 'provider-123', $now->modify('+5 seconds')));
        Assert::false($messages->markSent($id, 'smtp', 'provider-123', $now->modify('+6 seconds')));

        /** @var OutboundMessage $sent */
        $sent = $messages->findById($id);
        Assert::same(OutboundMessage::STATUS_SENT, $sent->status());
        Assert::same('smtp', $sent->provider());
        Assert::same('provider-123', $sent->providerMessageId());
        Assert::same('2031-10-01 10:00:05', $sent->sentAt()?->format('Y-m-d H:i:s'));
    }

    public function testReleaseClaimFailAndRequeue(): void
    {
        $messages = $this->messages($this->tenantA);
        $id = (int) $messages->insertIfNew($this->emailMessage(null))?->id();
        $now = new DateTimeImmutable('2031-10-01 10:00:00');

        Assert::true($messages->claim($id, $now));
        Assert::true($messages->releaseClaim($id, 'smtp_connect'));

        /** @var OutboundMessage $released */
        $released = $messages->findById($id);
        Assert::same(OutboundMessage::STATUS_QUEUED, $released->status());
        Assert::null($released->claimedAt());
        Assert::same(1, $released->attemptCount());
        Assert::same('smtp_connect', $released->lastErrorCode());

        Assert::false($messages->requeue($id));
        Assert::true($messages->markFailed($id, 'smtp_auth', $now->modify('+1 minute')));
        Assert::false($messages->markFailed($id, 'smtp_auth', $now->modify('+2 minutes')));

        /** @var OutboundMessage $failed */
        $failed = $messages->findById($id);
        Assert::same(OutboundMessage::STATUS_FAILED, $failed->status());
        Assert::same(2, $failed->attemptCount());
        Assert::same('smtp_auth', $failed->lastErrorCode());
        Assert::notNull($failed->failedAt());

        Assert::true($messages->requeue($id));
        Assert::false($messages->requeue($id));

        /** @var OutboundMessage $requeued */
        $requeued = $messages->findById($id);
        Assert::same(OutboundMessage::STATUS_QUEUED, $requeued->status());
        Assert::null($requeued->failedAt());
        Assert::null($requeued->claimedAt());
    }

    public function testMarkManualSentTwiceReturnsTrueThenFalse(): void
    {
        $messages = $this->messages($this->tenantA);
        $id = (int) $messages->insertIfNew($this->whatsappMessage())?->id();
        $emailId = (int) $messages->insertIfNew($this->emailMessage(null))?->id();
        $sentAt = new DateTimeImmutable('2031-10-01 11:00:00');

        Assert::false($messages->markManualSent($emailId, $this->userId, $sentAt));
        Assert::true($messages->markManualSent($id, $this->userId, $sentAt));
        Assert::false($messages->markManualSent($id, $this->userId, $sentAt->modify('+1 minute')));

        /** @var OutboundMessage $manual */
        $manual = $messages->findById($id);
        Assert::same(OutboundMessage::STATUS_MANUAL, $manual->status());
        Assert::same($this->userId, $manual->manualSentBySystemUserId());
        Assert::same('2031-10-01 11:00:00', $manual->sentAt()?->format('Y-m-d H:i:s'));
    }

    public function testCancelOnlyFromQueued(): void
    {
        $messages = $this->messages($this->tenantA);
        $id = (int) $messages->insertIfNew($this->whatsappMessage())?->id();
        $at = new DateTimeImmutable('2031-10-01 12:00:00');

        Assert::true($messages->cancel($id, $this->userId, 'discarded', $at));
        Assert::false($messages->cancel($id, $this->userId, 'discarded', $at));
        Assert::false($messages->markManualSent($id, $this->userId, $at));

        /** @var OutboundMessage $cancelled */
        $cancelled = $messages->findById($id);
        Assert::same(OutboundMessage::STATUS_CANCELLED, $cancelled->status());
        Assert::same('discarded', $cancelled->lastErrorCode());
        Assert::same($this->userId, $cancelled->cancelledBySystemUserId());
        Assert::notNull($cancelled->cancelledAt());

        $workerId = (int) $messages->insertIfNew($this->emailMessage(null))?->id();
        Assert::true($messages->cancel($workerId, null, 'opted_out', $at));
        Assert::null($messages->findById($workerId)?->cancelledBySystemUserId());
    }

    public function testCancelQueuedForTutorCancelsOnlyQueuedUnclaimedMessagesOfTheChannelInTheTenant(): void
    {
        $messages = $this->messages($this->tenantA);
        $queued = (int) $messages->insertIfNew($this->whatsappMessage())?->id();
        $alsoQueued = (int) $messages->insertIfNew($this->whatsappMessage())?->id();
        $alreadySent = (int) $messages->insertIfNew($this->whatsappMessage())?->id();
        $email = (int) $messages->insertIfNew($this->emailMessage(null))?->id();
        $at = new DateTimeImmutable('2031-10-01 12:00:00');
        Assert::true($messages->markManualSent($alreadySent, $this->userId, $at));

        Assert::same(0, $this->messages($this->tenantB)->cancelQueuedForTutor($this->tutorId, CommunicationChannel::WHATSAPP, $this->userId, 'opted_out', $at), 'another tenant changes nothing');
        Assert::same(2, $messages->cancelQueuedForTutor($this->tutorId, CommunicationChannel::WHATSAPP, $this->userId, 'opted_out', $at));
        Assert::same(0, $messages->cancelQueuedForTutor($this->tutorId, CommunicationChannel::WHATSAPP, $this->userId, 'opted_out', $at), 'second call finds nothing queued');

        foreach ([$queued, $alsoQueued] as $id) {
            /** @var OutboundMessage $cancelled */
            $cancelled = $messages->findById($id);
            Assert::same(OutboundMessage::STATUS_CANCELLED, $cancelled->status());
            Assert::same('opted_out', $cancelled->lastErrorCode());
            Assert::same($this->userId, $cancelled->cancelledBySystemUserId());
            Assert::same('2031-10-01 12:00:00', $cancelled->cancelledAt()?->format('Y-m-d H:i:s'));
        }

        Assert::same(OutboundMessage::STATUS_MANUAL, $messages->findById($alreadySent)?->status());
        Assert::same(OutboundMessage::STATUS_QUEUED, $messages->findById($email)?->status(), 'other channel untouched');

        // An e-mail being delivered (claimed) is left to the worker's own re-check.
        $claimed = (int) $messages->insertIfNew($this->emailMessage(null))?->id();
        Assert::true($messages->claim($claimed, $at));
        Assert::same(1, $messages->cancelQueuedForTutor($this->tutorId, CommunicationChannel::EMAIL, null, 'opted_out', $at));
        Assert::same(OutboundMessage::STATUS_CANCELLED, $messages->findById($email)?->status());
        Assert::same(OutboundMessage::STATUS_QUEUED, $messages->findById($claimed)?->status());
    }

    public function testLegalBasisRoundTripAndAllFields(): void
    {
        $messages = $this->messages($this->tenantA);
        $id = (int) $messages->insertIfNew($this->emailMessage('f7a:legal:basis', ['legal_basis' => MessagePurpose::LEGAL_BASIS_CONSENT]))?->id();

        /** @var OutboundMessage $loaded */
        $loaded = $messages->findById($id);
        Assert::same(MessagePurpose::LEGAL_BASIS_CONSENT, $loaded->legalBasis());
        Assert::same($this->tenantA, $loaded->tenantId());
        Assert::same($this->unitId, $loaded->systemUnitId());
        Assert::same($this->tutorId, $loaded->tutorId());
        Assert::same($this->patientId, $loaded->patientId());
        Assert::same(MessagePurpose::VACCINE_DUE, $loaded->purpose());
        Assert::same(CommunicationChannel::EMAIL, $loaded->channel());
        Assert::same(OutboundMessage::ORIGIN_AUTOMATION, $loaded->origin());
        Assert::same(OutboundMessage::SOURCE_VACCINATION, $loaded->sourceType());
        Assert::same(555, $loaded->sourceId());
        Assert::same('f7a:legal:basis', $loaded->dedupeKey());
        Assert::same('f7a.teste@example.invalid', $loaded->recipient());
        Assert::same('F7A teste assunto', $loaded->subject());
        Assert::same('F7A teste corpo', $loaded->bodyText());
        Assert::same(OutboundMessage::STATUS_QUEUED, $loaded->status());
        Assert::same(0, $loaded->attemptCount());
        Assert::same($this->userId, $loaded->createdBySystemUserId());
        Assert::notNull($loaded->createdAt());
    }

    public function testMessageOfAnotherTenantIsNotFound(): void
    {
        $id = (int) $this->messages($this->tenantA)->insertIfNew($this->emailMessage(null))?->id();

        Assert::notNull($this->messages($this->tenantA)->findById($id));
        Assert::null($this->messages($this->tenantB)->findById($id));
        Assert::false($this->messages($this->tenantB)->markManualSent($id, $this->userId, new DateTimeImmutable()));
        Assert::count(0, $this->messages($this->tenantB)->listForUnit($this->unitId, [], 50));
    }

    public function testInsertForAnotherTenantIsRefused(): void
    {
        $message = $this->emailMessage(null);

        Assert::throws(TenantBoundaryViolation::class, fn () => $this->messages($this->tenantB)->insertIfNew($message));
    }

    public function testListForUnitFiltersAndLimits(): void
    {
        $messages = $this->messages($this->tenantA);
        $messages->insertIfNew($this->emailMessage(null));
        $messages->insertIfNew($this->emailMessage(null));
        $whatsappId = (int) $messages->insertIfNew($this->whatsappMessage())?->id();

        Assert::count(3, $messages->listForUnit($this->unitId, [], 50));
        Assert::count(2, $messages->listForUnit($this->unitId, [], 2));

        $whatsapp = $messages->listForUnit($this->unitId, ['channel' => CommunicationChannel::WHATSAPP], 50);
        Assert::count(1, $whatsapp);
        Assert::same($whatsappId, $whatsapp[0]->id());

        Assert::count(3, $messages->listForUnit($this->unitId, ['status' => OutboundMessage::STATUS_QUEUED, 'tutor_id' => $this->tutorId], 50));
        Assert::count(1, $messages->listForUnit($this->unitId, ['purpose' => MessagePurpose::CUSTOM], 50));
        Assert::count(0, $messages->listForUnit($this->unitId + 100000, [], 50));
    }

    public function testListStaleQueuedEmailIds(): void
    {
        $messages = $this->messages($this->tenantA);
        $stale = (int) $messages->insertIfNew($this->emailMessage(null))?->id();
        $claimedRecently = (int) $messages->insertIfNew($this->emailMessage(null))?->id();
        $messages->insertIfNew($this->whatsappMessage());
        $this->pdo->exec("UPDATE communication_message SET created_at = '2031-10-01 09:00:00' WHERE tenant_id = {$this->tenantA}");

        $olderThan = new DateTimeImmutable('2031-10-01 10:00:00');
        Assert::true($messages->claim($claimedRecently, $olderThan->modify('+5 minutes')));

        Assert::same([$stale], $messages->listStaleQueuedEmailIds($olderThan, 10));
        Assert::same([], $messages->listStaleQueuedEmailIds(new DateTimeImmutable('2031-10-01 08:00:00'), 10));
        Assert::same([], $this->messages($this->tenantB)->listStaleQueuedEmailIds($olderThan, 10));
    }

    public function testUpsertTwiceKeepsOneRowWithLastStatus(): void
    {
        $preferences = new CommunicationPreferenceRepository($this->contextFor($this->tenantA), $this->pdo);

        $preferences->upsert(CommunicationPreference::record(
            $this->tenantA, $this->tutorId, CommunicationChannel::EMAIL, CommunicationPreference::STATUS_OPTED_IN,
            'in_person', $this->userId, new DateTimeImmutable('2031-10-01 08:00:00'),
        ));
        $preferences->upsert(CommunicationPreference::record(
            $this->tenantA, $this->tutorId, CommunicationChannel::EMAIL, CommunicationPreference::STATUS_OPTED_OUT,
            'phone', $this->userId, new DateTimeImmutable('2031-10-02 09:30:00.500000'),
        ));

        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM communication_preference WHERE tenant_id = :t AND tutor_id = :tutor');
        $statement->execute(['t' => $this->tenantA, 'tutor' => $this->tutorId]);
        Assert::same(1, (int) $statement->fetchColumn());

        $found = $preferences->findForTutor($this->tutorId);
        Assert::same([CommunicationChannel::EMAIL], array_keys($found));
        Assert::same(CommunicationPreference::STATUS_OPTED_OUT, $found[CommunicationChannel::EMAIL]->status());
        Assert::same('phone', $found[CommunicationChannel::EMAIL]->consentSource());
        Assert::same('2031-10-02 09:30:00.500000', $found[CommunicationChannel::EMAIL]->changedAt()->format('Y-m-d H:i:s.u'));

        $other = new CommunicationPreferenceRepository($this->contextFor($this->tenantB), $this->pdo);
        Assert::same([], $other->findForTutor($this->tutorId));
    }

    public function testTemplateRoundTripActiveLookupAndCount(): void
    {
        $templates = new MessageTemplateRepository($this->contextFor($this->tenantA), $this->pdo);
        $template = MessageTemplate::create($this->tenantA, MessagePurpose::VACCINE_DUE, CommunicationChannel::EMAIL, 'F7A teste vacina', 'F7A teste assunto', 'F7A teste corpo', $this->userId);
        $templates->save($template);
        Assert::notNull($template->id());

        /** @var MessageTemplate $loaded */
        $loaded = $templates->findById((int) $template->id());
        Assert::same('F7A teste vacina', $loaded->name());
        Assert::same('F7A teste assunto', $loaded->subject());
        Assert::same(MessageTemplate::STATUS_ACTIVE, $loaded->status());

        Assert::same((int) $template->id(), $templates->findActiveFor(MessagePurpose::VACCINE_DUE, CommunicationChannel::EMAIL)?->id());
        Assert::same(1, $templates->countActiveFor(MessagePurpose::VACCINE_DUE, CommunicationChannel::EMAIL, null));
        Assert::same(0, $templates->countActiveFor(MessagePurpose::VACCINE_DUE, CommunicationChannel::EMAIL, (int) $template->id()));
        Assert::count(1, $templates->listAll());

        $loaded->update(MessagePurpose::VACCINE_DUE, CommunicationChannel::EMAIL, 'F7A teste vacina 2', 'Novo assunto', 'Novo corpo', $this->userId);
        $loaded->deactivate();
        $templates->save($loaded);

        /** @var MessageTemplate $updated */
        $updated = $templates->findById((int) $template->id());
        Assert::same('F7A teste vacina 2', $updated->name());
        Assert::same(MessageTemplate::STATUS_INACTIVE, $updated->status());
        Assert::same($this->userId, $updated->updatedBySystemUserId());
        Assert::null($templates->findActiveFor(MessagePurpose::VACCINE_DUE, CommunicationChannel::EMAIL));
        Assert::same(0, $templates->countActiveFor(MessagePurpose::VACCINE_DUE, CommunicationChannel::EMAIL, null));

        $other = new MessageTemplateRepository($this->contextFor($this->tenantB), $this->pdo);
        Assert::null($other->findById((int) $template->id()));
        Assert::count(0, $other->listAll());
    }

    public function testFollowupLinkAndTenantIsolation(): void
    {
        $encounter = Encounter::start($this->tenantA, $this->unitId, $this->patientId, null, $this->userId, new DateTimeImmutable('2031-09-01 07:00:00'));
        (new EncounterRepository($this->contextFor($this->tenantA), $this->pdo))->save($encounter);
        $appointmentId = $this->createAppointment();

        $followups = new AppointmentFollowupRepository($this->contextFor($this->tenantA), $this->pdo);
        Assert::false($followups->isFollowup($appointmentId));

        $followups->link($appointmentId, (int) $encounter->id(), $this->userId);
        Assert::true($followups->isFollowup($appointmentId));

        $other = new AppointmentFollowupRepository($this->contextFor($this->tenantB), $this->pdo);
        Assert::false($other->isFollowup($appointmentId));
        Assert::throws(TenantBoundaryViolation::class, fn () => $other->link($this->createAppointment(), (int) $encounter->id(), $this->userId));
    }

    public function testConnectionFactoryBuildsStrictPdo(): void
    {
        $connection = PdoConnectionFactory::fromEnvironment();

        Assert::same(\PDO::ERRMODE_EXCEPTION, $connection->getAttribute(\PDO::ATTR_ERRMODE));
        Assert::same('1', (string) $connection->query('SELECT 1')->fetchColumn());
    }

    /** @param array<string, mixed> $overrides */
    private function emailMessage(?string $dedupeKey, array $overrides = []): OutboundMessage
    {
        return OutboundMessage::compose(
            tenantId: $this->tenantA,
            systemUnitId: $this->unitId,
            tutorId: $this->tutorId,
            patientId: $overrides['patient_id'] ?? $this->patientId,
            templateId: null,
            purpose: MessagePurpose::VACCINE_DUE,
            channel: CommunicationChannel::EMAIL,
            origin: OutboundMessage::ORIGIN_AUTOMATION,
            legalBasis: $overrides['legal_basis'] ?? MessagePurpose::LEGAL_BASIS_CONSENT,
            sourceType: OutboundMessage::SOURCE_VACCINATION,
            sourceId: 555,
            dedupeKey: $dedupeKey,
            recipient: 'f7a.teste@example.invalid',
            subject: 'F7A teste assunto',
            bodyText: 'F7A teste corpo',
            createdBySystemUserId: $this->userId,
        );
    }

    private function whatsappMessage(): OutboundMessage
    {
        return OutboundMessage::compose(
            tenantId: $this->tenantA,
            systemUnitId: $this->unitId,
            tutorId: $this->tutorId,
            patientId: $this->patientId,
            templateId: null,
            purpose: MessagePurpose::CUSTOM,
            channel: CommunicationChannel::WHATSAPP,
            origin: OutboundMessage::ORIGIN_MANUAL,
            legalBasis: MessagePurpose::LEGAL_BASIS_CONSENT,
            sourceType: null,
            sourceId: null,
            dedupeKey: null,
            recipient: '5511999990000',
            subject: null,
            bodyText: 'F7A teste corpo',
            createdBySystemUserId: $this->userId,
        );
    }

    private function messages(int $tenantId): OutboundMessageRepository
    {
        return new OutboundMessageRepository($this->contextFor($tenantId), $this->pdo);
    }

    private function countWhere(string $predicate): int
    {
        return (int) $this->pdo
            ->query("SELECT COUNT(*) FROM communication_message WHERE tenant_id = {$this->tenantA} AND {$predicate}")
            ->fetchColumn();
    }

    private function createAppointment(): int
    {
        $this->pdo->prepare('INSERT INTO service (tenant_id, name, duration_minutes, price_cents) VALUES (:t, :n, 30, 1000)')
            ->execute(['t' => $this->tenantA, 'n' => 'F7A teste Retorno ' . bin2hex(random_bytes(4))]);
        $serviceId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare(
            'INSERT INTO appointment (tenant_id, system_unit_id, patient_id, service_id, professional_system_user_id, scheduled_at) '
            . 'VALUES (:t, :u, :p, :s, :prof, :at)',
        )->execute([
            't' => $this->tenantA,
            'u' => $this->unitId,
            'p' => $this->patientId,
            's' => $serviceId,
            'prof' => $this->userId,
            'at' => '2031-09-08 08:00:00',
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function contextFor(int $tenantId): TenantContext
    {
        return TenantContext::authenticated($tenantId, $this->userId, $this->unitId);
    }

    private function createTenant(string $slugPrefix): int
    {
        $slug = $slugPrefix . '-' . bin2hex(random_bytes(4));
        $statement = $this->pdo->prepare(
            'INSERT INTO tenant (public_id, slug, legal_name, status) VALUES (UUID(), :slug, :legal_name, :status)',
        );
        $statement->execute(['slug' => $slug, 'legal_name' => 'F7A teste tenant (' . $slug . ')', 'status' => 'active']);

        return (int) $this->pdo->lastInsertId();
    }
}
