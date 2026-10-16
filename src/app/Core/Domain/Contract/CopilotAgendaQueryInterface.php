<?php

declare(strict_types=1);

namespace CentralVet\Domain\Contract;

use DateTimeImmutable;

/**
 * Read-only agenda data for the management copilot (Fase 8B.2), scoped to
 * the tenant of the session. Appointments are listed only from the active
 * unit (`appointment.system_unit_id`). Professionals are the active system
 * users that are members of the tenant (same rule as AgendaView). Busy
 * intervals follow AppointmentService::assertNoConflict: only agendado,
 * confirmado, em_atendimento and atendido occupy, for the service duration,
 * and a professional is occupied by any appointment of the tenant (the
 * booking rejects the slot whatever the unit). No patient or tutor contact
 * column is ever returned.
 */
interface CopilotAgendaQueryInterface
{
    /**
     * Appointments of the active unit with scheduled_at in [$from, $to),
     * ordered by time, any status.
     *
     * @return list<array{scheduled_at: string, patient_name: string, service_name: ?string, duration_minutes: int, status: string, professional_id: int, professional_name: string}>
     */
    public function appointments(DateTimeImmutable $from, DateTimeImmutable $to, ?int $professionalId, int $limit): array;

    /**
     * Occupied intervals ("Y-m-d H:i:s", end exclusive) on the calendar day
     * of $day, ordered by professional and start.
     *
     * @return list<array{professional_id: int, start: string, end: string}>
     */
    public function busy(DateTimeImmutable $day, ?int $professionalId): array;

    /** @return list<array{id: int, name: string}> */
    public function professionals(): array;

    /**
     * Professionals the copilot offers free slots for when none is named:
     * active members of the tenant with at least one appointment (any
     * status) in the active unit with scheduled_at in the 90 days before
     * the calendar day of $day or on that day. When nobody qualifies, the
     * agenda list (professionals()) is returned instead. Ordered by name.
     *
     * @return list<array{id: int, name: string}>
     */
    public function schedulableProfessionals(DateTimeImmutable $day): array;

    public function professionalExists(int $id): bool;
}
