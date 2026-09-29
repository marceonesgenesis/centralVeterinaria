<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Application\TutorService;
use CentralVet\Domain\Tutor;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\FakeTutorRepository;
use InvalidArgumentException;

/**
 * Unit tests for TutorService (T-04), against FakeTutorRepository (T-16) —
 * no database involved, since the `tutor` table does not exist yet
 * (migration T-01 not applied). Covers T-16's acceptance criterion for
 * TutorService: search by name/CPF/phone.
 */
final class TutorServiceTest
{
    public function testSearchReturnsEmptyListForBlankTerm(): void
    {
        $repository = new FakeTutorRepository(1, $this->makeTutor(1, 'Ana Souza'));
        $service = new TutorService($repository);

        Assert::same([], $service->search('   '));
    }

    public function testSearchFindsTutorByName(): void
    {
        $repository = new FakeTutorRepository(
            1,
            $this->makeTutor(1, 'Ana Souza', document: '11122233344', phone: '85999990000'),
            $this->makeTutor(1, 'Bruno Lima', document: '55566677788', phone: '85988880000'),
        );
        $service = new TutorService($repository);

        $results = $service->search('ana');

        Assert::count(1, $results);
        Assert::same('Ana Souza', $results[0]->fullName);
    }

    public function testSearchFindsTutorByDocument(): void
    {
        $repository = new FakeTutorRepository(
            1,
            $this->makeTutor(1, 'Ana Souza', document: '11122233344', phone: '85999990000'),
        );
        $service = new TutorService($repository);

        $results = $service->search('11122233344');

        Assert::count(1, $results);
        Assert::same('Ana Souza', $results[0]->fullName);
    }

    public function testSearchFindsTutorByPhone(): void
    {
        $repository = new FakeTutorRepository(
            1,
            $this->makeTutor(1, 'Ana Souza', document: '11122233344', phone: '85999990000'),
        );
        $service = new TutorService($repository);

        $results = $service->search('85999990000');

        Assert::count(1, $results);
        Assert::same('Ana Souza', $results[0]->fullName);
    }

    public function testSearchNeverReturnsAnotherTenantsTutor(): void
    {
        $repository = new FakeTutorRepository(
            1,
            $this->makeTutor(2, 'Carla Tenant Dois', document: '99988877766', phone: '85977770000'),
        );
        $service = new TutorService($repository);

        Assert::same([], $service->search('carla'));
    }

    public function testCreateRejectsDuplicateDocumentWithinTenant(): void
    {
        $repository = new FakeTutorRepository(
            1,
            $this->makeTutor(1, 'Ana Souza', document: '11122233344', phone: '85999990000'),
        );
        $service = new TutorService($repository);

        Assert::throws(InvalidArgumentException::class, static fn () => $service->create([
            'tenant_id' => 1,
            'full_name' => 'Outro Nome',
            'phone' => '85911112222',
            'document' => '11122233344',
        ]));
    }

    public function testCreatePersistsNewTutor(): void
    {
        $repository = new FakeTutorRepository(1);
        $service = new TutorService($repository);

        $tutor = $service->create([
            'tenant_id' => 1,
            'full_name' => 'Novo Tutor',
            'phone' => '85911112222',
        ]);

        Assert::notNull($tutor->id);
        Assert::same('Novo Tutor', $tutor->fullName);
        Assert::notNull($service->findById($tutor->id));
    }

    public function testCreateRejectsMissingRequiredFields(): void
    {
        $repository = new FakeTutorRepository(1);
        $service = new TutorService($repository);

        Assert::throws(InvalidArgumentException::class, static fn () => $service->create([
            'tenant_id' => 1,
            'full_name' => '',
            'phone' => '85911112222',
        ]));
    }

    private function makeTutor(int $tenantId, string $fullName, string $document = '', string $phone = '85900000000'): Tutor
    {
        return Tutor::register(
            tenantId: $tenantId,
            fullName: $fullName,
            phone: $phone,
            document: $document !== '' ? $document : null,
        );
    }
}
