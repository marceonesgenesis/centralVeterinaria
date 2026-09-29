<?php

declare(strict_types=1);

namespace CentralVet\Tests\Support;

use CentralVet\Domain\Appointment;
use CentralVet\Domain\Contract\AppointmentRepositoryInterface;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * In-memory double for AppointmentRepositoryInterface (T-16): the
 * `appointment` table does not exist yet (migration T-01 not applied), so
 * AppointmentServiceTest exercises AppointmentService::schedule() (and its
 * scheduling-conflict rule) against this instead of a real database.
 * Tenant-scoped like the real AppointmentRepository (ADR 0002): findById()
 * and both listBy*() methods only ever return appointments whose tenantId
 * matches this instance's own $tenantId.
 */
final class FakeAppointmentRepository implements AppointmentRepositoryInterface
{
    /** @var array<int, Appointment> */
    private array $appointments = [];
    private int $nextId = 1;

    public function __construct(private readonly int $tenantId, Appointment ...$seed)
    {
        foreach ($seed as $appointment) {
            $this->save($appointment);
        }
    }

    public function tenantId(): int
    {
        return $this->tenantId;
    }

    public function findById(int|string $id): ?object
    {
        $appointment = $this->appointments[(int) $id] ?? null;

        if ($appointment === null || $appointment->tenantId !== $this->tenantId) {
            return null;
        }

        return $appointment;
    }

    /** @return list<Appointment> */
    public function listByProfessionalAndDate(int $professionalSystemUserId, DateTimeImmutable $date): array
    {
        return $this->listByDate(
            $date,
            fn (Appointment $appointment): bool => $appointment->professionalSystemUserId === $professionalSystemUserId,
        );
    }

    /** @return list<Appointment> */
    public function listByUnitAndDate(int $systemUnitId, DateTimeImmutable $date): array
    {
        return $this->listByDate(
            $date,
            fn (Appointment $appointment): bool => $appointment->systemUnitId === $systemUnitId,
        );
    }

    public function save(object $entity): object
    {
        if (!$entity instanceof Appointment) {
            throw new InvalidArgumentException('FakeAppointmentRepository only stores Appointment entities');
        }

        $saved = $entity->id !== null ? $entity : $entity->withId($this->nextId++);
        $this->appointments[$saved->id] = $saved;

        return $saved;
    }

    public function remove(object $entity): void
    {
        if ($entity instanceof Appointment && $entity->id !== null) {
            unset($this->appointments[$entity->id]);
        }
    }

    /** @return list<Appointment> */
    private function listByDate(DateTimeImmutable $date, callable $matches): array
    {
        $dayStart = $date->format('Y-m-d 00:00:00');
        $dayEnd = $date->modify('+1 day')->format('Y-m-d 00:00:00');

        return array_values(array_filter(
            $this->appointments,
            function (Appointment $appointment) use ($matches, $dayStart, $dayEnd): bool {
                if ($appointment->tenantId !== $this->tenantId || !$matches($appointment)) {
                    return false;
                }

                $scheduledAt = $appointment->scheduledAt->format('Y-m-d H:i:s');

                return $scheduledAt >= $dayStart && $scheduledAt < $dayEnd;
            },
        ));
    }
}
