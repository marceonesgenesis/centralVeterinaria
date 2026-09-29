<?php
/**
 * TutorList
 *
 * Search screen for tutors, matching mock 01
 * (https://claude.ai/artifact/A78u8yLNxCro1AYHRLDrcR): a search field
 * (name/CPF-CNPJ/phone) in a CvPage filter bar plus a CvDatagrid result
 * list, with a "new tutor" shortcut that opens TutorForm in full page.
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
    private const LIMIT = 10;

    protected $form;           // search form (filter bar)
    protected $datagrid;       // results grid
    protected $pageNavigation; // pager
    protected $footerBox;      // "Showing X–Y of N" footer

    /**
     * Page constructor
     */
    public function __construct()
    {
        parent::__construct();

        // barra de filtros (busca por nome/CPF-CNPJ/telefone)
        $this->form = new TForm('form_search_Tutor');

        $query = new TEntry('query');
        $query->setSize('100%');
        $query->placeholder = _t('Search by name, CPF/CNPJ or phone');

        $find = new TButton('find');
        $find->setAction(new TAction([$this, 'onSearch']), _t('Find'));
        $find->setImage('fa:search');
        $find->{'class'} = 'btn btn-primary';

        $this->form->add(CvPage::filterBar([$query, $find]));
        $this->form->setFields([$query, $find]);

        // keep the search term filled during navigation
        $this->form->setData( TSession::getValue('TutorList_query') );

        // creates the results grid
        $this->datagrid = new BootstrapDatagridWrapper(new TDataGrid);
        CvDatagrid::decorate($this->datagrid);
        $this->datagrid->disableDefaultClick();
        $this->datagrid->setActionSide('right');

        $column_name     = new TDataGridColumn('full_name', _t('Name'), 'left');
        $column_document = new TDataGridColumn('document', _t('Document'), 'left');
        $column_phone    = new TDataGridColumn('phone', _t('Phone'), 'left');
        $column_email    = new TDataGridColumn('email', _t('Email'), 'left');

        $column_name->setTransformer(function ($value) {
            return CvAvatar::placeholder((string) $value) . ' <span class="ms-2">' . CvFormat::e((string) $value) . '</span>';
        });
        $dash = function ($value) {
            return ($value === null || $value === '') ? '—' : CvFormat::e((string) $value);
        };
        $column_document->setTransformer($dash);
        $column_phone->setTransformer($dash);
        $column_email->setTransformer($dash);

        // transformers escape the raw value themselves (CvFormat::e)
        foreach ([$column_name, $column_document, $column_phone, $column_email] as $column)
        {
            $column->disableHtmlConversion();
        }

        $this->datagrid->addColumn($column_name);
        $this->datagrid->addColumn($column_document);
        $this->datagrid->addColumn($column_phone);
        $this->datagrid->addColumn($column_email);

        $action_open     = new TDataGridAction(['TutorForm', 'onEdit'], ['key' => '{id}']);
        $action_patients = new TDataGridAction(['PatientList', 'onReload'], ['tutor_id' => '{id}']);
        $action_patient  = new TDataGridAction(['PatientForm', 'onEdit'], ['tutor_id' => '{id}']);

        $this->datagrid->addActionGroup(CvDatagrid::actionMenu([
            ['label' => _t('Open'), 'action' => $action_open, 'icon' => 'fa:external-link-alt'],
            ['label' => _t('Patients'), 'action' => $action_patients, 'icon' => 'fa:paw'],
            ['label' => _t('New patient'), 'action' => $action_patient, 'icon' => 'fa:plus'],
        ]));

        $this->datagrid->createModel();

        $this->pageNavigation = new TPageNavigation;
        $this->pageNavigation->setAction(new TAction([$this, 'onSearch']));
        $this->pageNavigation->setWidth($this->datagrid->getWidth());

        $this->footerBox = new TElement('div');

        $header = CvPage::header(_t('Tutors'), null, [
            ['label' => _t('New tutor'), 'action' => new TAction(['TutorForm', 'onEdit']), 'icon' => 'fa:plus', 'class' => 'btn btn-primary'],
        ]);

        $card = new TElement('div');
        $card->{'class'} = 'cv-card';
        $body = new TElement('div');
        $body->{'class'} = 'cv-card__body';
        $body->add($this->form);
        $body->add($this->datagrid);
        $body->add($this->footerBox);
        $card->add($body);

        // vertical box container
        $container = new TVBox;
        $container->style = 'width: 100%';
        $container->add($header);
        $container->add($card);

        parent::add($container);
    }

    /**
     * method onSearch()
     * Delegates the whole search to TutorService::search() and just
     * renders whatever list comes back (paginated in memory).
     */
    public function onSearch($param)
    {
        $param = is_array($param) ? $param : [];

        try
        {
            $term = isset($param['query']) ? trim((string) $param['query']) : '';

            TSession::setValue('TutorList_query', (object) ['query' => $term]);
            $this->form->setData((object) ['query' => $term]);

            $tenant_context = self::resolveTenantContext();

            TTransaction::open('permission');

            $service = new \CentralVet\Application\TutorService(
                new \CentralVet\Persistence\TutorRepository($tenant_context, TTransaction::get())
            );

            $tutors = $service->search($term);

            TTransaction::close();

            $total  = count($tutors);
            $offset = isset($param['offset']) ? max(0, (int) $param['offset']) : 0;
            if ($offset >= $total)
            {
                $offset = 0;
            }
            $page_rows = array_slice($tutors, $offset, self::LIMIT);

            $this->datagrid->clear();

            foreach ($page_rows as $tutor)
            {
                $item = new stdClass;
                $item->id = $tutor->id;
                $item->full_name = $tutor->fullName;
                $item->document = $tutor->document;
                $item->phone = $tutor->phone;
                $item->email = $tutor->email;
                $this->datagrid->addItem($item);
            }

            $this->renderFooter($param, $offset, count($page_rows), $total, $term);
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
     * Re-runs the last search term (used by the "back" of TutorForm).
     */
    public function onReload($param = null)
    {
        $filter_data = TSession::getValue('TutorList_query');
        $this->onSearch( ['query' => $filter_data->query ?? ''] );
    }

    /**
     * Pager + "Showing X–Y of N" footer.
     */
    private function renderFooter(array $param, int $offset, int $count, int $total, string $term): void
    {
        $this->pageNavigation->setAction(new TAction([$this, 'onSearch'], ['query' => $term]));
        $this->pageNavigation->setCount($total);
        $this->pageNavigation->setProperties($param);
        $this->pageNavigation->setLimit(self::LIMIT);

        $from = $total > 0 ? $offset + 1 : 0;
        $this->footerBox->clearChildren();
        $this->footerBox->add(CvDatagrid::footer($this->pageNavigation, $from, $offset + $count, $total, _t('tutors')));
    }

    /**
     * Shows the empty footer when the page is opened without a search.
     */
    public function show()
    {
        if (!$this->footerBox->getChildren())
        {
            $this->renderFooter([], 0, 0, 0, '');
        }

        parent::show();
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
