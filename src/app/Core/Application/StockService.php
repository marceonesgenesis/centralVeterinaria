<?php

declare(strict_types=1);

namespace CentralVet\Application;

use CentralVet\Authorization\AuthorizationRequest;
use CentralVet\Authorization\Contract\AuthorizationPolicyInterface;
use CentralVet\Domain\Contract\StockBatchRepositoryInterface;
use CentralVet\Domain\Contract\StockMovementRepositoryInterface;
use CentralVet\Domain\Exception\InsufficientStockException;
use CentralVet\Domain\StockBatch;
use CentralVet\Domain\StockMovement;
use CentralVet\Tenancy\TenantContext;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Use cases for stock movement (T-03): receiving a new physical batch of a
 * product at a system unit, and consuming a quantity of a product from that
 * unit's batches in validity order (earliest expiry_date first, i.e. FEFO —
 * first-expired, first-out). Conceptually the same idea as
 * VaccinationService::apply()'s stock decrement (rule 2 there), generalized
 * from a single running counter into a batch/lot ledger, since Product here
 * is tracked as real physical lots (CentralVet\Domain\StockBatch) instead
 * of one counter.
 *
 * consume() is the operation T-05 (ProcedureExecutionService::execute())
 * and T-06 (SaleService::create()) each call once per consumed product, so
 * its all-or-nothing behavior on InsufficientStockException (documented on
 * the method itself) is what lets those two services keep their own
 * "nothing is written when any input is short" guarantee without
 * re-implementing it.
 *
 * $tenantId is accepted explicitly on every method, matching this task's
 * own Interface spec (tasks.md T-03), but is always cross-checked against
 * the injected TenantContext via TenantContext::assertTenant() before
 * touching either repository. CentralVet\Domain\Contract\
 * StockBatchRepositoryInterface/StockMovementRepositoryInterface take no
 * tenantId parameter on any method — same pattern as
 * TutorRepositoryInterface/ServiceRepositoryInterface — and resolve their
 * own tenant scope from the TenantContext injected into the concrete
 * Repository (ADR 0002). So $tenantId here can never widen that scope, only
 * be rejected when it disagrees with it.
 */
final class StockService
{
    public function __construct(
        private readonly StockBatchRepositoryInterface $batches,
        private readonly StockMovementRepositoryInterface $movements,
        private readonly AuthorizationPolicyInterface $authorization,
        private readonly TenantContext $context,
    ) {
    }

    public function receiveBatch(
        int $tenantId,
        int $systemUnitId,
        int $productId,
        ?string $lot,
        ?string $expiryDate,
        int $quantity,
        int $professionalSystemUserId,
    ): StockBatch {
        $this->context->assertTenant($tenantId);

        // Unit-scope authorization against the caller-supplied
        // $systemUnitId, before any read/write — same pattern as
        // ProcedureExecutionService::execute()/SaleService::create(). A
        // denial throws AuthorizationDenied with nothing persisted.
        $this->authorization->decide(new AuthorizationRequest(
            context: $this->context,
            action: 'StockService::receiveBatch',
            requiresUnitScope: true,
            resourceUnitId: $systemUnitId,
            entityType: 'stock_batch',
            entityId: null,
        ))->assertAllowed();

        $batch = StockBatch::receive(
            tenantId: $tenantId,
            systemUnitId: $systemUnitId,
            productId: $productId,
            lot: $lot,
            expiryDate: $expiryDate !== null && $expiryDate !== '' ? new DateTimeImmutable($expiryDate) : null,
            quantity: $quantity,
            receivedAt: new DateTimeImmutable(),
        );

        /** @var StockBatch $savedBatch */
        $savedBatch = $this->batches->save($batch);

        $movement = StockMovement::record(
            tenantId: $tenantId,
            systemUnitId: $systemUnitId,
            productId: $productId,
            stockBatchId: (int) $savedBatch->id(),
            movementType: StockMovement::TYPE_IN,
            quantity: $quantity,
            reason: StockMovement::REASON_PURCHASE_ENTRY,
            referenceType: null,
            referenceId: null,
            professionalSystemUserId: $professionalSystemUserId,
        );
        $this->movements->save($movement);

        return $savedBatch;
    }

    /**
     * Consumes $quantity units of $productId from $systemUnitId's stock
     * batches, earliest expiry_date first (FEFO), until the requested
     * amount is fully covered, persisting one stock_movement row per batch
     * it actually decremented.
     *
     * All-or-nothing: the total available balance across every one of the
     * product's batches in this unit is summed BEFORE any batch is
     * touched. If that sum is smaller than $quantity,
     * InsufficientStockException is thrown immediately and nothing is
     * written to stock_batch or stock_movement. Only once that check
     * passes does the method start decrementing batches earliest-expiry
     * first, saving the batch and writing a stock_movement row for each
     * one it drains (fully or partially), until $quantity is exhausted.
     *
     * @throws InsufficientStockException when the sum of available batch
     *         balances is smaller than $quantity; carries productId and
     *         the shortfall amount. Nothing is persisted.
     */
    public function consume(
        int $tenantId,
        int $systemUnitId,
        int $productId,
        int $quantity,
        string $reason,
        ?string $referenceType,
        ?int $referenceId,
        int $professionalSystemUserId,
    ): void {
        $this->context->assertTenant($tenantId);

        if ($quantity < 1) {
            throw new InvalidArgumentException('quantity must be >= 1');
        }

        /** @var list<StockBatch> $batches */
        $batches = $this->batches->listByProductOrderedByExpiry($productId, $systemUnitId);

        $available = 0;

        foreach ($batches as $batch) {
            $available += $batch->quantity();
        }

        if ($available < $quantity) {
            throw InsufficientStockException::forShortfall($productId, $quantity - $available);
        }

        $remaining = $quantity;

        foreach ($batches as $batch) {
            if ($remaining <= 0) {
                break;
            }

            if ($batch->quantity() <= 0) {
                continue;
            }

            $taken = min($batch->quantity(), $remaining);
            $batch->decrease($taken);
            $this->batches->save($batch);

            $movement = StockMovement::record(
                tenantId: $tenantId,
                systemUnitId: $systemUnitId,
                productId: $productId,
                stockBatchId: (int) $batch->id(),
                movementType: StockMovement::TYPE_OUT,
                quantity: $taken,
                reason: $reason,
                referenceType: $referenceType,
                referenceId: $referenceId,
                professionalSystemUserId: $professionalSystemUserId,
            );
            $this->movements->save($movement);

            $remaining -= $taken;
        }
    }
}
