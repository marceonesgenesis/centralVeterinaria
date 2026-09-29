<?php
/**
 * ServiceForm
 *
 * Registration screen for the service catalog (T-11). Follows the
 * TStandardForm pattern used by SystemUnitForm, but contains no business
 * rule of its own: creation is delegated entirely to
 * CentralVet\Application\ServiceCatalogService (T-06) — name uniqueness,
 * price/duration validation and the "active by default" rule all live
 * there.
 *
 * @version    1.0
 * @package    control
 * @subpackage clinic
 */
class ServiceForm extends TStandardForm
{
    protected $form; // form

    /**
     * Class constructor
     * Creates the page and the registration form
     */
    function __construct()
    {
        parent::__construct();

        parent::setTargetContainer('adianti_right_panel');

        $this->setDatabase('permission');           // defines the database
        $this->setActiveRecord('Service');           // defines the active record
        $this->setAfterSaveAction( new TAction(['ServiceList', 'onReload']) );
        $this->setUseToast(true);

        // creates the form
        $this->form = new BootstrapFormBuilder('form_Service');
        $this->form->setFormTitle(_t('Service'));
        $this->form->enableClientValidation();

        // create the form fields
        $id = new TEntry('id');
        $name = new TEntry('name');
        $category = new TEntry('category');
        $duration_minutes = new TEntry('duration_minutes');
        $price = new TEntry('price');
        $active = new TCombo('active');
        $active->addItems([1 => _t('Active'), 0 => _t('Inactive')]);

        // add the fields
        $this->form->addFields( [new TLabel('Id')] );
        $this->form->addFields( [$id] );
        $this->form->addFields( [new TLabel(_t('Name'))] );
        $this->form->addFields( [$name] );
        $this->form->addFields( [new TLabel(_t('Category'))] );
        $this->form->addFields( [$category] );
        $this->form->addFields( [new TLabel(_t('Duration (minutes)'))] );
        $this->form->addFields( [$duration_minutes] );
        $this->form->addFields( [new TLabel(_t('Price'))] );
        $this->form->addFields( [$price] );
        $this->form->addFields( [new TLabel(_t('Status'))] );
        $this->form->addFields( [$active] );

        $id->setEditable(FALSE);
        $id->setSize('30%');
        $name->setSize('100%');
        $category->setSize('100%');
        $duration_minutes->setSize('30%');
        $duration_minutes->setNumericMask(0, '', '');
        $price->setSize('30%');
        $price->setNumericMask(2, ',', '.');
        $active->setSize('100%');
        $active->setValue(1);

        $name->addValidation( _t('Name'), new TRequiredValidator );
        $duration_minutes->addValidation( _t('Duration (minutes)'), new TRequiredValidator );
        $price->addValidation( _t('Price'), new TRequiredValidator );

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
        $page_header_title->add(_t('Service'));
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
     * method onSave()
     * Persists the catalog entry through ServiceCatalogService::create().
     * No validation/decision is made here: everything (required fields,
     * uniqueness, defaults) is enforced inside the Application service.
     */
    public function onSave($param = null)
    {
        try
        {
            $data = $this->form->getData();

            $this->form->validate();

            // open a transaction with database
            TTransaction::open('permission');

            $catalog = self::buildServiceCatalogService();

            $service = $catalog->create([
                'name'              => $data->name,
                'category'          => $data->category,
                'duration_minutes'  => $data->duration_minutes,
                'price_cents'       => self::toCents($data->price),
            ]);

            $data->id = $service->id();

            // fill the form with the active record data
            $this->form->setData($data);

            // close the transaction
            TTransaction::close();

            // shows the success message
            if (!empty($this->useToast))
            {
                TToast::show('info', _t('Record saved'));
                AdiantiCoreApplication::loadPageURL( $this->afterSaveAction->serialize() );
            }
            else
            {
                new TMessage('info', _t('Record saved'), $this->afterSaveAction);
            }

            return $data;
        }
        catch (Exception $e) // in case of exception
        {
            // fill the form with the active record data
            $this->form->setData($data ?? null);

            // shows the exception error message
            new TMessage('error', $e->getMessage());

            // undo all pending operations
            TTransaction::rollback();
        }
    }

    /**
     * Converts a "1.234,56"-style amount typed by the user into integer
     * cents, matching ServiceCatalogService::create()'s price_cents input.
     */
    private static function toCents($amount)
    {
        $normalized = str_replace('.', '', (string) $amount);
        $normalized = str_replace(',', '.', $normalized);

        return (int) round(((float) $normalized) * 100);
    }

    /**
     * Builds the Application service with its dependencies, following the
     * same tenant-scoping convention as SystemUnitForm::resolveTenantContext().
     * Requires an already-open TTransaction('permission') connection.
     */
    private static function buildServiceCatalogService()
    {
        $tenant_context = self::resolveTenantContext();
        $connection = TTransaction::get();

        $repository = new \CentralVet\Persistence\ServiceRepository($tenant_context, $connection);

        return new \CentralVet\Application\ServiceCatalogService($repository, $tenant_context);
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
