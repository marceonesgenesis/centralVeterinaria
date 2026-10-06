<?php

/**
 * CommunicationMessageView
 *
 * Ficha da mensagem (Fase 7A, T-17): status, datas, finalidade, origem,
 * base legal, destinatário completo, assunto e corpo (escapados; quebras
 * de linha com nl2br depois do escape) e o motivo da falha traduzido. A
 * coluna lateral mostra as ações do status atual:
 *
 * - WhatsApp `queued`: "Abrir WhatsApp" (link wa.me de
 *   MessageService::whatsAppLink, nova aba com rel="noopener noreferrer";
 *   o link só existe no HTML da ficha, nunca em log nem em banco),
 *   "Marcar como enviado" e "Descartar";
 * - e-mail `queued`: "Descartar";
 * - `failed`: "Reenviar" (retry; depois do commit, o e-mail é publicado na
 *   fila; falha no push só vai para o error_log com o id, e o agendador
 *   republica os e-mails presos).
 *
 * Confirmações por TQuestion com só o id no parâmetro. Toda regra vive no
 * MessageService; esta tela só lê e chama. Sem `id` → estado vazio antes de
 * resolver o tenant.
 *
 * @version    1.0
 * @package    control
 * @subpackage clinic
 */
class CommunicationMessageView extends TPage
{
    private const ACTION_READ = 'CommunicationMessageView::onReload';
    private const ACTION_MARK_SENT = 'CommunicationMessageView::onMarkSent';
    private const ACTION_CANCEL = 'CommunicationMessageView::onCancel';
    private const ACTION_RETRY = 'CommunicationMessageView::onRetry';

    private const TOUCH = 'min-height:var(--cv-touch-target)';

    private ?int $messageId;

    public function __construct($param = null)
    {
        parent::__construct();

        $this->messageId = self::paramInt('id', $param);

        $container = new TVBox;
        $container->style = 'width: 100%';

        $back = ['icon' => 'fa:arrow-left', 'href' => 'index.php?class=CommunicationMessageList'];

        if ($this->messageId === null)
        {
            $container->add(CvPage::header(_t('Message'), null, [$back]));
            $container->add(self::statePanel('empty', _t('Open a message from the message history.')));
            parent::add($container);
            return;
        }

        $data = $this->loadData($this->messageId);

        if ($data === null)
        {
            $container->add(CvPage::header(_t('Message'), null, [$back]));
            $container->add(self::statePanel('error', _t('This message could not be loaded.')));
            parent::add($container);
            return;
        }

        /** @var \CentralVet\Domain\OutboundMessage $message */
        $message = $data['message'];

        $container->add(CvPage::header(
            _t('Message') . ' #' . (int) $message->id(),
            self::channelLabel($message->channel()) . ' · ' . self::purposeLabel($message->purpose()),
            [self::statusBadge($message->status()), $back]
        ));

        $container->add(CvPage::columns($this->buildDetailPanel($data), $this->buildActionsPanel($data)));

        parent::add($container);
    }

    /**
     * Destino do OK das mensagens: o construtor já renderiza a ficha com o
     * `id` da URL.
     */
    public function onReload($param = null)
    {
    }

    /** Confirmação do envio manual do WhatsApp: o TQuestion carrega só o id. */
    public static function onAskMarkSent($param = null)
    {
        self::ask('onMarkSent', $param, _t('Confirm that this WhatsApp message was sent?'));
    }

    /** queued (WhatsApp) → manual (MessageService::markManualSent). */
    public static function onMarkSent($param)
    {
        $id = self::paramInt('id', $param);

        self::runChange($id, function (\CentralVet\Tenancy\TenantContext $context, int $id): void {
            self::makeMessageService($context)->markManualSent($id, self::ACTION_MARK_SENT);
        }, _t('Message marked as sent'));
    }

    /** Confirmação do descarte: o TQuestion carrega só o id. */
    public static function onAskCancel($param = null)
    {
        self::ask('onCancel', $param, _t('Discard this message? It will not be sent.'));
    }

    /** queued → cancelled (MessageService::cancel). */
    public static function onCancel($param)
    {
        $id = self::paramInt('id', $param);

        self::runChange($id, function (\CentralVet\Tenancy\TenantContext $context, int $id): void {
            self::makeMessageService($context)->cancel($id, self::ACTION_CANCEL);
        }, _t('Message discarded'));
    }

    /** Confirmação do reenvio: o TQuestion carrega só o id. */
    public static function onAskRetry($param = null)
    {
        self::ask('onRetry', $param, _t('Send this message again?'));
    }

