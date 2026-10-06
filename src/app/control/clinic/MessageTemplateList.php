<?php
/**
 * MessageTemplateList — templates de mensagem do tenant (Fase 7A, T-16).
 *
 * Colunas Nome, Finalidade, Canal e Situação (CvBadge ativo/inativo), com
 * ações Editar (MessageTemplateForm&id=), Ativar e Desativar. Toda linha vem
 * de CentralVet\Application\MessageTemplateService::list(); o repositório já
 * restringe ao tenant da sessão. Sem templates, mostra o estado vazio
 * `cv-state--empty` com o botão "Novo template".
 *
 * Ativar/Desativar são estáticos e passam por onToggle (`active` = 1/0):
 * chamam MessageTemplateService::setActive() e recarregam a lista. As ações
 * são links próprios (o <a> leva cv-touch-target, 44 px no tablet).
 *
 * @version    1.0
 * @package    control
 * @subpackage clinic
 */
class MessageTemplateList extends TPage
{
    private const ACTION_RELOAD = 'MessageTemplateList::onReload';
    private const ACTION_TOGGLE = 'MessageTemplateList::onToggle';

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

        $column_name    = new TDataGridColumn('name', _t('Name'), 'left');
        $column_purpose = new TDataGridColumn('purpose', _t('Purpose'), 'left', 200);
        $column_channel = new TDataGridColumn('channel', _t('Channel'), 'left', 120);
        $column_status  = new TDataGridColumn('status', _t('Status'), 'left', 130);

        // O TDataGrid passa ao transformer o valor cru: este e() é o único escape.
        $column_name->setTransformer(static fn ($value) => CvFormat::e((string) $value));
        $column_purpose->setTransformer(static fn ($value) => CvFormat::e(MessageTemplateForm::purposeLabel((string) $value)));
        $column_channel->setTransformer(static fn ($value) => CvFormat::e(MessageTemplateForm::channelLabel((string) $value)));
        $column_status->setTransformer(static fn ($value) => self::statusBadge((string) $value));

        $this->datagrid->addColumn($column_name);
        $this->datagrid->addColumn($column_purpose);
        $this->datagrid->addColumn($column_channel);
        $this->datagrid->addColumn($column_status);

        // Ações como links próprios numa coluna (padrão do CommunicationMessageList):
        // o <a> é o alvo de toque de 44 px. As ações do TDataGrid põem a classe só
        // num <span> interno, e o <a> inline ficava com 19 px no tablet.
        $column_actions = new TDataGridColumn('id', '', 'right');
        $column_actions->setTransformer(static fn ($value, $object) => self::actionLinks($object));
        $this->datagrid->addColumn($column_actions);

        $this->datagrid->createModel();

        $this->emptySlot = new TElement('div');

