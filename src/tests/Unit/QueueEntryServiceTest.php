<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Application\PatientService;
use CentralVet\Application\QueueEntryService;
use CentralVet\Authorization\Exception\AuthorizationDenied;
use CentralVet\Domain\Exception\CrossTenantReferenceException;
use CentralVet\Domain\Exception\InvalidStatusTransitionException;
use CentralVet\Domain\Patient;
use CentralVet\Domain\QueueEntry;
use CentralVet\Tenancy\TenantContext;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\FakeAuthorizationPolicy;
use CentralVet\Tests\Support\FakePatientRepository;
use CentralVet\Tests\Support\FakeQueueEntryRepository;
use CentralVet\Tests\Support\FakeTutorRepository;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Unit tests for QueueEntryService (T-08), against fake repositories (T-16)
 * — no database involved, since the `queue_entry`/`patient` tables do not
 * exist yet (migration T-01 not applied). Covers T-16's acceptance
 * criterion: rejection of an invalid status transition.
 *
 * Every scenario below (other than the two dedicated authorization tests)
 * wires QueueEntryService with an "allow-everything" FakeAuthorizationPolicy,
 * so they keep exercising only their own business rule (cross-tenant
 * reference, status transition) — the unit-scope authorization check itself
 * is covered separately by testCheckInThrowsAuthorizationDeniedWhenPolicyDenies()
 * and testAdvanceStatusThrowsAuthorizationDeniedWhenPolicyDenies(), which
 * also prove the check is not decorative: nothing is persisted/mutated when
 * it denies.
 */
final class QueueEntryServiceTest
{
    private const ACTION = 'test::action';

    public function testCheckInRejectsPatientIdFromAnotherTenant(): void
    {
        $foreignPatient = new Patient(id: null, tenantId: 2, tutorId: 1, name: 'Rex', species: 'canino');
        $patients = new FakePatientRepository(1, $foreignPatient);
        $service = $this->makeQueueEntryService($patients);

        Assert::throws(
            CrossTenantReferenceException::class,
            static fn () => $service->checkIn([
                'patient_id' => 1,
                'professional_system_user_id' => 10,
                'system_unit_id' => 1,
            ], self::ACTION),
        );
    }

    public function testCheckInAcceptsWalkInWithoutAppointmentId(): void
    {
        $ownPatient = new Patient(id: null, tenantId: 1, tutorId: 1, name: 'Rex', species: 'canino');
        $patients = new FakePatientRepository(1, $ownPatient);
        $service = $this->makeQueueEntryService($patients);

        $entry = $service->checkIn([
            'patient_id' => 1,
            'professional_system_user_id' => 10,
            'system_unit_id' => 1,
        ], self::ACTION);

        Assert::notNull($entry->id());
        Assert::null($entry->appointmentId());
        Assert::same(QueueEntry::STATUS_AGUARDANDO, $entry->status());
    }

    public function testAdvanceStatusMovesOneStepAtATime(): void
    {
        $ownPatient = new Patient(id: null, tenantId: 1, tutorId: 1, name: 'Rex', species: 'canino');
        $patients = new FakePatientRepository(1, $ownPatient);
        $service = $this->makeQueueEntryService($patients);

        $entry = $service->checkIn([
            'patient_id' => 1,
            'professional_system_user_id' => 10,
            'system_unit_id' => 1,
        ], self::ACTION);

        $advanced = $service->advanceStatus($entry->id(), self::ACTION);
        Assert::same(QueueEntry::STATUS_EM_ATENDIMENTO, $advanced->status());

        $finished = $service->advanceStatus($entry->id(), self::ACTION);
        Assert::same(QueueEntry::STATUS_ATENDIDO, $finished->status());
    }

    public function testAdvanceStatusRejectsAdvancingPastTerminalStatus(): void
    {
        $ownPatient = new Patient(id: null, tenantId: 1, tutorId: 1, name: 'Rex', species: 'canino');
        $patients = new FakePatientRepository(1, $ownPatient);
        $service = $this->makeQueueEntryService($patients);

        $entry = $service->checkIn([
            'patient_id' => 1,
            'professional_system_user_id' => 10,
            'system_unit_id' => 1,
        ], self::ACTION);

        $service->advanceStatus($entry->id(), self::ACTION);
        $service->advanceStatus($entry->id(), self::ACTION);

        // Entry is now 'atendido' (terminal) — a third advance must be
        // rejected, covering both "skip a step" and "regress" the same way
        // (see QueueEntry::advance() docblock).
        Assert::throws(
            InvalidStatusTransitionException::class,
            static fn () => $service->advanceStatus($entry->id(), self::ACTION),
        );
    }

