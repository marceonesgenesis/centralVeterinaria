<?php
/**
 * VaccineCatalogForm
 *
 * Registration screen for the vaccine catalog (T-08). Follows the same
 * TStandardForm pattern used by ServiceForm (Fase 1) / ExamCatalogForm
 * (T-07), but contains no business rule of its own: creation is delegated
 * entirely to CentralVet\Application\VaccineCatalogService (T-05) — name
 * validation and the "active by default" / initial stock rules all live
 * there.
 *
 * PENDING: this screen depends on the `vaccine_catalog_item` table created
 * by the not-yet-applied migration
 * src/app/database/migrations/20260922_0004_phase3_prescription_exam_vaccine.sql
 * (T-01). Validated only with `php -l` / `new VaccineCatalogForm()` (no
 * fatal error) until that migration is applied — any database failure while
 * actually saving is caught and shown as a TMessage, never a fatal error.
 *
 * @version    1.0
 * @package    control
 * @subpackage clinic
 */
class VaccineCatalogForm extends TStandardForm
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

        $this->setDatabase('permission');              // defines the database
        $this->setActiveRecord('VaccineCatalogItem');   // defines the active record
        $this->setAfterSaveAction( new TAction(['VaccineCatalogList', 'onReload']) );
        $this->setUseToast(true);

        // creates the form
        $this->form = new BootstrapFormBuilder('form_VaccineCatalogItem');
        $this->form->setFormTitle(_t('Vaccine'));
        $this->form->enableClientValidation();

        // create the form fields
        $id = new TEntry('id');
        $name = new TEntry('name');
        $manufacturer = new TEntry('manufacturer');
        $stock_quantity = new TEntry('stock_quantity');
        $active = new TCombo('active');
        $active->addItems([1 => _t('Active'), 0 => _t('Inactive')]);

        // add the fields
        $this->form->addFields( [new TLabel('Id')] );
        $this->form->addFields( [$id] );
        $this->form->addFields( [new TLabel(_t('Name'))] );
        $this->form->addFields( [$name] );
        $this->form->addFields( [new TLabel(_t('Manufacturer'))] );
        $this->form->addFields( [$manufacturer] );
        $this->form->addFields( [new TLabel(_t('Stock quantity'))] );
        $this->form->addFields( [$stock_quantity] );
        $this->form->addFields( [new TLabel(_t('Status'))] );
        $this->form->addFields( [$active] );

        $id->setEditable(FALSE);
        $id->setSize('30%');
        $name->setSize('100%');
        $manufacturer->setSize('100%');
        $stock_quantity->setSize('30%');
        $stock_quantity->setNumericMask(0, '', '');
        $active->setSize('100%');
        $active->setValue(1);

        $name->addValidation( _t('Name'), new TRequiredValidator );

        // create the form actions
        $btn = $this->form->addAction(_t('Save'), new TAction(array($this, 'onSave')), 'fa:check');
        $btn->class = 'btn btn-sm btn-primary';
        $this->form->addActionLink(_t('Clear'),  new TAction(array($this, 'onEdit')), 'fa:eraser red');

        $this->form->addHeaderActionLink(_t('Close'), new TAction([$this, 'onClose']), 'fa:times red');

        // vertical box container
        $container = new TVBox;
        $container->style = 'width: 100%';

        $pageHeader = new TElement('header');
        $pageHeader->class = 'cv-page-header';
        $pageHeaderTitleWrap = new TElement('div');
        $pageHeaderTitle = new TElement('h1');
        $pageHeaderTitle->class = 'cv-page-title';
        $pageHeaderTitle->add(_t('Vaccine'));
        $pageHeaderTitleWrap->add($pageHeaderTitle);
        $pageHeader->add($pageHeaderTitleWrap);
        $container->add($pageHeader);

        $container->add($this->form);

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
     * Persists the catalog entry through VaccineCatalogService::create().
     * No validation/decision is made here: everything (required fields,
     * defaults, initial stock) is enforced inside the Application service.
     */
    public function onSave($param = null)
    {
        try
        {
            $data = $this->form->getData();

            $this->form->validate();

            // open a transaction with database
            TTransaction::open('permission');

            $catalog = self::buildVaccineCatalogService();

            $item = $catalog->create([
                'name'           => $data->name,
                'manufacturer'   => $data->manufacturer,
                'stock_quantity' => $data->stock_quantity,
            ]);

            $data->id = $item->id();

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
        catch (\CentralVet\Authorization\Exception\AuthorizationDenied $e)
        {
            $this->form->setData($data ?? null);
            new TMessage('error', _t('You are not allowed to perform this action'));
            TTransaction::rollback();
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
     * Builds the Application service with its dependencies, following the
     * same tenant-scoping convention as ServiceForm::buildServiceCatalogService().
     * Requires an already-open TTransaction('permission') connection.
     */
    private static function buildVaccineCatalogService()
    {
        $tenant_context = self::resolveTenantContext();
        $connection = TTransaction::get();

        $repository = new \CentralVet\Persistence\VaccineCatalogRepository($tenant_context, $connection);

        return new \CentralVet\Application\VaccineCatalogService($repository, $tenant_context);
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
