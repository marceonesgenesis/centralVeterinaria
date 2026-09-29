<?php

declare(strict_types=1);

namespace CentralVet\Tests\Integration;

use CentralVet\Application\EncounterService;
use CentralVet\Persistence\EncounterRepository;
use CentralVet\Tenancy\TenantContext;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\FakeAuthorizationPolicy;
use CentralVet\Tests\Support\MysqlIntegrationTestCase;

/**
 * Proves EncounterService::timeline() (which reads `audit_log` directly via
 * PDO — no formal Repository, see the class docblock on EncounterService)
 * against the real, already-applied `encounter`/`patient`/`audit_log`
 * schema (T-01/T-03 migrations). Mirrors
 * TenantIsolationMysqlIntegrationTest's own pattern exactly: every row this
 * test writes (a throwaway tenant, tutor, patient and encounter, plus a
 * handful of audit_log rows) lives only inside this test's own transaction
 * and is always rolled back in tearDown() (inherited from
 * MysqlIntegrationTestCase) — nothing here is ever committed, so this is
 * safe to run against the real development database.
 *
 * The scoping proof (T-03's timeline() filters on
 * `tenant_id = :tenant_id AND entity_type = 'encounter' AND entity_id =
 * :entity_id`) is exercised with three "noise" rows that must NOT come
 * back: one for a different encounter_id under the same tenant, one for a
 * different entity_type under the same tenant, and one for the same
 * entity_id/entity_type but a different (foreign) tenant.
 */
final class EncounterTimelineIntegrationTest extends MysqlIntegrationTestCase
{
    private int $tenantId;
    private int $foreignTenantId;
    private int $systemUnitId;
    private int $professionalSystemUserId;
    private int $patientId;
    private int $encounterId;
    private int $otherEncounterId;

    public function setUp(): void
    {
        parent::setUp();

        $this->tenantId = $this->createThrowawayTenant('encounter-timeline-a');
        $this->foreignTenantId = $this->createThrowawayTenant('encounter-timeline-b');

        $unitId = $this->pdo->query('SELECT id FROM system_unit LIMIT 1')->fetchColumn();
        Assert::true($unitId !== false, 'Fixture requires at least one existing system_unit row');
        $this->systemUnitId = (int) $unitId;

        $userId = $this->pdo->query('SELECT id FROM system_users LIMIT 1')->fetchColumn();
        Assert::true($userId !== false, 'Fixture requires at least one existing system_users row');
        $this->professionalSystemUserId = (int) $userId;

        $tutorId = $this->createTutor($this->tenantId);
        $this->patientId = $this->createPatient($this->tenantId, $tutorId);
        $this->encounterId = $this->createEncounter($this->tenantId, $this->patientId);
        $this->otherEncounterId = $this->createEncounter($this->tenantId, $this->patientId);
    }

    public function testTimelineReturnsOnlyThisEncountersEventsInOrder(): void
    {
        // In-scope events, deliberately inserted out of chronological order
        // so the assertion below also proves timeline() sorts by
        // created_at ASC rather than by insertion/id order.
        $this->insertAuditLog($this->tenantId, $this->encounterId, 'encounter', 'EncounterView::onFinish', '2026-09-22 10:00:00.000000');
        $this->insertAuditLog($this->tenantId, $this->encounterId, 'encounter', 'EncounterView::onStart', '2026-09-22 09:00:00.000000');
        $this->insertAuditLog($this->tenantId, $this->encounterId, 'encounter', 'EncounterView::onAutosave', '2026-09-22 09:30:00.000000');

        // Noise: must never appear in this encounter's timeline.
        $this->insertAuditLog($this->tenantId, $this->otherEncounterId, 'encounter', 'EncounterView::onStart', '2026-09-22 09:15:00.000000');
        $this->insertAuditLog($this->tenantId, $this->encounterId, 'appointment', 'AppointmentForm::onSave', '2026-09-22 09:20:00.000000');
        $this->insertAuditLog($this->foreignTenantId, $this->encounterId, 'encounter', 'EncounterView::onStart', '2026-09-22 09:10:00.000000');

        $service = $this->makeEncounterService($this->tenantId);

        $events = $service->timeline($this->encounterId);

        Assert::count(3, $events, 'timeline() must return exactly the 3 in-scope events, no more, no less');

        $actions = array_map(static fn (array $event): string => $event['action'], $events);
        Assert::same(
            ['EncounterView::onStart', 'EncounterView::onAutosave', 'EncounterView::onFinish'],
            $actions,
            'timeline() must return events ordered by created_at ASC, not by insertion order',
        );

        foreach ($events as $event) {
            Assert::instanceOf(\DateTimeImmutable::class, $event['created_at']);
        }
    }

