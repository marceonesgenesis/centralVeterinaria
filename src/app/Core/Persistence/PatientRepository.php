<?php

declare(strict_types=1);

namespace CentralVet\Persistence;

use CentralVet\Domain\Contract\PatientRepositoryInterface;
use CentralVet\Domain\Patient;
use CentralVet\Tenancy\TenantContext;
use InvalidArgumentException;
use PDO;

/**
 * PDO-backed persistence for the Patient aggregate.
 *
 * PENDING / DO NOT WIRE YET: depends on the `patient` table created by the
 * not-yet-applied migration
 * src/app/database/migrations/20260921_0002_phase1_clinic_core.sql (ADR
 * 0003). This class is prepared and syntax-checked (php -l) only; it must
 * not be constructed with a real PDO connection or executed against the
 * live database until that migration has explicit SQL execution approval
 * and has actually been applied.
 *
 * Every query starts from `AbstractTenantRepository::tenantQuery()`, which
 * always seeds the predicate list with `tenant_id = :tenant_scope_id`
 * (ADR 0002) — the tenant filter is never a plain `andEquals()` call and can
 * never be overridden by caller input.
 *
 * @implements PatientRepositoryInterface<Patient>
 */
final class PatientRepository extends AbstractTenantRepository implements PatientRepositoryInterface
{
    public function __construct(TenantContext $context, private readonly PDO $connection)
    {
        parent::__construct($context);
    }

    public function findById(int|string $id): ?object
    {
        $query = $this->tenantQuery();
        $statement = $this->connection->prepare(
            "SELECT * FROM patient WHERE {$query->whereSql()} AND id = :patient_id"
        );
        $statement->execute([...$query->parameters(), ':patient_id' => (int) $id]);

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? Patient::fromRow($row) : null;
    }

    public function findByTutor(int $tutorId): array
    {
        $query = $this->tenantQuery()->andEquals('tutor_id', $tutorId);
        $statement = $this->connection->prepare(
            "SELECT * FROM patient WHERE {$query->whereSql()} ORDER BY name"
        );
        $statement->execute($query->parameters());

        return array_map(
            static fn (array $row): Patient => Patient::fromRow($row),
            $statement->fetchAll(PDO::FETCH_ASSOC),
        );
    }

    public function search(string $term): array
    {
        $query = $this->tenantQuery();
        $statement = $this->connection->prepare(
            "SELECT * FROM patient WHERE {$query->whereSql()} AND name LIKE :term ORDER BY name"
        );
        $statement->execute([...$query->parameters(), ':term' => '%' . $term . '%']);

        return array_map(
            static fn (array $row): Patient => Patient::fromRow($row),
            $statement->fetchAll(PDO::FETCH_ASSOC),
        );
    }

    public function save(object $entity): object
    {
        if (!$entity instanceof Patient) {
            throw new InvalidArgumentException('PatientRepository::save expects a Patient entity');
        }

        $this->assertEntityTenant($entity->tenantId);

        if ($entity->id === null) {
            $statement = $this->connection->prepare(
                <<<'SQL'
                INSERT INTO patient (
                    tenant_id, tutor_id, name, species, breed, sex,
                    birth_date, weight_kg, color, notes
                ) VALUES (
                    :tenant_id, :tutor_id, :name, :species, :breed, :sex,
                    :birth_date, :weight_kg, :color, :notes
                )
                SQL
            );
            $statement->execute([
                ':tenant_id' => $entity->tenantId,
                ':tutor_id' => $entity->tutorId,
                ':name' => $entity->name,
                ':species' => $entity->species,
                ':breed' => $entity->breed,
                ':sex' => $entity->sex,
                ':birth_date' => $entity->birthDate,
                ':weight_kg' => $entity->weightKg,
                ':color' => $entity->color,
                ':notes' => $entity->notes,
            ]);

            return $entity->withId((int) $this->connection->lastInsertId());
        }

        $query = $this->tenantQuery()->andEquals('id', $entity->id);
        $statement = $this->connection->prepare(
            <<<SQL
            UPDATE patient SET
                tutor_id = :tutor_id, name = :name, species = :species,
                breed = :breed, sex = :sex, birth_date = :birth_date,
                weight_kg = :weight_kg, color = :color, notes = :notes
            WHERE {$query->whereSql()}
            SQL
        );
        $statement->execute([
            ...$query->parameters(),
            ':tutor_id' => $entity->tutorId,
            ':name' => $entity->name,
            ':species' => $entity->species,
            ':breed' => $entity->breed,
            ':sex' => $entity->sex,
            ':birth_date' => $entity->birthDate,
            ':weight_kg' => $entity->weightKg,
            ':color' => $entity->color,
            ':notes' => $entity->notes,
        ]);

        return $entity;
    }

    public function remove(object $entity): void
    {
        if (!$entity instanceof Patient || $entity->id === null) {
            throw new InvalidArgumentException('PatientRepository::remove expects a persisted Patient entity');
        }

        $this->assertEntityTenant($entity->tenantId);

        $query = $this->tenantQuery()->andEquals('id', $entity->id);
        $statement = $this->connection->prepare("DELETE FROM patient WHERE {$query->whereSql()}");
        $statement->execute($query->parameters());
    }
}
