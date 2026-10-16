<?php

declare(strict_types=1);

namespace CentralVet\Application;

use DateTimeImmutable;

/**
 * Free agenda slots of a day (Fase 8B.2, T-19) on the agenda grid
 * (07:00-19:00, step 30, as AgendaView). A slot start is free for a
 * professional when [start, start + duration) ends by 19:00, does not
 * overlap any busy interval of that professional and starts after $now
 * (so nothing is offered in the past, today included).
 */
final class FreeSlotFinder
{
    private const GRID_START_MINUTES = 7 * 60;
    private const GRID_END_MINUTES = 19 * 60;
    private const STEP_MINUTES = 30;

    /**
     * @param list<array{professional_id: int, start: string, end: string}> $busy
     * @param list<int> $professionalIds
     * @return list<array{start: string, professional_id: int}> start as "Y-m-d H:i", ordered by time then by $professionalIds order
     */
    public function find(DateTimeImmutable $day, int $durationMinutes, array $busy, array $professionalIds, DateTimeImmutable $now): array
    {
        if ($durationMinutes <= 0 || $professionalIds === []) {
            return [];
        }

        $midnight = $day->setTime(0, 0);
        $gridEnd = $midnight->modify('+' . self::GRID_END_MINUTES . ' minutes');
        $intervals = $this->intervalsByProfessional($busy);
        $slots = (new AgendaSlots(self::GRID_START_MINUTES, self::GRID_END_MINUTES, self::STEP_MINUTES))->slots();

        $free = [];
        foreach ($slots as $slot) {
            [$hour, $minute] = array_map('intval', explode(':', $slot));
            $start = $midnight->setTime($hour, $minute);
            $end = $start->modify('+' . $durationMinutes . ' minutes');

            if ($end > $gridEnd || $start <= $now) {
                continue;
            }

            foreach ($professionalIds as $professionalId) {
                if ($this->overlaps($intervals[$professionalId] ?? [], $start, $end)) {
                    continue;
                }

                $free[] = ['start' => $start->format('Y-m-d H:i'), 'professional_id' => $professionalId];
            }
        }

        return $free;
    }

    /**
     * @param list<array{professional_id: int, start: string, end: string}> $busy
     * @return array<int, list<array{0: DateTimeImmutable, 1: DateTimeImmutable}>>
     */
    private function intervalsByProfessional(array $busy): array
    {
        $intervals = [];
        foreach ($busy as $row) {
            $intervals[(int) $row['professional_id']][] = [
                new DateTimeImmutable((string) $row['start']),
                new DateTimeImmutable((string) $row['end']),
            ];
        }

        return $intervals;
    }

    /** @param list<array{0: DateTimeImmutable, 1: DateTimeImmutable}> $intervals */
    private function overlaps(array $intervals, DateTimeImmutable $start, DateTimeImmutable $end): bool
    {
        foreach ($intervals as [$busyStart, $busyEnd]) {
            if ($busyStart < $end && $busyEnd > $start) {
                return true;
            }
        }

        return false;
    }
}
