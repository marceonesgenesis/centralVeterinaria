<?php
/**
 * PatientForm
 *
 * Tela de cadastro de paciente (T-10) em página cheia (kit Cv*), vinculada a
 * um tutor recebido por querystring (?tutor_id=...) ou escolhido por
 * TDBUniqueSearch de Tutor filtrado pelo tenant; com key/id na URL o
 * paciente salvo é reaberto em modo leitura. Nenhuma regra de negócio própria
 * vive aqui: criação e validação (inclusive a rejeição de um tutor_id de
 * outro tenant) são responsabilidade exclusiva de
 * CentralVet\Application\PatientService (T-05). Este controller apenas monta
 * o formulário, repassa os dados recebidos e traduz o resultado do serviço
 * (sucesso ou exceção) em feedback de tela — nunca deixando escapar um erro
 * HTTP 500/fatal.
 *
 * Do Tutor, esta tela só lê o nome (TutorService::findById) para exibição;
 * o tutor_id é repassado ao serviço, que valida o vínculo com o tenant.
 *
 * @version    8.6
 * @package    control
 * @subpackage clinic
 */
class PatientForm extends TStandardForm
{
    protected $form; // form
    protected $tutor_id; // received via querystring (or from the opened patient), forwarded to PatientService

    /** @var int|null paciente aberto em modo leitura (key/id na URL) */
    protected $viewId = null;

    /** @var \CentralVet\Domain\Patient|null paciente carregado no modo leitura */
    protected $viewPatient = null;

    /**
     * Class constructor
     * Creates the page: registration form (new) or the patient's record
     * opened read-only (key/id in the URL — PatientService has no update use case).
     */
    function __construct($param = null)
    {
        parent::__construct();

        $this->tutor_id = (isset($param['tutor_id']) && $param['tutor_id'] !== '')
            ? (int) $param['tutor_id']
            : null;

        $key = $param['key'] ?? ($param['id'] ?? null);
        $this->viewId = (is_numeric($key) && (int) $key > 0) ? (int) $key : null;

        $this->setDatabase('permission');          // defines the database
        $this->setActiveRecord('Patient');          // defines the active record
        $this->setUseToast(true);

        // modo leitura: o tutor vem do próprio paciente
        $tutor_name = null;
        try
        {
            TTransaction::open('permission');
            $service = $this->buildPatientService();

            if ($this->viewId !== null)
            {
                $this->viewPatient = $service->findById($this->viewId);
                if ($this->viewPatient !== null)
                {
                    $this->tutor_id = (int) $this->viewPatient->tutorId;
                }
            }

            if ($this->tutor_id !== null)
            {
                $tutor_service = new \CentralVet\Application\TutorService(
                    new \CentralVet\Persistence\TutorRepository(self::resolveTenantContext(), TTransaction::get())
                );
                $tutor = $tutor_service->findById($this->tutor_id);
                $tutor_name = $tutor !== null ? $tutor->fullName : null;
            }

            TTransaction::close();
        }
        catch (Exception $e)
        {
            // sem tenant/tutor resolvido: a tela abre e onSave/onEdit tratam o erro
            TTransaction::rollback();
        }

        // creates the form
        $this->form = new BootstrapFormBuilder('form_Patient');
        $this->form->enableClientValidation();

        // create the form fields
        if ($this->tutor_id !== null)
        {
            // tutor fixado pela URL (ou pelo paciente aberto): só exibe o nome
            $tutor_field = new THidden('tutor_id');
            $tutor_field->setValue($this->tutor_id);
            $tutor_label = new TEntry('tutor_name');
            $tutor_label->setEditable(FALSE);
            $tutor_label->setValue($tutor_name ?? ('#' . $this->tutor_id));
        }
        else
        {
            // sem tutor na URL: busca de Tutor restrita ao tenant da sessão
            try
            {
                $tenant_id = self::resolveTenantContext()->tenantId();
            }
            catch (\CentralVet\Tenancy\Exception\MissingTenantContext $e)
            {
                $tenant_id = -1;
            }

            $tenant_criteria = new TCriteria;
            $tenant_criteria->add(new TFilter('tenant_id', '=', $tenant_id));

            $tutor_field = new TDBUniqueSearch('tutor_id', 'permission', 'Tutor', 'id', 'full_name', 'full_name', $tenant_criteria);
            $tutor_field->setMinLength(1);
            $tutor_field->addValidation( _t('Tutor'), new TRequiredValidator );
            $tutor_label = null;
        }

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

        // add the fields (pares rótulo/campo em 2 colunas)
        if ($tutor_label !== null)
        {
            $this->form->addFields( [new TLabel(_t('Tutor'))], [$tutor_label, $tutor_field], [new TLabel(_t('Name'))], [$name] );
        }
        else
        {
            $this->form->addFields( [new TLabel(_t('Tutor'))], [$tutor_field], [new TLabel(_t('Name'))], [$name] );
        }
        $this->form->addFields( [new TLabel(_t('Species'))], [$species], [new TLabel(_t('Breed'))], [$breed] );
        $this->form->addFields( [new TLabel(_t('Sex'))], [$sex], [new TLabel(_t('Birth date'))], [$birth_date] );
        $this->form->addFields( [new TLabel(_t('Weight (kg)'))], [$weight_kg], [new TLabel(_t('Color'))], [$color] );
        $this->form->addFields( [new TLabel(_t('Notes'))], [$notes] );

        $name->addValidation( _t('Name'), new TRequiredValidator );
        $species->addValidation( _t('Species'), new TRequiredValidator );
        $notes->setSize('100%', 80);

        $back = $this->tutor_id !== null
            ? ['label' => '', 'icon' => 'fa:arrow-left', 'action' => new TAction(['PatientList', 'onReload'], ['tutor_id' => $this->tutor_id])]
            : ['label' => '', 'icon' => 'fa:arrow-left', 'action' => new TAction(['GlobalSearchController', 'onSearch'], ['query' => TSession::getValue('GlobalSearchController_query')->query ?? ''])];

        if ($this->viewId === null)
        {
            // create the form actions
            $btn = $this->form->addAction(_t('Save'), new TAction(array($this, 'onSave')), 'fa:check');
            $btn->class = 'btn btn-sm btn-primary';
            $this->form->addActionLink(_t('Clear'), new TAction(array($this, 'onEdit'), ['tutor_id' => $this->tutor_id]), 'fa:eraser');

            $header = CvPage::header(_t('New patient'), $tutor_name, [$back]);
        }
        else
        {
            foreach ([$name, $species, $breed, $sex, $birth_date, $weight_kg, $color, $notes] as $field)
            {
                $field->setEditable(FALSE);
            }

            $actions = [$back];
            if ($this->tutor_id !== null)
            {
                $actions[] = ['label' => _t('New patient'), 'icon' => 'fa:plus', 'class' => 'btn btn-primary', 'action' => new TAction([__CLASS__, 'onEdit'], ['tutor_id' => $this->tutor_id])];
            }

            $header = CvPage::header(_t('Patient'), $tutor_name, $actions);
        }

        CvForm::decorate($this->form, 2);

        // vertical box container
        $container = new TVBox;
        $container->style = 'width: 100%';
        $container->add($header);
        $container->add($this->form);

        parent::add($container);
    }

