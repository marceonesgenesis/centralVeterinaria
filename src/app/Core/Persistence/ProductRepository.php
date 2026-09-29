<?php

declare(strict_types=1);

namespace CentralVet\Persistence;

use CentralVet\Domain\Contract\ProductRepositoryInterface;
use CentralVet\Domain\Product;
use CentralVet\Tenancy\TenantContext;
use DateTimeImmutable;
use InvalidArgumentException;
use PDO;

/**
 * PDO-backed persistence for the Product aggregate. Every query starts from
 * TenantQuery::forTenant() (via AbstractTenantRepository::tenantQuery(),
 * ADR 0002); tenant scoping is never accepted from caller input.
 *
 * PENDING / DO NOT WIRE YET: this class depends on the `product` table
 * created by the not-yet-applied migration
 * src/app/database/migrations/20260924_0005_phase4_procedure_stock_sale.sql.
 * It is prepared and syntax-checked (php -l) only; it must not be
 * constructed with a real PDO connection nor executed against the live
 * database until that migration has explicit SQL execution approval.
 */
final class ProductRepository extends AbstractTenantRepository implements ProductRepositoryInterface
{
    public function __construct(TenantContext $context, private readonly PDO $connection)
    {
        parent::__construct($context);
    }

    public function findById(int|string $id): ?object
    {
        $query = $this->tenantQuery()->andEquals('id', (int) $id);

        $statement = $this->connection->prepare(
            "SELECT * FROM product WHERE {$query->whereSql()} LIMIT 1"
        );
        $statement->execute($query->parameters());

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : self::hydrate($row);
    }

    public function findActive(): array
    {
        $query = $this->tenantQuery()->andEquals('active', 1);

        $statement = $this->connection->prepare(
            "SELECT * FROM product WHERE {$query->whereSql()} ORDER BY name ASC"
        );
        $statement->execute($query->parameters());

        $products = [];

        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $products[] = self::hydrate($row);
        }

        return $products;
    }

    public function findByName(string $name): ?object
    {
        $query = $this->tenantQuery()->andEquals('name', $name);

        $statement = $this->connection->prepare(
            "SELECT * FROM product WHERE {$query->whereSql()} LIMIT 1"
        );
        $statement->execute($query->parameters());

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : self::hydrate($row);
    }

    public function save(object $entity): object
    {
        if (!$entity instanceof Product) {
            throw new InvalidArgumentException('Expected a Product entity');
        }

        $this->assertEntityTenant($entity->tenantId());

        if ($entity->id() === null) {
            $statement = $this->connection->prepare(
                <<<'SQL'
                INSERT INTO product (
                    tenant_id, name, category, unit_of_measure, unit_cost_cents,
                    minimum_stock_quantity, active
                ) VALUES (
                    :tenant_id, :name, :category, :unit_of_measure, :unit_cost_cents,
                    :minimum_stock_quantity, :active
                )
                SQL
            );
            $statement->execute([
                ':tenant_id' => $entity->tenantId(),
                ':name' => $entity->name(),
                ':category' => $entity->category(),
                ':unit_of_measure' => $entity->unitOfMeasure(),
                ':unit_cost_cents' => $entity->unitCostCents(),
                ':minimum_stock_quantity' => $entity->minimumStockQuantity(),
                ':active' => $entity->isActive() ? 1 : 0,
            ]);

            $entity->assignId((int) $this->connection->lastInsertId());

            return $entity;
        }

        $query = $this->tenantQuery()->andEquals('id', $entity->id());

        $statement = $this->connection->prepare(
            "UPDATE product SET name = :name, category = :category, "
            . "unit_of_measure = :unit_of_measure, unit_cost_cents = :unit_cost_cents, "
            . "minimum_stock_quantity = :minimum_stock_quantity, active = :active "
            . "WHERE {$query->whereSql()}"
        );
        $statement->execute([
            ...$query->parameters(),
            ':name' => $entity->name(),
            ':category' => $entity->category(),
            ':unit_of_measure' => $entity->unitOfMeasure(),
            ':unit_cost_cents' => $entity->unitCostCents(),
            ':minimum_stock_quantity' => $entity->minimumStockQuantity(),
            ':active' => $entity->isActive() ? 1 : 0,
        ]);

        return $entity;
    }

    public function remove(object $entity): void
    {
        if (!$entity instanceof Product) {
            throw new InvalidArgumentException('Expected a Product entity');
        }

        $id = $entity->id();

        if ($id === null) {
            return;
        }

        $this->assertEntityTenant($entity->tenantId());

        $query = $this->tenantQuery()->andEquals('id', $id);

        $statement = $this->connection->prepare("DELETE FROM product WHERE {$query->whereSql()}");
        $statement->execute($query->parameters());
    }

    /** @param array<string, mixed> $row */
    private static function hydrate(array $row): Product
    {
        return Product::reconstitute(
            id: (int) $row['id'],
            tenantId: (int) $row['tenant_id'],
            name: (string) $row['name'],
            category: $row['category'] !== null ? (string) $row['category'] : null,
            unitOfMeasure: (string) $row['unit_of_measure'],
            unitCostCents: (int) $row['unit_cost_cents'],
            minimumStockQuantity: (int) $row['minimum_stock_quantity'],
            active: (bool) $row['active'],
            createdAt: $row['created_at'] !== null ? new DateTimeImmutable((string) $row['created_at']) : null,
            updatedAt: $row['updated_at'] !== null ? new DateTimeImmutable((string) $row['updated_at']) : null,
        );
    }
}
