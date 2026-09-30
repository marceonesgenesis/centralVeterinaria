<?php
/**
 * ProductList — "Estoque e Vendas" (Fase 10, T-10).
 *
 * Presentation-only screen over CentralVet\Application\StockSalesOverviewService
 * (T-04): KPI cards, stock tabs, filter bar (search/category/status), product
 * table with stock status badges and a side column with recent sales and
 * low-stock products. Every figure comes from the service; tenant scoping lives
 * in StockSalesOverviewReader, never here.
 *
 * Filters travel as request parameters (GET or POST): search, category,
 * status (StockSalesOverviewService::STATUS_* or 'attention' = low + out).
 *
 * One screen load runs a single StockSalesOverviewService::overview() call
 * (summary, product table and low-stock column from one reader scan).
 * The table shows the product code and sale price (T-11, '—' when empty).
 * "Generate report" opens ProductList::onReport (static, dompdf) in a new
 * tab with the current filters (Rodada 2, T-21).
 *
 * @version    2.0
 * @package    control
 * @subpackage clinic
 */
class ProductList extends TPage
{
    private const LIMIT = 10;
    private const LOW_STOCK_LIMIT = 5;
    private const RECENT_SALES_LIMIT = 5;
    private const ATTENTION = 'attention';
    private const STATUSES = [
        \CentralVet\Application\StockSalesOverviewService::STATUS_NORMAL,
        \CentralVet\Application\StockSalesOverviewService::STATUS_LOW,
        \CentralVet\Application\StockSalesOverviewService::STATUS_OUT,
        self::ATTENTION,
    ];

    protected $datagrid;
    protected $pageNavigation;
    protected $filterForm;
    protected $footerSlot;
    protected $loaded = false;
    private bool $tenantErrorShown = false;

    private array $filters = ['search' => null, 'category' => null, 'status' => null];
    private ?\CentralVet\Application\StockSalesOverviewService $service = null;
    private ?array $overview = null;
    private ?array $overviewFilters = null;

    public function __construct($param = null)
    {
        parent::__construct();

        $this->filters = self::readFilters(is_array($param) ? $param : []);

        $summary    = ['products_in_stock' => 0, 'low_stock' => 0, 'out_of_stock' => 0, 'sales_month_cents' => 0, 'sales_prev_month_cents' => 0, 'items_sold_month' => 0, 'items_sold_prev_month' => 0];
        $categories = [];
        $recent     = [];
        $low        = [];

        try
        {
            TTransaction::open('permission');
            $service    = $this->service();
            $overview   = $this->overview();
            $summary    = $overview['summary'];
            $low        = $overview['low_stock'];
            $categories = $service->categories();
            $recent     = $service->recentSales(self::RECENT_SALES_LIMIT);
            TTransaction::close();
        }
        catch (\CentralVet\Tenancy\Exception\MissingTenantContext $e)
        {
            TTransaction::rollback();
            $this->tenantErrorShown = true;
            new TMessage('error', _t('Your session does not have an active tenant. Please log in again'));
        }
        catch (Exception $e)
        {
            TTransaction::rollback();
            new TMessage('error', $e->getMessage());
        }

        $page = new TElement('div');
        $page->{'class'} = 'cv-page';

        $page->add(CvPage::header(_t('Stock and sales'), null, [
            ['label' => _t('Generate report'), 'href' => self::reportHref($this->filters), 'icon' => 'fa:file-pdf', 'class' => 'btn btn-outline-secondary', 'target' => '_blank'],
            ['label' => _t('New product'), 'href' => 'index.php?class=ProductForm', 'icon' => 'fa:plus', 'class' => 'btn btn-primary'],
        ]));

        $page->add(self::kpiRow($summary));
        $page->add(CvNav::tabs('stock', 'products'));

        $main = new TElement('div');
        $main->add($this->buildFilterForm($categories));
        $main->add($this->buildDatagrid());

        $page->add(CvPage::columns($main, self::sideColumn($recent, $low)));

        parent::add($page);
    }

