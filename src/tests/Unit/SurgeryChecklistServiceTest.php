<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Application\SurgeryChecklistService;
use CentralVet\Authorization\Exception\AuthorizationDenied;
use CentralVet\Domain\Exception\CrossTenantReferenceException;
use CentralVet\Domain\Exception\InvalidStatusTransitionException;
use CentralVet\Domain\Surgery;
use CentralVet\Domain\SurgeryChecklist;
use CentralVet\Domain\SurgeryChecklistItem;
use CentralVet\Domain\SurgeryEvent;
use CentralVet\Tenancy\TenantContext;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\AssertionFailedException;
use CentralVet\Tests\Support\FakeAuthorizationPolicy;
use CentralVet\Tests\Support\FakeSurgeryChecklistRepository;
use CentralVet\Tests\Support\FakeSurgeryEventRepository;
use CentralVet\Tests\Support\FakeSurgeryRepository;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * T-09: SurgeryChecklistService — confirming each checklist phase (one item
 * row per code, same checked_at and user, a `checklist` event) with the
 * status gates (sign_in/time_out in pre_op, sign_out in in_progress), the
 * sign_in prerequisite of time_out, the duplicate refusal and phaseStatus().
 */
final class SurgeryChecklistServiceTest
{
    private const TENANT_ID = 1;
    private const UNIT_ID = 3;
    private const USER_ID = 7;
    private const ACTION = 'SurgeryChecklistForm::onConfirm';

    private FakeSurgeryRepository $surgeries;
    private FakeSurgeryChecklistRepository $checklist;
    private FakeSurgeryEventRepository $events;
    private FakeAuthorizationPolicy $authorization;
    private DateTimeImmutable $now;

    public function setUp(): void
    {
        $this->surgeries = new FakeSurgeryRepository(
            self::TENANT_ID,
            $this->surgery(10, Surgery::STATUS_PRE_OP),
            $this->surgery(11, Surgery::STATUS_IN_PROGRESS),
            $this->surgery(12, Surgery::STATUS_SCHEDULED),
        );
        $this->checklist = new FakeSurgeryChecklistRepository(self::TENANT_ID);
        $this->events = new FakeSurgeryEventRepository(self::TENANT_ID);
        $this->authorization = new FakeAuthorizationPolicy();
        $this->now = new DateTimeImmutable('2026-10-10 07:45:00');
    }

    private function service(): SurgeryChecklistService
    {
        return new SurgeryChecklistService(
            $this->surgeries,
            $this->checklist,
            $this->events,
            $this->authorization,
            TenantContext::authenticated(self::TENANT_ID, self::USER_ID, self::UNIT_ID),
            fn (): DateTimeImmutable => $this->now,
        );
    }

    private function surgery(int $id, string $status): Surgery
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

    /** @param class-string<\Throwable> $class */
    private static function throwsWithMessage(string $class, string $message, callable $callback): void
    {
        try {
            $callback();
        } catch (\Throwable $e) {
            Assert::instanceOf($class, $e, "Expected {$class}, got " . $e::class . ': ' . $e->getMessage());
            Assert::same($message, $e->getMessage());

            return;
        }

        throw new AssertionFailedException("Expected exception {$class} was not thrown");
    }

