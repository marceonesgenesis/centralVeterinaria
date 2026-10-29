<?php

declare(strict_types=1);

namespace CentralVet\Application;

use CentralVet\Authorization\AuthorizationRequest;
use CentralVet\Authorization\Contract\AuthorizationPolicyInterface;
use CentralVet\Domain\Bed;
use CentralVet\Domain\Contract\BedRepositoryInterface;
use CentralVet\Domain\Contract\HospitalizationAdministrationRepositoryInterface;
use CentralVet\Domain\Contract\HospitalizationEventRepositoryInterface;
use CentralVet\Domain\Contract\HospitalizationOrderRepositoryInterface;
use CentralVet\Domain\Contract\HospitalizationRepositoryInterface;
use CentralVet\Domain\Contract\ProductRepositoryInterface;
use CentralVet\Domain\EncounterAccount;
use CentralVet\Domain\EncounterAccountItem;
use CentralVet\Domain\Exception\CrossTenantReferenceException;
use CentralVet\Domain\Exception\InvalidStatusTransitionException;
use CentralVet\Domain\Hospitalization;
use CentralVet\Domain\HospitalizationAdministration;
use CentralVet\Domain\HospitalizationEvent;
use CentralVet\Domain\HospitalizationOrder;
use CentralVet\Domain\Product;
use CentralVet\Domain\StockMovement;
use CentralVet\Tenancy\TenantContext;
use Closure;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Integrated discharge (T-11): crosses hospitalization, the encounter
 * account (Phase 5) and stock (Phase 4). Consumes the products of every
 * performed administration, bills the stay and the administrations,
 * cancels what is still pending, discharges, releases the bed and records
 * the discharge event.
 *
 * Opens no transaction itself: the caller (HospitalizationView::onDischarge)
 * wraps the call in a single TTransaction so any exception — insufficient
 * stock, closed account, bed already released — rolls everything back.
 * Checks that can fail without touching data (status, account open,
 * products resolvable) run before the first write.
 */
final class HospitalizationDischargeService
{
    private readonly Closure $clock;

