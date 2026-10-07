<?php
/**
 * SurgeryRoomList — salas cirúrgicas da unidade ativa (Fase 6B, T-12).
 *
 * Colunas Código, Nome e Situação (CvBadge ativa/inativa), com ações Editar
 * (SurgeryRoomForm&id=), Ativar e Desativar. Toda linha vem de
 * CentralVet\Application\SurgeryRoomService::listForCurrentUnit(); o service
 * e o repositório já restringem ao tenant e à unidade da sessão. Sem salas,
 * mostra o estado vazio `cv-state--empty` com o botão "Nova sala".
 *
 * Ativar/Desativar são estáticos: chamam o service com o próprio $action e
 * recarregam a lista (__adianti_goto_page).
 *
 * @version    1.0
 * @package    control
 * @subpackage clinic
 */
class SurgeryRoomList extends TPage
{
    private const ACTION_RELOAD = 'SurgeryRoomList::onReload';
    private const ACTION_ACTIVATE = 'SurgeryRoomList::onActivate';
    private const ACTION_DEACTIVATE = 'SurgeryRoomList::onDeactivate';

    protected $datagrid;
    protected $emptySlot;
    private bool $loaded = false;

    public function __construct($param = null)
    {
        parent::__construct();

        $this->datagrid = new BootstrapDatagridWrapper(new TDataGrid);
        CvDatagrid::decorate($this->datagrid, false);
        $this->datagrid->disableDefaultClick();
        $this->datagrid->setActionSide('right');

        $column_code   = new TDataGridColumn('code', _t('Code'), 'left', 120);
        $column_name   = new TDataGridColumn('name', _t('Name'), 'left');
        $column_status = new TDataGridColumn('status', _t('Status'), 'left', 130);

        // O TDataGrid passa ao transformer o valor cru: este e() é o único escape.
        $column_code->setTransformer(static fn ($value) => CvFormat::e((string) $value));
        $column_name->setTransformer(static fn ($value) => CvFormat::e((string) $value));
        $column_status->setTransformer(static fn ($value) => self::statusBadge((string) $value));

        $this->datagrid->addColumn($column_code);
        $this->datagrid->addColumn($column_name);
        $this->datagrid->addColumn($column_status);

        // Ações como botões visíveis (não dropdown): alvo de toque de 44 px no tablet.
        $action_edit = new TDataGridAction(['SurgeryRoomForm', 'onEdit'], ['id' => '{id}', 'register_state' => 'false']);
        $action_activate = new TDataGridAction([__CLASS__, 'onActivate'], ['id' => '{id}', 'static' => '1', 'register_state' => 'false']);
        $action_activate->setDisplayCondition([__CLASS__, 'canActivate']);
        $action_deactivate = new TDataGridAction([__CLASS__, 'onDeactivate'], ['id' => '{id}', 'static' => '1', 'register_state' => 'false']);
        $action_deactivate->setDisplayCondition([__CLASS__, 'canDeactivate']);

        foreach ([
            [$action_edit, _t('Edit'), 'far:edit'],
            [$action_activate, _t('Activate'), 'fa:toggle-on'],
            [$action_deactivate, _t('Deactivate'), 'fa:toggle-off'],
        ] as [$action, $label, $icon])
        {
            $action->setLabel($label);
            $action->setImage($icon);
            $action->setUseButton(true);
            $action->setButtonClass('btn btn-sm btn-outline-secondary cv-touch-target');
            $this->datagrid->addAction($action);
        }

        $this->datagrid->createModel();

        $this->emptySlot = new TElement('div');

        $container = new TVBox;
        $container->style = 'width: 100%';
        $container->add(CvPage::header(_t('Operating rooms'), _t('Surgery'), [
            ['label' => _t('New operating room'), 'href' => 'index.php?class=SurgeryRoomForm', 'icon' => 'fa:plus', 'class' => 'btn btn-primary'],
        ]));

        // Grupo 'surgery' é criado pela T-18; até lá, sem abas.
        try
        {
            $container->add(CvNav::tabs('surgery', 'rooms'));
        }
        catch (InvalidArgumentException $e)
        {
            // grupo ainda inexistente: tela sem abas
        }

        $container->add($this->datagrid);
        $container->add($this->emptySlot);

        parent::add($container);
    }

    /**
     * Carrega as salas da unidade da sessão.
     */
    public function onReload($param = null)
    {
        $this->loaded = true;

        try
        {
            $context = self::resolveTenantContext();

            TTransaction::open('permission');

            $rooms = self::makeSurgeryRoomService($context)->listForCurrentUnit(self::ACTION_RELOAD);

            TTransaction::close();

            $this->datagrid->clear();
            foreach ($rooms as $room)
            {
                $row = new stdClass;
                $row->id     = $room->id();
                $row->code   = $room->code();
                $row->name   = $room->name();
                $row->status = $room->status();

                $this->datagrid->addItem($row);
            }

            if ($rooms === [])
            {
                $this->datagrid->style = 'display: none';
                $this->emptySlot->add(self::emptyState());
            }
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

    public static function onActivate($param)
    {
        self::changeStatus($param, true);
    }

    public static function onDeactivate($param)
    {
        self::changeStatus($param, false);
    }

    /** Ativar só aparece para sala inativa. */
    public static function canActivate($object): bool
    {
        return ($object->status ?? null) === \CentralVet\Domain\SurgeryRoom::STATUS_INACTIVE;
    }

    /** Desativar só aparece para sala ativa. */
    public static function canDeactivate($object): bool
    {
        return ($object->status ?? null) === \CentralVet\Domain\SurgeryRoom::STATUS_ACTIVE;
    }

    public function show()
    {
        if (!$this->loaded)
        {
            $this->onReload();
        }

        parent::show();
    }

    private static function changeStatus($param, bool $activate): void
    {
        try
        {
            $room_id = (int) ($param['id'] ?? ($param['key'] ?? 0));

            $context = self::resolveTenantContext();

            TTransaction::open('permission');

            $service = self::makeSurgeryRoomService($context);

            if ($activate)
            {
                $service->activate($room_id, self::ACTION_ACTIVATE);
            }
            else
            {
                $service->deactivate($room_id, self::ACTION_DEACTIVATE);
            }

            TTransaction::close();

            TToast::show('success', $activate ? _t('Operating room activated') : _t('Operating room deactivated'));
            TScript::create("__adianti_goto_page('index.php?class=SurgeryRoomList')");
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

    private static function statusBadge(string $status): TElement
    {
        return $status === \CentralVet\Domain\SurgeryRoom::STATUS_ACTIVE
            ? CvBadge::create(_t('Active operating room'), 'success')
            : CvBadge::create(_t('Inactive operating room'), 'neutral');
    }

    private static function emptyState(): TElement
    {
        $state = new TElement('div');
        $state->{'class'} = 'cv-state cv-state--empty';
        $state->add(TElement::tag('p', CvFormat::e(_t('No operating rooms registered in this unit')), ['class' => 'cv-state__title']));

        $button = TElement::tag('a', CvFormat::e(_t('New operating room')), [
            'class'     => 'btn btn-primary cv-touch-target',
            'href'      => 'index.php?class=SurgeryRoomForm',
            'generator' => 'adianti',
        ]);
        $state->add($button);

        return $state;
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