    /**
     * Loads the product table (filters + pagination) from the products of
     * StockSalesOverviewService::overview() — reused from the constructor
     * when the filters are the same, so one load scans the stock once.
     */
    public function onReload($param = null)
    {
        $param = is_array($param) ? $param : [];
        $this->filters = self::readFilters($param);

        try
        {
            TTransaction::open('permission');
            $rows = self::attentionFilter($this->overview()['products'], $this->filters['status']);
            TTransaction::close();
        }
        catch (\CentralVet\Tenancy\Exception\MissingTenantContext $e)
        {
            TTransaction::rollback();
            if (!$this->tenantErrorShown)
            {
                $this->tenantErrorShown = true;
                new TMessage('error', _t('Your session does not have an active tenant. Please log in again'));
            }
            return;
        }
        catch (Exception $e)
        {
            TTransaction::rollback();
            new TMessage('error', $e->getMessage());
            return;
        }

        $total  = count($rows);
        $offset = isset($param['offset']) ? max(0, (int) $param['offset']) : 0;
        if ($offset >= $total)
        {
            $offset = 0;
        }
        $page_rows = array_slice($rows, $offset, self::LIMIT);

        $this->datagrid->clear();
        foreach ($page_rows as $product)
        {
            $row = new stdClass;
            $row->id                     = $product['id'];
            $row->name                   = $product['name'];
            $row->unit                   = $product['unit'];
            $row->category               = $product['category'];
            $row->stock_quantity         = $product['stock_quantity'];
            $row->minimum_stock_quantity = $product['minimum_stock_quantity'];
            $row->status                 = $product['status'];
            $row->code                   = $product['code'] ?? null;
            $row->sale_price_cents       = $product['sale_price_cents'] ?? null;
            $this->datagrid->addItem($row);
        }

        $navigation_param = array_filter($this->filters, static fn ($value) => $value !== null);
        $this->pageNavigation->setAction(new TAction([$this, 'onReload'], $navigation_param));
        $this->pageNavigation->setCount($total);
        $this->pageNavigation->setProperties(['offset' => $offset, 'page' => intdiv($offset, self::LIMIT) + 1] + $param);
        $this->pageNavigation->setLimit(self::LIMIT);

        $from = $total > 0 ? $offset + 1 : 0;
        $to   = $offset + count($page_rows);
        $this->footerSlot->add(CvDatagrid::footer($this->pageNavigation, $from, $to, $total, mb_strtolower(_t('Products'), 'UTF-8')));

        $this->loaded = true;
    }

    public function show()
    {
        if (!$this->loaded && (!isset($_REQUEST['method']) || $_REQUEST['method'] !== 'onReload'))
        {
            $this->onReload($_REQUEST);
        }

        parent::show();
    }

    private function buildFilterForm(array $categories): TForm
    {
        $this->filterForm = new TForm('form_ProductList_filter');

        $search = new TEntry('search');
        $search->placeholder = _t('Search');
        $search->setSize('100%');
        $search->setValue($this->filters['search'] ?? '');

        $category = new TCombo('category');
        $category->setDefaultOption(_t('Category'));
        $category->addItems(array_combine($categories, $categories) ?: []);
        $category->setSize('100%');
        $category->setValue($this->filters['category'] ?? '');

        $status = new TCombo('status');
        $status->setDefaultOption(_t('Status'));
        $status->addItems(self::statusLabels());
        $status->setSize('100%');
        $status->setValue($this->filters['status'] ?? '');

        $button = new TButton('find');
        $button->setAction(new TAction([$this, 'onReload']), _t('Search'));
        $button->setImage('fa:search');
        $button->class = 'btn btn-primary';

        $this->filterForm->add(CvPage::filterBar([$search, $category, $status, $button]));
        $this->filterForm->setFields([$search, $category, $status, $button]);

        return $this->filterForm;
    }

