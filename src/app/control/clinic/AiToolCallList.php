<?php
/**
 * AiToolCallList — auditoria das ferramentas do copiloto para o gestor
 * (Fase 8B, T-15).
 *
 * Filtros por POST (usuário, ferramenta, decisão e período); colunas data,
 * usuário, ferramenta, decisão, motivo, linhas, truncado, duração e id de
 * correlação. Nunca mostra o `input_minimized` nem o texto das conversas.
 * Leitura por CentralVet\Application\AiToolAuditService::search (RBAC no
 * programa AiToolCallList, tenant inteiro, até 200 chamadas, mais novas
 * primeiro); nomes de usuário em lote, filtrados pelo tenant.
 *
 * @version    1.0
 * @package    control
 * @subpackage clinic
 */
class AiToolCallList extends TPage
{
    private const LIMIT = 200;
    private const FORM_NAME = 'form_AiToolCallList_filter';
    private const FILTERS = ['user_id', 'tool_name', 'decision', 'date_from', 'date_to'];

    private bool $loaded = false;

    public function __construct($param = null)
    {
        parent::__construct();

        // com method, o dispatcher chama onReload(), que já carrega
        if (empty($param['method']))
        {
            $this->onReload();
        }
    }

    /**
     * Recarrega a auditoria com os filtros do corpo do POST (a query string
     * nunca filtra; valores inválidos são ignorados pelo serviço).
     */
    public function onReload($param = null)
    {
        if ($this->loaded)
        {
            return;
        }
        $this->loaded = true;

        $filters = self::postedFilters();

        $container = new TVBox;
        $container->style = 'width: 100%';
        $container->add(CvPage::header(_t('Tool audit'), _t('AI assistant'), [
            ['icon' => 'fa:arrow-left', 'href' => 'index.php?class=AiSettingsForm', 'class' => 'btn btn-default cv-touch-target', 'title' => _t('Back')],
        ], false));

        try
        {
            $context = static::resolveTenantContext();

            [$calls, $users] = static::transaction(static function (PDO $connection) use ($context, $filters): array
            {
                $calls = static::makeService($context, $connection)->search($filters, self::LIMIT);

                return [$calls, static::tenantUsers($context, $connection)];
            });
        }
        catch (\CentralVet\Authorization\Exception\AuthorizationDenied $e)
        {
            new TMessage('error', CvFormat::e(_t('Permission denied')));
            parent::add($container);
            return;
        }
        catch (\CentralVet\Tenancy\Exception\MissingTenantContext $e)
        {
            new TMessage('error', CvFormat::e(_t('An authenticated session with an active unit is required')));
            parent::add($container);
            return;
        }
        catch (Exception $e)
        {
            error_log(__METHOD__ . ': ' . get_class($e));
            new TMessage('error', CvFormat::e(_t('Could not complete the operation. Please try again')));
            parent::add($container);
            return;
        }

        $container->add($this->filter($filters, $users));
        $container->add($calls === [] ? self::emptyState() : self::table($calls, $users));

        parent::add($container);
    }

    public static function decisionLabel(string $decision): string
    {
        return match ($decision)
        {
            \CentralVet\Domain\McpToolCall::DECISION_ALLOWED => _t('Allowed'),
            \CentralVet\Domain\McpToolCall::DECISION_DENIED  => _t('Denied'),
            default                                          => $decision,
        };
    }

    /**
     * @return array<string, string>
     */
    private static function postedFilters(): array
    {
        $filters = [];
        foreach (self::FILTERS as $key)
        {
            $value = trim((string) ($_POST[$key] ?? ''));
            if ($value !== '')
            {
                $filters[$key] = $value;
            }
        }

        return $filters;
    }

    /**
     * @param array<string, string> $filters
     * @param array<int, string> $users
     */
    private function filter(array $filters, array $users): TForm
    {
        $form = new TForm(self::FORM_NAME);

        $user = new TCombo('user_id');
        $user->addItems($users); // TCombo escapa os rótulos

        $tools = [];
        foreach (\CentralVet\Ai\Tools\ToolFamily::ALLOWLIST as $names)
        {
            foreach ($names as $name)
            {
                $tools[$name] = $name;
            }
        }
        $tool = new TCombo('tool_name');
        $tool->addItems($tools);

        $decision = new TCombo('decision');
        $decision->addItems([
            \CentralVet\Domain\McpToolCall::DECISION_ALLOWED => self::decisionLabel(\CentralVet\Domain\McpToolCall::DECISION_ALLOWED),
            \CentralVet\Domain\McpToolCall::DECISION_DENIED  => self::decisionLabel(\CentralVet\Domain\McpToolCall::DECISION_DENIED),
        ]);

        $from = new TDate('date_from');
        $from->setMask('dd/mm/yyyy');
        $to = new TDate('date_to');
        $to->setMask('dd/mm/yyyy');

        $fields = ['user_id' => $user, 'tool_name' => $tool, 'decision' => $decision, 'date_from' => $from, 'date_to' => $to];
        foreach ($fields as $key => $field)
        {
            $field->setSize('100%');
            $field->setValue($filters[$key] ?? '');
        }

        $button = new TButton('filter');
        $button->setAction(new TAction([$this, 'onReload']), _t('Apply'));
        $button->setImage('fa:filter');
        $button->class = 'btn btn-primary cv-touch-target';

        $form->setFields(array_merge(array_values($fields), [$button]));

        $boxes = [];
        foreach ([_t('User') => $user, _t('Tool') => $tool, _t('Decision') => $decision, _t('Start date') => $from, _t('End date') => $to] as $label => $field)
        {
            $box = new TElement('div');
            $box->add(new TLabel($label));
            $box->add($field);
            $boxes[] = $box;
        }
        $boxes[] = $button;

        $form->add(CvPage::filterBar($boxes));

        return $form;
    }

