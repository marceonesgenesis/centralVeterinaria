<?php
/**
 * ProcedureCatalogItem
 *
 * Adianti TRecord binding for the `procedure_catalog_item` table (T-08),
 * used only to feed the BootstrapFormBuilder in ProcedureCatalogForm (field
 * metadata / setData()). All business rules and persistence go through
 * CentralVet\Application\ProcedureCatalogService (T-04) — this class never
 * calls store()/delete() on its own.
 *
 * PENDING: the `procedure_catalog_item` table is created by the migration
 * src/app/database/migrations/20260924_0005_phase4_procedure_stock_sale.sql
 * (T-01). This class is validated only with `php -l` / plain instantiation
 * (no query against the live database) until that migration is applied.
 *
 * @version    1.0
 * @package    model
 * @subpackage clinic
 */
class ProcedureCatalogItem extends TRecord
{
    const TABLENAME = 'procedure_catalog_item';
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
        parent::addAttribute('price_cents');
        parent::addAttribute('duration_minutes');
        parent::addAttribute('preparation_text');
        parent::addAttribute('active');
    }
}
