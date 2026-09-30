<?php

declare(strict_types=1);

namespace CentralVet\Application;

use CentralVet\Domain\Contract\PatientRepositoryInterface;
use CentralVet\Domain\Contract\TutorRepositoryInterface;
use CentralVet\Domain\Exception\CrossTenantReferenceException;
use CentralVet\Domain\Patient;
use CentralVet\Storage\StorageInterface;
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
    /** Content types accepted by attachPhoto() (rodada 2, T-12). */
    public const PHOTO_CONTENT_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    /** Largest photo attachPhoto() accepts, in bytes (2 MB). */
    public const PHOTO_MAX_BYTES = 2 * 1024 * 1024;

    /** Message of the InvalidArgumentException for an unparseable weight_kg (T-27). */
    public const INVALID_WEIGHT_MESSAGE = 'weight_kg must be a number between 0 and 9999.99, e.g. 4,5';

    /** Largest weight_kg the column patient.weight_kg decimal(6,2) holds. */
    private const MAX_WEIGHT_KG = 9999.99;

    /**
     * $storage is optional so the existing 3-argument callers keep working;
     * only attachPhoto()/photo() need it (LogicException when absent).
     */
    public function __construct(
        private readonly PatientRepositoryInterface $patients,
        private readonly TutorRepositoryInterface $tutors,
        private readonly TenantContext $context,
        private readonly ?StorageInterface $storage = null,
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
     *     allergies?: string|null,
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
            breed: self::optional($data, 'breed'),
            sex: self::optional($data, 'sex'),
            birthDate: self::optional($data, 'birth_date'),
            weightKg: self::parseWeightKg($data['weight_kg'] ?? null),
            color: self::optional($data, 'color'),
            notes: self::optional($data, 'notes'),
            allergies: self::optional($data, 'allergies'),
        );

        /** @var Patient $saved */
        $saved = $this->patients->save($patient);

        return $saved;
    }

    /**
     * Updates the clinical data of an existing patient of the current tenant
     * (rodada 2, T-07). The tutor never changes here: any `tutor_id` in
     * $data is ignored, and id, tenantId, tutorId and createdAt are carried
     * over from the stored patient. Optional fields map '' to null. The
     * photo (photoObjectKey/photoContentType) is never changed here, only by
     * attachPhoto() (T-12).
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
     *     allergies?: string|null,
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

        $weight = self::parseWeightKg($data['weight_kg'] ?? null);

        $patient = new Patient(
            id: $current->id,
            tenantId: $current->tenantId,
            tutorId: $current->tutorId,
            name: $name,
            species: $species,
            breed: self::optional($data, 'breed'),
            sex: self::optional($data, 'sex'),
            birthDate: self::optional($data, 'birth_date'),
            weightKg: $weight,
            color: self::optional($data, 'color'),
            notes: self::optional($data, 'notes'),
            createdAt: $current->createdAt,
            allergies: self::optional($data, 'allergies'),
            photoObjectKey: $current->photoObjectKey,
            photoContentType: $current->photoContentType,
        );

        /** @var Patient $saved */
        $saved = $this->patients->save($patient);

        return $saved;
    }

    /**
     * Stores the patient's photo in object storage under
     * `tenant/<tenantId>/patient/<patientId>/photo-<12 hex>-<sanitized name>`
     * (a new key per upload) and then records the key and content type on
     * the patient (rodada 2, T-12). After the row is saved the previous
     * photo object is deleted; if the save fails the new object is deleted
     * and the exception rethrown (T-32).
     *
     * @throws \LogicException when no storage was injected
     * @throws \InvalidArgumentException when the patient is missing (or of
     *         another tenant), the content type is not JPEG/PNG/WEBP, or the
     *         file is larger than 2 MB
     */
    public function attachPhoto(int $patientId, string $fileName, string $contents, string $contentType): Patient
    {
        if ($this->storage === null) {
            throw new \LogicException('Storage not configured');
        }

        $current = $this->findById($patientId);

        if ($current === null) {
            throw new \InvalidArgumentException("Patient {$patientId} not found for this tenant");
        }

        if (!in_array($contentType, self::PHOTO_CONTENT_TYPES, true)) {
            throw new \InvalidArgumentException('Photo must be a JPEG, PNG or WEBP image');
        }

        if (strlen($contents) > self::PHOTO_MAX_BYTES) {
            throw new \InvalidArgumentException('Photo must be at most 2 MB');
        }

        // A fresh random segment per upload: re-sending the same file name
        // must not reuse the key, or the browser cache (onPhoto sends
        // Cache-Control max-age) keeps showing the previous photo (T-32).
        $key = sprintf(
            'tenant/%d/patient/%d/photo-%s-%s',
            $this->context->tenantId(),
            $patientId,
            bin2hex(random_bytes(6)),
            self::sanitizeFileName($fileName),
        );
        $previousKey = $current->photoObjectKey;

        $this->storage->put($key, $contents, $contentType);

        $patient = new Patient(
            id: $current->id,
            tenantId: $current->tenantId,
            tutorId: $current->tutorId,
            name: $current->name,
            species: $current->species,
            breed: $current->breed,
            sex: $current->sex,
            birthDate: $current->birthDate,
            weightKg: $current->weightKg,
            color: $current->color,
            notes: $current->notes,
            createdAt: $current->createdAt,
            updatedAt: $current->updatedAt,
            allergies: $current->allergies,
            photoObjectKey: $key,
            photoContentType: $contentType,
        );

        try {
            /** @var Patient $saved */
            $saved = $this->patients->save($patient);
        } catch (\Throwable $e) {
            // The row still points at the previous photo: drop the object we
            // just wrote so it does not stay orphaned in the bucket.
            $this->deleteQuietly($key);

            throw $e;
        }

        if ($previousKey !== null && $previousKey !== $key) {
            $this->deleteQuietly($previousKey);
        }

        return $saved;
    }

    /** Best-effort storage delete: a failure is logged, never thrown. */
    private function deleteQuietly(string $key): void
    {
        try {
            $this->storage?->delete($key);
        } catch (\Throwable $e) {
            error_log(sprintf('PatientService: could not delete photo object "%s": %s', $key, $e->getMessage()));
        }
    }

    /**
     * The patient's photo bytes and content type, or null when the patient
     * does not exist in this tenant or has no photo.
     *
     * @return array{contents: string, content_type: string}|null
     *
     * @throws \LogicException when the patient has a photo but no storage was injected
     */
    public function photo(int $patientId): ?array
    {
        $patient = $this->findById($patientId);

        if ($patient === null || $patient->photoObjectKey === null) {
            return null;
        }

        if ($this->storage === null) {
            throw new \LogicException('Storage not configured');
        }

        return [
            'contents' => $this->storage->get($patient->photoObjectKey),
            'content_type' => $patient->photoContentType ?? 'application/octet-stream',
        ];
    }

    private static function sanitizeFileName(string $fileName): string
    {
        $safe = (string) preg_replace('/[^A-Za-z0-9_.\-]+/', '_', $fileName);

        return $safe === '' ? '_' : $safe;
    }

    /**
     * Single conversion of weight_kg for create() and update() (T-27):
     * null/blank → null; int/float as is; a string of up to 4 digits with
     * an optional 1–2 decimal part after '.' or ',' ("4,5", "4.50", "12").
     * Anything else, or a value outside 0..9999.99, is rejected.
     *
     * @throws \InvalidArgumentException with INVALID_WEIGHT_MESSAGE
     */
    private static function parseWeightKg(mixed $value): ?float
    {
        if ($value === null) {
            return null;
        }

        if (is_int($value) || is_float($value)) {
            $weight = (float) $value;
        } elseif (is_string($value)) {
            $value = trim($value);
            if ($value === '') {
                return null;
            }
            if (preg_match('/^\d{1,4}([.,]\d{1,2})?$/', $value) !== 1) {
                throw new \InvalidArgumentException(self::INVALID_WEIGHT_MESSAGE);
            }
            $weight = (float) str_replace(',', '.', $value);
        } else {
            throw new \InvalidArgumentException(self::INVALID_WEIGHT_MESSAGE);
        }

        if (!is_finite($weight) || $weight < 0 || $weight > self::MAX_WEIGHT_KG) {
            throw new \InvalidArgumentException(self::INVALID_WEIGHT_MESSAGE);
        }

        return $weight;
    }

    /**
     * weight_kg as the form shows it (T-27, correção 1): decimal comma, no
     * thousands separator, trailing zeros dropped (4.5 → "4,5", 12.0 → "12").
     * The result parses back to the same value through parseWeightKg().
     */
    public static function formatWeightKg(?float $weightKg): ?string
    {
        if ($weightKg === null) {
            return null;
        }

        $text = number_format($weightKg, 2, ',', '');

        return rtrim(rtrim($text, '0'), ',');
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
