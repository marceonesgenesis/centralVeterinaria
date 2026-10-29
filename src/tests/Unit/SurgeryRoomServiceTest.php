<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Application\SurgeryRoomService;
use CentralVet\Authorization\Exception\AuthorizationDenied;
use CentralVet\Domain\Exception\CrossTenantReferenceException;
use CentralVet\Domain\SurgeryRoom;
use CentralVet\Tenancy\TenantContext;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\FakeAuthorizationPolicy;
use CentralVet\Tests\Support\FakeSurgeryRoomRepository;
use InvalidArgumentException;

/**
 * Unit tests for SurgeryRoomService (T-07), against
 * FakeSurgeryRoomRepository and FakeAuthorizationPolicy. Mirrors
 * BedServiceTest: unit from the context, duplicate code inside the unit,
 * authorization against the persisted room's own system_unit_id, active
 * filter and tenant isolation.
 */
final class SurgeryRoomServiceTest
{
    private const ACTION = 'test::surgery_room';
    private const TENANT_ID = 1;
    private const UNIT_ID = 5;
    private const OTHER_UNIT_ID = 9;

    /** @return array{0: SurgeryRoomService, 1: FakeSurgeryRoomRepository, 2: FakeAuthorizationPolicy} */
    private function build(bool $allowed = true, SurgeryRoom ...$seed): array
    {
        $rooms = new FakeSurgeryRoomRepository(self::TENANT_ID, ...$seed);
        $policy = new FakeAuthorizationPolicy(allowed: $allowed);
        $context = TenantContext::authenticated(self::TENANT_ID, 1, self::UNIT_ID);

        return [new SurgeryRoomService($rooms, $policy, $context), $rooms, $policy];
    }

    /** @param array<string, mixed> $overrides */
    private static function room(array $overrides = []): SurgeryRoom
    {
        return SurgeryRoom::reconstitute($overrides + [
            'id' => 1,
            'tenant_id' => self::TENANT_ID,
            'system_unit_id' => self::UNIT_ID,
            'code' => 'F6B-1',
            'name' => 'Sala 1',
            'status' => SurgeryRoom::STATUS_ACTIVE,
        ]);
    }

    /** @param class-string<\Throwable> $class */
    private static function expectMessage(string $class, string $message, callable $callback): void
    {
        try {
            $callback();
        } catch (\Throwable $e) {
            Assert::instanceOf($class, $e, "Expected {$class}, got " . $e::class . ': ' . $e->getMessage());
            Assert::same($message, $e->getMessage());

            return;
        }

        Assert::true(false, "Expected exception {$class} was not thrown");
    }

    /**
     * @param list<SurgeryRoom> $rooms
     * @return list<string>
     */
    private static function codes(array $rooms): array
    {
        return array_map(static fn (SurgeryRoom $r): string => $r->code(), $rooms);
    }

    public function testCreateStoresActiveRoomInContextUnit(): void
    {
        [$service, $rooms, $policy] = $this->build();

        $room = $service->create('F6B-1', 'Sala 1', self::ACTION);

        Assert::notNull($room->id());
        Assert::same(self::UNIT_ID, $room->systemUnitId());
        Assert::same(self::TENANT_ID, $room->tenantId());
        Assert::same(SurgeryRoom::STATUS_ACTIVE, $room->status());
        Assert::same(1, $rooms->saveCount);
        Assert::count(1, $policy->requests);
        Assert::same(self::UNIT_ID, $policy->requests[0]->resourceUnitId());
        Assert::same('surgery_room', $policy->requests[0]->entityType());
        Assert::true($policy->requests[0]->requiresUnitScope());
    }

    public function testCreateWithRepeatedCodeInUnitThrows(): void
    {
        [$service, $rooms] = $this->build();
        $service->create('F6B-1', 'Sala 1', self::ACTION);

        self::expectMessage(
            InvalidArgumentException::class,
            'A surgery room with code "F6B-1" already exists in this unit',
            fn () => $service->create('F6B-1', 'Outra', self::ACTION),
        );
        Assert::same(1, $rooms->saveCount);
    }

    public function testCreateAllowsSameCodeInAnotherUnit(): void
    {
        [$service, $rooms] = $this->build(true, self::room(['system_unit_id' => self::OTHER_UNIT_ID]));

        $room = $service->create('F6B-1', 'Sala 1', self::ACTION);

        Assert::same(self::UNIT_ID, $room->systemUnitId());
        Assert::same(1, $rooms->saveCount);
    }