    public function testConfirmSignInRecordsFiveItemsAnEventAndMarksThePhase(): void
    {
        $codes = SurgeryChecklist::items(SurgeryChecklist::PHASE_SIGN_IN);
        Assert::count(5, $codes);

        $saved = $this->service()->confirmPhase(10, SurgeryChecklist::PHASE_SIGN_IN, $codes, self::ACTION);

        Assert::count(5, $saved);
        Assert::same(5, $this->checklist->saveCount);
        foreach ($saved as $item) {
            Assert::instanceOf(SurgeryChecklistItem::class, $item);
            Assert::notNull($item->id());
            Assert::same(10, $item->surgeryId());
            Assert::same(SurgeryChecklist::PHASE_SIGN_IN, $item->phase());
            Assert::same(self::USER_ID, $item->checkedBySystemUserId());
            Assert::same($this->now->format('Y-m-d H:i:s'), $item->checkedAt()->format('Y-m-d H:i:s'));
        }
        Assert::same($codes, array_map(static fn (SurgeryChecklistItem $i): string => $i->itemCode(), $saved));

        $events = $this->events->listBySurgery(10);
        Assert::count(1, $events);
        Assert::same(SurgeryEvent::TYPE_CHECKLIST, $events[0]->eventType());
        Assert::same(SurgeryChecklist::PHASE_SIGN_IN, $events[0]->notesText());
        Assert::same(self::USER_ID, $events[0]->recordedBySystemUserId());

        $request = $this->authorization->requests[0];
        Assert::same(self::UNIT_ID, $request->resourceUnitId());
        Assert::same('surgery', $request->entityType());
        Assert::same(10, $request->entityId());

        $status = $this->service()->phaseStatus(10, self::ACTION);
        Assert::same(SurgeryChecklist::PHASES, array_keys($status));
        Assert::true($status[SurgeryChecklist::PHASE_SIGN_IN]['confirmed']);
        Assert::same(self::USER_ID, $status[SurgeryChecklist::PHASE_SIGN_IN]['checked_by_system_user_id']);
        Assert::same(
            $this->now->format('Y-m-d H:i:s'),
            $status[SurgeryChecklist::PHASE_SIGN_IN]['checked_at']->format('Y-m-d H:i:s'),
        );
        Assert::false($status[SurgeryChecklist::PHASE_TIME_OUT]['confirmed']);
        Assert::null($status[SurgeryChecklist::PHASE_TIME_OUT]['checked_by_system_user_id']);
        Assert::null($status[SurgeryChecklist::PHASE_TIME_OUT]['checked_at']);
        Assert::false($status[SurgeryChecklist::PHASE_SIGN_OUT]['confirmed']);
    }

    public function testConfirmingTheSamePhaseTwiceIsRefusedWithoutSaving(): void
    {
        $codes = SurgeryChecklist::items(SurgeryChecklist::PHASE_SIGN_IN);
        $this->service()->confirmPhase(10, SurgeryChecklist::PHASE_SIGN_IN, $codes, self::ACTION);
        $saves = $this->checklist->saveCount;
        $eventSaves = $this->events->saveCount;

        self::throwsWithMessage(
            InvalidStatusTransitionException::class,
            'Checklist phase "sign_in" is already confirmed for surgery 10',
            fn () => $this->service()->confirmPhase(10, SurgeryChecklist::PHASE_SIGN_IN, $codes, self::ACTION),
        );

        Assert::same($saves, $this->checklist->saveCount);
        Assert::same($eventSaves, $this->events->saveCount);
    }

    public function testTimeOutRequiresSignInConfirmed(): void
    {
        self::throwsWithMessage(
            InvalidStatusTransitionException::class,
            'Checklist phase "sign_in" is not confirmed for surgery 10',
            fn () => $this->service()->confirmPhase(
                10,
                SurgeryChecklist::PHASE_TIME_OUT,
                SurgeryChecklist::items(SurgeryChecklist::PHASE_TIME_OUT),
                self::ACTION,
            ),
        );
        Assert::same(0, $this->checklist->saveCount);

        $this->service()->confirmPhase(10, SurgeryChecklist::PHASE_SIGN_IN, SurgeryChecklist::items(SurgeryChecklist::PHASE_SIGN_IN), self::ACTION);
        $saved = $this->service()->confirmPhase(
            10,
            SurgeryChecklist::PHASE_TIME_OUT,
            SurgeryChecklist::items(SurgeryChecklist::PHASE_TIME_OUT),
            self::ACTION,
        );

        Assert::count(4, $saved);
        Assert::true($this->service()->phaseStatus(10, self::ACTION)[SurgeryChecklist::PHASE_TIME_OUT]['confirmed']);
    }

