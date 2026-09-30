<?php
/**
 * FinancialOverview — Visão geral financeira da unidade (Fase 10, T-09).
 *
 * Tela de apresentação sobre CentralVet\Application\FinancialOverviewService
 * (T-05): 4 indicadores do período, gráfico de linha Receitas x Despesas,
 * donut de receitas por categoria e lançamentos recentes. Não calcula nada
 * por conta própria nem acessa tabelas diretamente.
 *
 * Parâmetros opcionais `from`/`to` (Y-m-d ou dd/mm/yyyy, inclusivos); padrão
 * mês corrente. Unidade = userunitid da sessão (TenantContext::requireUnitId).
 *
 * Rodada 2 (T-20): os lançamentos recentes respeitam o período; os KPIs
 * comparam com o período anterior de mesmo tamanho ("vs. período anterior");
 * KPI de saldo bancário (BankAccountService::totalBalanceCents); ação
 * Exportar → onExport (static), CSV dos lançamentos do período.
 *
 * @package    control
 * @subpackage clinic
 */
class FinancialOverview extends TPage
{
    private const FORM_NAME = 'form_FinancialOverview';
    private const RECENT_LIMIT = 5;

    public function __construct($param = null)
    {
        parent::__construct();

        $param = is_array($param) ? $param : [];
        [$from, $to] = self::period($param);

        $container = new TElement('div');
        $container->{'class'} = 'cv-financial-overview';
        $container->{'style'} = 'width: 100%';

        $export = [
            'label'  => _t('Export'),
            'icon'   => 'fa:download',
            'href'   => 'engine.php?class=FinancialOverview&method=onExport&static=1&from=' . $from->format('Y-m-d') . '&to=' . $to->format('Y-m-d'),
            'target' => '_blank',
        ];
        $container->add(CvPage::header(_t('Financial'), _t('Revenues, expenses and cash of the unit'), [$export]));
        $container->add(CvNav::tabs('finance', 'overview'));
        $container->add($this->buildFilter($from, $to));

        try
        {
            TTransaction::open('permission');

            $context = self::resolveTenantContext();
            $unitId  = $context->requireUnitId();
            $service = new \CentralVet\Application\FinancialOverviewService(
                new \CentralVet\Persistence\FinancialOverviewReader($context, TTransaction::get())
            );

            $totals     = $service->totals($unitId, $from, $to);
            $series     = $service->dailySeries($unitId, $from, $to);
            $categories = $service->revenueByCategory($unitId, $from, $to);
            $recent     = $service->recentEntries($unitId, self::RECENT_LIMIT, $from, $to);
            $cash       = $service->openCashBalanceCents($unitId);
            $bank       = (new \CentralVet\Application\BankAccountService(
                new \CentralVet\Persistence\BankAccountRepository($context, TTransaction::get()),
                $context
            ))->totalBalanceCents($unitId);

            TTransaction::close();

            $container->add(self::buildKpis($totals, $cash, $bank));
            $container->add(CvPage::columns(
                CvCard::create(_t('Revenue x Expenses'), self::chartBox('cv-fin-line', 280)),
                CvCard::create(_t('Revenue by category'), self::categoryBody($categories))
            ));
            $container->add(CvCard::create(
                _t('Recent entries'),
                self::recentTable($recent),
                _t('View all'),
                'index.php?class=FinancialEntryList'
            ));

            TScript::create(self::chartScript($series, $categories));
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

        parent::add($container);
    }

    /**
     * Ação do botão Filtrar: o construtor já renderiza com from/to postados.
     */
    public function onFilter($param = null)
    {
    }

    /**
     * Exportar (T-20): CSV dos lançamentos do período `from`/`to` (mesma
     * leitura de period()) via FinancialEntryService::listByPeriod(). BOM
     * UTF-8, separador `;`, valor `1234,56` com sinal negativo nas despesas.
     * Sem tenant/unidade responde 403; outra falha, 500 — sem corpo HTML.
     */
    public static function onExport($param = null)
    {
        $param = is_array($param) ? $param : [];
        [$from, $to] = self::period($param);

        $entries = null;
        $status  = 500;

        try
        {
            TTransaction::open('permission');

            $context    = self::resolveTenantContext();
            $unitId     = $context->requireUnitId();
            $connection = TTransaction::get();

            $service = new \CentralVet\Application\FinancialEntryService(
                new \CentralVet\Persistence\FinancialEntryRepository($context, $connection),
                new \CentralVet\Authorization\RbacAuthorizationService(
                    new \CentralVet\Authorization\AdiantiProgramPermissionProvider(new \CentralVet\Tenancy\AdiantiSessionContextSource()),
                    new \CentralVet\Audit\PdoAuditLogWriter($connection),
                ),
                $context
            );

            $entries = $service->listByPeriod($unitId, $from->format('Y-m-d') . ' 00:00:00', $to->format('Y-m-d') . ' 23:59:59');

            TTransaction::close();
        }
        catch (\CentralVet\Tenancy\Exception\MissingTenantContext $e)
        {
            TTransaction::rollback();
            $status = 403;
        }
        catch (Throwable $e)
        {
            TTransaction::rollback();
            error_log(__METHOD__ . ': ' . get_class($e) . ': ' . $e->getMessage());
        }

        while (ob_get_level() > 0)
        {
            ob_end_clean();
        }

        if ($entries === null)
        {
            http_response_code($status);
            exit;
        }

        $file_name = 'financeiro-' . $from->format('Y-m-d') . '-' . $to->format('Y-m-d') . '.csv';

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $file_name . '"');
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');

        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, [_t('Date'), _t('Type'), _t('Category'), _t('Payment method'), _t('Reference'), _t('Amount')], ';', '"', '');

