<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Application\DocumentJobPublisher;
use CentralVet\Application\DocumentRequestService;
use CentralVet\Authorization\AuthorizationDecision;
use CentralVet\Authorization\AuthorizationRequest;
use CentralVet\Authorization\Contract\AuthorizationPolicyInterface;
use CentralVet\Authorization\Exception\AuthorizationDenied;
use CentralVet\Domain\DocumentKind;
use CentralVet\Domain\DocumentTemplate;
use CentralVet\Domain\Exception\DocumentNotAvailableException;
use CentralVet\Domain\Exception\DocumentSourceNotFoundException;
use CentralVet\Domain\GeneratedDocument;
use CentralVet\Storage\Exception\StorageException;
use CentralVet\Storage\StorageInterface;
use CentralVet\Storage\StoredObjectMetadata;
use CentralVet\Tenancy\TenantContext;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\CountingDocumentSourceQuery;
use CentralVet\Tests\Support\FakeAuthorizationPolicy;
use CentralVet\Tests\Support\FakeDocumentSourceQuery;
use CentralVet\Tests\Support\FakeDocumentTemplateRepository;
use CentralVet\Tests\Support\FakeGeneratedDocumentRepository;
use CentralVet\Tests\Support\FakeQueue;
use CentralVet\Tests\Support\FakeStorage;
use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;

/**
 * Unit tests for DocumentRequestService (request, retry, list, download)
 * and DocumentJobPublisher (T-10): source resolution per kind with the
 * active-unit check, frozen consent text, unresolved placeholders refused,
 * versioning per source, download without oracle (missing, other unit and
 * not ready look the same and never touch the storage) and a queue payload
 * with ids only.
 */
final class DocumentRequestServiceTest
{
    private const ACTION = 'test::generated_document';
    private const TENANT_ID = 1;
    private const UNIT_ID = 5;
    private const OTHER_UNIT_ID = 6;
    private const USER_ID = 7;
    private const PATIENT_ID = 20;
    private const PATIENT_WITHOUT_DOSES_ID = 21;
    private const TUTOR_ID = 10;
    private const PRESCRIPTION_ID = 30;
    private const OTHER_UNIT_PRESCRIPTION_ID = 31;
    private const SURGERY_ID = 40;
    private const SURGERY_WITHOUT_CONSENT_ID = 41;

    private FakeGeneratedDocumentRepository $documents;
    private FakeDocumentSourceQuery $sources;
    private FakeDocumentTemplateRepository $templates;
    private FakeStorage $storage;
    private FakeAuthorizationPolicy $policy;
    private int $storageGets = 0;

