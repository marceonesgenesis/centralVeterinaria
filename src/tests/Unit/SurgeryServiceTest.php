<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Application\SurgeryService;
use CentralVet\Domain\Encounter;
use CentralVet\Domain\EncounterAccount;
use CentralVet\Domain\Contract\SurgeryRepositoryInterface;
use CentralVet\Domain\Exception\CrossTenantReferenceException;
use CentralVet\Domain\Exception\InvalidStatusTransitionException;
use CentralVet\Domain\Exception\SurgeryRoomUnavailableException;
use CentralVet\Domain\ProcedureCatalogItem;
use CentralVet\Domain\Surgery;
use CentralVet\Domain\SurgeryChecklist;
use CentralVet\Domain\SurgeryChecklistItem;
use CentralVet\Domain\SurgeryEvent;
use CentralVet\Domain\SurgeryRoom;
use CentralVet\Domain\SurgeryTeamMember;
use CentralVet\Tenancy\TenantContext;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\AssertionFailedException;
use CentralVet\Tests\Support\FakeAuthorizationPolicy;
use CentralVet\Tests\Support\FakeEncounterAccountRepository;
use CentralVet\Tests\Support\FakeEncounterRepository;
use CentralVet\Tests\Support\FakeProcedureCatalogRepository;
use CentralVet\Tests\Support\FakeSurgeryChecklistRepository;
use CentralVet\Tests\Support\FakeSurgeryEventRepository;
use CentralVet\Tests\Support\FakeSurgeryRepository;
use CentralVet\Tests\Support\FakeSurgeryRoomRepository;
use CentralVet\Tests\Support\FakeSurgeryTeamRepository;
use CentralVet\Tests\Support\FakeTenantUserDirectory;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Unit tests for SurgeryService (T-08) against the in-memory fakes of T-05
 * and the 6A: scheduling order of checks, room overlap (Review Focus 1),
 * start gated by consent + checklist (Review Focus 5), concurrent status
 * change, cancellation, team and clinical events.
 */
final class SurgeryServiceTest
{
    private const ACTION = 'test.surgery';
    private const TENANT_ID = 1;
    private const UNIT_ID = 5;
    private const OTHER_UNIT_ID = 9;
    private const USER_ID = 3;
    private const SURGEON_ID = 10;
    private const ANESTHETIST_ID = 11;
    private const NOW = '2026-10-05 07:00:00';

    private FakeSurgeryRepository $surgeries;
    private FakeSurgeryRoomRepository $rooms;
    private FakeSurgeryTeamRepository $team;
    private FakeSurgeryChecklistRepository $checklist;
    private FakeSurgeryEventRepository $events;
    private FakeEncounterRepository $encounters;
    private FakeEncounterAccountRepository $accounts;
    private FakeProcedureCatalogRepository $procedures;
    private FakeAuthorizationPolicy $policy;

    public function setUp(): void
    {
        $this->surgeries = new FakeSurgeryRepository(self::TENANT_ID);
        $this->rooms = new FakeSurgeryRoomRepository(self::TENANT_ID);
        $this->team = new FakeSurgeryTeamRepository(self::TENANT_ID);
        $this->checklist = new FakeSurgeryChecklistRepository(self::TENANT_ID);
        $this->events = new FakeSurgeryEventRepository(self::TENANT_ID);
        $this->encounters = new FakeEncounterRepository(self::TENANT_ID);
        $this->accounts = new FakeEncounterAccountRepository(self::TENANT_ID);
        $this->procedures = new FakeProcedureCatalogRepository(self::TENANT_ID);
        $this->policy = new FakeAuthorizationPolicy(allowed: true);
    }

    private function service(?FakeTenantUserDirectory $users = null): SurgeryService
    {
        return new SurgeryService(
            $this->surgeries,
            $this->rooms,
            $this->team,
            $this->checklist,
            $this->events,
            $this->encounters,
            $this->accounts,
            $this->procedures,
            $users ?? FakeTenantUserDirectory::allowingAll(),
            $this->policy,
            TenantContext::authenticated(self::TENANT_ID, self::USER_ID, self::UNIT_ID),
            static fn (): DateTimeImmutable => new DateTimeImmutable(self::NOW),
        );
    }

