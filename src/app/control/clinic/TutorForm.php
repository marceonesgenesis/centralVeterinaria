<?php
/**
 * TutorForm
 *
 * Full-page quick registration of a new tutor (kit Cv*: CvPage header with
 * "back" to TutorList, CvForm 2-column grid). With key/id in the URL the
 * saved tutor is reopened read-only (TutorService has no update use case).
 *
 * This controller only assembles the UI and forwards the submitted data to
 * CentralVet\Application\TutorService::create() (T-04). It carries no
 * validation/decision rule of its own — required-field marking is plain
 * Adianti form wiring (TRequiredValidator), and every business rule
 * (uniqueness, required data, normalization) lives in TutorService. The
 * controller never touches CentralVet\Persistence or CentralVet\Domain
 * directly beyond wiring the repository instance the service needs.
 *
 * @package    control
 * @subpackage clinic
 */
class TutorForm extends TStandardForm
{
    protected $form; // form

    /** @var int|null tutor aberto em modo leitura (key/id na URL) */
    protected $viewId = null;

    /**
     * Class constructor
     * Creates the page: quick registration form (new) or the tutor's record
     * opened read-only (key/id in the URL — TutorService has no update use case).
     */
    public function __construct($param = null)
    {
        parent::__construct();

        $this->setUseToast(true);

        $key = $param['key'] ?? ($param['id'] ?? null);
        $this->viewId = (is_numeric($key) && (int) $key > 0) ? (int) $key : null;

        // creates the form
        $this->form = new BootstrapFormBuilder('form_Tutor');
        $this->form->enableClientValidation();

        // create the form fields
        $full_name = new TEntry('full_name');
        $document = new TEntry('document');
        $phone = new TEntry('phone');
        $email = new TEntry('email');
        $address = new TEntry('address');

        // add the fields (pares rótulo/campo em 2 colunas)
        $this->form->addFields( [new TLabel(_t('Full name'))], [$full_name], [new TLabel(_t('Document (CPF/CNPJ)'))], [$document] );
        $this->form->addFields( [new TLabel(_t('Phone'))], [$phone], [new TLabel(_t('Email'))], [$email] );
        $this->form->addFields( [new TLabel(_t('Address'))], [$address] );

        $full_name->addValidation( _t('Full name'), new TRequiredValidator );
        $phone->addValidation( _t('Phone'), new TRequiredValidator );

        $back = ['label' => '', 'icon' => 'fa:arrow-left', 'action' => new TAction(['TutorList', 'onReload'])];

        if ($this->viewId === null)
        {
            // create the form actions
            $btn = $this->form->addAction(_t('Save'), new TAction(array($this, 'onSave')), 'fa:check');
            $btn->class = 'btn btn-sm btn-primary';
            $this->form->addActionLink(_t('Clear'), new TAction(array($this, 'onEdit')), 'fa:eraser');

            $header = CvPage::header(_t('New tutor'), null, [$back]);
        }
        else
        {
            foreach ([$full_name, $document, $phone, $email, $address] as $field)
            {
                $field->setEditable(FALSE);
            }

            $header = CvPage::header(_t('Tutor'), null, [
                $back,
                ['label' => _t('Patients'), 'icon' => 'fa:paw', 'action' => new TAction(['PatientList', 'onReload'], ['tutor_id' => $this->viewId])],
                ['label' => _t('New patient'), 'icon' => 'fa:plus', 'class' => 'btn btn-primary', 'action' => new TAction(['PatientForm', 'onEdit'], ['tutor_id' => $this->viewId])],
            ]);
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
     * Without key: clears the form for a fresh registration. With key/id:
     * loads the tutor through TutorService::findById() (tenant-scoped by the
     * repository) and shows it read-only — there is no update use case.
     */
    public function onEdit($param)
    {
        $this->form->clear();

        if ($this->viewId === null)
        {
            return;
        }

        try
        {
            $tenant_context = self::resolveTenantContext();

            TTransaction::open('permission');

            $service = new \CentralVet\Application\TutorService(
                new \CentralVet\Persistence\TutorRepository($tenant_context, TTransaction::get())
            );

            $tutor = $service->findById($this->viewId);

            TTransaction::close();

            if ($tutor === null)
            {
                new TMessage('error', _t('Record not found'));
                return;
            }

            $this->form->setData((object) [
                'full_name' => $tutor->fullName,
                'document'  => $tutor->document,
                'phone'     => $tutor->phone,
                'email'     => $tutor->email,
                'address'   => $tutor->address,
            ]);
        }
        catch (\CentralVet\Tenancy\Exception\TenantBoundaryViolation | \CentralVet\Tenancy\Exception\MissingTenantContext $e)
        {
            TTransaction::rollback();
            new TMessage('error', _t('Record not found'));
        }
        catch (Exception $e)
        {
            TTransaction::rollback();
            new TMessage('error', $e->getMessage());
        }
    }

    /**
     * method onSave()
     * Collects the submitted data and delegates the whole registration
     * decision (required fields, duplicate document, persistence) to
     * TutorService::create(). No rule is re-implemented here.
     */
    public function onSave($param = null)
    {
        try
        {
            $data = $this->form->getData();
            $this->form->validate();

            $tenant_context = self::resolveTenantContext();

            TTransaction::open('permission');

            $service = new \CentralVet\Application\TutorService(
                new \CentralVet\Persistence\TutorRepository($tenant_context, TTransaction::get())
            );

            $tutor = $service->create([
                'tenant_id' => $tenant_context->tenantId(),
                'full_name' => $data->full_name ?? '',
                'phone'     => $data->phone ?? '',
                'document'  => $data->document ?? null,
                'email'     => $data->email ?? null,
                'address'   => $data->address ?? null,
            ]);

            TTransaction::close();

            $this->form->clear();

            // reabre o registro salvo em página cheia
            $open = new TAction([__CLASS__, 'onEdit'], ['key' => $tutor->id]);

            if (!empty($this->useToast))
            {
                TToast::show('info', _t('Record saved'));
                AdiantiCoreApplication::loadPageURL( $open->serialize() );
            }
            else
            {
                new TMessage('info', _t('Record saved'), $open);
            }
        }
        catch (\CentralVet\Tenancy\Exception\TenantBoundaryViolation | \CentralVet\Tenancy\Exception\MissingTenantContext $e)
        {
            TTransaction::rollback();
            new TMessage('error', _t('Record not found'));
        }
        catch (\InvalidArgumentException $e)
        {
            TTransaction::rollback();
            new TMessage('error', $e->getMessage());
        }
        catch (Exception $e) // in case of exception
        {
            new TMessage('error', $e->getMessage());
            TTransaction::rollback();
        }
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
