<?php
/**
 * GlobalSearchController
 *
 * Initial/global search (T-14), matching mocks 03/04
 * (https://claude.ai/artifact/A78u8yLNxCro1AYHRLDrcR): a single search box
 * that looks up tutors and patients at once and offers a shortcut straight
 * into TutorForm/PatientForm for the selected record.
 *
 * This controller only combines and types what
 * CentralVet\Application\TutorService::search() (T-04) and
 * CentralVet\Application\PatientService::search() (T-05, added by this
 * task) already return — no name/CPF/species matching rule lives here, and
 * it never touches CentralVet\Persistence or CentralVet\Domain directly
 * beyond wiring the repository instances the services need. The only
 * decision made in this controller is the search-box UX threshold ("at
 * least 2 characters"), which is specific to the combined/global search
 * entry point and not a rule of either the Tutor or the Patient aggregate.
 *
 * @package    control
 * @subpackage clinic
 */
class GlobalSearchController extends TPage
{
    private const LIMIT = 10;

    protected $form;           // search form (filter bar)
    protected $datagrid;       // combined results grid
    protected $pageNavigation; // pager
    protected $footerBox;      // "Showing X–Y of N" footer

    /**
     * Page constructor
     */
    public function __construct()
    {
        parent::__construct();

        // barra de filtros (termo único para tutores e pacientes)
        $this->form = new TForm('form_GlobalSearch');

        $query = new TEntry('query');
        $query->setSize('100%');
        $query->placeholder = _t('Search tutors and patients by name, document, phone or species');

        $find = new TButton('find');
        $find->setAction(new TAction([$this, 'onSearch']), _t('Find'));
        $find->setImage('fa:search');
        $find->{'class'} = 'btn btn-primary';

        $this->form->add(CvPage::filterBar([$query, $find]));
        $this->form->setFields([$query, $find]);

        // keep the search term filled during navigation
        $this->form->setData( TSession::getValue('GlobalSearchController_query') );

        // creates the combined results grid
        $this->datagrid = new BootstrapDatagridWrapper(new TDataGrid);
        CvDatagrid::decorate($this->datagrid);
        $this->datagrid->disableDefaultClick();
        $this->datagrid->setActionSide('right');

        $column_label  = new TDataGridColumn('label', _t('Name'), 'left');
        $column_type   = new TDataGridColumn('type_label', _t('Type'), 'left');
        $column_detail = new TDataGridColumn('detail', _t('Details'), 'left');

        $column_label->setTransformer(function ($value, $object) {
            $species = $object->type === 'patient' ? (string) $object->detail : null;
            return CvAvatar::placeholder((string) $object->label, $species)
                 . ' <span class="ms-2">' . CvFormat::e((string) $object->label) . '</span>';
        });
        $column_type->setTransformer(function ($value, $object) {
            return CvBadge::create((string) $object->type_label, $object->type === 'tutor' ? 'info' : 'success');
        });
        $column_detail->setTransformer(function ($value) {
            return ($value === null || $value === '') ? '—' : $value;
        });
        // label/type transformers escape the raw value themselves (CvFormat::e)
        $column_label->disableHtmlConversion();
        $column_type->disableHtmlConversion();

        $this->datagrid->addColumn($column_label);
        $this->datagrid->addColumn($column_type);
        $this->datagrid->addColumn($column_detail);

        // shortcut action: opens TutorForm or PatientForm depending on the
        // selected row's type — the only field-driven branching here, no
        // business rule.
        $action_select = new TDataGridAction(array('GlobalSearchController', 'onSelect'), ['register_state' => 'false']);
        $action_select->setFields(['id', 'type']);
        $this->datagrid->addActionGroup(CvDatagrid::actionMenu([
            ['label' => _t('Open'), 'action' => $action_select, 'icon' => 'fa:external-link-alt'],
        ]));

        $this->datagrid->createModel();

        $this->pageNavigation = new TPageNavigation;
        $this->pageNavigation->setAction(new TAction([$this, 'onSearch']));
        $this->pageNavigation->setWidth($this->datagrid->getWidth());

        $this->footerBox = new TElement('div');

        $header = CvPage::header(_t('Search'), null, [
            ['label' => _t('New tutor'), 'action' => new TAction(['TutorForm', 'onEdit']), 'icon' => 'fa:user-plus'],
            ['label' => _t('New patient'), 'action' => new TAction(['PatientForm', 'onEdit']), 'icon' => 'fa:plus', 'class' => 'btn btn-primary'],
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
     * Enforces the global-search minimum length (T-14 acceptance
     * criterion: a 1-character term returns an empty list, 2+ characters
     * trigger a real search), then delegates entirely to
     * TutorService::search() and PatientService::search() and renders
     * whatever comes back, typed (paginated in memory).
     */
    public function onSearch($param)
    {
        $param = is_array($param) ? $param : [];

        try
        {
            $term = isset($param['query']) ? trim((string) $param['query']) : '';

            TSession::setValue('GlobalSearchController_query', (object) ['query' => $term]);
            $this->form->setData((object) ['query' => $term]);

            $tenant_context = self::resolveTenantContext();

            TTransaction::open('permission');

            $tutor_repository = new \CentralVet\Persistence\TutorRepository($tenant_context, TTransaction::get());
            $patient_repository = new \CentralVet\Persistence\PatientRepository($tenant_context, TTransaction::get());

            $tutor_service = new \CentralVet\Application\TutorService($tutor_repository);
            $patient_service = new \CentralVet\Application\PatientService(
                $patient_repository,
                $tutor_repository,
                $tenant_context
            );

            $results = self::combineResults($tutor_service, $patient_service, $term);

            TTransaction::close();

            $total  = count($results);
            $offset = isset($param['offset']) ? max(0, (int) $param['offset']) : 0;
            if ($offset >= $total)
            {
                $offset = 0;
            }
            $page_rows = array_slice($results, $offset, self::LIMIT);

            $this->datagrid->clear();

            foreach ($page_rows as $result)
            {
                $item = new stdClass;
                $item->id = $result['id'];
                $item->type = $result['type'];
                $item->type_label = $result['type_label'];
                $item->label = $result['label'];
                $item->detail = $result['detail'];
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
        $this->footerBox->add(CvDatagrid::footer($this->pageNavigation, $from, $offset + $count, $total, _t('results')));
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
     * Combines a tutor search and a patient search into a single, typed
     * result list, applying the global-search minimum-length rule.
     *
     * Framework-independent on purpose (no TPage/TSession/TTransaction):
     * takes already-built Application services and returns plain arrays,
     * so it is directly unit-testable with fake repositories, without
     * booting Adianti or a real database connection — see
     * .tasks/03-fase-1-cadastros-agenda/notes.md for the manual test run
     * against this method.
     *
     * @return list<array{
     *     type: 'tutor'|'patient',
     *     type_label: string,
     *     id: int,
     *     label: string,
     *     detail: string,
     *     target_page: 'TutorForm'|'PatientForm',
     * }>
     */
    public static function combineResults(
        \CentralVet\Application\TutorService $tutorService,
        \CentralVet\Application\PatientService $patientService,
        string $query
    ): array
    {
        $term = trim($query);

        // Global-search UX threshold: a single character is too noisy for a
        // combined tutor+patient lookup (T-14 acceptance criterion). Below
        // 2 characters neither service is even called.
        if (mb_strlen($term) < 2)
        {
            return [];
        }

        $results = [];

        foreach ($tutorService->search($term) as $tutor)
        {
            $results[] = [
                'type'        => 'tutor',
                'type_label'  => _t('Tutor'),
                'id'          => $tutor->id,
                'label'       => $tutor->fullName,
                'detail'      => trim(($tutor->document ?? '') . ' ' . $tutor->phone),
                'target_page' => 'TutorForm',
            ];
        }

        foreach ($patientService->search($term) as $patient)
        {
            $results[] = [
                'type'        => 'patient',
                'type_label'  => _t('Patient'),
                'id'          => $patient->id,
                'label'       => $patient->name,
                'detail'      => $patient->species,
                'target_page' => 'PatientForm',
            ];
        }

        return $results;
    }

    /**
     * method onSelect()
     * Only routing: opens TutorForm or PatientForm's onEdit for the
     * selected record. No decision about tutors/patients is made here
     * beyond picking which screen owns the given id.
     */
    public static function onSelect($param)
    {
        $type = $param['type'] ?? null;
        $id   = $param['id'] ?? null;

        if (empty($id) || !in_array($type, ['tutor', 'patient'], true))
        {
            new TMessage('error', _t('Invalid selection'));
            return;
        }

        $target_page = $type === 'tutor' ? 'TutorForm' : 'PatientForm';

        $action = new TAction([$target_page, 'onEdit'], ['register_state' => 'false', 'key' => $id]);
        AdiantiCoreApplication::loadPageURL($action->serialize());
    }

    /**
     * Resolves the tenant context of the authenticated session (same
     * fallback used by SystemUnitForm/SystemUnitList/TutorForm/TutorList,
     * T-03), since TSession does not carry 'tenantid' yet for legacy
     * sessions.
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
