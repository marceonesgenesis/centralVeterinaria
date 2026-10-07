<?php
/**
 * PayableList
 *
 * Listing screen for Payables (T-09). Mirrors ServiceList (Fase 1) /
 * ProcedureCatalogList (T-08 da Fase 4): this listing has no data-access
 * logic of its own — onReload() is overridden to source every row from
 * CentralVet\Application\PayableService::listByStatus() (T-28) — the
 * Persistence layer (CentralVet\Persistence\PayableRepository) is never
 * touched from here.
 *
 * Filtro de status (T-28): combo `status` Em aberto (padrão) / Pagas /
 * Todas; o valor vem de $param['status'] (ou da URL) e fica na sessão
 * `PayableList_filter_status`, então voltar pelo menu reabre o último
 * filtro. Valor inválido cai em Em aberto.
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
    protected $footerBox;

    /** @var \CentralVet\Domain\Payable|null conta recém-paga, exibida com badge Pago no recarregamento */
    private $justPaid = null;

    /** @var TCombo filtro de status (open|paid|all) */
    private $statusCombo;

    /** valores do combo de status; 'all' lista todos os status */
    private const STATUS_FILTERS = ['open', 'paid', 'all'];

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

        // barra de filtros em linha (busca por descrição), no lugar da cortina
        $this->form = new TForm('form_search_Payable');

        $description_text = new TEntry('description_text');
        $description_text->placeholder = _t('Description');
        $description_text->setSize('100%');

        $status = new TCombo('status');
        $status->addItems([
            'open' => _t('Open (filter)'),
            'paid' => _t('Paid (filter)'),
            'all'  => _t('All (filter)'),
        ]);
        $status->setDefaultOption(false);
        $status->setSize('100%');
        $this->statusCombo = $status;

        $find = new TButton('find');
        $find->setAction(new TAction([$this, 'onSearch']), _t('Find'));
        $find->setImage('fa:search');
        $find->{'class'} = 'btn btn-primary';

        $this->form->add(CvPage::filterBar([$description_text, $status, $find]));
        $this->form->setFields([$description_text, $status, $find]);

        // keep the form filled during navigation with session data
        $this->form->setData( TSession::getValue('Payable_filter_data') );
        $status->setValue(self::sessionStatus());

        // creates a DataGrid
        $this->datagrid = new BootstrapDatagridWrapper(new TDataGrid);
        CvDatagrid::decorate($this->datagrid);
        $this->datagrid->disableDefaultClick();
        $this->datagrid->setActionSide('right');

        // creates the datagrid columns
        $column_description = new TDataGridColumn('description_text', _t('Description'), 'left');
        $column_category    = new TDataGridColumn('category', _t('Category'), 'left');
        $column_due_date    = new TDataGridColumn('due_date_label', _t('Due date'), 'left', 120);
        $column_amount      = new TDataGridColumn('amount_cents', _t('Amount'), 'right', 130);
        $column_status      = new TDataGridColumn('status', _t('Status'), 'left', 110);

        $column_amount->setTransformer(function ($value) {
            return CvFormat::e(CvFormat::money((int) $value));
        });
        $column_status->setTransformer(function ($value) {
            return self::statusBadge((string) $value);
        });

        // add the columns to the DataGrid
        $this->datagrid->addColumn($column_description);
        $this->datagrid->addColumn($column_category);
        $this->datagrid->addColumn($column_due_date);
        $this->datagrid->addColumn($column_amount);
        $this->datagrid->addColumn($column_status);

        // row action ("…" → Pagar): settle this payable
        $action_pay = new TDataGridAction(array($this, 'onPay'), ['id' => '{id}']);
        $action_pay->setDisplayCondition(function ($object) {
            return $object->status === \CentralVet\Domain\Payable::STATUS_OPEN;
        });
        $this->datagrid->addActionGroup(CvDatagrid::actionMenu([
            ['label' => _t('Pay'), 'action' => $action_pay, 'icon' => 'fa:money-bill-wave'],
        ]));

        // create the datagrid model
        $this->datagrid->createModel();

        // create the page navigation
        $this->pageNavigation = new TPageNavigation;
        $this->pageNavigation->setAction(new TAction(array($this, 'onReload')));
        $this->pageNavigation->setWidth($this->datagrid->getWidth());

        $this->footerBox = new TElement('div');

        // No TXMLBreadCrumb here on purpose: TXMLBreadCrumb throws when the
        // class is not listed in menu.xml, which would make
        // `new PayableList()` fatal whenever the menu changes.
        $container = new TVBox;
        $container->style = 'width: 100%';
        $container->add(CvPage::header(_t('Payables'), _t('Financial'), [
            ['label' => _t('New payable'), 'href' => 'index.php?class=PayableForm&method=onEdit&register_state=false', 'icon' => 'fa:plus', 'class' => 'btn btn-primary'],
        ]));
        $container->add(CvNav::tabs('finance', 'payables'));
        $container->add($this->form);
        $container->add($this->datagrid);
        $container->add($this->footerBox);

        parent::add($container);
    }

    /**
     * Badge de status: Aberto / Pago / Cancelado.
     */
    private static function statusBadge(string $status): TElement
    {
        if ($status === \CentralVet\Domain\Payable::STATUS_PAID)
        {
            return CvBadge::create(_t('Paid'), 'success');
        }
        if ($status === \CentralVet\Domain\Payable::STATUS_OPEN)
        {
            return CvBadge::create(_t('Open (status)'), 'warning');
        }

        if ($status === \CentralVet\Domain\Payable::STATUS_CANCELLED)
        {
            return CvBadge::create(_t('Canceled'), 'neutral');
        }

        return CvBadge::create($status, 'neutral');
    }

    /**
     * Filtro de status da sessão, ou 'open' quando ausente/inválido.
     */
    private static function sessionStatus(): string
    {
        $value = TSession::getValue(__CLASS__ . '_filter_status');

        return in_array($value, self::STATUS_FILTERS, true) ? $value : 'open';
    }

    /**
     * Resolve o filtro de status: $param['status'] (ou da URL) quando
     * presente, senão o da sessão; valor inválido vira 'open'. O resultado
     * fica na sessão PayableList_filter_status.
     */
    private static function resolveStatus($param): string
    {
        $value = $param['status'] ?? ($_REQUEST['status'] ?? null);

        if ($value === null || $value === '')
        {
            return self::sessionStatus();
        }

        $value = in_array($value, self::STATUS_FILTERS, true) ? $value : 'open';
        TSession::setValue(__CLASS__ . '_filter_status', $value);

        return $value;
    }

    /**
     * Busca: guarda o status escolhido no combo antes do fluxo padrão
     * (descrição na sessão + onReload).
     */
    public function onSearch($param = null)
    {
        self::resolveStatus($param);

        parent::onSearch($param);
    }

    /**
     * method onReload()
     * Loads the datagrid exclusively from
     * CentralVet\Application\PayableService::listByStatus() com o filtro
     * de status resolvido (open|paid|all → null) — the tenant scoping
     * happens inside that service/repository, never here.
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

            $status = self::resolveStatus($param);
            $this->statusCombo->setValue($status);

            // every row this listing can ever show comes from this call
            $payables = $service->listByStatus($tenant_context->requireUnitId(), $status === 'all' ? null : $status);

            $description_filter = TSession::getValue('Payable_filter_description_text');
            $description_filter = !empty($description_filter) ? mb_strtolower((string) $description_filter) : null;

            // filtro Em aberto não traz contas pagas: a recém-paga (onPay)
            // entra no topo desta renderização com o status atualizado (Pago);
            // nos filtros Pagas/Todas ela já vem da consulta
            if ($status === 'open' && $this->justPaid instanceof \CentralVet\Domain\Payable)
            {
                array_unshift($payables, $this->justPaid);
            }

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
                $row->amount_cents     = $payable->amountCents();
                $row->due_date_label   = $payable->dueDate() !== null ? $payable->dueDate()->format('d/m/Y') : '—';
                $row->status           = $payable->status();

                $rows[] = $row;
            }

            // total count for this tenant/unit (after the optional
            // description filter, mirroring TStandardList's own filtered-count
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

            $this->justPaid = $service->pay(
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
    public static function onChangeLimit($param)
    {
        TSession::setValue(__CLASS__ . '_limit', $param['limit'] );
        AdiantiCoreApplication::loadPage(__CLASS__, 'onReload');
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
