<?php

use CentralVet\Presentation\MoneyInput;

/**
 * ProductForm
 *
 * Cadastro/edição de produto em página cheia (fase 10, kit Cv*), aberto por
 * "Novo produto" e por "…" → Editar em ProductList
 * (index.php?class=ProductForm&method=onEdit&id=<id>).
 *
 * Criação e edição delegadas a CentralVet\Application\ProductService
 * (create()/update(), T-34), com preço de venda (em centavos) e código
 * (rodada 2, T-11). Leitura para edição via ProductService::findById()
 * (repositório escopado ao tenant), nunca pelo ActiveRecord Product. Depois de
 * salvar volta para ProductList.
 *
 * @version    2.0
 * @package    control
 * @subpackage clinic
 */
class ProductForm extends TPage
{
    protected $form; // form

    /**
     * Class constructor
     * Creates the page and the registration form
     */
    public function __construct($param = null)
    {
        parent::__construct();

        $this->form = new BootstrapFormBuilder('form_Product');
        $this->form->setFormTitle(_t('Product'));
        $this->form->enableClientValidation();

        // create the form fields
        $id = new THidden('id');
        $name = new TEntry('name');
        $code = new TEntry('code');
        $sale_price = new TEntry('sale_price');
        $category = new TEntry('category');
        $unit_of_measure = new TEntry('unit_of_measure');
        $unit_cost = new TEntry('unit_cost');
        $minimum_stock_quantity = new TEntry('minimum_stock_quantity');
        $active = new TCombo('active');
        $active->addItems([1 => _t('Active'), 0 => _t('Inactive')]);

        $hiddenRow = $this->form->addFields([$id]);
        $hiddenRow->style = 'display: none';

        $this->form->addFields(
            [new TLabel(_t('Name'))], [$name],
            [new TLabel(_t('Category'))], [$category]
        );
        $this->form->addFields(
            [new TLabel(_t('Code'))], [$code],
            [new TLabel(_t('Unit of measure'))], [$unit_of_measure]
        );
        $this->form->addFields(
            [new TLabel(_t('Unit cost'))], [$unit_cost],
            [new TLabel(_t('Sale price'))], [$sale_price]
        );
        $this->form->addFields(
            [new TLabel(_t('Minimum stock quantity'))], [$minimum_stock_quantity],
            [new TLabel(_t('Status'))], [$active]
        );

        // digitação livre, sem máscara nem filtro (padrão BankAccountForm):
        // MoneyInput::toCents() converte ou recusa ("Valor inválido").
        $unit_cost->setProperty('placeholder', _t('e.g. 12,34'));
        $unit_cost->setProperty('inputmode', 'decimal');
        $unit_cost->setMaxLength(16);
        $sale_price->setProperty('placeholder', _t('e.g. 12,34'));
        $sale_price->setProperty('inputmode', 'decimal');
        $sale_price->setMaxLength(16);
        $code->setMaxLength(60);
        $minimum_stock_quantity->setNumericMask(0, '', '');
        $minimum_stock_quantity->setProperty('pattern', '[0-9]*');
        $active->setValue(1);

        $name->addValidation( _t('Name'), new TRequiredValidator );
        $unit_of_measure->addValidation( _t('Unit of measure'), new TRequiredValidator );
        $minimum_stock_quantity->addValidation( _t('Minimum stock quantity'), new TRequiredValidator );

        CvForm::decorate($this->form, 2);

        // create the form actions
        $this->form->addActionLink(_t('Clear'), new TAction([$this, 'onClear']), 'fa:eraser');
        $btn = $this->form->addAction(_t('Save'), new TAction([$this, 'onSave']), 'fa:check');
        $btn->class = 'btn btn-primary';

        $container = new TVBox;
        $container->style = 'width: 100%';
        $container->add(CvPage::header(_t('Product'), null, [
            ['icon' => 'fa:arrow-left', 'href' => 'index.php?class=ProductList'],
        ]));
        $container->add(CvNav::tabs('stock', 'products'));
        $container->add($this->form);

        parent::add($container);
    }

    /**
     * method onClear()
     * Empties the form (new product).
     */
    public function onClear($param = null)
    {
        $this->form->clear(true);
        $this->form->setData((object) ['active' => 1]);
    }

