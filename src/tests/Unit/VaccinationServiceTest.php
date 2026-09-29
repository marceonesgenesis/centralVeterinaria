<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Application\VaccinationService;
use CentralVet\Authorization\Exception\AuthorizationDenied;
use CentralVet\Domain\Encounter;
use CentralVet\Domain\VaccineCatalogItem;
use CentralVet\Domain\VaccineProtocol;
use CentralVet\Tenancy\TenantContext;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\FakeAuthorizationPolicy;
use CentralVet\Tests\Support\FakeEncounterRepository;
use CentralVet\Tests\Support\FakeVaccinationRepository;
use CentralVet\Tests\Support\FakeVaccineCatalogRepository;
use CentralVet\Tests\Support\FakeVaccineProtocolRepository;
use DateTimeImmutable;
use CentralVet\Domain\Exception\CrossTenantReferenceException;
use CentralVet\Tests\Support\FakeTenantUserDirectory;

/**
 * Unit tests for VaccinationService (T-05/T-11), against fake repositories
 * — no database involved, since `vaccination`/`vaccine_catalog_item`/
 * `vaccine_protocol` do not exist yet (migration T-01 not applied). Mirrors
 * the FakeAuthorizationPolicy pattern already used by
 * AppointmentServiceTest/EncounterServiceTest (Phase 1/2).
 *
 * Covers apply()'s three ordered business rules (per its own class
 * docblock): (1) unit-scope authorization against the origin encounter's
 * REAL unit, checked before any write — a denial leaves stock untouched and
 * nothing persisted; (2) stock decrement on the vaccine catalog item; (3)
 * next_dose_at computed from the VaccineProtocol row (if any) matching
 * dose_number + 1, or null when no such row exists.
 */
final class VaccinationServiceTest
{
    private const ACTION = 'test::action';

    /**
     * Proves the unit-scope authorization check is a real gate: with a
     * policy configured to always deny, an otherwise valid application is
     * rejected with AuthorizationDenied, the catalog item's stock is left
     * completely untouched, and no Vaccination is persisted. Also proves the
     * unit checked is the encounter's REAL unit (5), not the caller's active
     * unit (1) — apply() takes no unit parameter in $data.
     */
    public function testApplyThrowsAuthorizationDeniedWhenPolicyDeniesAndPersistsNothingNorDecrementsStock(): void
    {
        $encounters = new FakeEncounterRepository(1);
        $encounter = Encounter::start(
            tenantId: 1,
            systemUnitId: 5,
            patientId: 1,
            appointmentId: null,
            professionalSystemUserId: 10,
            now: new DateTimeImmutable('-10 minutes'),
        );
        $encounters->save($encounter);
        $encounterId = $encounter->id();

        $catalog = new FakeVaccineCatalogRepository(1);
        $catalogItem = VaccineCatalogItem::create(1, 'V10', 'Zoetis', 10);
        $catalog->save($catalogItem);
        $catalogItemId = $catalogItem->id();

        $vaccinations = new FakeVaccinationRepository(1);
        $policy = new FakeAuthorizationPolicy(allowed: false);
        $service = new VaccinationService(
            $vaccinations,
            $catalog,
            new FakeVaccineProtocolRepository(1),
            $encounters,
            $policy,
            TenantContext::authenticated(1, 1, 1),
        );

        Assert::throws(
            AuthorizationDenied::class,
            static fn () => $service->apply([
                'encounter_id' => $encounterId,
                'patient_id' => 1,
                'vaccine_catalog_item_id' => $catalogItemId,
                'dose_number' => 1,
                'professional_system_user_id' => 10,
            ], self::ACTION),
        );

        Assert::count(1, $policy->requests);
        Assert::same(5, $policy->requests[0]->resourceUnitId());

        $reloadedItem = $catalog->findById($catalogItemId);
        Assert::notNull($reloadedItem);
        Assert::same(10, $reloadedItem->stockQuantity());

        Assert::count(0, $vaccinations->listByPatient(1));
    }

