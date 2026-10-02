<?php
/**
 * LandingLeadList — leads da landing pública (T-08), só grupo 1 ("Template -
 * Admin"; programa 109). Tela de leitura sobre
 * CentralVet\Persistence\LeadRepository (tabela de plataforma, sem tenant):
 * filtro por plano (LandingCatalog::plans(), vazio = todos) e período de/até
 * sobre created_at, 20 por página com TPageNavigation e "Exportar CSV".
 *
 * Os filtros viajam como parâmetros da requisição (plan_id, from, to) — no
 * POST do formulário as datas chegam em d/m/Y; na paginação e no link do CSV,
 * em Y-m-d. Todo texto do lead sai escapado com CvFormat::e (o lead é digitado
 * por visitante anônimo). O preço exibido é o gravado no pedido.
 *
 * onExport (static=1): CSV com BOM UTF-8 e `;`, mesmos filtros da tela, até
 * EXPORT_LIMIT linhas, linhas montadas por CentralVet\Landing\LeadCsvExport
 * (CsvCell::safe em todo texto).
 *
 * @version    1.0
 * @package    control
 * @subpackage admin
 */
class LandingLeadList extends TPage
{
    private const LIMIT = 20;
    private const EXPORT_LIMIT = 5000;
    private const EXPORT_PAGE = 500; // LeadRepository::search() limita a 500 por chamada

    protected $datagrid;
    protected $pageNavigation;
    protected $filterForm;
    protected $footerSlot;
    protected $loaded = false;

    /** @var array{plan_id: ?string, from: ?DateTimeImmutable, to: ?DateTimeImmutable} */
    private array $filters = ['plan_id' => null, 'from' => null, 'to' => null];

    public function __construct($param = null)
    {
        parent::__construct();

        $this->filters = self::readFilters(is_array($param) ? $param : []);

        $page = new TElement('div');
        $page->{'class'} = 'cv-page';

        $page->add(CvPage::header(_t('Landing leads'), _t('Administration'), [
            ['label' => _t('Export CSV'), 'href' => self::exportHref($this->filters), 'icon' => 'fa:file-csv', 'class' => 'btn btn-outline-secondary', 'target' => '_blank'],
        ], false));

        $page->add($this->buildFilterForm());
        $page->add($this->buildDatagrid());

        parent::add($page);
    }

