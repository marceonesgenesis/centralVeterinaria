<?php

declare(strict_types=1);

namespace CentralVet\Domain\Contract;

use DateTimeImmutable;

/**
 * Read-only clinical data the copilot tools may read, scoped to the tenant
 * AND the active unit of the session. `patient` has no unit: a patient
 * belongs to the unit when it has an encounter or an appointment there.
 * Clinical records (encounters, prescriptions, exams, vaccinations) come
 * only from encounters of the active unit. An id outside that scope reads
 * as "not found" (null or an empty list). Tutor contact, document and
 * address columns are never returned; only `tutor.full_name`.
 */
interface CopilotClinicalQueryInterface
{
    public const EXAMS_PENDING = 'pendentes';
    public const EXAMS_RELEASED = 'liberados';
    public const EXAMS_ALL = 'todos';

    public function patientInUnit(int $patientId): bool;

    public function encounterInUnit(int $encounterId): bool;

    /**
     * Digits-only term = `patient.id`; otherwise `patient.name` or
     * `tutor.full_name` containing the term. Only patients of the unit.
     *
     * @return list<array{id: int, name: string, species: string, breed: ?string, birth_date: ?string, tutor_full_name: string}>
     */
    public function searchPatients(string $term, int $limit): array;

    /**
     * Weight = latest `encounter.weight_kg` of the unit, else `patient.weight_kg`
     * (then `weight_measured_at` is null).
     *
     * @return array{id: int, name: string, species: string, breed: ?string, sex: ?string, birth_date: ?string, allergies: ?string, weight_kg: ?string, weight_measured_at: ?string, tutor_full_name: string}|null
     */
    public function patient(int $patientId): ?array;

    /**
     * Most recent first.
     *
     * @return list<array{id: int, started_at: string, status: string, anamnesis_text: ?string, diagnosis_text: ?string, clinical_plan_text: ?string}>
     */
    public function encounters(int $patientId, int $limit): array;

    /**
     * @return array{id: int, patient_id: int, started_at: string, status: string, anamnesis_text: ?string, physical_exam_text: ?string, diagnosis_text: ?string, clinical_plan_text: ?string, weight_kg: ?string}|null
     */
    public function encounter(int $encounterId): ?array;

    /**
     * One row per prescription item, most recent prescription first. Active =
     * `valid_until >= today`, or `valid_until` null and created in the last
     * 30 days.
     *
     * @return list<array{prescription_id: int, created_at: string, valid_until: ?string, status: string, medication_name: string, dose: ?string, dose_unit: ?string, route: ?string, frequency: ?string, duration: ?string}>
     */
    public function prescriptions(int $patientId, bool $onlyActive, DateTimeImmutable $today, int $limit): array;

    /**
     * $situacao: `pendentes` (no result or `pending_review = 1`), `liberados`
     * (result with `pending_review = 0`) or `todos`; anything else throws
     * InvalidArgumentException. Most recent request first.
     *
     * @return list<array{exam_request_id: int, exam_name: string, requested_at: string, status: string, pending_review: ?bool, structured_result_text: ?string, received_at: ?string}>
     */
    public function exams(int $patientId, string $situacao, int $limit): array;

    /**
     * Most recent first. `superseded` = a later application of the same
     * vaccine to the patient exists (in the unit).
     *
     * @return list<array{vaccine_catalog_item_id: int, vaccine_name: string, applied_at: string, next_dose_at: ?string, superseded: bool}>
     */
    public function vaccinations(int $patientId, int $limit): array;

    /**
     * Full name of the patient's tutor (for the PiiMinimizer); empty when
     * the patient is not of the unit.
     *
     * @return list<string>
     */
    public function tutorNames(int $patientId): array;
}
