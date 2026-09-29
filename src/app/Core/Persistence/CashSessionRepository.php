<?php

declare(strict_types=1);

namespace CentralVet\Persistence;

use CentralVet\Domain\CashSession;
use CentralVet\Domain\Contract\CashSessionRepositoryInterface;
use CentralVet\Tenancy\TenantContext;
use DateTimeImmutable;
use InvalidArgumentException;
use PDO;

/**
 * PDO-backed persistence for the CashSession aggregate. Every query starts
 * from TenantQuery::forTenant() (via AbstractTenantRepository::tenantQuery(),
 * ADR 0002); tenant scoping is never accepted from caller input.
 *
 * PENDING / DO NOT WIRE YET: this class depends on the `cash_session` table
 * created by the not-yet-applied migration
 * src/app/database/migrations/20260925_0006_phase5_financial.sql. It is
 * prepared and syntax-checked (php -l) only; it must not be constructed
 * with a real PDO connection nor executed against the live database until
 * that migration has explicit SQL execution approval and has actually been
 * applied.
 */
final class CashSessionRepository extends AbstractTenantRepository implements CashSessionRepositoryInterface
{
    public function __construct(TenantContext $context, private readonly PDO $connection)
    {
        parent::__construct($context);
    }

    public function findById(int|string $id): ?object
    {
        $query = $this->tenantQuery()->andEquals('id', (int) $id);

        $statement = $this->connection->prepare(
            "SELECT * FROM cash_session WHERE {$query->whereSql()} LIMIT 1"
        );
        $statement->execute($query->parameters());

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : self::hydrate($row);
    }

    public function findOpenBySystemUnit(int $systemUnitId): ?object
    {
        $query = $this->tenantQuery()
            ->andEquals('system_unit_id', $systemUnitId)
            ->andEquals('status', CashSession::STATUS_OPEN);

        $statement = $this->connection->prepare(
            "SELECT * FROM cash_session WHERE {$query->whereSql()} ORDER BY id DESC LIMIT 1"
        );
        $statement->execute($query->parameters());

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : self::hydrate($row);
    }

    public function save(object $entity): object
    {
        if (!$entity instanceof CashSession) {
            throw new InvalidArgumentException('Expected a CashSession entity');
        }

        $this->assertEntityTenant($entity->tenantId());

        if ($entity->id() === null) {
            $statement = $this->connection->prepare(
                <<<'SQL'
                INSERT INTO cash_session (
                    tenant_id, system_unit_id, opened_by_system_user_id, opening_balance_cents,
                    closed_by_system_user_id, closing_balance_cents, status, opened_at, closed_at
                ) VALUES (
                    :tenant_id, :system_unit_id, :opened_by_system_user_id, :opening_balance_cents,
                    :closed_by_system_user_id, :closing_balance_cents, :status, :opened_at, :closed_at
                )
                SQL
            );
            $statement->execute([
                ':tenant_id' => $entity->tenantId(),
                ':system_unit_id' => $entity->systemUnitId(),
                ':opened_by_system_user_id' => $entity->openedBySystemUserId(),
                ':opening_balance_cents' => $entity->openingBalanceCents(),
                ':closed_by_system_user_id' => $entity->closedBySystemUserId(),
                ':closing_balance_cents' => $entity->closingBalanceCents(),
                ':status' => $entity->status(),
                ':opened_at' => $entity->openedAt()->format('Y-m-d H:i:s.u'),
                ':closed_at' => $entity->closedAt()?->format('Y-m-d H:i:s.u'),
            ]);

            $entity->assignId((int) $this->connection->lastInsertId());

            return $entity;
        }

        $query = $this->tenantQuery()->andEquals('id', $entity->id());

        $statement = $this->connection->prepare(
            <<<SQL
            UPDATE cash_session
            SET status = :status,
                closed_by_system_user_id = :closed_by_system_user_id,
                closing_balance_cents = :closing_balance_cents,
                closed_at = :closed_at
            WHERE {$query->whereSql()}
            SQL
        );
        $statement->execute([
            ...$query->parameters(),
            ':status' => $entity->status(),
            ':closed_by_system_user_id' => $entity->closedBySystemUserId(),
            ':closing_balance_cents' => $entity->closingBalanceCents(),
            ':closed_at' => $entity->closedAt()?->format('Y-m-d H:i:s.u'),
        ]);

        return $entity;
    }

    public function remove(object $entity): void
    {
        if (!$entity instanceof CashSession) {
            throw new InvalidArgumentException('Expected a CashSession entity');
        }

        $id = $entity->id();

        if ($id === null) {
            return;
        }

        $this->assertEntityTenant($entity->tenantId());

        $query = $this->tenantQuery()->andEquals('id', $id);

        $statement = $this->connection->prepare("DELETE FROM cash_session WHERE {$query->whereSql()}");
        $statement->execute($query->parameters());
    }

    /** @param array<string, mixed> $row */
    private static function hydrate(array $row): CashSession
    {
        return CashSession::reconstitute(
            id: (int) $row['id'],
            tenantId: (int) $row['tenant_id'],
            systemUnitId: (int) $row['system_unit_id'],
            openedBySystemUserId: (int) $row['opened_by_system_user_id'],
            openingBalanceCents: (int) $row['opening_balance_cents'],
            closedBySystemUserId: $row['closed_by_system_user_id'] !== null ? (int) $row['closed_by_system_user_id'] : null,
            closingBalanceCents: $row['closing_balance_cents'] !== null ? (int) $row['closing_balance_cents'] : null,
            status: (string) $row['status'],
            openedAt: new DateTimeImmutable((string) $row['opened_at']),
            closedAt: $row['closed_at'] !== null ? new DateTimeImmutable((string) $row['closed_at']) : null,
            createdAt: $row['created_at'] !== null ? new DateTimeImmutable((string) $row['created_at']) : null,
        );
    }
}
