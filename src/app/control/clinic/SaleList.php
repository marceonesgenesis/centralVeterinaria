<?php

/**
 * SaleList
 *
 * Histórico de vendas do PDV (Etapa 2, T-16): só leitura. Cada linha vem de
 * SaleFinanceReader::listSales/countSales (T-10), a partir dos dados
 * gravados — venda, recebível e recebimentos sem estorno. A ação "Cancelar
 * venda" aparece só em venda concluída e abre SaleCancelForm na cortina
 * lateral; o cancelamento em si só acontece no POST de SaleCancelForm.
 *
 * Colunas e cabeçalhos alinhados à esquerda (CLAUDE.md).
 *
 * @version    1.0
 * @package    control
 * @subpackage clinic
 */
class SaleList extends TPage
{
    private const LIMIT = 20;

    private $datagrid;
    private $pageNavigation;
    private $footerBox;
    private $loaded = false;

    public function __construct($param = null)
    {
        parent::__construct();

        $this->datagrid = new BootstrapDatagridWrapper(new TDataGrid);
        CvDatagrid::decorate($this->datagrid);
        $this->datagrid->disableDefaultClick();

        $column_id        = new TDataGridColumn('id', '#', 'left', 70);
        $column_date      = new TDataGridColumn('sold_at', _t('Date'), 'left', 150);
        $column_tutor     = new TDataGridColumn('tutor_name', _t('Tutor'), 'left');
        $column_total     = new TDataGridColumn('total_cents', _t('Total'), 'left', 130);
        $column_status    = new TDataGridColumn('status', _t('Status'), 'left', 120);
        $column_financial = new TDataGridColumn('receivable_status', _t('Financial status'), 'left', 150);
        $column_received  = new TDataGridColumn('received_cents', _t('Amount received'), 'left', 150);
        $column_action    = new TDataGridColumn('action', '', 'left', 170);

        $money = function ($value) {
            return CvFormat::e(CvFormat::money((int) $value));
        };
        $column_total->setTransformer($money);
        $column_received->setTransformer($money);
        $column_tutor->setTransformer(function ($value) {
            return CvFormat::e((string) $value);
        });
        $column_date->setTransformer(function ($value) {
            $time = strtotime((string) $value);
            return CvFormat::e($time !== false ? date('d/m/Y H:i', $time) : (string) $value);
        });
        $column_status->setTransformer(function ($value) {
            return self::saleStatusBadge((string) $value);
        });
        $column_financial->setTransformer(function ($value) {
            return self::financialStatusBadge($value === null || $value === '' ? null : (string) $value);
        });

        $this->datagrid->addColumn($column_id);
        $this->datagrid->addColumn($column_date);
        $this->datagrid->addColumn($column_tutor);
        $this->datagrid->addColumn($column_total);
        $this->datagrid->addColumn($column_status);
        $this->datagrid->addColumn($column_financial);
        $this->datagrid->addColumn($column_received);
        $this->datagrid->addColumn($column_action);

        $this->datagrid->createModel();

        $this->pageNavigation = new TPageNavigation;
        $this->pageNavigation->setAction(new TAction([$this, 'onReload']));
        $this->pageNavigation->setWidth($this->datagrid->getWidth());

        $this->footerBox = new TElement('div');

        $container = new TVBox;
        $container->style = 'width: 100%';
        $container->add(CvPage::header(_t('Sale history'), _t('Stock')));
        $container->add(CvNav::tabs('stock', 'sale_history'));
        $container->add($this->datagrid);
        $container->add($this->footerBox);

        parent::add($container);
    }

    /**
     * Leitura: uma página de vendas do tenant pelo SaleFinanceReader.
     */
    public function onReload($param = null)
    {
        try
        {
            $offset = is_array($param) && isset($param['offset']) ? max(0, (int) $param['offset']) : 0;

            $context = self::resolveTenantContext();

            TTransaction::open('permission');
            $reader = new \CentralVet\Persistence\SaleFinanceReader($context, TTransaction::get());
            $sales = $reader->listSales(self::LIMIT, $offset);
            $count = $reader->countSales();
            TTransaction::close();

            $this->datagrid->clear();

            foreach ($sales as $sale)
            {
                $row = (object) $sale;
                $row->action = $sale['status'] === \CentralVet\Domain\Sale::STATUS_COMPLETED
                    ? self::cancelLink((int) $sale['id'])
                    : '';
                $this->datagrid->addItem($row);
            }

            $this->pageNavigation->setCount($count);
            $this->pageNavigation->setProperties($param);
            $this->pageNavigation->setLimit(self::LIMIT);

            $this->footerBox->clearChildren();
            $this->footerBox->add(CvDatagrid::footer(
                $this->pageNavigation,
                $count === 0 ? 0 : $offset + 1,
                $offset + count($sales),
                $count,
                mb_strtolower(_t('Sales'))
            ));

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
            error_log(__METHOD__ . ': ' . $e->getMessage());
            new TMessage('error', CvFormat::userError($e));
        }
    }

    public function show()
    {
        if (!$this->loaded)
        {
            $this->onReload($_GET);
        }

        parent::show();
    }

    public static function saleStatusBadge(string $status): TElement
    {
        return $status === \CentralVet\Domain\Sale::STATUS_CANCELLED
            ? CvBadge::create(_t('Cancelled'), 'danger')
            : CvBadge::create(_t('Completed'), 'success');
    }

    public static function financialStatusBadge(?string $status): TElement|string
    {
        switch ($status)
        {
            case \CentralVet\Domain\Receivable::STATUS_PAID:
                return CvBadge::create(_t('Paid'), 'success');
            case \CentralVet\Domain\Receivable::STATUS_PARTIALLY_PAID:
                return CvBadge::create(_t('Partially paid'), 'warning');
            case \CentralVet\Domain\Receivable::STATUS_OPEN:
                return CvBadge::create(_t('Open (status)'), 'info');
            case \CentralVet\Domain\Receivable::STATUS_CANCELLED:
                return CvBadge::create(_t('Cancelled'), 'neutral');
            default:
                return '—';
        }
    }

    /**
     * Link de leitura (GET onEdit) que abre a cortina de cancelamento.
     */
    private static function cancelLink(int $saleId): string
    {
        $url = 'index.php?class=SaleCancelForm&method=onEdit&sale_id=' . $saleId;

        return '<a href="' . CvFormat::e($url) . '" generator="adianti" class="btn btn-sm btn-outline-danger cv-touch-target">'
             . '<i class="fa fa-ban" aria-hidden="true"></i> ' . CvFormat::e(_t('Cancel sale')) . '</a>';
    }

    /**
     * Resolves the tenant context of the authenticated session (same
     * fallback as PaymentForm::resolveTenantContext() for legacy sessions).
     */
    private static function resolveTenantContext(): \CentralVet\Tenancy\TenantContext
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
