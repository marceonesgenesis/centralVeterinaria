<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Ai\Tools\AiTool;
use CentralVet\Ai\Tools\ToolContext;
use CentralVet\Ai\Tools\ToolFamily;
use CentralVet\Ai\Tools\ToolRegistry;
use CentralVet\Domain\Contract\FeatureFlagRepositoryInterface;
use CentralVet\Tenancy\Exception\MissingTenantContext;
use CentralVet\Tenancy\TenantContext;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\FakeAiTool;
use CentralVet\Tests\Support\FakeAuthorizationPolicy;
use CentralVet\Tests\Support\FakeFeatureFlagRepository;

final class ToolRegistryTest
{
    private function context(): TenantContext
    {
        return TenantContext::authenticated(7, 11, 3);
    }

    /** @return list<AiTool> */
    private function tools(): array
    {
        return [
            new FakeAiTool('buscar_paciente', 'clinical', 'GlobalSearchController::onSearch'),
            new FakeAiTool('consultar_agenda', 'management', 'AgendaView::onReload'),
            new FakeAiTool('apagar_paciente', 'clinical', 'PatientForm::onDelete'),
        ];
    }

    /** @return list<string> */
    private function names(array $tools): array
    {
        return array_map(static fn (AiTool $tool): string => $tool->name(), $tools);
    }

    public function testClinicalToolIsVisibleWithAssistantFlagAndPermission(): void
    {
        $registry = new ToolRegistry(
            $this->tools(),
            new FakeFeatureFlagRepository([FeatureFlagRepositoryInterface::AI_ASSISTANT]),
            new FakeAuthorizationPolicy(true),
            $this->context(),
        );

        Assert::same(['buscar_paciente'], $this->names($registry->visible('clinical')));
    }

    public function testToolOutsideAllowlistIsNeverVisible(): void
    {
        $registry = new ToolRegistry(
            $this->tools(),
            new FakeFeatureFlagRepository([
                FeatureFlagRepositoryInterface::AI_ASSISTANT,
                FeatureFlagRepositoryInterface::AI_COPILOT_MANAGEMENT,
            ]),
            new FakeAuthorizationPolicy(true),
            $this->context(),
        );

        Assert::same(['buscar_paciente'], $this->names($registry->visible('clinical')));
        Assert::null($registry->find('clinical', 'apagar_paciente'));
    }

    public function testManagementToolNeedsManagementFlag(): void
    {
        $registry = new ToolRegistry(
            $this->tools(),
            new FakeFeatureFlagRepository([FeatureFlagRepositoryInterface::AI_ASSISTANT]),
            new FakeAuthorizationPolicy(true),
            $this->context(),
        );

        Assert::same([], $registry->visible('management'));
        Assert::false(in_array('consultar_agenda', $this->names($registry->visible('clinical')), true));
        Assert::null($registry->find('management', 'consultar_agenda'));
    }

    public function testManagementToolVisibleWhenBothFlagsOn(): void
    {
        $registry = new ToolRegistry(
            $this->tools(),
            new FakeFeatureFlagRepository([
                FeatureFlagRepositoryInterface::AI_ASSISTANT,
                FeatureFlagRepositoryInterface::AI_COPILOT_MANAGEMENT,
            ]),
            new FakeAuthorizationPolicy(true),
            $this->context(),
        );

        Assert::same(['consultar_agenda'], $this->names($registry->visible('management')));
    }

    public function testAssistantFlagOffHidesClinicalTools(): void
    {
        $registry = new ToolRegistry(
            $this->tools(),
            new FakeFeatureFlagRepository([]),
            new FakeAuthorizationPolicy(true),
            $this->context(),
        );

        Assert::same([], $registry->visible('clinical'));
    }

    public function testDeniedPolicyHidesEveryTool(): void
    {
        $policy = new FakeAuthorizationPolicy(false, 'denied:permission');
        $registry = new ToolRegistry(
            $this->tools(),
            new FakeFeatureFlagRepository([FeatureFlagRepositoryInterface::AI_ASSISTANT]),
            $policy,
            $this->context(),
        );

        Assert::same([], $registry->visible('clinical'));
        Assert::null($registry->find('clinical', 'buscar_paciente'));
    }

