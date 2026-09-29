<?php

declare(strict_types=1);

namespace CentralVet\Persistence;

use CentralVet\Domain\Contract\ProcedureExecutionRepositoryInterface;
use CentralVet\Domain\ProcedureExecution;
use CentralVet\Tenancy\TenantContext;
use InvalidArgumentException;
use PDO;

/**
 * PDO-backed persistence for the ProcedureExecution aggregate. Every query
 * starts from TenantQuery::forTenant() (ADR 0002); tenant scoping is never
 * accepted from caller input.
 *
 * PENDING / DO NOT WIRE YET: this class depends on the `procedure_execution`
 * table created by the not-yet-applied migration
 * src/app/database/migrations/20260924_0005_phase4_procedure_stock_sale.sql.
 * It is prepared and syntax-checked (php -l) only; it must not be
 * constructed with a real PDO connection nor executed against the live
 * database until that migration has explicit SQL execution approval and has
 * actually been applied.
 *
 * @implements ProcedureExecutionRepositoryInterface<ProcedureExecution>
 */
final class ProcedureExecutionRepository extends AbstractTenantRepository implements
    ProcedureExecutionRepositoryInterface
{
    public function __construct(TenantContext $context, private readonly PDO $connection)
    {
        parent::__construct($context);
    }

    public function findById(int|string $id): ?object
    {
        $query = $this->tenantQuery()->andEquals('id', (int) $id);

        $statement = $this->connection->prepare(
            "SELECT * FROM procedure_execution WHERE {$query->whereSql()} LIMIT 1"
        );
        $statement->execute($query->parameters());

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->hydrate($row);
    }

    /** Used by ProcedureExecutionService::listByEncounter(). */
    public function listByEncounter(int $encounterId): array
    {
        $query = $this->tenantQuery()->andEquals('encounter_id', $encounterId);

        $statement = $this->connection->prepare(
            "SELECT * FROM procedure_execution WHERE {$query->whereSql()} ORDER BY executed_at ASC"
        );
        $statement->execute($query->parameters());

        return $this->hydrateAll($statement);
    }

    public function save(object $entity): object
    {
        if (!$entity instanceof ProcedureExecution) {
            throw new InvalidArgumentException('Expected a ProcedureExecution entity');
        }

        $this->assertEntityTenant($entity->tenantId());

        if ($entity->id() === null) {
            $statement = $this->connection->prepare(
                <<<'SQL'
                INSERT INTO procedure_execution (
                    tenant_id, encounter_id, patient_id, procedure_catalog_item_id,
                    professional_system_user_id, notes_text, executed_at
                ) VALUES (
                    :tenant_id, :encounter_id, :patient_id, :procedure_catalog_item_id,
                    :professional_system_user_id, :notes_text, :executed_at
                )
                SQL
            );
            $statement->execute([
                ':tenant_id' => $entity->tenantId(),
                ':encounter_id' => $entity->encounterId(),
                ':patient_id' => $entity->patientId(),
                ':procedure_catalog_item_id' => $entity->procedureCatalogItemId(),
                ':professional_system_user_id' => $entity->professionalSystemUserId(),
                ':notes_text' => $entity->notesText(),
                ':executed_at' => $entity->executedAt()->format('Y-m-d H:i:s.u'),
            ]);

            $entity->assignId((int) $this->connection->lastInsertId());

            return $entity;
        }

        // ProcedureExecution has no update use case in this plan (a
        // procedure execution is recorded once, never amended) — save()
        // only ever inserts. This branch exists solely so save() still
        // satisfies RepositoryInterface for an already-identified entity
        // instead of silently doing nothing.
        throw new InvalidArgumentException('ProcedureExecution records are immutable once persisted');
    }

    public function remove(object $entity): void
    {
        if (!$entity instanceof ProcedureExecution) {
            throw new InvalidArgumentException('Expected a ProcedureExecution entity');
        }

        $id = $entity->id();

        if ($id === null) {
            return;
        }

        $this->assertEntityTenant($entity->tenantId());

        $query = $this->tenantQuery()->andEquals('id', $id);

        $statement = $this->connection->prepare("DELETE FROM procedure_execution WHERE {$query->whereSql()}");
        $statement->execute($query->parameters());
    }

    /** @return list<ProcedureExecution> */
    private function hydrateAll(\PDOStatement $statement): array
    {
        $executions = [];

        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $executions[] = $this->hydrate($row);
        }

        return $executions;
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): ProcedureExecution
    {
        return ProcedureExecution::reconstitute($row);
    }
}
