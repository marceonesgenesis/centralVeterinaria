<?php

declare(strict_types=1);

namespace CentralVet\Domain;

use InvalidArgumentException;

/**
 * One provider-independent completion request. `system` and `userContent`
 * carry clinical text already minimized; they are never logged.
 */
final class AiCompletionRequest
{
    public function __construct(
        public readonly string $purpose,
        public readonly string $promptVersion,
        public readonly string $system,
        public readonly string $userContent,
    ) {
        AiPurpose::assertValid($purpose);

        if (trim($promptVersion) === '') {
            throw new InvalidArgumentException('Prompt version is required');
        }
    }
}
