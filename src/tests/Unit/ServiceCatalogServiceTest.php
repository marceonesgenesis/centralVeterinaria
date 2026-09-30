<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Application\ServiceCatalogService;
use CentralVet\Domain\Service;
use CentralVet\Tenancy\TenantContext;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\FakeServiceRepository;
use DomainException;
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

    public function testCreateWithActiveFalseStoresInactiveServiceInOneSave(): void
    {
        $repository = new FakeServiceRepository(1);
        $service = new ServiceCatalogService($repository, TenantContext::authenticated(1, 1));

        $created = $service->create([
            'name' => 'Consulta noturna',
            'duration_minutes' => 30,
            'price_cents' => 20000,
            'active' => false,
        ]);

        Assert::same(1, $repository->storedCount());
        Assert::false($created->isActive());
        Assert::same([], $service->listActive());
    }

    public function testDuplicateCreatesInactiveCopiesWithFreeNames(): void
    {
        $original = Service::create(1, 'A', 'clinica', 40, 12050);
        $repository = new FakeServiceRepository(1, $original);
        $service = new ServiceCatalogService($repository, TenantContext::authenticated(1, 1));
        $id = (int) $original->id();

        $first = $service->duplicate($id, 'cópia');
        $second = $service->duplicate($id, 'cópia');

        Assert::same('A (cópia)', $first->name());
        Assert::same('A (cópia 2)', $second->name());
        Assert::false($first->isActive());
        Assert::false($second->isActive());
        Assert::same('clinica', $second->category());
        Assert::same(40, $second->durationMinutes());
        Assert::same(12050, $second->priceCents());
        Assert::true($original->isActive());
        Assert::same(3, $repository->storedCount());
    }

    public function testDuplicateRejectsServiceFromAnotherTenant(): void
    {
        $foreign = Service::create(2, 'Alheio', null, 30, 1000);
        $repository = new FakeServiceRepository(1, $foreign);
        $service = new ServiceCatalogService($repository, TenantContext::authenticated(1, 1));

        $message = null;

        try {
            $service->duplicate((int) $foreign->id());
        } catch (InvalidArgumentException $e) {
            $message = $e->getMessage();
        }

        Assert::same('Service not found for this tenant', $message);
        Assert::same(1, $repository->storedCount());
    }

    public function testDeleteRejectsServiceWithAppointments(): void
    {
        $used = Service::create(1, 'Consulta', null, 30, 15000);
        $repository = new FakeServiceRepository(1, $used);
        $service = new ServiceCatalogService($repository, TenantContext::authenticated(1, 1));
        $id = (int) $used->id();
        $repository->markHasAppointments($id);

        $message = null;

        try {
            $service->delete($id);
        } catch (DomainException $e) {
            $message = $e->getMessage();
        }

        Assert::same("Service {$id} has appointments; deactivate it instead", $message);
        Assert::notNull($service->findById($id));
        Assert::same(1, $repository->storedCount());
    }

    public function testDeleteRemovesServiceWithoutAppointments(): void
    {
        $unused = Service::create(1, 'Banho', null, 30, 5000);
        $repository = new FakeServiceRepository(1, $unused);
        $service = new ServiceCatalogService($repository, TenantContext::authenticated(1, 1));
        $id = (int) $unused->id();

        $service->delete($id);

        Assert::null($service->findById($id));
        Assert::same(0, $repository->storedCount());
    }

    public function testDeleteRejectsUnknownId(): void
    {
        $foreign = Service::create(2, 'Alheio', null, 30, 1000);
        $repository = new FakeServiceRepository(1, $foreign);
        $service = new ServiceCatalogService($repository, TenantContext::authenticated(1, 1));

        $message = null;

        try {
            $service->delete((int) $foreign->id());
        } catch (InvalidArgumentException $e) {
            $message = $e->getMessage();
        }

        Assert::same('Service not found for this tenant', $message);
        Assert::same(1, $repository->storedCount());
    }

    public function testImportCsvCreatesValidRowsAndReportsSkippedLines(): void
    {
        $existing = Service::create(1, 'Consulta', null, 30, 15000);
        $repository = new FakeServiceRepository(1, $existing);
        $service = new ServiceCatalogService($repository, TenantContext::authenticated(1, 1));

        $csv = "\xEF\xBB\xBFname;category;duration_minutes;price\r\n"
            . "Vacina V10;preventivo;15;120,50\r\n"
            . "Retorno;;20;80.00\r\n"
            . "Consulta;clinica;30;150\r\n"
            . "Exame;lab;10;abc\r\n"
            . "\r\n";

        $result = $service->importCsv($csv);

        Assert::same(2, $result['created']);
        Assert::same([
            ['line' => 4, 'reason' => 'duplicated name'],
            ['line' => 5, 'reason' => 'invalid price'],
        ], $result['skipped']);

        $vacina = $repository->findByName('Vacina V10');
        Assert::notNull($vacina);
        Assert::same(12050, $vacina->priceCents());
        Assert::same('preventivo', $vacina->category());
        Assert::true($vacina->isActive());
        Assert::same(8000, $repository->findByName('Retorno')?->priceCents());
        Assert::same(3, $repository->storedCount());
    }

    public function testImportCsvReportsMissingNameInvalidDurationAndRepeatedName(): void
    {
        $repository = new FakeServiceRepository(1);
        $service = new ServiceCatalogService($repository, TenantContext::authenticated(1, 1));

        $csv = "name;category;duration_minutes;price\n"
            . ";x;10;10\n"
            . "Banho;;zero;10\n"
            . "Tosa;;30;10\n"
            . "Tosa;;30;10\n";

        $result = $service->importCsv($csv);

        Assert::same(1, $result['created']);
        Assert::same([
            ['line' => 2, 'reason' => 'name is required'],
            ['line' => 3, 'reason' => 'invalid duration_minutes'],
            ['line' => 5, 'reason' => 'duplicated name'],
        ], $result['skipped']);
    }

    public function testImportCsvSkipsRowsBeyondColumnLimits(): void
    {
        $repository = new FakeServiceRepository(1);
        $service = new ServiceCatalogService($repository, TenantContext::authenticated(1, 1));

        $csv = "name;category;duration_minutes;price\n"
            . "Valido;;30;10\n"
            . str_repeat('n', 191) . ";;30;10\n"
            . "Categoria longa;" . str_repeat('c', 61) . ";30;10\n"
            . "Duracao enorme;;4294967296;10\n"
            . "Preco enorme;;30;42949672,96\n";

        $result = $service->importCsv($csv);

        Assert::same(1, $result['created']);
        Assert::same([
            ['line' => 3, 'reason' => 'name too long'],
            ['line' => 4, 'reason' => 'category too long'],
            ['line' => 5, 'reason' => 'invalid duration_minutes'],
            ['line' => 6, 'reason' => 'invalid price'],
        ], $result['skipped']);
        Assert::same(1, $repository->storedCount());
    }

    public function testImportCsvAcceptsValuesAtColumnLimits(): void
    {
        $repository = new FakeServiceRepository(1);
        $service = new ServiceCatalogService($repository, TenantContext::authenticated(1, 1));

        $name = str_repeat('á', 190);
        $csv = "name;category;duration_minutes;price\n"
            . $name . ";" . str_repeat('ç', 60) . ";4294967295;42949672,95\n";

        $result = $service->importCsv($csv);

        Assert::same(1, $result['created']);
        Assert::same([], $result['skipped']);
        Assert::same(4294967295, $repository->findByName($name)?->priceCents());
    }

    public function testImportCsvRejectsInvalidHeader(): void
    {
        $repository = new FakeServiceRepository(1);
        $service = new ServiceCatalogService($repository, TenantContext::authenticated(1, 1));

        $message = null;

        try {
            $service->importCsv("nome;categoria;duracao;preco\nA;;10;10\n");
        } catch (InvalidArgumentException $e) {
            $message = $e->getMessage();
        }

        Assert::same('Invalid header: expected name;category;duration_minutes;price', $message);
        Assert::same(0, $repository->storedCount());
    }
}
