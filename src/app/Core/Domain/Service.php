<?php

declare(strict_types=1);

namespace CentralVet\Domain;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Veterinary service/procedure catalog entry (domain aggregate for the
 * `service` table): a billable item a clinic offers (e.g. "Consulta",
 * "Banho e tosa"), with a name, category, expected duration and price.
 *
 * Not to be confused with an "Application service" (a use-case class such
 * as CentralVet\Application\ServiceCatalogService) — this class is the
 * domain entity for the catalog item itself.
 */
final class Service
{
    private function __construct(
        private ?int $id,
        private readonly int $tenantId,
        private string $name,
        private ?string $category,
        private int $durationMinutes,
        private int $priceCents,
        private bool $active,
        private readonly ?DateTimeImmutable $createdAt = null,
        private readonly ?DateTimeImmutable $updatedAt = null,
    ) {
    }

    public static function create(
        int $tenantId,
        string $name,
        ?string $category,
        int $durationMinutes,
        int $priceCents,
    ): self {
        $name = trim($name);
        $category = $category !== null ? trim($category) : null;

        if ($tenantId <= 0) {
            throw new InvalidArgumentException('Tenant id must be positive');
        }

        if ($name === '') {
            throw new InvalidArgumentException('Service name is required');
        }

        if ($durationMinutes <= 0) {
            throw new InvalidArgumentException('Duration must be a positive number of minutes');
        }

        if ($priceCents < 0) {
            throw new InvalidArgumentException('Price cannot be negative');
        }

        return new self(
            id: null,
            tenantId: $tenantId,
            name: $name,
            category: $category !== null && $category !== '' ? $category : null,
            durationMinutes: $durationMinutes,
            priceCents: $priceCents,
            active: true,
        );
    }

    /**
     * Rebuilds an existing service from persisted data. Repositories use
     * this to hydrate rows; application code should use create() instead.
     */
    public static function reconstitute(
        int $id,
        int $tenantId,
        string $name,
        ?string $category,
        int $durationMinutes,
        int $priceCents,
        bool $active,
        ?DateTimeImmutable $createdAt,
        ?DateTimeImmutable $updatedAt,
    ): self {
        return new self($id, $tenantId, $name, $category, $durationMinutes, $priceCents, $active, $createdAt, $updatedAt);
    }

    /** Assigns the identifier generated on first persistence. */
    public function assignId(int $id): void
    {
        if ($this->id !== null) {
            throw new InvalidArgumentException('Service already has an id');
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

    public function name(): string
    {
        return $this->name;
    }

    public function category(): ?string
    {
        return $this->category;
    }

    public function durationMinutes(): int
    {
        return $this->durationMinutes;
    }

    public function priceCents(): int
    {
        return $this->priceCents;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function createdAt(): ?DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): ?DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * Replaces the editable details of an existing service, applying the
     * same rules as create(). Active/inactive stays with activate()/deactivate().
     */
    public function changeDetails(string $name, ?string $category, int $durationMinutes, int $priceCents): void
    {
        $name = trim($name);
        $category = $category !== null ? trim($category) : null;

        if ($name === '') {
            throw new InvalidArgumentException('Service name is required');
        }

        if ($durationMinutes <= 0) {
            throw new InvalidArgumentException('Duration must be a positive number of minutes');
        }

        if ($priceCents < 0) {
            throw new InvalidArgumentException('Price cannot be negative');
        }

        $this->name = $name;
        $this->category = $category !== null && $category !== '' ? $category : null;
        $this->durationMinutes = $durationMinutes;
        $this->priceCents = $priceCents;
    }

    public function activate(): void
    {
        $this->active = true;
    }

    public function deactivate(): void
    {
        $this->active = false;
    }
}
