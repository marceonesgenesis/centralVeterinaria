<?php

declare(strict_types=1);

namespace CentralVet\Application;

use CentralVet\Authorization\AuthorizationRequest;
use CentralVet\Authorization\Contract\AuthorizationPolicyInterface;
use CentralVet\Domain\Contract\EncounterRepositoryInterface;
use CentralVet\Domain\Contract\VaccinationRepositoryInterface;
use CentralVet\Domain\Contract\VaccineCatalogRepositoryInterface;
use CentralVet\Domain\Contract\VaccineProtocolRepositoryInterface;
use CentralVet\Domain\Contract\TenantUserDirectoryInterface;
use CentralVet\Domain\Encounter;
use CentralVet\Domain\Exception\CrossTenantReferenceException;
use CentralVet\Domain\Vaccination;
use CentralVet\Domain\VaccineCatalogItem;
use CentralVet\Domain\VaccineProtocol;
use CentralVet\Tenancy\TenantContext;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Use cases for the Vaccination aggregate (T-05): applying a vaccine dose
 * (with stock decrement and next-dose calculation) and reading a patient's
 * vaccination history (the vaccination card).
 *
 * Depends only on Domain contracts and TenantContext — no TPage or any
 * other Adianti class (ADR 0001). The "ClassName::method" action string in
 * apply()'s $action parameter is supplied by the caller (the
 * Presentation-layer controller); this Core class never hardcodes an
 * Adianti class name.
 *
 * Dependency note: this class also consumes
 * CentralVet\Domain\Contract\EncounterRepositoryInterface, an existing
 * Phase 2 (T-02) contract, not redefined here, exactly the way
 * AppointmentService consumes ServiceRepositoryInterface directly for a
 * cross-aggregate lookup it needs (Encounter's own system_unit_id) but that
 * no T-05 contract exposes on its own.
 *
 * Unit-scope authorization (same pattern as EncounterService::start()/
 * finish() and AppointmentService::schedule()): apply() asks the injected
 * AuthorizationPolicyInterface whether the caller's active unit
 * (TenantContext::unitId()) is allowed to act on the *origin encounter's*
 * own system_unit_id — not a unit supplied directly in $data, since a
 * vaccination only exists in the context of an already-open encounter.
 */
final class VaccinationService
{
    public function __construct(
        private readonly VaccinationRepositoryInterface $vaccinations,
        private readonly VaccineCatalogRepositoryInterface $catalog,
        private readonly VaccineProtocolRepositoryInterface $protocols,
        private readonly EncounterRepositoryInterface $encounters,
        private readonly AuthorizationPolicyInterface $authorization,
        private readonly TenantContext $context,
        private readonly TenantUserDirectoryInterface $tenantUsers,
    ) {
    }

    /**
     * Applies one dose of a vaccine catalog item to a patient, inside an
     * encounter. Business rules, in the order they run (each one documented
     * at its call site below):
     *   1. Unit-scope authorization against the origin encounter's own
     *      system_unit_id, checked BEFORE any write. A denial throws
     *      AuthorizationDenied with nothing persisted and stock untouched.
     *   2. The referenced VaccineCatalogItem has its stock_quantity
     *      decremented by one and is re-saved (a plain counter, not a full
     *      inventory module — per this phase's plan).
     *   3. next_dose_at is computed by looking up, among the catalog item's
     *      configured VaccineProtocol rows, the one whose dose_number equals
     *      $data['dose_number'] + 1: if found and it has a non-null
     *      interval_days_from_previous, next_dose_at = applied_at + that many
     *      days; otherwise next_dose_at is null (no protocol configured for
     *      the next dose, or the next dose has no fixed interval).
     *
     * @param array{
     *     encounter_id: int|string,
     *     patient_id: int|string,
     *     vaccine_catalog_item_id: int|string,
     *     lot?: string|null,
     *     expiry_date?: string|null,
     *     dose_number: int|string,
     *     professional_system_user_id: int|string,
     * } $data
     * @param string $action "ClassName::method" identifying the caller for
     *        the permission provider and the audit trail — this Core class
     *        does not know Adianti class names itself (ADR 0001).
     *
     * @throws InvalidArgumentException when a required key is missing, or
     *         dose_number is not a positive integer.
     * @throws CrossTenantReferenceException when encounter_id or
     *         vaccine_catalog_item_id does not resolve within the
     *         authenticated tenant.
     * @throws \CentralVet\Authorization\Exception\AuthorizationDenied when
     *         the active unit is missing or does not match the origin
     *         encounter's own system_unit_id, or the caller lacks
     *         permission for $action. Nothing is persisted and stock is not
     *         decremented when this is thrown.
     */
    public function apply(array $data, string $action): Vaccination
    {
        foreach (['encounter_id', 'patient_id', 'vaccine_catalog_item_id', 'dose_number', 'professional_system_user_id'] as $required) {
            if (!array_key_exists($required, $data)) {
                throw new InvalidArgumentException("{$required} is required");
            }
        }

        $encounterId = (int) $data['encounter_id'];
        $patientId = (int) $data['patient_id'];
        $vaccineCatalogItemId = (int) $data['vaccine_catalog_item_id'];
        $doseNumber = (int) $data['dose_number'];
        $professionalSystemUserId = (int) $data['professional_system_user_id'];
        $lot = isset($data['lot']) && $data['lot'] !== '' ? (string) $data['lot'] : null;
        $expiryDate = isset($data['expiry_date']) && $data['expiry_date'] !== ''
            ? new DateTimeImmutable((string) $data['expiry_date'])
            : null;

        if ($doseNumber < 1) {
            throw new InvalidArgumentException('dose_number must be >= 1');
        }

        // Tenant-scoped lookups (ADR 0002): findById() returns null both
        // when the referenced row does not exist and when it belongs to
        // another tenant (mirrors AppointmentService::schedule()'s
        // treatment of patient_id/service_id). Read-only, so safe to run
        // before the authorization check below.
        $encounter = $this->encounters->findById($encounterId);

        if (!$encounter instanceof Encounter) {
            throw new CrossTenantReferenceException(
                "encounter_id {$encounterId} was not found for the authenticated tenant"
            );
        }

        $catalogItem = $this->catalog->findById($vaccineCatalogItemId);

        if (!$catalogItem instanceof VaccineCatalogItem) {
            throw new CrossTenantReferenceException(
                "vaccine_catalog_item_id {$vaccineCatalogItemId} was not found for the authenticated tenant"
            );
        }

        // final-fix: the professional comes from caller input, so it must
        // resolve within the authenticated tenant like any other reference
        // (nonexistent and other-tenant users are indistinguishable).
        if (!$this->tenantUsers->isActiveMember($professionalSystemUserId)) {
            throw new CrossTenantReferenceException(
                "professional_system_user_id {$professionalSystemUserId} was not found for the authenticated tenant"
            );
        }

        // Rule 1: unit-scope authorization against the origin encounter's
        // REAL unit, run after the cross-tenant reference checks above (a
        // reference from another tenant is rejected on its own terms first)
        // and before any write. assertAllowed() throws AuthorizationDenied
        // on denial, left to propagate; nothing has been mutated or
        // persisted at this point, so the catalog item's stock is still
        // untouched.
        $this->authorization->decide(new AuthorizationRequest(
            context: $this->context,
            action: $action,
            requiresUnitScope: true,
            resourceUnitId: $encounter->systemUnitId(),
            entityType: 'vaccination',
            entityId: null,
        ))->assertAllowed();

        $appliedAt = new DateTimeImmutable();

        // Rule 2: stock decrement. decrementStock() itself refuses to drive
        // the counter below zero (see VaccineCatalogItem::decrementStock()).
        $catalogItem->decrementStock(1);
        $this->catalog->save($catalogItem);

        // Rule 3: next_dose_at, from the protocol row (if any) configured
        // for dose_number + 1 of this same catalog item.
        $nextDoseAt = $this->nextDoseAt($vaccineCatalogItemId, $doseNumber, $appliedAt);

        $vaccination = Vaccination::record(
            tenantId: $this->context->tenantId(),
            encounterId: $encounterId,
            patientId: $patientId,
            vaccineCatalogItemId: $vaccineCatalogItemId,
            lot: $lot,
            expiryDate: $expiryDate,
            doseNumber: $doseNumber,
            professionalSystemUserId: $professionalSystemUserId,
            appliedAt: $appliedAt,
            nextDoseAt: $nextDoseAt,
        );

        /** @var Vaccination $saved */
        $saved = $this->vaccinations->save($vaccination);

        return $saved;
    }

    /**
     * The vaccination card: full history for a patient, oldest first
     * (passthrough to VaccinationRepositoryInterface::listByPatient(),
     * already tenant-scoped via AbstractTenantRepository::tenantQuery(),
     * ADR 0002).
     *
     * @return list<Vaccination>
     */
    public function historyByPatient(int $patientId): array
    {
        /** @var list<Vaccination> $history */
        $history = $this->vaccinations->listByPatient($patientId);

        return $history;
    }

    /**
     * Looks up, among $vaccineCatalogItemId's configured VaccineProtocol
     * rows, the one whose dose_number is $appliedDoseNumber + 1. Returns
     * null when no such row exists, or it exists but has no
     * interval_days_from_previous configured — both cases mean "no fixed
     * schedule for the next dose", not an error.
     */
    private function nextDoseAt(int $vaccineCatalogItemId, int $appliedDoseNumber, DateTimeImmutable $appliedAt): ?DateTimeImmutable
    {
        $nextDoseNumber = $appliedDoseNumber + 1;

        /** @var list<VaccineProtocol> $schedule */
        $schedule = $this->protocols->listByVaccineCatalogItem($vaccineCatalogItemId);

        foreach ($schedule as $entry) {
            if ($entry->doseNumber() !== $nextDoseNumber) {
                continue;
            }

            $interval = $entry->intervalDaysFromPrevious();

            if ($interval === null) {
                return null;
            }

            return $appliedAt->modify("+{$interval} days");
        }

        return null;
    }
}
