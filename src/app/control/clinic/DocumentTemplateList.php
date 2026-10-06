<?php
/**
 * DocumentTemplateList — templates de documento do tenant (Fase 7B, T-17).
 *
 * Colunas Nome, Tipo (Atestado), Situação (CvBadge ativo/inativo) e Ações
 * (Editar → DocumentTemplateForm&id=). Toda linha vem de
 * CentralVet\Application\DocumentTemplateService::listAll(); o repositório
 * já restringe ao tenant da sessão. Sem templates, mostra o estado vazio
 * `cv-state--empty` com o botão "Novo template". A ação é um link próprio
 * (o <a> leva cv-touch-target, 44 px no tablet).
 *
 * @version    1.0
 * @package    control
 * @subpackage clinic
 */
class DocumentTemplateList extends TPage
{
    private const ACTION_RELOAD = 'DocumentTemplateList::onReload';

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
        $column_kind   = new TDataGridColumn('kind', _t('Kind'), 'left', 200);
        $column_status = new TDataGridColumn('status', _t('Status'), 'left', 130);

        // O TDataGrid passa ao transformer o valor cru: este e() é o único escape.
        $column_name->setTransformer(static fn ($value) => CvFormat::e((string) $value));
        $column_kind->setTransformer(static fn ($value) => CvFormat::e(DocumentTemplateForm::kindLabel((string) $value)));
        $column_status->setTransformer(static fn ($value) => self::statusBadge((string) $value));

        $this->datagrid->addColumn($column_name);
        $this->datagrid->addColumn($column_kind);
        $this->datagrid->addColumn($column_status);

        // Ação como link próprio numa coluna: o <a> é o alvo de toque de 44 px
        // (as ações do TDataGrid põem a classe só num <span> interno).
        $column_actions = new TDataGridColumn('id', _t('Actions'), 'right');
        $column_actions->setTransformer(static fn ($value, $object) => self::actionLinks($object));
        $this->datagrid->addColumn($column_actions);

        $this->datagrid->createModel();

        $this->emptySlot = new TElement('div');

        $container = new TVBox;
        $container->style = 'width: 100%';
        $container->add(CvPage::header(_t('Document templates'), _t('Documents'), [
            ['label' => _t('New template'), 'href' => 'index.php?class=DocumentTemplateForm', 'icon' => 'fa:plus', 'class' => 'btn btn-primary'],
        ]));
        $container->add($this->datagrid);
        $container->add($this->emptySlot);

        parent::add($container);
    }

    /**
     * Carrega os templates do tenant da sessão.
     */
    public function onReload($param = null)
    {
        $this->loaded = true;

        try
        {
            $context = self::resolveTenantContext();

            TTransaction::open('permission');

            $templates = self::makeDocumentTemplateService($context)->listAll(self::ACTION_RELOAD);

            TTransaction::close();

            $this->datagrid->clear();
            foreach ($templates as $template)
            {
                $row = new stdClass;
                $row->id     = $template->id();
                $row->name   = $template->name();
                $row->kind   = $template->kind();
                $row->status = $template->status();

                $this->datagrid->addItem($row);
            }

            if ($templates === [])
            {
                $this->datagrid->style = 'display: none';
                $this->emptySlot->add(self::emptyState());
            }
        }
        catch (\CentralVet\Authorization\Exception\AuthorizationDenied $e)
        {
            TTransaction::rollback();
            new TMessage('error', _t('You are not allowed to manage document templates'));
        }
        catch (\CentralVet\Tenancy\Exception\MissingTenantContext $e)
        {
            TTransaction::rollback();
            new TMessage('error', _t('An authenticated session with an active unit is required'));
        }
        catch (Exception $e)
        {
            TTransaction::rollback();
            error_log(__METHOD__ . ': ' . get_class($e));
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

    /**
     * Editar (DocumentTemplateForm::onEdit), um <a> com cv-touch-target. A URL leva só o id.
     */
    private static function actionLinks($object): TElement
    {
        $id = (int) ($object->id ?? 0);

        $links = new TElement('div');
        $links->style = 'display:flex; flex-wrap:wrap; gap:var(--cv-space-2); justify-content:flex-end';

        $label = _t('Edit');
        $link = new TElement('a');
        $link->{'class'} = 'btn btn-sm btn-outline-secondary cv-touch-target';
        $link->{'href'} = CvFormat::e('index.php?class=DocumentTemplateForm&method=onEdit&id=' . $id . '&register_state=false');
        $link->{'generator'} = 'adianti';
        $link->{'title'} = CvFormat::e($label);
        $link->add(new TImage('far:edit'));
        $link->add(TElement::tag('span', CvFormat::e($label)));
        $links->add($link);

        return $links;
    }

    private static function statusBadge(string $status): TElement
    {
        return $status === \CentralVet\Domain\DocumentTemplate::STATUS_ACTIVE
            ? CvBadge::create(_t('Active'), 'success')
            : CvBadge::create(_t('Inactive'), 'neutral');
    }

    private static function emptyState(): TElement
    {
        $state = new TElement('div');
        $state->{'class'} = 'cv-state cv-state--empty';
        $state->add(TElement::tag('p', CvFormat::e(_t('No document templates registered')), ['class' => 'cv-state__title']));

        $button = TElement::tag('a', CvFormat::e(_t('New template')), [
            'class'     => 'btn btn-primary cv-touch-target',
            'href'      => 'index.php?class=DocumentTemplateForm',
            'generator' => 'adianti',
        ]);
        $state->add($button);

        return $state;
    }

    /**
     * Requires an already-open TTransaction('permission') connection.
     */
    private static function makeDocumentTemplateService(\CentralVet\Tenancy\TenantContext $context): \CentralVet\Application\DocumentTemplateService
    {
        $connection = TTransaction::get();

        return new \CentralVet\Application\DocumentTemplateService(
            new \CentralVet\Persistence\DocumentTemplateRepository($context, $connection),
            new \CentralVet\Persistence\DocumentSourceQuery($context, $connection),
            new \CentralVet\Persistence\SenderNamesQuery($context, $connection),
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
