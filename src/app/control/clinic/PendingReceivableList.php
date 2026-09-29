<?php
/**
 * PendingReceivableList
 *
 * Listing screen for open receivables (T-04). Mirrors PayableList.php's
 * structure exactly: this listing has no data-access logic of its own —
 * onReload() is overridden to source every row from
 * CentralVet\Application\PaymentService::listOpenReceivables() (T-02) —
 * the Persistence layer (CentralVet\Persistence\ReceivableRepository) is
 * never touched from here.
 *
 * Tutor names are not carried by CentralVet\Domain\Receivable itself (it
 * only holds tutorId()), so this screen resolves them for display through
 * the existing, already-tested CentralVet\Application\TutorService::
 * findById() — a read-only Application-layer call, never a direct
 * Persistence/Domain touch.
 *
 * The "Receive payment" row action opens PaymentForm passing the current
 * row's id as `receivable_id` in the querystring — same
 * TDataGridAction(['ClassName','onEdit'], [...]) technique as
 * ProductList.php's "Batch" row action, targeting a plain TPage
 * (PaymentForm) that reads the id back from $_GET/$param in its own
 * constructor.
 *
 * No TXMLBreadCrumb here on purpose: registering PendingReceivableList in
 * menu.xml is explicitly T-06's job, not T-04's (same precedent as
 * PayableList::__construct()'s own docblock) — TXMLBreadCrumb throws when
 * the class is not yet listed there, which would make `new
 * PendingReceivableList()` fatal ahead of that registration.
 *
 * @version    1.0
 * @package    control
 * @subpackage clinic
 */
