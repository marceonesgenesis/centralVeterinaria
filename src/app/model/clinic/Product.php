<?php
/**
 * Product
 *
 * Adianti TRecord binding for the `product` table, used only to feed the
 * BootstrapFormBuilder in ProductForm (field metadata / setData()), same
 * role Service.php plays for ServiceForm (Fase 1). All business rules and
 * persistence go through CentralVet\Application\ProductService /
 * CentralVet\Application\StockService (T-03) — this class never calls
 * store()/delete() on its own.
 *
 * PENDING / DO NOT WIRE YET: `product` is created by the not-yet-applied
 * migration src/app/database/migrations/20260924_0005_phase4_procedure_stock_sale.sql.
 * This class is prepared and syntax-checked (php -l) only; no query runs
 * against it until that migration has explicit SQL execution approval and
 * has actually been applied.
 *
 * @version    1.0
 * @package    model
 * @subpackage clinic
 */
class Product extends TRecord
{
    const TABLENAME = 'product';
    const PRIMARYKEY= 'id';
    const IDPOLICY =  'serial'; // {max, serial}

    /**
     * Constructor method
     */
    public function __construct($id = NULL, $callObjectLoad = TRUE)
    {
        parent::__construct($id, $callObjectLoad);
        parent::addAttribute('tenant_id');
        parent::addAttribute('name');
        parent::addAttribute('category');
        parent::addAttribute('unit_of_measure');
        parent::addAttribute('unit_cost_cents');
        parent::addAttribute('minimum_stock_quantity');
        parent::addAttribute('active');
    }
}
