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

    /**
     * Updates the clinical data of an existing patient of the current tenant
     * (rodada 2, T-07). The tutor never changes here: any `tutor_id` in
     * $data is ignored, and id, tenantId, tutorId and createdAt are carried
     * over from the stored patient. Optional fields map '' to null.
     *
     * @param array{
     *     name: string,
     *     species: string,
     *     breed?: string|null,
     *     sex?: string|null,
     *     birth_date?: string|null,
     *     weight_kg?: float|int|string|null,
     *     color?: string|null,
     *     notes?: string|null,
     * } $data
     *
     * @throws \InvalidArgumentException when the patient is missing (or
     *         belongs to another tenant), a required field is blank, or the
     *         entity rejects sex/weight_kg.
     */
    public function update(int $id, array $data): Patient
    {
        $current = $this->findById($id);

        if ($current === null) {
            throw new \InvalidArgumentException("Patient {$id} not found for this tenant");
        }

        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw new \InvalidArgumentException('name is required');
        }

        $species = trim((string) ($data['species'] ?? ''));
        if ($species === '') {
            throw new \InvalidArgumentException('species is required');
        }

        $weight = self::optional($data, 'weight_kg');

        $patient = new Patient(
            id: $current->id,
            tenantId: $current->tenantId,
            tutorId: $current->tutorId,
            name: $name,
            species: $species,
            breed: self::optional($data, 'breed'),
            sex: self::optional($data, 'sex'),
            birthDate: self::optional($data, 'birth_date'),
            weightKg: $weight !== null ? (float) $weight : null,
            color: self::optional($data, 'color'),
            notes: self::optional($data, 'notes'),
            createdAt: $current->createdAt,
        );

        /** @var Patient $saved */
        $saved = $this->patients->save($patient);

        return $saved;
    }

    /** Optional field of $data as a string, with null/'' (after trim) → null. */
    private static function optional(array $data, string $key): ?string
    {
        if (!isset($data[$key])) {
            return null;
        }

        $value = trim((string) $data[$key]);

        return $value === '' ? null : $value;
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
