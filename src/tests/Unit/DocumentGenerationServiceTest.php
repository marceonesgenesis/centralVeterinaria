<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Application\DocumentGenerationResult;
use CentralVet\Application\DocumentGenerationService;
use CentralVet\Application\DocumentReadyNotifier;
use CentralVet\Domain\CommunicationChannel;
use CentralVet\Domain\CommunicationPreference;
use CentralVet\Domain\DocumentKind;
use CentralVet\Domain\Exception\DocumentGenerationFailed;
use CentralVet\Domain\Exception\DocumentSourceNotFoundException;
use CentralVet\Domain\GeneratedDocument;
use CentralVet\Domain\MessagePurpose;
use CentralVet\Domain\OutboundMessage;
use CentralVet\Tenancy\TenantContext;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\FakeCommunicationPreferenceRepository;
use CentralVet\Tests\Support\FakeDocumentContentFactory;
use CentralVet\Tests\Support\FakeDocumentRenderer;
use CentralVet\Tests\Support\FakeDocumentSourceQuery;
use CentralVet\Tests\Support\FakeGeneratedDocumentRepository;
use CentralVet\Tests\Support\FakeMessageTemplateRepository;
use CentralVet\Tests\Support\FakeOutboundMessageRepository;
use CentralVet\Tests\Support\FakeSenderNamesQuery;
use CentralVet\Tests\Support\FakeStorage;
use CentralVet\Tests\Support\FakeStoredObjectRepository;
use Closure;
use DateTimeImmutable;
use RuntimeException;

/**
 * DocumentGenerationService + DocumentReadyNotifier (Fase 7B, T-12):
 * idempotent generation under redelivery, retry/fail by attempt, object
 * cleanup when the transaction fails, and the `document_ready` notice only
 * with an explicit opt-in (legal basis `consent`).
 */
final class DocumentGenerationServiceTest
{
    private const TENANT_ID = 1;
    private const UNIT_ID = 5;
    private const PATIENT_ID = 21;
    private const TUTOR_ID = 7;
    private const USER_ID = 3;
    private const EMAIL = 'f7b.teste@example.invalid';

    private TenantContext $context;
    private FakeGeneratedDocumentRepository $documents;
    private FakeDocumentContentFactory $contents;
    private FakeDocumentRenderer $renderer;
    private FakeStorage $storage;
    private FakeStoredObjectRepository $objects;
    private FakeCommunicationPreferenceRepository $preferences;
    private FakeOutboundMessageRepository $messages;
    private FakeDocumentSourceQuery $sources;

    public function setUp(): void
    {
        $this->context = TenantContext::authenticated(self::TENANT_ID, self::USER_ID);
        $this->documents = new FakeGeneratedDocumentRepository($this->context);
        $this->contents = new FakeDocumentContentFactory();
        $this->renderer = new FakeDocumentRenderer();
        $this->storage = new FakeStorage();
        $this->objects = new FakeStoredObjectRepository(self::TENANT_ID);
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
    }

    public function testQueuedDocumentBecomesReadyWithOneStoredObject(): void
    {
        $id = $this->seedQueued(false);

        $result = $this->service()->generate($id, false);

        Assert::same(DocumentGenerationResult::READY, $result->status());
        Assert::same([], $result->emailMessageIds());
        $rows = $this->objects->allRows();
        Assert::count(1, $rows);

        $doc = $this->documents->findById($id);
        Assert::same(GeneratedDocument::STATUS_READY, $doc->status());
        Assert::same((int) $rows[0]['id'], $doc->storedObjectId());
        Assert::notNull($doc->storageKey());
        Assert::true(str_starts_with((string) $doc->storageKey(), "documents/{$id}/"));
        Assert::true($this->storage->exists((string) $doc->storageKey()));
        Assert::same('application/pdf', $this->storage->contentType((string) $doc->storageKey()));
        Assert::same($doc->fileName(), $rows[0]['original_name']);
    }

