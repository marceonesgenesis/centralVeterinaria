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
    protected $footerBox;

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

        // barra de filtros em linha (busca por tutor), no lugar da cortina
        $this->form = new TForm('form_search_Receivable');

        $tutor_name = new TEntry('tutor_name');
        $tutor_name->placeholder = _t('Tutor');
        $tutor_name->setSize('100%');

        $find = new TButton('find');
        $find->setAction(new TAction([$this, 'onSearch']), _t('Find'));
        $find->setImage('fa:search');
        $find->{'class'} = 'btn btn-primary';

        $this->form->add(CvPage::filterBar([$tutor_name, $find]));
        $this->form->setFields([$tutor_name, $find]);

        // keep the form filled during navigation with session data
        $this->form->setData( TSession::getValue('Receivable_filter_data') );

        // creates a DataGrid
        $this->datagrid = new BootstrapDatagridWrapper(new TDataGrid);
        CvDatagrid::decorate($this->datagrid);
        $this->datagrid->disableDefaultClick();

        // row action: receive a payment for this receivable. PaymentForm is
        // a plain TPage (not a TStandardForm), so it has no onEdit() of its
        // own — only its constructor reads receivable_id back from
        // $_GET/$param. A TDataGridAction (which extends TAction) requires a
        // real class+method callback validated via method_exists(); even a
        // real inherited method like TPage::show() is unsafe here (infinite
        // recursion through AdiantiPageControlTrait::run()), so the action
        // stays a raw <a href> without `method=` — rendered as a button in
        // the last column instead of the "…" menu.
        $column_action = new TDataGridColumn('id', '', 'right', 150);
        $column_action->setTransformer(function ($value) {
            $url = 'index.php?class=PaymentForm&receivable_id=' . (int) $value;
            return '<a href="' . CvFormat::e($url) . '" generator="adianti" class="btn btn-default btn-sm" title="' . CvFormat::e(_t('Receive payment')) . '">'
                 . '<i class="fa fa-money-bill-wave text-success" aria-hidden="true"></i> ' . CvFormat::e(_t('Receive payment')) . '</a>';
        });

        // creates the datagrid columns
        $column_tutor        = new TDataGridColumn('tutor_name', _t('Tutor'), 'left');
        $column_total        = new TDataGridColumn('total_cents', _t('Total'), 'right', 130);
        $column_paid         = new TDataGridColumn('paid_cents', _t('Paid'), 'right', 130);
        $column_status       = new TDataGridColumn('status', _t('Status'), 'left', 130);

        foreach ([$column_total, $column_paid] as $column)
        {
            $column->setTransformer(function ($value) {
                return CvFormat::e(CvFormat::money((int) $value));
            });
        }
        $column_status->setTransformer(function ($value) {
            return $value === \CentralVet\Domain\Receivable::STATUS_PARTIALLY_PAID
                ? CvBadge::create(_t('Partially paid'), 'warning')
                : CvBadge::create(_t('Open (status)'), 'info');
        });

        // add the columns to the DataGrid
        $this->datagrid->addColumn($column_tutor);
        $this->datagrid->addColumn($column_total);
        $this->datagrid->addColumn($column_paid);
        $this->datagrid->addColumn($column_status);
        $this->datagrid->addColumn($column_action);

        // create the datagrid model
        $this->datagrid->createModel();

        // create the page navigation
        $this->pageNavigation = new TPageNavigation;
        $this->pageNavigation->setAction(new TAction(array($this, 'onReload')));
        $this->pageNavigation->setWidth($this->datagrid->getWidth());

        $this->footerBox = new TElement('div');

        // vertical box container
        // No TXMLBreadCrumb here on purpose — see class docblock.
        $container = new TVBox;
        $container->style = 'width: 100%';
        $container->add(CvPage::header(_t('Open receivables'), _t('Financial')));
        $container->add(CvNav::tabs('finance', 'receivables'));
        $container->add($this->form);
        $container->add($this->datagrid);
        $container->add($this->footerBox);

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
                $row->total_cents  = $receivable->totalCents();
                $row->paid_cents   = $receivable->paidCents();
                $row->status       = $receivable->status();

                $rows[] = $row;
            }

            // total count for this tenant (after the optional tutor-name
            // filter, mirroring TStandardList's own filtered-count
            // semantics)
            $count = count($rows);

            $offset = isset($param['offset']) ? max(0, (int) $param['offset']) : 0;
            $limit  = isset($this->limit) ? ( $this->limit > 0 ? $this->limit : NULL) : 10;

            $page_rows = $limit ? array_slice($rows, $offset, $limit) : $rows;

            $this->datagrid->clear();
            foreach ($page_rows as $row)
            {
                $this->datagrid->addItem($row);
            }

            $this->pageNavigation->setCount($count); // count of records
            $this->pageNavigation->setProperties($param); // order, page
            $this->pageNavigation->setLimit($limit); // limit

            $this->footerBox->clearChildren();
            $this->footerBox->add(CvDatagrid::footer(
                $this->pageNavigation,
                $offset + 1,
                $offset + count($page_rows),
                $count,
                _t('accounts')
            ));

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
            error_log(__METHOD__ . ': ' . $e->getMessage());
            new TMessage('error', CvFormat::userError($e));
            // undo all pending operations
            TTransaction::rollback();
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

        return new \CentralVet\Application\TutorService($tutors, $context);
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