    public function __construct(
        private readonly HospitalizationRepositoryInterface $hospitalizations,
        private readonly BedRepositoryInterface $beds,
        private readonly HospitalizationOrderRepositoryInterface $orders,
        private readonly HospitalizationAdministrationRepositoryInterface $administrations,
        private readonly HospitalizationEventRepositoryInterface $events,
        private readonly ProductRepositoryInterface $products,
        private readonly EncounterAccountService $accounts,
        private readonly StockService $stock,
        private readonly AuthorizationPolicyInterface $authorization,
        private readonly TenantContext $context,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): DateTimeImmutable => new DateTimeImmutable();
    }

    /**
     * @return array{account_id: int, items_added: int, consumed_products: int, billable_days: int}
     *
     * @throws CrossTenantReferenceException when the hospitalization, its bed
     *         or a referenced product does not resolve within the tenant.
     * @throws InvalidStatusTransitionException when the hospitalization is
     *         not admitted or the encounter account is not open.
     * @throws \CentralVet\Domain\Exception\InsufficientStockException when a
     *         consumed product lacks stock at the unit.
     */
    public function discharge(int $hospitalizationId, string $summaryText, string $action): array
    {
        if ($hospitalizationId <= 0) {
            throw new InvalidArgumentException('hospitalization_id must be positive');
        }

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
            entityType: 'hospitalization',
            entityId: $hospitalizationId,
        ))->assertAllowed();

        if ($hospitalization->status() !== Hospitalization::STATUS_ADMITTED) {
            throw new InvalidStatusTransitionException("Hospitalization {$hospitalizationId} is not admitted");
        }

        $account = $this->accounts->openOrGet($hospitalization->encounterId(), $action);

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

        $bed = $this->beds->findById($hospitalization->bedId());

        if (!$bed instanceof Bed) {
            throw new CrossTenantReferenceException(
                "bed_id {$hospitalization->bedId()} was not found for the authenticated tenant"
            );
        }

        /** @var array<int, HospitalizationOrder> $ordersById */
        $ordersById = [];

        foreach ($this->orders->listByHospitalization($hospitalizationId) as $order) {
            /** @var HospitalizationOrder $order */
            $ordersById[(int) $order->id()] = $order;
        }

        /** @var list<array{administration: HospitalizationAdministration, product: Product, quantity: int}> $billable */
        $billable = [];
        /** @var array<int, int> $quantityByProduct */
        $quantityByProduct = [];
        /** @var array<int, Product> $productsById */
        $productsById = [];
        /** @var list<HospitalizationAdministration> $pending */
        $pending = [];

        foreach ($this->administrations->listByHospitalization($hospitalizationId) as $administration) {
            /** @var HospitalizationAdministration $administration */
            if ($administration->status() === HospitalizationAdministration::STATUS_PENDING) {
                $pending[] = $administration;
                continue;
            }

            if ($administration->status() !== HospitalizationAdministration::STATUS_DONE) {
                continue;
            }

            $order = $ordersById[$administration->orderId()] ?? null;
            $productId = $order?->productId();
            $quantity = (int) ($order?->quantityPerAdministration() ?? 0);

            if ($productId === null || $quantity <= 0) {
                continue;
            }

            if (!isset($productsById[$productId])) {
                $product = $this->products->findById($productId);

                if (!$product instanceof Product) {
                    throw new CrossTenantReferenceException(
                        "product_id {$productId} was not found for the authenticated tenant"
                    );
                }

                $productsById[$productId] = $product;
            }

            $quantityByProduct[$productId] = ($quantityByProduct[$productId] ?? 0) + $quantity;
            $billable[] = [
                'administration' => $administration,
                'product' => $productsById[$productId],
                'quantity' => $quantity,
            ];
        }

        foreach ($quantityByProduct as $productId => $quantity) {
            $this->stock->consume(
                $this->context->tenantId(),
                $hospitalization->systemUnitId(),
                $productId,
                $quantity,
                StockMovement::REASON_HOSPITALIZATION_CONSUMPTION,
                'hospitalization',
                $hospitalizationId,
                $userId,
            );
        }

        $itemsAdded = 0;
        $billableDays = $hospitalization->billableDays($now);
        $stayCents = $billableDays * $hospitalization->dailyRateCents();

        if ($stayCents > 0) {
            $item = $this->accounts->addSourcedItem(
                $accountId,
                EncounterAccountItem::TYPE_HOSPITALIZATION_STAY,
                $hospitalizationId,
                sprintf('Internação — %d diária(s) (leito %s)', $billableDays, $bed->code()),
                $stayCents,
                $action,
            );
            $itemsAdded += $item !== null ? 1 : 0;
        }

        foreach ($billable as $line) {
            $administration = $line['administration'];
            $amountCents = (int) ($line['product']->salePriceCents() ?? 0) * $line['quantity'];

            if ($amountCents <= 0) {
                continue;
            }

            $performedAt = $administration->performedAt() ?? $administration->scheduledAt();
            $item = $this->accounts->addSourcedItem(
                $accountId,
                EncounterAccountItem::TYPE_HOSPITALIZATION_ADMINISTRATION,
                (int) $administration->id(),
                $line['product']->name() . ' — ' . $performedAt->format('d/m/Y H:i'),
                $amountCents,
                $action,
            );
            $itemsAdded += $item !== null ? 1 : 0;
        }

        foreach ($pending as $administration) {
            $administration->cancel();
            $this->administrations->save($administration);
        }

        $hospitalization->discharge($now, $userId, $summaryText);
        $this->hospitalizations->save($hospitalization);

        if (!$this->beds->release($hospitalization->bedId(), $hospitalizationId)) {
            throw new InvalidStatusTransitionException(
                "Bed {$hospitalization->bedId()} is not occupied by hospitalization {$hospitalizationId}"
            );
        }

        $this->events->save(HospitalizationEvent::record(
            $this->context->tenantId(),
            $hospitalizationId,
            HospitalizationEvent::TYPE_DISCHARGE,
            $userId,
            $now,
            $summaryText,
        ));

        return [
            'account_id' => $accountId,
            'items_added' => $itemsAdded,
            'consumed_products' => count($quantityByProduct),
            'billable_days' => $billableDays,
        ];
    }
}
