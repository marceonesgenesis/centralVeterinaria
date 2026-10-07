<?php
/**
 * CashSessionList
 *
 * History of cash till sessions for the active unit (T-08). Read-only,
 * same shape as SystemUnitList: no onReload() override, the grid is bound
 * straight to the `CashSession` TRecord (app/model/clinic/CashSession.php)
 * through TStandardList's own default loading, scoped to the current
 * tenant/unit via setCriteria() exactly like SystemUnitList does for
 * tenant_id. There is no Edit/Delete row action: a session's lifecycle only
 * moves through CentralVet\Application\CashSessionService::open()/close()
 * (T-04), reached from CashSessionForm — this listing never writes.
 *
 * @version    1.0
 * @package    control
 * @subpackage clinic
 */
class CashSessionList extends TStandardList
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

        parent::setDatabase('permission');           // defines the database
        parent::setActiveRecord('CashSession');       // defines the active record
        parent::setDefaultOrder('opened_at', 'desc');
        parent::setLimit(TSession::getValue(__CLASS__ . '_limit') ?? 10);

        // tenant + active-unit scoping (mirrors SystemUnitList::__construct()):
        // this history only ever shows the authenticated user's tenant and
        // active system unit, never another unit's sessions.
        $tenant_context = self::resolveTenantContext();
        $criteria = new TCriteria;
        $criteria->add(new TFilter('tenant_id', '=', $tenant_context->tenantId()));
        $criteria->add(new TFilter('system_unit_id', '=', $tenant_context->requireUnitId()));
        parent::setCriteria($criteria);

        // creates the form (sem filtros: histórico da unidade ativa)
        $this->form = new TForm('form_search_CashSession');

        // creates a DataGrid
        $this->datagrid = new BootstrapDatagridWrapper(new TDataGrid);
        CvDatagrid::decorate($this->datagrid);
        $this->datagrid->disableDefaultClick();

        // creates the datagrid columns
        $column_opened_at     = new TDataGridColumn('opened_at', _t('Opened at'), 'left', 150);
        $column_opening       = new TDataGridColumn('opening_balance_cents', _t('Opening balance'), 'right', 140);
        $column_closed_at     = new TDataGridColumn('closed_at', _t('Closed at'), 'left', 150);
        $column_closing       = new TDataGridColumn('closing_balance_cents', _t('Closing balance'), 'right', 140);
        $column_status        = new TDataGridColumn('status', _t('Status'), 'left', 110);

        $money = function ($value) {
            return $value !== null && $value !== '' ? CvFormat::e(CvFormat::money((int) $value)) : '—';
        };
        $date = function ($value) {
            if ($value === null || $value === '')
            {
                return '—';
            }
            $time = strtotime((string) $value);
            return CvFormat::e($time !== false ? date('d/m/Y H:i', $time) : (string) $value);
        };

        $column_opening->setTransformer($money);
        $column_closing->setTransformer($money);
        $column_opened_at->setTransformer($date);
        $column_closed_at->setTransformer($date);
        $column_status->setTransformer(function ($value) {
            return $value === 'open'
                ? CvBadge::create(_t('Open (status)'), 'success')
                : CvBadge::create(_t('Closed'), 'neutral');
        });

        // add the columns to the DataGrid
        $this->datagrid->addColumn($column_opened_at);
        $this->datagrid->addColumn($column_opening);
        $this->datagrid->addColumn($column_closed_at);
        $this->datagrid->addColumn($column_closing);
        $this->datagrid->addColumn($column_status);

        // create the datagrid model
        $this->datagrid->createModel();

        // create the page navigation
        $this->pageNavigation = new TPageNavigation;
        $this->pageNavigation->setAction(new TAction(array($this, 'onReload')));
        $this->pageNavigation->setWidth($this->datagrid->getWidth());

        $this->footerBox = new TElement('div');

        // No TXMLBreadCrumb: o cabeçalho do kit substitui a trilha e a tela
        // não quebra quando o menu.xml é reorganizado.
        $container = new TVBox;
        $container->style = 'width: 100%';
        $container->add(CvPage::header(_t('Cash sessions'), _t('Financial'), [
            ['label' => _t('Cash session'), 'href' => 'index.php?class=CashSessionForm', 'icon' => 'fa:cash-register', 'class' => 'btn btn-primary'],
        ]));
        $container->add(CvNav::tabs('finance', 'cashflow'));
        $container->add($this->datagrid);
        $container->add($this->footerBox);

        parent::add($container);
    }

    /**
     * Carregamento padrão do TStandardList (CashSession + critério de
     * tenant/unidade) seguido do rodapé "Mostrando X–Y de N".
     */
    public function onReload($param = NULL)
    {
        $objects = parent::onReload($param);

        if ($this->loaded)
        {
            $offset = is_array($param) && isset($param['offset']) ? max(0, (int) $param['offset']) : 0;
            $shown  = is_array($objects) ? count($objects) : 0;

            $this->footerBox->clearChildren();
            $this->footerBox->add(CvDatagrid::footer(
                $this->pageNavigation,
                $offset + 1,
                $offset + $shown,
                (int) $this->pageNavigation->getCount(),
                _t('sessions')
            ));
        }

        return $objects;
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
