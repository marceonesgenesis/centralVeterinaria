<?php

declare(strict_types=1);

namespace CentralVet\Application;

use CentralVet\Domain\Contract\DocumentContentFactoryInterface;
use CentralVet\Domain\Contract\DocumentRendererInterface;
use CentralVet\Domain\Contract\GeneratedDocumentRepositoryInterface;
use CentralVet\Domain\Contract\StoredObjectRepositoryInterface;
use CentralVet\Domain\Exception\DocumentGenerationFailed;
use CentralVet\Domain\Exception\DocumentSourceNotFoundException;
use CentralVet\Domain\GeneratedDocument;
use CentralVet\Storage\StorageInterface;
use CentralVet\Tenancy\TenantContext;
use Closure;
use DateTimeImmutable;
use RuntimeException;
use Throwable;

/**
 * Generates one requested document from the worker (Fase 7B, job
 * `document.generate`). System actor: no authorization; the transaction is
 * injected (`$transaction`, run directly by default) and never opened here.
 *
 * Idempotent under redelivery: a missing or non-`queued` document, or a
 * failed conditional claim, is `skipped`. The PDF is stored under a fresh
 * key `documents/<id>/<random>.pdf`; the `stored_object` row, the
 * conditional `markReady`, the `document_ready` notice and `markNotified`
 * run inside the transaction, and when it fails the stored object is
 * deleted (best effort).
 *
 * A missing source fails at once. Any other failure (render, storage,
 * persist) releases the claim and throws DocumentGenerationFailed (the
 * queue retries) or, on the final attempt, marks the document failed. Only
 * the error code ever leaves this class: no exception message, no previous
 * exception, no personal data or PDF bytes.
 */
final class DocumentGenerationService
{
    private readonly Closure $transaction;
    private readonly Closure $clock;

    public function __construct(
        private readonly GeneratedDocumentRepositoryInterface $documents,
        private readonly DocumentContentFactoryInterface $contents,
        private readonly DocumentRendererInterface $renderer,
        private readonly StorageInterface $storage,
        private readonly StoredObjectRepositoryInterface $objects,
        private readonly DocumentReadyNotifier $notifier,
        private readonly TenantContext $context,
        ?Closure $transaction = null,
        ?Closure $clock = null,
    ) {
        $this->transaction = $transaction ?? static fn (Closure $work): mixed => $work();
        $this->clock = $clock ?? static fn (): DateTimeImmutable => new DateTimeImmutable();
    }

    /**
     * @throws DocumentGenerationFailed on a retryable failure that is not the final attempt
     */
    public function generate(int $documentId, bool $finalAttempt): DocumentGenerationResult
    {
        $document = $this->documents->findById($documentId);

        if (
            !$document instanceof GeneratedDocument
            || $document->tenantId() !== $this->context->tenantId()
            || $document->status() !== GeneratedDocument::STATUS_QUEUED
        ) {
            return DocumentGenerationResult::skipped();
        }

        if (!$this->documents->claim($documentId, $this->now())) {
            return DocumentGenerationResult::skipped();
        }

        try {
            $content = $this->contents->build($document);
        } catch (DocumentSourceNotFoundException) {
            return $this->failAtOnce($documentId);
        } catch (DocumentGenerationFailed $failure) {
            if ($failure->errorCode() === DocumentGenerationFailed::SOURCE_NOT_FOUND) {
                return $this->failAtOnce($documentId);
            }

            return $this->handleFailure($documentId, $failure->errorCode(), $finalAttempt);
        } catch (Throwable) {
            return $this->handleFailure($documentId, DocumentGenerationFailed::RENDER_FAILED, $finalAttempt);
        }

        try {
            $pdf = $this->renderer->render($content);
        } catch (Throwable) {
            // The original message may carry personal data: only the code survives.
            return $this->handleFailure($documentId, DocumentGenerationFailed::RENDER_FAILED, $finalAttempt);
        }

        $key = sprintf('documents/%d/%s.pdf', $documentId, bin2hex(random_bytes(8)));

        try {
            $metadata = $this->storage->put($key, $pdf, 'application/pdf');
        } catch (Throwable) {
            return $this->handleFailure($documentId, DocumentGenerationFailed::STORAGE_FAILED, $finalAttempt);
        }

        try {
            /** @var list<int> $emailIds */
            $emailIds = ($this->transaction)(function () use ($document, $documentId, $metadata, $key, $pdf): array {
                $row = $this->objects->record(
                    $metadata,
                    $document->fileName(),
                    $document->systemUnitId(),
                    $document->requestedBySystemUserId(),
                );

                $now = $this->now();

                if (!$this->documents->markReady($documentId, (int) $row['id'], $key, strlen($pdf), hash('sha256', $pdf), $now)) {
                    throw new RuntimeException('Document is no longer claimed');
                }

                $emailIds = $document->notifyTutor() ? $this->notifier->notify($document) : [];
                $this->documents->markNotified($documentId, $now);

                return $emailIds;
            });
        } catch (Throwable) {
            try {
                $this->storage->delete($key);
            } catch (Throwable) {
                // Best effort: an orphan object holds no reference and is never served.
            }

            return $this->handleFailure($documentId, DocumentGenerationFailed::PERSIST_FAILED, $finalAttempt);
        }

        return DocumentGenerationResult::ready($emailIds);
    }

    private function failAtOnce(int $documentId): DocumentGenerationResult
    {
        $this->documents->markFailed($documentId, DocumentGenerationFailed::SOURCE_NOT_FOUND, $this->now());

        return DocumentGenerationResult::failed();
    }

    private function handleFailure(int $documentId, string $code, bool $finalAttempt): DocumentGenerationResult
    {
        if ($finalAttempt) {
            $this->documents->markFailed($documentId, $code, $this->now());

            return DocumentGenerationResult::failed();
        }

        $this->documents->releaseClaim($documentId, $code);

        throw new DocumentGenerationFailed($code);
    }

    private function now(): DateTimeImmutable
    {
        return ($this->clock)();
    }
}
