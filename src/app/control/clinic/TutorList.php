<?php
/**
 * TutorList
 *
 * Search screen for tutors, matching mock 01
 * (https://claude.ai/artifact/A78u8yLNxCro1AYHRLDrcR): a search field
 * (name/CPF-CNPJ/phone) plus a selectable result list, with a "new tutor"
 * shortcut that opens TutorForm's quick registration card in the right
 * panel when the search does not find anyone.
 *
 * This controller renders only what CentralVet\Application\TutorService::
 * search() (T-04) returns — it does not filter, sort, rank or otherwise
 * decide anything about the results, and it never touches
 * CentralVet\Persistence or CentralVet\Domain directly beyond wiring the
 * repository instance the service needs.
 *
 * @package    control
 * @subpackage clinic
 */
class TutorList extends TPage
{
    protected $form;     // search form
    protected $datagrid; // results grid

    /**
     * Page constructor
     */
    public function __construct()
    {
        parent::__construct();

        // creates the search form
        $this->form = new BootstrapFormBuilder('form_search_Tutor');
        $this->form->setFormTitle(_t('Tutors'));

        $query = new TEntry('query');
        $query->setSize('100%');
        $query->placeholder = _t('Search by name, CPF/CNPJ or phone');

        $this->form->addFields( [new TLabel(_t('Search'))] );
        $this->form->addFields( [$query] );

        // keep the search term filled during navigation
        $this->form->setData( TSession::getValue('TutorList_query') );

        $btn = $this->form->addAction(_t('Find'), new TAction(array($this, 'onSearch')), 'fa:search');
        $btn->class = 'btn btn-sm btn-primary';

        // creates the results grid
        $this->datagrid = new BootstrapDatagridWrapper(new TQuickGrid);
        $this->datagrid->style = 'width: 100%';
        $this->datagrid->setHeight(320);

        $this->datagrid->addQuickColumn(_t('Name'), 'full_name', 'left');
        $this->datagrid->addQuickColumn(_t('Document'), 'document', 'left');
        $this->datagrid->addQuickColumn(_t('Phone'), 'phone', 'left');
        $this->datagrid->addQuickColumn(_t('Email'), 'email', 'left');

        // create SELECT action (the caller decides what a selected tutor means)
        $action_select = new TDataGridAction(array($this, 'onSelect'), ['register_state' => 'false']);
        $action_select->setButtonClass('btn btn-default');
        $action_select->setLabel(_t('Select'));
        $action_select->setImage('fa:check green');
        $action_select->setField('id');
        $this->datagrid->addAction($action_select);

        // row action: navigate to this tutor's patients (T-05), same
        // pattern as ProductList's action_batch (product_id -> StockBatchForm)
        $action_patients = new TDataGridAction(['PatientList', 'onReload'], ['tutor_id' => '{id}', 'register_state' => 'false']);
        $action_patients->setLabel(_t('Patients'));
        $action_patients->setImage('fa:paw blue');
        $this->datagrid->addAction($action_patients);

        $this->datagrid->createModel();

        $panel = new TPanelGroup;
        $panel->class = 'cv-section';
        $panel->add($this->datagrid);
        $panel->addHeaderWidget($this->form);

        $panel->addHeaderActionLink('', new TAction(['TutorForm', 'onEdit'], ['register_state' => 'false']), 'fa:plus');

        // page header (design system: .cv-page-header/.cv-page-title, T-02)
        $page_header = new TElement('header');
        $page_header->class = 'cv-page-header';
        $page_header_titlebox = new TElement('div');
        $page_header_title = new TElement('h1');
        $page_header_title->class = 'cv-page-title';
        $page_header_title->add(_t('Tutors'));
        $page_header_titlebox->add($page_header_title);
        $page_header->add($page_header_titlebox);

        // vertical box container
        $container = new TVBox;
        $container->style = 'width: 100%';
        $container->add($panel);

        parent::add($page_header);
        parent::add($container);
    }

    /**
     * method onSearch()
     * Delegates the whole search to TutorService::search() and just
     * renders whatever list comes back.
     */
    public function onSearch($param)
    {
        try
        {
            $term = isset($param['query']) ? trim((string) $param['query']) : '';

            TSession::setValue('TutorList_query', (object) ['query' => $term]);

            $tenant_context = self::resolveTenantContext();

            TTransaction::open('permission');

            $service = new \CentralVet\Application\TutorService(
                new \CentralVet\Persistence\TutorRepository($tenant_context, TTransaction::get())
            );

            $tutors = $service->search($term);

            TTransaction::close();

            $this->datagrid->clear();

            foreach ($tutors as $tutor)
            {
                $item = new stdClass;
                $item->id = $tutor->id;
                $item->full_name = $tutor->fullName;
                $item->document = $tutor->document;
                $item->phone = $tutor->phone;
                $item->email = $tutor->email;
                $this->datagrid->addItem($item);
            }
        }
        catch (\CentralVet\Tenancy\Exception\TenantBoundaryViolation | \CentralVet\Tenancy\Exception\MissingTenantContext $e)
        {
            TTransaction::rollback();
            new TMessage('error', _t('Record not found'));
        }
        catch (Exception $e) // in case of exception
        {
            new TMessage('error', $e->getMessage());
            TTransaction::rollback();
        }
    }

    /**
     * method onReload()
     * Re-runs the last search term, used as TutorForm's after-save action.
     */
    public function onReload($param = null)
    {
        $filter_data = TSession::getValue('TutorList_query');
        $this->onSearch( ['query' => $filter_data->query ?? ''] );
    }

    /**
     * method onSelect()
     * Only forwards the chosen tutor id to whoever consumes this screen
     * (e.g. the appointment/queue flows from T-12/T-13); no decision is
     * made here.
     */
    public static function onSelect($param)
    {
        TSession::setValue('selected_tutor_id', $param['id'] ?? null);
        TScript::create("Template.closeRightPanel()");
    }

    /**
     * Resolves the tenant context of the authenticated session (same
     * fallback used by SystemUnitForm/SystemUnitList, T-03), since TSession
     * does not carry 'tenantid' yet for legacy sessions.
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
