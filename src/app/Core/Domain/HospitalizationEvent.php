<?php

declare(strict_types=1);

namespace CentralVet\Domain;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * One entry of a hospitalization timeline (domain entity for the
 * `hospitalization_event` table, migration
 * 20261005_0010_phase6a_hospitalization): admission, transfer, clinical
 * evolution, vital signs or discharge. Append-only — never updated.
 */
final class HospitalizationEvent
{
    public const TYPE_ADMISSION = 'admission';
    public const TYPE_TRANSFER = 'transfer';
    public const TYPE_EVOLUTION = 'evolution';
    public const TYPE_VITALS = 'vitals';
    public const TYPE_DISCHARGE = 'discharge';

    private const TYPES = [
        self::TYPE_ADMISSION,
        self::TYPE_TRANSFER,
        self::TYPE_EVOLUTION,
        self::TYPE_VITALS,
        self::TYPE_DISCHARGE,
    ];

    private function __construct(
        private ?int $id,
        private readonly int $tenantId,
        private readonly int $hospitalizationId,
        private readonly string $eventType,
        private readonly int $recordedBySystemUserId,
        private readonly DateTimeImmutable $recordedAt,
        private readonly ?string $notesText,
        private readonly ?float $temperatureC,
        private readonly ?int $heartRateBpm,
        private readonly ?int $respiratoryRateRpm,
        private readonly ?float $weightKg,
        private readonly ?int $painScore,
        private readonly ?int $fromBedId,
        private readonly ?int $toBedId,
        private readonly ?DateTimeImmutable $createdAt = null,
    ) {
    }

    public static function record(
        int $tenantId,
        int $hospitalizationId,
        string $eventType,
        int $recordedBySystemUserId,
        DateTimeImmutable $recordedAt,
        string $notesText,
        ?float $temperatureC = null,
        ?int $heartRateBpm = null,
        ?int $respiratoryRateRpm = null,
        ?float $weightKg = null,
        ?int $painScore = null,
        ?int $fromBedId = null,
        ?int $toBedId = null,
    ): self {
        if ($tenantId <= 0) {
            throw new InvalidArgumentException('Tenant id must be positive');
        }

        if ($hospitalizationId <= 0) {
            throw new InvalidArgumentException('hospitalization_id must be positive');
        }

        if (!in_array($eventType, self::TYPES, true)) {
            throw new InvalidArgumentException('event_type must be one of: ' . implode(', ', self::TYPES));
        }

        if ($recordedBySystemUserId <= 0) {
            throw new InvalidArgumentException('recorded_by_system_user_id must be positive');
        }

        $notesText = trim($notesText);

        if ($eventType === self::TYPE_EVOLUTION && $notesText === '') {
            throw new InvalidArgumentException('notes_text is required');
        }

        if (
            $eventType === self::TYPE_VITALS
            && $temperatureC === null
            && $heartRateBpm === null
            && $respiratoryRateRpm === null
            && $weightKg === null
            && $painScore === null
        ) {
            throw new InvalidArgumentException('At least one vital sign is required');
        }

        if ($painScore !== null && ($painScore < 0 || $painScore > 10)) {
            throw new InvalidArgumentException('pain_score must be between 0 and 10');
        }

        foreach ([
            'temperature_c' => $temperatureC,
            'heart_rate_bpm' => $heartRateBpm,
            'respiratory_rate_rpm' => $respiratoryRateRpm,
            'weight_kg' => $weightKg,
        ] as $field => $value) {
            if ($value !== null && $value < 0) {
                throw new InvalidArgumentException("{$field} cannot be negative");
            }
        }

        if ($eventType === self::TYPE_TRANSFER) {
            if ($fromBedId === null || $fromBedId <= 0 || $toBedId === null || $toBedId <= 0) {
                throw new InvalidArgumentException('from_bed_id and to_bed_id are required for a transfer');
            }

            if ($fromBedId === $toBedId) {
                throw new InvalidArgumentException('to_bed_id must differ from from_bed_id');
            }
        }

        return new self(
            id: null,
            tenantId: $tenantId,
            hospitalizationId: $hospitalizationId,
            eventType: $eventType,
            recordedBySystemUserId: $recordedBySystemUserId,
            recordedAt: $recordedAt,
            notesText: $notesText !== '' ? $notesText : null,
            temperatureC: $temperatureC,
            heartRateBpm: $heartRateBpm,
            respiratoryRateRpm: $respiratoryRateRpm,
            weightKg: $weightKg,
            painScore: $painScore,
            fromBedId: $fromBedId,
            toBedId: $toBedId,
        );
    }

    /**
     * Rebuilds an existing event from persisted data.
     *
     * @param array<string, mixed> $row raw column values, keyed exactly like the `hospitalization_event` table.
     */
    public static function reconstitute(array $row): self
    {
        $eventType = (string) $row['event_type'];

        if (!in_array($eventType, self::TYPES, true)) {
            throw new InvalidArgumentException("Unknown event_type \"{$eventType}\"");
        }

        $float = static fn (string $key): ?float => isset($row[$key]) ? (float) $row[$key] : null;
        $int = static fn (string $key): ?int => isset($row[$key]) ? (int) $row[$key] : null;

        return new self(
            id: (int) $row['id'],
            tenantId: (int) $row['tenant_id'],
            hospitalizationId: (int) $row['hospitalization_id'],
            eventType: $eventType,
            recordedBySystemUserId: (int) $row['recorded_by_system_user_id'],
            recordedAt: new DateTimeImmutable((string) $row['recorded_at']),
            notesText: isset($row['notes_text']) ? (string) $row['notes_text'] : null,
            temperatureC: $float('temperature_c'),
            heartRateBpm: $int('heart_rate_bpm'),
            respiratoryRateRpm: $int('respiratory_rate_rpm'),
            weightKg: $float('weight_kg'),
            painScore: $int('pain_score'),
            fromBedId: $int('from_bed_id'),
            toBedId: $int('to_bed_id'),
            createdAt: isset($row['created_at']) ? new DateTimeImmutable((string) $row['created_at']) : null,
        );
    }

    public function assignId(int $id): void
    {
        if ($this->id !== null) {
            throw new InvalidArgumentException('HospitalizationEvent already has an id');
        }

        if ($id <= 0) {
            throw new InvalidArgumentException('Id must be positive');
        }

        $this->id = $id;
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function tenantId(): int
    {
        return $this->tenantId;
    }

    public function hospitalizationId(): int
    {
        return $this->hospitalizationId;
    }

    public function eventType(): string
    {
        return $this->eventType;
    }

    public function recordedBySystemUserId(): int
    {
        return $this->recordedBySystemUserId;
    }

    public function recordedAt(): DateTimeImmutable
    {
        return $this->recordedAt;
    }

    public function notesText(): ?string
    {
        return $this->notesText;
    }

    public function temperatureC(): ?float
    {
        return $this->temperatureC;
    }

    public function heartRateBpm(): ?int
    {
        return $this->heartRateBpm;
    }

    public function respiratoryRateRpm(): ?int
    {
        return $this->respiratoryRateRpm;
    }

    public function weightKg(): ?float
    {
        return $this->weightKg;
    }

    public function painScore(): ?int
    {
        return $this->painScore;
    }

    public function fromBedId(): ?int
    {
        return $this->fromBedId;
    }

    public function toBedId(): ?int
    {
        return $this->toBedId;
    }

    public function createdAt(): ?DateTimeImmutable
    {
        return $this->createdAt;
    }
}
