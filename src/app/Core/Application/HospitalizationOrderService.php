<?php

declare(strict_types=1);

namespace CentralVet\Application;

use CentralVet\Authorization\AuthorizationRequest;
use CentralVet\Authorization\Contract\AuthorizationPolicyInterface;
use CentralVet\Domain\AdministrationSchedule;
use CentralVet\Domain\Contract\HospitalizationAdministrationRepositoryInterface;
use CentralVet\Domain\Contract\HospitalizationOrderRepositoryInterface;
use CentralVet\Domain\Contract\HospitalizationRepositoryInterface;
use CentralVet\Domain\Contract\ProductRepositoryInterface;
use CentralVet\Domain\Exception\CrossTenantReferenceException;
use CentralVet\Domain\Exception\InvalidStatusTransitionException;
use CentralVet\Domain\Hospitalization;
use CentralVet\Domain\HospitalizationAdministration;
use CentralVet\Domain\HospitalizationOrder;
use CentralVet\Domain\Product;
use CentralVet\Tenancy\TenantContext;
use Closure;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Use cases for the internal prescription of a hospitalization (T-10):
 * prescribing an order together with its whole administration schedule
 * ({@see AdministrationSchedule::generate()}), suspending it (cancelling the
 * pending administrations still ahead of "now"), recording an
 * administration as done/skipped, and the shift flowboard rows with their
 * derived timeliness ({@see HospitalizationAdministration::classify()}).
 *
 * Authorization always runs against the persisted hospitalization's own
 * system_unit_id (never caller input), with entityType `hospitalization`;
 * the flowboard authorizes against the active unit. The optional $clock
 * (last parameter) makes "now" deterministic in tests.
 *
 * The service opens no transaction: the Presentation controller wraps each
 * call in its TTransaction, so a failure in the middle of the schedule
 * rolls the order back too.
 */
final class HospitalizationOrderService
{
    public const OUTCOME_DONE = 'done';
    public const OUTCOME_SKIPPED = 'skipped';

    /** Flowboard looks back this many hours to show late pending items. */
    public const BOARD_LOOKBACK_HOURS = 12;

    private const ENTITY_TYPE = 'hospitalization';

    private readonly Closure $clock;

