<?php

declare(strict_types=1);

namespace CentralVet\Persistence;

use CentralVet\Domain\Contract\EncounterAccountItemRepositoryInterface;
use CentralVet\Domain\EncounterAccountItem;
use CentralVet\Tenancy\TenantContext;
use InvalidArgumentException;
use PDO;

/**
 * PDO-backed persistence for the EncounterAccountItem aggregate (T-03).
 * Every query starts from TenantQuery::forTenant() (ADR 0002); tenant
 * scoping is never accepted from caller input.
 *
 * PENDING / DO NOT WIRE YET: this class depends on the
 * `encounter_account_item` table created by the not-yet-applied migration
 * src/app/database/migrations/20260925_0006_phase5_financial.sql. It is
 * prepared and syntax-checked (php -l) only; it must not be constructed
 * with a real PDO connection nor executed against the live database until
 * that migration has explicit SQL execution approval and has actually been
 * applied.
 *
 * @implements EncounterAccountItemRepositoryInterface<EncounterAccountItem>
 */
final class EncounterAccountItemRepository extends AbstractTenantRepository implements
    EncounterAccountItemRepositoryInterface
{
    public function __construct(TenantContext $context, private readonly PDO $connection)
    {
        parent::__construct($context);
    }

    public function findById(int|string $id): ?object
    {
        $query = $this->tenantQuery()->andEquals('id', (int) $id);

        $statement = $this->connection->prepare(
            "SELECT * FROM encounter_account_item WHERE {$query->whereSql()} LIMIT 1"
        );
        $statement->execute($query->parameters());

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : self::hydrate($row);
    }

    /**
     * Used by EncounterAccountService for its item-source existing-keys set
     * (syncAutomaticItems() idempotency), its items-sum recomputation
     * (refreshSubtotal()/applyDiscount()/close()), and directly by callers
     * reading an account's line items back.
     */
    public function listByAccount(int $accountId): array
    {
        $query = $this->tenantQuery()->andEquals('account_id', $accountId);

        $statement = $this->connection->prepare(
            "SELECT * FROM encounter_account_item WHERE {$query->whereSql()} ORDER BY created_at ASC, id ASC"
        );
        $statement->execute($query->parameters());

        $items = [];

        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $items[] = self::hydrate($row);
        }

        return $items;
    }

    public function save(object $entity): object
    {
        if (!$entity instanceof EncounterAccountItem) {
            throw new InvalidArgumentException('Expected an EncounterAccountItem entity');
        }

        $this->assertEntityTenant($entity->tenantId());

        if ($entity->id() === null) {
            $statement = $this->connection->prepare(
                <<<'SQL'
                INSERT INTO encounter_account_item (
                    tenant_id, account_id, source_type, source_id, description_text, amount_cents
                ) VALUES (
                    :tenant_id, :account_id, :source_type, :source_id, :description_text, :amount_cents
                )
                SQL
            );
            $statement->execute([
                ':tenant_id' => $entity->tenantId(),
                ':account_id' => $entity->accountId(),
                ':source_type' => $entity->sourceType(),
                ':source_id' => $entity->sourceId(),
                ':description_text' => $entity->descriptionText(),
                ':amount_cents' => $entity->amountCents(),
            ]);

            $entity->assignId((int) $this->connection->lastInsertId());

            return $entity;
        }

        // EncounterAccountItem has no update use case in this plan (a
        // billable line is added once, never amended) — save() only ever
        // inserts, same convention as ProcedureExecutionRepository::save().
        throw new InvalidArgumentException('EncounterAccountItem records are immutable once persisted');
    }

    public function remove(object $entity): void
    {
        if (!$entity instanceof EncounterAccountItem) {
            throw new InvalidArgumentException('Expected an EncounterAccountItem entity');
        }

        $id = $entity->id();

        if ($id === null) {
            return;
        }

        $this->assertEntityTenant($entity->tenantId());

        $query = $this->tenantQuery()->andEquals('id', $id);

        $statement = $this->connection->prepare("DELETE FROM encounter_account_item WHERE {$query->whereSql()}");
        $statement->execute($query->parameters());
    }

    /** @param array<string, mixed> $row */
    private static function hydrate(array $row): EncounterAccountItem
    {
        return EncounterAccountItem::reconstitute($row);
    }
}