    /**
     * failed → queued (MessageService::retry). Depois do commit, o e-mail é
     * publicado na fila; falha no push só registra o id no error_log (o
     * agendador republica e-mails presos) e a tela confirma o reenfileiramento.
     */
    public static function onRetry($param)
    {
        $id = self::paramInt('id', $param);

        try
        {
            if ($id === null)
            {
                throw new InvalidArgumentException(_t('Invalid message'));
            }

            $context = self::resolveTenantContext();

            TTransaction::open('permission');
            $message = self::makeMessageService($context)->retry($id, self::ACTION_RETRY);
            TTransaction::close();

            if ($message->channel() === \CentralVet\Domain\CommunicationChannel::EMAIL)
            {
                try
                {
                    (new \CentralVet\Application\MessageQueuePublisher(\CentralVet\Queue\RedisQueue::fromEnvironment()))
                        ->publish($context->tenantId(), $id);
                }
                catch (Throwable $publishError)
                {
                    // só o id e a classe: nada de destinatário nem corpo
                    error_log(__METHOD__ . ': queue publish failed for message ' . $id . ' (' . get_class($publishError) . ')');
                }
            }

            new TMessage('info', _t('Message requeued'), self::reloadAction($id));
        }
        catch (\CentralVet\Authorization\Exception\AuthorizationDenied $e)
        {
            TTransaction::rollback();
            new TMessage('error', _t('You are not allowed to change this message'));
        }
        catch (\CentralVet\Tenancy\Exception\MissingTenantContext $e)
        {
            TTransaction::rollback();
            new TMessage('error', _t('An authenticated session with an active unit is required'));
        }
        catch (Exception $e)
        {
            TTransaction::rollback();
            error_log(__METHOD__ . ': ' . $e->getMessage());
            new TMessage('error', CvFormat::userError($e));
        }
    }

    private static function ask(string $method, $param, string $question): void
    {
        $action = new TAction([__CLASS__, $method]);
        $action->setParameter('id', (int) ($param['id'] ?? 0));
        $action->setParameter('static', '1');

        new TQuestion($question, $action);
    }

    /**
     * Roda uma transição num único TTransaction('permission') e recarrega a
     * ficha pelo OK; qualquer exceção dá rollback.
     *
     * @param callable(\CentralVet\Tenancy\TenantContext, int): void $change
     */
    private static function runChange(?int $id, callable $change, string $success): void
    {
        try
        {
            if ($id === null)
            {
                throw new InvalidArgumentException(_t('Invalid message'));
            }

            $context = self::resolveTenantContext();

            TTransaction::open('permission');
            $change($context, $id);
            TTransaction::close();

            new TMessage('info', $success, self::reloadAction($id));
        }
        catch (\CentralVet\Authorization\Exception\AuthorizationDenied $e)
        {
            TTransaction::rollback();
            new TMessage('error', _t('You are not allowed to change this message'));
        }
        catch (\CentralVet\Tenancy\Exception\MissingTenantContext $e)
        {
            TTransaction::rollback();
            new TMessage('error', _t('An authenticated session with an active unit is required'));
        }
        catch (Exception $e)
        {
            TTransaction::rollback();
            error_log(__METHOD__ . ': ' . $e->getMessage());
            new TMessage('error', CvFormat::userError($e));
        }
    }

    /**
     * Lê a mensagem (autorizada contra a unidade dela), os nomes de tutor e
     * paciente e, para WhatsApp `queued`, o link wa.me.
     *
     * @return array{message: \CentralVet\Domain\OutboundMessage, tutor_name: string, patient_name: ?string, whatsapp_url: ?string}|null
     */
    private function loadData(int $id): ?array
    {
        try
        {
            $context = self::resolveTenantContext();

            TTransaction::open('permission');
            $connection = TTransaction::get();

            $service = self::makeMessageService($context);
            $message = $service->find($id, self::ACTION_READ);

            $whatsAppUrl = null;
            if ($message->channel() === \CentralVet\Domain\CommunicationChannel::WHATSAPP
                && $message->status() === \CentralVet\Domain\OutboundMessage::STATUS_QUEUED)
            {
                $whatsAppUrl = $service->whatsAppLink($id, self::ACTION_READ);
            }

            $tutorName = self::lookupName($connection, 'SELECT full_name FROM tutor WHERE tenant_id = ? AND id = ?', $context->tenantId(), $message->tutorId());
            $patientName = $message->patientId() !== null
                ? self::lookupName($connection, 'SELECT name FROM patient WHERE tenant_id = ? AND id = ?', $context->tenantId(), $message->patientId())
                : null;

            TTransaction::close();

            return [
                'message' => $message,
                'tutor_name' => $tutorName ?? _t('Tutor') . ' #' . $message->tutorId(),
                'patient_name' => $patientName,
                'whatsapp_url' => $whatsAppUrl,
            ];
        }
        catch (\CentralVet\Authorization\Exception\AuthorizationDenied $e)
        {
            TTransaction::rollback();
            new TMessage('error', _t('You are not allowed to view this message'));
        }
        catch (\CentralVet\Tenancy\Exception\MissingTenantContext $e)
        {
            TTransaction::rollback();
            new TMessage('error', _t('An authenticated session with an active unit is required'));
        }
        catch (Exception $e)
        {
            TTransaction::rollback();
            error_log(__METHOD__ . ': ' . $e->getMessage());
            new TMessage('error', CvFormat::userError($e));
        }

        return null;
    }

