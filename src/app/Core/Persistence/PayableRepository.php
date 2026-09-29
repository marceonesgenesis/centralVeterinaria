<?php

declare(strict_types=1);

namespace CentralVet\Persistence;

use CentralVet\Domain\Contract\PayableRepositoryInterface;
use CentralVet\Domain\Payable;
use CentralVet\Tenancy\TenantContext;
use DateTimeImmutable;
use InvalidArgumentException;
use PDO;

/**
 * PDO-backed persistence for the Payable aggregate. Every query starts
 * from TenantQuery::forTenant() (via AbstractTenantRepository::tenantQuery(),
 * ADR 0002); tenant scoping is never accepted from caller input.
 *
 * listOpenBySystemUnit() orders by "due_date IS NULL, due_date ASC": bills
 * with a real due date come first, earliest first; bills with no due date
 * sort last, mirroring StockBatchRepository::listByProductOrderedByExpiry()'s
 * own null-last convention (Phase 4).
 *
 * PENDING / DO NOT WIRE YET: this class depends on the `payable` table
 * created by the not-yet-applied migration
 * src/app/database/migrations/20260925_0006_phase5_financial.sql. It is
 * prepared and syntax-checked (php -l) only; it must not be constructed
 * with a real PDO connection nor executed against the live database until
 * that migration has explicit SQL execution approval.
 */
final class PayableRepository extends AbstractTenantRepository implements PayableRepositoryInterface
{
    public function __construct(TenantContext $context, private readonly PDO $connection)
    {
        parent::__construct($context);
    }

    public function findById(int|string $id): ?object
    {
        $query = $this->tenantQuery()->andEquals('id', (int) $id);

        $statement = $this->connection->prepare(
            "SELECT * FROM payable WHERE {$query->whereSql()} LIMIT 1"
        );
        $statement->execute($query->parameters());

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : self::hydrate($row);
    }

    public function listOpenBySystemUnit(int $systemUnitId): array
    {
        $query = $this->tenantQuery()
            ->andEquals('system_unit_id', $systemUnitId)
            ->andEquals('status', Payable::STATUS_OPEN);

        $statement = $this->connection->prepare(
            "SELECT * FROM payable WHERE {$query->whereSql()} "
            . 'ORDER BY due_date IS NULL, due_date ASC, id ASC'
        );
        $statement->execute($query->parameters());

        $payables = [];

        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $payables[] = self::hydrate($row);
        }

        return $payables;
    }

    public function save(object $entity): object
    {
        if (!$entity instanceof Payable) {
            throw new InvalidArgumentException('Expected a Payable entity');
        }

        $this->assertEntityTenant($entity->tenantId());

        if ($entity->id() === null) {
            $statement = $this->connection->prepare(
                <<<'SQL'
                INSERT INTO payable (
                    tenant_id, system_unit_id, description_text, category, amount_cents,
                    due_date, status, paid_at, system_user_id
                ) VALUES (
                    :tenant_id, :system_unit_id, :description_text, :category, :amount_cents,
                    :due_date, :status, :paid_at, :system_user_id
                )
                SQL
            );
            $statement->execute([
                ':tenant_id' => $entity->tenantId(),
                ':system_unit_id' => $entity->systemUnitId(),
                ':description_text' => $entity->descriptionText(),
                ':category' => $entity->category(),
                ':amount_cents' => $entity->amountCents(),
                ':due_date' => $entity->dueDate()?->format('Y-m-d'),
                ':status' => $entity->status(),
                ':paid_at' => $entity->paidAt()?->format('Y-m-d H:i:s'),
                ':system_user_id' => $entity->systemUserId(),
            ]);

            $entity->assignId((int) $this->connection->lastInsertId());

            return $entity;
        }

        $query = $this->tenantQuery()->andEquals('id', $entity->id());

        $statement = $this->connection->prepare(
            'UPDATE payable SET description_text = :description_text, category = :category, '
            . 'amount_cents = :amount_cents, due_date = :due_date, '
            . 'status = :status, paid_at = :paid_at, updated_at = CURRENT_TIMESTAMP '
            . "WHERE {$query->whereSql()}"
        );
        $statement->execute([
            ...$query->parameters(),
            ':description_text' => $entity->descriptionText(),
            ':category' => $entity->category(),
            ':amount_cents' => $entity->amountCents(),
            ':due_date' => $entity->dueDate()?->format('Y-m-d'),
            ':status' => $entity->status(),
            ':paid_at' => $entity->paidAt()?->format('Y-m-d H:i:s'),
        ]);

        return $entity;
    }

    public function remove(object $entity): void
    {
        if (!$entity instanceof Payable) {
            throw new InvalidArgumentException('Expected a Payable entity');
        }

        $id = $entity->id();

        if ($id === null) {
            return;
        }

        $this->assertEntityTenant($entity->tenantId());

        $query = $this->tenantQuery()->andEquals('id', $id);

        $statement = $this->connection->prepare("DELETE FROM payable WHERE {$query->whereSql()}");
        $statement->execute($query->parameters());
    }

    /** @param array<string, mixed> $row */
    private static function hydrate(array $row): Payable
    {
        return Payable::reconstitute(
            id: (int) $row['id'],
            tenantId: (int) $row['tenant_id'],
            systemUnitId: (int) $row['system_unit_id'],
            descriptionText: (string) $row['description_text'],
            category: (string) $row['category'],
            amountCents: (int) $row['amount_cents'],
            dueDate: $row['due_date'] !== null ? new DateTimeImmutable((string) $row['due_date']) : null,
            status: (string) $row['status'],
            paidAt: $row['paid_at'] !== null ? new DateTimeImmutable((string) $row['paid_at']) : null,
            systemUserId: (int) $row['system_user_id'],
            createdAt: $row['created_at'] !== null ? new DateTimeImmutable((string) $row['created_at']) : null,
            updatedAt: $row['updated_at'] !== null ? new DateTimeImmutable((string) $row['updated_at']) : null,
        );
    }
}
