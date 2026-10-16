<?php

declare(strict_types=1);

namespace CentralVet\Ai\Tools;

use CentralVet\Authorization\AuthorizationRequest;
use CentralVet\Authorization\Contract\AuthorizationPolicyInterface;
use CentralVet\Domain\Contract\FeatureFlagRepositoryInterface;
use CentralVet\Tenancy\TenantContext;

/**
 * Decides which tools the copilot may offer. Visible = name in the family
 * allowlist + every flag the family requires is on + the RBAC policy allows
 * the tool's screen permission in the active unit. Anything else is hidden
 * (fail-closed); find() only returns visible tools.
 */
final class ToolRegistry
{
    /** @var list<AiTool> */
    private readonly array $tools;

    /** @param iterable<AiTool> $tools */
    public function __construct(
        iterable $tools,
        private readonly FeatureFlagRepositoryInterface $flags,
        private readonly AuthorizationPolicyInterface $authorization,
        private readonly TenantContext $context,
    ) {
        $list = [];
        foreach ($tools as $tool) {
            if (!$tool instanceof AiTool) {
                throw new \InvalidArgumentException('ToolRegistry only accepts AiTool instances');
            }
            $list[] = $tool;
        }
        $this->tools = $list;
    }

    /** @return list<AiTool> */
    public function visible(string $family): array
    {
        foreach (ToolFamily::requiredFlags($family) as $flag) {
            if (!$this->flags->isEnabled($flag)) {
                return [];
            }
        }

        $visible = [];
        foreach ($this->tools as $tool) {
            if ($tool->family() !== $family || !ToolFamily::isAllowed($family, $tool->name())) {
                continue;
            }
            if ($this->allowed($tool)) {
                $visible[] = $tool;
            }
        }

        return $visible;
    }

    /** @return list<array{name: string, description: string, input_schema: array<string, mixed>}> */
    public function definitions(string $family): array
    {
        return array_map(
            static fn (AiTool $tool): array => [
                'name' => $tool->name(),
                'description' => $tool->description(),
                'input_schema' => $tool->inputSchema(),
            ],
            $this->visible($family),
        );
    }

    public function find(string $family, string $name): ?AiTool
    {
        foreach ($this->visible($family) as $tool) {
            if ($tool->name() === $name) {
                return $tool;
            }
        }

        return null;
    }

    private function allowed(AiTool $tool): bool
    {
        $decision = $this->authorization->decide(new AuthorizationRequest(
            context: $this->context,
            action: $tool->requiredPermission(),
            requiresUnitScope: true,
            resourceTenantId: $this->context->tenantId(),
            resourceUnitId: $this->context->unitId(),
            entityType: 'mcp_tool',
            entityId: null,
            metadata: ['tool' => $tool->name()],
        ));

        return $decision->allowed();
    }
}
