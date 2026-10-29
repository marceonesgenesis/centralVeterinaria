<?php

declare(strict_types=1);

namespace CentralVet\Domain;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * One copilot conversation (`ai_conversation`, migration 0015).
 *
 * Owned by a user inside a unit of a tenant. The family is `clinical` or
 * `management`; only a clinical conversation may be anchored to a patient.
 * The title arrives already minimized (service responsibility) and is cut
 * at TITLE_MAX characters. Texts never go into exception messages.
 */
final class AiConversation
{
    public const FAMILY_CLINICAL = 'clinical';
    public const FAMILY_MANAGEMENT = 'management';
    public const FAMILIES = [self::FAMILY_CLINICAL, self::FAMILY_MANAGEMENT];
    public const TITLE_MAX = 80;

    private function __construct(
        private ?int $id,
        private readonly int $tenantId,
        private readonly int $systemUnitId,
        private readonly int $systemUserId,
        private readonly string $family,
        private readonly ?int $anchorPatientId,
        private readonly string $title,
        private readonly DateTimeImmutable $createdAt,
        private DateTimeImmutable $updatedAt,
    ) {
        foreach ([
            'tenant_id' => $tenantId,
            'system_unit_id' => $systemUnitId,
            'system_user_id' => $systemUserId,
        ] as $field => $value) {
            if ($value <= 0) {
                throw new InvalidArgumentException("{$field} must be positive");
            }
        }

        if (!in_array($family, self::FAMILIES, true)) {
            throw new InvalidArgumentException('Unknown conversation family');
        }

        if ($anchorPatientId !== null && ($family !== self::FAMILY_CLINICAL || $anchorPatientId <= 0)) {
            throw new InvalidArgumentException('Only a clinical conversation may be anchored to a patient');
        }

        if ($title === '' || mb_strlen($title) > self::TITLE_MAX) {
            throw new InvalidArgumentException('Conversation title must have 1 to 80 characters');
        }
    }

    public static function start(
        int $tenantId,
        int $systemUnitId,
        int $systemUserId,
        string $family,
        ?int $anchorPatientId,
        string $title,
        DateTimeImmutable $now,
    ): self {
        return new self(
            id: null,
            tenantId: $tenantId,
            systemUnitId: $systemUnitId,
            systemUserId: $systemUserId,
            family: $family,
            anchorPatientId: $anchorPatientId,
            title: mb_substr(trim($title), 0, self::TITLE_MAX),
            createdAt: $now,
            updatedAt: $now,
        );
    }

    /** @param array<string, mixed> $row raw column values of `ai_conversation`. */
    public static function reconstitute(array $row): self
    {
        $conversation = new self(
            id: null,
            tenantId: (int) $row['tenant_id'],
            systemUnitId: (int) $row['system_unit_id'],
            systemUserId: (int) $row['system_user_id'],
            family: (string) $row['family'],
            anchorPatientId: isset($row['anchor_patient_id']) ? (int) $row['anchor_patient_id'] : null,
            title: (string) $row['title'],
            createdAt: new DateTimeImmutable((string) $row['created_at']),
            updatedAt: new DateTimeImmutable((string) ($row['updated_at'] ?? $row['created_at'])),
        );
        $conversation->assignId((int) $row['id']);

        return $conversation;
    }

    /** Used by the repository after the insert. */
    public function assignId(int $id): void
    {
        if ($this->id !== null) {
            throw new InvalidArgumentException('Conversation already has an id');
        }

        if ($id <= 0) {
            throw new InvalidArgumentException('Id must be positive');
        }

        $this->id = $id;
    }

    /** Marks new activity; the list orders by `updated_at`. */
    public function touch(DateTimeImmutable $now): void
    {
        $this->updatedAt = $now;
    }

    /**
     * Column values for `ai_conversation`; dates use `Y-m-d H:i:s.u`.
     *
     * @return array<string, mixed>
     */
    public function toRow(): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenantId,
            'system_unit_id' => $this->systemUnitId,
            'system_user_id' => $this->systemUserId,
            'family' => $this->family,
            'anchor_patient_id' => $this->anchorPatientId,
            'title' => $this->title,
            'created_at' => $this->createdAt->format('Y-m-d H:i:s.u'),
            'updated_at' => $this->updatedAt->format('Y-m-d H:i:s.u'),
        ];
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

    public function systemUserId(): int
    {
        return $this->systemUserId;
    }

    public function family(): string
    {
        return $this->family;
    }

    public function anchorPatientId(): ?int
    {
        return $this->anchorPatientId;
    }

    public function title(): string
    {
        return $this->title;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
