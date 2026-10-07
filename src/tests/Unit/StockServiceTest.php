<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Application\StockService;
use CentralVet\Domain\Exception\InsufficientStockException;
use CentralVet\Domain\StockBatch;
use CentralVet\Domain\StockMovement;
use CentralVet\Tenancy\TenantContext;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\FakeAuthorizationPolicy;
use CentralVet\Tests\Support\FakeStockBatchRepository;
use CentralVet\Tests\Support\FakeStockMovementRepository;
use DateTimeImmutable;

/**
 * Unit tests for StockService::consume() (T-03), against fake repositories
 * — no database involved, since `stock_batch`/`stock_movement` do not exist
 * yet (migration T-01 not applied).
 *
 * Covers consume()'s own documented contract (see its class docblock):
 *   - batches are drained earliest expiry_date first (FEFO), a batch fully
 *     exhausted before the next one is touched at all;
 *   - when the sum of every batch's available balance is smaller than the
 *     requested quantity, InsufficientStockException is thrown and NOTHING
 *     is written to stock_batch (quantities unchanged) or stock_movement
 *     (no rows at all) — proven, not assumed, by reloading both fakes after
 *     the throw.
 */
final class StockServiceTest
{
    private const TENANT_ID = 1;
    private const SYSTEM_UNIT_ID = 1;
    private const PRODUCT_ID = 1;
    private const PROFESSIONAL_ID = 10;

    /**
     * Two batches: batch A expires first and only carries 5 units, batch B
     * expires later and carries 10. Requesting 8 units must fully drain
     * batch A (down to 0) and take only the remaining 3 from batch B (down
     * to 7), never touching B before A is exhausted — proof of FEFO order,
     * not just total balance. Also asserts the two stock_movement rows are
     * written in that same order, one per batch actually decremented.
     */
    public function testConsumeAcrossTwoBatchesDrainsEarliestExpiryFirstAndPartiallyConsumesSecond(): void
    {
        $batches = new FakeStockBatchRepository(self::TENANT_ID);
        $movements = new FakeStockMovementRepository(self::TENANT_ID);
        $context = TenantContext::authenticated(self::TENANT_ID, 1, self::SYSTEM_UNIT_ID);
        $policy = new FakeAuthorizationPolicy(allowed: true);
        $service = new StockService($batches, $movements, $policy, $context);

        $earlierBatch = StockBatch::receive(
            tenantId: self::TENANT_ID,
            systemUnitId: self::SYSTEM_UNIT_ID,
            productId: self::PRODUCT_ID,
            lot: 'LOTE-A',
            expiryDate: new DateTimeImmutable('2026-01-01'),
            quantity: 5,
            receivedAt: new DateTimeImmutable('2025-06-01'),
        );
        $batches->save($earlierBatch);

        $laterBatch = StockBatch::receive(
            tenantId: self::TENANT_ID,
            systemUnitId: self::SYSTEM_UNIT_ID,
            productId: self::PRODUCT_ID,
            lot: 'LOTE-B',
            expiryDate: new DateTimeImmutable('2026-06-01'),
            quantity: 10,
            receivedAt: new DateTimeImmutable('2025-06-01'),
        );
        $batches->save($laterBatch);

        $service->consume(
            tenantId: self::TENANT_ID,
            systemUnitId: self::SYSTEM_UNIT_ID,
            productId: self::PRODUCT_ID,
            quantity: 8,
            reason: StockMovement::REASON_MANUAL_ADJUSTMENT,
            referenceType: null,
            referenceId: null,
            professionalSystemUserId: self::PROFESSIONAL_ID,
        );

        $reloadedEarlier = $batches->findById($earlierBatch->id());
        Assert::notNull($reloadedEarlier);
        Assert::same(0, $reloadedEarlier->quantity(), 'Earliest-expiry batch must be fully drained first');

        $reloadedLater = $batches->findById($laterBatch->id());
        Assert::notNull($reloadedLater);
        Assert::same(7, $reloadedLater->quantity(), 'Later batch only absorbs the remainder (8 - 5 = 3)');

        $productMovements = $movements->listByProduct(self::PRODUCT_ID);
        Assert::count(2, $productMovements);

        Assert::same($earlierBatch->id(), $productMovements[0]->stockBatchId());
        Assert::same(5, $productMovements[0]->quantity());
        Assert::same(StockMovement::TYPE_OUT, $productMovements[0]->movementType());

        Assert::same($laterBatch->id(), $productMovements[1]->stockBatchId());
        Assert::same(3, $productMovements[1]->quantity());
        Assert::same(StockMovement::TYPE_OUT, $productMovements[1]->movementType());
    }

