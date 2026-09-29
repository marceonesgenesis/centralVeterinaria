<?php

declare(strict_types=1);

namespace CentralVet\Persistence;

use CentralVet\Domain\Contract\PrescriptionRepositoryInterface;
use CentralVet\Domain\Prescription;
use CentralVet\Domain\PrescriptionItem;
use CentralVet\Tenancy\TenantContext;
use InvalidArgumentException;
use PDO;

/**
 * PDO-backed persistence for the Prescription aggregate (header row in
 * `prescription`, its medication lines in the child table
 * `prescription_item`). Every query starts from TenantQuery::forTenant()
 * (ADR 0002); tenant scoping is never accepted from caller input.
 *
 * PENDING / DO NOT WIRE YET: this class depends on the `prescription` and
 * `prescription_item` tables created by the not-yet-applied migration
 * src/app/database/migrations/20260922_0004_phase3_prescription_exam_vaccine.sql.
 * It is prepared and syntax-checked (php -l) only; it must not be
 * constructed with a real PDO connection nor executed against the live
 * database until that migration has explicit SQL execution approval and has
 * actually been applied.
 *
 * save() persists the header and, only on first insert (a new aggregate),
 * its items in the same call — this repository has no separate API to
 * mutate items after creation because PrescriptionService (T-03) exposes
 * none either. This mirrors how EncounterRepository persists its own
 * single-table aggregate in one save() call, extended here to the
 * one-header/many-children shape prescription/prescription_item actually
 * have.
 *
 * @implements PrescriptionRepositoryInterface<Prescription>
 */
final class PrescriptionRepository extends AbstractTenantRepository implements PrescriptionRepositoryInterface
{
    public function __construct(TenantContext $context, private readonly PDO $connection)
    {
        parent::__construct($context);
    }

    public function findById(int|string $id): ?object
    {
        $query = $this->tenantQuery()->andEquals('id', (int) $id);

        $statement = $this->connection->prepare(
            "SELECT * FROM prescription WHERE {$query->whereSql()} LIMIT 1"
        );
        $statement->execute($query->parameters());

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->hydrateWithItems($row);
    }

