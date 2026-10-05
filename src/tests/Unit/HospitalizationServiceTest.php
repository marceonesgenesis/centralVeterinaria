<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Application\HospitalizationService;
use CentralVet\Authorization\Exception\AuthorizationDenied;
use CentralVet\Domain\Bed;
use CentralVet\Domain\Contract\BedRepositoryInterface;
use CentralVet\Domain\Encounter;
use CentralVet\Domain\EncounterAccount;
use CentralVet\Domain\Exception\BedUnavailableException;
use CentralVet\Domain\Exception\CrossTenantReferenceException;
use CentralVet\Domain\Exception\InvalidStatusTransitionException;
use CentralVet\Domain\Exception\PatientAlreadyHospitalizedException;
use CentralVet\Domain\Hospitalization;
use CentralVet\Domain\HospitalizationEvent;
use CentralVet\Tenancy\TenantContext;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\FakeAuthorizationPolicy;
use CentralVet\Tests\Support\FakeBedRepository;
use CentralVet\Tests\Support\FakeEncounterAccountRepository;
use CentralVet\Tests\Support\FakeEncounterRepository;
use CentralVet\Tests\Support\FakeHospitalizationEventRepository;
use CentralVet\Tests\Support\FakeHospitalizationRepository;
use CentralVet\Tests\Support\FakeTenantUserDirectory;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Unit tests for HospitalizationService (T-09) against the in-memory fakes
 * of T-06: admission order of checks, bed occupancy race (Review Focus),
 * transfer, evolution/vitals and unit-scoped reads.
 */
final class HospitalizationServiceTest
{
    private const ACTION = 'test.hospitalization';
    private const TENANT_ID = 1;
    private const UNIT_ID = 5;
    private const OTHER_UNIT_ID = 9;
    private const USER_ID = 3;
    private const RESPONSIBLE_ID = 10;
    private const NOW = '2026-10-05 10:00:00';

    private FakeHospitalizationRepository $hospitalizations;
    private FakeBedRepository $beds;
    private FakeHospitalizationEventRepository $events;
    private FakeEncounterRepository $encounters;
    private FakeEncounterAccountRepository $accounts;
    private FakeAuthorizationPolicy $policy;

    public function setUp(): void
    {
        $this->hospitalizations = new FakeHospitalizationRepository(self::TENANT_ID);
        $this->beds = new FakeBedRepository(self::TENANT_ID);
        $this->events = new FakeHospitalizationEventRepository(self::TENANT_ID);
        $this->encounters = new FakeEncounterRepository(self::TENANT_ID);
        $this->accounts = new FakeEncounterAccountRepository(self::TENANT_ID);
        $this->policy = new FakeAuthorizationPolicy(allowed: true);
    }

    private function service(?BedRepositoryInterface $beds = null, ?FakeTenantUserDirectory $users = null): HospitalizationService
    {
        return new HospitalizationService(
            $this->hospitalizations,
            $beds ?? $this->beds,
            $this->events,
            $this->encounters,
            $this->accounts,
            $users ?? FakeTenantUserDirectory::allowingAll(),
            $this->policy,
            TenantContext::authenticated(self::TENANT_ID, self::USER_ID, self::UNIT_ID),
            static fn (): DateTimeImmutable => new DateTimeImmutable(self::NOW),
        );
    }

    private function encounter(int $patientId, int $unitId = self::UNIT_ID): int
    {
        $encounter = Encounter::start(self::TENANT_ID, $unitId, $patientId, null, self::RESPONSIBLE_ID, new DateTimeImmutable('-1 hour'));
        $this->encounters->save($encounter);

        return (int) $encounter->id();
    }

    private function bed(string $code, int $unitId = self::UNIT_ID, int $rate = 15000): int
    {
        $bed = Bed::create(self::TENANT_ID, $unitId, $code, 'Leito ' . $code, $rate);
        $this->beds->save($bed);

        return (int) $bed->id();
    }

    private function bedStatus(int $bedId): string
    {
        $bed = $this->beds->findById($bedId);
        Assert::instanceOf(Bed::class, $bed);

        return $bed->status();
    }

    /** @return list<HospitalizationEvent> */
    private function eventsOfType(string $type): array
    {
        $found = [];

        foreach ([1, 2, 3, 4, 5, 6, 7, 8] as $hospitalizationId) {
            foreach ($this->events->listByHospitalization($hospitalizationId) as $event) {
                if ($event->eventType() === $type) {
                    $found[] = $event;
                }
            }
        }

        return $found;
    }

