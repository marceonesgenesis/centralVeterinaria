<?php

declare(strict_types=1);

namespace CentralVet\Persistence;

use CentralVet\Domain\Contract\ExamCatalogRepositoryInterface;
use CentralVet\Domain\ExamCatalogItem;
use CentralVet\Tenancy\TenantContext;
use DateTimeImmutable;
use InvalidArgumentException;
use PDO;

/**
 * PDO-backed persistence for the ExamCatalogItem aggregate. Every query
 * starts from TenantQuery::forTenant() (ADR 0002); tenant scoping is never
 * accepted from caller input.
 *
 * PENDING / DO NOT WIRE YET: this class depends on the `exam_catalog_item`
 * table created by the not-yet-applied migration
 * src/app/database/migrations/20260922_0004_phase3_prescription_exam_vaccine.sql.
 * It is prepared and syntax-checked (php -l) only; it must not be
 * constructed with a real PDO connection nor executed against the live
 * database until that migration has explicit SQL execution approval and has
 * actually been applied.
 *
 * @implements ExamCatalogRepositoryInterface<ExamCatalogItem>
 */
final class ExamCatalogRepository extends AbstractTenantRepository implements ExamCatalogRepositoryInterface
{
    public function __construct(TenantContext $context, private readonly PDO $connection)
    {
        parent::__construct($context);
    }

    public function findById(int|string $id): ?object
    {
        $query = $this->tenantQuery()->andEquals('id', (int) $id);

        $statement = $this->connection->prepare(
            "SELECT * FROM exam_catalog_item WHERE {$query->whereSql()} LIMIT 1"
        );
        $statement->execute($query->parameters());

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * `active = 1` is a fixed literal appended to the WHERE clause, not
     * caller input, so it is safe outside TenantQuery's parameter binding
     * (same convention as QueueEntryRepository::listActiveByUnit()).
     */
    public function listActive(): array
    {
        $query = $this->tenantQuery();

        $statement = $this->connection->prepare(
            "SELECT * FROM exam_catalog_item WHERE {$query->whereSql()} "
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
        if (!$entity instanceof ExamCatalogItem) {
            throw new InvalidArgumentException('Expected an ExamCatalogItem entity');
        }

        $this->assertEntityTenant($entity->tenantId());

        if ($entity->id() === null) {
            $statement = $this->connection->prepare(
                <<<'SQL'
                INSERT INTO exam_catalog_item (
                    tenant_id, name, partner_name, price_cents, active
                ) VALUES (
                    :tenant_id, :name, :partner_name, :price_cents, :active
                )
                SQL
            );
            $statement->execute([
                ':tenant_id' => $entity->tenantId(),
                ':name' => $entity->name(),
                ':partner_name' => $entity->partnerName(),
                ':price_cents' => $entity->priceCents(),
                ':active' => $entity->active() ? 1 : 0,
            ]);

            $entity->assignId((int) $this->connection->lastInsertId());

            return $entity;
        }

        $query = $this->tenantQuery()->andEquals('id', $entity->id());

        $statement = $this->connection->prepare(
            "UPDATE exam_catalog_item SET active = :active WHERE {$query->whereSql()}"
        );
        $statement->execute([
            ...$query->parameters(),
            ':active' => $entity->active() ? 1 : 0,
        ]);

        return $entity;
    }

    public function remove(object $entity): void
    {
        if (!$entity instanceof ExamCatalogItem) {
            throw new InvalidArgumentException('Expected an ExamCatalogItem entity');
        }

        $id = $entity->id();

        if ($id === null) {
            return;
        }

        $this->assertEntityTenant($entity->tenantId());

        $query = $this->tenantQuery()->andEquals('id', $id);

        $statement = $this->connection->prepare("DELETE FROM exam_catalog_item WHERE {$query->whereSql()}");
        $statement->execute($query->parameters());
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): ExamCatalogItem
    {
        return ExamCatalogItem::reconstitute(
            id: (int) $row['id'],
            tenantId: (int) $row['tenant_id'],
            name: (string) $row['name'],
            partnerName: $row['partner_name'] !== null ? (string) $row['partner_name'] : null,
            priceCents: (int) $row['price_cents'],
            active: (bool) $row['active'],
            createdAt: $row['created_at'] !== null ? new DateTimeImmutable((string) $row['created_at']) : null,
            updatedAt: $row['updated_at'] !== null ? new DateTimeImmutable((string) $row['updated_at']) : null,
        );
    }
}
