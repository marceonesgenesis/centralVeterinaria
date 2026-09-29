<?php
/**
 * ProcedureInputForm
 *
 * Bill-of-materials ("inputs") editor for one procedure catalog item (T-08):
 * receives procedure_catalog_item_id by parameter
 * (?procedure_catalog_item_id=..., mirroring VaccineProtocolForm's
 * vaccine_catalog_item_id-by-parameter pattern from Fase 3) and lets the
 * user add product_id + quantity_per_execution rows, consuming
 * CentralVet\Application\ProcedureCatalogService (T-04) and
 * CentralVet\Application\ProductService (T-03) entirely.
 *
 * Extends TPage (not TStandardForm): an input row has no independent "edit"
 * semantics — ProcedureCatalogItemInput is immutable once created and
 * ProcedureCatalogService only exposes addInput()/listInputs() for this
 * aggregate, so this screen combines a small entry form with an embedded
 * read-only listing, the same shape VaccineProtocolForm uses for a listing
 * scoped by a related id. There is no delete action here for the same
 * reason ExamCatalogList/VaccineCatalogList have none: the Application
 * service exposes no removal method for this aggregate.
 *
 * The product_id field is a TCombo populated exclusively from
 * CentralVet\Application\ProductService::listActive() (T-03) — this
 * controller never touches the Product/ProcedureCatalogItemInput
 * Persistence/Domain layers directly.
 *
 * PENDING: this screen depends on the `procedure_catalog_item_input` and
 * `product` tables created by the migration
 * src/app/database/migrations/20260924_0005_phase4_procedure_stock_sale.sql
 * (T-01). Validated only with `php -l` / `new ProcedureInputForm()` (no
 * fatal error) until that migration is applied.
 *
 * @version    1.0
 * @package    control
 * @subpackage clinic
 */
class ProcedureInputForm extends TPage
{
    protected $procedure_catalog_item_id;
    protected $form;
    protected $datagrid;
    protected $panel;

    /**
     * Class constructor
     */
    public function __construct($param = null)
    {
        parent::__construct();

        parent::setTargetContainer('adianti_right_panel');

        $this->procedure_catalog_item_id = (isset($param['procedure_catalog_item_id']) && $param['procedure_catalog_item_id'] !== '')
            ? (int) $param['procedure_catalog_item_id']
            : null;

        // creates the entry form
        $this->form = new BootstrapFormBuilder('form_ProcedureCatalogItemInput');
        $this->form->setFormTitle(_t('Procedure inputs'));
        $this->form->enableClientValidation();

        $procedure_catalog_item_id = new TEntry('procedure_catalog_item_id');
        $product_id = new TCombo('product_id');
        $quantity_per_execution = new TEntry('quantity_per_execution');

        $procedure_catalog_item_id->setValue($this->procedure_catalog_item_id);
        $procedure_catalog_item_id->setEditable(FALSE);
        $quantity_per_execution->setNumericMask(0, '', '');

        $this->form->addFields( [new TLabel(_t('Procedure (catalog item id)'))] );
        $this->form->addFields( [$procedure_catalog_item_id] );
        $this->form->addFields( [new TLabel(_t('Product'))] );
        $this->form->addFields( [$product_id] );
        $this->form->addFields( [new TLabel(_t('Quantity per execution'))] );
        $this->form->addFields( [$quantity_per_execution] );

        $procedure_catalog_item_id->setSize('30%');
        $product_id->setSize('100%');
        $quantity_per_execution->setSize('30%');

        $product_id->addValidation( _t('Product'), new TRequiredValidator );
        $quantity_per_execution->addValidation( _t('Quantity per execution'), new TRequiredValidator );

        $btn = $this->form->addAction(_t('Add input'), new TAction(array($this, 'onSave')), 'fa:check');
        $btn->class = 'btn btn-sm btn-primary';

        $this->form->addHeaderActionLink(_t('Close'), new TAction([$this, 'onClose']), 'fa:times red');

        // creates the inputs listing
        $this->datagrid = new BootstrapDatagridWrapper(new TDataGrid);
        $this->datagrid->style = 'width: 100%';
        $this->datagrid->setHeight(240);

        $column_product  = new TDataGridColumn('product_label', _t('Product'), 'left');
        $column_quantity = new TDataGridColumn('quantity_per_execution', _t('Quantity per execution'), 'center', 140);

        $this->datagrid->addColumn($column_product);
        $this->datagrid->addColumn($column_quantity);

        $this->datagrid->createModel();

        $this->panel = new TPanelGroup(_t('Inputs (bill of materials)'));
        $this->panel->add($this->datagrid);

        // page header (design system: .cv-page-header/.cv-page-title, T-04)
        $header = new TElement('header');
        $header->class = 'cv-page-header';

        $header_text = new TElement('div');
        $header_title = new TElement('h1');
        $header_title->class = 'cv-page-title';
        $header_title->add(_t('Procedure inputs'));
        $header_text->add($header_title);

        $header->add($header_text);

        // vertical box container
        $container = new TVBox;
        $container->style = 'width: 100%';
        $container->add($header);
        $container->add($this->form);
        $container->add($this->panel);

        parent::add($container);

        $this->loadProductOptions($product_id);
        $this->onReload($param);
    }

