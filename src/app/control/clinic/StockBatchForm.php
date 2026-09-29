<?php
/**
 * StockBatchForm
 *
 * Stock batch ("lot") entry screen for a product (T-07): receives
 * product_id by parameter (?product_id=..., mirroring PatientForm's
 * tutor_id-by-parameter pattern from Fase 1) and registers a new physical
 * batch through CentralVet\Application\StockService::receiveBatch() (T-03),
 * which persists both the `stock_batch` row and its matching `in`/
 * `purchase_entry` `stock_movement` row. No business rule of its own:
 * quantity/expiry validation lives in the Domain entity
 * CentralVet\Domain\StockBatch::receive(), reached only through the
 * Application service — this controller never touches Persistence/Domain
 * directly.
 *
 * Create-only, same shape as PatientForm: there is no "edit a batch" use
 * case (StockService only exposes receiveBatch()/consume()), so onEdit()
 * just clears the form and re-applies the product_id received in the
 * querystring.
 *
 * PENDING: depends on the `product`/`stock_batch`/`stock_movement` tables
 * created by the not-yet-applied migration
 * src/app/database/migrations/20260924_0005_phase4_procedure_stock_sale.sql
 * (T-01). Validated only with `php -l` / `new StockBatchForm()` (no fatal
 * error) until that migration is applied.
 *
 * @version    1.0
 * @package    control
 * @subpackage clinic
 */
class StockBatchForm extends TStandardForm
{
    protected $form; // form
    protected $product_id; // received via querystring, forwarded to StockService

