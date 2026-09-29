<?php
/**
 * ProductForm
 *
 * Registration screen for the product catalog (T-07). Follows the exact
 * TStandardForm pattern used by ServiceForm (Fase 1): the form itself
 * carries no business rule — creation, name uniqueness and every other
 * validation live entirely in CentralVet\Application\ProductService::create()
 * (T-03). This controller only forwards form data and translates the
 * outcome (success or domain/tenancy exception) into screen feedback,
 * never letting an exception escape as a fatal error / HTTP 500.
 *
 * PENDING: depends on the `product` table created by the not-yet-applied
 * migration src/app/database/migrations/20260924_0005_phase4_procedure_stock_sale.sql
 * (T-01). Validated only with `php -l` / `new ProductForm()` (no fatal
 * error) until that migration is applied.
 *
 * @version    1.0
 * @package    control
 * @subpackage clinic
 */
class ProductForm extends TStandardForm
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

        $this->setDatabase('permission');            // defines the database
        $this->setActiveRecord('Product');            // defines the active record
        $this->setAfterSaveAction( new TAction(['ProductList', 'onReload']) );
        $this->setUseToast(true);

        // creates the form
        $this->form = new BootstrapFormBuilder('form_Product');
        $this->form->setFormTitle(_t('Product'));
        $this->form->enableClientValidation();

        // create the form fields
        $id = new TEntry('id');
        $name = new TEntry('name');
        $category = new TEntry('category');
        $unit_of_measure = new TEntry('unit_of_measure');
        $unit_cost = new TEntry('unit_cost');
        $minimum_stock_quantity = new TEntry('minimum_stock_quantity');
        $active = new TCombo('active');
        $active->addItems([1 => _t('Active'), 0 => _t('Inactive')]);

        // add the fields
        $this->form->addFields( [new TLabel('Id')] );
        $this->form->addFields( [$id] );
        $this->form->addFields( [new TLabel(_t('Name'))] );
        $this->form->addFields( [$name] );
        $this->form->addFields( [new TLabel(_t('Category'))] );
        $this->form->addFields( [$category] );
        $this->form->addFields( [new TLabel(_t('Unit of measure'))] );
        $this->form->addFields( [$unit_of_measure] );
        $this->form->addFields( [new TLabel(_t('Unit cost'))] );
        $this->form->addFields( [$unit_cost] );
        $this->form->addFields( [new TLabel(_t('Minimum stock quantity'))] );
        $this->form->addFields( [$minimum_stock_quantity] );
        $this->form->addFields( [new TLabel(_t('Status'))] );
        $this->form->addFields( [$active] );

        $id->setEditable(FALSE);
        $id->setSize('30%');
        $name->setSize('100%');
        $category->setSize('100%');
        $unit_of_measure->setSize('30%');
        $unit_cost->setSize('30%');
        $unit_cost->setNumericMask(2, ',', '.');
        $minimum_stock_quantity->setSize('30%');
        $minimum_stock_quantity->setNumericMask(0, '', '');
        $active->setSize('100%');
        $active->setValue(1);

        $name->addValidation( _t('Name'), new TRequiredValidator );
        $unit_of_measure->addValidation( _t('Unit of measure'), new TRequiredValidator );
        $minimum_stock_quantity->addValidation( _t('Minimum stock quantity'), new TRequiredValidator );

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
        $header_title->add(_t('Product'));
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
     * Persists the catalog entry through ProductService::create(). No
     * validation/decision is made here: required fields, uniqueness and
     * defaults are all enforced inside the Application service.
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

            $service = self::buildProductService($tenant_context);

            $product = $service->create(
                $tenant_context->tenantId(),
                $data->name,
                $data->category ?: null,
                $data->unit_of_measure,
                self::toCents($data->unit_cost),
                (int) $data->minimum_stock_quantity
            );

            $data->id = $product->id();

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
     * cents, matching ProductService::create()'s unit_cost_cents input
     * (same convention as ServiceForm::toCents()).
     */
    private static function toCents($amount)
    {
        $normalized = str_replace('.', '', (string) $amount);
        $normalized = str_replace(',', '.', $normalized);

        return (int) round(((float) $normalized) * 100);
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
