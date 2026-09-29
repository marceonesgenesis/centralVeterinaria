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

        // creates the form
        $this->form = new BootstrapFormBuilder('form_search_CashSession');
        $this->form->setFormTitle(_t('Cash sessions'));

        // creates a DataGrid
        $this->datagrid = new BootstrapDatagridWrapper(new TDataGrid);
        $this->datagrid->style = 'width: 100%';
        $this->datagrid->setHeight(320);

        // creates the datagrid columns
        $column_id            = new TDataGridColumn('id', 'Id', 'center', 50);
        $column_opened_at     = new TDataGridColumn('opened_at', _t('Opened at'), 'center', 150);
        $column_opening       = new TDataGridColumn('opening_balance_cents', _t('Opening balance'), 'right', 120);
        $column_status        = new TDataGridColumn('status', _t('Status'), 'center', 100);
        $column_closing       = new TDataGridColumn('closing_balance_cents', _t('Closing balance'), 'right', 120);
        $column_closed_at     = new TDataGridColumn('closed_at', _t('Closed at'), 'center', 150);

        $column_opening->setTransformer(function ($value) {
            return $value !== null ? number_format(((int) $value) / 100, 2, ',', '.') : '';
        });
        $column_closing->setTransformer(function ($value) {
            return $value !== null ? number_format(((int) $value) / 100, 2, ',', '.') : '';
        });
        $column_status->setTransformer(function ($value) {
            return $value === 'open' ? _t('Open') : _t('Closed');
        });

        // add the columns to the DataGrid
        $this->datagrid->addColumn($column_id);
        $this->datagrid->addColumn($column_opened_at);
        $this->datagrid->addColumn($column_opening);
        $this->datagrid->addColumn($column_status);
        $this->datagrid->addColumn($column_closing);
        $this->datagrid->addColumn($column_closed_at);

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

        $panel->addHeaderActionLink(_t('Cash session'), new TAction(['CashSessionForm', 'onReload']), 'fa:cash-register');

        // page header (design system: .cv-page-header / .cv-page-title,
        // mirrors src/design-system.html)
        $page_header = new TElement('header');
        $page_header->class = 'cv-page-header';

        $page_header_content = new TElement('div');

        $page_header_title = new TElement('h1');
        $page_header_title->class = 'cv-page-title';
        $page_header_title->add(_t('Cash sessions'));

        $page_header_content->add($page_header_title);
        $page_header->add($page_header_content);

        // vertical box container
        $container = new TVBox;
        $container->style = 'width: 100%';
        $container->add(new TXMLBreadCrumb('menu.xml', __CLASS__));
        $container->add($page_header);
        $container->add($panel);

        parent::add($container);
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
