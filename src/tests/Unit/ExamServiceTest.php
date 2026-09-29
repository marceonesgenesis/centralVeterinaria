<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Application\ExamService;
use CentralVet\Authorization\Exception\AuthorizationDenied;
use CentralVet\Domain\Encounter;
use CentralVet\Domain\ExamRequest;
use CentralVet\Tenancy\TenantContext;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\FakeAuthorizationPolicy;
use CentralVet\Tests\Support\FakeEncounterRepository;
use CentralVet\Tests\Support\FakeExamRequestRepository;
use CentralVet\Tests\Support\FakeExamResultRepository;
use DateTimeImmutable;

/**
 * Unit tests for ExamService (T-04/T-11), against fake repositories — no
 * database involved, since `exam_request`/`exam_result` do not exist yet
 * (migration T-01 not applied). Mirrors the FakeAuthorizationPolicy pattern
 * already used by AppointmentServiceTest/EncounterServiceTest (Phase 1/2).
 *
 * Covers both unit-scope authorization checks:
 *   - requestExam() checks the unit of the ENCOUNTER named by caller input
 *     (data.encounter_id), before the request is built or persisted;
 *   - recordResult() takes no unit parameter at all: it checks the unit of
 *     the origin encounter of the ALREADY PERSISTED exam request it loads by
 *     id — mirrors EncounterService::finish()'s "read the real unit back off
 *     the aggregate" pattern.
 * Also covers ExamRequest's status state machine: markResultAvailable()
 * (requested -> result_available) only runs in memory AFTER the
 * authorization decision is approved and BEFORE anything is persisted, so a
 * denied recordResult() call leaves the request's status untouched.
 */
final class ExamServiceTest
{
    private const ACTION = 'test::action';

    public function testRequestExamThrowsAuthorizationDeniedWhenPolicyDeniesAndPersistsNothing(): void
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

        $examRequests = new FakeExamRequestRepository(1);
        $policy = new FakeAuthorizationPolicy(allowed: false);
        // Caller's active unit (1) deliberately differs from the
        // encounter's own unit (5): a check using the wrong source would
        // send a different resourceUnitId than the assertion below expects.
        $service = $this->makeService($examRequests, new FakeExamResultRepository(1), $encounters, $policy);

        Assert::throws(
            AuthorizationDenied::class,
            static fn () => $service->requestExam([
                'encounter_id' => $encounterId,
                'patient_id' => 1,
                'exam_catalog_item_id' => 1,
                'professional_system_user_id' => 10,
            ], self::ACTION),
        );

