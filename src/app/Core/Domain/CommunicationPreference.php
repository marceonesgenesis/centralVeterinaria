<?php

declare(strict_types=1);

namespace CentralVet\Domain;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * A tutor's opt-in/opt-out on one communication channel (domain entity for
 * the `communication_preference` table, migration
 * 20261006_0012_phase7a_communication). One row per tenant, tutor and
 * channel; a channel without a row means "no explicit choice".
 *
 * `permitsSending()` is the single LGPD rule used by composing, reminder
 * generation and delivery: an opt-out always blocks; `consent` purposes
 * need an explicit opt-in; `legitimate_interest` purposes go out unless
 * opted out.
 */
final class CommunicationPreference
{
    public const STATUS_OPTED_IN = 'opted_in';
    public const STATUS_OPTED_OUT = 'opted_out';

    public const SOURCES = ['in_person', 'phone', 'written', 'online'];

    private const STATUSES = [self::STATUS_OPTED_IN, self::STATUS_OPTED_OUT];

    private function __construct(
        private ?int $id,
        private readonly int $tenantId,
        private readonly int $tutorId,
        private readonly string $channel,
        private readonly string $status,
        private readonly string $consentSource,
        private readonly int $changedBySystemUserId,
        private readonly DateTimeImmutable $changedAt,
        private readonly ?DateTimeImmutable $createdAt = null,
        private readonly ?DateTimeImmutable $updatedAt = null,
    ) {
    }

    public static function record(
        int $tenantId,
        int $tutorId,
        string $channel,
        string $status,
        string $consentSource,
        int $changedBySystemUserId,
        DateTimeImmutable $changedAt,
    ): self {
        if ($tenantId <= 0) {
            throw new InvalidArgumentException('Tenant id must be positive');
        }

        if ($tutorId <= 0) {
            throw new InvalidArgumentException('tutor_id must be positive');
        }

        if ($changedBySystemUserId <= 0) {
            throw new InvalidArgumentException('changed_by_system_user_id must be positive');
        }

        self::assertValues($channel, $status, $consentSource);

        return new self(
            id: null,
            tenantId: $tenantId,
            tutorId: $tutorId,
            channel: $channel,
            status: $status,
            consentSource: $consentSource,
            changedBySystemUserId: $changedBySystemUserId,
            changedAt: $changedAt,
        );
    }

    /**
     * Rebuilds a preference from persisted data.
     *
     * @param array<string, mixed> $row raw column values, keyed exactly like the `communication_preference` table.
     */
    public static function reconstitute(array $row): self
    {
        $channel = (string) $row['channel'];
        $status = (string) $row['status'];
        $consentSource = (string) $row['consent_source'];
        self::assertValues($channel, $status, $consentSource);

        return new self(
            id: (int) $row['id'],
            tenantId: (int) $row['tenant_id'],
            tutorId: (int) $row['tutor_id'],
            channel: $channel,
            status: $status,
            consentSource: $consentSource,
            changedBySystemUserId: (int) $row['changed_by_system_user_id'],
            changedAt: new DateTimeImmutable((string) $row['changed_at']),
            createdAt: isset($row['created_at']) ? new DateTimeImmutable((string) $row['created_at']) : null,
            updatedAt: isset($row['updated_at']) ? new DateTimeImmutable((string) $row['updated_at']) : null,
        );
    }

    public static function permitsSending(?self $preference, string $legalBasis): bool
    {
        MessagePurpose::assertValidLegalBasis($legalBasis);

        if ($preference !== null && !$preference->isOptedIn()) {
            return false;
        }

        if ($legalBasis === MessagePurpose::LEGAL_BASIS_CONSENT) {
            return $preference !== null;
        }

        return true;
    }

    public function assignId(int $id): void
    {
        if ($this->id !== null) {
            throw new InvalidArgumentException('Communication preference already has an id');
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

    public function tutorId(): int
    {
        return $this->tutorId;
    }

    public function channel(): string
    {
        return $this->channel;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function consentSource(): string
    {
        return $this->consentSource;
    }

    public function changedBySystemUserId(): int
    {
        return $this->changedBySystemUserId;
    }

    public function changedAt(): DateTimeImmutable
    {
        return $this->changedAt;
    }

    public function isOptedIn(): bool
    {
        return $this->status === self::STATUS_OPTED_IN;
    }

    public function createdAt(): ?DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): ?DateTimeImmutable
    {
        return $this->updatedAt;
    }

    private static function assertValues(string $channel, string $status, string $consentSource): void
    {
        CommunicationChannel::assertValid($channel);

        if (!in_array($status, self::STATUSES, true)) {
            throw new InvalidArgumentException("Unknown communication preference status \"{$status}\"");
        }

        if (!in_array($consentSource, self::SOURCES, true)) {
            throw new InvalidArgumentException("Unknown consent source \"{$consentSource}\"");
        }
    }
}
