<?php

declare(strict_types=1);

namespace CentralVet\Tests\Support;

use CentralVet\Domain\Contract\PrescriptionTemplateRepositoryInterface;
use CentralVet\Domain\PrescriptionTemplate;
use InvalidArgumentException;

/**
 * In-memory double for PrescriptionTemplateRepositoryInterface (rodada 2,
 * T-13). Tenant-scoped like the real PrescriptionTemplateRepository
 * (ADR 0002): findById(), findByName() and listAll() only ever return
 * templates whose tenantId() matches this instance's own $tenantId.
 */
final class FakePrescriptionTemplateRepository implements PrescriptionTemplateRepositoryInterface
{
    /** @var array<int, PrescriptionTemplate> */
    private array $templates = [];
    private int $nextId = 1;

    public function __construct(private readonly int $tenantId, PrescriptionTemplate ...$seed)
    {
        foreach ($seed as $template) {
            $this->templates[$this->nextId] = $template;
            $template->assignId($this->nextId++);
        }
    }

    public function tenantId(): int
    {
        return $this->tenantId;
    }

    public function findById(int|string $id): ?object
    {
        $template = $this->templates[(int) $id] ?? null;

        if ($template === null || $template->tenantId() !== $this->tenantId) {
            return null;
        }

        return $template;
    }

    public function findByName(string $name): ?object
    {
        foreach ($this->templates as $template) {
            if ($template->tenantId() === $this->tenantId && $template->name() === $name) {
                return $template;
            }
        }

        return null;
    }

    /** @return list<PrescriptionTemplate> */
    public function listAll(): array
    {
        $own = array_values(array_filter(
            $this->templates,
            fn (PrescriptionTemplate $template): bool => $template->tenantId() === $this->tenantId,
        ));

        usort($own, static fn (PrescriptionTemplate $a, PrescriptionTemplate $b): int => strcmp($a->name(), $b->name()));

        return $own;
    }

    public function save(object $entity): object
    {
        if (!$entity instanceof PrescriptionTemplate) {
            throw new InvalidArgumentException('FakePrescriptionTemplateRepository only stores PrescriptionTemplate entities');
        }

        if ($entity->tenantId() !== $this->tenantId) {
            throw new InvalidArgumentException('Entity belongs to another tenant');
        }

        if ($entity->id() === null) {
            $entity->assignId($this->nextId++);
        }

        $this->templates[$entity->id()] = $entity;

        return $entity;
    }

    public function remove(object $entity): void
    {
        if ($entity instanceof PrescriptionTemplate && $entity->id() !== null) {
            unset($this->templates[$entity->id()]);
        }
    }
}
