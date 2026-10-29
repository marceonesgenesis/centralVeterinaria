<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Application\AppointmentFollowupService;
use CentralVet\Domain\Appointment;
use CentralVet\Domain\Encounter;
use CentralVet\Domain\Exception\CrossTenantReferenceException;
use CentralVet\Tenancy\TenantContext;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\FakeAppointmentFollowupRepository;
use CentralVet\Tests\Support\FakeAppointmentRepository;
use CentralVet\Tests\Support\FakeEncounterRepository;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Unit tests for AppointmentFollowupService (T-14, Fase 7A): links the
 * follow-up appointment scheduled from EncounterView to its encounter,
 * only when both belong to the tenant and to the same patient.
 */
final class AppointmentFollowupServiceTest
{
    private const TENANT = 1;
    private const OTHER_TENANT = 2;
    private const USER = 7;

    public function testLinkRecordsFollowupWithContextUser(): void
    {
        [$service, $followups, $appointmentId, $encounterId] = $this->scenario(patientOfAppointment: 5, patientOfEncounter: 5);

        $service->link($appointmentId, $encounterId);

        Assert::true($followups->isFollowup($appointmentId));
        Assert::same(
            [$appointmentId => ['encounter_id' => $encounterId, 'created_by_system_user_id' => self::USER]],
            $followups->links(),
        );
    }

    public function testLinkRejectsDifferentPatients(): void
    {
        [$service, $followups, $appointmentId, $encounterId] = $this->scenario(patientOfAppointment: 5, patientOfEncounter: 6);

        $message = null;

        try {
            $service->link($appointmentId, $encounterId);
        } catch (InvalidArgumentException $e) {
            $message = $e->getMessage();
        }

        Assert::same("Appointment {$appointmentId} and encounter {$encounterId} belong to different patients", $message);
        Assert::same([], $followups->links());
    }

    public function testLinkRejectsAppointmentOfAnotherTenantWithoutRecording(): void
    {
        $context = TenantContext::authenticated(self::TENANT, self::USER, 1);
        $followups = new FakeAppointmentFollowupRepository(self::TENANT);
        $appointments = new FakeAppointmentRepository(self::TENANT);
        $foreign = $appointments->save($this->appointment(self::OTHER_TENANT, 5));
        $encounters = new FakeEncounterRepository(self::TENANT);
        $encounter = $encounters->save($this->encounter(self::TENANT, 5));

        $service = new AppointmentFollowupService($followups, $appointments, $encounters, $context);

        Assert::throws(
            CrossTenantReferenceException::class,
            static fn () => $service->link($foreign->id, $encounter->id()),
        );
        Assert::same([], $followups->links());
    }

    public function testLinkRejectsUnknownEncounterWithoutRecording(): void
    {
        [$service, $followups, $appointmentId] = $this->scenario(patientOfAppointment: 5, patientOfEncounter: 5);

        Assert::throws(
            CrossTenantReferenceException::class,
            static fn () => $service->link($appointmentId, 999),
        );
        Assert::same([], $followups->links());
    }

    /** @return array{0: AppointmentFollowupService, 1: FakeAppointmentFollowupRepository, 2: int, 3: int} */
    private function scenario(int $patientOfAppointment, int $patientOfEncounter): array
    {
        $context = TenantContext::authenticated(self::TENANT, self::USER, 1);
        $followups = new FakeAppointmentFollowupRepository(self::TENANT);
        $appointments = new FakeAppointmentRepository(self::TENANT);
        $appointment = $appointments->save($this->appointment(self::TENANT, $patientOfAppointment));
        $encounters = new FakeEncounterRepository(self::TENANT);
        $encounter = $encounters->save($this->encounter(self::TENANT, $patientOfEncounter));

        return [
            new AppointmentFollowupService($followups, $appointments, $encounters, $context),
            $followups,
            (int) $appointment->id,
            (int) $encounter->id(),
        ];
    }

    private function appointment(int $tenantId, int $patientId): Appointment
    {
        return new Appointment(
            id: null,
            tenantId: $tenantId,
            systemUnitId: 1,
            patientId: $patientId,
            serviceId: 1,
            professionalSystemUserId: self::USER,
            scheduledAt: new DateTimeImmutable('2026-10-20 09:00:00'),
        );
    }

    private function encounter(int $tenantId, int $patientId): Encounter
    {
        return Encounter::start($tenantId, 1, $patientId, null, self::USER, new DateTimeImmutable('2026-10-06 10:00:00'));
    }
}
