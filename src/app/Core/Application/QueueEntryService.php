<?php

declare(strict_types=1);

namespace CentralVet\Application;

use CentralVet\Authorization\AuthorizationRequest;
use CentralVet\Authorization\Contract\AuthorizationPolicyInterface;
use CentralVet\Domain\Contract\QueueEntryRepositoryInterface;
use CentralVet\Domain\Exception\CrossTenantReferenceException;
use CentralVet\Domain\Exception\InvalidStatusTransitionException;
use CentralVet\Domain\QueueEntry;
use CentralVet\Tenancy\TenantContext;
use DateTimeImmutable;
use DomainException;
use InvalidArgumentException;

/**
 * Use cases for the QueueEntry aggregate (T-08): checking a patient into the
 * front-desk/waiting-room queue (either walk-in or off a prior appointment)
 * and advancing it through service.
 *
 * Depends only on Domain contracts, `PatientService` (T-05) and
 * `TenantContext` — no TPage or any other Adianti class (ADR 0001), so it
 * can run from REST, workers or MCP exactly like from the current Adianti
 * presentation layer.
 *
 * Deliberately does NOT depend on anything Appointment-related (T-07, being
 * built in parallel): `appointment_id` is accepted and stored as a plain
 * nullable int, matching the migration's `queue_entry.appointment_id NULL`
 * column — no existence/tenant check against an appointment is performed
 * here. The `queue_entry_appointment_fk` foreign key (ON DELETE SET NULL)
 * is the enforcement point for that reference once the migration is
 * applied.
 *
 * Unit-scope authorization (post-Fase-1 gap closed here): both checkIn()
 * and advanceStatus() ask the injected AuthorizationPolicyInterface whether
 * the caller's active unit (TenantContext::unitId(), via
 * TenantContext::requireUnitId()) matches the queue entry's own
 * system_unit_id, via a unit-scoped AuthorizationRequest. This Core class
 * never hardcodes an Adianti class name (ADR 0001): the "ClassName::method"
 * action string is supplied by the caller (the Presentation-layer
 * controller) through each method's $action parameter.
 */
final class QueueEntryService
{
    public function __construct(
        private readonly QueueEntryRepositoryInterface $queueEntries,
        private readonly PatientService $patients,
        private readonly TenantContext $context,
        private readonly AuthorizationPolicyInterface $authorization,
    ) {
    }

    /**
     * @param array{
     *     patient_id: int|string,
     *     professional_system_user_id: int|string,
     *     system_unit_id: int|string,
     *     appointment_id?: int|string|null,
     * } $data
     * @param string $action "ClassName::method" identifying the caller for
     *        the permission provider and the audit trail — this Core class
     *        does not know Adianti class names itself (ADR 0001).
     *
     * @throws CrossTenantReferenceException when patient_id does not
     *         resolve within the authenticated tenant (missing or belongs
     *         to another tenant — fail closed, same convention
     *         PatientService uses for tutor_id).
     * @throws DomainException when appointment_id is already in the queue
     *         ("Appointment {id} is already in the queue").
     * @throws \CentralVet\Authorization\Exception\AuthorizationDenied when
     *         the active unit is missing or does not match system_unit_id,
     *         or the caller lacks permission for $action.
     */
    public function checkIn(array $data, string $action): QueueEntry
    {
        foreach (['patient_id', 'professional_system_user_id', 'system_unit_id'] as $required) {
            if (!array_key_exists($required, $data)) {
                throw new InvalidArgumentException("{$required} is required");
            }
        }

        $patientId = (int) $data['patient_id'];
        $professionalSystemUserId = (int) $data['professional_system_user_id'];
        $systemUnitId = (int) $data['system_unit_id'];
        $appointmentId = isset($data['appointment_id']) && $data['appointment_id'] !== ''
            ? (int) $data['appointment_id']
            : null;

        // PatientRepository (via PatientService) is tenant-aware (ADR 0002):
        // findById() returns null both when the patient does not exist and
        // when it belongs to another tenant — that ambiguity is intentional
        // (fail closed).
        if ($this->patients->findById($patientId) === null) {
            throw new CrossTenantReferenceException(
                "patient_id {$patientId} was not found for the authenticated tenant"
            );
        }

        // An appointment enters the queue at most once (T-29): refused after
        // the tenant check and before authorization, so nothing is saved.
        // Walk-ins (no appointment_id) are never deduplicated.
        if ($appointmentId !== null && $appointmentId > 0
            && $this->queueEntries->findByAppointment($appointmentId) !== null) {
            throw new DomainException("Appointment {$appointmentId} is already in the queue");
        }

        // Unit-scope authorization, run after the cross-tenant patient_id
        // check and before building/persisting the entry. assertAllowed()
        // throws AuthorizationDenied on denial, left to propagate.
        $this->authorization->decide(new AuthorizationRequest(
            context: $this->context,
            action: $action,
            requiresUnitScope: true,
            resourceUnitId: $systemUnitId,
            entityType: 'queue_entry',
            entityId: null,
        ))->assertAllowed();

        $entry = QueueEntry::checkIn(
            tenantId: $this->context->tenantId(),
            systemUnitId: $systemUnitId,
            patientId: $patientId,
            appointmentId: $appointmentId,
            professionalSystemUserId: $professionalSystemUserId,
            now: new DateTimeImmutable(),
        );

        /** @var QueueEntry $saved */
        $saved = $this->queueEntries->save($entry);

        return $saved;
    }

