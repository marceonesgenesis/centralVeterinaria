<?php
/**
 * FinancialEntryForm
 *
 * Registration screen for a manual FinancialEntry (T-09): one append-only
 * ledger row (income or expense) for the active system unit, entered by
 * hand instead of being written as the side effect of another write (e.g.
 * CentralVet\Application\PayableService::pay() or, in T-06,
 * CentralVet\Application\PaymentService::register()). Follows the
 * TStandardForm pattern used by ServiceForm (Fase 1), but contains no
 * business rule of its own: creation is delegated entirely to
 * CentralVet\Application\FinancialEntryService::record() (T-05) —
 * entry_type/amount validation lives there. referenceType/referenceId are
 * always passed as null, per this task's own Interface spec: a manual
 * entry never points back at another aggregate.
 *
 * onSave() is fully overridden (never calls the parent TStandardForm
 * onSave()/setActiveRecord()-driven flow), so
 * CentralVet\Persistence\FinancialEntryRepository/CentralVet\Domain\
 * FinancialEntry are never touched directly from here. There is no
 * FinancialEntry TRecord model backing setActiveRecord() here (unlike
 * PayableForm/Payable.php): 'FinancialEntry' is only used as the string
 * TStandardForm::onEdit()/onSave() checks are-not-empty against — since
 * entries are append-only there is no edit-by-key flow ('key' param) that
 * would ever instantiate it.
 *
 * @version    1.0
 * @package    control
 * @subpackage clinic
 */
class FinancialEntryForm extends TStandardForm
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
        // No setActiveRecord() call: AdiantiStandardControlTrait::
        // setActiveRecord() requires class_exists($activeRecord) and throws
        // otherwise — there is no CentralVet\Domain\FinancialEntry-backed
        // TRecord model (see class docblock: onSave() below talks to
        // FinancialEntryService directly), so calling it here was a fatal
        // dead end on every load, never exercised by the fake-repository
        // unit tests.
        $this->setAfterSaveAction( new TAction(['FinancialEntryList', 'onReload']) );
        $this->setUseToast(true);

        // creates the form
        $this->form = new BootstrapFormBuilder('form_FinancialEntry');
        $this->form->setFormTitle(_t('Financial entry'));
        $this->form->enableClientValidation();

        // create the form fields
        $id = new TEntry('id');
        $entry_type = new TCombo('entry_type');
        $entry_type->addItems([
            \CentralVet\Domain\FinancialEntry::TYPE_INCOME  => _t('Income'),
            \CentralVet\Domain\FinancialEntry::TYPE_EXPENSE => _t('Expense'),
        ]);
        $category = new TEntry('category');
        $amount = new TEntry('amount');

        // add the fields
        $this->form->addFields( [new TLabel('Id')] );
        $this->form->addFields( [$id] );
        $this->form->addFields( [new TLabel(_t('Type'))] );
        $this->form->addFields( [$entry_type] );
        $this->form->addFields( [new TLabel(_t('Category'))] );
        $this->form->addFields( [$category] );
        $this->form->addFields( [new TLabel(_t('Amount'))] );
        $this->form->addFields( [$amount] );

        $id->setEditable(FALSE);
        $id->setSize('30%');
        $entry_type->setSize('100%');
        $category->setSize('100%');
        $amount->setSize('30%');
        $amount->setNumericMask(2, ',', '.');

        $entry_type->addValidation( _t('Type'), new TRequiredValidator );
        $category->addValidation( _t('Category'), new TRequiredValidator );
        $amount->addValidation( _t('Amount'), new TRequiredValidator );

        // create the form actions
        $btn = $this->form->addAction(_t('Save'), new TAction(array($this, 'onSave')), 'fa:check');
        $btn->class = 'btn btn-sm btn-primary';
        $this->form->addActionLink(_t('Clear'),  new TAction(array($this, 'onEdit')), 'fa:eraser red');

        $this->form->addHeaderActionLink(_t('Close'), new TAction([$this, 'onClose']), 'fa:times red');

        // page header (design system: .cv-page-header / .cv-page-title,
        // mirrors src/design-system.html)
        $page_header = new TElement('header');
        $page_header->class = 'cv-page-header';

        $page_header_content = new TElement('div');

        $page_header_title = new TElement('h1');
        $page_header_title->class = 'cv-page-title';
        $page_header_title->add(_t('Financial entry'));

        $page_header_content->add($page_header_title);
        $page_header->add($page_header_content);

        // vertical box container
        $container = new TVBox;
        $container->style = 'width: 100%';
        $container->add($page_header);
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
     * Persists the entry through FinancialEntryService::record(), always
     * with referenceType=null/referenceId=null (manual entry, per this
     * task's own Interface spec), then redirects to FinancialEntryList
     * (criterio de aceite).
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
            $service = self::buildFinancialEntryService($tenant_context);

            $entry = $service->record(
                $tenant_context->requireUnitId(),
                (string) $data->entry_type,
                (string) $data->category,
                self::toCents($data->amount),
                null,
                null,
                $tenant_context->userId(),
                __CLASS__ . '::' . __FUNCTION__,
            );

            $data->id = $entry->id();

            // fill the form with the active record data
            $this->form->setData($data);

            // close the transaction
            TTransaction::close();

            // shows the success message and redirects to FinancialEntryList
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
     * cents, matching FinancialEntryService::record()'s amount_cents input.
     */
    private static function toCents($amount)
    {
        $normalized = str_replace('.', '', (string) $amount);
        $normalized = str_replace(',', '.', $normalized);

        return (int) round(((float) $normalized) * 100);
    }

    /**
     * Wires an Application-layer FinancialEntryService instance, consistent
     * with the wiring already prepared by
     * AppointmentForm::buildAppointmentService() (T-07): the real
     * RbacAuthorizationService backed by AdiantiProgramPermissionProvider
     * and PdoAuditLogWriter against this same 'permission' connection, so
     * every record() call is both unit-scope-checked and audited to
     * `audit_log`. Requires an already-open TTransaction('permission')
     * connection.
     */
    private static function buildFinancialEntryService(\CentralVet\Tenancy\TenantContext $context): \CentralVet\Application\FinancialEntryService
    {
        $connection = TTransaction::get();

        $entries = new \CentralVet\Persistence\FinancialEntryRepository($context, $connection);

        $authorization = new \CentralVet\Authorization\RbacAuthorizationService(
            new \CentralVet\Authorization\AdiantiProgramPermissionProvider(new \CentralVet\Tenancy\AdiantiSessionContextSource()),
            new \CentralVet\Audit\PdoAuditLogWriter($connection),
        );

        return new \CentralVet\Application\FinancialEntryService($entries, $authorization, $context);
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
