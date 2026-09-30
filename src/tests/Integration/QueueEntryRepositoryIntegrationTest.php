<?php

declare(strict_types=1);

namespace CentralVet\Tests\Integration;

use CentralVet\Domain\QueueEntry;
use CentralVet\Persistence\QueueEntryRepository;
use CentralVet\Tenancy\TenantContext;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\MysqlIntegrationTestCase;
use DateTimeImmutable;

/**
 * QueueEntryRepository against the real MySQL schema (rodada 2, T-41):
 * the UNIQUE `queue_entry_appointment_uq` from migration 0008 turns a
 * second entry for the same appointment into the domain message
 * "Appointment <id> is already in the queue", and
 * listAppointmentIdsInQueue() answers which appointments already have an
 * entry. Every row belongs to throwaway tenants created inside the test's
 * transaction, rolled back in tearDown() — nothing is committed.
 */
final class QueueEntryRepositoryIntegrationTest extends MysqlIntegrationTestCase
{
    private int $userId;
    private int $unitId;
    private int $tenantA;
    private int $tenantB;

    public function setUp(): void
    {
        parent::setUp();

        $hasUnique = (bool) $this->pdo
            ->query("SHOW INDEX FROM queue_entry WHERE Key_name = 'queue_entry_appointment_uq'")
            ->fetch();
        Assert::true($hasUnique, 'Fixture requires migration 0008 (queue_entry_appointment_uq) applied');

        $this->userId = (int) $this->pdo->query('SELECT MIN(id) FROM system_users')->fetchColumn();
        Assert::true($this->userId > 0, 'Fixture requires at least one existing system_users row');

        // system_unit.id has no AUTO_INCREMENT (Adianti table): reuse an existing unit.
        $this->unitId = (int) $this->pdo->query('SELECT MIN(id) FROM system_unit')->fetchColumn();
        Assert::true($this->unitId > 0, 'Fixture requires at least one existing system_unit row');

        $this->tenantA = $this->createTenant('r2-queue-a');
        $this->tenantB = $this->createTenant('r2-queue-b');
    }

    public function testSecondEntryForSameAppointmentThrowsDomainMessageAndKeepsOneRow(): void
    {
        [$patientId, $appointmentId] = $this->seedAppointment($this->tenantA);
        $repository = $this->repositoryFor($this->tenantA);

        $first = $repository->save($this->newEntry($this->tenantA, $patientId, $appointmentId));
        Assert::true(($first->id() ?? 0) > 0, 'first insert assigns the generated id');

        $message = null;
        $class = null;
        try {
            // Bypasses QueueEntryService's findByAppointment() pre-check, as a
            // concurrent request would: only the UNIQUE index stops it.
            $repository->save($this->newEntry($this->tenantA, $patientId, $appointmentId));
        } catch (\Throwable $e) {
            $class = $e::class;
            $message = $e->getMessage();
        }

        Assert::same(\DomainException::class, $class, 'unique violation becomes a DomainException');
        Assert::same("Appointment {$appointmentId} is already in the queue", $message);
        Assert::same(1, $this->countFor($appointmentId), 'only the first entry is stored');
    }

    public function testOtherPdoFailuresAreRethrown(): void
    {
        [$patientId] = $this->seedAppointment($this->tenantA);
        $missingAppointment = (int) $this->pdo->query('SELECT COALESCE(MAX(id), 0) + 1000 FROM appointment')->fetchColumn();

        // FK violation (SQLSTATE 23000 too, but not the unique index): must stay a PDOException.
        Assert::throws(
            \PDOException::class,
            fn () => $this->repositoryFor($this->tenantA)->save($this->newEntry($this->tenantA, $patientId, $missingAppointment)),
        );
    }

    public function testListAppointmentIdsInQueueIsTenantScoped(): void
    {
        [$patientA, $inQueue] = $this->seedAppointment($this->tenantA);
        [, $notInQueue] = $this->seedAppointment($this->tenantA);
        [$patientB, $otherTenant] = $this->seedAppointment($this->tenantB);

        $repoA = $this->repositoryFor($this->tenantA);
        $repoA->save($this->newEntry($this->tenantA, $patientA, $inQueue));
        $this->repositoryFor($this->tenantB)->save($this->newEntry($this->tenantB, $patientB, $otherTenant));

        Assert::same([$inQueue], $repoA->listAppointmentIdsInQueue([$inQueue, $notInQueue, $otherTenant]));
        Assert::same([], $repoA->listAppointmentIdsInQueue([]));
    }

    private function newEntry(int $tenantId, int $patientId, int $appointmentId): QueueEntry
    {
        return QueueEntry::checkIn($tenantId, $this->unitId, $patientId, $appointmentId, $this->userId, new DateTimeImmutable());
    }

    private function countFor(int $appointmentId): int
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM queue_entry WHERE appointment_id = :id');
        $statement->execute(['id' => $appointmentId]);

        return (int) $statement->fetchColumn();
    }

    /** @return array{0: int, 1: int} [patient id, appointment id] */
    private function seedAppointment(int $tenantId): array
    {
        $this->pdo->prepare('INSERT INTO tutor (tenant_id, public_id, full_name, phone) VALUES (:t, UUID(), :n, :p)')
            ->execute(['t' => $tenantId, 'n' => 'R2 Tutor Fila', 'p' => '11999990000']);
        $tutorId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare('INSERT INTO patient (tenant_id, tutor_id, name, species) VALUES (:t, :tutor, :n, :s)')
            ->execute(['t' => $tenantId, 'tutor' => $tutorId, 'n' => 'R2 Rex Fila', 's' => 'canino']);
        $patientId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare('INSERT INTO service (tenant_id, name, duration_minutes, price_cents) VALUES (:t, :n, 30, 1000)')
            ->execute(['t' => $tenantId, 'n' => 'R2 Consulta Fila ' . bin2hex(random_bytes(4))]);
        $serviceId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare(
            'INSERT INTO appointment (tenant_id, system_unit_id, patient_id, service_id, professional_system_user_id, scheduled_at) '
            . 'VALUES (:t, :u, :p, :s, :prof, :at)',
        )->execute([
            't' => $tenantId,
            'u' => $this->unitId,
            'p' => $patientId,
            's' => $serviceId,
            'prof' => $this->userId,
            'at' => '2031-05-10 09:30:00',
        ]);

        return [$patientId, (int) $this->pdo->lastInsertId()];
    }

    private function repositoryFor(int $tenantId): QueueEntryRepository
    {
        return new QueueEntryRepository(TenantContext::authenticated($tenantId, $this->userId), $this->pdo);
    }

    private function createTenant(string $slugPrefix): int
    {
        $slug = $slugPrefix . '-' . bin2hex(random_bytes(4));
        $statement = $this->pdo->prepare(
            'INSERT INTO tenant (public_id, slug, legal_name, status) VALUES (UUID(), :slug, :legal_name, :status)',
        );
        $statement->execute(['slug' => $slug, 'legal_name' => 'R2 test tenant (' . $slug . ')', 'status' => 'active']);

        return (int) $this->pdo->lastInsertId();
    }
}
