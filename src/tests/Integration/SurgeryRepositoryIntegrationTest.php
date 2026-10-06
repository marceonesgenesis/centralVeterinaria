<?php

declare(strict_types=1);

namespace CentralVet\Tests\Integration;

use CentralVet\Domain\Encounter;
use CentralVet\Domain\Exception\InvalidStatusTransitionException;
use CentralVet\Domain\Surgery;
use CentralVet\Domain\SurgeryChecklist;
use CentralVet\Domain\SurgeryChecklistItem;
use CentralVet\Domain\SurgeryEvent;
use CentralVet\Domain\SurgeryMaterial;
use CentralVet\Domain\SurgeryRoom;
use CentralVet\Domain\SurgeryTeamMember;
use CentralVet\Persistence\EncounterRepository;
use CentralVet\Persistence\SurgeryChecklistRepository;
use CentralVet\Persistence\SurgeryEventRepository;
use CentralVet\Persistence\SurgeryMaterialRepository;
use CentralVet\Persistence\SurgeryRepository;
use CentralVet\Persistence\SurgeryRoomRepository;
use CentralVet\Persistence\SurgeryTeamRepository;
use CentralVet\Tenancy\TenantContext;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\MysqlIntegrationTestCase;
use DateTimeImmutable;

/**
 * The six surgery repositories (migration 0011) against the real MySQL
 * schema: full round trip, room overlap, conditional status UPDATE,
 * checklist uniqueness and tenant isolation. Every row lives only inside
 * the test transaction, rolled back in tearDown() — nothing is committed.
 */
final class SurgeryRepositoryIntegrationTest extends MysqlIntegrationTestCase
{
    private int $userId;
    private int $unitId;
    private int $tenantA;
    private int $tenantB;
    private int $patientId;
    private int $encounterId;
    private int $procedureId;
    private int $productId;

    public function setUp(): void
    {
        parent::setUp();

        $hasTable = (bool) $this->pdo->query("SHOW TABLES LIKE 'surgery_material'")->fetchColumn();
        Assert::true($hasTable, 'Migration 0011 must be applied to the test database');

        $this->userId = (int) $this->pdo->query('SELECT MIN(id) FROM system_users')->fetchColumn();
        Assert::true($this->userId > 0, 'Fixture requires at least one existing system_users row');

        $this->unitId = (int) $this->pdo->query('SELECT MIN(id) FROM system_unit')->fetchColumn();
        Assert::true($this->unitId > 0, 'Fixture requires at least one existing system_unit row');

        $this->tenantA = $this->createTenant('f6b-surg-a');
        $this->tenantB = $this->createTenant('f6b-surg-b');

        $this->pdo->prepare('INSERT INTO tutor (tenant_id, public_id, full_name, phone) VALUES (:t, UUID(), :n, :p)')
            ->execute(['t' => $this->tenantA, 'n' => 'F6B teste Tutor', 'p' => '11999990000']);
        $tutorId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare('INSERT INTO patient (tenant_id, tutor_id, name, species) VALUES (:t, :tutor, :n, :s)')
            ->execute(['t' => $this->tenantA, 'tutor' => $tutorId, 'n' => 'F6B teste Rex', 's' => 'canino']);
        $this->patientId = (int) $this->pdo->lastInsertId();

        $encounter = Encounter::start($this->tenantA, $this->unitId, $this->patientId, null, $this->userId, new DateTimeImmutable('2031-09-01 07:00:00'));
        (new EncounterRepository($this->contextFor($this->tenantA), $this->pdo))->save($encounter);
        $this->encounterId = (int) $encounter->id();

        $this->pdo->prepare('INSERT INTO procedure_catalog_item (tenant_id, name, price_cents) VALUES (:t, :n, :p)')
            ->execute(['t' => $this->tenantA, 'n' => 'F6B teste Orquiectomia', 'p' => 45000]);
        $this->procedureId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare(
            'INSERT INTO product (tenant_id, name, category, unit_of_measure, unit_cost_cents, minimum_stock_quantity) '
            . 'VALUES (:t, :n, :c, :u, 100, 0)',
        )->execute(['t' => $this->tenantA, 'n' => 'F6B teste Fio de sutura', 'c' => 'material', 'u' => 'un']);
        $this->productId = (int) $this->pdo->lastInsertId();
    }

