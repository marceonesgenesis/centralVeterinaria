<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Application\HospitalizationOrderService;
use CentralVet\Authorization\Exception\AuthorizationDenied;
use CentralVet\Domain\Exception\CrossTenantReferenceException;
use CentralVet\Domain\Exception\InvalidStatusTransitionException;
use CentralVet\Domain\Hospitalization;
use CentralVet\Domain\HospitalizationAdministration;
use CentralVet\Domain\HospitalizationOrder;
use CentralVet\Domain\Product;
use CentralVet\Tenancy\TenantContext;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\AssertionFailedException;
use CentralVet\Tests\Support\FakeAuthorizationPolicy;
use CentralVet\Tests\Support\FakeHospitalizationAdministrationRepository;
use CentralVet\Tests\Support\FakeHospitalizationOrderRepository;
use CentralVet\Tests\Support\FakeHospitalizationRepository;
use CentralVet\Tests\Support\FakeProductRepository;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * T-10: HospitalizationOrderService — prescription with the full
 * administration schedule, suspension cancelling the future pending
 * administrations, recording an administration (done/skipped) and the
 * flowboard rows with their derived timeliness. The clock is injected so
 * "now" is deterministic.
 */
final class HospitalizationOrderServiceTest
{
    private const TENANT_ID = 1;
    private const OTHER_TENANT_ID = 2;
    private const UNIT_ID = 3;
    private const USER_ID = 7;
    private const ACTION = 'HospitalizationOrderService::prescribe';

    private FakeHospitalizationOrderRepository $orders;
    private FakeHospitalizationAdministrationRepository $administrations;
    private FakeHospitalizationRepository $hospitalizations;
    private FakeProductRepository $products;
    private FakeAuthorizationPolicy $authorization;
    private DateTimeImmutable $now;

    public function setUp(): void
    {
        $this->orders = new FakeHospitalizationOrderRepository(self::TENANT_ID);
        $this->administrations = new FakeHospitalizationAdministrationRepository(self::TENANT_ID);
        $this->hospitalizations = new FakeHospitalizationRepository(self::TENANT_ID);
        $this->products = new FakeProductRepository(
            self::TENANT_ID,
            Product::create(self::TENANT_ID, 'Dipirona 500mg', 'Medicamento', 'comprimido', 100, 0),
            Product::create(self::OTHER_TENANT_ID, 'Outro tenant', 'Medicamento', 'comprimido', 100, 0),
        );
        $this->authorization = new FakeAuthorizationPolicy();
        $this->now = new DateTimeImmutable('2026-10-01 07:00:00');
    }

    private function service(): HospitalizationOrderService
    {
        return new HospitalizationOrderService(
            $this->orders,
            $this->administrations,
            $this->hospitalizations,
            $this->products,
            $this->authorization,
            TenantContext::authenticated(self::TENANT_ID, self::USER_ID, self::UNIT_ID),
            fn (): DateTimeImmutable => $this->now,
        );
    }

    private function admitted(): Hospitalization
    {
        $hospitalization = Hospitalization::admit(
            self::TENANT_ID,
            self::UNIT_ID,
            11,
            12,
            13,
            self::USER_ID,
            self::USER_ID,
            'Observação pós-cirúrgica',
            null,
            5000,
            new DateTimeImmutable('2026-10-01 06:00:00'),
        );
        $this->hospitalizations->save($hospitalization);

        return $hospitalization;
    }

