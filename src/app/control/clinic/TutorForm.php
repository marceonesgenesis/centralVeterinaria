<?php
/**
 * TutorForm
 *
 * Compact quick-registration card for a new tutor, matching mock 01
 * (https://claude.ai/artifact/A78u8yLNxCro1AYHRLDrcR): shown inside the
 * right panel next to TutorList's search results when no tutor is found.
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

    /**
     * Class constructor
     * Creates the page and the quick registration form
     */
    public function __construct()
    {
        parent::__construct();

        parent::setTargetContainer('adianti_right_panel');
        $this->setUseToast(true);
        $this->setAfterSaveAction( new TAction(['TutorList', 'onReload']) );

        // creates the form
        $this->form = new BootstrapFormBuilder('form_Tutor');
        $this->form->setFormTitle(_t('New tutor'));
        $this->form->enableClientValidation();

        // create the form fields
        $full_name = new TEntry('full_name');
        $document = new TEntry('document');
        $phone = new TEntry('phone');
        $email = new TEntry('email');
        $address = new TEntry('address');

        // add the fields
        $this->form->addFields( [new TLabel(_t('Full name'))] );
        $this->form->addFields( [$full_name] );
        $this->form->addFields( [new TLabel(_t('Document (CPF/CNPJ)'))] );
        $this->form->addFields( [$document] );
        $this->form->addFields( [new TLabel(_t('Phone'))] );
        $this->form->addFields( [$phone] );
        $this->form->addFields( [new TLabel(_t('Email'))] );
        $this->form->addFields( [$email] );
        $this->form->addFields( [new TLabel(_t('Address'))] );
        $this->form->addFields( [$address] );

        $full_name->setSize('100%');
        $full_name->addValidation( _t('Full name'), new TRequiredValidator );
        $document->setSize('100%');
        $phone->setSize('100%');
        $phone->addValidation( _t('Phone'), new TRequiredValidator );
        $email->setSize('100%');
        $address->setSize('100%');

        // create the form actions
        $btn = $this->form->addAction(_t('Save'), new TAction(array($this, 'onSave')), 'fa:check');
        $btn->class = 'btn btn-sm btn-primary';
        $this->form->addActionLink(_t('Clear'), new TAction(array($this, 'onEdit')), 'fa:eraser red');

        $this->form->addHeaderActionLink(_t('Close'), new TAction([$this, 'onClose']), 'fa:times red');

        // page header (design system: .cv-page-header/.cv-page-title, T-02)
        $page_header = new TElement('header');
        $page_header->class = 'cv-page-header';
        $page_header_titlebox = new TElement('div');
        $page_header_title = new TElement('h1');
        $page_header_title->class = 'cv-page-title';
        $page_header_title->add(_t('New tutor'));
        $page_header_titlebox->add($page_header_title);
        $page_header->add($page_header_titlebox);

        // vertical box container
        $container = new TVBox;
        $container->style = 'width: 100%';
        $container->class = 'cv-section';
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
     * There is no update flow in this mock (only search + quick create), so
     * this action only clears the form for a fresh registration.
     */
    public function onEdit($param)
    {
        $this->form->clear();
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

            $service->create([
                'tenant_id' => $tenant_context->tenantId(),
                'full_name' => $data->full_name ?? '',
                'phone'     => $data->phone ?? '',
                'document'  => $data->document ?? null,
                'email'     => $data->email ?? null,
                'address'   => $data->address ?? null,
            ]);

            TTransaction::close();

            $this->form->clear();

            if (!empty($this->useToast))
            {
                TToast::show('info', _t('Record saved'));
                AdiantiCoreApplication::loadPageURL( $this->afterSaveAction->serialize() );
            }
            else
            {
                new TMessage('info', _t('Record saved'), $this->afterSaveAction);
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
