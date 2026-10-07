<?php
/**
 * Service
 *
 * Adianti TRecord binding for the `service` table, used only to feed the
 * BootstrapFormBuilder in ServiceForm (field metadata / setData()). All
 * business rules and persistence go through
 * CentralVet\Application\ServiceCatalogService (T-06) — this class never
 * calls store()/delete() on its own.
 *
 * @version    1.0
 * @package    model
 * @subpackage clinic
 */
class Service extends TRecord
{
    use CvSafeLabelTrait;

    const TABLENAME = 'service';
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
        parent::addAttribute('duration_minutes');
        parent::addAttribute('price_cents');
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
