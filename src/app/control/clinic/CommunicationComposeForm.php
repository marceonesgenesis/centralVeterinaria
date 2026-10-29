<?php
/**
 * CommunicationComposeForm
 *
 * Mensagem manual para um tutor (Fase 7A, T-18): canal, finalidade,
 * template (combo dos templates ativos do canal; onChangeTemplate preenche
 * assunto e corpo via MessageService::renderTemplate), assunto e corpo.
 * Salvar chama MessageService::compose() numa TTransaction; depois do
 * commit, o e-mail é publicado na fila (MessageQueuePublisher; falha só vai
 * ao error_log com o id, o agendador republica os presos) e a tela segue
 * para CommunicationMessageView&id=<id>.
 *
 * Recusa por base legal (sem opt-in em finalidade de consentimento, ou
 * opt-out) mostra a mensagem traduzida e um link para TutorCommunicationForm.
 *
 * Entrada: `tutor_id` (obrigatório) e `patient_id` (opcional), de $_GET
 * senão $param. Assunto e corpo só pelo corpo do POST (texto livre nunca
 * pela URL). E-mail e telefone do tutor nunca são impressos.
 *
 * @version    1.0
 * @package    control
 * @subpackage clinic
 */
class CommunicationComposeForm extends TPage
{
    protected $form;

    private const ACTION_SAVE = 'CommunicationComposeForm::onSave';
    private const ACTION_LOAD = 'CommunicationComposeForm::onLoad';
    private const ACTION_CHANGE_TEMPLATE = 'CommunicationComposeForm::onChangeTemplate';
    private const ACTION_CHANGE_CHANNEL = 'CommunicationComposeForm::onChangeChannel';

    private const FORM_NAME = 'form_CommunicationCompose';

    private const CHANNELS = ['email', 'whatsapp'];
    private const DEFAULT_CHANNEL = 'email';
    private const DEFAULT_PURPOSE = 'custom';

    /** Texto livre: só pelo corpo do POST. */
    private const TEXT_FIELDS = ['subject', 'body_text'];

    public function __construct($param = null)
    {
        parent::__construct();

        $tutorId = self::paramInt('tutor_id', $param);
        $patientId = self::paramInt('patient_id', $param);

        $tutorName = null;
        $patients = [];

        if ($tutorId !== null && $tutorId > 0)
        {
            $tutorName = static::loadTutorName($tutorId);
            $patients = static::loadPatients($tutorId);
        }

        $container = new TVBox;
        $container->style = 'width: 100%';
        // CvPage::header escapa título e subtítulo
        $container->add(CvPage::header(_t('Compose message'), $tutorName, [[
            'icon' => 'fa:arrow-left',
            'href' => self::returnUrl($tutorId),
        ]]));

        if ($tutorId === null || $tutorId <= 0)
        {
            $container->add(self::emptyState(_t('Provide a tutor_id to compose a message.')));
            parent::add($container);
            return;
        }

        $channelValue = self::channelFromRequest($param);
        $purposeValue = self::purposeFromRequest($param);

        $this->form = new BootstrapFormBuilder(self::FORM_NAME);
        // BootstrapFormBuilder não escapa o título
        $this->form->setFormTitle(CvFormat::e($tutorName ?? (_t('Tutor') . ' #' . $tutorId)));

        $tutor_id = new THidden('tutor_id');
        $tutor_id->setValue($tutorId);
        $hiddenRow = $this->form->addFields([$tutor_id]);
        $hiddenRow->style = 'display: none';

        $patient_id = new TCombo('patient_id');
        $patient_id->addItems($patients);

        if ($patientId !== null && isset($patients[$patientId]))
        {
            $patient_id->setValue($patientId);
        }

        $channel = new TCombo('channel');
        $channel->addItems(self::channelOptions());
        $channel->setDefaultOption(false);
        $channel->setValue($channelValue);
        $channel->setChangeAction(new TAction([__CLASS__, 'onChangeChannel']));

        $purpose = new TCombo('purpose');
        $purpose->addItems(self::purposeOptions());
        $purpose->setDefaultOption(false);
        $purpose->setValue($purposeValue);

        $template_id = new TCombo('template_id');
        $template_id->addItems(static::loadTemplateOptions($channelValue, self::ACTION_LOAD));
        $template_id->setChangeAction(new TAction([__CLASS__, 'onChangeTemplate']));

        $subject = new TEntry('subject');
        $subject->setProperty('maxlength', '190');
        $subject->setProperty('autocomplete', 'off');

        $body_text = new TText('body_text');
        $body_text->setSize('100%', 220);
        $body_text->setProperty('maxlength', '2000');
        $body_text->setProperty('required', 'required');
        $body_text->setProperty('aria-required', 'true');

        $this->form->addFields([new TLabel(_t('Patient'))], [$patient_id]);
        $this->form->addFields([new TLabel(_t('Channel') . ' *', '#dc3545')], [$channel]);
        $this->form->addFields([new TLabel(_t('Purpose') . ' *', '#dc3545')], [$purpose]);
        $this->form->addFields([new TLabel(_t('Template'))], [$template_id]);
        $this->form->addFields([new TLabel(_t('Subject'))], [$subject]);
        $this->form->addFields([new TLabel(_t('Message') . ' *', '#dc3545')]);
        $this->form->addFields([$body_text]);

        $btn = $this->form->addAction(_t('Save'), new TAction([$this, 'onSave']), 'fa:paper-plane');
        $btn->class = 'btn btn-primary btn-lg cv-touch-target';
        // um toque: evita mensagem em dobro por clique repetido
        $btn->addFunction("this.disabled=true");

        $prefs = $this->form->addActionLink(_t('Communication preferences'), new TAction(['TutorCommunicationForm', 'onReload'], ['tutor_id' => $tutorId]), 'fa:address-card');
        $prefs->class = 'btn btn-outline-secondary btn-lg cv-touch-target';

        CvForm::decorate($this->form, 2);

        $container->add($this->form);

        parent::add($container);
    }