    public function testAdvanceStatusRejectsDirectReconstitutionInAtendido(): void
    {
        $direct = QueueEntry::reconstitute(
            id: 5,
            tenantId: 1,
            systemUnitId: 1,
            patientId: 1,
            appointmentId: null,
            professionalSystemUserId: 10,
            status: QueueEntry::STATUS_ATENDIDO,
            checkedInAt: new DateTimeImmutable('-2 hours'),
            calledAt: new DateTimeImmutable('-90 minutes'),
            startedAt: new DateTimeImmutable('-90 minutes'),
            finishedAt: new DateTimeImmutable('-30 minutes'),
            createdAt: null,
            updatedAt: null,
        );

        $ownPatient = new Patient(id: null, tenantId: 1, tutorId: 1, name: 'Rex', species: 'canino');
        $patients = new FakePatientRepository(1, $ownPatient);
        $queueEntries = new FakeQueueEntryRepository(1, $direct);
        $tutors = new FakeTutorRepository(1);
        $patientService = new PatientService($patients, $tutors, TenantContext::authenticated(1, 1, 1));
        $service = new QueueEntryService(
            $queueEntries,
            $patientService,
            TenantContext::authenticated(1, 1, 1),
            new FakeAuthorizationPolicy(allowed: true),
        );

        Assert::throws(
            InvalidStatusTransitionException::class,
            static fn () => $service->advanceStatus(5, self::ACTION),
        );
    }

    public function testAdvanceStatusRejectsUnknownEntry(): void
    {
        $ownPatient = new Patient(id: null, tenantId: 1, tutorId: 1, name: 'Rex', species: 'canino');
        $patients = new FakePatientRepository(1, $ownPatient);
        $service = $this->makeQueueEntryService($patients);

        Assert::throws(InvalidArgumentException::class, static fn () => $service->advanceStatus(999, self::ACTION));
    }

    public function testListTodayExcludesAttendedEntries(): void
    {
        $ownPatient = new Patient(id: null, tenantId: 1, tutorId: 1, name: 'Rex', species: 'canino');
        $patients = new FakePatientRepository(1, $ownPatient);
        $service = $this->makeQueueEntryService($patients);

        $waiting = $service->checkIn(['patient_id' => 1, 'professional_system_user_id' => 10, 'system_unit_id' => 1], self::ACTION);
        $finished = $service->checkIn(['patient_id' => 1, 'professional_system_user_id' => 10, 'system_unit_id' => 1], self::ACTION);
        $service->advanceStatus($finished->id(), self::ACTION);
        $service->advanceStatus($finished->id(), self::ACTION);

        $today = $service->listToday(1);

        Assert::count(1, $today);
        Assert::same($waiting->id(), $today[0]->id());
    }

    /**
     * Proves the unit-scope authorization check added to checkIn() is a
     * real gate, not decorative: with a policy configured to always deny,
     * check-in data that would otherwise be perfectly valid (own-tenant
     * patient) is rejected with AuthorizationDenied and never reaches
     * QueueEntryRepository::save() — the fake repository still reports no
     * entry under the id the first successful save would have produced.
     */
    public function testCheckInThrowsAuthorizationDeniedWhenPolicyDenies(): void
    {
        $ownPatient = new Patient(id: null, tenantId: 1, tutorId: 1, name: 'Rex', species: 'canino');
        $patients = new FakePatientRepository(1, $ownPatient);
        $tutors = new FakeTutorRepository(1);
        $queueEntries = new FakeQueueEntryRepository(1);
        $patientService = new PatientService($patients, $tutors, TenantContext::authenticated(1, 1, 1));
        $service = new QueueEntryService(
            $queueEntries,
            $patientService,
            TenantContext::authenticated(1, 1, 1),
            new FakeAuthorizationPolicy(allowed: false),
        );

        Assert::throws(
            AuthorizationDenied::class,
            static fn () => $service->checkIn([
                'patient_id' => 1,
                'professional_system_user_id' => 10,
                'system_unit_id' => 1,
            ], self::ACTION),
        );

        Assert::null($queueEntries->findById(1));
    }

