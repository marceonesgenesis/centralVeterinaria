<?php

declare(strict_types=1);

namespace CentralVet\Domain\Contract;

/**
 * Read-only clinical context the AI may use, scoped to the current tenant.
 * Returns raw data (including names) so the caller can authorize by unit
 * and minimize PII before anything leaves the system.
 */
interface AiClinicalContextQueryInterface
{
    /**
     * @return array{encounter_id: int, system_unit_id: int, patient_id: int, status: string, anamnesis_text: ?string, patient_name: string, tutor_name: ?string, species: string, breed: ?string, sex: ?string, birth_date: ?string, weight_kg: ?string}|null
     */
    public function encounterContext(int $encounterId): ?array;

    /**
     * Up to 5 of each, most recent first.
     *
     * @return array{encounters: list<array{started_at: string, anamnesis_text: ?string, physical_exam_text: ?string, diagnosis_text: ?string, clinical_plan_text: ?string}>, prescriptions: list<array{created_at: string, items: list<string>}>, exam_results: list<array{received_at: string, exam_name: string, result_text: ?string}>}
     */
    public function patientHistory(int $patientId, ?int $excludeEncounterId): array;

    /**
     * @return array{exam_result_id: int, encounter_id: int, system_unit_id: int, patient_id: int, exam_name: string, result_text: ?string, received_at: string, patient_name: string, tutor_name: ?string, species: string, breed: ?string, sex: ?string, birth_date: ?string}|null
     */
    public function examResultContext(int $examResultId): ?array;
}