    /** Ação de navegação: a tela é montada no construtor a partir do request. */
    public function onReload($param = null)
    {
    }

    /** Troca de canal: recarrega o combo com os templates ativos do canal. */
    public static function onChangeChannel($param)
    {
        $channel = is_array($param) ? (string) ($param['channel'] ?? '') : '';

        if (!in_array($channel, self::CHANNELS, true))
        {
            $channel = self::DEFAULT_CHANNEL;
        }

        CvCombo::reload(self::FORM_NAME, 'template_id', static::loadTemplateOptions($channel, self::ACTION_CHANGE_CHANNEL), true, false);
    }

    /**
     * Template escolhido: assunto e corpo renderizados (tutor, paciente,
     * unidade ativa e clínica; marcadores sem valor, como data e hora do
     * agendamento, ficam visíveis como {{nome}} para o atendente trocar, e o
     * compose recusa o texto enquanto houver um) e a finalidade do template.
     */
    public static function onChangeTemplate($param)
    {
        $templateId = self::intOf($param, 'template_id');
        $tutorId = self::intOf($param, 'tutor_id');
        $patientId = self::intOf($param, 'patient_id');

        if ($templateId <= 0 || $tutorId <= 0)
        {
            return;
        }

        try
        {
            $rendered = static::renderTemplateData($templateId, $tutorId, $patientId > 0 ? $patientId : null);

            if ($rendered === null)
            {
                return;
            }

            $data = [
                'subject' => $rendered['subject'],
                'body_text' => $rendered['body'],
            ];

            if ($rendered['purpose'] !== null)
            {
                $data['purpose'] = $rendered['purpose'];
            }

            self::sendTemplateData($data);
        }
        catch (Exception $e)
        {
            TTransaction::rollback();
            error_log(__METHOD__ . ': ' . get_class($e) . ' template_id=' . $templateId . ' tutor_id=' . $tutorId);
            new TMessage('error', CvFormat::userError($e));
        }
    }

