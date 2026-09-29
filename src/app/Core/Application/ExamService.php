<?php

declare(strict_types=1);

namespace CentralVet\Application;

use CentralVet\Authorization\AuthorizationRequest;
use CentralVet\Authorization\Contract\AuthorizationPolicyInterface;
use CentralVet\Domain\Contract\EncounterRepositoryInterface;
use CentralVet\Domain\Contract\ExamRequestRepositoryInterface;
use CentralVet\Domain\Contract\ExamResultRepositoryInterface;
use CentralVet\Domain\Contract\TenantUserDirectoryInterface;
use CentralVet\Domain\Exception\CrossTenantReferenceException;
use CentralVet\Domain\ExamRequest;
use CentralVet\Domain\ExamResult;
use CentralVet\Tenancy\TenantContext;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Use cases for the ExamRequest/ExamResult aggregates (T-04): requesting a
 * catalog exam for a patient inside an encounter, and recording its result.
 *
 * Depends only on Domain contracts and `TenantContext` — no TPage or any
 * other Adianti class (ADR 0001), so it can run from REST, workers or MCP
 * exactly like from the current Adianti presentation layer.
 *
 * Unit-scope authorization (same pattern as `EncounterService::start()`/
 * `finish()` and `QueueEntryService::checkIn()`/`advanceStatus()`): neither
 * `exam_request` nor `exam_result` carries its own `system_unit_id` column
 * (the migration deliberately does not add one — see the class docblocks on
 * `CentralVet\Domain\ExamRequest`/`ExamResult`), so the unit checked is
 * always read back from the *origin encounter*
 * ({@see \CentralVet\Domain\Encounter::systemUnitId()}), via the injected
 * `EncounterRepositoryInterface` (an existing Phase 2 contract, not
 * redefined here):
 *   - requestExam() loads the encounter named by `data.encounter_id` and
 *     checks against ITS unit, before building/persisting the request —
 *     mirrors EncounterService::start(), which checks the unit given by
 *     caller input before the first write for a brand-new aggregate;
 *   - recordResult() loads the already-persisted exam request, then its
 *     origin encounter, and checks against THAT encounter's unit — mirrors
 *     EncounterService::finish()/QueueEntryService::advanceStatus(), which
 *     check the REAL unit of an existing aggregate rather than a
 *     caller-supplied one, closing the same "wrong active unit" gap.
 * In both cases, assertAllowed() throws AuthorizationDenied on denial, left
 * to propagate; nothing is persisted when that happens. This Core class
 * never hardcodes an Adianti class name (ADR 0001): the "ClassName::method"
 * action string is supplied by the caller (the Presentation-layer
 * controller) through each method's $action parameter.
 */
final class ExamService
{
    public function __construct(
        private readonly ExamRequestRepositoryInterface $examRequests,
        private readonly ExamResultRepositoryInterface $examResults,
        private readonly EncounterRepositoryInterface $encounters,
        private readonly AuthorizationPolicyInterface $authorization,
        private readonly TenantContext $context,
        private readonly TenantUserDirectoryInterface $tenantUsers,
    ) {
    }