    /**
     * Total available balance (4) is smaller than the requested quantity
     * (10): InsufficientStockException must be thrown carrying the exact
     * shortfall (6), and — the acceptance criterion — nothing may be
     * written to either fake: the batch's quantity is reloaded and found
     * unchanged, and no stock_movement row exists for the product at all.
     */
    public function testConsumeThrowsInsufficientStockExceptionOnShortBalanceAndPersistsNothing(): void
    {
        $batches = new FakeStockBatchRepository(self::TENANT_ID);
        $movements = new FakeStockMovementRepository(self::TENANT_ID);
        $context = TenantContext::authenticated(self::TENANT_ID, 1, self::SYSTEM_UNIT_ID);
        $policy = new FakeAuthorizationPolicy(allowed: true);
        $service = new StockService($batches, $movements, $policy, $context);

        $onlyBatch = StockBatch::receive(
            tenantId: self::TENANT_ID,
            systemUnitId: self::SYSTEM_UNIT_ID,
            productId: self::PRODUCT_ID,
            lot: 'LOTE-C',
            expiryDate: null,
            quantity: 4,
            receivedAt: new DateTimeImmutable('2025-06-01'),
        );
        $batches->save($onlyBatch);

        $caught = null;

        try {
            $service->consume(
                tenantId: self::TENANT_ID,
                systemUnitId: self::SYSTEM_UNIT_ID,
                productId: self::PRODUCT_ID,
                quantity: 10,
                reason: StockMovement::REASON_MANUAL_ADJUSTMENT,
                referenceType: null,
                referenceId: null,
                professionalSystemUserId: self::PROFESSIONAL_ID,
            );
        } catch (InsufficientStockException $exception) {
            $caught = $exception;
        }

        Assert::notNull($caught, 'Expected InsufficientStockException to be thrown');
        Assert::same(self::PRODUCT_ID, $caught->productId);
        Assert::same(6, $caught->shortfall);

        $reloadedBatch = $batches->findById($onlyBatch->id());
        Assert::notNull($reloadedBatch);
        Assert::same(4, $reloadedBatch->quantity(), 'Batch quantity must be untouched when the request is refused');

        Assert::count(0, $movements->listByProduct(self::PRODUCT_ID), 'No stock_movement row may exist when nothing was consumed');
    }

    /**
     * T-17 Correção 1: receiveBatch() authorizes the caller's own program
     * action (e.g. 'StockBatchForm::onSave'), like SaleService::create() and
     * the other Application services — not a hardcoded 'StockService::…',
     * which has no system_program and was always denied.
     */
    public function testReceiveBatchAuthorizesTheCallerAction(): void
    {
        $batches = new FakeStockBatchRepository(self::TENANT_ID);
        $movements = new FakeStockMovementRepository(self::TENANT_ID);
        $context = TenantContext::authenticated(self::TENANT_ID, 1, self::SYSTEM_UNIT_ID);
        $policy = new FakeAuthorizationPolicy(allowed: true);
        $service = new StockService($batches, $movements, $policy, $context);

        $batch = $service->receiveBatch(
            tenantId: self::TENANT_ID,
            systemUnitId: self::SYSTEM_UNIT_ID,
            productId: self::PRODUCT_ID,
            lot: 'F10-LOTE',
            expiryDate: null,
            quantity: 5,
            professionalSystemUserId: self::PROFESSIONAL_ID,
            action: 'StockBatchForm::onSave',
        );

        Assert::notNull($batch->id());
        Assert::count(1, $policy->requests);
        Assert::same('StockBatchForm::onSave', $policy->requests[0]->action());
    }
}
