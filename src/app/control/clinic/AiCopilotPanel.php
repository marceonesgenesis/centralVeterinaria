<?php
/**
 * AiCopilotPanel — cortina lateral do copiloto somente leitura (Fase 8B,
 * T-14).
 *
 * Mostra o título da família (clínico ou gestão), o aviso de IA, a conversa
 * atual (com "Consultei: …" abaixo de cada resposta, a partir do
 * tool_summary), o formulário de pergunta e as conversas anteriores do
 * autor (no clínico, só as do paciente âncora), com "Nova conversa" e
 * "Apagar conversa". Copiloto indisponível → o motivo da 8A, sem
 * formulário. Âncora fora da unidade → "Paciente não encontrado" e a
 * cortina não abre.
 *
 * Envio duplo: o formulário leva um `submit_token` novo a cada renderização;
 * `onAsk` guarda o último processado em TSession e, com token repetido, só
 * re-renderiza a conversa. No navegador, cv-ai-copilot.js desabilita o
 * botão, marca o formulário com aria-busy e anuncia "Pensando…" na live
 * region.
 *
 * Toda leitura e escrita passa por CentralVet\Application\AiCopilotService
 * (RBAC AiCopilotPanel::onAsk|onOpen|onDelete com unidade ativa). Logs só
 * com nomes de classe; nunca pergunta, resposta ou nomes.
 *
 * @version    1.0
 * @package    control
 * @subpackage clinic
 */
class AiCopilotPanel extends TPage
{
    private const FORM = 'form_AiCopilotPanel';
    private const ACTIONS_FORM = 'form_AiCopilotPanel_actions';
    private const TOKEN_KEY = 'cv_ai_copilot_last_token';
    private const LIST_LIMIT = 20;
    private const SCRIPT = 'app/templates/adminbs5/js/cv-ai-copilot.js';

    public function __construct($param = null)
    {
        parent::__construct();
        parent::setTargetContainer('adianti_right_panel');

        // com method, o dispatcher chama o método pedido
        if (empty($param['method']))
        {
            $this->onOpen(is_array($param) ? $param : []);
        }
    }

    /**
     * Link que abre a cortina (botões "Copiloto" e item de menu).
     */
    public static function linkFor(string $family, ?int $anchorPatientId): string
    {
        $link = 'index.php?class=AiCopilotPanel&method=onOpen&family=' . rawurlencode($family) . '&register_state=false';

        if ($anchorPatientId !== null)
        {
            $link .= '&anchor_patient_id=' . $anchorPatientId;
        }

        return $link;
    }

    /**
     * Abre a cortina: params `family`, `anchor_patient_id`, `conversation_id`.
     */
    public function onOpen($param)
    {
        [$family, $anchor, $conversationId] = self::readParams($param);

        $this->perform(function (\CentralVet\Application\AiCopilotService $service) use ($family, $anchor, $conversationId): array
        {
            if ($anchor !== null)
            {
                $service->assertAnchor($anchor);
            }

            return self::load($service, $family, $anchor, $conversationId);
        }, $family, $anchor);
    }

    /**
     * POST de form_AiCopilotPanel: `question`, `family`, `anchor_patient_id`,
     * `conversation_id`, `submit_token`.
     */
    public function onAsk($param)
    {
        [$family, $anchor, $conversationId] = self::readParams($param);
        $question = trim((string) ($param['question'] ?? ''));
        $token = (string) ($param['submit_token'] ?? '');
        $repeated = $token !== '' && hash_equals((string) TSession::getValue(self::TOKEN_KEY), $token);

        if (!$repeated && $token !== '')
        {
            TSession::setValue(self::TOKEN_KEY, $token);
        }

        $this->perform(function (\CentralVet\Application\AiCopilotService $service) use ($family, $anchor, $conversationId, $question, $repeated): array
        {
            if ($anchor !== null)
            {
                $service->assertAnchor($anchor);
            }

            if (!$repeated)
            {
                $conversationId = $service->ask($family, $conversationId, $anchor, $question)->conversationId;
            }
            elseif ($conversationId === null)
            {
                // envio repetido da primeira pergunta: a conversa mais recente
                $latest = $service->conversations($family, $anchor, 1);
                $conversationId = $latest !== [] ? $latest[0]->id() : null;
            }

            return self::load($service, $family, $anchor, $conversationId);
        }, $family, $anchor);
    }

