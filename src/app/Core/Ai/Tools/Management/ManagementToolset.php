<?php

declare(strict_types=1);

namespace CentralVet\Ai\Tools\Management;

use CentralVet\Ai\Tools\AiTool;
use CentralVet\Domain\Contract\CopilotAgendaQueryInterface;
use CentralVet\Domain\Contract\CopilotFinanceQueryInterface;
use CentralVet\Domain\Contract\CopilotStockQueryInterface;
use Closure;

/**
 * The five read-only management tools of the copilot (Fase 8B.2): agenda,
 * free slots, stock, open receivables and clinic indicators, each on its
 * unit-scoped query. Visibility still depends on ToolRegistry (flags
 * `ai_assistant` + `ai_copilot_management`, allowlist and RBAC of each
 * tool's screen).
 */
final class ManagementToolset
{
    private function __construct()
    {
    }

    /** @return list<AiTool> */
    public static function create(
        CopilotAgendaQueryInterface $agenda,
        CopilotStockQueryInterface $stock,
        CopilotFinanceQueryInterface $finance,
        ?Closure $clock = null,
    ): array {
        return [
            new ConsultarAgendaTool($agenda, $clock),
            new VerificarHorariosTool($agenda, $clock),
            new ConsultarEstoqueTool($stock, $clock),
            new ListarDebitosTool($finance, $clock),
            new IndicadoresClinicaTool($finance, $clock),
        ];
    }
}
