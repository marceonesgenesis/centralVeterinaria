<?php
/**
 * ServiceList
 *
 * Tela "Serviços" (fase 10, T-11): tabela do catálogo à esquerda e painel de
 * detalhe do serviço selecionado à direita, no padrão do kit Cv*.
 *
 * Toda linha vem de CentralVet\Application\ServiceCatalogService — a camada
 * Persistence nunca é acessada daqui; o escopo por tenant acontece dentro do
 * serviço/repositório.
 *
 * Parâmetros de URL:
 *  - service_id: serviço exibido no painel direito (padrão: primeiro da página)
 *  - search, category, status ('active'|'inactive'): filtros da barra
 *  - offset, limit, page: paginação (TPageNavigation)
 *
 * Ações (rodada 2, T-10/T-33): Importar (ServiceImportForm), Duplicar
 * (onAskDuplicate → TQuestion → onDuplicate → ServiceCatalogService::duplicate(),
 * a cópia nasce inativa) e Excluir
 * (onAskDelete → TQuestion → onDelete → ServiceCatalogService::delete();
 * serviço com agendamento não é excluído e deve ser inativado).
 *
 * Sem schema para descrição/preparo/ícone do serviço: o painel mostra só os
 * campos existentes e a tabela usa um ícone neutro.
 *
 * @version    2.0
 * @package    control
 * @subpackage clinic
 */
class ServiceList extends TPage
{
    private const LIMIT = 10;

    protected $datagrid;
    protected $pageNavigation;
    protected $filterForm;
    protected $category;
    protected $footerBox;
    protected $detailBox;
    protected $loaded = false;

    /** @var int|null serviço selecionado na renderização atual */
    private $selectedId = null;

    /** @var array<string, string> filtros ativos, repassados aos links */
    private $filters = [];

    /** @var array{offset: int, page: mixed} paginação atual, repassada aos links */
    private $pagination = ['offset' => 0, 'page' => null];

