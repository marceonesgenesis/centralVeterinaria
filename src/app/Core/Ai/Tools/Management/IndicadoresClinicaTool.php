<?php

declare(strict_types=1);

namespace CentralVet\Ai\Tools\Management;

use CentralVet\Ai\Tools\AiTool;
use CentralVet\Ai\Tools\ToolContext;
use CentralVet\Ai\Tools\ToolFamily;
use CentralVet\Ai\Tools\ToolInputException;
use CentralVet\Ai\Tools\ToolResult;
use CentralVet\Domain\Contract\CopilotFinanceQueryInterface;
use Closure;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Indicators of the active unit in a period: encounters, revenue, average
 * ticket, new patients and share of returning encounters. Periods are
 * half-open ranges in the default timezone (date_default_timezone_get()):
 * current month, previous month or the last 30 days including today.
 */
final class IndicadoresClinicaTool implements AiTool
{
    private const PERIODS = ['mes_atual', 'mes_anterior', 'ultimos_30d'];

    private readonly Closure $clock;

    public function __construct(
        private readonly CopilotFinanceQueryInterface $query,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): DateTimeImmutable => new DateTimeImmutable('now');
    }

    public function name(): string
    {
        return 'indicadores_clinica';
    }

    public function description(): string
    {
        return 'Indicadores da unidade ativa no período: atendimentos iniciados, receita (lançamentos de entrada), '
            . 'ticket médio (receita / atendimentos), pacientes novos (primeiro atendimento na unidade no período) '
            . 'e percentual de atendimentos de retorno. periodo: mes_atual, mes_anterior ou ultimos_30d (inclui hoje).';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'periodo' => [
                    'type' => 'string',
                    'enum' => self::PERIODS,
                    'description' => 'mes_atual, mes_anterior ou ultimos_30d.',
                ],
            ],
            'required' => ['periodo'],
        ];
    }

    public function requiredPermission(): string
    {
        return 'FinancialOverview::onFilter';
    }

    public function family(): string
    {
        return ToolFamily::MANAGEMENT;
    }

    public function label(string $lang): string
    {
        return $lang === 'en' ? 'clinic indicators' : 'indicadores da clínica';
    }

    public function assertInScope(ToolContext $context, array $input): void
    {
        // No id in the input: the query is always scoped by the active unit.
    }

    public function execute(ToolContext $context, array $input): ToolResult
    {
        [$from, $to] = $this->period((string) $input['periodo']);
        $indicators = $this->query->indicators($from, $to);

        $encounters = (int) $indicators['encounters'];
        $revenue = (int) $indicators['revenue_cents'];

        return new ToolResult([[
            'periodo' => $from->format('d/m/Y') . ' a ' . $to->modify('-1 day')->format('d/m/Y'),
            'atendimentos' => $encounters,
            'receita' => self::money($revenue),
            'ticket_medio' => $encounters > 0 ? self::money((int) round($revenue / $encounters)) : null,
            'novos_pacientes' => (int) $indicators['new_patients'],
            'retorno_percentual' => $encounters > 0
                ? round((int) $indicators['returning_encounters'] * 100 / $encounters, 1)
                : null,
        ]]);
    }

    /** @return array{0: DateTimeImmutable, 1: DateTimeImmutable} half-open [from, to) */
    private function period(string $periodo): array
    {
        $now = ($this->clock)()->setTimezone(new DateTimeZone(date_default_timezone_get()));
        $today = $now->setTime(0, 0);
        $monthStart = $today->modify('first day of this month');

        return match ($periodo) {
            'mes_atual' => [$monthStart, $monthStart->modify('+1 month')],
            'mes_anterior' => [$monthStart->modify('-1 month'), $monthStart],
            'ultimos_30d' => [$today->modify('-29 days'), $today->modify('+1 day')],
            default => throw new ToolInputException('Unknown period'),
        };
    }

    private static function money(int $cents): string
    {
        return 'R$ ' . number_format($cents / 100, 2, ',', '.');
    }
}
