<?php

declare(strict_types=1);

namespace CentralVet\Ai\Tools\Clinical;

use CentralVet\Ai\Tools\AiTool;
use CentralVet\Ai\Tools\ToolContext;
use CentralVet\Ai\Tools\ToolFamily;
use CentralVet\Ai\Tools\ToolResult;
use CentralVet\Domain\Contract\CopilotClinicalQueryInterface;
use Closure;
use DateTimeImmutable;

/**
 * obter_prescricoes (T-11): prescription items of a patient of the active
 * unit. A draft prescription comes out with `provisorio: true`.
 */
final class ObterPrescricoesTool implements AiTool
{
    use ResolvesPatientInUnit;

    private const ACTIVE = 'ativas';
    private const ALL = 'todas';

    private readonly Closure $clock;

    public function __construct(
        private readonly CopilotClinicalQueryInterface $query,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): DateTimeImmutable => new DateTimeImmutable();
    }

    public function name(): string
    {
        return 'obter_prescricoes';
    }

    public function description(): string
    {
        return 'Lista os itens de prescrição do paciente (medicamento, dose, via, posologia, duração, validade). '
            . 'situacao "ativas" (padrão) traz só as vigentes; "todas" inclui as vencidas. '
            . 'Sem patient_id, usa o paciente da conversa. Rascunhos vêm com provisorio = true.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'patient_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Id do paciente; padrão: paciente da conversa.'],
                'situacao' => ['type' => 'string', 'enum' => [self::ACTIVE, self::ALL], 'description' => 'ativas (padrão) ou todas.'],
            ],
        ];
    }

    public function requiredPermission(): string
    {
        return 'PrescriptionForm::__construct';
    }

    public function family(): string
    {
        return ToolFamily::CLINICAL;
    }

    public function label(string $lang): string
    {
        return $lang === 'en' ? 'prescriptions' : 'prescrições';
    }

    public function assertInScope(ToolContext $context, array $input): void
    {
        $this->patientId($context, $input);
    }

    public function execute(ToolContext $context, array $input): ToolResult
    {
        $patientId = $this->patientId($context, $input);
        $onlyActive = ($input['situacao'] ?? self::ACTIVE) === self::ACTIVE;
        $today = (($this->clock)())->setTime(0, 0);

        $rows = $this->query->prescriptions($patientId, $onlyActive, $today, ToolResult::MAX_ROWS + 1);
        $truncated = count($rows) > ToolResult::MAX_ROWS;

        $data = [];
        foreach (array_slice($rows, 0, ToolResult::MAX_ROWS) as $row) {
            $dose = trim(trim((string) ($row['dose'] ?? '')) . ' ' . trim((string) ($row['dose_unit'] ?? '')));
            $data[] = [
                'data' => self::date($row['created_at'] ?? null),
                'medicamento' => (string) $row['medication_name'],
                'dose' => $dose === '' ? null : $dose,
                'via' => $row['route'] ?? null,
                'posologia' => $row['frequency'] ?? null,
                'duracao' => $row['duration'] ?? null,
                'status' => (string) $row['status'],
                'provisorio' => $row['status'] === 'draft',
                'validade' => self::date($row['valid_until'] ?? null),
            ];
        }

        return new ToolResult($data, $truncated, ['via', 'posologia', 'duracao']);
    }

    private static function date(?string $value): ?string
    {
        return $value === null || trim($value) === '' ? null : substr(trim($value), 0, 10);
    }
}
