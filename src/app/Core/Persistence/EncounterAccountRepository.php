<?php

declare(strict_types=1);

namespace CentralVet\Persistence;

use CentralVet\Domain\Contract\EncounterAccountRepositoryInterface;
use CentralVet\Domain\EncounterAccount;
use CentralVet\Tenancy\TenantContext;
use InvalidArgumentException;
use PDO;

/**
 * PDO-backed persistence for the EncounterAccount aggregate (T-03). Every
 * query starts from TenantQuery::forTenant() (via
 * AbstractTenantRepository::tenantQuery(), ADR 0002); tenant scoping is
 * never accepted from caller input.
 *
 * PENDING / DO NOT WIRE YET: this class depends on the `encounter_account`
 * table created by the not-yet-applied migration
 * src/app/database/migrations/20260925_0006_phase5_financial.sql. It is
 * prepared and syntax-checked (php -l) only; it must not be constructed
 * with a real PDO connection nor executed against the live database until
 * that migration has explicit SQL execution approval and has actually been
 * applied.
 *
 * @implements EncounterAccountRepositoryInterface<EncounterAccount>
 */
final class EncounterAccountRepository extends AbstractTenantRepository implements
    EncounterAccountRepositoryInterface
{
    public function __construct(TenantContext $context, private readonly PDO $connection)
    {
        parent::__construct($context);
    }

    public function findById(int|string $id): ?object
    {
        $query = $this->tenantQuery()->andEquals('id', (int) $id);

        $statement = $this->connection->prepare(
            "SELECT * FROM encounter_account WHERE {$query->whereSql()} LIMIT 1"
        );
        $statement->execute($query->parameters());

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : self::hydrate($row);
    }

    /** Used by EncounterAccountService::openOrGet() for its idempotency check. */
    public function findByEncounterId(int $encounterId): ?object
    {
        $query = $this->tenantQuery()->andEquals('encounter_id', $encounterId);

        $statement = $this->connection->prepare(
            "SELECT * FROM encounter_account WHERE {$query->whereSql()} LIMIT 1"
        );
        $statement->execute($query->parameters());

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : self::hydrate($row);
    }

    public function save(object $entity): object
    {
        if (!$entity instanceof EncounterAccount) {
            throw new InvalidArgumentException('Expected an EncounterAccount entity');
        }

        $this->assertEntityTenant($entity->tenantId());

        if ($entity->id() === null) {
            $statement = $this->connection->prepare(
                <<<'SQL'
                INSERT INTO encounter_account (
                    tenant_id, encounter_id, patient_id, tutor_id, system_unit_id, status,
                    subtotal_cents, discount_cents, discount_authorized_by_system_user_id,
                    total_cents, closed_at
                ) VALUES (
                    :tenant_id, :encounter_id, :patient_id, :tutor_id, :system_unit_id, :status,
                    :subtotal_cents, :discount_cents, :discount_authorized_by_system_user_id,
                    :total_cents, :closed_at
                )
                SQL
            );
            $statement->execute([
                ':tenant_id' => $entity->tenantId(),
                ':encounter_id' => $entity->encounterId(),
                ':patient_id' => $entity->patientId(),
                ':tutor_id' => $entity->tutorId(),
                ':system_unit_id' => $entity->systemUnitId(),
                ':status' => $entity->status(),
                ':subtotal_cents' => $entity->subtotalCents(),
                ':discount_cents' => $entity->discountCents(),
                ':discount_authorized_by_system_user_id' => $entity->discountAuthorizedBySystemUserId(),
                ':total_cents' => $entity->totalCents(),
                ':closed_at' => $entity->closedAt()?->format('Y-m-d H:i:s.u'),
            ]);

            $entity->assignId((int) $this->connection->lastInsertId());

            return $entity;
        }

        // encounter_id/patient_id/tutor_id/system_unit_id are set once at
        // open() time and never revised; only the mutable billing fields
        // (status, subtotal/discount/total, closed_at) are ever re-saved,
        // by EncounterAccountService's refreshSubtotal()/applyDiscount()/
        // close() flows.
        $query = $this->tenantQuery()->andEquals('id', $entity->id());

        $statement = $this->connection->prepare(
            <<<SQL
            UPDATE encounter_account SET
                status = :status,
                subtotal_cents = :subtotal_cents,
                discount_cents = :discount_cents,
                discount_authorized_by_system_user_id = :discount_authorized_by_system_user_id,
                total_cents = :total_cents,
                closed_at = :closed_at
            WHERE {$query->whereSql()}
            SQL
        );
        $statement->execute([
            ...$query->parameters(),
            ':status' => $entity->status(),
            ':subtotal_cents' => $entity->subtotalCents(),
            ':discount_cents' => $entity->discountCents(),
            ':discount_authorized_by_system_user_id' => $entity->discountAuthorizedBySystemUserId(),
            ':total_cents' => $entity->totalCents(),
            ':closed_at' => $entity->closedAt()?->format('Y-m-d H:i:s.u'),
        ]);

        return $entity;
    }

    public function remove(object $entity): void
    {
        if (!$entity instanceof EncounterAccount) {
            throw new InvalidArgumentException('Expected an EncounterAccount entity');
        }

        $id = $entity->id();

        if ($id === null) {
            return;
        }

        $this->assertEntityTenant($entity->tenantId());

        $query = $this->tenantQuery()->andEquals('id', $id);

        $statement = $this->connection->prepare("DELETE FROM encounter_account WHERE {$query->whereSql()}");
        $statement->execute($query->parameters());
    }

    /** @param array<string, mixed> $row */
    private static function hydrate(array $row): EncounterAccount
    {
        return EncounterAccount::reconstitute($row);
    }
}
