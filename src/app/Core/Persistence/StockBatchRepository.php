<?php

declare(strict_types=1);

namespace CentralVet\Persistence;

use CentralVet\Domain\Contract\StockBatchRepositoryInterface;
use CentralVet\Domain\StockBatch;
use CentralVet\Tenancy\TenantContext;
use DateTimeImmutable;
use InvalidArgumentException;
use PDO;

/**
 * PDO-backed persistence for the StockBatch aggregate. Every query starts
 * from TenantQuery::forTenant() (via AbstractTenantRepository::tenantQuery(),
 * ADR 0002); tenant scoping is never accepted from caller input.
 *
 * listByProductOrderedByExpiry() orders by "expiry_date IS NULL, expiry_date
 * ASC": batches with a real expiry date come first, earliest first (the
 * FEFO consumption order CentralVet\Application\StockService::consume()
 * relies on); batches with no expiry date (expiry_date NULL) sort last,
 * since they carry no expiry urgency.
 *
 * PENDING / DO NOT WIRE YET: this class depends on the `stock_batch` table
 * created by the not-yet-applied migration
 * src/app/database/migrations/20260924_0005_phase4_procedure_stock_sale.sql.
 * It is prepared and syntax-checked (php -l) only; it must not be
 * constructed with a real PDO connection nor executed against the live
 * database until that migration has explicit SQL execution approval.
 */
final class StockBatchRepository extends AbstractTenantRepository implements StockBatchRepositoryInterface
{
    public function __construct(TenantContext $context, private readonly PDO $connection)
    {
        parent::__construct($context);
    }

    public function findById(int|string $id): ?object
    {
        $query = $this->tenantQuery()->andEquals('id', (int) $id);

        $statement = $this->connection->prepare(
            "SELECT * FROM stock_batch WHERE {$query->whereSql()} LIMIT 1"
        );
        $statement->execute($query->parameters());

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : self::hydrate($row);
    }

    public function listByProductOrderedByExpiry(int $productId, int $systemUnitId): array
    {
        $query = $this->tenantQuery()
            ->andEquals('product_id', $productId)
            ->andEquals('system_unit_id', $systemUnitId);

        $statement = $this->connection->prepare(
            "SELECT * FROM stock_batch WHERE {$query->whereSql()} "
            . 'ORDER BY expiry_date IS NULL, expiry_date ASC, id ASC'
        );
        $statement->execute($query->parameters());

        $batches = [];

        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $batches[] = self::hydrate($row);
        }

        return $batches;
    }

    public function save(object $entity): object
    {
        if (!$entity instanceof StockBatch) {
            throw new InvalidArgumentException('Expected a StockBatch entity');
        }

        $this->assertEntityTenant($entity->tenantId());

        if ($entity->id() === null) {
            $statement = $this->connection->prepare(
                <<<'SQL'
                INSERT INTO stock_batch (
                    tenant_id, system_unit_id, product_id, lot, expiry_date, quantity, received_at
                ) VALUES (
                    :tenant_id, :system_unit_id, :product_id, :lot, :expiry_date, :quantity, :received_at
                )
                SQL
            );
            $statement->execute([
                ':tenant_id' => $entity->tenantId(),
                ':system_unit_id' => $entity->systemUnitId(),
                ':product_id' => $entity->productId(),
                ':lot' => $entity->lot(),
                ':expiry_date' => $entity->expiryDate()?->format('Y-m-d'),
                ':quantity' => $entity->quantity(),
                ':received_at' => $entity->receivedAt()->format('Y-m-d H:i:s.u'),
            ]);

            $entity->assignId((int) $this->connection->lastInsertId());

            return $entity;
        }

        $query = $this->tenantQuery()->andEquals('id', $entity->id());

        $statement = $this->connection->prepare(
            "UPDATE stock_batch SET quantity = :quantity WHERE {$query->whereSql()}"
        );
        $statement->execute([
            ...$query->parameters(),
            ':quantity' => $entity->quantity(),
        ]);

        return $entity;
    }

    public function remove(object $entity): void
    {
        if (!$entity instanceof StockBatch) {
            throw new InvalidArgumentException('Expected a StockBatch entity');
        }

        $id = $entity->id();

        if ($id === null) {
            return;
        }

        $this->assertEntityTenant($entity->tenantId());

        $query = $this->tenantQuery()->andEquals('id', $id);

        $statement = $this->connection->prepare("DELETE FROM stock_batch WHERE {$query->whereSql()}");
        $statement->execute($query->parameters());
    }

    /** @param array<string, mixed> $row */
    private static function hydrate(array $row): StockBatch
    {
        return StockBatch::reconstitute(
            id: (int) $row['id'],
            tenantId: (int) $row['tenant_id'],
            systemUnitId: (int) $row['system_unit_id'],
            productId: (int) $row['product_id'],
            lot: $row['lot'] !== null ? (string) $row['lot'] : null,
            expiryDate: $row['expiry_date'] !== null ? new DateTimeImmutable((string) $row['expiry_date']) : null,
            quantity: (int) $row['quantity'],
            receivedAt: new DateTimeImmutable((string) $row['received_at']),
            createdAt: $row['created_at'] !== null ? new DateTimeImmutable((string) $row['created_at']) : null,
        );
    }
}
