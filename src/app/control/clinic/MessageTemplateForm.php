<?php
/**
 * MessageTemplateForm — cadastro e edição de template de mensagem do tenant
 * (Fase 7A, T-16).
 *
 * Campos: finalidade, canal, nome, assunto (obrigatório no e-mail; a regra
 * é do domínio) e corpo (textarea, enviado por POST). Um bloco de ajuda
 * lista os placeholders de MessageTemplateRenderer::PLACEHOLDERS entre
 * `{{ }}`. Sem regra própria: onSave() chama
 * CentralVet\Application\MessageTemplateService::save(); o status atual
 * viaja num campo oculto para a edição não reativar um template inativo.
 *
 * Rotas: index.php?class=MessageTemplateForm (novo) e
 * index.php?class=MessageTemplateForm&id=<template_id>.
 *
 * @version    1.0
 * @package    control
 * @subpackage clinic
 */
class MessageTemplateForm extends TPage
{
    private const ACTION_SAVE = 'MessageTemplateForm::onSave';
    private const ACTION_EDIT = 'MessageTemplateForm::onEdit';

    protected $form;
    private bool $loaded = false;
    private ?int $templateId = null;

    public function __construct($param = null)
    {
        parent::__construct();

        $this->templateId = self::paramId($param);

        $this->form = new BootstrapFormBuilder('form_MessageTemplate');
        $this->form->enableClientValidation();

        $id        = new THidden('id');
        $status    = new THidden('status');
        $purpose   = new TCombo('purpose');
        $channel   = new TCombo('channel');
        $name      = new TEntry('name');
        $subject   = new TEntry('subject');
        $body_text = new TText('body_text');

        $purposes = [];
        foreach (\CentralVet\Domain\MessagePurpose::all() as $code)
        {
            $purposes[$code] = self::purposeLabel($code);
        }
        $purpose->addItems($purposes);

        $channels = [];
        foreach (\CentralVet\Domain\CommunicationChannel::all() as $code)
        {
            $channels[$code] = self::channelLabel($code);
        }
        $channel->addItems($channels);

        $name->setMaxLength(120);
        $subject->setMaxLength(190);
        $body_text->setSize('100%', 180);

        CvForm::decorate($this->form, 2);

        $this->form->addFields( [new TLabel(_t('Purpose'))], [$purpose], [new TLabel(_t('Channel'))], [$channel] );
        $this->form->addFields( [new TLabel(_t('Name'))], [$name], [new TLabel(_t('Subject (e-mail only)'))], [$subject] );
        $this->form->addFields( [new TLabel(_t('Message text'))], [$body_text] );

        $hidden_row = $this->form->addFields( [$id], [$status] );
        $hidden_row->style = 'display: none';

        $purpose->addValidation( _t('Purpose'), new TRequiredValidator );
        $channel->addValidation( _t('Channel'), new TRequiredValidator );
        $name->addValidation( _t('Name'), new TRequiredValidator );
        $body_text->addValidation( _t('Message text'), new TRequiredValidator );

        $btn = $this->form->addAction(_t('Save'), new TAction([$this, 'onSave']), 'fa:check');
        $btn->class = 'btn btn-primary cv-touch-target';

        $container = new TVBox;
        $container->style = 'width: 100%';
        $container->add(CvPage::header($this->templateId !== null ? _t('Edit message template') : _t('New message template'), _t('Communication'), [
            ['icon' => 'fa:arrow-left', 'href' => 'index.php?class=MessageTemplateList'],
        ]));
        $container->add($this->form);
        $container->add(self::placeholderHelp());

        parent::add($container);
    }

