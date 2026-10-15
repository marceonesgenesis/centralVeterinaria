<?php

declare(strict_types=1);

namespace CentralVet\Domain;

/**
 * Suggestion shown for human review. It is not persisted on the clinical
 * record until the professional accepts or edits it.
 */
final class AiSuggestion
{
    public function __construct(
        public readonly int $interactionId,
        public readonly string $purpose,
        public readonly string $text,
        public readonly string $provider,
        public readonly string $model,
        public readonly string $promptVersion,
    ) {
    }
}
