<?php

declare(strict_types=1);

namespace CentralVet\Ai\Tools\Clinical;

use CentralVet\Ai\Tools\AiTool;
use CentralVet\Ai\Tools\ToolContext;
use CentralVet\Ai\Tools\ToolEntityNotFoundException;
use CentralVet\Ai\Tools\ToolFamily;
use CentralVet\Ai\Tools\ToolResult;
use CentralVet\Domain\Contract\CopilotClinicalQueryInterface;
use Closure;
use DateTimeImmutable;

/**
 * Full clinical record (anamnesis, physical exam, diagnosis, plan, weight)
 * of one encounter of the active unit.
 */
final class ObterProntuarioTool implements AiTool
{
    private readonly Closure $clock;

    public function __construct(
        private readonly CopilotClinicalQueryInterface $query,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): DateTimeImmutable => new DateTimeImmutable('now');
    }

    public function name(): string
    {
        return 'obter_prontuario';
    }

    public function description(): string
    {
        return 'Obtém o prontuário de um atendimento da unidade ativa: data, anamnese, exame físico, '
            . 'diagnóstico, plano e peso.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'encounter_id' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'description' => 'Id do atendimento.',
                ],
            ],
            'required' => ['encounter_id'],
        ];
    }

    public function requiredPermission(): string
    {
        return 'EncounterView::onReload';
    }

    public function family(): string
    {
        return ToolFamily::CLINICAL;
    }

    public function label(string $lang): string
    {
        return $lang === 'en' ? 'medical record' : 'prontuário';
    }

    public function assertInScope(ToolContext $context, array $input): void
    {
        if (!$this->query->encounterInUnit((int) $input['encounter_id'])) {
            throw new ToolEntityNotFoundException('Encounter not found');
        }
    }

    public function execute(ToolContext $context, array $input): ToolResult
    {
        $row = $this->query->encounter((int) $input['encounter_id']);
        if ($row === null) {
            throw new ToolEntityNotFoundException('Encounter not found');
        }

        $data = [[
            'encounter_id' => (int) $row['id'],
            'data' => (string) $row['started_at'],
            'anamnese' => $row['anamnesis_text'] ?? null,
            'exame_fisico' => $row['physical_exam_text'] ?? null,
            'diagnostico' => $row['diagnosis_text'] ?? null,
            'plano' => $row['clinical_plan_text'] ?? null,
            'peso_kg' => $row['weight_kg'] ?? null,
        ]];

        return new ToolResult(
            $data,
            false,
            ['anamnese', 'exame_fisico', 'diagnostico', 'plano'],
            array_values(array_unique($this->query->tutorNames((int) $row['patient_id']))),
        );
    }
}
