<?php

declare(strict_types=1);

namespace CentralVet\Persistence;

use CentralVet\Domain\Bed;
use CentralVet\Domain\Contract\BedRepositoryInterface;
use CentralVet\Domain\Exception\BedUnavailableException;
use CentralVet\Tenancy\TenantContext;
use InvalidArgumentException;
use PDO;

/**
 * PDO-backed persistence for the Bed aggregate (`bed`, migration 0010).
 * Every query starts from TenantQuery::forTenant() (ADR 0002).
 *
 * Occupancy is only ever changed by occupy()/release(): conditional UPDATEs
 * whose WHERE carries the expected state, so two tablets admitting into
 * the same bed cannot both win (rowCount() === 1 decides). save() never
 * writes current_hospitalization_id and never moves a bed into or out of
 * `occupied`.
 *
 * @implements BedRepositoryInterface<Bed>
 */
final class BedRepository extends AbstractTenantRepository implements BedRepositoryInterface
{
    public function __construct(TenantContext $context, private readonly PDO $connection)
    {
        parent::__construct($context);
    }

    public function findById(int|string $id): ?object
    {
        $query = $this->tenantQuery()->andEquals('id', (int) $id);

        return $this->fetchOne($query);
    }

    public function listByUnit(int $systemUnitId): array
    {
        $query = $this->tenantQuery()->andEquals('system_unit_id', $systemUnitId);

        $statement = $this->connection->prepare(
            "SELECT * FROM bed WHERE {$query->whereSql()} ORDER BY code ASC, id ASC"
        );
        $statement->execute($query->parameters());

        return array_map(
            static fn (array $row): Bed => Bed::reconstitute($row),
            $statement->fetchAll(PDO::FETCH_ASSOC),
        );
    }

    public function findByCode(int $systemUnitId, string $code): ?object
    {
        $query = $this->tenantQuery()
            ->andEquals('system_unit_id', $systemUnitId)
            ->andEquals('code', trim($code));

        return $this->fetchOne($query);
    }

    public function occupy(int $bedId, int $hospitalizationId): bool
    {
        $query = $this->tenantQuery()
            ->andEquals('id', $bedId)
            ->andEquals('status', Bed::STATUS_AVAILABLE);

        $statement = $this->connection->prepare(
            "UPDATE bed SET status = 'occupied', current_hospitalization_id = :hospitalization_id WHERE {$query->whereSql()}"
        );
        $statement->execute([...$query->parameters(), ':hospitalization_id' => $hospitalizationId]);

        return $statement->rowCount() === 1;
    }

    public function release(int $bedId, int $hospitalizationId): bool
    {
        $query = $this->tenantQuery()
            ->andEquals('id', $bedId)
            ->andEquals('current_hospitalization_id', $hospitalizationId);

        $statement = $this->connection->prepare(
            "UPDATE bed SET status = 'available', current_hospitalization_id = NULL WHERE {$query->whereSql()}"
        );
        $statement->execute($query->parameters());

        return $statement->rowCount() === 1;
    }

    public function save(object $entity): object
    {
        if (!$entity instanceof Bed) {
            throw new InvalidArgumentException('Expected a Bed entity');
        }

        $this->assertEntityTenant($entity->tenantId());

        if ($entity->id() === null) {
            if ($entity->status() === Bed::STATUS_OCCUPIED) {
                throw new InvalidArgumentException('A new bed cannot be persisted as occupied');
            }

            $statement = $this->connection->prepare(
                <<<'SQL'
                INSERT INTO bed (tenant_id, system_unit_id, code, name, daily_rate_cents, status)
                VALUES (:tenant_id, :system_unit_id, :code, :name, :daily_rate_cents, :status)
                SQL
            );
            $statement->execute([
                ':tenant_id' => $entity->tenantId(),
                ':system_unit_id' => $entity->systemUnitId(),
                ':code' => $entity->code(),
                ':name' => $entity->name(),
                ':daily_rate_cents' => $entity->dailyRateCents(),
                ':status' => $entity->status(),
            ]);

            $entity->assignId((int) $this->connection->lastInsertId());

            return $entity;
        }

        $query = $this->tenantQuery()->andEquals('id', $entity->id());

        if ($entity->status() === Bed::STATUS_INACTIVE) {
            // Only a bed nobody occupies can be taken out of use.
            $statement = $this->connection->prepare(
                "UPDATE bed SET name = :name, daily_rate_cents = :daily_rate_cents, status = 'inactive'
                 WHERE {$query->whereSql()} AND current_hospitalization_id IS NULL"
            );
            $statement->execute([
                ...$query->parameters(),
                ':name' => $entity->name(),
                ':daily_rate_cents' => $entity->dailyRateCents(),
            ]);

            // rowCount() counts changed rows: 0 is also "already inactive,
            // nothing changed". Only a held (or invisible) bed is refused.
            if ($statement->rowCount() === 0 && !$this->isFreeInactive((int) $entity->id())) {
                throw BedUnavailableException::occupied((int) $entity->id());
            }

            return $entity;
        }

        // available/occupied: occupancy belongs to occupy()/release(), so an
        // occupied row keeps its status, and an `occupied` entity (possibly
        // stale) never writes the status at all.
        $statement = $this->connection->prepare(
            "UPDATE bed SET name = :name, daily_rate_cents = :daily_rate_cents,
                status = CASE WHEN status = 'occupied' OR :status_guard = 'occupied' THEN status ELSE :status END
             WHERE {$query->whereSql()}"
        );
        $statement->execute([
            ...$query->parameters(),
            ':name' => $entity->name(),
            ':daily_rate_cents' => $entity->dailyRateCents(),
            ':status_guard' => $entity->status(),
            ':status' => $entity->status(),
        ]);

        return $entity;
    }

    public function remove(object $entity): void
    {
        if (!$entity instanceof Bed) {
            throw new InvalidArgumentException('Expected a Bed entity');
        }

        $id = $entity->id();

        if ($id === null) {
            return;
        }

        $this->assertEntityTenant($entity->tenantId());

        $query = $this->tenantQuery()->andEquals('id', $id);

        $statement = $this->connection->prepare(
            "DELETE FROM bed WHERE {$query->whereSql()} AND current_hospitalization_id IS NULL"
        );
        $statement->execute($query->parameters());
    }

    private function isFreeInactive(int $bedId): bool
    {
        $query = $this->tenantQuery()
            ->andEquals('id', $bedId)
            ->andEquals('status', Bed::STATUS_INACTIVE);

        $statement = $this->connection->prepare(
            "SELECT 1 FROM bed WHERE {$query->whereSql()} AND current_hospitalization_id IS NULL LIMIT 1"
        );
        $statement->execute($query->parameters());

        return $statement->fetchColumn() !== false;
    }

    private function fetchOne(TenantQuery $query): ?Bed
    {
        $statement = $this->connection->prepare("SELECT * FROM bed WHERE {$query->whereSql()} LIMIT 1");
        $statement->execute($query->parameters());

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : Bed::reconstitute($row);
    }
}