    public function testRoomAndSurgeryRoundTripWithAllFields(): void
    {
        $rooms = new SurgeryRoomRepository($this->contextFor($this->tenantA), $this->pdo);
        $room = $this->createRoom('F6B-S1');

        /** @var SurgeryRoom $reloadedRoom */
        $reloadedRoom = $rooms->findById((int) $room->id());
        Assert::same('F6B-S1', $reloadedRoom->code());
        Assert::same('Sala F6B-S1', $reloadedRoom->name());
        Assert::same(SurgeryRoom::STATUS_ACTIVE, $reloadedRoom->status());
        Assert::same((int) $room->id(), (int) $rooms->findByCode($this->unitId, 'F6B-S1')?->id());
        Assert::count(1, array_filter($rooms->listByUnit($this->unitId), static fn (SurgeryRoom $r): bool => $r->id() === $room->id()));
        Assert::true($rooms->lockForScheduling((int) $room->id()));

        $reloadedRoom->rename('Sala renomeada');
        $reloadedRoom->deactivate();
        $rooms->save($reloadedRoom);
        /** @var SurgeryRoom $updatedRoom */
        $updatedRoom = $rooms->findById((int) $room->id());
        Assert::same('Sala renomeada', $updatedRoom->name());
        Assert::same(SurgeryRoom::STATUS_INACTIVE, $updatedRoom->status());

        $surgeries = new SurgeryRepository($this->contextFor($this->tenantA), $this->pdo);
        $surgery = $this->schedule($room, '2031-09-01 08:00:00', '2031-09-01 09:30:00');
        Assert::notNull($surgery->id());

        /** @var Surgery $loaded */
        $loaded = $surgeries->findById((int) $surgery->id());
        Assert::same($this->tenantA, $loaded->tenantId());
        Assert::same($this->unitId, $loaded->systemUnitId());
        Assert::same($this->patientId, $loaded->patientId());
        Assert::same($this->encounterId, $loaded->encounterId());
        Assert::same((int) $room->id(), $loaded->roomId());
        Assert::same($this->procedureId, $loaded->procedureCatalogItemId());
        Assert::same('F6B teste Orquiectomia', $loaded->procedureName());
        Assert::same(45000, $loaded->procedurePriceCents());
        Assert::same($this->userId, $loaded->surgeonSystemUserId());
        Assert::same($this->userId, $loaded->scheduledBySystemUserId());
        Assert::same('2031-09-01 08:00:00', $loaded->scheduledStartAt()->format('Y-m-d H:i:s'));
        Assert::same('2031-09-01 09:30:00', $loaded->scheduledEndAt()->format('Y-m-d H:i:s'));
        Assert::same(Surgery::STATUS_SCHEDULED, $loaded->status());
        Assert::same(Surgery::STATUS_SCHEDULED, $loaded->loadedStatus());
        Assert::same('F6B teste jejum 12h', $loaded->notesText());
        Assert::notNull($loaded->createdAt());

        $loaded->recordConsent('F6B teste Tutor', 'Texto integral aceito', $this->userId, new DateTimeImmutable('2031-09-01 07:40:00.123456'));
        $loaded->startPreOp();
        $loaded->start(new DateTimeImmutable('2031-09-01 08:05:00'));
        $surgeries->save($loaded);
        Assert::same(Surgery::STATUS_IN_PROGRESS, $surgeries->lockStatus((int) $surgery->id()));

        /** @var Surgery $started */
        $started = $surgeries->findById((int) $surgery->id());
        $started->complete(new DateTimeImmutable('2031-09-01 09:20:00'), $this->userId);
        $started->linkFollowUp($this->createAppointment());
        $surgeries->save($started);

        /** @var Surgery $done */
        $done = $surgeries->findById((int) $surgery->id());
        Assert::same(Surgery::STATUS_COMPLETED, $done->status());
        Assert::same('F6B teste Tutor', $done->consentSignerName());
        Assert::same('Texto integral aceito', $done->consentText());
        Assert::same('2031-09-01 07:40:00.123456', $done->consentRecordedAt()?->format('Y-m-d H:i:s.u'));
        Assert::same($this->userId, $done->consentRecordedBySystemUserId());
        Assert::same('2031-09-01 08:05:00', $done->startedAt()?->format('Y-m-d H:i:s'));
        Assert::same('2031-09-01 09:20:00', $done->completedAt()?->format('Y-m-d H:i:s'));
        Assert::same($this->userId, $done->completedBySystemUserId());
        Assert::notNull($done->followupAppointmentId());

        $day = $surgeries->listByUnitAndDay($this->unitId, new DateTimeImmutable('2031-09-01 15:00:00'));
        Assert::count(1, array_filter($day, static fn (Surgery $s): bool => $s->id() === $surgery->id()));
        Assert::count(0, array_filter(
            $surgeries->listByUnitAndDay($this->unitId, new DateTimeImmutable('2031-09-02')),
            static fn (Surgery $s): bool => $s->id() === $surgery->id(),
        ));
        Assert::null($surgeries->lockStatus(999999999));
    }

