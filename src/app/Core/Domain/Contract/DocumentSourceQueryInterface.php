<?php

declare(strict_types=1);

namespace CentralVet\Domain\Contract;

/**
 * Read-only queries over the sources of a document (patient, vaccinations,
 * prescription, surgery, tutor contact). Every method is filtered by the
 * tenant of the current context and returns null / [] for rows of another
 * tenant. `prescription` and `surgery` also return the unit, so the
 * service can check it against the active unit.
 */
interface DocumentSourceQueryInterface
{
    /**
     * @return array{patient_id: int, patient_name: string, species: string, breed: ?string, tutor_id: int, tutor_name: string}|null
     */
    public function patientSummary(int $patientId): ?array;

    /**
     * Applied vaccinations of the patient, ordered by `applied_at`.
     *
     * @return list<array{vaccine_name: string, dose_number: int, applied_at: string, lot: ?string, next_dose_at: ?string, professional_name: string}>
     */
    public function vaccinations(int $patientId): array;

    /**
     * @return array{prescription_id: int, patient_id: int, system_unit_id: int, professional_name: string, orientation_text: ?string, created_at: string, items: list<array{medication_name: string, dose: string, dose_unit: string, route: string, frequency: string, duration: string}>}|null
     */
    public function prescription(int $prescriptionId): ?array;

    /**
     * @return array{surgery_id: int, patient_id: int, system_unit_id: int, procedure_name: string, scheduled_start_at: string, surgeon_name: string, consent_signer_name: ?string, consent_text: ?string, consent_recorded_at: ?string}|null
     */
    public function surgery(int $surgeryId): ?array;

    /**
     * @return array{tutor_name: string, email: ?string, phone: string}|null
     */
    public function tutorContact(int $tutorId): ?array;
}