        Assert::count(1, $policy->requests);
        Assert::same(5, $policy->requests[0]->resourceUnitId());
        Assert::null($examRequests->findById(1));
    }

    /**
     * Proves recordResult()'s authorization check reads the unit back from
     * the ALREADY PERSISTED request's own origin encounter — never a
     * parameter, since recordResult() takes no unit argument at all — and
     * that a denial leaves both the status transition and the result
     * unwritten: the request stays "requested" and no ExamResult is saved.
     */
    public function testRecordResultThrowsAuthorizationDeniedWhenPolicyDeniesUsingRequestRealUnitAndPersistsNothing(): void
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

        $examRequests = new FakeExamRequestRepository(1);
        $examResults = new FakeExamResultRepository(1);

        // First, request the exam under an allowing policy so a real,
        // persisted request exists to call recordResult() against.
        $requestingService = $this->makeService(
            $examRequests,
            $examResults,
            $encounters,
            new FakeAuthorizationPolicy(allowed: true),
        );
        $request = $requestingService->requestExam([
            'encounter_id' => $encounterId,
            'patient_id' => 1,
            'exam_catalog_item_id' => 1,
            'professional_system_user_id' => 10,
        ], self::ACTION);

        // Now a DIFFERENT service instance, wired with a denying policy and
        // a caller whose active unit (1) deliberately differs from the
        // encounter's own unit (5), attempts to record a result.
        $denyingPolicy = new FakeAuthorizationPolicy(allowed: false);
        $recordingService = $this->makeService(
            $examRequests,
            $examResults,
            $encounters,
            $denyingPolicy,
            TenantContext::authenticated(1, 1, 1),
        );

        Assert::throws(
            AuthorizationDenied::class,
            static fn () => $recordingService->recordResult($request->id(), [
                'structured_result' => 'Hemograma normal',
            ], self::ACTION),
        );

        Assert::count(1, $denyingPolicy->requests);
        Assert::same(5, $denyingPolicy->requests[0]->resourceUnitId());

        $reloaded = $examRequests->findById($request->id());
        Assert::notNull($reloaded);
        Assert::same(ExamRequest::STATUS_REQUESTED, $reloaded->status());
        Assert::null($examResults->findByExamRequest($request->id()));
    }

    /**
     * Proves the requested -> result_available transition is not decorative:
     * it only happens once authorization is approved, and both the mutated
     * request and the new ExamResult land in their repositories.
     */
    public function testRecordResultTransitionsStatusOnlyWhenAuthorizationApproved(): void
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

        $examRequests = new FakeExamRequestRepository(1);
        $examResults = new FakeExamResultRepository(1);
        $service = $this->makeService(
            $examRequests,
            $examResults,
            $encounters,
            new FakeAuthorizationPolicy(allowed: true),
        );

        $request = $service->requestExam([
            'encounter_id' => $encounterId,
            'patient_id' => 1,
            'exam_catalog_item_id' => 1,
            'professional_system_user_id' => 10,
        ], self::ACTION);

        Assert::same(ExamRequest::STATUS_REQUESTED, $request->status());

        $result = $service->recordResult($request->id(), [
            'structured_result' => 'Hemograma normal',
        ], self::ACTION);

        Assert::notNull($result->id());
        Assert::same($request->id(), $result->examRequestId());

        $reloaded = $examRequests->findById($request->id());
        Assert::notNull($reloaded);
        Assert::same(ExamRequest::STATUS_RESULT_AVAILABLE, $reloaded->status());
    }

    /**
     * Proves listPending() delegates 100% to
     * ExamRequestRepositoryInterface::listPending(): a request still
     * 'requested' comes back, while a sibling request whose result was
     * already recorded (status 'result_available') does not.
     */
    public function testListPendingReturnsOnlyRequestsStillAwaitingAResult(): void
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

        $examRequests = new FakeExamRequestRepository(1);
        $examResults = new FakeExamResultRepository(1);
        $service = $this->makeService(
            $examRequests,
            $examResults,
            $encounters,
            new FakeAuthorizationPolicy(allowed: true),
        );

        $pendingRequest = $service->requestExam([
            'encounter_id' => $encounterId,
            'patient_id' => 1,
            'exam_catalog_item_id' => 1,
            'professional_system_user_id' => 10,
        ], self::ACTION);

        $resolvedRequest = $service->requestExam([
            'encounter_id' => $encounterId,
            'patient_id' => 1,
            'exam_catalog_item_id' => 2,
            'professional_system_user_id' => 10,
        ], self::ACTION);
        $service->recordResult($resolvedRequest->id(), [
            'structured_result' => 'Hemograma normal',
        ], self::ACTION);

        $pending = $service->listPending();

        Assert::count(1, $pending);
        Assert::same($pendingRequest->id(), $pending[0]->id());
        Assert::same(ExamRequest::STATUS_REQUESTED, $pending[0]->status());
    }

    private function makeService(
        FakeExamRequestRepository $examRequests,
        FakeExamResultRepository $examResults,
        FakeEncounterRepository $encounters,
        FakeAuthorizationPolicy $policy,
        ?TenantContext $context = null,
    ): ExamService {
        return new ExamService(
            $examRequests,
            $examResults,
            $encounters,
            $policy,
            $context ?? TenantContext::authenticated(1, 1, 1),
        );
    }
}
