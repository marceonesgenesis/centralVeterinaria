<?php

declare(strict_types=1);

namespace CentralVet\Application;

use CentralVet\Authorization\AuthorizationRequest;
use CentralVet\Authorization\Contract\AuthorizationPolicyInterface;
use CentralVet\Domain\Appointment;
use CentralVet\Domain\Contract\ProductRepositoryInterface;
use CentralVet\Domain\Contract\SurgeryChecklistRepositoryInterface;
use CentralVet\Domain\Contract\SurgeryEventRepositoryInterface;
use CentralVet\Domain\Contract\SurgeryMaterialRepositoryInterface;
use CentralVet\Domain\Contract\SurgeryRepositoryInterface;
use CentralVet\Domain\EncounterAccount;
use CentralVet\Domain\EncounterAccountItem;
use CentralVet\Domain\Exception\CrossTenantReferenceException;
use CentralVet\Domain\Exception\InvalidStatusTransitionException;
use CentralVet\Domain\Product;
use CentralVet\Domain\StockMovement;
use CentralVet\Domain\Surgery;
use CentralVet\Domain\SurgeryChecklist;
use CentralVet\Domain\SurgeryChecklistItem;
use CentralVet\Domain\SurgeryEvent;
use CentralVet\Domain\SurgeryMaterial;
use CentralVet\Tenancy\TenantContext;
use Closure;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Integrated surgery completion (T-11): crosses the surgery, the encounter
 * account (Phase 5), stock (Phase 4) and the agenda (follow-up).
 *
 * complete() consumes the recorded materials (FEFO, reason
 * `surgery_consumption`), bills the procedure and each material, completes
 * the surgery and records the completion event. scheduleFollowUp() books
 * the surgeon's follow-up appointment and links it to the surgery.
 *
 * Opens no transaction itself: the caller (SurgeryView) wraps each call in
 * a single TTransaction so any exception — insufficient stock, closed
 * account, concurrent status change, scheduling conflict — rolls
 * everything back. Both methods take the surgery row lock (lockStatus)
 * before reading and deciding; checks that can fail without touching data
 * (status, sign_out, account open, products resolvable) run before the
 * first write.
 */
final class SurgeryCompletionService
{
    private readonly Closure $clock;

