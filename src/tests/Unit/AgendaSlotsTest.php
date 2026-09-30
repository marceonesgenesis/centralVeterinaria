<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Application\AgendaSlots;
use CentralVet\Tests\Support\Assert;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Rodada 2, T-25: time → grid slot rule used by AgendaView (appointments
 * outside the exact 30-min slot used to vanish from the grid).
 */
final class AgendaSlotsTest
{
    private function slots(): AgendaSlots
    {
        return new AgendaSlots(420, 1140, 30);
    }

    public function testSlotForRoundsDownInsideTheGrid(): void
    {
        $slots = $this->slots();

        Assert::same('14:00', $slots->slotFor(new DateTimeImmutable('2026-09-30 14:21')));
        Assert::same('15:30', $slots->slotFor(new DateTimeImmutable('2026-09-30 15:59')));
        Assert::same('14:30', $slots->slotFor(new DateTimeImmutable('2026-09-30 14:30')));
    }

    public function testSlotForClampsOutsideTheGrid(): void
    {
        $slots = $this->slots();

        Assert::same('07:00', $slots->slotFor(new DateTimeImmutable('2026-09-30 06:45')));
        Assert::same('18:30', $slots->slotFor(new DateTimeImmutable('2026-09-30 19:10')));
        Assert::same('18:30', $slots->slotFor(new DateTimeImmutable('2026-09-30 19:00')));
    }

    public function testSlotsListsEveryStartBetweenStartAndEnd(): void
    {
        $list = $this->slots()->slots();

        Assert::count(24, $list);
        Assert::same('07:00', $list[0]);
        Assert::same('07:30', $list[1]);
        Assert::same('18:30', $list[23]);
    }

    public function testInvalidConfigurationIsRejected(): void
    {
        foreach ([[420, 1140, 0], [420, 1140, -30], [1140, 420, 30], [420, 420, 30]] as [$start, $end, $step]) {
            $message = null;

            try {
                new AgendaSlots($start, $end, $step);
            } catch (InvalidArgumentException $e) {
                $message = $e->getMessage();
            }

            Assert::same('Invalid agenda slot configuration', $message, "config {$start}/{$end}/{$step}");
        }
    }
}
