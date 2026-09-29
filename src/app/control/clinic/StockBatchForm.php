<?php
/**
 * StockBatchForm
 *
 * Entrada de lote de um produto em página cheia (fase 10, kit Cv*). Com
 * product_id na URL (…&product_id=<id>, vindo de "…" → Entrada de lote em
 * ProductList) o produto fica fixo (campo oculto + nome só leitura); sem ele,
 * o produto é escolhido num TDBUniqueSearch de Product filtrado pelo tenant da
 * sessão. O registro é delegado a CentralVet\Application\StockService::
 * receiveBatch() (stock_batch + stock_movement in/purchase_entry); a tela não
 * tem regra de negócio. Depois de receber volta para ProductList.
 *
 * Só criação: não há caso de uso de edição de lote, então onEdit() apenas
 * limpa o formulário e reaplica o product_id da URL.
 *
 * @version    2.0
 * @package    control
 * @subpackage clinic
 */
class StockBatchForm extends TPage
{
    protected $form; // form
    protected $product_id; // received via querystring, forwarded to StockService

    /**
     * Class constructor
     * Creates the page and the registration form
     */
    public function __construct($param = null)
    {
        parent::__construct();

        $raw = $_GET['product_id'] ?? (is_array($param) ? ($param['product_id'] ?? null) : null);
        $this->product_id = ($raw !== null && $raw !== '' && (int) $raw > 0) ? (int) $raw : null;

        $this->form = new BootstrapFormBuilder('form_StockBatch');
        $this->form->setFormTitle(_t('Stock batch entry'));
        $this->form->enableClientValidation();

        try
        {
            $tenant_id = self::resolveTenantContext()->tenantId();
        }
        catch (\CentralVet\Tenancy\Exception\MissingTenantContext $e)
        {
            $tenant_id = -1;
        }

        // create the form fields
        $lot = new TEntry('lot');
        $expiry_date = new TDate('expiry_date');
        $quantity = new TEntry('quantity');

        $expiry_date->setMask('dd/mm/yyyy');
        $expiry_date->setDatabaseMask('yyyy-mm-dd');

        if ($this->product_id !== null)
        {
            // produto fixo: id oculto + nome só leitura
            $product_id = new THidden('product_id');
            $product_id->setValue($this->product_id);

            $hiddenRow = $this->form->addFields([$product_id]);
            $hiddenRow->style = 'display: none';

            $product_name = new TEntry('product_name');
            $product_name->setEditable(false);
            $product_name->setValue(self::productName($this->product_id));

            $this->form->addFields([new TLabel(_t('Product'))], [$product_name]);
        }
        else
        {
            $tenant_criteria = new TCriteria;
            $tenant_criteria->add(new TFilter('tenant_id', '=', $tenant_id));
            $tenant_criteria->add(new TFilter('active', '=', 1));

            $product_id = new TDBUniqueSearch('product_id', 'permission', 'Product', 'id', 'name', 'name', $tenant_criteria);
            $product_id->setMinLength(0);
            $product_id->addValidation(_t('Product'), new TRequiredValidator);

            $this->form->addFields([new TLabel(_t('Product'))], [$product_id]);
        }

        $this->form->addFields(
            [new TLabel(_t('Lot'))], [$lot],
            [new TLabel(_t('Expiry date'))], [$expiry_date]
        );
        $this->form->addFields(
            [new TLabel(_t('Quantity'))], [$quantity]
        );

        $quantity->setNumericMask(0, '', '');
        $quantity->setProperty('pattern', '[0-9]*');

        $quantity->addValidation( _t('Quantity'), new TRequiredValidator );

        CvForm::decorate($this->form, 2);

        // create the form actions
        $this->form->addActionLink(_t('Clear'), new TAction([$this, 'onEdit'], $this->product_id !== null ? ['product_id' => $this->product_id] : []), 'fa:eraser');
        $btn = $this->form->addAction(_t('Receive'), new TAction([$this, 'onSave']), 'fa:check');
        $btn->class = 'btn btn-primary';

        $container = new TVBox;
        $container->style = 'width: 100%';
        $container->add(CvPage::header(_t('Stock batch entry'), null, [
            ['icon' => 'fa:arrow-left', 'href' => 'index.php?class=ProductList'],
        ]));
        $container->add(CvNav::tabs('stock', 'products'));
        $container->add($this->form);

        parent::add($container);
    }

    /**
     * Name of the fixed product, read through ProductService::findById()
     * (tenant-scoped repository). Unknown/foreign id → "—".
     */
    private static function productName(int $product_id): string
    {
        try
        {
            TTransaction::open('permission');

            $tenant_context = self::resolveTenantContext();
            $repository = new \CentralVet\Persistence\ProductRepository($tenant_context, TTransaction::get());
            $product = (new \CentralVet\Application\ProductService($repository, $tenant_context))->findById($product_id);

            TTransaction::close();

            return $product !== null ? $product->name() : '—';
        }
        catch (Exception $e)
        {
            TTransaction::rollback();

            return '—';
        }
    }

    /**
     * method onEdit()
     * StockBatch has no update use case (StockService only exposes
     * receiveBatch()/consume()), so this screen is create-only: onEdit()
     * just clears the form and re-applies the product_id received in the
     * querystring.
     */
    public function onEdit($param)
    {
        $this->form->clear(true);

        if ($this->product_id !== null)
        {
            $data = new stdClass;
            $data->product_id = $this->product_id;
            $data->product_name = self::productName($this->product_id);
            $this->form->setData($data);
        }
    }

    /**
     * method onSave()
     * Registers the batch through StockService::receiveBatch(). No
     * validation/decision is made here: quantity/expiry rules live in the
     * Application/Domain layer. On success returns to ProductList.
     */
    public function onSave($param = null)
    {
        try
        {
            $data = $this->form->getData();

            $this->form->validate();

            $product_id = $this->product_id ?? (!empty($data->product_id) ? (int) $data->product_id : null);

            if (empty($product_id))
            {
                throw new Exception(_t('A product must be informed to receive a stock batch'));
            }

            TTransaction::open('permission');

            $tenant_context = self::resolveTenantContext();

            $service = self::buildStockService($tenant_context);

            $service->receiveBatch(
                $tenant_context->tenantId(),
                $tenant_context->requireUnitId(),
                $product_id,
                $data->lot ?: null,
                $data->expiry_date ?: null,
                (int) $data->quantity,
                $tenant_context->userId()
            );

            TTransaction::close();

            TToast::show('info', _t('Batch received'));
            AdiantiCoreApplication::loadPage('ProductList');
        }
        catch (\CentralVet\Domain\Exception\CrossTenantReferenceException $e)
        {
            TTransaction::rollback();
            $this->form->setData($data ?? null);
            new TMessage('error', $e->getMessage());
        }
        catch (\CentralVet\Authorization\Exception\AuthorizationDenied $e)
        {
            TTransaction::rollback();
            $this->form->setData($data ?? null);
            new TMessage('error', _t('You are not allowed to perform this action'));
        }
        catch (\CentralVet\Tenancy\Exception\TenantBoundaryViolation $e)
        {
            TTransaction::rollback();
            $this->form->setData($data ?? null);
            new TMessage('error', _t('You are not allowed to perform this action'));
        }
        catch (\CentralVet\Tenancy\Exception\MissingTenantContext $e)
        {
            TTransaction::rollback();
            $this->form->setData($data ?? null);
            new TMessage('error', _t('Your session does not have an active tenant. Please log in again'));
        }
        catch (Exception $e) // in case of exception (validation, domain, etc.)
        {
            TTransaction::rollback();
            $this->form->setData($data ?? null);
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