    private static function lookupName(PDO $connection, string $sql, int $tenantId, int $id): ?string
    {
        $statement = $connection->prepare($sql);
        $statement->execute([$tenantId, $id]);
        $name = $statement->fetchColumn();

        return $name === false || $name === null ? null : (string) $name;
    }

    /**
     * @param array{message: \CentralVet\Domain\OutboundMessage, tutor_name: string, patient_name: ?string, whatsapp_url: ?string} $data
     */
    private function buildDetailPanel(array $data): TElement
    {
        $m = $data['message'];

        $panel = new TPanelGroup(_t('Message details'));
        $panel->class = 'cv-section';

        $list = new TElement('dl');
        $list->{'class'} = 'row';
        $list->style = 'margin:0';

        $rows = [
            _t('Status') => self::statusBadge($m->status()),
            _t('Channel') => CvFormat::e(self::channelLabel($m->channel())),
            _t('Purpose') => CvFormat::e(self::purposeLabel($m->purpose())),
            _t('Origin') => CvFormat::e(self::originLabel($m->origin())),
            _t('Legal basis') => CvFormat::e(self::legalBasisLabel($m->legalBasis())),
            _t('Tutor') => CvFormat::e($data['tutor_name']),
        ];

        if ($data['patient_name'] !== null)
        {
            $rows[_t('Patient')] = CvFormat::e($data['patient_name']);
        }

        $rows[_t('Recipient')] = CvFormat::e($m->recipient());
        $rows[_t('Created at')] = CvFormat::e(self::date($m->createdAt()));

        if ($m->sentAt() !== null)
        {
            $rows[_t('Sent at')] = CvFormat::e(self::date($m->sentAt()));
        }

        if ($m->failedAt() !== null)
        {
            $rows[_t('Failed at')] = CvFormat::e(self::date($m->failedAt()));
        }

        if ($m->cancelledAt() !== null)
        {
            $rows[_t('Cancelled at')] = CvFormat::e(self::date($m->cancelledAt()));
        }

        if ($m->attemptCount() > 0)
        {
            $rows[_t('Attempts')] = CvFormat::e((string) $m->attemptCount());
        }

        if ($m->lastErrorCode() !== null)
        {
            $rows[_t('Reason')] = CvFormat::e(self::errorLabel($m->lastErrorCode()));
        }

        foreach ($rows as $label => $value)
        {
            $list->add(TElement::tag('dt', CvFormat::e($label), ['class' => 'col-sm-4']));
            $list->add(TElement::tag('dd', $value, ['class' => 'col-sm-8']));
        }

        $panel->add($list);

        if ($m->subject() !== null)
        {
            $panel->add(TElement::tag('h3', CvFormat::e(_t('Subject')), ['style' => 'font-size:1rem; margin:var(--cv-space-3) 0 var(--cv-space-1)']));
            $panel->add(TElement::tag('p', CvFormat::e($m->subject()), ['style' => 'margin:0']));
        }

        $panel->add(TElement::tag('h3', CvFormat::e(_t('Message text')), ['style' => 'font-size:1rem; margin:var(--cv-space-3) 0 var(--cv-space-1)']));
        $panel->add(TElement::tag('div', nl2br(CvFormat::e($m->bodyText())), ['class' => 'cv-message-body', 'style' => 'white-space:normal; overflow-wrap:anywhere']));

        return $panel;
    }

