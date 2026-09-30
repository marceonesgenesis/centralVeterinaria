<?php

declare(strict_types=1);

namespace CentralVet\Persistence;

use CentralVet\Domain\Contract\PrescriptionTemplateRepositoryInterface;
use CentralVet\Domain\PrescriptionTemplate;
use CentralVet\Tenancy\TenantContext;
use InvalidArgumentException;
use PDO;

/**
 * PDO-backed persistence for PrescriptionTemplate (rodada 2, T-13): header
 * in `prescription_template`, lines in `prescription_template_item` with
 * `position` 1..n (migration 0007). Every query starts from
 * TenantQuery::forTenant() (ADR 0002).
 *
 * save() of an existing template updates the header and replaces its lines
 * (delete + insert), so position always stays 1..n.
 *
 * @implements PrescriptionTemplateRepositoryInterface<PrescriptionTemplate>
 */
final class PrescriptionTemplateRepository extends AbstractTenantRepository implements PrescriptionTemplateRepositoryInterface
{
    public function __construct(TenantContext $context, private readonly PDO $connection)
    {
        parent::__construct($context);
    }

    public function findById(int|string $id): ?object
    {
        return $this->findOneBy('id', (int) $id);
    }

    public function findByName(string $name): ?object
    {
        return $this->findOneBy('name', $name);
    }

    /** @return list<PrescriptionTemplate> */
    public function listAll(): array
    {
        $query = $this->tenantQuery();

        $statement = $this->connection->prepare(
            "SELECT * FROM prescription_template WHERE {$query->whereSql()} ORDER BY name ASC, id ASC"
        );
        $statement->execute($query->parameters());

        $templates = [];

        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $templates[] = $this->hydrate($row);
        }

        return $templates;
    }

    public function save(object $entity): object
    {
        if (!$entity instanceof PrescriptionTemplate) {
            throw new InvalidArgumentException('Expected a PrescriptionTemplate entity');
        }

        $this->assertEntityTenant($entity->tenantId());

        if ($entity->id() === null) {
            $statement = $this->connection->prepare(
                <<<'SQL'
                INSERT INTO prescription_template (
                    tenant_id, name, orientation_text, created_by_system_user_id
                ) VALUES (
                    :tenant_id, :name, :orientation_text, :created_by_system_user_id
                )
                SQL
            );
            $statement->execute([
                ':tenant_id' => $entity->tenantId(),
                ':name' => $entity->name(),
                ':orientation_text' => $entity->orientationText(),
                ':created_by_system_user_id' => $entity->createdBySystemUserId(),
            ]);

            $entity->assignId((int) $this->connection->lastInsertId());
            $this->insertItems($entity);

            return $entity;
        }

        // Another tenant's id (or a deleted one) is never touched: its lines
        // must not be replaced either.
        if ($this->findOneBy('id', (int) $entity->id()) === null) {
            return $entity;
        }

        $query = $this->tenantQuery()->andEquals('id', $entity->id());

        $statement = $this->connection->prepare(
            <<<SQL
            UPDATE prescription_template SET
                name = :name, orientation_text = :orientation_text
            WHERE {$query->whereSql()}
            SQL
        );
        $statement->execute([
            ...$query->parameters(),
            ':name' => $entity->name(),
            ':orientation_text' => $entity->orientationText(),
        ]);

        $this->deleteItems((int) $entity->id());
        $this->insertItems($entity);

        return $entity;
    }

    public function remove(object $entity): void
    {
        if (!$entity instanceof PrescriptionTemplate) {
            throw new InvalidArgumentException('Expected a PrescriptionTemplate entity');
        }

        $id = $entity->id();

        if ($id === null) {
            return;
        }

        $this->assertEntityTenant($entity->tenantId());

        // Items first, tenant-scoped (the FK also cascades, but the tenant
        // filter keeps this repository from touching another tenant's rows).
        $this->deleteItems($id);

        $query = $this->tenantQuery()->andEquals('id', $id);
        $statement = $this->connection->prepare("DELETE FROM prescription_template WHERE {$query->whereSql()}");
        $statement->execute($query->parameters());
    }

    private function findOneBy(string $column, int|string $value): ?PrescriptionTemplate
    {
        $query = $this->tenantQuery()->andEquals($column, $value);

        $statement = $this->connection->prepare(
            "SELECT * FROM prescription_template WHERE {$query->whereSql()} LIMIT 1"
        );
        $statement->execute($query->parameters());

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->hydrate($row);
    }

    private function insertItems(PrescriptionTemplate $entity): void
    {
        $statement = $this->connection->prepare(
            <<<'SQL'
            INSERT INTO prescription_template_item (
                tenant_id, template_id, position, medication_name, dose, dose_unit,
                route, frequency, duration
            ) VALUES (
                :tenant_id, :template_id, :position, :medication_name, :dose, :dose_unit,
                :route, :frequency, :duration
            )
            SQL
        );

        foreach ($entity->items() as $index => $item) {
            $statement->execute([
                ':tenant_id' => $entity->tenantId(),
                ':template_id' => $entity->id(),
                ':position' => $index + 1,
                ':medication_name' => $item['medication_name'],
                ':dose' => $item['dose'],
                ':dose_unit' => $item['dose_unit'],
                ':route' => $item['route'],
                ':frequency' => $item['frequency'],
                ':duration' => $item['duration'],
            ]);
        }
    }

    private function deleteItems(int $templateId): void
    {
        $query = $this->tenantQuery()->andEquals('template_id', $templateId);
        $statement = $this->connection->prepare(
            "DELETE FROM prescription_template_item WHERE {$query->whereSql()}"
        );
        $statement->execute($query->parameters());
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): PrescriptionTemplate
    {
        $query = $this->tenantQuery()->andEquals('template_id', (int) $row['id']);
        $statement = $this->connection->prepare(
            "SELECT * FROM prescription_template_item WHERE {$query->whereSql()} ORDER BY position ASC, id ASC"
        );
        $statement->execute($query->parameters());

        return PrescriptionTemplate::reconstitute($row, $statement->fetchAll(PDO::FETCH_ASSOC));
    }
}
