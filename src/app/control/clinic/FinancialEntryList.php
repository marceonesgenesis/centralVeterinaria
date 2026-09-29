<?php
/**
 * FinancialEntryList
 *
 * Listing screen for FinancialEntry rows within a period (T-09). Mirrors
 * ServiceList (Fase 1) / ProcedureCatalogList (T-08 da Fase 4): this
 * listing has no data-access logic of its own — onReload() is overridden to
 * source every row from
 * CentralVet\Application\FinancialEntryService::listByPeriod() (T-09
 * passthrough over CentralVet\Domain\Contract\
 * FinancialEntryRepositoryInterface::listBySystemUnitAndPeriod(), per this
 * task's own Interface spec) — the Persistence layer
 * (CentralVet\Persistence\FinancialEntryRepository) is never touched from
 * here.
 *
 * There is no Edit/Delete row action: financial_entry is append-only
 * (CentralVet\Domain\FinancialEntry's own docblock), so offering edit here
 * would force this controller to bypass the Application service and hit
 * Persistence/Domain directly, which is out of scope for this task.
 *
 * The period filter defaults to the current calendar month when no filter
 * has been searched yet this session.
 *
 * @version    1.0
 * @package    control
 * @subpackage clinic
 */
class FinancialEntryList extends TStandardList
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

        // No setActiveRecord() call: AdiantiStandardControlTrait::
        // setActiveRecord() requires class_exists($activeRecord) and throws
        // otherwise — there is no CentralVet\Domain\FinancialEntry-backed
        // TRecord model (onReload()/onAfterSearch() below talk to
        // FinancialEntryService directly), so calling it here was a fatal
        // dead end on every load, never exercised by the fake-repository
        // unit tests.
        parent::setDefaultOrder('id', 'desc');
        parent::addFilterField('period_from', '>=', 'period_from'); // filterField, operator, formField
        parent::addFilterField('period_to', '<=', 'period_to');
        parent::setLimit(TSession::getValue(__CLASS__ . '_limit') ?? 10);

        parent::setAfterSearchCallback( [$this, 'onAfterSearch' ] );

        // creates the form
        $this->form = new BootstrapFormBuilder('form_search_FinancialEntry');
        $this->form->setFormTitle(_t('Financial entries'));

        // create the form fields
        $period_from = new TDate('period_from');
        $period_to = new TDate('period_to');

        $period_from->setMask('dd/mm/yyyy');
        $period_from->setDatabaseMask('yyyy-mm-dd');
        $period_to->setMask('dd/mm/yyyy');
        $period_to->setDatabaseMask('yyyy-mm-dd');

        // add the fields
        $this->form->addFields( [new TLabel(_t('From'))] );
        $this->form->addFields( [$period_from] );
        $this->form->addFields( [new TLabel(_t('To'))] );
        $this->form->addFields( [$period_to] );

        $period_from->setSize('100%');
        $period_to->setSize('100%');

        // keep the form filled during navigation with session data, or
        // default to the current calendar month on first load
        $filter_data = TSession::getValue('FinancialEntry_filter_data');

        if (empty($filter_data))
        {
            $filter_data = new stdClass;
            $filter_data->period_from = date('Y-m-01');
            $filter_data->period_to = date('Y-m-t');
        }

        $this->form->setData($filter_data);

        // add the search form actions
        $btn = $this->form->addAction(_t('Find'), new TAction(array($this, 'onSearch')), 'fa:search');
        $btn->class = 'btn btn-sm btn-primary';

        // creates a DataGrid
        $this->datagrid = new BootstrapDatagridWrapper(new TDataGrid);
        $this->datagrid->style = 'width: 100%';
        $this->datagrid->setHeight(320);

        // creates the datagrid columns
        $column_id            = new TDataGridColumn('id', 'Id', 'center', 50);
        $column_entry_type    = new TDataGridColumn('entry_type_label', _t('Type'), 'center', 100);
        $column_category      = new TDataGridColumn('category', _t('Category'), 'left');
        $column_amount        = new TDataGridColumn('amount_label', _t('Amount'), 'right', 110);
        $column_occurred_at   = new TDataGridColumn('occurred_at_label', _t('Date'), 'center', 130);
        $column_reference     = new TDataGridColumn('reference_label', _t('Reference'), 'left');

        // add the columns to the DataGrid
        $this->datagrid->addColumn($column_id);
        $this->datagrid->addColumn($column_entry_type);
        $this->datagrid->addColumn($column_category);
        $this->datagrid->addColumn($column_amount);
        $this->datagrid->addColumn($column_occurred_at);
        $this->datagrid->addColumn($column_reference);

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

        $panel->addHeaderWidget($this->form);

        $panel->addHeaderActionLink('', new TAction(['FinancialEntryForm', 'onEdit'], ['register_state' => 'false']), 'fa:plus');
        $this->filter_label = $panel->addHeaderActionLink(_t('Filters'), new TAction([$this, 'onShowCurtainFilters']), 'fa:filter');

        if (TSession::getValue(get_class($this).'_filter_counter') > 0)
        {
            $this->filter_label->class = 'btn btn-primary';
            $this->filter_label->setLabel(_t('Filters') . ' ('. TSession::getValue(get_class($this).'_filter_counter').')');
        }

        // page header (design system: .cv-page-header / .cv-page-title,
        // mirrors src/design-system.html)
        $page_header = new TElement('header');
        $page_header->class = 'cv-page-header';

        $page_header_content = new TElement('div');

        $page_header_title = new TElement('h1');
        $page_header_title->class = 'cv-page-title';
        $page_header_title->add(_t('Financial entries'));

        $page_header_content->add($page_header_title);
        $page_header->add($page_header_content);

        // vertical box container
        // No TXMLBreadCrumb here on purpose: registering FinancialEntryList
        // in menu.xml is explicitly T-12's job, not T-09's (same precedent
        // as ProcedureCatalogList::__construct()'s own docblock) —
        // TXMLBreadCrumb throws when the class is not yet listed there,
        // which would make `new FinancialEntryList()` fatal ahead of that
        // registration.
        $container = new TVBox;
        $container->style = 'width: 100%';
        $container->add($page_header);
        $container->add($panel);

        parent::add($container);
    }

    /**
     * method onReload()
     * Loads the datagrid exclusively from
     * CentralVet\Application\FinancialEntryService::listByPeriod() — the
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

            $tenant_context = self::resolveTenantContext();
            $service = self::buildFinancialEntryService($tenant_context);

            $filter_data = TSession::getValue('FinancialEntry_filter_data');

            $period_from = !empty($filter_data->period_from ?? null) ? $filter_data->period_from : date('Y-m-01');
            $period_to   = !empty($filter_data->period_to ?? null) ? $filter_data->period_to : date('Y-m-t');

            // every row this listing can ever show comes from this call
            $entries = $service->listByPeriod(
                $tenant_context->requireUnitId(),
                $period_from . ' 00:00:00',
                $period_to . ' 23:59:59',
            );

            $rows = [];
            foreach ($entries as $entry)
            {
                $row = new stdClass;
                $row->id                 = $entry->id();
                $row->entry_type_label   = $entry->entryType() === \CentralVet\Domain\FinancialEntry::TYPE_INCOME ? _t('Income') : _t('Expense');
                $row->category           = $entry->category();
                $row->amount_label       = number_format($entry->amountCents() / 100, 2, ',', '.');
                $row->occurred_at_label  = $entry->occurredAt()->format('d/m/Y H:i');
                $row->reference_label    = $entry->referenceType() !== null
                    ? $entry->referenceType() . ' #' . $entry->referenceId()
                    : '-';

                $rows[] = $row;
            }

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
        catch (\CentralVet\Tenancy\Exception\MissingTenantContext $e)
        {
            new TMessage('error', _t('An authenticated session with a tenant is required'));
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
            TForm::sendData('form_search_FinancialEntry', TSession::getValue(get_class($this).'_filter_data'));
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
     * Wires an Application-layer FinancialEntryService instance, consistent
     * with FinancialEntryForm::buildFinancialEntryService() (T-09).
     * Requires an already-open TTransaction('permission') connection.
     */
    private static function buildFinancialEntryService(\CentralVet\Tenancy\TenantContext $context): \CentralVet\Application\FinancialEntryService
    {
        $connection = TTransaction::get();

        $entries = new \CentralVet\Persistence\FinancialEntryRepository($context, $connection);

        $authorization = new \CentralVet\Authorization\RbacAuthorizationService(
            new \CentralVet\Authorization\AdiantiProgramPermissionProvider(new \CentralVet\Tenancy\AdiantiSessionContextSource()),
            new \CentralVet\Audit\PdoAuditLogWriter($connection),
        );

        return new \CentralVet\Application\FinancialEntryService($entries, $authorization, $context);
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
