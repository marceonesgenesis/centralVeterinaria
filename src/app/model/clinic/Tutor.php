<?php
/**
 * Tutor
 *
 * Adianti active-record mirror of the `tutor` table
 * (src/app/database/migrations/20260921_0002_phase1_clinic_core.sql),
 * following the same plain TRecord + addAttribute() pattern used by
 * SystemUnit (src/app/model/admin/SystemUnit.php).
 *
 * This class exists only to bind form fields (BootstrapFormBuilder
 * setData()/getData()) in the clinic/Tutor* controllers. It carries no
 * business logic and its persistence methods (load()/store()/delete())
 * must not be called from the controllers: every read and write of a
 * tutor goes through CentralVet\Application\TutorService (T-04), which in
 * turn uses CentralVet\Persistence\TutorRepository — never this class —
 * to talk to the database.
 *
 * @package    model
 * @subpackage clinic
 */
class Tutor extends TRecord
{
    const TABLENAME = 'tutor';
    const PRIMARYKEY = 'id';
    const IDPOLICY = 'serial'; // {max, serial} — id is AUTO_INCREMENT in the migration

    /**
     * Constructor method
     */
    public function __construct($id = NULL, $callObjectLoad = TRUE)
    {
        parent::__construct($id, $callObjectLoad);
        parent::addAttribute('tenant_id');
        parent::addAttribute('public_id');
        parent::addAttribute('full_name');
        parent::addAttribute('document');
        parent::addAttribute('phone');
        parent::addAttribute('email');
        parent::addAttribute('address');
        parent::addAttribute('created_at');
        parent::addAttribute('updated_at');
    }
}
