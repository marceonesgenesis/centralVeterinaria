<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Application\PatientService;
use CentralVet\Domain\Contract\PatientRepositoryInterface;
use CentralVet\Domain\Exception\CrossTenantReferenceException;
use CentralVet\Domain\Patient;
use CentralVet\Domain\Tutor;
use CentralVet\Storage\StorageInterface;
use CentralVet\Storage\StoredObjectMetadata;
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

    public function testCreateAndUpdateParseWeightWithDecimalComma(): void
    {
        $tutors = new FakeTutorRepository(1, Tutor::register(tenantId: 1, fullName: 'Ana Souza', phone: '85999990000'));
        $service = new PatientService(new FakePatientRepository(1), $tutors, TenantContext::authenticated(1, 1));

        $created = $service->create(['tutor_id' => 1, 'name' => 'Rex', 'species' => 'dog', 'weight_kg' => '4,5']);
        Assert::same(4.5, $created->weightKg);

        $updated = $service->update($created->id, ['name' => 'Rex', 'species' => 'dog', 'weight_kg' => '4,5']);
        Assert::same(4.5, $updated->weightKg);

        Assert::same(4.5, $service->update($created->id, ['name' => 'Rex', 'species' => 'dog', 'weight_kg' => '4.50'])->weightKg);
        Assert::same(12.0, $service->update($created->id, ['name' => 'Rex', 'species' => 'dog', 'weight_kg' => '12'])->weightKg);

        $blank = $service->update($created->id, ['name' => 'Rex', 'species' => 'dog', 'weight_kg' => '']);
        Assert::null($blank->weightKg);
        Assert::null($service->findById($created->id)->weightKg);
    }

    public function testUpdateRejectsInvalidWeightAndKeepsStoredWeight(): void
    {
        $tutors = new FakeTutorRepository(1, Tutor::register(tenantId: 1, fullName: 'Ana Souza', phone: '85999990000'));
        $service = new PatientService(new FakePatientRepository(1), $tutors, TenantContext::authenticated(1, 1));
        $patient = $service->create(['tutor_id' => 1, 'name' => 'Rex', 'species' => 'dog', 'weight_kg' => 4.5]);

        foreach (['abc', '10000', '4,5kg', '-1', '1.234,5'] as $weight) {
            try {
                $service->update($patient->id, ['name' => 'Rex', 'species' => 'dog', 'weight_kg' => $weight]);
                throw new \RuntimeException("update() with weight_kg '{$weight}' should have thrown");
            } catch (\InvalidArgumentException $e) {
                Assert::same(PatientService::INVALID_WEIGHT_MESSAGE, $e->getMessage());
            }

            Assert::same(4.5, $service->findById($patient->id)->weightKg);
        }

        Assert::throws(
            \InvalidArgumentException::class,
            static fn () => $service->create(['tutor_id' => 1, 'name' => 'Mia', 'species' => 'cat', 'weight_kg' => 'abc']),
        );
        Assert::throws(
            \InvalidArgumentException::class,
            static fn () => $service->update($patient->id, ['name' => 'Rex', 'species' => 'dog', 'weight_kg' => 10000.0]),
        );
    }

    public function testFormatWeightKgShowsDecimalCommaAndRoundTripsThroughUpdate(): void
    {
        Assert::null(PatientService::formatWeightKg(null));
        Assert::same('4,5', PatientService::formatWeightKg(4.5));
        Assert::same('12', PatientService::formatWeightKg(12.0));
        Assert::same('0,8', PatientService::formatWeightKg(0.8));
        Assert::same('12,3', PatientService::formatWeightKg(12.30));
        Assert::same('9999,99', PatientService::formatWeightKg(9999.99));

        $tutors = new FakeTutorRepository(1, Tutor::register(tenantId: 1, fullName: 'Ana Souza', phone: '85999990000'));
        $service = new PatientService(new FakePatientRepository(1), $tutors, TenantContext::authenticated(1, 1));
        $patient = $service->create(['tutor_id' => 1, 'name' => 'Rex', 'species' => 'dog', 'weight_kg' => '0,8']);
        Assert::same(0.8, $patient->weightKg);

        // reopening and saving without touching the field keeps the weight
        foreach ([0.8, 4.5, 12.3, 12.0] as $weight) {
            $shown = PatientService::formatWeightKg($weight);
            $saved = $service->update($patient->id, ['name' => 'Rex', 'species' => 'dog', 'weight_kg' => $shown]);
            Assert::same($weight, $saved->weightKg);
        }
    }

    public function testAttachPhotoStoresUnderTenantKeyAndPhotoReturnsBytes(): void
    {
        $tutors = new FakeTutorRepository(1, Tutor::register(tenantId: 1, fullName: 'Ana Souza', phone: '85999990000'));
        $storage = new FakeStorage();
        $service = new PatientService(new FakePatientRepository(1), $tutors, TenantContext::authenticated(1, 1), $storage);
        $patient = $service->create(['tutor_id' => 1, 'name' => 'Rex', 'species' => 'dog']);
        $bytes = "\x89PNG\r\n\x1a\nfake-png-bytes";

        $saved = $service->attachPhoto($patient->id, 'foto.png', $bytes, 'image/png');

        $expectedKey = $saved->photoObjectKey;
        Assert::true(
            preg_match("#^tenant/1/patient/{$patient->id}/photo-[0-9a-f]{12}-foto\\.png$#", (string) $expectedKey) === 1,
            "unexpected photo key {$expectedKey}",
        );
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

        Assert::true(
            preg_match("#^tenant/1/patient/{$patient->id}/photo-[0-9a-f]{12}-\\.\\._minha_foto\\.jpg$#", (string) $saved->photoObjectKey) === 1,
            "unexpected photo key {$saved->photoObjectKey}",
        );
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

        Assert::same(0, self::storedObjectCount($storage));
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

    public function testAttachPhotoTwiceKeepsPreviousObjectUntilDiscarded(): void
    {
        $tutors = new FakeTutorRepository(1, Tutor::register(tenantId: 1, fullName: 'Ana Souza', phone: '85999990000'));
        $storage = new FakeStorage();
        $service = new PatientService(new FakePatientRepository(1), $tutors, TenantContext::authenticated(1, 1), $storage);
        $patient = $service->create(['tutor_id' => 1, 'name' => 'Rex', 'species' => 'dog']);

        $first = $service->attachPhoto($patient->id, 'foto.png', 'first-bytes', 'image/png');
        Assert::null($service->previousPhotoKey());

        $second = $service->attachPhoto($patient->id, 'foto.png', 'second-bytes', 'image/png');

        Assert::true($first->photoObjectKey !== $second->photoObjectKey, 'same file name must get a new key on each upload');
        // T-47: the previous object survives attachPhoto(); the caller discards
        // it only after the transaction commits.
        Assert::true($storage->exists((string) $first->photoObjectKey));
        Assert::true($storage->exists((string) $second->photoObjectKey));
        Assert::same($first->photoObjectKey, $service->previousPhotoKey());
        Assert::same(['contents' => 'second-bytes', 'content_type' => 'image/png'], $service->photo($patient->id));
    }

    public function testDiscardPhotoRemovesTheObject(): void
    {
        $tutors = new FakeTutorRepository(1, Tutor::register(tenantId: 1, fullName: 'Ana Souza', phone: '85999990000'));
        $storage = new FakeStorage();
        $service = new PatientService(new FakePatientRepository(1), $tutors, TenantContext::authenticated(1, 1), $storage);
        $patient = $service->create(['tutor_id' => 1, 'name' => 'Rex', 'species' => 'dog']);

        $first = $service->attachPhoto($patient->id, 'foto.png', 'first-bytes', 'image/png');
        $second = $service->attachPhoto($patient->id, 'foto.png', 'second-bytes', 'image/png');

        $service->discardPhoto((string) $service->previousPhotoKey());

        Assert::false($storage->exists((string) $first->photoObjectKey));
        Assert::true($storage->exists((string) $second->photoObjectKey));
        Assert::same(1, self::storedObjectCount($storage));
    }

    public function testDiscardPhotoSwallowsStorageFailure(): void
    {
        $tutors = new FakeTutorRepository(1, Tutor::register(tenantId: 1, fullName: 'Ana Souza', phone: '85999990000'));
        $storage = new class implements StorageInterface {
            public int $deleteCalls = 0;

            public function put(string $key, string $contents, string $contentType = 'application/octet-stream'): StoredObjectMetadata
            {
                throw new \LogicException('not used');
            }

            public function get(string $key): string
            {
                throw new \LogicException('not used');
            }

            public function exists(string $key): bool
            {
                return true;
            }

            public function delete(string $key): void
            {
                $this->deleteCalls++;
                throw new \RuntimeException('storage down');
            }

            public function presignedUrl(string $key, int $ttlSeconds = 300): string
            {
                throw new \LogicException('not used');
            }
        };
        $service = new PatientService(new FakePatientRepository(1), $tutors, TenantContext::authenticated(1, 1), $storage);

        // The failure goes to error_log; keep it out of the suite output.
        $previousLog = ini_set('error_log', '/dev/null');
        try {
            $service->discardPhoto('tenant/1/patient/1/photo-aaaaaaaaaaaa-foto.png');
        } finally {
            ini_set('error_log', $previousLog === false ? '' : $previousLog);
        }

        Assert::same(1, $storage->deleteCalls);
    }

    public function testAttachPhotoRemovesNewObjectWhenSaveFails(): void
    {
        $tutors = new FakeTutorRepository(1, Tutor::register(tenantId: 1, fullName: 'Ana Souza', phone: '85999990000'));
        $storage = new FakeStorage();
        $inner = new FakePatientRepository(1);
        $patient = (new PatientService($inner, $tutors, TenantContext::authenticated(1, 1)))
            ->create(['tutor_id' => 1, 'name' => 'Rex', 'species' => 'dog']);

        $failing = new class ($inner) implements PatientRepositoryInterface {
            public function __construct(private readonly FakePatientRepository $inner)
            {
            }

            public function tenantId(): int
            {
                return $this->inner->tenantId();
            }

            public function findById(int|string $id): ?object
            {
                return $this->inner->findById($id);
            }

            public function findByTutor(int $tutorId): array
            {
                return $this->inner->findByTutor($tutorId);
            }

            public function search(string $term): array
            {
                return $this->inner->search($term);
            }

            public function save(object $entity): object
            {
                throw new \RuntimeException('database down');
            }

            public function remove(object $entity): void
            {
                $this->inner->remove($entity);
            }
        };

        $service = new PatientService($failing, $tutors, TenantContext::authenticated(1, 1), $storage);

        try {
            $service->attachPhoto($patient->id, 'foto.png', 'png-bytes', 'image/png');
            throw new \LogicException('attachPhoto() should have rethrown the save() failure');
        } catch (\RuntimeException $e) {
            Assert::same('database down', $e->getMessage());
        }

        Assert::same(0, self::storedObjectCount($storage));
        Assert::null($inner->findById($patient->id)->photoObjectKey);
    }

    public function testCreateNormalizesBlankOptionalFieldsToNull(): void
    {
        $tutors = new FakeTutorRepository(1, Tutor::register(tenantId: 1, fullName: 'Ana Souza', phone: '85999990000'));
        $service = new PatientService(new FakePatientRepository(1), $tutors, TenantContext::authenticated(1, 1));

        $patient = $service->create([
            'tutor_id' => 1,
            'name' => 'Rex',
            'species' => 'dog',
            'breed' => '',
            'sex' => '  ',
            'birth_date' => '',
            'color' => ' ',
            'notes' => '',
            'allergies' => '',
        ]);

        Assert::null($patient->breed);
        Assert::null($patient->sex);
        Assert::null($patient->birthDate);
        Assert::null($patient->color);
        Assert::null($patient->notes);
        Assert::null($patient->allergies);
    }

    public function testPhotoVersionChangesWhenPhotoIsReplaced(): void
    {
        $tutors = new FakeTutorRepository(1, Tutor::register(tenantId: 1, fullName: 'Ana Souza', phone: '85999990000'));
        $service = new PatientService(new FakePatientRepository(1), $tutors, TenantContext::authenticated(1, 1), new FakeStorage());
        $patient = $service->create(['tutor_id' => 1, 'name' => 'Rex', 'species' => 'dog']);

        Assert::null(PatientService::photoVersion($patient));

        $first = $service->attachPhoto($patient->id, 'foto.png', 'first-bytes', 'image/png');
        $second = $service->attachPhoto($patient->id, 'foto.png', 'second-bytes', 'image/png');
        $v1 = PatientService::photoVersion($first);
        $v2 = PatientService::photoVersion($second);

        Assert::true(preg_match('/^[0-9a-f]{12}$/', (string) $v1) === 1, "unexpected photo version {$v1}");
        Assert::true($v1 !== $v2, 'replacing the photo must change its version (cache-busting of the preview URL)');
        Assert::same($v2, PatientService::photoVersion($service->findById($patient->id)));
    }

    /** Number of objects held by the FakeStorage (it has no listing API). */
    private static function storedObjectCount(FakeStorage $storage): int
    {
        return count((new \ReflectionProperty(FakeStorage::class, 'objects'))->getValue($storage));
    }
}
