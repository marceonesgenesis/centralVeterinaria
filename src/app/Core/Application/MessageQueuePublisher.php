<?php

declare(strict_types=1);

namespace CentralVet\Application;

use CentralVet\Queue\QueueInterface;
use InvalidArgumentException;

/**
 * Publishes the delivery job of one outbound message (Fase 7A).
 *
 * Called by the controller only after the transaction that persisted the
 * message commits (the scheduler re-publishes e-mails left `queued` when a
 * publish is lost). The payload carries only the job type and the message
 * id — never recipient, subject or body (LGPD): the worker reloads the
 * message from the database.
 */
final class MessageQueuePublisher
{
    public const JOB_TYPE = 'communication.message.send';
    public const QUEUE = 'default';
    public const MAX_ATTEMPTS = 5;

    public function __construct(private readonly QueueInterface $queue)
    {
    }

    public function publish(int $tenantId, int $messageId): string
    {
        if ($tenantId <= 0 || $messageId <= 0) {
            throw new InvalidArgumentException('Tenant id and message id must be positive');
        }

        return $this->queue->push(
            self::QUEUE,
            ['type' => self::JOB_TYPE, 'message_id' => $messageId],
            $tenantId,
            maxAttempts: self::MAX_ATTEMPTS,
        );
    }
}