    public function __construct()
    {
        parent::__construct();

        // barra de filtros (busca + categoria + status), no lugar da cortina
        $this->filterForm = new TForm('form_ServiceList_filter');

        $search = new TEntry('search');
        $search->placeholder = _t('Search services');
        $search->setSize('100%');

        $this->category = new TCombo('category');
        $this->category->setDefaultOption(_t('All categories'));
        $this->category->setSize('100%');

        $status = new TCombo('status');
        $status->setDefaultOption(_t('All statuses'));
        $status->addItems(['active' => _t('Active'), 'inactive' => _t('Inactive')]);
        $status->setSize('100%');

        $find = new TButton('find');
        $find->setAction(new TAction([$this, 'onReload']), _t('Find'));
        $find->setImage('fa:search');
        $find->{'class'} = 'btn btn-primary';

        $this->filterForm->add(CvPage::filterBar([$search, $this->category, $status, $find]));
        $this->filterForm->setFields([$search, $this->category, $status, $find]);

        // tabela
        $this->datagrid = new BootstrapDatagridWrapper(new TDataGrid);
        CvDatagrid::decorate($this->datagrid);
        $this->datagrid->disableDefaultClick();
        $this->datagrid->setActionSide('right');

        $column_name     = new TDataGridColumn('name', _t('Service'), 'left');
        $column_category = new TDataGridColumn('category', _t('Category'), 'left');
        $column_price    = new TDataGridColumn('price_cents', _t('Standard price'), 'right');
        $column_duration = new TDataGridColumn('duration_minutes', _t('Duration'), 'left');
        $column_status   = new TDataGridColumn('active', _t('Status'), 'left');

        $column_name->setTransformer(function ($value, $object, $row, $cell) {
            $this->linkRow($object, $row, $cell);
            // link focável: Tab chega à linha e Enter abre o painel (o clique
            // na célula continua valendo; o handler do Adianti para no <a>)
            $link = is_object($object) && !empty($object->id)
                ? '<a class="cv-row-link text-reset" href="' . CvFormat::e($this->selectUrl((int) $object->id)) . '" generator="adianti">'
                  . CvFormat::e((string) $value) . '</a>'
                : CvFormat::e((string) $value);
            return '<span class="cv-service-name"><i class="fas fa-stethoscope text-primary me-2" aria-hidden="true"></i>'
                 . $link . '</span>';
        });
        $column_category->setTransformer(function ($value, $object, $row, $cell) {
            $this->linkRow($object, $row, $cell);
            return ($value === null || $value === '') ? '—' : CvBadge::create((string) $value, 'info');
        });
        $column_price->setTransformer(function ($value, $object, $row, $cell) {
            $this->linkRow($object, $row, $cell);
            return CvFormat::e(CvFormat::money((int) $value));
        });
        $column_duration->setTransformer(function ($value, $object, $row, $cell) {
            $this->linkRow($object, $row, $cell);
            return CvFormat::e(self::formatDuration((int) $value));
        });
        $column_status->setTransformer(function ($value, $object, $row, $cell) {
            $this->linkRow($object, $row, $cell);
            return self::statusBadge((bool) $value);
        });

        $this->datagrid->addColumn($column_name);
        $this->datagrid->addColumn($column_category);
        $this->datagrid->addColumn($column_price);
        $this->datagrid->addColumn($column_duration);
        $this->datagrid->addColumn($column_status);

        $action_edit      = new TDataGridAction(['ServiceForm', 'onEdit'], ['id' => '{id}']);
        $action_duplicate = new TDataGridAction([$this, 'onAskDuplicate'], ['id' => '{id}']);
        $action_delete    = new TDataGridAction([$this, 'onAskDelete'], ['id' => '{id}']);
        $this->datagrid->addActionGroup(CvDatagrid::actionMenu([
            ['label' => _t('Edit'), 'action' => $action_edit, 'icon' => 'far:edit'],
            ['label' => _t('Duplicate'), 'action' => $action_duplicate, 'icon' => 'far:copy'],
            ['label' => _t('Delete'), 'action' => $action_delete, 'icon' => 'far:trash-alt'],
        ]));

        $this->datagrid->createModel();

        $this->pageNavigation = new TPageNavigation;
        $this->pageNavigation->setAction(new TAction([$this, 'onReload']));
        $this->pageNavigation->setWidth($this->datagrid->getWidth());

        $this->footerBox = new TElement('div');
        $this->detailBox = new TElement('div');

        $main = new TElement('div');
        $main->add($this->filterForm);
        $main->add($this->datagrid);
        $main->add($this->footerBox);

        $header = CvPage::header(_t('Services'), null, [
            ['label' => _t('Import'), 'href' => 'index.php?class=ServiceImportForm', 'icon' => 'fa:file-import'],
            ['label' => _t('New service'), 'href' => 'index.php?class=ServiceForm', 'icon' => 'fa:plus', 'class' => 'btn btn-primary'],
        ]);

        $container = new TVBox;
        $container->style = 'width: 100%';
        $container->add($header);
        $container->add(CvNav::tabs('services', 'services'));
        $container->add(CvPage::columns($main, $this->detailBox));

        parent::add($container);
    }

