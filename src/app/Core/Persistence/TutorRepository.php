<?php

declare(strict_types=1);

namespace CentralVet\Persistence;

use CentralVet\Domain\Contract\TutorRepositoryInterface;
use CentralVet\Domain\Tutor;
use CentralVet\Tenancy\TenantContext;
use DateTimeImmutable;
use InvalidArgumentException;
use PDO;

/**
 * PENDING / DO NOT WIRE YET: this class depends on the `tutor` table
 * created by the not-yet-applied migration
 * src/app/database/migrations/20260921_0002_phase1_clinic_core.sql. It is
 * prepared and syntax-checked (php -l) but must not be constructed with a
 * real PDO connection or executed against the live database until that
 * migration has been applied and explicitly approved.
 *
 * Every query starts from CentralVet\Persistence\TenantQuery::forTenant()
 * (via AbstractTenantRepository::tenantQuery()), so the tenant boundary can
 * never be widened by a caller-supplied parameter (ADR 0002).
 */
final class TutorRepository extends AbstractTenantRepository implements TutorRepositoryInterface
{
    public function __construct(TenantContext $context, private readonly PDO $connection)
    {
        parent::__construct($context);
    }

    public function findById(int|string $id): ?object
    {
        $query = $this->tenantQuery()->andEquals('id', (int) $id);

        $statement = $this->connection->prepare(
            "SELECT * FROM tutor WHERE {$query->whereSql()} LIMIT 1"
        );
        $statement->execute($query->parameters());

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : self::hydrate($row);
    }

    public function findByDocument(string $document): ?object
    {
        $query = $this->tenantQuery()->andEquals('document', $document);

        $statement = $this->connection->prepare(
            "SELECT * FROM tutor WHERE {$query->whereSql()} LIMIT 1"
        );
        $statement->execute($query->parameters());

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : self::hydrate($row);
    }

    public function search(string $term): array
    {
        $query = $this->tenantQuery();

        $statement = $this->connection->prepare(
            "SELECT * FROM tutor WHERE {$query->whereSql()}"
            . ' AND (full_name LIKE :term OR document LIKE :term OR phone LIKE :term)'
            . ' ORDER BY full_name ASC'
        );
        $statement->execute([...$query->parameters(), ':term' => '%' . $term . '%']);

        /** @var list<array<string, mixed>> $rows */
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        return array_map(self::hydrate(...), $rows);
    }

    public function save(object $entity): object
    {
        if (!$entity instanceof Tutor) {
            throw new InvalidArgumentException('Expected an instance of ' . Tutor::class);
        }

        $this->assertEntityTenant($entity->tenantId);

        if ($entity->id === null) {
            $statement = $this->connection->prepare(
                <<<'SQL'
                INSERT INTO tutor (tenant_id, public_id, full_name, document, phone, email, address)
                VALUES (:tenant_id, :public_id, :full_name, :document, :phone, :email, :address)
                SQL
            );
            $statement->execute([
                ':tenant_id' => $entity->tenantId,
                ':public_id' => $entity->publicId,
                ':full_name' => $entity->fullName,
                ':document' => $entity->document,
                ':phone' => $entity->phone,
                ':email' => $entity->email,
                ':address' => $entity->address,
            ]);

            return $entity->withId((int) $this->connection->lastInsertId());
        }

        $query = $this->tenantQuery()->andEquals('id', $entity->id);

        $statement = $this->connection->prepare(
            "UPDATE tutor SET full_name = :full_name, document = :document, phone = :phone,"
            . " email = :email, address = :address WHERE {$query->whereSql()}"
        );
        $statement->execute([
            ':full_name' => $entity->fullName,
            ':document' => $entity->document,
            ':phone' => $entity->phone,
            ':email' => $entity->email,
            ':address' => $entity->address,
            ...$query->parameters(),
        ]);

        return $entity;
    }

    public function remove(object $entity): void
    {
        if (!$entity instanceof Tutor || $entity->id === null) {
            throw new InvalidArgumentException('Expected a persisted instance of ' . Tutor::class);
        }

        $this->assertEntityTenant($entity->tenantId);

        $query = $this->tenantQuery()->andEquals('id', $entity->id);

        $statement = $this->connection->prepare("DELETE FROM tutor WHERE {$query->whereSql()}");
        $statement->execute($query->parameters());
    }

    /** @param array<string, mixed> $row */
    private static function hydrate(array $row): Tutor
    {
        return new Tutor(
            id: (int) $row['id'],
            tenantId: (int) $row['tenant_id'],
            publicId: (string) $row['public_id'],
            fullName: (string) $row['full_name'],
            document: $row['document'] !== null ? (string) $row['document'] : null,
            phone: (string) $row['phone'],
            email: $row['email'] !== null ? (string) $row['email'] : null,
            address: $row['address'] !== null ? (string) $row['address'] : null,
            createdAt: isset($row['created_at']) ? new DateTimeImmutable((string) $row['created_at']) : null,
            updatedAt: isset($row['updated_at']) ? new DateTimeImmutable((string) $row['updated_at']) : null,
        );
    }
}
