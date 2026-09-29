<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Application\PatientService;
use CentralVet\Domain\Exception\CrossTenantReferenceException;
use CentralVet\Domain\Tutor;
use CentralVet\Tenancy\TenantContext;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\FakePatientRepository;
use CentralVet\Tests\Support\FakeTutorRepository;

/**
 * Unit tests for PatientService (T-05), against fake repositories (T-16) —
 * no database involved, since the `patient`/`tutor` tables do not exist yet
 * (migration T-01 not applied). Covers T-16's acceptance criterion:
 * rejection of a tutor_id belonging to another tenant.
 */
final class PatientServiceTest
{
    public function testCreateRejectsTutorIdFromAnotherTenant(): void
    {
        $ownTutor = Tutor::register(tenantId: 1, fullName: 'Ana Souza', phone: '85999990000');
        $foreignTutor = Tutor::register(tenantId: 2, fullName: 'Tutor de Outro Tenant', phone: '85988880000');

        // FakeTutorRepository(1, ...) is scoped to tenant 1 (mirrors the
        // real TutorRepository's tenantQuery()), so it stores both but only
        // ever resolves tenant 1's own tutor via findById().
        $tutors = new FakeTutorRepository(1, $ownTutor, $foreignTutor);
        $patients = new FakePatientRepository(1);
        $context = TenantContext::authenticated(1, 1);
        $service = new PatientService($patients, $tutors, $context);

        // withId(2) mirrors the id the fake would have assigned the second
        // seeded tutor (the foreign one), which findById() must refuse to
        // resolve since it belongs to tenant 2, not the authenticated
        // tenant 1.
        Assert::throws(
            CrossTenantReferenceException::class,
            static fn () => $service->create([
                'tutor_id' => 2,
                'name' => 'Rex',
                'species' => 'canino',
            ]),
        );
    }

    public function testCreateRejectsNonExistentTutorId(): void
    {
        $tutors = new FakeTutorRepository(1);
        $patients = new FakePatientRepository(1);
        $context = TenantContext::authenticated(1, 1);
        $service = new PatientService($patients, $tutors, $context);

        Assert::throws(
            CrossTenantReferenceException::class,
            static fn () => $service->create([
                'tutor_id' => 999,
                'name' => 'Rex',
                'species' => 'canino',
            ]),
        );
    }

    public function testCreatePersistsPatientForOwnTenantTutor(): void
    {
        $ownTutor = Tutor::register(tenantId: 1, fullName: 'Ana Souza', phone: '85999990000');
        $tutors = new FakeTutorRepository(1, $ownTutor);
        $patients = new FakePatientRepository(1);
        $context = TenantContext::authenticated(1, 1);
        $service = new PatientService($patients, $tutors, $context);

        $patient = $service->create([
            'tutor_id' => 1,
            'name' => 'Rex',
            'species' => 'canino',
        ]);

        Assert::notNull($patient->id);
        Assert::same(1, $patient->tenantId);
        Assert::same(1, $patient->tutorId);
        Assert::notNull($service->findById($patient->id));
    }

    public function testFindByTutorReturnsOnlyThatTutorsPatients(): void
    {
        $ownTutor = Tutor::register(tenantId: 1, fullName: 'Ana Souza', phone: '85999990000');
        $tutors = new FakeTutorRepository(1, $ownTutor);
        $patients = new FakePatientRepository(1);
        $context = TenantContext::authenticated(1, 1);
        $service = new PatientService($patients, $tutors, $context);

        $service->create(['tutor_id' => 1, 'name' => 'Rex', 'species' => 'canino']);
        $service->create(['tutor_id' => 1, 'name' => 'Mimi', 'species' => 'felino']);

        Assert::count(2, $service->findByTutor(1));
        Assert::count(0, $service->findByTutor(2));
    }

    public function testSearchReturnsEmptyListForBlankTerm(): void
    {
        $tutors = new FakeTutorRepository(1);
        $patients = new FakePatientRepository(1);
        $context = TenantContext::authenticated(1, 1);
        $service = new PatientService($patients, $tutors, $context);

        Assert::same([], $service->search(' '));
    }
}
