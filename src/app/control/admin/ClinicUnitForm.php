<?php
/**
 * ClinicUnitForm — criação e renomeação de unidade da clínica (Etapa 3, T-25).
 *
 * Campo único: nome. Sem id, onSave() cria pelo ClinicUnitService::create
 * (assinatura, quota de unidades do plano e nome); com id, renomeia pelo
 * ClinicUnitService::rename (só unidade do tenant da sessão). Não há
 * exclusão de unidade.
 *
 * onSave é mutação (POST + X-CSRF-Token pela MutationRequestPolicy central);
 * onEdit só lê. O serviço usa uma conexão própria, sem TTransaction aberta.
 *
 * Rotas: index.php?class=ClinicUnitForm (nova) e
 * index.php?class=ClinicUnitForm&method=onEdit&id=<id>.
 *
 * @version    1.0
 * @package    control
 * @subpackage admin
 */
class ClinicUnitForm extends TPage
{
    protected $form;
    private bool $loaded = false;

    public function __construct($param = null)
    {
        parent::__construct();

        $editing = self::paramId($param) !== null;

        $this->form = new BootstrapFormBuilder('form_ClinicUnit');
        $this->form->enableClientValidation();

        $id   = new THidden('id');
        $name = new TEntry('name');
        $name->setMaxLength(120);

        CvForm::decorate($this->form, 1);

        $this->form->addFields( [new TLabel(_t('Name'))], [$name] );

        $hidden_row = $this->form->addFields( [$id] );
        $hidden_row->style = 'display: none';

        $name->addValidation( _t('Name'), new TRequiredValidator );

        $btn = $this->form->addAction(_t('Save'), new TAction([$this, 'onSave']), 'fa:check');
        $btn->class = 'btn btn-primary cv-touch-target';

        $container = new TVBox;
        $container->style = 'width: 100%';
        $container->add(CvPage::header($editing ? _t('Clinic units') : _t('New unit'), _t('Administration'), [
            ['icon' => 'fa:arrow-left', 'href' => 'index.php?class=ClinicUnitList', 'class' => 'btn btn-default cv-touch-target', 'title' => _t('Back')],
        ]));
        $container->add($this->form);

        parent::add($container);
    }

    /**
     * Cria (sem id) ou renomeia (com id) pelo ClinicUnitService.
     */
    public function onSave($param = null)
    {
        $this->loaded = true;

        try
        {
            $data = $this->form->getData();

            $this->form->validate();

            $service = self::makeService();

            if (!empty($data->id))
            {
                $service->rename((int) $data->id, (string) $data->name);
            }
            else
            {
                $data->id = $service->create((string) $data->name, new DateTimeImmutable());
            }

            $this->form->setData($data);

            TToast::show('success', _t('Record saved'));
            TScript::create("__adianti_goto_page('index.php?class=ClinicUnitList')");
        }
        catch (\CentralVet\Tenancy\Exception\MissingTenantContext $e)
        {
            $this->form->setData($data ?? null);
            new TMessage('error', _t('An authenticated session with an active unit is required'));
        }
        catch (Exception $e)
        {
            $this->form->setData($data ?? null);
            error_log(__METHOD__ . ': ' . $e->getMessage());
            new TMessage('error', CvFormat::userError($e));
        }
    }

    /**
     * Carrega a unidade pelo ClinicUnitService::findUnit() (só do tenant).
     */
    public function onEdit($param = null)
    {
        $this->loaded = true;

        $unit_id = self::paramId($param);

        if ($unit_id === null)
        {
            $this->form->clear(true);
            return;
        }

        try
        {
            $unit = self::makeService()->findUnit($unit_id);

            $data = new stdClass;
            $data->id   = $unit['id'];
            $data->name = $unit['name'];

            $this->form->setData($data);
        }
        catch (\CentralVet\Tenancy\Exception\MissingTenantContext $e)
        {
            new TMessage('error', _t('An authenticated session with an active unit is required'));
        }
        catch (Exception $e)
        {
            error_log(__METHOD__ . ': ' . $e->getMessage());
            new TMessage('error', CvFormat::userError($e));
        }
    }

    public function show()
    {
        if (!$this->loaded && isset($_REQUEST['id']) && ($_REQUEST['method'] ?? '') === '')
        {
            $this->onEdit($_REQUEST);
        }

        parent::show();
    }

    /**
     * Serviço escopado ao tenant da sessão, numa conexão sem TTransaction
     * (o TransactionRunner abre e fecha a própria transação). O tenant segue
     * a mesma regra da equipe (ClinicUserForm::makeService).
     */
    public static function makeService(): \CentralVet\Tenancy\ClinicUnitService
    {
        $pdo = TConnection::open('permission');
        $context = self::resolveTenantContext($pdo);
        $subscriptions = new \CentralVet\Persistence\SubscriptionRepository($pdo);

        return new \CentralVet\Tenancy\ClinicUnitService(
            $pdo,
            new \CentralVet\Billing\TransactionRunner($pdo),
            $context,
            new \CentralVet\Billing\QuotaGuard($subscriptions, new \CentralVet\Persistence\TenantUsageReader($pdo)),
            new \CentralVet\Billing\SubscriptionAccessGuard($subscriptions),
        );
    }

    /**
     * id da unidade a partir de `id` ou `key`; null quando ausente/inválido.
     */
    private static function paramId($param): ?int
    {
        if (!is_array($param))
        {
            return null;
        }

        $raw = $param['id'] ?? ($param['key'] ?? null);

        if ($raw === null || $raw === '' || !ctype_digit((string) $raw) || (int) $raw <= 0)
        {
            return null;
        }

        return (int) $raw;
    }

    /**
     * Tenant da sessão autenticada, com o fallback por tenant_user usado nas
     * demais telas da clínica.
     */
    private static function resolveTenantContext(PDO $pdo): \CentralVet\Tenancy\TenantContext
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

            $stmt = $pdo->prepare('SELECT tenant_id FROM tenant_user WHERE system_user_id = :userid ORDER BY id ASC LIMIT 1');
            $stmt->execute(['userid' => (int) $userid]);
            $tenant_id = $stmt->fetchColumn();

            if (empty($tenant_id))
            {
                throw $e;
            }

            $unit_id = TSession::getValue('userunitid');

            return \CentralVet\Tenancy\TenantContext::authenticated((int) $tenant_id, (int) $userid, $unit_id ? (int) $unit_id : null);
        }
    }
}
