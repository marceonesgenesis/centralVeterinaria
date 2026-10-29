<?php

declare(strict_types=1);

namespace CentralVet\Tests\Support;

use CentralVet\Domain\Contract\StockBatchRepositoryInterface;
use CentralVet\Domain\StockBatch;
use InvalidArgumentException;

/**
 * In-memory double for StockBatchRepositoryInterface (T-03): the
 * `stock_batch` table does not exist yet (migration T-01 not applied), so
 * StockServiceTest/ProcedureExecutionServiceTest/SaleServiceTest exercise
 * their services against this instead of a real database. Tenant-scoped
 * like the real StockBatchRepository (ADR 0002): findById() only ever
 * returns a batch whose tenantId() matches this instance's own $tenantId.
 *
 * listByProductOrderedByExpiry() replicates the real
 * CentralVet\Persistence\StockBatchRepository's exact ordering ("expiry_date
 * IS NULL, expiry_date ASC, id ASC" — batches with a real expiry date first,
 * earliest first, batches with no expiry date last), which is what
 * StockService::consume()'s FEFO (first-expired, first-out) guarantee
 * depends on.
 */
final class FakeStockBatchRepository implements StockBatchRepositoryInterface
{
    /** @var array<int, StockBatch> */
    private array $batches = [];
    private int $nextId = 1;

    /** @var array<int, true> batch ids whose next decreaseQuantity() is refused */
    private array $refusedDecreases = [];

    public function __construct(private readonly int $tenantId, StockBatch ...$seed)
    {
        foreach ($seed as $batch) {
            $this->save($batch);
        }
    }

    public function tenantId(): int
    {
        return $this->tenantId;
    }

    public function findById(int|string $id): ?object
    {
        $batch = $this->batches[(int) $id] ?? null;

        if ($batch === null || $batch->tenantId() !== $this->tenantId) {
            return null;
        }

        return $batch;
    }

    /** @return list<StockBatch> */
    public function listByProductOrderedByExpiry(int $productId, int $systemUnitId): array
    {
        $matches = array_values(array_filter(
            $this->batches,
            fn (StockBatch $batch): bool => $batch->tenantId() === $this->tenantId
                && $batch->productId() === $productId
                && $batch->systemUnitId() === $systemUnitId,
        ));

        usort($matches, static function (StockBatch $a, StockBatch $b): int {
            $aExpiry = $a->expiryDate();
            $bExpiry = $b->expiryDate();

            if ($aExpiry === null && $bExpiry !== null) {
                return 1;
            }

            if ($aExpiry !== null && $bExpiry === null) {
                return -1;
            }

            if ($aExpiry !== null && $bExpiry !== null) {
                $cmp = $aExpiry <=> $bExpiry;

                if ($cmp !== 0) {
                    return $cmp;
                }
            }

            return $a->id() <=> $b->id();
        });

        return $matches;
    }

    /**
     * No transaction exists in memory: same result as
     * listByProductOrderedByExpiry().
     *
     * @return list<StockBatch>
     */
    public function lockByProductOrderedByExpiry(int $productId, int $systemUnitId): array
    {
        return $this->listByProductOrderedByExpiry($productId, $systemUnitId);
    }

    public function decreaseQuantity(int $batchId, int $quantity): bool
    {
        if (isset($this->refusedDecreases[$batchId])) {
            unset($this->refusedDecreases[$batchId]);

            return false;
        }

        $batch = $this->findById($batchId);

        if (!$batch instanceof StockBatch || $batch->quantity() < $quantity) {
            return false;
        }

        $batch->decrease($quantity);

        return true;
    }

    public function increaseQuantity(int $batchId, int $quantity): void
    {
        $batch = $this->findById($batchId);

        if (!$batch instanceof StockBatch) {
            return;
        }

        $this->batches[$batchId] = StockBatch::reconstitute(
            id: $batchId,
            tenantId: $batch->tenantId(),
            systemUnitId: $batch->systemUnitId(),
            productId: $batch->productId(),
            lot: $batch->lot(),
            expiryDate: $batch->expiryDate(),
            quantity: $batch->quantity() + $quantity,
            receivedAt: $batch->receivedAt(),
            createdAt: $batch->createdAt(),
        );
    }

    /**
     * Simulates a concurrent consumer draining the batch between the
     * locked read and the conditional UPDATE: the next decreaseQuantity()
     * of $batchId returns false.
     */
    public function refuseNextDecrease(int $batchId): void
    {
        $this->refusedDecreases[$batchId] = true;
    }

    public function save(object $entity): object
    {
        if (!$entity instanceof StockBatch) {
            throw new InvalidArgumentException('FakeStockBatchRepository only stores StockBatch entities');
        }

        if ($entity->id() === null) {
            $entity->assignId($this->nextId++);
        }

        $this->batches[$entity->id()] = $entity;

        return $entity;
    }

    public function remove(object $entity): void
    {
        if ($entity instanceof StockBatch && $entity->id() !== null) {
            unset($this->batches[$entity->id()]);
        }
    }
}