    public function testCreateDeniedDoesNotSave(): void
    {
        [$service, $rooms] = $this->build(false);

        Assert::throws(AuthorizationDenied::class, fn () => $service->create('F6B-1', 'Sala 1', self::ACTION));
        Assert::same(0, $rooms->saveCount);
    }

    public function testUpdateOfRoomFromOtherUnitDeniedDoesNotSave(): void
    {
        [$service, $rooms, $policy] = $this->build(false, self::room(['id' => 3, 'system_unit_id' => self::OTHER_UNIT_ID]));

        Assert::throws(AuthorizationDenied::class, fn () => $service->update(3, 'Novo nome', self::ACTION));

        Assert::same(0, $rooms->saveCount);
        Assert::same(self::OTHER_UNIT_ID, $policy->requests[0]->resourceUnitId());
        Assert::same('surgery_room', $policy->requests[0]->entityType());
        Assert::same(3, $policy->requests[0]->entityId());
        Assert::same('Sala 1', $rooms->findById(3)->name());
    }

    public function testUpdateRenames(): void
    {
        [$service, $rooms] = $this->build(true, self::room());

        Assert::same('Sala Cirúrgica A', $service->update(1, 'Sala Cirúrgica A', self::ACTION)->name());
        Assert::same(1, $rooms->saveCount);
    }

    public function testDeactivateAndActivate(): void
    {
        [$service, $rooms] = $this->build(true, self::room());

        Assert::same(SurgeryRoom::STATUS_INACTIVE, $service->deactivate(1, self::ACTION)->status());
        Assert::same(SurgeryRoom::STATUS_ACTIVE, $service->activate(1, self::ACTION)->status());
        Assert::same(2, $rooms->saveCount);
    }

    public function testUnknownOrForeignTenantRoomIsNotFound(): void
    {
        [$service, $rooms, $policy] = $this->build(true, self::room(['id' => 2, 'tenant_id' => 77]));

        self::expectMessage(
            CrossTenantReferenceException::class,
            'room_id 2 was not found for the authenticated tenant',
            fn () => $service->get(2, self::ACTION),
        );
        self::expectMessage(
            CrossTenantReferenceException::class,
            'room_id 99 was not found for the authenticated tenant',
            fn () => $service->deactivate(99, self::ACTION),
        );
        Assert::count(0, $policy->requests);
        Assert::same(0, $rooms->saveCount);
    }

    public function testListForCurrentUnitAndActiveForUnit(): void
    {
        [$service, , $policy] = $this->build(
            true,
            self::room(['id' => 1, 'code' => 'F6B-2']),
            self::room(['id' => 2, 'code' => 'F6B-1']),
            self::room(['id' => 3, 'code' => 'F6B-3', 'status' => SurgeryRoom::STATUS_INACTIVE]),
            self::room(['id' => 4, 'code' => 'X1', 'system_unit_id' => self::OTHER_UNIT_ID]),
        );

        Assert::same(['F6B-1', 'F6B-2', 'F6B-3'], self::codes($service->listForCurrentUnit(self::ACTION)));
        Assert::same(['F6B-1', 'F6B-2'], self::codes($service->listActiveForUnit(self::UNIT_ID, self::ACTION)));
        Assert::same(['X1'], self::codes($service->listActiveForUnit(self::OTHER_UNIT_ID, self::ACTION)));
        Assert::same(self::OTHER_UNIT_ID, $policy->requests[2]->resourceUnitId());
    }

    public function testListActiveForUnitOmitsDeactivatedRoom(): void
    {
        [$service] = $this->build();
        $service->create('F6B-1', 'Sala 1', self::ACTION);
        $second = $service->create('F6B-2', 'Sala 2', self::ACTION);
        $service->deactivate((int) $second->id(), self::ACTION);

        Assert::same(['F6B-1'], self::codes($service->listActiveForUnit(self::UNIT_ID, self::ACTION)));
    }

    public function testListDeniedThrows(): void
    {
        [$service] = $this->build(false, self::room());

        Assert::throws(AuthorizationDenied::class, fn () => $service->listForCurrentUnit(self::ACTION));
        Assert::throws(AuthorizationDenied::class, fn () => $service->listActiveForUnit(self::UNIT_ID, self::ACTION));
        Assert::throws(AuthorizationDenied::class, fn () => $service->get(1, self::ACTION));
    }
}