    /**
     * on close
     */
    public static function onClose($param)
    {
        TScript::create("Template.closeRightPanel()");
    }

    /**
     * method onEdit()
     * The constructor already builds the full page (entry form + inputs
     * listing) straight from $param; this method only needs to exist so the
     * "Inputs" row action wired in ProcedureCatalogList
     * (`new TAction(['ProcedureInputForm', 'onEdit'], ...)`) resolves to a
     * valid callback for Adianti's router, mirroring the role
     * VaccineProtocolForm::onEdit() plays for a plain TPage (Fase 3).
     */
    public function onEdit($param)
    {
    }

    /**
     * Populates the product_id combo exclusively from
     * CentralVet\Application\ProductService::listActive() (T-03).
     */
    private function loadProductOptions(TCombo $product_id)
    {
        try
        {
            TTransaction::open('permission');

            $tenant_context = self::resolveTenantContext();
            $service = self::buildProductService($tenant_context);

            $products = $service->listActive($tenant_context->tenantId());

            TTransaction::close();

            $items = [];
            foreach ($products as $product)
            {
                $items[$product->id()] = $product->name();
            }

            $product_id->addItems($items);
        }
        catch (\CentralVet\Tenancy\Exception\MissingTenantContext $e)
        {
            TTransaction::rollback();
            new TMessage('error', _t('Your session does not have an active tenant. Please log in again'));
        }
        catch (\CentralVet\Authorization\Exception\AuthorizationDenied $e)
        {
            TTransaction::rollback();
            new TMessage('error', _t('You are not allowed to perform this action'));
        }
        catch (Exception $e) // never let it escape as a fatal error
        {
            TTransaction::rollback();
            new TMessage('error', $e->getMessage());
        }
    }

    /**
     * method onReload()
     * Reloads the inputs listing from
     * ProcedureCatalogService::listInputs(). Never lets a domain/tenancy
     * exception escape as a fatal error — every failure is translated into
     * a treated TMessage.
     */
    public function onReload($param = null)
    {
        try
        {
            if (empty($this->procedure_catalog_item_id))
            {
                return;
            }

            $this->datagrid->clear();

            TTransaction::open('permission');

            $tenant_context = self::resolveTenantContext();
            $catalog = self::buildProcedureCatalogService($tenant_context);
            $inputs = $catalog->listInputs($this->procedure_catalog_item_id);

            $product_service = self::buildProductService($tenant_context);
            $products = $product_service->listActive($tenant_context->tenantId());

            TTransaction::close();

            $product_names = [];
            foreach ($products as $product)
            {
                $product_names[$product->id()] = $product->name();
            }

            foreach ($inputs as $input)
            {
                $row = new stdClass;
                $row->id                      = $input->id();
                $row->product_label           = $product_names[$input->productId()] ?? ('#' . $input->productId());
                $row->quantity_per_execution  = $input->quantityPerExecution();

                $this->datagrid->addItem($row);
            }
        }
        catch (\CentralVet\Tenancy\Exception\MissingTenantContext $e)
        {
            TTransaction::rollback();
            new TMessage('error', _t('Your session does not have an active tenant. Please log in again'));
        }
        catch (\CentralVet\Authorization\Exception\AuthorizationDenied $e)
        {
            TTransaction::rollback();
            new TMessage('error', _t('You are not allowed to perform this action'));
        }
        catch (Exception $e) // never let it escape as a fatal error
        {
            TTransaction::rollback();
            new TMessage('error', $e->getMessage());
        }
    }