    /**
     * Advances a queue entry exactly one step: `aguardando` ->
     * `em_atendimento` -> `atendido`. There is no way to target an
     * arbitrary status through this method — it only ever moves the entry
     * from wherever it currently is to the single legal next status — so
     * skipping a step or regressing are both rejected the same way: as "no
     * legal next status from here".
     *
     * @param string $action "ClassName::method" identifying the caller (see
     *        checkIn()'s $action docblock).
     *
     * @throws InvalidArgumentException when no queue entry with this id
     *         exists for the authenticated tenant.
     * @throws \CentralVet\Authorization\Exception\AuthorizationDenied when
     *         the active unit is missing or does not match the entry's own
     *         system_unit_id, or the caller lacks permission for $action.
     *         Unlike schedule()/checkIn(), the unit checked here is not
     *         taken from caller input — it is read back from the already
     *         persisted entry ({@see QueueEntry::systemUnitId()}), which is
     *         exactly what closes the gap this check exists for: it stops a
     *         user whose active unit is, say, "Unidade Centro" from
     *         advancing a queue entry that actually belongs to a different
     *         unit of the same tenant.
     * @throws InvalidStatusTransitionException when the entry's current
     *         status has no legal next status (see
     *         {@see QueueEntry::advance()}).
     */
    public function advanceStatus(int $id, string $action): QueueEntry
    {
        /** @var QueueEntry|null $entry */
        $entry = $this->queueEntries->findById($id);

        if ($entry === null) {
            throw new InvalidArgumentException(
                "queue_entry {$id} was not found for the authenticated tenant"
            );
        }

        // Unit-scope authorization against the entry's REAL unit (not a
        // caller-supplied one — advanceStatus() takes no unit parameter),
        // run after loading the entry but before mutating it.
        // assertAllowed() throws AuthorizationDenied on denial, left to
        // propagate.
        $this->authorization->decide(new AuthorizationRequest(
            context: $this->context,
            action: $action,
            requiresUnitScope: true,
            resourceUnitId: $entry->systemUnitId(),
            entityType: 'queue_entry',
            entityId: $id,
        ))->assertAllowed();

        $entry->advance(new DateTimeImmutable());

        /** @var QueueEntry $saved */
        $saved = $this->queueEntries->save($entry);

        return $saved;
    }

    /**
     * Lists today's queue for a unit within the current tenant.
     *
     * Implemented via `listActiveByUnit()` — the only listing method
     * `QueueEntryRepositoryInterface` (T-02, contract not redefined here)
     * exposes — rather than a separate "by date" query: a queue entry's
     * lifecycle (check in -> advance -> attended) is inherently same-day,
     * so "active" (not yet `atendido`) and "today's queue" coincide in
     * practice for this waiting-room use case.
     *
     * @return list<QueueEntry>
     */
    public function listToday(int $systemUnitId): array
    {
        /** @var list<QueueEntry> $entries */
        $entries = $this->queueEntries->listActiveByUnit($systemUnitId);

        return $entries;
    }

    /**
     * Which of the given appointments already have a queue entry in the
     * current tenant (T-41): AgendaView calls it once per load with the
     * day's appointment ids to show "In queue" instead of Check-in.
     *
     * @param list<int> $appointmentIds
     * @return list<int>
     */
    public function appointmentIdsInQueue(array $appointmentIds): array
    {
        return $this->queueEntries->listAppointmentIdsInQueue($appointmentIds);
    }
}
