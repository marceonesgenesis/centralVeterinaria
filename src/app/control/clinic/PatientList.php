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
    protected $tutor_id;
    protected $datagrid;
    protected $panel;

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
        $this->datagrid->style = 'width: 100%';
        $this->datagrid->setHeight(320);

        $column_name = new TDataGridColumn('name', _t('Name'), 'left');
        $column_species = new TDataGridColumn('species', _t('Species'), 'left');
        $column_breed = new TDataGridColumn('breed', _t('Breed'), 'left');
        $column_sex = new TDataGridColumn('sex', _t('Sex'), 'center', 80);
        $column_birth_date = new TDataGridColumn('birth_date', _t('Birth date'), 'center', 110);
        $column_weight_kg = new TDataGridColumn('weight_kg', _t('Weight (kg)'), 'right', 100);

        $this->datagrid->addColumn($column_name);
        $this->datagrid->addColumn($column_species);
        $this->datagrid->addColumn($column_breed);
        $this->datagrid->addColumn($column_sex);
        $this->datagrid->addColumn($column_birth_date);
        $this->datagrid->addColumn($column_weight_kg);

        $this->datagrid->createModel();

        $this->panel = new TPanelGroup(_t('Patients'));
        $this->panel->add($this->datagrid);

        $new_action = new TAction(['PatientForm', 'onEdit'], ['register_state' => 'false']);
        if ($this->tutor_id !== null)
        {
            $new_action->setParameter('tutor_id', $this->tutor_id);
        }
        $this->panel->addHeaderActionLink(_t('New'), $new_action, 'fa:plus');

        // page header (design system: .cv-page-header/.cv-page-title, T-02)
        $page_header = new TElement('header');
        $page_header->class = 'cv-page-header';
        $page_header_titlebox = new TElement('div');
        $page_header_title = new TElement('h1');
        $page_header_title->class = 'cv-page-title';
        $page_header_title->add(_t('Patients'));
        $page_header_titlebox->add($page_header_title);
        $page_header->add($page_header_titlebox);

        // vertical box container
        $container = new TVBox;
        $container->style = 'width: 100%';
        $container->add(new TXMLBreadCrumb('menu.xml', __CLASS__));
        $container->add($this->panel);

        parent::add($page_header);
        parent::add($container);

        // onReload() is NOT called here: every real entry point to this
        // screen (TutorList's "Patients" row action, PatientForm's
        // setAfterSaveAction()) explicitly requests `method=onReload` in
        // its TAction, so Adianti's dispatcher already invokes onReload()
        // once per request. onReload() never clears the datagrid before
        // adding rows (population only happens once per real request), so
        // calling it a second time here from the constructor used to
        // duplicate every row (each patient rendered twice).
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
        try
        {
            if (empty($this->tutor_id))
            {
                return;
            }

            TTransaction::open('permission');

            $service = $this->buildPatientService();
            $patients = $service->findByTutor($this->tutor_id);

            TTransaction::close();

            foreach ($patients as $patient)
            {
                $row = new stdClass;
                $row->name = $patient->name;
                $row->species = $patient->species;
                $row->breed = $patient->breed;
                $row->sex = $patient->sex;
                $row->birth_date = $patient->birthDate;
                $row->weight_kg = $patient->weightKg;

                $this->datagrid->addItem($row);
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
