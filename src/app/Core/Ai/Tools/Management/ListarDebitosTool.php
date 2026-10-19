<?php

declare(strict_types=1);

namespace CentralVet\Ai\Tools\Management;

use CentralVet\Ai\Tools\AiTool;
use CentralVet\Ai\Tools\ToolContext;
use CentralVet\Ai\Tools\ToolFamily;
use CentralVet\Ai\Tools\ToolFormat;
use CentralVet\Ai\Tools\ToolResult;
use CentralVet\Domain\Contract\CopilotFinanceQueryInterface;
use Closure;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Open receivables of the active unit, overdue or still to fall due (due
 * date = creation + 7 days; the schema has no due date). Only the tutor's
 * first name leaves this tool: never contact, document or address.
 */
final class ListarDebitosTool implements AiTool
{
    private const DEFAULT_LIMIT = 20;
    private const MAX_LIMIT = 50;

    private readonly Closure $clock;

    public function __construct(
        private readonly CopilotFinanceQueryInterface $query,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): DateTimeImmutable => new DateTimeImmutable('now');
    }

    public function name(): string
    {
        return 'listar_debitos';
    }

    public function description(): string
    {
        return 'Lista os débitos em aberto (recebíveis abertos ou parcialmente pagos) da unidade ativa: '
            . 'primeiro nome do tutor, valor em aberto, vencimento (criação + 7 dias) e dias em atraso. '
            . 'situacao "vencidos" = vencimento antes de hoje; "a_vencer" = vence hoje ou depois.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'situacao' => [
                    'type' => 'string',
                    'enum' => [CopilotFinanceQueryInterface::OVERDUE, CopilotFinanceQueryInterface::UPCOMING],
                    'description' => 'vencidos ou a_vencer.',
                ],
                'limite' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => self::MAX_LIMIT,
                    'description' => 'Máximo de débitos (padrão 20).',
                ],
            ],
            'required' => ['situacao'],
        ];
    }

    public function requiredPermission(): string
    {
        return 'PendingReceivableList::onReload';
    }

    public function family(): string
    {
        return ToolFamily::MANAGEMENT;
    }

    public function label(string $lang): string
    {
        return $lang === 'en' ? 'open receivables' : 'débitos em aberto';
    }

    public function assertInScope(ToolContext $context, array $input): void
    {
        // No id in the input: the query is always scoped by the active unit.
    }

    public function execute(ToolContext $context, array $input): ToolResult
    {
        $limit = max(1, min(self::MAX_LIMIT, (int) ($input['limite'] ?? self::DEFAULT_LIMIT)));
        // Calendar day in the application timezone, whatever the clock's zone.
        $today = ($this->clock)()->setTimezone(new DateTimeZone(date_default_timezone_get()))->setTime(0, 0);

        $rows = $this->query->openReceivables((string) $input['situacao'], $today, $limit + 1);
        $truncated = count($rows) > $limit;

        $data = [];
        foreach (array_slice($rows, 0, $limit) as $row) {
            $due = new DateTimeImmutable((string) $row['due_date']);
            $overdue = $due < $today ? (int) $due->diff($today)->days : 0;

            $data[] = [
                'tutor' => ToolFormat::firstName($row['tutor_full_name'] ?? null),
                'valor' => self::money((int) $row['open_cents']),
                'vencimento' => $due->format('Y-m-d'),
                'dias_em_atraso' => $overdue,
            ];
        }

        return new ToolResult($data, $truncated);
    }

    private static function money(int $cents): string
    {
        return 'R$ ' . number_format($cents / 100, 2, ',', '.');
    }
}
