<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Domain\Contract\SurgeryChecklistRepositoryInterface;
use CentralVet\Domain\Contract\SurgeryEventRepositoryInterface;
use CentralVet\Domain\Contract\SurgeryMaterialRepositoryInterface;
use CentralVet\Domain\Contract\SurgeryRepositoryInterface;
use CentralVet\Domain\Contract\SurgeryRoomRepositoryInterface;
use CentralVet\Domain\Contract\SurgeryTeamRepositoryInterface;
use CentralVet\Domain\Exception\InvalidStatusTransitionException;
use CentralVet\Domain\Surgery;
use CentralVet\Domain\SurgeryChecklist;
use CentralVet\Domain\SurgeryChecklistItem;
use CentralVet\Domain\SurgeryEvent;
use CentralVet\Domain\SurgeryMaterial;
use CentralVet\Domain\SurgeryRoom;
use CentralVet\Domain\SurgeryTeamMember;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\FakeSurgeryChecklistRepository;
use CentralVet\Tests\Support\FakeSurgeryEventRepository;
use CentralVet\Tests\Support\FakeSurgeryMaterialRepository;
use CentralVet\Tests\Support\FakeSurgeryRepository;
use CentralVet\Tests\Support\FakeSurgeryRoomRepository;
use CentralVet\Tests\Support\FakeSurgeryTeamRepository;
use DateTimeImmutable;
use LogicException;
use Throwable;

/**
 * T-05: behaviour of the in-memory doubles of the six surgery repositories
 * that the Onda 3 services are tested against. Covers the rules mirrored
 * from the PDO repositories (T-06): room overlap, conditional save of a
 * surgery whose status changed concurrently, duplicate checklist item,
 * orderings, tenant isolation and room locking.
 */
final class SurgeryFakesTest
{
    private const TENANT_ID = 1;
    private const OTHER_TENANT_ID = 2;
    private const UNIT_ID = 1;

    private function schedule(string $start, string $end, int $roomId = 1, int $tenantId = self::TENANT_ID): Surgery
    {
        return Surgery::schedule(
            tenantId: $tenantId,
            systemUnitId: self::UNIT_ID,
            patientId: 5,
            encounterId: 9,
            roomId: $roomId,
            procedureCatalogItemId: 4,
            procedureName: 'Orquiectomia',
            procedurePriceCents: 80000,
            surgeonSystemUserId: 3,
            scheduledBySystemUserId: 3,
            scheduledStartAt: new DateTimeImmutable($start),
            scheduledEndAt: new DateTimeImmutable($end),
            notesText: null,
        );
    }

    private function reconstituted(int $id, string $status): Surgery
    {
        return Surgery::reconstitute([
            'id' => $id,
            'tenant_id' => self::TENANT_ID,
            'system_unit_id' => self::UNIT_ID,
            'patient_id' => 5,
            'encounter_id' => 9,
            'room_id' => 1,
            'procedure_catalog_item_id' => 4,
            'procedure_name' => 'Orquiectomia',
            'procedure_price_cents' => 80000,
            'surgeon_system_user_id' => 3,
            'scheduled_by_system_user_id' => 3,
            'scheduled_start_at' => '2026-10-10 08:00:00',
            'scheduled_end_at' => '2026-10-10 10:00:00',
            'status' => $status,
        ]);
    }

    private function messageOf(callable $callback, string $exceptionClass): string
    {
        try {
            $callback();
        } catch (Throwable $e) {
            Assert::instanceOf($exceptionClass, $e, 'Unexpected exception ' . $e::class . ': ' . $e->getMessage());

            return $e->getMessage();
        }

        throw new \RuntimeException("Expected exception {$exceptionClass} was not thrown");
    }

