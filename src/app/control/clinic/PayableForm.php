<?php
/**
 * PayableForm
 *
 * Registration screen for a Payable (T-09): a vendor/operational bill owed
 * by the tenant at the active system unit. Follows the TStandardForm
 * pattern used by ServiceForm (Fase 1) / ProcedureCatalogForm (T-08 da Fase
 * 4), but contains no business rule of its own: creation is delegated
 * entirely to CentralVet\Application\PayableService::create() (T-05) —
 * required fields, amount validation and the "created open" status all
 * live there.
 *
 * onSave() is fully overridden (never calls the parent TStandardForm
 * onSave()/setActiveRecord()-driven flow), so CentralVet\Persistence\
 * PayableRepository/CentralVet\Domain\Payable are never touched directly
 * from here.
 *
 * @version    1.0
 * @package    control
 * @subpackage clinic
 */
class PayableForm extends TStandardForm
{
    protected $form; // form

    /**
     * Class constructor
     * Creates the page and the registration form
     */
    function __construct()
    {
        parent::__construct();

        $this->setDatabase('permission');           // defines the database
        $this->setActiveRecord('Payable');           // defines the active record
        $this->setAfterSaveAction( new TAction(['PayableList', 'onReload']) );
        $this->setUseToast(true);

        // creates the form
        $this->form = new BootstrapFormBuilder('form_Payable');
        $this->form->enableClientValidation();

        // create the form fields
        $id = new TEntry('id');
        $description_text = new TEntry('description_text');
        $category = new TEntry('category');
        $amount = new TEntry('amount');
        $due_date = new TDate('due_date');

        $due_date->setMask('dd/mm/yyyy');
        $due_date->setDatabaseMask('yyyy-mm-dd');

        CvForm::decorate($this->form, 2);

        // add the fields (pares rótulo/campo em 2 colunas, rótulo acima)
        $this->form->addFields( [new TLabel(_t('Description'))], [$description_text] );
        $this->form->addFields( [new TLabel(_t('Category'))], [$category], [new TLabel(_t('Amount'))], [$amount] );
        $this->form->addFields( [new TLabel(_t('Due date'))], [$due_date], [new TLabel('Id')], [$id] );

        $id->setEditable(FALSE);
        $amount->setNumericMask(2, ',', '.');

        $description_text->addValidation( _t('Description'), new TRequiredValidator );
        $category->addValidation( _t('Category'), new TRequiredValidator );
        $amount->addValidation( _t('Amount'), new TRequiredValidator );

        // create the form actions
        $btn = $this->form->addAction(_t('Save'), new TAction(array($this, 'onSave')), 'fa:check');
        $btn->class = 'btn btn-primary';
        $this->form->addActionLink(_t('Clear'),  new TAction(array($this, 'onEdit')), 'fa:eraser');

        // página cheia: cabeçalho do kit com voltar para a lista
        $container = new TVBox;
        $container->style = 'width: 100%';
        $container->add(CvPage::header(_t('Payable'), _t('Financial'), [
            ['icon' => 'fa:arrow-left', 'href' => 'index.php?class=PayableList'],
        ]));
        $container->add($this->form);

        parent::add($container);
    }

    /**
     * on close
     */
    public static function onClose($param)
    {
        AdiantiCoreApplication::loadPage('PayableList');
    }

    /**
     * method onSave()
     * Persists the payable through PayableService::create(). No validation/
     * decision is made here: required fields and amount rules are enforced
     * inside the Application service; unit-scope authorization is enforced
     * inside PayableService::create() itself (fail-closed, before any
     * write).
     */
    public function onSave($param = null)
    {
        try
        {
            $data = $this->form->getData();

            $this->form->validate();

            // open a transaction with database
            TTransaction::open('permission');

            $tenant_context = self::resolveTenantContext();
            $service = self::buildPayableService($tenant_context);

            $payable = $service->create(
                $tenant_context->requireUnitId(),
                (string) $data->description_text,
                (string) $data->category,
                self::toCents($data->amount),
                !empty($data->due_date) ? (string) $data->due_date : null,
                $tenant_context->userId(),
                __CLASS__ . '::' . __FUNCTION__,
            );

            $data->id = $payable->id();

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
            TTransaction::rollback();
            new TMessage('error', _t('You are not allowed to perform this action'));
        }
        catch (\CentralVet\Tenancy\Exception\MissingTenantContext $e)
        {
            $this->form->setData($data ?? null);
            TTransaction::rollback();
            new TMessage('error', _t('An authenticated session with a tenant is required'));
        }
        catch (InvalidArgumentException $e)
        {
            $this->form->setData($data ?? null);
            TTransaction::rollback();
            new TMessage('error', $e->getMessage());
        }
        catch (Exception $e) // catch-all: never let a fatal error reach the screen
        {
            $this->form->setData($data ?? null);
            TTransaction::rollback();
            new TMessage('error', $e->getMessage());
        }
    }

    /**
     * Converts a "1.234,56"-style amount typed by the user into integer
     * cents, matching PayableService::create()'s amount_cents input.
     */
    private static function toCents($amount)
    {
        $normalized = str_replace('.', '', (string) $amount);
        $normalized = str_replace(',', '.', $normalized);

        return (int) round(((float) $normalized) * 100);
    }

    /**
     * Wires an Application-layer PayableService instance, consistent with
     * the wiring already prepared by AppointmentForm::buildAppointmentService()
     * (T-07): the real RbacAuthorizationService backed by
     * AdiantiProgramPermissionProvider and PdoAuditLogWriter against this
     * same 'permission' connection, so every create() call is both
     * unit-scope-checked and audited to `audit_log`. The same authorization
     * instance is reused to build the FinancialEntryService dependency
     * PayableService::pay() needs internally (T-05's own wiring).
     * Requires an already-open TTransaction('permission') connection.
     */
    private static function buildPayableService(\CentralVet\Tenancy\TenantContext $context): \CentralVet\Application\PayableService
    {
        $connection = TTransaction::get();

        $payables = new \CentralVet\Persistence\PayableRepository($context, $connection);
        $entries = new \CentralVet\Persistence\FinancialEntryRepository($context, $connection);

        $authorization = new \CentralVet\Authorization\RbacAuthorizationService(
            new \CentralVet\Authorization\AdiantiProgramPermissionProvider(new \CentralVet\Tenancy\AdiantiSessionContextSource()),
            new \CentralVet\Audit\PdoAuditLogWriter($connection),
        );

        $financial_entries = new \CentralVet\Application\FinancialEntryService($entries, $authorization, $context);

        return new \CentralVet\Application\PayableService($payables, $financial_entries, $authorization, $context);
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
