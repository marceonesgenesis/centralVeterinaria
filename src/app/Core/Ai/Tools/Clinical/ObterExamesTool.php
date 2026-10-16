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
 * obter_exames (T-11): exam requests of a patient of the active unit, with a
 * short excerpt of the result. A result pending review comes out with
 * `provisorio: true`.
 */
final class ObterExamesTool implements AiTool
{
    private const RESULT_EXCERPT = 400;

    private readonly Closure $clock;

    public function __construct(
        private readonly CopilotClinicalQueryInterface $query,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): DateTimeImmutable => new DateTimeImmutable();
    }

    public function name(): string
    {
        return 'obter_exames';
    }

    public function description(): string
    {
        return 'Lista os exames solicitados ao paciente, com status e trecho do resultado. '
            . 'situacao: "pendentes", "liberados" ou "todos" (padrão). '
            . 'Sem patient_id, usa o paciente da conversa. Resultado aguardando revisão vem com provisorio = true.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'patient_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Id do paciente; padrão: paciente da conversa.'],
                'situacao' => [
                    'type' => 'string',
                    'enum' => [
                        CopilotClinicalQueryInterface::EXAMS_PENDING,
                        CopilotClinicalQueryInterface::EXAMS_RELEASED,
                        CopilotClinicalQueryInterface::EXAMS_ALL,
                    ],
                    'description' => 'pendentes, liberados ou todos (padrão).',
                ],
            ],
        ];
    }

    public function requiredPermission(): string
    {
        return 'ExamResultForm::__construct';
    }

    public function family(): string
    {
        return ToolFamily::CLINICAL;
    }

    public function label(string $lang): string
    {
        return $lang === 'en' ? 'exams' : 'exames';
    }

    public function assertInScope(ToolContext $context, array $input): void
    {
        $this->patientId($context, $input);
    }

    public function execute(ToolContext $context, array $input): ToolResult
    {
        $patientId = $this->patientId($context, $input);
        $situacao = $input['situacao'] ?? CopilotClinicalQueryInterface::EXAMS_ALL;

        $rows = $this->query->exams($patientId, $situacao, ToolResult::MAX_ROWS + 1);
        $truncated = count($rows) > ToolResult::MAX_ROWS;

        $data = [];
        foreach (array_slice($rows, 0, ToolResult::MAX_ROWS) as $row) {
            $requestedAt = $row['requested_at'] ?? null;
            $data[] = [
                'exame' => (string) $row['exam_name'],
                'data' => $requestedAt === null ? null : substr(trim((string) $requestedAt), 0, 10),
                'status' => (string) $row['status'],
                'provisorio' => ($row['pending_review'] ?? null) === true,
                'resultado' => ToolFormat::excerpt($row['structured_result_text'] ?? null, self::RESULT_EXCERPT),
            ];
        }

        return new ToolResult($data, $truncated, ['resultado'], $this->query->tutorNames($patientId));
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
}