    /**
     * Carrega a página corrente da grade (filtros + offset) via
     * LeadRepository::count()/search() na conexão de TTransaction('permission').
     */
    public function onReload($param = null)
    {
        $param = is_array($param) ? $param : [];
        $this->filters = self::readFilters($param);
        $filters = $this->filters;

        $offset = isset($param['offset']) ? max(0, (int) $param['offset']) : 0;

        try
        {
            TTransaction::open('permission');

            $repository = new \CentralVet\Persistence\LeadRepository(TTransaction::get());
            $total = $repository->count($filters['plan_id'], $filters['from'], $filters['to']);
            if ($offset >= $total)
            {
                $offset = 0;
            }
            $leads = $repository->search($filters['plan_id'], $filters['from'], $filters['to'], self::LIMIT, $offset);

            TTransaction::close();
        }
        catch (Exception $e)
        {
            TTransaction::rollback();
            error_log(__METHOD__ . ': ' . $e->getMessage());
            new TMessage('error', CvFormat::userError($e));
            return;
        }

        $this->datagrid->clear();
        foreach ($leads as $lead)
        {
            $row = new stdClass;
            $row->id               = $lead['id'];
            $row->created_at_label = \CentralVet\Landing\LeadCsvExport::dateTimeLabel((string) $lead['created_at']);
            $row->name             = (string) $lead['name'];
            $row->clinic_name      = (string) $lead['clinic_name'];
            $row->email            = (string) $lead['email'];
            $row->phone            = (string) $lead['phone'];
            $row->vets_label       = \CentralVet\Landing\LeadCsvExport::vetsLabel((string) $lead['vets_range']);
            $row->place_label      = self::place((string) $lead['city'], (string) $lead['uf']);
            $row->plan_name        = (string) $lead['plan_name'];
            $row->plan_price_cents = (int) $lead['plan_price_cents'];
            $this->datagrid->addItem($row);
        }

        $this->pageNavigation->setAction(new TAction([$this, 'onReload'], self::filterParams($filters)));
        $this->pageNavigation->setCount($total);
        $this->pageNavigation->setProperties(['offset' => $offset, 'page' => intdiv($offset, self::LIMIT) + 1] + $param);
        $this->pageNavigation->setLimit(self::LIMIT);

        $from = $total > 0 ? $offset + 1 : 0;
        $to   = $offset + count($leads);
        $this->footerSlot->clearChildren();
        $this->footerSlot->add(CvDatagrid::footer($this->pageNavigation, $from, $to, $total, _t('leads')));

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

    /**
     * CSV dos leads com os mesmos filtros da tela (plan_id, from/to em Y-m-d),
     * até EXPORT_LIMIT linhas. Falha responde 500 sem corpo HTML.
     */
    public static function onExport($param = null)
    {
        $filters = self::readFilters(is_array($param) ? $param : []);
        $leads = null;

        try
        {
            TTransaction::open('permission');

            $repository = new \CentralVet\Persistence\LeadRepository(TTransaction::get());
            $leads = [];
            while (count($leads) < self::EXPORT_LIMIT)
            {
                $chunk = $repository->search(
                    $filters['plan_id'],
                    $filters['from'],
                    $filters['to'],
                    min(self::EXPORT_PAGE, self::EXPORT_LIMIT - count($leads)),
                    count($leads)
                );
                array_push($leads, ...$chunk);
                if (count($chunk) < self::EXPORT_PAGE)
                {
                    break;
                }
            }

            TTransaction::close();
        }
        catch (Throwable $e)
        {
            TTransaction::rollback();
            $leads = null;
            error_log(__METHOD__ . ': ' . get_class($e) . ': ' . $e->getMessage());
        }

        while (ob_get_level() > 0)
        {
            ob_end_clean();
        }

        if ($leads === null)
        {
            http_response_code(500);
            exit;
        }

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="leads-landing-' . date('Ymd') . '.csv"');
        header('Cache-Control: private, no-store');

        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, \CentralVet\Landing\LeadCsvExport::header(), ';', '"', '');

        foreach ($leads as $lead)
        {
            fputcsv($out, \CentralVet\Landing\LeadCsvExport::row($lead), ';', '"', '');
        }

        fclose($out);
        exit;
    }

    private function buildFilterForm(): TForm
    {
        $this->filterForm = new TForm('form_LandingLeadList_filter');

        $plan = new TCombo('plan_id');
        $plan->setDefaultOption(_t('All plans'));
        $plan->addItems(self::planOptions());
        $plan->setSize('100%');
        $plan->setValue($this->filters['plan_id'] ?? '');

        $from = new TDate('from');
        $to   = new TDate('to');
        foreach (['from' => $from, 'to' => $to] as $key => $field)
        {
            $field->setMask('dd/mm/yyyy');
            $field->setSize('100%');
            $field->setValue($this->filters[$key] !== null ? $this->filters[$key]->format('d/m/Y') : '');
        }

        $find = new TButton('find');
        $find->setAction(new TAction([$this, 'onReload']), _t('Find'));
        $find->setImage('fa:search');
        $find->{'class'} = 'btn btn-primary';

        $this->filterForm->add(CvPage::filterBar([
            self::labeled(_t('Plan'), $plan),
            self::labeled(_t('Start date'), $from),
            self::labeled(_t('End date'), $to),
            $find,
        ]));
        $this->filterForm->setFields([$plan, $from, $to, $find]);

        return $this->filterForm;
    }