    private function buildDatagrid(): TElement
    {
        $this->datagrid = new BootstrapDatagridWrapper(new TDataGrid);
        CvDatagrid::decorate($this->datagrid);
        $this->datagrid->disableDefaultClick(); // row click would hijack the checkbox

        $column_name     = new TDataGridColumn('name', _t('Product'), 'left');
        $column_code     = new TDataGridColumn('code', _t('Code'), 'left');
        $column_category = new TDataGridColumn('category', _t('Category'), 'left');
        $column_stock    = new TDataGridColumn('stock_quantity', _t('Current stock'), 'right');
        $column_minimum  = new TDataGridColumn('minimum_stock_quantity', _t('Minimum stock'), 'right');
        $column_price    = new TDataGridColumn('sale_price_cents', _t('Sale price'), 'right');
        $column_status   = new TDataGridColumn('status', _t('Status'), 'center');

        $column_name->setTransformer(function ($value, $object) {
            $cell = new TElement('div');
            $cell->{'class'} = 'd-flex align-items-center gap-2';
            $cell->add(CvAvatar::placeholder((string) $value));

            $text = new TElement('div');
            $text->add(TElement::tag('div', CvFormat::e((string) $value), ['class' => 'fw-semibold']));
            if (!empty($object->unit))
            {
                $text->add(TElement::tag('div', CvFormat::e((string) $object->unit), ['class' => 'small text-muted']));
            }
            $cell->add($text);

            return $cell;
        });

        $column_code->setTransformer(fn ($value) => CvFormat::e(self::code($value)));
        $column_category->setTransformer(fn ($value) => CvFormat::e((string) $value));
        $column_price->setTransformer(fn ($value) => CvFormat::e(self::price($value)));
        $column_stock->setTransformer(fn ($value, $object) => CvFormat::e(self::quantity((float) $value, $object->unit ?? null)));
        $column_minimum->setTransformer(fn ($value, $object) => CvFormat::e(self::quantity((float) $value, $object->unit ?? null)));
        $column_status->setTransformer(fn ($value) => self::statusBadge((string) $value));

        $this->datagrid->addColumn($column_name);
        $this->datagrid->addColumn($column_code);
        $this->datagrid->addColumn($column_category);
        $this->datagrid->addColumn($column_stock);
        $this->datagrid->addColumn($column_minimum);
        $this->datagrid->addColumn($column_price);
        $this->datagrid->addColumn($column_status);

        $action_edit = new TDataGridAction(['ProductForm', 'onEdit'], ['id' => '{id}']);
        $action_batch = new TDataGridAction(['StockBatchForm', 'onEdit'], ['product_id' => '{id}']);

        $this->datagrid->addActionGroup(CvDatagrid::actionMenu([
            ['label' => _t('Edit'), 'action' => $action_edit, 'icon' => 'far:edit'],
            ['label' => _t('Stock batch entry'), 'action' => $action_batch, 'icon' => 'fa:boxes'],
        ]));

        $this->datagrid->createModel();

        $this->pageNavigation = new TPageNavigation;
        $this->pageNavigation->setAction(new TAction([$this, 'onReload']));

        $this->footerSlot = new TElement('div');

        $card = new TElement('section');
        $card->{'class'} = 'cv-card';
        $body = new TElement('div');
        $body->{'class'} = 'cv-card__body';
        $body->add($this->datagrid);
        $body->add($this->footerSlot);
        $card->add($body);

        return $card;
    }

    private static function kpiRow(array $summary): TElement
    {
        $row = new TElement('div');
        $row->{'class'} = 'cv-kpi-row';

        $row->add(CvKpiCard::create('fa:boxes', 'info', (string) $summary['products_in_stock'], _t('Products in stock')));
        $row->add(CvKpiCard::create('fa:exclamation-triangle', 'warning', (string) $summary['low_stock'], _t('Low stock products')));
        $row->add(CvKpiCard::create(
            'fa:dollar-sign',
            'success',
            CvFormat::money((int) $summary['sales_month_cents']),
            _t('Sales this month'),
            CvFormat::delta((int) $summary['sales_month_cents'], (int) $summary['sales_prev_month_cents'])
        ));
        $row->add(CvKpiCard::create(
            'fa:shopping-cart',
            'info',
            (string) $summary['items_sold_month'],
            _t('Items sold'),
            CvFormat::delta((int) $summary['items_sold_month'], (int) $summary['items_sold_prev_month'])
        ));

        return $row;
    }

