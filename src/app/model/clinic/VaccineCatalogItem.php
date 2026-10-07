<?php
/**
 * VaccineCatalogItem
 *
 * Adianti TRecord binding for the `vaccine_catalog_item` table (T-08), used
 * only to feed the BootstrapFormBuilder in VaccineCatalogForm (field
 * metadata / setData()). All business rules and persistence go through
 * CentralVet\Application\VaccineCatalogService (T-05) — this class never
 * calls store()/delete() on its own.
 *
 * PENDING: the `vaccine_catalog_item` table is created by the not-yet-applied
 * migration src/app/database/migrations/20260922_0004_phase3_prescription_exam_vaccine.sql
 * (T-01). This class is validated only with `php -l` / plain instantiation
 * (no query against the live database) until that migration is applied.
 *
 * @version    1.0
 * @package    model
 * @subpackage clinic
 */
class VaccineCatalogItem extends TRecord
{
    use CvSafeLabelTrait;

    const TABLENAME = 'vaccine_catalog_item';
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
        parent::addAttribute('manufacturer');
        parent::addAttribute('stock_quantity');
        parent::addAttribute('active');
    }

    /**
     * Rótulo escapado para os combos de busca (T-65): {name_safe}.
     */
    public function get_name_safe(): string
    {
        return $this->safeLabel('name');
    }
}
