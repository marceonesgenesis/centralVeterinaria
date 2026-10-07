<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Domain\Surgery;
use CentralVet\Presentation\SurgeryAgendaView;
use CentralVet\Tests\Support\Assert;

/**
 * Fase 6B, T-17: contagem por status e ordenação por início da agenda
 * cirúrgica do dia, sem Adianti.
 */
final class SurgeryAgendaViewTest
{
    public function testCountByStatusHasAllStatusesWithZeroForMissing(): void
    {
        Assert::same(
            ['scheduled' => 1, 'pre_op' => 0, 'in_progress' => 1, 'completed' => 0, 'cancelled' => 1],
            SurgeryAgendaView::countByStatus(self::agenda()),
        );
    }

    public function testCountByStatusOfEmptyDayIsAllZero(): void
    {
        Assert::same(
            ['scheduled' => 0, 'pre_op' => 0, 'in_progress' => 0, 'completed' => 0, 'cancelled' => 0],
            SurgeryAgendaView::countByStatus([]),
        );
    }

    public function testSortByStartOrdersByScheduledStart(): void
    {
        $sorted = SurgeryAgendaView::sortByStart(self::agenda());

        Assert::same(
            ['08:00', '09:00', '10:00'],
            array_map(static fn (Surgery $s): string => $s->scheduledStartAt()->format('H:i'), $sorted),
        );
        Assert::same([2, 3, 1], array_map(static fn (Surgery $s): ?int => $s->id(), $sorted));
    }

    public function testSortByStartKeepsInputOrderOnTie(): void
    {
        $sorted = SurgeryAgendaView::sortByStart([
            self::surgery(5, 'scheduled', '10:00'),
            self::surgery(4, 'pre_op', '10:00'),
            self::surgery(6, 'completed', '07:30'),
        ]);

        Assert::same([6, 5, 4], array_map(static fn (Surgery $s): ?int => $s->id(), $sorted));
    }

    /** @return list<Surgery> */
    private static function agenda(): array
    {
        return [
            self::surgery(1, 'scheduled', '10:00'),
            self::surgery(2, 'in_progress', '08:00'),
            self::surgery(3, 'cancelled', '09:00'),
        ];
    }

    private static function surgery(int $id, string $status, string $start): Surgery
    {
        $startAt = '2026-10-05 ' . $start . ':00';

        return Surgery::reconstitute([
            'id' => $id,
            'tenant_id' => 1,
            'system_unit_id' => 1,
            'patient_id' => 10,
            'encounter_id' => 20,
            'room_id' => 30,
            'procedure_catalog_item_id' => 40,
            'procedure_name' => 'F6B teste procedimento',
            'procedure_price_cents' => 0,
            'surgeon_system_user_id' => 2,
            'scheduled_by_system_user_id' => 2,
            'scheduled_start_at' => $startAt,
            'scheduled_end_at' => (new \DateTimeImmutable($startAt))->modify('+1 hour')->format('Y-m-d H:i:s'),
            'status' => $status,
        ]);
    }
}