    /**
     * Carrega tabela, rodapé e painel de detalhe a partir de ServiceCatalogService.
     */
    public function onReload($param = null)
    {
        $param = is_array($param) ? $param : [];

        try
        {
            TTransaction::open('permission');

            $catalog  = self::buildServiceCatalogService();
            $services = $catalog->listAll();

            $this->filters = self::readFilters($param);

            // categorias existentes no catálogo do tenant
            $categories = [];
            foreach ($services as $service)
            {
                $name = (string) $service->category();
                if ($name !== '')
                {
                    $categories[$name] = $name;
                }
            }
            ksort($categories, SORT_NATURAL | SORT_FLAG_CASE);
            $this->category->addItems($categories);

            $rows = [];
            foreach ($services as $service)
            {
                if (self::matches($service, $this->filters))
                {
                    $rows[] = $service;
                }
            }

            $total  = count($rows);
            $limit  = self::LIMIT;
            $offset = isset($param['offset']) ? max(0, (int) $param['offset']) : 0;
            if ($offset >= $total)
            {
                $offset = 0;
            }
            $page_rows = array_slice($rows, $offset, $limit);

            // serviço do painel: service_id informado ou o primeiro da página
            $selected = null;
            $requested = isset($param['service_id']) ? (int) $param['service_id'] : 0;
            if ($requested > 0)
            {
                $selected = $catalog->findById($requested);
            }
            if ($selected === null && !empty($page_rows))
            {
                $selected = $page_rows[0];
            }
            $this->selectedId = $selected ? $selected->id() : null;

            $this->pagination = ['offset' => $offset, 'page' => $param['page'] ?? null];

            $this->datagrid->clear();
            foreach ($page_rows as $service)
            {
                $row = new stdClass;
                $row->id               = $service->id();
                $row->name             = $service->name();
                $row->category         = $service->category();
                $row->price_cents      = $service->priceCents();
                $row->duration_minutes = $service->durationMinutes();
                $row->active           = $service->isActive() ? 1 : 0;
                $this->datagrid->addItem($row);
            }

            $this->pageNavigation->setAction(new TAction([$this, 'onReload'], $this->filters));
            $this->pageNavigation->setCount($total);
            $this->pageNavigation->setProperties($param);
            $this->pageNavigation->setLimit($limit);

            $from = $total > 0 ? $offset + 1 : 0;
            $to   = $offset + count($page_rows);
            $this->footerBox->add(CvDatagrid::footer($this->pageNavigation, $from, $to, $total, mb_strtolower(_t('Services'))));

            $this->detailBox->add($this->buildDetailPanel($selected));

            $this->filterForm->setData((object) $this->filters);

            TTransaction::close();
            $this->loaded = true;
        }
        catch (Exception $e)
        {
            new TMessage('error', $e->getMessage());
            TTransaction::rollback();
        }
    }

    /**
     * Duplicar: pede confirmação (TQuestion) antes de chamar onDuplicate.
     */
    public static function onAskDuplicate($param = null)
    {
        $id = is_array($param) && isset($param['id']) ? (int) $param['id'] : 0;

        $service = self::findServiceForQuestion($id);
        if ($service === null)
        {
            return;
        }

        $action = new TAction([__CLASS__, 'onDuplicate']);
        $action->setParameter('id', $id);

        new TQuestion(_t('Duplicate the service "^1"?', CvFormat::e($service->name())), $action);
    }

    /**
     * Duplicar: ServiceCatalogService::duplicate() cria "{nome} (cópia)"
     * inativo; a lista recarrega com a cópia no painel.
     */
    public function onDuplicate($param = null)
    {
        $id = is_array($param) && isset($param['id']) ? (int) $param['id'] : 0;

        try
        {
            TTransaction::open('permission');

            $copy = self::buildServiceCatalogService()->duplicate($id, _t('copy'));

            TTransaction::close();

            TToast::show('info', _t('Record saved'));
            AdiantiCoreApplication::loadPageURL('index.php?class=ServiceList&method=onReload&service_id=' . (int) $copy->id());
        }
        catch (Exception $e)
        {
            TTransaction::rollback();
            error_log(__METHOD__ . ': ' . $e->getMessage());
            new TMessage('error', CvFormat::userError($e));
        }
    }

    /**
     * Excluir: pede confirmação (TQuestion) antes de chamar onDelete.
     */
    public static function onAskDelete($param = null)
    {
        $id = is_array($param) && isset($param['id']) ? (int) $param['id'] : 0;

        $service = self::findServiceForQuestion($id);
        if ($service === null)
        {
            return;
        }

        $action = new TAction([__CLASS__, 'onDelete']);
        $action->setParameter('id', $id);

        new TQuestion(_t('Delete the service "^1"?', CvFormat::e($service->name())), $action);
    }

