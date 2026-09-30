<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Application\EncounterService;
use CentralVet\Assistant\NullAiClinicalAssistant;
use CentralVet\Authorization\Exception\AuthorizationDenied;
use CentralVet\Domain\Exception\InvalidStatusTransitionException;
use CentralVet\Domain\Encounter;
use CentralVet\Tenancy\TenantContext;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\FakeAuthorizationPolicy;
use CentralVet\Tests\Support\FakeEncounterRepository;
use CentralVet\Tests\Support\NullPdo;
use DateTimeImmutable;

/**
 * Unit tests for EncounterService (T-08), against FakeEncounterRepository —
 * no database involved, since the `encounter` table does not exist yet
 * (migration T-01 not applied) — and the same FakeAuthorizationPolicy double
 * Phase 1's AppointmentServiceTest/QueueEntryServiceTest already use.
 *
 * NullAiClinicalAssistant (T-04's own acceptance criterion — "the two
 * methods never throw with any id") is also covered here rather than in a
 * third file: T-08 names only EncounterServiceTest.php and
 * EncounterDocumentServiceTest.php, and the assistant is part of the same
 * clinical-encounter workflow.
 *
 * timeline() is intentionally NOT covered, documented instead of faked or
 * skipped silently: it is the one EncounterService method that bypasses
 * EncounterRepositoryInterface entirely and reads `audit_log` straight
 * through the constructor's raw PDO connection (see EncounterService's own
 * class docblock for why no Repository indirection exists for it). PDO is a
 * concrete class, not an interface/port, so there is no seam to substitute a
 * test double through without either (a) opening a real database connection
 * — turning this into a database-dependent Integration test, inconsistent
 * with every other scenario in this file, and requiring audit_log rows this
 * suite does not own or seed for a synthetic encounter id — or (b)
 * subclassing PDO to fabricate PDOStatement results, which would mostly test
 * the fake's own plumbing rather than timeline() itself. NullPdo below
 * covers only the narrow, legitimate need shared by every test in this file:
 * satisfying the constructor's PDO type without ever calling a method on it.
 */
final class EncounterServiceTest
{
    private const ACTION = 'test::action';

    public function testStartThrowsAuthorizationDeniedWhenPolicyDeniesAndPersistsNothing(): void
    {
        $repository = new FakeEncounterRepository(1);
        $policy = new FakeAuthorizationPolicy(allowed: false);
        $service = $this->makeService($repository, $policy);

        Assert::throws(
            AuthorizationDenied::class,
            static fn () => $service->start([
                'patient_id' => 1,
                'professional_system_user_id' => 10,
                'system_unit_id' => 1,
            ], self::ACTION),
        );

        Assert::null($repository->findById(1));
    }

    /**
     * finish() takes no unit parameter at all — the only unit it can check
     * is the one already persisted on the loaded Encounter. Proven two ways:
     * (1) the denial still fires and nothing is mutated, and (2) the
     * AuthorizationRequest the fake policy recorded carries the encounter's
     * OWN system_unit_id (5), never the caller's active unit (1, from the
     * denying service's own TenantContext) — there is no other source
     * finish() could have read that value from.
     */
    public function testFinishThrowsAuthorizationDeniedWhenPolicyDeniesUsingEncounterRealUnit(): void
    {
        $repository = new FakeEncounterRepository(1);
        $encounter = Encounter::start(
            tenantId: 1,
            systemUnitId: 5,
            patientId: 1,
            appointmentId: null,
            professionalSystemUserId: 10,
            now: new DateTimeImmutable('-10 minutes'),
        );
        $repository->save($encounter);
        $encounterId = $encounter->id();

        $policy = new FakeAuthorizationPolicy(allowed: false);
        // Caller's active unit (1) deliberately differs from the
        // encounter's own unit (5), so a check that mistakenly used the
        // caller's unit instead of the encounter's would send a different
        // resourceUnitId than the assertion below expects.
        $service = $this->makeService($repository, $policy, TenantContext::authenticated(1, 1, 1));

        Assert::throws(
            AuthorizationDenied::class,
            static fn () => $service->finish($encounterId, self::ACTION),
        );

        Assert::count(1, $policy->requests);
        Assert::same(5, $policy->requests[0]->resourceUnitId());

        $reloaded = $repository->findById($encounterId);
        Assert::same(Encounter::STATUS_IN_PROGRESS, $reloaded->status());
        Assert::null($reloaded->finishedAt());
    }

