<?php

declare(strict_types=1);

namespace CentralVet\Domain\Exception;

use DomainException;

/**
 * Thrown after a queued message was cancelled because the tutor's channel
 * preference no longer allows it (opt-out, or no opt-in for a consent
 * purpose), when an attendant tries to open or mark it as sent (T-25).
 *
 * Unlike a refusal, the cancellation is already written: the caller must
 * COMMIT the transaction before showing the message, or the cancellation
 * is lost. Messages are raw English with ids only; the presentation maps
 * them through `UserMessage`.
 */
final class MessageCancelledByPreferenceException extends DomainException
{
    public static function optedOut(int $messageId, string $channel): self
    {
        return new self("Message {$messageId} was cancelled because the tutor opted out of {$channel} messages");
    }

    public static function notOptedIn(int $messageId, string $channel): self
    {
        return new self("Message {$messageId} was cancelled because the tutor has not opted in to {$channel} messages");
    }
}
