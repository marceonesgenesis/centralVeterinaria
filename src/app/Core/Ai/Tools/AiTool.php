<?php

declare(strict_types=1);

namespace CentralVet\Ai\Tools;

/**
 * One read-only tool the copilot may call (Fase 8B). Implementations never
 * write, never trust ids coming from the model and always filter by the
 * tenant and active unit carried by ToolContext.
 */
interface AiTool
{
    /** Stable snake_case name sent to the provider (must be in ToolFamily::ALLOWLIST). */
    public function name(): string;

    /** Description sent to the provider in the `tools` definition. */
    public function description(): string;

    /**
     * JSON Schema (subset supported by JsonSchemaValidator) of the input.
     *
     * @return array<string, mixed>
     */
    public function inputSchema(): array;

    /** Screen action (`Class::method`) whose permission also guards this tool. */
    public function requiredPermission(): string;

    /** One of ToolFamily::ALL. */
    public function family(): string;

    /** Human name shown in "Consultei: …" ('pt' or 'en'). */
    public function label(string $lang): string;

    /**
     * Throws ToolEntityNotFoundException when an id in $input is outside the
     * tenant/active unit. Runs before execute().
     *
     * @param array<string, mixed> $input already validated against inputSchema()
     */
    public function assertInScope(ToolContext $context, array $input): void;

    /**
     * @param array<string, mixed> $input already validated against inputSchema()
     * @throws ToolInputException when the input is valid by schema but unusable
     */
    public function execute(ToolContext $context, array $input): ToolResult;
}