        foreach ($entries as $entry)
        {
            fputcsv($out, self::exportRow($entry), ';', '"', '');
        }

        fclose($out);
        exit;
    }

    /**
     * @return list<string>
     */
    private static function exportRow(\CentralVet\Domain\FinancialEntry $entry): array
    {
        $isExpense = $entry->entryType() === 'expense';
        $amount    = number_format($entry->amountCents() / 100, 2, ',', '');
        $method    = $entry->paymentMethod();
        $reference = $entry->referenceType() !== null
            ? $entry->referenceType() . ($entry->referenceId() !== null ? ' #' . $entry->referenceId() : '')
            : '';

        return [
            $entry->occurredAt()->format('d/m/Y H:i'),
            $isExpense ? _t('Expense') : _t('Income'),
            self::csvSafe(CvFormat::paymentMethod($entry->category())),
            $method !== null && $method !== '' ? CvFormat::paymentMethod($method) : '',
            self::csvSafe($reference),
            $isExpense ? '-' . $amount : $amount,
        ];
    }

    /**
     * Neutraliza injeção de fórmula em planilha: texto começando com
     * = + - @ ou tab/CR ganha um apóstrofo na frente.
     */
    private static function csvSafe(string $value): string
    {
        return $value !== '' && strpbrk($value[0], "=+-@\t\r") !== false ? "'" . $value : $value;
    }

    /**
     * @return array{0: DateTimeImmutable, 1: DateTimeImmutable}
     */
    private static function period(array $param): array
    {
        $from = self::parseDate($param['from'] ?? null) ?? new DateTimeImmutable('first day of this month');
        $to   = self::parseDate($param['to'] ?? null) ?? new DateTimeImmutable('last day of this month');

        $from = $from->setTime(0, 0);
        $to   = $to->setTime(0, 0);

        if ($to < $from)
        {
            [$from, $to] = [$to, $from];
        }

        return [$from, $to];
    }

    private static function parseDate(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value) || trim($value) === '')
        {
            return null;
        }

        foreach (['!Y-m-d', '!d/m/Y'] as $format)
        {
            $date = DateTimeImmutable::createFromFormat($format, trim($value));
            if ($date !== false && DateTimeImmutable::getLastErrors() === false)
            {
                return $date;
            }
        }

        return null;
    }

    private function buildFilter(DateTimeImmutable $from, DateTimeImmutable $to): TForm
    {
        $form = new TForm(self::FORM_NAME);

        $fromField = new TDate('from');
        $toField   = new TDate('to');
        foreach ([$fromField, $toField] as $field)
        {
            $field->setMask('dd/mm/yyyy');
            $field->setSize('100%');
        }
        $fromField->setValue($from->format('d/m/Y'));
        $toField->setValue($to->format('d/m/Y'));

        $button = new TButton('filter');
        $button->setAction(new TAction([$this, 'onFilter']), _t('Apply'));
        $button->setImage('fa:filter');
        $button->class = 'btn btn-primary';

        $form->setFields([$fromField, $toField, $button]);

        $form->add(CvPage::filterBar([
            self::labeled(_t('Start date'), $fromField),
            self::labeled(_t('End date'), $toField),
            $button,
        ]));

        return $form;
    }

    private static function labeled(string $label, $field): TElement
    {
        $box = new TElement('div');
        $box->add(new TLabel($label));
        $box->add($field);

        return $box;
    }

    private static function buildKpis(array $totals, ?int $cash, ?int $bank): TElement
    {
        $row = new TElement('div');
        $row->{'class'} = 'cv-kpi-row';

        // FinancialOverviewService::totals compara com o período anterior de mesmo tamanho
        $vs = _t('vs. previous period');

        $row->add(CvKpiCard::create('fa:arrow-up', 'success', CvFormat::money($totals['revenue_cents']), _t('Revenues'),
            CvFormat::delta($totals['revenue_cents'], $totals['prev_revenue_cents']), $vs));
        $row->add(CvKpiCard::create('fa:arrow-down', 'danger', CvFormat::money($totals['expense_cents']), _t('Expenses'),
            CvFormat::delta($totals['expense_cents'], $totals['prev_expense_cents']), $vs));
        $row->add(CvKpiCard::create('fa:chart-line', 'info', CvFormat::money($totals['result_cents']), _t('Result'),
            CvFormat::delta($totals['result_cents'], $totals['prev_result_cents']), $vs));

        if ($cash === null)
        {
            $row->add(CvKpiCard::create('fa:cash-register', 'neutral', '—', _t('No open cash register')));
        }
        else
        {
            $row->add(CvKpiCard::create('fa:cash-register', 'warning', CvFormat::money($cash), _t('Open cash balance')));
        }

        // KPI de saldo bancário: o link para as contas vai como filho próprio do card
        $card = $bank === null
            ? CvKpiCard::create('fa:university', 'neutral', '—', _t('No bank account'))
            : CvKpiCard::create('fa:university', 'info', CvFormat::money($bank), _t('Bank balance'));
        $card->add(TElement::tag('a', CvFormat::e(_t('Bank accounts')), [
            'href'      => 'index.php?class=BankAccountList',
            'generator' => 'adianti',
            'class'     => 'small ms-auto align-self-end',
        ]));
        $row->add($card);

        return $row;
    }

    private static function chartBox(string $id, int $height): TElement
    {
        $box = new TElement('div');
        $box->{'class'} = 'cv-chart';
        $box->{'style'} = 'position: relative; width: 100%; height: ' . $height . 'px';
        $box->add(TElement::tag('canvas', '', ['id' => $id]));

        return $box;
    }

    private static function categoryBody(array $categories): TElement
    {
        $body = new TElement('div');
        $body->add(self::chartBox('cv-fin-donut', 220));

        if ($categories === [])
        {
            $body->add(TElement::tag('p', CvFormat::e(_t('No entries in this period')), ['class' => 'text-muted text-center mt-2']));
            return $body;
        }

        $list = new TElement('ul');
        $list->{'class'} = 'list-unstyled mt-3 mb-0';
        foreach ($categories as $category)
        {
            $item = new TElement('li');
            $item->{'class'} = 'd-flex justify-content-between';
            $item->add(TElement::tag('span', CvFormat::e(CvFormat::paymentMethod((string) $category['category'])), []));
            $item->add(TElement::tag('span',
                CvFormat::e(CvFormat::money($category['amount_cents']) . ' · ' . number_format($category['share'] * 100, 1, ',', '.') . '%'),
                ['class' => 'text-muted']));
            $list->add($item);
        }
        $body->add($list);

        return $body;
    }

    private static function recentTable(array $entries): TElement
    {
        $table = new TElement('table');
        $table->{'class'} = 'table cv-table';

        $head = new TElement('thead');
        $headRow = new TElement('tr');
        foreach (['Date', 'Description', 'Category', 'Type', 'Amount'] as $index => $label)
        {
            $headRow->add(TElement::tag('th', CvFormat::e(_t($label)), $index === 4 ? ['class' => 'text-end'] : []));
        }
        $head->add($headRow);
        $table->add($head);

        $body = new TElement('tbody');

        if ($entries === [])
        {
            $row = new TElement('tr');
            $row->add(TElement::tag('td', CvFormat::e(_t('No entries in this period')), ['colspan' => '5', 'class' => 'text-center text-muted']));
            $body->add($row);
        }

        foreach ($entries as $entry)
        {
            $isExpense = $entry['entry_type'] === 'expense';
            $amount = CvFormat::money((int) $entry['amount_cents']);

            try
            {
                $date = (new DateTimeImmutable((string) $entry['occurred_at']))->format('d/m/Y');
            }
            catch (Exception $e)
            {
                $date = (string) $entry['occurred_at'];
            }

            $row = new TElement('tr');
            $row->add(TElement::tag('td', CvFormat::e($date), []));
            $row->add(TElement::tag('td', CvFormat::e($entry['reference'] ?? '—'), []));
            $row->add(TElement::tag('td', CvFormat::e(CvFormat::paymentMethod((string) $entry['category'])), []));

            $typeCell = new TElement('td');
            $typeCell->add($isExpense ? CvBadge::create(_t('Expense'), 'danger') : CvBadge::create(_t('Income'), 'success'));
            $row->add($typeCell);

            $row->add(TElement::tag('td', CvFormat::e($isExpense ? '-' . $amount : $amount), [
                'class' => 'text-end ' . ($isExpense ? 'text-danger' : 'text-success'),
            ]));

            $body->add($row);
        }

        $table->add($body);

        $wrapper = new TElement('div');
        $wrapper->{'class'} = 'table-responsive';
        $wrapper->add($table);

        return $wrapper;
    }

    private static function chartScript(array $series, array $categories): string
    {
        $data = [
            'labels'   => array_map(static fn (array $d): string => substr($d['date'], 8, 2) . '/' . substr($d['date'], 5, 2), $series),
            'revenue'  => array_map(static fn (array $d): float => $d['revenue_cents'] / 100, $series),
            'expense'  => array_map(static fn (array $d): float => $d['expense_cents'] / 100, $series),
            'revLabel' => _t('Revenues'),
            'expLabel' => _t('Expenses'),
            'catLabels' => array_map(static fn (array $c): string => CvFormat::paymentMethod((string) $c['category']), $categories),
            'catValues' => array_map(static fn (array $c): float => $c['amount_cents'] / 100, $categories),
        ];

        $json = json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);

        return <<<JS