    public function testTimelineReturnsEmptyListWhenNoAuditLogRowsExist(): void
    {
        $service = $this->makeEncounterService($this->tenantId);

        Assert::same([], $service->timeline($this->encounterId));
    }

    /**
     * Proves the ROLLBACK MysqlIntegrationTestCase::tearDown() always runs
     * leaves `patient`/`encounter`/`audit_log` with the exact same row
     * counts they had before this test inserted anything — nothing this
     * test writes is ever actually persisted. Counts are taken through the
     * SAME open transaction (this test's own inserts are visible to it),
     * so the assertion is: baseline-before-fixtures + this test's own
     * inserts == count-now. The suite-level proof that the transaction
     * itself never reaches the database is the external, read-only
     * before/after COUNT(*) check run outside of PHPUnit-style execution
     * (see the task's final validation step), since a rolled-back INSERT is
     * only invisible from a connection outside the transaction.
     */
    public function testFixtureRowsAreCountedWithinTheOpenTransaction(): void
    {
        $patientCountBefore = (int) $this->pdo->query('SELECT COUNT(*) FROM patient')->fetchColumn();
        $encounterCountBefore = (int) $this->pdo->query('SELECT COUNT(*) FROM encounter')->fetchColumn();

        $tutorId = $this->createTutor($this->tenantId);
        $newPatientId = $this->createPatient($this->tenantId, $tutorId);
        $this->createEncounter($this->tenantId, $newPatientId);

        $patientCountAfter = (int) $this->pdo->query('SELECT COUNT(*) FROM patient')->fetchColumn();
        $encounterCountAfter = (int) $this->pdo->query('SELECT COUNT(*) FROM encounter')->fetchColumn();

        Assert::same($patientCountBefore + 1, $patientCountAfter, 'Inserting one more patient row must move the count by exactly 1 inside this transaction');
        Assert::same($encounterCountBefore + 1, $encounterCountAfter, 'Inserting one more encounter row must move the count by exactly 1 inside this transaction');
    }

    private function makeEncounterService(int $tenantId): EncounterService
    {
        $context = TenantContext::authenticated($tenantId, $this->professionalSystemUserId, $this->systemUnitId);
        $encounters = new EncounterRepository($context, $this->pdo);
        $authorization = new FakeAuthorizationPolicy(allowed: true);

        return new EncounterService($encounters, $authorization, $context, $this->pdo);
    }

    private function createThrowawayTenant(string $slugPrefix): int
    {
        $slug = $slugPrefix . '-' . bin2hex(random_bytes(4));

        $statement = $this->pdo->prepare(
            'INSERT INTO tenant (public_id, slug, legal_name, status)
             VALUES (UUID(), :slug, :legal_name, :status)',
        );
        $statement->execute([
            'slug' => $slug,
            'legal_name' => 'Encounter timeline test tenant (' . $slug . ')',
            'status' => 'active',
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function createTutor(int $tenantId): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO tutor (tenant_id, public_id, full_name, phone)
             VALUES (:tenant_id, UUID(), :full_name, :phone)',
        );
        $statement->execute([
            'tenant_id' => $tenantId,
            'full_name' => 'Encounter timeline test tutor',
            'phone' => '11999990000',
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function createPatient(int $tenantId, int $tutorId): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO patient (tenant_id, tutor_id, name, species)
             VALUES (:tenant_id, :tutor_id, :name, :species)',
        );
        $statement->execute([
            'tenant_id' => $tenantId,
            'tutor_id' => $tutorId,
            'name' => 'Rex (timeline test)',
            'species' => 'canino',
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function createEncounter(int $tenantId, int $patientId): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO encounter (tenant_id, system_unit_id, patient_id, professional_system_user_id, status)
             VALUES (:tenant_id, :system_unit_id, :patient_id, :professional_system_user_id, :status)',
        );
        $statement->execute([
            'tenant_id' => $tenantId,
            'system_unit_id' => $this->systemUnitId,
            'patient_id' => $patientId,
            'professional_system_user_id' => $this->professionalSystemUserId,
            'status' => 'in_progress',
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function insertAuditLog(int $tenantId, int $entityId, string $entityType, string $action, string $createdAt): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO audit_log (tenant_id, correlation_id, action, entity_type, entity_id, created_at)
             VALUES (:tenant_id, :correlation_id, :action, :entity_type, :entity_id, :created_at)',
        );
        $statement->execute([
            'tenant_id' => $tenantId,
            'correlation_id' => 'encounter-timeline-test-' . bin2hex(random_bytes(4)),
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => (string) $entityId,
            'created_at' => $createdAt,
        ]);
    }
}