    /**
     * @param array{message: \CentralVet\Domain\OutboundMessage, tutor_name: string, patient_name: ?string, whatsapp_url: ?string} $data
     */
    private function buildActionsPanel(array $data): TElement
    {
        $m = $data['message'];
        $id = (int) $m->id();

        $panel = new TPanelGroup(_t('Actions'));
        $panel->class = 'cv-section';

        $buttons = new TElement('div');
        $buttons->style = 'display:flex; flex-direction:column; gap:var(--cv-space-2)';

        $isWhatsApp = $m->channel() === \CentralVet\Domain\CommunicationChannel::WHATSAPP;

        switch ($m->status())
        {
            case \CentralVet\Domain\OutboundMessage::STATUS_QUEUED:
                if ($isWhatsApp)
                {
                    if ($data['whatsapp_url'] !== null)
                    {
                        $buttons->add(self::externalLink(_t('Open WhatsApp'), $data['whatsapp_url'], 'fab:whatsapp', 'btn btn-success'));
                    }
                    $buttons->add(self::actionButton(_t('Mark as sent'), 'onAskMarkSent', $id, 'fa:check', 'btn btn-primary'));
                }
                $buttons->add(self::actionButton(_t('Discard'), 'onAskCancel', $id, 'fa:ban', 'btn btn-default'));
                break;

            case \CentralVet\Domain\OutboundMessage::STATUS_FAILED:
                $buttons->add(self::actionButton(_t('Retry'), 'onAskRetry', $id, 'fa:redo', 'btn btn-primary'));
                break;
        }

        if (empty($buttons->getChildren()))
        {
            $panel->add(self::statePanel('empty', _t('No actions available for this message.')));
        }
        else
        {
            $panel->add($buttons);
        }

        return $panel;
    }

