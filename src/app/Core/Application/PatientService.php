<?php

declare(strict_types=1);

namespace CentralVet\Application;

use CentralVet\Domain\Contract\PatientRepositoryInterface;
use CentralVet\Domain\Contract\TutorRepositoryInterface;
use CentralVet\Domain\Exception\CrossTenantReferenceException;
use CentralVet\Domain\Patient;
use CentralVet\Tenancy\TenantContext;

/**
 * Use cases for the Patient aggregate (T-05).
 *
 * Depends only on Domain contracts and TenantContext — no TPage or any
 * other Adianti class (ADR 0001), so it can run from REST, workers or MCP
 * exactly like from the current Adianti presentation layer.
 */
final class PatientService
{
    public function __construct(
        private readonly PatientRepositoryInterface $patients,
        private readonly TutorRepositoryInterface $tutors,
        private readonly TenantContext $context,
    ) {
    }

    /**
     * @param array{
     *     name: string,
     *     species: string,
     *     breed?: string|null,
     *     sex?: string|null,
     *     birth_date?: string|null,
     *     weight_kg?: float|int|string|null,
     *     color?: string|null,
     *     notes?: string|null,
     *     tutor_id: int|string,
     * } $data
     *
     * @throws CrossTenantReferenceException when tutor_id does not resolve
     *         within the authenticated tenant (missing or belongs to
     *         another tenant — see CrossTenantReferenceException docblock).
     */
    public function create(array $data): Patient
    {
        $tutorId = (int) $data['tutor_id'];

        // TutorRepository is tenant-aware (ADR 0002): every query it runs
        // starts with `tenant_id = :tenant_scope_id`, so findById() returns
        // null both when the tutor does not exist and when it belongs to a
        // different tenant. That ambiguity is intentional (fail closed) and
        // is exactly what lets us reject a cross-tenant tutor_id here
        // without a separate "which tenant owns this tutor" lookup.
        if ($this->tutors->findById($tutorId) === null) {
            throw new CrossTenantReferenceException(
                "tutor_id {$tutorId} was not found for the authenticated tenant"
            );
        }

        $patient = new Patient(
            id: null,
            tenantId: $this->context->tenantId(),
            tutorId: $tutorId,
            name: (string) $data['name'],
            species: (string) $data['species'],
            breed: isset($data['breed']) ? (string) $data['breed'] : null,
            sex: isset($data['sex']) ? (string) $data['sex'] : null,
            birthDate: isset($data['birth_date']) ? (string) $data['birth_date'] : null,
            weightKg: isset($data['weight_kg']) ? (float) $data['weight_kg'] : null,
            color: isset($data['color']) ? (string) $data['color'] : null,
            notes: isset($data['notes']) ? (string) $data['notes'] : null,
        );

        /** @var Patient $saved */
        $saved = $this->patients->save($patient);

        return $saved;
    }

    public function findById(int $id): ?Patient
    {
        /** @var Patient|null $patient */
        $patient = $this->patients->findById($id);

        return $patient;
    }

    /** @return list<Patient> */
    public function findByTutor(int $tutorId): array
    {
        return $this->patients->findByTutor($tutorId);
    }

    /**
     * Searches patients by name within the current tenant (T-14: backs the
     * global search alongside TutorService::search()). Mirrors
     * TutorService::search()'s empty-term short-circuit: an empty (or
     * whitespace-only) term never reaches the repository.
     *
     * @return list<Patient>
     */
    public function search(string $query): array
    {
        $term = trim($query);

        if ($term === '') {
            return [];
        }

        /** @var list<Patient> $results */
        $results = $this->patients->search($term);

        return $results;
    }
}