    /**
     * Apaga a conversa `conversation_id` do autor e volta à conversa nova.
     */
    public function onDelete($param)
    {
        [$family, $anchor, $conversationId] = self::readParams($param);

        $deleted = false;
        $this->perform(function (\CentralVet\Application\AiCopilotService $service) use ($family, $anchor, $conversationId, &$deleted): array
        {
            if ($anchor !== null)
            {
                $service->assertAnchor($anchor);
            }

            if ($conversationId !== null)
            {
                $deleted = $service->deleteConversation($conversationId);
            }

            return self::load($service, $family, $anchor, null);
        }, $family, $anchor);

        if ($deleted)
        {
            new TMessage('info', CvFormat::e(_t('Conversation deleted')));
        }
        elseif ($conversationId !== null)
        {
            new TMessage('warning', CvFormat::e(_t('Conversation not found')));
        }
    }

    public function onClose($param = null)
    {
        TScript::create('Template.closeRightPanel()');
    }

    /**
     * Executa `$work` com o serviço numa transação e renderiza o estado
     * devolvido; trata as negações e falhas sem abrir dados.
     */
    private function perform(Closure $work, string $family, ?int $anchor): void
    {
        try
        {
            $context = static::resolveTenantContext();
            $locale = \CentralVet\Ai\AiLocale::fromLanguage(ApplicationTranslator::getLanguage());

            $state = static::transaction(static function () use ($work, $context, $locale): array
            {
                return $work(static::makeService($context, $locale));
            });
        }
        catch (\CentralVet\Domain\Exception\AiSubjectNotFoundException $e)
        {
            $message = $anchor !== null ? _t('Patient not found') : _t('Conversation not found');
            new TMessage('error', CvFormat::e($message));

            if ($anchor !== null)
            {
                TScript::create('Template.closeRightPanel()');
                return;
            }

            $this->renderMessageOnly($family, $message);
            return;
        }
        catch (\CentralVet\Domain\Exception\AiUnavailableException $e)
        {
            $this->renderPanel(['family' => $family, 'anchor' => $anchor, 'reason' => $e->reason(), 'current' => null, 'messages' => [], 'conversations' => []]);
            return;
        }
        catch (\CentralVet\Authorization\Exception\AuthorizationDenied $e)
        {
            $this->renderMessageOnly($family, _t('Permission denied'));
            return;
        }
        catch (\CentralVet\Tenancy\Exception\MissingTenantContext $e)
        {
            $this->renderMessageOnly($family, _t('An authenticated session with an active unit is required'));
            return;
        }
        catch (PDOException $e)
        {
            error_log(__METHOD__ . ': ' . get_class($e));
            new TMessage('error', CvFormat::userError($e));
            $this->renderMessageOnly($family, null);
            return;
        }
        catch (RuntimeException $e)
        {
            // rate limiter sem backend (Redis fora do ar): copiloto indisponível
            error_log(__METHOD__ . ': ' . get_class($e));
            $this->renderPanel(['family' => $family, 'anchor' => $anchor, 'reason' => 'ai_provider_error', 'current' => null, 'messages' => [], 'conversations' => []]);
            return;
        }
        catch (Exception $e)
        {
            error_log(__METHOD__ . ': ' . get_class($e));
            new TMessage('error', CvFormat::userError($e));
            $this->renderMessageOnly($family, null);
            return;
        }

        $this->renderPanel($state);
    }

