<?php

declare(strict_types=1);

namespace CentralVet\Domain;

/**
 * Successful provider answer: the text, the model actually used (it may be
 * a fallback model), the token usage and the cost computed from it.
 *
 * With tool use (`stopReason` `tool_use`) `text` may be empty and `toolUses`
 * lists the requested calls as list<array{id: string, name: string,
 * input: array}>; `content` keeps the assistant blocks as received, to be
 * sent back in the next round.
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
        public readonly array $toolUses = [],
        public readonly array $content = [],
    ) {
    }
}
