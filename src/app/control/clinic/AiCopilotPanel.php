<?php
/**
 * AiCopilotPanel — cortina lateral do copiloto somente leitura (Fase 8B,
 * T-14).
 *
 * Mostra o título da família (clínico ou gestão), o aviso de IA, a conversa
 * atual (com "Consultei: …" abaixo de cada resposta, a partir do
 * tool_summary), o formulário de pergunta e as conversas anteriores do
 * autor (no clínico, só as do paciente âncora), com "Nova conversa" e
 * "Apagar conversa". Copiloto indisponível → o motivo da 8A no lugar do
 * formulário. Âncora fora da unidade → "Paciente não encontrado" e a
 * cortina não abre.
 *
 * Envio duplo: o formulário leva um `submit_token` novo a cada renderização;
 * `onAsk` guarda o último processado em TSession e, com token repetido, só
 * re-renderiza a conversa. No navegador, cv-ai-copilot.js desabilita o
 * botão, marca o formulário com aria-busy e anuncia "Pensando…" na live
 * region.
 *
 * Abrir, listar e apagar passam por CentralVet\Application\AiConversationHistoryService
 * (sem IA: com o copiloto indisponível a conversa aparece e o motivo fica
 * no lugar do formulário); perguntar passa por AiCopilotService numa
 * conexão em autocommit. RBAC AiCopilotPanel::onAsk|onOpen|onDelete com
 * unidade ativa. Apagar só por POST. Logs só com nomes de classe; nunca
 * pergunta, resposta ou nomes.
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
     * Botão "Copiloto" das telas hospedeiras (PatientForm, EncounterView):
     * um link, não um TButton, para nunca postar o formulário da tela. Quem
     * chama confere a disponibilidade antes.
     */
    public static function hostLink(string $family, ?int $anchorPatientId): TElement
    {
        $link = TElement::tag('a', '', [
            'class' => 'btn btn-default cv-touch-target',
            'href' => CvFormat::e(self::linkFor($family, $anchorPatientId)),
            'generator' => 'adianti',
            'data-cv-copilot' => $family,
        ]);
        $link->add(new TImage('fa:comments'));
        $link->add(TElement::tag('span', CvFormat::e(_t('Copilot'))));

        return $link;
    }

    /**
     * Abre a cortina: params `family`, `anchor_patient_id`, `conversation_id`.
     * Ler não depende da IA: a conversa vem do histórico e só o formulário
     * de pergunta depende da disponibilidade.
     */
    public function onOpen($param)
    {
        [$family, $anchor, $conversationId] = self::readParams($param);

        $this->perform(static function (PDO $connection, \CentralVet\Tenancy\TenantContext $context) use ($family, $anchor, $conversationId): array
        {
            return self::load($connection, $context, $family, $anchor, $conversationId);
        }, $family, $anchor);
    }

    /**
     * POST de form_AiCopilotPanel: `question`, `family`, `anchor_patient_id`,
     * `conversation_id`, `submit_token`. Roda numa conexão em autocommit
     * (connection()): o laço de ferramentas não fica dentro de uma transação.
     */
    public function onAsk($param)
    {
        [$family, $anchor, $conversationId] = self::readParams($param);
        $question = trim((string) ($param['question'] ?? ''));

        if ($question === '')
        {
            // pergunta em branco não chega ao serviço: o formulário volta com o aviso
            $this->perform(static function (PDO $connection, \CentralVet\Tenancy\TenantContext $context) use ($family, $anchor, $conversationId): array
            {
                return ['warning' => _t('Type a question before sending')] + self::load($connection, $context, $family, $anchor, $conversationId);
            }, $family, $anchor);

            return;
        }

        $token = (string) ($param['submit_token'] ?? '');
        $repeated = $token !== '' && hash_equals((string) TSession::getValue(self::TOKEN_KEY), $token);

        if (!$repeated && $token !== '')
        {
            TSession::setValue(self::TOKEN_KEY, $token);
        }

        $this->perform(static function (PDO $connection, \CentralVet\Tenancy\TenantContext $context, string $locale) use ($family, $anchor, $conversationId, $question, $repeated): array
        {
            // AiUnavailableException (montagem ou pré-check do ask) sobe a perform, que
            // mostra o motivo com a pergunta digitada, como na negação
            try
            {
                $service = static::makeService($context, $locale, $connection);
            }
            catch (\CentralVet\Domain\Exception\AiUnavailableException | \CentralVet\Authorization\Exception\AuthorizationDenied | \CentralVet\Tenancy\Exception\MissingTenantContext | \CentralVet\Tenancy\Exception\TenantBoundaryViolation | PDOException $e)
            {
                throw $e;
            }
            catch (RuntimeException $e)
            {
                // só a montagem: rate limiter sem backend (Redis fora do ar) = copiloto indisponível
                error_log(__METHOD__ . ': ' . get_class($e));
                throw new \CentralVet\Domain\Exception\AiUnavailableException('ai_provider_error');
            }

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

            // a conversa é renderizada pelo id devolvido; o motivo (ex.: cota esgotada nesta resposta) vem de load;
            // 'focus' pede ao JS que leve o foco à resposta nova (a cortina inteira é substituída)
            return ['focus' => 'latest'] + self::load($connection, $context, $family, $anchor, $conversationId);
        }, $family, $anchor, true, $question);
    }

    /**
     * POST de form_AiCopilotPanel_actions: apaga a conversa `conversation_id`
     * do autor e volta à conversa nova. Outro método HTTP só avisa.
     */
    public function onDelete($param)
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST')
        {
            new TMessage('warning', CvFormat::e(_t('Invalid action')));
            $this->onOpen($param);
            return;
        }

        [$family, $anchor, $conversationId] = self::readParams($param);

        $deleted = false;
        $this->perform(static function (PDO $connection, \CentralVet\Tenancy\TenantContext $context) use ($family, $anchor, $conversationId, &$deleted): array
        {
            if ($conversationId !== null)
            {
                // âncora fora da unidade → AiSubjectNotFoundException antes de apagar: a conversa fica
                self::assertAnchorInUnit($connection, $context, $anchor);
                $deleted = static::makeHistory($context, $connection)->deleteConversation($conversationId);
            }

            return self::load($connection, $context, $family, $anchor, null);
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
     * Executa `$work(PDO, TenantContext, locale)` numa transação curta (ou,
     * com `$autocommit`, na conexão de connection()) e renderiza o estado
     * devolvido; trata as negações e falhas sem abrir dados.
     */
    private function perform(Closure $work, string $family, ?int $anchor, bool $autocommit = false, ?string $draft = null): void
    {
        try
        {
            $context = static::resolveTenantContext();
            $locale = \CentralVet\Ai\AiLocale::fromLanguage(ApplicationTranslator::getLanguage());

            $state = $autocommit
                ? $work(static::connection(), $context, $locale)
                : static::transaction(static function (PDO $connection) use ($work, $context, $locale): array
                {
                    return $work($connection, $context, $locale);
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
            if ($draft !== null)
            {
                // onAsk: o motivo e a pergunta digitada, pelo mesmo caminho da negação
                $reason = CvFormat::userMessage('AI assistant is unavailable: ' . $e->reason());
                $this->renderMessageOnly($family, html_entity_decode($reason, ENT_QUOTES | ENT_HTML5, 'UTF-8'), $draft);
                return;
            }

            $this->renderPanel(['family' => $family, 'anchor' => $anchor, 'reason' => $e->reason(), 'current' => null, 'messages' => [], 'conversations' => []]);
            return;
        }
        catch (\CentralVet\Authorization\Exception\AuthorizationDenied $e)
        {
            $this->renderMessageOnly($family, _t('Permission denied'), $draft);
            return;
        }
        catch (\CentralVet\Tenancy\Exception\MissingTenantContext $e)
        {
            $this->renderMessageOnly($family, _t('An authenticated session with an active unit is required'), $draft);
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
     * Estado da cortina pelo histórico (sem IA): conversa atual, lista das
     * anteriores e o motivo de indisponibilidade, que só tira o formulário.
     *
     * @return array{family: string, anchor: ?int, reason: ?string, current: ?\CentralVet\Domain\AiConversation, messages: list<\CentralVet\Domain\AiConversationMessage>, conversations: list<\CentralVet\Domain\AiConversation>}
     */
    private static function load(PDO $connection, \CentralVet\Tenancy\TenantContext $context, string $family, ?int $anchor, ?int $conversationId): array
    {
        $history = static::makeHistory($context, $connection);

        // RBAC AiCopilotPanel::onOpen antes de olhar o paciente âncora
        $conversations = $history->conversations(self::LIST_LIMIT, $family, $anchor);

        self::assertAnchorInUnit($connection, $context, $anchor);

        $state = ['family' => $family, 'anchor' => $anchor, 'reason' => null, 'current' => null, 'messages' => [], 'conversations' => $conversations];

        if ($conversationId !== null)
        {
            $found = $history->conversation($conversationId);

            if ($found === null || $found['conversation']->family() !== $family
                || ($anchor !== null && $found['conversation']->anchorPatientId() !== $anchor))
            {
                throw new \CentralVet\Domain\Exception\AiSubjectNotFoundException();
            }

            $state['current'] = $found['conversation'];
            $state['messages'] = $found['messages'];
        }

        $state['reason'] = \CentralVet\Application\AiCopilotAvailability::forContext($context, $connection)->reason($family);

        return $state;
    }

    /**
     * Paciente âncora na unidade ativa (a mesma checagem de load e de
     * onDelete); fora dela → AiSubjectNotFoundException.
     */
    private static function assertAnchorInUnit(PDO $connection, \CentralVet\Tenancy\TenantContext $context, ?int $anchor): void
    {
        if ($anchor !== null && !(new \CentralVet\Persistence\CopilotClinicalQuery($context, $connection))->patientInUnit($anchor))
        {
            throw new \CentralVet\Domain\Exception\AiSubjectNotFoundException();
        }
    }

    /** @param array<string, mixed> $state */
    private function renderPanel(array $state): void
    {
        $family = (string) $state['family'];
        $anchor = $state['anchor'];
        $current = $state['current'];

        $panel = $this->shell($family);

        if (($state['focus'] ?? null) === 'latest')
        {
            // cv-ai-copilot.js foca [data-cv-copilot-latest="1"] (ou o campo question) e remove o atributo
            $panel->{'data-cv-copilot-focus'} = 'latest';
        }

        $panel->add(TElement::tag('p', CvFormat::e(_t('Answer generated by AI from system data. Check before using.')), ['class' => 'cv-ai-origin cv-ai-copilot__notice']));

        if ($state['reason'] === null || $state['messages'] !== [])
        {
            $panel->add(self::conversationView($state['messages']));
        }

        if (isset($state['warning']))
        {
            $panel->add(TElement::tag('p', CvFormat::e((string) $state['warning']), ['class' => 'cv-ai-copilot__notice', 'role' => 'alert']));
        }

        if ($state['reason'] !== null)
        {
            // indisponível: o motivo fica no lugar do formulário de pergunta
            $notice = new TElement('div');
            $notice->{'class'} = 'cv-state cv-state--empty cv-ai-copilot__unavailable';
            $notice->{'role'} = 'status';
            $notice->add(TElement::tag('p', CvFormat::userMessage('AI assistant is unavailable: ' . $state['reason']), ['class' => 'cv-state__title']));
            $panel->add($notice);
        }
        else
        {
            $panel->add($this->questionForm($family, $anchor, $current?->id()));
        }

        $panel->add($this->conversationActions($family, $anchor, $current?->id()));
        $panel->add(self::previousConversations($family, $anchor, $state['conversations'], $current?->id()));
        $panel->add(TElement::tag('script', '', ['src' => self::SCRIPT]));

        parent::add($panel);
    }

    /**
     * Só a mensagem; com `$draft` (pergunta de onAsk que não foi enviada), o
     * texto digitado fica num campo somente leitura para o usuário copiar.
     */
    private function renderMessageOnly(string $family, ?string $message, ?string $draft = null): void
    {
        $panel = $this->shell($family);

        if ($message !== null)
        {
            $panel->add(TElement::tag('p', CvFormat::e($message), ['class' => 'cv-ai-copilot__notice', 'role' => 'alert']));
        }

        if ($draft !== null && $draft !== '')
        {
            $field = new TElement('div');
            $field->{'class'} = 'cv-ai-copilot__field';
            $field->add(TElement::tag('label', CvFormat::e(_t('Your question')), ['for' => 'cv_ai_copilot_draft', 'class' => 'cv-ai-copilot__label']));
            $field->add(TElement::tag('textarea', CvFormat::e($draft), ['id' => 'cv_ai_copilot_draft', 'class' => 'form-control', 'readonly' => 'readonly', 'rows' => '4']));
            $panel->add($field);
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

        // última mensagem do assistente: alvo do foco depois do envio (T-22)
        $latest = null;
        foreach ($messages as $index => $message)
        {
            if ($message->role() !== \CentralVet\Domain\AiConversationMessage::ROLE_USER)
            {
                $latest = $index;
            }
        }

        foreach ($messages as $index => $message)
        {
            $item = new TElement('div');

            if ($index === $latest)
            {
                $item->{'tabindex'} = '-1';
                $item->{'data-cv-copilot-latest'} = '1';
            }

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

    protected static function makeService(\CentralVet\Tenancy\TenantContext $context, string $locale, ?PDO $connection = null): \CentralVet\Application\AiCopilotService
    {
        return \CentralVet\Application\AiCopilotService::forContext($context, $connection ?? static::connection(), $locale);
    }

    /** Histórico do autor (abrir, listar, apagar): sem gateway, limiter, flag, termo ou cota. */
    protected static function makeHistory(\CentralVet\Tenancy\TenantContext $context, PDO $connection): \CentralVet\Application\AiConversationHistoryService
    {
        return \CentralVet\Application\AiConversationHistoryService::forContext($context, $connection);
    }

    /**
     * Conexão 'permission' em autocommit, usada só por onAsk: cada gravação
     * do serviço (mensagens, auditoria, ai_interaction em finally) vale
     * sozinha, sem transação longa em volta das chamadas ao provedor.
     */
    protected static function connection(): PDO
    {
        return TConnection::open('permission');
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
