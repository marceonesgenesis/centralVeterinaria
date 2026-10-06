<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Application\MessageDeliveryService;
use CentralVet\Application\MessageQueuePublisher;
use CentralVet\Application\ReminderGenerationService;
use CentralVet\Communication\CommunicationJobHandler;
use CentralVet\Communication\CommunicationScheduler;
use CentralVet\Communication\MessageDeliveryFailed;
use CentralVet\Domain\CommunicationChannel;
use CentralVet\Domain\MessagePurpose;
use CentralVet\Domain\OutboundMessage;
use CentralVet\Domain\ReminderCandidate;
use CentralVet\Observability\Logging\LoggerInterface;
use CentralVet\Queue\QueueMessage;
use CentralVet\Tenancy\TenantContext;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\FakeCommunicationPreferenceRepository;
use CentralVet\Tests\Support\FakeEmailProvider;
use CentralVet\Tests\Support\FakeMessageTemplateRepository;
use CentralVet\Tests\Support\FakeOutboundMessageRepository;
use CentralVet\Tests\Support\FakeQueue;
use CentralVet\Tests\Support\FakeReminderSourceQuery;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

/**
 * Fase 7A, T-15: handler do job de comunicação no worker e agendador dos
 * lembretes (geração + publicação dos e-mails criados e dos presos), com
 * fakes (sem Redis, sem banco, sem SMTP).
 */
final class CommunicationWorkerTest
{
    private const TENANT_ID = 2;
    private const TUTOR_ID = 7;
    private const EMAIL = 'f7a.teste@example.invalid';
    private const PHONE = '(85) 99999-0000';
    private const NOW = '2026-10-06 10:00:00';

    private FakeOutboundMessageRepository $messages;
    private FakeCommunicationPreferenceRepository $preferences;
    private FakeEmailProvider $provider;
    private LoggerInterface $logger;
    /** @var list<array{level: string, message: string, context: array<mixed>}> */
    private array $logs = [];
    private int $factoryCalls = 0;

    public function setUp(): void
    {
        $this->messages = new FakeOutboundMessageRepository(self::TENANT_ID);
        $this->preferences = new FakeCommunicationPreferenceRepository(self::TENANT_ID);
        $this->provider = new FakeEmailProvider('fake-provider');
        $this->logs = [];
        $this->factoryCalls = 0;
        $logs = &$this->logs;
        $this->logger = new class ($logs) implements LoggerInterface {
            /** @param list<array{level: string, message: string, context: array<mixed>}> $logs */
            public function __construct(private array &$logs)
            {
            }

            public function debug(string $message, array $context = []): void
            {
                $this->logs[] = ['level' => 'debug', 'message' => $message, 'context' => $context];
            }

            public function info(string $message, array $context = []): void
            {
                $this->logs[] = ['level' => 'info', 'message' => $message, 'context' => $context];
            }

            public function warning(string $message, array $context = []): void
            {
                $this->logs[] = ['level' => 'warning', 'message' => $message, 'context' => $context];
            }

            public function error(string $message, array $context = []): void
            {
                $this->logs[] = ['level' => 'error', 'message' => $message, 'context' => $context];
            }

            public function critical(string $message, array $context = []): void
            {
                $this->logs[] = ['level' => 'critical', 'message' => $message, 'context' => $context];
            }
        };
    }

    public function testSupportsOnlyTheCommunicationJobType(): void
    {
        $handler = $this->handler();

        Assert::same('communication.message.send', MessageQueuePublisher::JOB_TYPE);
        Assert::true($handler->supports(['type' => 'communication.message.send', 'message_id' => 1]));
        Assert::false($handler->supports(['type' => 'communication.message.other', 'message_id' => 1]));
        Assert::false($handler->supports(['message_id' => 1]));
        Assert::false($handler->supports([]));
    }

    public function testSuccessfulJobDeliversAndLogsOnlyIdentifiers(): void
    {
        $id = $this->seedQueuedEmail();

        $this->handler()->handle(self::job(['type' => MessageQueuePublisher::JOB_TYPE, 'message_id' => $id], self::TENANT_ID, 0, 5));

        Assert::same(1, $this->factoryCalls);
        Assert::same(OutboundMessage::STATUS_SENT, $this->messages->findById($id)->status());
        $entry = $this->lastLog();
        Assert::same('communication.job.sent', $entry['message']);
        Assert::same(['message_id' => $id, 'tenant_id' => self::TENANT_ID, 'code' => 'sent'], $entry['context']);
        Assert::false(str_contains((string) json_encode($this->logs), 'example.invalid'));
    }

