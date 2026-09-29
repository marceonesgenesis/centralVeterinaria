<?php
/**
 * ExamCatalogItem
 *
 * Adianti TRecord binding for the `exam_catalog_item` table (T-07), used
 * only to feed the BootstrapFormBuilder in ExamCatalogForm (field metadata /
 * setData()). All business rules and persistence go through
 * CentralVet\Application\ExamCatalogService (T-04) — this class never calls
 * store()/delete() on its own.
 *
 * PENDING: the `exam_catalog_item` table is created by the not-yet-applied
 * migration src/app/database/migrations/20260922_0004_phase3_prescription_exam_vaccine.sql
 * (T-01). This class is validated only with `php -l` / plain instantiation
 * (no query against the live database) until that migration is applied.
 *
 * @version    1.0
 * @package    model
 * @subpackage clinic
 */
class ExamCatalogItem extends TRecord
{
    const TABLENAME = 'exam_catalog_item';
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
        parent::addAttribute('partner_name');
        parent::addAttribute('price_cents');
        parent::addAttribute('active');
    }
}
