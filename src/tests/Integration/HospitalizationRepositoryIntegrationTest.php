<?php

declare(strict_types=1);

namespace CentralVet\Tests\Integration;

use CentralVet\Domain\Bed;
use CentralVet\Domain\Encounter;
use CentralVet\Domain\Exception\BedUnavailableException;
use CentralVet\Domain\Exception\InvalidStatusTransitionException;
use CentralVet\Domain\Hospitalization;
use CentralVet\Domain\HospitalizationAdministration;
use CentralVet\Domain\HospitalizationEvent;
use CentralVet\Domain\HospitalizationOrder;
use CentralVet\Persistence\BedRepository;
use CentralVet\Persistence\EncounterRepository;
use CentralVet\Persistence\HospitalizationAdministrationRepository;
use CentralVet\Persistence\HospitalizationEventRepository;
use CentralVet\Persistence\HospitalizationOrderRepository;
use CentralVet\Persistence\HospitalizationRepository;
use CentralVet\Tenancy\TenantContext;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\MysqlIntegrationTestCase;
use DateTimeImmutable;

/**
 * The five hospitalization repositories (migration 0010) against the real
 * MySQL schema (T-07): atomic bed occupancy, tenant isolation and the
 * flowboard query. Every row lives only inside the test transaction,
 * rolled back in tearDown() — nothing is committed.
 */
final class HospitalizationRepositoryIntegrationTest extends MysqlIntegrationTestCase
{
    private int $userId;
    private int $unitId;
    private int $tenantA;
    private int $tenantB;
    private int $patientId;
    private int $encounterId;

    public function setUp(): void
    {
        parent::setUp();

        $hasTable = (bool) $this->pdo->query("SHOW TABLES LIKE 'hospitalization_administration'")->fetchColumn();
        Assert::true($hasTable, 'Migration 0010 must be applied to the test database');

        $this->userId = (int) $this->pdo->query('SELECT MIN(id) FROM system_users')->fetchColumn();
        Assert::true($this->userId > 0, 'Fixture requires at least one existing system_users row');

        // system_unit.id has no AUTO_INCREMENT (Adianti table): reuse an existing unit.
        $this->unitId = (int) $this->pdo->query('SELECT MIN(id) FROM system_unit')->fetchColumn();
        Assert::true($this->unitId > 0, 'Fixture requires at least one existing system_unit row');

        $this->tenantA = $this->createTenant('f6-hosp-a');
        $this->tenantB = $this->createTenant('f6-hosp-b');

        $this->pdo->prepare('INSERT INTO tutor (tenant_id, public_id, full_name, phone) VALUES (:t, UUID(), :n, :p)')
            ->execute(['t' => $this->tenantA, 'n' => 'F6 teste Tutor', 'p' => '11999990000']);
        $tutorId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare('INSERT INTO patient (tenant_id, tutor_id, name, species) VALUES (:t, :tutor, :n, :s)')
            ->execute(['t' => $this->tenantA, 'tutor' => $tutorId, 'n' => 'F6 teste Rex', 's' => 'canino']);
        $this->patientId = (int) $this->pdo->lastInsertId();

        $encounter = Encounter::start($this->tenantA, $this->unitId, $this->patientId, null, $this->userId, new DateTimeImmutable('2031-08-01 07:00:00'));
        (new EncounterRepository($this->contextFor($this->tenantA), $this->pdo))->save($encounter);
        $this->encounterId = (int) $encounter->id();
    }

