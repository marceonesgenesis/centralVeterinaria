<?php

declare(strict_types=1);

namespace CentralVet\Persistence;

use CentralVet\Domain\Contract\PrescriptionTemplateRepositoryInterface;
use CentralVet\Domain\PrescriptionTemplate;
use CentralVet\Tenancy\TenantContext;
use InvalidArgumentException;
use PDO;
use PDOException;

/**
 * PDO-backed persistence for PrescriptionTemplate (rodada 2, T-13): header
 * in `prescription_template`, lines in `prescription_template_item` with
 * `position` 1..n (migration 0007). Every query starts from
 * TenantQuery::forTenant() (ADR 0002).
 *
 * save() of an existing template updates the header and replaces its lines
 * (delete + insert), so position always stays 1..n. A name already used in
 * the tenant (unique key prescription_template_tenant_name_uq) surfaces as
 * the same InvalidArgumentException PrescriptionTemplateService raises, so
 * two concurrent saves cannot both land.
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

        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        if ($rows === []) {
            return [];
        }

        $itemsByTemplate = $this->itemRowsFor(array_map(static fn (array $row): int => (int) $row['id'], $rows));

        return array_map(
            static fn (array $row): PrescriptionTemplate => PrescriptionTemplate::reconstitute(
                $row,
                $itemsByTemplate[(int) $row['id']] ?? [],
            ),
            $rows,
        );
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
            $this->executeGuardingName($statement, [
                ':tenant_id' => $entity->tenantId(),
                ':name' => $entity->name(),
                ':orientation_text' => $entity->orientationText(),
                ':created_by_system_user_id' => $entity->createdBySystemUserId(),
            ], $entity->name());

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
        $this->executeGuardingName($statement, [
            ...$query->parameters(),
            ':name' => $entity->name(),
            ':orientation_text' => $entity->orientationText(),
        ], $entity->name());

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

    /**
     * Runs an INSERT/UPDATE of the header, turning a violation of the
     * tenant+name unique key into the service's duplicate-name message.
     *
     * @param array<string, mixed> $parameters
     */
    private function executeGuardingName(\PDOStatement $statement, array $parameters, string $name): void
    {
        try {
            $statement->execute($parameters);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000' && str_contains($e->getMessage(), 'prescription_template_tenant_name_uq')) {
                throw new InvalidArgumentException(
                    "A template named \"{$name}\" already exists for this tenant",
                    0,
                    $e,
                );
            }

            throw $e;
        }
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
        $id = (int) $row['id'];

        return PrescriptionTemplate::reconstitute($row, $this->itemRowsFor([$id])[$id] ?? []);
    }

    /**
     * Item rows of the given templates in one tenant-scoped query, grouped by
     * template id and ordered by position.
     *
     * @param list<int> $templateIds non-empty
     * @return array<int, list<array<string, mixed>>>
     */
    private function itemRowsFor(array $templateIds): array
    {
        $query = $this->tenantQuery();
        $placeholders = [];
        $parameters = $query->parameters();

        foreach (array_values($templateIds) as $index => $templateId) {
            $placeholders[] = ":template_id_{$index}";
            $parameters[":template_id_{$index}"] = $templateId;
        }

        $statement = $this->connection->prepare(
            "SELECT * FROM prescription_template_item WHERE {$query->whereSql()}"
            . ' AND template_id IN (' . implode(', ', $placeholders) . ')'
            . ' ORDER BY template_id ASC, position ASC, id ASC'
        );
        $statement->execute($parameters);

        $grouped = [];

        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $item) {
            $grouped[(int) $item['template_id']][] = $item;
        }

        return $grouped;
    }
}