    public function testAuthorizationRequestCarriesToolPermissionAndUnitScope(): void
    {
        $policy = new FakeAuthorizationPolicy(true);
        $registry = new ToolRegistry(
            $this->tools(),
            new FakeFeatureFlagRepository([FeatureFlagRepositoryInterface::AI_ASSISTANT]),
            $policy,
            $this->context(),
        );

        $registry->visible('clinical');

        Assert::count(1, $policy->requests);
        $request = $policy->requests[0];
        Assert::same('GlobalSearchController::onSearch', $request->action());
        Assert::true($request->requiresUnitScope());
        Assert::same(7, $request->resourceTenantId());
        Assert::same(3, $request->resourceUnitId());
        Assert::same('mcp_tool', $request->entityType());
        Assert::null($request->entityId());
        Assert::same(['tool' => 'buscar_paciente'], $request->metadata());
    }

    public function testFindReturnsVisibleTool(): void
    {
        $registry = new ToolRegistry(
            $this->tools(),
            new FakeFeatureFlagRepository([FeatureFlagRepositoryInterface::AI_ASSISTANT]),
            new FakeAuthorizationPolicy(true),
            $this->context(),
        );

        $tool = $registry->find('clinical', 'buscar_paciente');
        Assert::notNull($tool);
        Assert::same('buscar_paciente', $tool->name());
        Assert::null($registry->find('management', 'buscar_paciente'));
    }

    public function testDefinitionsUseToolsFormat(): void
    {
        $registry = new ToolRegistry(
            $this->tools(),
            new FakeFeatureFlagRepository([FeatureFlagRepositoryInterface::AI_ASSISTANT]),
            new FakeAuthorizationPolicy(true),
            $this->context(),
        );

        $definitions = $registry->definitions('clinical');
        Assert::count(1, $definitions);
        Assert::same(['name', 'description', 'input_schema'], array_keys($definitions[0]));
        Assert::same('buscar_paciente', $definitions[0]['name']);
        Assert::same('object', $definitions[0]['input_schema']['type']);
    }

    public function testUnknownFamilyIsRejected(): void
    {
        $registry = new ToolRegistry(
            $this->tools(),
            new FakeFeatureFlagRepository([FeatureFlagRepositoryInterface::AI_ASSISTANT]),
            new FakeAuthorizationPolicy(true),
            $this->context(),
        );

        Assert::throws(\InvalidArgumentException::class, static fn () => $registry->visible('admin'));
    }

    public function testFamilyAllowlistAndRequiredFlags(): void
    {
        Assert::same(['clinical', 'management'], ToolFamily::ALL);
        Assert::count(7, ToolFamily::ALLOWLIST[ToolFamily::CLINICAL]);
        Assert::count(5, ToolFamily::ALLOWLIST[ToolFamily::MANAGEMENT]);
        Assert::same(['ai_assistant'], ToolFamily::requiredFlags('clinical'));
        Assert::same(['ai_assistant', 'ai_copilot_management'], ToolFamily::requiredFlags('management'));
        Assert::same('ai_copilot_management', FeatureFlagRepositoryInterface::AI_COPILOT_MANAGEMENT);
    }

    public function testToolContextRequiresActiveUnit(): void
    {
        Assert::throws(
            MissingTenantContext::class,
            static fn () => ToolContext::create(TenantContext::authenticated(7, 11), 'corr-1', null, 'copilot_clinical', null),
        );
    }

    public function testToolContextExposesTenantUnitAndConversation(): void
    {
        $context = ToolContext::create($this->context(), 'corr-1', 42, 'copilot_clinical', 99);

        Assert::same(7, $context->tenantId);
        Assert::same(3, $context->unitId);
        Assert::same(11, $context->userId);
        Assert::same('corr-1', $context->correlationId);
        Assert::same(42, $context->conversationId);
        Assert::same('copilot_clinical', $context->purpose);
        Assert::same(99, $context->anchorPatientId);
    }

