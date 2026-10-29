<?php
/**
 * AiConversationList — "Minhas conversas" do copiloto (Fase 8B, T-15).
 *
 * Conversas do próprio usuário na unidade ativa (família, data, título),
 * mais recentes primeiro, com abrir (AiCopilotPanel com `conversation_id`)
 * e apagar (TForm por linha enviado por POST a `onDelete`, com
 * confirmação no navegador; GET não apaga). Nunca mostra o texto
 * das mensagens. Tudo passa por CentralVet\Application\AiConversationHistoryService
 * (RBAC `AiCopilotPanel::onOpen|onDelete` na unidade ativa, sem depender de
 * gateway, limiter, flag, termo ou cota); conversa de
 * outro usuário ou de outra unidade (inclusive a anterior à troca de
 * unidade) é "Conversa não encontrada".
 *
 * @version    1.0
 * @package    control
 * @subpackage clinic
 */
class AiConversationList extends TPage
{
    private const LIMIT = 100;

    private bool $loaded = false;

    public function __construct($param = null)
    {
        parent::__construct();

        // com method, o dispatcher chama o método, que recarrega
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
        $container->add(CvPage::header(_t('My conversations'), _t('Copilot')));

        try
        {
            $context = static::resolveTenantContext();

            $conversations = static::transaction(static function (PDO $connection) use ($context): array
            {
                return static::makeService($context, $connection)->conversations(self::LIMIT);
            });
        }
        catch (Exception $e)
        {
            self::showError($e);
            parent::add($container);
            return;
        }

        $container->add($conversations === [] ? self::emptyState() : self::table($conversations));

        parent::add($container);
    }

    /**
     * POST de form_AiConversationList_<id>: apaga a conversa
     * `conversation_id` do usuário. Outro método HTTP (link, prefetch,
     * recarga) só avisa e não apaga.
     */
    public function onDelete($param = null)
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST')
        {
            new TMessage('warning', CvFormat::e(_t('Invalid action')));
            $this->onReload($param);
            return;
        }

        $id = (int) ($param['conversation_id'] ?? 0);

        try
        {
            $context = static::resolveTenantContext();

            $deleted = $id > 0 && static::transaction(static function (PDO $connection) use ($context, $id): bool
            {
                return static::makeService($context, $connection)->deleteConversation($id);
            });

            if ($deleted)
            {
                new TMessage('info', CvFormat::e(_t('Conversation deleted')));
            }
            else
            {
                new TMessage('error', CvFormat::e(_t('Conversation not found')));
            }
        }
        catch (Exception $e)
        {
            self::showError($e);
        }