    private function build(bool $allowed = true): DocumentRequestService
    {
        $context = TenantContext::authenticated(self::TENANT_ID, self::USER_ID, self::UNIT_ID);
        $this->documents = new FakeGeneratedDocumentRepository($context);
        $this->sources = new FakeDocumentSourceQuery();
        $this->templates = new FakeDocumentTemplateRepository($context);
        $this->storage = new FakeStorage();
        $this->policy = new FakeAuthorizationPolicy(allowed: $allowed);
        $this->storageGets = 0;

        foreach ([self::PATIENT_ID, self::PATIENT_WITHOUT_DOSES_ID] as $patientId) {
            $this->sources->seedPatient([
                'patient_id' => $patientId,
                'patient_name' => 'F7B teste Rex',
                'species' => 'canine',
                'breed' => null,
                'tutor_id' => self::TUTOR_ID,
                'tutor_name' => 'F7B teste Tutor',
            ]);
        }

        $this->sources->seedVaccinations(self::PATIENT_ID, [[
            'vaccine_name' => 'V10',
            'dose_number' => 1,
            'applied_at' => '2026-09-01 10:00:00',
            'lot' => 'L1',
            'next_dose_at' => null,
            'professional_name' => 'F7B teste Vet',
        ]]);

        foreach ([self::PRESCRIPTION_ID => self::UNIT_ID, self::OTHER_UNIT_PRESCRIPTION_ID => self::OTHER_UNIT_ID] as $id => $unitId) {
            $this->sources->seedPrescription([
                'prescription_id' => $id,
                'patient_id' => self::PATIENT_ID,
                'system_unit_id' => $unitId,
                'professional_name' => 'F7B teste Vet',
                'orientation_text' => null,
                'created_at' => '2026-10-01 09:00:00',
                'items' => [],
            ]);
        }

        foreach ([self::SURGERY_ID => '2026-10-02 08:00:00', self::SURGERY_WITHOUT_CONSENT_ID => null] as $id => $recordedAt) {
            $this->sources->seedSurgery([
                'surgery_id' => $id,
                'patient_id' => self::PATIENT_ID,
                'system_unit_id' => self::UNIT_ID,
                'procedure_name' => 'Castração',
                'scheduled_start_at' => '2026-10-03 08:00:00',
                'surgeon_name' => 'F7B teste Cirurgião',
                'consent_signer_name' => $recordedAt === null ? null : 'F7B teste Tutor',
                'consent_text' => $recordedAt === null ? null : 'Autorizo o procedimento.',
                'consent_recorded_at' => $recordedAt,
            ]);
        }

        $storage = $this->storage;
        $gets = &$this->storageGets;
        $spy = new class ($storage, $gets) implements StorageInterface {
            private int $gets;

            public function __construct(private readonly FakeStorage $inner, int &$gets)
            {
                $this->gets = &$gets;
            }

            public function put(string $key, string $contents, string $contentType = 'application/octet-stream'): StoredObjectMetadata
            {
                return $this->inner->put($key, $contents, $contentType);
            }

            public function get(string $key): string
            {
                $this->gets++;

                if (!$this->inner->exists($key)) {
                    // Same contract as the real drivers (LocalFilesystemStorage, S3).
                    throw new StorageException('Stored object not found');
                }

                return $this->inner->get($key);
            }

            public function exists(string $key): bool
            {
                return $this->inner->exists($key);
            }

            public function delete(string $key): void
            {
                $this->inner->delete($key);
            }

            public function presignedUrl(string $key, int $ttlSeconds = 300): string
            {
                return $this->inner->presignedUrl($key, $ttlSeconds);
            }
        };

        return new DocumentRequestService(
            $this->documents,
            $this->sources,
            $this->templates,
            $spy,
            $this->policy,
            $context,
            static fn (): DateTimeImmutable => new DateTimeImmutable('2026-10-06 10:00:00'),
        );
    }

    /** Same collaborators as build(), with another source query or policy. */
    private function service(\CentralVet\Domain\Contract\DocumentSourceQueryInterface $sources, AuthorizationPolicyInterface $policy): DocumentRequestService
    {
        return new DocumentRequestService(
            $this->documents,
            $sources,
            $this->templates,
            $this->storage,
            $policy,
            TenantContext::authenticated(self::TENANT_ID, self::USER_ID, self::UNIT_ID),
            static fn (): DateTimeImmutable => new DateTimeImmutable('2026-10-06 10:00:00'),
        );
    }

    /** Seeds a persisted document with an explicit id, status and unit. */
    private function seedDocument(int $id, string $status, int $unitId, ?string $storageKey = null): void
    {
        $this->documents->seed(GeneratedDocument::reconstitute([
            'id' => $id,
            'tenant_id' => self::TENANT_ID,
            'system_unit_id' => $unitId,
            'patient_id' => self::PATIENT_ID,
            'tutor_id' => self::TUTOR_ID,
            'kind' => DocumentKind::VACCINATION_CARD,
            'source_type' => DocumentKind::SOURCE_PATIENT,
            'source_id' => self::PATIENT_ID,
            'version' => 1,
            'template_id' => null,
            'title' => 'Carteira de vacinação',
            'body_text' => null,
            'notify_tutor' => 0,
            'status' => $status,
            'attempt_count' => 1,
            'stored_object_id' => $storageKey === null ? null : 99,
            'storage_key' => $storageKey,
            'last_error_code' => $status === GeneratedDocument::STATUS_FAILED ? 'render_failed' : null,
            'ready_at' => $status === GeneratedDocument::STATUS_READY ? '2026-10-06 09:00:00' : null,
            'notified_at' => null,
            'requested_by_system_user_id' => self::USER_ID,
            'created_at' => '2026-10-06 08:00:00',
        ]));
    }

    public function testVaccinationCardIsQueuedAndSecondRequestIsVersionTwo(): void
    {
        $service = $this->build();

        $first = $service->request(DocumentKind::VACCINATION_CARD, self::PATIENT_ID, null, null, true, self::ACTION);
        $second = $service->request(DocumentKind::VACCINATION_CARD, self::PATIENT_ID, null, null, false, self::ACTION);

        Assert::same(GeneratedDocument::STATUS_QUEUED, $first->status());
        Assert::same(1, $first->version());
        Assert::same(2, $second->version());
        Assert::same(self::UNIT_ID, $first->systemUnitId());
        Assert::same(self::TUTOR_ID, $first->tutorId());
        Assert::same(self::USER_ID, $first->requestedBySystemUserId());
        Assert::true($first->notifyTutor());
        Assert::count(2, $this->documents->all());

        $request = $this->policy->requests[0];
        Assert::same(self::UNIT_ID, $request->resourceUnitId());
        Assert::same('generated_document', $request->entityType());
        Assert::null($request->entityId());
        Assert::same(['kind' => DocumentKind::VACCINATION_CARD, 'source_id' => self::PATIENT_ID], $request->metadata());
    }