    public function testEveryFakeImplementsItsContract(): void
    {
        Assert::instanceOf(SurgeryRoomRepositoryInterface::class, new FakeSurgeryRoomRepository(self::TENANT_ID));
        Assert::instanceOf(SurgeryRepositoryInterface::class, new FakeSurgeryRepository(self::TENANT_ID));
        Assert::instanceOf(SurgeryTeamRepositoryInterface::class, new FakeSurgeryTeamRepository(self::TENANT_ID));
        Assert::instanceOf(SurgeryChecklistRepositoryInterface::class, new FakeSurgeryChecklistRepository(self::TENANT_ID));
        Assert::instanceOf(SurgeryEventRepositoryInterface::class, new FakeSurgeryEventRepository(self::TENANT_ID));
        Assert::instanceOf(SurgeryMaterialRepositoryInterface::class, new FakeSurgeryMaterialRepository(self::TENANT_ID));
    }

    public function testOverlapInRoomIgnoresCancelledSurgeries(): void
    {
        $repository = new FakeSurgeryRepository(self::TENANT_ID, $this->schedule('2026-10-10 08:00:00', '2026-10-10 10:00:00'));
        Assert::same(0, $repository->saveCount);

        $start = new DateTimeImmutable('2026-10-10 09:00:00');
        $end = new DateTimeImmutable('2026-10-10 11:00:00');

        Assert::true($repository->hasOverlapInRoom(1, $start, $end, null), '09:00-11:00 overlaps 08:00-10:00');
        Assert::false($repository->hasOverlapInRoom(1, $start, $end, 1), 'the surgery itself is ignored');
        Assert::false($repository->hasOverlapInRoom(2, $start, $end, null), 'other room does not overlap');
        Assert::false(
            $repository->hasOverlapInRoom(1, new DateTimeImmutable('2026-10-10 10:00:00'), $end, null),
            'adjacent period does not overlap',
        );

        $loaded = $repository->findById(1);
        $loaded->cancel(new DateTimeImmutable('2026-10-09 12:00:00'), 3, 'Tutor desistiu');
        $repository->save($loaded);

        Assert::false($repository->hasOverlapInRoom(1, $start, $end, null), 'cancelled surgery frees the room');
        Assert::same(Surgery::STATUS_CANCELLED, $repository->lockStatus(1));
    }

    public function testForceStatusMakesStaleSaveFail(): void
    {
        $repository = new FakeSurgeryRepository(self::TENANT_ID, $this->reconstituted(7, Surgery::STATUS_PRE_OP));

        $copy = $repository->findById(7);
        Assert::same(Surgery::STATUS_PRE_OP, $copy->loadedStatus());

        $repository->forceStatus(7, Surgery::STATUS_CANCELLED);
        Assert::same(Surgery::STATUS_CANCELLED, $repository->lockStatus(7));
        Assert::same(Surgery::STATUS_CANCELLED, $repository->findById(7)->status());

        $message = $this->messageOf(fn () => $repository->save($copy), InvalidStatusTransitionException::class);
        Assert::stringContains('changed status concurrently', $message);
        Assert::same('Surgery 7 changed status concurrently', $message);
        Assert::same(0, $repository->saveCount);
    }

    public function testSameInstanceSavesTwiceButStaleInstanceFails(): void
    {
        $repository = new FakeSurgeryRepository(self::TENANT_ID, $this->reconstituted(7, Surgery::STATUS_SCHEDULED));

        $copy = $repository->findById(7);
        $stale = $repository->findById(7);

        $copy->startPreOp();
        $copy->recordConsent('Maria Tutora', 'Autorizo o procedimento.', 3, new DateTimeImmutable('2026-10-10 07:30:00'));
        $repository->save($copy);
        $copy->start(new DateTimeImmutable('2026-10-10 08:05:00'));
        $repository->save($copy);

        Assert::same(Surgery::STATUS_IN_PROGRESS, $repository->lockStatus(7));
        Assert::same(2, $repository->saveCount);

        $stale->cancel(new DateTimeImmutable('2026-10-10 08:10:00'), 3, 'Outra aba');
        $message = $this->messageOf(fn () => $repository->save($stale), InvalidStatusTransitionException::class);
        Assert::same('Surgery 7 changed status concurrently', $message);
        Assert::same(Surgery::STATUS_IN_PROGRESS, $repository->lockStatus(7));
    }

