<?php

declare(strict_types=1);

namespace CentralVet\Domain;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * One entry of a surgery timeline (domain entity for the `surgery_event`
 * table, migration 20261005_0011_phase6b_surgery): clinical notes
 * (pre-op, anesthesia, intra-op, complication, post-op) or a system
 * record (scheduling, consent, checklist, status, material, cancellation,
 * completion, follow-up). Append-only — never updated.
 */
final class SurgeryEvent
{
    public const TYPE_PRE_OP = 'pre_op';
    public const TYPE_ANESTHESIA = 'anesthesia';
    public const TYPE_INTRA_OP = 'intra_op';
    public const TYPE_COMPLICATION = 'complication';
    public const TYPE_POST_OP = 'post_op';
    public const TYPE_SCHEDULED = 'scheduled';
    public const TYPE_CONSENT = 'consent';
    public const TYPE_CHECKLIST = 'checklist';
    public const TYPE_STATUS = 'status';
    public const TYPE_MATERIAL = 'material';
    public const TYPE_CANCELLATION = 'cancellation';
    public const TYPE_COMPLETION = 'completion';
    public const TYPE_FOLLOWUP = 'followup';

    public const CLINICAL_TYPES = [
        self::TYPE_PRE_OP,
        self::TYPE_ANESTHESIA,
        self::TYPE_INTRA_OP,
        self::TYPE_COMPLICATION,
        self::TYPE_POST_OP,
    ];

    private const TYPES = [
        ...self::CLINICAL_TYPES,
        self::TYPE_SCHEDULED,
        self::TYPE_CONSENT,
        self::TYPE_CHECKLIST,
        self::TYPE_STATUS,
        self::TYPE_MATERIAL,
        self::TYPE_CANCELLATION,
        self::TYPE_COMPLETION,
        self::TYPE_FOLLOWUP,
    ];

    private const NOTES_MAX_LENGTH = 5000;

    private function __construct(
        private ?int $id,
        private readonly int $tenantId,
        private readonly int $surgeryId,
        private readonly string $eventType,
        private readonly int $recordedBySystemUserId,
        private readonly DateTimeImmutable $recordedAt,
        private readonly ?string $notesText,
        private readonly ?DateTimeImmutable $createdAt = null,
    ) {
    }

    public static function record(
        int $tenantId,
        int $surgeryId,
        string $eventType,
        int $recordedBySystemUserId,
        DateTimeImmutable $recordedAt,
        ?string $notesText,
    ): self {
        if ($tenantId <= 0) {
            throw new InvalidArgumentException('Tenant id must be positive');
        }

        if ($surgeryId <= 0) {
            throw new InvalidArgumentException('surgery_id must be positive');
        }

        self::assertType($eventType);

        if ($recordedBySystemUserId <= 0) {
            throw new InvalidArgumentException('recorded_by_system_user_id must be positive');
        }

        $notesText = $notesText !== null ? trim($notesText) : '';

        if ($notesText === '' && in_array($eventType, self::CLINICAL_TYPES, true)) {
            throw new InvalidArgumentException('notes_text is required');
        }

        if (mb_strlen($notesText) > self::NOTES_MAX_LENGTH) {
            throw new InvalidArgumentException('notes_text must have at most 5000 characters');
        }

        return new self(
            id: null,
            tenantId: $tenantId,
            surgeryId: $surgeryId,
            eventType: $eventType,
            recordedBySystemUserId: $recordedBySystemUserId,
            recordedAt: $recordedAt,
            notesText: $notesText !== '' ? $notesText : null,
        );
    }

    /**
     * Rebuilds an existing event from persisted data.
     *
     * @param array<string, mixed> $row raw column values, keyed exactly like the `surgery_event` table.
     */
    public static function reconstitute(array $row): self
    {
        $eventType = (string) $row['event_type'];
        self::assertType($eventType);

        return new self(
            id: (int) $row['id'],
            tenantId: (int) $row['tenant_id'],
            surgeryId: (int) $row['surgery_id'],
            eventType: $eventType,
            recordedBySystemUserId: (int) $row['recorded_by_system_user_id'],
            recordedAt: new DateTimeImmutable((string) $row['recorded_at']),
            notesText: isset($row['notes_text']) ? (string) $row['notes_text'] : null,
            createdAt: isset($row['created_at']) ? new DateTimeImmutable((string) $row['created_at']) : null,
        );
    }

    private static function assertType(string $eventType): void
    {
        if (!in_array($eventType, self::TYPES, true)) {
            throw new InvalidArgumentException("Unknown surgery event type \"{$eventType}\"");
        }
    }

    public function assignId(int $id): void
    {
        if ($this->id !== null) {
            throw new InvalidArgumentException('SurgeryEvent already has an id');
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

    public function surgeryId(): int
    {
        return $this->surgeryId;
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

    public function createdAt(): ?DateTimeImmutable
    {
        return $this->createdAt;
    }
}
