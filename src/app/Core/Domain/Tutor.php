<?php

declare(strict_types=1);

namespace CentralVet\Domain;

use DateTimeImmutable;

/**
 * Tutor aggregate (pet guardian) — Core domain entity, storage-agnostic on
 * purpose. Building one never touches a database, so callers can construct
 * it even before the phase 1 migration
 * (src/app/database/migrations/20260921_0002_phase1_clinic_core.sql) has
 * been applied. Only actually persisting it (see
 * Contract\TutorRepositoryInterface / Persistence\TutorRepository) depends
 * on the `tutor` table existing.
 *
 * Immutable: every mutation returns a new instance.
 */
final class Tutor
{
    public function __construct(
        public readonly ?int $id,
        public readonly int $tenantId,
        public readonly string $publicId,
        public readonly string $fullName,
        public readonly ?string $document,
        public readonly string $phone,
        public readonly ?string $email,
        public readonly ?string $address,
        public readonly ?DateTimeImmutable $createdAt = null,
        public readonly ?DateTimeImmutable $updatedAt = null,
    ) {
    }

    /**
     * Builds a brand-new (not yet persisted) tutor for the given tenant.
     */
    public static function register(
        int $tenantId,
        string $fullName,
        string $phone,
        ?string $document = null,
        ?string $email = null,
        ?string $address = null,
    ): self {
        return new self(
            id: null,
            tenantId: $tenantId,
            publicId: self::generatePublicId(),
            fullName: $fullName,
            document: $document,
            phone: $phone,
            email: $email,
            address: $address,
        );
    }

    /**
     * Returns a copy carrying the id assigned by the repository after
     * insertion (this entity never assigns its own numeric id).
     */
    public function withId(int $id): self
    {
        return new self(
            id: $id,
            tenantId: $this->tenantId,
            publicId: $this->publicId,
            fullName: $this->fullName,
            document: $this->document,
            phone: $this->phone,
            email: $this->email,
            address: $this->address,
            createdAt: $this->createdAt,
            updatedAt: $this->updatedAt,
        );
    }

    /**
     * Returns a copy with updated contact/registration data, keeping id,
     * tenant and public id untouched.
     */
    public function withDetails(
        string $fullName,
        string $phone,
        ?string $document = null,
        ?string $email = null,
        ?string $address = null,
    ): self {
        return new self(
            id: $this->id,
            tenantId: $this->tenantId,
            publicId: $this->publicId,
            fullName: $fullName,
            document: $document,
            phone: $phone,
            email: $email,
            address: $address,
            createdAt: $this->createdAt,
            updatedAt: $this->updatedAt,
        );
    }

    private static function generatePublicId(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        $hex = bin2hex($bytes);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        );
    }
}