    /**
     * Estado da cortina: motivo de indisponibilidade ou conversa atual e
     * lista das anteriores.
     *
     * @return array{family: string, anchor: ?int, reason: ?string, current: ?\CentralVet\Domain\AiConversation, messages: list<\CentralVet\Domain\AiConversationMessage>, conversations: list<\CentralVet\Domain\AiConversation>}
     */
    private static function load(\CentralVet\Application\AiCopilotService $service, string $family, ?int $anchor, ?int $conversationId): array
    {
        $state = ['family' => $family, 'anchor' => $anchor, 'reason' => $service->availability($family), 'current' => null, 'messages' => [], 'conversations' => []];

        if ($state['reason'] !== null)
        {
            return $state;
        }

        if ($conversationId !== null)
        {
            $found = $service->conversation($conversationId);

            if ($found === null || $found['conversation']->family() !== $family
                || ($anchor !== null && $found['conversation']->anchorPatientId() !== $anchor))
            {
                throw new \CentralVet\Domain\Exception\AiSubjectNotFoundException();
            }

            $state['current'] = $found['conversation'];
            $state['messages'] = $found['messages'];
        }

        $state['conversations'] = $service->conversations($family, $family === \CentralVet\Ai\Tools\ToolFamily::CLINICAL ? $anchor : null, self::LIST_LIMIT);

        return $state;
    }

    /** @param array<string, mixed> $state */
    private function renderPanel(array $state): void
    {
        $family = (string) $state['family'];
        $anchor = $state['anchor'];
        $current = $state['current'];

        $panel = $this->shell($family);

        $panel->add(TElement::tag('p', CvFormat::e(_t('Answer generated by AI from system data. Check before using.')), ['class' => 'cv-ai-origin cv-ai-copilot__notice']));

        if ($state['reason'] !== null)
        {
            $notice = new TElement('div');
            $notice->{'class'} = 'cv-state cv-state--empty cv-ai-copilot__unavailable';
            $notice->{'role'} = 'status';
            $notice->add(TElement::tag('p', CvFormat::userMessage('AI assistant is unavailable: ' . $state['reason']), ['class' => 'cv-state__title']));
            $panel->add($notice);
            parent::add($panel);
            return;
        }

        $panel->add(self::conversationView($state['messages']));
        $panel->add($this->questionForm($family, $anchor, $current?->id()));
        $panel->add($this->conversationActions($family, $anchor, $current?->id()));
        $panel->add(self::previousConversations($family, $anchor, $state['conversations'], $current?->id()));
        $panel->add(TElement::tag('script', '', ['src' => self::SCRIPT]));

        parent::add($panel);
    }

    private function renderMessageOnly(string $family, ?string $message): void
    {
        $panel = $this->shell($family);

        if ($message !== null)
        {
            $panel->add(TElement::tag('p', CvFormat::e($message), ['class' => 'cv-ai-copilot__notice', 'role' => 'alert']));
        }

        parent::add($panel);
    }

    /**
     * Contêiner da cortina com cabeçalho (título, fechar) e a live region.
     */
    private function shell(string $family): TElement
    {
        $panel = new TElement('section');
        $panel->{'class'} = 'cv-ai-copilot';
        $panel->{'aria-labelledby'} = 'cv-ai-copilot-title';

        $header = new TElement('div');
        $header->{'class'} = 'cv-ai-copilot__header';
        $header->add(TElement::tag('h2', CvFormat::e(self::title($family)), ['id' => 'cv-ai-copilot-title', 'class' => 'cv-ai-copilot__title']));

        $close = TElement::tag('a', '', [
            'href'       => 'index.php?class=AiCopilotPanel&method=onClose&register_state=false',
            'generator'  => 'adianti',
            'class'      => 'btn btn-default cv-touch-target cv-ai-copilot__close',
            'aria-label' => _t('Close'),
            'title'      => _t('Close'),
        ]);
        $close->add(new TImage('fa:times'));
        $header->add($close);
        $panel->add($header);

        $panel->add(TElement::tag('div', '', [
            'id'        => 'cv-ai-copilot-status',
            'class'     => 'cv-ai-status',
            'role'      => 'status',
            'aria-live' => 'polite',
        ]));

        return $panel;
    }

