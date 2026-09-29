<?php
/**
 * PatientForm
 *
 * Tela de cadastro de paciente (T-10), vinculada a um tutor recebido por
 * parâmetro de querystring (?tutor_id=...). Nenhuma regra de negócio própria
 * vive aqui: criação e validação (inclusive a rejeição de um tutor_id de
 * outro tenant) são responsabilidade exclusiva de
 * CentralVet\Application\PatientService (T-05). Este controller apenas monta
 * o formulário, repassa os dados recebidos e traduz o resultado do serviço
 * (sucesso ou exceção) em feedback de tela — nunca deixando escapar um erro
 * HTTP 500/fatal.
 *
 * Este arquivo não implementa nenhuma tela/consulta de Tutor: o tutor_id é
 * apenas recebido e repassado ao serviço, conforme escopo da T-10.
 *
 * @version    8.6
 * @package    control
 * @subpackage clinic
 */
class PatientForm extends TStandardForm
{
    protected $form; // form
    protected $tutor_id; // received via querystring, forwarded to PatientService

    /**
     * Class constructor
     * Creates the page and the registration form
     */
    function __construct($param = null)
    {
        parent::__construct();

        parent::setTargetContainer('adianti_right_panel');

        $this->tutor_id = (isset($param['tutor_id']) && $param['tutor_id'] !== '')
            ? (int) $param['tutor_id']
            : null;

        $this->setDatabase('permission');          // defines the database
        $this->setActiveRecord('Patient');          // defines the active record
        $this->setAfterSaveAction( new TAction(['PatientList', 'onReload']) );
        $this->setUseToast(true);

        // creates the form
        $this->form = new BootstrapFormBuilder('form_Patient');
        $this->form->setFormTitle(_t('Patient'));
        $this->form->enableClientValidation();

        // create the form fields
        $tutor_id = new TEntry('tutor_id');
        $name = new TEntry('name');
        $species = new TRadioGroup('species');
        $breed = new TEntry('breed');
        $sex = new TRadioGroup('sex');
        $birth_date = new TDate('birth_date');
        $weight_kg = new TEntry('weight_kg');
        $color = new TEntry('color');
        $notes = new TText('notes');

        $species->addItems( ['Canino' => _t('Dog'), 'Felino' => _t('Cat'), 'Outro' => _t('Other')] );
        $species->setLayout('horizontal');
        $species->setUseButton();

        $sex->addItems( ['M' => _t('Male'), 'F' => _t('Female')] );
        $sex->setLayout('horizontal');
        $sex->setUseButton();

        $birth_date->setMask('dd/mm/yyyy');
        $birth_date->setDatabaseMask('yyyy-mm-dd');

        // add the fields
        $this->form->addFields( [new TLabel(_t('Tutor'))] );
        $this->form->addFields( [$tutor_id] );
        $this->form->addFields( [new TLabel(_t('Name'))] );
        $this->form->addFields( [$name] );
        $this->form->addFields( [new TLabel(_t('Species'))] );
        $this->form->addFields( [$species] );
        $this->form->addFields( [new TLabel(_t('Breed'))] );
        $this->form->addFields( [$breed] );
        $this->form->addFields( [new TLabel(_t('Sex'))] );
        $this->form->addFields( [$sex] );
        $this->form->addFields( [new TLabel(_t('Birth date'))] );
        $this->form->addFields( [$birth_date] );
        $this->form->addFields( [new TLabel(_t('Weight (kg)'))] );
        $this->form->addFields( [$weight_kg] );
        $this->form->addFields( [new TLabel(_t('Color'))] );
        $this->form->addFields( [$color] );
        $this->form->addFields( [new TLabel(_t('Notes'))] );
        $this->form->addFields( [$notes] );

        // tutor_id comes exclusively from the querystring param; the field
        // only echoes it back read-only, no Tutor lookup/UI is built here
        $tutor_id->setEditable(FALSE);
        $tutor_id->setSize('30%');
        if ($this->tutor_id !== null)
        {
            $tutor_id->setValue($this->tutor_id);
        }

        $name->setSize('100%');
        $name->addValidation( _t('Name'), new TRequiredValidator );
        $species->addValidation( _t('Species'), new TRequiredValidator );
        $breed->setSize('100%');
        $weight_kg->setSize('30%');
        $color->setSize('100%');
        $notes->setSize('100%', 80);

        // create the form actions
        $btn = $this->form->addAction(_t('Save'), new TAction(array($this, 'onSave')), 'fa:check');
        $btn->class = 'btn btn-sm btn-primary';
        $this->form->addActionLink(_t('Clear'),  new TAction(array($this, 'onEdit')), 'fa:eraser red');

        $this->form->addHeaderActionLink(_t('Close'), new TAction([$this, 'onClose']), 'fa:times red');

        // page header (design system: .cv-page-header/.cv-page-title, T-02)
        $page_header = new TElement('header');
        $page_header->class = 'cv-page-header';
        $page_header_titlebox = new TElement('div');
        $page_header_title = new TElement('h1');
        $page_header_title->class = 'cv-page-title';
        $page_header_title->add(_t('Patient'));
        $page_header_titlebox->add($page_header_title);
        $page_header->add($page_header_titlebox);

        // vertical box container
        $container = new TVBox;
        $container->style = 'width: 100%';
        $container->add($this->form);

        parent::add($page_header);
        parent::add($container);
    }

