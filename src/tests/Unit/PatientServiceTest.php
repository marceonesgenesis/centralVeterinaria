<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Application\PatientService;
use CentralVet\Domain\Exception\CrossTenantReferenceException;
use CentralVet\Domain\Patient;
use CentralVet\Domain\Tutor;
use CentralVet\Tenancy\TenantContext;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\FakePatientRepository;
use CentralVet\Tests\Support\FakeStorage;
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

    public function testUpdateChangesFieldsAndKeepsTutorAndId(): void
    {
        $tutorA = Tutor::register(tenantId: 1, fullName: 'Ana Souza', phone: '85999990000');
        $tutorB = Tutor::register(tenantId: 1, fullName: 'Bruno Lima', phone: '85977770000');
        $tutors = new FakeTutorRepository(1, $tutorA, $tutorB);
        $patients = new FakePatientRepository(1);
        $service = new PatientService($patients, $tutors, TenantContext::authenticated(1, 1));

        $original = $service->create(['tutor_id' => 1, 'name' => 'Rex', 'species' => 'dog', 'color' => 'Preto']);

        $updated = $service->update($original->id, [
            'name' => '  Rex 2  ',
            'species' => 'dog',
            'tutor_id' => 2,
            'color' => '',
            'weight_kg' => '12.5',
        ]);

        Assert::same($original->id, $updated->id);
        Assert::same(1, $updated->tutorId);
        Assert::same(1, $updated->tenantId);
        Assert::same('Rex 2', $updated->name);
        Assert::null($updated->color);
        Assert::same(12.5, $updated->weightKg);
        Assert::same('Rex 2', $service->findById($original->id)->name);
        Assert::same(1, $service->findById($original->id)->tutorId);
    }

    public function testUpdateRejectsUnknownId(): void
    {
        $service = new PatientService(new FakePatientRepository(1), new FakeTutorRepository(1), TenantContext::authenticated(1, 1));

        try {
            $service->update(999, ['name' => 'Rex', 'species' => 'dog']);
            throw new \RuntimeException('update(999) should have thrown');
        } catch (\InvalidArgumentException $e) {
            Assert::same('Patient 999 not found for this tenant', $e->getMessage());
        }
    }

    public function testUpdateDoesNotFindPatientFromAnotherTenant(): void
    {
        $foreign = new Patient(id: null, tenantId: 2, tutorId: 5, name: 'Estranho', species: 'cat');
        $patients = new FakePatientRepository(1, $foreign);
        $service = new PatientService($patients, new FakeTutorRepository(1), TenantContext::authenticated(1, 1));

        try {
            $service->update(1, ['name' => 'Invadido', 'species' => 'cat']);
            throw new \RuntimeException('update() of another tenant patient should have thrown');
        } catch (\InvalidArgumentException $e) {
            Assert::same('Patient 1 not found for this tenant', $e->getMessage());
        }
    }

    public function testUpdateValidatesRequiredFieldsAndSex(): void
    {
        $tutors = new FakeTutorRepository(1, Tutor::register(tenantId: 1, fullName: 'Ana Souza', phone: '85999990000'));
        $service = new PatientService(new FakePatientRepository(1), $tutors, TenantContext::authenticated(1, 1));
        $patient = $service->create(['tutor_id' => 1, 'name' => 'Rex', 'species' => 'dog']);

        foreach ([
            'name is required' => ['name' => '  ', 'species' => 'dog'],
            'species is required' => ['name' => 'Rex', 'species' => ''],
        ] as $message => $data) {
            try {
                $service->update($patient->id, $data);
                throw new \RuntimeException("update() should have thrown '{$message}'");
            } catch (\InvalidArgumentException $e) {
                Assert::same($message, $e->getMessage());
            }
        }

        Assert::throws(
            \InvalidArgumentException::class,
            static fn () => $service->update($patient->id, ['name' => 'Rex', 'species' => 'dog', 'sex' => 'X']),
        );
        Assert::same('Rex', $service->findById($patient->id)->name);
    }

    public function testCreateAndUpdatePersistAllergies(): void
    {
        $tutors = new FakeTutorRepository(1, Tutor::register(tenantId: 1, fullName: 'Ana Souza', phone: '85999990000'));
        $service = new PatientService(new FakePatientRepository(1), $tutors, TenantContext::authenticated(1, 1));

        $created = $service->create(['tutor_id' => 1, 'name' => 'Rex', 'species' => 'dog', 'allergies' => 'Dipirona']);
        Assert::same('Dipirona', $created->allergies);
        Assert::same('Dipirona', $service->findById($created->id)->allergies);

        $blank = $service->create(['tutor_id' => 1, 'name' => 'Mia', 'species' => 'cat', 'allergies' => '']);
        Assert::null($blank->allergies);

        $updated = $service->update($created->id, ['name' => 'Rex', 'species' => 'dog', 'allergies' => '  Penicilina ']);
        Assert::same('Penicilina', $updated->allergies);

        $cleared = $service->update($created->id, ['name' => 'Rex', 'species' => 'dog', 'allergies' => '']);
        Assert::null($cleared->allergies);
    }

    public function testAttachPhotoStoresUnderTenantKeyAndPhotoReturnsBytes(): void
    {
        $tutors = new FakeTutorRepository(1, Tutor::register(tenantId: 1, fullName: 'Ana Souza', phone: '85999990000'));
        $storage = new FakeStorage();
        $service = new PatientService(new FakePatientRepository(1), $tutors, TenantContext::authenticated(1, 1), $storage);
        $patient = $service->create(['tutor_id' => 1, 'name' => 'Rex', 'species' => 'dog']);
        $bytes = "\x89PNG\r\n\x1a\nfake-png-bytes";

        $saved = $service->attachPhoto($patient->id, 'foto.png', $bytes, 'image/png');

        $expectedKey = "tenant/1/patient/{$patient->id}/photo-foto.png";
        Assert::same($expectedKey, $saved->photoObjectKey);
        Assert::same('image/png', $saved->photoContentType);
        Assert::same($bytes, $storage->get($expectedKey));
        Assert::same('image/png', $storage->contentType($expectedKey));
        Assert::same($expectedKey, $service->findById($patient->id)->photoObjectKey);
        Assert::same(['contents' => $bytes, 'content_type' => 'image/png'], $service->photo($patient->id));

        // update() of the clinical data never drops the photo.
        $service->update($patient->id, ['name' => 'Rex', 'species' => 'dog', 'allergies' => 'Dipirona']);
        Assert::same($expectedKey, $service->findById($patient->id)->photoObjectKey);
        Assert::same('image/png', $service->findById($patient->id)->photoContentType);
    }

    public function testAttachPhotoSanitizesFileName(): void
    {
        $tutors = new FakeTutorRepository(1, Tutor::register(tenantId: 1, fullName: 'Ana Souza', phone: '85999990000'));
        $service = new PatientService(new FakePatientRepository(1), $tutors, TenantContext::authenticated(1, 1), new FakeStorage());
        $patient = $service->create(['tutor_id' => 1, 'name' => 'Rex', 'species' => 'dog']);

        $saved = $service->attachPhoto($patient->id, '../minha foto.jpg', 'jpg', 'image/jpeg');

        Assert::same("tenant/1/patient/{$patient->id}/photo-.._minha_foto.jpg", $saved->photoObjectKey);
    }

    public function testAttachPhotoRejectsInvalidInput(): void
    {
        $tutors = new FakeTutorRepository(1, Tutor::register(tenantId: 1, fullName: 'Ana Souza', phone: '85999990000'));
        $storage = new FakeStorage();
        $service = new PatientService(new FakePatientRepository(1), $tutors, TenantContext::authenticated(1, 1), $storage);
        $patient = $service->create(['tutor_id' => 1, 'name' => 'Rex', 'species' => 'dog']);

        foreach ([
            ['Photo must be a JPEG, PNG or WEBP image', [$patient->id, 'doc.pdf', '%PDF', 'application/pdf']],
            ['Photo must be a JPEG, PNG or WEBP image', [$patient->id, 'x.svg', '<svg onload="alert(1)"/>', 'image/svg+xml']],
            ['Photo must be a JPEG, PNG or WEBP image', [$patient->id, 'x.html', '<script>alert(1)</script>', 'text/html']],
            ['Photo must be at most 2 MB', [$patient->id, 'big.png', str_repeat('a', 2 * 1024 * 1024 + 1), 'image/png']],
            ['Patient 999 not found for this tenant', [999, 'foto.png', 'png', 'image/png']],
        ] as [$message, $args]) {
            try {
                $service->attachPhoto(...$args);
                throw new \RuntimeException("attachPhoto() should have thrown '{$message}'");
            } catch (\InvalidArgumentException $e) {
                Assert::same($message, $e->getMessage());
            }
        }

        Assert::false($storage->exists("tenant/1/patient/{$patient->id}/photo-x.svg"));
        Assert::false($storage->exists("tenant/1/patient/{$patient->id}/photo-doc.pdf"));
        Assert::null($service->findById($patient->id)->photoObjectKey);
    }

    public function testAttachPhotoWithoutStorageThrowsLogicException(): void
    {
        $tutors = new FakeTutorRepository(1, Tutor::register(tenantId: 1, fullName: 'Ana Souza', phone: '85999990000'));
        $service = new PatientService(new FakePatientRepository(1), $tutors, TenantContext::authenticated(1, 1));
        $patient = $service->create(['tutor_id' => 1, 'name' => 'Rex', 'species' => 'dog']);

        try {
            $service->attachPhoto($patient->id, 'foto.png', 'png', 'image/png');
            throw new \RuntimeException('attachPhoto() without storage should have thrown');
        } catch (\LogicException $e) {
            Assert::same('Storage not configured', $e->getMessage());
        }
    }

    public function testPhotoReturnsNullWithoutPhotoOrPatient(): void
    {
        $tutors = new FakeTutorRepository(1, Tutor::register(tenantId: 1, fullName: 'Ana Souza', phone: '85999990000'));
        $service = new PatientService(new FakePatientRepository(1), $tutors, TenantContext::authenticated(1, 1), new FakeStorage());
        $patient = $service->create(['tutor_id' => 1, 'name' => 'Rex', 'species' => 'dog']);

        Assert::null($service->photo($patient->id));
        Assert::null($service->photo(999999));
    }
}
