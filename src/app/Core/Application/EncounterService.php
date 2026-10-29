<?php

declare(strict_types=1);

namespace CentralVet\Application;

use CentralVet\Authorization\AuthorizationRequest;
use CentralVet\Authorization\Contract\AuthorizationPolicyInterface;
use CentralVet\Domain\Contract\EncounterRepositoryInterface;
use CentralVet\Domain\Encounter;
use CentralVet\Tenancy\TenantContext;
use DateTimeImmutable;
use InvalidArgumentException;
use JsonException;
use PDO;

/**
 * Use cases for the Encounter aggregate (T-03): opening a clinical encounter,
 * autosaving the in-progress draft (anamnesis/vitals/exam/diagnosis/plan),
 * closing it, recording an accepted AI summary and reading its audit
 * timeline.
 *
 * Depends only on Domain contracts, `TenantContext` and (see the PDO note
 * below) a raw PDO connection — no TPage or any other Adianti class
 * (ADR 0001), so it can run from REST, workers or MCP exactly like from the
 * current Adianti presentation layer.
 *
 * Unit-scope authorization (same pattern as `AppointmentService::schedule()`
 * and `QueueEntryService::checkIn()`/`advanceStatus()`, required from day
 * one per this phase's plan instead of being deferred like it was in Phase
 * 1): both start() and finish() ask the injected AuthorizationPolicyInterface
 * whether the caller's active unit (TenantContext::unitId(), via
 * TenantContext::requireUnitId()) matches the encounter's own
 * system_unit_id, via a unit-scoped AuthorizationRequest. This Core class
 * never hardcodes an Adianti class name (ADR 0001): the "ClassName::method"
 * action string is supplied by the caller (the Presentation-layer
 * controller) through each method's $action parameter.
 *
 * PDO dependency note: the constructor's first three parameters
 * (EncounterRepositoryInterface, AuthorizationPolicyInterface, TenantContext)
 * mirror AppointmentService/QueueEntryService exactly. A fourth parameter,
 * a raw PDO connection, is added solely for timeline(): that method reads
 * `audit_log` (ADR 0003), and this plan defines no formal Repository for
 * `audit_log` (PdoAuditLogWriter only ever writes to it). Rather than invent
 * a one-off AuditLogRepository for a single read method, or smuggle an
 * audit_log query through EncounterRepositoryInterface (which would mean
 * redefining that T-02 contract — not allowed), timeline() queries
 * `audit_log` directly through the same PDO connection this service is
 * constructed with, exactly like EncounterRepository does for `encounter`.
 * It still never accepts tenant scoping from caller input: the tenant
 * predicate always comes from TenantContext.
 */
final class EncounterService
{
    public function __construct(
        private readonly EncounterRepositoryInterface $encounters,
        private readonly AuthorizationPolicyInterface $authorization,
        private readonly TenantContext $context,
        private readonly PDO $connection,
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
     * @throws \CentralVet\Authorization\Exception\AuthorizationDenied when
     *         the active unit is missing or does not match system_unit_id,
     *         or the caller lacks permission for $action. Nothing is
     *         persisted when this is thrown.
     */
    public function start(array $data, string $action): Encounter
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

        // Unit-scope authorization, run before building/persisting the
        // encounter. assertAllowed() throws AuthorizationDenied on denial,
        // left to propagate — nothing has been written yet at this point.
        $this->authorization->decide(new AuthorizationRequest(
            context: $this->context,
            action: $action,
            requiresUnitScope: true,
            resourceUnitId: $systemUnitId,
            entityType: 'encounter',
            entityId: null,
        ))->assertAllowed();

        $encounter = Encounter::start(
            tenantId: $this->context->tenantId(),
            systemUnitId: $systemUnitId,
            patientId: $patientId,
            appointmentId: $appointmentId,
            professionalSystemUserId: $professionalSystemUserId,
            now: new DateTimeImmutable(),
        );

        /** @var Encounter $saved */
        $saved = $this->encounters->save($encounter);

        return $saved;
    }

