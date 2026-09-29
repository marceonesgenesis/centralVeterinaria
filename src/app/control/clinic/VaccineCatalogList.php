<?php
/**
 * VaccineCatalogList
 *
 * Tela de listagem do catálogo de vacinas no padrão do kit Cv* (fase 10, T-16):
 * cabeçalho CvPage com "Novo", barra de filtros em linha (no lugar da cortina
 * do right panel), tabela CvDatagrid com status em CvBadge e rodapé paginado.
 *
 * Toda linha vem de CentralVet\Application\VaccineCatalogService::listActive() — a
 * camada Persistence nunca é acessada daqui; o escopo por tenant acontece
 * dentro do serviço/repositório.
 *
 * Sem ação de Editar/Excluir: VaccineCatalogService não expõe atualização nem remoção.
 *
 * A ação de linha "Protocolo" abre VaccineProtocolForm (esquema de doses)
 * em página cheia.
 *
 * @version    2.0
 * @package    control
 * @subpackage clinic
 */
class VaccineCatalogList extends TStandardList
{
    protected $form;     // barra de filtros
    protected $datagrid; // listing
    protected $pageNavigation;
    protected $footerBox;

    /**
     * Page constructor
     */
    public function __construct()
    {
        parent::__construct();

        // 'VaccineCatalogItem' is only used here as the session-key namespace for the
        // filter form (see AdiantiStandardCollectionTrait::onSearch()); the
        // actual listing never queries the table through it.
        parent::setActiveRecord('VaccineCatalogItem');
        parent::setDefaultOrder('name', 'asc');
        parent::addFilterField('name', 'like', 'name'); // filterField, operator, formField
        parent::setLimit(TSession::getValue(__CLASS__ . '_limit') ?? 10);

        // barra de filtros em linha (busca por nome)
        $this->form = new TForm('form_search_VaccineCatalogItem');

        $name = new TEntry('name');
        $name->placeholder = _t('Name');
        $name->setSize('100%');

        $find = new TButton('find');
        $find->setAction(new TAction([$this, 'onSearch']), _t('Find'));
        $find->setImage('fa:search');
        $find->{'class'} = 'btn btn-primary';

        $this->form->add(CvPage::filterBar([$name, $find]));
        $this->form->setFields([$name, $find]);

        // keep the form filled during navigation with session data
        $this->form->setData( TSession::getValue('VaccineCatalogItem_filter_data') );

        // tabela
        $this->datagrid = new BootstrapDatagridWrapper(new TDataGrid);
        CvDatagrid::decorate($this->datagrid);
        $this->datagrid->setActionSide('right');

        $column_name         = new TDataGridColumn('name', _t('Name'), 'left');
        $column_manufacturer = new TDataGridColumn('manufacturer', _t('Manufacturer'), 'left');
        $column_stock        = new TDataGridColumn('stock_quantity', _t('Stock'), 'right');
        $column_manufacturer->setTransformer(function ($value) {
            return ($value === null || $value === '') ? '—' : CvFormat::e((string) $value);
        });

        $column_status = new TDataGridColumn('active', _t('Status'), 'left');
        $column_status->setTransformer(function ($value) {
            return $value ? CvBadge::create(_t('Active'), 'success') : CvBadge::create(_t('Inactive'), 'neutral');
        });

        $this->datagrid->addColumn($column_name);
        $this->datagrid->addColumn($column_manufacturer);
        $this->datagrid->addColumn($column_stock);
        $this->datagrid->addColumn($column_status);

        // row action: configure this vaccine's dose schedule
        $action_protocol = new TDataGridAction(['VaccineProtocolForm', 'onEdit'], ['vaccine_catalog_item_id' => '{id}']);
        $this->datagrid->addActionGroup(CvDatagrid::actionMenu([
            ['label' => _t('Protocol'), 'action' => $action_protocol, 'icon' => 'fa:syringe'],
        ]));

        // create the datagrid model
        $this->datagrid->createModel();

        // create the page navigation
        $this->pageNavigation = new TPageNavigation;
        $this->pageNavigation->setAction(new TAction(array($this, 'onReload')));
        $this->pageNavigation->setWidth($this->datagrid->getWidth());

        $this->footerBox = new TElement('div');

        $card = new TElement('div');
        $card->{'class'} = 'cv-card';
        $card->{'style'} = 'padding: 16px';
        $card->add($this->form);
        $card->add($this->datagrid);
        $card->add($this->footerBox);

        // No TXMLBreadCrumb here on purpose (menu.xml registration is not
        // guaranteed for this class; TXMLBreadCrumb throws when it is absent).
        $container = new TVBox;
        $container->style = 'width: 100%';
        $container->add(CvPage::header(_t('Vaccines'), null, [
            ['label' => _t('New'), 'href' => 'index.php?class=VaccineCatalogForm', 'icon' => 'fa:plus', 'class' => 'btn btn-primary'],
        ]));
        $container->add($card);

        parent::add($container);
    }

    /**
     * method onReload()
     * Loads the datagrid exclusively from
     * CentralVet\Application\VaccineCatalogService::listActive() — the tenant
     * scoping happens inside that service/repository, never here.
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

            $catalog = self::buildVaccineCatalogService();

            // every row this listing can ever show comes from this call
            $items = $catalog->listActive();

            // valor digitado na busca (onSearch grava '<record>_name'; a chave
            // '<record>_filter_name' guarda um TFilter, não o texto)
            $name_filter = TSession::getValue('VaccineCatalogItem_name');
            $name_filter = (is_scalar($name_filter) && $name_filter !== '') ? mb_strtolower((string) $name_filter) : null;

            $rows = [];
            foreach ($items as $item)
            {
                if ($name_filter !== null && mb_strpos(mb_strtolower($item->name()), $name_filter) === false)
                {
                    continue;
                }

                $row = new stdClass;
                $row->id     = $item->id();
                $row->name   = $item->name();
                $row->manufacturer   = $item->manufacturer();
                $row->stock_quantity = $item->stockQuantity();
                $row->active = $item->active();

                $rows[] = $row;
            }

            // total count for this tenant, as returned by listActive()
            // (after the optional name filter)
            $count = count($rows);

            $offset = isset($param['offset']) ? max(0, (int) $param['offset']) : 0;
            $limit  = isset($this->limit) ? ( $this->limit > 0 ? $this->limit : NULL) : 10;
            if ($offset >= $count)
            {
                $offset = 0;
            }

            $page_rows = $limit ? array_slice($rows, $offset, $limit) : $rows;

            $this->datagrid->clear();
            foreach ($page_rows as $row)
            {
                $this->datagrid->addItem($row);
            }

            $this->pageNavigation->setCount($count); // count of records
            $this->pageNavigation->setProperties($param); // order, page
            $this->pageNavigation->setLimit($limit); // limit

            $this->footerBox->add(CvDatagrid::footer(
                $this->pageNavigation,
                $offset + 1,
                $offset + count($page_rows),
                $count,
                mb_strtolower(_t('Vaccines'))
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
        catch (Exception $e) // in case of exception
        {
            // shows the exception error message
            new TMessage('error', $e->getMessage());
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
     * Builds the Application service with its dependencies. Requires an
     * already-open TTransaction('permission') connection.
     */
    private static function buildVaccineCatalogService()
    {
        $tenant_context = self::resolveTenantContext();
        $connection = TTransaction::get();

        $repository = new \CentralVet\Persistence\VaccineCatalogRepository($tenant_context, $connection);

        return new \CentralVet\Application\VaccineCatalogService($repository, $tenant_context);
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
