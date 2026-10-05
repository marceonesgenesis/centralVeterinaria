<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Application\BedService;
use CentralVet\Authorization\Exception\AuthorizationDenied;
use CentralVet\Domain\Bed;
use CentralVet\Domain\Exception\BedUnavailableException;
use CentralVet\Domain\Exception\CrossTenantReferenceException;
use CentralVet\Tenancy\TenantContext;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\FakeAuthorizationPolicy;
use CentralVet\Tests\Support\FakeBedRepository;
use InvalidArgumentException;

/**
 * Unit tests for BedService (T-08), against FakeBedRepository and
 * FakeAuthorizationPolicy. Covers the unit-scoped bed catalog: duplicate
 * code inside the unit, authorization against the persisted bed's own
 * system_unit_id, occupied bed deactivation and tenant isolation.
 */
final class BedServiceTest
{
    private const ACTION = 'test::bed';
    private const TENANT_ID = 1;
    private const UNIT_ID = 5;
    private const OTHER_UNIT_ID = 9;

    /** @return array{0: BedService, 1: FakeBedRepository, 2: FakeAuthorizationPolicy} */
    private function build(bool $allowed = true, Bed ...$seed): array
    {
        $beds = new FakeBedRepository(self::TENANT_ID, ...$seed);
        $policy = new FakeAuthorizationPolicy(allowed: $allowed);
        $context = TenantContext::authenticated(self::TENANT_ID, 1, self::UNIT_ID);

        return [new BedService($beds, $policy, $context), $beds, $policy];
    }

