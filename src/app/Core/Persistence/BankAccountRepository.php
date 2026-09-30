<?php

declare(strict_types=1);

namespace CentralVet\Persistence;

use CentralVet\Domain\BankAccount;
use CentralVet\Domain\Contract\BankAccountRepositoryInterface;
use CentralVet\Tenancy\TenantContext;
use InvalidArgumentException;
use PDO;

/**
 * PDO-backed persistence for the BankAccount aggregate (rodada 2, T-15,
 * table `bank_account` from migration 0007). Every query starts from
 * TenantQuery::forTenant() (via AbstractTenantRepository::tenantQuery(),
 * ADR 0002); tenant scoping is never accepted from caller input, and the
 * UPDATE/DELETE carry the tenant filter so a forged id of another tenant
 * touches nothing. When the TenantContext carries a current unit, every
 * query (SELECT/UPDATE/DELETE) is also filtered by that system_unit_id, so
 * an account of another unit of the same tenant is invisible (T-15
 * rodada 1); a context without unit keeps the tenant-only scope.
 */
final class BankAccountRepository extends AbstractTenantRepository implements BankAccountRepositoryInterface
{
    private const DATETIME = 'Y-m-d H:i:s.u';

    public function __construct(TenantContext $context, private readonly PDO $connection)
    {
        parent::__construct($context);
    }

    public function findById(int|string $id): ?object
    {
        $query = $this->scopedQuery()->andEquals('id', (int) $id);

        return $this->fetchOne("SELECT * FROM bank_account WHERE {$query->whereSql()} LIMIT 1", $query->parameters());
    }

    public function findByName(int $systemUnitId, string $name): ?object
    {
        $query = $this->scopedQuery()
            ->andEquals('system_unit_id', $systemUnitId)
            ->andEquals('name', trim($name));

        return $this->fetchOne("SELECT * FROM bank_account WHERE {$query->whereSql()} LIMIT 1", $query->parameters());
    }

    public function listBySystemUnit(int $systemUnitId): array
    {
        $query = $this->scopedQuery()->andEquals('system_unit_id', $systemUnitId);

        $statement = $this->connection->prepare(
            "SELECT * FROM bank_account WHERE {$query->whereSql()} ORDER BY name ASC, id ASC"
        );
        $statement->execute($query->parameters());

        $accounts = [];

        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $accounts[] = BankAccount::reconstitute($row);
        }

        return $accounts;
    }

    public function save(object $entity): object
    {
        if (!$entity instanceof BankAccount) {
            throw new InvalidArgumentException('Expected a BankAccount entity');
        }

        $this->assertEntityTenant($entity->tenantId());

        $values = [
            ':name' => $entity->name(),
            ':bank_name' => $entity->bankName(),
            ':balance_cents' => $entity->balanceCents(),
            ':balance_updated_at' => $entity->balanceUpdatedAt()?->format(self::DATETIME),
            ':active' => $entity->isActive() ? 1 : 0,
        ];

        if ($entity->id() === null) {
            $currentUnit = $this->context->unitId();

            if ($currentUnit !== null && $entity->systemUnitId() !== $currentUnit) {
                throw new InvalidArgumentException('BankAccount belongs to another unit than the current one');
            }

            $statement = $this->connection->prepare(
                <<<'SQL'
                INSERT INTO bank_account (
                    tenant_id, system_unit_id, name, bank_name, balance_cents, balance_updated_at, active
                ) VALUES (
                    :tenant_id, :system_unit_id, :name, :bank_name, :balance_cents, :balance_updated_at, :active
                )
                SQL
            );
            $statement->execute([
                ':tenant_id' => $entity->tenantId(),
                ':system_unit_id' => $entity->systemUnitId(),
                ...$values,
            ]);

            $entity->assignId((int) $this->connection->lastInsertId());

            return $entity;
        }

        $query = $this->scopedQuery()->andEquals('id', $entity->id());

        $statement = $this->connection->prepare(
            'UPDATE bank_account SET name = :name, bank_name = :bank_name, balance_cents = :balance_cents, '
            . 'balance_updated_at = :balance_updated_at, active = :active '
            . "WHERE {$query->whereSql()}"
        );
        $statement->execute([...$query->parameters(), ...$values]);

        return $entity;
    }

    public function remove(object $entity): void
    {
        if (!$entity instanceof BankAccount) {
            throw new InvalidArgumentException('Expected a BankAccount entity');
        }

        $id = $entity->id();

        if ($id === null) {
            return;
        }

        $this->assertEntityTenant($entity->tenantId());

        $query = $this->scopedQuery()->andEquals('id', $id);

        $statement = $this->connection->prepare("DELETE FROM bank_account WHERE {$query->whereSql()}");
        $statement->execute($query->parameters());
    }

    private function scopedQuery(): TenantQuery
    {
        $query = $this->tenantQuery();
        $unitId = $this->context->unitId();

        return $unitId === null ? $query : $query->andEquals('system_unit_id', $unitId);
    }

    /** @param array<string, mixed> $parameters */
    private function fetchOne(string $sql, array $parameters): ?BankAccount
    {
        $statement = $this->connection->prepare($sql);
        $statement->execute($parameters);

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : BankAccount::reconstitute($row);
    }
}