    /**
     * method onEdit()
     * Loads the product through ProductService::findById() (tenant-scoped
     * repository). Unknown id or another tenant's id → "Record not found".
     */
    public function onEdit($param)
    {
        $id = isset($param['id']) ? (int) $param['id'] : (isset($param['key']) ? (int) $param['key'] : 0);

        if ($id <= 0)
        {
            $this->onClear($param);
            return;
        }

        try
        {
            TTransaction::open('permission');

            $tenant_context = self::resolveTenantContext();
            $product = self::buildProductService($tenant_context)->findById($id);

            TTransaction::close();

            if ($product === null)
            {
                $this->form->clear(true);
                new TMessage('error', _t('Record not found'));
                return;
            }

            $data = new stdClass;
            $data->id = $product->id();
            $data->name = $product->name();
            $data->category = $product->category();
            $data->unit_of_measure = $product->unitOfMeasure();
            $data->unit_cost = number_format($product->unitCostCents() / 100, 2, ',', '.');
            $data->sale_price = $product->salePriceCents() !== null ? number_format($product->salePriceCents() / 100, 2, ',', '.') : '';
            $data->code = $product->code();
            $data->minimum_stock_quantity = $product->minimumStockQuantity();
            $data->active = $product->isActive() ? 1 : 0;

            $this->form->setData($data);
        }
        catch (\CentralVet\Tenancy\Exception\MissingTenantContext $e)
        {
            TTransaction::rollback();
            $this->form->clear(true);
            new TMessage('error', _t('Your session does not have an active tenant. Please log in again'));
        }
        catch (Exception $e)
        {
            TTransaction::rollback();
            $this->form->clear(true);
            error_log(__METHOD__ . ': ' . $e->getMessage());
            new TMessage('error', CvFormat::userError($e));
        }
    }

    /**
     * method onSave()
     * New product → ProductService::create(); existing id →
     * ProductService::update(). Returns to ProductList.
     */
    public function onSave($param = null)
    {
        try
        {
            $data = $this->form->getData();

            $this->form->validate();

            TTransaction::open('permission');

            $tenant_context = self::resolveTenantContext();
            $connection = TTransaction::get();
            $repository = new \CentralVet\Persistence\ProductRepository($tenant_context, $connection);
            $service = new \CentralVet\Application\ProductService($repository, $tenant_context);

            $active = ((string) $data->active) !== '0';

            if (!empty($data->id))
            {
                $product = $service->update(
                    (int) $data->id,
                    (string) $data->name,
                    $data->category ?: null,
                    (string) $data->unit_of_measure,
                    MoneyInput::toCents((string) $data->unit_cost, false, MoneyInput::MAX_UNSIGNED_INT_CENTS),
                    (int) $data->minimum_stock_quantity,
                    $active,
                    self::toNullableCents($data->sale_price ?? null),
                    self::nullableString($data->code ?? null)
                );
            }
            else
            {
                $product = $service->create(
                    $tenant_context->tenantId(),
                    (string) $data->name,
                    $data->category ?: null,
                    (string) $data->unit_of_measure,
                    MoneyInput::toCents((string) $data->unit_cost, false, MoneyInput::MAX_UNSIGNED_INT_CENTS),
                    (int) $data->minimum_stock_quantity,
                    self::toNullableCents($data->sale_price ?? null),
                    self::nullableString($data->code ?? null)
                );

                if (!$active)
                {
                    $product->deactivate();
                    $repository->save($product);
                }
            }

            TTransaction::close();

            TToast::show('info', _t('Record saved'));
            AdiantiCoreApplication::loadPage('ProductList');
        }
        catch (\CentralVet\Domain\Exception\CrossTenantReferenceException $e)
        {
            TTransaction::rollback();
            $this->form->setData($data ?? null);
            error_log(__METHOD__ . ': ' . $e->getMessage());
            new TMessage('error', CvFormat::userError($e));
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
            new TMessage('error', CvFormat::userError($e));
        }
    }

    /**
     * Sale price is optional: blank field → null (no price informed),
     * otherwise MoneyInput::toCents() with the int unsigned ceiling of
     * product.sale_price_cents (invalid text → 'Invalid amount').
     */
    private static function toNullableCents($amount)
    {
        if ($amount === null || trim((string) $amount) === '')
        {
            return null;
        }

        return MoneyInput::toCents((string) $amount, false, MoneyInput::MAX_UNSIGNED_INT_CENTS);
    }

    /** Blank text field → null (Product::create() trims and nulls it too). */
    private static function nullableString($value)
    {
        if ($value === null || trim((string) $value) === '')
        {
            return null;
        }

        return (string) $value;
    }

    /**
     * Builds CentralVet\Application\ProductService with its dependencies,
     * following the same tenant-scoping convention as
     * ServiceForm::buildServiceCatalogService(). Requires an already-open
     * TTransaction('permission') connection.
     */
    private static function buildProductService(\CentralVet\Tenancy\TenantContext $tenant_context)
    {
        $connection = TTransaction::get();

        $repository = new \CentralVet\Persistence\ProductRepository($tenant_context, $connection);

        return new \CentralVet\Application\ProductService($repository, $tenant_context);
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
