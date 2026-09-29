<?php
/**
 * Receivable
 *
 * Adianti TRecord binding for the `receivable` table (T-04), used only as
 * the session-key namespace for PendingReceivableList's filter form
 * (setActiveRecord()/AdiantiStandardCollectionTrait::onSearch()), mirroring
 * CentralVet\Domain\Receivable's own field set. All business rules and
 * persistence go through CentralVet\Application\PaymentService (T-06,
 * already implemented) — this class never calls store()/delete() on its
 * own, and PendingReceivableList never queries the `receivable` table
 * through it either (every row comes from
 * PaymentService::listOpenReceivables()).
 *
 * The `receivable` table was created by migration
 * src/app/database/migrations/20260925_0006_phase5_financial.sql, already
 * applied (Fase 5).
 *
 * @version    1.0
 * @package    model
 * @subpackage clinic
 */
class Receivable extends TRecord
{
    const TABLENAME = 'receivable';
    const PRIMARYKEY = 'id';
    const IDPOLICY = 'serial'; // {max, serial}

    /**
     * Constructor method
     */
    public function __construct($id = NULL, $callObjectLoad = TRUE)
    {
        parent::__construct($id, $callObjectLoad);
        parent::addAttribute('tenant_id');
        parent::addAttribute('encounter_account_id');
        parent::addAttribute('tutor_id');
        parent::addAttribute('total_cents');
        parent::addAttribute('paid_cents');
        parent::addAttribute('status');
        parent::addAttribute('created_at');
        parent::addAttribute('updated_at');
    }
}
