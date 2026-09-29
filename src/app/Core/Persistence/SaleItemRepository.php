<?php

declare(strict_types=1);

namespace CentralVet\Persistence;

use CentralVet\Domain\Contract\SaleItemRepositoryInterface;
use CentralVet\Domain\SaleItem;
use CentralVet\Tenancy\TenantContext;
use DateTimeImmutable;
use InvalidArgumentException;
use PDO;

/**
 * PDO-backed persistence for the SaleItem aggregate. Every query starts from
 * TenantQuery::forTenant() (via AbstractTenantRepository::tenantQuery(),
 * ADR 0002); tenant scoping is never accepted from caller input.
 *
 * PENDING / DO NOT WIRE YET: this class depends on the `sale_item` table
 * created by the not-yet-applied migration
 * src/app/database/migrations/20260924_0005_phase4_procedure_stock_sale.sql.
 * It is prepared and syntax-checked (php -l) only; it must not be
 * constructed with a real PDO connection nor executed against the live
 * database until that migration has explicit SQL execution approval.
 *
 * @implements SaleItemRepositoryInterface<SaleItem>
 */
final class SaleItemRepository extends AbstractTenantRepository implements SaleItemRepositoryInterface
{
    public function __construct(TenantContext $context, private readonly PDO $connection)
    {
        parent::__construct($context);
    }

    public function findById(int|string $id): ?object
    {
        $query = $this->tenantQuery()->andEquals('id', (int) $id);

        $statement = $this->connection->prepare(
            "SELECT * FROM sale_item WHERE {$query->whereSql()} LIMIT 1"
        );
        $statement->execute($query->parameters());

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : self::hydrate($row);
    }

    /**
     * Used by SaleService::create() to delete the items it just wrote when a
     * later product item turns out to be short on stock (its compensating
     * action for the absence of a real DB transaction around create() — see
     * that method's docblock), and by any future receipt/detail screen.
     */
    public function listBySale(int $saleId): array
    {
        $query = $this->tenantQuery()->andEquals('sale_id', $saleId);

        $statement = $this->connection->prepare(
            "SELECT * FROM sale_item WHERE {$query->whereSql()} ORDER BY id ASC"
        );
        $statement->execute($query->parameters());

        $items = [];

        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $items[] = self::hydrate($row);
        }

        return $items;
    }

    public function save(object $entity): object
    {
        if (!$entity instanceof SaleItem) {
            throw new InvalidArgumentException('Expected a SaleItem entity');
        }

        $this->assertEntityTenant($entity->tenantId());

        if ($entity->id() !== null) {
            // SaleItem is immutable once persisted (a sale line, once rung
            // up, is never edited in place — cancelling/adjusting a sale is
            // out of T-06's scope): save() only ever inserts.
            throw new InvalidArgumentException('Sale items are immutable once persisted');
        }

        $statement = $this->connection->prepare(
            <<<'SQL'
            INSERT INTO sale_item (
                tenant_id, sale_id, item_type, item_reference_id, description_text,
                unit_price_cents, quantity, total_cents
            ) VALUES (
                :tenant_id, :sale_id, :item_type, :item_reference_id, :description_text,
                :unit_price_cents, :quantity, :total_cents
            )
            SQL
        );
        $statement->execute([
            ':tenant_id' => $entity->tenantId(),
            ':sale_id' => $entity->saleId(),
            ':item_type' => $entity->itemType(),
            ':item_reference_id' => $entity->itemReferenceId(),
            ':description_text' => $entity->descriptionText(),
            ':unit_price_cents' => $entity->unitPriceCents(),
            ':quantity' => $entity->quantity(),
            ':total_cents' => $entity->totalCents(),
        ]);

        $entity->assignId((int) $this->connection->lastInsertId());

        return $entity;
    }

    public function remove(object $entity): void
    {
        if (!$entity instanceof SaleItem) {
            throw new InvalidArgumentException('Expected a SaleItem entity');
        }

        $id = $entity->id();

        if ($id === null) {
            return;
        }

        $this->assertEntityTenant($entity->tenantId());

        $query = $this->tenantQuery()->andEquals('id', $id);

        $statement = $this->connection->prepare("DELETE FROM sale_item WHERE {$query->whereSql()}");
        $statement->execute($query->parameters());
    }

    /** @param array<string, mixed> $row */
    private static function hydrate(array $row): SaleItem
    {
        return SaleItem::reconstitute(
            id: (int) $row['id'],
            tenantId: (int) $row['tenant_id'],
            saleId: (int) $row['sale_id'],
            itemType: (string) $row['item_type'],
            itemReferenceId: (int) $row['item_reference_id'],
            descriptionText: (string) $row['description_text'],
            unitPriceCents: (int) $row['unit_price_cents'],
            quantity: (int) $row['quantity'],
            totalCents: (int) $row['total_cents'],
            createdAt: $row['created_at'] !== null ? new DateTimeImmutable((string) $row['created_at']) : null,
        );
    }
}