    public function __construct(
        private readonly SurgeryRepositoryInterface $surgeries,
        private readonly SurgeryChecklistRepositoryInterface $checklist,
        private readonly SurgeryMaterialRepositoryInterface $materials,
        private readonly SurgeryEventRepositoryInterface $events,
        private readonly ProductRepositoryInterface $products,
        private readonly EncounterAccountService $accounts,
        private readonly StockService $stock,
        private readonly AppointmentService $appointments,
        private readonly AuthorizationPolicyInterface $authorization,
        private readonly TenantContext $context,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): DateTimeImmutable => new DateTimeImmutable();
    }

    /**
     * @return array{account_id: int, items_added: int, consumed_products: int}
     *
     * @throws CrossTenantReferenceException when the surgery or a material's
     *         product does not resolve within the tenant.
     * @throws InvalidStatusTransitionException when the surgery is not in
     *         progress, sign_out is not confirmed or the account is not open.
     * @throws \CentralVet\Domain\Exception\InsufficientStockException when a
     *         material lacks stock at the unit (nothing is written before).
     */
    public function complete(int $surgeryId, string $action): array
    {
        [$surgery, $lockedStatus] = $this->lockAndLoad($surgeryId, $action);

        if ($lockedStatus !== Surgery::STATUS_IN_PROGRESS) {
            throw new InvalidStatusTransitionException("Surgery {$surgeryId} is not in progress");
        }

        $this->assertSignOutConfirmed($surgeryId);

        $account = $this->accounts->openOrGet($surgery->encounterId(), $action);

        if ($account->status() !== EncounterAccount::STATUS_OPEN) {
            // Same wording as EncounterAccountService::assertAccountOpen().
            throw new InvalidStatusTransitionException(
                sprintf(
                    'Encounter account %d cannot be modified: status is "%s", not "open"',
                    $account->id() ?? 0,
                    $account->status(),
                )
            );
        }

        $accountId = (int) $account->id();
        $now = ($this->clock)();
        $userId = $this->context->userId();

        /** @var list<array{material: SurgeryMaterial, product: Product}> $billable */
        $billable = [];
        /** @var array<int, int> $quantityByProduct */
        $quantityByProduct = [];
        /** @var array<int, Product> $productsById */
        $productsById = [];

        foreach ($this->materials->listBySurgery($surgeryId) as $material) {
            /** @var SurgeryMaterial $material */
            $productId = $material->productId();

            if (!isset($productsById[$productId])) {
                $product = $this->products->findById($productId);

                if (!$product instanceof Product) {
                    throw new CrossTenantReferenceException(
                        "product_id {$productId} was not found for the authenticated tenant"
                    );
                }

                $productsById[$productId] = $product;
            }

            $quantityByProduct[$productId] = ($quantityByProduct[$productId] ?? 0) + $material->quantity();
            $billable[] = ['material' => $material, 'product' => $productsById[$productId]];
        }

        foreach ($quantityByProduct as $productId => $quantity) {
            $this->stock->consume(
                $this->context->tenantId(),
                $surgery->systemUnitId(),
                $productId,
                $quantity,
                StockMovement::REASON_SURGERY_CONSUMPTION,
                'surgery',
                $surgeryId,
                $userId,
            );
        }

        $itemsAdded = 0;

        if ($surgery->procedurePriceCents() > 0) {
            $item = $this->accounts->addSourcedItem(
                $accountId,
                EncounterAccountItem::TYPE_SURGERY_PROCEDURE,
                $surgeryId,
                'Cirurgia — ' . $surgery->procedureName(),
                $surgery->procedurePriceCents(),
                $action,
            );
            $itemsAdded += $item !== null ? 1 : 0;
        }

        foreach ($billable as $line) {
            $material = $line['material'];
            $amountCents = (int) ($line['product']->salePriceCents() ?? 0) * $material->quantity();

            if ($amountCents <= 0) {
                continue;
            }

            $item = $this->accounts->addSourcedItem(
                $accountId,
                EncounterAccountItem::TYPE_SURGERY_MATERIAL,
                (int) $material->id(),
                $line['product']->name() . ' × ' . $material->quantity(),
                $amountCents,
                $action,
            );
            $itemsAdded += $item !== null ? 1 : 0;
        }

        $surgery->complete($now, $userId);
        $this->surgeries->save($surgery);

        $this->events->save(SurgeryEvent::record(
            $this->context->tenantId(),
            $surgeryId,
            SurgeryEvent::TYPE_COMPLETION,
            $userId,
            $now,
            null,
        ));

        return [
            'account_id' => $accountId,
            'items_added' => $itemsAdded,
            'consumed_products' => count($quantityByProduct),
        ];
    }

    /**
     * Books the surgeon's follow-up appointment for a completed surgery and
     * links it to the surgery (once).
     *
     * @throws CrossTenantReferenceException when the surgery (or the
     *         service/patient of the appointment) does not resolve.
     * @throws InvalidStatusTransitionException when the surgery is not
     *         completed or already has a follow-up appointment.
     * @throws \CentralVet\Domain\Exception\SchedulingConflictException when
     *         the slot overlaps another appointment of the surgeon.
     */
    public function scheduleFollowUp(
        int $surgeryId,
        int $serviceId,
        DateTimeImmutable $scheduledAt,
        string $action,
    ): Appointment {
        [$surgery, $lockedStatus] = $this->lockAndLoad($surgeryId, $action);

        // Same wording as Surgery::linkFollowUp(), checked before booking.
        if ($lockedStatus !== Surgery::STATUS_COMPLETED) {
            throw new InvalidStatusTransitionException("Surgery {$surgeryId} is not completed");
        }

        if ($surgery->followupAppointmentId() !== null) {
            throw new InvalidStatusTransitionException("Surgery {$surgeryId} already has a follow-up appointment");
        }

        $appointment = $this->appointments->schedule([
            'patient_id' => $surgery->patientId(),
            'service_id' => $serviceId,
            'professional_system_user_id' => $surgery->surgeonSystemUserId(),
            'scheduled_at' => $scheduledAt,
            'system_unit_id' => $surgery->systemUnitId(),
        ], $action);

        $surgery->linkFollowUp((int) $appointment->id);
        $this->surgeries->save($surgery);

        $this->events->save(SurgeryEvent::record(
            $this->context->tenantId(),
            $surgeryId,
            SurgeryEvent::TYPE_FOLLOWUP,
            $this->context->userId(),
            ($this->clock)(),
            null,
        ));

        return $appointment;
    }

    /**
     * Takes the surgery row lock, then loads (after the lock) and authorizes
     * against the surgery's unit.
     *
     * @return array{0: Surgery, 1: string} the surgery and its locked status.
     */
    private function lockAndLoad(int $surgeryId, string $action): array
    {
        if ($surgeryId <= 0) {
            throw new InvalidArgumentException('surgery_id must be positive');
        }

        $lockedStatus = $this->surgeries->lockStatus($surgeryId);
        $surgery = $lockedStatus === null ? null : $this->surgeries->findById($surgeryId);

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
            entityType: 'surgery',
            entityId: $surgeryId,
        ))->assertAllowed();

        return [$surgery, $lockedStatus];
    }

    private function assertSignOutConfirmed(int $surgeryId): void
    {
        $checked = [];

        foreach ($this->checklist->listBySurgery($surgeryId) as $item) {
            /** @var SurgeryChecklistItem $item */
            if ($item->phase() === SurgeryChecklist::PHASE_SIGN_OUT) {
                $checked[$item->itemCode()] = true;
            }
        }

        foreach (SurgeryChecklist::items(SurgeryChecklist::PHASE_SIGN_OUT) as $code) {
            if (!isset($checked[$code])) {
                throw new InvalidStatusTransitionException(
                    sprintf('Checklist phase "%s" is not confirmed for surgery %d', SurgeryChecklist::PHASE_SIGN_OUT, $surgeryId)
                );
            }
        }
    }
}
