<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Application\PrescriptionService;
use CentralVet\Authorization\Exception\AuthorizationDenied;
use CentralVet\Domain\Encounter;
use CentralVet\Domain\Prescription;
use CentralVet\Tenancy\TenantContext;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\FakeAuthorizationPolicy;
use CentralVet\Tests\Support\FakeEncounterRepository;
use CentralVet\Tests\Support\FakePrescriptionRepository;
use DateTimeImmutable;

/**
 * Unit tests for PrescriptionService (T-03/T-11), against fake repositories
 * — no database involved, since `prescription`/`prescription_item` do not
 * exist yet (migration T-01 not applied). Mirrors the FakeAuthorizationPolicy
 * pattern already used by AppointmentServiceTest/EncounterServiceTest
 * (Phase 1/2): create()'s unit-scope authorization check is proven with a
 * denying policy to be a real gate (nothing persisted on denial), not
 * decorative, and the check runs against the source ENCOUNTER's own
 * system_unit_id — never a caller-supplied unit — the same pattern
 * EncounterService::finish() and ExamService::recordResult() use.
 */
final class PrescriptionServiceTest
{
    private const ACTION = 'test::action';

    /**
     * Proves the unit-scope authorization check added to create() is a real
     * gate: with a policy configured to always deny, a candidate that would
     * otherwise be perfectly valid (existing own-tenant encounter, one
     * medication item) is rejected with AuthorizationDenied and nothing is
     * persisted — the fake repository still reports no prescription under
     * the id the first successful save would have produced. Also proves the
     * unit checked is the encounter's REAL unit (5), not the caller's active
     * unit (1, from the denying service's own TenantContext) — there is no
     * other source create() could have read that value from, since $data
     * carries no system_unit_id of its own.
     */
    public function testCreateThrowsAuthorizationDeniedWhenPolicyDeniesAndPersistsNothing(): void
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

        $prescriptions = new FakePrescriptionRepository(1);
        $policy = new FakeAuthorizationPolicy(allowed: false);
        // Caller's active unit (1) deliberately differs from the
        // encounter's own unit (5), so a check that mistakenly used the
        // caller's unit instead of the encounter's would send a different
        // resourceUnitId than the assertion below expects.
        $service = new PrescriptionService(
            $prescriptions,
            $encounters,
            $policy,
            TenantContext::authenticated(1, 1, 1),
        );

        Assert::throws(
            AuthorizationDenied::class,
            static fn () => $service->create([
                'encounter_id' => $encounterId,
                'patient_id' => 1,
                'professional_system_user_id' => 10,
                'items' => [[
                    'medication_name' => 'Amoxicilina',
                    'dose' => '250',
                    'dose_unit' => 'mg',
                    'route' => 'oral',
                    'frequency' => '12/12h',
                    'duration' => '7 dias',
                ]],
            ], self::ACTION),
        );

        Assert::count(1, $policy->requests);
        Assert::same(5, $policy->requests[0]->resourceUnitId());
        Assert::null($prescriptions->findById(1));
    }

    /**
     * Sanity check that a matching, allowed authorization decision does let
     * create() persist a Prescription with its items attached — proves the
     * deny scenario above is actually testing a gate and not a service that
     * always throws.
     */
    public function testCreatePersistsPrescriptionWithItemsWhenPolicyAllows(): void
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

        $prescriptions = new FakePrescriptionRepository(1);
        $service = new PrescriptionService(
            $prescriptions,
            $encounters,
            new FakeAuthorizationPolicy(allowed: true),
            TenantContext::authenticated(1, 1, 1),
        );

        $created = $service->create([
            'encounter_id' => $encounterId,
            'patient_id' => 1,
            'professional_system_user_id' => 10,
            'orientation' => 'Tomar apos as refeicoes',
            'items' => [[
                'medication_name' => 'Amoxicilina',
                'dose' => '250',
                'dose_unit' => 'mg',
                'route' => 'oral',
                'frequency' => '12/12h',
                'duration' => '7 dias',
            ]],
        ], self::ACTION);

        Assert::notNull($created->id());
        Assert::same(Prescription::STATUS_DRAFT, $created->status());
        Assert::count(1, $created->items());

        $found = $service->findById($created->id());
        Assert::notNull($found);
        Assert::same($created->id(), $found->id());
    }

    public function testFindByIdReturnsNullForUnknownId(): void
    {
        $service = new PrescriptionService(
            new FakePrescriptionRepository(1),
            new FakeEncounterRepository(1),
            new FakeAuthorizationPolicy(allowed: true),
            TenantContext::authenticated(1, 1, 1),
        );

        Assert::null($service->findById(999));
    }
}