(function () {
    if (typeof Chart === 'undefined') { return; }
    var d = {$json};
    var money = function (v) { return Number(v).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' }); };
    var mount = function (id, config) {
        var canvas = document.getElementById(id);
        if (!canvas) { return; }
        var old = Chart.getChart(canvas);
        if (old) { old.destroy(); }
        new Chart(canvas, config);
    };
    mount('cv-fin-line', {
        type: 'line',
        data: {
            labels: d.labels,
            datasets: [
                { label: d.revLabel, data: d.revenue, borderColor: '#16a34a', backgroundColor: 'rgba(22,163,74,0.12)', fill: true, tension: 0.3, pointRadius: 0 },
                { label: d.expLabel, data: d.expense, borderColor: '#dc2626', backgroundColor: 'rgba(220,38,38,0.10)', fill: true, tension: 0.3, pointRadius: 0 }
            ]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: { legend: { position: 'bottom' }, tooltip: { callbacks: { label: function (c) { return c.dataset.label + ': ' + money(c.parsed.y); } } } },
            scales: { y: { beginAtZero: true, ticks: { callback: function (v) { return money(v); } } } }
        }
    });
    mount('cv-fin-donut', {
        type: 'doughnut',
        data: {
            labels: d.catLabels,
            datasets: [{ data: d.catValues, backgroundColor: ['#0ea5e9', '#16a34a', '#f59e0b', '#8b5cf6', '#ec4899', '#14b8a6', '#64748b'] }]
        },
        options: {
            responsive: true, maintainAspectRatio: false, cutout: '65%',
            plugins: { legend: { display: false }, tooltip: { callbacks: { label: function (c) { return c.label + ': ' + money(c.parsed); } } } }
        }
    });
})();
JS;
    }

    /**
     * Contexto de tenant da sessão autenticada; mesmo fallback de
     * FinancialEntryList::resolveTenantContext() para sessões legadas.
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
            $tenantId = $stmt->fetchColumn();

            if (empty($tenantId))
            {
                throw $e;
            }

            $unitId = TSession::getValue('userunitid');

            return \CentralVet\Tenancy\TenantContext::authenticated((int) $tenantId, (int) $userid, $unitId ? (int) $unitId : null);
        }
    }
}
