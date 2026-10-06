<?php

declare(strict_types=1);

namespace CentralVet\Persistence;

use CentralVet\Domain\Contract\SurgeryEventRepositoryInterface;
use CentralVet\Domain\SurgeryEvent;
use CentralVet\Tenancy\TenantContext;
use InvalidArgumentException;
use LogicException;
use PDO;

/**
 * PDO-backed persistence for SurgeryEvent (`surgery_event`, migration
 * 0011): an append-only timeline, so save() only INSERTs and remove()
 * throws. Every query starts from TenantQuery::forTenant() (ADR 0002).
 *
 * @implements SurgeryEventRepositoryInterface<SurgeryEvent>
 */
final class SurgeryEventRepository extends AbstractTenantRepository implements SurgeryEventRepositoryInterface
{
    public function __construct(TenantContext $context, private readonly PDO $connection)
    {
        parent::__construct($context);
    }

    public function findById(int|string $id): ?object
    {
        $query = $this->tenantQuery()->andEquals('id', (int) $id);

        $statement = $this->connection->prepare("SELECT * FROM surgery_event WHERE {$query->whereSql()} LIMIT 1");
        $statement->execute($query->parameters());

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : SurgeryEvent::reconstitute($row);
    }

    public function listBySurgery(int $surgeryId): array
    {
        $query = $this->tenantQuery()->andEquals('surgery_id', $surgeryId);

        $statement = $this->connection->prepare(
            "SELECT * FROM surgery_event WHERE {$query->whereSql()} ORDER BY recorded_at DESC, id DESC"
        );
        $statement->execute($query->parameters());

        return array_map(
            static fn (array $row): SurgeryEvent => SurgeryEvent::reconstitute($row),
            $statement->fetchAll(PDO::FETCH_ASSOC),
        );
    }

    public function save(object $entity): object
    {
        if (!$entity instanceof SurgeryEvent) {
            throw new InvalidArgumentException('Expected a SurgeryEvent entity');
        }

        $this->assertEntityTenant($entity->tenantId());

        if ($entity->id() !== null) {
            throw new LogicException('Surgery events are append-only');
        }

        $statement = $this->connection->prepare(
            <<<'SQL'
            INSERT INTO surgery_event (tenant_id, surgery_id, event_type, recorded_by_system_user_id, recorded_at, notes_text)
            VALUES (:tenant_id, :surgery_id, :event_type, :recorded_by_system_user_id, :recorded_at, :notes_text)
            SQL
        );
        $statement->execute([
            ':tenant_id' => $entity->tenantId(),
            ':surgery_id' => $entity->surgeryId(),
            ':event_type' => $entity->eventType(),
            ':recorded_by_system_user_id' => $entity->recordedBySystemUserId(),
            ':recorded_at' => $entity->recordedAt()->format('Y-m-d H:i:s.u'),
            ':notes_text' => $entity->notesText(),
        ]);

        $entity->assignId((int) $this->connection->lastInsertId());

        return $entity;
    }

    public function remove(object $entity): void
    {
        throw new LogicException('Surgery events are append-only');
    }
}
