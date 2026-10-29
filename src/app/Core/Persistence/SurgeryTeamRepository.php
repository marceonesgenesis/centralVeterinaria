<?php

declare(strict_types=1);

namespace CentralVet\Persistence;

use CentralVet\Domain\Contract\SurgeryTeamRepositoryInterface;
use CentralVet\Domain\SurgeryTeamMember;
use CentralVet\Tenancy\TenantContext;
use InvalidArgumentException;
use PDO;

/**
 * PDO-backed persistence for SurgeryTeamMember (`surgery_team`, migration
 * 0011). Every query starts from TenantQuery::forTenant() (ADR 0002).
 * replaceForSurgery() deletes the surgery's whole team and inserts the
 * given members; the caller holds the surgery's status lock.
 *
 * @implements SurgeryTeamRepositoryInterface<SurgeryTeamMember>
 */
final class SurgeryTeamRepository extends AbstractTenantRepository implements SurgeryTeamRepositoryInterface
{
    public function __construct(TenantContext $context, private readonly PDO $connection)
    {
        parent::__construct($context);
    }

    public function findById(int|string $id): ?object
    {
        $query = $this->tenantQuery()->andEquals('id', (int) $id);

        $statement = $this->connection->prepare("SELECT * FROM surgery_team WHERE {$query->whereSql()} LIMIT 1");
        $statement->execute($query->parameters());

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : SurgeryTeamMember::reconstitute($row);
    }

    public function listBySurgery(int $surgeryId): array
    {
        $query = $this->tenantQuery()->andEquals('surgery_id', $surgeryId);

        $statement = $this->connection->prepare(
            "SELECT * FROM surgery_team WHERE {$query->whereSql()} ORDER BY id ASC"
        );
        $statement->execute($query->parameters());

        return array_map(
            static fn (array $row): SurgeryTeamMember => SurgeryTeamMember::reconstitute($row),
            $statement->fetchAll(PDO::FETCH_ASSOC),
        );
    }

    public function replaceForSurgery(int $surgeryId, array $members): void
    {
        foreach ($members as $member) {
            if (!$member instanceof SurgeryTeamMember) {
                throw new InvalidArgumentException('Expected SurgeryTeamMember entities');
            }

            $this->assertEntityTenant($member->tenantId());

            if ($member->surgeryId() !== $surgeryId) {
                throw new InvalidArgumentException("Team member does not belong to surgery {$surgeryId}");
            }

            if ($member->id() !== null) {
                throw new InvalidArgumentException('Team members are replaced with new rows');
            }
        }

        $query = $this->tenantQuery()->andEquals('surgery_id', $surgeryId);

        $statement = $this->connection->prepare("DELETE FROM surgery_team WHERE {$query->whereSql()}");
        $statement->execute($query->parameters());

        foreach ($members as $member) {
            $this->insert($member);
        }
    }

    public function save(object $entity): object
    {
        if (!$entity instanceof SurgeryTeamMember) {
            throw new InvalidArgumentException('Expected a SurgeryTeamMember entity');
        }

        $this->assertEntityTenant($entity->tenantId());

        if ($entity->id() !== null) {
            throw new InvalidArgumentException('Team members are immutable; use replaceForSurgery()');
        }

        $this->insert($entity);

        return $entity;
    }

    public function remove(object $entity): void
    {
        if (!$entity instanceof SurgeryTeamMember) {
            throw new InvalidArgumentException('Expected a SurgeryTeamMember entity');
        }

        $id = $entity->id();

        if ($id === null) {
            return;
        }

        $this->assertEntityTenant($entity->tenantId());

        $query = $this->tenantQuery()->andEquals('id', $id);

        $statement = $this->connection->prepare("DELETE FROM surgery_team WHERE {$query->whereSql()}");
        $statement->execute($query->parameters());
    }

    private function insert(SurgeryTeamMember $member): void
    {
        $statement = $this->connection->prepare(
            <<<'SQL'
            INSERT INTO surgery_team (tenant_id, surgery_id, system_user_id, role)
            VALUES (:tenant_id, :surgery_id, :system_user_id, :role)
            SQL
        );
        $statement->execute([
            ':tenant_id' => $member->tenantId(),
            ':surgery_id' => $member->surgeryId(),
            ':system_user_id' => $member->systemUserId(),
            ':role' => $member->role(),
        ]);

        $member->assignId((int) $this->connection->lastInsertId());
    }
}
