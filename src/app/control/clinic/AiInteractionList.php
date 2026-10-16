<?php
/**
 * AiInteractionList — as 50 interações de IA mais recentes do tenant
 * (Fase 8A, T-13).
 *
 * Colunas: data, usuário, finalidade, modelo, status, tokens
 * (entrada/saída), custo e código de erro. Nunca mostra o texto da
 * sugestão nem o texto final (dado clínico). Leitura por
 * CentralVet\Application\AiSettingsService::recentInteractions (RBAC no
 * programa AiInteractionList); nomes de usuário em lote, filtrados pelo
 * tenant.
 *
 * @version    1.0
 * @package    control
 * @subpackage clinic
 */
class AiInteractionList extends TPage
{
    private const LIMIT = 50;

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

    public function onReload($param = null)
    {
        if ($this->loaded)
        {
            return;
        }
        $this->loaded = true;

        $container = new TVBox;
        $container->style = 'width: 100%';
        $container->add(CvPage::header(_t('AI interactions'), _t('AI assistant'), [
            ['icon' => 'fa:arrow-left', 'href' => 'index.php?class=AiSettingsForm', 'class' => 'btn btn-default cv-touch-target', 'title' => _t('Back')],
        ], false));

        try
        {
            $context = static::resolveTenantContext();

            [$interactions, $users] = static::transaction(static function (PDO $connection) use ($context): array
            {
                $interactions = static::makeService($context, $connection)->recentInteractions(self::LIMIT);
                $ids = array_values(array_unique(array_map(static fn ($i): int => $i->systemUserId(), $interactions)));

                return [$interactions, $ids === [] ? [] : static::userNames($context, $connection, $ids)];
            });
        }
        catch (\CentralVet\Authorization\Exception\AuthorizationDenied $e)
        {
            new TMessage('error', CvFormat::e(_t('You are not allowed to view the AI interactions')));
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
            new TMessage('error', CvFormat::userError($e));
            parent::add($container);
            return;
        }

        $container->add($interactions === [] ? self::emptyState() : self::table($interactions, $users));

        parent::add($container);
    }

    public static function statusLabel(string $status): string
    {
        return match ($status)
        {
            \CentralVet\Domain\AiInteraction::STATUS_PENDING_REVIEW => _t('Pending review'),
            \CentralVet\Domain\AiInteraction::STATUS_ACCEPTED       => _t('Accepted'),
            \CentralVet\Domain\AiInteraction::STATUS_EDITED         => _t('Edited'),
            \CentralVet\Domain\AiInteraction::STATUS_DISCARDED      => _t('Discarded'),
            \CentralVet\Domain\AiInteraction::STATUS_FAILED         => _t('Failed'),
            \CentralVet\Domain\AiInteraction::STATUS_ANSWERED       => _t('Answered'),
            default                                                 => $status,
        };
    }

    private static function emptyState(): TElement
    {
        $state = new TElement('div');
        $state->{'class'} = 'cv-state cv-state--empty';
        $state->{'role'} = 'status';

        $icon = new TElement('span');
        $icon->{'class'} = 'cv-state__icon';
        $icon->{'aria-hidden'} = 'true';
        $icon->add(new TImage('fa:robot'));
        $state->add($icon);

        $text = new TElement('div');
        $text->add(TElement::tag('p', CvFormat::e(_t('No AI interactions yet')), ['class' => 'cv-state__title']));
        $text->add(TElement::tag('p', CvFormat::e(_t('Each use of the AI assistant appears here with tokens and cost.')), ['class' => 'cv-state__message']));
        $state->add($text);

        return $state;
    }

    /**
     * @param list<\CentralVet\Domain\AiInteraction> $interactions
     * @param array<int, string> $users
     */
    private static function table(array $interactions, array $users): TElement
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
        foreach ([_t('Date'), _t('User'), _t('Purpose'), _t('Model'), _t('Status'), _t('Tokens (input/output)'), _t('Cost'), _t('Error code')] as $title)
        {
            $head->add(TElement::tag('th', CvFormat::e($title), ['scope' => 'col']));
        }
        $thead = new TElement('thead');
        $thead->add($head);
        $table->add($thead);

        $tbody = new TElement('tbody');
        foreach ($interactions as $interaction)
        {
            $createdAt = $interaction->createdAt();
            $usage = $interaction->usage();
            $cost = $interaction->costMicroUsd();
            $userId = $interaction->systemUserId();

            $tr = new TElement('tr');
            $tr->add(TElement::tag('td', CvFormat::e($createdAt !== null ? $createdAt->format('d/m/Y H:i') : '—')));
            $tr->add(TElement::tag('td', CvFormat::e($users[$userId] ?? _t('User') . ' #' . $userId)));
            $tr->add(TElement::tag('td', CvFormat::e(AiSettingsForm::purposeLabel($interaction->purpose()))));
            $tr->add(TElement::tag('td', CvFormat::e($interaction->model() ?? '—')));
            $tr->add(TElement::tag('td', CvFormat::e(self::statusLabel($interaction->status()))));
            $tr->add(TElement::tag('td', CvFormat::e(AiSettingsForm::number($usage->inputTokens) . ' / ' . AiSettingsForm::number($usage->outputTokens))));
            $tr->add(TElement::tag('td', CvFormat::e($cost !== null ? AiSettingsForm::usd($cost) : '—')));
            $tr->add(TElement::tag('td', CvFormat::e($interaction->errorCode() ?? '—')));
            $tbody->add($tr);
        }
        $table->add($tbody);

        $body->add($table);

        return $card;
    }

    /**
     * Nomes dos usuários em lote (uma consulta), só os vinculados ao tenant.
     *
     * @param list<int> $ids
     * @return array<int, string>
     */
    protected static function userNames(\CentralVet\Tenancy\TenantContext $context, PDO $connection, array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, static fn ($id) => $id > 0)));
        if ($ids === [])
        {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $statement = $connection->prepare(
            "SELECT u.id, u.name FROM system_users u"
            . " JOIN tenant_user tu ON tu.system_user_id = u.id AND tu.tenant_id = ?"
            . " WHERE u.id IN ({$placeholders})"
        );
        $statement->execute(array_merge([$context->tenantId()], $ids));

        $names = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row)
        {
            $names[(int) $row['id']] = (string) $row['name'];
        }

        return $names;
    }

    protected static function makeService(\CentralVet\Tenancy\TenantContext $context, PDO $connection): \CentralVet\Application\AiSettingsService
    {
        return \CentralVet\Application\AiSettingsService::forContext($context, $connection);
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
     * CommunicationMessageList::resolveTenantContext() para sessões legadas.
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
