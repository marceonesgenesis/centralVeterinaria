<?php

declare(strict_types=1);

namespace CentralVet\Application;

use CentralVet\Authorization\AuthorizationRequest;
use CentralVet\Authorization\Contract\AuthorizationPolicyInterface;
use CentralVet\Domain\Appointment;
use CentralVet\Domain\Contract\AppointmentRepositoryInterface;
use CentralVet\Domain\Contract\ServiceRepositoryInterface;
use CentralVet\Domain\Exception\CrossTenantReferenceException;
use CentralVet\Domain\Exception\InvalidStatusTransitionException;
use CentralVet\Domain\Exception\SchedulingConflictException;
use CentralVet\Domain\Service;
use CentralVet\Support\DateTimeInput;
use CentralVet\Tenancy\TenantContext;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Use cases for the Appointment aggregate (T-07).
 *
 * Depends only on Domain contracts, other Application services and
 * TenantContext — no TPage or any other Adianti class (ADR 0001).
 *
 * Dependency note: this class consumes CentralVet\Application\PatientService
 * (T-05) to validate patient_id. For service_id it consumes
 * ServiceRepositoryInterface directly: schedule() and reschedule() need
 * random-access lookup of an arbitrary service's duration_minutes (both for
 * the appointment being booked and for every existing appointment it must
 * be checked against). ServiceRepositoryInterface's findById() is
 * tenant-scoped (ADR 0002) the same way PatientRepositoryInterface and
 * TutorRepositoryInterface are, so this preserves fail-closed cross-tenant
 * behaviour.
 *
 * Scheduling-conflict rule (T-07's acceptance criterion) lives here, in the
 * Application layer, not in AppointmentRepository: see the docblock on
 * AppointmentRepository for the rationale (cross-aggregate duration lookup,
 * unit-testability without a live database).
 *
 * Unit-scope authorization (post-Fase-1 gap closed here): schedule() and
 * reschedule() also
 * asks the injected AuthorizationPolicyInterface whether the caller's active
 * unit (TenantContext::unitId(), via TenantContext::requireUnitId()) is
 * allowed to act on the resource's own system_unit_id, via a unit-scoped
 * AuthorizationRequest. This Core class never hardcodes an Adianti class
 * name (ADR 0001): the "ClassName::method" action string is supplied by the
 * caller (the Presentation-layer controller) through the $action parameter.
 */
final class AppointmentService
{
    public function __construct(
        private readonly AppointmentRepositoryInterface $appointments,
        private readonly ServiceRepositoryInterface $services,
        private readonly PatientService $patients,
        private readonly TenantContext $context,
        private readonly AuthorizationPolicyInterface $authorization,
    ) {
    }

    /**
     * @param array{
     *     patient_id: int|string,
     *     service_id: int|string,
     *     professional_system_user_id: int|string,
     *     scheduled_at: string|DateTimeImmutable,
     *     system_unit_id: int|string,
     * } $data
     * @param string $action "ClassName::method" identifying the caller for
     *        the permission provider and the audit trail (e.g.
     *        "AppointmentForm::onSave") — this Core class does not know
     *        Adianti class names itself (ADR 0001).
     *
     * @throws CrossTenantReferenceException when patient_id or service_id
     *         does not resolve within the authenticated tenant.
     * @throws \CentralVet\Authorization\Exception\AuthorizationDenied when
     *         the active unit is missing or does not match system_unit_id,
     *         or the caller lacks permission for $action.
     * @throws SchedulingConflictException when [scheduled_at, scheduled_at +
     *         service duration) overlaps another still-active appointment
     *         already booked for the same professional_system_user_id.
     */
    public function schedule(array $data, string $action): Appointment
    {
        foreach (['patient_id', 'service_id', 'professional_system_user_id', 'scheduled_at', 'system_unit_id'] as $required) {
            if (!array_key_exists($required, $data)) {
                throw new InvalidArgumentException("{$required} is required");
            }
        }

        $patientId = (int) $data['patient_id'];
        $serviceId = (int) $data['service_id'];
        $professionalId = (int) $data['professional_system_user_id'];
        $systemUnitId = (int) $data['system_unit_id'];
        $scheduledAt = $data['scheduled_at'] instanceof DateTimeImmutable
            ? $data['scheduled_at']
            : DateTimeInput::parse((string) $data['scheduled_at']);

        // Tenant-scoped lookups (ADR 0002): findById() returns null both when
        // the referenced row does not exist and when it belongs to another
        // tenant, so this rejects a cross-tenant patient_id/service_id
        // without a separate "which tenant owns this" check (mirrors
        // PatientService::create()'s treatment of tutor_id).
        if ($this->patients->findById($patientId) === null) {
            throw new CrossTenantReferenceException(
                "patient_id {$patientId} was not found for the authenticated tenant"
            );
        }

        $service = $this->services->findById($serviceId);

        if (!$service instanceof Service) {
            throw new CrossTenantReferenceException(
                "service_id {$serviceId} was not found for the authenticated tenant"
            );
        }

        // Unit-scope authorization, run after cross-tenant reference checks
        // (a patient_id/service_id from another tenant is rejected on its
        // own terms, without leaking whether it would also have been a unit
        // mismatch) and before the scheduling-conflict check (no point
        // computing a conflict for a unit the caller is not allowed to
        // touch). assertAllowed() throws AuthorizationDenied on denial,
        // which is left to propagate to the caller.
        $this->authorization->decide(new AuthorizationRequest(
            context: $this->context,
            action: $action,
            requiresUnitScope: true,
            resourceUnitId: $systemUnitId,
            entityType: 'appointment',
            entityId: null,
        ))->assertAllowed();

        $candidate = new Appointment(
            id: null,
            tenantId: $this->context->tenantId(),
            systemUnitId: $systemUnitId,
            patientId: $patientId,
            serviceId: $serviceId,
            professionalSystemUserId: $professionalId,
            scheduledAt: $scheduledAt,
        );

        $this->assertNoConflict($candidate, $service->durationMinutes());

        /** @var Appointment $saved */
        $saved = $this->appointments->save($candidate);

        return $saved;
    }

    /**
     * Moves an existing appointment to another service, professional and/or
     * slot (rodada 2, T-08). patientId, systemUnitId and status never change.
     *
     * @param array{
     *     service_id: int|string,
     *     professional_system_user_id: int|string,
     *     scheduled_at: string|DateTimeImmutable,
     * } $data
     * @param string $action "ClassName::method" of the caller (ADR 0001).
     *
     * @throws InvalidArgumentException when a required key is missing or the
     *         appointment does not exist for this tenant.
     * @throws InvalidStatusTransitionException when the appointment is not
     *         scheduled/confirmed.
     * @throws CrossTenantReferenceException when service_id does not resolve
     *         within the authenticated tenant.
     * @throws \CentralVet\Authorization\Exception\AuthorizationDenied when
     *         the active unit does not match the appointment's unit or the
     *         caller lacks permission for $action.
     * @throws SchedulingConflictException when the new slot overlaps another
     *         active appointment of the professional (the appointment itself
     *         is ignored, so keeping the same slot is allowed).
     */
    public function reschedule(int $id, array $data, string $action): Appointment
    {
        foreach (['service_id', 'professional_system_user_id', 'scheduled_at'] as $required) {
            if (!array_key_exists($required, $data)) {
                throw new InvalidArgumentException("{$required} is required");
            }
        }

        $current = $this->appointments->findById($id);

        if (!$current instanceof Appointment) {
            throw new InvalidArgumentException("Appointment {$id} not found for this tenant");
        }

        if (!in_array($current->status, [Appointment::STATUS_SCHEDULED, Appointment::STATUS_CONFIRMED], true)) {
            throw new InvalidStatusTransitionException(
                "Appointment {$id} cannot be rescheduled from status {$current->status}"
            );
        }

        $serviceId = (int) $data['service_id'];
        $professionalId = (int) $data['professional_system_user_id'];
        $scheduledAt = $data['scheduled_at'] instanceof DateTimeImmutable
            ? $data['scheduled_at']
            : DateTimeInput::parse((string) $data['scheduled_at']);

        $service = $this->services->findById($serviceId);

        if (!$service instanceof Service) {
            throw new CrossTenantReferenceException(
                "service_id {$serviceId} was not found for the authenticated tenant"
            );
        }

        $this->authorization->decide(new AuthorizationRequest(
            context: $this->context,
            action: $action,
            requiresUnitScope: true,
            resourceUnitId: $current->systemUnitId,
            entityType: 'appointment',
            entityId: $id,
        ))->assertAllowed();

        $candidate = new Appointment(
            id: $current->id,
            tenantId: $current->tenantId,
            systemUnitId: $current->systemUnitId,
            patientId: $current->patientId,
            serviceId: $serviceId,
            professionalSystemUserId: $professionalId,
            scheduledAt: $scheduledAt,
            status: $current->status,
            createdAt: $current->createdAt,
            updatedAt: $current->updatedAt,
        );

        $this->assertNoConflict($candidate, $service->durationMinutes());

        /** @var Appointment $saved */
        $saved = $this->appointments->save($candidate);

        return $saved;
    }

    /**
     * Tenant-scoped lookup of a single appointment by id (passthrough to
     * AppointmentRepositoryInterface::findById(), already tenant-scoped via
     * AbstractTenantRepository::tenantQuery(), ADR 0002): returns null both
     * when no row exists with this id and when it belongs to another
     * tenant, exactly like the patient_id/service_id lookups in schedule().
     * Added so callers (e.g. EncounterView's financial summary) can resolve
     * an Encounter's appointment_id into its service_id without keeping
     * that value in TSession.
     */
    public function findById(int $id): ?Appointment
    {
        /** @var Appointment|null $appointment */
        $appointment = $this->appointments->findById($id);

        return $appointment;
    }

    /** @return list<Appointment> */
    public function listByProfessionalAndDate(int $professionalId, DateTimeImmutable $date): array
    {
        /** @var list<Appointment> $appointments */
        $appointments = $this->appointments->listByProfessionalAndDate($professionalId, $date);

        return $appointments;
    }

    /**
     * Rejects $candidate when [scheduled_at, scheduled_at + $durationMinutes)
     * overlaps another appointment already booked for the same
     * professional_system_user_id. Only appointments still considered
     * "active" on the agenda are checked: a cancelled or no-show appointment
     * no longer occupies its slot, so it cannot block a new one.
     */
    private function assertNoConflict(Appointment $candidate, int $durationMinutes): void
    {
        $candidateEnd = $candidate->endsAt($durationMinutes);

        $blockingStatuses = [
            Appointment::STATUS_SCHEDULED,
            Appointment::STATUS_CONFIRMED,
            Appointment::STATUS_IN_PROGRESS,
            Appointment::STATUS_DONE,
        ];

        // listByProfessionalAndDate() is scoped to the calendar day of
        // scheduled_at; appointments are assumed not to span past midnight,
        // consistent with clinic scheduling in whole-minute slots.
        $existingAppointments = $this->appointments->listByProfessionalAndDate(
            $candidate->professionalSystemUserId,
            $candidate->scheduledAt,
        );

        foreach ($existingAppointments as $existing) {
            if (!$existing instanceof Appointment) {
                continue;
            }

            if ($existing->id !== null && $candidate->id !== null && $existing->id === $candidate->id) {
                continue;
            }

            if (!in_array($existing->status, $blockingStatuses, true)) {
                continue;
            }

            $existingService = $this->services->findById($existing->serviceId);
            $existingDuration = $existingService instanceof Service ? $existingService->durationMinutes() : 0;

            if ($existingDuration <= 0) {
                continue;
            }

            if ($existing->overlaps($existingDuration, $candidate->scheduledAt, $candidateEnd)) {
                throw new SchedulingConflictException(sprintf(
                    'Requested slot %s-%s conflicts with an existing appointment for professional_system_user_id %d',
                    $candidate->scheduledAt->format('Y-m-d H:i'),
                    $candidateEnd->format('Y-m-d H:i'),
                    $candidate->professionalSystemUserId,
                ));
            }
        }
    }
}
