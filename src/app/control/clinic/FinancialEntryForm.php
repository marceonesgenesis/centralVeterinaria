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
        $this->form->enableClientValidation();

        // create the form fields
        $id = new THidden('id');
        $entry_type = new TCombo('entry_type');
        $entry_type->addItems([
            \CentralVet\Domain\FinancialEntry::TYPE_INCOME  => _t('Income'),
            \CentralVet\Domain\FinancialEntry::TYPE_EXPENSE => _t('Expense'),
        ]);
        $category = new TEntry('category');
        $amount = new TEntry('amount');

        // forma de pagamento opcional (T-14): rótulos de CvFormat::paymentMethod()
        $payment_method = new TCombo('payment_method');
        $payment_items = [];
        foreach ([
            \CentralVet\Domain\Payment::METHOD_CASH,
            \CentralVet\Domain\Payment::METHOD_DEBIT_CARD,
            \CentralVet\Domain\Payment::METHOD_CREDIT_CARD,
            \CentralVet\Domain\Payment::METHOD_PIX,
            \CentralVet\Domain\Payment::METHOD_BANK_TRANSFER,
        ] as $method)
        {
            $payment_items[$method] = CvFormat::paymentMethod($method);
        }
        $payment_method->addItems($payment_items);

        CvForm::decorate($this->form, 2);

        // add the fields (pares rótulo/campo em 2 colunas, rótulo acima)
        $this->form->addFields( [new TLabel(_t('Type'))], [$entry_type], [new TLabel(_t('Category'))], [$category] );
        $this->form->addFields( [new TLabel(_t('Amount'))], [$amount], [new TLabel(_t('Payment method'))], [$payment_method] );

        // id só para o fluxo editar/salvar, fora do layout visível
        $hidden_row = $this->form->addFields( [$id] );
        $hidden_row->style = 'display: none';
        $amount->setNumericMask(2, ',', '.');

        $entry_type->addValidation( _t('Type'), new TRequiredValidator );
        $category->addValidation( _t('Category'), new TRequiredValidator );
        $amount->addValidation( _t('Amount'), new TRequiredValidator );

        // pré-seleciona o tipo vindo da aba (FinancialEntryList&entry_type=...)
        $requested_type = self::requestedType($_REQUEST['entry_type'] ?? null);
        if ($requested_type !== null)
        {
            $entry_type->setValue($requested_type);
        }

        // create the form actions
        $btn = $this->form->addAction(_t('Save'), new TAction(array($this, 'onSave')), 'fa:check');
        $btn->class = 'btn btn-primary';
        $this->form->addActionLink(_t('Clear'),  new TAction(array($this, 'onEdit')), 'fa:eraser');

        // página cheia: cabeçalho do kit com voltar para a lista
        $back = 'index.php?class=FinancialEntryList' . ($requested_type !== null ? '&entry_type=' . $requested_type : '');

        $container = new TVBox;
        $container->style = 'width: 100%';
        $container->add(CvPage::header(_t('Financial entry'), _t('Financial'), [
            ['icon' => 'fa:arrow-left', 'href' => $back],
        ]));
        $container->add($this->form);

        parent::add($container);
    }

    /**
     * entry_type válido do schema (income|expense) ou null.
     */
    private static function requestedType($value): ?string
    {
        return in_array($value, [\CentralVet\Domain\FinancialEntry::TYPE_INCOME, \CentralVet\Domain\FinancialEntry::TYPE_EXPENSE], true) ? $value : null;
    }

    /**
     * method onEdit()
     * financial_entry é append-only: não há edição por key. "Novo"/"Limpar"
     * apenas esvaziam o formulário (mantendo o tipo da aba). Substitui o
     * onEdit() herdado, que exigia setActiveRecord() e mostrava erro
     * "Active Record não definido" (T-18, Correção 2).
     */
    public function onEdit($param = null)
    {
        $this->form->clear(true);

        $requested_type = self::requestedType($param['entry_type'] ?? ($_REQUEST['entry_type'] ?? null));
        if ($requested_type !== null)
        {
            $data = new stdClass;
            $data->entry_type = $requested_type;
            $this->form->setData($data);
        }
    }

    /**
     * on close
     */
    public static function onClose($param)
    {
        AdiantiCoreApplication::loadPage('FinancialEntryList');
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
                self::paymentMethodOrNull($data->payment_method ?? null),
            );

            $data->id = $entry->id();

            // fill the form with the active record data
            $this->form->setData($data);

            // close the transaction
            TTransaction::close();

            // volta para a aba do tipo salvo (Receitas/Despesas)
            $saved_type = self::requestedType((string) $data->entry_type);
            if ($saved_type !== null)
            {
                $this->setAfterSaveAction(new TAction(['FinancialEntryList', 'onReload'], ['entry_type' => $saved_type]));
            }

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
     * Forma de pagamento opcional (T-14): vazio vira null; valor fora da
     * lista é repassado como veio para FinancialEntry::record() recusar.
     */
    private static function paymentMethodOrNull($value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
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
