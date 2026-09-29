<?php

declare(strict_types=1);

namespace CentralVet\Persistence;

use CentralVet\Domain\Contract\ProcedureCatalogRepositoryInterface;
use CentralVet\Domain\ProcedureCatalogItem;
use CentralVet\Tenancy\TenantContext;
use InvalidArgumentException;
use PDO;

/**
 * PDO-backed persistence for the ProcedureCatalogItem aggregate. Every query
 * starts from TenantQuery::forTenant() (ADR 0002); tenant scoping is never
 * accepted from caller input.
 *
 * PENDING / DO NOT WIRE YET: this class depends on the
 * `procedure_catalog_item` table created by the not-yet-applied migration
 * src/app/database/migrations/20260924_0005_phase4_procedure_stock_sale.sql.
 * It is prepared and syntax-checked (php -l) only; it must not be
 * constructed with a real PDO connection nor executed against the live
 * database until that migration has explicit SQL execution approval and has
 * actually been applied.
 *
 * @implements ProcedureCatalogRepositoryInterface<ProcedureCatalogItem>
 */
final class ProcedureCatalogRepository extends AbstractTenantRepository implements ProcedureCatalogRepositoryInterface
{
    public function __construct(TenantContext $context, private readonly PDO $connection)
    {
        parent::__construct($context);
    }

    public function findById(int|string $id): ?object
    {
        $query = $this->tenantQuery()->andEquals('id', (int) $id);

        $statement = $this->connection->prepare(
            "SELECT * FROM procedure_catalog_item WHERE {$query->whereSql()} LIMIT 1"
        );
        $statement->execute($query->parameters());

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * 'active = 1' is a fixed literal appended to the WHERE clause, not
     * caller input, so it is safe outside TenantQuery's parameter binding
     * (same convention as VaccineCatalogRepository::listActive()).
     */
    public function findActive(): array
    {
        $query = $this->tenantQuery();

        $statement = $this->connection->prepare(
            "SELECT * FROM procedure_catalog_item WHERE {$query->whereSql()} "
            . 'AND active = 1 ORDER BY name ASC'
        );
        $statement->execute($query->parameters());

        $items = [];

        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $items[] = $this->hydrate($row);
        }

        return $items;
    }

    public function save(object $entity): object
    {
        if (!$entity instanceof ProcedureCatalogItem) {
            throw new InvalidArgumentException('Expected a ProcedureCatalogItem entity');
        }

        $this->assertEntityTenant($entity->tenantId());

        if ($entity->id() === null) {
            $statement = $this->connection->prepare(
                <<<'SQL'
                INSERT INTO procedure_catalog_item (
                    tenant_id, name, price_cents, duration_minutes, preparation_text, active
                ) VALUES (
                    :tenant_id, :name, :price_cents, :duration_minutes, :preparation_text, :active
                )
                SQL
            );
            $statement->execute([
                ':tenant_id' => $entity->tenantId(),
                ':name' => $entity->name(),
                ':price_cents' => $entity->priceCents(),
                ':duration_minutes' => $entity->durationMinutes(),
                ':preparation_text' => $entity->preparationText(),
                ':active' => $entity->active() ? 1 : 0,
            ]);

            $entity->assignId((int) $this->connection->lastInsertId());

            return $entity;
        }

        $query = $this->tenantQuery()->andEquals('id', $entity->id());

        $statement = $this->connection->prepare(
            <<<SQL
            UPDATE procedure_catalog_item SET
                name = :name, price_cents = :price_cents, duration_minutes = :duration_minutes,
                preparation_text = :preparation_text, active = :active
            WHERE {$query->whereSql()}
            SQL
        );
        $statement->execute([
            ...$query->parameters(),
            ':name' => $entity->name(),
            ':price_cents' => $entity->priceCents(),
            ':duration_minutes' => $entity->durationMinutes(),
            ':preparation_text' => $entity->preparationText(),
            ':active' => $entity->active() ? 1 : 0,
        ]);

        return $entity;
    }

    public function remove(object $entity): void
    {
        if (!$entity instanceof ProcedureCatalogItem) {
            throw new InvalidArgumentException('Expected a ProcedureCatalogItem entity');
        }

        $id = $entity->id();

        if ($id === null) {
            return;
        }

        $this->assertEntityTenant($entity->tenantId());

        $query = $this->tenantQuery()->andEquals('id', $id);

        $statement = $this->connection->prepare("DELETE FROM procedure_catalog_item WHERE {$query->whereSql()}");
        $statement->execute($query->parameters());
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): ProcedureCatalogItem
    {
        return ProcedureCatalogItem::reconstitute($row);
    }
}