    /**
     * method onEdit()
     * Without key: clears the form and re-applies the tutor_id received in
     * the querystring (new patient). With key/id: shows the patient loaded
     * by PatientService::findById() (tenant-scoped) read-only — there is no
     * update use case in PatientService.
     */
    public function onEdit($param)
    {
        $this->form->clear(true);

        if ($this->viewId === null)
        {
            if ($this->tutor_id !== null)
            {
                $data = new stdClass;
                $data->tutor_id = $this->tutor_id;
                $this->form->setData($data);
            }
            return;
        }

        if ($this->viewPatient === null)
        {
            new TMessage('error', _t('Record not found'));
            return;
        }

        $patient = $this->viewPatient;
        $birth = $patient->birthDate ? DateTime::createFromFormat('Y-m-d', substr($patient->birthDate, 0, 10)) : false;

        $this->form->setData((object) [
            'tutor_id'   => $patient->tutorId,
            'name'       => $patient->name,
            'species'    => $patient->species,
            'breed'      => $patient->breed,
            'sex'        => $patient->sex,
            'birth_date' => $birth ? $birth->format('d/m/Y') : null,
            'weight_kg'  => $patient->weightKg,
            'color'      => $patient->color,
            'notes'      => $patient->notes,
        ]);
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

            // reabre o registro salvo em página cheia
            $open = new TAction([__CLASS__, 'onEdit'], ['key' => $patient->id, 'tutor_id' => $tutor_id]);

            if (!empty($this->useToast))
            {
                TToast::show('info', _t('Record saved'));
                AdiantiCoreApplication::loadPageURL( $open->serialize() );
            }
            else
            {
                new TMessage('info', _t('Record saved'), $open);
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