    /** @return list<Prescription> */
    public function listByPatient(int $patientId): array
    {
        $query = $this->tenantQuery()->andEquals('patient_id', $patientId);

        $statement = $this->connection->prepare(
            "SELECT * FROM prescription WHERE {$query->whereSql()} ORDER BY created_at ASC"
        );
        $statement->execute($query->parameters());

        return $this->hydrateAllWithItems($statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return list<Prescription> */
    public function listByEncounter(int $encounterId): array
    {
        $query = $this->tenantQuery()->andEquals('encounter_id', $encounterId);

        $statement = $this->connection->prepare(
            "SELECT * FROM prescription WHERE {$query->whereSql()} ORDER BY created_at ASC"
        );
        $statement->execute($query->parameters());

        return $this->hydrateAllWithItems($statement->fetchAll(PDO::FETCH_ASSOC));
    }

    public function save(object $entity): object
    {
        if (!$entity instanceof Prescription) {
            throw new InvalidArgumentException('Expected a Prescription entity');
        }

        $this->assertEntityTenant($entity->tenantId());

        if ($entity->id() === null) {
            $statement = $this->connection->prepare(
                <<<'SQL'
                INSERT INTO prescription (
                    tenant_id, encounter_id, patient_id, professional_system_user_id,
                    orientation_text, status
                ) VALUES (
                    :tenant_id, :encounter_id, :patient_id, :professional_system_user_id,
                    :orientation_text, :status
                )
                SQL
            );
            $statement->execute([
                ':tenant_id' => $entity->tenantId(),
                ':encounter_id' => $entity->encounterId(),
                ':patient_id' => $entity->patientId(),
                ':professional_system_user_id' => $entity->professionalSystemUserId(),
                ':orientation_text' => $entity->orientationText(),
                ':status' => $entity->status(),
            ]);

            $entity->assignId((int) $this->connection->lastInsertId());

            $this->insertItems($entity);

            return $entity;
        }

        $query = $this->tenantQuery()->andEquals('id', $entity->id());

        $statement = $this->connection->prepare(
            <<<SQL
            UPDATE prescription SET
                orientation_text = :orientation_text, status = :status
            WHERE {$query->whereSql()}
            SQL
        );
        $statement->execute([
            ...$query->parameters(),
            ':orientation_text' => $entity->orientationText(),
            ':status' => $entity->status(),
        ]);

        return $entity;
    }

    public function remove(object $entity): void
    {
        if (!$entity instanceof Prescription) {
            throw new InvalidArgumentException('Expected a Prescription entity');
        }

        $id = $entity->id();

        if ($id === null) {
            return;
        }

        $this->assertEntityTenant($entity->tenantId());

        // prescription_item rows are removed first: no ON DELETE CASCADE is
        // declared in the migration (every FK there is
        // ON DELETE RESTRICT), so the parent row cannot be deleted while
        // children still reference it.
        $itemsQuery = $this->tenantQuery()->andEquals('prescription_id', $id);
        $deleteItems = $this->connection->prepare(
            "DELETE FROM prescription_item WHERE {$itemsQuery->whereSql()}"
        );
        $deleteItems->execute($itemsQuery->parameters());

        $query = $this->tenantQuery()->andEquals('id', $id);
        $statement = $this->connection->prepare("DELETE FROM prescription WHERE {$query->whereSql()}");
        $statement->execute($query->parameters());
    }

    /**
     * Inserts every item currently attached to $entity. Only used right
     * after the header INSERT above (see save()'s docblock): items are
     * assumed to have no id and no prescription_id yet, exactly as
     * PrescriptionItem::create() builds them.
     */
    private function insertItems(Prescription $entity): void
    {
        $prescriptionId = $entity->id();

        if ($prescriptionId === null) {
            throw new InvalidArgumentException('Prescription must be persisted before its items');
        }

        $statement = $this->connection->prepare(
            <<<'SQL'
            INSERT INTO prescription_item (
                tenant_id, prescription_id, medication_name, dose, dose_unit,
                route, frequency, duration
            ) VALUES (
                :tenant_id, :prescription_id, :medication_name, :dose, :dose_unit,
                :route, :frequency, :duration
            )
            SQL
        );

        foreach ($entity->items() as $item) {
            $item->assignPrescriptionId($prescriptionId);

            $statement->execute([
                ':tenant_id' => $item->tenantId(),
                ':prescription_id' => $prescriptionId,
                ':medication_name' => $item->medicationName(),
                ':dose' => $item->dose(),
                ':dose_unit' => $item->doseUnit(),
                ':route' => $item->route(),
                ':frequency' => $item->frequency(),
                ':duration' => $item->duration(),
            ]);

            $item->assignId((int) $this->connection->lastInsertId());
        }
    }

    /** @param array<string, mixed> $row */
    private function hydrateWithItems(array $row): Prescription
    {
        $prescription = Prescription::reconstitute($row);

        $itemsQuery = $this->tenantQuery()->andEquals('prescription_id', (int) $row['id']);
        $statement = $this->connection->prepare(
            "SELECT * FROM prescription_item WHERE {$itemsQuery->whereSql()} ORDER BY id ASC"
        );
        $statement->execute($itemsQuery->parameters());

        $items = [];

        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $itemRow) {
            $items[] = PrescriptionItem::reconstitute($itemRow);
        }

        return $prescription->withItems($items);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<Prescription>
     */
    private function hydrateAllWithItems(array $rows): array
    {
        $prescriptions = [];

        foreach ($rows as $row) {
            $prescriptions[] = $this->hydrateWithItems($row);
        }

        return $prescriptions;
    }
}