    public function testOccupyIsAtomicAndReleaseOnlyByHolder(): void
    {
        $beds = new BedRepository($this->contextFor($this->tenantA), $this->pdo);
        $bed = $this->createBed('F6-L1');
        $hospitalization = $this->admit($bed, '2031-08-01 07:30:00');
        $other = $this->admit($this->createBed('F6-L2'), '2031-08-01 07:31:00');

        Assert::true($beds->occupy((int) $bed->id(), (int) $hospitalization->id()), 'first occupy wins');
        Assert::false($beds->occupy((int) $bed->id(), (int) $other->id()), 'second occupy on the same bed loses');

        /** @var Bed $occupied */
        $occupied = $beds->findById((int) $bed->id());
        Assert::same(Bed::STATUS_OCCUPIED, $occupied->status());
        Assert::same((int) $hospitalization->id(), $occupied->currentHospitalizationId());

        Assert::false($beds->release((int) $bed->id(), (int) $other->id()), 'only the holder releases');
        Assert::true($beds->release((int) $bed->id(), (int) $hospitalization->id()));

        /** @var Bed $released */
        $released = $beds->findById((int) $bed->id());
        Assert::same(Bed::STATUS_AVAILABLE, $released->status());
        Assert::null($released->currentHospitalizationId());
    }

    public function testOtherTenantCannotSeeOrOccupyTheBed(): void
    {
        $bed = $this->createBed('F6-L3');
        $hospitalization = $this->admit($bed, '2031-08-01 07:30:00');
        $bedsB = new BedRepository($this->contextFor($this->tenantB), $this->pdo);

        Assert::null($bedsB->findById((int) $bed->id()), 'bed of another tenant is invisible');
        Assert::false($bedsB->occupy((int) $bed->id(), (int) $hospitalization->id()), 'bed of another tenant cannot be occupied');
        Assert::null(
            (new HospitalizationRepository($this->contextFor($this->tenantB), $this->pdo))->findById((int) $hospitalization->id()),
            'hospitalization of another tenant is invisible',
        );
    }

    public function testSaveNeverTouchesOccupancyAndRefusesToDeactivateOccupiedBed(): void
    {
        $beds = new BedRepository($this->contextFor($this->tenantA), $this->pdo);
        $bed = $this->createBed('F6-L4');
        $hospitalization = $this->admit($bed, '2031-08-01 07:30:00');

        // Stale copy loaded while the bed was still available.
        /** @var Bed $stale */
        $stale = $beds->findById((int) $bed->id());
        Assert::true($beds->occupy((int) $bed->id(), (int) $hospitalization->id()));

        $stale->rename('Baia renomeada');
        $stale->changeDailyRate(9900);
        $stale->deactivate();

        $refused = false;
        try {
            $beds->save($stale);
        } catch (BedUnavailableException) {
            $refused = true;
        }
        Assert::true($refused, 'deactivating an occupied bed throws BedUnavailableException');

        /** @var Bed $current */
        $current = $beds->findById((int) $bed->id());
        Assert::same(Bed::STATUS_OCCUPIED, $current->status());
        Assert::same((int) $hospitalization->id(), $current->currentHospitalizationId());
        Assert::same('Baia F6-L4', $current->name(), 'refused save changes nothing');

        $beds->release((int) $bed->id(), (int) $hospitalization->id());
        /** @var Bed $free */
        $free = $beds->findById((int) $bed->id());
        $free->rename('Baia renomeada');
        $free->deactivate();
        $beds->save($free);

        /** @var Bed $inactive */
        $inactive = $beds->findById((int) $bed->id());
        Assert::same(Bed::STATUS_INACTIVE, $inactive->status());
        Assert::same('Baia renomeada', $inactive->name());
        Assert::same('F6-L4', $beds->findByCode($this->unitId, 'F6-L4')?->code());
        Assert::same(1, count(array_filter(
            $beds->listByUnit($this->unitId),
            static fn (Bed $b): bool => $b->id() === $bed->id(),
        )));
    }

