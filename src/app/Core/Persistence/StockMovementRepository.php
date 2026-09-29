<?php

declare(strict_types=1);

namespace CentralVet\Persistence;

use CentralVet\Domain\Contract\StockMovementRepositoryInterface;
use CentralVet\Domain\StockMovement;
use CentralVet\Tenancy\TenantContext;
use DateTimeImmutable;
use InvalidArgumentException;
use PDO;

/**
 * PDO-backed persistence for the StockMovement aggregate: an append-only
 * ledger, so save() is expected to always insert a brand-new row (a
 * movement is never edited after creation, per the migration's own
 * documentation). The update branch below exists only to keep the same
 * save()-does-insert-or-update shape as every other
 * AbstractTenantRepository implementation in this codebase; it is not
 * exercised by CentralVet\Application\StockService.
 *
 * Every query starts from TenantQuery::forTenant() (via
 * AbstractTenantRepository::tenantQuery(), ADR 0002); tenant scoping is
 * never accepted from caller input.
 *
 * PENDING / DO NOT WIRE YET: this class depends on the `stock_movement`
 * table created by the not-yet-applied migration
 * src/app/database/migrations/20260924_0005_phase4_procedure_stock_sale.sql.
 * It is prepared and syntax-checked (php -l) only; it must not be
 * constructed with a real PDO connection nor executed against the live
 * database until that migration has explicit SQL execution approval.
 */
final class StockMovementRepository extends AbstractTenantRepository implements StockMovementRepositoryInterface
{
    public function __construct(TenantContext $context, private readonly PDO $connection)
    {
        parent::__construct($context);
    }

    public function findById(int|string $id): ?object
    {
        $query = $this->tenantQuery()->andEquals('id', (int) $id);

        $statement = $this->connection->prepare(
            "SELECT * FROM stock_movement WHERE {$query->whereSql()} LIMIT 1"
        );
        $statement->execute($query->parameters());

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : self::hydrate($row);
    }

    public function listByProduct(int $productId): array
    {
        $query = $this->tenantQuery()->andEquals('product_id', $productId);

        $statement = $this->connection->prepare(
            "SELECT * FROM stock_movement WHERE {$query->whereSql()} ORDER BY created_at ASC, id ASC"
        );
        $statement->execute($query->parameters());

        $movements = [];

        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $movements[] = self::hydrate($row);
        }

        return $movements;
    }

    public function save(object $entity): object
    {
        if (!$entity instanceof StockMovement) {
            throw new InvalidArgumentException('Expected a StockMovement entity');
        }

        $this->assertEntityTenant($entity->tenantId());

        if ($entity->id() === null) {
            $statement = $this->connection->prepare(
                <<<'SQL'
                INSERT INTO stock_movement (
                    tenant_id, system_unit_id, product_id, stock_batch_id, movement_type,
                    quantity, reason, reference_type, reference_id, professional_system_user_id
                ) VALUES (
                    :tenant_id, :system_unit_id, :product_id, :stock_batch_id, :movement_type,
                    :quantity, :reason, :reference_type, :reference_id, :professional_system_user_id
                )
                SQL
            );
            $statement->execute([
                ':tenant_id' => $entity->tenantId(),
                ':system_unit_id' => $entity->systemUnitId(),
                ':product_id' => $entity->productId(),
                ':stock_batch_id' => $entity->stockBatchId(),
                ':movement_type' => $entity->movementType(),
                ':quantity' => $entity->quantity(),
                ':reason' => $entity->reason(),
                ':reference_type' => $entity->referenceType(),
                ':reference_id' => $entity->referenceId(),
                ':professional_system_user_id' => $entity->professionalSystemUserId(),
            ]);

            $entity->assignId((int) $this->connection->lastInsertId());

            return $entity;
        }

        $query = $this->tenantQuery()->andEquals('id', $entity->id());

        $statement = $this->connection->prepare(
            "UPDATE stock_movement SET quantity = :quantity, reason = :reason, "
            . "reference_type = :reference_type, reference_id = :reference_id "
            . "WHERE {$query->whereSql()}"
        );
        $statement->execute([
            ...$query->parameters(),
            ':quantity' => $entity->quantity(),
            ':reason' => $entity->reason(),
            ':reference_type' => $entity->referenceType(),
            ':reference_id' => $entity->referenceId(),
        ]);

        return $entity;
    }

    public function remove(object $entity): void
    {
        if (!$entity instanceof StockMovement) {
            throw new InvalidArgumentException('Expected a StockMovement entity');
        }

        $id = $entity->id();

        if ($id === null) {
            return;
        }

        $this->assertEntityTenant($entity->tenantId());

        $query = $this->tenantQuery()->andEquals('id', $id);

        $statement = $this->connection->prepare("DELETE FROM stock_movement WHERE {$query->whereSql()}");
        $statement->execute($query->parameters());
    }

    /** @param array<string, mixed> $row */
    private static function hydrate(array $row): StockMovement
    {
        return StockMovement::reconstitute(
            id: (int) $row['id'],
            tenantId: (int) $row['tenant_id'],
            systemUnitId: (int) $row['system_unit_id'],
            productId: (int) $row['product_id'],
            stockBatchId: (int) $row['stock_batch_id'],
            movementType: (string) $row['movement_type'],
            quantity: (int) $row['quantity'],
            reason: (string) $row['reason'],
            referenceType: $row['reference_type'] !== null ? (string) $row['reference_type'] : null,
            referenceId: $row['reference_id'] !== null ? (int) $row['reference_id'] : null,
            professionalSystemUserId: (int) $row['professional_system_user_id'],
            createdAt: $row['created_at'] !== null ? new DateTimeImmutable((string) $row['created_at']) : null,
        );
    }
}
