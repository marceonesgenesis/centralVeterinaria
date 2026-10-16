<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Ai\Tools\AiTool;
use CentralVet\Ai\Tools\Management\ManagementToolset;
use CentralVet\Ai\Tools\ToolFamily;
use CentralVet\Ai\Tools\ToolRegistry;
use CentralVet\Domain\Contract\FeatureFlagRepositoryInterface;
use CentralVet\Tenancy\TenantContext;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\FakeAuthorizationPolicy;
use CentralVet\Tests\Support\FakeCopilotAgendaQuery;
use CentralVet\Tests\Support\FakeCopilotFinanceQuery;
use CentralVet\Tests\Support\FakeCopilotStockQuery;
use CentralVet\Tests\Support\FakeFeatureFlagRepository;

/**
 * ManagementToolset (T-23) builds the five read-only management tools of the
 * copilot; ToolRegistry offers them only with `ai_copilot_management` on.
 */
final class ManagementToolsetTest
{
    /** @return list<AiTool> */
    private function tools(): array
    {
        return ManagementToolset::create(
            new FakeCopilotAgendaQuery(),
            new FakeCopilotStockQuery(),
            new FakeCopilotFinanceQuery(),
        );
    }

    /** @param list<string> $flags */
    private function registry(array $flags): ToolRegistry
    {
        return new ToolRegistry(
            $this->tools(),
            new FakeFeatureFlagRepository($flags),
            new FakeAuthorizationPolicy(true),
            TenantContext::authenticated(7, 11, 3),
        );
    }

    public function testCreatesTheFiveManagementToolsWithTheirPermissions(): void
    {
        $permissions = [];
        foreach ($this->tools() as $tool) {
            Assert::instanceOf(AiTool::class, $tool);
            Assert::same(ToolFamily::MANAGEMENT, $tool->family());
            $permissions[$tool->name()] = $tool->requiredPermission();
        }

        Assert::same(ToolFamily::ALLOWLIST[ToolFamily::MANAGEMENT], array_keys($permissions));
        Assert::same(
            [
                'consultar_agenda' => 'AgendaView::onReload',
                'verificar_horarios' => 'AgendaView::onReload',
                'consultar_estoque' => 'ProductList::onReload',
                'listar_debitos' => 'PendingReceivableList::onReload',
                'indicadores_clinica' => 'FinancialOverview::onFilter',
            ],
            $permissions
        );
    }

    public function testRegistryOffersTheFiveToolsWithBothFlags(): void
    {
        $registry = $this->registry([
            FeatureFlagRepositoryInterface::AI_ASSISTANT,
            FeatureFlagRepositoryInterface::AI_COPILOT_MANAGEMENT,
        ]);

        Assert::count(5, $registry->definitions(ToolFamily::MANAGEMENT));
    }

    public function testRegistryOffersNothingWithoutTheManagementFlag(): void
    {
        $registry = $this->registry([FeatureFlagRepositoryInterface::AI_ASSISTANT]);

        Assert::count(0, $registry->definitions(ToolFamily::MANAGEMENT));
    }
}