    /**
     * Class constructor
     * Creates the page and the registration form
     */
    function __construct($param = null)
    {
        parent::__construct();

        parent::setTargetContainer('adianti_right_panel');

        $this->product_id = (isset($param['product_id']) && $param['product_id'] !== '')
            ? (int) $param['product_id']
            : null;

        $this->setDatabase('permission');           // defines the database
        $this->setActiveRecord('StockBatch');         // defines the active record
        $this->setUseToast(true);

        // creates the form
        $this->form = new BootstrapFormBuilder('form_StockBatch');
        $this->form->setFormTitle(_t('Stock batch entry'));
        $this->form->enableClientValidation();

        // create the form fields
        $product_id = new TEntry('product_id');
        $lot = new TEntry('lot');
        $expiry_date = new TDate('expiry_date');
        $quantity = new TEntry('quantity');

        $expiry_date->setMask('dd/mm/yyyy');
        $expiry_date->setDatabaseMask('yyyy-mm-dd');

        // add the fields
        $this->form->addFields( [new TLabel(_t('Product'))] );
        $this->form->addFields( [$product_id] );
        $this->form->addFields( [new TLabel(_t('Lot'))] );
        $this->form->addFields( [$lot] );
        $this->form->addFields( [new TLabel(_t('Expiry date'))] );
        $this->form->addFields( [$expiry_date] );
        $this->form->addFields( [new TLabel(_t('Quantity'))] );
        $this->form->addFields( [$quantity] );

        // product_id comes exclusively from the querystring param; the
        // field only echoes it back read-only, no Product lookup/UI is
        // built here
        $product_id->setEditable(FALSE);
        $product_id->setSize('30%');
        if ($this->product_id !== null)
        {
            $product_id->setValue($this->product_id);
        }

        $lot->setSize('30%');
        $expiry_date->setSize('30%');
        $quantity->setSize('30%');
        $quantity->setNumericMask(0, '', '');

        $quantity->addValidation( _t('Quantity'), new TRequiredValidator );

        // create the form actions
        $btn = $this->form->addAction(_t('Receive'), new TAction(array($this, 'onSave')), 'fa:check');
        $btn->class = 'btn btn-sm btn-primary';
        $this->form->addActionLink(_t('Clear'),  new TAction(array($this, 'onEdit')), 'fa:eraser red');

        $this->form->addHeaderActionLink(_t('Close'), new TAction([$this, 'onClose']), 'fa:times red');

        // page header (design system: .cv-page-header/.cv-page-title, T-04)
        $header = new TElement('header');
        $header->class = 'cv-page-header';

        $header_text = new TElement('div');
        $header_title = new TElement('h1');
        $header_title->class = 'cv-page-title';
        $header_title->add(_t('Stock batch entry'));
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
     * method onEdit()
     * StockBatch has no update use case (StockService only exposes
     * receiveBatch()/consume()), so this screen is create-only: onEdit()
     * just clears the form and re-applies the product_id received in the
     * querystring, mirroring PatientForm::onEdit().
     */
    public function onEdit($param)
    {
        $this->form->clear(true);

        if ($this->product_id !== null)
        {
            $data = new stdClass;
            $data->product_id = $this->product_id;
            $this->form->setData($data);
        }
    }

    /**
     * method onSave()
     * Registers the batch through StockService::receiveBatch(), which
     * persists the stock_batch row and its matching in/purchase_entry
     * stock_movement row. No validation/decision is made here: everything
     * (quantity, expiry date) is enforced inside the Application/Domain
     * layer; this method only forwards form data and translates the
     * outcome into screen feedback, never letting an exception escape as a
     * fatal error.
     */
    public function onSave($param = null)
    {
        try
        {
            $data = $this->form->getData();

            $this->form->validate();

            $product_id = $this->product_id ?? (isset($data->product_id) ? (int) $data->product_id : null);

            if (empty($product_id))
            {
                throw new Exception(_t('A product must be informed to receive a stock batch'));
            }

            TTransaction::open('permission');

            $tenant_context = self::resolveTenantContext();

            $service = self::buildStockService($tenant_context);

            $batch = $service->receiveBatch(
                $tenant_context->tenantId(),
                $tenant_context->requireUnitId(),
                $product_id,
                $data->lot ?: null,
                $data->expiry_date ?: null,
                (int) $data->quantity,
                $tenant_context->userId()
            );

            $data->id = $batch->id();

            TTransaction::close();

            if (!empty($this->useToast))
            {
                TToast::show('info', _t('Batch received'));
            }
            else
            {
                new TMessage('info', _t('Batch received'));
            }

            $this->form->clear(true);
            $this->form->setData((object) ['product_id' => $product_id]);

            return $batch;
        }
        catch (\CentralVet\Domain\Exception\CrossTenantReferenceException $e)
        {
            TTransaction::rollback();
            new TMessage('error', $e->getMessage());
        }
        catch (\CentralVet\Authorization\Exception\AuthorizationDenied $e)
        {
            TTransaction::rollback();
            new TMessage('error', _t('You are not allowed to perform this action'));
        }
        catch (\CentralVet\Tenancy\Exception\TenantBoundaryViolation $e)
        {
            TTransaction::rollback();
            new TMessage('error', _t('You are not allowed to perform this action'));
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
     * Builds CentralVet\Application\StockService with its dependencies.
     * Mirrors SaleForm::buildSaleService()/ProcedureExecutionForm::
     * makeProcedureExecutionService(): the real RbacAuthorizationService
     * (Fase 0), backed by AdiantiProgramPermissionProvider (reads the same
     * programs/methods session keys SystemPermission::checkPermission()
     * already uses) and PdoAuditLogWriter against this same 'permission'
     * connection, so every receiveBatch() call is both unit-scope-checked
     * and audited to `audit_log`. Requires an already-open
     * TTransaction('permission') connection.
     */
    private static function buildStockService(\CentralVet\Tenancy\TenantContext $tenant_context)
    {
        $connection = TTransaction::get();

        $batches = new \CentralVet\Persistence\StockBatchRepository($tenant_context, $connection);
        $movements = new \CentralVet\Persistence\StockMovementRepository($tenant_context, $connection);

        $authorization = new \CentralVet\Authorization\RbacAuthorizationService(
            new \CentralVet\Authorization\AdiantiProgramPermissionProvider(new \CentralVet\Tenancy\AdiantiSessionContextSource()),
            new \CentralVet\Audit\PdoAuditLogWriter($connection),
        );

        return new \CentralVet\Application\StockService($batches, $movements, $authorization, $tenant_context);
    }

    /**
     * Resolves the tenant context of the authenticated session, mirroring
     * the helper used by PatientForm/ServiceForm (T-03). Duplicated here
     * (rather than shared) to avoid touching files outside T-07 scope.
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