    public function testLastQueueAttemptIsDeliveredAsFinalAttempt(): void
    {
        $id = $this->seedQueuedEmail();
        $this->provider->failWith('smtp_error');

        // attempts 4 of 5: the delivery service must mark the message failed
        // instead of rethrowing, which only happens with finalAttempt = true.
        $this->handler()->handle(self::job(['type' => MessageQueuePublisher::JOB_TYPE, 'message_id' => $id], self::TENANT_ID, 4, 5));

        $stored = $this->messages->findById($id);
        Assert::same(OutboundMessage::STATUS_FAILED, $stored->status());
        Assert::same('communication.job.failed', $this->lastLog()['message']);
    }

    public function testDeliveryFailureBeforeTheLastAttemptBubblesUp(): void
    {
        $id = $this->seedQueuedEmail();
        $this->provider->failWith('smtp_connect');
        $handler = $this->handler();

        Assert::throws(MessageDeliveryFailed::class, function () use ($handler, $id): void {
            $handler->handle(self::job(['type' => MessageQueuePublisher::JOB_TYPE, 'message_id' => $id], self::TENANT_ID, 3, 5));
        });
        Assert::same(OutboundMessage::STATUS_QUEUED, $this->messages->findById($id)->status());
    }

    public function testJobWithoutTenantOrValidMessageIdIsDroppedWithoutBuildingTheService(): void
    {
        $handler = $this->handler();

        $handler->handle(self::job(['type' => MessageQueuePublisher::JOB_TYPE, 'message_id' => 1], null, 0, 5));
        $handler->handle(self::job(['type' => MessageQueuePublisher::JOB_TYPE, 'message_id' => 0], self::TENANT_ID, 0, 5));
        $handler->handle(self::job(['type' => MessageQueuePublisher::JOB_TYPE, 'message_id' => 'x'], self::TENANT_ID, 0, 5));
        $handler->handle(self::job(['type' => MessageQueuePublisher::JOB_TYPE], self::TENANT_ID, 0, 5));

        Assert::same(0, $this->factoryCalls);
        Assert::count(4, $this->logs);
        foreach ($this->logs as $entry) {
            Assert::same('communication.job.invalid', $entry['message']);
        }
    }

    public function testRunOncePublishesCreatedAndStuckEmailsAndIsolatesTenantFailures(): void
    {
        $stuckId = 900;
        $this->messages->seed(self::stuckEmail($stuckId));
        $queue = new FakeQueue();
        $sources = new FakeReminderSourceQuery(appointments: [
            new ReminderCandidate(
                MessagePurpose::APPOINTMENT_CONFIRMATION,
                OutboundMessage::SOURCE_APPOINTMENT,
                11,
                5,
                self::TUTOR_ID,
                21,
                self::EMAIL,
                self::PHONE,
                ['tutor_name' => 'F7A teste', 'patient_name' => 'Rex', 'unit_name' => 'Unidade', 'clinic_name' => 'Clínica', 'appointment_date' => '07/10/2026', 'appointment_time' => '09:00'],
            ),
        ]);
        $servicesFactory = function (int $tenantId) use ($sources): array {
            if ($tenantId === 1) {
                throw new RuntimeException('F7A teste ' . self::EMAIL);
            }

            $context = TenantContext::authenticated($tenantId, 1);

            return [
                'reminders' => new ReminderGenerationService(
                    $sources,
                    $this->preferences,
                    new FakeMessageTemplateRepository($tenantId),
                    $this->messages,
                    $context,
                    static fn (): DateTimeImmutable => new DateTimeImmutable(self::NOW, new DateTimeZone('America/Fortaleza')),
                ),
                'messages' => $this->messages,
            ];
        };

        $scheduler = new CommunicationScheduler(
            static fn (): array => [
                ['id' => 1, 'timezone' => 'America/Sao_Paulo'],
                ['id' => self::TENANT_ID, 'timezone' => 'America/Fortaleza'],
            ],
            $servicesFactory,
            new MessageQueuePublisher($queue),
            $this->logger,
            7,
            static fn (): DateTimeImmutable => new DateTimeImmutable(self::NOW, new DateTimeZone('America/Fortaleza')),
        );

        $result = $scheduler->runOnce();

        $createdEmailIds = array_values(array_map(
            static fn (OutboundMessage $m): int => (int) $m->id(),
            array_filter(
                $this->messages->all(),
                static fn (OutboundMessage $m): bool => $m->id() !== $stuckId && $m->channel() === CommunicationChannel::EMAIL,
            ),
        ));
        Assert::count(1, $createdEmailIds);

        Assert::same([
            'tenants' => 2,
            'created' => 2,
            'duplicates' => 0,
            'skipped_no_consent' => 0,
            'skipped_no_contact' => 0,
            'skipped_opted_out' => 0,
            'published' => 2,
            'errors' => 1,
        ], $result);

        $published = array_map(static fn (array $job): int => $job['payload']['message_id'], $queue->pushed());
        sort($published);
        Assert::same([$createdEmailIds[0], $stuckId], $published);
        foreach ($queue->pushed() as $job) {
            Assert::same(['type' => MessageQueuePublisher::JOB_TYPE, 'message_id' => $job['payload']['message_id']], $job['payload']);
            Assert::same(self::TENANT_ID, $job['tenantId']);
        }

        $failure = array_values(array_filter($this->logs, static fn (array $e): bool => $e['message'] === 'communication.scheduler.tenant_failed'));
        Assert::count(1, $failure);
        Assert::same(['tenant_id' => 1, 'exception' => RuntimeException::class], $failure[0]['context']);
        Assert::false(str_contains((string) json_encode($this->logs), 'example.invalid'));
    }