    public function testFreshSurgeryCanBeSavedAgainAfterLoad(): void
    {
        $repository = new FakeSurgeryRepository(self::TENANT_ID);
        $surgery = $repository->save($this->schedule('2026-10-10 08:00:00', '2026-10-10 10:00:00'));
        Assert::same(1, $surgery->id());

        $loaded = $repository->findById(1);
        $loaded->startPreOp();
        $repository->save($loaded);

        Assert::same(Surgery::STATUS_PRE_OP, $repository->lockStatus(1));
        Assert::same(2, $repository->saveCount);
        Assert::null($repository->lockStatus(99));
    }

    public function testSurgeryTenantIsolationAndDayListing(): void
    {
        $repository = new FakeSurgeryRepository(
            self::TENANT_ID,
            $this->schedule('2026-10-10 14:00:00', '2026-10-10 15:00:00'),
            $this->schedule('2026-10-10 08:00:00', '2026-10-10 09:00:00', 2),
            $this->schedule('2026-10-11 08:00:00', '2026-10-11 09:00:00'),
            $this->schedule('2026-10-10 08:00:00', '2026-10-10 10:00:00', 1, self::OTHER_TENANT_ID),
        );

        Assert::null($repository->findById(4), 'other tenant surgery is invisible');
        Assert::false($repository->hasOverlapInRoom(
            1,
            new DateTimeImmutable('2026-10-10 09:00:00'),
            new DateTimeImmutable('2026-10-10 09:30:00'),
            null,
        ), 'other tenant surgery does not block the room');

        $day = $repository->listByUnitAndDay(self::UNIT_ID, new DateTimeImmutable('2026-10-10'));
        Assert::same([2, 1], array_map(static fn (Surgery $s): ?int => $s->id(), $day));
    }

    public function testRoomListingLookupAndLock(): void
    {
        $repository = new FakeSurgeryRoomRepository(
            self::TENANT_ID,
            SurgeryRoom::create(self::TENANT_ID, self::UNIT_ID, 'S2', 'Sala 2'),
            SurgeryRoom::create(self::TENANT_ID, self::UNIT_ID, 'S1', 'Sala 1'),
            SurgeryRoom::create(self::OTHER_TENANT_ID, self::UNIT_ID, 'S3', 'Sala 3'),
        );
        Assert::same(0, $repository->saveCount);
        Assert::same(0, $repository->lockCalls);

        Assert::same(['S1', 'S2'], array_map(static fn (SurgeryRoom $r): string => $r->code(), $repository->listByUnit(self::UNIT_ID)));
        Assert::same(2, $repository->findByCode(self::UNIT_ID, 'S1')?->id());
        Assert::null($repository->findByCode(self::UNIT_ID, 'S3'));
        Assert::null($repository->findById(3));

        Assert::true($repository->lockForScheduling(1));
        Assert::false($repository->lockForScheduling(3), 'other tenant room cannot be locked');
        Assert::same(2, $repository->lockCalls);
    }

    public function testTeamIsReplacedPerSurgery(): void
    {
        $repository = new FakeSurgeryTeamRepository(self::TENANT_ID);
        $repository->replaceForSurgery(1, [
            SurgeryTeamMember::create(self::TENANT_ID, 1, 3, SurgeryTeamMember::ROLE_SURGEON),
            SurgeryTeamMember::create(self::TENANT_ID, 1, 4, SurgeryTeamMember::ROLE_ANESTHETIST),
        ]);
        $repository->replaceForSurgery(2, [SurgeryTeamMember::create(self::TENANT_ID, 2, 3, SurgeryTeamMember::ROLE_SURGEON)]);
        $repository->replaceForSurgery(1, [SurgeryTeamMember::create(self::TENANT_ID, 1, 5, SurgeryTeamMember::ROLE_SURGEON)]);

        $team = $repository->listBySurgery(1);
        Assert::count(1, $team);
        Assert::same(5, $team[0]->systemUserId());
        Assert::count(1, $repository->listBySurgery(2));
    }

