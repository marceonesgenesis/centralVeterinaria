<?php
/**
 * ProcedureCatalogForm
 *
 * Registration screen for the procedure catalog (T-08). Follows the same
 * TStandardForm pattern used by ServiceForm (Fase 1) / VaccineCatalogForm
 * (T-08 da Fase 3), but contains no business rule of its own: creation is
 * delegated entirely to CentralVet\Application\ProcedureCatalogService
 * (T-04) — name/price/duration validation and the "active by default" rule
 * all live there.
 *
 * The `procedure_catalog_item` table was created by migration
 * src/app/database/migrations/20260924_0005_phase4_procedure_stock_sale.sql,
 * already applied (Fase 4) — any database failure while saving is still
 * caught and shown as a TMessage, never a fatal error.
 *
 * @version    1.0
 * @package    control
 * @subpackage clinic
 */
class ProcedureCatalogForm extends TStandardForm
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

        $this->setDatabase('permission');                // defines the database
        $this->setActiveRecord('ProcedureCatalogItem');   // defines the active record
        $this->setAfterSaveAction( new TAction(['ProcedureCatalogList', 'onReload']) );
        $this->setUseToast(true);

        // creates the form
        $this->form = new BootstrapFormBuilder('form_ProcedureCatalogItem');
        $this->form->setFormTitle(_t('Procedure'));
        $this->form->enableClientValidation();

        // create the form fields
        $id = new TEntry('id');
        $name = new TEntry('name');
        $price = new TEntry('price');
        $duration_minutes = new TEntry('duration_minutes');
        $preparation_text = new TText('preparation_text');
        $active = new TCombo('active');
        $active->addItems([1 => _t('Active'), 0 => _t('Inactive')]);

        // add the fields
        $this->form->addFields( [new TLabel('Id')] );
        $this->form->addFields( [$id] );
        $this->form->addFields( [new TLabel(_t('Name'))] );
        $this->form->addFields( [$name] );
        $this->form->addFields( [new TLabel(_t('Price'))] );
        $this->form->addFields( [$price] );
        $this->form->addFields( [new TLabel(_t('Duration (minutes)'))] );
        $this->form->addFields( [$duration_minutes] );
        $this->form->addFields( [new TLabel(_t('Preparation notes'))] );
        $this->form->addFields( [$preparation_text] );
        $this->form->addFields( [new TLabel(_t('Status'))] );
        $this->form->addFields( [$active] );

        $id->setEditable(FALSE);
        $id->setSize('30%');
        $name->setSize('100%');
        $price->setSize('30%');
        $price->setNumericMask(2, ',', '.');
        $duration_minutes->setSize('30%');
        $duration_minutes->setNumericMask(0, '', '');
        $preparation_text->setSize('100%', 80);
        $active->setSize('100%');
        $active->setValue(1);

        $name->addValidation( _t('Name'), new TRequiredValidator );
        $price->addValidation( _t('Price'), new TRequiredValidator );

        // create the form actions
        $btn = $this->form->addAction(_t('Save'), new TAction(array($this, 'onSave')), 'fa:check');
        $btn->class = 'btn btn-sm btn-primary';
        $this->form->addActionLink(_t('Clear'),  new TAction(array($this, 'onEdit')), 'fa:eraser red');

        $this->form->addHeaderActionLink(_t('Close'), new TAction([$this, 'onClose']), 'fa:times red');

        // page header (design system: .cv-page-header/.cv-page-title, T-04)
        $header = new TElement('header');
        $header->class = 'cv-page-header';

        $header_text = new TElement('div');
        $header_title = new TElement('h1');
        $header_title->class = 'cv-page-title';
        $header_title->add(_t('Procedure'));
        $header_text->add($header_title);

        $header->add($header_text);

        // vertical box container
        $container = new TVBox;
        $container->style = 'width: 100%';
        $container->add($header);
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
     * Persists the catalog entry through ProcedureCatalogService::create().
     * No validation/decision is made here: everything (required fields,
     * defaults, price/duration rules) is enforced inside the Application
     * service / Domain entity.
     */
    public function onSave($param = null)
    {
        try
        {
            $data = $this->form->getData();

            $this->form->validate();

            // open a transaction with database
            TTransaction::open('permission');

            $catalog = self::buildProcedureCatalogService();

            $duration_minutes = ($data->duration_minutes !== null && $data->duration_minutes !== '')
                ? (int) $data->duration_minutes
                : null;

            $preparation_text = ($data->preparation_text !== null && trim((string) $data->preparation_text) !== '')
                ? $data->preparation_text
                : null;

            $item = $catalog->create(
                $data->name,
                self::toCents($data->price),
                $duration_minutes,
                $preparation_text
            );

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
     * Converts a "1.234,56"-style amount typed by the user into integer
     * cents, matching ProcedureCatalogService::create()'s priceCents input.
     */
    private static function toCents($amount)
    {
        $normalized = str_replace('.', '', (string) $amount);
        $normalized = str_replace(',', '.', $normalized);

        return (int) round(((float) $normalized) * 100);
    }

    /**
     * Builds the Application service with its dependencies, following the
     * same tenant-scoping convention as ServiceForm::buildServiceCatalogService().
     * Requires an already-open TTransaction('permission') connection.
     */
    private static function buildProcedureCatalogService()
    {
        $tenant_context = self::resolveTenantContext();
        $connection = TTransaction::get();

        $catalog_repository = new \CentralVet\Persistence\ProcedureCatalogRepository($tenant_context, $connection);
        $inputs_repository  = new \CentralVet\Persistence\ProcedureCatalogItemInputRepository($tenant_context, $connection);

        return new \CentralVet\Application\ProcedureCatalogService($catalog_repository, $inputs_repository, $tenant_context);
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
