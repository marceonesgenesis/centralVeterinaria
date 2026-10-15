<?php

declare(strict_types=1);

namespace CentralVet\Domain;

/**
 * Successful provider answer: the text, the model actually used (it may be
 * a fallback model), the token usage and the cost computed from it.
 */
final class AiCompletionResult
{
    public function __construct(
        public readonly string $text,
        public readonly string $provider,
        public readonly string $model,
        public readonly AiUsage $usage,
        public readonly ?int $costMicroUsd,
        public readonly string $stopReason,
        public readonly ?string $providerRequestId,
    ) {
    }
}
