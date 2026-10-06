<?php

declare(strict_types=1);

namespace CentralVet\Persistence;

use CentralVet\Domain\Contract\SurgeryMaterialRepositoryInterface;
use CentralVet\Domain\SurgeryMaterial;
use CentralVet\Tenancy\TenantContext;
use InvalidArgumentException;
use LogicException;
use PDO;

/**
 * PDO-backed persistence for SurgeryMaterial (`surgery_material`,
 * migration 0011). Rows are inserted and deleted, never updated; the
 * service holds the surgery's status lock while doing either. Every query
 * starts from TenantQuery::forTenant() (ADR 0002).
 *
 * @implements SurgeryMaterialRepositoryInterface<SurgeryMaterial>
 */
final class SurgeryMaterialRepository extends AbstractTenantRepository implements SurgeryMaterialRepositoryInterface
{
    public function __construct(TenantContext $context, private readonly PDO $connection)
    {
        parent::__construct($context);
    }

    public function findById(int|string $id): ?object
    {
        $query = $this->tenantQuery()->andEquals('id', (int) $id);

        $statement = $this->connection->prepare("SELECT * FROM surgery_material WHERE {$query->whereSql()} LIMIT 1");
        $statement->execute($query->parameters());

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : SurgeryMaterial::reconstitute($row);
    }

    public function listBySurgery(int $surgeryId): array
    {
        $query = $this->tenantQuery()->andEquals('surgery_id', $surgeryId);

        $statement = $this->connection->prepare(
            "SELECT * FROM surgery_material WHERE {$query->whereSql()} ORDER BY recorded_at ASC, id ASC"
        );
        $statement->execute($query->parameters());

        return array_map(
            static fn (array $row): SurgeryMaterial => SurgeryMaterial::reconstitute($row),
            $statement->fetchAll(PDO::FETCH_ASSOC),
        );
    }

    public function save(object $entity): object
    {
        if (!$entity instanceof SurgeryMaterial) {
            throw new InvalidArgumentException('Expected a SurgeryMaterial entity');
        }

        $this->assertEntityTenant($entity->tenantId());

        if ($entity->id() !== null) {
            throw new LogicException('Surgery materials are not updated; remove and record again');
        }

        $statement = $this->connection->prepare(
            <<<'SQL'
            INSERT INTO surgery_material (tenant_id, surgery_id, product_id, quantity, recorded_by_system_user_id, recorded_at)
            VALUES (:tenant_id, :surgery_id, :product_id, :quantity, :recorded_by_system_user_id, :recorded_at)
            SQL
        );
        $statement->execute([
            ':tenant_id' => $entity->tenantId(),
            ':surgery_id' => $entity->surgeryId(),
            ':product_id' => $entity->productId(),
            ':quantity' => $entity->quantity(),
            ':recorded_by_system_user_id' => $entity->recordedBySystemUserId(),
            ':recorded_at' => $entity->recordedAt()->format('Y-m-d H:i:s.u'),
        ]);

        $entity->assignId((int) $this->connection->lastInsertId());

        return $entity;
    }

    public function remove(object $entity): void
    {
        if (!$entity instanceof SurgeryMaterial) {
            throw new InvalidArgumentException('Expected a SurgeryMaterial entity');
        }

        $this->delete($entity);
    }

    public function delete(SurgeryMaterial $material): int
    {
        $id = $material->id();

        if ($id === null) {
            return 0;
        }

        $this->assertEntityTenant($material->tenantId());

        $query = $this->tenantQuery()
            ->andEquals('id', $id)
            ->andEquals('surgery_id', $material->surgeryId());

        $statement = $this->connection->prepare("DELETE FROM surgery_material WHERE {$query->whereSql()}");
        $statement->execute($query->parameters());

        return $statement->rowCount();
    }
}
