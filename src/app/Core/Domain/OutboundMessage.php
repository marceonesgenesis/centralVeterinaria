<?php

declare(strict_types=1);

namespace CentralVet\Domain;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * One outbound message and its delivery state (domain entity for the
 * `communication_message` table, migration
 * 20261006_0012_phase7a_communication).
 *
 * The entity is born `queued`; every later transition (claim, sent,
 * failed, manual, cancelled, requeue) is a conditional UPDATE in
 * `OutboundMessageRepositoryInterface`, never a full save, so concurrent
 * workers and attendants cannot overwrite each other. Recipient, subject
 * and body are personal data: they never go into exception messages.
 */
final class OutboundMessage
{
    public const STATUS_QUEUED = 'queued';
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';
    public const STATUS_MANUAL = 'manual';
    public const STATUS_CANCELLED = 'cancelled';

    public const ORIGIN_AUTOMATION = 'automation';
    public const ORIGIN_MANUAL = 'manual';

    public const SOURCE_APPOINTMENT = 'appointment';
    public const SOURCE_VACCINATION = 'vaccination';
    public const SOURCE_RECEIVABLE = 'receivable';

    private const STATUSES = [
        self::STATUS_QUEUED,
        self::STATUS_SENT,
        self::STATUS_FAILED,
        self::STATUS_MANUAL,
        self::STATUS_CANCELLED,
    ];

    private const ORIGINS = [self::ORIGIN_AUTOMATION, self::ORIGIN_MANUAL];

    private const SOURCE_TYPES = [self::SOURCE_APPOINTMENT, self::SOURCE_VACCINATION, self::SOURCE_RECEIVABLE];

    private function __construct(
        private ?int $id,
        private readonly int $tenantId,
        private readonly int $systemUnitId,
        private readonly int $tutorId,
        private readonly ?int $patientId,
        private readonly ?int $templateId,
        private readonly string $purpose,
        private readonly string $channel,
        private readonly string $origin,
        private readonly string $legalBasis,
        private readonly ?string $sourceType,
        private readonly ?int $sourceId,
        private readonly ?string $dedupeKey,
        private readonly string $recipient,
        private readonly ?string $subject,
        private readonly string $bodyText,
        private readonly string $status,
        private readonly int $attemptCount,
        private readonly ?string $lastErrorCode,
        private readonly ?string $provider,
        private readonly ?string $providerMessageId,
        private readonly ?DateTimeImmutable $claimedAt,
        private readonly ?DateTimeImmutable $sentAt,
        private readonly ?DateTimeImmutable $failedAt,
        private readonly ?DateTimeImmutable $cancelledAt,
        private readonly ?int $manualSentBySystemUserId,
        private readonly ?int $cancelledBySystemUserId,
        private readonly ?int $createdBySystemUserId,
        private readonly ?DateTimeImmutable $createdAt,
        private readonly ?DateTimeImmutable $updatedAt,
    ) {
    }

