<?php

declare(strict_types=1);

namespace CentralVet\Application;

use CentralVet\Authorization\AuthorizationRequest;
use CentralVet\Authorization\Contract\AuthorizationPolicyInterface;
use CentralVet\Domain\Contract\EncounterRepositoryInterface;
use CentralVet\Domain\Contract\PrescriptionRepositoryInterface;
use CentralVet\Domain\Encounter;
use CentralVet\Domain\Exception\CrossTenantReferenceException;
use CentralVet\Domain\Prescription;
use CentralVet\Domain\PrescriptionItem;
use CentralVet\Tenancy\TenantContext;
use InvalidArgumentException;

/**
 * Use cases for the Prescription aggregate (T-03): issuing a prescription
 * (header + medication lines) from an in-progress or finished encounter, and
 * reading one back by id.
 *
 * Depends only on Domain contracts and TenantContext — no TPage or any other
 * Adianti class (ADR 0001), so it can run from REST, workers or MCP exactly
 * like from the current Adianti presentation layer.
 *
 * Unit-scope authorization design choice: create()'s $data carries no
 * system_unit_id of its own. A prescription is always issued from within an
 * already-open Encounter (encounter_id is required), and that Encounter
 * already carries the unit the clinical act actually happened in
 * (Encounter::systemUnitId()) — trusting a second, caller-supplied unit id
 * here would let a caller assert a different unit than the encounter it
 * claims to prescribe from, the same class of bug
 * EncounterService::finish() avoids by reading the unit back off the
 * persisted encounter instead of taking it from caller input (see that
 * method's docblock). So this service takes
 * CentralVet\Domain\Contract\EncounterRepositoryInterface (already defined
 * in Phase 2) in its constructor purely to resolve encounter_id ->
 * system_unit_id, mirroring how AppointmentService::schedule() takes
 * ServiceRepositoryInterface to resolve service_id -> duration_minutes for a
 * check it cannot make from caller input alone. It does not depend on
 * EncounterService (the Application-layer use case class) to avoid a
 * cross-service Application dependency for a single read-only lookup.
 *
 * The AuthorizationPolicyInterface::decide() call runs after that encounter
 * lookup (a cross-tenant/nonexistent encounter_id is rejected on its own
 * terms first, without leaking whether it would also have been a unit
 * mismatch — the same ordering AppointmentService::schedule() uses) and
 * strictly before any Prescription/PrescriptionItem is persisted: a denied
 * decision throws AuthorizationDenied and nothing is written.
 */
final class PrescriptionService
{
    public function __construct(
        private readonly PrescriptionRepositoryInterface $prescriptions,
        private readonly EncounterRepositoryInterface $encounters,
        private readonly AuthorizationPolicyInterface $authorization,
        private readonly TenantContext $context,
    ) {
    }

    /**
     * @param array{
     *     encounter_id: int|string,
     *     patient_id: int|string,
     *     professional_system_user_id: int|string,
     *     orientation?: string|null,
     *     items: list<array{
     *         medication_name: string,
     *         dose: string,
     *         dose_unit: string,
     *         route: string,
     *         frequency: string,
     *         duration: string,
     *     }>,
     * } $data
     * @param string $action "ClassName::method" identifying the caller for
     *        the permission provider and the audit trail — this Core class
     *        does not know Adianti class names itself (ADR 0001).
     *
     * @throws InvalidArgumentException when a required key is missing or
     *         items is empty.
     * @throws CrossTenantReferenceException when encounter_id does not
     *         resolve within the authenticated tenant.
     * @throws \CentralVet\Authorization\Exception\AuthorizationDenied when
     *         the active unit is missing or does not match the source
     *         encounter's own system_unit_id, or the caller lacks
     *         permission for $action. Nothing is persisted when this is
     *         thrown.
     */
    public function create(array $data, string $action): Prescription
    {
        foreach (['encounter_id', 'patient_id', 'professional_system_user_id', 'items'] as $required) {
            if (!array_key_exists($required, $data)) {
                throw new InvalidArgumentException("{$required} is required");
            }
        }

        $encounterId = (int) $data['encounter_id'];
        $patientId = (int) $data['patient_id'];
        $professionalSystemUserId = (int) $data['professional_system_user_id'];
        $orientation = isset($data['orientation']) && $data['orientation'] !== ''
            ? (string) $data['orientation']
            : null;

        if (!is_array($data['items']) || $data['items'] === []) {
            throw new InvalidArgumentException('items must be a non-empty list');
        }

        // Tenant-scoped lookup (ADR 0002): findById() returns null both when
        // the referenced row does not exist and when it belongs to another
        // tenant, so this rejects a cross-tenant encounter_id without a
        // separate "which tenant owns this" check (mirrors
        // AppointmentService::schedule()'s treatment of patient_id/service_id).
        $encounter = $this->encounters->findById($encounterId);

        if (!$encounter instanceof Encounter) {
            throw new CrossTenantReferenceException(
                "encounter_id {$encounterId} was not found for the authenticated tenant"
            );
        }

        // Unit-scope authorization against the source encounter's REAL unit
        // (not caller input — see this class's docblock for why). Run before
        // building/persisting the prescription: assertAllowed() throws
        // AuthorizationDenied on denial, left to propagate, and nothing has
        // been written yet at this point.
        $this->authorization->decide(new AuthorizationRequest(
            context: $this->context,
            action: $action,
            requiresUnitScope: true,
            resourceUnitId: $encounter->systemUnitId(),
            entityType: 'prescription',
            entityId: null,
        ))->assertAllowed();

        $tenantId = $this->context->tenantId();

        $items = [];

        foreach ($data['items'] as $itemData) {
            foreach (['medication_name', 'dose', 'dose_unit', 'route', 'frequency', 'duration'] as $requiredItemField) {
                if (!array_key_exists($requiredItemField, $itemData)) {
                    throw new InvalidArgumentException("items[].{$requiredItemField} is required");
                }
            }

            $items[] = PrescriptionItem::create(
                tenantId: $tenantId,
                medicationName: (string) $itemData['medication_name'],
                dose: (string) $itemData['dose'],
                doseUnit: (string) $itemData['dose_unit'],
                route: (string) $itemData['route'],
                frequency: (string) $itemData['frequency'],
                duration: (string) $itemData['duration'],
            );
        }

        $prescription = Prescription::create(
            tenantId: $tenantId,
            encounterId: $encounterId,
            patientId: $patientId,
            professionalSystemUserId: $professionalSystemUserId,
            orientationText: $orientation,
            items: $items,
        );

        /** @var Prescription $saved */
        $saved = $this->prescriptions->save($prescription);

        return $saved;
    }

    public function findById(int $id): ?Prescription
    {
        /** @var Prescription|null $prescription */
        $prescription = $this->prescriptions->findById($id);

        return $prescription;
    }
}
