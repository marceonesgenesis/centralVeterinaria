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

    public function testUpdateKeepsIdAndDoesNotDuplicate(): void
    {
        $existing = Service::create(1, 'Banho', 'estetica', 30, 5000);
        $repository = new FakeServiceRepository(1, $existing);
        $service = new ServiceCatalogService($repository, TenantContext::authenticated(1, 1));
        $id = (int) $existing->id();

        $updated = $service->update($id, [
            'name' => 'Banho e hidratação',
            'category' => 'higiene',
            'duration_minutes' => 45,
            'price_cents' => 7500,
            'active' => false,
        ]);

        Assert::same($id, $updated->id());
        Assert::same('Banho e hidratação', $updated->name());
        Assert::same('higiene', $updated->category());
        Assert::same(45, $updated->durationMinutes());
        Assert::same(7500, $updated->priceCents());
        Assert::false($updated->isActive());
        Assert::same(1, $repository->storedCount());

        $reloaded = $service->findById($id);
        Assert::notNull($reloaded);
        Assert::same('Banho e hidratação', $reloaded->name());
    }

    public function testUpdateRejectsNameOfAnotherService(): void
    {
        $first = Service::create(1, 'Consulta', null, 30, 15000);
        $second = Service::create(1, 'Retorno', null, 20, 8000);
        $repository = new FakeServiceRepository(1, $first, $second);
        $service = new ServiceCatalogService($repository, TenantContext::authenticated(1, 1));

        Assert::throws(InvalidArgumentException::class, static fn () => $service->update((int) $second->id(), [
            'name' => 'Consulta',
            'category' => null,
            'duration_minutes' => 20,
            'price_cents' => 8000,
            'active' => true,
        ]));
        Assert::same('Retorno', $second->name());
        Assert::same(2, $repository->storedCount());
    }

    public function testUpdateRejectsServiceFromAnotherTenant(): void
    {
        $foreign = Service::create(2, 'Consulta tenant dois', null, 30, 10000);
        $repository = new FakeServiceRepository(1, $foreign);
        $service = new ServiceCatalogService($repository, TenantContext::authenticated(1, 1));
        $foreignId = (int) $foreign->id();

        $message = null;

        try {
            $service->update($foreignId, [
                'name' => 'Sequestrado',
                'category' => null,
                'duration_minutes' => 10,
                'price_cents' => 1,
                'active' => true,
            ]);
        } catch (InvalidArgumentException $e) {
            $message = $e->getMessage();
        }

        Assert::same('Service not found for this tenant', $message);
        Assert::null($service->findById($foreignId));
        Assert::same('Consulta tenant dois', $foreign->name());
    }

    public function testListAllIncludesInactiveServices(): void
    {
        $active = Service::create(1, 'Consulta', null, 30, 15000);
        $inactive = Service::create(1, 'Banho', 'estetica', 60, 8000);
        $inactive->deactivate();
        $foreign = Service::create(2, 'Alheio', null, 30, 1000);

        $repository = new FakeServiceRepository(1, $active, $inactive, $foreign);
        $service = new ServiceCatalogService($repository, TenantContext::authenticated(1, 1));

        $all = array_map(static fn (Service $s): string => $s->name(), $service->listAll());
        $activeOnly = array_map(static fn (Service $s): string => $s->name(), $service->listActive());

        Assert::same(['Banho', 'Consulta'], $all);
        Assert::same(['Consulta'], $activeOnly);
    }
}
