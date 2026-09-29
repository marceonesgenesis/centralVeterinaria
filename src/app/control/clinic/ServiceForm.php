<?php
/**
 * ServiceForm
 *
 * Cadastro/edição de serviço do catálogo em página cheia (fase 10, kit Cv*).
 * Não contém regra de negócio: criação e edição são delegadas a
 * CentralVet\Application\ServiceCatalogService (create()/update()), que
 * valida nome único, preço/duração e escopo de tenant.
 *
 * onEdit carrega o serviço por ServiceCatalogService::findById() (repositório
 * escopado ao tenant da sessão) — nunca pelo ActiveRecord Service, que não
 * filtra tenant. onSave chama update() quando o campo id vem preenchido e
 * create() quando vazio; depois de salvar volta para ServiceList.
 *
 * @version    2.0
 * @package    control
 * @subpackage clinic
 */
class ServiceForm extends TPage
{
    protected $form; // form

    /**
     * Class constructor
     * Creates the page and the registration form
     */
    public function __construct($param = null)
    {
        parent::__construct();

        $this->form = new BootstrapFormBuilder('form_Service');
        $this->form->setFormTitle(_t('Service'));
        $this->form->enableClientValidation();

        // create the form fields
        $id = new THidden('id');
        $name = new TEntry('name');
        $category = new TEntry('category');
        $duration_minutes = new TEntry('duration_minutes');
        $price = new TEntry('price');
        $active = new TCombo('active');
        $active->addItems([1 => _t('Active'), 0 => _t('Inactive')]);

        $hiddenRow = $this->form->addFields([$id]);
        $hiddenRow->style = 'display: none';

        $this->form->addFields(
            [new TLabel(_t('Name'))], [$name],
            [new TLabel(_t('Category'))], [$category]
        );
        $this->form->addFields(
            [new TLabel(_t('Duration (minutes)'))], [$duration_minutes],
            [new TLabel(_t('Price'))], [$price]
        );
        $this->form->addFields(
            [new TLabel(_t('Status'))], [$active]
        );

        $duration_minutes->setNumericMask(0, '', '');
        $duration_minutes->setProperty('pattern', '[0-9]*');
        $price->setNumericMask(2, ',', '.');
        $active->setValue(1);

        $name->addValidation( _t('Name'), new TRequiredValidator );
        $duration_minutes->addValidation( _t('Duration (minutes)'), new TRequiredValidator );
        $price->addValidation( _t('Price'), new TRequiredValidator );

        CvForm::decorate($this->form, 2);

        // create the form actions
        $this->form->addActionLink(_t('Clear'), new TAction([$this, 'onClear']), 'fa:eraser');
        $btn = $this->form->addAction(_t('Save'), new TAction([$this, 'onSave']), 'fa:check');
        $btn->class = 'btn btn-primary';

        $container = new TVBox;
        $container->style = 'width: 100%';
        $container->add(CvPage::header(_t('Service'), null, [
            ['icon' => 'fa:arrow-left', 'href' => 'index.php?class=ServiceList'],
        ]));
        $container->add(CvNav::tabs('services', 'services'));
        $container->add($this->form);

        parent::add($container);
    }

    /**
     * method onClear()
     * Empties the form (new service).
     */
    public function onClear($param = null)
    {
        $this->form->clear(true);
        $this->form->setData((object) ['active' => 1]);
    }

    /**
     * method onEdit()
     * Loads the service through ServiceCatalogService::findById(), which is
     * scoped to the session tenant. Unknown id or another tenant's id →
     * "Record not found" and an empty form.
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

            $service = self::buildServiceCatalogService()->findById($id);

            TTransaction::close();

            if ($service === null)
            {
                $this->form->clear(true);
                new TMessage('error', _t('Record not found'));
                return;
            }

            $data = new stdClass;
            $data->id = $service->id();
            $data->name = $service->name();
            $data->category = $service->category();
            $data->duration_minutes = $service->durationMinutes();
            $data->price = number_format($service->priceCents() / 100, 2, ',', '.');
            $data->active = $service->isActive() ? 1 : 0;

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
            new TMessage('error', $e->getMessage());
        }
    }

    /**
     * method onSave()
     * update() when the id field is filled, create() otherwise. No
     * validation/decision is made here: everything (required fields,
     * uniqueness, tenant scope) is enforced inside the Application service.
     */
    public function onSave($param = null)
    {
        try
        {
            $data = $this->form->getData();

            $this->form->validate();

            TTransaction::open('permission');

            $catalog = self::buildServiceCatalogService();

            $input = [
                'name'              => (string) $data->name,
                'category'          => $data->category,
                'duration_minutes'  => (int) $data->duration_minutes,
                'price_cents'       => self::toCents($data->price),
            ];

            if (!empty($data->id))
            {
                $input['active'] = ((string) $data->active) !== '0';
                $service = $catalog->update((int) $data->id, $input);
            }
            else
            {
                $service = $catalog->create($input);

                if (((string) $data->active) === '0')
                {
                    $service = $catalog->update((int) $service->id(), $input + ['active' => false]);
                }
            }

            TTransaction::close();

            TToast::show('info', _t('Record saved'));
            AdiantiCoreApplication::loadPageURL('index.php?class=ServiceList&service_id=' . (int) $service->id());
        }
        catch (\CentralVet\Tenancy\Exception\MissingTenantContext $e)
        {
            TTransaction::rollback();
            $this->form->setData($data ?? null);
            new TMessage('error', _t('Your session does not have an active tenant. Please log in again'));
        }
        catch (Exception $e) // in case of exception
        {
            TTransaction::rollback();
            $this->form->setData($data ?? null);
            new TMessage('error', $e->getMessage());
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