    public function testAutosaveUpdatesOnlyInformedFieldsPreservingOthers(): void
    {
        $repository = new FakeEncounterRepository(1);
        $policy = new FakeAuthorizationPolicy(allowed: true);
        $service = $this->makeService($repository, $policy);

        $started = $service->start([
            'patient_id' => 1,
            'professional_system_user_id' => 10,
            'system_unit_id' => 1,
        ], self::ACTION);

        $service->autosave($started->id(), [
            'diagnosis_text' => 'Otite externa',
            'weight_kg' => 12.5,
        ]);

        $updated = $service->autosave($started->id(), [
            'anamnesis_text' => 'Cocando a orelha ha 3 dias',
        ]);

        Assert::same('Cocando a orelha ha 3 dias', $updated->anamnesisText());
        Assert::same('Otite externa', $updated->diagnosisText());
        Assert::same(12.5, $updated->weightKg());
        Assert::null($updated->physicalExamText());
        Assert::null($updated->clinicalPlanText());
        Assert::null($updated->mucousMembranes());
    }

    public function testNullAiClinicalAssistantSummarizePatientHistoryNeverThrowsAndReturnsNull(): void
    {
        $assistant = new NullAiClinicalAssistant();

        Assert::null($assistant->summarizePatientHistory(999999));
    }

    public function testNullAiClinicalAssistantSuggestNextStepsNeverThrowsAndReturnsEmptyArray(): void
    {
        $assistant = new NullAiClinicalAssistant();

        Assert::count(0, $assistant->suggestNextSteps(999999));
    }

    public function testPauseMarksEncounterAsPaused(): void
    {
        [$service, $id] = $this->startedEncounter();
        $now = new DateTimeImmutable('2026-09-30 10:00:00');

        $paused = $service->pause($id, self::ACTION, $now);

        Assert::true($paused->isPaused());
        Assert::same($now->format('Y-m-d H:i:s'), $paused->pausedAt()?->format('Y-m-d H:i:s'));
        Assert::same(Encounter::STATUS_IN_PROGRESS, $paused->status());
        Assert::same(0, $paused->pausedSeconds());
    }

    public function testResumeAddsPausedIntervalInSecondsAndClearsPause(): void
    {
        [$service, $id] = $this->startedEncounter();
        $pausedAt = new DateTimeImmutable('2026-09-30 10:00:00');

        $service->pause($id, self::ACTION, $pausedAt);
        $resumed = $service->resume($id, self::ACTION, $pausedAt->modify('+90 seconds'));

        Assert::same(90, $resumed->pausedSeconds());
        Assert::false($resumed->isPaused());
        Assert::null($resumed->pausedAt());
    }

    public function testPauseTwiceThrowsInvalidStatusTransition(): void
    {
        [$service, $id] = $this->startedEncounter();
        $now = new DateTimeImmutable('2026-09-30 10:00:00');

        $service->pause($id, self::ACTION, $now);

        Assert::throws(
            InvalidStatusTransitionException::class,
            static fn () => $service->pause($id, self::ACTION, $now->modify('+1 minute')),
        );
    }

    public function testResumeWithoutPauseThrowsInvalidStatusTransition(): void
    {
        [$service, $id] = $this->startedEncounter();

        Assert::throws(
            InvalidStatusTransitionException::class,
            static fn () => $service->resume($id, self::ACTION, new DateTimeImmutable()),
        );
    }

    public function testFinishPausedEncounterAccumulatesPauseAndClearsPausedAt(): void
    {
        [$service, $id] = $this->startedEncounter();

        $service->pause($id, self::ACTION, new DateTimeImmutable('-5 minutes'));
        $finished = $service->finish($id, self::ACTION);

        Assert::same(Encounter::STATUS_FINISHED, $finished->status());
        Assert::notNull($finished->finishedAt());
        Assert::null($finished->pausedAt());
        Assert::false($finished->isPaused());
        Assert::true($finished->pausedSeconds() > 0, 'pausedSeconds must be > 0 after finishing a paused encounter');
    }

