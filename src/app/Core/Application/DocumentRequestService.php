<?php

declare(strict_types=1);

namespace CentralVet\Application;

use CentralVet\Authorization\AuthorizationRequest;
use CentralVet\Authorization\Contract\AuthorizationPolicyInterface;
use CentralVet\Domain\Contract\DocumentSourceQueryInterface;
use CentralVet\Domain\Contract\DocumentTemplateRepositoryInterface;
use CentralVet\Domain\Contract\GeneratedDocumentRepositoryInterface;
use CentralVet\Domain\DocumentKind;
use CentralVet\Domain\DocumentTemplateRenderer;
use CentralVet\Domain\Exception\DocumentNotAvailableException;
use CentralVet\Domain\Exception\DocumentSourceNotFoundException;
use CentralVet\Domain\GeneratedDocument;
use CentralVet\Storage\Exception\StorageException;
use CentralVet\Storage\StorageInterface;
use CentralVet\Tenancy\TenantContext;
use Closure;
use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;

/**
 * Request, retry, list and download of generated PDF documents (Fase 7B).
 *
 * `request` resolves the source of the kind (patient, prescription or
 * surgery), checks it against the active unit, freezes the text of the
 * certificate and of the surgical consent, authorizes (entity
 * `generated_document`, metadata with kind and source id only) and inserts
 * the next version of the source as `queued`. The service opens no
 * transaction and publishes nothing: the controller requests inside
 * `TTransaction`, commits and then calls `DocumentJobPublisher::publish`.
 *
 * `download` and `retry` answer `DocumentNotAvailableException` (one single
 * message) for a missing document, one of another unit or one in the
 * wrong state, before any authorization or storage read, so they cannot
 * be used as an oracle of other units' documents. Exception messages carry
 * ids only, never names or document text.
 */
final class DocumentRequestService
{
    private const ENTITY_TYPE = 'generated_document';
    private const LIST_LIMIT = 200;
    private const CONTENT_TYPE = 'application/pdf';

    private readonly Closure $clock;

