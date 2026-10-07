<?php
/**
 * SurgeryRoomForm — cadastro e edição de sala cirúrgica da unidade ativa
 * (Fase 6B, T-12).
 *
 * Campos: código e nome. Sem regra própria: onSave() chama
 * CentralVet\Application\SurgeryRoomService::create() (sem id; a unidade é a
 * da sessão) ou ::update() (com id). Na edição (`&id=<room_id>`) o código
 * fica só leitura — o service não troca código de sala existente.
 *
 * Rotas: index.php?class=SurgeryRoomForm (novo) e
 * index.php?class=SurgeryRoomForm&id=<id>.
 *
 * @version    1.0
 * @package    control
 * @subpackage clinic
 */
class SurgeryRoomForm extends TPage
{
    private const ACTION_SAVE = 'SurgeryRoomForm::onSave';
    private const ACTION_EDIT = 'SurgeryRoomForm::onEdit';

    protected $form;
    private bool $loaded = false;
    private ?int $roomId = null;

    public function __construct($param = null)
    {
        parent::__construct();

        $this->roomId = self::paramId($param);

        $this->form = new BootstrapFormBuilder('form_SurgeryRoom');
        $this->form->enableClientValidation();

        $id         = new THidden('id');
        $code       = new TEntry('code');
        $name       = new TEntry('name');

        $code->setMaxLength(30);
        $name->setMaxLength(120);

        if ($this->roomId !== null)
        {
            $code->setEditable(false);
        }

        CvForm::decorate($this->form, 2);

        $this->form->addFields( [new TLabel(_t('Code'))], [$code], [new TLabel(_t('Name'))], [$name] );

        $hidden_row = $this->form->addFields( [$id] );
        $hidden_row->style = 'display: none';

        $code->addValidation( _t('Code'), new TRequiredValidator );
        $name->addValidation( _t('Name'), new TRequiredValidator );

        $btn = $this->form->addAction(_t('Save'), new TAction([$this, 'onSave']), 'fa:check');
        $btn->class = 'btn btn-primary cv-touch-target';

        $container = new TVBox;
        $container->style = 'width: 100%';
        $container->add(CvPage::header($this->roomId !== null ? _t('Edit operating room') : _t('New operating room'), _t('Surgery'), [
            ['icon' => 'fa:arrow-left', 'href' => 'index.php?class=SurgeryRoomList'],
        ]));
        $container->add($this->form);

        parent::add($container);
    }

    /**
     * Cria (sem id) ou renomeia (com id) pela SurgeryRoomService.
     */
    public function onSave($param = null)
    {
        $this->loaded = true;

        try
        {
            $data = $this->form->getData();

            $this->form->validate();

            $context = self::resolveTenantContext();

            TTransaction::open('permission');

            $service = self::makeSurgeryRoomService($context);

            if (!empty($data->id))
            {
                $room = $service->update((int) $data->id, (string) $data->name, self::ACTION_SAVE);
            }
            else
            {
                $room = $service->create((string) $data->code, (string) $data->name, self::ACTION_SAVE);
            }

            TTransaction::close();

            $data->id = $room->id();
            $this->form->setData($data);

            TToast::show('success', _t('Record saved'));
            TScript::create("__adianti_goto_page('index.php?class=SurgeryRoomList')");
        }
        catch (\CentralVet\Authorization\Exception\AuthorizationDenied $e)
        {
            $this->form->setData($data ?? null);
            TTransaction::rollback();
            new TMessage('error', _t('You are not allowed to manage operating rooms'));
        }
        catch (\CentralVet\Tenancy\Exception\MissingTenantContext $e)
        {
            $this->form->setData($data ?? null);
            TTransaction::rollback();
            new TMessage('error', _t('An authenticated session with an active unit is required'));
        }
        catch (Exception $e)
        {
            $this->form->setData($data ?? null);
            TTransaction::rollback();
            error_log(__METHOD__ . ': ' . $e->getMessage());
            new TMessage('error', CvFormat::userError($e));
        }
    }

    /**
     * Carrega a sala pela SurgeryRoomService::get() (escopada ao tenant e
     * autorizada contra a unidade da sala). Sem id, formulário vazio.
     */
    public function onEdit($param = null)
    {
        $this->loaded = true;

        $room_id = self::paramId($param) ?? $this->roomId;

        if ($room_id === null)
        {
            $this->form->clear(true);
            return;
        }

        try
        {
            $context = self::resolveTenantContext();

            TTransaction::open('permission');

            $room = self::makeSurgeryRoomService($context)->get($room_id, self::ACTION_EDIT);

            TTransaction::close();

            $data = new stdClass;
            $data->id   = $room->id();
            $data->code = $room->code();
            $data->name = $room->name();

            $this->form->setData($data);
        }
        catch (\CentralVet\Authorization\Exception\AuthorizationDenied $e)
        {
            TTransaction::rollback();
            new TMessage('error', _t('You are not allowed to manage operating rooms'));
        }
        catch (\CentralVet\Tenancy\Exception\MissingTenantContext $e)
        {
            TTransaction::rollback();
            new TMessage('error', _t('An authenticated session with an active unit is required'));
        }
        catch (Exception $e)
        {
            TTransaction::rollback();
            error_log(__METHOD__ . ': ' . $e->getMessage());
            new TMessage('error', CvFormat::userError($e));
        }
    }

    /**
     * `index.php?class=SurgeryRoomForm&id=<id>` (sem method) carrega a sala aqui.
     */
    public function show()
    {
        if (!$this->loaded && $this->roomId !== null)
        {
            $this->onEdit(['id' => $this->roomId]);
        }

        parent::show();
    }

    /**
     * id da sala a partir de `id` ou `key`; null quando ausente/inválido.
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
     * Requires an already-open TTransaction('permission') connection.
     */
    private static function makeSurgeryRoomService(\CentralVet\Tenancy\TenantContext $context): \CentralVet\Application\SurgeryRoomService
    {
        $connection = TTransaction::get();

        return new \CentralVet\Application\SurgeryRoomService(
            new \CentralVet\Persistence\SurgeryRoomRepository($context, $connection),
            new \CentralVet\Authorization\RbacAuthorizationService(
                new \CentralVet\Authorization\AdiantiProgramPermissionProvider(new \CentralVet\Tenancy\AdiantiSessionContextSource()),
                new \CentralVet\Audit\PdoAuditLogWriter($connection),
            ),
            $context,
        );
    }

    /**
     * Resolves the tenant context of the authenticated session, with the
     * tenant_user fallback used by the other clinic screens.
     */
    private static function resolveTenantContext(): \CentralVet\Tenancy\TenantContext
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