    public static function compose(
        int $tenantId,
        int $systemUnitId,
        int $tutorId,
        ?int $patientId,
        ?int $templateId,
        string $purpose,
        string $channel,
        string $origin,
        string $legalBasis,
        ?string $sourceType,
        ?int $sourceId,
        ?string $dedupeKey,
        string $recipient,
        ?string $subject,
        string $bodyText,
        ?int $createdBySystemUserId,
    ): self {
        if ($tenantId <= 0) {
            throw new InvalidArgumentException('Tenant id must be positive');
        }

        if ($systemUnitId <= 0) {
            throw new InvalidArgumentException('system_unit_id must be positive');
        }

        if ($tutorId <= 0) {
            throw new InvalidArgumentException('tutor_id must be positive');
        }

        foreach (['patient_id' => $patientId, 'template_id' => $templateId, 'created_by_system_user_id' => $createdBySystemUserId] as $field => $value) {
            if ($value !== null && $value <= 0) {
                throw new InvalidArgumentException("{$field} must be positive");
            }
        }

        MessagePurpose::assertValid($purpose);
        CommunicationChannel::assertValid($channel);
        MessagePurpose::assertValidLegalBasis($legalBasis);

        if (!in_array($origin, self::ORIGINS, true)) {
            throw new InvalidArgumentException("Unknown message origin \"{$origin}\"");
        }

        if ($sourceType !== null && !in_array($sourceType, self::SOURCE_TYPES, true)) {
            throw new InvalidArgumentException("Unknown message source type \"{$sourceType}\"");
        }

        if (($sourceType === null) !== ($sourceId === null) || ($sourceId !== null && $sourceId <= 0)) {
            throw new InvalidArgumentException('source_type and a positive source_id must be given together');
        }

        if ($dedupeKey !== null && ($dedupeKey === '' || strlen($dedupeKey) > 120)) {
            throw new InvalidArgumentException('dedupe_key must be between 1 and 120 characters');
        }

        $recipient = trim($recipient);

        if ($recipient === '' || mb_strlen($recipient) > 190) {
            throw new InvalidArgumentException('recipient must be between 1 and 190 characters');
        }

        if ($channel === CommunicationChannel::EMAIL) {
            $subject = trim((string) $subject);

            if ($subject === '' || mb_strlen($subject) > 190) {
                throw new InvalidArgumentException('subject must be between 1 and 190 characters for email messages');
            }
        } elseif ($subject !== null) {
            $subject = trim($subject);
            $subject = $subject === '' ? null : mb_substr($subject, 0, 190);
        }

        if (trim($bodyText) === '') {
            throw new InvalidArgumentException('body must not be empty');
        }

        return new self(
            id: null,
            tenantId: $tenantId,
            systemUnitId: $systemUnitId,
            tutorId: $tutorId,
            patientId: $patientId,
            templateId: $templateId,
            purpose: $purpose,
            channel: $channel,
            origin: $origin,
            legalBasis: $legalBasis,
            sourceType: $sourceType,
            sourceId: $sourceId,
            dedupeKey: $dedupeKey,
            recipient: $recipient,
            subject: $subject,
            bodyText: $bodyText,
            status: self::STATUS_QUEUED,
            attemptCount: 0,
            lastErrorCode: null,
            provider: null,
            providerMessageId: null,
            claimedAt: null,
            sentAt: null,
            failedAt: null,
            cancelledAt: null,
            manualSentBySystemUserId: null,
            cancelledBySystemUserId: null,
            createdBySystemUserId: $createdBySystemUserId,
            createdAt: null,
            updatedAt: null,
        );
    }

    /** Idempotency key `<purpose>:<sourceType>:<sourceId>:<channel>`, unique per tenant. */
    public static function buildDedupeKey(string $purpose, string $sourceType, int $sourceId, string $channel): string
    {
        return "{$purpose}:{$sourceType}:{$sourceId}:{$channel}";
    }

