<?php

declare(strict_types=1);

namespace CentralVet\Tests\Integration;

use CentralVet\Domain\Prescription;
use CentralVet\Domain\PrescriptionItem;
use CentralVet\Persistence\PrescriptionRepository;
use CentralVet\Tenancy\TenantContext;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\MysqlIntegrationTestCase;
use DateTimeImmutable;

/**
 * PrescriptionRepository against the real MySQL schema (rodada 3, T-09):
 * `prescription.valid_until` (date only, rodada 2 T-13) was only covered by
 * PrescriptionServiceTest against a fake. Every row belongs to a throwaway
 * tenant created inside the test's transaction, rolled back in tearDown().
 */
final class PrescriptionRepositoryIntegrationTest extends MysqlIntegrationTestCase
{
    private int $tenantId;
    private int $userId;
    private int $patientId;
    private int $encounterId;

    public function setUp(): void
    {
        parent::setUp();

        $unitId = $this->pdo->query('SELECT MIN(id) FROM system_unit')->fetchColumn();
        Assert::true((int) $unitId > 0, 'Fixture requires at least one existing system_unit row');

        $this->userId = (int) $this->pdo->query('SELECT MIN(id) FROM system_users')->fetchColumn();
        Assert::true($this->userId > 0, 'Fixture requires at least one existing system_users row');

        $slug = 'r3-rx-valid-' . bin2hex(random_bytes(4));
        $this->tenantId = $this->insert('tenant', [
            'public_id' => $this->uuid(),
            'slug' => $slug,
            'legal_name' => 'Prescription repository test tenant (' . $slug . ')',
            'status' => 'active',
        ]);
        $tutorId = $this->insert('tutor', [
            'tenant_id' => $this->tenantId,
            'public_id' => $this->uuid(),
            'full_name' => 'Tutor da receita',
            'phone' => '11988887777',
        ]);
        $this->patientId = $this->insert('patient', [
            'tenant_id' => $this->tenantId,
            'tutor_id' => $tutorId,
            'name' => 'Thor (receita)',
            'species' => 'canino',
        ]);
        $this->encounterId = $this->insert('encounter', [
            'tenant_id' => $this->tenantId,
            'system_unit_id' => (int) $unitId,
            'patient_id' => $this->patientId,
            'professional_system_user_id' => $this->userId,
            'status' => 'in_progress',
            'started_at' => '2026-09-20 10:00:00.000000',
        ]);
    }

    public function testValidUntilRoundTripsThroughTheDatabase(): void
    {
        $repository = new PrescriptionRepository(TenantContext::authenticated($this->tenantId, $this->userId), $this->pdo);

        $dated = $this->newPrescription(new DateTimeImmutable('2026-10-15 17:45:00'));
        $undated = $this->newPrescription(null);
        $repository->save($dated);
        $repository->save($undated);

        $column = $this->pdo->prepare('SELECT valid_until FROM prescription WHERE id = :id');
        $column->execute(['id' => $dated->id()]);
        Assert::same('2026-10-15', (string) $column->fetchColumn());
        $column->execute(['id' => $undated->id()]);
        Assert::null($column->fetchColumn());

        /** @var Prescription|null $found */
        $found = $repository->findById((int) $dated->id());
        Assert::notNull($found);
        Assert::notNull($found->validUntil());
        Assert::same('2026-10-15 00:00:00', $found->validUntil()->format('Y-m-d H:i:s'));

        /** @var Prescription|null $foundUndated */
        $foundUndated = $repository->findById((int) $undated->id());
        Assert::notNull($foundUndated);
        Assert::null($foundUndated->validUntil());
    }

    private function newPrescription(?DateTimeImmutable $validUntil): Prescription
    {
        return Prescription::create(
            $this->tenantId,
            $this->encounterId,
            $this->patientId,
            $this->userId,
            'Dar após a refeição',
            [PrescriptionItem::create($this->tenantId, 'Amoxicilina', '1', 'comprimido', 'oral', '12/12h', '7 dias')],
            $validUntil,
        );
    }

    /** @param array<string, int|string> $values */
    private function insert(string $table, array $values): int
    {
        $columns = array_keys($values);
        $statement = $this->pdo->prepare(sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $table,
            implode(', ', $columns),
            implode(', ', array_map(static fn (string $c): string => ':' . $c, $columns)),
        ));
        $statement->execute($values);

        return (int) $this->pdo->lastInsertId();
    }

    private function uuid(): string
    {
        return (string) $this->pdo->query('SELECT UUID()')->fetchColumn();
    }
}