    /**
     * Assunto e corpo renderizados e a finalidade do template (null quando
     * o template não é encontrado na leitura da finalidade).
     *
     * @return array{subject: string, body: string, purpose: ?string}|null
     */
    protected static function renderTemplateData(int $templateId, int $tutorId, ?int $patientId): ?array
    {
        $context = self::resolveTenantContext();

        TTransaction::open('permission');

        $connection = TTransaction::get();
        $rendered = self::makeMessageService($context)->renderTemplate($templateId, $tutorId, $patientId, self::ACTION_CHANGE_TEMPLATE);
        $template = (new \CentralVet\Persistence\MessageTemplateRepository($context, $connection))->findById($templateId);

        TTransaction::close();

        return [
            'subject' => (string) ($rendered['subject'] ?? ''),
            'body' => (string) $rendered['body'],
            'purpose' => $template instanceof \CentralVet\Domain\MessageTemplate ? $template->purpose() : null,
        ];
    }

    /**
     * Preenche o formulário como TForm::sendData (sem disparar eventos), mas
     * com cada valor como literal JS em JSON com JSON_HEX_*: o texto do
     * template é livre e TForm::sendData só aplica addslashes, o que deixava
     * `</script>` fechar o script e injetar HTML (revisão final, T-24).
     *
     * @param array<string, string> $data
     */
    private static function sendTemplateData(array $data): void
    {
        $flags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;
        $form = json_encode(self::FORM_NAME, $flags);

        foreach ($data as $field => $value)
        {
            $name = json_encode((string) $field, $flags);
            $literal = json_encode($value, $flags);

            TScript::create(" tform_send_data({$form}, {$name}, {$literal}, false, '0'); ");
            TScript::create(" tform_send_data_by_id({$form}, {$name}, {$literal}, false, '0'); ");
        }
    }

    public function onSave($param)
    {
        $tutorId = 0;

        try
        {
            $tutorId = self::intOf($param, 'tutor_id');

            if ($tutorId <= 0)
            {
                throw new InvalidArgumentException('tutor_id must be a positive integer');
            }

            // texto livre só pelo corpo do POST (nunca pela query string)
            $subject = trim((string) ($_POST['subject'] ?? ''));
            $body = (string) ($_POST['body_text'] ?? '');

            if (trim($body) === '')
            {
                throw new InvalidArgumentException(_t('The message text is required'));
            }

            $channel = is_array($param) ? trim((string) ($param['channel'] ?? '')) : '';
            $purpose = is_array($param) ? trim((string) ($param['purpose'] ?? '')) : '';
            $patientId = self::intOf($param, 'patient_id');
            $templateId = self::intOf($param, 'template_id');

            $context = self::resolveTenantContext();

            TTransaction::open('permission');

            $message = self::makeMessageService($context)->compose([
                'tutor_id' => $tutorId,
                'patient_id' => $patientId > 0 ? $patientId : null,
                'channel' => $channel,
                'purpose' => $purpose,
                'template_id' => $templateId > 0 ? $templateId : null,
                'subject' => $subject === '' ? null : $subject,
                'body_text' => $body,
            ], self::ACTION_SAVE);

            TTransaction::close();

            $messageId = (int) $message->id();

            // publicação só depois do commit; falha fica para a varredura do agendador
            if ($message->channel() === 'email')
            {
                try
                {
                    (new \CentralVet\Application\MessageQueuePublisher(\CentralVet\Queue\RedisQueue::fromEnvironment()))
                        ->publish($context->tenantId(), $messageId);
                }
                catch (Throwable $publishError)
                {
                    error_log(__METHOD__ . ': publish failed for message_id=' . $messageId . ' (' . get_class($publishError) . ')');
                }
            }

            TToast::show('success', _t('Message registered'));
            TScript::create("__adianti_goto_page('index.php?class=CommunicationMessageView&id=" . $messageId . "')");
        }
        catch (\CentralVet\Domain\Exception\CommunicationConsentRequiredException $e)
        {
            TTransaction::rollback();
            $this->keepTypedData($param);
            error_log(__METHOD__ . ': consent refused for tutor_id=' . $tutorId);
            new TMessage('error', CvFormat::userError($e) . '<br><br>'
                . '<a href="index.php?class=TutorCommunicationForm&tutor_id=' . (int) $tutorId . '" generator="adianti">'
                . CvFormat::e(_t('Review the communication preferences of the tutor')) . '</a>');
        }
        catch (\CentralVet\Authorization\Exception\AuthorizationDenied $e)
        {
            TTransaction::rollback();
            $this->keepTypedData($param);
            new TMessage('error', _t('You are not allowed to send messages'));
        }
        catch (\CentralVet\Tenancy\Exception\MissingTenantContext $e)
        {
            TTransaction::rollback();
            $this->keepTypedData($param);
            new TMessage('error', _t('An authenticated session with an active unit is required'));
        }
        catch (Exception $e)
        {
            TTransaction::rollback();
            $this->keepTypedData($param);
            // mensagens de domínio carregam só ids (sem contato nem texto)
            error_log(__METHOD__ . ': ' . get_class($e) . ' tutor_id=' . $tutorId);
            new TMessage('error', CvFormat::userError($e));
        }
    }