    private function prescribeEvery8h(int $hospitalizationId, ?int $productId = null, ?int $quantity = null): HospitalizationOrder
    {
        return $this->service()->prescribe(
            $hospitalizationId,
            HospitalizationOrder::TYPE_MEDICATION,
            'Dipirona',
            $productId,
            $quantity,
            '25 mg/kg',
            'oral',
            8,
            new DateTimeImmutable('2026-10-01 08:00:00'),
            new DateTimeImmutable('2026-10-04 08:00:00'),
            self::ACTION,
        );
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

    public function testPrescribeEvery8hFor3DaysSchedulesNinePendingAdministrations(): void
    {
        $hospitalization = $this->admitted();

        $order = $this->prescribeEvery8h($hospitalization->id(), 1, 2);

        Assert::notNull($order->id());
        Assert::same(HospitalizationOrder::STATUS_ACTIVE, $order->status());
        Assert::same(self::USER_ID, $order->prescribedBySystemUserId());

        $list = $this->administrations->listByHospitalization($hospitalization->id());
        Assert::count(9, $list);
        foreach ($list as $administration) {
            Assert::same(HospitalizationAdministration::STATUS_PENDING, $administration->status());
            Assert::same($order->id(), $administration->orderId());
        }
        Assert::same('2026-10-01 08:00', $list[0]->scheduledAt()->format('Y-m-d H:i'));
        Assert::same('2026-10-04 00:00', $list[8]->scheduledAt()->format('Y-m-d H:i'));

        $request = $this->authorization->requests[0];
        Assert::same('hospitalization', $request->entityType());
        Assert::same(self::UNIT_ID, $request->resourceUnitId());
    }

    public function testSuspendAt20hCancelsTheSevenFuturePendingAdministrations(): void
    {
        $hospitalization = $this->admitted();
        $order = $this->prescribeEvery8h($hospitalization->id());

        $this->now = new DateTimeImmutable('2026-10-01 20:00:00');
        $cancelled = $this->service()->suspend($order->id(), 'HospitalizationOrderService::suspend');

        Assert::same(7, $cancelled);
        Assert::same(HospitalizationOrder::STATUS_SUSPENDED, $this->orders->findById($order->id())->status());

        $statuses = array_map(
            static fn (HospitalizationAdministration $a): string => $a->status(),
            $this->administrations->listByHospitalization($hospitalization->id()),
        );
        Assert::same(2, count(array_keys($statuses, HospitalizationAdministration::STATUS_PENDING, true)));
        Assert::same(7, count(array_keys($statuses, HospitalizationAdministration::STATUS_CANCELLED, true)));
    }

    public function testRecordAdministrationTwiceThrowsNotPending(): void
    {
        $hospitalization = $this->admitted();
        $this->prescribeEvery8h($hospitalization->id());
        $first = $this->administrations->listByHospitalization($hospitalization->id())[0];

        $this->now = new DateTimeImmutable('2026-10-01 08:10:00');
        $recorded = $this->service()->recordAdministration($first->id(), 'done', '', 'HospitalizationOrderService::recordAdministration');

        Assert::same(HospitalizationAdministration::STATUS_DONE, $recorded->status());
        Assert::same(self::USER_ID, $recorded->performedBySystemUserId());
        Assert::same('2026-10-01 08:10', $recorded->performedAt()->format('Y-m-d H:i'));

        self::throwsWithMessage(
            InvalidStatusTransitionException::class,
            "Administration {$first->id()} is not pending",
            fn () => $this->service()->recordAdministration($first->id(), 'done', '', 'HospitalizationOrderService::recordAdministration'),
        );
    }

    public function testRecordAdministrationSkippedRequiresNotesAndRejectsUnknownOutcome(): void
    {
        $hospitalization = $this->admitted();
        $this->prescribeEvery8h($hospitalization->id());
        $first = $this->administrations->listByHospitalization($hospitalization->id())[0];
        $action = 'HospitalizationOrderService::recordAdministration';

        self::throwsWithMessage(InvalidArgumentException::class, 'notes_text is required', fn () => $this->service()->recordAdministration($first->id(), 'skipped', '  ', $action));
        Assert::throws(InvalidArgumentException::class, fn () => $this->service()->recordAdministration($first->id(), 'cancelled', 'x', $action));

        $skipped = $this->service()->recordAdministration($first->id(), 'skipped', 'Paciente em jejum', $action);
        Assert::same(HospitalizationAdministration::STATUS_SKIPPED, $skipped->status());
        Assert::same('Paciente em jejum', $skipped->notesText());
    }

    public function testPrescribeOnDischargedHospitalizationThrowsNotAdmitted(): void
    {
        $hospitalization = $this->admitted();
        $hospitalization->discharge(new DateTimeImmutable('2026-10-01 06:30:00'), self::USER_ID, 'Alta');
        $this->hospitalizations->save($hospitalization);

        self::throwsWithMessage(
            InvalidStatusTransitionException::class,
            "Hospitalization {$hospitalization->id()} is not admitted",
            fn () => $this->prescribeEvery8h($hospitalization->id()),
        );
        Assert::same(0, $this->orders->saveCount);
        Assert::same(0, $this->administrations->saveCount);
    }

    public function testRecordAdministrationOnDischargedHospitalizationThrowsNotAdmitted(): void
    {
        $hospitalization = $this->admitted();
        $this->prescribeEvery8h($hospitalization->id());
        $first = $this->administrations->listByHospitalization($hospitalization->id())[0];
        $hospitalization->discharge(new DateTimeImmutable('2026-10-01 07:30:00'), self::USER_ID, 'Alta');

        self::throwsWithMessage(
            InvalidStatusTransitionException::class,
            "Hospitalization {$hospitalization->id()} is not admitted",
            fn () => $this->service()->recordAdministration($first->id(), 'done', '', 'HospitalizationOrderService::recordAdministration'),
        );
        Assert::same(HospitalizationAdministration::STATUS_PENDING, $first->status());
    }

    public function testPrescribeRejectsProductOfAnotherTenantOrInactive(): void
    {
        $hospitalization = $this->admitted();

        Assert::throws(CrossTenantReferenceException::class, fn () => $this->prescribeEvery8h($hospitalization->id(), 2, 1));

        $this->products->findById(1)->deactivate();
        Assert::throws(CrossTenantReferenceException::class, fn () => $this->prescribeEvery8h($hospitalization->id(), 1, 1));
        Assert::same(0, $this->administrations->saveCount);
    }

    public function testUnknownHospitalizationIsCrossTenantReference(): void
    {
        Assert::throws(CrossTenantReferenceException::class, fn () => $this->prescribeEvery8h(999));
    }

    public function testDeniedAuthorizationPersistsNothing(): void
    {
        $hospitalization = $this->admitted();
        $this->authorization->setAllowed(false);

        Assert::throws(AuthorizationDenied::class, fn () => $this->prescribeEvery8h($hospitalization->id()));
        Assert::same(0, $this->orders->saveCount);
        Assert::same(0, $this->administrations->saveCount);
    }

    public function testListsReturnOrdersAndAdministrationsOfTheHospitalization(): void
    {
        $hospitalization = $this->admitted();
        $order = $this->prescribeEvery8h($hospitalization->id());
        $first = $this->administrations->listByHospitalization($hospitalization->id())[0];

        Assert::count(1, $this->service()->listOrders($hospitalization->id(), 'HospitalizationOrderService::listOrders'));
        Assert::count(9, $this->service()->listAdministrations($hospitalization->id(), 'HospitalizationOrderService::listAdministrations'));
        Assert::same($first, $this->service()->getAdministration($first->id(), 'HospitalizationOrderService::getAdministration'));
        Assert::same($order->id(), $first->orderId());
    }

    public function testBoardRowsAddTimelinessUsingTheInjectedClock(): void
    {
        $row = static fn (int $id, string $status, string $scheduledAt, ?string $performedAt): array => [
            'administration_id' => $id,
            'hospitalization_id' => 1,
            'patient_name' => 'Rex',
            'bed_code' => 'B1',
            'order_type' => 'medication',
            'description_text' => 'Dipirona',
            'dose_text' => '25 mg/kg',
            'route' => 'oral',
            'scheduled_at' => $scheduledAt,
            'status' => $status,
            'performed_at' => $performedAt,
        ];
        $this->administrations->seedBoardRows([
            $row(1, 'pending', '2026-10-01 10:00:00', null),
            $row(2, 'pending', '2026-10-01 10:20:00', null),
            $row(3, 'pending', '2026-10-01 12:00:00', null),
            $row(4, 'done', '2026-10-01 08:00:00', '2026-10-01 08:45:00'),
            $row(5, 'skipped', '2026-10-01 09:00:00', '2026-10-01 09:05:00'),
        ]);
        $this->now = new DateTimeImmutable('2026-10-01 10:31:00');

        $rows = $this->service()->boardRowsForCurrentUnit(6, 'HospitalizationOrderService::boardRowsForCurrentUnit');

        Assert::count(5, $rows);
        Assert::same(HospitalizationAdministration::TIMELINESS_LATE, $rows[0]['timeliness']);
        Assert::same(HospitalizationAdministration::TIMELINESS_DUE, $rows[1]['timeliness']);
        Assert::same(HospitalizationAdministration::TIMELINESS_UPCOMING, $rows[2]['timeliness']);
        Assert::same(HospitalizationAdministration::TIMELINESS_DONE_LATE, $rows[3]['timeliness']);
        Assert::same(HospitalizationAdministration::TIMELINESS_SKIPPED, $rows[4]['timeliness']);
        Assert::same('Rex', $rows[0]['patient_name']);
        Assert::same(self::UNIT_ID, $this->authorization->requests[0]->resourceUnitId());

        Assert::throws(InvalidArgumentException::class, fn () => $this->service()->boardRowsForCurrentUnit(0, 'HospitalizationOrderService::boardRowsForCurrentUnit'));
    }
}
