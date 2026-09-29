<?php
/**
 * Encounter
 *
 * Adianti active-record mapping for the `encounter` table (T-06), kept for
 * framework compatibility (e.g. widget/transformer binding), mirroring the
 * TRecord + addAttribute() pattern used by clinic/Patient.php (T-10) and
 * admin/SystemUnit.php.
 *
 * IMPORTANT: EncounterView does NOT use this class to read or persist
 * clinical data. Every start/autosave/finish/acceptAiSummary flow goes
 * through CentralVet\Application\EncounterService (T-03), which owns the
 * tenant boundary, the unit-scope authorization check and the
 * in_progress -> finished status transition. Using this TRecord directly
 * from a controller would bypass that service and is out of scope for T-06.
 *
 * PENDING: the `encounter` table is created by the not-yet-applied migration
 * src/app/database/migrations/20260922_0003_phase2_encounter.sql (T-01).
 * This class is prepared and syntax-checked (php -l) only.
 *
 * @version    8.6
 * @package    model
 * @subpackage clinic
 */
class Encounter extends TRecord
{
    const TABLENAME = 'encounter';
    const PRIMARYKEY = 'id';
    const IDPOLICY = 'max'; // {max, serial}

    /**
     * Constructor method
     */
    public function __construct($id = NULL, $callObjectLoad = TRUE)
    {
        parent::__construct($id, $callObjectLoad);
        parent::addAttribute('tenant_id');
        parent::addAttribute('system_unit_id');
        parent::addAttribute('patient_id');
        parent::addAttribute('appointment_id');
        parent::addAttribute('professional_system_user_id');
        parent::addAttribute('status');
        parent::addAttribute('started_at');
        parent::addAttribute('finished_at');
        parent::addAttribute('anamnesis_text');
        parent::addAttribute('temperature_c');
        parent::addAttribute('heart_rate_bpm');
        parent::addAttribute('respiratory_rate_mpm');
        parent::addAttribute('weight_kg');
        parent::addAttribute('mucous_membranes');
        parent::addAttribute('capillary_refill_seconds');
        parent::addAttribute('physical_exam_text');
        parent::addAttribute('diagnosis_text');
        parent::addAttribute('clinical_plan_text');
        parent::addAttribute('ai_summary_text');
        parent::addAttribute('ai_summary_accepted_at');
    }
}