    public function __construct(
        private readonly GeneratedDocumentRepositoryInterface $documents,
        private readonly DocumentSourceQueryInterface $sources,
        private readonly DocumentTemplateRepositoryInterface $templates,
        private readonly StorageInterface $storage,
        private readonly AuthorizationPolicyInterface $authorization,
        private readonly TenantContext $context,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): DateTimeImmutable => new DateTimeImmutable();
    }

    /**
     * @throws DocumentSourceNotFoundException when the source is missing or belongs to another unit.
     * @throws InvalidArgumentException for an unknown kind or a source/text that cannot be printed.
     * @throws \CentralVet\Authorization\Exception\AuthorizationDenied
     */
    public function request(
        string $kind,
        int $sourceId,
        ?int $templateId,
        ?string $bodyText,
        bool $notifyTutor,
        string $action,
    ): GeneratedDocument {
        DocumentKind::assertValid($kind);
        $unitId = $this->context->requireUnitId();

        [$patientId, $bodyText, $templateId] = match ($kind) {
            DocumentKind::VACCINATION_CARD => $this->resolveVaccinationCard($sourceId),
            DocumentKind::PRESCRIPTION => $this->resolvePrescription($sourceId, $unitId),
            DocumentKind::SURGERY_CONSENT => $this->resolveSurgeryConsent($sourceId, $unitId),
            DocumentKind::MEDICAL_CERTIFICATE => $this->resolveMedicalCertificate($sourceId, $templateId, $bodyText),
        };

        $patient = $this->requirePatient($patientId);

        $this->authorization->decide(new AuthorizationRequest(
            context: $this->context,
            action: $action,
            requiresUnitScope: true,
            resourceUnitId: $unitId,
            entityType: self::ENTITY_TYPE,
            entityId: null,
            metadata: ['kind' => $kind, 'source_id' => $sourceId],
        ))->assertAllowed();

        return $this->documents->insertNextVersion(GeneratedDocument::request(
            tenantId: $this->context->tenantId(),
            systemUnitId: $unitId,
            patientId: (int) $patient['patient_id'],
            tutorId: (int) $patient['tutor_id'],
            kind: $kind,
            sourceId: $sourceId,
            templateId: $templateId,
            bodyText: $bodyText,
            notifyTutor: $notifyTutor,
            requestedBySystemUserId: $this->context->userId(),
        ));
    }

    /**
     * Puts a `failed` document of the active unit back in the queue (same
     * row and version). The caller publishes the job after commit.
     *
     * @throws DocumentNotAvailableException when the document is missing, of another unit or not failed.
     * @throws RuntimeException when a concurrent touch already requeued it.
     */
    public function retry(int $documentId, string $action): void
    {
        $unitId = $this->context->requireUnitId();
        $document = $this->documents->findById($documentId);

        if ($document === null
            || $document->systemUnitId() !== $unitId
            || $document->status() !== GeneratedDocument::STATUS_FAILED) {
            throw new DocumentNotAvailableException();
        }

        $this->authorizeDocument($action, $document);

        if (!$this->documents->requeueFailed($documentId)) {
            throw new RuntimeException("Document {$documentId} can no longer be retried");
        }
    }

    /** @return list<GeneratedDocument> */
    public function listForUnit(?int $patientId, string $action): array
    {
        $unitId = $this->context->requireUnitId();

        $this->authorization->decide(new AuthorizationRequest(
            context: $this->context,
            action: $action,
            requiresUnitScope: true,
            resourceUnitId: $unitId,
            entityType: self::ENTITY_TYPE,
            entityId: null,
        ))->assertAllowed();

        return $this->documents->listForUnit($unitId, $patientId, self::LIST_LIMIT);
    }

    /**
     * @return array{contents: string, content_type: string, file_name: string}
     *
     * @throws DocumentNotAvailableException when missing, of another unit, not ready or absent from the storage.
     * @throws \CentralVet\Authorization\Exception\AuthorizationDenied
     */
    public function download(int $documentId, string $action): array
    {
        $unitId = $this->context->requireUnitId();
        $document = $this->documents->findById($documentId);

        // Availability before authorization: no oracle of other units' documents.
        if ($document === null || $document->systemUnitId() !== $unitId || !$document->isDownloadable()) {
            throw new DocumentNotAvailableException();
        }

        $this->authorizeDocument($action, $document);

        try {
            $contents = $this->storage->get((string) $document->storageKey());
        } catch (StorageException) {
            throw new DocumentNotAvailableException();
        }

        return [
            'contents' => $contents,
            'content_type' => self::CONTENT_TYPE,
            'file_name' => $document->fileName(),
        ];
    }

    /** @return array{0: int, 1: null, 2: null} */
    private function resolveVaccinationCard(int $patientId): array
    {
        $this->requirePatient($patientId);

        if ($this->sources->vaccinations($patientId) === []) {
            throw new InvalidArgumentException('Patient has no vaccinations to print');
        }

        return [$patientId, null, null];
    }

    /** @return array{0: int, 1: null, 2: null} */
    private function resolvePrescription(int $prescriptionId, int $unitId): array
    {
        $prescription = $this->sources->prescription($prescriptionId);

        if ($prescription === null || (int) $prescription['system_unit_id'] !== $unitId) {
            throw new DocumentSourceNotFoundException();
        }

        return [(int) $prescription['patient_id'], null, null];
    }

    /** @return array{0: int, 1: string, 2: null} */
    private function resolveSurgeryConsent(int $surgeryId, int $unitId): array
    {
        $surgery = $this->sources->surgery($surgeryId);

        if ($surgery === null || (int) $surgery['system_unit_id'] !== $unitId) {
            throw new DocumentSourceNotFoundException();
        }

        if ($surgery['consent_recorded_at'] === null) {
            throw new InvalidArgumentException('Surgery consent has not been recorded');
        }

        // Snapshot of the consent recorded on the surgery; the request text is ignored.
        $bodyText = (string) $surgery['consent_signer_name'] . "\n\n" . (string) $surgery['consent_text'];

        return [(int) $surgery['patient_id'], $bodyText, null];
    }

    /** @return array{0: int, 1: ?string, 2: ?int} */
    private function resolveMedicalCertificate(int $patientId, ?int $templateId, ?string $bodyText): array
    {
        $this->requirePatient($patientId);

        if ($bodyText !== null && DocumentTemplateRenderer::unresolvedPlaceholders($bodyText) !== []) {
            throw new InvalidArgumentException('Document text has unresolved placeholders');
        }

        if ($templateId !== null) {
            $template = $this->templates->findById($templateId);

            if ($template === null
                || !$template->isActive()
                || $template->kind() !== DocumentKind::MEDICAL_CERTIFICATE) {
                throw new InvalidArgumentException('Document template is not available');
            }
        }

        // A missing or blank text is refused by GeneratedDocument::request().
        return [$patientId, $bodyText, $templateId];
    }

    /** @return array{patient_id: int, patient_name: string, species: string, breed: ?string, tutor_id: int, tutor_name: string} */
    private function requirePatient(int $patientId): array
    {
        return $this->sources->patientSummary($patientId) ?? throw new DocumentSourceNotFoundException();
    }

    private function authorizeDocument(string $action, GeneratedDocument $document): void
    {
        $this->authorization->decide(new AuthorizationRequest(
            context: $this->context,
            action: $action,
            requiresUnitScope: true,
            resourceUnitId: $document->systemUnitId(),
            entityType: self::ENTITY_TYPE,
            entityId: $document->id(),
            metadata: [
                'document_id' => $document->id(),
                'kind' => $document->kind(),
                'version' => $document->version(),
            ],
        ))->assertAllowed();
    }
}
