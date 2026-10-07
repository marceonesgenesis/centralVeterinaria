<?php

declare(strict_types=1);

namespace CentralVet\Tests\Integration;

use CentralVet\Domain\Encounter;
use CentralVet\Persistence\EncounterRepository;
use CentralVet\Tenancy\TenantContext;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\MysqlIntegrationTestCase;
use DateTimeImmutable;

/**
 * EncounterRepository against the real MySQL schema (rodada 2, T-37: the
 * `paused_at`/`paused_seconds` columns of migration 0007, delivered by
 * T-16). Every row (tenant, tutor, patient, encounter) lives only inside
 * the test's transaction, rolled back in tearDown() — nothing is committed.
 */
final class EncounterRepositoryIntegrationTest extends MysqlIntegrationTestCase
{
    private int $userId;
    private int $tenantA;
    private int $tenantB;
    private int $unitId;
    private int $patientId;

    public function setUp(): void
    {
        parent::setUp();

        $this->userId = (int) $this->pdo->query('SELECT MIN(id) FROM system_users')->fetchColumn();
        Assert::true($this->userId > 0, 'Fixture requires at least one existing system_users row');

        // system_unit.id has no AUTO_INCREMENT (Adianti table): reuse an existing unit.
        $this->unitId = (int) $this->pdo->query('SELECT MIN(id) FROM system_unit')->fetchColumn();
        Assert::true($this->unitId > 0, 'Fixture requires at least one existing system_unit row');

        $this->tenantA = $this->createTenant('r2-encounter-a');
        $this->tenantB = $this->createTenant('r2-encounter-b');

        $this->pdo->prepare('INSERT INTO tutor (tenant_id, public_id, full_name, phone) VALUES (:t, UUID(), :n, :p)')
            ->execute(['t' => $this->tenantA, 'n' => 'R2 Tutor Pausa', 'p' => '11999990000']);
        $tutorId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare('INSERT INTO patient (tenant_id, tutor_id, name, species) VALUES (:t, :tutor, :n, :s)')
            ->execute(['t' => $this->tenantA, 'tutor' => $tutorId, 'n' => 'R2 Rex Pausa', 's' => 'canino']);
        $this->patientId = (int) $this->pdo->lastInsertId();
    }

    public function testNewEncounterPersistsNullPausedAtAndZeroSeconds(): void
    {
        $encounter = $this->startEncounter();

        /** @var Encounter $found */
        $found = $this->repositoryFor($this->tenantA)->findById((int) $encounter->id());
        Assert::instanceOf(Encounter::class, $found);
        Assert::null($found->pausedAt());
        Assert::same(0, $found->pausedSeconds());
        Assert::false($found->isPaused());
    }

    public function testPauseAndResumePersistPausedAtAndPausedSeconds(): void
    {
        $repository = $this->repositoryFor($this->tenantA);
        $encounter = $this->startEncounter();
        $pausedAt = new DateTimeImmutable('2031-07-01 10:15:30');

        $encounter->pause($pausedAt);
        $repository->save($encounter);

        /** @var Encounter $paused */
        $paused = $repository->findById((int) $encounter->id());
        Assert::true($paused->isPaused(), 'paused_at is written by the UPDATE');
        Assert::same('2031-07-01 10:15:30', $paused->pausedAt()?->format('Y-m-d H:i:s'));
        Assert::same(0, $paused->pausedSeconds());

        $paused->resume($pausedAt->modify('+125 seconds'));
        $repository->save($paused);

        /** @var Encounter $resumed */
        $resumed = $repository->findById((int) $encounter->id());
        Assert::false($resumed->isPaused(), 'paused_at is cleared (NULL) after resume');
        Assert::null($resumed->pausedAt());
        Assert::same(125, $resumed->pausedSeconds());

        $row = $this->pdo->prepare('SELECT paused_at, paused_seconds FROM encounter WHERE id = :id');
        $row->execute(['id' => $encounter->id()]);
        Assert::same(['paused_at' => null, 'paused_seconds' => 125], array_map(
            static fn ($v) => is_numeric($v) ? (int) $v : $v,
            $row->fetch(\PDO::FETCH_ASSOC),
        ));

        Assert::null($this->repositoryFor($this->tenantB)->findById((int) $encounter->id()), 'other tenant cannot find it');
    }

    public function testEncounterInsertedWhilePausedKeepsPauseColumns(): void
    {
        $repository = $this->repositoryFor($this->tenantA);
        $encounter = Encounter::start($this->tenantA, $this->unitId, $this->patientId, null, $this->userId, new DateTimeImmutable('2031-07-02 08:00:00'));
        $encounter->pause(new DateTimeImmutable('2031-07-02 08:05:00'));
        $repository->save($encounter);

        /** @var Encounter $found */
        $found = $repository->findById((int) $encounter->id());
        Assert::same('2031-07-02 08:05:00', $found->pausedAt()?->format('Y-m-d H:i:s'), 'paused_at is written by the INSERT');
        Assert::same(0, $found->pausedSeconds());
    }

    private function startEncounter(): Encounter
    {
        $encounter = Encounter::start($this->tenantA, $this->unitId, $this->patientId, null, $this->userId, new DateTimeImmutable('2031-07-01 10:00:00'));
        $this->repositoryFor($this->tenantA)->save($encounter);
        Assert::true(($encounter->id() ?? 0) > 0, 'insert assigns the generated id');

        return $encounter;
    }

    private function repositoryFor(int $tenantId): EncounterRepository
    {
        return new EncounterRepository(TenantContext::authenticated($tenantId, $this->userId, $this->unitId), $this->pdo);
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