    private function encounter(int $patientId = 7, int $unitId = self::UNIT_ID): int
    {
        $encounter = Encounter::start(self::TENANT_ID, $unitId, $patientId, null, self::SURGEON_ID, new DateTimeImmutable('-1 hour'));
        $this->encounters->save($encounter);

        return (int) $encounter->id();
    }

    private function room(string $code = 'S1', int $unitId = self::UNIT_ID, bool $active = true): int
    {
        $room = SurgeryRoom::create(self::TENANT_ID, $unitId, $code, 'Sala ' . $code);

        if (!$active) {
            $room->deactivate();
        }

        $this->rooms->save($room);

        return (int) $room->id();
    }

    private function procedure(int $priceCents = 250000): int
    {
        $item = ProcedureCatalogItem::create(self::TENANT_ID, 'Orquiectomia', $priceCents, 60, null);
        $this->procedures->save($item);

        return (int) $item->id();
    }

    /** @param list<array{system_user_id: int, role: string}>|null $team */
    private function schedule(
        int $encounterId,
        int $roomId,
        int $procedureId,
        string $start = '2026-10-06 08:00:00',
        int $duration = 120,
        ?array $team = null,
    ): Surgery {
        return $this->service()->schedule(
            $encounterId,
            $roomId,
            $procedureId,
            self::SURGEON_ID,
            new DateTimeImmutable($start),
            $duration,
            $team ?? [['system_user_id' => self::ANESTHETIST_ID, 'role' => SurgeryTeamMember::ROLE_ANESTHETIST]],
            'Jejum de 8h',
            self::ACTION,
        );
    }

    private function scheduledSurgery(): Surgery
    {
        return $this->schedule($this->encounter(), $this->room(), $this->procedure());
    }

    private function confirmPhase(int $surgeryId, string $phase): void
    {
        foreach (SurgeryChecklist::items($phase) as $code) {
            $this->checklist->save(SurgeryChecklistItem::check(
                self::TENANT_ID,
                $surgeryId,
                $phase,
                $code,
                self::USER_ID,
                new DateTimeImmutable(self::NOW),
            ));
        }
    }

    /** @return list<SurgeryEvent> */
    private function eventsOfType(int $surgeryId, string $type): array
    {
        return array_values(array_filter(
            $this->events->listBySurgery($surgeryId),
            static fn (SurgeryEvent $e): bool => $e->eventType() === $type,
        ));
    }

    /** @param class-string<\Throwable> $class */
    private static function expectThrows(string $class, string $message, callable $callback): void
    {
        try {
            $callback();
        } catch (\Throwable $e) {
            Assert::instanceOf($class, $e, 'Unexpected ' . $e::class . ': ' . $e->getMessage());
            Assert::stringContains($message, $e->getMessage());

            return;
        }

        throw new AssertionFailedException("Expected {$class} with \"{$message}\" was not thrown");
    }

    public function testScheduleRecordsSurgeryWithCopiedPriceAndTeam(): void
    {
        $roomId = $this->room();
        $surgery = $this->schedule($this->encounter(), $roomId, $this->procedure(250000));

        Assert::notNull($surgery->id());
        Assert::same(Surgery::STATUS_SCHEDULED, $surgery->status());
        Assert::same(250000, $surgery->procedurePriceCents());
        Assert::same('Orquiectomia', $surgery->procedureName());
        Assert::same(self::UNIT_ID, $surgery->systemUnitId());
        Assert::same(7, $surgery->patientId());
        Assert::same(self::USER_ID, $surgery->scheduledBySystemUserId());
        Assert::same('2026-10-06 10:00:00', $surgery->scheduledEndAt()->format('Y-m-d H:i:s'));
        Assert::same(1, $this->rooms->lockCalls);

        $team = [];

        foreach ($this->team->listBySurgery((int) $surgery->id()) as $member) {
            $team[$member->systemUserId()] = $member->role();
        }

        ksort($team);
        Assert::same([
            self::SURGEON_ID => SurgeryTeamMember::ROLE_SURGEON,
            self::ANESTHETIST_ID => SurgeryTeamMember::ROLE_ANESTHETIST,
        ], $team);
        Assert::count(1, $this->eventsOfType((int) $surgery->id(), SurgeryEvent::TYPE_SCHEDULED));
    }

