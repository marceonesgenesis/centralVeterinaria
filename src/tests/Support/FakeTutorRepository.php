<?php

declare(strict_types=1);

namespace CentralVet\Tests\Support;

use CentralVet\Domain\Contract\TutorRepositoryInterface;
use CentralVet\Domain\Tutor;
use InvalidArgumentException;

/**
 * In-memory double for TutorRepositoryInterface, used by Application-layer
 * unit tests (T-16) so TutorService/PatientService can be exercised without
 * a real database — the `tutor` table does not exist yet (migration T-01
 * not applied). Mirrors the real TutorRepository's tenant-scoping
 * (ADR 0002): findById()/findByDocument()/search() only ever return tutors
 * whose tenantId matches this instance's own $tenantId, exactly like every
 * query built from AbstractTenantRepository::tenantQuery() would — this is
 * what lets PatientServiceTest prove rejection of a cross-tenant tutor_id
 * without a live database.
 */
final class FakeTutorRepository implements TutorRepositoryInterface
{
    /** @var array<int, Tutor> */
    private array $tutors = [];
    private int $nextId = 1;

    public function __construct(private readonly int $tenantId, Tutor ...$seed)
    {
        foreach ($seed as $tutor) {
            $this->save($tutor);
        }
    }

    public function tenantId(): int
    {
        return $this->tenantId;
    }

    public function findById(int|string $id): ?object
    {
        $tutor = $this->tutors[(int) $id] ?? null;

        if ($tutor === null || $tutor->tenantId !== $this->tenantId) {
            return null;
        }

        return $tutor;
    }

    public function findByDocument(string $document): ?object
    {
        foreach ($this->tutors as $tutor) {
            if ($tutor->tenantId === $this->tenantId && $tutor->document === $document) {
                return $tutor;
            }
        }

        return null;
    }

    /** @return list<Tutor> */
    public function search(string $term): array
    {
        $needle = mb_strtolower($term);

        return array_values(array_filter(
            $this->tutors,
            fn (Tutor $tutor): bool => $tutor->tenantId === $this->tenantId && (
                str_contains(mb_strtolower($tutor->fullName), $needle)
                || ($tutor->document !== null && str_contains($tutor->document, $term))
                || str_contains($tutor->phone, $term)
            ),
        ));
    }

    public function save(object $entity): object
    {
        if (!$entity instanceof Tutor) {
            throw new InvalidArgumentException('FakeTutorRepository only stores Tutor entities');
        }

        $saved = $entity->id !== null ? $entity : $entity->withId($this->nextId++);
        $this->tutors[$saved->id] = $saved;

        return $saved;
    }

    public function remove(object $entity): void
    {
        if ($entity instanceof Tutor && $entity->id !== null) {
            unset($this->tutors[$entity->id]);
        }
    }
}
