<?php
/**
 * Patient
 *
 * Adianti active-record mapping for the `patient` table (T-10), kept for
 * framework compatibility (e.g. widget/transformer binding), mirroring the
 * TRecord + addAttribute() pattern used by admin/SystemUnit.php.
 *
 * IMPORTANT: PatientForm and PatientList do NOT use this class to read or
 * persist data. Every create/read flow goes through
 * CentralVet\Application\PatientService (T-05), which owns the tenant
 * boundary and cross-tenant tutor_id validation rules. Using this TRecord
 * directly from a controller would bypass that service and is out of scope
 * for T-10.
 *
 * PENDING: the `patient` table is created by the not-yet-applied migration
 * src/app/database/migrations/20260921_0002_phase1_clinic_core.sql (T-01).
 * This class is prepared and syntax-checked (php -l) only.
 *
 * @version    8.6
 * @package    model
 * @subpackage clinic
 */
class Patient extends TRecord
{
    const TABLENAME = 'patient';
    const PRIMARYKEY = 'id';
    const IDPOLICY = 'max'; // {max, serial}

    /**
     * Constructor method
     */
    public function __construct($id = NULL, $callObjectLoad = TRUE)
    {
        parent::__construct($id, $callObjectLoad);
        parent::addAttribute('tenant_id');
        parent::addAttribute('tutor_id');
        parent::addAttribute('name');
        parent::addAttribute('species');
        parent::addAttribute('breed');
        parent::addAttribute('sex');
        parent::addAttribute('birth_date');
        parent::addAttribute('weight_kg');
        parent::addAttribute('color');
        parent::addAttribute('notes');
    }
}
