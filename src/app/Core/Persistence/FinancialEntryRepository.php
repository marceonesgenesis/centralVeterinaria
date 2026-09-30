<?php

declare(strict_types=1);

namespace CentralVet\Persistence;

use CentralVet\Domain\Contract\FinancialEntryRepositoryInterface;
use CentralVet\Domain\FinancialEntry;
use CentralVet\Tenancy\TenantContext;
use DateTimeImmutable;
use InvalidArgumentException;
use PDO;

/**
 * PDO-backed persistence for the FinancialEntry aggregate: an append-only
 * ledger, so save() is expected to always insert a brand-new row (an entry
 * is never edited after creation) — same shape as
 * CentralVet\Persistence\StockMovementRepository (Phase 4). Every query
 * starts from TenantQuery::forTenant() (via
 * AbstractTenantRepository::tenantQuery(), ADR 0002); tenant scoping is
 * never accepted from caller input.
 *
 * PENDING / DO NOT WIRE YET: this class depends on the `financial_entry`
 * table created by the not-yet-applied migration
 * src/app/database/migrations/20260925_0006_phase5_financial.sql. It is
 * prepared and syntax-checked (php -l) only; it must not be constructed
 * with a real PDO connection nor executed against the live database until
 * that migration has explicit SQL execution approval.
 */
final class FinancialEntryRepository extends AbstractTenantRepository implements FinancialEntryRepositoryInterface
{
    public function __construct(TenantContext $context, private readonly PDO $connection)
    {
        parent::__construct($context);
    }

    public function findById(int|string $id): ?object
    {
        $query = $this->tenantQuery()->andEquals('id', (int) $id);

        $statement = $this->connection->prepare(
            "SELECT * FROM financial_entry WHERE {$query->whereSql()} LIMIT 1"
        );
        $statement->execute($query->parameters());

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : self::hydrate($row);
    }

    public function listBySystemUnitAndPeriod(int $systemUnitId, string $from, string $to): array
    {
        $query = $this->tenantQuery()->andEquals('system_unit_id', $systemUnitId);

        $statement = $this->connection->prepare(
            "SELECT * FROM financial_entry WHERE {$query->whereSql()} "
            . 'AND occurred_at >= :period_from AND occurred_at <= :period_to '
            . 'ORDER BY occurred_at ASC, id ASC'
        );
        $statement->execute([
            ...$query->parameters(),
            ':period_from' => $from,
            ':period_to' => $to,
        ]);

        $entries = [];

        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $entries[] = self::hydrate($row);
        }

        return $entries;
    }

    public function save(object $entity): object
    {
        if (!$entity instanceof FinancialEntry) {
            throw new InvalidArgumentException('Expected a FinancialEntry entity');
        }

        $this->assertEntityTenant($entity->tenantId());

        if ($entity->id() === null) {
            $statement = $this->connection->prepare(
                <<<'SQL'
                INSERT INTO financial_entry (
                    tenant_id, system_unit_id, entry_type, category, amount_cents,
                    reference_type, reference_id, occurred_at, system_user_id, payment_method
                ) VALUES (
                    :tenant_id, :system_unit_id, :entry_type, :category, :amount_cents,
                    :reference_type, :reference_id, :occurred_at, :system_user_id, :payment_method
                )
                SQL
            );
            $statement->execute([
                ':tenant_id' => $entity->tenantId(),
                ':system_unit_id' => $entity->systemUnitId(),
                ':entry_type' => $entity->entryType(),
                ':category' => $entity->category(),
                ':amount_cents' => $entity->amountCents(),
                ':reference_type' => $entity->referenceType(),
                ':reference_id' => $entity->referenceId(),
                ':occurred_at' => $entity->occurredAt()->format('Y-m-d H:i:s.u'),
                ':system_user_id' => $entity->systemUserId(),
                ':payment_method' => $entity->paymentMethod(),
            ]);

            $entity->assignId((int) $this->connection->lastInsertId());

            return $entity;
        }

        // financial_entry is append-only (see class docblock); this branch
        // is unreachable via FinancialEntryService::record() and exists
        // only to satisfy RepositoryInterface::save()'s insert-or-update
        // contract, same as StockMovementRepository::save() (Phase 4).
        $query = $this->tenantQuery()->andEquals('id', $entity->id());

        $statement = $this->connection->prepare(
            "UPDATE financial_entry SET category = :category WHERE {$query->whereSql()}"
        );
        $statement->execute([
            ...$query->parameters(),
            ':category' => $entity->category(),
        ]);

        return $entity;
    }

    public function remove(object $entity): void
    {
        if (!$entity instanceof FinancialEntry) {
            throw new InvalidArgumentException('Expected a FinancialEntry entity');
        }

        $id = $entity->id();

        if ($id === null) {
            return;
        }

        $this->assertEntityTenant($entity->tenantId());

        $query = $this->tenantQuery()->andEquals('id', $id);

        $statement = $this->connection->prepare("DELETE FROM financial_entry WHERE {$query->whereSql()}");
        $statement->execute($query->parameters());
    }

    /** @param array<string, mixed> $row */
    private static function hydrate(array $row): FinancialEntry
    {
        return FinancialEntry::reconstitute(
            id: (int) $row['id'],
            tenantId: (int) $row['tenant_id'],
            systemUnitId: (int) $row['system_unit_id'],
            entryType: (string) $row['entry_type'],
            category: (string) $row['category'],
            amountCents: (int) $row['amount_cents'],
            referenceType: $row['reference_type'] !== null ? (string) $row['reference_type'] : null,
            referenceId: $row['reference_id'] !== null ? (int) $row['reference_id'] : null,
            occurredAt: new DateTimeImmutable((string) $row['occurred_at']),
            systemUserId: (int) $row['system_user_id'],
            createdAt: $row['created_at'] !== null ? new DateTimeImmutable((string) $row['created_at']) : null,
            paymentMethod: isset($row['payment_method']) ? (string) $row['payment_method'] : null,
        );
    }
}
