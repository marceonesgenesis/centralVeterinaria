<?php

declare(strict_types=1);

namespace CentralVet\Ai\Tools\Clinical;

use CentralVet\Ai\Tools\AiTool;
use CentralVet\Ai\Tools\ToolContext;
use CentralVet\Ai\Tools\ToolEntityNotFoundException;
use CentralVet\Ai\Tools\ToolFamily;
use CentralVet\Ai\Tools\ToolFormat;
use CentralVet\Ai\Tools\ToolInputException;
use CentralVet\Ai\Tools\ToolResult;
use CentralVet\Domain\Contract\CopilotClinicalQueryInterface;
use Closure;
use DateTimeImmutable;

/**
 * Latest encounters of a patient in the active unit (most recent first).
 * "motivo" is the start of the anamnesis: there is no reason column.
 */
final class ObterHistoricoAtendimentosTool implements AiTool
{
    private const DEFAULT_LIMIT = 5;
    private const MAX_LIMIT = 10;
    private const MOTIVE_LENGTH = 160;

    private readonly Closure $clock;

    public function __construct(
        private readonly CopilotClinicalQueryInterface $query,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): DateTimeImmutable => new DateTimeImmutable('now');
    }

    public function name(): string
    {
        return 'obter_historico_atendimentos';
    }

    public function description(): string
    {
        return 'Lista os últimos atendimentos do paciente na unidade ativa, do mais recente ao mais antigo: '
            . 'data, motivo (início da anamnese), diagnóstico e conduta. Sem patient_id, usa o paciente da conversa.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'patient_id' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'description' => 'Id do paciente (padrão: paciente da conversa).',
                ],
                'limite' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => self::MAX_LIMIT,
                    'description' => 'Máximo de atendimentos (padrão 5).',
                ],
            ],
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
        return $lang === 'en' ? 'encounter history' : 'histórico de atendimentos';
    }

    public function assertInScope(ToolContext $context, array $input): void
    {
        if (!$this->query->patientInUnit($this->patientId($context, $input))) {
            throw new ToolEntityNotFoundException('Patient not found');
        }
    }

    public function execute(ToolContext $context, array $input): ToolResult
    {
        $patientId = $this->patientId($context, $input);
        $limit = max(1, min(self::MAX_LIMIT, (int) ($input['limite'] ?? self::DEFAULT_LIMIT)));

        $data = [];
        foreach (array_slice($this->query->encounters($patientId, $limit), 0, $limit) as $row) {
            $data[] = [
                'encounter_id' => (int) $row['id'],
                'data' => (string) $row['started_at'],
                'motivo' => ToolFormat::excerpt($row['anamnesis_text'] ?? null, self::MOTIVE_LENGTH),
                'diagnostico' => $row['diagnosis_text'] ?? null,
                'conduta' => $row['clinical_plan_text'] ?? null,
            ];
        }

        return new ToolResult(
            $data,
            false,
            ['motivo', 'diagnostico', 'conduta'],
            array_values(array_unique($this->query->tutorNames($patientId))),
        );
    }

    /** @param array<string, mixed> $input */
    private function patientId(ToolContext $context, array $input): int
    {
        $patientId = $input['patient_id'] ?? $context->anchorPatientId;
        if ($patientId === null) {
            throw new ToolInputException('patient_id is required when the conversation has no patient');
        }

        return (int) $patientId;
    }
}
