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
 *
 * Visibility is computed once per family and kept for the lifetime of the
 * instance (one per request): visible(), definitions(), find() and
 * denialReason() share the cache, so each tool gets exactly one RBAC
 * decision (and one `audit_log` row) per request. ToolPolicy reads the
 * cached reason instead of asking the RBAC policy again.
 */
final class ToolRegistry
{
    public const NOT_OFFERED = 'not_offered';
    public const FORBIDDEN = 'forbidden';

    /** @var list<AiTool> */
    private readonly array $tools;

    /** @var array<string, array{flags: bool, reasons: array<string, ?string>, tools: array<string, AiTool>}> */
    private array $cache = [];

    /** @param iterable<AiTool> $tools */
    public function __construct(
        iterable $tools,
        private readonly FeatureFlagRepositoryInterface $flags,
        private readonly AuthorizationPolicyInterface $authorization,
        private readonly TenantContext $context,
    ) {
        $list = [];
        $names = [];
        foreach ($tools as $tool) {
            if (!$tool instanceof AiTool) {
                throw new \InvalidArgumentException('ToolRegistry only accepts AiTool instances');
            }
            $name = $tool->name();
            if (isset($names[$name])) {
                throw new \LogicException('Duplicate tool name: ' . $name);
            }
            $names[$name] = true;
            $list[] = $tool;
        }
        $this->tools = $list;
    }

    /**
     * True only when the call scope is the session this registry was built
     * for (same tenant, user and active unit); ToolPolicy denies otherwise.
     */
    public function sameContext(ToolContext $context): bool
    {
        return $context->tenantId === $this->context->tenantId()
            && $context->userId === $this->context->userId()
            && $context->unitId === $this->context->unitId();
    }

    /**
     * Audit scope for a call denied by !sameContext(): tenant, unit and user
     * of this registry's session, keeping the call's correlation_id and
     * purpose. Conversation and anchor are dropped (they may belong to the
     * foreign context).
     *
     * @throws \CentralVet\Tenancy\Exception\MissingTenantContext when the registry has no active unit
     */
    public function contextFor(ToolContext $foreign): ToolContext
    {
        return ToolContext::create($this->context, $foreign->correlationId, null, $foreign->purpose, null);
    }

    /** @return list<AiTool> */
    public function visible(string $family): array
    {
        $entry = $this->entry($family);
        if (!$entry['flags']) {
            return [];
        }

        $visible = [];
        foreach ($entry['tools'] as $name => $tool) {
            if ($entry['reasons'][$name] === null) {
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
        return $this->denialReason($family, $name) === null ? $this->entry($family)['tools'][$name] : null;
    }

    /**
     * null = visible; `not_offered` = outside the family allowlist, not
     * registered or a required flag is off; `forbidden` = RBAC denied.
     */
    public function denialReason(string $family, string $name): ?string
    {
        $entry = $this->entry($family);
        if (!$entry['flags'] || !isset($entry['tools'][$name])) {
            return self::NOT_OFFERED;
        }

        return $entry['reasons'][$name];
    }

    /**
     * Registered tool of the family allowlist, whatever its visibility, so
     * ToolPolicy can validate the input before reporting `forbidden`. Never
     * execute a tool obtained here without checking denialReason().
     */
    public function registered(string $family, string $name): ?AiTool
    {
        return $this->entry($family)['tools'][$name] ?? null;
    }

    /** @return array{flags: bool, reasons: array<string, ?string>, tools: array<string, AiTool>} */
    private function entry(string $family): array
    {
        if (isset($this->cache[$family])) {
            return $this->cache[$family];
        }

        $flagsOn = true;
        foreach (ToolFamily::requiredFlags($family) as $flag) {
            if (!$this->flags->isEnabled($flag)) {
                $flagsOn = false;
                break;
            }
        }

        $tools = [];
        $reasons = [];
        foreach ($this->tools as $tool) {
            $name = $tool->name();
            if ($tool->family() !== $family || !ToolFamily::isAllowed($family, $name)) {
                continue;
            }
            $tools[$name] = $tool;
            $reasons[$name] = $flagsOn ? ($this->allowed($tool) ? null : self::FORBIDDEN) : self::NOT_OFFERED;
        }

        return $this->cache[$family] = ['flags' => $flagsOn, 'reasons' => $reasons, 'tools' => $tools];
    }

    /** Fail-closed: a failing RBAC backend hides the tool. */
    private function allowed(AiTool $tool): bool
    {
        try {
            return $this->authorization->decide(new AuthorizationRequest(
                context: $this->context,
                action: $tool->requiredPermission(),
                requiresUnitScope: true,
                resourceTenantId: $this->context->tenantId(),
                resourceUnitId: $this->context->unitId(),
                entityType: 'mcp_tool',
                entityId: null,
                metadata: ['tool' => $tool->name()],
            ))->allowed();
        } catch (\Throwable) {
            return false;
        }
    }
}