    /**
     * method onSave()
     * Adds one input to the bill of materials through
     * ProcedureCatalogService::addInput(). No validation/decision is made
     * here: quantity_per_execution >= 1 is enforced inside
     * ProcedureCatalogItemInput::create(), reached only through the
     * Application service.
     */
    public function onSave($param = null)
    {
        try
        {
            $data = $this->form->getData();

            $this->form->validate();

            if (empty($data->procedure_catalog_item_id))
            {
                throw new Exception(_t('A procedure catalog item is required'));
            }

            TTransaction::open('permission');

            $catalog = self::buildProcedureCatalogService(self::resolveTenantContext());

            $catalog->addInput(
                (int) $data->procedure_catalog_item_id,
                (int) $data->product_id,
                (int) $data->quantity_per_execution
            );

            TTransaction::close();

            new TMessage('info', _t('Input added'));

            $this->form->clear();
            $this->form->setData((object) ['procedure_catalog_item_id' => $this->procedure_catalog_item_id]);

            $this->onReload($param);
        }
        catch (\CentralVet\Domain\Exception\CrossTenantReferenceException $e)
        {
            TTransaction::rollback();
            new TMessage('error', $e->getMessage());
        }
        catch (\CentralVet\Authorization\Exception\AuthorizationDenied $e)
        {
            TTransaction::rollback();
            new TMessage('error', _t('You are not allowed to perform this action'));
        }
        catch (Exception $e) // in case of exception, never let it escape as a fatal error
        {
            $this->form->setData($data ?? null);
            new TMessage('error', $e->getMessage());
            TTransaction::rollback();
        }
    }

    /**
     * Builds the ProcedureCatalogService with its dependencies. Requires an
     * already-open TTransaction('permission') connection.
     */
    private static function buildProcedureCatalogService($tenant_context)
    {
        $connection = TTransaction::get();

        $catalog_repository = new \CentralVet\Persistence\ProcedureCatalogRepository($tenant_context, $connection);
        $inputs_repository  = new \CentralVet\Persistence\ProcedureCatalogItemInputRepository($tenant_context, $connection);

        return new \CentralVet\Application\ProcedureCatalogService($catalog_repository, $inputs_repository, $tenant_context);
    }

    /**
     * Builds the ProductService with its dependencies. Requires an
     * already-open TTransaction('permission') connection.
     */
    private static function buildProductService($tenant_context)
    {
        $connection = TTransaction::get();

        $repository = new \CentralVet\Persistence\ProductRepository($tenant_context, $connection);

        return new \CentralVet\Application\ProductService($repository, $tenant_context);
    }

    /**
     * Resolves the tenant context of the authenticated session (T-03).
     * Falls back to the tenant_user membership table for legacy sessions
     * created before this task, since TSession does not carry 'tenantid'
     * yet (LoginForm.php / ApplicationAuthenticationService::loadSessionVars()
     * are out of scope for this task).
     */
    private static function resolveTenantContext()
    {
        $source = new \CentralVet\Tenancy\AdiantiSessionContextSource();

        try
        {
            return \CentralVet\Tenancy\TenantContext::fromAuthenticatedSession($source);
        }
        catch (\CentralVet\Tenancy\Exception\MissingTenantContext $e)
        {
            $userid = TSession::getValue('userid');

            if (TSession::getValue('logged') !== true || empty($userid))
            {
                throw $e;
            }

            TTransaction::open('permission');
            $stmt = TTransaction::get()->prepare('SELECT tenant_id FROM tenant_user WHERE system_user_id = :userid ORDER BY id ASC LIMIT 1');
            $stmt->execute(['userid' => (int) $userid]);
            $tenant_id = $stmt->fetchColumn();
            TTransaction::close();

            if (empty($tenant_id))
            {
                throw $e;
            }

            $unit_id = TSession::getValue('userunitid');

            return \CentralVet\Tenancy\TenantContext::authenticated((int) $tenant_id, (int) $userid, $unit_id ? (int) $unit_id : null);
        }
    }
}