    public function findById(int $id): ?Encounter
    {
        /** @var Encounter|null $encounter */
        $encounter = $this->encounters->findById($id);

        return $encounter;
    }

    /**
     * Draft save guarded by the draft version (Fase 8A, T-24): the periodic
     * autosave and the AI "Apply to anamnesis" (anamnesis_text only) of
     * EncounterView. There is no unversioned draft save.
     *
     * Reads the encounter once, with the row lock
     * (EncounterRepositoryInterface::lockForDraft(), `SELECT ... FOR
     * UPDATE`, Fase 8A T-28), so the read is the latest committed row and no
     * other write reaches it before this transaction ends. The caller must
     * have a transaction open. The tab's base is accepted when
     * $expectedVersion is the stored {@see Encounter::draftVersion()}, or
     * when $previousDraft (what the tab's previous tick sent, while its
     * response has not arrived) applied to a copy of the stored encounter
     * keeps the stored version — that tick is already stored. A draft that
     * does not change the version answers `saved` without writing.
     * Otherwise only the draft columns are written
     * (EncounterRepositoryInterface::updateDraft()). The version covers only
     * the draft fields, so pause, resume, finish and the accepted summary
     * never turn a tick into a conflict.
     *
     * On a conflict (including $expectedVersion === null, a client without
     * version) no field is written and the result carries the version of
     * the locked read.
     *
     * A numeric field of $draft (or $previousDraft) that is not a number is
     * left out before anything else: it keeps the stored value, the other
     * fields are saved as usual and the result lists it in
     * `invalidFields` (T-28, correção 1).
     *
     * A finished encounter (checked on the locked read, before any
     * comparison) answers `closed` and writes nothing (T-32).
     *
     * @param array<string, mixed> $draft subset of anamnesis_text,
     *        temperature_c, heart_rate_bpm, respiratory_rate_mpm, weight_kg,
     *        mucous_membranes, capillary_refill_seconds, physical_exam_text,
     *        diagnosis_text, clinical_plan_text (only these keys are written).
     * @param array<string, mixed>|null $previousDraft same keys as $draft.
     *
     * @throws InvalidArgumentException when no encounter with this id exists
     *         for the authenticated tenant.
     */
    public function autosave(int $id, array $draft, ?string $expectedVersion, ?array $previousDraft = null): DraftSaveResult
    {
        // A numeric field that is not a number is left out (it keeps the
        // stored value) instead of failing the whole tick (T-28, correção 1).
        $invalid = Encounter::invalidDraftNumbers($draft);
        $draft = array_diff_key($draft, array_flip($invalid));

        $encounter = $this->encounters->lockForDraft($id);

        if ($encounter === null) {
            throw new InvalidArgumentException(
                "encounter {$id} was not found for the authenticated tenant"
            );
        }

        $current = $encounter->draftVersion();

        // Checked under the row lock, before any comparison (T-32): a
        // finished encounter takes no draft.
        if ($encounter->isFinished()) {
            return new DraftSaveResult(false, $current, [], closed: true);
        }

        if ($expectedVersion === null || !$this->isCurrentBase($encounter, $current, $expectedVersion, $draft, $previousDraft)) {
            return new DraftSaveResult(false, $current, $invalid);
        }

        $candidate = clone $encounter;
        $candidate->applyDraft($draft);
        $next = $candidate->draftVersion();

        if ($next === $current) {
            return new DraftSaveResult(true, $current, $invalid);
        }

        $this->encounters->updateDraft($candidate);

        return new DraftSaveResult(true, $next, $invalid);
    }

    /**
     * @param array<string, mixed> $draft
     * @param array<string, mixed>|null $previousDraft
     */
    private function isCurrentBase(Encounter $encounter, string $current, string $expectedVersion, array $draft, ?array $previousDraft): bool
    {
        if (hash_equals($current, $expectedVersion)) {
            return true;
        }

        // The previous tick only vouches for the fields it sent (all of
        // them, invalid numbers included, T-32): a draft with fields the
        // previous tick did not carry needs the real base. Only its valid
        // values are applied, as its own autosave() stored only those.
        if ($previousDraft === null || $previousDraft === [] || array_diff_key($draft, $previousDraft) !== []) {
            return false;
        }

        $previous = clone $encounter;
        $previous->applyDraft(array_diff_key($previousDraft, array_flip(Encounter::invalidDraftNumbers($previousDraft))));

        return hash_equals($current, $previous->draftVersion());
    }