    public function testSecondGenerateOfTheSameDocumentIsSkippedWithoutNewObject(): void
    {
        $id = $this->seedQueued(false);
        $service = $this->service();
        $service->generate($id, false);
        $key = $this->documents->findById($id)->storageKey();

        $result = $service->generate($id, false);

        Assert::same(DocumentGenerationResult::SKIPPED, $result->status());
        Assert::count(1, $this->objects->allRows());
        Assert::count(1, $this->renderer->calls());
        Assert::same($key, $this->documents->findById($id)->storageKey());
    }

    public function testConcurrentClaimIsSkipped(): void
    {
        $id = $this->seedQueued(false);
        $this->documents->simulateConcurrentClaim($id);

        $result = $this->service()->generate($id, false);

        Assert::same(DocumentGenerationResult::SKIPPED, $result->status());
        Assert::count(0, $this->objects->allRows());
        Assert::count(0, $this->contents->calls());
    }

    public function testMissingDocumentIsSkipped(): void
    {
        Assert::same(DocumentGenerationResult::SKIPPED, $this->service()->generate(999, false)->status());
    }

    public function testRenderFailureBeforeTheFinalAttemptThrowsAndReleasesTheClaim(): void
    {
        $id = $this->seedQueued(false);
        $this->renderer->failWith(new RuntimeException('F7B teste Rex secret'));

        $caught = null;

        try {
            $this->service()->generate($id, false);
        } catch (DocumentGenerationFailed $e) {
            $caught = $e;
        }

        Assert::notNull($caught);
        Assert::same(DocumentGenerationFailed::RENDER_FAILED, $caught->errorCode());
        Assert::false(str_contains($caught->getMessage(), 'Rex'));
        Assert::null($caught->getPrevious());

        $doc = $this->documents->findById($id);
        Assert::same(GeneratedDocument::STATUS_QUEUED, $doc->status());
        Assert::same(DocumentGenerationFailed::RENDER_FAILED, $doc->lastErrorCode());
        // The claim was released: a new claim succeeds at once.
        Assert::true($this->documents->claim($id, new DateTimeImmutable()));
        Assert::count(0, $this->objects->allRows());
    }

    public function testRenderFailureOnTheFinalAttemptMarksFailed(): void
    {
        $id = $this->seedQueued(false);
        $this->renderer->failWith(new RuntimeException('boom'));

        $result = $this->service()->generate($id, true);

        Assert::same(DocumentGenerationResult::FAILED, $result->status());
        $doc = $this->documents->findById($id);
        Assert::same(GeneratedDocument::STATUS_FAILED, $doc->status());
        Assert::same(DocumentGenerationFailed::RENDER_FAILED, $doc->lastErrorCode());
    }

    public function testMissingSourceFailsAtOnceWithoutRethrowing(): void
    {
        $id = $this->seedQueued(false);
        $this->contents->failWith(new DocumentSourceNotFoundException());

        $result = $this->service()->generate($id, false);

        Assert::same(DocumentGenerationResult::FAILED, $result->status());
        $doc = $this->documents->findById($id);
        Assert::same(GeneratedDocument::STATUS_FAILED, $doc->status());
        Assert::same(DocumentGenerationFailed::SOURCE_NOT_FOUND, $doc->lastErrorCode());
        Assert::count(0, $this->renderer->calls());
    }

    public function testStorageFailureUsesTheStorageCode(): void
    {
        $id = $this->seedQueued(false);
        $failing = $this->failingStorage();
        $result = $this->service(storage: $failing)->generate($id, true);

        Assert::same(DocumentGenerationResult::FAILED, $result->status());
        Assert::same(DocumentGenerationFailed::STORAGE_FAILED, $this->documents->findById($id)->lastErrorCode());
    }