    public function testVaccinationCardRefusesUnknownPatientAndPatientWithoutDoses(): void
    {
        $service = $this->build();

        Assert::throws(DocumentSourceNotFoundException::class, fn () => $service->request(DocumentKind::VACCINATION_CARD, 999, null, null, false, self::ACTION));
        Assert::throws(InvalidArgumentException::class, fn () => $service->request(DocumentKind::VACCINATION_CARD, self::PATIENT_WITHOUT_DOSES_ID, null, null, false, self::ACTION));
        Assert::count(0, $this->documents->all());
    }

    public function testUnknownKindIsRefused(): void
    {
        $service = $this->build();

        Assert::throws(InvalidArgumentException::class, fn () => $service->request('invoice', self::PATIENT_ID, null, null, false, self::ACTION));
    }

    public function testPrescriptionOfAnotherUnitIsNotFound(): void
    {
        $service = $this->build();

        Assert::throws(DocumentSourceNotFoundException::class, fn () => $service->request(DocumentKind::PRESCRIPTION, self::OTHER_UNIT_PRESCRIPTION_ID, null, null, false, self::ACTION));
        Assert::throws(DocumentSourceNotFoundException::class, fn () => $service->request(DocumentKind::PRESCRIPTION, 999, null, null, false, self::ACTION));
        Assert::count(0, $this->documents->all());

        $document = $service->request(DocumentKind::PRESCRIPTION, self::PRESCRIPTION_ID, null, null, false, self::ACTION);
        Assert::same(self::PATIENT_ID, $document->patientId());
        Assert::same(DocumentKind::SOURCE_PRESCRIPTION, $document->sourceType());
    }

    public function testSurgeryConsentRequiresRecordedConsentAndFreezesTheText(): void
    {
        $service = $this->build();

        Assert::throws(InvalidArgumentException::class, fn () => $service->request(DocumentKind::SURGERY_CONSENT, self::SURGERY_WITHOUT_CONSENT_ID, null, null, false, self::ACTION));
        Assert::count(0, $this->documents->all());

        $document = $service->request(DocumentKind::SURGERY_CONSENT, self::SURGERY_ID, null, 'ignored', false, self::ACTION);
        Assert::same("F7B teste Tutor\n\nAutorizo o procedimento.", $document->bodyText());
    }

    public function testMedicalCertificateRefusesUnresolvedPlaceholdersAndUnavailableTemplate(): void
    {
        $service = $this->build();

        Assert::throws(InvalidArgumentException::class, fn () => $service->request(DocumentKind::MEDICAL_CERTIFICATE, self::PATIENT_ID, null, 'Atesto que {{patient_name}} está apto.', false, self::ACTION));
        Assert::throws(InvalidArgumentException::class, fn () => $service->request(DocumentKind::MEDICAL_CERTIFICATE, self::PATIENT_ID, 999, 'Atesto que Rex está apto.', false, self::ACTION));

        $template = DocumentTemplate::create(self::TENANT_ID, DocumentKind::MEDICAL_CERTIFICATE, 'F7B teste Atestado', 'Atesto {{patient_name}}', self::USER_ID);
        $template->update('F7B teste Atestado', 'Atesto {{patient_name}}', DocumentTemplate::STATUS_INACTIVE, self::USER_ID);
        $this->templates->save($template);
        Assert::throws(InvalidArgumentException::class, fn () => $service->request(DocumentKind::MEDICAL_CERTIFICATE, self::PATIENT_ID, (int) $template->id(), 'Atesto que Rex está apto.', false, self::ACTION));
        Assert::count(0, $this->documents->all());

        $active = $this->templates->save(DocumentTemplate::create(self::TENANT_ID, DocumentKind::MEDICAL_CERTIFICATE, 'F7B teste Ativo', 'Atesto {{patient_name}}', self::USER_ID));
        $document = $service->request(DocumentKind::MEDICAL_CERTIFICATE, self::PATIENT_ID, (int) $active->id(), 'Atesto que Rex está apto.', false, self::ACTION);
        Assert::same((int) $active->id(), $document->templateId());
        Assert::same('Atesto que Rex está apto.', $document->bodyText());
    }

