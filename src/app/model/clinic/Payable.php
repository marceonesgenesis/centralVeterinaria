<?php
/**
 * Payable
 *
 * Adianti TRecord binding for the `payable` table, used only to feed the
 * BootstrapFormBuilder in PayableForm (field metadata / setData()) and as
 * the session-key namespace for PayableList's filter form, mirroring
 * CentralVet\Domain\Payable's own field set. All business rules and
 * persistence go through CentralVet\Application\PayableService (T-05) —
 * this class never calls store()/delete() on its own.
 *
 * PENDING / DO NOT WIRE YET: `payable` is created by the not-yet-applied
 * migration src/app/database/migrations/20260925_0006_phase5_financial.sql.
 * This class is prepared and syntax-checked (php -l) only.
 *
 * @version    1.0
 * @package    model
 * @subpackage clinic
 */
class Payable extends TRecord
{
    const TABLENAME = 'payable';
    const PRIMARYKEY = 'id';
    const IDPOLICY = 'serial'; // {max, serial}

    /**
     * Constructor method
     */
    public function __construct($id = NULL, $callObjectLoad = TRUE)
    {
        parent::__construct($id, $callObjectLoad);
        parent::addAttribute('tenant_id');
        parent::addAttribute('system_unit_id');
        parent::addAttribute('description_text');
        parent::addAttribute('category');
        parent::addAttribute('amount_cents');
        parent::addAttribute('due_date');
        parent::addAttribute('status');
        parent::addAttribute('paid_at');
        parent::addAttribute('system_user_id');
    }
}