    public function testFailingTransactionDeletesTheStoredObject(): void
    {
        $id = $this->seedQueued(false);
        $transaction = static function (Closure $work): mixed {
            $work();

            throw new RuntimeException('commit failed');
        };

        $caught = null;

        try {
            $this->service(transaction: $transaction)->generate($id, false);
        } catch (DocumentGenerationFailed $e) {
            $caught = $e;
        }

        Assert::notNull($caught);
        Assert::same(DocumentGenerationFailed::PERSIST_FAILED, $caught->errorCode());
        $rows = $this->objects->allRows();
        Assert::count(1, $rows);
        Assert::false($this->storage->exists((string) $rows[0]['object_key']));
    }

    public function testLosingAStaleClaimRaceIsSkippedWithoutTouchingTheWinner(): void
    {
        $id = $this->seedQueued(false);
        $winnerKey = "documents/{$id}/winner.pdf";
        $documents = $this->documents;
        $storage = $this->storage;
        // Another worker reclaimed the stale claim and finished first: our markReady() finds the row ready.
        $transaction = static function (Closure $work) use ($documents, $storage, $id, $winnerKey): mixed {
            $storage->put($winnerKey, '%PDF-winner', 'application/pdf');
            $documents->markReady($id, 999, $winnerKey, 11, str_repeat('a', 64), new DateTimeImmutable());

            return $work();
        };

        $result = $this->service(transaction: $transaction)->generate($id, false);

        Assert::same(DocumentGenerationResult::SKIPPED, $result->status());
        $doc = $this->documents->findById($id);
        Assert::same(GeneratedDocument::STATUS_READY, $doc->status());
        Assert::same($winnerKey, $doc->storageKey());
        Assert::null($doc->lastErrorCode());
        Assert::true($this->storage->exists($winnerKey), 'the winner object must stay');
        $rows = $this->objects->allRows();
        Assert::count(1, $rows);
        Assert::false($this->storage->exists((string) $rows[0]['object_key']), 'the loser object is removed');

        // Final attempt: still skipped, never failed.
        $id2 = $this->seedQueued(false);
        $winnerKey = "documents/{$id2}/winner.pdf";
        $transaction2 = static function (Closure $work) use ($documents, $storage, $id2, $winnerKey): mixed {
            $storage->put($winnerKey, '%PDF-winner', 'application/pdf');
            $documents->markReady($id2, 998, $winnerKey, 11, str_repeat('b', 64), new DateTimeImmutable());

            return $work();
        };

        Assert::same(DocumentGenerationResult::SKIPPED, $this->service(transaction: $transaction2)->generate($id2, true)->status());
        Assert::same(GeneratedDocument::STATUS_READY, $this->documents->findById($id2)->status());
    }

    public function testNotifyWithoutPreferenceCreatesNoMessage(): void
    {
        $id = $this->seedQueued(true);

        $result = $this->service()->generate($id, false);

        Assert::same(DocumentGenerationResult::READY, $result->status());
        Assert::same([], $result->emailMessageIds());
        Assert::count(0, $this->messages->all());
        Assert::notNull($this->documents->findById($id)->notifiedAt());
    }

    public function testOptInOnEmailOnlyCreatesOneConsentEmail(): void
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

        $result = $this->service()->generate($id, false);