    private function buildDatagrid(): TElement
    {
        $this->datagrid = new BootstrapDatagridWrapper(new TDataGrid);
        CvDatagrid::decorate($this->datagrid, false);
        $this->datagrid->disableDefaultClick();

        $columns = [
            new TDataGridColumn('created_at_label', _t('Date'), 'left', 130),
            new TDataGridColumn('name', _t('Name'), 'left'),
            new TDataGridColumn('clinic_name', _t('Clinic'), 'left'),
            new TDataGridColumn('email', _t('Email'), 'left'),
            new TDataGridColumn('phone', _t('WhatsApp'), 'left'),
            new TDataGridColumn('vets_label', _t('Veterinarians'), 'left'),
            new TDataGridColumn('place_label', _t('City/State'), 'left'),
            new TDataGridColumn('plan_name', _t('Plan'), 'left'),
        ];
        foreach ($columns as $column)
        {
            $column->setTransformer(fn ($value) => CvFormat::e((string) $value));
            $this->datagrid->addColumn($column);
        }

        $price = new TDataGridColumn('plan_price_cents', _t('Monthly price'), 'right', 130);
        $price->setTransformer(fn ($value) => CvFormat::e(CvFormat::money((int) $value)));
        $this->datagrid->addColumn($price);

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

    /**
     * plan_id só quando é id do catálogo; datas em d/m/Y (POST do formulário)
     * ou Y-m-d (paginação/CSV); de/até invertidos são trocados.
     *
     * @return array{plan_id: ?string, from: ?DateTimeImmutable, to: ?DateTimeImmutable}
     */
    private static function readFilters(array $param): array
    {
        $plan = is_string($param['plan_id'] ?? null) ? trim($param['plan_id']) : '';
        $from = self::parseDate($param['from'] ?? null);
        $to   = self::parseDate($param['to'] ?? null);

        if ($from !== null && $to !== null && $to < $from)
        {
            [$from, $to] = [$to, $from];
        }

        return [
            'plan_id' => array_key_exists($plan, self::planOptions()) ? $plan : null,
            'from'    => $from,
            'to'      => $to,
        ];
    }

    private static function parseDate($value): ?DateTimeImmutable
    {
        if (!is_string($value) || trim($value) === '')
        {
            return null;
        }

        $value = trim($value);
        foreach (['!Y-m-d', '!d/m/Y'] as $format)
        {
            $date = DateTimeImmutable::createFromFormat($format, $value);
            $errors = DateTimeImmutable::getLastErrors();
            if ($date !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
                && (int) $date->format('Y') >= 1900 && (int) $date->format('Y') <= 2100)
            {
                return $date;
            }
        }

        return null;
    }

    /** @return array<string, string> */
    private static function filterParams(array $filters): array
    {
        return array_filter([
            'plan_id' => $filters['plan_id'],
            'from'    => $filters['from'] !== null ? $filters['from']->format('Y-m-d') : null,
            'to'      => $filters['to'] !== null ? $filters['to']->format('Y-m-d') : null,
        ], static fn ($value) => $value !== null);
    }

    private static function exportHref(array $filters): string
    {
        $query = http_build_query(['class' => 'LandingLeadList', 'method' => 'onExport', 'static' => 1] + self::filterParams($filters));

        return 'engine.php?' . $query;
    }

    /** @return array<string, string> id → nome, de LandingCatalog::plans() */
    private static function planOptions(): array
    {
        $options = [];
        foreach (\CentralVet\Landing\LandingCatalog::plans() as $plan)
        {
            $options[$plan['id']] = $plan['name'];
        }

        return $options;
    }

    private static function place(string $city, string $uf): string
    {
        $parts = array_filter([trim($city), trim($uf)], static fn ($part) => $part !== '');

        return $parts === [] ? '—' : implode('/', $parts);
    }

    private static function labeled(string $label, $field): TElement
    {
        $box = new TElement('div');
        $box->add(new TLabel($label));
        $box->add($field);

        return $box;
    }
}