    private function handler(): CommunicationJobHandler
    {
        return new CommunicationJobHandler(
            function (int $tenantId): MessageDeliveryService {
                $this->factoryCalls++;

                return new MessageDeliveryService(
                    $this->messages,
                    $this->preferences,
                    $this->provider,
                    TenantContext::authenticated($tenantId, 1),
                    static fn (): DateTimeImmutable => new DateTimeImmutable(self::NOW),
                );
            },
            $this->logger,
        );
    }

    private function seedQueuedEmail(): int
    {
        $message = OutboundMessage::compose(
            tenantId: self::TENANT_ID,
            systemUnitId: 5,
            tutorId: self::TUTOR_ID,
            patientId: null,
            templateId: null,
            purpose: MessagePurpose::APPOINTMENT_CONFIRMATION,
            channel: CommunicationChannel::EMAIL,
            origin: OutboundMessage::ORIGIN_AUTOMATION,
            legalBasis: MessagePurpose::LEGAL_BASIS_LEGITIMATE_INTEREST,
            sourceType: null,
            sourceId: null,
            dedupeKey: null,
            recipient: self::EMAIL,
            subject: 'F7A teste',
            bodyText: 'F7A teste corpo',
            createdBySystemUserId: null,
        );
        $this->messages->seed($message);

        return (int) $message->id();
    }

    private static function stuckEmail(int $id): OutboundMessage
    {
        return OutboundMessage::reconstitute([
            'id' => $id,
            'tenant_id' => self::TENANT_ID,
            'system_unit_id' => 5,
            'tutor_id' => self::TUTOR_ID,
            'patient_id' => null,
            'template_id' => null,
            'purpose' => MessagePurpose::APPOINTMENT_CONFIRMATION,
            'channel' => CommunicationChannel::EMAIL,
            'origin' => OutboundMessage::ORIGIN_AUTOMATION,
            'legal_basis' => MessagePurpose::LEGAL_BASIS_LEGITIMATE_INTEREST,
            'source_type' => null,
            'source_id' => null,
            'dedupe_key' => null,
            'recipient' => self::EMAIL,
            'subject' => 'F7A teste',
            'body_text' => 'F7A teste corpo',
            'status' => OutboundMessage::STATUS_QUEUED,
            'created_at' => '2026-10-06 09:00:00',
        ]);
    }

    /** @param array<mixed> $payload */
    private static function job(array $payload, ?int $tenantId, int $attempts, int $maxAttempts): QueueMessage
    {
        return new QueueMessage('job-1', 'default', $payload, $tenantId, 'corr-1', $attempts, $maxAttempts, '{}');
    }

    /** @return array{level: string, message: string, context: array<mixed>} */
    private function lastLog(): array
    {
        Assert::true($this->logs !== [], 'No log entry recorded');

        return $this->logs[array_key_last($this->logs)];
    }
}