    public static function statusBadge(string $status): TElement
    {
        $tone = match ($status) {
            \CentralVet\Domain\OutboundMessage::STATUS_QUEUED    => 'warning',
            \CentralVet\Domain\OutboundMessage::STATUS_SENT      => 'success',
            \CentralVet\Domain\OutboundMessage::STATUS_MANUAL    => 'success',
            \CentralVet\Domain\OutboundMessage::STATUS_FAILED    => 'danger',
            default                                              => 'neutral',
        };

        return CvBadge::create(self::statusLabel($status), $tone);
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            \CentralVet\Domain\OutboundMessage::STATUS_QUEUED    => _t('Queued'),
            \CentralVet\Domain\OutboundMessage::STATUS_SENT      => _t('Sent'),
            \CentralVet\Domain\OutboundMessage::STATUS_MANUAL    => _t('Sent manually'),
            \CentralVet\Domain\OutboundMessage::STATUS_FAILED    => _t('Failed'),
            \CentralVet\Domain\OutboundMessage::STATUS_CANCELLED => _t('Cancelled'),
            default                                              => $status,
        };
    }

    public static function channelLabel(string $channel): string
    {
        return match ($channel) {
            \CentralVet\Domain\CommunicationChannel::EMAIL    => _t('E-mail'),
            \CentralVet\Domain\CommunicationChannel::WHATSAPP => _t('WhatsApp'),
            default                                           => $channel,
        };
    }

    public static function purposeLabel(string $purpose): string
    {
        return match ($purpose) {
            \CentralVet\Domain\MessagePurpose::APPOINTMENT_CONFIRMATION => _t('Appointment confirmation'),
            \CentralVet\Domain\MessagePurpose::VACCINE_DUE              => _t('Vaccine due'),
            \CentralVet\Domain\MessagePurpose::RETURN_REMINDER          => _t('Follow-up reminder'),
            \CentralVet\Domain\MessagePurpose::RECEIVABLE_OPEN          => _t('Open receivable'),
            \CentralVet\Domain\MessagePurpose::DOCUMENT_READY           => _t('Document ready'),
            \CentralVet\Domain\MessagePurpose::CUSTOM                   => _t('Custom message'),
            default                                                     => $purpose,
        };
    }

    private static function originLabel(string $origin): string
    {
        return match ($origin) {
            \CentralVet\Domain\OutboundMessage::ORIGIN_AUTOMATION => _t('Automatic'),
            \CentralVet\Domain\OutboundMessage::ORIGIN_MANUAL     => _t('Manual'),
            default                                               => $origin,
        };
    }

    private static function legalBasisLabel(string $basis): string
    {
        return match ($basis) {
            \CentralVet\Domain\MessagePurpose::LEGAL_BASIS_LEGITIMATE_INTEREST => _t('Legitimate interest'),
            \CentralVet\Domain\MessagePurpose::LEGAL_BASIS_CONSENT             => _t('Consent'),
            default                                                            => $basis,
        };
    }

    private static function errorLabel(string $code): string
    {
        return match ($code) {
            'smtp_connect'            => _t('Could not connect to the e-mail server'),
            'smtp_auth'               => _t('E-mail server authentication failed'),
            'smtp_recipient_rejected' => _t('Recipient rejected by the e-mail server'),
            'smtp_error'              => _t('E-mail server error'),
            'provider_error'          => _t('Delivery provider error'),
            'opted_out'               => _t('Tutor opted out of this channel'),
            'consent_missing'         => _t('Tutor has not consented to this channel'),
            'discarded'               => _t('Discarded by staff'),
            default                   => $code,
        };
    }

    private static function date(?DateTimeImmutable $date): string
    {
        return $date !== null ? $date->format('d/m/Y H:i') : '—';
    }

    private static function actionButton(string $label, string $method, int $id, string $icon, string $class): TElement
    {
        $action = new TAction([__CLASS__, $method]);
        $action->setParameter('id', $id);
        $action->setParameter('static', '1');

        $link = new TElement('a');
        $link->{'class'} = $class . ' cv-touch-target';
        $link->{'href'} = CvFormat::e($action->serialize(true));
        $link->{'generator'} = 'adianti';
        $link->style = self::TOUCH . '; display:inline-flex; align-items:center; gap:var(--cv-space-1)';
        $link->add(new TImage($icon));
        $link->add(TElement::tag('span', CvFormat::e($label), []));

        return $link;
    }

    /**
     * Link externo (wa.me): nova aba, sem opener nem referrer e fora do
     * roteador do Adianti (sem generator).
     */
    private static function externalLink(string $label, string $href, string $icon, string $class): TElement
    {
        $link = new TElement('a');
        $link->{'class'} = $class . ' cv-touch-target';
        $link->{'href'} = CvFormat::e($href);
        $link->{'target'} = '_blank';
        $link->{'rel'} = 'noopener noreferrer';
        $link->style = self::TOUCH . '; display:inline-flex; align-items:center; gap:var(--cv-space-1)';
        $link->add(new TImage($icon));
        $link->add(TElement::tag('span', CvFormat::e($label), []));

        return $link;
    }

    private static function statePanel(string $state, string $message): TElement
    {
        $box = new TElement('div');
        $box->{'class'} = 'cv-state cv-state--' . $state;
        $box->style = 'padding:var(--cv-space-4); text-align:center; color:var(--cv-color-text-muted)';
        $box->add(TElement::tag('p', CvFormat::e($message), ['style' => 'margin:0']));

        return $box;
    }

    private static function reloadAction(int $id): TAction
    {
        $action = new TAction([__CLASS__, 'onReload']);
        $action->setParameter('id', $id);

        return $action;
    }

    private static function paramInt(string $name, $param): ?int
    {
        if (isset($_GET[$name]) && $_GET[$name] !== '' && (int) $_GET[$name] > 0)
        {
            return (int) $_GET[$name];
        }

        if (is_array($param) && isset($param[$name]) && $param[$name] !== '' && (int) $param[$name] > 0)
        {
            return (int) $param[$name];
        }

        return null;
    }

    /**
     * RBAC real com auditoria na mesma conexão. Exige um
     * TTransaction('permission') aberto.
     */
    private static function makeAuthorization(): \CentralVet\Authorization\RbacAuthorizationService
    {
        return new \CentralVet\Authorization\RbacAuthorizationService(
            new \CentralVet\Authorization\AdiantiProgramPermissionProvider(new \CentralVet\Tenancy\AdiantiSessionContextSource()),
            new \CentralVet\Audit\PdoAuditLogWriter(TTransaction::get()),
        );
    }

    private static function makeMessageService(\CentralVet\Tenancy\TenantContext $context): \CentralVet\Application\MessageService
    {
        $connection = TTransaction::get();

        return new \CentralVet\Application\MessageService(
            new \CentralVet\Persistence\OutboundMessageRepository($context, $connection),
            new \CentralVet\Persistence\MessageTemplateRepository($context, $connection),
            new \CentralVet\Persistence\CommunicationPreferenceRepository($context, $connection),
            new \CentralVet\Persistence\TutorRepository($context, $connection),
            new \CentralVet\Persistence\PatientRepository($context, $connection),
            self::makeAuthorization(),
            $context,
        );
    }

    /**
     * Contexto de tenant da sessão autenticada, com o mesmo fallback de
     * SurgeryView::resolveTenantContext() para sessões legadas.
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
