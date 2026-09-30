<?php
/**
 * BankAccountList
 *
 * Contas bancárias da unidade da sessão (rodada 2, T-22), com o saldo
 * informado à mão (sem conciliação). Toda linha vem de
 * CentralVet\Application\BankAccountService::listByUnit() (T-15), e o
 * rodapé mostra BankAccountService::totalBalanceCents() — a soma das contas
 * ativas. O serviço e o repositório já restringem à unidade corrente; esta
 * tela nunca toca a camada Persistence para ler.
 *
 * @version    1.0
 * @package    control
 * @subpackage clinic
 */
class BankAccountList extends TPage
{
    protected $datagrid;
    protected $totalBox;
    private $loaded = false;

    public function __construct($param = null)
    {
        parent::__construct();

        $this->datagrid = new BootstrapDatagridWrapper(new TDataGrid);
        CvDatagrid::decorate($this->datagrid, false);
        $this->datagrid->disableDefaultClick();
        $this->datagrid->setActionSide('right');

        $column_name       = new TDataGridColumn('name', _t('Name'), 'left');
        $column_bank       = new TDataGridColumn('bank_name', _t('Bank'), 'left');
        $column_balance    = new TDataGridColumn('balance_cents', _t('Balance'), 'right', 150);
        $column_updated_at = new TDataGridColumn('balance_updated_at', _t('Updated at'), 'left', 150);
        $column_active     = new TDataGridColumn('active', _t('Status'), 'left', 110);

        $column_bank->setTransformer(function ($value) {
            return $value !== null && $value !== '' ? CvFormat::e((string) $value) : '—';
        });
        $column_balance->setTransformer(function ($value) {
            return self::moneySpan((int) $value);
        });
        $column_active->setTransformer(function ($value) {
            return $value
                ? CvBadge::create(_t('Active'), 'success')
                : CvBadge::create(_t('Inactive'), 'neutral');
        });

        $this->datagrid->addColumn($column_name);
        $this->datagrid->addColumn($column_bank);
        $this->datagrid->addColumn($column_balance);
        $this->datagrid->addColumn($column_updated_at);
        $this->datagrid->addColumn($column_active);

        $action_edit = new TDataGridAction(['BankAccountForm', 'onEdit'], ['key' => '{id}', 'register_state' => 'false']);
        $this->datagrid->addActionGroup(CvDatagrid::actionMenu([
            ['label' => _t('Edit'), 'action' => $action_edit, 'icon' => 'far:edit'],
        ]));

        $this->datagrid->createModel();

        $this->totalBox = new TElement('div');
        $this->totalBox->{'class'} = 'cv-table-footer';

        $container = new TVBox;
        $container->style = 'width: 100%';
        $container->add(CvPage::header(_t('Bank accounts'), _t('Financial'), [
            ['label' => _t('New'), 'href' => 'index.php?class=BankAccountForm&method=onEdit&register_state=false', 'icon' => 'fa:plus', 'class' => 'btn btn-primary'],
        ]));
        $container->add(CvNav::tabs('finance', 'bank_accounts'));
        $container->add($this->datagrid);
        $container->add($this->totalBox);

        parent::add($container);
    }

    /**
     * Carrega as contas da unidade da sessão e o total das ativas.
     */
    public function onReload($param = null)
    {
        try
        {
            TTransaction::open('permission');

            $tenant_context = self::resolveTenantContext();
            $service = self::buildService($tenant_context);
            $unit_id = $tenant_context->requireUnitId();

            $this->datagrid->clear();
            foreach ($service->listByUnit($unit_id) as $account)
            {
                $row = new stdClass;
                $row->id                 = $account->id();
                $row->name               = $account->name();
                $row->bank_name          = $account->bankName();
                $row->balance_cents      = $account->balanceCents();
                $row->balance_updated_at = $account->balanceUpdatedAt() !== null
                    ? $account->balanceUpdatedAt()->format('d/m/Y H:i')
                    : '—';
                $row->active             = $account->isActive();

                $this->datagrid->addItem($row);
            }

            $total = $service->totalBalanceCents($unit_id);

            TTransaction::close();

            $this->totalBox->clearChildren();
            $summary = new TElement('div');
            $summary->{'class'} = 'cv-table-footer__summary';
            $summary->add(CvFormat::e(_t('Total of active accounts')) . ': ');
            $summary->add($total !== null ? self::moneySpan($total) : '—');
            $this->totalBox->add($summary);

            $this->loaded = true;
        }
        catch (\CentralVet\Tenancy\Exception\MissingTenantContext $e)
        {
            TTransaction::rollback();
            new TMessage('error', _t('An authenticated session with a tenant is required'));
        }
        catch (Exception $e)
        {
            TTransaction::rollback();
            new TMessage('error', $e->getMessage());
        }
    }

    public function show()
    {
        if (!$this->loaded)
        {
            $this->onReload();
        }

        parent::show();
    }

    /**
     * Valor em R$, com negativo em vermelho.
     */
    private static function moneySpan(int $cents): string
    {
        $class = $cents < 0 ? ' class="text-danger"' : '';

        return '<span' . $class . '>' . CvFormat::e(CvFormat::money($cents)) . '</span>';
    }

    /**
     * Requires an already-open TTransaction('permission') connection.
     */
    private static function buildService(\CentralVet\Tenancy\TenantContext $context): \CentralVet\Application\BankAccountService
    {
        return new \CentralVet\Application\BankAccountService(
            new \CentralVet\Persistence\BankAccountRepository($context, TTransaction::get()),
            $context,
        );
    }

    /**
     * Resolves the tenant context of the authenticated session, with the
     * tenant_user fallback used by the other clinic screens.
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