    private static function emptyState(): TElement
    {
        $state = new TElement('div');
        $state->{'class'} = 'cv-state cv-state--empty';
        $state->{'role'} = 'status';

        $icon = new TElement('span');
        $icon->{'class'} = 'cv-state__icon';
        $icon->{'aria-hidden'} = 'true';
        $icon->add(new TImage('fa:toolbox'));
        $state->add($icon);

        $text = new TElement('div');
        $text->add(TElement::tag('p', CvFormat::e(_t('No tool calls in the period')), ['class' => 'cv-state__title']));
        $state->add($text);

        return $state;
    }

    /**
     * @param list<\CentralVet\Domain\McpToolCall> $calls
     * @param array<int, string> $users
     */
    private static function table(array $calls, array $users): TElement
    {
        $card = new TElement('div');
        $card->{'class'} = 'cv-card';
        $body = new TElement('div');
        $body->{'class'} = 'cv-card__body';
        $body->style = 'overflow-x:auto';
        $card->add($body);

        $table = new TElement('table');
        $table->{'class'} = 'table cv-table';
        $table->style = 'width:100%';

        $head = new TElement('tr');
        foreach ([_t('Date'), _t('User'), _t('Tool'), _t('Decision'), _t('Reason'), _t('Rows'), _t('Truncated'), _t('Duration (ms)'), _t('Correlation id')] as $title)
        {
            $head->add(TElement::tag('th', CvFormat::e($title), ['scope' => 'col']));
        }
        $thead = new TElement('thead');
        $thead->add($head);
        $table->add($thead);

        $tbody = new TElement('tbody');
        foreach ($calls as $call)
        {
            $createdAt = $call->createdAt();
            $userId = $call->systemUserId();

            $tr = new TElement('tr');
            $tr->add(TElement::tag('td', CvFormat::e($createdAt !== null ? $createdAt->format('d/m/Y H:i:s') : '—')));
            $tr->add(TElement::tag('td', CvFormat::e($users[$userId] ?? _t('User') . ' #' . $userId)));
            $tr->add(TElement::tag('td', CvFormat::e($call->toolName())));
            $tr->add(TElement::tag('td', CvFormat::e(self::decisionLabel($call->decision()))));
            $tr->add(TElement::tag('td', CvFormat::e($call->denyReason() ?? '—')));
            $tr->add(TElement::tag('td', CvFormat::e((string) $call->rowCount())));
            $tr->add(TElement::tag('td', CvFormat::e($call->truncated() ? _t('Yes') : _t('No'))));
            $tr->add(TElement::tag('td', CvFormat::e((string) $call->durationMs())));
            $tr->add(TElement::tag('td', CvFormat::e($call->correlationId())));
            $tbody->add($tr);
        }
        $table->add($tbody);

        $body->add($table);

        return $card;
    }

    /**
     * Usuários vinculados ao tenant (para o filtro e os nomes da tabela).
     *
     * @return array<int, string>
     */
    protected static function tenantUsers(\CentralVet\Tenancy\TenantContext $context, PDO $connection): array
    {
        $statement = $connection->prepare(
            'SELECT u.id, u.name FROM system_users u'
            . ' JOIN tenant_user tu ON tu.system_user_id = u.id AND tu.tenant_id = ?'
            . ' ORDER BY u.name, u.id'
        );
        $statement->execute([$context->tenantId()]);

        $names = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row)
        {
            $names[(int) $row['id']] = (string) ($row['name'] ?? ('#' . $row['id']));
        }

        return $names;
    }

    protected static function makeService(\CentralVet\Tenancy\TenantContext $context, PDO $connection): \CentralVet\Application\AiToolAuditService
    {
        return \CentralVet\Application\AiToolAuditService::forContext($context, $connection);
    }

    /**
     * Executa `$work` numa transação 'permission' (commit no fim, rollback
     * em qualquer erro, que é relançado).
     */
    protected static function transaction(Closure $work): mixed
    {
        TTransaction::open('permission');

        try
        {
            $result = $work(TTransaction::get());
            TTransaction::close();

            return $result;
        }
        catch (Throwable $e)
        {
            TTransaction::rollback();
            throw $e;
        }
    }

    /**
     * Contexto de tenant da sessão autenticada, com o mesmo fallback de
     * AiInteractionList::resolveTenantContext() para sessões legadas.
     */
    protected static function resolveTenantContext(): \CentralVet\Tenancy\TenantContext
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

            $tenant_id = static::transaction(static function (PDO $connection) use ($userid)
            {
                $stmt = $connection->prepare('SELECT tenant_id FROM tenant_user WHERE system_user_id = :userid ORDER BY id ASC LIMIT 1');
                $stmt->execute(['userid' => (int) $userid]);

                return $stmt->fetchColumn();
            });

            if (empty($tenant_id))
            {
                throw $e;
            }

            $unit_id = TSession::getValue('userunitid');

            return \CentralVet\Tenancy\TenantContext::authenticated((int) $tenant_id, (int) $userid, $unit_id ? (int) $unit_id : null);
        }
    }
}
