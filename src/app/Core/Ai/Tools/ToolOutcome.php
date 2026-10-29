<?php

declare(strict_types=1);

namespace CentralVet\Ai\Tools;

/**
 * What ToolPolicy hands back for one tool call. $content is the JSON sent to
 * the provider as `tool_result`: `{"rows": [...], "row_count": n,
 * "truncated": bool}` when allowed, or `{"error": "<fixed message>"}` when
 * denied — never an exception message, class name or SQL.
 */
final class ToolOutcome
{
    public function __construct(
        public readonly string $toolName,
        public readonly bool $isError,
        public readonly string $content,
        public readonly string $decision,
        public readonly ?string $denyReason,
        public readonly int $rowCount,
        public readonly bool $truncated,
    ) {
    }
}