    /** @param list<\CentralVet\Domain\AiConversationMessage> $messages */
    private static function conversationView(array $messages): TElement
    {
        $list = new TElement('div');
        $list->{'class'} = 'cv-ai-copilot__messages';

        if ($messages === [])
        {
            $list->add(TElement::tag('p', CvFormat::e(_t('Ask the copilot')), ['class' => 'cv-ai-copilot__empty']));
            return $list;
        }

        foreach ($messages as $message)
        {
            $item = new TElement('div');
            $isUser = $message->role() === \CentralVet\Domain\AiConversationMessage::ROLE_USER;
            $item->{'class'} = 'cv-ai-copilot__message ' . ($isUser ? 'cv-ai-copilot__message--user' : 'cv-ai-copilot__message--assistant');

            if ($message->isFailed())
            {
                $item->{'class'} .= ' cv-ai-copilot__message--failed';
                $item->add(TElement::tag('p', CvFormat::e(_t('The answer failed; you can send the question again'))));
                $list->add($item);
                continue;
            }

            $item->add(TElement::tag('p', nl2br(CvFormat::e($message->content()), false)));

            if (!$isUser && $message->toolSummary() !== [])
            {
                $labels = [];
                foreach ($message->toolSummary() as $tool)
                {
                    $label = (string) ($tool['label'] ?? $tool['tool'] ?? '');
                    $count = (int) ($tool['count'] ?? 1);
                    $labels[] = $count > 1 ? $label . ' (' . $count . ')' : $label;
                }

                $item->add(TElement::tag('p', CvFormat::e(_t('Consulted') . ': ' . implode(', ', $labels)), ['class' => 'cv-ai-copilot__consulted']));
            }

            $list->add($item);
        }

        return $list;
    }

    private function questionForm(string $family, ?int $anchor, ?int $conversationId): TForm
    {
        $form = new TForm(self::FORM);
        $form->{'class'} = 'cv-ai-copilot__form';
        $form->{'aria-busy'} = 'false';

        $body = new TElement('div');
        $body->{'class'} = 'cv-ai-copilot__field';
        $body->add(TElement::tag('label', CvFormat::e(_t('Your question')), ['for' => 'cv_ai_copilot_question', 'class' => 'cv-ai-copilot__label']));

        $question = new TText('question');
        $question->setId('cv_ai_copilot_question');
        $question->setSize('100%', 96);
        $question->setProperty('maxlength', (string) \CentralVet\Application\AiCopilotService::MAX_QUESTION_CHARS);
        $question->setProperty('required', 'required');
        $question->setProperty('aria-describedby', 'cv-ai-copilot-status');
        $body->add($question);

        $hidden = [];
        foreach ([
            'family'            => $family,
            'anchor_patient_id' => $anchor !== null ? (string) $anchor : '',
            'conversation_id'   => $conversationId !== null ? (string) $conversationId : '',
            'submit_token'      => bin2hex(random_bytes(16)),
        ] as $name => $value)
        {
            $field = new THidden($name);
            $field->setValue($value);
            $body->add($field);
            $hidden[] = $field;
        }

        $send = new TButton('ask');
        $send->setAction(new TAction([$this, 'onAsk']), _t('Send'));
        $send->setImage('fa:paper-plane');
        $send->class = 'btn btn-primary cv-touch-target cv-ai-copilot__send';
        $send->setProperty('data-thinking-label', _t('Thinking…'));
        $send->addFunction('if (window.CvAiCopilot && !window.CvAiCopilot.begin(this)) { return false; }');
        $body->add($send);

        $form->add($body);
        $form->setFields(array_merge([$question], $hidden, [$send]));

        return $form;
    }

    /**
     * "Nova conversa" e, com conversa aberta, "Apagar conversa".
     */
    private function conversationActions(string $family, ?int $anchor, ?int $conversationId): TElement
    {
        $actions = new TElement('div');
        $actions->{'class'} = 'cv-ai-actions cv-ai-copilot__actions';

        $new = TElement::tag('a', '', [
            'href'      => self::linkFor($family, $anchor),
            'generator' => 'adianti',
            'class'     => 'btn btn-default cv-touch-target',
        ]);
        $new->add(new TImage('fa:plus'));
        $new->add(CvFormat::e(_t('New conversation')));
        $actions->add($new);

        if ($conversationId !== null)
        {
            $form = new TForm(self::ACTIONS_FORM);
            $form->{'class'} = 'cv-ai-copilot__delete';
            $fields = [];

            foreach ([
                'family'            => $family,
                'anchor_patient_id' => $anchor !== null ? (string) $anchor : '',
                'conversation_id'   => (string) $conversationId,
            ] as $name => $value)
            {
                $field = new THidden($name);
                $field->setValue($value);
                $form->add($field);
                $fields[] = $field;
            }

            $delete = new TButton('delete_conversation');
            $delete->setAction(new TAction([$this, 'onDelete']), _t('Delete conversation'));
            $delete->setImage('fa:trash-alt');
            $delete->class = 'btn btn-outline-danger cv-touch-target';
            $form->add($delete);
            $fields[] = $delete;
            $form->setFields($fields);

            $actions->add($form);
        }

        return $actions;
    }

