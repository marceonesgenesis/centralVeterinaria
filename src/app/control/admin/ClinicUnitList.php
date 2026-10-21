<?php
/**
 * ClinicUnitList — unidades da clínica da sessão (Etapa 3, T-25).
 *
 * Grade só de CentralVet\Tenancy\ClinicUnitService::listUnits(): nome e
 * número de usuários. Ação Editar (ClinicUnitForm::onEdit) e o botão
 * "Nova unidade"; o limite de unidades do plano é aplicado no
 * ClinicUnitService::create. Nunca lê SystemUnit sem o tenant: a tela de
 * sistema fica só para o grupo 1.
 *
 * @version    1.0
 * @package    control
 * @subpackage admin
 */
class ClinicUnitList extends TPage
{
    protected $datagrid;
    private bool $loaded = false;

    public function __construct($param = null)
    {
        parent::__construct();

        $this->datagrid = new BootstrapDatagridWrapper(new TDataGrid);
        CvDatagrid::decorate($this->datagrid, false);
        $this->datagrid->disableDefaultClick();
        $this->datagrid->setActionSide('right');

        $column_name  = new TDataGridColumn('name', _t('Name'), 'left');
        $column_users = new TDataGridColumn('users', _t('Users'), 'left', 130);

        // O TDataGrid passa ao transformer o valor cru: este e() é o único escape.
        $column_name->setTransformer(static fn ($value) => CvFormat::e((string) $value));
        $column_users->setTransformer(static fn ($value) => CvFormat::e((string) (int) $value));

        $this->datagrid->addColumn($column_name);
        $this->datagrid->addColumn($column_users);

        // Ação como botão visível (não dropdown): alvo de toque de 44 px.
        $action_edit = new TDataGridAction(['ClinicUnitForm', 'onEdit'], ['id' => '{id}', 'register_state' => 'false']);
        $action_edit->setLabel(_t('Edit'));
        $action_edit->setImage('far:edit');
        $action_edit->setUseButton(true);
        $action_edit->setButtonClass('btn btn-sm btn-outline-secondary cv-touch-target');
        $this->datagrid->addAction($action_edit);

        $this->datagrid->createModel();

        $container = new TVBox;
        $container->style = 'width: 100%';
        $container->add(CvPage::header(_t('Clinic units'), _t('Administration'), [
            ['label' => _t('New unit'), 'href' => 'index.php?class=ClinicUnitForm', 'icon' => 'fa:plus', 'class' => 'btn btn-primary cv-touch-target'],
        ]));
        $container->add($this->datagrid);

        parent::add($container);
    }

    /**
     * Carrega as unidades do tenant da sessão.
     */
    public function onReload($param = null)
    {
        $this->loaded = true;

        try
        {
            $units = ClinicUnitForm::makeService()->listUnits();

            $this->datagrid->clear();
            foreach ($units as $unit)
            {
                $row = new stdClass;
                $row->id    = $unit['id'];
                $row->name  = $unit['name'];
                $row->users = $unit['users'];
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

    public function show()
    {
        if (!$this->loaded)
        {
            $this->onReload();
        }

        parent::show();
    }
}