        $container = new TVBox;
        $container->style = 'width: 100%';
        $container->add(CvPage::header(_t('Message templates'), _t('Communication'), [
            ['label' => _t('New template'), 'href' => 'index.php?class=MessageTemplateForm', 'icon' => 'fa:plus', 'class' => 'btn btn-primary'],
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

            $templates = self::makeMessageTemplateService($context)->list(self::ACTION_RELOAD);

            TTransaction::close();

            $this->datagrid->clear();
            foreach ($templates as $template)
            {
                $row = new stdClass;
                $row->id      = $template->id();
                $row->name    = $template->name();
                $row->purpose = $template->purpose();
                $row->channel = $template->channel();
                $row->status  = $template->status();

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
            new TMessage('error', _t('You are not allowed to manage message templates'));
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

    /**
     * Ativa (`active` = 1) ou desativa (`active` = 0) o template `id`.
     */
    public static function onToggle($param)
    {
        try
        {
            $template_id = (int) ($param['id'] ?? ($param['key'] ?? 0));
            $activate = (string) ($param['active'] ?? '') === '1';

            $context = self::resolveTenantContext();

            TTransaction::open('permission');

            self::makeMessageTemplateService($context)->setActive($template_id, $activate, self::ACTION_TOGGLE);

            TTransaction::close();

            TToast::show('success', $activate ? _t('Message template activated') : _t('Message template deactivated'));
            TScript::create("__adianti_goto_page('index.php?class=MessageTemplateList')");
        }
        catch (\CentralVet\Authorization\Exception\AuthorizationDenied $e)
        {
            TTransaction::rollback();
            new TMessage('error', _t('You are not allowed to manage message templates'));
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

    /** Ativar só aparece para template inativo. */
    public static function canActivate($object): bool
    {
        return ($object->status ?? null) === \CentralVet\Domain\MessageTemplate::STATUS_INACTIVE;
    }

    /** Desativar só aparece para template ativo. */
    public static function canDeactivate($object): bool
    {
        return ($object->status ?? null) === \CentralVet\Domain\MessageTemplate::STATUS_ACTIVE;
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
     * Editar (MessageTemplateForm::onEdit) e Ativar ou Desativar (onToggle,
     * estático), cada um um <a> com cv-touch-target. A URL leva só o id.
     */
    private static function actionLinks($object): TElement
    {
        $id = (int) ($object->id ?? 0);

        $links = new TElement('div');
        $links->style = 'display:flex; flex-wrap:wrap; gap:var(--cv-space-2); justify-content:flex-end';

        $links->add(self::actionLink(
            _t('Edit'),
            'far:edit',
            'index.php?class=MessageTemplateForm&method=onEdit&id=' . $id . '&register_state=false'
        ));

        if (self::canDeactivate($object))
        {
            $links->add(self::actionLink(
                _t('Deactivate'),
                'fa:toggle-off',
                'index.php?class=MessageTemplateList&method=onToggle&id=' . $id . '&active=0&static=1&register_state=false'
            ));
        }
        elseif (self::canActivate($object))
        {
            $links->add(self::actionLink(
                _t('Activate'),
                'fa:toggle-on',
                'index.php?class=MessageTemplateList&method=onToggle&id=' . $id . '&active=1&static=1&register_state=false'
            ));
        }

        return $links;
    }

    private static function actionLink(string $label, string $icon, string $href): TElement
    {
        $link = new TElement('a');
        $link->{'class'} = 'btn btn-sm btn-outline-secondary cv-touch-target';
        $link->{'href'} = CvFormat::e($href);
        $link->{'generator'} = 'adianti';
        $link->{'title'} = CvFormat::e($label);
        $link->add(new TImage($icon));
        $link->add(TElement::tag('span', CvFormat::e($label)));

        return $link;
    }

    private static function statusBadge(string $status): TElement
    {
        return $status === \CentralVet\Domain\MessageTemplate::STATUS_ACTIVE
            ? CvBadge::create(_t('Active'), 'success')
            : CvBadge::create(_t('Inactive'), 'neutral');
    }

    private static function emptyState(): TElement
    {
        $state = new TElement('div');
        $state->{'class'} = 'cv-state cv-state--empty';
        $state->add(TElement::tag('p', CvFormat::e(_t('No message templates registered')), ['class' => 'cv-state__title']));

        $button = TElement::tag('a', CvFormat::e(_t('New template')), [
            'class'     => 'btn btn-primary cv-touch-target',
            'href'      => 'index.php?class=MessageTemplateForm',
            'generator' => 'adianti',
        ]);
        $state->add($button);

        return $state;
    }

    /**
     * Requires an already-open TTransaction('permission') connection.
     */
    private static function makeMessageTemplateService(\CentralVet\Tenancy\TenantContext $context): \CentralVet\Application\MessageTemplateService
    {
        $connection = TTransaction::get();

        return new \CentralVet\Application\MessageTemplateService(
            new \CentralVet\Persistence\MessageTemplateRepository($context, $connection),
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
