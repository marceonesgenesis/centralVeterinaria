<?php

declare(strict_types=1);

namespace CentralVet\Domain;

use CentralVet\Domain\Exception\InvalidStatusTransitionException;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * One hospitalization of a patient, always opened from an encounter
 * (domain entity for the `hospitalization` table, migration
 * 20261005_0010_phase6a_hospitalization).
 *
 * Status machine: `admitted` -> `discharged` (terminal). The daily rate is
 * copied from the bed at admission and is not recalculated on transfer;
 * billable days are `max(1, ceil(hours / 24))` (see billableDays()).
 */
final class Hospitalization
{
    public const STATUS_ADMITTED = 'admitted';
    public const STATUS_DISCHARGED = 'discharged';

    private const STATUSES = [self::STATUS_ADMITTED, self::STATUS_DISCHARGED];

    private function __construct(
        private ?int $id,
        private readonly int $tenantId,
        private readonly int $systemUnitId,
        private readonly int $patientId,
        private readonly int $encounterId,
        private int $bedId,
        private readonly int $responsibleSystemUserId,
        private readonly int $admittedBySystemUserId,
        private readonly string $reasonText,
        private readonly ?DateTimeImmutable $expectedDischargeDate,
        private readonly int $dailyRateCents,
        private string $status,
        private readonly DateTimeImmutable $admittedAt,
        private ?DateTimeImmutable $dischargedAt,
        private ?int $dischargedBySystemUserId,
        private ?string $dischargeSummaryText,
        private readonly ?DateTimeImmutable $createdAt = null,
        private readonly ?DateTimeImmutable $updatedAt = null,
    ) {
    }

    public static function admit(
        int $tenantId,
        int $systemUnitId,
        int $patientId,
        int $encounterId,
        int $bedId,
        int $responsibleSystemUserId,
        int $admittedBySystemUserId,
        string $reasonText,
        ?DateTimeImmutable $expectedDischargeDate,
        int $dailyRateCents,
        DateTimeImmutable $admittedAt,
    ): self {
        foreach ([
            'Tenant id' => $tenantId,
            'system_unit_id' => $systemUnitId,
            'patient_id' => $patientId,
            'encounter_id' => $encounterId,
            'bed_id' => $bedId,
            'responsible_system_user_id' => $responsibleSystemUserId,
            'admitted_by_system_user_id' => $admittedBySystemUserId,
        ] as $field => $value) {
            if ($value <= 0) {
                throw new InvalidArgumentException("{$field} must be positive");
            }
        }

        $reasonText = trim($reasonText);

        if ($reasonText === '') {
            throw new InvalidArgumentException('reason_text is required');
        }

        if (mb_strlen($reasonText) > 500) {
            throw new InvalidArgumentException('reason_text must be at most 500 characters');
        }

        if ($dailyRateCents < 0) {
            throw new InvalidArgumentException('daily_rate_cents cannot be negative');
        }

        return new self(
            id: null,
            tenantId: $tenantId,
            systemUnitId: $systemUnitId,
            patientId: $patientId,
            encounterId: $encounterId,
            bedId: $bedId,
            responsibleSystemUserId: $responsibleSystemUserId,
            admittedBySystemUserId: $admittedBySystemUserId,
            reasonText: $reasonText,
            expectedDischargeDate: $expectedDischargeDate,
            dailyRateCents: $dailyRateCents,
            status: self::STATUS_ADMITTED,
            admittedAt: $admittedAt,
            dischargedAt: null,
            dischargedBySystemUserId: null,
            dischargeSummaryText: null,
        );
    }