    public function testOverlapInRoomUntilCancelled(): void
    {
        $surgeries = new SurgeryRepository($this->contextFor($this->tenantA), $this->pdo);
        $room = $this->createRoom('F6B-S2');
        $surgery = $this->schedule($room, '2031-09-01 08:00:00', '2031-09-01 09:00:00');
        $roomId = (int) $room->id();

        Assert::true($surgeries->hasOverlapInRoom($roomId, new DateTimeImmutable('2031-09-01 08:30:00'), new DateTimeImmutable('2031-09-01 10:00:00'), null), 'overlapping period');
        Assert::false($surgeries->hasOverlapInRoom($roomId, new DateTimeImmutable('2031-09-01 09:00:00'), new DateTimeImmutable('2031-09-01 10:00:00'), null), 'adjacent period does not overlap');
        Assert::false($surgeries->hasOverlapInRoom($roomId, new DateTimeImmutable('2031-09-01 08:30:00'), new DateTimeImmutable('2031-09-01 10:00:00'), (int) $surgery->id()), 'the surgery itself is ignored');

        /** @var Surgery $loaded */
        $loaded = $surgeries->findById((int) $surgery->id());
        $loaded->cancel(new DateTimeImmutable('2031-09-01 07:00:00'), $this->userId, 'F6B teste tutor desistiu');
        $surgeries->save($loaded);

        Assert::false($surgeries->hasOverlapInRoom($roomId, new DateTimeImmutable('2031-09-01 08:30:00'), new DateTimeImmutable('2031-09-01 10:00:00'), null), 'cancelled surgery frees the room');
    }

    public function testStaleCopyCannotOverwriteConcurrentStatusChange(): void
    {
        $surgeries = new SurgeryRepository($this->contextFor($this->tenantA), $this->pdo);
        $surgery = $this->schedule($this->createRoom('F6B-S3'), '2031-09-01 08:00:00', '2031-09-01 09:00:00');

        /** @var Surgery $copyA */
        $copyA = $surgeries->findById((int) $surgery->id());
        /** @var Surgery $copyB */
        $copyB = $surgeries->findById((int) $surgery->id());

        $copyB->cancel(new DateTimeImmutable('2031-09-01 07:00:00'), $this->userId, 'F6B teste cancelada');
        $surgeries->save($copyB);

        $copyA->startPreOp();
        $message = '';
        try {
            $surgeries->save($copyA);
        } catch (InvalidStatusTransitionException $e) {
            $message = $e->getMessage();
        }
        Assert::stringContains('changed status concurrently', $message);
        Assert::same(Surgery::STATUS_CANCELLED, $surgeries->lockStatus((int) $surgery->id()));

        // An unchanged save of a fresh copy (rowCount 0) is not a conflict.
        /** @var Surgery $fresh */
        $fresh = $surgeries->findById((int) $surgery->id());
        $surgeries->save($fresh);
    }

