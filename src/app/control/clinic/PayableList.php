<?php
/**
 * PayableList
 *
 * Listing screen for open Payables (T-09). Mirrors ServiceList (Fase 1) /
 * ProcedureCatalogList (T-08 da Fase 4): this listing has no data-access
 * logic of its own — onReload() is overridden to source every row from
 * CentralVet\Application\PayableService::listOpen() (T-05) — the
 * Persistence layer (CentralVet\Persistence\PayableRepository) is never
 * touched from here.
 *
 * The "Pagar" row action calls CentralVet\Application\PayableService::pay()
 * (T-05) directly, never CentralVet\Domain\Payable/PayableRepository: a
 * payable already 'paid' or 'cancelled' makes Payable::markPaid() throw
 * InvalidStatusTransitionException before any write, which onPay() turns
 * into a TMessage error without ever reaching FinancialEntryService::
 * record() — so a second financial_entry is never generated for an
 * already-settled payable (criterio de aceite).
 *
 * @version    1.0
 * @package    control
 * @subpackage clinic
 */
class PayableList extends TStandardList
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

        // 'Payable' is only used here as the session-key namespace for the
        // filter form (see AdiantiStandardCollectionTrait::onSearch()); the
        // actual listing never queries the `payable` table through it.
        parent::setActiveRecord('Payable');
        parent::setDefaultOrder('id', 'asc');
        parent::addFilterField('description_text', 'like', 'description_text'); // filterField, operator, formField
        parent::setLimit(TSession::getValue(__CLASS__ . '_limit') ?? 10);

        parent::setAfterSearchCallback( [$this, 'onAfterSearch' ] );

        // creates the form
        $this->form = new BootstrapFormBuilder('form_search_Payable');
        $this->form->setFormTitle(_t('Payables'));

        // create the form fields
        $description_text = new TEntry('description_text');

        // add the fields
        $this->form->addFields( [new TLabel(_t('Description'))] );
        $this->form->addFields( [$description_text] );

        $description_text->setSize('100%');

        // keep the form filled during navigation with session data
        $this->form->setData( TSession::getValue('Payable_filter_data') );

        // add the search form actions
        $btn = $this->form->addAction(_t('Find'), new TAction(array($this, 'onSearch')), 'fa:search');
        $btn->class = 'btn btn-sm btn-primary';

        // creates a DataGrid
        $this->datagrid = new BootstrapDatagridWrapper(new TDataGrid);
        $this->datagrid->style = 'width: 100%';
        $this->datagrid->setHeight(320);

        // creates the datagrid columns
        $column_id          = new TDataGridColumn('id', 'Id', 'center', 50);
        $column_description = new TDataGridColumn('description_text', _t('Description'), 'left');
        $column_category    = new TDataGridColumn('category', _t('Category'), 'left');
        $column_amount       = new TDataGridColumn('amount_label', _t('Amount'), 'right', 110);
        $column_due_date     = new TDataGridColumn('due_date_label', _t('Due date'), 'center', 110);
        $column_status       = new TDataGridColumn('status_label', _t('Status'), 'center', 100);

        // add the columns to the DataGrid
        $this->datagrid->addColumn($column_id);
        $this->datagrid->addColumn($column_description);
        $this->datagrid->addColumn($column_category);
        $this->datagrid->addColumn($column_amount);
        $this->datagrid->addColumn($column_due_date);
        $this->datagrid->addColumn($column_status);

        // row action: settle this payable
        $action_pay = new TDataGridAction(array($this, 'onPay'));
        $action_pay->setButtonClass('btn btn-default');
        $action_pay->setLabel(_t('Pay'));
        $action_pay->setImage('fa:money-bill-wave green');
        $action_pay->setField('id');
        $this->datagrid->addAction($action_pay);

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

        $form_search = new TForm('form_search_description_text');
        $form_search->style = 'float:left;display:flex';
        $form_search->add($description_text, true);
        $form_search->add($btnf, true);

        $panel->addHeaderWidget($form_search);

        $panel->addHeaderActionLink('', new TAction(['PayableForm', 'onEdit'], ['register_state' => 'false']), 'fa:plus');
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
        $page_header_title->add(_t('Payables'));

        $page_header_content->add($page_header_title);
        $page_header->add($page_header_content);

        // vertical box container
        // No TXMLBreadCrumb here on purpose: registering PayableList in
        // menu.xml is explicitly T-12's job, not T-09's (same precedent as
        // ProcedureCatalogList::__construct()'s own docblock) — TXMLBreadCrumb
        // throws when the class is not yet listed there, which would make
        // `new PayableList()` fatal ahead of that registration.
        $container = new TVBox;
        $container->style = 'width: 100%';
        $container->add($page_header);
        $container->add($panel);

        parent::add($container);
    }

    /**
     * method onReload()
     * Loads the datagrid exclusively from
     * CentralVet\Application\PayableService::listOpen() — the tenant
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

            $tenant_context = self::resolveTenantContext();
            $service = self::buildPayableService($tenant_context);

            // every row this listing can ever show comes from this call
            $payables = $service->listOpen($tenant_context->requireUnitId());

            $description_filter = TSession::getValue('Payable_filter_description_text');
            $description_filter = !empty($description_filter) ? mb_strtolower((string) $description_filter) : null;

            $rows = [];
            foreach ($payables as $payable)
            {
                if ($description_filter !== null && mb_strpos(mb_strtolower($payable->descriptionText()), $description_filter) === false)
                {
                    continue;
                }

                $row = new stdClass;
                $row->id               = $payable->id();
                $row->description_text = $payable->descriptionText();
                $row->category         = $payable->category();
                $row->amount_label     = number_format($payable->amountCents() / 100, 2, ',', '.');
                $row->due_date_label   = $payable->dueDate() !== null ? $payable->dueDate()->format('d/m/Y') : '-';
                $row->status           = $payable->status();
                $row->status_label     = $payable->status() === \CentralVet\Domain\Payable::STATUS_OPEN ? _t('Open') : $payable->status();

                $rows[] = $row;
            }

            // total count for this tenant/unit (after the optional
            // description filter, mirroring TStandardList's own filtered-count
            // semantics)
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
     * method onPay()
     * Row action target: settles the payable through PayableService::pay().
     * A refusal (already-paid/cancelled payable, unit-scope authorization,
     * missing tenant) is shown as a TMessage error and the grid is left
     * untouched — no second financial_entry is ever generated, since
     * Payable::markPaid() throws before PayableService::pay() reaches
     * ->save()/FinancialEntryService::record() (criterio de aceite).
     */
    public function onPay($param)
    {
        try
        {
            TTransaction::open('permission');

            $tenant_context = self::resolveTenantContext();
            $service = self::buildPayableService($tenant_context);

            $service->pay(
                (int) $param['id'],
                $tenant_context->userId(),
                __CLASS__ . '::' . __FUNCTION__,
            );

            TTransaction::close();

            new TMessage('info', _t('Payable paid successfully'));
            $this->onReload();
        }
        catch (\CentralVet\Domain\Exception\InvalidStatusTransitionException $e)
        {
            TTransaction::rollback();
            new TMessage('error', $e->getMessage());
        }
        catch (\CentralVet\Authorization\Exception\AuthorizationDenied $e)
        {
            TTransaction::rollback();
            new TMessage('error', _t('You are not allowed to perform this action'));
        }
        catch (\CentralVet\Tenancy\Exception\MissingTenantContext $e)
        {
            TTransaction::rollback();
            new TMessage('error', _t('An authenticated session with a tenant is required'));
        }
        catch (InvalidArgumentException $e)
        {
            TTransaction::rollback();
            new TMessage('error', $e->getMessage());
        }
        catch (Exception $e) // catch-all: never let a fatal error reach the screen
        {
            TTransaction::rollback();
            new TMessage('error', $e->getMessage());
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
            $obj->description_text = TSession::getValue(get_class($this).'_filter_data')->description_text;
            TForm::sendData('form_search_description_text', $obj);
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
     * Wires an Application-layer PayableService instance, consistent with
     * PayableForm::buildPayableService() (T-09). Requires an already-open
     * TTransaction('permission') connection.
     */
    private static function buildPayableService(\CentralVet\Tenancy\TenantContext $context): \CentralVet\Application\PayableService
    {
        $connection = TTransaction::get();

        $payables = new \CentralVet\Persistence\PayableRepository($context, $connection);
        $entries = new \CentralVet\Persistence\FinancialEntryRepository($context, $connection);

        $authorization = new \CentralVet\Authorization\RbacAuthorizationService(
            new \CentralVet\Authorization\AdiantiProgramPermissionProvider(new \CentralVet\Tenancy\AdiantiSessionContextSource()),
            new \CentralVet\Audit\PdoAuditLogWriter($connection),
        );

        $financial_entries = new \CentralVet\Application\FinancialEntryService($entries, $authorization, $context);

        return new \CentralVet\Application\PayableService($payables, $financial_entries, $authorization, $context);
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
