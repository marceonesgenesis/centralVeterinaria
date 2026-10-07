<?php

declare(strict_types=1);

namespace CentralVet\Application;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Time grid of the agenda (rodada 2, T-25): the list of "H:i" slot starts
 * between $startMinutes (inclusive) and $endMinutes (exclusive), stepping by
 * $stepMinutes, and the rule that maps any appointment time to the slot row
 * it is drawn in.
 *
 * slotFor() rounds down to the slot start at or before the given time;
 * times before the grid go to the first slot, and times at or after
 * $endMinutes go to the last one, so no appointment is ever left off the
 * grid. Minutes are counted from midnight; the date part is ignored.
 */
final class AgendaSlots
{
    private int $startMinutes;
    private int $endMinutes;
    private int $stepMinutes;

    public function __construct(int $startMinutes, int $endMinutes, int $stepMinutes)
    {
        if ($stepMinutes <= 0 || $endMinutes <= $startMinutes) {
            throw new InvalidArgumentException('Invalid agenda slot configuration');
        }

        $this->startMinutes = $startMinutes;
        $this->endMinutes = $endMinutes;
        $this->stepMinutes = $stepMinutes;
    }

    /** @return list<string> "H:i" slot starts, in order */
    public function slots(): array
    {
        $slots = [];

        for ($minutes = $this->startMinutes; $minutes < $this->endMinutes; $minutes += $this->stepMinutes) {
            $slots[] = self::format($minutes);
        }

        return $slots;
    }

    public function slotFor(DateTimeImmutable $at): string
    {
        $minutes = (int) $at->format('G') * 60 + (int) $at->format('i');
        $last = $this->lastSlotMinutes();

        if ($minutes <= $this->startMinutes) {
            return self::format($this->startMinutes);
        }

        if ($minutes >= $last) {
            return self::format($last);
        }

        $offset = $minutes - $this->startMinutes;

        return self::format($this->startMinutes + intdiv($offset, $this->stepMinutes) * $this->stepMinutes);
    }

    private function lastSlotMinutes(): int
    {
        $span = $this->endMinutes - 1 - $this->startMinutes;

        return $this->startMinutes + intdiv($span, $this->stepMinutes) * $this->stepMinutes;
    }

    private static function format(int $minutes): string
    {
        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }
}