    public function __construct(
        private readonly HospitalizationOrderRepositoryInterface $orders,
        private readonly HospitalizationAdministrationRepositoryInterface $administrations,
        private readonly HospitalizationRepositoryInterface $hospitalizations,
        private readonly ProductRepositoryInterface $products,
        private readonly AuthorizationPolicyInterface $authorization,
        private readonly TenantContext $context,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): DateTimeImmutable => new DateTimeImmutable();
    }

    /**
     * Creates an active order and one `pending` administration per slot of
     * its schedule.
     *
     * @throws CrossTenantReferenceException hospitalization or product not
     *         found in the tenant, or product inactive.
     * @throws InvalidStatusTransitionException hospitalization not admitted.
     */
    public function prescribe(
        int $hospitalizationId,
        string $orderType,
        string $descriptionText,
        ?int $productId,
        ?int $quantityPerAdministration,
        string $doseText,
        string $route,
        int $frequencyHours,
        DateTimeImmutable $startsAt,
        DateTimeImmutable $endsAt,
        string $action,
    ): HospitalizationOrder {
        $hospitalization = $this->authorizedHospitalization($hospitalizationId, $action);
        $this->assertAdmitted($hospitalization);

        if ($productId !== null) {
            $product = $this->products->findById($productId);

            if (!$product instanceof Product || !$product->isActive()) {
                throw new CrossTenantReferenceException(
                    "product_id {$productId} was not found among the active products of the authenticated tenant"
                );
            }
        }

        $order = HospitalizationOrder::prescribe(
            $this->context->tenantId(),
            $hospitalizationId,
            $orderType,
            $descriptionText,
            $productId,
            $quantityPerAdministration,
            $doseText,
            $route,
            $frequencyHours,
            $startsAt,
            $endsAt,
            $this->context->userId(),
        );
        $schedule = AdministrationSchedule::generate($startsAt, $endsAt, $frequencyHours);

        $this->orders->save($order);

        foreach ($schedule as $scheduledAt) {
            $this->administrations->save(HospitalizationAdministration::schedule(
                $this->context->tenantId(),
                $hospitalizationId,
                (int) $order->id(),
                $scheduledAt,
            ));
        }

        return $order;
    }

    /**
     * Suspends an active order and cancels its pending administrations
     * scheduled at or after now.
     *
     * @return int how many administrations were cancelled.
     */
    public function suspend(int $orderId, string $action): int
    {
        $order = $this->orders->findById($orderId);

        if (!$order instanceof HospitalizationOrder) {
            throw new CrossTenantReferenceException(
                "order_id {$orderId} was not found for the authenticated tenant"
            );
        }

        $this->authorizedHospitalization($order->hospitalizationId(), $action);

        $now = $this->now();
        $order->suspend($now);
        $this->orders->save($order);

        $cancelled = 0;

        foreach ($this->administrations->listPendingByOrder($orderId) as $administration) {
            if ($administration->scheduledAt() < $now) {
                continue;
            }

            $administration->cancel();
            $this->administrations->save($administration);
            $cancelled++;
        }

        return $cancelled;
    }

    /**
     * Records a pending administration as `done` or `skipped` (notes
     * required for skipped), performed now by the authenticated user.
     *
     * @throws InvalidArgumentException unknown $outcome.
     * @throws InvalidStatusTransitionException hospitalization not admitted
     *         or administration no longer pending.
     */
    public function recordAdministration(int $administrationId, string $outcome, string $notesText, string $action): HospitalizationAdministration
    {
        if ($outcome !== self::OUTCOME_DONE && $outcome !== self::OUTCOME_SKIPPED) {
            throw new InvalidArgumentException("Unknown administration outcome \"{$outcome}\"");
        }

        $administration = $this->findAdministration($administrationId);
        $hospitalization = $this->authorizedHospitalization($administration->hospitalizationId(), $action);
        $this->assertAdmitted($hospitalization);

        if ($outcome === self::OUTCOME_DONE) {
            $administration->markDone($this->now(), $this->context->userId(), $notesText);
        } else {
            $administration->markSkipped($this->now(), $this->context->userId(), $notesText);
        }

        $this->administrations->save($administration);

        return $administration;
    }

    public function getAdministration(int $administrationId, string $action): HospitalizationAdministration
    {
        $administration = $this->findAdministration($administrationId);
        $this->authorizedHospitalization($administration->hospitalizationId(), $action);

        return $administration;
    }

    /** @return list<HospitalizationOrder> */
    public function listOrders(int $hospitalizationId, string $action): array
    {
        $this->authorizedHospitalization($hospitalizationId, $action);

        return $this->orders->listByHospitalization($hospitalizationId);
    }

    /** @return list<HospitalizationAdministration> */
    public function listAdministrations(int $hospitalizationId, string $action): array
    {
        $this->authorizedHospitalization($hospitalizationId, $action);

        return $this->administrations->listByHospitalization($hospitalizationId);
    }

    /**
     * Flowboard rows of the active unit, from now − 12 h to now +
     * $windowHours, each with a `timeliness` key.
     *
     * @return list<array<string, mixed>>
     */
    public function boardRowsForCurrentUnit(int $windowHours, string $action): array
    {
        if ($windowHours < 1) {
            throw new InvalidArgumentException('window_hours must be positive');
        }

        $unitId = $this->context->requireUnitId();

        $this->authorization->decide(new AuthorizationRequest(
            context: $this->context,
            action: $action,
            requiresUnitScope: true,
            resourceUnitId: $unitId,
            entityType: self::ENTITY_TYPE,
            entityId: null,
        ))->assertAllowed();

        $now = $this->now();
        $from = $now->modify('-' . self::BOARD_LOOKBACK_HOURS . ' hours');
        $to = $now->modify("+{$windowHours} hours");

        $rows = [];

        foreach ($this->administrations->listBoardRows($unitId, $from, $to) as $row) {
            $performedAt = $row['performed_at'] ?? null;
            $row['timeliness'] = HospitalizationAdministration::classify(
                (string) $row['status'],
                new DateTimeImmutable((string) $row['scheduled_at']),
                $performedAt !== null ? new DateTimeImmutable((string) $performedAt) : null,
                $now,
            );
            $rows[] = $row;
        }

        return $rows;
    }

    private function now(): DateTimeImmutable
    {
        return ($this->clock)();
    }

    private function findAdministration(int $administrationId): HospitalizationAdministration
    {
        $administration = $this->administrations->findById($administrationId);

        if (!$administration instanceof HospitalizationAdministration) {
            throw new CrossTenantReferenceException(
                "administration_id {$administrationId} was not found for the authenticated tenant"
            );
        }

        return $administration;
    }

    /**
     * Loads the hospitalization within the tenant and authorizes $action
     * against its persisted system_unit_id.
     */
    private function authorizedHospitalization(int $hospitalizationId, string $action): Hospitalization
    {
        $hospitalization = $this->hospitalizations->findById($hospitalizationId);

        if (!$hospitalization instanceof Hospitalization) {
            throw new CrossTenantReferenceException(
                "hospitalization_id {$hospitalizationId} was not found for the authenticated tenant"
            );
        }

        $this->authorization->decide(new AuthorizationRequest(
            context: $this->context,
            action: $action,
            requiresUnitScope: true,
            resourceUnitId: $hospitalization->systemUnitId(),
            entityType: self::ENTITY_TYPE,
            entityId: $hospitalizationId,
        ))->assertAllowed();

        return $hospitalization;
    }

    private function assertAdmitted(Hospitalization $hospitalization): void
    {
        if ($hospitalization->status() !== Hospitalization::STATUS_ADMITTED) {
            throw new InvalidStatusTransitionException("Hospitalization {$hospitalization->id()} is not admitted");
        }
    }
}
