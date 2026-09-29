<?php
/**
 * CashSession
 *
 * Adianti TRecord binding for the `cash_session` table (T-01), used only for
 * read access: CashSessionList's history grid (T-08, default TStandardList
 * grid bound straight to this active record, same shape as SystemUnitList)
 * and CashSessionForm's own "is there an open session for this unit?" state
 * check (via TRepository/TCriteria, same precedent as
 * SystemPostCommentList::onReload()). Every state-changing operation goes
 * through CentralVet\Application\CashSessionService (T-04) instead — this
 * class never calls store()/delete() on its own.
 *
 * @version    1.0
 * @package    model
 * @subpackage clinic
 */
class CashSession extends TRecord
{
    const TABLENAME = 'cash_session';
    const PRIMARYKEY= 'id';
    const IDPOLICY =  'serial'; // {max, serial}

    /**
     * Constructor method
     */
    public function __construct($id = NULL, $callObjectLoad = TRUE)
    {
        parent::__construct($id, $callObjectLoad);
        parent::addAttribute('tenant_id');
        parent::addAttribute('system_unit_id');
        parent::addAttribute('opened_by_system_user_id');
        parent::addAttribute('opening_balance_cents');
        parent::addAttribute('closed_by_system_user_id');
        parent::addAttribute('closing_balance_cents');
        parent::addAttribute('status');
        parent::addAttribute('opened_at');
        parent::addAttribute('closed_at');
        parent::addAttribute('created_at');
    }
}
