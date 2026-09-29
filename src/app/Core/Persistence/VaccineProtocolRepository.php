<?php

declare(strict_types=1);

namespace CentralVet\Persistence;

use CentralVet\Domain\Contract\VaccineProtocolRepositoryInterface;
use CentralVet\Domain\VaccineProtocol;
use CentralVet\Tenancy\TenantContext;
use InvalidArgumentException;
use PDO;

/**
 * PDO-backed persistence for the VaccineProtocol aggregate. Every query
 * starts from TenantQuery::forTenant() (ADR 0002); tenant scoping is never
 * accepted from caller input.
 *
 * PENDING / DO NOT WIRE YET: this class depends on the `vaccine_protocol`
 * table created by the not-yet-applied migration
 * src/app/database/migrations/20260922_0004_phase3_prescription_exam_vaccine.sql.
 * It is prepared and syntax-checked (php -l) only; it must not be
 * constructed with a real PDO connection nor executed against the live
 * database until that migration has explicit SQL execution approval and has
 * actually been applied.
 *
 * @implements VaccineProtocolRepositoryInterface<VaccineProtocol>
 */
final class VaccineProtocolRepository extends AbstractTenantRepository implements VaccineProtocolRepositoryInterface
{
    public function __construct(TenantContext $context, private readonly PDO $connection)
    {
        parent::__construct($context);
    }

    public function findById(int|string $id): ?object
    {
        $query = $this->tenantQuery()->andEquals('id', (int) $id);

        $statement = $this->connection->prepare(
            "SELECT * FROM vaccine_protocol WHERE {$query->whereSql()} LIMIT 1"
        );
        $statement->execute($query->parameters());

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * Used by VaccinationService::apply() to find, among a catalog item's
     * configured doses, the one matching dose_number + 1 of the dose just
     * applied, in order to compute next_dose_at.
     */
    public function listByVaccineCatalogItem(int $vaccineCatalogItemId): array
    {
        $query = $this->tenantQuery()->andEquals('vaccine_catalog_item_id', $vaccineCatalogItemId);

        $statement = $this->connection->prepare(
            "SELECT * FROM vaccine_protocol WHERE {$query->whereSql()} ORDER BY dose_number ASC"
        );
        $statement->execute($query->parameters());

        $protocols = [];

        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $protocols[] = $this->hydrate($row);
        }

        return $protocols;
    }

    public function save(object $entity): object
    {
        if (!$entity instanceof VaccineProtocol) {
            throw new InvalidArgumentException('Expected a VaccineProtocol entity');
        }

        $this->assertEntityTenant($entity->tenantId());

        if ($entity->id() === null) {
            $statement = $this->connection->prepare(
                <<<'SQL'
                INSERT INTO vaccine_protocol (
                    tenant_id, vaccine_catalog_item_id, dose_number, interval_days_from_previous
                ) VALUES (
                    :tenant_id, :vaccine_catalog_item_id, :dose_number, :interval_days_from_previous
                )
                SQL
            );
            $statement->execute([
                ':tenant_id' => $entity->tenantId(),
                ':vaccine_catalog_item_id' => $entity->vaccineCatalogItemId(),
                ':dose_number' => $entity->doseNumber(),
                ':interval_days_from_previous' => $entity->intervalDaysFromPrevious(),
            ]);

            $entity->assignId((int) $this->connection->lastInsertId());

            return $entity;
        }

        // vaccine_protocol has no mutable columns beyond its identifying
        // triplet (tenant/item/dose_number) and interval_days_from_previous;
        // an update only ever rewrites the interval.
        $query = $this->tenantQuery()->andEquals('id', $entity->id());

        $statement = $this->connection->prepare(
            <<<SQL
            UPDATE vaccine_protocol SET interval_days_from_previous = :interval_days_from_previous
            WHERE {$query->whereSql()}
            SQL
        );
        $statement->execute([
            ...$query->parameters(),
            ':interval_days_from_previous' => $entity->intervalDaysFromPrevious(),
        ]);

        return $entity;
    }

    public function remove(object $entity): void
    {
        if (!$entity instanceof VaccineProtocol) {
            throw new InvalidArgumentException('Expected a VaccineProtocol entity');
        }

        $id = $entity->id();

        if ($id === null) {
            return;
        }

        $this->assertEntityTenant($entity->tenantId());

        $query = $this->tenantQuery()->andEquals('id', $id);

        $statement = $this->connection->prepare("DELETE FROM vaccine_protocol WHERE {$query->whereSql()}");
        $statement->execute($query->parameters());
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): VaccineProtocol
    {
        return VaccineProtocol::reconstitute($row);
    }
}