    /**
     * A página é reconstruída no POST: sem isto o formulário voltaria vazio.
     * Texto livre só do POST; o resto (ids e códigos) do request.
     */
    private function keepTypedData($param): void
    {
        if ($this->form !== null)
        {
            $data = [];

            foreach (self::TEXT_FIELDS as $name)
            {
                if (array_key_exists($name, $_POST))
                {
                    $data[$name] = (string) $_POST[$name];
                }
            }

            foreach (['patient_id', 'channel', 'purpose', 'template_id'] as $name)
            {
                if (is_array($param) && isset($param[$name]) && is_scalar($param[$name]))
                {
                    $data[$name] = (string) $param[$name];
                }
            }

            $this->form->setData((object) $data);
        }

        TScript::create("document.querySelectorAll('#" . self::FORM_NAME . " button').forEach(function(b){b.disabled=false;})");
    }

    /** Nome do tutor (nunca e-mail ou telefone) ou null sem sessão/conexão. */
    protected static function loadTutorName(int $tutorId): ?string
    {
        try
        {
            $context = self::resolveTenantContext();

            TTransaction::open('permission');
            $tutor = (new \CentralVet\Persistence\TutorRepository($context, TTransaction::get()))->findById($tutorId);
            TTransaction::close();

            return $tutor instanceof \CentralVet\Domain\Tutor ? $tutor->fullName : null;
        }
        catch (Throwable $e)
        {
            TTransaction::rollback();
            error_log(__METHOD__ . ': ' . get_class($e) . ' tutor_id=' . $tutorId);

            return null;
        }
    }

    /** @return array<int, string> id do paciente → nome (pacientes do tutor) */
    protected static function loadPatients(int $tutorId): array
    {
        try
        {
            $context = self::resolveTenantContext();

            TTransaction::open('permission');
            $patients = (new \CentralVet\Persistence\PatientRepository($context, TTransaction::get()))->findByTutor($tutorId);
            TTransaction::close();

            $options = [];

            foreach ($patients as $patient)
            {
                $options[(int) $patient->id] = (string) $patient->name;
            }

            return $options;
        }
        catch (Throwable $e)
        {
            TTransaction::rollback();
            error_log(__METHOD__ . ': ' . get_class($e) . ' tutor_id=' . $tutorId);

            return [];
        }
    }

    /** @return array<int, string> id → nome dos templates ativos do canal */
    protected static function loadTemplateOptions(string $channel, string $action): array
    {
        try
        {
            $context = self::resolveTenantContext();

            TTransaction::open('permission');
            $templates = self::makeTemplateService($context)->list($action);
            TTransaction::close();

            $purposes = self::purposeOptions();
            $options = [];

            foreach ($templates as $template)
            {
                if ($template->isActive() && $template->channel() === $channel)
                {
                    $options[(int) $template->id()] = $template->name() . ' — ' . ($purposes[$template->purpose()] ?? $template->purpose());
                }
            }

            return $options;
        }
        catch (Throwable $e)
        {
            TTransaction::rollback();
            error_log(__METHOD__ . ': ' . get_class($e));

            return [];
        }
    }

    /** @return array<string, string> */
    private static function channelOptions(): array
    {
        return ['email' => _t('E-mail'), 'whatsapp' => _t('WhatsApp')];
    }

