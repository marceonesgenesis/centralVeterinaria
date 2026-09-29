<?php
/**
 * Prescription
 *
 * Adianti active-record mapping for the `prescription` table (T-06), kept
 * for framework compatibility only (e.g. widget/transformer binding),
 * mirroring the TRecord + addAttribute() pattern used by
 * clinic/Encounter.php and admin/SystemUnit.php.
 *
 * IMPORTANT: PrescriptionForm does NOT use this class to read or persist
 * clinical data. Every create()/findById() flow goes through
 * CentralVet\Application\PrescriptionService (T-03), which owns the tenant
 * boundary and the unit-scope authorization check (against the source
 * encounter). Using this TRecord directly from a controller would bypass
 * that service and is out of scope for T-06.
 *
 * PENDING: the `prescription` table is created by the not-yet-applied
 * migration
 * src/app/database/migrations/20260922_0004_phase3_prescription_exam_vaccine.sql
 * (T-01). This class is prepared and syntax-checked (php -l) only.
 *
 * @version    8.6
 * @package    model
 * @subpackage clinic
 */
class Prescription extends TRecord
{
    const TABLENAME = 'prescription';
    const PRIMARYKEY = 'id';
    const IDPOLICY = 'max'; // {max, serial}

    /**
     * Constructor method
     */
    public function __construct($id = NULL, $callObjectLoad = TRUE)
    {
        parent::__construct($id, $callObjectLoad);
        parent::addAttribute('tenant_id');
        parent::addAttribute('encounter_id');
        parent::addAttribute('patient_id');
        parent::addAttribute('professional_system_user_id');
        parent::addAttribute('orientation_text');
        parent::addAttribute('status');
        parent::addAttribute('created_at');
        parent::addAttribute('updated_at');
    }
}
