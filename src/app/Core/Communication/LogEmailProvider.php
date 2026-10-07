<?php

declare(strict_types=1);

namespace CentralVet\Communication;

use CentralVet\Observability\Logging\LoggerInterface;

/**
 * Sandbox e-mail provider (default driver): records a structured event with
 * only the reference, a truncated recipient hash and the lengths, and sends
 * nothing.
 */
final class LogEmailProvider implements MessageChannelProviderInterface
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public function channel(): string
    {
        return 'email';
    }

    public function name(): string
    {
        return 'log';
    }

    public function deliver(OutgoingMessage $message): ?string
    {
        $this->logger->info('communication.email.sandbox', [
            'reference' => $message->reference,
            'recipient_hash' => substr(hash('sha256', strtolower($message->recipient)), 0, 12),
            'subject_length' => mb_strlen((string) $message->subject, 'UTF-8'),
            'body_length' => mb_strlen($message->body, 'UTF-8'),
        ]);

        return null;
    }
}