    public function testSecondSaveOfSameChecklistItemIsRefused(): void
    {
        $repository = new FakeSurgeryChecklistRepository(self::TENANT_ID);
        $item = SurgeryChecklistItem::check(
            self::TENANT_ID,
            1,
            SurgeryChecklist::PHASE_SIGN_IN,
            'patient_identity_confirmed',
            3,
            new DateTimeImmutable('2026-10-10 07:50:00'),
        );
        $repository->save($item);

        $duplicate = SurgeryChecklistItem::check(
            self::TENANT_ID,
            1,
            SurgeryChecklist::PHASE_SIGN_IN,
            'patient_identity_confirmed',
            4,
            new DateTimeImmutable('2026-10-10 07:51:00'),
        );
        $message = $this->messageOf(fn () => $repository->save($duplicate), InvalidStatusTransitionException::class);
        Assert::same('Checklist phase "sign_in" is already confirmed for surgery 1', $message);

        $this->messageOf(fn () => $repository->save($item), InvalidStatusTransitionException::class);

        Assert::count(1, $repository->listBySurgery(1));
        Assert::count(0, $repository->listBySurgery(2));
        Assert::same(1, $repository->saveCount);
    }

    public function testEventsAreListedMostRecentFirstAndAppendOnly(): void
    {
        $repository = new FakeSurgeryEventRepository(self::TENANT_ID);
        $first = $repository->save(SurgeryEvent::record(self::TENANT_ID, 1, SurgeryEvent::TYPE_SCHEDULED, 3, new DateTimeImmutable('2026-10-09 10:00:00'), null));
        $repository->save(SurgeryEvent::record(self::TENANT_ID, 1, SurgeryEvent::TYPE_CONSENT, 3, new DateTimeImmutable('2026-10-10 07:00:00'), null));
        $repository->save(SurgeryEvent::record(self::TENANT_ID, 1, SurgeryEvent::TYPE_PRE_OP, 3, new DateTimeImmutable('2026-10-10 07:00:00'), 'Jejum ok'));
        $repository->save(SurgeryEvent::record(self::TENANT_ID, 2, SurgeryEvent::TYPE_SCHEDULED, 3, new DateTimeImmutable('2026-10-11 10:00:00'), null));

        Assert::same([3, 2, 1], array_map(static fn (SurgeryEvent $e): ?int => $e->id(), $repository->listBySurgery(1)));
        Assert::throws(LogicException::class, fn () => $repository->remove($first));
    }

    public function testMaterialsAreListedInRecordOrderAndRemovable(): void
    {
        $repository = new FakeSurgeryMaterialRepository(
            self::TENANT_ID,
            SurgeryMaterial::record(self::TENANT_ID, 1, 10, 2, 3, new DateTimeImmutable('2026-10-10 09:00:00')),
            SurgeryMaterial::record(self::TENANT_ID, 1, 11, 1, 3, new DateTimeImmutable('2026-10-10 08:30:00')),
            SurgeryMaterial::record(self::OTHER_TENANT_ID, 1, 12, 1, 3, new DateTimeImmutable('2026-10-10 08:00:00')),
        );
        Assert::same(0, $repository->saveCount);

        Assert::same([2, 1], array_map(static fn (SurgeryMaterial $m): ?int => $m->id(), $repository->listBySurgery(1)));
        Assert::null($repository->findById(3));

        $repository->remove($repository->findById(2));
        Assert::same([1], array_map(static fn (SurgeryMaterial $m): ?int => $m->id(), $repository->listBySurgery(1)));
    }
}
