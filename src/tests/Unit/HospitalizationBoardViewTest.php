<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Presentation\HospitalizationBoardView;
use CentralVet\Tests\Support\Assert;

/**
 * Fase 6A, T-16: agrupamento das linhas do flowboard do turno em colunas
 * Atrasadas / Próximas / Feitas, sem Adianti.
 */
final class HospitalizationBoardViewTest
{
    public function testGroupSplitsRowsByTimelinessAndDropsCancelled(): void
    {
        $board = HospitalizationBoardView::group([
            self::row(1, '2026-10-05 10:00:00', 'late'),
            self::row(2, '2026-10-05 12:30:00', 'upcoming'),
            self::row(3, '2026-10-05 11:55:00', 'due'),
            self::row(4, '2026-10-05 08:00:00', 'done_late'),
            self::row(5, '2026-10-05 09:00:00', 'cancelled'),
        ]);

        Assert::same(['late' => 1, 'upcoming' => 2, 'done' => 1], $board['counts']);
        Assert::same([1], self::ids($board['late']));
        Assert::same([3, 2], self::ids($board['upcoming']), 'upcoming ordered by scheduled_at');
        Assert::same([4], self::ids($board['done']));

        $all = array_merge(self::ids($board['late']), self::ids($board['upcoming']), self::ids($board['done']));
        Assert::false(in_array(5, $all, true), 'cancelled row must not be on the board');
    }

    public function testDoneColumnIncludesDoneDoneLateAndSkippedOrderedByScheduledAt(): void
    {
        $board = HospitalizationBoardView::group([
            self::row(7, '2026-10-05 10:00:00', 'skipped'),
            self::row(8, '2026-10-05 06:00:00', 'done'),
            self::row(9, '2026-10-05 08:00:00', 'done_late'),
        ]);

        Assert::same([8, 9, 7], self::ids($board['done']));
        Assert::same(['late' => 0, 'upcoming' => 0, 'done' => 3], $board['counts']);
    }

    public function testEmptyInputGivesEmptyColumns(): void
    {
        Assert::same(
            ['late' => [], 'upcoming' => [], 'done' => [], 'counts' => ['late' => 0, 'upcoming' => 0, 'done' => 0]],
            HospitalizationBoardView::group([]),
        );
    }

    /** @return array<string, mixed> */
    private static function row(int $id, string $scheduledAt, string $timeliness): array
    {
        return [
            'administration_id' => $id,
            'hospitalization_id' => 1,
            'patient_name' => 'F6 teste',
            'bed_code' => 'L1',
            'order_type' => 'medication',
            'description_text' => 'Item ' . $id,
            'dose_text' => '1 mL',
            'route' => 'iv',
            'scheduled_at' => $scheduledAt,
            'status' => 'pending',
            'performed_at' => null,
            'timeliness' => $timeliness,
        ];
    }

    /** @param list<array<string, mixed>> $rows @return list<int> */
    private static function ids(array $rows): array
    {
        return array_map(static fn (array $row): int => (int) $row['administration_id'], $rows);
    }
}
