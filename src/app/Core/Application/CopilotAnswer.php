<?php

declare(strict_types=1);

namespace CentralVet\Application;

/**
 * Result of one copilot question. `failed` answers carry an `ai_*` code in
 * `reason` and an empty `text`; `toolSummary` lists the tools consulted
 * ("Consultei: …").
 */
final class CopilotAnswer
{
    /** @param list<array{tool: string, label: string, count: int}> $toolSummary */
    public function __construct(
        public readonly int $conversationId,
        public readonly string $text,
        public readonly array $toolSummary,
        public readonly bool $failed,
        public readonly ?string $reason,
    ) {
    }
}
