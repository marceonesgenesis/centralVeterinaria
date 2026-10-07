<?php

declare(strict_types=1);

namespace CentralVet\Domain\Exception;

use DomainException;

/**
 * Thrown when the tutor's channel preference does not allow a message:
 * no opt-in for a consent-based purpose, or an opt-out on the channel.
 * Messages are raw English with ids only (no contact data); the
 * presentation maps them through `UserMessage`.
 */
final class CommunicationConsentRequiredException extends DomainException
{
    public static function notOptedIn(int $tutorId, string $channel): self
    {
        return new self("Tutor {$tutorId} has not opted in to {$channel} messages");
    }

    public static function optedOut(int $tutorId, string $channel): self
    {
        return new self("Tutor {$tutorId} has opted out of {$channel} messages");
    }
}