    /**
     * @param array{
     *     encounter_id: int|string,
     *     patient_id: int|string,
     *     exam_catalog_item_id: int|string,
     *     professional_system_user_id: int|string,
     * } $data
     * @param string $action "ClassName::method" identifying the caller for
     *        the permission provider and the audit trail — this Core class
     *        does not know Adianti class names itself (ADR 0001).
     *
     * @throws InvalidArgumentException when no encounter with
     *         `data.encounter_id` exists for the authenticated tenant.
     * @throws \CentralVet\Authorization\Exception\AuthorizationDenied when
     *         the active unit is missing or does not match the origin
     *         encounter's own system_unit_id, or the caller lacks
     *         permission for $action. Nothing is persisted when this is
     *         thrown.
     */
    public function requestExam(array $data, string $action): ExamRequest
    {
        foreach (['encounter_id', 'patient_id', 'exam_catalog_item_id', 'professional_system_user_id'] as $required) {
            if (!array_key_exists($required, $data)) {
                throw new InvalidArgumentException("{$required} is required");
            }
        }

        $encounterId = (int) $data['encounter_id'];
        $patientId = (int) $data['patient_id'];
        $examCatalogItemId = (int) $data['exam_catalog_item_id'];
        $professionalSystemUserId = (int) $data['professional_system_user_id'];

        $encounter = $this->encounters->findById($encounterId);

        if ($encounter === null) {
            throw new InvalidArgumentException(
                "encounter {$encounterId} was not found for the authenticated tenant"
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

        // Unit-scope authorization against the origin encounter's REAL
        // unit, run after loading the encounter but before building or
        // persisting the request. assertAllowed() throws AuthorizationDenied
        // on denial, left to propagate; nothing is written when that
        // happens.
        $this->authorization->decide(new AuthorizationRequest(
            context: $this->context,
            action: $action,
            requiresUnitScope: true,
            resourceUnitId: $encounter->systemUnitId(),
            entityType: 'exam_request',
            entityId: null,
        ))->assertAllowed();

        $request = ExamRequest::request(
            tenantId: $this->context->tenantId(),
            encounterId: $encounterId,
            patientId: $patientId,
            examCatalogItemId: $examCatalogItemId,
            professionalSystemUserId: $professionalSystemUserId,
            now: new DateTimeImmutable(),
        );

        /** @var ExamRequest $saved */
        $saved = $this->examRequests->save($request);

        return $saved;
    }

    /**
     * @param array{
     *     structured_result?: string|null,
     *     stored_object_key?: string|null,
     *     pending_review?: bool,
     * } $data
     * @param string $action "ClassName::method" identifying the caller (see
     *        requestExam()'s $action docblock).
     *
     * @throws InvalidArgumentException when no exam request with $requestId
     *         exists for the authenticated tenant, or its origin encounter
     *         cannot be found.
     * @throws \CentralVet\Authorization\Exception\AuthorizationDenied when
     *         the active unit is missing or does not match the origin
     *         encounter's own system_unit_id, or the caller lacks
     *         permission for $action. Unlike requestExam(), the unit
     *         checked here is not taken from caller input — it is read back
     *         from the already persisted request's own encounter, the same
     *         unit-boundary pattern `EncounterService::finish()` and
     *         `QueueEntryService::advanceStatus()` already use. Nothing is
     *         persisted when this is thrown.
     * @throws \CentralVet\Domain\Exception\InvalidStatusTransitionException
     *         when the request already has a result (status is not
     *         `requested`).
     */
    public function recordResult(int $requestId, array $data, string $action): ExamResult
    {
        $request = $this->examRequests->findById($requestId);

        if (!$request instanceof ExamRequest) {
            throw new InvalidArgumentException(
                "exam_request {$requestId} was not found for the authenticated tenant"
            );
        }

        $encounter = $this->encounters->findById($request->encounterId());

        if ($encounter === null) {
            throw new InvalidArgumentException(
                "encounter {$request->encounterId()} was not found for the authenticated tenant"
            );
        }

        // Unit-scope authorization against the request's origin encounter's
        // REAL unit (not a caller-supplied parameter — recordResult() takes
        // no unit argument), run after loading both the request and the
        // encounter but before mutating or persisting anything.
        // assertAllowed() throws AuthorizationDenied on denial, left to
        // propagate.
        $this->authorization->decide(new AuthorizationRequest(
            context: $this->context,
            action: $action,
            requiresUnitScope: true,
            resourceUnitId: $encounter->systemUnitId(),
            entityType: 'exam_result',
            entityId: $requestId,
        ))->assertAllowed();

        // markResultAvailable() is an in-memory mutation only (no query),
        // and throws InvalidStatusTransitionException when the request
        // already has a result. Doing this BEFORE building/persisting the
        // ExamResult below means an invalid transition leaves nothing
        // written to either table — mirrors QueueEntry::advance() being
        // called before QueueEntryService::advanceStatus() persists.
        $request->markResultAvailable();

        $now = new DateTimeImmutable();

        $result = ExamResult::record(
            tenantId: $this->context->tenantId(),
            examRequestId: $requestId,
            structuredResultText: isset($data['structured_result']) ? (string) $data['structured_result'] : null,
            storedObjectKey: isset($data['stored_object_key']) && $data['stored_object_key'] !== ''
                ? (string) $data['stored_object_key']
                : null,
            pendingReview: !array_key_exists('pending_review', $data) || (bool) $data['pending_review'],
            now: $now,
        );

        /** @var ExamResult $savedResult */
        $savedResult = $this->examResults->save($result);

        $this->examRequests->save($request);

        return $savedResult;
    }

    public function findById(int $id): ?ExamRequest
    {
        $request = $this->examRequests->findById($id);

        return $request instanceof ExamRequest ? $request : null;
    }

    /**
     * Lists every ExamRequest still awaiting a result
     * (status()===ExamRequest::STATUS_REQUESTED) for the current tenant.
     * Delegates 100% to ExamRequestRepositoryInterface::listPending(),
     * already tenant-scoped and already filtered to 'requested' — same
     * plain-delegation pattern as findById() above and
     * PayableService::listOpen(): no unit-scope authorization is run here,
     * since this list is not tied to a single caller-known unit the way
     * requestExam()/recordResult() are.
     *
     * @return list<ExamRequest>
     */
    public function listPending(): array
    {
        /** @var list<ExamRequest> $requests */
        $requests = $this->examRequests->listPending();

        return $requests;
    }
}
