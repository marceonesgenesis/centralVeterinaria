<?php
/**
 * PatientList
 *
 * Tela de listagem de pacientes de um tutor (T-10). Recebe o tutor_id por
 * parâmetro de querystring (?tutor_id=...) e delega toda leitura a
 * CentralVet\Application\PatientService::findByTutor() (T-05) — nenhuma
 * consulta ou regra de negócio própria vive neste controller, e o
 * tenant-scoping é responsabilidade exclusiva do repositório usado pelo
 * serviço (ADR 0002), nunca deste controller.
 *
 * Este arquivo não implementa nenhuma tela/consulta de Tutor: o tutor_id é
 * apenas recebido e repassado ao serviço, conforme escopo da T-10.
 *
 * Extends TPage (not TStandardList): PatientService only exposes
 * create()/findById()/findByTutor() (no update/delete/generic search), so
 * the full TStandardList CRUD trait (which assumes a directly queryable
 * active record) does not fit this narrower, tutor-scoped read surface.
 *
 * @version    8.6
 * @package    control
 * @subpackage clinic
 */
class PatientList extends TPage
{
    private const LIMIT = 10;

    protected $tutor_id;
    protected $datagrid;
    protected $pageNavigation;
    protected $footerBox;
    protected $headerBox;

    /**
     * Page constructor
     */
    public function __construct($param = null)
    {
        parent::__construct();

        $this->tutor_id = (isset($param['tutor_id']) && $param['tutor_id'] !== '')
            ? (int) $param['tutor_id']
            : null;

        // creates a DataGrid
        $this->datagrid = new BootstrapDatagridWrapper(new TDataGrid);
        CvDatagrid::decorate($this->datagrid);
        $this->datagrid->disableDefaultClick();
        $this->datagrid->setActionSide('right');

        $column_name = new TDataGridColumn('name', _t('Name'), 'left');
        $column_species = new TDataGridColumn('species', _t('Species'), 'left');
        $column_breed = new TDataGridColumn('breed', _t('Breed'), 'left');
        $column_sex = new TDataGridColumn('sex', _t('Sex'), 'center', 80);
        $column_birth_date = new TDataGridColumn('birth_date', _t('Birth date'), 'center', 110);
        $column_weight_kg = new TDataGridColumn('weight_kg', _t('Weight (kg)'), 'right', 100);

        $column_name->setTransformer(function ($value, $object) {
            return CvAvatar::placeholder((string) $object->name, (string) $object->species)
                 . ' <span class="ms-2">' . CvFormat::e((string) $object->name) . '</span>';
        });
        $column_species->setTransformer(function ($value, $object) {
            return ($object->species === null || $object->species === '') ? '—' : CvBadge::create((string) $object->species, 'info');
        });
        $column_birth_date->setTransformer(function ($value, $object) {
            $date = $object->birth_date ? DateTime::createFromFormat('Y-m-d', substr((string) $object->birth_date, 0, 10)) : false;
            return $date ? $date->format('d/m/Y') : '—';
        });
        $column_weight_kg->setTransformer(function ($value, $object) {
            return $object->weight_kg === null ? '—' : number_format((float) $object->weight_kg, 2, ',', '.');
        });
        foreach ([$column_breed, $column_sex] as $column)
        {
            $column->setTransformer(function ($value) {
                return ($value === null || $value === '') ? '—' : $value;
            });
        }
        foreach ([$column_name, $column_species, $column_birth_date, $column_weight_kg] as $column)
        {
            // transformers escape the raw value themselves (CvFormat::e)
            $column->disableHtmlConversion();
        }

        $this->datagrid->addColumn($column_name);
        $this->datagrid->addColumn($column_species);
        $this->datagrid->addColumn($column_breed);
        $this->datagrid->addColumn($column_sex);
        $this->datagrid->addColumn($column_birth_date);
        $this->datagrid->addColumn($column_weight_kg);

        $action_open = new TDataGridAction(['PatientForm', 'onEdit'], ['key' => '{id}', 'tutor_id' => '{tutor_id}']);
        $this->datagrid->addActionGroup(CvDatagrid::actionMenu([
            ['label' => _t('Open'), 'action' => $action_open, 'icon' => 'fa:external-link-alt'],
        ]));

        $this->datagrid->createModel();

        $this->pageNavigation = new TPageNavigation;
        $this->pageNavigation->setAction(new TAction([$this, 'onReload'], ['tutor_id' => $this->tutor_id]));
        $this->pageNavigation->setWidth($this->datagrid->getWidth());

        $this->footerBox = new TElement('div');
        $this->headerBox = new TElement('div');

        $card = new TElement('div');
        $card->{'class'} = 'cv-card';
        $body = new TElement('div');
        $body->{'class'} = 'cv-card__body';
        $body->add($this->datagrid);
        $body->add($this->footerBox);
        $card->add($body);

        // vertical box container
        $container = new TVBox;
        $container->style = 'width: 100%';
        $container->add($this->headerBox);
        $container->add($card);

        parent::add($container);

        // onReload() is NOT called here: every real entry point to this
        // screen (TutorList's "Patients" row action, TutorForm/PatientForm's
        // "back") explicitly requests `method=onReload` in its TAction, so
        // Adianti's dispatcher already invokes onReload() once per request;
        // show() fills the header/footer when it was not called.
    }

