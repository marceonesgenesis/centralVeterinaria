<?php
/**
 * ClinicUserList — equipe da clínica da sessão (Etapa 3, T-24).
 *
 * Grade só de CentralVet\Tenancy\ClinicTeamService::listUsers(): nome,
 * e-mail, situação (convidado, ativo, inativo) e grupo. Ações Editar
 * (ClinicUserForm::onEdit), Desativar e Reativar (ClinicUserForm::onDeactivate
 * e ::onReactivate, mutações pela MutationRequestPolicy) e o botão
 * "Convidar usuário". Nunca lê SystemUser/SystemGroup sem o tenant: as telas
 * de sistema ficam só para o grupo 1.
 *
 * @version    1.0
 * @package    control
 * @subpackage admin
 */
class ClinicUserList extends TPage
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

        $column_name   = new TDataGridColumn('name', _t('Name'), 'left');
        $column_email  = new TDataGridColumn('email', _t('E-mail'), 'left');
        $column_status = new TDataGridColumn('status', _t('Status'), 'left', 130);
        $column_group  = new TDataGridColumn('group', _t('Group'), 'left');

        // O TDataGrid passa ao transformer o valor cru: este e() é o único escape.
        $column_name->setTransformer(static fn ($value) => CvFormat::e((string) $value));
        $column_email->setTransformer(static fn ($value) => CvFormat::e((string) $value));
        $column_status->setTransformer(static fn ($value) => self::statusBadge((string) $value));
        $column_group->setTransformer(static fn ($value) => CvFormat::e((string) $value));

        foreach ([$column_name, $column_email, $column_status, $column_group] as $column)
        {
            $this->datagrid->addColumn($column);
        }

        $action_edit = new TDataGridAction(['ClinicUserForm', 'onEdit'], ['id' => '{id}', 'register_state' => 'false']);
        $action_deactivate = new TDataGridAction(['ClinicUserForm', 'onDeactivate'], ['id' => '{id}', 'static' => '1', 'register_state' => 'false']);
        $action_deactivate->setDisplayCondition([__CLASS__, 'canDeactivate']);
        $action_reactivate = new TDataGridAction(['ClinicUserForm', 'onReactivate'], ['id' => '{id}', 'static' => '1', 'register_state' => 'false']);
        $action_reactivate->setDisplayCondition([__CLASS__, 'canReactivate']);

        // Ações como botões visíveis (não dropdown): alvo de toque de 44 px.
        foreach ([
            [$action_edit, _t('Edit'), 'far:edit'],
            [$action_deactivate, _t('Deactivate'), 'fa:toggle-off'],
            [$action_reactivate, _t('Reactivate'), 'fa:toggle-on'],
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
        $container->add(CvPage::header(_t('Team'), _t('Administration'), [
            ['label' => _t('Invite user'), 'href' => 'index.php?class=ClinicUserForm', 'icon' => 'fa:user-plus', 'class' => 'btn btn-primary cv-touch-target'],
        ]));
        $container->add($this->datagrid);
        $container->add($this->emptySlot);

        parent::add($container);
    }

    /**
     * Carrega a equipe do tenant da sessão.
     */
    public function onReload($param = null)
    {
        $this->loaded = true;

        try
        {
            $users = ClinicUserForm::makeService()->listUsers();

            $this->datagrid->clear();
            foreach ($users as $user)
            {
                $row = new stdClass;
                $row->id     = $user['id'];
                $row->name   = $user['name'];
                $row->email  = $user['email'];
                $row->status = $user['status'];
                $row->group  = $user['group'] ?? '';
                $this->datagrid->addItem($row);
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
    }

    /** Desativar só aparece para convidado ou ativo. */
    public static function canDeactivate($object): bool
    {
        return in_array($object->status ?? null, ['invited', 'active'], true);
    }

    /** Reativar só aparece para inativo. */
    public static function canReactivate($object): bool
    {
        return ($object->status ?? null) === 'inactive';
    }

    public function show()
    {
        if (!$this->loaded)
        {
            $this->onReload();
        }

        parent::show();
    }

    private static function statusBadge(string $status): TElement
    {
        return match ($status)
        {
            'active'  => CvBadge::create(_t('Active'), 'success'),
            'invited' => CvBadge::create(_t('Invited'), 'info'),
            default   => CvBadge::create(_t('Inactive'), 'neutral'),
        };
    }
}
