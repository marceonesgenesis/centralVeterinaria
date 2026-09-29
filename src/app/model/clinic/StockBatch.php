<?php
/**
 * StockBatch
 *
 * Adianti TRecord binding for the `stock_batch` table, used only to feed
 * the BootstrapFormBuilder in StockBatchForm (field metadata / setData()),
 * same role Service.php/Product.php play for their own TStandardForm
 * screens. All business rules and persistence go through
 * CentralVet\Application\StockService::receiveBatch() (T-03) — this class
 * never calls store()/delete() on its own.
 *
 * PENDING / DO NOT WIRE YET: `stock_batch` is created by the not-yet-applied
 * migration src/app/database/migrations/20260924_0005_phase4_procedure_stock_sale.sql.
 * This class is prepared and syntax-checked (php -l) only; no query runs
 * against it until that migration has explicit SQL execution approval and
 * has actually been applied.
 *
 * @version    1.0
 * @package    model
 * @subpackage clinic
 */
class StockBatch extends TRecord
{
    const TABLENAME = 'stock_batch';
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
        parent::addAttribute('product_id');
        parent::addAttribute('lot');
        parent::addAttribute('expiry_date');
        parent::addAttribute('quantity');
        parent::addAttribute('received_at');
    }
}
