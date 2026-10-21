<?php
/**
 * ClinicUserForm — convite e edição de usuário da equipe da clínica
 * (Etapa 3, T-24).
 *
 * Campos: nome, e-mail, grupo e unidade. O combo de grupo vem só de
 * ClinicTeamService::allowedGroups() (o grupo do plano ligado ao tenant) e o
 * de unidade só de ClinicTeamService::listUnits() (unidades do tenant). Sem
 * id, onSave() convida (ClinicTeamService::invite: assinatura, quota, grupo,
 * unidade e e-mail); com id, atualiza nome, grupo e unidade — o e-mail (login)
 * fica somente leitura. onDeactivate/onReactivate são chamados pela grade.
 *
 * onSave, onDeactivate e onReactivate são mutações (POST + X-CSRF-Token pela
 * MutationRequestPolicy central). O serviço usa uma conexão própria, sem
 * TTransaction aberta, para que o convite saia depois do commit.
 *
 * Rotas: index.php?class=ClinicUserForm (convite) e
 * index.php?class=ClinicUserForm&method=onEdit&id=<id>.
 *
 * @version    1.0
 * @package    control
 * @subpackage admin
 */
class ClinicUserForm extends TPage
{
    protected $form;
    protected $group_id;
    protected $unit_id;
    protected $email;
    private bool $loaded = false;

    public function __construct($param = null)
    {
        parent::__construct();

        $editing = self::paramId($param) !== null;

        $this->form = new BootstrapFormBuilder('form_ClinicUser');
        $this->form->enableClientValidation();

        $id          = new THidden('id');
        $name        = new TEntry('name');
        $this->email = new TEntry('email');
        $this->group_id = new TCombo('group_id');
        $this->unit_id  = new TCombo('unit_id');

        $name->setMaxLength(256);
        $this->email->setMaxLength(256);

        if ($editing)
        {
            $this->email->setEditable(false);
        }

        $notice = null;

        try
        {
            $service = self::makeService();
            $groups  = $service->allowedGroups();
            $this->group_id->addItems($groups);
            $this->unit_id->addItems($service->listUnits());

            if ($groups === [])
            {
                $notice = TElement::tag('div', CvFormat::e(_t('No plan group available for this clinic')), ['class' => 'alert alert-warning']);
            }
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

        CvForm::decorate($this->form, 2);

        $this->form->addFields( [new TLabel(_t('Name'))], [$name], [new TLabel(_t('E-mail'))], [$this->email] );
        $this->form->addFields( [new TLabel(_t('Group'))], [$this->group_id], [new TLabel(_t('Unit'))], [$this->unit_id] );

        $hidden_row = $this->form->addFields( [$id] );
        $hidden_row->style = 'display: none';

        $name->addValidation( _t('Name'), new TRequiredValidator );
        $this->email->addValidation( _t('E-mail'), new TRequiredValidator );
        $this->group_id->addValidation( _t('Group'), new TRequiredValidator );
        $this->unit_id->addValidation( _t('Unit'), new TRequiredValidator );

        $btn = $this->form->addAction($editing ? _t('Save') : _t('Invite user'), new TAction([$this, 'onSave']), 'fa:check');
        $btn->class = 'btn btn-primary cv-touch-target';

        $container = new TVBox;
        $container->style = 'width: 100%';
        $container->add(CvPage::header($editing ? _t('Team') : _t('Invite user'), _t('Administration'), [
            ['icon' => 'fa:arrow-left', 'href' => 'index.php?class=ClinicUserList', 'class' => 'btn btn-default cv-touch-target', 'title' => _t('Back')],
        ]));
        if ($notice !== null)
        {
            $container->add($notice);
        }
        $container->add($this->form);

        parent::add($container);
    }

    /**
     * Convida (sem id) ou atualiza (com id) pelo ClinicTeamService.
     */
    public function onSave($param = null)
    {
        $this->loaded = true;

        try
        {
            $data = $this->form->getData();

            $this->form->validate();

            $service = self::makeService();
            $now = new DateTimeImmutable();

            if (!empty($data->id))
            {
                $service->update((int) $data->id, (string) $data->name, (int) $data->group_id, (int) $data->unit_id);
            }
            else
            {
                $data->id = $service->invite((string) $data->name, (string) $data->email, (int) $data->group_id, (int) $data->unit_id, $now);
            }

            $this->form->setData($data);

            TToast::show('success', _t('Record saved'));
            TScript::create("__adianti_goto_page('index.php?class=ClinicUserList')");
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
     * Carrega o usuário pelo ClinicTeamService::findUser() (só do tenant).
     */
    public function onEdit($param = null)
    {
        $this->loaded = true;

        $user_id = self::paramId($param);

        if ($user_id === null)
        {
            $this->form->clear(true);
            return;
        }

        try
        {
            $user = self::makeService()->findUser($user_id);

            $data = new stdClass;
            $data->id       = $user['id'];
            $data->name     = $user['name'];
            $data->email    = $user['email'];
            $data->group_id = $user['group_id'];
            $data->unit_id  = $user['unit_id'];

            $this->email->setEditable(false);
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

    public static function onDeactivate($param)
    {
        self::changeStatus($param, false);
    }

    public static function onReactivate($param)
    {
        self::changeStatus($param, true);
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
     * (o TransactionRunner faz o commit antes do e-mail de convite).
     */
    public static function makeService(): \CentralVet\Tenancy\ClinicTeamService
    {
        $pdo = TConnection::open('permission');
        $context = self::resolveTenantContext($pdo);
        $subscriptions = new \CentralVet\Persistence\SubscriptionRepository($pdo);
        $ini = AdiantiApplicationConfig::get();

        return new \CentralVet\Tenancy\ClinicTeamService(
            $pdo,
            new \CentralVet\Billing\TransactionRunner($pdo),
            $context,
            $subscriptions,
            new \CentralVet\Billing\QuotaGuard($subscriptions, new \CentralVet\Persistence\TenantUsageReader($pdo)),
            new \CentralVet\Billing\SubscriptionAccessGuard($subscriptions),
            new \CentralVet\Billing\AccountInviteService(
                new \CentralVet\Security\PasswordResetToken(APPLICATION_NAME . $ini['general']['seed']),
                \CentralVet\Security\PublicUrl::fromEnvironment(),
                \CentralVet\Communication\EmailProviderFactory::fromEnvironment(\CentralVet\Observability\Logging\JsonLogger::fromEnvironment('web')),
                static fn (string $key, string ...$params): string => _t($key, ...$params),
            ),
        );
    }

    private static function changeStatus($param, bool $reactivate): void
    {
        try
        {
            $user_id = self::paramId(is_array($param) ? $param : []) ?? 0;
            $service = self::makeService();

            if ($reactivate)
            {
                $service->reactivate($user_id, new DateTimeImmutable());
            }
            else
            {
                $service->deactivate($user_id);
            }

            TToast::show('success', _t('Record saved'));
            TScript::create("__adianti_goto_page('index.php?class=ClinicUserList')");
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

    /**
     * id do usuário a partir de `id` ou `key`; null quando ausente/inválido.
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