    public function testDuplicatedTeamPairsAreDiscarded(): void
    {
        $surgery = $this->schedule($this->encounter(), $this->room(), $this->procedure(), team: [
            ['system_user_id' => self::SURGEON_ID, 'role' => SurgeryTeamMember::ROLE_SURGEON],
            ['system_user_id' => self::ANESTHETIST_ID, 'role' => SurgeryTeamMember::ROLE_ANESTHETIST],
            ['system_user_id' => self::ANESTHETIST_ID, 'role' => SurgeryTeamMember::ROLE_ANESTHETIST],
        ]);

        Assert::count(2, $this->team->listBySurgery((int) $surgery->id()));
    }

    public function testOverlappingScheduleInSameRoomIsRefused(): void
    {
        $encounterId = $this->encounter();
        $roomId = $this->room();
        $procedureId = $this->procedure();
        $this->schedule($encounterId, $roomId, $procedureId, '2026-10-06 08:00:00', 120);
        $this->surgeries->saveCount = 0;

        self::expectThrows(
            SurgeryRoomUnavailableException::class,
            "Surgery room {$roomId} is already booked for this period",
            fn () => $this->schedule($encounterId, $roomId, $procedureId, '2026-10-06 09:00:00', 60),
        );

        Assert::same(0, $this->surgeries->saveCount);

        // Back-to-back slot (starts exactly at the end) is allowed.
        $next = $this->schedule($encounterId, $roomId, $procedureId, '2026-10-06 10:00:00', 60);
        Assert::notNull($next->id());
    }

    public function testRoomOfAnotherUnitOrInactiveIsRefused(): void
    {
        $encounterId = $this->encounter();
        $procedureId = $this->procedure();
        $otherUnitRoom = $this->room('X1', self::OTHER_UNIT_ID);
        $inactiveRoom = $this->room('S9', active: false);

        self::expectThrows(
            InvalidArgumentException::class,
            "Surgery room {$otherUnitRoom} belongs to another unit",
            fn () => $this->schedule($encounterId, $otherUnitRoom, $procedureId),
        );
        self::expectThrows(
            SurgeryRoomUnavailableException::class,
            "Surgery room {$inactiveRoom} is not active",
            fn () => $this->schedule($encounterId, $inactiveRoom, $procedureId),
        );
        self::expectThrows(
            CrossTenantReferenceException::class,
            'room_id 999 was not found for the authenticated tenant',
            fn () => $this->schedule($encounterId, 999, $procedureId),
        );
    }

    public function testScheduleValidatesEncounterProcedureDurationAndUsers(): void
    {
        $encounterId = $this->encounter();
        $roomId = $this->room();
        $procedureId = $this->procedure();

        self::expectThrows(
            CrossTenantReferenceException::class,
            'encounter_id 404 was not found for the authenticated tenant',
            fn () => $this->schedule(404, $roomId, $procedureId),
        );
        self::expectThrows(
            CrossTenantReferenceException::class,
            'procedure_catalog_item_id 404 was not found for the authenticated tenant',
            fn () => $this->schedule($encounterId, $roomId, 404),
        );
        self::expectThrows(
            InvalidArgumentException::class,
            'duration_minutes must be between 15 and 1440',
            fn () => $this->schedule($encounterId, $roomId, $procedureId, duration: 10),
        );
        self::expectThrows(
            InvalidArgumentException::class,
            'Unknown team role "nurse"',
            fn () => $this->schedule($encounterId, $roomId, $procedureId, team: [['system_user_id' => 12, 'role' => 'nurse']]),
        );

        $users = new FakeTenantUserDirectory([self::SURGEON_ID]);
        self::expectThrows(
            InvalidArgumentException::class,
            'Team member ' . self::ANESTHETIST_ID . ' must be an active user of this tenant',
            fn () => $this->service($users)->schedule(
                $encounterId,
                $roomId,
                $procedureId,
                self::SURGEON_ID,
                new DateTimeImmutable('2026-10-06 08:00:00'),
                60,
                [['system_user_id' => self::ANESTHETIST_ID, 'role' => SurgeryTeamMember::ROLE_ANESTHETIST]],
                null,
                self::ACTION,
            ),
        );
        self::expectThrows(
            InvalidArgumentException::class,
            'surgeon_system_user_id must be an active user of this tenant',
            fn () => $this->service(new FakeTenantUserDirectory([]))->schedule(
                $encounterId,
                $roomId,
                $procedureId,
                self::SURGEON_ID,
                new DateTimeImmutable('2026-10-06 08:00:00'),
                60,
                [],
                null,
                self::ACTION,
            ),
        );
        Assert::same(0, $this->surgeries->saveCount);
    }