    /**
     * Rebuilds a message from persisted data.
     *
     * @param array<string, mixed> $row raw column values, keyed exactly like the `communication_message` table.
     */
    public static function reconstitute(array $row): self
    {
        $status = (string) $row['status'];

        if (!in_array($status, self::STATUSES, true)) {
            throw new InvalidArgumentException("Unknown message status \"{$status}\"");
        }

        return new self(
            id: (int) $row['id'],
            tenantId: (int) $row['tenant_id'],
            systemUnitId: (int) $row['system_unit_id'],
            tutorId: (int) $row['tutor_id'],
            patientId: self::nullableInt($row, 'patient_id'),
            templateId: self::nullableInt($row, 'template_id'),
            purpose: (string) $row['purpose'],
            channel: (string) $row['channel'],
            origin: (string) $row['origin'],
            legalBasis: (string) $row['legal_basis'],
            sourceType: self::nullableString($row, 'source_type'),
            sourceId: self::nullableInt($row, 'source_id'),
            dedupeKey: self::nullableString($row, 'dedupe_key'),
            recipient: (string) $row['recipient'],
            subject: self::nullableString($row, 'subject'),
            bodyText: (string) $row['body_text'],
            status: $status,
            attemptCount: (int) ($row['attempt_count'] ?? 0),
            lastErrorCode: self::nullableString($row, 'last_error_code'),
            provider: self::nullableString($row, 'provider'),
            providerMessageId: self::nullableString($row, 'provider_message_id'),
            claimedAt: self::nullableDate($row, 'claimed_at'),
            sentAt: self::nullableDate($row, 'sent_at'),
            failedAt: self::nullableDate($row, 'failed_at'),
            cancelledAt: self::nullableDate($row, 'cancelled_at'),
            manualSentBySystemUserId: self::nullableInt($row, 'manual_sent_by_system_user_id'),
            cancelledBySystemUserId: self::nullableInt($row, 'cancelled_by_system_user_id'),
            createdBySystemUserId: self::nullableInt($row, 'created_by_system_user_id'),
            createdAt: self::nullableDate($row, 'created_at'),
            updatedAt: self::nullableDate($row, 'updated_at'),
        );
    }

    public function assignId(int $id): void
    {
        if ($this->id !== null) {
            throw new InvalidArgumentException('Outbound message already has an id');
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

    public function systemUnitId(): int
    {
        return $this->systemUnitId;
    }

    public function tutorId(): int
    {
        return $this->tutorId;
    }

    public function patientId(): ?int
    {
        return $this->patientId;
    }

    public function templateId(): ?int
    {
        return $this->templateId;
    }

    public function purpose(): string
    {
        return $this->purpose;
    }

    public function channel(): string
    {
        return $this->channel;
    }

    public function origin(): string
    {
        return $this->origin;
    }

    public function legalBasis(): string
    {
        return $this->legalBasis;
    }

    public function sourceType(): ?string
    {
        return $this->sourceType;
    }

    public function sourceId(): ?int
    {
        return $this->sourceId;
    }

    public function dedupeKey(): ?string
    {
        return $this->dedupeKey;
    }

    public function recipient(): string
    {
        return $this->recipient;
    }

    public function subject(): ?string
    {
        return $this->subject;
    }

    public function bodyText(): string
    {
        return $this->bodyText;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function attemptCount(): int
    {
        return $this->attemptCount;
    }

    public function lastErrorCode(): ?string
    {
        return $this->lastErrorCode;
    }

    public function provider(): ?string
    {
        return $this->provider;
    }

    public function providerMessageId(): ?string
    {
        return $this->providerMessageId;
    }

    public function claimedAt(): ?DateTimeImmutable
    {
        return $this->claimedAt;
    }

    public function sentAt(): ?DateTimeImmutable
    {
        return $this->sentAt;
    }

    public function failedAt(): ?DateTimeImmutable
    {
        return $this->failedAt;
    }

    public function cancelledAt(): ?DateTimeImmutable
    {
        return $this->cancelledAt;
    }

    public function manualSentBySystemUserId(): ?int
    {
        return $this->manualSentBySystemUserId;
    }

    public function cancelledBySystemUserId(): ?int
    {
        return $this->cancelledBySystemUserId;
    }

    public function createdBySystemUserId(): ?int
    {
        return $this->createdBySystemUserId;
    }

    public function createdAt(): ?DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): ?DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /** @param array<string, mixed> $row */
    private static function nullableInt(array $row, string $key): ?int
    {
        return isset($row[$key]) ? (int) $row[$key] : null;
    }

    /** @param array<string, mixed> $row */
    private static function nullableString(array $row, string $key): ?string
    {
        return isset($row[$key]) ? (string) $row[$key] : null;
    }

    /** @param array<string, mixed> $row */
    private static function nullableDate(array $row, string $key): ?DateTimeImmutable
    {
        return isset($row[$key]) ? new DateTimeImmutable((string) $row[$key]) : null;
    }
}