    public function testDenialReasonDistinguishesNotOfferedFromForbidden(): void
    {
        $tools = [
            ...$this->tools(),
            new FakeAiTool('obter_paciente', 'clinical', 'PatientForm::onEdit'),
        ];
        $policy = new class implements \CentralVet\Authorization\Contract\AuthorizationPolicyInterface {
            public function decide(\CentralVet\Authorization\AuthorizationRequest $request): \CentralVet\Authorization\AuthorizationDecision
            {
                $allowed = $request->action() !== 'PatientForm::onEdit';

                return new \CentralVet\Authorization\AuthorizationDecision($allowed, $allowed ? 'granted' : 'denied:permission', 'corr');
            }
        };
        $registry = new ToolRegistry(
            $tools,
            new FakeFeatureFlagRepository([FeatureFlagRepositoryInterface::AI_ASSISTANT]),
            $policy,
            $this->context(),
        );

        Assert::null($registry->denialReason('clinical', 'buscar_paciente'));
        Assert::same('forbidden', $registry->denialReason('clinical', 'obter_paciente'));
        Assert::same('not_offered', $registry->denialReason('clinical', 'apagar_paciente'));
        Assert::same('not_offered', $registry->denialReason('clinical', 'nao_existe'));
        Assert::same('not_offered', $registry->denialReason('management', 'consultar_agenda'));
        Assert::same('not_offered', $registry->denialReason('clinical', 'consultar_agenda'));
        Assert::null($registry->find('clinical', 'obter_paciente'));
        Assert::throws(\InvalidArgumentException::class, static fn () => $registry->denialReason('admin', 'buscar_paciente'));
    }

    public function testVisibilityIsCachedPerFamilyWithOneDecisionPerTool(): void
    {
        $policy = new FakeAuthorizationPolicy(true);
        $registry = new ToolRegistry(
            [...$this->tools(), new FakeAiTool('obter_paciente', 'clinical', 'PatientForm::onEdit')],
            new FakeFeatureFlagRepository([FeatureFlagRepositoryInterface::AI_ASSISTANT]),
            $policy,
            $this->context(),
        );

        $registry->definitions('clinical');
        $registry->visible('clinical');
        $registry->find('clinical', 'buscar_paciente');
        $registry->find('clinical', 'obter_paciente');
        $registry->denialReason('clinical', 'obter_paciente');

        Assert::count(2, $policy->requests);
    }

    /** T-02 (pendências 8B): nome duplicado é erro de programação, não escolha silenciosa. */
    public function testDuplicateToolNameIsRejected(): void
    {
        $error = null;
        try {
            new ToolRegistry(
                [
                    new FakeAiTool('buscar_paciente', 'clinical', 'GlobalSearchController::onSearch'),
                    new FakeAiTool('buscar_paciente', 'clinical', 'PatientList::onReload'),
                ],
                new FakeFeatureFlagRepository([FeatureFlagRepositoryInterface::AI_ASSISTANT]),
                new FakeAuthorizationPolicy(true),
                $this->context(),
            );
        } catch (\LogicException $e) {
            $error = $e->getMessage();
        }

        Assert::same('Duplicate tool name: buscar_paciente', $error);
    }

    /** T-02 (pendências 8B): ToolContext do mesmo tenant, usuário e unidade do registry. */
    public function testSameContextMatchesTenantUserAndUnit(): void
    {
        $registry = new ToolRegistry(
            $this->tools(),
            new FakeFeatureFlagRepository([FeatureFlagRepositoryInterface::AI_ASSISTANT]),
            new FakeAuthorizationPolicy(true),
            $this->context(),
        );

        Assert::true($registry->sameContext(ToolContext::create($this->context(), 'corr-1', null, 'copilot_clinical', null)));
        Assert::false($registry->sameContext(ToolContext::create(TenantContext::authenticated(7, 12, 3), 'corr-1', null, 'copilot_clinical', null)));
        Assert::false($registry->sameContext(ToolContext::create(TenantContext::authenticated(8, 11, 3), 'corr-1', null, 'copilot_clinical', null)));
        Assert::false($registry->sameContext(ToolContext::create(TenantContext::authenticated(7, 11, 4), 'corr-1', null, 'copilot_clinical', null)));
    }
}
