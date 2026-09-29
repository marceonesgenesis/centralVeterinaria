<?php

declare(strict_types=1);

namespace CentralVet\Application;

use CentralVet\Authorization\AuthorizationRequest;
use CentralVet\Authorization\Contract\AuthorizationPolicyInterface;
use CentralVet\Domain\Contract\SaleItemRepositoryInterface;
use CentralVet\Domain\Contract\SaleRepositoryInterface;
use CentralVet\Domain\Exception\CrossTenantReferenceException;
use CentralVet\Domain\Exception\InsufficientStockException;
use CentralVet\Domain\ProcedureCatalogItem;
use CentralVet\Domain\Product;
use CentralVet\Domain\Sale;
use CentralVet\Domain\SaleItem;
use CentralVet\Domain\StockMovement;
use CentralVet\Tenancy\TenantContext;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Use cases for the Sale/PDV aggregate (T-06): ringing up a mixed cart of
 * product and procedure lines in one transaction and reading a sale back.
 *
 * Depends only on Domain contracts, sibling Application services
 * (ProductService, StockService, ProcedureCatalogService) and TenantContext
 * — no TPage or any other Adianti class (ADR 0001).
 *
 * create() takes an $action parameter, same convention as
 * AppointmentService::schedule()/VaccinationService::apply()/
 * PayableService::pay() (the caller passes __CLASS__.'::'.__FUNCTION__,
 * e.g. 'SaleForm::onSave'). T-06's original Interface spec omitted this
 * parameter and used a fixed 'SaleService::create' string instead — since
 * no real Adianti controller is ever registered under that name in
 * `system_program`, every call was unconditionally denied regardless of
 * the caller's actual permissions (found and fixed in Fase 9, navegação-
 * jornada-clínica, when this method's caller — SaleForm::onSave() — was
 * exercised end-to-end for the first time).
 *
 * --- Ordering and "no partial sale" guarantee (T-06's acceptance criteria) ---
 *
 * create() runs in four phases, in this order:
 *
 *   1. Unit-scope authorization against $systemUnitId, the caller-supplied
 *      parameter (no cross-aggregate resolution needed, unlike
 *      Vaccination/Appointment, since T-06's own Interface already takes
 *      the unit directly) — before any lookup or write. A denial throws
 *      AuthorizationDenied with nothing persisted.
 *   2. Every item in $items is resolved against its catalog — a product
 *      item against ProductService::listActive()'s result (unknown or
 *      inactive product_id is rejected the same as a nonexistent one, since
 *      an inactive product cannot be sold), a procedure item against
 *      ProcedureCatalogService::findById() — entirely read-only: no Sale,
 *      SaleItem or stock row is written during this phase. Any invalid item
 *      (unknown/inactive product, unknown procedure, bad shape) throws
 *      CrossTenantReferenceException/InvalidArgumentException here, before a
 *      single row exists. Sale::totalAmountCents is computed in this same
 *      phase as the exact sum of every resolved item's unit_price *
 *      quantity — the same numbers that become each SaleItem::totalCents()
 *      in phase 3, so the two can never drift.
 *   3. Now that every item is known valid, the Sale row and every SaleItem
 *      row are persisted.
 *   4. Stock is consumed last, one StockService::consume() call per
 *      product-type item, referencing the now-real sale id
 *      (reference_type='sale', reference_id=$sale->id()). consume() is
 *      itself all-or-nothing per product (its own docblock): it sums that
 *      product's available balance across every batch BEFORE writing
 *      anything, and throws InsufficientStockException immediately — with
 *      nothing written for that call — when the balance is short.
 *
 *      Compensating action on that failure: this class has no PDO
 *      transaction to roll back (confirmed: no beginTransaction/commit/
 *      rollBack call exists anywhere under src/app/Core — this codebase's
 *      Core layer relies on single-statement, single-aggregate writes
 *      instead), so the Sale and every SaleItem persisted in phase 3 are
 *      explicitly deleted here (via SaleItemRepositoryInterface::remove()/
 *      SaleRepositoryInterface::remove(), both part of RepositoryInterface)
 *      before InsufficientStockException is rethrown. This guarantees the
 *      acceptance criterion literally: create() never returns, and never
 *      leaves behind, a `sale` row or any `sale_item` row for a sale whose
 *      stock could not be fully consumed.
 *
 *      Documented, unavoidable-without-a-real-transaction limitation: stock
 *      already decremented by an earlier consume() call in the SAME phase-4
 *      loop (for a different product item that succeeded before the one
 *      that fails) is NOT restored — StockService (T-03, already built,
 *      out of this task's scope) exposes no compensating "un-consume"
 *      operation. Those stock_movement/stock_batch writes remain, no longer
 *      referenced by any sale (the sale row that would have pointed to them
 *      is deleted immediately above), recoverable only through a manual
 *      stock adjustment. Placing consume() before the Sale/SaleItem writes
 *      instead would not remove this specific gap — it would only move it
 *      from "orphaned stock movements" to "an orphaned Sale/SaleItem with
 *      stock never consumed for its last line", which is strictly worse
 *      since it fails the literal acceptance criterion above. Closing this
 *      gap for good requires a real database transaction wrapping the whole
 *      of create(), which does not exist anywhere in this Core layer today.
 */
final class SaleService
{
    private const ITEM_TYPE_PRODUCT = 'product';
    private const ITEM_TYPE_PROCEDURE = 'procedure';
    private const ITEM_TYPES = [self::ITEM_TYPE_PRODUCT, self::ITEM_TYPE_PROCEDURE];

    public function __construct(
        private readonly SaleRepositoryInterface $sales,
        private readonly SaleItemRepositoryInterface $saleItems,
        private readonly ProductService $products,
        private readonly StockService $stock,
        private readonly ProcedureCatalogService $procedures,
        private readonly AuthorizationPolicyInterface $authorization,
        private readonly TenantContext $context,
    ) {
    }

    /**
     * @param list<array{type: string, referenceId: int|string, quantity: int|string}> $items
     *
     * @throws InvalidArgumentException when $items is empty or malformed, or
     *         a required scalar is not positive.
     * @throws \CentralVet\Authorization\Exception\AuthorizationDenied when
     *         the active unit is missing or does not match $systemUnitId, or
     *         the caller lacks permission for $action. Nothing is persisted
     *         when this is thrown.
     * @throws CrossTenantReferenceException when a product/procedure item
     *         references a catalog entry that does not exist, is inactive
     *         (product) or belongs to another tenant. Nothing is persisted
     *         when this is thrown.
     * @throws InsufficientStockException when a product item's stock is
     *         short. Neither the Sale nor any SaleItem remains persisted
     *         when this is thrown (see class docblock for the compensating
     *         delete and its one documented limitation).
     */
    public function create(
        int $tenantId,
        int $systemUnitId,
        int $tutorId,
        ?int $patientId,
        ?int $encounterId,
        int $systemUserId,
        array $items,
        string $action,
    ): Sale {
        $this->context->assertTenant($tenantId);

        if ($systemUnitId <= 0) {
            throw new InvalidArgumentException('system_unit_id must be positive');
        }

        if ($tutorId <= 0) {
            throw new InvalidArgumentException('tutor_id must be positive');
        }

        if ($patientId !== null && $patientId <= 0) {
            throw new InvalidArgumentException('patient_id must be positive when informed');
        }

        if ($encounterId !== null && $encounterId <= 0) {
            throw new InvalidArgumentException('encounter_id must be positive when informed');
        }

        if ($systemUserId <= 0) {
            throw new InvalidArgumentException('system_user_id must be positive');
        }

        if ($items === []) {
            throw new InvalidArgumentException('items must not be empty');
        }

        // Phase 1: unit-scope authorization against the caller-supplied
        // $systemUnitId, before any lookup or write.
        $this->authorization->decide(new AuthorizationRequest(
            context: $this->context,
            action: $action,
            requiresUnitScope: true,
            resourceUnitId: $systemUnitId,
            entityType: 'sale',
            entityId: null,
        ))->assertAllowed();

        // Phase 2: resolve + validate every item, read-only.
        $activeProducts = [];

        foreach ($this->products->listActive($tenantId) as $product) {
            if ($product instanceof Product && $product->id() !== null) {
                $activeProducts[$product->id()] = $product;
            }
        }

        $resolved = [];
        $totalAmountCents = 0;

        foreach ($items as $index => $item) {
            [$type, $referenceId, $quantity] = self::readItem($item, $index);

            if ($type === self::ITEM_TYPE_PRODUCT) {
                $product = $activeProducts[$referenceId] ?? null;

                if (!$product instanceof Product) {
                    throw new CrossTenantReferenceException(
                        "item #{$index}: product referenceId {$referenceId} was not found or "
                        . 'is not active for the authenticated tenant'
                    );
                }

                $description = $product->name();
                $unitPriceCents = $product->unitCostCents();
            } else {
                $procedure = $this->procedures->findById($referenceId);

                if (!$procedure instanceof ProcedureCatalogItem) {
                    throw new CrossTenantReferenceException(
                        "item #{$index}: procedure referenceId {$referenceId} was not found "
                        . 'for the authenticated tenant'
                    );
                }

                $description = $procedure->name();
                $unitPriceCents = $procedure->priceCents();
            }

            $totalCents = $unitPriceCents * $quantity;
            $totalAmountCents += $totalCents;

            $resolved[] = [
                'type' => $type,
                'referenceId' => $referenceId,
                'quantity' => $quantity,
                'description' => $description,
                'unitPriceCents' => $unitPriceCents,
                'totalCents' => $totalCents,
            ];
        }

        // Phase 3: persist Sale + every SaleItem now that all items are
        // known valid. $totalAmountCents is the exact sum computed above,
        // from the same per-item totals that become each SaleItem's
        // totalCents() below.
        $sale = Sale::create(
            tenantId: $tenantId,
            systemUnitId: $systemUnitId,
            tutorId: $tutorId,
            patientId: $patientId,
            encounterId: $encounterId,
            systemUserId: $systemUserId,
            totalAmountCents: $totalAmountCents,
            soldAt: new DateTimeImmutable(),
        );

        /** @var Sale $savedSale */
        $savedSale = $this->sales->save($sale);
        $saleId = (int) $savedSale->id();

        /** @var list<SaleItem> $savedItems */
        $savedItems = [];

        foreach ($resolved as $entry) {
            $saleItem = SaleItem::create(
                tenantId: $tenantId,
                saleId: $saleId,
                itemType: $entry['type'],
                itemReferenceId: $entry['referenceId'],
                descriptionText: $entry['description'],
                unitPriceCents: $entry['unitPriceCents'],
                quantity: $entry['quantity'],
            );

            /** @var SaleItem $savedItem */
            $savedItem = $this->saleItems->save($saleItem);
            $savedItems[] = $savedItem;
        }

        // Phase 4: consume stock last, one call per product-type item, in
        // item order. See class docblock for the compensating delete below
        // and its one documented limitation.
        try {
            foreach ($resolved as $entry) {
                if ($entry['type'] !== self::ITEM_TYPE_PRODUCT) {
                    continue;
                }

                $this->stock->consume(
                    tenantId: $tenantId,
                    systemUnitId: $systemUnitId,
                    productId: $entry['referenceId'],
                    quantity: $entry['quantity'],
                    reason: StockMovement::REASON_SALE_CONSUMPTION,
                    referenceType: 'sale',
                    referenceId: $saleId,
                    professionalSystemUserId: $systemUserId,
                );
            }
        } catch (InsufficientStockException $exception) {
            foreach ($savedItems as $savedItem) {
                $this->saleItems->remove($savedItem);
            }

            $this->sales->remove($savedSale);

            throw $exception;
        }

        return $savedSale;
    }

    public function findById(int $id): ?Sale
    {
        /** @var Sale|null $sale */
        $sale = $this->sales->findById($id);

        return $sale;
    }

    /**
     * Parses and validates one raw $items entry into [type, referenceId,
     * quantity]. Kept separate from create()'s main loop so the required-key
     * / type / positivity checks read as one block.
     *
     * @return array{0: string, 1: int, 2: int}
     */
    private static function readItem(mixed $item, int|string $index): array
    {
        if (!is_array($item)) {
            throw new InvalidArgumentException("item #{$index} must be an array");
        }

        foreach (['type', 'referenceId', 'quantity'] as $key) {
            if (!array_key_exists($key, $item)) {
                throw new InvalidArgumentException("item #{$index}.{$key} is required");
            }
        }

        $type = (string) $item['type'];

        if (!in_array($type, self::ITEM_TYPES, true)) {
            throw new InvalidArgumentException("item #{$index}.type must be 'product' or 'procedure'");
        }

        $referenceId = (int) $item['referenceId'];
        $quantity = (int) $item['quantity'];

        if ($referenceId <= 0) {
            throw new InvalidArgumentException("item #{$index}.referenceId must be positive");
        }

        if ($quantity < 1) {
            throw new InvalidArgumentException("item #{$index}.quantity must be >= 1");
        }

        return [$type, $referenceId, $quantity];
    }
}
