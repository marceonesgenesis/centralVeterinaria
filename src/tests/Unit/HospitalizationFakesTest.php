<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Domain\Bed;
use CentralVet\Domain\Contract\BedRepositoryInterface;
use CentralVet\Domain\Contract\HospitalizationAdministrationRepositoryInterface;
use CentralVet\Domain\Contract\HospitalizationEventRepositoryInterface;
use CentralVet\Domain\Contract\HospitalizationOrderRepositoryInterface;
use CentralVet\Domain\Contract\HospitalizationRepositoryInterface;
use CentralVet\Domain\Hospitalization;
use CentralVet\Domain\HospitalizationAdministration;
use CentralVet\Domain\HospitalizationEvent;
use CentralVet\Domain\HospitalizationOrder;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\FakeBedRepository;
use CentralVet\Tests\Support\FakeHospitalizationAdministrationRepository;
use CentralVet\Tests\Support\FakeHospitalizationEventRepository;
use CentralVet\Tests\Support\FakeHospitalizationOrderRepository;
use CentralVet\Tests\Support\FakeHospitalizationRepository;
use DateTimeImmutable;

/**
 * T-06: behaviour of the in-memory doubles of the five hospitalization
 * repositories that the Onda 2 services are tested against. Covers the
 * atomic occupy/release semantics of FakeBedRepository (mirroring the
 * conditional UPDATE of the PDO repository), tenant isolation on findById
 * and that every Fake implements its contract.
 */
final class HospitalizationFakesTest
{
    private const TENANT_ID = 1;
    private const OTHER_TENANT_ID = 2;
    private const UNIT_ID = 1;

    public function testEveryFakeImplementsItsContract(): void
    {
        Assert::instanceOf(BedRepositoryInterface::class, new FakeBedRepository(self::TENANT_ID));
        Assert::instanceOf(HospitalizationRepositoryInterface::class, new FakeHospitalizationRepository(self::TENANT_ID));
        Assert::instanceOf(HospitalizationOrderRepositoryInterface::class, new FakeHospitalizationOrderRepository(self::TENANT_ID));
        Assert::instanceOf(HospitalizationAdministrationRepositoryInterface::class, new FakeHospitalizationAdministrationRepository(self::TENANT_ID));
        Assert::instanceOf(HospitalizationEventRepositoryInterface::class, new FakeHospitalizationEventRepository(self::TENANT_ID));
    }

    public function testSecondOccupyOfSameBedReturnsFalse(): void
    {
        $repository = new FakeBedRepository(self::TENANT_ID, Bed::create(self::TENANT_ID, self::UNIT_ID, 'B1', 'Baia 1', 5000));

        Assert::true($repository->occupy(1, 10), 'first occupy must succeed');
        $bed = $repository->findById(1);
        Assert::same(Bed::STATUS_OCCUPIED, $bed->status());
        Assert::same(10, $bed->currentHospitalizationId());

        Assert::false($repository->occupy(1, 11), 'second occupy of the same bed must fail');
        Assert::same(10, $repository->findById(1)->currentHospitalizationId());
    }

    public function testOccupyRefusesInactiveAndUnknownBeds(): void
    {
        $bed = Bed::create(self::TENANT_ID, self::UNIT_ID, 'B1', 'Baia 1', 5000);
        $bed->deactivate();
        $repository = new FakeBedRepository(self::TENANT_ID, $bed);

        Assert::false($repository->occupy(1, 10));
        Assert::false($repository->occupy(99, 10));
    }

    public function testReleaseWithWrongHospitalizationIdReturnsFalse(): void
    {
        $repository = new FakeBedRepository(self::TENANT_ID, Bed::reconstitute([
            'id' => 5,
            'tenant_id' => self::TENANT_ID,
            'system_unit_id' => self::UNIT_ID,
            'code' => 'B5',
            'name' => 'Baia 5',
            'daily_rate_cents' => 5000,
            'status' => Bed::STATUS_OCCUPIED,
            'current_hospitalization_id' => 7,
        ]));

        Assert::false($repository->release(5, 8), 'release by another hospitalization must fail');
        Assert::same(Bed::STATUS_OCCUPIED, $repository->findById(5)->status());

        Assert::true($repository->release(5, 7));
        $bed = $repository->findById(5);
        Assert::same(Bed::STATUS_AVAILABLE, $bed->status());
        Assert::null($bed->currentHospitalizationId());
        Assert::false($repository->release(5, 7), 'releasing an available bed must fail');
    }

