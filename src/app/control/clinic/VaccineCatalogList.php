<?php
/**
 * VaccineCatalogList
 *
 * Listing screen for the vaccine catalog (T-08). Mirrors ServiceList
 * (Fase 1) / ExamCatalogList (T-07): this listing has no data-access logic
 * of its own — onReload() is overridden to source every row from
 * CentralVet\Application\VaccineCatalogService::listActive() (T-05) — the
 * Persistence layer (CentralVet\Persistence\VaccineCatalogRepository) is
 * never touched from here.
 *
 * There is no Edit/Delete row action: VaccineCatalogService only exposes
 * create()/listActive()/findById() for now, so offering edit/delete here
 * would force this controller to bypass the Application service and hit
 * Persistence/Domain directly, which is out of scope for this task.
 *
 * The "New" action links to VaccineProtocolForm as well, so a freshly
 * created vaccine can have its dose schedule configured right away.
 *
 * @version    1.0
 * @package    control
 * @subpackage clinic
 */
class VaccineCatalogList extends TStandardList
{
    protected $form;     // registration form
    protected $datagrid; // listing
    protected $pageNavigation;

    /**
     * Page constructor
     */
    public function __construct()
    {
        parent::__construct();

        // 'VaccineCatalogItem' is only used here as the session-key
        // namespace for the filter form (see
        // AdiantiStandardCollectionTrait::onSearch()); the actual listing
        // never queries the `vaccine_catalog_item` table through it.
        parent::setActiveRecord('VaccineCatalogItem');
        parent::setDefaultOrder('name', 'asc');
        parent::addFilterField('name', 'like', 'name'); // filterField, operator, formField
        parent::setLimit(TSession::getValue(__CLASS__ . '_limit') ?? 10);

        parent::setAfterSearchCallback( [$this, 'onAfterSearch' ] );

        // creates the form
        $this->form = new BootstrapFormBuilder('form_search_VaccineCatalogItem');
        $this->form->setFormTitle(_t('Vaccines'));

        // create the form fields
        $name = new TEntry('name');

        // add the fields
        $this->form->addFields( [new TLabel(_t('Name'))] );
        $this->form->addFields( [$name] );

        $name->setSize('100%');

        // keep the form filled during navigation with session data
        $this->form->setData( TSession::getValue('VaccineCatalogItem_filter_data') );

        // add the search form actions
        $btn = $this->form->addAction(_t('Find'), new TAction(array($this, 'onSearch')), 'fa:search');
        $btn->class = 'btn btn-sm btn-primary';

        // creates a DataGrid
        $this->datagrid = new BootstrapDatagridWrapper(new TDataGrid);
        $this->datagrid->style = 'width: 100%';
        $this->datagrid->setHeight(320);

        // creates the datagrid columns
        $column_id            = new TDataGridColumn('id', 'Id', 'center', 50);
        $column_name          = new TDataGridColumn('name', _t('Name'), 'left');
        $column_manufacturer  = new TDataGridColumn('manufacturer', _t('Manufacturer'), 'left');
        $column_stock         = new TDataGridColumn('stock_quantity', _t('Stock'), 'center', 90);
        $column_status        = new TDataGridColumn('status_label', _t('Status'), 'center', 100);

        // add the columns to the DataGrid
        $this->datagrid->addColumn($column_id);
        $this->datagrid->addColumn($column_name);
        $this->datagrid->addColumn($column_manufacturer);
        $this->datagrid->addColumn($column_stock);
        $this->datagrid->addColumn($column_status);

        // row action: configure this vaccine's dose schedule
        $action_protocol = new TDataGridAction(['VaccineProtocolForm', 'onEdit'], ['vaccine_catalog_item_id' => '{id}', 'register_state' => 'false']);
        $action_protocol->setLabel(_t('Protocol'));
        $action_protocol->setImage('fa:syringe blue');
        $this->datagrid->addAction($action_protocol);

        // create the datagrid model
        $this->datagrid->createModel();

        // create the page navigation
        $this->pageNavigation = new TPageNavigation;
        $this->pageNavigation->enableCounters();
        $this->pageNavigation->setAction(new TAction(array($this, 'onReload')));
        $this->pageNavigation->setWidth($this->datagrid->getWidth());

        $panel = new TPanelGroup;
        $panel->add($this->datagrid);
        $panel->addFooter($this->pageNavigation);

        $btnf = TButton::create('find', [$this, 'onSearch'], '', 'fa:search');
        $btnf->style = 'height: 37px; margin-right:4px;';

        $form_search = new TForm('form_search_name');
        $form_search->style = 'float:left;display:flex';
        $form_search->add($name, true);
        $form_search->add($btnf, true);

        $panel->addHeaderWidget($form_search);

        $panel->addHeaderActionLink('', new TAction(['VaccineCatalogForm', 'onEdit'], ['register_state' => 'false']), 'fa:plus');
        $this->filter_label = $panel->addHeaderActionLink(_t('Filters'), new TAction([$this, 'onShowCurtainFilters']), 'fa:filter');

        if (TSession::getValue(get_class($this).'_filter_counter') > 0)
        {
            $this->filter_label->class = 'btn btn-primary';
            $this->filter_label->setLabel(_t('Filters') . ' ('. TSession::getValue(get_class($this).'_filter_counter').')');
        }

        // vertical box container
        // No TXMLBreadCrumb here on purpose: registering VaccineCatalogList
        // in menu.xml is explicitly T-10's job, not T-08's (same precedent
        // as ExamCatalogList::__construct()'s own docblock) — TXMLBreadCrumb
        // throws when the class is not yet listed there, which would make
        // `new VaccineCatalogList()` fatal ahead of that registration.
        $container = new TVBox;
        $container->style = 'width: 100%';

        $pageHeader = new TElement('header');
        $pageHeader->class = 'cv-page-header';
        $pageHeaderTitleWrap = new TElement('div');
        $pageHeaderTitle = new TElement('h1');
        $pageHeaderTitle->class = 'cv-page-title';
        $pageHeaderTitle->add(_t('Vaccines'));
        $pageHeaderTitleWrap->add($pageHeaderTitle);
        $pageHeader->add($pageHeaderTitleWrap);
        $container->add($pageHeader);

        $container->add($panel);

        parent::add($container);
    }

