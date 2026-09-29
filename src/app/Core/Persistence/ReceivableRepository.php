<?php

declare(strict_types=1);

namespace CentralVet\Persistence;

use CentralVet\Domain\Contract\ReceivableRepositoryInterface;
use CentralVet\Domain\Receivable;
use CentralVet\Tenancy\TenantContext;
use InvalidArgumentException;
use PDO;

/**
 * PDO-backed persistence for the Receivable aggregate (T-03). Every query
 * starts from TenantQuery::forTenant() (ADR 0002); tenant scoping is never
 * accepted from caller input.
 *
 * The `receivable` table was created by migration
 * src/app/database/migrations/20260925_0006_phase5_financial.sql, already
 * applied (Fase 5).
 *
 * @implements ReceivableRepositoryInterface<Receivable>
 */
final class ReceivableRepository extends AbstractTenantRepository implements ReceivableRepositoryInterface
{
    public function __construct(TenantContext $context, private readonly PDO $connection)
    {
        parent::__construct($context);
    }

    public function findById(int|string $id): ?object
    {
        $query = $this->tenantQuery()->andEquals('id', (int) $id);

        $statement = $this->connection->prepare(
            "SELECT * FROM receivable WHERE {$query->whereSql()} LIMIT 1"
        );
        $statement->execute($query->parameters());

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : self::hydrate($row);
    }

    /** Used by EncounterAccountService::close() (read-back potential) and by T-06's PaymentService. */
    public function findByEncounterAccountId(int $accountId): ?object
    {
        $query = $this->tenantQuery()->andEquals('encounter_account_id', $accountId);

        $statement = $this->connection->prepare(
            "SELECT * FROM receivable WHERE {$query->whereSql()} LIMIT 1"
        );
        $statement->execute($query->parameters());

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : self::hydrate($row);
    }

    /**
     * 'open'/'partially_paid' are fixed literals appended to the WHERE
     * clause, not caller input, so they are safe outside TenantQuery's
     * parameter binding (same convention as
     * ExamRequestRepository::listPending()'s 'requested' literal).
     *
     * @return list<Receivable>
     */
    public function listOpen(): array
    {
        $query = $this->tenantQuery();

        $statement = $this->connection->prepare(
            "SELECT * FROM receivable WHERE {$query->whereSql()} "
            . "AND status IN ('" . Receivable::STATUS_OPEN . "', '" . Receivable::STATUS_PARTIALLY_PAID . "') "
            . 'ORDER BY id ASC'
        );
        $statement->execute($query->parameters());

        return $this->hydrateAll($statement);
    }

    public function save(object $entity): object
    {
        if (!$entity instanceof Receivable) {
            throw new InvalidArgumentException('Expected a Receivable entity');
        }

        $this->assertEntityTenant($entity->tenantId());

        if ($entity->id() === null) {
            $statement = $this->connection->prepare(
                <<<'SQL'
                INSERT INTO receivable (
                    tenant_id, encounter_account_id, tutor_id, total_cents, paid_cents, status
                ) VALUES (
                    :tenant_id, :encounter_account_id, :tutor_id, :total_cents, :paid_cents, :status
                )
                SQL
            );
            $statement->execute([
                ':tenant_id' => $entity->tenantId(),
                ':encounter_account_id' => $entity->encounterAccountId(),
                ':tutor_id' => $entity->tutorId(),
                ':total_cents' => $entity->totalCents(),
                ':paid_cents' => $entity->paidCents(),
                ':status' => $entity->status(),
            ]);

            $entity->assignId((int) $this->connection->lastInsertId());

            return $entity;
        }

        // encounter_account_id/tutor_id/total_cents are set once at open()
        // time (T-03, EncounterAccountService::close()) and never revised;
        // only paid_cents/status change afterwards, via a future
        // PaymentService (T-06) recording payments against this receivable.
        $query = $this->tenantQuery()->andEquals('id', $entity->id());

        $statement = $this->connection->prepare(
            "UPDATE receivable SET paid_cents = :paid_cents, status = :status WHERE {$query->whereSql()}"
        );
        $statement->execute([
            ...$query->parameters(),
            ':paid_cents' => $entity->paidCents(),
            ':status' => $entity->status(),
        ]);

        return $entity;
    }

    public function remove(object $entity): void
    {
        if (!$entity instanceof Receivable) {
            throw new InvalidArgumentException('Expected a Receivable entity');
        }

        $id = $entity->id();

        if ($id === null) {
            return;
        }

        $this->assertEntityTenant($entity->tenantId());

        $query = $this->tenantQuery()->andEquals('id', $id);

        $statement = $this->connection->prepare("DELETE FROM receivable WHERE {$query->whereSql()}");
        $statement->execute($query->parameters());
    }

    /** @return list<Receivable> */
    private function hydrateAll(\PDOStatement $statement): array
    {
        $receivables = [];

        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $receivables[] = self::hydrate($row);
        }

        return $receivables;
    }

    /** @param array<string, mixed> $row */
    private static function hydrate(array $row): Receivable
    {
        return Receivable::reconstitute($row);
    }
}