    public function testPauseFinishedEncounterThrowsInvalidStatusTransition(): void
    {
        [$service, $id] = $this->startedEncounter();

        $service->finish($id, self::ACTION);

        Assert::throws(
            InvalidStatusTransitionException::class,
            static fn () => $service->pause($id, self::ACTION, new DateTimeImmutable()),
        );
    }

    public function testPauseUsesEncounterRealUnitAndDeniedPausePersistsNothing(): void
    {
        $repository = new FakeEncounterRepository(1);
        $encounter = Encounter::start(
            tenantId: 1,
            systemUnitId: 5,
            patientId: 1,
            appointmentId: null,
            professionalSystemUserId: 10,
            now: new DateTimeImmutable('-10 minutes'),
        );
        $repository->save($encounter);
        $policy = new FakeAuthorizationPolicy(allowed: false);
        $service = $this->makeService($repository, $policy, TenantContext::authenticated(1, 1, 1));

        Assert::throws(
            AuthorizationDenied::class,
            static fn () => $service->pause($encounter->id(), self::ACTION, new DateTimeImmutable()),
        );

        Assert::count(1, $policy->requests);
        Assert::same(5, $policy->requests[0]->resourceUnitId());
        Assert::false($repository->findById($encounter->id())->isPaused());
    }

    public function testPauseUnknownEncounterThrowsSameExceptionAsFinish(): void
    {
        $service = $this->makeService(new FakeEncounterRepository(1), new FakeAuthorizationPolicy(allowed: true));

        Assert::throws(
            \InvalidArgumentException::class,
            static fn () => $service->pause(999, self::ACTION, new DateTimeImmutable()),
        );
        Assert::throws(
            \InvalidArgumentException::class,
            static fn () => $service->resume(999, self::ACTION, new DateTimeImmutable()),
        );
    }

    public function testReconstituteReadsPauseColumnsWithDefaults(): void
    {
        $row = [
            'id' => 7, 'tenant_id' => 1, 'system_unit_id' => 1, 'patient_id' => 1,
            'appointment_id' => null, 'professional_system_user_id' => 10,
            'status' => Encounter::STATUS_IN_PROGRESS, 'started_at' => '2026-09-30 09:00:00',
            'finished_at' => null, 'anamnesis_text' => null, 'temperature_c' => null,
            'heart_rate_bpm' => null, 'respiratory_rate_mpm' => null, 'weight_kg' => null,
            'mucous_membranes' => null, 'capillary_refill_seconds' => null,
            'physical_exam_text' => null, 'diagnosis_text' => null, 'clinical_plan_text' => null,
            'ai_summary_text' => null, 'ai_summary_accepted_at' => null,
        ];

        $legacy = Encounter::reconstitute($row);
        Assert::false($legacy->isPaused());
        Assert::same(0, $legacy->pausedSeconds());

        $paused = Encounter::reconstitute([
            ...$row,
            'paused_at' => '2026-09-30 09:30:00.000000',
            'paused_seconds' => '45',
        ]);
        Assert::true($paused->isPaused());
        Assert::same('2026-09-30 09:30:00', $paused->pausedAt()?->format('Y-m-d H:i:s'));
        Assert::same(45, $paused->pausedSeconds());
    }

    /** @return array{0: EncounterService, 1: int} */
    private function startedEncounter(): array
    {
        $repository = new FakeEncounterRepository(1);
        $service = $this->makeService($repository, new FakeAuthorizationPolicy(allowed: true));

        $started = $service->start([
            'patient_id' => 1,
            'professional_system_user_id' => 10,
            'system_unit_id' => 1,
        ], self::ACTION);

        return [$service, (int) $started->id()];
    }

    private function makeService(
        FakeEncounterRepository $repository,
        FakeAuthorizationPolicy $policy,
        ?TenantContext $context = null,
    ): EncounterService {
        return new EncounterService(
            $repository,
            $policy,
            $context ?? TenantContext::authenticated(1, 1, 1),
            new NullPdo(),
        );
    }
}
