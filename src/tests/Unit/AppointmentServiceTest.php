<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Application\AppointmentService;
use CentralVet\Application\PatientService;
use CentralVet\Authorization\Exception\AuthorizationDenied;
use CentralVet\Domain\Appointment;
use CentralVet\Domain\Exception\CrossTenantReferenceException;
use CentralVet\Domain\Exception\SchedulingConflictException;
use CentralVet\Domain\Patient;
use CentralVet\Domain\Service;
use CentralVet\Tenancy\TenantContext;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\FakeAppointmentRepository;
use CentralVet\Tests\Support\FakeAuthorizationPolicy;
use CentralVet\Tests\Support\FakePatientRepository;
use CentralVet\Tests\Support\FakeServiceRepository;
use CentralVet\Tests\Support\FakeTutorRepository;
use DateTimeImmutable;

/**
 * Unit tests for AppointmentService (T-07), against fake repositories
 * (T-16) — no database involved, since the `appointment`/`service`/
 * `patient` tables do not exist yet (migration T-01 not applied). Covers
 * T-16's acceptance criterion: rejection of a scheduling conflict.
 *
 * Every scenario below (other than the two dedicated authorization tests)
 * wires AppointmentService with an "allow-everything"
 * FakeAuthorizationPolicy, so they keep exercising only their own business
 * rule (scheduling conflict, cross-tenant reference) — the unit-scope
 * authorization check itself is covered separately by
 * testScheduleThrowsAuthorizationDeniedWhenPolicyDenies(), which also
 * proves the check is not decorative: nothing is persisted when it denies.
 */
final class AppointmentServiceTest
{
    private const ACTION = 'test::action';

    public function testScheduleRejectsOverlappingSlotForSameProfessional(): void
    {
        $service = $this->makeAppointmentService();

        $service->schedule([
            'patient_id' => 1,
            'service_id' => 1,
            'professional_system_user_id' => 10,
            'scheduled_at' => '2026-09-22 09:00:00',
            'system_unit_id' => 1,
        ], self::ACTION);

        Assert::throws(
            SchedulingConflictException::class,
            static fn () => $service->schedule([
                'patient_id' => 1,
                'service_id' => 1,
                'professional_system_user_id' => 10,
                // Overlaps the first booking's 09:00-09:30 window.
                'scheduled_at' => '2026-09-22 09:15:00',
                'system_unit_id' => 1,
            ], self::ACTION),
        );
    }

    public function testScheduleAllowsBackToBackAppointmentsForSameProfessional(): void
    {
        $service = $this->makeAppointmentService();

        $service->schedule([
            'patient_id' => 1,
            'service_id' => 1,
            'professional_system_user_id' => 10,
            'scheduled_at' => '2026-09-22 09:00:00',
            'system_unit_id' => 1,
        ], self::ACTION);

        // Second appointment starts exactly when the first one ends
        // (09:00 + 30min duration) — half-open interval, must not conflict.
        $second = $service->schedule([
            'patient_id' => 1,
            'service_id' => 1,
            'professional_system_user_id' => 10,
            'scheduled_at' => '2026-09-22 09:30:00',
            'system_unit_id' => 1,
        ], self::ACTION);

        Assert::notNull($second->id);
    }

    public function testScheduleAllowsOverlapWithAnotherProfessional(): void
    {
        $service = $this->makeAppointmentService();

        $service->schedule([
            'patient_id' => 1,
            'service_id' => 1,
            'professional_system_user_id' => 10,
            'scheduled_at' => '2026-09-22 09:00:00',
            'system_unit_id' => 1,
        ], self::ACTION);

        $second = $service->schedule([
            'patient_id' => 1,
            'service_id' => 1,
            // Different professional: same slot must not conflict.
            'professional_system_user_id' => 20,
            'scheduled_at' => '2026-09-22 09:15:00',
            'system_unit_id' => 1,
        ], self::ACTION);

        Assert::notNull($second->id);
    }

    public function testScheduleIgnoresCancelledAppointmentsWhenCheckingConflict(): void
    {
        $cancelled = new Appointment(
            id: 99,
            tenantId: 1,
            systemUnitId: 1,
            patientId: 1,
            serviceId: 1,
            professionalSystemUserId: 10,
            scheduledAt: new DateTimeImmutable('2026-09-22 09:00:00'),
            status: Appointment::STATUS_CANCELLED,
        );

        $service = $this->makeAppointmentService([$cancelled]);

        // Same slot as the cancelled appointment: must be free.
        $booked = $service->schedule([
            'patient_id' => 1,
            'service_id' => 1,
            'professional_system_user_id' => 10,
            'scheduled_at' => '2026-09-22 09:00:00',
            'system_unit_id' => 1,
        ], self::ACTION);

        Assert::notNull($booked->id);
    }

    public function testScheduleRejectsCrossTenantPatientId(): void
    {
        $foreignPatient = new Patient(id: null, tenantId: 2, tutorId: 1, name: 'Rex', species: 'canino');
        $patients = new FakePatientRepository(1, $foreignPatient);
        $tutors = new FakeTutorRepository(1);
        $services = new FakeServiceRepository(1, Service::create(1, 'Consulta', null, 30, 15000));
        $appointments = new FakeAppointmentRepository(1);
        $patientService = new PatientService($patients, $tutors, TenantContext::authenticated(1, 1, 1));
        $service = new AppointmentService(
            $appointments,
            $services,
            $patientService,
            TenantContext::authenticated(1, 1, 1),
            new FakeAuthorizationPolicy(allowed: true),
        );

        Assert::throws(
            CrossTenantReferenceException::class,
            static fn () => $service->schedule([
                'patient_id' => 1,
                'service_id' => 1,
                'professional_system_user_id' => 10,
                'scheduled_at' => '2026-09-22 09:00:00',
                'system_unit_id' => 1,
            ], self::ACTION),
        );
    }