        Assert::same(DocumentGenerationResult::READY, $result->status());
        $messages = $this->messages->all();
        Assert::count(1, $messages);
        $message = $messages[0];
        Assert::same(MessagePurpose::DOCUMENT_READY, $message->purpose());
        Assert::same(CommunicationChannel::EMAIL, $message->channel());
        Assert::same(MessagePurpose::LEGAL_BASIS_CONSENT, $message->legalBasis());
        Assert::same(OutboundMessage::ORIGIN_AUTOMATION, $message->origin());
        Assert::same("document_ready:document:{$id}:email", $message->dedupeKey());
        Assert::null($message->sourceType());
        Assert::null($message->sourceId());
        Assert::same(self::EMAIL, $message->recipient());
        Assert::stringContains('F7B teste Rex', $message->bodyText());
        Assert::same([$message->id()], $result->emailMessageIds());
    }

    public function testOptOutOnEmailBlocksItAndOptInOnWhatsAppStillQueues(): void
    {
        $id = $this->seedQueued(true);
        $this->preferences->upsert(CommunicationPreference::record(
            self::TENANT_ID,
            self::TUTOR_ID,
            CommunicationChannel::EMAIL,
            CommunicationPreference::STATUS_OPTED_OUT,
            'in_person',
            self::USER_ID,
            new DateTimeImmutable('2026-10-01 09:00:00'),
        ));
        $this->preferences->upsert(CommunicationPreference::record(
            self::TENANT_ID,
            self::TUTOR_ID,
            CommunicationChannel::WHATSAPP,
            CommunicationPreference::STATUS_OPTED_IN,
            'in_person',
            self::USER_ID,
            new DateTimeImmutable('2026-10-01 09:00:00'),
        ));

        $emailIds = $this->notifier()->notify($this->documents->findById($id));

        Assert::same([], $emailIds);
        $messages = $this->messages->all();
        Assert::count(1, $messages);
        Assert::same(CommunicationChannel::WHATSAPP, $messages[0]->channel());
        Assert::same("document_ready:document:{$id}:whatsapp", $messages[0]->dedupeKey());
    }

    public function testNotifyingTheSameDocumentTwiceQueuesOneMessagePerChannel(): void
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
        $notifier = $this->notifier();

        $first = $notifier->notify($this->documents->findById($id));
        $second = $notifier->notify($this->documents->findById($id));

        $messages = $this->messages->all();
        Assert::count(1, $messages);
        Assert::same("document_ready:document:{$id}:email", $messages[0]->dedupeKey());
        Assert::same([$messages[0]->id()], $first);
        Assert::same([], $second);
    }

    public function testOptOutOnEveryChannelCreatesNoMessage(): void
    {
        $id = $this->seedQueued(true);

        foreach (CommunicationChannel::all() as $channel) {
            $this->preferences->upsert(CommunicationPreference::record(
                self::TENANT_ID,
                self::TUTOR_ID,
                $channel,
                CommunicationPreference::STATUS_OPTED_OUT,
                'in_person',
                self::USER_ID,
                new DateTimeImmutable('2026-10-01 09:00:00'),
            ));
        }

        Assert::same([], $this->notifier()->notify($this->documents->findById($id)));
        Assert::count(0, $this->messages->all());
    }

    public function testNotifierCreatesNothingWithoutUnitNames(): void
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

        $notifier = $this->notifier(new FakeSenderNamesQuery([]));

        Assert::same([], $notifier->notify($this->documents->findById($id)));
        Assert::count(0, $this->messages->all());
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

    private function notifier(?FakeSenderNamesQuery $names = null): DocumentReadyNotifier
    {
        return new DocumentReadyNotifier(
            $this->preferences,
            new FakeMessageTemplateRepository(self::TENANT_ID),
            $this->messages,
            $this->sources,
            $names ?? new FakeSenderNamesQuery([self::UNIT_ID => ['unit_name' => 'F7B teste Unidade', 'clinic_name' => 'F7B teste Clínica']]),
            $this->context,
        );
    }

    private function service(?Closure $transaction = null, ?\CentralVet\Storage\StorageInterface $storage = null): DocumentGenerationService
    {
        return new DocumentGenerationService(
            $this->documents,
            $this->contents,
            $this->renderer,
            $storage ?? $this->storage,
            $this->objects,
            $this->notifier(),
            $this->context,
            $transaction,
        );
    }

    private function failingStorage(): \CentralVet\Storage\StorageInterface
    {
        return new class () implements \CentralVet\Storage\StorageInterface {
            public function put(string $key, string $contents, string $contentType = 'application/octet-stream'): \CentralVet\Storage\StoredObjectMetadata
            {
                throw new RuntimeException('disk full');
            }

            public function get(string $key): string
            {
                throw new RuntimeException('not found');
            }

            public function exists(string $key): bool
            {
                return false;
            }

            public function delete(string $key): void
            {
            }

            public function presignedUrl(string $key, int $ttlSeconds = 300): string
            {
                return '';
            }
        };
    }
}
