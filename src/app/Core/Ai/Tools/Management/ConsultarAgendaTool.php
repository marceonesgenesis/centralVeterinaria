<?php

declare(strict_types=1);

namespace CentralVet\Ai\Tools\Management;

use CentralVet\Ai\Tools\AiTool;
use CentralVet\Ai\Tools\ToolContext;
use CentralVet\Ai\Tools\ToolEntityNotFoundException;
use CentralVet\Ai\Tools\ToolFamily;
use CentralVet\Ai\Tools\ToolFormat;
use CentralVet\Ai\Tools\ToolInputException;
use CentralVet\Ai\Tools\ToolResult;
use CentralVet\Domain\Contract\CopilotAgendaQueryInterface;
use Closure;
use DateTimeImmutable;

/**
 * Appointments of the active unit for one day (`data`, default today) or a
 * range of up to 7 days (`data_inicio` + `data_fim`, inclusive), optionally
 * for one professional. Professional only by first name; no tutor or
 * contact data. At most 50 rows, `truncated` when there are more.
 */
final class ConsultarAgendaTool implements AiTool
{
    private const MAX_RANGE_DAYS = 7;

    private readonly Closure $clock;

    public function __construct(
        private readonly CopilotAgendaQueryInterface $query,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): DateTimeImmutable => new DateTimeImmutable('now');
    }

    public function name(): string
    {
        return 'consultar_agenda';
    }

    public function description(): string
    {
        return 'Lista os agendamentos da unidade ativa em um dia (data, padrão hoje) ou em um intervalo de até 7 dias '
            . '(data_inicio e data_fim): horário, paciente, tipo de serviço, status e profissional. '
            . 'Filtra por profissional_id quando informado.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'data' => ['type' => 'string', 'format' => 'date', 'description' => 'Dia (AAAA-MM-DD). Padrão: hoje.'],
                'data_inicio' => ['type' => 'string', 'format' => 'date', 'description' => 'Início do intervalo (AAAA-MM-DD), com data_fim.'],
                'data_fim' => ['type' => 'string', 'format' => 'date', 'description' => 'Fim do intervalo (AAAA-MM-DD), inclusive; no máximo 7 dias.'],
                'profissional_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Id do profissional (opcional).'],
            ],
        ];
    }

    public function requiredPermission(): string
    {
        return 'AgendaView::onReload';
    }

    public function family(): string
    {
        return ToolFamily::MANAGEMENT;
    }

    public function label(string $lang): string
    {
        return $lang === 'en' ? 'schedule' : 'agenda';
    }

    public function assertInScope(ToolContext $context, array $input): void
    {
        // An inactive member may still have appointments to look up.
        if (isset($input['profissional_id']) && !$this->query->professionalExists((int) $input['profissional_id'], true)) {
            throw new ToolEntityNotFoundException('Professional not found');
        }
    }

    public function execute(ToolContext $context, array $input): ToolResult
    {
        [$from, $to] = $this->range($input);
        $professionalId = isset($input['profissional_id']) ? (int) $input['profissional_id'] : null;

        $rows = $this->query->appointments($from, $to, $professionalId, ToolResult::MAX_ROWS + 1);
        $truncated = count($rows) > ToolResult::MAX_ROWS;

        $data = [];
        foreach (array_slice($rows, 0, ToolResult::MAX_ROWS) as $row) {
            $data[] = [
                'horario' => substr((string) $row['scheduled_at'], 0, 16),
                'paciente' => (string) $row['patient_name'],
                'tipo' => $row['service_name'] ?? null,
                'status' => (string) $row['status'],
                'profissional' => ToolFormat::firstName($row['professional_name'] ?? null),
            ];
        }

        return new ToolResult($data, $truncated);
    }

    /**
     * @param array<string, mixed> $input
     * @return array{0: DateTimeImmutable, 1: DateTimeImmutable} [first day 00:00, day after the last 00:00)
     */
    private function range(array $input): array
    {
        $hasRange = isset($input['data_inicio']) || isset($input['data_fim']);

        if (isset($input['data'])) {
            if ($hasRange) {
                throw new ToolInputException('Use data or data_inicio/data_fim, not both');
            }
            $day = self::day((string) $input['data']);

            return [$day, $day->modify('+1 day')];
        }

        if (!$hasRange) {
            $today = (($this->clock)())->setTime(0, 0);

            return [$today, $today->modify('+1 day')];
        }

        if (!isset($input['data_inicio'], $input['data_fim'])) {
            throw new ToolInputException('data_inicio and data_fim go together');
        }

        $first = self::day((string) $input['data_inicio']);
        $last = self::day((string) $input['data_fim']);
        if ($last < $first) {
            throw new ToolInputException('data_fim before data_inicio');
        }
        if ((int) $first->diff($last)->days + 1 > self::MAX_RANGE_DAYS) {
            throw new ToolInputException('Range longer than 7 days');
        }

        return [$first, $last->modify('+1 day')];
    }

    private static function day(string $value): DateTimeImmutable
    {
        $day = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($day === false || $day->format('Y-m-d') !== $value) {
            throw new ToolInputException('Invalid date');
        }

        return $day;
    }
}
