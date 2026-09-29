<?php

declare(strict_types=1);

namespace CentralVet\Persistence;

use CentralVet\Domain\Contract\ServiceRepositoryInterface;
use CentralVet\Domain\Service;
use CentralVet\Tenancy\TenantContext;
use DateTimeImmutable;
use InvalidArgumentException;
use PDO;

/**
 * PDO-backed persistence for the Service (catalog) aggregate. Every query
 * starts from TenantQuery::forTenant() (ADR 0002); tenant scoping is never
 * accepted from caller input.
 *
 * PENDING / DO NOT WIRE YET: this class depends on the `service` table
 * created by the not-yet-applied migration
 * src/app/database/migrations/20260921_0002_phase1_clinic_core.sql. It is
 * prepared and syntax-checked (php -l) only; it must not be constructed with
 * a real PDO connection nor executed against the live database until that
 * migration has explicit SQL execution approval.
 */
final class ServiceRepository extends AbstractTenantRepository implements ServiceRepositoryInterface
{
    public function __construct(TenantContext $context, private readonly PDO $connection)
    {
        parent::__construct($context);
    }

    public function findById(int|string $id): ?object
    {
        $query = $this->tenantQuery()->andEquals('id', (int) $id);

        $statement = $this->connection->prepare(
            "SELECT * FROM service WHERE {$query->whereSql()} LIMIT 1"
        );
        $statement->execute($query->parameters());

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->hydrate($row);
    }

    public function findByName(string $name): ?object
    {
        $query = $this->tenantQuery()->andEquals('name', $name);

        $statement = $this->connection->prepare(
            "SELECT * FROM service WHERE {$query->whereSql()} LIMIT 1"
        );
        $statement->execute($query->parameters());

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->hydrate($row);
    }

    public function listActive(): array
    {
        $query = $this->tenantQuery()->andEquals('active', 1);

        $statement = $this->connection->prepare(
            "SELECT * FROM service WHERE {$query->whereSql()} ORDER BY name ASC"
        );
        $statement->execute($query->parameters());

        $services = [];

        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $services[] = $this->hydrate($row);
        }

        return $services;
    }

    public function listAll(): array
    {
        $query = $this->tenantQuery();

        $statement = $this->connection->prepare(
            "SELECT * FROM service WHERE {$query->whereSql()} ORDER BY name ASC"
        );
        $statement->execute($query->parameters());

        $services = [];

        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $services[] = $this->hydrate($row);
        }

        return $services;
    }

    public function save(object $entity): object
    {
        if (!$entity instanceof Service) {
            throw new InvalidArgumentException('Expected a Service entity');
        }

        $this->assertEntityTenant($entity->tenantId());

        if ($entity->id() === null) {
            $statement = $this->connection->prepare(
                <<<'SQL'
                INSERT INTO service (tenant_id, name, category, duration_minutes, price_cents, active)
                VALUES (:tenant_id, :name, :category, :duration_minutes, :price_cents, :active)
                SQL
            );
            $statement->execute([
                ':tenant_id' => $entity->tenantId(),
                ':name' => $entity->name(),
                ':category' => $entity->category(),
                ':duration_minutes' => $entity->durationMinutes(),
                ':price_cents' => $entity->priceCents(),
                ':active' => $entity->isActive() ? 1 : 0,
            ]);

            $entity->assignId((int) $this->connection->lastInsertId());

            return $entity;
        }

        $query = $this->tenantQuery()->andEquals('id', $entity->id());

        $statement = $this->connection->prepare(
            "UPDATE service SET name = :name, category = :category, "
            . "duration_minutes = :duration_minutes, price_cents = :price_cents, "
            . "active = :active WHERE {$query->whereSql()}"
        );
        $statement->execute([
            ...$query->parameters(),
            ':name' => $entity->name(),
            ':category' => $entity->category(),
            ':duration_minutes' => $entity->durationMinutes(),
            ':price_cents' => $entity->priceCents(),
            ':active' => $entity->isActive() ? 1 : 0,
        ]);

        return $entity;
    }

    public function remove(object $entity): void
    {
        if (!$entity instanceof Service) {
            throw new InvalidArgumentException('Expected a Service entity');
        }

        $id = $entity->id();

        if ($id === null) {
            return;
        }

        $this->assertEntityTenant($entity->tenantId());

        $query = $this->tenantQuery()->andEquals('id', $id);

        $statement = $this->connection->prepare("DELETE FROM service WHERE {$query->whereSql()}");
        $statement->execute($query->parameters());
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): Service
    {
        return Service::reconstitute(
            id: (int) $row['id'],
            tenantId: (int) $row['tenant_id'],
            name: (string) $row['name'],
            category: $row['category'] !== null ? (string) $row['category'] : null,
            durationMinutes: (int) $row['duration_minutes'],
            priceCents: (int) $row['price_cents'],
            active: (bool) $row['active'],
            createdAt: $row['created_at'] !== null ? new DateTimeImmutable((string) $row['created_at']) : null,
            updatedAt: $row['updated_at'] !== null ? new DateTimeImmutable((string) $row['updated_at']) : null,
        );
    }
}
