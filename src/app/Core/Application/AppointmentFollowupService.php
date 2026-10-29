<?php

declare(strict_types=1);

namespace CentralVet\Application;

use CentralVet\Domain\Appointment;
use CentralVet\Domain\Contract\AppointmentFollowupRepositoryInterface;
use CentralVet\Domain\Contract\AppointmentRepositoryInterface;
use CentralVet\Domain\Contract\EncounterRepositoryInterface;
use CentralVet\Domain\Encounter;
use CentralVet\Domain\Exception\CrossTenantReferenceException;
use CentralVet\Tenancy\TenantContext;
use InvalidArgumentException;

/**
 * Records that an appointment is the return visit scheduled from an
 * encounter (Fase 7A, `appointment_followup`), so the reminder generation
 * can tell a follow-up from a regular appointment. Called by
 * EncounterView::onScheduleFollowUp inside the same transaction that
 * scheduled the appointment; opens no transaction of its own.
 */
final class AppointmentFollowupService
{
    public function __construct(
        private readonly AppointmentFollowupRepositoryInterface $followups,
        private readonly AppointmentRepositoryInterface $appointments,
        private readonly EncounterRepositoryInterface $encounters,
        private readonly TenantContext $context,
    ) {
    }

    /**
     * @throws CrossTenantReferenceException when the appointment or the
     *         encounter does not resolve within the authenticated tenant
     * @throws InvalidArgumentException when they belong to different patients
     */
    public function link(int $appointmentId, int $encounterId): void
    {
        $appointment = $this->appointments->findById($appointmentId);

        if (!$appointment instanceof Appointment) {
            throw new CrossTenantReferenceException(
                "appointment_id {$appointmentId} was not found for the authenticated tenant"
            );
        }

        $encounter = $this->encounters->findById($encounterId);

        if (!$encounter instanceof Encounter) {
            throw new CrossTenantReferenceException(
                "encounter_id {$encounterId} was not found for the authenticated tenant"
            );
        }

        if ($appointment->patientId !== $encounter->patientId()) {
            throw new InvalidArgumentException(
                "Appointment {$appointmentId} and encounter {$encounterId} belong to different patients"
            );
        }

        $this->followups->link($appointmentId, $encounterId, $this->context->userId());
    }
}