    public function testClosedEncounterAccountBlocksScheduling(): void
    {
        $encounterId = $this->encounter();
        $account = EncounterAccount::open(self::TENANT_ID, $encounterId, 7, 2, self::UNIT_ID);
        $account->close(new DateTimeImmutable());
        $this->accounts->save($account);

        self::expectThrows(
            InvalidStatusTransitionException::class,
            sprintf('Encounter account %d cannot be modified: status is "closed", not "open"', $account->id()),
            fn () => $this->schedule($encounterId, $this->room(), $this->procedure()),
        );
    }

    public function testStartRequiresConsentAndChecklist(): void
    {
        $id = (int) $this->scheduledSurgery()->id();
        $service = $this->service();
        $service->startPreOp($id, self::ACTION);

        self::expectThrows(
            InvalidStatusTransitionException::class,
            'has no recorded consent',
            fn () => $service->start($id, self::ACTION),
        );

        $service->recordConsent($id, 'Maria Tutora', 'Autorizo o procedimento.', self::ACTION);
        $this->confirmPhase($id, SurgeryChecklist::PHASE_SIGN_IN);

        self::expectThrows(
            InvalidStatusTransitionException::class,
            "Checklist phase \"time_out\" is not confirmed for surgery {$id}",
            fn () => $service->start($id, self::ACTION),
        );
        Assert::same(Surgery::STATUS_PRE_OP, $service->get($id, self::ACTION)->status());

        $this->confirmPhase($id, SurgeryChecklist::PHASE_TIME_OUT);
        $started = $service->start($id, self::ACTION);

        Assert::same(Surgery::STATUS_IN_PROGRESS, $started->status());
        Assert::same(self::NOW, $started->startedAt()?->format('Y-m-d H:i:s'));
        Assert::count(2, $this->eventsOfType($id, SurgeryEvent::TYPE_STATUS));
        Assert::count(1, $this->eventsOfType($id, SurgeryEvent::TYPE_CONSENT));
    }

