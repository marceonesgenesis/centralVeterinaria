<?php

declare(strict_types=1);

namespace CentralVet\Application;

use CentralVet\Domain\Contract\ServiceRepositoryInterface;
use CentralVet\Domain\Service;
use CentralVet\Tenancy\TenantContext;
use InvalidArgumentException;

/**
 * Use cases for the service (catalog) aggregate: registering a new catalog
 * entry and listing the ones currently offered by the tenant.
 *
 * Named ServiceCatalogService — not ServiceService — to avoid a naming
 * collision with the Service domain entity (CentralVet\Domain\Service),
 * which represents a veterinary procedure/item, not this application layer.
 */
final class ServiceCatalogService
{
    public function __construct(
        private readonly ServiceRepositoryInterface $repository,
        private readonly TenantContext $context,
    ) {
    }

    /**
     * @param array{name: string, category?: string|null, duration_minutes: int, price_cents: int} $data
     */
    public function create(array $data): Service
    {
        if (!array_key_exists('name', $data)) {
            throw new InvalidArgumentException('name is required');
        }

        if (!array_key_exists('duration_minutes', $data)) {
            throw new InvalidArgumentException('duration_minutes is required');
        }

        if (!array_key_exists('price_cents', $data)) {
            throw new InvalidArgumentException('price_cents is required');
        }

        $name = (string) $data['name'];
        $category = isset($data['category']) && $data['category'] !== '' ? (string) $data['category'] : null;
        $durationMinutes = (int) $data['duration_minutes'];
        $priceCents = (int) $data['price_cents'];

        if ($this->repository->findByName(trim($name)) !== null) {
            throw new InvalidArgumentException("A service named \"{$name}\" already exists for this tenant");
        }

        $service = Service::create(
            tenantId: $this->context->tenantId(),
            name: $name,
            category: $category,
            durationMinutes: $durationMinutes,
            priceCents: $priceCents,
        );

        /** @var Service $saved */
        $saved = $this->repository->save($service);

        return $saved;
    }

    /**
     * Updates an existing service of the current tenant in place (never inserts).
     *
     * @param array{name: string, category?: string|null, duration_minutes: int, price_cents: int, active?: bool} $data
     */
    public function update(int $id, array $data): Service
    {
        $service = $this->repository->findById($id);

        if (!$service instanceof Service || $service->tenantId() !== $this->context->tenantId()) {
            throw new InvalidArgumentException('Service not found for this tenant');
        }

        foreach (['name', 'duration_minutes', 'price_cents'] as $required) {
            if (!array_key_exists($required, $data)) {
                throw new InvalidArgumentException("{$required} is required");
            }
        }

        $name = (string) $data['name'];
        $category = isset($data['category']) && $data['category'] !== '' ? (string) $data['category'] : null;

        $sameName = $this->repository->findByName(trim($name));

        if ($sameName instanceof Service && $sameName->id() !== $service->id()) {
            throw new InvalidArgumentException("A service named \"{$name}\" already exists for this tenant");
        }

        $service->changeDetails($name, $category, (int) $data['duration_minutes'], (int) $data['price_cents']);

        if (array_key_exists('active', $data)) {
            (bool) $data['active'] ? $service->activate() : $service->deactivate();
        }

        /** @var Service $saved */
        $saved = $this->repository->save($service);

        return $saved;
    }

    /** @return list<Service> active and inactive services of the tenant, ordered by name */
    public function listAll(): array
    {
        /** @var list<Service> $services */
        $services = $this->repository->listAll();

        return $services;
    }

    /** @return list<Service> */
    public function listActive(): array
    {
        /** @var list<Service> $services */
        $services = $this->repository->listActive();

        return $services;
    }

    public function findById(int $id): ?Service
    {
        /** @var Service|null $service */
        $service = $this->repository->findById($id);

        return $service;
    }
}
