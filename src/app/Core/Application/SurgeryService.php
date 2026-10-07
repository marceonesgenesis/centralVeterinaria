<?php

declare(strict_types=1);

namespace CentralVet\Application;

use CentralVet\Authorization\AuthorizationRequest;
use CentralVet\Authorization\Contract\AuthorizationPolicyInterface;
use CentralVet\Domain\Contract\EncounterAccountRepositoryInterface;
use CentralVet\Domain\Contract\EncounterRepositoryInterface;
use CentralVet\Domain\Contract\ProcedureCatalogRepositoryInterface;
use CentralVet\Domain\Contract\SurgeryChecklistRepositoryInterface;
use CentralVet\Domain\Contract\SurgeryEventRepositoryInterface;
use CentralVet\Domain\Contract\SurgeryRepositoryInterface;
use CentralVet\Domain\Contract\SurgeryRoomRepositoryInterface;
use CentralVet\Domain\Contract\SurgeryTeamRepositoryInterface;
use CentralVet\Domain\Contract\TenantUserDirectoryInterface;
use CentralVet\Domain\Encounter;
use CentralVet\Domain\EncounterAccount;
use CentralVet\Domain\Exception\CrossTenantReferenceException;
use CentralVet\Domain\Exception\InvalidStatusTransitionException;
use CentralVet\Domain\Exception\SurgeryRoomUnavailableException;
use CentralVet\Domain\ProcedureCatalogItem;
use CentralVet\Domain\Surgery;
use CentralVet\Domain\SurgeryChecklist;
use CentralVet\Domain\SurgeryChecklistItem;
use CentralVet\Domain\SurgeryEvent;
use CentralVet\Domain\SurgeryRoom;
use CentralVet\Domain\SurgeryTeamMember;
use CentralVet\Tenancy\TenantContext;
use Closure;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Use cases of a surgery's life cycle (T-08): scheduling from an encounter
 * with its team, team replacement, consent, pre-op, start, cancellation,
 * clinical events and unit-scoped reads.
 *
 * Room conflicts are decided under the room lock: `lockForScheduling()`
 * serializes the overlap check and the insert of surgeries in the same room.
 * Status changes rely on the repository's conditional save (the row is only
 * updated while its status is still the one this instance expects), so a
 * concurrent change surfaces as "changed status concurrently".
 *
 * Opens no transaction (the caller's TTransaction wraps the whole use case)
 * and depends only on Domain contracts and TenantContext (ADR 0001).
 */
final class SurgeryService
{
    private const MIN_DURATION_MINUTES = 15;
    private const MAX_DURATION_MINUTES = 1440;

    /** Checklist phases required before `start()`. */
    private const START_PHASES = [SurgeryChecklist::PHASE_SIGN_IN, SurgeryChecklist::PHASE_TIME_OUT];

    private readonly Closure $clock;

    public function __construct(
        private readonly SurgeryRepositoryInterface $surgeries,
        private readonly SurgeryRoomRepositoryInterface $rooms,
        private readonly SurgeryTeamRepositoryInterface $team,
        private readonly SurgeryChecklistRepositoryInterface $checklist,
        private readonly SurgeryEventRepositoryInterface $events,
        private readonly EncounterRepositoryInterface $encounters,
        private readonly EncounterAccountRepositoryInterface $accounts,
        private readonly ProcedureCatalogRepositoryInterface $procedures,
        private readonly TenantUserDirectoryInterface $users,
        private readonly AuthorizationPolicyInterface $authorization,
        private readonly TenantContext $context,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): DateTimeImmutable => new DateTimeImmutable();
    }

    /**
     * Schedules a surgery for the encounter's patient in a room of the
     * encounter's unit, with its team (the surgeon is always a member).
     *
     * @param list<array{system_user_id: int, role: string}> $team
     *
     * @throws CrossTenantReferenceException encounter, procedure or room not found for this tenant.
     * @throws \CentralVet\Authorization\Exception\AuthorizationDenied
     * @throws InvalidStatusTransitionException the encounter account exists and is not open.
     * @throws InvalidArgumentException bad duration, inactive user, unknown role or room of another unit.
     * @throws SurgeryRoomUnavailableException room inactive or already booked for the period.
     */
    public function schedule(
        int $encounterId,
        int $roomId,
        int $procedureCatalogItemId,
        int $surgeonSystemUserId,
        DateTimeImmutable $scheduledStartAt,
        int $durationMinutes,
        array $team,
        ?string $notesText,
        string $action,
    ): Surgery {
        $encounter = $encounterId > 0 ? $this->encounters->findById($encounterId) : null;

        if (!$encounter instanceof Encounter) {
            throw new CrossTenantReferenceException(
                "encounter_id {$encounterId} was not found for the authenticated tenant"
            );
        }

        $unitId = $encounter->systemUnitId();
        $this->authorize($action, $unitId, null);

        $account = $this->accounts->findByEncounterId($encounterId);

        if ($account instanceof EncounterAccount && $account->status() !== EncounterAccount::STATUS_OPEN) {
            throw new InvalidStatusTransitionException(sprintf(
                'Encounter account %d cannot be modified: status is "%s", not "open"',
                $account->id() ?? 0,
                $account->status(),
            ));
        }

        $procedure = $procedureCatalogItemId > 0 ? $this->procedures->findById($procedureCatalogItemId) : null;

        if (!$procedure instanceof ProcedureCatalogItem || !$procedure->active()) {
            throw new CrossTenantReferenceException(
                "procedure_catalog_item_id {$procedureCatalogItemId} was not found for the authenticated tenant"
            );
        }

        if ($durationMinutes < self::MIN_DURATION_MINUTES || $durationMinutes > self::MAX_DURATION_MINUTES) {
            throw new InvalidArgumentException('duration_minutes must be between 15 and 1440');
        }

        $scheduledEndAt = $scheduledStartAt->modify("+{$durationMinutes} minutes");
        $members = $this->normalizeTeam($surgeonSystemUserId, $team);

        if (!$this->rooms->lockForScheduling($roomId)) {
            throw new CrossTenantReferenceException("room_id {$roomId} was not found for the authenticated tenant");
        }

        $room = $this->rooms->findById($roomId);

        if (!$room instanceof SurgeryRoom) {
            throw new CrossTenantReferenceException("room_id {$roomId} was not found for the authenticated tenant");
        }

        if ($room->systemUnitId() !== $unitId) {
            throw new InvalidArgumentException("Surgery room {$roomId} belongs to another unit");
        }

        if (!$room->isActive()) {
            throw SurgeryRoomUnavailableException::inactive($roomId);
        }

        if ($this->surgeries->hasOverlapInRoom($roomId, $scheduledStartAt, $scheduledEndAt, null)) {
            throw SurgeryRoomUnavailableException::booked($roomId);
        }

        $surgery = Surgery::schedule(
            tenantId: $this->context->tenantId(),
            systemUnitId: $unitId,
            patientId: $encounter->patientId(),
            encounterId: $encounterId,
            roomId: $roomId,
            procedureCatalogItemId: (int) $procedure->id(),
            procedureName: $procedure->name(),
            procedurePriceCents: $procedure->priceCents(),
            surgeonSystemUserId: $surgeonSystemUserId,
            scheduledBySystemUserId: $this->context->userId(),
            scheduledStartAt: $scheduledStartAt,
            scheduledEndAt: $scheduledEndAt,
            notesText: $notesText,
        );

        /** @var Surgery $surgery */
        $surgery = $this->surgeries->save($surgery);
        $surgeryId = (int) $surgery->id();

        $this->team->replaceForSurgery($surgeryId, $this->buildMembers($surgeryId, $members));
        $this->recordEvent($surgeryId, SurgeryEvent::TYPE_SCHEDULED, $surgery->procedureName());

        return $surgery;
    }

    /**
     * Replaces the whole team while the surgery is `scheduled`/`pre_op`; the
     * surgeon keeps the `surgeon` role.
     *
     * @param list<array{system_user_id: int, role: string}> $team
     *
     * @return list<SurgeryTeamMember>
     */
    public function replaceTeam(int $surgeryId, array $team, string $action): array
    {
        $surgery = $this->requireSurgery($surgeryId, $action);
        $lockedStatus = $this->surgeries->lockStatus($surgeryId) ?? $surgery->status();

        if (!$surgery->isOpenForPreOp()
            || !in_array($lockedStatus, [Surgery::STATUS_SCHEDULED, Surgery::STATUS_PRE_OP], true)
        ) {
            throw new InvalidStatusTransitionException("Surgery {$surgeryId} is not open for pre-operative changes");
        }

        $members = $this->normalizeTeam($surgery->surgeonSystemUserId(), $team);
        $this->team->replaceForSurgery($surgeryId, $this->buildMembers($surgeryId, $members));

        /** @var list<SurgeryTeamMember> $list */
        $list = $this->team->listBySurgery($surgeryId);

        return $list;
    }

    public function recordConsent(int $surgeryId, string $signerName, string $consentText, string $action): Surgery
    {
        $surgery = $this->requireSurgery($surgeryId, $action);
        $surgery->recordConsent($signerName, $consentText, $this->context->userId(), $this->now());

        /** @var Surgery $surgery */
        $surgery = $this->surgeries->save($surgery);
        $this->recordEvent($surgeryId, SurgeryEvent::TYPE_CONSENT, $surgery->consentSignerName());

        return $surgery;
    }

    public function startPreOp(int $surgeryId, string $action): Surgery
    {
        $surgery = $this->requireSurgery($surgeryId, $action);
        $surgery->startPreOp();

        return $this->saveTransition($surgery);
    }

    /**
     * Starts the surgery: requires `pre_op`, recorded consent (Domain) and
     * the `sign_in` and `time_out` checklist phases fully confirmed.
     */
    public function start(int $surgeryId, string $action): Surgery
    {
        $surgery = $this->requireSurgery($surgeryId, $action);
        $surgery->start($this->now());
        $this->assertChecklistConfirmed($surgeryId, self::START_PHASES);

        return $this->saveTransition($surgery);
    }

    public function cancel(int $surgeryId, string $reasonText, string $action): Surgery
    {
        $surgery = $this->requireSurgery($surgeryId, $action);
        $surgery->cancel($this->now(), $this->context->userId(), $reasonText);

        /** @var Surgery $surgery */
        $surgery = $this->surgeries->save($surgery);
        $this->recordEvent($surgeryId, SurgeryEvent::TYPE_CANCELLATION, $surgery->cancellationReasonText());

        return $surgery;
    }

    public function recordClinicalEvent(int $surgeryId, string $eventType, string $notesText, string $action): SurgeryEvent
    {
        if (!in_array($eventType, SurgeryEvent::CLINICAL_TYPES, true)) {
            throw new InvalidArgumentException("Unknown surgery event type \"{$eventType}\"");
        }

        $surgery = $this->requireSurgery($surgeryId, $action);

        if ($surgery->status() === Surgery::STATUS_CANCELLED) {
            throw new InvalidStatusTransitionException("Surgery {$surgeryId} is cancelled");
        }

        return $this->recordEvent($surgeryId, $eventType, $notesText);
    }

    /** @throws CrossTenantReferenceException `Surgery <id> not found for this tenant` */
    public function get(int $surgeryId, string $action): Surgery
    {
        return $this->requireSurgery($surgeryId, $action);
    }

    /** @return list<SurgeryTeamMember> */
    public function listTeam(int $surgeryId, string $action): array
    {
        $this->requireSurgery($surgeryId, $action);

        /** @var list<SurgeryTeamMember> $list */
        $list = $this->team->listBySurgery($surgeryId);

        return $list;
    }

    /** @return list<SurgeryEvent> most recent first */
    public function listEvents(int $surgeryId, string $action): array
    {
        $this->requireSurgery($surgeryId, $action);

        /** @var list<SurgeryEvent> $list */
        $list = $this->events->listBySurgery($surgeryId);

        return $list;
    }

    /** @return list<Surgery> surgeries of the active unit scheduled on the given day (any status) */
    public function listForDay(DateTimeImmutable $day, string $action): array
    {
        $unitId = $this->context->requireUnitId();
        $this->authorize($action, $unitId, null);

        /** @var list<Surgery> $list */
        $list = $this->surgeries->listByUnitAndDay($unitId, $day);

        return $list;
    }

    /** @return list<ProcedureCatalogItem> active procedures that can be scheduled */
    public function listProcedures(string $action): array
    {
        $this->authorize($action, $this->context->requireUnitId(), null);

        /** @var list<ProcedureCatalogItem> $list */
        $list = $this->procedures->findActive();

        return $list;
    }

    private function requireSurgery(int $surgeryId, string $action): Surgery
    {
        $surgery = $surgeryId > 0 ? $this->surgeries->findById($surgeryId) : null;

        if (!$surgery instanceof Surgery) {
            throw new CrossTenantReferenceException("Surgery {$surgeryId} not found for this tenant");
        }

        $this->authorize($action, $surgery->systemUnitId(), $surgeryId);

        return $surgery;
    }

    private function saveTransition(Surgery $surgery): Surgery
    {
        /** @var Surgery $surgery */
        $surgery = $this->surgeries->save($surgery);
        $this->recordEvent((int) $surgery->id(), SurgeryEvent::TYPE_STATUS, $surgery->status());

        return $surgery;
    }

    /** @param list<string> $phases */
    private function assertChecklistConfirmed(int $surgeryId, array $phases): void
    {
        $checked = [];

        /** @var SurgeryChecklistItem $item */
        foreach ($this->checklist->listBySurgery($surgeryId) as $item) {
            $checked[$item->phase()][$item->itemCode()] = true;
        }

        foreach ($phases as $phase) {
            foreach (SurgeryChecklist::items($phase) as $code) {
                if (!isset($checked[$phase][$code])) {
                    throw new InvalidStatusTransitionException(
                        "Checklist phase \"{$phase}\" is not confirmed for surgery {$surgeryId}"
                    );
                }
            }
        }
    }

    /**
     * Validates the surgeon and every member (active user, known role),
     * adds the surgeon as `surgeon` when missing and drops repeated pairs.
     *
     * @param array<int, mixed> $team
     *
     * @return list<array{system_user_id: int, role: string}>
     */
    private function normalizeTeam(int $surgeonSystemUserId, array $team): array
    {
        if ($surgeonSystemUserId <= 0 || !$this->users->isActiveMember($surgeonSystemUserId)) {
            throw new InvalidArgumentException('surgeon_system_user_id must be an active user of this tenant');
        }

        $pairs = [];

        foreach ($team as $entry) {
            $userId = is_array($entry) ? (int) ($entry['system_user_id'] ?? 0) : 0;
            $role = is_array($entry) ? (string) ($entry['role'] ?? '') : '';

            if (!in_array($role, SurgeryTeamMember::ROLES, true)) {
                throw new InvalidArgumentException("Unknown team role \"{$role}\"");
            }

            $key = $userId . '|' . $role;

            if (isset($pairs[$key])) {
                continue;
            }

            if ($userId !== $surgeonSystemUserId
                && ($userId <= 0 || !$this->users->isActiveMember($userId))
            ) {
                throw new InvalidArgumentException("Team member {$userId} must be an active user of this tenant");
            }

            $pairs[$key] = ['system_user_id' => $userId, 'role' => $role];
        }

        $surgeonKey = $surgeonSystemUserId . '|' . SurgeryTeamMember::ROLE_SURGEON;

        if (!isset($pairs[$surgeonKey])) {
            $pairs = [$surgeonKey => ['system_user_id' => $surgeonSystemUserId, 'role' => SurgeryTeamMember::ROLE_SURGEON]] + $pairs;
        }

        return array_values($pairs);
    }

    /**
     * @param list<array{system_user_id: int, role: string}> $pairs
     *
     * @return list<SurgeryTeamMember>
     */
    private function buildMembers(int $surgeryId, array $pairs): array
    {
        return array_map(
            fn (array $pair): SurgeryTeamMember => SurgeryTeamMember::create(
                $this->context->tenantId(),
                $surgeryId,
                $pair['system_user_id'],
                $pair['role'],
            ),
            $pairs,
        );
    }

    private function recordEvent(int $surgeryId, string $eventType, ?string $notesText): SurgeryEvent
    {
        /** @var SurgeryEvent $event */
        $event = $this->events->save(SurgeryEvent::record(
            tenantId: $this->context->tenantId(),
            surgeryId: $surgeryId,
            eventType: $eventType,
            recordedBySystemUserId: $this->context->userId(),
            recordedAt: $this->now(),
            notesText: $notesText,
        ));

        return $event;
    }

    private function authorize(string $action, int $unitId, ?int $surgeryId): void
    {
        $this->authorization->decide(new AuthorizationRequest(
            context: $this->context,
            action: $action,
            requiresUnitScope: true,
            resourceUnitId: $unitId,
            entityType: 'surgery',
            entityId: $surgeryId,
        ))->assertAllowed();
    }

    private function now(): DateTimeImmutable
    {
        return ($this->clock)();
    }
}