    public function testHospitalizationOrderAndEventRoundTrip(): void
    {
        $context = $this->contextFor($this->tenantA);
        $hospitalizations = new HospitalizationRepository($context, $this->pdo);
        $bed = $this->createBed('F6-L5');
        $hospitalization = $this->admit($bed, '2031-08-01 07:30:00');

        Assert::same($hospitalization->id(), $hospitalizations->findActiveByPatient($this->patientId)?->id());
        Assert::same(1, count($hospitalizations->listActiveByUnit($this->unitId)));

        $order = $this->prescribe($hospitalization, '2031-08-01 08:00:00', '2031-08-02 08:00:00');
        $order->suspend(new DateTimeImmutable('2031-08-01 12:00:00'));
        $orders = new HospitalizationOrderRepository($context, $this->pdo);
        $orders->save($order);
        /** @var HospitalizationOrder $loadedOrder */
        $loadedOrder = $orders->listByHospitalization((int) $hospitalization->id())[0];
        Assert::same(HospitalizationOrder::STATUS_SUSPENDED, $loadedOrder->status());
        Assert::same('2031-08-01 12:00:00', $loadedOrder->suspendedAt()?->format('Y-m-d H:i:s'));

        $events = new HospitalizationEventRepository($context, $this->pdo);
        $events->save(HospitalizationEvent::record($this->tenantA, (int) $hospitalization->id(), HospitalizationEvent::TYPE_ADMISSION, $this->userId, new DateTimeImmutable('2031-08-01 07:30:00'), ''));
        $events->save(HospitalizationEvent::record($this->tenantA, (int) $hospitalization->id(), HospitalizationEvent::TYPE_VITALS, $this->userId, new DateTimeImmutable('2031-08-01 09:00:00'), '', 38.5, 110, null, 12.25, 3));
        $timeline = $events->listByHospitalization((int) $hospitalization->id());
        Assert::same(2, count($timeline));
        Assert::same(HospitalizationEvent::TYPE_VITALS, $timeline[0]->eventType(), 'most recent first');
        Assert::same(38.5, $timeline[0]->temperatureC());
        Assert::same(3, $timeline[0]->painScore());

        $hospitalization->discharge(new DateTimeImmutable('2031-08-03 10:00:00'), $this->userId, 'Alta F6 teste');
        $hospitalizations->save($hospitalization);
        /** @var Hospitalization $discharged */
        $discharged = $hospitalizations->findById((int) $hospitalization->id());
        Assert::same(Hospitalization::STATUS_DISCHARGED, $discharged->status());
        Assert::same('2031-08-03 10:00:00', $discharged->dischargedAt()?->format('Y-m-d H:i:s'));
        Assert::null($hospitalizations->findActiveByPatient($this->patientId));
    }

    public function testBoardRowsIncludeLatePendingAndExcludeDischarged(): void
    {
        $context = $this->contextFor($this->tenantA);
        $administrations = new HospitalizationAdministrationRepository($context, $this->pdo);
        $from = new DateTimeImmutable('2031-08-01 08:00:00');
        $to = new DateTimeImmutable('2031-08-01 20:00:00');

        $active = $this->admit($this->createBed('F6-L6'), '2031-08-01 01:00:00');
        $activeOrder = $this->prescribe($active, '2031-08-01 02:00:00', '2031-08-02 02:00:00');
        $late = $this->scheduleAt($active, $activeOrder, '2031-08-01 05:00:00');
        $inShift = $this->scheduleAt($active, $activeOrder, '2031-08-01 10:00:00');
        $afterShift = $this->scheduleAt($active, $activeOrder, '2031-08-01 22:00:00');
        $doneBefore = $this->scheduleAt($active, $activeOrder, '2031-08-01 03:00:00');
        $doneBefore->markDone(new DateTimeImmutable('2031-08-01 03:05:00'), $this->userId, '');
        $administrations->save($doneBefore);

        $dischargedBed = $this->createBed('F6-L7');
        $discharged = $this->admit($dischargedBed, '2031-08-01 01:00:00');
        $dischargedOrder = $this->prescribe($discharged, '2031-08-01 02:00:00', '2031-08-02 02:00:00');
        $ghost = $this->scheduleAt($discharged, $dischargedOrder, '2031-08-01 10:00:00');
        $discharged->discharge(new DateTimeImmutable('2031-08-01 07:00:00'), $this->userId, '');
        (new HospitalizationRepository($context, $this->pdo))->save($discharged);

        $rows = $administrations->listBoardRows($this->unitId, $from, $to);
        $ids = array_map(static fn (array $row): int => $row['administration_id'], $rows);

        Assert::true(in_array($late->id(), $ids, true), 'pending administration 3 h before from is listed');
        Assert::true(in_array($inShift->id(), $ids, true), 'pending administration inside the shift is listed');
        Assert::false(in_array($ghost->id(), $ids, true), 'administration of a discharged hospitalization is not listed');
        Assert::false(in_array($afterShift->id(), $ids, true), 'administration after the shift is not listed');
        Assert::false(in_array($doneBefore->id(), $ids, true), 'done administration before the shift is not listed');

        $lateRow = $rows[array_search($late->id(), $ids, true)];
        Assert::same('F6 teste Rex', $lateRow['patient_name']);
        Assert::same('F6-L6', $lateRow['bed_code']);
        Assert::same('Dipirona F6', $lateRow['description_text']);
        Assert::same('2031-08-01 05:00:00', $lateRow['scheduled_at']);
        Assert::same('pending', $lateRow['status']);
        Assert::null($lateRow['performed_at']);

        $otherTenant = new HospitalizationAdministrationRepository($this->contextFor($this->tenantB), $this->pdo);
        Assert::same([], $otherTenant->listBoardRows($this->unitId, $from, $to), 'other tenant sees no board rows');
    }

