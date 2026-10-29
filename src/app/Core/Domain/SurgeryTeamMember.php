<?php

declare(strict_types=1);

namespace CentralVet\Domain;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * One member of a surgery's team (domain entity for the `surgery_team`
 * table, migration 20261005_0011_phase6b_surgery). The team is replaced as
 * a whole through `SurgeryTeamRepositoryInterface::replaceForSurgery()`.
 */
final class SurgeryTeamMember
{
    public const ROLE_SURGEON = 'surgeon';
    public const ROLE_ANESTHETIST = 'anesthetist';
    public const ROLE_ASSISTANT = 'assistant';
    public const ROLE_CIRCULATING = 'circulating';

    public const ROLES = [
        self::ROLE_SURGEON,
        self::ROLE_ANESTHETIST,
        self::ROLE_ASSISTANT,
        self::ROLE_CIRCULATING,
    ];

    private function __construct(
        private ?int $id,
        private readonly int $tenantId,
        private readonly int $surgeryId,
        private readonly int $systemUserId,
        private readonly string $role,
        private readonly ?DateTimeImmutable $createdAt = null,
    ) {
    }

    public static function create(int $tenantId, int $surgeryId, int $systemUserId, string $role): self
    {
        foreach ([
            'Tenant id' => $tenantId,
            'surgery_id' => $surgeryId,
            'system_user_id' => $systemUserId,
        ] as $field => $value) {
            if ($value <= 0) {
                throw new InvalidArgumentException("{$field} must be positive");
            }
        }

        return new self(
            id: null,
            tenantId: $tenantId,
            surgeryId: $surgeryId,
            systemUserId: $systemUserId,
            role: self::assertRole($role),
        );
    }

    /**
     * Rebuilds an existing team member from persisted data.
     *
     * @param array<string, mixed> $row raw column values, keyed exactly like the `surgery_team` table.
     */
    public static function reconstitute(array $row): self
    {
        return new self(
            id: (int) $row['id'],
            tenantId: (int) $row['tenant_id'],
            surgeryId: (int) $row['surgery_id'],
            systemUserId: (int) $row['system_user_id'],
            role: self::assertRole((string) $row['role']),
            createdAt: isset($row['created_at']) ? new DateTimeImmutable((string) $row['created_at']) : null,
        );
    }

    public function assignId(int $id): void
    {
        if ($this->id !== null) {
            throw new InvalidArgumentException('Surgery team member already has an id');
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

    public function systemUserId(): int
    {
        return $this->systemUserId;
    }

    public function role(): string
    {
        return $this->role;
    }

    public function createdAt(): ?DateTimeImmutable
    {
        return $this->createdAt;
    }

    private static function assertRole(string $role): string
    {
        if (!in_array($role, self::ROLES, true)) {
            throw new InvalidArgumentException("Unknown team role \"{$role}\"");
        }

        return $role;
    }
}
