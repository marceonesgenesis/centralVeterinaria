<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Application\DocumentGenerationService;
use CentralVet\Application\DocumentJobPublisher;
use CentralVet\Application\DocumentReadyNotifier;
use CentralVet\Application\MessageQueuePublisher;
use CentralVet\Document\DocumentJobHandler;
use CentralVet\Document\DocumentSweeper;
use CentralVet\Domain\CommunicationChannel;
use CentralVet\Domain\CommunicationPreference;
use CentralVet\Domain\DocumentKind;
use CentralVet\Domain\Exception\DocumentGenerationFailed;
use CentralVet\Domain\GeneratedDocument;
use CentralVet\Observability\Logging\LoggerInterface;
use CentralVet\Queue\QueueMessage;
use CentralVet\Tenancy\TenantContext;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\FakeCommunicationPreferenceRepository;
use CentralVet\Tests\Support\FakeDocumentContentFactory;
use CentralVet\Tests\Support\FakeDocumentRenderer;
use CentralVet\Tests\Support\FakeDocumentSourceQuery;
use CentralVet\Tests\Support\FakeGeneratedDocumentRepository;
use CentralVet\Tests\Support\FakeMessageTemplateRepository;
use CentralVet\Tests\Support\FakeOutboundMessageRepository;
use CentralVet\Tests\Support\FakeQueue;
use CentralVet\Tests\Support\FakeSenderNamesQuery;
use CentralVet\Tests\Support\FakeStorage;
use CentralVet\Tests\Support\FakeStoredObjectRepository;
use DateTimeImmutable;
use RuntimeException;

/**
 * Fase 7B, T-14: handler do job `document.generate` no worker e varredor dos
 * documentos presos em `queued`, com fakes (sem Redis, sem banco, sem dompdf).
 */
final class DocumentWorkerTest
{
    private const TENANT_ID = 2;
    private const UNIT_ID = 5;
    private const PATIENT_ID = 21;
    private const TUTOR_ID = 7;
    private const USER_ID = 3;
    private const EMAIL = 'f7b.teste@example.invalid';

    private TenantContext $context;
    private FakeGeneratedDocumentRepository $documents;
    private FakeDocumentRenderer $renderer;
    private FakeCommunicationPreferenceRepository $preferences;
    private FakeOutboundMessageRepository $messages;
    private FakeDocumentSourceQuery $sources;
    private FakeQueue $queue;
    private LoggerInterface $logger;
    /** @var list<array{level: string, message: string, context: array<mixed>}> */
    private array $logs = [];
    private int $factoryCalls = 0;

