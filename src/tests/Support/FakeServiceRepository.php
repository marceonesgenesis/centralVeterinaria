<?php

declare(strict_types=1);

namespace CentralVet\Tests\Support;

use CentralVet\Domain\Contract\ServiceRepositoryInterface;
use CentralVet\Domain\Service;
use InvalidArgumentException;

/**
 * In-memory double for ServiceRepositoryInterface (T-16): the `service`
 * table does not exist yet (migration T-01 not applied), so
 * ServiceCatalogService/AppointmentService unit tests exercise this instead
 * of a real database. Tenant-scoped like the real ServiceRepository
 * (ADR 0002): findById()/findByName()/listActive() only ever return
 * services whose tenantId() matches this instance's own $tenantId.
 */
final class FakeServiceRepository implements ServiceRepositoryInterface
{
    /** @var array<int, Service> */
    private array $services = [];
    private int $nextId = 1;
    /** @var array<int, true> service ids flagged by markHasAppointments() */
    private array $withAppointments = [];
    private ?\Throwable $nextSaveFailure = null;

    public function __construct(private readonly int $tenantId, Service ...$seed)
    {
        foreach ($seed as $service) {
            $this->save($service);
        }
    }

    public function tenantId(): int
    {
        return $this->tenantId;
    }

    public function findById(int|string $id): ?object
    {
        $service = $this->services[(int) $id] ?? null;

        if ($service === null || $service->tenantId() !== $this->tenantId) {
            return null;
        }

        return $service;
    }

    public function findByName(string $name): ?object
    {
        foreach ($this->services as $service) {
            if ($service->tenantId() === $this->tenantId && $service->name() === $name) {
                return $service;
            }
        }

        return null;
    }

    /** @return list<Service> */
    public function listActive(): array
    {
        return array_values(array_filter(
            $this->services,
            fn (Service $service): bool => $service->tenantId() === $this->tenantId && $service->isActive(),
        ));
    }

    /** @return list<Service> active and inactive services of this tenant, ordered by name */
    public function listAll(): array
    {
        $services = array_values(array_filter(
            $this->services,
            fn (Service $service): bool => $service->tenantId() === $this->tenantId,
        ));
        usort($services, static fn (Service $a, Service $b): int => strcmp($a->name(), $b->name()));

        return $services;
    }

    /** Number of stored rows, every tenant included (tests check that update() never inserts). */
    public function storedCount(): int
    {
        return count($this->services);
    }

    /** Flags a service as referenced by an appointment, so hasAppointments() returns true for it. */
    public function markHasAppointments(int $serviceId): void
    {
        $this->withAppointments[$serviceId] = true;
    }

    public function hasAppointments(int $serviceId): bool
    {
        return isset($this->withAppointments[$serviceId]);
    }

    /** The next save() throws $e once; later saves behave normally. */
    public function failNextSaveWith(\Throwable $e): void
    {
        $this->nextSaveFailure = $e;
    }

    public function save(object $entity): object
    {
        if ($this->nextSaveFailure !== null) {
            $failure = $this->nextSaveFailure;
            $this->nextSaveFailure = null;

            throw $failure;
        }

        if (!$entity instanceof Service) {
            throw new InvalidArgumentException('FakeServiceRepository only stores Service entities');
        }

        if ($entity->id() === null) {
            $entity->assignId($this->nextId++);
        }

        $this->services[$entity->id()] = $entity;

        return $entity;
    }

    public function remove(object $entity): void
    {
        if ($entity instanceof Service && $entity->id() !== null) {
            unset($this->services[$entity->id()]);
        }
    }
}
