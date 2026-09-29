<?php

declare(strict_types=1);

namespace CentralVet\Persistence;

use CentralVet\Domain\Contract\SaleRepositoryInterface;
use CentralVet\Domain\Sale;
use CentralVet\Tenancy\TenantContext;
use DateTimeImmutable;
use InvalidArgumentException;
use PDO;

/**
 * PDO-backed persistence for the Sale aggregate. Every query starts from
 * TenantQuery::forTenant() (via AbstractTenantRepository::tenantQuery(),
 * ADR 0002); tenant scoping is never accepted from caller input.
 *
 * PENDING / DO NOT WIRE YET: this class depends on the `sale` table created
 * by the not-yet-applied migration
 * src/app/database/migrations/20260924_0005_phase4_procedure_stock_sale.sql.
 * It is prepared and syntax-checked (php -l) only; it must not be
 * constructed with a real PDO connection nor executed against the live
 * database until that migration has explicit SQL execution approval.
 *
 * @implements SaleRepositoryInterface<Sale>
 */
final class SaleRepository extends AbstractTenantRepository implements SaleRepositoryInterface
{
    public function __construct(TenantContext $context, private readonly PDO $connection)
    {
        parent::__construct($context);
    }

    public function findById(int|string $id): ?object
    {
        $query = $this->tenantQuery()->andEquals('id', (int) $id);

        $statement = $this->connection->prepare(
            "SELECT * FROM sale WHERE {$query->whereSql()} LIMIT 1"
        );
        $statement->execute($query->parameters());

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : self::hydrate($row);
    }

    /** Used by SaleService for a tutor's purchase history. */
    public function listByTutor(int $tutorId): array
    {
        $query = $this->tenantQuery()->andEquals('tutor_id', $tutorId);

        $statement = $this->connection->prepare(
            "SELECT * FROM sale WHERE {$query->whereSql()} ORDER BY sold_at DESC"
        );
        $statement->execute($query->parameters());

        $sales = [];

        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $sales[] = self::hydrate($row);
        }

        return $sales;
    }

    public function save(object $entity): object
    {
        if (!$entity instanceof Sale) {
            throw new InvalidArgumentException('Expected a Sale entity');
        }

        $this->assertEntityTenant($entity->tenantId());

        if ($entity->id() === null) {
            $statement = $this->connection->prepare(
                <<<'SQL'
                INSERT INTO sale (
                    tenant_id, system_unit_id, tutor_id, patient_id, encounter_id,
                    system_user_id, status, total_amount_cents, sold_at
                ) VALUES (
                    :tenant_id, :system_unit_id, :tutor_id, :patient_id, :encounter_id,
                    :system_user_id, :status, :total_amount_cents, :sold_at
                )
                SQL
            );
            $statement->execute([
                ':tenant_id' => $entity->tenantId(),
                ':system_unit_id' => $entity->systemUnitId(),
                ':tutor_id' => $entity->tutorId(),
                ':patient_id' => $entity->patientId(),
                ':encounter_id' => $entity->encounterId(),
                ':system_user_id' => $entity->systemUserId(),
                ':status' => $entity->status(),
                ':total_amount_cents' => $entity->totalAmountCents(),
                ':sold_at' => $entity->soldAt()->format('Y-m-d H:i:s.u'),
            ]);

            $entity->assignId((int) $this->connection->lastInsertId());

            return $entity;
        }

        // total_amount_cents/sold_at/etc. are set once at create() time and
        // never revised (T-06's plan exposes no "edit a sale" use case);
        // only status changes (e.g. a future cancel()) are ever re-saved.
        $query = $this->tenantQuery()->andEquals('id', $entity->id());

        $statement = $this->connection->prepare(
            "UPDATE sale SET status = :status WHERE {$query->whereSql()}"
        );
        $statement->execute([
            ...$query->parameters(),
            ':status' => $entity->status(),
        ]);

        return $entity;
    }

    public function remove(object $entity): void
    {
        if (!$entity instanceof Sale) {
            throw new InvalidArgumentException('Expected a Sale entity');
        }

        $id = $entity->id();

        if ($id === null) {
            return;
        }

        $this->assertEntityTenant($entity->tenantId());

        $query = $this->tenantQuery()->andEquals('id', $id);

        $statement = $this->connection->prepare("DELETE FROM sale WHERE {$query->whereSql()}");
        $statement->execute($query->parameters());
    }

    /** @param array<string, mixed> $row */
    private static function hydrate(array $row): Sale
    {
        return Sale::reconstitute(
            id: (int) $row['id'],
            tenantId: (int) $row['tenant_id'],
            systemUnitId: (int) $row['system_unit_id'],
            tutorId: (int) $row['tutor_id'],
            patientId: $row['patient_id'] !== null ? (int) $row['patient_id'] : null,
            encounterId: $row['encounter_id'] !== null ? (int) $row['encounter_id'] : null,
            systemUserId: (int) $row['system_user_id'],
            status: (string) $row['status'],
            totalAmountCents: (int) $row['total_amount_cents'],
            soldAt: new DateTimeImmutable((string) $row['sold_at']),
            createdAt: $row['created_at'] !== null ? new DateTimeImmutable((string) $row['created_at']) : null,
        );
    }
}
