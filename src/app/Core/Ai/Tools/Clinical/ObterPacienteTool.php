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
 * Patient summary (species, age, allergies, last weight) of a patient of
 * the active unit; defaults to the conversation's anchor patient.
 */
final class ObterPacienteTool implements AiTool
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
        return 'obter_paciente';
    }

    public function description(): string
    {
        return 'Obtém o resumo de um paciente da unidade ativa: espécie, raça, sexo, idade, alergias, '
            . 'último peso e primeiro nome do tutor. Sem patient_id, usa o paciente da conversa.';
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
            ],
        ];
    }

    public function requiredPermission(): string
    {
        return 'PatientForm::onEdit';
    }

    public function family(): string
    {
        return ToolFamily::CLINICAL;
    }

    public function label(string $lang): string
    {
        return $lang === 'en' ? 'patient record' : 'cadastro do paciente';
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
        $row = $this->query->patient($patientId);
        if ($row === null) {
            throw new ToolEntityNotFoundException('Patient not found');
        }

        $tutor = $row['tutor_full_name'] ?? null;
        $knownNames = $this->query->tutorNames($patientId);
        if (is_string($tutor) && trim($tutor) !== '') {
            $knownNames[] = trim($tutor);
        }

        $data = [[
            'id' => (int) $row['id'],
            'nome' => (string) $row['name'],
            'especie' => $row['species'] ?? null,
            'raca' => $row['breed'] ?? null,
            'sexo' => $row['sex'] ?? null,
            'idade' => ToolFormat::age($row['birth_date'] ?? null, ($this->clock)()),
            'alergias' => $row['allergies'] ?? null,
            'peso_kg' => $row['weight_kg'] ?? null,
            'peso_em' => $row['weight_measured_at'] ?? null,
            'tutor' => ToolFormat::firstName($tutor),
        ]];

        return new ToolResult($data, false, ['alergias'], array_values(array_unique($knownNames)));
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
