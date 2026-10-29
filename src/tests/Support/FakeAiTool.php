<?php

declare(strict_types=1);

namespace CentralVet\Tests\Support;

use CentralVet\Ai\Tools\AiTool;
use CentralVet\Ai\Tools\ToolContext;
use CentralVet\Ai\Tools\ToolEntityNotFoundException;
use CentralVet\Ai\Tools\ToolResult;
use Throwable;

/**
 * In-memory double for AiTool (T-04). Returns $result (or an empty
 * ToolResult), throws $failure when given, and rejects every input in
 * assertInScope() when $inScope is false. executions() counts execute()
 * calls so a policy test can prove a denied call never ran.
 */
final class FakeAiTool implements AiTool
{
    private int $executions = 0;

    public function __construct(
        private readonly string $name,
        private readonly string $family,
        private readonly string $permission,
        private readonly ?ToolResult $result = null,
        private readonly ?Throwable $failure = null,
        private readonly bool $inScope = true,
    ) {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function description(): string
    {
        return 'Fake tool ' . $this->name;
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer', 'minimum' => 1],
            ],
        ];
    }

    public function requiredPermission(): string
    {
        return $this->permission;
    }

    public function family(): string
    {
        return $this->family;
    }

    public function label(string $lang): string
    {
        return ($lang === 'en' ? 'Fake ' : 'Falsa ') . $this->name;
    }

    public function assertInScope(ToolContext $context, array $input): void
    {
        if (!$this->inScope) {
            throw new ToolEntityNotFoundException('Entity not found');
        }
    }

    public function execute(ToolContext $context, array $input): ToolResult
    {
        $this->executions++;

        if ($this->failure !== null) {
            throw $this->failure;
        }

        return $this->result ?? new ToolResult([]);
    }

    public function executions(): int
    {
        return $this->executions;
    }
}