    /**
     * method onReload()
     * Reloads the datagrid with the current tutor's patients, calling
     * PatientService::findByTutor(). Never lets a domain/tenancy exception
     * escape as an HTTP 500 — every failure is translated into a treated
     * TMessage.
     */
    public function onReload($param = null)
    {
        $param = is_array($param) ? $param : [];
        $tutor_name = null;
        $patients = [];

        try
        {
            if (!empty($this->tutor_id))
            {
                TTransaction::open('permission');

                $service = $this->buildPatientService();
                $patients = $service->findByTutor($this->tutor_id);

                $tutor_service = new \CentralVet\Application\TutorService(
                    new \CentralVet\Persistence\TutorRepository(self::resolveTenantContext(), TTransaction::get())
                );
                $tutor = $tutor_service->findById($this->tutor_id);
                $tutor_name = $tutor !== null ? $tutor->fullName : null;

                TTransaction::close();
            }
        }
        catch (\CentralVet\Tenancy\Exception\MissingTenantContext $e)
        {
            TTransaction::rollback();
            new TMessage('error', _t('Your session does not have an active tenant. Please log in again'));
        }
        catch (Exception $e) // in case of exception, never let it escape as a 500
        {
            TTransaction::rollback();
            new TMessage('error', $e->getMessage());
        }

        $total  = count($patients);
        $offset = isset($param['offset']) ? max(0, (int) $param['offset']) : 0;
        if ($offset >= $total)
        {
            $offset = 0;
        }
        $page_rows = array_slice($patients, $offset, self::LIMIT);

        $this->datagrid->clear();

        foreach ($page_rows as $patient)
        {
            $row = new stdClass;
            $row->id = $patient->id;
            $row->tutor_id = $patient->tutorId;
            $row->name = $patient->name;
            $row->species = $patient->species;
            $row->breed = $patient->breed;
            $row->sex = $patient->sex;
            $row->birth_date = $patient->birthDate;
            $row->weight_kg = $patient->weightKg;

            $this->datagrid->addItem($row);
        }

        $this->renderChrome($param, $offset, count($page_rows), $total, $tutor_name);
    }

    /**
     * Shows header and empty footer when onReload() was not requested.
     */
    public function show()
    {
        if (!$this->headerBox->getChildren())
        {
            $this->renderChrome([], 0, 0, 0, null);
        }

        parent::show();
    }

    /**
     * CvPage header (back to tutors, new patient) + "Showing X–Y of N" footer.
     */
    private function renderChrome(array $param, int $offset, int $count, int $total, ?string $tutor_name): void
    {
        $actions = [
            ['label' => '', 'icon' => 'fa:arrow-left', 'action' => $this->tutor_id !== null
                ? new TAction(['TutorForm', 'onEdit'], ['key' => $this->tutor_id])
                : new TAction(['TutorList', 'onReload'])],
        ];

        $new_action = new TAction(['PatientForm', 'onEdit']);
        if ($this->tutor_id !== null)
        {
            $new_action->setParameter('tutor_id', $this->tutor_id);
        }
        $actions[] = ['label' => _t('New patient'), 'action' => $new_action, 'icon' => 'fa:plus', 'class' => 'btn btn-primary'];

        $this->headerBox->clearChildren();
        $this->headerBox->add(CvPage::header(_t('Patients'), $tutor_name, $actions));

        $this->pageNavigation->setCount($total);
        $this->pageNavigation->setProperties($param);
        $this->pageNavigation->setLimit(self::LIMIT);

        $from = $total > 0 ? $offset + 1 : 0;
        $this->footerBox->clearChildren();
        $this->footerBox->add(CvDatagrid::footer($this->pageNavigation, $from, $offset + $count, $total, mb_strtolower(_t('Patients'))));
    }

    /**
     * Builds CentralVet\Application\PatientService with tenant-aware
     * repositories, reusing the authenticated session's tenant context.
     * Must be called inside an open 'permission' TTransaction.
     */
    private function buildPatientService()
    {
        $tenant_context = self::resolveTenantContext();
        $connection = TTransaction::get();

        $tutors = new \CentralVet\Persistence\TutorRepository($tenant_context, $connection);
        $patients = new \CentralVet\Persistence\PatientRepository($tenant_context, $connection);

        return new \CentralVet\Application\PatientService($patients, $tutors, $tenant_context);
    }

    /**
     * Resolves the tenant context of the authenticated session, mirroring
     * the helper used by SystemUnitForm/SystemUnitList (T-03). Duplicated
     * here (rather than shared) to avoid touching files outside T-10 scope.
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
