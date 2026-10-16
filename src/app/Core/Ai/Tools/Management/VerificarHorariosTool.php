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
use CentralVet\Application\FreeSlotFinder;
use CentralVet\Domain\Contract\CopilotAgendaQueryInterface;
use Closure;
use DateTimeImmutable;

/**
 * Free agenda slots of one day for a service duration, per professional
 * (one, or every professional of the tenant). Same conflict rule as the
 * booking (AppointmentService::assertNoConflict) on the agenda grid
 * (07:00-19:00, step 30); see FreeSlotFinder. At most 50 rows.
 */
final class VerificarHorariosTool implements AiTool
{
    private const MIN_DURATION = 15;
    private const MAX_DURATION = 240;

    private readonly Closure $clock;

    public function __construct(
        private readonly CopilotAgendaQueryInterface $query,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): DateTimeImmutable => new DateTimeImmutable('now');
    }

    public function name(): string
    {
        return 'verificar_horarios';
    }

    public function description(): string
    {
        return 'Lista os horários livres de um dia (grade 07:00–19:00, de 30 em 30 minutos) para um atendimento com a '
            . 'duração informada, por profissional, sem conflito com agendamentos ativos. Hoje, só horários futuros.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'data' => ['type' => 'string', 'format' => 'date', 'description' => 'Dia (AAAA-MM-DD).'],
                'duracao_min' => [
                    'type' => 'integer',
                    'minimum' => self::MIN_DURATION,
                    'maximum' => self::MAX_DURATION,
                    'description' => 'Duração do atendimento em minutos (15 a 240).',
                ],
                'profissional_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Id do profissional (opcional).'],
            ],
            'required' => ['data', 'duracao_min'],
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
        return $lang === 'en' ? 'free time slots' : 'horários livres';
    }

    public function assertInScope(ToolContext $context, array $input): void
    {
        if (isset($input['profissional_id']) && !$this->query->professionalExists((int) $input['profissional_id'])) {
            throw new ToolEntityNotFoundException('Professional not found');
        }
    }

    public function execute(ToolContext $context, array $input): ToolResult
    {
        $value = (string) ($input['data'] ?? '');
        $day = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($day === false || $day->format('Y-m-d') !== $value) {
            throw new ToolInputException('Invalid date');
        }

        $duration = (int) ($input['duracao_min'] ?? 0);
        if ($duration < self::MIN_DURATION || $duration > self::MAX_DURATION) {
            throw new ToolInputException('duracao_min out of range');
        }

        $professionalId = isset($input['profissional_id']) ? (int) $input['profissional_id'] : null;

        $names = [];
        foreach ($this->query->professionals() as $professional) {
            $names[(int) $professional['id']] = (string) $professional['name'];
        }
        $professionalIds = $professionalId !== null ? [$professionalId] : array_keys($names);

        $slots = (new FreeSlotFinder())->find(
            $day,
            $duration,
            $this->query->busy($day, $professionalId),
            $professionalIds,
            ($this->clock)(),
        );

        $data = [];
        foreach (array_slice($slots, 0, ToolResult::MAX_ROWS) as $slot) {
            $data[] = [
                'horario' => $slot['start'],
                'profissional_id' => $slot['professional_id'],
                'profissional' => ToolFormat::firstName($names[$slot['professional_id']] ?? null),
            ];
        }

        return new ToolResult($data, count($slots) > ToolResult::MAX_ROWS);
    }
}