    /**
     * method onReload()
     * Loads the datagrid exclusively from
     * CentralVet\Application\VaccineCatalogService::listActive() — the
     * tenant scoping happens inside that service/repository, never here.
     */
    public function onReload($param = NULL)
    {
        if (!isset($this->datagrid))
        {
            return;
        }

        try
        {
            // open a transaction with database
            TTransaction::open('permission');

            $catalog = self::buildVaccineCatalogService();

            // every row this listing can ever show comes from this call
            $items = $catalog->listActive();

            $name_filter = TSession::getValue('VaccineCatalogItem_filter_name');
            $name_filter = !empty($name_filter) ? mb_strtolower((string) $name_filter) : null;

            $rows = [];
            foreach ($items as $item)
            {
                if ($name_filter !== null && mb_strpos(mb_strtolower($item->name()), $name_filter) === false)
                {
                    continue;
                }

                $row = new stdClass;
                $row->id              = $item->id();
                $row->name            = $item->name();
                $row->manufacturer    = $item->manufacturer();
                $row->stock_quantity  = $item->stockQuantity();
                $row->status_label    = $item->active() ? _t('Active') : _t('Inactive');

                $rows[] = $row;
            }

            // total count for this tenant, as returned by listActive()
            // (after the optional name filter, mirroring TStandardList's
            // own filtered-count semantics)
            $count = count($rows);

            $offset = isset($param['offset']) ? (int) $param['offset'] : 0;
            $limit  = isset($this->limit) ? ( $this->limit > 0 ? $this->limit : NULL) : 10;

            $page_rows = $limit ? array_slice($rows, $offset, $limit) : $rows;

            $this->datagrid->clear();
            foreach ($page_rows as $row)
            {
                $this->datagrid->addItem($row);
            }

            if (isset($this->pageNavigation))
            {
                $this->pageNavigation->setCount($count); // count of records
                $this->pageNavigation->setProperties($param); // order, page
                $this->pageNavigation->setLimit($limit); // limit
            }

            // close the transaction
            TTransaction::close();
            $this->loaded = true;

            return $rows;
        }
        catch (\CentralVet\Authorization\Exception\AuthorizationDenied $e)
        {
            new TMessage('error', _t('You are not allowed to perform this action'));
            TTransaction::rollback();
        }
        catch (Exception $e) // in case of exception
        {
            // shows the exception error message
            new TMessage('error', $e->getMessage());
            // undo all pending operations
            TTransaction::rollback();
        }
    }

    /**
     *
     */
    public function onAfterSearch($datagrid, $options)
    {
        if (TSession::getValue(get_class($this).'_filter_counter') > 0)
        {
            $this->filter_label->class = 'btn btn-primary';
            $this->filter_label->setLabel(_t('Filters') . ' ('. TSession::getValue(get_class($this).'_filter_counter').')');
        }
        else
        {
            $this->filter_label->class = 'btn btn-default';
            $this->filter_label->setLabel(_t('Filters'));
        }

        if (!empty(TSession::getValue(get_class($this).'_filter_data')))
        {
            $obj = new stdClass;
            $obj->name = TSession::getValue(get_class($this).'_filter_data')->name;
            TForm::sendData('form_search_name', $obj);
        }
    }

    /**
     *
     */
    public static function onChangeLimit($param)
    {
        TSession::setValue(__CLASS__ . '_limit', $param['limit'] );
        AdiantiCoreApplication::loadPage(__CLASS__, 'onReload');
    }

    /**
     *
     */
    public static function onShowCurtainFilters($param = null)
    {
        try
        {
            // create empty page for right panel
            $page = new TPage;
            $page->setTargetContainer('adianti_right_panel');
            $page->setProperty('override', 'true');
            $page->setPageName(__CLASS__);

            $btn_close = new TButton('closeCurtain');
            $btn_close->onClick = "Template.closeRightPanel();";
            $btn_close->setLabel(_t('Close'));
            $btn_close->setImage('fas:times red');

            // instantiate self class, populate filters in construct
            $embed = new self;
            $embed->form->addHeaderWidget($btn_close);

            // embed form inside curtain
            $page->add($embed->form);
            $page->setIsWrapped(true);
            $page->show();
        }
        catch (Exception $e)
        {
            new TMessage('error', $e->getMessage());
        }
    }

    /**
     * Builds the Application service with its dependencies. Requires an
     * already-open TTransaction('permission') connection.
     */
    private static function buildVaccineCatalogService()
    {
        $tenant_context = self::resolveTenantContext();
        $connection = TTransaction::get();

        $repository = new \CentralVet\Persistence\VaccineCatalogRepository($tenant_context, $connection);

        return new \CentralVet\Application\VaccineCatalogService($repository, $tenant_context);
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
