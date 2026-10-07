<?php

declare(strict_types=1);

namespace CentralVet\Domain;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * A tenant's plain-text document template (domain entity for the
 * `document_template` table, migration 0013, Fase 7B).
 *
 * Only kinds with `DocumentKind::usesTemplate()` (today the medical
 * certificate); name 1..120 chars; body 1..20000 chars using only
 * `DocumentTemplateRenderer::PLACEHOLDERS`; status `active` or `inactive`.
 * The name is unique per tenant (`document_template_tenant_name_uq`).
 */
final class DocumentTemplate
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';

    private const STATUSES = [self::STATUS_ACTIVE, self::STATUS_INACTIVE];

    private function __construct(
        private ?int $id,
        private readonly int $tenantId,
        private readonly string $kind,
        private string $name,
        private string $bodyText,
        private string $status,
        private readonly int $createdBySystemUserId,
        private ?int $updatedBySystemUserId = null,
        private readonly ?DateTimeImmutable $createdAt = null,
        private readonly ?DateTimeImmutable $updatedAt = null,
    ) {
    }

    public static function create(int $tenantId, string $kind, string $name, string $bodyText, int $createdBySystemUserId): self
    {
        if ($tenantId <= 0) {
            throw new InvalidArgumentException('Tenant id must be positive');
        }

        if ($createdBySystemUserId <= 0) {
            throw new InvalidArgumentException('created_by_system_user_id must be positive');
        }

        DocumentKind::assertValid($kind);

        if (!DocumentKind::usesTemplate($kind)) {
            throw new InvalidArgumentException("Document kind \"{$kind}\" does not use templates");
        }

        [$name, $bodyText] = self::normalize($name, $bodyText);

        return new self(
            id: null,
            tenantId: $tenantId,
            kind: $kind,
            name: $name,
            bodyText: $bodyText,
            status: self::STATUS_ACTIVE,
            createdBySystemUserId: $createdBySystemUserId,
        );
    }

    /**
     * Rebuilds a template from persisted data.
     *
     * @param array<string, mixed> $row raw column values, keyed exactly like the `document_template` table.
     */
    public static function reconstitute(array $row): self
    {
        $status = (string) $row['status'];
        self::assertValidStatus($status);

        return new self(
            id: (int) $row['id'],
            tenantId: (int) $row['tenant_id'],
            kind: (string) $row['kind'],
            name: (string) $row['name'],
            bodyText: (string) $row['body_text'],
            status: $status,
            createdBySystemUserId: (int) $row['created_by_system_user_id'],
            updatedBySystemUserId: isset($row['updated_by_system_user_id']) ? (int) $row['updated_by_system_user_id'] : null,
            createdAt: isset($row['created_at']) ? new DateTimeImmutable((string) $row['created_at']) : null,
            updatedAt: isset($row['updated_at']) ? new DateTimeImmutable((string) $row['updated_at']) : null,
        );
    }

    public function update(string $name, string $bodyText, string $status, int $updatedBySystemUserId): void
    {
        if ($updatedBySystemUserId <= 0) {
            throw new InvalidArgumentException('updated_by_system_user_id must be positive');
        }

        self::assertValidStatus($status);
        [$name, $bodyText] = self::normalize($name, $bodyText);

        $this->name = $name;
        $this->bodyText = $bodyText;
        $this->status = $status;
        $this->updatedBySystemUserId = $updatedBySystemUserId;
    }

    /** Used by the repository after the insert. */
    public function assignId(int $id): void
    {
        if ($this->id !== null) {
            throw new InvalidArgumentException('Document template already has an id');
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

    public function kind(): string
    {
        return $this->kind;
    }

    public function name(): string
    {
        return $this->name;
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

    private static function assertValidStatus(string $status): void
    {
        if (!in_array($status, self::STATUSES, true)) {
            throw new InvalidArgumentException("Unknown document template status \"{$status}\"");
        }
    }

    /** @return array{0: string, 1: string} */
    private static function normalize(string $name, string $bodyText): array
    {
        $name = trim($name);
        $nameLength = mb_strlen($name);

        if ($nameLength < 1 || $nameLength > 120) {
            throw new InvalidArgumentException('name must be between 1 and 120 characters');
        }

        $bodyText = trim($bodyText);
        $bodyLength = mb_strlen($bodyText);

        if ($bodyLength < 1 || $bodyLength > 20000) {
            throw new InvalidArgumentException('body must be between 1 and 20000 characters');
        }

        $unknown = DocumentTemplateRenderer::unknownPlaceholders($bodyText);

        if ($unknown !== []) {
            throw new InvalidArgumentException("Unknown placeholder \"{$unknown[0]}\" in template");
        }

        return [$name, $bodyText];
    }
}