    /**
     * Proves the unit-scope authorization check added to advanceStatus() is
     * a real gate, not decorative, and that it reads the entry's REAL unit
     * back from storage rather than trusting caller input (advanceStatus()
     * takes no unit parameter at all): the entry is checked in through an
     * "allow" service instance, then a second service instance sharing the
     * same fake repository but wired with a "deny" policy attempts to
     * advance it. The call is rejected with AuthorizationDenied and the
     * entry's status is left untouched at 'aguardando'.
     */
    public function testAdvanceStatusThrowsAuthorizationDeniedWhenPolicyDenies(): void
    {
        $ownPatient = new Patient(id: null, tenantId: 1, tutorId: 1, name: 'Rex', species: 'canino');
        $patients = new FakePatientRepository(1, $ownPatient);
        $tutors = new FakeTutorRepository(1);
        $queueEntries = new FakeQueueEntryRepository(1);
        $context = TenantContext::authenticated(1, 1, 1);

        $allowingService = new QueueEntryService(
            $queueEntries,
            new PatientService($patients, $tutors, $context),
            $context,
            new FakeAuthorizationPolicy(allowed: true),
        );

        $entry = $allowingService->checkIn([
            'patient_id' => 1,
            'professional_system_user_id' => 10,
            'system_unit_id' => 1,
        ], self::ACTION);

        $denyingService = new QueueEntryService(
            $queueEntries,
            new PatientService($patients, $tutors, $context),
            $context,
            new FakeAuthorizationPolicy(allowed: false),
        );

        Assert::throws(
            AuthorizationDenied::class,
            static fn () => $denyingService->advanceStatus($entry->id(), self::ACTION),
        );

        Assert::same(QueueEntry::STATUS_AGUARDANDO, $queueEntries->findById($entry->id())->status());
    }

    /**
     * T-29: an appointment can be checked in only once — the second
     * checkIn() with the same appointment_id is refused before anything is
     * saved, while walk-ins (no appointment_id) are never deduplicated.
     */
    public function testCheckInRejectsAppointmentAlreadyInTheQueue(): void
    {
        $ownPatient = new Patient(id: null, tenantId: 1, tutorId: 1, name: 'Rex', species: 'canino');
        $patients = new FakePatientRepository(1, $ownPatient);
        $queueEntries = new FakeQueueEntryRepository(1);
        $service = $this->makeQueueEntryService($patients, $queueEntries);
        $data = ['patient_id' => 1, 'professional_system_user_id' => 10, 'system_unit_id' => 1, 'appointment_id' => 7];

        $entry = $service->checkIn($data, self::ACTION);
        Assert::same(7, $entry->appointmentId());
        Assert::count(1, $queueEntries->listActiveByUnit(1));

        $message = null;
        try {
            $service->checkIn($data, self::ACTION);
        } catch (\DomainException $e) {
            $message = $e->getMessage();
        }

        Assert::same('Appointment 7 is already in the queue', $message);
        Assert::count(1, $queueEntries->listActiveByUnit(1));
    }

    /**
     * T-41: AgendaView asks once per load which of the day's appointments
     * already have a queue entry, to swap the Check-in link for the badge.
     */
    public function testAppointmentIdsInQueueReturnsOnlyCheckedInAppointments(): void
    {
        $ownPatient = new Patient(id: null, tenantId: 1, tutorId: 1, name: 'Rex', species: 'canino');
        $patients = new FakePatientRepository(1, $ownPatient);
        $queueEntries = new FakeQueueEntryRepository(1);
        $service = $this->makeQueueEntryService($patients, $queueEntries);
        $service->checkIn(['patient_id' => 1, 'professional_system_user_id' => 10, 'system_unit_id' => 1, 'appointment_id' => 7], self::ACTION);

        Assert::same([7], $service->appointmentIdsInQueue([7, 8]));
        Assert::same([], $service->appointmentIdsInQueue([8]));
        Assert::same([], $service->appointmentIdsInQueue([]));
    }

    public function testCheckInWithoutAppointmentIdTwiceCreatesTwoEntries(): void
    {
        $ownPatient = new Patient(id: null, tenantId: 1, tutorId: 1, name: 'Rex', species: 'canino');
        $patients = new FakePatientRepository(1, $ownPatient);
        $queueEntries = new FakeQueueEntryRepository(1);
        $service = $this->makeQueueEntryService($patients, $queueEntries);
        $data = ['patient_id' => 1, 'professional_system_user_id' => 10, 'system_unit_id' => 1];

        $service->checkIn($data, self::ACTION);
        $service->checkIn($data, self::ACTION);

        Assert::count(2, $queueEntries->listActiveByUnit(1));
    }

    private function makeQueueEntryService(FakePatientRepository $patients, ?FakeQueueEntryRepository $queueEntries = null): QueueEntryService
    {
        $tutors = new FakeTutorRepository(1);
        $queueEntries ??= new FakeQueueEntryRepository(1);
        $patientService = new PatientService($patients, $tutors, TenantContext::authenticated(1, 1, 1));

        return new QueueEntryService(
            $queueEntries,
            $patientService,
            TenantContext::authenticated(1, 1, 1),
            new FakeAuthorizationPolicy(allowed: true),
        );
    }
}
