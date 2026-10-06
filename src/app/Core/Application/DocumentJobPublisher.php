<?php

declare(strict_types=1);

namespace CentralVet\Application;

use CentralVet\Queue\QueueInterface;
use InvalidArgumentException;

/**
 * Publishes the generation job of one requested document (Fase 7B).
 *
 * Called by the controller only after the transaction that persisted the
 * document commits (the `DocumentSweeper` re-publishes documents left
 * `queued` when a publish is lost). The payload carries only the job type
 * and the document id — never names, text or PDF bytes (LGPD): the worker
 * reloads the document from the database.
 */
final class DocumentJobPublisher
{
    public const JOB_TYPE = 'document.generate';
    public const QUEUE = 'default';
    public const MAX_ATTEMPTS = 3;

    public function __construct(private readonly QueueInterface $queue)
    {
    }

    public function publish(int $tenantId, int $documentId): string
    {
        if ($tenantId <= 0 || $documentId <= 0) {
            throw new InvalidArgumentException('Tenant id and document id must be positive');
        }

        return $this->queue->push(
            self::QUEUE,
            ['type' => self::JOB_TYPE, 'document_id' => $documentId],
            $tenantId,
            null,
            0,
            self::MAX_ATTEMPTS,
        );
    }
}