    /**
     * @param string $action "ClassName::method" identifying the caller (see
     *        start()'s $action docblock).
     *
     * @throws InvalidArgumentException when no encounter with this id exists
     *         for the authenticated tenant.
     * @throws \CentralVet\Authorization\Exception\AuthorizationDenied when
     *         the active unit is missing or does not match the encounter's
     *         own system_unit_id, or the caller lacks permission for
     *         $action. Unlike start(), the unit checked here is not taken
     *         from caller input — it is read back from the already
     *         persisted encounter ({@see Encounter::systemUnitId()}), the
     *         same unit-boundary pattern
     *         `QueueEntryService::advanceStatus()` already uses: it stops a
     *         user whose active unit is, say, "Unidade Centro" from
     *         finishing an encounter that actually belongs to a different
     *         unit of the same tenant.
     * @throws \CentralVet\Domain\Exception\InvalidStatusTransitionException
     *         when the encounter is already finished.
     */
    public function finish(int $id, string $action): Encounter
    {
        // Unit-scope authorization against the encounter's REAL unit (not a
        // caller-supplied one — finish() takes no unit parameter), run
        // after loading the encounter but before mutating it.
        // assertAllowed() throws AuthorizationDenied on denial, left to
        // propagate; nothing is mutated or persisted when that happens.
        $encounter = $this->requireAuthorizedEncounter($id, $action);

        $encounter->finish(new DateTimeImmutable());

        /** @var Encounter $saved */
        $saved = $this->encounters->save($encounter);

        return $saved;
    }

    /**
     * Pauses an in-progress encounter (status stays `in_progress`,
     * `paused_at` is stamped). Same authorization as finish(): unit scope
     * against the encounter's own persisted system_unit_id.
     *
     * @param ?DateTimeImmutable $now null = current time.
     *
     * @throws InvalidArgumentException when no encounter with this id exists
     *         for the authenticated tenant.
     * @throws \CentralVet\Authorization\Exception\AuthorizationDenied
     * @throws \CentralVet\Domain\Exception\InvalidStatusTransitionException
     *         when finished or already paused.
     */
    public function pause(int $id, string $action, ?DateTimeImmutable $now = null): Encounter
    {
        $encounter = $this->requireAuthorizedEncounter($id, $action);

        $encounter->pause($now ?? new DateTimeImmutable());

        /** @var Encounter $saved */
        $saved = $this->encounters->save($encounter);

        return $saved;
    }

    /**
     * Resumes a paused encounter, adding the paused stretch to
     * `paused_seconds`. Same authorization as finish().
     *
     * @param ?DateTimeImmutable $now null = current time.
     *
     * @throws InvalidArgumentException when no encounter with this id exists
     *         for the authenticated tenant.
     * @throws \CentralVet\Authorization\Exception\AuthorizationDenied
     * @throws \CentralVet\Domain\Exception\InvalidStatusTransitionException
     *         when the encounter is not paused.
     */
    public function resume(int $id, string $action, ?DateTimeImmutable $now = null): Encounter
    {
        $encounter = $this->requireAuthorizedEncounter($id, $action);

        $encounter->resume($now ?? new DateTimeImmutable());

        /** @var Encounter $saved */
        $saved = $this->encounters->save($encounter);

        return $saved;
    }

    /**
     * Loads the encounter and runs the same unit-scope authorization as
     * finish() (the encounter's own system_unit_id, entityType 'encounter').
     */
    private function requireAuthorizedEncounter(int $id, string $action): Encounter
    {
        $encounter = $this->requireEncounter($id);

        $this->authorization->decide(new AuthorizationRequest(
            context: $this->context,
            action: $action,
            requiresUnitScope: true,
            resourceUnitId: $encounter->systemUnitId(),
            entityType: 'encounter',
            entityId: $id,
        ))->assertAllowed();

        return $encounter;
    }

