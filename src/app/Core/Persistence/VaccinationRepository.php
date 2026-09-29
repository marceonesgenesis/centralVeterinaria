<?php

declare(strict_types=1);

namespace CentralVet\Persistence;

use CentralVet\Domain\Contract\VaccinationRepositoryInterface;
use CentralVet\Domain\Vaccination;
use CentralVet\Tenancy\TenantContext;
use InvalidArgumentException;
use PDO;

/**
 * PDO-backed persistence for the Vaccination aggregate. Every query starts
 * from TenantQuery::forTenant() (ADR 0002); tenant scoping is never
 * accepted from caller input.
 *
 * PENDING / DO NOT WIRE YET: this class depends on the `vaccination` table
 * created by the not-yet-applied migration
 * src/app/database/migrations/20260922_0004_phase3_prescription_exam_vaccine.sql.
 * It is prepared and syntax-checked (php -l) only; it must not be
 * constructed with a real PDO connection nor executed against the live
 * database until that migration has explicit SQL execution approval and has
 * actually been applied.
 *
 * @implements VaccinationRepositoryInterface<Vaccination>
 */
final class VaccinationRepository extends AbstractTenantRepository implements VaccinationRepositoryInterface
{
    public function __construct(TenantContext $context, private readonly PDO $connection)
    {
        parent::__construct($context);
    }

    public function findById(int|string $id): ?object
    {
        $query = $this->tenantQuery()->andEquals('id', (int) $id);

        $statement = $this->connection->prepare(
            "SELECT * FROM vaccination WHERE {$query->whereSql()} LIMIT 1"
        );
        $statement->execute($query->parameters());

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->hydrate($row);
    }

    /** Used by VaccinationService::historyByPatient() to build the vaccination card. */
    public function listByPatient(int $patientId): array
    {
        $query = $this->tenantQuery()->andEquals('patient_id', $patientId);

        $statement = $this->connection->prepare(
            "SELECT * FROM vaccination WHERE {$query->whereSql()} ORDER BY applied_at ASC"
        );
        $statement->execute($query->parameters());

        return $this->hydrateAll($statement);
    }

    public function listByEncounter(int $encounterId): array
    {
        $query = $this->tenantQuery()->andEquals('encounter_id', $encounterId);

        $statement = $this->connection->prepare(
            "SELECT * FROM vaccination WHERE {$query->whereSql()} ORDER BY applied_at ASC"
        );
        $statement->execute($query->parameters());

        return $this->hydrateAll($statement);
    }

    public function save(object $entity): object
    {
        if (!$entity instanceof Vaccination) {
            throw new InvalidArgumentException('Expected a Vaccination entity');
        }

        $this->assertEntityTenant($entity->tenantId());

        if ($entity->id() === null) {
            $statement = $this->connection->prepare(
                <<<'SQL'
                INSERT INTO vaccination (
                    tenant_id, encounter_id, patient_id, vaccine_catalog_item_id, lot,
                    expiry_date, dose_number, professional_system_user_id, applied_at, next_dose_at
                ) VALUES (
                    :tenant_id, :encounter_id, :patient_id, :vaccine_catalog_item_id, :lot,
                    :expiry_date, :dose_number, :professional_system_user_id, :applied_at, :next_dose_at
                )
                SQL
            );
            $statement->execute([
                ':tenant_id' => $entity->tenantId(),
                ':encounter_id' => $entity->encounterId(),
                ':patient_id' => $entity->patientId(),
                ':vaccine_catalog_item_id' => $entity->vaccineCatalogItemId(),
                ':lot' => $entity->lot(),
                ':expiry_date' => $entity->expiryDate()?->format('Y-m-d'),
                ':dose_number' => $entity->doseNumber(),
                ':professional_system_user_id' => $entity->professionalSystemUserId(),
                ':applied_at' => $entity->appliedAt()->format('Y-m-d H:i:s.u'),
                ':next_dose_at' => $entity->nextDoseAt()?->format('Y-m-d'),
            ]);

            $entity->assignId((int) $this->connection->lastInsertId());

            return $entity;
        }

        // Vaccination has no update use case in this plan (a vaccination is
        // recorded once, never amended) — save() only ever inserts. This
        // branch exists solely so save() still satisfies RepositoryInterface
        // for an already-identified entity instead of silently doing
        // nothing.
        throw new InvalidArgumentException('Vaccination records are immutable once persisted');
    }

    public function remove(object $entity): void
    {
        if (!$entity instanceof Vaccination) {
            throw new InvalidArgumentException('Expected a Vaccination entity');
        }

        $id = $entity->id();

        if ($id === null) {
            return;
        }

        $this->assertEntityTenant($entity->tenantId());

        $query = $this->tenantQuery()->andEquals('id', $id);

        $statement = $this->connection->prepare("DELETE FROM vaccination WHERE {$query->whereSql()}");
        $statement->execute($query->parameters());
    }

    /** @return list<Vaccination> */
    private function hydrateAll(\PDOStatement $statement): array
    {
        $vaccinations = [];

        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $vaccinations[] = $this->hydrate($row);
        }

        return $vaccinations;
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): Vaccination
    {
        return Vaccination::reconstitute($row);
    }
}