    /** @param array<string, mixed> $overrides */
    private static function bed(array $overrides = []): Bed
    {
        return Bed::reconstitute($overrides + [
            'id' => 1,
            'tenant_id' => self::TENANT_ID,
            'system_unit_id' => self::UNIT_ID,
            'code' => 'L1',
            'name' => 'Leito 1',
            'daily_rate_cents' => 15000,
            'status' => Bed::STATUS_AVAILABLE,
            'current_hospitalization_id' => null,
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

    public function testCreateUsesContextUnitAndAuthorizesIt(): void
    {
        [$service, $beds, $policy] = $this->build();

        $bed = $service->create('L2', 'Leito 2', 12000, self::ACTION);

        Assert::notNull($bed->id());
        Assert::same(self::UNIT_ID, $bed->systemUnitId());
        Assert::same(Bed::STATUS_AVAILABLE, $bed->status());
        Assert::same(1, $beds->saveCount);
        Assert::count(1, $policy->requests);
        Assert::same(self::UNIT_ID, $policy->requests[0]->resourceUnitId());
        Assert::same('bed', $policy->requests[0]->entityType());
        Assert::true($policy->requests[0]->requiresUnitScope());
    }

    public function testCreateWithCodeAlreadyUsedInUnitThrows(): void
    {
        [$service, $beds] = $this->build(true, self::bed());

        self::expectMessage(
            InvalidArgumentException::class,
            'A bed with code "L1" already exists in this unit',
            fn () => $service->create('L1', 'Outro', 1000, self::ACTION),
        );
        Assert::same(0, $beds->saveCount);
    }

    public function testCreateAllowsSameCodeInAnotherUnit(): void
    {
        [$service, $beds] = $this->build(true, self::bed(['system_unit_id' => self::OTHER_UNIT_ID]));

        $bed = $service->create('L1', 'Leito 1', 1000, self::ACTION);

        Assert::same(self::UNIT_ID, $bed->systemUnitId());
        Assert::same(1, $beds->saveCount);
    }

    public function testUpdateOfBedFromOtherUnitDeniedDoesNotSave(): void
    {
        [$service, $beds, $policy] = $this->build(false, self::bed(['id' => 3, 'system_unit_id' => self::OTHER_UNIT_ID]));

        Assert::throws(
            AuthorizationDenied::class,
            fn () => $service->update(3, 'Novo nome', 99, self::ACTION),
        );

        Assert::same(0, $beds->saveCount);
        Assert::count(1, $policy->requests);
        Assert::same(self::OTHER_UNIT_ID, $policy->requests[0]->resourceUnitId());
        Assert::same('bed', $policy->requests[0]->entityType());
        Assert::same(3, $policy->requests[0]->entityId());
        Assert::same('Leito 1', $beds->findById(3)->name());
    }

    public function testUpdateChangesNameAndRate(): void
    {
        [$service, $beds, $policy] = $this->build(true, self::bed());

        $bed = $service->update(1, 'Leito UTI', 30000, self::ACTION);

        Assert::same('Leito UTI', $bed->name());
        Assert::same(30000, $bed->dailyRateCents());
        Assert::same(1, $beds->saveCount);
        Assert::same(self::UNIT_ID, $policy->requests[0]->resourceUnitId());
    }

    public function testDeactivateOccupiedBedThrows(): void
    {
        [$service, $beds] = $this->build(true, self::bed([
            'status' => Bed::STATUS_OCCUPIED,
            'current_hospitalization_id' => 40,
        ]));

        Assert::throws(BedUnavailableException::class, fn () => $service->deactivate(1, self::ACTION));
        Assert::same(0, $beds->saveCount);
        Assert::same(Bed::STATUS_OCCUPIED, $beds->findById(1)->status());
    }

    public function testDeactivateAndActivate(): void
    {
        [$service, $beds] = $this->build(true, self::bed());

        Assert::same(Bed::STATUS_INACTIVE, $service->deactivate(1, self::ACTION)->status());
        Assert::same(Bed::STATUS_AVAILABLE, $service->activate(1, self::ACTION)->status());
        Assert::same(2, $beds->saveCount);
    }

    public function testUnknownOrForeignTenantBedIsNotFound(): void
    {
        [$service, $beds, $policy] = $this->build(true, self::bed(['id' => 2, 'tenant_id' => 77]));

        self::expectMessage(
            CrossTenantReferenceException::class,
            'Bed 2 not found for this tenant',
            fn () => $service->get(2, self::ACTION),
        );
        self::expectMessage(
            CrossTenantReferenceException::class,
            'Bed 99 not found for this tenant',
            fn () => $service->deactivate(99, self::ACTION),
        );
        Assert::count(0, $policy->requests);
        Assert::same(0, $beds->saveCount);
    }

    public function testGetDeniedThrows(): void
    {
        [$service] = $this->build(false, self::bed());

        Assert::throws(AuthorizationDenied::class, fn () => $service->get(1, self::ACTION));
    }

    public function testListForCurrentUnitAndAvailableForUnit(): void
    {
        [$service, , $policy] = $this->build(
            true,
            self::bed(['id' => 1, 'code' => 'L2']),
            self::bed(['id' => 2, 'code' => 'L1', 'status' => Bed::STATUS_OCCUPIED, 'current_hospitalization_id' => 8]),
            self::bed(['id' => 3, 'code' => 'L3', 'status' => Bed::STATUS_INACTIVE]),
            self::bed(['id' => 4, 'code' => 'X1', 'system_unit_id' => self::OTHER_UNIT_ID]),
        );

        $all = $service->listForCurrentUnit(self::ACTION);
        Assert::same(['L1', 'L2', 'L3'], array_map(static fn (Bed $b): string => $b->code(), $all));

        $available = $service->listAvailableForUnit(self::OTHER_UNIT_ID, self::ACTION);
        Assert::same(['X1'], array_map(static fn (Bed $b): string => $b->code(), $available));
        Assert::same(self::OTHER_UNIT_ID, $policy->requests[1]->resourceUnitId());

        $availableHere = $service->listAvailableForUnit(self::UNIT_ID, self::ACTION);
        Assert::same(['L2'], array_map(static fn (Bed $b): string => $b->code(), $availableHere));
    }

    public function testListDeniedThrows(): void
    {
        [$service] = $this->build(false, self::bed());

        Assert::throws(AuthorizationDenied::class, fn () => $service->listForCurrentUnit(self::ACTION));
        Assert::throws(AuthorizationDenied::class, fn () => $service->listAvailableForUnit(self::UNIT_ID, self::ACTION));
    }
}
