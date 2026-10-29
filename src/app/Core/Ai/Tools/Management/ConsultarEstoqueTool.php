<?php

declare(strict_types=1);

namespace CentralVet\Ai\Tools\Management;

use CentralVet\Ai\Tools\AiTool;
use CentralVet\Ai\Tools\ToolContext;
use CentralVet\Ai\Tools\ToolFamily;
use CentralVet\Ai\Tools\ToolInputException;
use CentralVet\Ai\Tools\ToolResult;
use CentralVet\Domain\Contract\CopilotStockQueryInterface;
use Closure;
use DateTimeImmutable;

/**
 * consultar_estoque (T-20, 8B.2): stock of the active unit — products at or
 * below the minimum, batches expiring in 30 days, or a name/code search.
 * Balance counts only the batches of the active unit.
 */
final class ConsultarEstoqueTool implements AiTool
{
    public const FILTER_BELOW_MINIMUM = 'abaixo_minimo';
    public const FILTER_EXPIRING = 'vencendo_30d';
    public const FILTER_TERM = 'termo';

    private const EXPIRY_DAYS = 30;

    private readonly Closure $clock;

    public function __construct(
        private readonly CopilotStockQueryInterface $query,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): DateTimeImmutable => new DateTimeImmutable();
    }

    public function name(): string
    {
        return 'consultar_estoque';
    }

    public function description(): string
    {
        return 'Consulta o estoque da unidade ativa. filtro: "abaixo_minimo" (produtos com saldo igual ou abaixo do mínimo, '
            . 'inclusive zerados), "vencendo_30d" (lotes com saldo que vencem nos próximos 30 dias) ou "termo" '
            . '(busca por nome ou código; exige termo). Devolve item, saldo, mínimo, validade e lote.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'filtro' => [
                    'type' => 'string',
                    'enum' => [self::FILTER_BELOW_MINIMUM, self::FILTER_EXPIRING, self::FILTER_TERM],
                    'description' => 'abaixo_minimo, vencendo_30d ou termo.',
                ],
                'termo' => [
                    'type' => 'string',
                    'minLength' => 2,
                    'maxLength' => 60,
                    'description' => 'Parte do nome ou do código do produto; obrigatório com filtro = termo.',
                ],
            ],
            'required' => ['filtro'],
        ];
    }

    public function requiredPermission(): string
    {
        return 'ProductList::onReload';
    }

    public function family(): string
    {
        return ToolFamily::MANAGEMENT;
    }

    public function label(string $lang): string
    {
        return $lang === 'en' ? 'stock' : 'estoque';
    }

    public function assertInScope(ToolContext $context, array $input): void
    {
        // No entity id in the input: the query is always scoped to the active unit.
    }

    public function execute(ToolContext $context, array $input): ToolResult
    {
        $limit = ToolResult::MAX_ROWS + 1;

        $rows = match ($input['filtro'] ?? null) {
            self::FILTER_BELOW_MINIMUM => $this->query->belowMinimum($limit),
            self::FILTER_EXPIRING => $this->query->expiring(($this->clock)(), self::EXPIRY_DAYS, $limit),
            self::FILTER_TERM => $this->query->search($this->term($input), $limit),
            default => throw new ToolInputException('filtro: unsupported value'),
        };

        $data = [];
        foreach (array_slice($rows, 0, ToolResult::MAX_ROWS) as $row) {
            $data[] = [
                'item' => (string) $row['name'],
                'saldo' => (string) $row['balance'],
                'minimo' => $row['minimum'] ?? null,
                'validade' => $row['next_expiry'] ?? null,
                'lote' => $row['lot'] ?? null,
            ];
        }

        return new ToolResult($data, count($rows) > ToolResult::MAX_ROWS);
    }

    /** @param array<string, mixed> $input */
    private function term(array $input): string
    {
        $term = trim((string) ($input['termo'] ?? ''));
        if (mb_strlen($term) < 2) {
            throw new ToolInputException('termo: required with filtro = termo');
        }

        return $term;
    }
}
