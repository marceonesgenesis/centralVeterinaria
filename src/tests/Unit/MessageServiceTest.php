<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Application\MessageQueuePublisher;
use CentralVet\Application\MessageService;
use CentralVet\Authorization\Exception\AuthorizationDenied;
use CentralVet\Domain\CommunicationChannel;
use CentralVet\Domain\CommunicationPreference;
use CentralVet\Domain\Exception\CommunicationConsentRequiredException;
use CentralVet\Domain\Exception\CrossTenantReferenceException;
use CentralVet\Domain\Exception\InvalidStatusTransitionException;
use CentralVet\Domain\MessagePurpose;
use CentralVet\Domain\MessageTemplate;
use CentralVet\Domain\OutboundMessage;
use CentralVet\Domain\Patient;
use CentralVet\Domain\Tutor;
use CentralVet\Tenancy\TenantContext;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\FakeAuthorizationPolicy;
use CentralVet\Tests\Support\FakeCommunicationPreferenceRepository;
use CentralVet\Tests\Support\FakeMessageTemplateRepository;
use CentralVet\Tests\Support\FakeOutboundMessageRepository;
use CentralVet\Tests\Support\FakePatientRepository;
use CentralVet\Tests\Support\FakeQueue;
use CentralVet\Tests\Support\FakeTutorRepository;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Unit tests for MessageService (manual message cycle) and
 * MessageQueuePublisher (T-10): LGPD legal basis per purpose, consent and
 * opt-out, contact checks, authorization before any write, conditional
 * transitions turned into domain messages when a concurrent touch wins,
 * the wa.me link and the queue payload without personal data.
 */
final class MessageServiceTest
{
    private const ACTION = 'test::communication_message';
    private const TENANT_ID = 1;
    private const UNIT_ID = 5;
    private const USER_ID = 7;
    private const TUTOR_ID = 10;
    private const TUTOR_WITHOUT_EMAIL_ID = 11;
    private const OTHER_TUTOR_ID = 12;
    private const PATIENT_ID = 20;
    private const OTHER_PATIENT_ID = 21;
    private const NOW = '2026-10-06 10:00:00';

    private FakeOutboundMessageRepository $messages;
    private FakeMessageTemplateRepository $templates;
    private FakeCommunicationPreferenceRepository $preferences;
    private FakeAuthorizationPolicy $policy;

    /** @var array{unit_name: ?string, clinic_name: ?string}|null */
    private ?array $senderNames = null;

    private function build(bool $allowed = true, CommunicationPreference ...$preferences): MessageService
    {
        $this->messages = new FakeOutboundMessageRepository(self::TENANT_ID);
        $this->templates = new FakeMessageTemplateRepository(self::TENANT_ID);
        $this->preferences = new FakeCommunicationPreferenceRepository(self::TENANT_ID, ...$preferences);
        $this->policy = new FakeAuthorizationPolicy(allowed: $allowed);

        $tutors = new FakeTutorRepository(
            self::TENANT_ID,
            self::tutor(self::TUTOR_ID, 'f7a.teste@example.invalid', '(85) 99999-0000'),
            self::tutor(self::TUTOR_WITHOUT_EMAIL_ID, null, '123'),
            self::tutor(self::OTHER_TUTOR_ID, 'f7a.outro@example.invalid', '(85) 98888-0000'),
        );
        $patients = new FakePatientRepository(
            self::TENANT_ID,
            new Patient(self::PATIENT_ID, self::TENANT_ID, self::TUTOR_ID, 'F7A teste Rex', 'canine'),
            new Patient(self::OTHER_PATIENT_ID, self::TENANT_ID, self::OTHER_TUTOR_ID, 'F7A teste Mia', 'feline'),
        );

        return new MessageService(
            $this->messages,
            $this->templates,
            $this->preferences,
            $tutors,
            $patients,
            $this->policy,
            TenantContext::authenticated(self::TENANT_ID, self::USER_ID, self::UNIT_ID),
            static fn (): DateTimeImmutable => new DateTimeImmutable(self::NOW),
            $this->senderNames === null ? null : self::senderNamesQuery($this->senderNames),
        );
    }

