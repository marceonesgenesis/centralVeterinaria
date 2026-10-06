<?php
/**
 * DocumentTemplateForm — cadastro e edição de template de documento do
 * tenant (Fase 7B, T-17).
 *
 * Campos: nome, texto (textarea de 12 linhas, enviado por POST) e situação
 * (Ativo/Inativo). O tipo é fixo em `medical_certificate` (campo oculto).
 * Abaixo do texto, um bloco de ajuda lista os placeholders de
 * DocumentTemplateRenderer::PLACEHOLDERS entre `{{ }}`. Sem regra própria:
 * onSave() chama CentralVet\Application\DocumentTemplateService::save(); a
 * edição carrega o template pelo listAll() do service (escopado ao tenant).
 *
 * Rotas: index.php?class=DocumentTemplateForm (novo) e
 * index.php?class=DocumentTemplateForm&id=<template_id>.
 *
 * @version    1.0
 * @package    control
 * @subpackage clinic
 */
class DocumentTemplateForm extends TPage
{
    private const ACTION_SAVE = 'DocumentTemplateForm::onSave';
    private const ACTION_EDIT = 'DocumentTemplateForm::onEdit';

    protected $form;
    private bool $loaded = false;
    private ?int $templateId = null;

    public function __construct($param = null)
    {
        parent::__construct();

        $this->templateId = self::paramId($param);

        $this->form = new BootstrapFormBuilder('form_DocumentTemplate');
        $this->form->enableClientValidation();

        $id        = new THidden('id');
        $kind      = new THidden('kind');
        $name      = new TEntry('name');
        $body_text = new TText('body_text');
        $status    = new TCombo('status');

        $kind->setValue(\CentralVet\Domain\DocumentKind::MEDICAL_CERTIFICATE);

        $status->addItems([
            \CentralVet\Domain\DocumentTemplate::STATUS_ACTIVE   => _t('Active'),
            \CentralVet\Domain\DocumentTemplate::STATUS_INACTIVE => _t('Inactive'),
        ]);
        $status->setDefaultOption(false);
        $status->setValue(\CentralVet\Domain\DocumentTemplate::STATUS_ACTIVE);

        $name->setMaxLength(120);
        $body_text->setMaxLength(20000);
        $body_text->setSize('100%', 288);
        $body_text->setProperty('rows', '12');

        CvForm::decorate($this->form, 2);

        $this->form->addFields( [new TLabel(_t('Name'))], [$name], [new TLabel(_t('Status'))], [$status] );
        $this->form->addFields( [new TLabel(_t('Text'))], [$body_text] );

        $hidden_row = $this->form->addFields( [$id], [$kind] );
        $hidden_row->style = 'display: none';

        $name->addValidation( _t('Name'), new TRequiredValidator );
        $body_text->addValidation( _t('Text'), new TRequiredValidator );
        $status->addValidation( _t('Status'), new TRequiredValidator );

        $btn = $this->form->addAction(_t('Save'), new TAction([$this, 'onSave']), 'fa:check');
        $btn->class = 'btn btn-primary cv-touch-target';

        $container = new TVBox;
        $container->style = 'width: 100%';
        $container->add(CvPage::header($this->templateId !== null ? _t('Edit document template') : _t('New document template'), _t('Documents'), [
            ['icon' => 'fa:arrow-left', 'href' => 'index.php?class=DocumentTemplateList', 'class' => 'btn btn-default cv-touch-target'],
        ]));
        $container->add($this->form);
        $container->add(self::placeholderHelp());

        parent::add($container);
    }

    /**
     * Cria (sem id) ou edita (com id) pela DocumentTemplateService. Em erro,
     * os dados digitados ficam no formulário.
     */
    public function onSave($param = null)
    {
        $this->loaded = true;

        try
        {
            $data = $this->form->getData();
            $data->kind = \CentralVet\Domain\DocumentKind::MEDICAL_CERTIFICATE;

            $this->form->validate();

            $context = self::resolveTenantContext();

            TTransaction::open('permission');

            $payload = [
                'kind'      => \CentralVet\Domain\DocumentKind::MEDICAL_CERTIFICATE,
                'name'      => (string) $data->name,
                'body_text' => (string) $data->body_text,
                'status'    => (string) $data->status,
            ];

            if (!empty($data->id))
            {
                $payload['id'] = (int) $data->id;
            }

            $template = self::makeDocumentTemplateService($context)->save($payload, self::ACTION_SAVE);

            TTransaction::close();

            $data->id = $template->id();
            $data->status = $template->status();
            $this->form->setData($data);

            TToast::show('success', _t('Record saved'));
            TScript::create("__adianti_goto_page('index.php?class=DocumentTemplateList')");
        }
        catch (\CentralVet\Authorization\Exception\AuthorizationDenied $e)
        {
            $this->form->setData($data ?? null);
            TTransaction::rollback();
            new TMessage('error', _t('You are not allowed to manage document templates'));
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
     * Carrega o template `id` entre os do tenant (DocumentTemplateService::listAll()).
     * Sem id, formulário vazio; id de outro tenant ou inexistente, erro.
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

            $found = null;
            foreach (self::makeDocumentTemplateService($context)->listAll(self::ACTION_EDIT) as $template)
            {
                if ($template->id() === $template_id)
                {
                    $found = $template;
                    break;
                }
            }

            TTransaction::close();

            if ($found === null)
            {
                throw new \CentralVet\Domain\Exception\CrossTenantReferenceException("template_id {$template_id} was not found for the authenticated tenant");
            }

            $data = new stdClass;
            $data->id        = $found->id();
            $data->kind      = $found->kind();
            $data->name      = $found->name();
            $data->body_text = $found->bodyText();
            $data->status    = $found->status();

            $this->form->setData($data);
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

    /**
     * `index.php?class=DocumentTemplateForm&id=<id>` (sem method) carrega o template aqui.
     */
    public function show()
    {
        if (!$this->loaded && $this->templateId !== null)
        {
            $this->onEdit(['id' => $this->templateId]);
        }

        parent::show();
    }

    /** Rótulo traduzido do tipo de documento (texto puro: quem imprime escapa). */
    public static function kindLabel(string $kind): string
    {
        return match ($kind)
        {
            \CentralVet\Domain\DocumentKind::MEDICAL_CERTIFICATE => _t('Medical certificate'),
            default                                              => $kind,
        };
    }

    /**
     * Ajuda com os placeholders aceitos no texto.
     */
    private static function placeholderHelp(): TElement
    {
        $help = new TElement('div');
        $help->{'class'} = 'cv-card';
        $help->add(TElement::tag('p', CvFormat::e(_t('Available placeholders')), ['class' => 'cv-card__title']));

        $list = new TElement('ul');
        foreach (\CentralVet\Domain\DocumentTemplateRenderer::PLACEHOLDERS as $placeholder)
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
