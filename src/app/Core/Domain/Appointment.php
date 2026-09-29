<?php

declare(strict_types=1);

namespace CentralVet\Domain;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Domain entity for a clinic appointment, scoped to a tenant. Maps 1:1 to
 * the `appointment` table.
 *
 * PENDING / DO NOT WIRE YET: `appointment` is created by the not-yet-applied
 * migration src/app/database/migrations/20260921_0002_phase1_clinic_core.sql
 * (ADR 0003). This class is prepared and syntax-checked (php -l) only; no
 * query runs against it until that migration has explicit SQL execution
 * approval and has actually been applied.
 *
 * Plain PHP value object: no Adianti dependency (ADR 0001).
 *
 * Note: this entity intentionally has no `duration_minutes`/`ends_at` of its
 * own — the `appointment` table does not store one (see the migration). The
 * duration always comes from the referenced Service (T-06), which is why
 * endsAt() takes it as a parameter instead of reading a property.
 */
final class Appointment
{
    public const STATUS_SCHEDULED = 'agendado';
    public const STATUS_CONFIRMED = 'confirmado';
    public const STATUS_IN_PROGRESS = 'em_atendimento';
    public const STATUS_DONE = 'atendido';
    public const STATUS_CANCELLED = 'cancelado';
    public const STATUS_NO_SHOW = 'faltou';

    /** Mirrors the `appointment_status_ck` CHECK constraint in the migration. */
    private const VALID_STATUSES = [
        self::STATUS_SCHEDULED,
        self::STATUS_CONFIRMED,
        self::STATUS_IN_PROGRESS,
        self::STATUS_DONE,
        self::STATUS_CANCELLED,
        self::STATUS_NO_SHOW,
    ];

    public function __construct(
        public readonly ?int $id,
        public readonly int $tenantId,
        public readonly int $systemUnitId,
        public readonly int $patientId,
        public readonly int $serviceId,
        public readonly int $professionalSystemUserId,
        public readonly DateTimeImmutable $scheduledAt,
        public readonly string $status = self::STATUS_SCHEDULED,
        public readonly ?string $createdAt = null,
        public readonly ?string $updatedAt = null,
    ) {
        if (!in_array($this->status, self::VALID_STATUSES, true)) {
            throw new InvalidArgumentException(
                'Appointment status must be one of: ' . implode(', ', self::VALID_STATUSES)
            );
        }
    }

    /**
     * Computes the end of this appointment's scheduled window, given the
     * duration (in minutes) of the Service it references. Callers are
     * expected to look that duration up via ServiceRepositoryInterface
     * (see AppointmentService::schedule()).
     */
    public function endsAt(int $serviceDurationMinutes): DateTimeImmutable
    {
        if ($serviceDurationMinutes <= 0) {
            throw new InvalidArgumentException('Service duration must be a positive number of minutes');
        }

        return $this->scheduledAt->modify("+{$serviceDurationMinutes} minutes");
    }

    /**
     * True when [this appointment's window, given its service duration]
     * overlaps [otherStart, otherEnd). Half-open intervals: back-to-back
     * appointments (one ends exactly when the other starts) do not overlap.
     */
    public function overlaps(int $serviceDurationMinutes, DateTimeImmutable $otherStart, DateTimeImmutable $otherEnd): bool
    {
        $thisEnd = $this->endsAt($serviceDurationMinutes);

        return $this->scheduledAt < $otherEnd && $otherStart < $thisEnd;
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            id: (int) $row['id'],
            tenantId: (int) $row['tenant_id'],
            systemUnitId: (int) $row['system_unit_id'],
            patientId: (int) $row['patient_id'],
            serviceId: (int) $row['service_id'],
            professionalSystemUserId: (int) $row['professional_system_user_id'],
            scheduledAt: new DateTimeImmutable((string) $row['scheduled_at']),
            status: (string) $row['status'],
            createdAt: isset($row['created_at']) ? (string) $row['created_at'] : null,
            updatedAt: isset($row['updated_at']) ? (string) $row['updated_at'] : null,
        );
    }

    /** Returns a copy carrying the id assigned by persistence (e.g. after INSERT). */
    public function withId(int $id): self
    {
        return new self(
            id: $id,
            tenantId: $this->tenantId,
            systemUnitId: $this->systemUnitId,
            patientId: $this->patientId,
            serviceId: $this->serviceId,
            professionalSystemUserId: $this->professionalSystemUserId,
            scheduledAt: $this->scheduledAt,
            status: $this->status,
            createdAt: $this->createdAt,
            updatedAt: $this->updatedAt,
        );
    }
}