    /**
     * on close
     */
    public static function onClose($param)
    {
        TScript::create("Template.closeRightPanel()");
    }

    /**
     * method onEdit()
     * Patient has no update use case in PatientService (T-05 exposes only
     * create()/findById()/findByTutor()), so this screen is create-only:
     * onEdit() just clears the form and re-applies the tutor_id received in
     * the querystring, mirroring the "new" flow used by SystemUnitForm's
     * addHeaderActionLink('+') convention.
     */
    public function onEdit($param)
    {
        $this->form->clear(true);

        if ($this->tutor_id !== null)
        {
            $data = new stdClass;
            $data->tutor_id = $this->tutor_id;
            $this->form->setData($data);
        }
    }

    /**
     * method onSave()
     * Executed whenever the user clicks the save button. All business rules
     * (including cross-tenant tutor_id rejection) live in PatientService;
     * this method only forwards form data and translates the outcome into
     * screen feedback, never letting an exception escape as a 500.
     */
    public function onSave($param = null)
    {
        try
        {
            $data = $this->form->getData();

            $this->form->validate();

            $tutor_id = $this->tutor_id ?? (isset($data->tutor_id) ? (int) $data->tutor_id : null);

            if (empty($tutor_id))
            {
                throw new Exception(_t('A tutor must be informed to register a patient'));
            }

            TTransaction::open('permission');

            $service = $this->buildPatientService();

            $patient = $service->create([
                'tutor_id'   => $tutor_id,
                'name'       => $data->name ?? null,
                'species'    => $data->species ?? null,
                'breed'      => $data->breed ?? null,
                'sex'        => $data->sex ?? null,
                'birth_date' => !empty($data->birth_date) ? $data->birth_date : null,
                'weight_kg'  => $data->weight_kg ?? null,
                'color'      => $data->color ?? null,
                'notes'      => $data->notes ?? null,
            ]);

            TTransaction::close();

            if (!empty($this->useToast))
            {
                TToast::show('info', _t('Record saved'));
                if (!empty($this->afterSaveAction))
                {
                    AdiantiCoreApplication::loadPageURL( $this->afterSaveAction->serialize() );
                }
            }
            else
            {
                new TMessage('info', _t('Record saved'), $this->afterSaveAction);
            }

            return $patient;
        }
        catch (\CentralVet\Domain\Exception\CrossTenantReferenceException $e)
        {
            // tutor_id does not resolve within the authenticated tenant
            // (missing or belongs to another tenant) — treated message,
            // never an uncaught exception / HTTP 500.
            TTransaction::rollback();
            new TMessage('error', _t('Selected tutor was not found for your account'));
        }
        catch (\CentralVet\Tenancy\Exception\MissingTenantContext $e)
        {
            TTransaction::rollback();
            new TMessage('error', _t('Your session does not have an active tenant. Please log in again'));
        }
        catch (Exception $e) // in case of exception (validation, domain, etc.)
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