    private function admit(int $encounterId, int $bedId): Hospitalization
    {
        return $this->service()->admit($encounterId, $bedId, self::RESPONSIBLE_ID, 'Desidratação', '2026-10-08', self::ACTION);
    }

    public function testAdmitPersistsHospitalizationOccupiesBedAndRecordsAdmissionEvent(): void
    {
        $encounterId = $this->encounter(7);
        $bedId = $this->bed('L1', rate: 12345);

        $hospitalization = $this->admit($encounterId, $bedId);

        Assert::notNull($hospitalization->id());
        Assert::same(Hospitalization::STATUS_ADMITTED, $hospitalization->status());
        Assert::same(7, $hospitalization->patientId());
        Assert::same(self::UNIT_ID, $hospitalization->systemUnitId());
        Assert::same(12345, $hospitalization->dailyRateCents());
        Assert::same(self::USER_ID, $hospitalization->admittedBySystemUserId());
        Assert::same('2026-10-08', $hospitalization->expectedDischargeDate()?->format('Y-m-d'));
        Assert::same(self::NOW, $hospitalization->admittedAt()->format('Y-m-d H:i:s'));
        Assert::same(Bed::STATUS_OCCUPIED, $this->bedStatus($bedId));
        Assert::same($hospitalization->id(), $this->beds->findById($bedId)->currentHospitalizationId());
        Assert::count(1, $this->eventsOfType(HospitalizationEvent::TYPE_ADMISSION));
        Assert::same(self::UNIT_ID, $this->policy->requests[0]->resourceUnitId());
    }

    public function testAdmitPatientAlreadyHospitalizedThrows(): void
    {
        $this->admit($this->encounter(7), $this->bed('L1'));
        $secondEncounter = $this->encounter(7);
        $otherBed = $this->bed('L2');

        Assert::throws(PatientAlreadyHospitalizedException::class, fn () => $this->admit($secondEncounter, $otherBed));
        Assert::same(Bed::STATUS_AVAILABLE, $this->bedStatus($otherBed));
        Assert::count(1, $this->eventsOfType(HospitalizationEvent::TYPE_ADMISSION));
    }

    public function testSecondAdmissionToSameBedIsRejected(): void
    {
        $bedId = $this->bed('L1');
        $this->admit($this->encounter(7), $bedId);
        $otherEncounter = $this->encounter(8);

        try {
            $this->admit($otherEncounter, $bedId);
            throw new \RuntimeException('BedUnavailableException was not thrown');
        } catch (BedUnavailableException $e) {
            Assert::same("Bed {$bedId} is not available", $e->getMessage());
        }

        Assert::count(1, $this->eventsOfType(HospitalizationEvent::TYPE_ADMISSION));
        Assert::null($this->hospitalizations->findActiveByPatient(8));
    }

    public function testLostOccupyRaceThrowsAndRecordsNoEvent(): void
    {
        $bedId = $this->bed('L1');
        $encounterId = $this->encounter(7);
        $inner = $this->beds;
        // Simulates another tablet winning the conditional UPDATE between the read and occupy().
        $racingBeds = new class ($inner) implements BedRepositoryInterface {
            public function __construct(private readonly FakeBedRepository $inner)
            {
            }

            public function tenantId(): int
            {
                return $this->inner->tenantId();
            }

            public function findById(int|string $id): ?object
            {
                return $this->inner->findById($id);
            }

            public function save(object $entity): object
            {
                return $this->inner->save($entity);
            }

            public function remove(object $entity): void
            {
                $this->inner->remove($entity);
            }

            public function listByUnit(int $systemUnitId): array
            {
                return $this->inner->listByUnit($systemUnitId);
            }

            public function findByCode(int $systemUnitId, string $code): ?object
            {
                return $this->inner->findByCode($systemUnitId, $code);
            }

            public function occupy(int $bedId, int $hospitalizationId): bool
            {
                return false;
            }

            public function release(int $bedId, int $hospitalizationId): bool
            {
                return $this->inner->release($bedId, $hospitalizationId);
            }
        };

        try {
            $this->service($racingBeds)->admit($encounterId, $bedId, self::RESPONSIBLE_ID, 'Vômito', null, self::ACTION);
            throw new \RuntimeException('BedUnavailableException was not thrown');
        } catch (BedUnavailableException $e) {
            Assert::same("Bed {$bedId} is not available", $e->getMessage());
        }

        Assert::count(0, $this->eventsOfType(HospitalizationEvent::TYPE_ADMISSION));
    }

