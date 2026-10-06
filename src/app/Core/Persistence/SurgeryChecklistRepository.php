<?php

declare(strict_types=1);

namespace CentralVet\Persistence;

use CentralVet\Domain\Contract\SurgeryChecklistRepositoryInterface;
use CentralVet\Domain\Exception\InvalidStatusTransitionException;
use CentralVet\Domain\SurgeryChecklistItem;
use CentralVet\Tenancy\TenantContext;
use InvalidArgumentException;
use LogicException;
use PDO;
use PDOException;

/**
 * PDO-backed persistence for SurgeryChecklistItem (`surgery_checklist`,
 * migration 0011), append-only. Every query starts from
 * TenantQuery::forTenant() (ADR 0002). The UNIQUE key
 * `surgery_checklist_item_uq (surgery_id, phase, item_code)` turns a double
 * tap into InvalidStatusTransitionException
 * `Checklist phase "<phase>" is already confirmed for surgery <id>`.
 *
 * @implements SurgeryChecklistRepositoryInterface<SurgeryChecklistItem>
 */
final class SurgeryChecklistRepository extends AbstractTenantRepository implements SurgeryChecklistRepositoryInterface
{
    private const UNIQUE_KEY = 'surgery_checklist_item_uq';

    public function __construct(TenantContext $context, private readonly PDO $connection)
    {
        parent::__construct($context);
    }

    public function findById(int|string $id): ?object
    {
        $query = $this->tenantQuery()->andEquals('id', (int) $id);

        $statement = $this->connection->prepare("SELECT * FROM surgery_checklist WHERE {$query->whereSql()} LIMIT 1");
        $statement->execute($query->parameters());

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : SurgeryChecklistItem::reconstitute($row);
    }

    public function listBySurgery(int $surgeryId): array
    {
        $query = $this->tenantQuery()->andEquals('surgery_id', $surgeryId);

        $statement = $this->connection->prepare(
            "SELECT * FROM surgery_checklist WHERE {$query->whereSql()} ORDER BY checked_at ASC, id ASC"
        );
        $statement->execute($query->parameters());

        return array_map(
            static fn (array $row): SurgeryChecklistItem => SurgeryChecklistItem::reconstitute($row),
            $statement->fetchAll(PDO::FETCH_ASSOC),
        );
    }

    public function save(object $entity): object
    {
        if (!$entity instanceof SurgeryChecklistItem) {
            throw new InvalidArgumentException('Expected a SurgeryChecklistItem entity');
        }

        $this->assertEntityTenant($entity->tenantId());

        if ($entity->id() !== null) {
            throw new LogicException('Checklist items are append-only');
        }

        $statement = $this->connection->prepare(
            <<<'SQL'
            INSERT INTO surgery_checklist (tenant_id, surgery_id, phase, item_code, checked_by_system_user_id, checked_at)
            VALUES (:tenant_id, :surgery_id, :phase, :item_code, :checked_by_system_user_id, :checked_at)
            SQL
        );

        try {
            $statement->execute([
                ':tenant_id' => $entity->tenantId(),
                ':surgery_id' => $entity->surgeryId(),
                ':phase' => $entity->phase(),
                ':item_code' => $entity->itemCode(),
                ':checked_by_system_user_id' => $entity->checkedBySystemUserId(),
                ':checked_at' => $entity->checkedAt()->format('Y-m-d H:i:s.u'),
            ]);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000' && str_contains($e->getMessage(), self::UNIQUE_KEY)) {
                throw new InvalidStatusTransitionException(
                    "Checklist phase \"{$entity->phase()}\" is already confirmed for surgery {$entity->surgeryId()}",
                    0,
                    $e,
                );
            }

            throw $e;
        }

        $entity->assignId((int) $this->connection->lastInsertId());

        return $entity;
    }

    public function remove(object $entity): void
    {
        throw new LogicException('Checklist items are append-only');
    }
}