    public function testTeamChecklistEventsAndMaterials(): void
    {
        $context = $this->contextFor($this->tenantA);
        $surgery = $this->schedule($this->createRoom('F6B-S4'), '2031-09-01 08:00:00', '2031-09-01 09:00:00');
        $surgeryId = (int) $surgery->id();

        $team = new SurgeryTeamRepository($context, $this->pdo);
        $team->replaceForSurgery($surgeryId, [
            SurgeryTeamMember::create($this->tenantA, $surgeryId, $this->userId, SurgeryTeamMember::ROLE_SURGEON),
            SurgeryTeamMember::create($this->tenantA, $surgeryId, $this->userId, SurgeryTeamMember::ROLE_ANESTHETIST),
        ]);
        $team->replaceForSurgery($surgeryId, [
            SurgeryTeamMember::create($this->tenantA, $surgeryId, $this->userId, SurgeryTeamMember::ROLE_SURGEON),
        ]);
        $members = $team->listBySurgery($surgeryId);
        Assert::count(1, $members);
        Assert::same(SurgeryTeamMember::ROLE_SURGEON, $members[0]->role());

        $checklist = new SurgeryChecklistRepository($context, $this->pdo);
        $checklist->save(SurgeryChecklistItem::check($this->tenantA, $surgeryId, SurgeryChecklist::PHASE_SIGN_IN, 'patient_identity_confirmed', $this->userId, new DateTimeImmutable('2031-09-01 07:50:00')));
        $message = '';
        try {
            $checklist->save(SurgeryChecklistItem::check($this->tenantA, $surgeryId, SurgeryChecklist::PHASE_SIGN_IN, 'patient_identity_confirmed', $this->userId, new DateTimeImmutable('2031-09-01 07:51:00')));
        } catch (InvalidStatusTransitionException $e) {
            $message = $e->getMessage();
        }
        Assert::same("Checklist phase \"sign_in\" is already confirmed for surgery {$surgeryId}", $message);
        Assert::count(1, $checklist->listBySurgery($surgeryId));

        $events = new SurgeryEventRepository($context, $this->pdo);
        $events->save(SurgeryEvent::record($this->tenantA, $surgeryId, SurgeryEvent::TYPE_SCHEDULED, $this->userId, new DateTimeImmutable('2031-09-01 07:00:00'), null));
        $events->save(SurgeryEvent::record($this->tenantA, $surgeryId, SurgeryEvent::TYPE_ANESTHESIA, $this->userId, new DateTimeImmutable('2031-09-01 08:01:00'), 'F6B teste indução'));
        $listed = $events->listBySurgery($surgeryId);
        Assert::count(2, $listed);
        Assert::same(SurgeryEvent::TYPE_ANESTHESIA, $listed[0]->eventType(), 'most recent first');
        Assert::throws(\LogicException::class, static fn () => $events->remove($listed[0]));

        $materials = new SurgeryMaterialRepository($context, $this->pdo);
        $material = SurgeryMaterial::record($this->tenantA, $surgeryId, $this->productId, 3, $this->userId, new DateTimeImmutable('2031-09-01 08:10:00'));
        $materials->save($material);
        $listedMaterials = $materials->listBySurgery($surgeryId);
        Assert::count(1, $listedMaterials);
        Assert::same(3, $listedMaterials[0]->quantity());
        Assert::same($this->productId, $listedMaterials[0]->productId());
        Assert::same((int) $material->id(), (int) $materials->findById((int) $material->id())?->id());
        Assert::same(1, $materials->delete($material));
        Assert::count(0, $materials->listBySurgery($surgeryId));
        Assert::same(0, $materials->delete($material), 'deleting an already removed material affects no row');
    }