    public function testConcurrentCancellationMakesStartPreOpFail(): void
    {
        $id = (int) $this->scheduledSurgery()->id();
        $fake = $this->surgeries;

        // Another tab cancels the surgery right after this request read it
        // (between findById and save): forceStatus runs inside findById.
        $racing = new class ($fake, $id) implements SurgeryRepositoryInterface {
            public function __construct(private readonly FakeSurgeryRepository $inner, private readonly int $raceId)
            {
            }

            public function tenantId(): int
            {
                return $this->inner->tenantId();
            }

            public function findById(int|string $id): ?object
            {
                $found = $this->inner->findById($id);

                if ((int) $id === $this->raceId) {
                    $this->inner->forceStatus($this->raceId, Surgery::STATUS_CANCELLED);
                }

                return $found;
            }

            public function save(object $entity): object
            {
                return $this->inner->save($entity);
            }

            public function remove(object $entity): void
            {
                $this->inner->remove($entity);
            }

            public function listByUnitAndDay(int $systemUnitId, DateTimeImmutable $day): array
            {
                return $this->inner->listByUnitAndDay($systemUnitId, $day);
            }

            public function hasOverlapInRoom(int $roomId, DateTimeImmutable $startAt, DateTimeImmutable $endAt, ?int $exceptSurgeryId): bool
            {
                return $this->inner->hasOverlapInRoom($roomId, $startAt, $endAt, $exceptSurgeryId);
            }

            public function lockStatus(int $surgeryId): ?string
            {
                return $this->inner->lockStatus($surgeryId);
            }
        };

        $service = new SurgeryService(
            $racing,
            $this->rooms,
            $this->team,
            $this->checklist,
            $this->events,
            $this->encounters,
            $this->accounts,
            $this->procedures,
            FakeTenantUserDirectory::allowingAll(),
            $this->policy,
            TenantContext::authenticated(self::TENANT_ID, self::USER_ID, self::UNIT_ID),
            static fn (): DateTimeImmutable => new DateTimeImmutable(self::NOW),
        );

        self::expectThrows(
            InvalidStatusTransitionException::class,
            'changed status concurrently',
            fn () => $service->startPreOp($id, self::ACTION),
        );
        Assert::same(Surgery::STATUS_CANCELLED, $fake->lockStatus($id));
        Assert::count(0, $this->eventsOfType($id, SurgeryEvent::TYPE_STATUS));
    }

    public function testCancelRecordsReasonAndEvent(): void
    {
        $id = (int) $this->scheduledSurgery()->id();
        $cancelled = $this->service()->cancel($id, 'Tutor desistiu', self::ACTION);

        Assert::same(Surgery::STATUS_CANCELLED, $cancelled->status());
        Assert::same('Tutor desistiu', $cancelled->cancellationReasonText());
        Assert::same(self::USER_ID, $cancelled->cancelledBySystemUserId());
        $events = $this->eventsOfType($id, SurgeryEvent::TYPE_CANCELLATION);
        Assert::count(1, $events);
        Assert::same('Tutor desistiu', $events[0]->notesText());

        self::expectThrows(
            InvalidStatusTransitionException::class,
            "Surgery {$id} is cancelled",
            fn () => $this->service()->recordClinicalEvent($id, SurgeryEvent::TYPE_POST_OP, 'Estável', self::ACTION),
        );
        self::expectThrows(
            InvalidStatusTransitionException::class,
            "Surgery {$id} is not open for pre-operative changes",
            fn () => $this->service()->replaceTeam($id, [], self::ACTION),
        );
    }

    public function testRecordClinicalEventAcceptsOnlyClinicalTypes(): void
    {
        $id = (int) $this->scheduledSurgery()->id();
        $service = $this->service();

        $event = $service->recordClinicalEvent($id, SurgeryEvent::TYPE_PRE_OP, 'Jejum confirmado', self::ACTION);
        Assert::notNull($event->id());
        Assert::same(SurgeryEvent::TYPE_PRE_OP, $event->eventType());

        self::expectThrows(
            InvalidArgumentException::class,
            'Unknown surgery event type "status"',
            fn () => $service->recordClinicalEvent($id, SurgeryEvent::TYPE_STATUS, 'x', self::ACTION),
        );
    }

    public function testReplaceTeamKeepsSurgeonAndReadsAreScoped(): void
    {
        $surgery = $this->scheduledSurgery();
        $id = (int) $surgery->id();
        $service = $this->service();

        $members = $service->replaceTeam($id, [['system_user_id' => 12, 'role' => SurgeryTeamMember::ROLE_ASSISTANT]], self::ACTION);

        Assert::count(2, $members);
        Assert::count(2, $service->listTeam($id, self::ACTION));
        Assert::count(1, $service->listForDay(new DateTimeImmutable('2026-10-06'), self::ACTION));
        Assert::count(1, $service->listProcedures(self::ACTION));
        Assert::true(count($service->listEvents($id, self::ACTION)) >= 1);

        self::expectThrows(
            CrossTenantReferenceException::class,
            'Surgery 404 not found for this tenant',
            fn () => $service->get(404, self::ACTION),
        );
    }
}