    /**
     * Dublê da consulta de nomes da unidade e da clínica (tenant). Só é criado
     * depois de conferir a interface, para o RED falhar por asserção.
     *
     * @param array{unit_name: ?string, clinic_name: ?string} $names
     */
    private static function senderNamesQuery(array $names): object
    {
        Assert::true(
            interface_exists(\CentralVet\Domain\Contract\SenderNamesQueryInterface::class),
            'SenderNamesQueryInterface must exist',
        );

        return new class ($names, self::UNIT_ID) implements \CentralVet\Domain\Contract\SenderNamesQueryInterface {
            /** @param array{unit_name: ?string, clinic_name: ?string} $names */
            public function __construct(private readonly array $names, private readonly int $unitId)
            {
            }

            public function namesForUnit(int $unitId): array
            {
                Assert::same($this->unitId, $unitId, 'names must come from the active unit');

                return $this->names;
            }
        };
    }

    private static function tutor(int $id, ?string $email, string $phone): Tutor
    {
        return new Tutor($id, self::TENANT_ID, 'pub-' . $id, 'F7A teste Tutor ' . $id, null, $phone, $email, null);
    }

    private static function preference(string $channel, string $status): CommunicationPreference
    {
        return CommunicationPreference::record(
            self::TENANT_ID,
            self::TUTOR_ID,
            $channel,
            $status,
            'in_person',
            self::USER_ID,
            new DateTimeImmutable('2026-10-01 09:00:00'),
        );
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private static function data(array $overrides = []): array
    {
        return $overrides + [
            'tutor_id' => self::TUTOR_ID,
            'patient_id' => self::PATIENT_ID,
            'channel' => CommunicationChannel::EMAIL,
            'purpose' => MessagePurpose::CUSTOM,
            'subject' => 'F7A teste assunto',
            'body_text' => 'F7A teste corpo',
        ];
    }

    /** @param class-string<\Throwable> $class */
    private static function expectMessage(string $class, string $message, callable $callback): void
    {
        try {
            $callback();
        } catch (\Throwable $e) {
            Assert::instanceOf($class, $e, "Expected {$class}, got " . $e::class . ': ' . $e->getMessage());
            Assert::same($message, $e->getMessage());

            return;
        }

        Assert::true(false, "Expected exception {$class} was not thrown");
    }

    public function testComposeCustomWithoutPreferenceIsRefusedAndNotStored(): void
    {
        $service = $this->build();

        self::expectMessage(
            CommunicationConsentRequiredException::class,
            'Tutor ' . self::TUTOR_ID . ' has not opted in to email messages',
            fn () => $service->compose(self::data(), self::ACTION),
        );
        Assert::count(0, $this->messages->all());
    }

    public function testComposeAppointmentConfirmationWithoutPreferenceUsesLegitimateInterest(): void
    {
        $service = $this->build();

        $message = $service->compose(self::data(['purpose' => MessagePurpose::APPOINTMENT_CONFIRMATION]), self::ACTION);

        Assert::notNull($message->id());
        Assert::same(OutboundMessage::STATUS_QUEUED, $message->status());
        Assert::same(MessagePurpose::LEGAL_BASIS_LEGITIMATE_INTEREST, $message->legalBasis());
        Assert::count(1, $this->messages->all());
        Assert::same(MessagePurpose::LEGAL_BASIS_LEGITIMATE_INTEREST, $this->messages->all()[0]->legalBasis());
    }

    public function testComposeAppointmentConfirmationOptedOutIsRefused(): void
    {
        $service = $this->build(true, self::preference(CommunicationChannel::EMAIL, CommunicationPreference::STATUS_OPTED_OUT));

        self::expectMessage(
            CommunicationConsentRequiredException::class,
            'Tutor ' . self::TUTOR_ID . ' has opted out of email messages',
            fn () => $service->compose(self::data(['purpose' => MessagePurpose::APPOINTMENT_CONFIRMATION]), self::ACTION),
        );
        Assert::count(0, $this->messages->all());
    }

    public function testComposeCustomEmailWithOptInIsQueuedAsManualConsent(): void
    {
        $service = $this->build(true, self::preference(CommunicationChannel::EMAIL, CommunicationPreference::STATUS_OPTED_IN));

        $message = $service->compose(self::data(), self::ACTION);

        Assert::same(OutboundMessage::STATUS_QUEUED, $message->status());
        Assert::same(OutboundMessage::ORIGIN_MANUAL, $message->origin());
        Assert::same(MessagePurpose::LEGAL_BASIS_CONSENT, $message->legalBasis());
        Assert::same('f7a.teste@example.invalid', $message->recipient());
        Assert::same(self::UNIT_ID, $message->systemUnitId());
        Assert::same(self::PATIENT_ID, $message->patientId());
        Assert::same(self::USER_ID, $message->createdBySystemUserId());
        Assert::null($message->dedupeKey());
        Assert::count(1, $this->policy->requests);
        Assert::same(self::UNIT_ID, $this->policy->requests[0]->resourceUnitId());
        Assert::same('communication_message', $this->policy->requests[0]->entityType());
        Assert::true($this->policy->requests[0]->requiresUnitScope());
    }

    public function testComposeDeniedDoesNotStore(): void
    {
        $service = $this->build(false, self::preference(CommunicationChannel::EMAIL, CommunicationPreference::STATUS_OPTED_IN));

        Assert::throws(AuthorizationDenied::class, fn () => $service->compose(self::data(), self::ACTION));
        Assert::count(0, $this->messages->all());
    }

    public function testComposeWhatsAppUsesNormalizedPhoneAndNoSubject(): void
    {
        $service = $this->build();

        $message = $service->compose(self::data([
            'channel' => CommunicationChannel::WHATSAPP,
            'purpose' => MessagePurpose::RETURN_REMINDER,
            'subject' => '',
        ]), self::ACTION);

        Assert::same('5585999990000', $message->recipient());
        Assert::null($message->subject());
        Assert::same(MessagePurpose::LEGAL_BASIS_LEGITIMATE_INTEREST, $message->legalBasis());
    }

    public function testComposeRejectsContactAndPatientProblems(): void
    {
        $service = $this->build();

        self::expectMessage(
            InvalidArgumentException::class,
            'Tutor ' . self::TUTOR_WITHOUT_EMAIL_ID . ' has no e-mail address',
            fn () => $service->compose(self::data([
                'tutor_id' => self::TUTOR_WITHOUT_EMAIL_ID,
                'patient_id' => null,
                'purpose' => MessagePurpose::APPOINTMENT_CONFIRMATION,
            ]), self::ACTION),
        );
        self::expectMessage(
            InvalidArgumentException::class,
            'Invalid phone number for WhatsApp',
            fn () => $service->compose(self::data([
                'tutor_id' => self::TUTOR_WITHOUT_EMAIL_ID,
                'patient_id' => null,
                'channel' => CommunicationChannel::WHATSAPP,
                'purpose' => MessagePurpose::APPOINTMENT_CONFIRMATION,
            ]), self::ACTION),
        );
        Assert::throws(CrossTenantReferenceException::class, fn () => $service->compose(self::data([
            'patient_id' => self::OTHER_PATIENT_ID,
            'purpose' => MessagePurpose::APPOINTMENT_CONFIRMATION,
        ]), self::ACTION));
        Assert::throws(CrossTenantReferenceException::class, fn () => $service->compose(self::data([
            'tutor_id' => 999,
            'patient_id' => null,
            'purpose' => MessagePurpose::APPOINTMENT_CONFIRMATION,
        ]), self::ACTION));
        self::expectMessage(
            InvalidArgumentException::class,
            'body must be between 1 and 2000 characters',
            fn () => $service->compose(self::data([
                'purpose' => MessagePurpose::APPOINTMENT_CONFIRMATION,
                'body_text' => str_repeat('a', 2001),
            ]), self::ACTION),
        );
        Assert::count(0, $this->messages->all());
    }

    public function testSecondManualSentAfterConcurrentTransitionIsRefused(): void
    {
        $service = $this->build();
        $message = $service->compose(self::data([
            'channel' => CommunicationChannel::WHATSAPP,
            'purpose' => MessagePurpose::APPOINTMENT_CONFIRMATION,
        ]), self::ACTION);
        $id = (int) $message->id();

        $this->messages->simulateConcurrentTransition($id, OutboundMessage::STATUS_MANUAL);

        self::expectMessage(
            InvalidStatusTransitionException::class,
            "Message {$id} is no longer awaiting manual send",
            fn () => $service->markManualSent($id, self::ACTION),
        );
    }

    public function testMarkManualSentRecordsUserAndTime(): void
    {
        $service = $this->build();
        $id = (int) $service->compose(self::data([
            'channel' => CommunicationChannel::WHATSAPP,
            'purpose' => MessagePurpose::APPOINTMENT_CONFIRMATION,
        ]), self::ACTION)->id();

        $service->markManualSent($id, self::ACTION);

        $stored = $service->find($id, self::ACTION);
        Assert::same(OutboundMessage::STATUS_MANUAL, $stored->status());
        Assert::same(self::USER_ID, $stored->manualSentBySystemUserId());
        Assert::same(self::NOW, $stored->sentAt()?->format('Y-m-d H:i:s'));
    }

    public function testCancelTwiceIsRefusedOnSecondTouch(): void
    {
        $service = $this->build();
        $id = (int) $service->compose(self::data(['purpose' => MessagePurpose::APPOINTMENT_CONFIRMATION]), self::ACTION)->id();

        $service->cancel($id, self::ACTION);

        $stored = $service->find($id, self::ACTION);
        Assert::same(OutboundMessage::STATUS_CANCELLED, $stored->status());
        Assert::same('discarded', $stored->lastErrorCode());
        Assert::same(self::USER_ID, $stored->cancelledBySystemUserId());
        self::expectMessage(
            InvalidStatusTransitionException::class,
            "Message {$id} is no longer queued",
            fn () => $service->cancel($id, self::ACTION),
        );
    }

    public function testRetryOfSentMessageIsRefusedAndFailedIsRequeued(): void
    {
        $service = $this->build();
        $id = (int) $service->compose(self::data(['purpose' => MessagePurpose::APPOINTMENT_CONFIRMATION]), self::ACTION)->id();

        $this->messages->simulateConcurrentTransition($id, OutboundMessage::STATUS_SENT);
        self::expectMessage(
            InvalidStatusTransitionException::class,
            "Message {$id} has not failed",
            fn () => $service->retry($id, self::ACTION),
        );

        $this->messages->simulateConcurrentTransition($id, OutboundMessage::STATUS_FAILED);
        $retried = $service->retry($id, self::ACTION);
        Assert::same(OutboundMessage::STATUS_QUEUED, $retried->status());
    }

    public function testDeniedTransitionChangesNothing(): void
    {
        $service = $this->build();
        $id = (int) $service->compose(self::data(['purpose' => MessagePurpose::APPOINTMENT_CONFIRMATION]), self::ACTION)->id();

        $this->policy->setAllowed(false);
        Assert::throws(AuthorizationDenied::class, fn () => $service->cancel($id, self::ACTION));

        Assert::same(OutboundMessage::STATUS_QUEUED, $this->messages->all()[0]->status());
    }

    public function testWhatsAppLinkEncodesBody(): void
    {
        $service = $this->build();
        $id = (int) $service->compose(self::data([
            'channel' => CommunicationChannel::WHATSAPP,
            'purpose' => MessagePurpose::APPOINTMENT_CONFIRMATION,
            'body_text' => 'Olá, consulta amanhã & confirme?',
        ]), self::ACTION)->id();

        $link = $service->whatsAppLink($id, self::ACTION);

        Assert::same('https://wa.me/5585999990000?text=' . rawurlencode('Olá, consulta amanhã & confirme?'), $link);
        Assert::stringContains('Ol%C3%A1%2C%20consulta', $link);

        $service->markManualSent($id, self::ACTION);
        self::expectMessage(
            InvalidStatusTransitionException::class,
            "Message {$id} is no longer awaiting manual send",
            fn () => $service->whatsAppLink($id, self::ACTION),
        );
    }

    public function testRenderTemplateFillsTutorAndPatientNames(): void
    {
        $service = $this->build();
        $template = $this->templates->save(MessageTemplate::create(
            self::TENANT_ID,
            MessagePurpose::CUSTOM,
            CommunicationChannel::EMAIL,
            'F7A teste modelo',
            'Olá {{tutor_name}}',
            'Notícias de {{patient_name}} em {{unit_name}}.',
            self::USER_ID,
        ));

        $rendered = $service->renderTemplate((int) $template->id(), self::TUTOR_ID, self::PATIENT_ID, self::ACTION);

        // Sem nome da unidade, o marcador fica visível para o atendente completar
        // (antes saía vazio: "em .").
        Assert::same(['subject' => 'Olá F7A teste Tutor 10', 'body' => 'Notícias de F7A teste Rex em {{unit_name}}.'], $rendered);
    }

    public function testRenderTemplateFillsUnitAndClinicNamesFromTheActiveUnit(): void
    {
        // Gate T-23: {{unit_name}} e {{clinic_name}} saíam vazios ("aqui é a .").
        $this->senderNames = ['unit_name' => 'F7A teste Unidade', 'clinic_name' => 'F7A teste Clínica'];
        $service = $this->build();
        $template = $this->templates->save(MessageTemplate::create(
            self::TENANT_ID,
            MessagePurpose::CUSTOM,
            CommunicationChannel::EMAIL,
            'F7A teste modelo',
            'Mensagem da {{clinic_name}}',
            'Olá {{tutor_name}}, aqui é a {{clinic_name}} ({{unit_name}}).',
            self::USER_ID,
        ));

        $rendered = $service->renderTemplate((int) $template->id(), self::TUTOR_ID, null, self::ACTION);

        Assert::same([
            'subject' => 'Mensagem da F7A teste Clínica',
            'body' => 'Olá F7A teste Tutor 10, aqui é a F7A teste Clínica (F7A teste Unidade).',
        ], $rendered);
    }

    public function testRenderTemplateKeepsPlaceholdersWithoutValueVisible(): void
    {
        // Mensagem manual não tem agendamento, vacina nem recebível: data, hora,
        // vacina, vencimento e valor ficam como {{marcador}} para o atendente
        // trocar pelo texto, em vez de sumirem do texto.
        $this->senderNames = ['unit_name' => 'F7A teste Unidade', 'clinic_name' => 'F7A teste Clínica'];
        $service = $this->build();
        $template = $this->templates->save(MessageTemplate::create(
            self::TENANT_ID,
            MessagePurpose::APPOINTMENT_CONFIRMATION,
            CommunicationChannel::WHATSAPP,
            'F7A teste confirmação',
            null,
            'Consulta de {{patient_name}} em {{appointment_date}} às {{appointment_time}} na {{unit_name}}.',
            self::USER_ID,
        ));

        $rendered = $service->renderTemplate((int) $template->id(), self::TUTOR_ID, self::PATIENT_ID, self::ACTION);

        Assert::same(
            'Consulta de F7A teste Rex em {{appointment_date}} às {{appointment_time}} na F7A teste Unidade.',
            $rendered['body'],
        );
    }

    public function testComposeRefusesUnfilledPlaceholders(): void
    {
        $service = $this->build(true, self::preference(CommunicationChannel::EMAIL, CommunicationPreference::STATUS_OPTED_IN));

        foreach ([
            ['body_text' => 'Consulta em {{appointment_date}} às {{ appointment_time }}.'],
            ['subject' => 'Lembrete da {{clinic_name}}'],
        ] as $overrides) {
            self::expectMessage(
                InvalidArgumentException::class,
                'Replace the template placeholders before sending the message',
                fn () => $service->compose(self::data($overrides), self::ACTION),
            );
        }

        Assert::count(0, $this->messages->all(), 'a message with unfilled placeholders must not be stored');

        // Chaves fora da lista fechada não são marcadores: o texto segue como está.
        $message = $service->compose(self::data(['body_text' => 'Código {{abc}} do portal']), self::ACTION);
        Assert::same('Código {{abc}} do portal', $message->bodyText());
    }

    public function testListForUnitReturnsActiveUnitMessages(): void
    {
        $service = $this->build();
        $service->compose(self::data(['purpose' => MessagePurpose::APPOINTMENT_CONFIRMATION]), self::ACTION);

        $list = $service->listForUnit(['status' => OutboundMessage::STATUS_QUEUED], self::ACTION);

        Assert::count(1, $list);
        Assert::count(0, $service->listForUnit(['status' => OutboundMessage::STATUS_SENT], self::ACTION));
    }

    public function testPublishPushesOnlyTypeAndMessageId(): void
    {
        $queue = new FakeQueue();

        $jobId = (new MessageQueuePublisher($queue))->publish(self::TENANT_ID, 42);

        Assert::same('fake-job-1', $jobId);
        Assert::count(1, $queue->pushed());
        $pushed = $queue->pushed()[0];
        Assert::same(['type' => 'communication.message.send', 'message_id' => 42], $pushed['payload']);
        Assert::same(MessageQueuePublisher::QUEUE, $pushed['queue']);
        Assert::same('default', $pushed['queue']);
        Assert::same(self::TENANT_ID, $pushed['tenantId']);
        Assert::same(5, $pushed['maxAttempts']);
    }
}