    /**
     * When authorization is approved: stock decrements by exactly one, and
     * next_dose_at is computed as applied_at + interval_days_from_previous
     * from the VaccineProtocol row configured for dose_number + 1 — proven
     * without pinning the real clock, by asserting the gap between
     * appliedAt() and nextDoseAt() is exactly the protocol's interval.
     */
    public function testApplyDecrementsStockAndComputesNextDoseFromProtocol(): void
    {
        $encounters = new FakeEncounterRepository(1);
        $encounter = Encounter::start(
            tenantId: 1,
            systemUnitId: 1,
            patientId: 1,
            appointmentId: null,
            professionalSystemUserId: 10,
            now: new DateTimeImmutable('-10 minutes'),
        );
        $encounters->save($encounter);
        $encounterId = $encounter->id();

        $catalog = new FakeVaccineCatalogRepository(1);
        $catalogItem = VaccineCatalogItem::create(1, 'V10', 'Zoetis', 10);
        $catalog->save($catalogItem);
        $catalogItemId = $catalogItem->id();

        $protocols = new FakeVaccineProtocolRepository(1);
        // Applied dose is 1; the schedule's next entry (dose_number 2) has a
        // 21-day interval from the previous dose.
        $protocols->save(VaccineProtocol::create(1, $catalogItemId, 2, 21));

        $vaccinations = new FakeVaccinationRepository(1);
        $service = new VaccinationService(
            $vaccinations,
            $catalog,
            $protocols,
            $encounters,
            new FakeAuthorizationPolicy(allowed: true),
            TenantContext::authenticated(1, 1, 1),
        );

        $vaccination = $service->apply([
            'encounter_id' => $encounterId,
            'patient_id' => 1,
            'vaccine_catalog_item_id' => $catalogItemId,
            'dose_number' => 1,
            'professional_system_user_id' => 10,
        ], self::ACTION);

        Assert::notNull($vaccination->id());

        $reloadedItem = $catalog->findById($catalogItemId);
        Assert::notNull($reloadedItem);
        Assert::same(9, $reloadedItem->stockQuantity());

        Assert::notNull($vaccination->nextDoseAt());
        $daysUntilNextDose = (int) $vaccination->appliedAt()
            ->diff($vaccination->nextDoseAt())
            ->format('%a');
        Assert::same(21, $daysUntilNextDose);
    }

    /**
     * When no VaccineProtocol row exists for dose_number + 1 (no schedule
     * configured for the next dose), next_dose_at must be null — not an
     * error, not a guessed date.
     */
    public function testApplyLeavesNextDoseAtNullWhenNoProtocolConfiguredForNextDoseNumber(): void
    {
        $encounters = new FakeEncounterRepository(1);
        $encounter = Encounter::start(
            tenantId: 1,
            systemUnitId: 1,
            patientId: 1,
            appointmentId: null,
            professionalSystemUserId: 10,
            now: new DateTimeImmutable('-10 minutes'),
        );
        $encounters->save($encounter);
        $encounterId = $encounter->id();

        $catalog = new FakeVaccineCatalogRepository(1);
        $catalogItem = VaccineCatalogItem::create(1, 'V10', 'Zoetis', 10);
        $catalog->save($catalogItem);
        $catalogItemId = $catalogItem->id();

        // No VaccineProtocol rows seeded at all: no schedule for dose 2.
        $protocols = new FakeVaccineProtocolRepository(1);

        $vaccinations = new FakeVaccinationRepository(1);
        $service = new VaccinationService(
            $vaccinations,
            $catalog,
            $protocols,
            $encounters,
            new FakeAuthorizationPolicy(allowed: true),
            TenantContext::authenticated(1, 1, 1),
        );

        $vaccination = $service->apply([
            'encounter_id' => $encounterId,
            'patient_id' => 1,
            'vaccine_catalog_item_id' => $catalogItemId,
            'dose_number' => 1,
            'professional_system_user_id' => 10,
        ], self::ACTION);

        Assert::null($vaccination->nextDoseAt());

        $reloadedItem = $catalog->findById($catalogItemId);
        Assert::notNull($reloadedItem);
        Assert::same(9, $reloadedItem->stockQuantity());
    }

    /**
     * final-fix: a professional_system_user_id that is not an active member
     * of the authenticated tenant (another tenant's user, or nonexistent) is
     * rejected with CrossTenantReferenceException and nothing is persisted.
     */
    public function testApplyRejectsProfessionalOutsideTenantAndPersistsNothingNorDecrementsStock(): void
    {
        $encounters = new FakeEncounterRepository(1);
        $encounter = Encounter::start(
            tenantId: 1,
            systemUnitId: 1,
            patientId: 1,
            appointmentId: null,
            professionalSystemUserId: 10,
            now: new DateTimeImmutable('-10 minutes'),
        );
        $encounters->save($encounter);
        $encounterId = $encounter->id();

        $catalog = new FakeVaccineCatalogRepository(1);
        $catalogItem = VaccineCatalogItem::create(1, 'V10', 'Zoetis', 10);
        $catalog->save($catalogItem);
        $catalogItemId = $catalogItem->id();

        $vaccinations = new FakeVaccinationRepository(1);
        $service = new VaccinationService(
            $vaccinations,
            $catalog,
            new FakeVaccineProtocolRepository(1),
            $encounters,
            new FakeAuthorizationPolicy(allowed: true),
            TenantContext::authenticated(1, 1, 1),
            new FakeTenantUserDirectory([10]),
        );

        Assert::throws(
            CrossTenantReferenceException::class,
            static fn () => $service->apply([
                'encounter_id' => $encounterId,
                'patient_id' => 1,
                'vaccine_catalog_item_id' => $catalogItemId,
                'dose_number' => 1,
                'professional_system_user_id' => 999,
            ], self::ACTION),
        );

        Assert::null($vaccinations->findById(1));
        Assert::same(10, $catalog->findById($catalogItemId)->stockQuantity());
    }
}
