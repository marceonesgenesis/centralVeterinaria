<?php

declare(strict_types=1);

namespace CentralVet\Ai\Tools\Clinical;

use CentralVet\Ai\Tools\AiTool;
use CentralVet\Ai\Tools\ToolContext;
use CentralVet\Ai\Tools\ToolFamily;
use CentralVet\Ai\Tools\ToolFormat;
use CentralVet\Ai\Tools\ToolResult;
use CentralVet\Domain\Contract\CopilotClinicalQueryInterface;
use Closure;
use DateTimeImmutable;

/**
 * Searches patients of the active unit by name, tutor name or id. Output
 * carries the tutor's first name only and no contact data.
 */
final class BuscarPacienteTool implements AiTool
{
    private const DEFAULT_LIMIT = 10;
    private const MAX_LIMIT = 20;

    private readonly Closure $clock;

    public function __construct(
        private readonly CopilotClinicalQueryInterface $query,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): DateTimeImmutable => new DateTimeImmutable('now');
    }

    public function name(): string
    {
        return 'buscar_paciente';
    }

    public function description(): string
    {
        return 'Busca pacientes da unidade ativa por nome do paciente, nome do tutor ou id. '
            . 'Devolve id, nome, espécie, raça, idade e primeiro nome do tutor.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'termo' => [
                    'type' => 'string',
                    'minLength' => 2,
                    'maxLength' => 80,
                    'description' => 'Nome do paciente, nome do tutor ou id numérico do paciente.',
                ],
                'limite' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => self::MAX_LIMIT,
                    'description' => 'Máximo de pacientes (padrão 10).',
                ],
            ],
            'required' => ['termo'],
        ];
    }

    public function requiredPermission(): string
    {
        return 'GlobalSearchController::onSearch';
    }

    public function family(): string
    {
        return ToolFamily::CLINICAL;
    }

    public function label(string $lang): string
    {
        return $lang === 'en' ? 'patient search' : 'busca de pacientes';
    }

    public function assertInScope(ToolContext $context, array $input): void
    {
        // No id in the input: the query itself only returns patients of the unit.
    }

    public function execute(ToolContext $context, array $input): ToolResult
    {
        $limit = max(1, min(self::MAX_LIMIT, (int) ($input['limite'] ?? self::DEFAULT_LIMIT)));
        $today = ($this->clock)();

        $rows = array_slice($this->query->searchPatients(trim((string) $input['termo']), $limit), 0, $limit);

        $data = [];
        $knownNames = [];
        foreach ($rows as $row) {
            $tutor = $row['tutor_full_name'] ?? null;
            if (is_string($tutor) && trim($tutor) !== '') {
                $knownNames[] = trim($tutor);
            }
            $data[] = [
                'id' => (int) $row['id'],
                'nome' => (string) $row['name'],
                'especie' => $row['species'] ?? null,
                'raca' => $row['breed'] ?? null,
                'idade' => ToolFormat::age($row['birth_date'] ?? null, $today),
                'tutor' => ToolFormat::firstName($tutor),
            ];
        }

        return new ToolResult($data, false, [], array_values(array_unique($knownNames)));
    }
}