    public function testBedFromOtherUnitThrows(): void
    {
        $encounterId = $this->encounter(7);
        $bedId = $this->bed('X1', self::OTHER_UNIT_ID);

        Assert::throws(BedUnavailableException::class, fn () => $this->admit($encounterId, $bedId));
        Assert::same(0, $this->hospitalizations->saveCount);
        Assert::same(Bed::STATUS_AVAILABLE, $this->bedStatus($bedId));
    }

    public function testInactiveBedThrows(): void
    {
        $encounterId = $this->encounter(7);
        $bed = Bed::create(self::TENANT_ID, self::UNIT_ID, 'L9', 'Leito 9', 0);
        $bed->deactivate();
        $this->beds->save($bed);

        Assert::throws(BedUnavailableException::class, fn () => $this->admit($encounterId, (int) $bed->id()));
    }

    public function testEncounterFromOtherTenantThrowsCrossTenant(): void
    {
        $bedId = $this->bed('L1');

        Assert::throws(CrossTenantReferenceException::class, fn () => $this->admit(999, $bedId));
        Assert::count(0, $this->policy->requests);
    }

    public function testInactiveResponsibleIsRejected(): void
    {
        $encounterId = $this->encounter(7);
        $bedId = $this->bed('L1');

        try {
            $this->service(users: new FakeTenantUserDirectory([self::USER_ID]))
                ->admit($encounterId, $bedId, self::RESPONSIBLE_ID, 'Motivo', null, self::ACTION);
            throw new \RuntimeException('InvalidArgumentException was not thrown');
        } catch (InvalidArgumentException $e) {
            Assert::same('responsible_system_user_id must be an active user of this tenant', $e->getMessage());
        }

        Assert::same(0, $this->hospitalizations->saveCount);
    }

    public function testClosedEncounterAccountBlocksAdmission(): void
    {
        $encounterId = $this->encounter(7);
        $bedId = $this->bed('L1');
        $account = EncounterAccount::open(self::TENANT_ID, $encounterId, 7, 2, self::UNIT_ID);
        $account->close(new DateTimeImmutable());
        $this->accounts->save($account);

        try {
            $this->admit($encounterId, $bedId);
            throw new \RuntimeException('InvalidStatusTransitionException was not thrown');
        } catch (InvalidStatusTransitionException $e) {
            Assert::same(
                sprintf('Encounter account %d cannot be modified: status is "closed", not "open"', $account->id()),
                $e->getMessage(),
            );
        }

        Assert::same(Bed::STATUS_AVAILABLE, $this->bedStatus($bedId));
    }

    public function testAdmissionDeniedPersistsNothing(): void
    {
        $encounterId = $this->encounter(7);
        $bedId = $this->bed('L1');
        $this->policy->setAllowed(false);

        Assert::throws(AuthorizationDenied::class, fn () => $this->admit($encounterId, $bedId));
        Assert::same(0, $this->hospitalizations->saveCount);
        Assert::same(Bed::STATUS_AVAILABLE, $this->bedStatus($bedId));
    }

    public function testTransferReleasesOldBedAndOccupiesNewWithTransferEvent(): void
    {
        $oldBed = $this->bed('L1');
        $newBed = $this->bed('L2');
        $hospitalization = $this->admit($this->encounter(7), $oldBed);

        $moved = $this->service()->transfer((int) $hospitalization->id(), $newBed, self::ACTION);

        Assert::same($newBed, $moved->bedId());
        Assert::same(Bed::STATUS_AVAILABLE, $this->bedStatus($oldBed));
        Assert::same(Bed::STATUS_OCCUPIED, $this->bedStatus($newBed));
        Assert::same($hospitalization->id(), $this->beds->findById($newBed)->currentHospitalizationId());
        $transfers = $this->eventsOfType(HospitalizationEvent::TYPE_TRANSFER);
        Assert::count(1, $transfers);
        Assert::same($oldBed, $transfers[0]->fromBedId());
        Assert::same($newBed, $transfers[0]->toBedId());
    }

    public function testTransferToOccupiedBedThrowsAndKeepsOldBed(): void
    {
        $oldBed = $this->bed('L1');
        $busyBed = $this->bed('L2');
        $hospitalization = $this->admit($this->encounter(7), $oldBed);
        $this->admit($this->encounter(8), $busyBed);

        Assert::throws(
            BedUnavailableException::class,
            fn () => $this->service()->transfer((int) $hospitalization->id(), $busyBed, self::ACTION),
        );
        Assert::same(Bed::STATUS_OCCUPIED, $this->bedStatus($oldBed));
        Assert::same($hospitalization->id(), $this->beds->findById($oldBed)->currentHospitalizationId());
        Assert::count(0, $this->eventsOfType(HospitalizationEvent::TYPE_TRANSFER));
    }

