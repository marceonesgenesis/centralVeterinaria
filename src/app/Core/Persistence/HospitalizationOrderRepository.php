<?php

declare(strict_types=1);

namespace CentralVet\Persistence;

use CentralVet\Domain\Contract\HospitalizationOrderRepositoryInterface;
use CentralVet\Domain\HospitalizationOrder;
use CentralVet\Tenancy\TenantContext;
use DateTimeImmutable;
use InvalidArgumentException;
use PDO;

/**
 * PDO-backed persistence for the HospitalizationOrder aggregate
 * (`hospitalization_order`, migration 0010). Every query starts from
 * TenantQuery::forTenant() (ADR 0002). Only status/suspended_at change
 * after the insert (suspension); the prescription itself is immutable.
 *
 * @implements HospitalizationOrderRepositoryInterface<HospitalizationOrder>
 */
final class HospitalizationOrderRepository extends AbstractTenantRepository implements
    HospitalizationOrderRepositoryInterface
{
    public function __construct(TenantContext $context, private readonly PDO $connection)
    {
        parent::__construct($context);
    }

    public function findById(int|string $id): ?object
    {
        $query = $this->tenantQuery()->andEquals('id', (int) $id);

        $statement = $this->connection->prepare(
            "SELECT * FROM hospitalization_order WHERE {$query->whereSql()} LIMIT 1"
        );
        $statement->execute($query->parameters());

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : self::hydrate($row);
    }

    public function listByHospitalization(int $hospitalizationId): array
    {
        $query = $this->tenantQuery()->andEquals('hospitalization_id', $hospitalizationId);

        $statement = $this->connection->prepare(
            "SELECT * FROM hospitalization_order WHERE {$query->whereSql()} ORDER BY starts_at ASC, id ASC"
        );
        $statement->execute($query->parameters());

        return array_map(
            static fn (array $row): HospitalizationOrder => self::hydrate($row),
            $statement->fetchAll(PDO::FETCH_ASSOC),
        );
    }

    public function save(object $entity): object
    {
        if (!$entity instanceof HospitalizationOrder) {
            throw new InvalidArgumentException('Expected a HospitalizationOrder entity');
        }

        $this->assertEntityTenant($entity->tenantId());

        if ($entity->id() === null) {
            $statement = $this->connection->prepare(
                <<<'SQL'
                INSERT INTO hospitalization_order (
                    tenant_id, hospitalization_id, order_type, description_text, product_id,
                    quantity_per_administration, dose_text, route, frequency_hours,
                    starts_at, ends_at, status, prescribed_by_system_user_id, suspended_at
                ) VALUES (
                    :tenant_id, :hospitalization_id, :order_type, :description_text, :product_id,
                    :quantity_per_administration, :dose_text, :route, :frequency_hours,
                    :starts_at, :ends_at, :status, :prescribed_by_system_user_id, :suspended_at
                )
                SQL
            );
            $statement->execute([
                ':tenant_id' => $entity->tenantId(),
                ':hospitalization_id' => $entity->hospitalizationId(),
                ':order_type' => $entity->orderType(),
                ':description_text' => $entity->descriptionText(),
                ':product_id' => $entity->productId(),
                ':quantity_per_administration' => $entity->quantityPerAdministration(),
                ':dose_text' => $entity->doseText(),
                ':route' => $entity->route(),
                ':frequency_hours' => $entity->frequencyHours(),
                ':starts_at' => self::timestamp($entity->startsAt()),
                ':ends_at' => self::timestamp($entity->endsAt()),
                ':status' => $entity->status(),
                ':prescribed_by_system_user_id' => $entity->prescribedBySystemUserId(),
                ':suspended_at' => self::timestamp($entity->suspendedAt()),
            ]);

            $entity->assignId((int) $this->connection->lastInsertId());

            return $entity;
        }

        $query = $this->tenantQuery()->andEquals('id', $entity->id());

        $statement = $this->connection->prepare(
            "UPDATE hospitalization_order SET status = :status, suspended_at = :suspended_at WHERE {$query->whereSql()}"
        );
        $statement->execute([
            ...$query->parameters(),
            ':status' => $entity->status(),
            ':suspended_at' => self::timestamp($entity->suspendedAt()),
        ]);

        return $entity;
    }

    public function remove(object $entity): void
    {
        if (!$entity instanceof HospitalizationOrder) {
            throw new InvalidArgumentException('Expected a HospitalizationOrder entity');
        }

        $id = $entity->id();

        if ($id === null) {
            return;
        }

        $this->assertEntityTenant($entity->tenantId());

        $query = $this->tenantQuery()->andEquals('id', $id);

        $statement = $this->connection->prepare("DELETE FROM hospitalization_order WHERE {$query->whereSql()}");
        $statement->execute($query->parameters());
    }

    /** @param array<string, mixed> $row */
    private static function hydrate(array $row): HospitalizationOrder
    {
        return HospitalizationOrder::reconstitute(
            (int) $row['id'],
            (int) $row['tenant_id'],
            (int) $row['hospitalization_id'],
            (string) $row['order_type'],
            (string) $row['description_text'],
            isset($row['product_id']) ? (int) $row['product_id'] : null,
            isset($row['quantity_per_administration']) ? (int) $row['quantity_per_administration'] : null,
            (string) $row['dose_text'],
            (string) $row['route'],
            (int) $row['frequency_hours'],
            new DateTimeImmutable((string) $row['starts_at']),
            new DateTimeImmutable((string) $row['ends_at']),
            (string) $row['status'],
            (int) $row['prescribed_by_system_user_id'],
            isset($row['suspended_at']) ? new DateTimeImmutable((string) $row['suspended_at']) : null,
        );
    }

    private static function timestamp(?DateTimeImmutable $value): ?string
    {
        return $value?->format('Y-m-d H:i:s.u');
    }
}