    /**
     * Rebuilds an existing hospitalization from persisted data.
     *
     * @param array<string, mixed> $row raw column values, keyed exactly like the `hospitalization` table.
     */
    public static function reconstitute(array $row): self
    {
        $status = (string) $row['status'];

        if (!in_array($status, self::STATUSES, true)) {
            throw new InvalidArgumentException("Unknown hospitalization status \"{$status}\"");
        }

        return new self(
            id: (int) $row['id'],
            tenantId: (int) $row['tenant_id'],
            systemUnitId: (int) $row['system_unit_id'],
            patientId: (int) $row['patient_id'],
            encounterId: (int) $row['encounter_id'],
            bedId: (int) $row['bed_id'],
            responsibleSystemUserId: (int) $row['responsible_system_user_id'],
            admittedBySystemUserId: (int) $row['admitted_by_system_user_id'],
            reasonText: (string) $row['reason_text'],
            expectedDischargeDate: isset($row['expected_discharge_date'])
                ? new DateTimeImmutable((string) $row['expected_discharge_date'])
                : null,
            dailyRateCents: (int) $row['daily_rate_cents'],
            status: $status,
            admittedAt: new DateTimeImmutable((string) $row['admitted_at']),
            dischargedAt: isset($row['discharged_at']) ? new DateTimeImmutable((string) $row['discharged_at']) : null,
            dischargedBySystemUserId: isset($row['discharged_by_system_user_id'])
                ? (int) $row['discharged_by_system_user_id']
                : null,
            dischargeSummaryText: isset($row['discharge_summary_text']) ? (string) $row['discharge_summary_text'] : null,
            createdAt: isset($row['created_at']) ? new DateTimeImmutable((string) $row['created_at']) : null,
            updatedAt: isset($row['updated_at']) ? new DateTimeImmutable((string) $row['updated_at']) : null,
        );
    }

    public function assignId(int $id): void
    {
        if ($this->id !== null) {
            throw new InvalidArgumentException('Hospitalization already has an id');
        }

        if ($id <= 0) {
            throw new InvalidArgumentException('Id must be positive');
        }

        $this->id = $id;
    }

    /** Records a transfer to another bed (bed occupancy itself is the repository's job). */
    public function moveToBed(int $bedId): void
    {
        $this->assertAdmitted();

        if ($bedId <= 0) {
            throw new InvalidArgumentException('bed_id must be positive');
        }

        $this->bedId = $bedId;
    }

    public function discharge(DateTimeImmutable $at, int $dischargedBySystemUserId, string $summaryText): void
    {
        $this->assertAdmitted();

        if ($dischargedBySystemUserId <= 0) {
            throw new InvalidArgumentException('discharged_by_system_user_id must be positive');
        }

        $summaryText = trim($summaryText);

        $this->status = self::STATUS_DISCHARGED;
        $this->dischargedAt = $at;
        $this->dischargedBySystemUserId = $dischargedBySystemUserId;
        $this->dischargeSummaryText = $summaryText !== '' ? $summaryText : null;
    }

    /** Started 24h periods since admission, never less than 1. */
    public function billableDays(DateTimeImmutable $until): int
    {
        $seconds = $until->getTimestamp() - $this->admittedAt->getTimestamp();

        return max(1, (int) ceil($seconds / 86400));
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function tenantId(): int
    {
        return $this->tenantId;
    }

    public function systemUnitId(): int
    {
        return $this->systemUnitId;
    }

    public function patientId(): int
    {
        return $this->patientId;
    }

    public function encounterId(): int
    {
        return $this->encounterId;
    }

    public function bedId(): int
    {
        return $this->bedId;
    }

    public function responsibleSystemUserId(): int
    {
        return $this->responsibleSystemUserId;
    }

    public function admittedBySystemUserId(): int
    {
        return $this->admittedBySystemUserId;
    }

    public function reasonText(): string
    {
        return $this->reasonText;
    }

    public function expectedDischargeDate(): ?DateTimeImmutable
    {
        return $this->expectedDischargeDate;
    }

    public function dailyRateCents(): int
    {
        return $this->dailyRateCents;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function admittedAt(): DateTimeImmutable
    {
        return $this->admittedAt;
    }

    public function dischargedAt(): ?DateTimeImmutable
    {
        return $this->dischargedAt;
    }

    public function dischargedBySystemUserId(): ?int
    {
        return $this->dischargedBySystemUserId;
    }

    public function dischargeSummaryText(): ?string
    {
        return $this->dischargeSummaryText;
    }

    public function createdAt(): ?DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): ?DateTimeImmutable
    {
        return $this->updatedAt;
    }

    private function assertAdmitted(): void
    {
        if ($this->status !== self::STATUS_ADMITTED) {
            throw new InvalidStatusTransitionException("Hospitalization {$this->id} is not admitted");
        }
    }
}
