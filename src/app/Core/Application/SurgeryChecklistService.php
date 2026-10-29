<?php

declare(strict_types=1);

namespace CentralVet\Application;

use CentralVet\Authorization\AuthorizationRequest;
use CentralVet\Authorization\Contract\AuthorizationPolicyInterface;
use CentralVet\Domain\Contract\SurgeryChecklistRepositoryInterface;
use CentralVet\Domain\Contract\SurgeryEventRepositoryInterface;
use CentralVet\Domain\Contract\SurgeryRepositoryInterface;
use CentralVet\Domain\Exception\CrossTenantReferenceException;
use CentralVet\Domain\Exception\InvalidStatusTransitionException;
use CentralVet\Domain\Surgery;
use CentralVet\Domain\SurgeryChecklist;
use CentralVet\Domain\SurgeryChecklistItem;
use CentralVet\Domain\SurgeryEvent;
use CentralVet\Tenancy\TenantContext;
use Closure;
use DateTimeImmutable;

/**
 * Surgical safety checklist (T-09): confirms a whole phase at once — one
 * SurgeryChecklistItem per item code, all with the same checked_at and
 * user, plus a `checklist` event whose notes are the phase — and reports
 * which phases are confirmed.
 *
 * Gates: `sign_in` and `time_out` only in `pre_op`, `sign_out` only in
 * `in_progress`; `time_out` requires `sign_in`; a phase is confirmed once.
 * A double tap racing past the check reaches the repository UNIQUE key,
 * which throws the same "already confirmed" message.
 *
 * Authorization runs against the persisted surgery's own system_unit_id
 * with entityType `surgery`. The service opens no transaction: the
 * controller wraps the call in its TTransaction and rolls it back.
 */
final class SurgeryChecklistService
{
    private const ENTITY_TYPE = 'surgery';

    /** @var array<string, string> phase => surgery status in which it can be confirmed */
    private const PHASE_STATUS = [
        SurgeryChecklist::PHASE_SIGN_IN => Surgery::STATUS_PRE_OP,
        SurgeryChecklist::PHASE_TIME_OUT => Surgery::STATUS_PRE_OP,
        SurgeryChecklist::PHASE_SIGN_OUT => Surgery::STATUS_IN_PROGRESS,
    ];

    private readonly Closure $clock;

    public function __construct(
        private readonly SurgeryRepositoryInterface $surgeries,
        private readonly SurgeryChecklistRepositoryInterface $checklist,
        private readonly SurgeryEventRepositoryInterface $events,
        private readonly AuthorizationPolicyInterface $authorization,
        private readonly TenantContext $context,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): DateTimeImmutable => new DateTimeImmutable();
    }

    /**
     * Confirms every item of a phase.
     *
     * @param array<int, string> $checkedItemCodes
     * @return list<SurgeryChecklistItem> the saved items, in catalog order.
     *
     * @throws CrossTenantReferenceException surgery not found in the tenant.
     * @throws InvalidStatusTransitionException wrong status, missing sign_in
     *         or phase already confirmed.
     * @throws \InvalidArgumentException unknown phase/item or incomplete list.
     */
    public function confirmPhase(int $surgeryId, string $phase, array $checkedItemCodes, string $action): array
    {
        $surgery = $this->authorizedSurgery($surgeryId, $action);
        $phaseCodes = SurgeryChecklist::items($phase);

        if ($surgery->status() !== self::PHASE_STATUS[$phase]) {
            throw new InvalidStatusTransitionException(
                "Checklist phase \"{$phase}\" cannot be confirmed while surgery {$surgeryId} is {$surgery->status()}"
            );
        }

        $byPhase = $this->itemsByPhase($surgeryId);

        if ($phase === SurgeryChecklist::PHASE_TIME_OUT && !$this->isComplete(SurgeryChecklist::PHASE_SIGN_IN, $byPhase)) {
            throw new InvalidStatusTransitionException(
                "Checklist phase \"" . SurgeryChecklist::PHASE_SIGN_IN . "\" is not confirmed for surgery {$surgeryId}"
            );
        }

        if (($byPhase[$phase] ?? []) !== []) {
            throw new InvalidStatusTransitionException(
                "Checklist phase \"{$phase}\" is already confirmed for surgery {$surgeryId}"
            );
        }

        $checkedItemCodes = array_map('strval', array_values($checkedItemCodes));
        SurgeryChecklist::assertComplete($phase, $checkedItemCodes);

        $now = $this->now();
        $userId = $this->context->userId();
        $saved = [];

        foreach ($phaseCodes as $code) {
            $item = SurgeryChecklistItem::check(
                $this->context->tenantId(),
                $surgeryId,
                $phase,
                $code,
                $userId,
                $now,
            );
            $this->checklist->save($item);
            $saved[] = $item;
        }

        $this->events->save(SurgeryEvent::record(
            $this->context->tenantId(),
            $surgeryId,
            SurgeryEvent::TYPE_CHECKLIST,
            $userId,
            $now,
            $phase,
        ));

        return $saved;
    }

    /**
     * Confirmation state of each phase, in SurgeryChecklist::PHASES order.
     *
     * @return array<string, array{confirmed: bool, checked_by_system_user_id: ?int, checked_at: ?DateTimeImmutable}>
     */
    public function phaseStatus(int $surgeryId, string $action): array
    {
        $this->authorizedSurgery($surgeryId, $action);
        $byPhase = $this->itemsByPhase($surgeryId);
        $status = [];

        foreach (SurgeryChecklist::PHASES as $phase) {
            $confirmed = $this->isComplete($phase, $byPhase);
            $first = $confirmed ? $byPhase[$phase][0] : null;

            $status[$phase] = [
                'confirmed' => $confirmed,
                'checked_by_system_user_id' => $first?->checkedBySystemUserId(),
                'checked_at' => $first?->checkedAt(),
            ];
        }

        return $status;
    }

    /** @return array<string, list<SurgeryChecklistItem>> */
    private function itemsByPhase(int $surgeryId): array
    {
        $byPhase = [];

        foreach ($this->checklist->listBySurgery($surgeryId) as $item) {
            $byPhase[$item->phase()][] = $item;
        }

        return $byPhase;
    }

    /** @param array<string, list<SurgeryChecklistItem>> $byPhase */
    private function isComplete(string $phase, array $byPhase): bool
    {
        $checked = array_map(
            static fn (SurgeryChecklistItem $item): string => $item->itemCode(),
            $byPhase[$phase] ?? [],
        );

        return array_diff(SurgeryChecklist::items($phase), $checked) === [];
    }

    private function authorizedSurgery(int $surgeryId, string $action): Surgery
    {
        $surgery = $this->surgeries->findById($surgeryId);

        if (!$surgery instanceof Surgery) {
            throw new CrossTenantReferenceException(
                "surgery_id {$surgeryId} was not found for the authenticated tenant"
            );
        }

        $this->authorization->decide(new AuthorizationRequest(
            context: $this->context,
            action: $action,
            requiresUnitScope: true,
            resourceUnitId: $surgery->systemUnitId(),
            entityType: self::ENTITY_TYPE,
            entityId: $surgeryId,
        ))->assertAllowed();

        return $surgery;
    }

    private function now(): DateTimeImmutable
    {
        return ($this->clock)();
    }
}
