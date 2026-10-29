<?php

declare(strict_types=1);

namespace CentralVet\Ai\Tools\Clinical;

use CentralVet\Ai\Tools\AiTool;
use CentralVet\Domain\Contract\CopilotClinicalQueryInterface;
use Closure;

/**
 * The seven read-only clinical tools of the copilot (Fase 8B), built on the
 * same unit-scoped query. Visibility still depends on ToolRegistry (flags,
 * allowlist and RBAC of each tool's screen).
 */
final class ClinicalToolset
{
    private function __construct()
    {
    }

    /** @return list<AiTool> */
    public static function create(CopilotClinicalQueryInterface $query, ?Closure $clock = null): array
    {
        return [
            new BuscarPacienteTool($query, $clock),
            new ObterPacienteTool($query, $clock),
            new ObterHistoricoAtendimentosTool($query, $clock),
            new ObterProntuarioTool($query, $clock),
            new ObterPrescricoesTool($query, $clock),
            new ObterExamesTool($query, $clock),
            new ConsultarVacinacaoTool($query, $clock),
        ];
    }
}
