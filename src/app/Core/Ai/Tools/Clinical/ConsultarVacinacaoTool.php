<?php

declare(strict_types=1);

namespace CentralVet\Ai\Tools\Clinical;

use CentralVet\Ai\Tools\AiTool;
use CentralVet\Ai\Tools\ToolContext;
use CentralVet\Ai\Tools\ToolEntityNotFoundException;
use CentralVet\Ai\Tools\ToolFamily;
use CentralVet\Ai\Tools\ToolInputException;
use CentralVet\Ai\Tools\ToolResult;
use CentralVet\Domain\Contract\CopilotClinicalQueryInterface;
use Closure;
use DateTimeImmutable;

/**
 * consultar_vacinacao (T-11): vaccine applications of a patient of the
 * active unit. `atrasada` = next dose before today and no later application
 * of the same vaccine (`superseded`).
 */
final class ConsultarVacinacaoTool implements AiTool
{
    private readonly Closure $clock;

    public function __construct(
        private readonly CopilotClinicalQueryInterface $query,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): DateTimeImmutable => new DateTimeImmutable();
    }

    public function name(): string
    {
        return 'consultar_vacinacao';
    }

    public function description(): string
    {
        return 'Lista as vacinas aplicadas ao paciente, com a próxima dose e se ela está atrasada. '
            . 'Sem patient_id, usa o paciente da conversa.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'patient_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Id do paciente; padrão: paciente da conversa.'],
            ],
        ];
    }

    public function requiredPermission(): string
    {
        return 'VaccinationCardView::onReload';
    }

    public function family(): string
    {
        return ToolFamily::CLINICAL;
    }

    public function label(string $lang): string
    {
        return $lang === 'en' ? 'vaccination' : 'vacinação';
    }

    public function assertInScope(ToolContext $context, array $input): void
    {
        $this->patientId($context, $input);
    }

    public function execute(ToolContext $context, array $input): ToolResult
    {
        $patientId = $this->patientId($context, $input);
        $today = (($this->clock)())->format('Y-m-d');

        $rows = $this->query->vaccinations($patientId, ToolResult::MAX_ROWS + 1);
        $truncated = count($rows) > ToolResult::MAX_ROWS;

        $data = [];
        foreach (array_slice($rows, 0, ToolResult::MAX_ROWS) as $row) {
            $nextDose = self::date($row['next_dose_at'] ?? null);
            $data[] = [
                'vacina' => (string) $row['vaccine_name'],
                'aplicada_em' => self::date($row['applied_at'] ?? null),
                'proxima_dose' => $nextDose,
                'atrasada' => $nextDose !== null && $nextDose < $today && ($row['superseded'] ?? false) !== true,
            ];
        }

        return new ToolResult($data, $truncated);
    }

    /** @param array<string, mixed> $input */
    private function patientId(ToolContext $context, array $input): int
    {
        $patientId = $input['patient_id'] ?? $context->anchorPatientId;
        if (!is_int($patientId) || $patientId <= 0) {
            throw new ToolInputException('patient_id: required (no patient in the conversation)');
        }
        if (!$this->query->patientInUnit($patientId)) {
            throw new ToolEntityNotFoundException('Patient not found');
        }

        return $patientId;
    }

    private static function date(?string $value): ?string
    {
        return $value === null || trim($value) === '' ? null : substr(trim($value), 0, 10);
    }
}