    public function testDeniedRequestStoresNothing(): void
    {
        $service = $this->build(allowed: false);

        Assert::throws(AuthorizationDenied::class, fn () => $service->request(DocumentKind::VACCINATION_CARD, self::PATIENT_ID, null, null, false, self::ACTION));
        Assert::count(0, $this->documents->all());
    }

    public function testDownloadOfQueuedOtherUnitOrMissingIsNotAvailableWithoutTouchingStorage(): void
    {
        $service = $this->build();
        $this->storage->put('k/queued.pdf', '%PDF-1');
        $this->storage->put('k/other.pdf', '%PDF-1');
        $this->seedDocument(1, GeneratedDocument::STATUS_QUEUED, self::UNIT_ID);
        $this->seedDocument(2, GeneratedDocument::STATUS_READY, self::OTHER_UNIT_ID, 'k/other.pdf');

        Assert::throws(DocumentNotAvailableException::class, fn () => $service->download(1, self::ACTION));
        Assert::throws(DocumentNotAvailableException::class, fn () => $service->download(2, self::ACTION));
        Assert::throws(DocumentNotAvailableException::class, fn () => $service->download(999, self::ACTION));
        Assert::same(0, $this->storageGets, 'storage must not be read');
        Assert::count(0, $this->policy->requests, 'the availability check comes before authorization');
    }

    public function testDownloadOfReadyDocumentReturnsPdfWithNeutralFileName(): void
    {
        $service = $this->build();
        $this->storage->put('k/ready.pdf', '%PDF-1.7 fake');
        $this->seedDocument(3, GeneratedDocument::STATUS_READY, self::UNIT_ID, 'k/ready.pdf');

        $file = $service->download(3, self::ACTION);

        Assert::same('%PDF-1.7 fake', $file['contents']);
        Assert::same('application/pdf', $file['content_type']);
        Assert::same('vaccination_card-3-v1.pdf', $file['file_name']);
        Assert::same(self::UNIT_ID, $this->policy->requests[0]->resourceUnitId());
        Assert::same(3, $this->policy->requests[0]->entityId());
    }

    public function testDownloadWithMissingObjectIsNotAvailable(): void
    {
        $service = $this->build();
        $this->seedDocument(4, GeneratedDocument::STATUS_READY, self::UNIT_ID, 'k/missing.pdf');

        Assert::throws(DocumentNotAvailableException::class, fn () => $service->download(4, self::ACTION));
    }

    public function testRetryRequeuesOnlyFailedDocumentsOfTheActiveUnit(): void
    {
        $service = $this->build();
        $this->seedDocument(5, GeneratedDocument::STATUS_FAILED, self::UNIT_ID);
        $this->seedDocument(6, GeneratedDocument::STATUS_QUEUED, self::UNIT_ID);
        $this->seedDocument(7, GeneratedDocument::STATUS_FAILED, self::OTHER_UNIT_ID);

        $service->retry(5, self::ACTION);

        Assert::same(GeneratedDocument::STATUS_QUEUED, $this->documents->findById(5)?->status());
        Assert::throws(DocumentNotAvailableException::class, fn () => $service->retry(6, self::ACTION));
        Assert::throws(DocumentNotAvailableException::class, fn () => $service->retry(7, self::ACTION));
        Assert::throws(DocumentNotAvailableException::class, fn () => $service->retry(999, self::ACTION));
    }

    public function testRetryThatLosesTheRequeueRaceThrows(): void
    {
        $this->build();
        $this->seedDocument(5, GeneratedDocument::STATUS_FAILED, self::UNIT_ID);
        $documents = $this->documents;
        // Another request requeues the document between the read and the conditional update.
        $racing = new class ($documents) implements AuthorizationPolicyInterface {
            public function __construct(private readonly FakeGeneratedDocumentRepository $documents)
            {
            }

            public function decide(AuthorizationRequest $request): AuthorizationDecision
            {
                $this->documents->requeueFailed(5);

                return new AuthorizationDecision(true, 'granted', 'test-correlation-id');
            }
        };

        Assert::throws(RuntimeException::class, fn () => $this->service($this->sources, $racing)->retry(5, self::ACTION));
        Assert::same(GeneratedDocument::STATUS_QUEUED, $this->documents->findById(5)?->status());
    }