    public function testSignOutCannotBeConfirmedInPreOp(): void
    {
        self::throwsWithMessage(
            InvalidStatusTransitionException::class,
            'Checklist phase "sign_out" cannot be confirmed while surgery 10 is pre_op',
            fn () => $this->service()->confirmPhase(
                10,
                SurgeryChecklist::PHASE_SIGN_OUT,
                SurgeryChecklist::items(SurgeryChecklist::PHASE_SIGN_OUT),
                self::ACTION,
            ),
        );
        Assert::same(0, $this->checklist->saveCount);
    }

    public function testSignOutIsConfirmedInProgressAndSignInIsRefusedThere(): void
    {
        $saved = $this->service()->confirmPhase(
            11,
            SurgeryChecklist::PHASE_SIGN_OUT,
            SurgeryChecklist::items(SurgeryChecklist::PHASE_SIGN_OUT),
            self::ACTION,
        );
        Assert::count(4, $saved);

        self::throwsWithMessage(
            InvalidStatusTransitionException::class,
            'Checklist phase "sign_in" cannot be confirmed while surgery 11 is in_progress',
            fn () => $this->service()->confirmPhase(
                11,
                SurgeryChecklist::PHASE_SIGN_IN,
                SurgeryChecklist::items(SurgeryChecklist::PHASE_SIGN_IN),
                self::ACTION,
            ),
        );
    }

    public function testSignInCannotBeConfirmedWhileScheduled(): void
    {
        self::throwsWithMessage(
            InvalidStatusTransitionException::class,
            'Checklist phase "sign_in" cannot be confirmed while surgery 12 is scheduled',
            fn () => $this->service()->confirmPhase(
                12,
                SurgeryChecklist::PHASE_SIGN_IN,
                SurgeryChecklist::items(SurgeryChecklist::PHASE_SIGN_IN),
                self::ACTION,
            ),
        );
    }

    public function testIncompleteListIsRefusedWithoutSaving(): void
    {
        $codes = SurgeryChecklist::items(SurgeryChecklist::PHASE_SIGN_IN);
        array_pop($codes);

        self::throwsWithMessage(
            InvalidArgumentException::class,
            'All checklist items of phase "sign_in" must be checked',
            fn () => $this->service()->confirmPhase(10, SurgeryChecklist::PHASE_SIGN_IN, $codes, self::ACTION),
        );
        Assert::same(0, $this->checklist->saveCount);
        Assert::same(0, $this->events->saveCount);
    }

    public function testUnknownPhaseIsRefused(): void
    {
        self::throwsWithMessage(
            InvalidArgumentException::class,
            'Unknown checklist phase "debrief"',
            fn () => $this->service()->confirmPhase(10, 'debrief', [], self::ACTION),
        );
    }

    public function testUnknownSurgeryIsACrossTenantReference(): void
    {
        self::throwsWithMessage(
            CrossTenantReferenceException::class,
            'surgery_id 999 was not found for the authenticated tenant',
            fn () => $this->service()->confirmPhase(999, SurgeryChecklist::PHASE_SIGN_IN, [], self::ACTION),
        );
    }

    public function testDeniedAuthorizationSavesNothing(): void
    {
        $this->authorization->setAllowed(false);

        try {
            $this->service()->confirmPhase(
                10,
                SurgeryChecklist::PHASE_SIGN_IN,
                SurgeryChecklist::items(SurgeryChecklist::PHASE_SIGN_IN),
                self::ACTION,
            );
        } catch (AuthorizationDenied) {
            Assert::same(0, $this->checklist->saveCount);
            Assert::same(0, $this->events->saveCount);

            return;
        }

        throw new AssertionFailedException('Expected AuthorizationDenied');
    }
}
