<?php

declare(strict_types=1);

namespace CentralVet\Presentation;

use CentralVet\Domain\Surgery;

/**
 * View-model da agenda cirúrgica do dia (Fase 6B, T-17), sem Adianti.
 * Consome a lista de `SurgeryService::listForDay()`.
 *
 * - `countByStatus`: contagem por status com as 5 chaves sempre presentes
 *   (`scheduled`, `pre_op`, `in_progress`, `completed`, `cancelled`);
 * - `sortByStart`: ordena pelo início agendado; empate mantém a ordem de
 *   entrada.
 */
final class SurgeryAgendaView
{
    private function __construct()
    {
    }

    /**
     * @param list<Surgery> $surgeries
     * @return array{scheduled: int, pre_op: int, in_progress: int, completed: int, cancelled: int}
     */
    public static function countByStatus(array $surgeries): array
    {
        $counts = [
            Surgery::STATUS_SCHEDULED => 0,
            Surgery::STATUS_PRE_OP => 0,
            Surgery::STATUS_IN_PROGRESS => 0,
            Surgery::STATUS_COMPLETED => 0,
            Surgery::STATUS_CANCELLED => 0,
        ];

        foreach ($surgeries as $surgery) {
            $status = $surgery->status();

            if (array_key_exists($status, $counts)) {
                $counts[$status]++;
            }
        }

        return $counts;
    }

    /**
     * @param list<Surgery> $surgeries
     * @return list<Surgery>
     */
    public static function sortByStart(array $surgeries): array
    {
        $sorted = array_values($surgeries);

        // usort é estável desde o PHP 8.0: empate mantém a ordem de entrada.
        usort(
            $sorted,
            static fn (Surgery $a, Surgery $b): int => $a->scheduledStartAt() <=> $b->scheduledStartAt(),
        );

        return $sorted;
    }
}
