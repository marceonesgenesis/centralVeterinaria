<?php

declare(strict_types=1);

namespace CentralVet\Communication;

/**
 * Rendered message handed to a provider. Holds personal data (recipient and
 * body): never log, serialize to a queue or put it in an exception.
 * "reference" is an opaque, non-personal id (e.g. "message:42").
 */
final class OutgoingMessage
{
    public function __construct(
        public readonly string $recipient,
        public readonly ?string $subject,
        public readonly string $body,
        public readonly string $reference,
    ) {
    }
}
