<?php
/**
 * ExamCatalogList
 *
 * Listing screen for the exam catalog (T-07). Like ServiceList (Fase 1),
 * this listing has no data-access logic of its own: onReload() is
 * overridden to source every row from
 * CentralVet\Application\ExamCatalogService::listActive() — the Persistence
 * layer (CentralVet\Persistence\ExamCatalogRepository) is never touched from
 * here.
 *
 * There is no Edit/Delete row action: ExamCatalogService (T-04) only
 * exposes create() and listActive() for now, so offering edit/delete here
 * would force this controller to bypass the Application service and hit
 * Persistence/Domain directly, which is out of scope for this task.
 *
 * @version    1.0
 * @package    control
 * @subpackage clinic
 */
class ExamCatalogList extends TStandardList
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

        // 'ExamCatalogItem' is only used here as the session-key namespace
        // for the filter form (see AdiantiStandardCollectionTrait::onSearch());
        // the actual listing never queries the `exam_catalog_item` table
        // through it.
        parent::setActiveRecord('ExamCatalogItem');
        parent::setDefaultOrder('name', 'asc');
        parent::addFilterField('name', 'like', 'name'); // filterField, operator, formField
        parent::setLimit(TSession::getValue(__CLASS__ . '_limit') ?? 10);

        parent::setAfterSearchCallback( [$this, 'onAfterSearch' ] );

        // creates the form
        $this->form = new BootstrapFormBuilder('form_search_ExamCatalogItem');
        $this->form->setFormTitle(_t('Exams'));

        // create the form fields
        $name = new TEntry('name');

        // add the fields
        $this->form->addFields( [new TLabel(_t('Name'))] );
        $this->form->addFields( [$name] );

        $name->setSize('100%');

        // keep the form filled during navigation with session data
        $this->form->setData( TSession::getValue('ExamCatalogItem_filter_data') );

        // add the search form actions
        $btn = $this->form->addAction(_t('Find'), new TAction(array($this, 'onSearch')), 'fa:search');
        $btn->class = 'btn btn-sm btn-primary';

        // creates a DataGrid
        $this->datagrid = new BootstrapDatagridWrapper(new TDataGrid);
        $this->datagrid->style = 'width: 100%';
        $this->datagrid->setHeight(320);

        // creates the datagrid columns
        $column_id      = new TDataGridColumn('id', 'Id', 'center', 50);
        $column_name    = new TDataGridColumn('name', _t('Name'), 'left');
        $column_partner = new TDataGridColumn('partner_name', _t('Partner'), 'left');
        $column_price   = new TDataGridColumn('price', _t('Price'), 'right', 110);
        $column_status  = new TDataGridColumn('status_label', _t('Status'), 'center', 100);

        // add the columns to the DataGrid
        $this->datagrid->addColumn($column_id);
        $this->datagrid->addColumn($column_name);
        $this->datagrid->addColumn($column_partner);
        $this->datagrid->addColumn($column_price);
        $this->datagrid->addColumn($column_status);

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

        $panel->addHeaderActionLink('', new TAction(['ExamCatalogForm', 'onEdit'], ['register_state' => 'false']), 'fa:plus');
        $this->filter_label = $panel->addHeaderActionLink(_t('Filters'), new TAction([$this, 'onShowCurtainFilters']), 'fa:filter');

        if (TSession::getValue(get_class($this).'_filter_counter') > 0)
        {
            $this->filter_label->class = 'btn btn-primary';
            $this->filter_label->setLabel(_t('Filters') . ' ('. TSession::getValue(get_class($this).'_filter_counter').')');
        }

        // vertical box container
        // No TXMLBreadCrumb here on purpose: registering ExamCatalogList in
        // menu.xml is explicitly T-10's job, not T-07's (same precedent as
        // EncounterView::__construct()'s own docblock) — TXMLBreadCrumb
        // throws when the class is not yet listed there, which would make
        // `new ExamCatalogList()` fatal ahead of that registration.
        $container = new TVBox;
        $container->style = 'width: 100%';

        $pageHeader = new TElement('header');
        $pageHeader->class = 'cv-page-header';
        $pageHeaderTitleWrap = new TElement('div');
        $pageHeaderTitle = new TElement('h1');
        $pageHeaderTitle->class = 'cv-page-title';
        $pageHeaderTitle->add(_t('Exams'));
        $pageHeaderTitleWrap->add($pageHeaderTitle);
        $pageHeader->add($pageHeaderTitleWrap);
        $container->add($pageHeader);

        $container->add($panel);

        parent::add($container);
    }

    /**
     * method onReload()
     * Loads the datagrid exclusively from
     * CentralVet\Application\ExamCatalogService::listActive() — the tenant
     * scoping happens inside that service/repository, never here.
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

            $catalog = self::buildExamCatalogService();

            // every row this listing can ever show comes from this call
            $items = $catalog->listActive();

            $name_filter = TSession::getValue('ExamCatalogItem_filter_name');
            $name_filter = !empty($name_filter) ? mb_strtolower((string) $name_filter) : null;

            $rows = [];
            foreach ($items as $item)
            {
                if ($name_filter !== null && mb_strpos(mb_strtolower($item->name()), $name_filter) === false)
                {
                    continue;
                }

                $row = new stdClass;
                $row->id           = $item->id();
                $row->name         = $item->name();
                $row->partner_name = $item->partnerName();
                $row->price        = number_format($item->priceCents() / 100, 2, ',', '.');
                $row->status_label = $item->active() ? _t('Active') : _t('Inactive');

                $rows[] = $row;
            }

            // total count for this tenant, as returned by listActive()
            // (after the optional name filter, mirroring TStandardList's own
            // filtered-count semantics)
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
    private static function buildExamCatalogService()
    {
        $tenant_context = self::resolveTenantContext();
        $connection = TTransaction::get();

        $repository = new \CentralVet\Persistence\ExamCatalogRepository($tenant_context, $connection);

        return new \CentralVet\Application\ExamCatalogService($repository, $tenant_context);
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
