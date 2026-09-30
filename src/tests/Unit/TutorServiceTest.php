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

    public function testUpdateChangesNameAndPhoneKeepingIdentity(): void
    {
        [$repository, $a] = $this->seedTwoTutors();
        $service = new TutorService($repository);

        $updated = $service->update($a->id, [
            'full_name' => '  Ana Souza Editada ',
            'phone' => ' 85933334444 ',
            'document' => '11122233344',
            'email' => '',
        ]);

        Assert::same($a->id, $updated->id);
        Assert::same($a->publicId, $updated->publicId);
        Assert::same($a->tenantId, $updated->tenantId);
        Assert::same('Ana Souza Editada', $updated->fullName);
        Assert::same('85933334444', $updated->phone);
        Assert::same('11122233344', $updated->document);
        Assert::same(null, $updated->email);
        Assert::same('85933334444', $service->findById($a->id)->phone);
    }

    public function testUpdateRejectsDocumentOfAnotherTutor(): void
    {
        [$repository, $a] = $this->seedTwoTutors();
        $service = new TutorService($repository);

        $this->assertInvalidArgument('A tutor with this document already exists in this tenant', static fn () => $service->update($a->id, [
            'full_name' => 'Ana Souza',
            'phone' => '85999990000',
            'document' => '55566677788',
        ]));
    }

    public function testUpdateRejectsUnknownTutor(): void
    {
        [$repository] = $this->seedTwoTutors();
        $service = new TutorService($repository);

        $this->assertInvalidArgument('Tutor 999 not found for this tenant', static fn () => $service->update(999, [
            'full_name' => 'Ninguem',
            'phone' => '85900000000',
        ]));
    }

    public function testUpdateDoesNotFindAnotherTenantsTutor(): void
    {
        $other = $this->makeTutor(2, 'Carla Tenant Dois', document: '99988877766')->withId(50);
        $repository = new FakeTutorRepository(1, $other);
        $service = new TutorService($repository);

        $this->assertInvalidArgument('Tutor 50 not found for this tenant', static fn () => $service->update(50, [
            'full_name' => 'Invasor',
            'phone' => '85900000000',
        ]));
    }

    public function testUpdateRejectsMissingRequiredFields(): void
    {
        [$repository, $a] = $this->seedTwoTutors();
        $service = new TutorService($repository);

        $this->assertInvalidArgument('full_name is required', static fn () => $service->update($a->id, [
            'full_name' => '   ',
            'phone' => '85999990000',
        ]));
        $this->assertInvalidArgument('phone is required', static fn () => $service->update($a->id, [
            'full_name' => 'Ana Souza',
            'phone' => '',
        ]));
    }

    /** @return array{0: FakeTutorRepository, 1: Tutor, 2: Tutor} */
    private function seedTwoTutors(): array
    {
        $repository = new FakeTutorRepository(
            1,
            $this->makeTutor(1, 'Ana Souza', document: '11122233344', phone: '85999990000'),
            $this->makeTutor(1, 'Bruno Lima', document: '55566677788', phone: '85988880000'),
        );
        $all = $repository->search('8');

        return [$repository, $all[0], $all[1]];
    }

    private function assertInvalidArgument(string $message, callable $callback): void
    {
        try {
            $callback();
        } catch (InvalidArgumentException $e) {
            Assert::same($message, $e->getMessage());

            return;
        }

        throw new \RuntimeException("Expected InvalidArgumentException('{$message}')");
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