    public function testSavingAStaleAdmittedCopyAfterDischargeIsRefusedWithoutOverwriting(): void
    {
        $hospitalizations = new HospitalizationRepository($this->contextFor($this->tenantA), $this->pdo);
        $hospitalization = $this->admit($this->createBed('F6-L9'), '2031-08-01 07:30:00');
        $otherBed = $this->createBed('F6-L10');
        $id = (int) $hospitalization->id();

        /** @var Hospitalization $unchanged */
        $unchanged = $hospitalizations->findById($id);
        $hospitalizations->save($unchanged);
        Assert::same(Hospitalization::STATUS_ADMITTED, $hospitalizations->findById($id)?->status(), 'saving an unchanged admitted copy is a no-op');

        // A transfer loads the admitted row, then the discharge commits.
        /** @var Hospitalization $transferCopy */
        $transferCopy = $hospitalizations->findById($id);
        /** @var Hospitalization $dischargeCopy */
        $dischargeCopy = $hospitalizations->findById($id);
        $dischargeCopy->discharge(new DateTimeImmutable('2031-08-02 09:00:00'), $this->userId, 'Alta F6 teste');
        $hospitalizations->save($dischargeCopy);

        $transferCopy->moveToBed((int) $otherBed->id());
        $message = '';
        try {
            $hospitalizations->save($transferCopy);
        } catch (InvalidStatusTransitionException $e) {
            $message = $e->getMessage();
        }
        Assert::same("Hospitalization {$id} is not admitted", $message, 'stale admitted save signals the lost race');

        /** @var Hospitalization $current */
        $current = $hospitalizations->findById($id);
        Assert::same(Hospitalization::STATUS_DISCHARGED, $current->status(), 'discharge is not undone');
        Assert::same('2031-08-02 09:00:00', $current->dischargedAt()?->format('Y-m-d H:i:s'));
        Assert::same($hospitalization->bedId(), $current->bedId(), 'bed_id is not overwritten');
    }