    public function testTransferToBedOfOtherUnitThrows(): void
    {
        $hospitalization = $this->admit($this->encounter(7), $this->bed('L1'));
        $foreignBed = $this->bed('X1', self::OTHER_UNIT_ID);

        Assert::throws(
            BedUnavailableException::class,
            fn () => $this->service()->transfer((int) $hospitalization->id(), $foreignBed, self::ACTION),
        );
        Assert::same(Bed::STATUS_AVAILABLE, $this->bedStatus($foreignBed));
    }

    public function testRecordEvolutionAndVitals(): void
    {
        $hospitalization = $this->admit($this->encounter(7), $this->bed('L1'));
        $id = (int) $hospitalization->id();

        $evolution = $this->service()->recordEvolution($id, 'Alimentou-se bem', self::ACTION);
        $vitals = $this->service()->recordVitals($id, 38.5, 110, 24, 12.4, 2, '', self::ACTION);

        Assert::same(HospitalizationEvent::TYPE_EVOLUTION, $evolution->eventType());
        Assert::same('Alimentou-se bem', $evolution->notesText());
        Assert::same(self::USER_ID, $evolution->recordedBySystemUserId());
        Assert::same(HospitalizationEvent::TYPE_VITALS, $vitals->eventType());
        Assert::same(38.5, $vitals->temperatureC());
        Assert::same(2, $vitals->painScore());
        Assert::count(3, $this->service()->listEvents($id, self::ACTION));
    }

    public function testEvolutionRequiresAdmittedHospitalization(): void
    {
        $hospitalization = $this->admit($this->encounter(7), $this->bed('L1'));
        $hospitalization->discharge(new DateTimeImmutable(self::NOW), self::USER_ID, 'Alta');
        $this->hospitalizations->save($hospitalization);
        $id = (int) $hospitalization->id();

        Assert::throws(InvalidStatusTransitionException::class, fn () => $this->service()->recordEvolution($id, 'x', self::ACTION));
        Assert::throws(
            InvalidStatusTransitionException::class,
            fn () => $this->service()->recordVitals($id, 38.0, null, null, null, null, '', self::ACTION),
        );
    }

    public function testGetMissingHospitalizationThrowsCrossTenant(): void
    {
        try {
            $this->service()->get(42, self::ACTION);
            throw new \RuntimeException('CrossTenantReferenceException was not thrown');
        } catch (CrossTenantReferenceException $e) {
            Assert::same('Hospitalization 42 not found for this tenant', $e->getMessage());
        }
    }

    public function testGetFromOtherUnitIsDenied(): void
    {
        $encounterId = $this->encounter(7, self::OTHER_UNIT_ID);
        $hospitalization = Hospitalization::admit(
            self::TENANT_ID,
            self::OTHER_UNIT_ID,
            7,
            $encounterId,
            50,
            self::RESPONSIBLE_ID,
            self::USER_ID,
            'Motivo',
            null,
            0,
            new DateTimeImmutable(self::NOW),
        );
        $this->hospitalizations->save($hospitalization);
        $this->policy->setAllowed(false);

        Assert::throws(AuthorizationDenied::class, fn () => $this->service()->get((int) $hospitalization->id(), self::ACTION));
        $last = $this->policy->requests[count($this->policy->requests) - 1];
        Assert::same(self::OTHER_UNIT_ID, $last->resourceUnitId());
        Assert::true($last->requiresUnitScope());
    }

    public function testListActiveForCurrentUnit(): void
    {
        $this->admit($this->encounter(7), $this->bed('L1'));
        $this->admit($this->encounter(8), $this->bed('L2'));
        $other = Hospitalization::admit(
            self::TENANT_ID,
            self::OTHER_UNIT_ID,
            9,
            $this->encounter(9, self::OTHER_UNIT_ID),
            50,
            self::RESPONSIBLE_ID,
            self::USER_ID,
            'Motivo',
            null,
            0,
            new DateTimeImmutable(self::NOW),
        );
        $this->hospitalizations->save($other);

        $active = $this->service()->listActiveForCurrentUnit(self::ACTION);

        Assert::count(2, $active);
        $last = $this->policy->requests[count($this->policy->requests) - 1];
        Assert::same(self::UNIT_ID, $last->resourceUnitId());
    }
}