    /**
     * Stores the accepted AI summary (T-32, correção 1): the encounter is
     * read under the row lock (lockForDraft(), so the caller must hold a
     * transaction) and only the summary columns are written
     * (updateAiSummary()); status, finished_at and pause are never
     * rewritten from this read.
     *
     * @throws InvalidArgumentException when no encounter with this id exists
     *         for the authenticated tenant.
     * @throws \CentralVet\Domain\Exception\InvalidStatusTransitionException
     *         when the encounter is finished (checked under the lock).
     */
    public function acceptAiSummary(int $id, string $summaryText): Encounter
    {
        $encounter = $this->encounters->lockForDraft($id);

        if ($encounter === null) {
            throw new InvalidArgumentException(
                "encounter {$id} was not found for the authenticated tenant"
            );
        }

        $encounter->acceptAiSummary($summaryText, new DateTimeImmutable());
        $this->encounters->updateAiSummary($encounter);

        return $encounter;
    }

    /**
     * Reads this encounter's audit trail straight from `audit_log`
     * (see the PDO dependency note on the class docblock for why there is
     * no Repository indirection here), filtered by
     * `entity_type = 'encounter' AND entity_id = :id`, scoped to the
     * authenticated tenant, oldest first. 'encounter' is a fixed literal
     * appended to the WHERE clause, not caller input, so it is safe outside
     * TenantQuery's parameter binding (same convention
     * EncounterRepository/QueueEntryRepository use for their own status
     * literals).
     *
     * @return list<array{
     *     id: int,
     *     action: string,
     *     system_unit_id: int|null,
     *     system_user_id: int|null,
     *     correlation_id: string,
     *     before: array<string, mixed>|null,
     *     after: array<string, mixed>|null,
     *     metadata: array<string, mixed>|null,
     *     ip_address: string|null,
     *     user_agent: string|null,
     *     created_at: DateTimeImmutable,
     * }>
     */
    public function timeline(int $id): array
    {
        $tenantId = $this->context->tenantId();

        $statement = $this->connection->prepare(
            <<<'SQL'
            SELECT id, system_unit_id, system_user_id, correlation_id, action,
                   before_data, after_data, metadata, ip_address, user_agent, created_at
            FROM audit_log
            WHERE tenant_id = :tenant_id AND entity_type = 'encounter' AND entity_id = :entity_id
            ORDER BY created_at ASC
            SQL
        );
        $statement->execute([
            ':tenant_id' => $tenantId,
            ':entity_id' => (string) $id,
        ]);

        $events = [];

        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $events[] = [
                'id' => (int) $row['id'],
                'action' => (string) $row['action'],
                'system_unit_id' => $row['system_unit_id'] !== null ? (int) $row['system_unit_id'] : null,
                'system_user_id' => $row['system_user_id'] !== null ? (int) $row['system_user_id'] : null,
                'correlation_id' => (string) $row['correlation_id'],
                'before' => self::decodeJson($row['before_data']),
                'after' => self::decodeJson($row['after_data']),
                'metadata' => self::decodeJson($row['metadata']),
                'ip_address' => $row['ip_address'] !== null ? (string) $row['ip_address'] : null,
                'user_agent' => $row['user_agent'] !== null ? (string) $row['user_agent'] : null,
                'created_at' => new DateTimeImmutable((string) $row['created_at']),
            ];
        }

        return $events;
    }

    /**
     * @throws InvalidArgumentException when no encounter with this id exists
     *         for the authenticated tenant.
     */
    private function requireEncounter(int $id): Encounter
    {
        $encounter = $this->findById($id);

        if ($encounter === null) {
            throw new InvalidArgumentException(
                "encounter {$id} was not found for the authenticated tenant"
            );
        }

        return $encounter;
    }

    /** @return array<string, mixed>|null */
    private static function decodeJson(?string $json): ?array
    {
        if ($json === null) {
            return null;
        }

        try {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

            return $decoded;
        } catch (JsonException) {
            return null;
        }
    }
}
