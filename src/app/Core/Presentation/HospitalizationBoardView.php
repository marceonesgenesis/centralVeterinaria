<?php

declare(strict_types=1);

namespace CentralVet\Presentation;

/**
 * Agrupamento das linhas do flowboard do turno (T-16) nas colunas
 * Atrasadas / Próximas / Feitas, sem Adianti. Consome as linhas de
 * `HospitalizationOrderService::boardRowsForCurrentUnit()`, que já trazem a
 * chave `timeliness` derivada por `HospitalizationAdministration::classify()`.
 *
 * - late: `late`;
 * - upcoming: `due` ou `upcoming`;
 * - done: `done`, `done_late` ou `skipped`;
 * - `cancelled` (e qualquer outro valor) fica fora.
 *
 * Cada coluna é ordenada por `scheduled_at` (formato `Y-m-d H:i:s`, que
 * ordena como texto); empate mantém a ordem de entrada.
 */
final class HospitalizationBoardView
{
    private const COLUMN_BY_TIMELINESS = [
        'late' => 'late',
        'due' => 'upcoming',
        'upcoming' => 'upcoming',
        'done' => 'done',
        'done_late' => 'done',
        'skipped' => 'done',
    ];

    private function __construct()
    {
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array{late: list<array<string, mixed>>, upcoming: list<array<string, mixed>>, done: list<array<string, mixed>>, counts: array{late: int, upcoming: int, done: int}}
     */
    public static function group(array $rows): array
    {
        $columns = ['late' => [], 'upcoming' => [], 'done' => []];

        foreach ($rows as $row) {
            $column = self::COLUMN_BY_TIMELINESS[(string) ($row['timeliness'] ?? '')] ?? null;

            if ($column !== null) {
                $columns[$column][] = $row;
            }
        }

        foreach ($columns as $key => $list) {
            usort(
                $list,
                static fn (array $a, array $b): int => strcmp((string) ($a['scheduled_at'] ?? ''), (string) ($b['scheduled_at'] ?? '')),
            );
            $columns[$key] = $list;
        }

        return [
            'late' => $columns['late'],
            'upcoming' => $columns['upcoming'],
            'done' => $columns['done'],
            'counts' => [
                'late' => count($columns['late']),
                'upcoming' => count($columns['upcoming']),
                'done' => count($columns['done']),
            ],
        ];
    }
}
