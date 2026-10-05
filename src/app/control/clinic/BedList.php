<?php
/**
 * BedList — leitos da unidade ativa (Fase 6A, T-12).
 *
 * Colunas Código, Nome, Diária e Situação (CvBadge disponível/ocupado/
 * inativo), com ações Editar (BedForm&id=), Ativar e Desativar. Toda linha
 * vem de CentralVet\Application\BedService::listForCurrentUnit(); o service
 * e o repositório já restringem ao tenant e à unidade da sessão. Sem leitos,
 * mostra o estado vazio `cv-state--empty` com o botão "Novo leito".
 *
 * Ativar/Desativar são estáticos: chamam o service com o próprio $action e
 * recarregam a lista (__adianti_goto_page). Desativar leito ocupado é
 * recusado pelo domínio (BedUnavailableException → TMessage).
 *
 * @version    1.0
 * @package    control
 * @subpackage clinic
 */
class BedList extends TPage
{
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
        $column_rate   = new TDataGridColumn('daily_rate_cents', _t('Daily rate'), 'right', 140);
        $column_status = new TDataGridColumn('status', _t('Status'), 'left', 130);

        // O TDataGrid passa ao transformer o valor cru: este e() é o único escape.
        $column_code->setTransformer(static fn ($value) => CvFormat::e((string) $value));
        $column_name->setTransformer(static fn ($value) => CvFormat::e((string) $value));
        $column_rate->setTransformer(static fn ($value) => CvFormat::e(CvFormat::money((int) $value)));
        $column_status->setTransformer(static fn ($value) => self::statusBadge((string) $value));

        $this->datagrid->addColumn($column_code);
        $this->datagrid->addColumn($column_name);
        $this->datagrid->addColumn($column_rate);
        $this->datagrid->addColumn($column_status);

        $action_edit = new TDataGridAction(['BedForm', 'onEdit'], ['id' => '{id}', 'register_state' => 'false']);
        $action_activate = new TDataGridAction([__CLASS__, 'onActivate'], ['id' => '{id}', 'static' => '1', 'register_state' => 'false']);
        $action_activate->setDisplayCondition([__CLASS__, 'canActivate']);
        $action_deactivate = new TDataGridAction([__CLASS__, 'onDeactivate'], ['id' => '{id}', 'static' => '1', 'register_state' => 'false']);
        $action_deactivate->setDisplayCondition([__CLASS__, 'canDeactivate']);

        $this->datagrid->addActionGroup(CvDatagrid::actionMenu([
            ['label' => _t('Edit'), 'action' => $action_edit, 'icon' => 'far:edit'],
            ['label' => _t('Activate'), 'action' => $action_activate, 'icon' => 'fa:toggle-on'],
            ['label' => _t('Deactivate'), 'action' => $action_deactivate, 'icon' => 'fa:toggle-off'],
        ]));

        $this->datagrid->createModel();

        $this->emptySlot = new TElement('div');

        $container = new TVBox;
        $container->style = 'width: 100%';
        $container->add(CvPage::header(_t('Beds'), _t('Hospitalization'), [
            ['label' => _t('New bed'), 'href' => 'index.php?class=BedForm', 'icon' => 'fa:plus', 'class' => 'btn btn-primary'],
        ]));

        // Grupo 'hospitalization' é criado pela T-17; até lá, sem abas.
        try
        {
            $container->add(CvNav::tabs('hospitalization', 'beds'));
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
     * Carrega os leitos da unidade da sessão.
     */
    public function onReload($param = null)
    {
        $this->loaded = true;

        try
        {
            $context = self::resolveTenantContext();

            TTransaction::open('permission');

            $beds = self::makeBedService($context)->listForCurrentUnit('BedList::onReload');

            TTransaction::close();

            $this->datagrid->clear();
            foreach ($beds as $bed)
            {
                $row = new stdClass;
                $row->id               = $bed->id();
                $row->code             = $bed->code();
                $row->name             = $bed->name();
                $row->daily_rate_cents = $bed->dailyRateCents();
                $row->status           = $bed->status();

                $this->datagrid->addItem($row);
            }

            if ($beds === [])
            {
                $this->datagrid->style = 'display: none';
                $this->emptySlot->add(self::emptyState());
            }
        }
        catch (\CentralVet\Authorization\Exception\AuthorizationDenied $e)
        {
            TTransaction::rollback();
            new TMessage('error', _t('You are not allowed to manage beds'));
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

    /** Ativar só aparece para leito inativo. */
    public static function canActivate($object): bool
    {
        return ($object->status ?? null) === \CentralVet\Domain\Bed::STATUS_INACTIVE;
    }

    /** Desativar só aparece para leito disponível (ocupado é recusado pelo domínio). */
    public static function canDeactivate($object): bool
    {
        return ($object->status ?? null) === \CentralVet\Domain\Bed::STATUS_AVAILABLE;
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
            $bed_id = (int) ($param['id'] ?? ($param['key'] ?? 0));

            $context = self::resolveTenantContext();

            TTransaction::open('permission');

            $service = self::makeBedService($context);

            if ($activate)
            {
                $service->activate($bed_id, 'BedList::onActivate');
            }
            else
            {
                $service->deactivate($bed_id, 'BedList::onDeactivate');
            }

            TTransaction::close();

            TToast::show('success', $activate ? _t('Bed activated') : _t('Bed deactivated'));
            TScript::create("__adianti_goto_page('index.php?class=BedList')");
        }
        catch (\CentralVet\Authorization\Exception\AuthorizationDenied $e)
        {
            TTransaction::rollback();
            new TMessage('error', _t('You are not allowed to manage beds'));
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
        return match ($status)
        {
            \CentralVet\Domain\Bed::STATUS_AVAILABLE => CvBadge::create(_t('Available'), 'success'),
            \CentralVet\Domain\Bed::STATUS_OCCUPIED  => CvBadge::create(_t('Occupied'), 'warning'),
            default                                  => CvBadge::create(_t('Inactive'), 'neutral'),
        };
    }

    private static function emptyState(): TElement
    {
        $state = new TElement('div');
        $state->{'class'} = 'cv-state cv-state--empty';
        $state->add(TElement::tag('p', CvFormat::e(_t('No beds registered in this unit')), ['class' => 'cv-state__title']));

        $button = TElement::tag('a', CvFormat::e(_t('New bed')), [
            'class'     => 'btn btn-primary',
            'href'      => 'index.php?class=BedForm',
            'generator' => 'adianti',
        ]);
        $state->add($button);

        return $state;
    }

    /**
     * Requires an already-open TTransaction('permission') connection.
     */
    private static function makeBedService(\CentralVet\Tenancy\TenantContext $context): \CentralVet\Application\BedService
    {
        $connection = TTransaction::get();

        return new \CentralVet\Application\BedService(
            new \CentralVet\Persistence\BedRepository($context, $connection),
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
