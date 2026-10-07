<?php

declare(strict_types=1);

namespace CentralVet\Persistence;

use CentralVet\Domain\Contract\SurgeryRoomRepositoryInterface;
use CentralVet\Domain\SurgeryRoom;
use CentralVet\Tenancy\TenantContext;
use InvalidArgumentException;
use PDO;

/**
 * PDO-backed persistence for the SurgeryRoom aggregate (`surgery_room`,
 * migration 0011). Every query starts from TenantQuery::forTenant()
 * (ADR 0002). lockForScheduling() serializes the overlap check and the
 * insert of a surgery in the same room (SELECT ... FOR UPDATE on the room
 * row).
 *
 * @implements SurgeryRoomRepositoryInterface<SurgeryRoom>
 */
final class SurgeryRoomRepository extends AbstractTenantRepository implements SurgeryRoomRepositoryInterface
{
    public function __construct(TenantContext $context, private readonly PDO $connection)
    {
        parent::__construct($context);
    }

    public function findById(int|string $id): ?object
    {
        return $this->fetchOne($this->tenantQuery()->andEquals('id', (int) $id));
    }

    public function listByUnit(int $systemUnitId): array
    {
        $query = $this->tenantQuery()->andEquals('system_unit_id', $systemUnitId);

        $statement = $this->connection->prepare(
            "SELECT * FROM surgery_room WHERE {$query->whereSql()} ORDER BY code ASC, id ASC"
        );
        $statement->execute($query->parameters());

        return array_map(
            static fn (array $row): SurgeryRoom => SurgeryRoom::reconstitute($row),
            $statement->fetchAll(PDO::FETCH_ASSOC),
        );
    }

    public function findByCode(int $systemUnitId, string $code): ?object
    {
        return $this->fetchOne(
            $this->tenantQuery()
                ->andEquals('system_unit_id', $systemUnitId)
                ->andEquals('code', trim($code)),
        );
    }

    public function lockForScheduling(int $roomId): bool
    {
        $query = $this->tenantQuery()->andEquals('id', $roomId);

        $statement = $this->connection->prepare(
            "SELECT id FROM surgery_room WHERE {$query->whereSql()} FOR UPDATE"
        );
        $statement->execute($query->parameters());

        return $statement->fetchColumn() !== false;
    }

    public function save(object $entity): object
    {
        if (!$entity instanceof SurgeryRoom) {
            throw new InvalidArgumentException('Expected a SurgeryRoom entity');
        }

        $this->assertEntityTenant($entity->tenantId());

        if ($entity->id() === null) {
            $statement = $this->connection->prepare(
                <<<'SQL'
                INSERT INTO surgery_room (tenant_id, system_unit_id, code, name, status)
                VALUES (:tenant_id, :system_unit_id, :code, :name, :status)
                SQL
            );
            $statement->execute([
                ':tenant_id' => $entity->tenantId(),
                ':system_unit_id' => $entity->systemUnitId(),
                ':code' => $entity->code(),
                ':name' => $entity->name(),
                ':status' => $entity->status(),
            ]);

            $entity->assignId((int) $this->connection->lastInsertId());

            return $entity;
        }

        $query = $this->tenantQuery()->andEquals('id', $entity->id());

        $statement = $this->connection->prepare(
            "UPDATE surgery_room SET name = :name, status = :status WHERE {$query->whereSql()}"
        );
        $statement->execute([
            ...$query->parameters(),
            ':name' => $entity->name(),
            ':status' => $entity->status(),
        ]);

        return $entity;
    }

    public function remove(object $entity): void
    {
        if (!$entity instanceof SurgeryRoom) {
            throw new InvalidArgumentException('Expected a SurgeryRoom entity');
        }

        $id = $entity->id();

        if ($id === null) {
            return;
        }

        $this->assertEntityTenant($entity->tenantId());

        $query = $this->tenantQuery()->andEquals('id', $id);

        $statement = $this->connection->prepare("DELETE FROM surgery_room WHERE {$query->whereSql()}");
        $statement->execute($query->parameters());
    }

    private function fetchOne(TenantQuery $query): ?SurgeryRoom
    {
        $statement = $this->connection->prepare(
            "SELECT * FROM surgery_room WHERE {$query->whereSql()} LIMIT 1"
        );
        $statement->execute($query->parameters());

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : SurgeryRoom::reconstitute($row);
    }
}