    /**
     * Cria (sem id) ou edita (com id) pela MessageTemplateService.
     */
    public function onSave($param = null)
    {
        $this->loaded = true;

        try
        {
            $data = $this->form->getData();

            $this->form->validate();

            $context = self::resolveTenantContext();

            TTransaction::open('permission');

            $payload = [
                'purpose'   => (string) $data->purpose,
                'channel'   => (string) $data->channel,
                'name'      => (string) $data->name,
                'subject'   => (string) $data->subject !== '' ? (string) $data->subject : null,
                'body_text' => (string) $data->body_text,
            ];

            if (!empty($data->id))
            {
                $payload['id'] = (int) $data->id;
            }

            if (!empty($data->status))
            {
                $payload['status'] = (string) $data->status;
            }

            $template = self::makeMessageTemplateService($context)->save($payload, self::ACTION_SAVE);

            TTransaction::close();

            $data->id = $template->id();
            $data->status = $template->status();
            $this->form->setData($data);

            TToast::show('success', _t('Record saved'));
            TScript::create("__adianti_goto_page('index.php?class=MessageTemplateList')");
        }
        catch (\CentralVet\Authorization\Exception\AuthorizationDenied $e)
        {
            $this->form->setData($data ?? null);
            TTransaction::rollback();
            new TMessage('error', _t('You are not allowed to manage message templates'));
        }
        catch (\CentralVet\Tenancy\Exception\MissingTenantContext $e)
        {
            $this->form->setData($data ?? null);
            TTransaction::rollback();
            new TMessage('error', _t('An authenticated session with an active unit is required'));
        }
        catch (Exception $e)
        {
            $this->form->setData($data ?? null);
            TTransaction::rollback();
            error_log(__METHOD__ . ': ' . get_class($e));
            new TMessage('error', CvFormat::userError($e));
        }
    }

    /**
     * Carrega o template pela MessageTemplateService::find() (escopado ao
     * tenant). Sem id, formulário vazio.
     */
    public function onEdit($param = null)
    {
        $this->loaded = true;

        $template_id = self::paramId($param) ?? $this->templateId;

        if ($template_id === null)
        {
            $this->form->clear(true);
            return;
        }

        try
        {
            $context = self::resolveTenantContext();

            TTransaction::open('permission');

            $template = self::makeMessageTemplateService($context)->find($template_id, self::ACTION_EDIT);

            TTransaction::close();

            $data = new stdClass;
            $data->id        = $template->id();
            $data->status    = $template->status();
            $data->purpose   = $template->purpose();
            $data->channel   = $template->channel();
            $data->name      = $template->name();
            $data->subject   = $template->subject();
            $data->body_text = $template->bodyText();

            $this->form->setData($data);
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
     * `index.php?class=MessageTemplateForm&id=<id>` (sem method) carrega o template aqui.
     */
    public function show()
    {
        if (!$this->loaded && $this->templateId !== null)
        {
            $this->onEdit(['id' => $this->templateId]);
        }

        parent::show();
    }

    /** Rótulo traduzido da finalidade (texto puro: quem imprime escapa). */
    public static function purposeLabel(string $purpose): string
    {
        return match ($purpose)
        {
            \CentralVet\Domain\MessagePurpose::APPOINTMENT_CONFIRMATION => _t('Appointment confirmation'),
            \CentralVet\Domain\MessagePurpose::VACCINE_DUE              => _t('Vaccine due'),
            \CentralVet\Domain\MessagePurpose::RETURN_REMINDER          => _t('Return reminder'),
            \CentralVet\Domain\MessagePurpose::RECEIVABLE_OPEN          => _t('Open receivable'),
            \CentralVet\Domain\MessagePurpose::DOCUMENT_READY           => _t('Document ready'),
            \CentralVet\Domain\MessagePurpose::CUSTOM                   => _t('Custom message'),
            default                                                     => $purpose,
        };
    }

    /** Rótulo do canal (texto puro: quem imprime escapa). */
    public static function channelLabel(string $channel): string
    {
        return match ($channel)
        {
            \CentralVet\Domain\CommunicationChannel::EMAIL    => _t('E-mail'),
            \CentralVet\Domain\CommunicationChannel::WHATSAPP => _t('WhatsApp'),
            default                                           => $channel,
        };
    }

    /**
     * Ajuda com os placeholders aceitos no assunto e no corpo.
     */
    private static function placeholderHelp(): TElement
    {
        $help = new TElement('div');
        $help->{'class'} = 'cv-card';
        $help->add(TElement::tag('p', CvFormat::e(_t('Available placeholders (subject and message text)')), ['class' => 'cv-card__title']));

        $list = new TElement('ul');
        foreach (\CentralVet\Domain\MessageTemplateRenderer::PLACEHOLDERS as $placeholder)
        {
            $item = new TElement('li');
            $item->add(TElement::tag('code', CvFormat::e('{{' . $placeholder . '}}')));
            $list->add($item);
        }
        $help->add($list);

        return $help;
    }

    /**
     * id do template a partir de `id` ou `key`; null quando ausente/inválido.
     */
    private static function paramId($param): ?int
    {
        if (!is_array($param))
        {
            return null;
        }

        $raw = $param['id'] ?? ($param['key'] ?? null);

        if ($raw === null || $raw === '' || !ctype_digit((string) $raw) || (int) $raw <= 0)
        {
            return null;
        }

        return (int) $raw;
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