    /** @return array<string, string> finalidade → rótulo traduzido */
    private static function purposeOptions(): array
    {
        $labels = [
            'appointment_confirmation' => _t('Appointment confirmation'),
            'vaccine_due' => _t('Vaccine due'),
            'return_reminder' => _t('Return reminder'),
            'receivable_open' => _t('Open receivable'),
            'document_ready' => _t('Document ready'),
            'custom' => _t('Custom message'),
        ];

        $options = [];

        foreach (\CentralVet\Domain\MessagePurpose::all() as $purpose)
        {
            $options[$purpose] = $labels[$purpose] ?? $purpose;
        }

        return $options;
    }

    private static function channelFromRequest($param): string
    {
        $raw = $_POST['channel'] ?? $_GET['channel'] ?? (is_array($param) ? ($param['channel'] ?? null) : null);

        return is_string($raw) && in_array($raw, self::CHANNELS, true) ? $raw : self::DEFAULT_CHANNEL;
    }

    private static function purposeFromRequest($param): string
    {
        $raw = $_POST['purpose'] ?? $_GET['purpose'] ?? (is_array($param) ? ($param['purpose'] ?? null) : null);

        return is_string($raw) && in_array($raw, \CentralVet\Domain\MessagePurpose::all(), true) ? $raw : self::DEFAULT_PURPOSE;
    }

    private static function emptyState(string $message): TElement
    {
        $state = new TElement('div');
        $state->{'class'} = 'cv-state cv-state--empty';
        $state->add(TElement::tag('p', CvFormat::e($message), ['class' => 'cv-state__title']));

        return $state;
    }

    private static function returnUrl(?int $tutorId): string
    {
        return ($tutorId !== null && $tutorId > 0)
            ? 'index.php?class=TutorForm&method=onEdit&id=' . $tutorId
            : 'index.php?class=TutorList';
    }

    private static function paramInt(string $name, $param): ?int
    {
        $raw = $_GET[$name] ?? (is_array($param) ? ($param[$name] ?? null) : null);
        $raw = $raw === null || !is_scalar($raw) ? '' : trim((string) $raw);

        return ($raw !== '' && ctype_digit($raw)) ? (int) $raw : null;
    }

    private static function intOf($param, string $name): int
    {
        $raw = is_array($param) && isset($param[$name]) && is_scalar($param[$name]) ? trim((string) $param[$name]) : '';

        return ($raw !== '' && ctype_digit($raw)) ? (int) $raw : 0;
    }

    /** Requer TTransaction('permission') aberta. */
    private static function makeMessageService(\CentralVet\Tenancy\TenantContext $context): \CentralVet\Application\MessageService
    {
        $connection = TTransaction::get();

        return new \CentralVet\Application\MessageService(
            new \CentralVet\Persistence\OutboundMessageRepository($context, $connection),
            new \CentralVet\Persistence\MessageTemplateRepository($context, $connection),
            new \CentralVet\Persistence\CommunicationPreferenceRepository($context, $connection),
            new \CentralVet\Persistence\TutorRepository($context, $connection),
            new \CentralVet\Persistence\PatientRepository($context, $connection),
            self::makeAuthorization($connection),
            $context,
            senderNames: new \CentralVet\Persistence\SenderNamesQuery($context, $connection),
        );
    }

    /** Requer TTransaction('permission') aberta. */
    private static function makeTemplateService(\CentralVet\Tenancy\TenantContext $context): \CentralVet\Application\MessageTemplateService
    {
        $connection = TTransaction::get();

        return new \CentralVet\Application\MessageTemplateService(
            new \CentralVet\Persistence\MessageTemplateRepository($context, $connection),
            self::makeAuthorization($connection),
            $context,
        );
    }

    private static function makeAuthorization(PDO $connection): \CentralVet\Authorization\RbacAuthorizationService
    {
        return new \CentralVet\Authorization\RbacAuthorizationService(
            new \CentralVet\Authorization\AdiantiProgramPermissionProvider(new \CentralVet\Tenancy\AdiantiSessionContextSource()),
            new \CentralVet\Audit\PdoAuditLogWriter($connection),
        );
    }

    /**
     * Contexto do tenant da sessão autenticada (cópia de
     * SurgeryConsentForm::resolveTenantContext()).
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
