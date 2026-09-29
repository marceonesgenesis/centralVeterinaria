<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Application\ServiceCatalogService;
use CentralVet\Domain\Service;
use CentralVet\Tenancy\TenantContext;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\FakeServiceRepository;
use InvalidArgumentException;

/**
 * Unit tests for ServiceCatalogService (T-06), against FakeServiceRepository
 * (T-16) — no database involved, since the `service` table does not exist
 * yet (migration T-01 not applied). Covers T-16's acceptance criterion:
 * the `active` filter on listActive().
 */
final class ServiceCatalogServiceTest
{
    public function testListActiveExcludesDeactivatedServices(): void
    {
        $active = Service::create(1, 'Consulta', 'clinica geral', 30, 15000);
        $inactive = Service::create(1, 'Banho e tosa (descontinuado)', 'estetica', 60, 8000);
        $inactive->deactivate();

        $repository = new FakeServiceRepository(1, $active, $inactive);
        $service = new ServiceCatalogService($repository, TenantContext::authenticated(1, 1));

        $results = $service->listActive();

        Assert::count(1, $results);
        Assert::same('Consulta', $results[0]->name());
    }

    public function testListActiveNeverReturnsAnotherTenantsService(): void
    {
        $foreignActive = Service::create(2, 'Consulta tenant dois', null, 30, 10000);

        $repository = new FakeServiceRepository(1, $foreignActive);
        $service = new ServiceCatalogService($repository, TenantContext::authenticated(1, 1));

        Assert::same([], $service->listActive());
    }

    public function testCreateRejectsDuplicateNameWithinTenant(): void
    {
        $existing = Service::create(1, 'Consulta', null, 30, 15000);
        $repository = new FakeServiceRepository(1, $existing);
        $service = new ServiceCatalogService($repository, TenantContext::authenticated(1, 1));

        Assert::throws(InvalidArgumentException::class, static fn () => $service->create([
            'name' => 'Consulta',
            'duration_minutes' => 20,
            'price_cents' => 12000,
        ]));
    }

    public function testCreatePersistsNewActiveService(): void
    {
        $repository = new FakeServiceRepository(1);
        $service = new ServiceCatalogService($repository, TenantContext::authenticated(1, 1));

        $created = $service->create([
            'name' => 'Vacinação',
            'category' => 'preventivo',
            'duration_minutes' => 15,
            'price_cents' => 9000,
        ]);

        Assert::notNull($created->id());
        Assert::true($created->isActive());
        Assert::count(1, $service->listActive());
    }
}