    public function testSavingAStaleAdministrationNoLongerPendingIsRefusedWithoutOverwriting(): void
    {
        $administrations = new HospitalizationAdministrationRepository($this->contextFor($this->tenantA), $this->pdo);
        $hospitalization = $this->admit($this->createBed('F6-L8'), '2031-08-01 07:30:00');
        $order = $this->prescribe($hospitalization, '2031-08-01 08:00:00', '2031-08-02 08:00:00');
        $id = (int) $this->scheduleAt($hospitalization, $order, '2031-08-01 08:00:00')->id();

        // Two tablets load the same pending administration.
        /** @var HospitalizationAdministration $first */
        $first = $administrations->findById($id);
        /** @var HospitalizationAdministration $second */
        $second = $administrations->findById($id);

        $first->markDone(new DateTimeImmutable('2031-08-01 08:05:00'), $this->userId, 'Dada F6');
        $administrations->save($first);

        $second->markSkipped(new DateTimeImmutable('2031-08-01 08:06:00'), $this->userId, 'Recusou F6');
        $message = '';
        try {
            $administrations->save($second);
        } catch (InvalidStatusTransitionException $e) {
            $message = $e->getMessage();
        }
        Assert::same("Administration {$id} is not pending", $message, 'stale save signals the lost race');

        $current = $administrations->findById($id);
        Assert::same(HospitalizationAdministration::STATUS_DONE, $current?->status(), 'row keeps the first outcome');
        Assert::same('2031-08-01 08:05:00', $current?->performedAt()?->format('Y-m-d H:i:s'));
        Assert::same('Dada F6', $current?->notesText());

        /** @var HospitalizationAdministration $third */
        $third = HospitalizationAdministration::reconstitute($id, $this->tenantA, (int) $hospitalization->id(), (int) $order->id(), new DateTimeImmutable('2031-08-01 08:00:00'), 'pending', null, null, null);
        $third->cancel();
        Assert::throws(InvalidStatusTransitionException::class, static fn () => $administrations->save($third), 'cancel over done is refused too');
        Assert::same(HospitalizationAdministration::STATUS_DONE, $administrations->findById($id)?->status());
    }

    private function createBed(string $code): Bed
    {
        $bed = Bed::create($this->tenantA, $this->unitId, $code, 'Baia ' . $code, 15000);
        (new BedRepository($this->contextFor($this->tenantA), $this->pdo))->save($bed);
        Assert::true(($bed->id() ?? 0) > 0, 'insert assigns the bed id');

        return $bed;
    }

    private function admit(Bed $bed, string $at): Hospitalization
    {
        $hospitalization = Hospitalization::admit(
            $this->tenantA,
            $this->unitId,
            $this->patientId,
            $this->encounterId,
            (int) $bed->id(),
            $this->userId,
            $this->userId,
            'F6 teste internação',
            null,
            $bed->dailyRateCents(),
            new DateTimeImmutable($at),
        );
        (new HospitalizationRepository($this->contextFor($this->tenantA), $this->pdo))->save($hospitalization);
        Assert::true(($hospitalization->id() ?? 0) > 0, 'insert assigns the hospitalization id');

        return $hospitalization;
    }

    private function prescribe(Hospitalization $hospitalization, string $startsAt, string $endsAt): HospitalizationOrder
    {
        $order = HospitalizationOrder::prescribe(
            $this->tenantA,
            (int) $hospitalization->id(),
            HospitalizationOrder::TYPE_MEDICATION,
            'Dipirona F6',
            null,
            null,
            '25 mg/kg',
            'iv',
            8,
            new DateTimeImmutable($startsAt),
            new DateTimeImmutable($endsAt),
            $this->userId,
        );
        (new HospitalizationOrderRepository($this->contextFor($this->tenantA), $this->pdo))->save($order);
        Assert::true(($order->id() ?? 0) > 0, 'insert assigns the order id');

        return $order;
    }

    private function scheduleAt(Hospitalization $hospitalization, HospitalizationOrder $order, string $at): HospitalizationAdministration
    {
        $administration = HospitalizationAdministration::schedule($this->tenantA, (int) $hospitalization->id(), (int) $order->id(), new DateTimeImmutable($at));
        (new HospitalizationAdministrationRepository($this->contextFor($this->tenantA), $this->pdo))->save($administration);
        Assert::true(($administration->id() ?? 0) > 0, 'insert assigns the administration id');

        return $administration;
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
        $statement->execute(['slug' => $slug, 'legal_name' => 'F6 teste tenant (' . $slug . ')', 'status' => 'active']);

        return (int) $this->pdo->lastInsertId();
    }
}