    private static function sideColumn(array $recent, array $low): TElement
    {
        $side = new TElement('div');

        $sales = new TElement('ul');
        $sales->{'class'} = 'list-unstyled mb-0';
        foreach ($recent as $sale)
        {
            $who = $sale['patient_name'] ?? null;

            $item = new TElement('li');
            $item->{'class'} = 'd-flex align-items-center gap-2 py-2 border-bottom';
            $item->add(CvAvatar::placeholder($who ?? (string) $sale['items_label']));

            $text = new TElement('div');
            $text->{'class'} = 'flex-grow-1 text-truncate';
            $text->add(TElement::tag('div', CvFormat::e((string) $sale['items_label']), ['class' => 'fw-semibold text-truncate']));
            $meta = self::dateTime((string) $sale['sold_at']);
            if ($who !== null && $who !== '')
            {
                $meta = $who . ' · ' . $meta;
            }
            $text->add(TElement::tag('div', CvFormat::e($meta), ['class' => 'small text-muted text-truncate']));
            $item->add($text);

            $item->add(TElement::tag('div', CvFormat::e(CvFormat::money((int) $sale['total_cents'])), ['class' => 'fw-semibold text-nowrap']));
            $sales->add($item);
        }
        if (!$recent)
        {
            $sales->add(TElement::tag('li', CvFormat::e(_t('No recent sales')), ['class' => 'text-muted py-2']));
        }
        $side->add(CvCard::create(_t('Recent sales'), $sales));

        $stock = new TElement('ul');
        $stock->{'class'} = 'list-unstyled mb-0';
        foreach ($low as $product)
        {
            $item = new TElement('li');
            $item->{'class'} = 'd-flex align-items-center gap-2 py-2 border-bottom';
            $item->add(CvAvatar::placeholder((string) $product['name']));

            $text = new TElement('div');
            $text->{'class'} = 'flex-grow-1 text-truncate';
            $text->add(TElement::tag('div', CvFormat::e((string) $product['name']), ['class' => 'fw-semibold text-truncate']));
            $text->add(TElement::tag('div', CvFormat::e(
                self::quantity((float) $product['stock_quantity'], $product['unit']) . ' / ' . _t('Minimum stock') . ': '
                . self::quantity((float) $product['minimum_stock_quantity'], $product['unit'])
            ), ['class' => 'small text-muted text-truncate']));
            $item->add($text);

            $item->add(self::statusBadge((string) $product['status']));
            $stock->add($item);
        }
        if (!$low)
        {
            $stock->add(TElement::tag('li', CvFormat::e(_t('No low stock products')), ['class' => 'text-muted py-2']));
        }
        $side->add(CvCard::create(_t('Low stock products'), $stock, _t('View all'), 'index.php?class=ProductList&status=attention'));

        return $side;
    }

    private static function statusLabels(): array
    {
        return [
            \CentralVet\Application\StockSalesOverviewService::STATUS_NORMAL => _t('Normal'),
            \CentralVet\Application\StockSalesOverviewService::STATUS_LOW    => _t('Low stock'),
            \CentralVet\Application\StockSalesOverviewService::STATUS_OUT    => _t('Out of stock'),
            self::ATTENTION => _t('Low or out of stock'),
        ];
    }

    private static function statusBadge(string $status): TElement
    {
        $tones = [
            \CentralVet\Application\StockSalesOverviewService::STATUS_NORMAL => 'success',
            \CentralVet\Application\StockSalesOverviewService::STATUS_LOW    => 'warning',
            \CentralVet\Application\StockSalesOverviewService::STATUS_OUT    => 'danger',
        ];
        $labels = self::statusLabels();

        return CvBadge::create($labels[$status] ?? $status, $tones[$status] ?? 'neutral');
    }

    private static function quantity(float $value, ?string $unit): string
    {
        $text = rtrim(rtrim(number_format($value, 3, ',', '.'), '0'), ',');

        return $unit !== null && $unit !== '' ? $text . ' ' . $unit : $text;
    }

    private static function dateTime(string $value): string
    {
        $time = strtotime($value);

        return $time ? date('d/m/Y H:i', $time) : $value;
    }

    private static function readFilters(array $param): array
    {
        $text = static function ($value): ?string {
            $value = is_string($value) ? trim($value) : '';
            return $value === '' ? null : $value;
        };

        $status = $text($param['status'] ?? null);

        return [
            'search'   => $text($param['search'] ?? null),
            'category' => $text($param['category'] ?? null),
            'status'   => in_array($status, self::STATUSES, true) ? $status : null,
        ];
    }