    public function testRetryAndDownloadAuditCarryOnlyDocumentIdKindAndVersion(): void
    {
        $service = $this->build();
        $this->seedDocument(5, GeneratedDocument::STATUS_FAILED, self::UNIT_ID);
        $this->storage->put('k/ready.pdf', '%PDF-1.7 fake');
        $this->seedDocument(3, GeneratedDocument::STATUS_READY, self::UNIT_ID, 'k/ready.pdf');

        $service->retry(5, self::ACTION);
        $service->download(3, self::ACTION);

        Assert::count(2, $this->policy->requests);
        $expected = [
            [5, ['document_id' => 5, 'kind' => DocumentKind::VACCINATION_CARD, 'version' => 1]],
            [3, ['document_id' => 3, 'kind' => DocumentKind::VACCINATION_CARD, 'version' => 1]],
        ];

        foreach ($expected as $i => [$id, $metadata]) {
            $request = $this->policy->requests[$i];
            Assert::same(self::ACTION, $request->action());
            Assert::same('generated_document', $request->entityType());
            Assert::same($id, $request->entityId());
            Assert::same(self::UNIT_ID, $request->resourceUnitId());
            Assert::same($metadata, $request->metadata());
        }
    }

    public function testRequestReadsThePatientSummaryOnce(): void
    {
        $this->build();
        $counting = new CountingDocumentSourceQuery($this->sources);
        $service = $this->service($counting, $this->policy);
        $cases = [
            [DocumentKind::VACCINATION_CARD, self::PATIENT_ID, null],
            [DocumentKind::PRESCRIPTION, self::PRESCRIPTION_ID, null],
            [DocumentKind::SURGERY_CONSENT, self::SURGERY_ID, null],
            [DocumentKind::MEDICAL_CERTIFICATE, self::PATIENT_ID, 'Atesto que Rex está apto.'],
        ];
        $expected = 0;

        foreach ($cases as [$kind, $sourceId, $body]) {
            $service->request($kind, $sourceId, null, $body, false, self::ACTION);
            Assert::same(++$expected, $counting->calls('patientSummary'), "patientSummary once for {$kind}");
        }
    }

    public function testMedicalCertificateResolvesOptionalBreedAndRefusesUnknownTokens(): void
    {
        $service = $this->build();

        $document = $service->request(DocumentKind::MEDICAL_CERTIFICATE, self::PATIENT_ID, null, 'Atesto que Rex ({{breed}}) está apto.', false, self::ACTION);
        Assert::same('Atesto que Rex (—) está apto.', $document->bodyText());

        Assert::throws(InvalidArgumentException::class, fn () => $service->request(DocumentKind::MEDICAL_CERTIFICATE, self::PATIENT_ID, null, 'Atesto {{cpf}}.', false, self::ACTION));
        Assert::throws(InvalidArgumentException::class, fn () => $service->request(DocumentKind::MEDICAL_CERTIFICATE, self::PATIENT_ID, null, 'Atesto {{tutor_name}} e {{breed}}.', false, self::ACTION));
        Assert::count(1, $this->documents->all());
    }

    public function testListForUnitReturnsOnlyActiveUnitDocuments(): void
    {
        $service = $this->build();
        $this->seedDocument(9, GeneratedDocument::STATUS_READY, self::UNIT_ID, 'k/a.pdf');
        $this->seedDocument(10, GeneratedDocument::STATUS_READY, self::OTHER_UNIT_ID, 'k/b.pdf');

        $list = $service->listForUnit(self::PATIENT_ID, self::ACTION);

        Assert::count(1, $list);
        Assert::same(9, $list[0]->id());
        Assert::same(self::UNIT_ID, $this->policy->requests[0]->resourceUnitId());
    }

    public function testPublishPushesOnlyTypeAndDocumentId(): void
    {
        $queue = new FakeQueue();

        $jobId = (new DocumentJobPublisher($queue))->publish(self::TENANT_ID, 42);

        Assert::same('fake-job-1', $jobId);
        Assert::count(1, $queue->pushed());
        $pushed = $queue->pushed()[0];
        Assert::same(['type' => 'document.generate', 'document_id' => 42], $pushed['payload']);
        Assert::same('default', $pushed['queue']);
        Assert::same(self::TENANT_ID, $pushed['tenantId']);
        Assert::same(3, $pushed['maxAttempts']);
        Assert::same('document.generate', DocumentJobPublisher::JOB_TYPE);
        Assert::same(3, DocumentJobPublisher::MAX_ATTEMPTS);
    }
}
