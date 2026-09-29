<?php

declare(strict_types=1);

namespace CentralVet\Persistence;

use CentralVet\Domain\Contract\ProcedureCatalogItemInputRepositoryInterface;
use CentralVet\Domain\ProcedureCatalogItemInput;
use CentralVet\Tenancy\TenantContext;
use InvalidArgumentException;
use PDO;

/**
 * PDO-backed persistence for the ProcedureCatalogItemInput aggregate. Every
 * query starts from TenantQuery::forTenant() (ADR 0002); tenant scoping is
 * never accepted from caller input.
 *
 * PENDING / DO NOT WIRE YET: this class depends on the
 * `procedure_catalog_item_input` table created by the not-yet-applied
 * migration
 * src/app/database/migrations/20260924_0005_phase4_procedure_stock_sale.sql.
 * It is prepared and syntax-checked (php -l) only; it must not be
 * constructed with a real PDO connection nor executed against the live
 * database until that migration has explicit SQL execution approval and has
 * actually been applied.
 *
 * @implements ProcedureCatalogItemInputRepositoryInterface<ProcedureCatalogItemInput>
 */
final class ProcedureCatalogItemInputRepository extends AbstractTenantRepository implements
    ProcedureCatalogItemInputRepositoryInterface
{
    public function __construct(TenantContext $context, private readonly PDO $connection)
    {
        parent::__construct($context);
    }

    public function findById(int|string $id): ?object
    {
        $query = $this->tenantQuery()->andEquals('id', (int) $id);

        $statement = $this->connection->prepare(
            "SELECT * FROM procedure_catalog_item_input WHERE {$query->whereSql()} LIMIT 1"
        );
        $statement->execute($query->parameters());

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * Ordered by `id ASC` (insertion order) so ProcedureCatalogService::
     * listInputs() satisfies its acceptance criterion of returning inputs in
     * the order they were registered — `id` is auto-increment and therefore
     * a reliable proxy for insertion order, unlike `created_at`, which two
     * inputs added within the same microsecond batch could tie on.
     */
    public function listByProcedureCatalogItem(int $procedureCatalogItemId): array
    {
        $query = $this->tenantQuery()->andEquals('procedure_catalog_item_id', $procedureCatalogItemId);

        $statement = $this->connection->prepare(
            "SELECT * FROM procedure_catalog_item_input WHERE {$query->whereSql()} ORDER BY id ASC"
        );
        $statement->execute($query->parameters());

        $inputs = [];

        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $inputs[] = $this->hydrate($row);
        }

        return $inputs;
    }

    public function save(object $entity): object
    {
        if (!$entity instanceof ProcedureCatalogItemInput) {
            throw new InvalidArgumentException('Expected a ProcedureCatalogItemInput entity');
        }

        $this->assertEntityTenant($entity->tenantId());

        if ($entity->id() === null) {
            $statement = $this->connection->prepare(
                <<<'SQL'
                INSERT INTO procedure_catalog_item_input (
                    tenant_id, procedure_catalog_item_id, product_id, quantity_per_execution
                ) VALUES (
                    :tenant_id, :procedure_catalog_item_id, :product_id, :quantity_per_execution
                )
                SQL
            );
            $statement->execute([
                ':tenant_id' => $entity->tenantId(),
                ':procedure_catalog_item_id' => $entity->procedureCatalogItemId(),
                ':product_id' => $entity->productId(),
                ':quantity_per_execution' => $entity->quantityPerExecution(),
            ]);

            $entity->assignId((int) $this->connection->lastInsertId());

            return $entity;
        }

        // procedure_catalog_item_input has no mutable columns beyond its
        // identifying pair (procedure_catalog_item_id/product_id) and
        // quantity_per_execution; an update only ever rewrites the quantity.
        $query = $this->tenantQuery()->andEquals('id', $entity->id());

        $statement = $this->connection->prepare(
            <<<SQL
            UPDATE procedure_catalog_item_input SET quantity_per_execution = :quantity_per_execution
            WHERE {$query->whereSql()}
            SQL
        );
        $statement->execute([
            ...$query->parameters(),
            ':quantity_per_execution' => $entity->quantityPerExecution(),
        ]);

        return $entity;
    }

    public function remove(object $entity): void
    {
        if (!$entity instanceof ProcedureCatalogItemInput) {
            throw new InvalidArgumentException('Expected a ProcedureCatalogItemInput entity');
        }

        $id = $entity->id();

        if ($id === null) {
            return;
        }

        $this->assertEntityTenant($entity->tenantId());

        $query = $this->tenantQuery()->andEquals('id', $id);

        $statement = $this->connection->prepare(
            "DELETE FROM procedure_catalog_item_input WHERE {$query->whereSql()}"
        );
        $statement->execute($query->parameters());
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): ProcedureCatalogItemInput
    {
        return ProcedureCatalogItemInput::reconstitute($row);
    }
}