class PendingReceivableList extends TStandardList
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

        // 'Receivable' is only used here as the session-key namespace for
        // the filter form (see AdiantiStandardCollectionTrait::onSearch());
        // the actual listing never queries the `receivable` table through
        // it.
        parent::setActiveRecord('Receivable');
        parent::setDefaultOrder('id', 'asc');
        parent::addFilterField('tutor_name', 'like', 'tutor_name'); // filterField, operator, formField
        parent::setLimit(TSession::getValue(__CLASS__ . '_limit') ?? 10);

        parent::setAfterSearchCallback( [$this, 'onAfterSearch' ] );

        // creates the form
        $this->form = new BootstrapFormBuilder('form_search_Receivable');
        $this->form->setFormTitle(_t('Open receivables'));

        // create the form fields
        $tutor_name = new TEntry('tutor_name');

        // add the fields
        $this->form->addFields( [new TLabel(_t('Tutor'))] );
        $this->form->addFields( [$tutor_name] );

        $tutor_name->setSize('100%');

        // keep the form filled during navigation with session data
        $this->form->setData( TSession::getValue('Receivable_filter_data') );

        // add the search form actions
        $btn = $this->form->addAction(_t('Find'), new TAction(array($this, 'onSearch')), 'fa:search');
        $btn->class = 'btn btn-sm btn-primary';

        // creates a DataGrid
        $this->datagrid = new BootstrapDatagridWrapper(new TDataGrid);
        $this->datagrid->style = 'width: 100%';
        $this->datagrid->setHeight(320);

        // row action: receive a payment for this receivable. PaymentForm is
        // a plain TPage (not a TStandardForm), so it has no onEdit() of its
        // own — only its constructor reads receivable_id back from
        // $_GET/$param. A TDataGridAction (which extends TAction) requires a
        // real class+method callback validated via method_exists(); even a
        // real inherited method like TPage::show() is unsafe here, because
        // AdiantiCoreApplication::run() already calls show() once for a bare
        // navigation, and TPage::show() itself calls $this->run()
        // (AdiantiPageControlTrait), which re-reads $_GET['method'] and,
        // since class === get_class($this), invokes that very same method
        // again — 'show' calling itself forever (confirmed via a real
        // browser click: "Maximum call stack size... Infinite recursion?").
        // The safe, already-proven pattern in this codebase for linking to a
        // plain TPage with a querystring parameter is a raw <a href> (see
        // EncounterAccountForm::onClose()'s link to PaymentForm), which never
        // sends a `method=` parameter at all.
        $column_action = new TDataGridColumn('id', _t('Receive payment'), 'center', 40);
        $column_action->setTransformer(function ($value) {
            $url = 'index.php?class=PaymentForm&receivable_id=' . (int) $value;
            return '<a href="' . $url . '" title="' . _t('Receive payment') . '"><i class="fa fa-money-bill-wave text-success"></i></a>';
        });
        $column_action->disableHtmlConversion();

        // creates the datagrid columns
        $column_id           = new TDataGridColumn('id', 'Id', 'center', 50);
        $column_tutor        = new TDataGridColumn('tutor_name', _t('Tutor'), 'left');
        $column_total        = new TDataGridColumn('total_label', _t('Total'), 'right', 110);
        $column_paid         = new TDataGridColumn('paid_label', _t('Paid'), 'right', 110);
        $column_status       = new TDataGridColumn('status_label', _t('Status'), 'center', 100);

        // add the columns to the DataGrid
        $this->datagrid->addColumn($column_action);
        $this->datagrid->addColumn($column_id);
        $this->datagrid->addColumn($column_tutor);
        $this->datagrid->addColumn($column_total);
        $this->datagrid->addColumn($column_paid);
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

        $form_search = new TForm('form_search_tutor_name');
        $form_search->style = 'float:left;display:flex';
        $form_search->add($tutor_name, true);
        $form_search->add($btnf, true);

        $panel->addHeaderWidget($form_search);

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
        $page_header_title->add(_t('Open receivables'));

        $page_header_content->add($page_header_title);
        $page_header->add($page_header_content);

        // vertical box container
        // No TXMLBreadCrumb here on purpose — see class docblock.
        $container = new TVBox;
        $container->style = 'width: 100%';
        $container->add($page_header);
        $container->add($panel);

        parent::add($container);
    }

    /**
     * method onReload()
     * Loads the datagrid exclusively from
     * CentralVet\Application\PaymentService::listOpenReceivables() — the
     * tenant scoping happens inside that service/repository, never here.
     * The tutor name shown for display is resolved through
     * TutorService::findById(), an already-existing Application-layer
     * read.
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
            $connection = TTransaction::get();

            $service = self::buildPaymentService($tenant_context, $connection);

            // every row this listing can ever show comes from this call
            $receivables = $service->listOpenReceivables();

            $tutor_service = self::buildTutorService($tenant_context, $connection);

            $tutor_name_filter = TSession::getValue('Receivable_filter_tutor_name');
            $tutor_name_filter = !empty($tutor_name_filter) ? mb_strtolower((string) $tutor_name_filter) : null;

            $rows = [];
            foreach ($receivables as $receivable)
            {
                $tutor = $tutor_service->findById($receivable->tutorId());
                $tutor_name = $tutor !== null ? $tutor->fullName : ('#' . $receivable->tutorId());

                if ($tutor_name_filter !== null && mb_strpos(mb_strtolower($tutor_name), $tutor_name_filter) === false)
                {
                    continue;
                }

                $row = new stdClass;
                $row->id           = $receivable->id();
                $row->tutor_name   = $tutor_name;
                $row->total_label  = number_format($receivable->totalCents() / 100, 2, ',', '.');
                $row->paid_label   = number_format($receivable->paidCents() / 100, 2, ',', '.');
                $row->status       = $receivable->status();
                $row->status_label = $receivable->status() === \CentralVet\Domain\Receivable::STATUS_PARTIALLY_PAID
                    ? _t('Partially paid')
                    : _t('Open (status)');

                $rows[] = $row;
            }

            // total count for this tenant (after the optional tutor-name
            // filter, mirroring TStandardList's own filtered-count
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
            $obj->tutor_name = TSession::getValue(get_class($this).'_filter_data')->tutor_name;
            TForm::sendData('form_search_tutor_name', $obj);
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
     * Wires an Application-layer PaymentService instance, mirroring
     * PaymentForm::buildPaymentService(). Requires an already-open
     * TTransaction('permission') connection.
     */
    private static function buildPaymentService(\CentralVet\Tenancy\TenantContext $context, $connection): \CentralVet\Application\PaymentService
    {
        $payments = new \CentralVet\Persistence\PaymentRepository($context, $connection);
        $receivables = new \CentralVet\Persistence\ReceivableRepository($context, $connection);
        $cashSessions = new \CentralVet\Persistence\CashSessionRepository($context, $connection);
        $entries = new \CentralVet\Persistence\FinancialEntryRepository($context, $connection);

        $authorization = new \CentralVet\Authorization\RbacAuthorizationService(
            new \CentralVet\Authorization\AdiantiProgramPermissionProvider(new \CentralVet\Tenancy\AdiantiSessionContextSource()),
            new \CentralVet\Audit\PdoAuditLogWriter($connection),
        );

        $financial_entries = new \CentralVet\Application\FinancialEntryService($entries, $authorization, $context);

        return new \CentralVet\Application\PaymentService(
            $payments,
            $receivables,
            $cashSessions,
            $financial_entries,
            $authorization,
            $context,
        );
    }

    /**
     * Wires an Application-layer TutorService instance, used only to
     * resolve a tutor's display name for this listing's grid — never to
     * create/mutate a tutor. Requires an already-open
     * TTransaction('permission') connection.
     */
    private static function buildTutorService(\CentralVet\Tenancy\TenantContext $context, $connection): \CentralVet\Application\TutorService
    {
        $tutors = new \CentralVet\Persistence\TutorRepository($context, $connection);

        return new \CentralVet\Application\TutorService($tutors);
    }

    /**
     * Resolves the tenant context of the authenticated session. Copied
     * verbatim from PayableList::resolveTenantContext() (falls back to the
     * tenant_user membership table for legacy sessions created before this
     * task, since TSession does not carry 'tenantid' yet).
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