        $this->onReload($param);
    }

    public static function familyLabel(string $family): string
    {
        return match ($family)
        {
            \CentralVet\Ai\Tools\ToolFamily::CLINICAL   => _t('Clinical'),
            \CentralVet\Ai\Tools\ToolFamily::MANAGEMENT => _t('Management'),
            default                                     => $family,
        };
    }

    /** Link do painel do copiloto que reabre a conversa. */
    public static function openLink(\CentralVet\Domain\AiConversation $conversation): string
    {
        $family = $conversation->family();
        $anchor = $conversation->anchorPatientId();

        $base = class_exists('AiCopilotPanel') && method_exists('AiCopilotPanel', 'linkFor')
            ? AiCopilotPanel::linkFor($family, $anchor)
            : 'index.php?class=AiCopilotPanel&method=onOpen&family=' . rawurlencode($family) . '&register_state=false'
                . ($anchor !== null ? '&anchor_patient_id=' . $anchor : '');

        return $base . '&conversation_id=' . (int) $conversation->id();
    }

    private static function showError(Exception $e): void
    {
        if ($e instanceof \CentralVet\Authorization\Exception\AuthorizationDenied)
        {
            new TMessage('error', CvFormat::e(_t('Permission denied')));
            return;
        }

        if ($e instanceof \CentralVet\Tenancy\Exception\MissingTenantContext)
        {
            new TMessage('error', CvFormat::e(_t('An authenticated session with an active unit is required')));
            return;
        }

        // nunca o código cru: texto genérico traduzido
        error_log('AiConversationList: ' . get_class($e));
        new TMessage('error', CvFormat::e(_t('Could not complete the operation. Please try again')));
    }

    private static function emptyState(): TElement
    {
        $state = new TElement('div');
        $state->{'class'} = 'cv-state cv-state--empty';
        $state->{'role'} = 'status';

        $icon = new TElement('span');
        $icon->{'class'} = 'cv-state__icon';
        $icon->{'aria-hidden'} = 'true';
        $icon->add(new TImage('fa:comments'));
        $state->add($icon);

        $text = new TElement('div');
        $text->add(TElement::tag('p', CvFormat::e(_t('No conversations yet')), ['class' => 'cv-state__title']));
        $state->add($text);

        return $state;
    }

    /**
     * @param list<\CentralVet\Domain\AiConversation> $conversations
     */
    private static function table(array $conversations): TElement
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
        foreach ([_t('Family'), _t('Date'), _t('Title'), ''] as $title)
        {
            $head->add(TElement::tag('th', CvFormat::e($title), ['scope' => 'col']));
        }
        $thead = new TElement('thead');
        $thead->add($head);
        $table->add($thead);

        $tbody = new TElement('tbody');
        foreach ($conversations as $conversation)
        {
            $id = (int) $conversation->id();

            $open = new TElement('a');
            $open->{'class'} = 'btn btn-default btn-sm cv-touch-target';
            $open->{'href'} = CvFormat::e(self::openLink($conversation));
            $open->{'generator'} = 'adianti';
            $open->add(new TImage('fa:folder-open'));
            $open->add(' ' . CvFormat::e(_t('Open')));

            $delete = self::deleteForm($id);

            $actions = new TElement('td');
            $actions->add($open);
            $actions->add(' ');
            $actions->add($delete);

            $tr = new TElement('tr');
            $tr->add(TElement::tag('td', CvFormat::e(self::familyLabel($conversation->family()))));
            $tr->add(TElement::tag('td', CvFormat::e($conversation->updatedAt()->format('d/m/Y H:i'))));
            $tr->add(TElement::tag('td', CvFormat::e($conversation->title())));
            $tr->add($actions);
            $tbody->add($tr);
        }
        $table->add($tbody);

        $body->add($table);

        return $card;
    }

    /**
     * "Apagar" da linha: TForm form_AiConversationList_<id> com o
     * `conversation_id` oculto, enviado por POST a onDelete (padrão de
     * ExamResultForm::onAiExamSummary), com confirmação no navegador.
     */
    private static function deleteForm(int $id): TForm
    {
        $formName = 'form_AiConversationList_' . $id;
        $form = new TForm($formName);
        $form->{'class'} = 'cv-ai-conversation-delete';
        $form->style = 'display:inline';

        $field = new THidden('conversation_id');
        $field->setValue((string) $id);

        $button = new TButton('delete_conversation_' . $id);
        $button->setAction(new TAction([__CLASS__, 'onDelete'], ['register_state' => 'false']), _t('Delete'));
        $button->setFormName($formName);
        $button->setImage('fa:trash');
        $button->{'class'} = 'btn btn-default btn-sm cv-touch-target';
        $button->setProperty('title', _t('Delete conversation'));
        $button->setProperty('aria-label', _t('Delete conversation'));
        $question = json_encode(_t('Delete conversation') . '?', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
        $button->addFunction('if (!window.confirm(' . $question . ')) { return false; }');

        $form->add($field);
        $form->add($button);
        $form->setFields([$field, $button]);

        return $form;
    }

    /** Serviço leve de histórico: sem gateway, limiter, flag, termo ou cota. */
    protected static function makeService(\CentralVet\Tenancy\TenantContext $context, PDO $connection): \CentralVet\Application\AiConversationHistoryService
    {
        return \CentralVet\Application\AiConversationHistoryService::forContext($context, $connection);
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