    /**
     * Generates the stock report PDF (dompdf, same pattern as
     * SaleForm::onGenerateReceiptPdf) for the filters in $param — the same
     * rows the table shows for those filters. Static: no page is built.
     */
    public static function onReport($param)
    {
        $filters = self::readFilters(is_array($param) ? $param : []);

        try
        {
            TTransaction::open('permission');
            $context = self::resolveTenantContext();
            $service = self::buildService($context);
            $overview = $service->overview(
                new DateTimeImmutable('now'),
                $filters['search'],
                $filters['category'],
                $filters['status'] === self::ATTENTION ? null : $filters['status'],
                self::LOW_STOCK_LIMIT
            );
            $rows = self::attentionFilter($overview['products'], $filters['status']);

            // unit cost is a product attribute the overview does not carry
            $costs = [];
            $products = new \CentralVet\Application\ProductService(new \CentralVet\Persistence\ProductRepository($context, TTransaction::get()), $context);
            foreach ($products->listActive($context->tenantId()) as $product)
            {
                $costs[(int) $product->id()] = $product->unitCostCents();
            }
            TTransaction::close();

            $dompdf = new \Dompdf\Dompdf();
            $dompdf->loadHtml(self::renderReportHtml($rows, $costs, $filters));
            $dompdf->setPaper('A4', 'landscape');
            $dompdf->render();

            header('Content-Type: application/pdf');
            header('Content-Disposition: inline; filename="estoque-' . date('Y-m-d') . '.pdf"');
            echo $dompdf->output();
            exit;
        }
        catch (\CentralVet\Tenancy\Exception\MissingTenantContext $e)
        {
            TTransaction::rollback();
            new TMessage('error', _t('Your session does not have an active tenant. Please log in again'));
        }
        catch (Exception $e)
        {
            TTransaction::rollback();
            new TMessage('error', $e->getMessage());
        }
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param array<int, int> $costs unit cost in cents by product id
     * @param array{search: ?string, category: ?string, status: ?string} $filters
     */
    private static function renderReportHtml(array $rows, array $costs, array $filters): string
    {
        $labels = self::statusLabels();

        $applied = [];
        if ($filters['search'] !== null)
        {
            $applied[] = _t('Search') . ': ' . $filters['search'];
        }
        if ($filters['category'] !== null)
        {
            $applied[] = _t('Category') . ': ' . $filters['category'];
        }
        if ($filters['status'] !== null)
        {
            $applied[] = _t('Status') . ': ' . ($labels[$filters['status']] ?? $filters['status']);
        }

        $body = '';
        foreach ($rows as $row)
        {
            $id = (int) $row['id'];
            $body .= '<tr>'
                . '<td>' . CvFormat::e((string) $row['name']) . '</td>'
                . '<td>' . CvFormat::e(self::code($row['code'] ?? null)) . '</td>'
                . '<td>' . CvFormat::e((string) $row['category']) . '</td>'
                . '<td style="text-align:right">' . CvFormat::e(self::quantity((float) $row['stock_quantity'], $row['unit'] ?? null)) . '</td>'
                . '<td style="text-align:right">' . CvFormat::e(self::quantity((float) $row['minimum_stock_quantity'], $row['unit'] ?? null)) . '</td>'
                . '<td>' . CvFormat::e($labels[$row['status']] ?? (string) $row['status']) . '</td>'
                . '<td style="text-align:right">' . CvFormat::e(self::price($costs[$id] ?? null)) . '</td>'
                . '<td style="text-align:right">' . CvFormat::e(self::price($row['sale_price_cents'] ?? null)) . '</td>'
                . '</tr>';
        }
        if ($body === '')
        {
            $body = '<tr><td colspan="8">' . CvFormat::e(_t('No products found')) . '</td></tr>';
        }

        return '<html><head><meta charset="utf-8"><style>'
            . 'body{font-family:DejaVu Sans,sans-serif;font-size:10px}th{text-align:left;background:#eee}'
            . '</style></head><body>'
            . '<h3>' . CvFormat::e(_t('Stock report')) . '</h3>'
            . '<p>' . CvFormat::e(_t('Date')) . ': ' . CvFormat::e(date('d/m/Y H:i')) . '</p>'
            . '<p>' . CvFormat::e(_t('Filters')) . ': ' . CvFormat::e($applied ? implode(' · ', $applied) : '—') . '</p>'
            . '<table border="1" cellpadding="4" cellspacing="0" width="100%">'
            . '<thead><tr>'
            . '<th>' . CvFormat::e(_t('Product')) . '</th>'
            . '<th>' . CvFormat::e(_t('Code')) . '</th>'
            . '<th>' . CvFormat::e(_t('Category')) . '</th>'
            . '<th>' . CvFormat::e(_t('Current stock')) . '</th>'
            . '<th>' . CvFormat::e(_t('Minimum stock')) . '</th>'
            . '<th>' . CvFormat::e(_t('Status')) . '</th>'
            . '<th>' . CvFormat::e(_t('Unit cost')) . '</th>'
            . '<th>' . CvFormat::e(_t('Sale price')) . '</th>'
            . '</tr></thead><tbody>' . $body . '</tbody></table>'
            . '</body></html>';
    }

    /**
     * Report link with the current filters (opened in a new tab).
     */
    private static function reportHref(array $filters): string
    {
        $query = http_build_query(array_filter($filters, static fn ($value) => $value !== null));

        return 'engine.php?class=ProductList&method=onReport&static=1' . ($query !== '' ? '&' . $query : '');
    }

    /**
     * 'attention' is a filter, not a row status: low + out (same set as the
     * low-stock card). Other statuses were already applied by overview().
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private static function attentionFilter(array $rows, ?string $status): array
    {
        if ($status !== self::ATTENTION)
        {
            return $rows;
        }

        $wanted = [
            \CentralVet\Application\StockSalesOverviewService::STATUS_LOW,
            \CentralVet\Application\StockSalesOverviewService::STATUS_OUT,
        ];

        return array_values(array_filter($rows, static fn (array $row): bool => in_array($row['status'], $wanted, true)));
    }

    private static function code($value): string
    {
        $value = is_string($value) ? trim($value) : '';

        return $value !== '' ? $value : '—';
    }

    private static function price($cents): string
    {
        return $cents !== null && $cents !== '' ? CvFormat::money((int) $cents) : '—';
    }

    /**
     * Single overview() call per load, cached for the current filters.
     * Requires an already-open TTransaction('permission').
     */
    private function overview(): array
    {
        if ($this->overview === null || $this->overviewFilters !== $this->filters)
        {
            $this->overview = $this->service()->overview(
                new DateTimeImmutable('now'),
                $this->filters['search'],
                $this->filters['category'],
                $this->filters['status'] === self::ATTENTION ? null : $this->filters['status'],
                self::LOW_STOCK_LIMIT
            );
            $this->overviewFilters = $this->filters;
        }

        return $this->overview;
    }

    /**
     * Requires an already-open TTransaction('permission').
     */
    private function service(): \CentralVet\Application\StockSalesOverviewService
    {
        if ($this->service === null)
        {
            $this->service = self::buildService(self::resolveTenantContext());
        }

        return $this->service;
    }

    /**
     * Requires an already-open TTransaction('permission').
     */
    private static function buildService(\CentralVet\Tenancy\TenantContext $context): \CentralVet\Application\StockSalesOverviewService
    {
        return new \CentralVet\Application\StockSalesOverviewService(
            new \CentralVet\Persistence\StockSalesOverviewReader($context, TTransaction::get())
        );
    }

    /**
     * Resolves the tenant context of the authenticated session, falling back to
     * the tenant_user membership for legacy sessions without 'tenantid'.
     * Runs inside the caller's open transaction.
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

            $stmt = TTransaction::get()->prepare('SELECT tenant_id FROM tenant_user WHERE system_user_id = :userid ORDER BY id ASC LIMIT 1');
            $stmt->execute(['userid' => (int) $userid]);
            $tenant_id = $stmt->fetchColumn();

            if (empty($tenant_id))
            {
                throw $e;
            }

            $unit_id = TSession::getValue('userunitid');

            return \CentralVet\Tenancy\TenantContext::authenticated((int) $tenant_id, (int) $userid, $unit_id ? (int) $unit_id : null);
        }
    }
}
