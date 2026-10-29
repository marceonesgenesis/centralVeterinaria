<?php

declare(strict_types=1);

namespace CentralVet\Domain;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * A tenant's plain-text message template for one purpose and channel
 * (domain entity for the `message_template` table, migration
 * 20261006_0012_phase7a_communication).
 *
 * Name 1..120 chars; subject required on e-mail (up to 190) and ignored
 * (stored as null) on WhatsApp; body 1..2000 chars; subject and body may
 * only use `MessageTemplateRenderer::PLACEHOLDERS`. "One active template per
 * purpose and channel" is enforced by the service through
 * `MessageTemplateRepositoryInterface::countActiveFor()`.
 */
final class MessageTemplate
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';

    private const STATUSES = [self::STATUS_ACTIVE, self::STATUS_INACTIVE];

    private function __construct(
        private ?int $id,
        private readonly int $tenantId,
        private string $purpose,
        private string $channel,
        private string $name,
        private ?string $subject,
        private string $bodyText,
        private string $status,
        private readonly int $createdBySystemUserId,
        private ?int $updatedBySystemUserId = null,
        private readonly ?DateTimeImmutable $createdAt = null,
        private readonly ?DateTimeImmutable $updatedAt = null,
    ) {
    }

    public static function create(
        int $tenantId,
        string $purpose,
        string $channel,
        string $name,
        ?string $subject,
        string $bodyText,
        int $createdBySystemUserId,
    ): self {
        if ($tenantId <= 0) {
            throw new InvalidArgumentException('Tenant id must be positive');
        }

        if ($createdBySystemUserId <= 0) {
            throw new InvalidArgumentException('created_by_system_user_id must be positive');
        }

        [$purpose, $channel, $name, $subject, $bodyText] = self::normalize($purpose, $channel, $name, $subject, $bodyText);

        return new self(
            id: null,
            tenantId: $tenantId,
            purpose: $purpose,
            channel: $channel,
            name: $name,
            subject: $subject,
            bodyText: $bodyText,
            status: self::STATUS_ACTIVE,
            createdBySystemUserId: $createdBySystemUserId,
        );
    }

    /**
     * Rebuilds a template from persisted data.
     *
     * @param array<string, mixed> $row raw column values, keyed exactly like the `message_template` table.
     */
    public static function reconstitute(array $row): self
    {
        $status = (string) $row['status'];

        if (!in_array($status, self::STATUSES, true)) {
            throw new InvalidArgumentException("Unknown message template status \"{$status}\"");
        }

        return new self(
            id: (int) $row['id'],
            tenantId: (int) $row['tenant_id'],
            purpose: (string) $row['purpose'],
            channel: (string) $row['channel'],
            name: (string) $row['name'],
            subject: isset($row['subject']) ? (string) $row['subject'] : null,
            bodyText: (string) $row['body_text'],
            status: $status,
            createdBySystemUserId: (int) $row['created_by_system_user_id'],
            updatedBySystemUserId: isset($row['updated_by_system_user_id']) ? (int) $row['updated_by_system_user_id'] : null,
            createdAt: isset($row['created_at']) ? new DateTimeImmutable((string) $row['created_at']) : null,
            updatedAt: isset($row['updated_at']) ? new DateTimeImmutable((string) $row['updated_at']) : null,
        );
    }

    public function update(
        string $purpose,
        string $channel,
        string $name,
        ?string $subject,
        string $bodyText,
        int $updatedBySystemUserId,
    ): void {
        if ($updatedBySystemUserId <= 0) {
            throw new InvalidArgumentException('updated_by_system_user_id must be positive');
        }

        [$purpose, $channel, $name, $subject, $bodyText] = self::normalize($purpose, $channel, $name, $subject, $bodyText);

        $this->purpose = $purpose;
        $this->channel = $channel;
        $this->name = $name;
        $this->subject = $subject;
        $this->bodyText = $bodyText;
        $this->updatedBySystemUserId = $updatedBySystemUserId;
    }

    public function assignId(int $id): void
    {
        if ($this->id !== null) {
            throw new InvalidArgumentException('Message template already has an id');
        }

        if ($id <= 0) {
            throw new InvalidArgumentException('Id must be positive');
        }

        $this->id = $id;
    }

    public function activate(): void
    {
        $this->status = self::STATUS_ACTIVE;
    }

    public function deactivate(): void
    {
        $this->status = self::STATUS_INACTIVE;
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function tenantId(): int
    {
        return $this->tenantId;
    }

    public function purpose(): string
    {
        return $this->purpose;
    }

    public function channel(): string
    {
        return $this->channel;
    }

    public function name(): string
    {
        return $this->name;
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

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function createdBySystemUserId(): int
    {
        return $this->createdBySystemUserId;
    }

    public function updatedBySystemUserId(): ?int
    {
        return $this->updatedBySystemUserId;
    }

    public function createdAt(): ?DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): ?DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /** @return array{0: string, 1: string, 2: string, 3: ?string, 4: string} */
    private static function normalize(string $purpose, string $channel, string $name, ?string $subject, string $bodyText): array
    {
        MessagePurpose::assertValid($purpose);
        CommunicationChannel::assertValid($channel);

        $name = trim($name);
        $nameLength = mb_strlen($name);

        if ($nameLength < 1 || $nameLength > 120) {
            throw new InvalidArgumentException('name must be between 1 and 120 characters');
        }

        if ($channel === CommunicationChannel::EMAIL) {
            $subject = trim((string) $subject);

            if ($subject === '') {
                throw new InvalidArgumentException('subject is required for email templates');
            }

            if (mb_strlen($subject) > 190) {
                throw new InvalidArgumentException('subject must be at most 190 characters');
            }

            MessageTemplateRenderer::assertKnownPlaceholders($subject);
        } else {
            $subject = null;
        }

        $bodyText = trim($bodyText);
        $bodyLength = mb_strlen($bodyText);

        if ($bodyLength < 1 || $bodyLength > 2000) {
            throw new InvalidArgumentException('body must be between 1 and 2000 characters');
        }

        MessageTemplateRenderer::assertKnownPlaceholders($bodyText);

        return [$purpose, $channel, $name, $subject, $bodyText];
    }
}