    public function testScheduleRejectsCrossTenantServiceId(): void
    {
        $ownPatient = new Patient(id: null, tenantId: 1, tutorId: 1, name: 'Rex', species: 'canino');
        $patients = new FakePatientRepository(1, $ownPatient);
        $tutors = new FakeTutorRepository(1);
        $foreignService = Service::create(2, 'Consulta tenant dois', null, 30, 15000);
        $services = new FakeServiceRepository(1, $foreignService);
        $appointments = new FakeAppointmentRepository(1);
        $patientService = new PatientService($patients, $tutors, TenantContext::authenticated(1, 1, 1));
        $service = new AppointmentService(
            $appointments,
            $services,
            $patientService,
            TenantContext::authenticated(1, 1, 1),
            new FakeAuthorizationPolicy(allowed: true),
        );

        Assert::throws(
            CrossTenantReferenceException::class,
            static fn () => $service->schedule([
                'patient_id' => 1,
                'service_id' => 1,
                'professional_system_user_id' => 10,
                'scheduled_at' => '2026-09-22 09:00:00',
                'system_unit_id' => 1,
            ], self::ACTION),
        );
    }

    /**
     * Proves the unit-scope authorization check added to schedule() is a
     * real gate, not decorative: with a policy configured to always deny, a
     * candidate that would otherwise be perfectly valid (own-tenant
     * patient/service, no conflict) is rejected with AuthorizationDenied
     * and, crucially, never reaches AppointmentRepository::save() — the
     * fake repository still reports no appointment under the id the first
     * successful save would have produced.
     */
    public function testScheduleThrowsAuthorizationDeniedWhenPolicyDenies(): void
    {
        $ownPatient = new Patient(id: null, tenantId: 1, tutorId: 1, name: 'Rex', species: 'canino');
        $patients = new FakePatientRepository(1, $ownPatient);
        $tutors = new FakeTutorRepository(1);
        $services = new FakeServiceRepository(1, Service::create(1, 'Consulta', null, 30, 15000));
        $appointments = new FakeAppointmentRepository(1);
        $patientService = new PatientService($patients, $tutors, TenantContext::authenticated(1, 1, 1));
        $service = new AppointmentService(
            $appointments,
            $services,
            $patientService,
            TenantContext::authenticated(1, 1, 1),
            new FakeAuthorizationPolicy(allowed: false),
        );

        Assert::throws(
            AuthorizationDenied::class,
            static fn () => $service->schedule([
                'patient_id' => 1,
                'service_id' => 1,
                'professional_system_user_id' => 10,
                'scheduled_at' => '2026-09-22 09:00:00',
                'system_unit_id' => 1,
            ], self::ACTION),
        );

        Assert::null($appointments->findById(1));
    }

    /**
     * Covers AppointmentService::findById() (passthrough to
     * AppointmentRepositoryInterface::findById(), added so
     * EncounterView can resolve an encounter's appointment_id into its
     * service_id without TSession): returns the matching Appointment for an
     * id that exists in the (fake) repository.
     */
    public function testFindByIdReturnsMatchingAppointment(): void
    {
        $service = $this->makeAppointmentService();

        $created = $service->schedule([
            'patient_id' => 1,
            'service_id' => 1,
            'professional_system_user_id' => 10,
            'scheduled_at' => '2026-09-22 09:00:00',
            'system_unit_id' => 1,
        ], self::ACTION);

        $found = $service->findById($created->id);

        Assert::notNull($found);
        Assert::same($created->id, $found->id);
        Assert::same(1, $found->patientId);
        Assert::same(1, $found->serviceId);
    }

    /**
     * Covers AppointmentService::findById() returning null for an id that
     * does not exist in the (fake) repository — mirrors the real
     * AppointmentRepository::findById()'s behaviour for a missing row.
     */
    public function testFindByIdReturnsNullForUnknownId(): void
    {
        $service = $this->makeAppointmentService();

        Assert::null($service->findById(999));
    }

    /**
     * @param list<Appointment> $seedAppointments
     */
    private function makeAppointmentService(array $seedAppointments = []): AppointmentService
    {
        $ownPatient = new Patient(id: null, tenantId: 1, tutorId: 1, name: 'Rex', species: 'canino');
        $patients = new FakePatientRepository(1, $ownPatient);
        $tutors = new FakeTutorRepository(1);
        $services = new FakeServiceRepository(1, Service::create(1, 'Consulta', null, 30, 15000));
        $appointments = new FakeAppointmentRepository(1, ...$seedAppointments);

        $patientService = new PatientService($patients, $tutors, TenantContext::authenticated(1, 1, 1));

        return new AppointmentService(
            $appointments,
            $services,
            $patientService,
            TenantContext::authenticated(1, 1, 1),
            new FakeAuthorizationPolicy(allowed: true),
        );
    }
}