    public function setUp(): void
    {
        $this->context = TenantContext::authenticated(self::TENANT_ID, self::USER_ID);
        $this->documents = new FakeGeneratedDocumentRepository($this->context);
        $this->renderer = new FakeDocumentRenderer();
        $this->preferences = new FakeCommunicationPreferenceRepository(self::TENANT_ID);
        $this->messages = new FakeOutboundMessageRepository(self::TENANT_ID);
        $this->sources = new FakeDocumentSourceQuery();
        $this->sources->seedPatient([
            'patient_id' => self::PATIENT_ID,
            'patient_name' => 'F7B teste Rex',
            'species' => 'dog',
            'breed' => null,
            'tutor_id' => self::TUTOR_ID,
            'tutor_name' => 'F7B teste Tutor',
        ]);
        $this->sources->seedTutorContact(self::TUTOR_ID, [
            'tutor_name' => 'F7B teste Tutor',
            'email' => self::EMAIL,
            'phone' => '(85) 99999-0000',
        ]);
        $this->queue = new FakeQueue();
        $this->factoryCalls = 0;
        $this->logs = [];
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

    public function testSupportsOnlyTheDocumentJobType(): void
    {
        $handler = $this->handler();

        Assert::same('document.generate', DocumentJobPublisher::JOB_TYPE);
        Assert::true($handler->supports(['type' => 'document.generate', 'document_id' => 1]));
        Assert::false($handler->supports(['type' => MessageQueuePublisher::JOB_TYPE, 'message_id' => 1]));
        Assert::false($handler->supports(['document_id' => 1]));
        Assert::false($handler->supports([]));
    }

    public function testLastQueueAttemptGeneratesAsFinalAttempt(): void
    {
        $id = $this->seedQueued(false);
        $this->renderer->failWith(new RuntimeException('F7B teste Rex secret'));

        // attempts 2 of 3: only finalAttempt = true marks the document failed
        // instead of rethrowing DocumentGenerationFailed.
        $this->handler()->handle(self::job(['type' => DocumentJobPublisher::JOB_TYPE, 'document_id' => $id], self::TENANT_ID, 2, 3));

        Assert::same(1, $this->factoryCalls);
        Assert::same(GeneratedDocument::STATUS_FAILED, $this->documents->findById($id)->status());
        $entry = $this->lastLog();
        Assert::same('document.job.failed', $entry['message']);
        Assert::same(['document_id' => $id, 'tenant_id' => self::TENANT_ID, 'code' => 'failed'], $entry['context']);
        Assert::false(str_contains((string) json_encode($this->logs), 'F7B teste'));
    }

    public function testGenerationFailureBeforeTheLastAttemptBubblesUp(): void
    {
        $id = $this->seedQueued(false);
        $this->renderer->failWith(new RuntimeException('boom'));
        $handler = $this->handler();

        Assert::throws(DocumentGenerationFailed::class, function () use ($handler, $id): void {
            $handler->handle(self::job(['type' => DocumentJobPublisher::JOB_TYPE, 'document_id' => $id], self::TENANT_ID, 0, 3));
        });
        Assert::same(GeneratedDocument::STATUS_QUEUED, $this->documents->findById($id)->status());
        Assert::same([], $this->queue->pushed());
    }

    public function testJobWithoutTenantOrValidDocumentIdIsDroppedWithoutBuildingTheServices(): void
    {
        $handler = $this->handler();

        $handler->handle(self::job(['type' => DocumentJobPublisher::JOB_TYPE, 'document_id' => 1], null, 0, 3));
        $handler->handle(self::job(['type' => DocumentJobPublisher::JOB_TYPE, 'document_id' => 0], self::TENANT_ID, 0, 3));
        $handler->handle(self::job(['type' => DocumentJobPublisher::JOB_TYPE, 'document_id' => 'x'], self::TENANT_ID, 0, 3));
        $handler->handle(self::job(['type' => DocumentJobPublisher::JOB_TYPE], self::TENANT_ID, 0, 3));

        Assert::same(0, $this->factoryCalls);
        Assert::count(4, $this->logs);
        foreach ($this->logs as $entry) {
            Assert::same('document.job.invalid', $entry['message']);
        }
    }

    public function testReadyDocumentWithOptInPublishesTheEmailSendJob(): void
    {
        $id = $this->seedQueued(true);
        $this->preferences->upsert(CommunicationPreference::record(
            self::TENANT_ID,
            self::TUTOR_ID,
            CommunicationChannel::EMAIL,
            CommunicationPreference::STATUS_OPTED_IN,
            'in_person',
            self::USER_ID,
            new DateTimeImmutable('2026-10-01 09:00:00'),
        ));

        $this->handler()->handle(self::job(['type' => DocumentJobPublisher::JOB_TYPE, 'document_id' => $id], self::TENANT_ID, 0, 3));

        Assert::same(GeneratedDocument::STATUS_READY, $this->documents->findById($id)->status());
        $messages = $this->messages->all();
        Assert::count(1, $messages);
        $pushed = $this->queue->pushed();
        Assert::count(1, $pushed);
        Assert::same(MessageQueuePublisher::JOB_TYPE, $pushed[0]['payload']['type']);
        Assert::same($messages[0]->id(), $pushed[0]['payload']['message_id']);
        Assert::same(self::TENANT_ID, $pushed[0]['tenantId']);
        $entry = $this->lastLog();
        Assert::same('document.job.ready', $entry['message']);
        Assert::same(['document_id' => $id, 'tenant_id' => self::TENANT_ID, 'code' => 'ready'], $entry['context']);
        Assert::false(str_contains((string) json_encode($this->logs), 'example.invalid'));
        Assert::false(str_contains((string) json_encode($this->logs), 'F7B teste'));
    }

    public function testRunOnceRepublishesStuckDocumentsAndIsolatesTenantFailures(): void
    {
        $stuckA = $this->seedQueued(false);
        $stuckB = $this->seedQueued(false);
        $sweeper = new DocumentSweeper(
            static fn (): array => [1, self::TENANT_ID],
            function (int $tenantId): FakeGeneratedDocumentRepository {
                if ($tenantId === 1) {
                    throw new RuntimeException('F7B teste ' . self::EMAIL);
                }

                return $this->documents;
            },
            new DocumentJobPublisher($this->queue),
            $this->logger,
            static fn (): DateTimeImmutable => new DateTimeImmutable('+1 hour'),
        );

        $result = $sweeper->runOnce();

        Assert::same(['tenants' => 2, 'republished' => 2, 'errors' => 1], $result);
        $pushed = $this->queue->pushed();
        Assert::count(2, $pushed);
        Assert::same(
            [[DocumentJobPublisher::JOB_TYPE, $stuckA, self::TENANT_ID], [DocumentJobPublisher::JOB_TYPE, $stuckB, self::TENANT_ID]],
            array_map(static fn (array $p): array => [$p['payload']['type'], $p['payload']['document_id'], $p['tenantId']], $pushed),
        );
        Assert::same(DocumentJobPublisher::MAX_ATTEMPTS, $pushed[0]['maxAttempts']);
        Assert::false(str_contains((string) json_encode($this->logs), 'example.invalid'));
        Assert::false(str_contains((string) json_encode($this->logs), 'F7B teste'));
    }

    public function testSweeperOnlyRepublishesDocumentsOlderThanTenMinutes(): void
    {
        $requestedAt = new DateTimeImmutable();
        $stuck = $this->seedQueued(false);

        $nineMinutesLater = $this->sweeper($requestedAt->modify('+9 minutes'))->runOnce();
        Assert::same(['tenants' => 1, 'republished' => 0, 'errors' => 0], $nineMinutesLater);
        Assert::count(0, $this->queue->pushed());

        $elevenMinutesLater = $this->sweeper($requestedAt->modify('+11 minutes'))->runOnce();
        Assert::same(['tenants' => 1, 'republished' => 1, 'errors' => 0], $elevenMinutesLater);
        Assert::same($stuck, $this->queue->pushed()[0]['payload']['document_id']);
    }

    public function testSweeperRepublishesAtMostOneHundredOldestPerTenant(): void
    {
        $ids = [];
        for ($i = 0; $i < 101; $i++) {
            $ids[] = $this->seedQueued(false);
        }

        $result = $this->sweeper(new DateTimeImmutable('+1 hour'))->runOnce();

        Assert::same(['tenants' => 1, 'republished' => 100, 'errors' => 0], $result);
        Assert::same(
            array_slice($ids, 0, 100),
            array_map(static fn (array $p): int => $p['payload']['document_id'], $this->queue->pushed()),
        );
    }

    private function sweeper(DateTimeImmutable $now): DocumentSweeper
    {
        return new DocumentSweeper(
            static fn (): array => [self::TENANT_ID],
            fn (int $tenantId): FakeGeneratedDocumentRepository => $this->documents,
            new DocumentJobPublisher($this->queue),
            $this->logger,
            static fn (): DateTimeImmutable => $now,
        );
    }

    private function handler(): DocumentJobHandler
    {
        return new DocumentJobHandler(
            function (int $tenantId): array {
                $this->factoryCalls++;
                Assert::same(self::TENANT_ID, $tenantId);

                return [
                    new DocumentGenerationService(
                        $this->documents,
                        new FakeDocumentContentFactory(),
                        $this->renderer,
                        new FakeStorage(),
                        new FakeStoredObjectRepository(self::TENANT_ID),
                        new DocumentReadyNotifier(
                            $this->preferences,
                            new FakeMessageTemplateRepository(self::TENANT_ID),
                            $this->messages,
                            $this->sources,
                            new FakeSenderNamesQuery([self::UNIT_ID => ['unit_name' => 'F7B teste Unidade', 'clinic_name' => 'F7B teste Clínica']]),
                            $this->context,
                        ),
                        $this->context,
                    ),
                    new MessageQueuePublisher($this->queue),
                ];
            },
            $this->logger,
        );
    }

    private function seedQueued(bool $notifyTutor): int
    {
        $doc = GeneratedDocument::request(
            self::TENANT_ID,
            self::UNIT_ID,
            self::PATIENT_ID,
            self::TUTOR_ID,
            DocumentKind::VACCINATION_CARD,
            self::PATIENT_ID,
            null,
            null,
            $notifyTutor,
            self::USER_ID,
        );

        return (int) $this->documents->insertNextVersion($doc)->id();
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