    /** @param list<\CentralVet\Domain\AiConversation> $conversations */
    private static function previousConversations(string $family, ?int $anchor, array $conversations, ?int $currentId): TElement
    {
        $section = new TElement('div');
        $section->{'class'} = 'cv-ai-copilot__history';
        $section->add(TElement::tag('h3', CvFormat::e(_t('Previous conversations')), ['class' => 'cv-ai-copilot__subtitle']));

        if ($conversations === [])
        {
            $section->add(TElement::tag('p', CvFormat::e(_t('No conversations yet')), ['class' => 'cv-ai-copilot__empty']));
            return $section;
        }

        $list = new TElement('ul');
        $list->{'class'} = 'cv-ai-copilot__list';

        foreach ($conversations as $conversation)
        {
            $id = (int) $conversation->id();
            $attributes = [
                'href'      => self::linkFor($family, $family === \CentralVet\Ai\Tools\ToolFamily::CLINICAL ? $anchor : null) . '&conversation_id=' . $id,
                'generator' => 'adianti',
                'class'     => 'cv-ai-copilot__link cv-touch-target',
            ];

            if ($id === $currentId)
            {
                $attributes['aria-current'] = 'true';
            }

            $link = TElement::tag('a', '', $attributes);
            $link->add(TElement::tag('span', CvFormat::e($conversation->title()), ['class' => 'cv-ai-copilot__link-title']));
            $link->add(TElement::tag('small', CvFormat::e($conversation->updatedAt()->format('d/m/Y H:i'))));

            $item = new TElement('li');
            $item->add($link);
            $list->add($item);
        }

        $section->add($list);

        return $section;
    }

    private static function title(string $family): string
    {
        return $family === \CentralVet\Ai\Tools\ToolFamily::MANAGEMENT ? _t('Management copilot') : _t('Clinical copilot');
    }

    /**
     * @return array{0: string, 1: ?int, 2: ?int} família (padrão clínico), âncora (só no clínico) e conversa
     */
    private static function readParams($param): array
    {
        $param = is_array($param) ? $param : [];
        $family = (string) ($param['family'] ?? \CentralVet\Ai\Tools\ToolFamily::CLINICAL);

        if (!in_array($family, \CentralVet\Ai\Tools\ToolFamily::ALL, true))
        {
            $family = \CentralVet\Ai\Tools\ToolFamily::CLINICAL;
        }

        $anchor = self::positiveInt($param['anchor_patient_id'] ?? null);
        if ($family !== \CentralVet\Ai\Tools\ToolFamily::CLINICAL)
        {
            $anchor = null;
        }

        return [$family, $anchor, self::positiveInt($param['conversation_id'] ?? null)];
    }

    private static function positiveInt(mixed $value): ?int
    {
        if (is_int($value))
        {
            return $value > 0 ? $value : null;
        }

        if (is_string($value) && preg_match('/^[1-9][0-9]{0,18}$/', $value) === 1)
        {
            return (int) $value;
        }

        return null;
    }

    protected static function makeService(\CentralVet\Tenancy\TenantContext $context, string $locale): \CentralVet\Application\AiCopilotService
    {
        return \CentralVet\Application\AiCopilotService::forContext($context, TTransaction::get(), $locale);
    }

    /**
     * Executa `$fn` numa transação 'permission' (commit no fim, rollback em
     * qualquer erro, que é relançado). `$fn` recebe a conexão.
     */
    protected static function transaction(callable $fn): mixed
    {
        TTransaction::open('permission');

        try
        {
            $result = $fn(TTransaction::get());
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