    public function testOtherTenantCannotSeeTheSurgery(): void
    {
        $room = $this->createRoom('F6B-S5');
        $surgery = $this->schedule($room, '2031-09-01 08:00:00', '2031-09-01 09:00:00');
        $contextB = $this->contextFor($this->tenantB);

        Assert::null((new SurgeryRepository($contextB, $this->pdo))->findById((int) $surgery->id()), 'surgery of another tenant is invisible');
        Assert::null((new SurgeryRepository($contextB, $this->pdo))->lockStatus((int) $surgery->id()));
        Assert::null((new SurgeryRoomRepository($contextB, $this->pdo))->findById((int) $room->id()), 'room of another tenant is invisible');
        Assert::false((new SurgeryRoomRepository($contextB, $this->pdo))->lockForScheduling((int) $room->id()));
        Assert::false((new SurgeryRepository($contextB, $this->pdo))->hasOverlapInRoom((int) $room->id(), new DateTimeImmutable('2031-09-01 08:00:00'), new DateTimeImmutable('2031-09-01 09:00:00'), null));
        Assert::count(0, (new SurgeryEventRepository($contextB, $this->pdo))->listBySurgery((int) $surgery->id()));
    }

    private function createRoom(string $code): SurgeryRoom
    {
        $room = SurgeryRoom::create($this->tenantA, $this->unitId, $code, 'Sala ' . $code);
        (new SurgeryRoomRepository($this->contextFor($this->tenantA), $this->pdo))->save($room);

        return $room;
    }

    private function schedule(SurgeryRoom $room, string $start, string $end): Surgery
    {
        $surgery = Surgery::schedule(
            $this->tenantA,
            $this->unitId,
            $this->patientId,
            $this->encounterId,
            (int) $room->id(),
            $this->procedureId,
            'F6B teste Orquiectomia',
            45000,
            $this->userId,
            $this->userId,
            new DateTimeImmutable($start),
            new DateTimeImmutable($end),
            'F6B teste jejum 12h',
        );
        (new SurgeryRepository($this->contextFor($this->tenantA), $this->pdo))->save($surgery);

        return $surgery;
    }

    private function createAppointment(): int
    {
        $this->pdo->prepare('INSERT INTO service (tenant_id, name, duration_minutes, price_cents) VALUES (:t, :n, 30, 1000)')
            ->execute(['t' => $this->tenantA, 'n' => 'F6B teste Retorno ' . bin2hex(random_bytes(4))]);
        $serviceId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare(
            'INSERT INTO appointment (tenant_id, system_unit_id, patient_id, service_id, professional_system_user_id, scheduled_at) '
            . 'VALUES (:t, :u, :p, :s, :prof, :at)',
        )->execute([
            't' => $this->tenantA,
            'u' => $this->unitId,
            'p' => $this->patientId,
            's' => $serviceId,
            'prof' => $this->userId,
            'at' => '2031-09-08 08:00:00',
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function contextFor(int $tenantId): TenantContext
    {
        return TenantContext::authenticated($tenantId, $this->userId, $this->unitId);
    }

    private function createTenant(string $slugPrefix): int
    {
        $slug = $slugPrefix . '-' . bin2hex(random_bytes(4));
        $statement = $this->pdo->prepare(
            'INSERT INTO tenant (public_id, slug, legal_name, status) VALUES (UUID(), :slug, :legal_name, :status)',
        );
        $statement->execute(['slug' => $slug, 'legal_name' => 'F6B teste tenant (' . $slug . ')', 'status' => 'active']);

        return (int) $this->pdo->lastInsertId();
    }
}