    /**
     * Carrega o serviço do tenant para a pergunta de Duplicar/Excluir.
     * Id inexistente (ou de outro tenant) mostra "Record not found" e
     * devolve null, sem pergunta.
     */
    private static function findServiceForQuestion(int $id)
    {
        try
        {
            TTransaction::open('permission');
            $service = $id > 0 ? self::buildServiceCatalogService()->findById($id) : null;
            TTransaction::close();
        }
        catch (Exception $e)
        {
            TTransaction::rollback();
            error_log(__METHOD__ . ': ' . $e->getMessage());
            new TMessage('error', CvFormat::userError($e));
            return null;
        }

        if ($service === null)
        {
            new TMessage('error', _t('Record not found'));
            return null;
        }

        return $service;
    }

    /**
     * Exclui pelo ServiceCatalogService::delete(). Serviço com agendamento
     * (DomainException; FK RESTRICT) não é excluído: a mensagem orienta a
     * inativá-lo.
     */
    public function onDelete($param = null)
    {
        $id = is_array($param) && isset($param['id']) ? (int) $param['id'] : 0;

        try
        {
            TTransaction::open('permission');

            try
            {
                self::buildServiceCatalogService()->delete($id);
            }
            catch (DomainException $e)
            {
                TTransaction::rollback();
                error_log(__METHOD__ . ': ' . $e->getMessage());
                new TMessage('error', _t('This service has appointments; deactivate it instead'));
                return;
            }

            TTransaction::close();

            TToast::show('info', _t('Record deleted'));
            AdiantiCoreApplication::loadPageURL('index.php?class=ServiceList&method=onReload');
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
        if (!$this->loaded && (!isset($_GET['method']) || $_GET['method'] !== 'onReload'))
        {
            $this->onReload(func_num_args() > 0 ? func_get_arg(0) : $_GET);
        }

        parent::show();
    }

    /**
     * Torna a linha clicável (troca o painel) e marca a selecionada.
     */
    private function linkRow($object, $row, $cell): void
    {
        if (!is_object($object) || empty($object->id))
        {
            return;
        }

        $cell->{'href'}      = $this->selectUrl((int) $object->id);
        $cell->{'generator'} = 'adianti';
        $cell->{'style'}     = 'cursor: pointer';

        // BootstrapDatagridWrapper descarta 'class' das linhas e reaplica 'className'
        if ((int) $object->id === (int) $this->selectedId)
        {
            $row->{'className'} = 'table-active';
            $row->{'aria-selected'} = 'true';
        }
    }

    private function selectUrl(int $service_id): string
    {
        $query = array_merge(
            ['class' => 'ServiceList', 'method' => 'onReload'],
            $this->filters,
            array_filter(['offset' => $this->pagination['offset'] ?: null, 'page' => $this->pagination['page']], fn ($v) => $v !== null && $v !== ''),
            ['service_id' => $service_id]
        );

        return 'index.php?' . http_build_query($query);
    }

    private function buildDetailPanel($service): TElement
    {
        if ($service === null)
        {
            return CvCard::create(_t('Service'), TElement::tag('p', CvFormat::e(_t('No services found')), ['class' => 'text-muted mb-0']));
        }

        $id = (int) $service->id();

        $content = new TElement('div');
        $content->add(CvPage::tabs([
            'data'     => ['label' => _t('Data'),     'href' => $this->selectUrl($id)],
            'prices'   => ['label' => _t('Prices'),   'href' => null],
            'links'    => ['label' => _t('Links'),    'href' => null],
            'history'  => ['label' => _t('History'),  'href' => null],
        ], 'data'));

        $category = (string) $service->category();

        $list = new TElement('dl');
        $list->{'class'} = 'row mb-3 mt-3';
        $fields = [
            [_t('Category'),           $category === '' ? '—' : CvBadge::create($category, 'info')],
            [_t('Standard price'),     CvFormat::e(CvFormat::money($service->priceCents()))],
            [_t('Estimated duration'), CvFormat::e(self::formatDuration($service->durationMinutes()))],
            [_t('Status'),             self::statusBadge($service->isActive())],
        ];
        foreach ($fields as [$label, $value])
        {
            $list->add(TElement::tag('dt', CvFormat::e($label), ['class' => 'col-5 text-muted fw-normal']));
            $list->add(TElement::tag('dd', $value, ['class' => 'col-7']));
        }
        $content->add($list);

        $edit = new TElement('a');
        $edit->{'class'}     = 'btn btn-primary';
        $edit->{'href'}      = 'index.php?class=ServiceForm&method=onEdit&id=' . $id;
        $edit->{'generator'} = 'adianti';
        $edit->add(new TImage('far:edit'));
        $edit->add(TElement::tag('span', CvFormat::e(_t('Edit')), ['class' => 'ms-1']));
        $content->add($edit);

        $duplicate = new TElement('a');
        $duplicate->{'class'}     = 'btn btn-default ms-2';
        $duplicate->{'href'}      = 'index.php?class=ServiceList&method=onAskDuplicate&static=1&id=' . $id;
        $duplicate->{'generator'} = 'adianti';
        $duplicate->add(new TImage('far:copy'));
        $duplicate->add(TElement::tag('span', CvFormat::e(_t('Duplicate')), ['class' => 'ms-1']));
        $content->add($duplicate);

        $delete = new TElement('a');
        $delete->{'class'}     = 'btn btn-outline-danger ms-2';
        $delete->{'href'}      = 'index.php?class=ServiceList&method=onAskDelete&static=1&id=' . $id;
        $delete->{'generator'} = 'adianti';
        $delete->add(new TImage('far:trash-alt'));
        $delete->add(TElement::tag('span', CvFormat::e(_t('Delete')), ['class' => 'ms-1']));
        $content->add($delete);

        $panel = CvCard::create($service->name(), $content);
        $panel->{'data-service-id'} = (string) $id;

        return $panel;
    }

    /**
     * 30 → "30 min"; 90 → "1h 30min"; 60 → "1h".
     */
    private static function formatDuration(int $minutes): string
    {
        if ($minutes < 60)
        {
            return $minutes . ' min';
        }

        $hours = intdiv($minutes, 60);
        $rest  = $minutes % 60;

        return $rest === 0 ? $hours . 'h' : $hours . 'h ' . $rest . 'min';
    }

    private static function statusBadge(bool $active): TElement
    {
        return $active ? CvBadge::create(_t('Active'), 'success') : CvBadge::create(_t('Inactive'), 'neutral');
    }

    /**
     * @return array<string, string> só filtros preenchidos e válidos
     */
    private static function readFilters(array $param): array
    {
        $filters = [];

        $search = trim((string) ($param['search'] ?? ''));
        if ($search !== '')
        {
            $filters['search'] = $search;
        }

        $category = trim((string) ($param['category'] ?? ''));
        if ($category !== '')
        {
            $filters['category'] = $category;
        }

        $status = (string) ($param['status'] ?? '');
        if (in_array($status, ['active', 'inactive'], true))
        {
            $filters['status'] = $status;
        }

        return $filters;
    }

    private static function matches($service, array $filters): bool
    {
        if (isset($filters['search']) && mb_stripos($service->name(), $filters['search']) === false)
        {
            return false;
        }

        if (isset($filters['category']) && (string) $service->category() !== $filters['category'])
        {
            return false;
        }

        if (isset($filters['status']) && $service->isActive() !== ($filters['status'] === 'active'))
        {
            return false;
        }

        return true;
    }

    /**
     * Monta o serviço de aplicação. Exige TTransaction('permission') aberta.
     */
    private static function buildServiceCatalogService()
    {
        $tenant_context = self::resolveTenantContext();
        $connection = TTransaction::get();

        $repository = new \CentralVet\Persistence\ServiceRepository($tenant_context, $connection);

        return new \CentralVet\Application\ServiceCatalogService($repository, $tenant_context);
    }

    /**
     * Resolve o tenant da sessão autenticada; sessões legadas sem 'tenantid'
     * caem no vínculo tenant_user.
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