    public function testFindByIdOfAnotherTenantReturnsNull(): void
    {
        $at = new DateTimeImmutable('2026-10-05 08:00:00');

        $beds = new FakeBedRepository(self::TENANT_ID, Bed::create(self::OTHER_TENANT_ID, self::UNIT_ID, 'B1', 'Baia 1', 5000));
        Assert::null($beds->findById(1));
        Assert::false($beds->occupy(1, 10), 'occupy must not touch another tenant bed');

        $hospitalizations = new FakeHospitalizationRepository(
            self::TENANT_ID,
            Hospitalization::admit(self::OTHER_TENANT_ID, self::UNIT_ID, 3, 4, 1, 9, 9, 'Observação', null, 5000, $at),
        );
        Assert::null($hospitalizations->findById(1));
        Assert::null($hospitalizations->findActiveByPatient(3));

        $orders = new FakeHospitalizationOrderRepository(
            self::TENANT_ID,
            HospitalizationOrder::prescribe(self::OTHER_TENANT_ID, 1, HospitalizationOrder::TYPE_PROCEDURE, 'Curativo', null, null, '', 'topical', 12, $at, $at->modify('+1 day'), 9),
        );
        Assert::null($orders->findById(1));
        Assert::count(0, $orders->listByHospitalization(1));

        $administrations = new FakeHospitalizationAdministrationRepository(
            self::TENANT_ID,
            HospitalizationAdministration::schedule(self::OTHER_TENANT_ID, 1, 1, $at),
        );
        Assert::null($administrations->findById(1));
        Assert::count(0, $administrations->listPendingByHospitalization(1));

        $events = new FakeHospitalizationEventRepository(
            self::TENANT_ID,
            HospitalizationEvent::record(self::OTHER_TENANT_ID, 1, HospitalizationEvent::TYPE_EVOLUTION, 9, $at, 'Estável'),
        );
        Assert::null($events->findById(1));
        Assert::count(0, $events->listByHospitalization(1));
    }

    public function testQueriesFilterByStatusAndOrder(): void
    {
        $at = new DateTimeImmutable('2026-10-05 08:00:00');

        $hospitalizations = new FakeHospitalizationRepository(
            self::TENANT_ID,
            Hospitalization::admit(self::TENANT_ID, self::UNIT_ID, 3, 4, 1, 9, 9, 'Observação', null, 5000, $at),
            Hospitalization::admit(self::TENANT_ID, 2, 5, 6, 2, 9, 9, 'Pós-operatório', null, 5000, $at),
        );
        Assert::same(1, $hospitalizations->findActiveByPatient(3)?->id());
        Assert::count(1, $hospitalizations->listActiveByUnit(self::UNIT_ID));
        $hospitalizations->findById(1)->discharge($at->modify('+1 day'), 9, '');
        Assert::null($hospitalizations->findActiveByPatient(3));
        Assert::count(0, $hospitalizations->listActiveByUnit(self::UNIT_ID));

        $late = HospitalizationAdministration::schedule(self::TENANT_ID, 1, 1, $at->modify('+2 hours'));
        $early = HospitalizationAdministration::schedule(self::TENANT_ID, 1, 1, $at);
        $done = HospitalizationAdministration::schedule(self::TENANT_ID, 1, 2, $at->modify('+1 hour'));
        $done->markDone($at->modify('+1 hour'), 9, '');
        $administrations = new FakeHospitalizationAdministrationRepository(self::TENANT_ID, $late, $early, $done);
        Assert::same([2, 3, 1], array_map(static fn ($a) => $a->id(), $administrations->listByHospitalization(1)));
        Assert::count(2, $administrations->listPendingByOrder(1));
        Assert::count(0, $administrations->listPendingByOrder(2));
        Assert::count(2, $administrations->listPendingByHospitalization(1));

        $first = HospitalizationEvent::record(self::TENANT_ID, 1, HospitalizationEvent::TYPE_EVOLUTION, 9, $at, 'A');
        $second = HospitalizationEvent::record(self::TENANT_ID, 1, HospitalizationEvent::TYPE_EVOLUTION, 9, $at->modify('+1 hour'), 'B');
        $events = new FakeHospitalizationEventRepository(self::TENANT_ID, $first, $second);
        Assert::same([2, 1], array_map(static fn ($e) => $e->id(), $events->listByHospitalization(1)));
    }

    public function testAdministrationFakeRefusesStaleSaveOfNoLongerPendingRow(): void
    {
        $scheduledAt = new DateTimeImmutable('2031-08-01 08:00:00');
        $repository = new FakeHospitalizationAdministrationRepository(1);
        $stored = HospitalizationAdministration::schedule(1, 10, 20, $scheduledAt);
        $repository->save($stored);
        $id = (int) $stored->id();

        $stored->markDone(new DateTimeImmutable('2031-08-01 08:05:00'), 7, '');
        $repository->save($stored);

        $stale = HospitalizationAdministration::reconstitute($id, 1, 10, 20, $scheduledAt, 'pending', null, null, null);
        $stale->markSkipped(new DateTimeImmutable('2031-08-01 08:06:00'), 7, 'Recusou');

        $message = '';
        try {
            $repository->save($stale);
        } catch (\CentralVet\Domain\Exception\InvalidStatusTransitionException $e) {
            $message = $e->getMessage();
        }

        Assert::same("Administration {$id} is not pending", $message);
        Assert::same(HospitalizationAdministration::STATUS_DONE, $repository->findById($id)?->status());
    }

    public function testBoardRowsAreReturnedUnfiltered(): void
    {
        $repository = new FakeHospitalizationAdministrationRepository(self::TENANT_ID);
        $row = [
            'administration_id' => 1,
            'hospitalization_id' => 1,
            'patient_name' => 'Rex',
            'bed_code' => 'B1',
            'order_type' => 'medication',
            'description_text' => 'Dipirona',
            'dose_text' => '1 ml',
            'route' => 'iv',
            'scheduled_at' => '2026-10-05 08:00:00',
            'status' => 'pending',
            'performed_at' => null,
        ];
        $repository->seedBoardRows([$row]);

        Assert::same([$row], $repository->listBoardRows(99, new DateTimeImmutable('2030-01-01'), new DateTimeImmutable('2030-01-02')));
    }
}
