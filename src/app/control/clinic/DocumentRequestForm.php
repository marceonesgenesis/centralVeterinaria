<?php
/**
 * DocumentRequestForm
 *
 * Pedido de documento PDF (Fase 7B, T-15): carteira de vacinação, receita,
 * atestado e termo de consentimento cirúrgico. Rota
 * `DocumentRequestForm&kind=<kind>&source_id=<id>`; kind fora de
 * DocumentKind::ALL ou source_id não inteiro mostram só
 * "Invalid document request".
 *
 * Atestado: combo dos templates ativos (0 = texto padrão) e TText com o texto
 * mesclado para o paciente (carga inicial e onChangeTemplate, enviado como
 * literal JS seguro). Termo: usa o texto registrado na cirurgia. Todos:
 * opção "avisar o tutor" com o resumo do consentimento por canal (sem
 * mostrar o contato).
 *
 * onSave: DocumentRequestService::request() numa TTransaction; depois do
 * commit, publica `document.generate` (falha do Redis só vai ao error_log
 * com o document_id; o varredor republica) e segue para
 * DocumentList&patient_id=<id>.
 *
 * Texto do atestado só pelo corpo do POST; nada de nome, contato ou texto
 * em log (só ids e kind).
 *
 * @version    1.0
 * @package    control
 * @subpackage clinic
 */
class DocumentRequestForm extends TPage
{
    protected $form;

    private const ACTION_SAVE = 'DocumentRequestForm::onSave';
    private const ACTION_LOAD = 'DocumentRequestForm::onLoad';
    private const ACTION_CHANGE_TEMPLATE = 'DocumentRequestForm::onChangeTemplate';

    private const FORM_NAME = 'form_DocumentRequest';

    private const TOUCH = 'min-height:var(--cv-touch-target)';

    public function __construct($param = null)
    {
        parent::__construct();

        $kind = self::kindFromRequest($param);
        $sourceId = self::paramInt('source_id', $param);

        $container = new TVBox;
        $container->style = 'width: 100%';

        if ($kind === null || $sourceId === null || $sourceId <= 0)
        {
            $container->add(CvPage::header(_t('New document'), null, [[
                'label' => _t('Back'),
                'icon' => 'fa:arrow-left',
                'href' => 'index.php?class=DocumentList',
            ]]));
            $container->add(self::emptyState(_t('Invalid document request')));
            parent::add($container);
            return;
        }

        // CvPage::header escapa título e subtítulo
        $container->add(CvPage::header(_t('New document'), self::kindLabel($kind), [[
            'label' => _t('Back'),
            'icon' => 'fa:arrow-left',
            'href' => self::returnUrl($kind, $sourceId),
        ]]));

        $this->form = new BootstrapFormBuilder(self::FORM_NAME);
        $this->form->setFormTitle(CvFormat::e(self::kindLabel($kind)));

        $kindField = new THidden('kind');
        $kindField->setValue($kind);
        $sourceField = new THidden('source_id');
        $sourceField->setValue((string) $sourceId);
        $hiddenRow = $this->form->addFields([$kindField, $sourceField]);
        $hiddenRow->style = 'display: none';

        $kindView = new TEntry('kind_label');
        $kindView->setValue(self::kindLabel($kind));
        $kindView->setEditable(false);
        $this->form->addFields([new TLabel(_t('Document type'))], [$kindView]);

        if ($kind === \CentralVet\Domain\DocumentKind::MEDICAL_CERTIFICATE)
        {
            $template_id = new TCombo('template_id');
            // TCombo escapa os rótulos
            $template_id->addItems([0 => _t('Default text')] + static::loadTemplateOptions($kind));
            $template_id->setDefaultOption(false);
            $template_id->setValue('0');
            $template_id->setChangeAction(new TAction([__CLASS__, 'onChangeTemplate']));

            $body_text = new TText('body_text');
            $body_text->setSize('100%', 260);
            $body_text->setProperty('maxlength', (string) \CentralVet\Domain\GeneratedDocument::BODY_TEXT_MAX_LENGTH);
            $body_text->setProperty('required', 'required');
            $body_text->setProperty('aria-required', 'true');
            $body_text->setValue(static::loadInitialBody($sourceId));

            $this->form->addFields([new TLabel(_t('Template'))], [$template_id]);
            $this->form->addFields([new TLabel(_t('Text') . ' *', '#dc3545')]);
            $this->form->addFields([$body_text]);
        }

        if ($kind === \CentralVet\Domain\DocumentKind::SURGERY_CONSENT)
        {
            $notice = new TElement('div');
            $notice->{'class'} = 'alert alert-info';
            $notice->add(CvFormat::e(_t('The consent text recorded on the surgery will be used')));
            $this->form->addContent([$notice]);
        }

        $notify = new TCheckButton('notify_tutor');
        $notify->setIndexValue('1');
        $notify->setUseSwitch(true, 'blue');
        $this->form->addFields([new TLabel(_t('Notify the tutor when ready'))], [$notify]);
        $this->form->addContent([self::consentSummary(static::loadConsentSummary($kind, $sourceId))]);

        $btn = $this->form->addAction(_t('Generate PDF'), new TAction([$this, 'onSave']), 'fa:file-pdf');
        $btn->class = 'btn btn-primary btn-lg cv-touch-target';
        // um toque: evita pedido em dobro por clique repetido
        $btn->addFunction('this.disabled=true');

        CvForm::decorate($this->form, 2);

        $container->add($this->form);

        parent::add($container);
    }

    /** Ação de navegação: a tela é montada no construtor a partir do request. */
    public function onReload($param = null)
    {
    }

    /** Template escolhido (0 = texto padrão): texto mesclado para o paciente. */
    public static function onChangeTemplate($param)
    {
        $kind = is_array($param) ? (string) ($param['kind'] ?? '') : '';
        $sourceId = self::intOf($param, 'source_id');
        $templateId = self::intOf($param, 'template_id');

        if ($kind !== \CentralVet\Domain\DocumentKind::MEDICAL_CERTIFICATE || $sourceId <= 0)
        {
            return;
        }

        try
        {
            self::sendFieldData(['body_text' => static::mergeTemplate($templateId, $sourceId)]);
        }
        catch (Exception $e)
        {
            TTransaction::rollback();
            error_log(__METHOD__ . ': ' . get_class($e) . ' template_id=' . $templateId . ' source_id=' . $sourceId);
            new TMessage('error', CvFormat::userError($e));
        }
    }

    public function onSave($param)
    {
        $kind = '';
        $sourceId = 0;

        try
        {
            $kind = self::kindFromRequest($param) ?? '';
            $sourceId = self::intOf($param, 'source_id');

            if ($kind === '' || $sourceId <= 0)
            {
                throw new InvalidArgumentException(_t('Invalid document request'));
            }

            $isCertificate = $kind === \CentralVet\Domain\DocumentKind::MEDICAL_CERTIFICATE;
            $templateId = $isCertificate ? self::intOf($param, 'template_id') : 0;
            // texto livre só pelo corpo do POST (nunca pela query string)
            $bodyText = $isCertificate ? (string) ($_POST['body_text'] ?? '') : null;
            $notifyTutor = is_array($param) && (string) ($param['notify_tutor'] ?? '') === '1';

            $context = self::resolveTenantContext();

            TTransaction::open('permission');

            $document = self::makeRequestService($context)->request(
                $kind,
                $sourceId,
                $templateId > 0 ? $templateId : null,
                $bodyText,
                $notifyTutor,
                self::ACTION_SAVE,
            );

            TTransaction::close();

            $documentId = (int) $document->id();

            // publicação só depois do commit; falha fica para o varredor
            try
            {
                (new \CentralVet\Application\DocumentJobPublisher(\CentralVet\Queue\RedisQueue::fromEnvironment()))
                    ->publish($context->tenantId(), $documentId);
            }
            catch (Throwable $publishError)
            {
                error_log(__METHOD__ . ': publish failed for document_id=' . $documentId . ' (' . get_class($publishError) . ')');
            }

            TToast::show('success', _t('Document requested. It will be available in the list in a few moments.'));
            TScript::create("__adianti_goto_page('index.php?class=DocumentList&patient_id=" . (int) $document->patientId() . "')");
        }
        catch (\CentralVet\Authorization\Exception\AuthorizationDenied $e)
        {
            TTransaction::rollback();
            $this->keepTypedData($param);
            new TMessage('error', _t('You are not allowed to request documents'));
        }
        catch (\CentralVet\Tenancy\Exception\MissingTenantContext $e)
        {
            TTransaction::rollback();
            $this->keepTypedData($param);
            new TMessage('error', _t('An authenticated session with an active unit is required'));
        }
        catch (\CentralVet\Domain\Exception\DocumentSourceNotFoundException $e)
        {
            TTransaction::rollback();
            $this->keepTypedData($param);
            error_log(__METHOD__ . ': source not found kind=' . $kind . ' source_id=' . $sourceId);
            new TMessage('error', CvFormat::userError($e));
        }
        catch (Exception $e)
        {
            TTransaction::rollback();
            $this->keepTypedData($param);
            // mensagens de domínio carregam só ids e códigos
            error_log(__METHOD__ . ': ' . get_class($e) . ' kind=' . $kind . ' source_id=' . $sourceId);
            new TMessage('error', CvFormat::userError($e));
        }
    }

    /**
     * A página é reconstruída no POST: sem isto o formulário voltaria com o
     * texto inicial. Texto livre só do POST; o resto (ids) do request.
     */
    private function keepTypedData($param): void
    {
        if ($this->form !== null)
        {
            $data = [];

            if (array_key_exists('body_text', $_POST))
            {
                $data['body_text'] = (string) $_POST['body_text'];
            }

            foreach (['template_id', 'notify_tutor'] as $name)
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

    /** @return array<int, string> id → nome dos templates ativos do tipo */
    protected static function loadTemplateOptions(string $kind): array
    {
        try
        {
            $context = self::resolveTenantContext();

            TTransaction::open('permission');
            $templates = self::makeTemplateService($context)->listActive($kind, self::ACTION_LOAD);
            TTransaction::close();

            $options = [];

            foreach ($templates as $template)
            {
                $options[(int) $template->id()] = $template->name();
            }

            return $options;
        }
        catch (Throwable $e)
        {
            TTransaction::rollback();
            error_log(__METHOD__ . ': ' . get_class($e) . ' kind=' . $kind);

            return [];
        }
    }

    /** Texto padrão do atestado mesclado para o paciente ('' sem sessão ou paciente). */
    protected static function loadInitialBody(int $patientId): string
    {
        try
        {
            return static::mergeTemplate(0, $patientId);
        }
        catch (Throwable $e)
        {
            TTransaction::rollback();
            error_log(__METHOD__ . ': ' . get_class($e) . ' patient_id=' . $patientId);

            return '';
        }
    }

    /** Merge do template (0 = padrão) para o paciente; lança em falha. */
    protected static function mergeTemplate(int $templateId, int $patientId): string
    {
        $context = self::resolveTenantContext();

        TTransaction::open('permission');
        $text = self::makeTemplateService($context)->mergeForPatient($templateId, $patientId, self::ACTION_CHANGE_TEMPLATE);
        TTransaction::close();

        return $text;
    }

    /**
     * Consentimento por canal do tutor do paciente da fonte (true =
     * opt-in explícito). Vazio quando a fonte não é encontrada.
     *
     * @return array<string, bool>
     */
    protected static function loadConsentSummary(string $kind, int $sourceId): array
    {
        try
        {
            $context = self::resolveTenantContext();

            TTransaction::open('permission');

            $connection = TTransaction::get();
            $sources = new \CentralVet\Persistence\DocumentSourceQuery($context, $connection);

            $patientId = match (\CentralVet\Domain\DocumentKind::sourceTypeFor($kind)) {
                \CentralVet\Domain\DocumentKind::SOURCE_PRESCRIPTION => (int) ($sources->prescription($sourceId)['patient_id'] ?? 0),
                \CentralVet\Domain\DocumentKind::SOURCE_SURGERY => (int) ($sources->surgery($sourceId)['patient_id'] ?? 0),
                default => $sourceId,
            };

            $patient = $patientId > 0 ? $sources->patientSummary($patientId) : null;
            $preferences = $patient !== null
                ? (new \CentralVet\Persistence\CommunicationPreferenceRepository($context, $connection))->findForTutor((int) $patient['tutor_id'])
                : null;

            TTransaction::close();

            if ($preferences === null)
            {
                return [];
            }

            $summary = [];

            foreach (\CentralVet\Domain\CommunicationChannel::all() as $channel)
            {
                $summary[$channel] = isset($preferences[$channel]) && $preferences[$channel]->isOptedIn();
            }

            return $summary;
        }
        catch (Throwable $e)
        {
            TTransaction::rollback();
            error_log(__METHOD__ . ': ' . get_class($e) . ' kind=' . $kind . ' source_id=' . $sourceId);

            return [];
        }
    }

    /** @param array<string, bool> $summary */
    private static function consentSummary(array $summary): TElement
    {
        $box = new TElement('div');
        $box->{'class'} = 'cv-document-consent';
        $box->{'style'} = self::TOUCH . '; display:flex; flex-wrap:wrap; gap:var(--cv-space-3); align-items:center';

        if ($summary === [])
        {
            $box->add(TElement::tag('span', CvFormat::e(_t('Communication consent unavailable')), ['class' => 'text-muted']));

            return $box;
        }

        $labels = ['email' => _t('E-mail'), 'whatsapp' => _t('WhatsApp')];

        foreach ($summary as $channel => $authorized)
        {
            $text = ($labels[$channel] ?? $channel) . ': ' . ($authorized ? _t('authorized') : _t('not authorized'));
            $box->add(TElement::tag('span', CvFormat::e($text), ['class' => $authorized ? 'badge bg-success' : 'badge bg-secondary']));
        }

        return $box;
    }

    /**
     * Preenche campos como TForm::sendData, com o valor como literal JS em
     * JSON com JSON_HEX_* (o texto é livre; addslashes não basta contra
     * `</script>`; lição da T-24 da 7A).
     *
     * @param array<string, string> $data
     */
    private static function sendFieldData(array $data): void
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

    private static function kindLabel(string $kind): string
    {
        return match ($kind) {
            \CentralVet\Domain\DocumentKind::VACCINATION_CARD => _t('Vaccination card'),
            \CentralVet\Domain\DocumentKind::PRESCRIPTION => _t('Prescription'),
            \CentralVet\Domain\DocumentKind::MEDICAL_CERTIFICATE => _t('Medical certificate'),
            \CentralVet\Domain\DocumentKind::SURGERY_CONSENT => _t('Surgery consent'),
            default => $kind,
        };
    }

    private static function returnUrl(string $kind, int $sourceId): string
    {
        return match ($kind) {
            \CentralVet\Domain\DocumentKind::SURGERY_CONSENT => 'index.php?class=SurgeryView&id=' . $sourceId,
            \CentralVet\Domain\DocumentKind::PRESCRIPTION => 'index.php?class=DocumentList',
            default => 'index.php?class=PatientForm&method=onEdit&key=' . $sourceId . '&id=' . $sourceId,
        };
    }

    private static function emptyState(string $message): TElement
    {
        $state = new TElement('div');
        $state->{'class'} = 'cv-state cv-state--empty';
        $state->add(TElement::tag('p', CvFormat::e($message), ['class' => 'cv-state__title']));

        return $state;
    }

    private static function kindFromRequest($param): ?string
    {
        $raw = $_GET['kind'] ?? $_POST['kind'] ?? (is_array($param) ? ($param['kind'] ?? null) : null);

        return is_string($raw) && in_array($raw, \CentralVet\Domain\DocumentKind::ALL, true) ? $raw : null;
    }

    private static function paramInt(string $name, $param): ?int
    {
        $raw = $_GET[$name] ?? $_POST[$name] ?? (is_array($param) ? ($param[$name] ?? null) : null);
        $raw = $raw === null || !is_scalar($raw) ? '' : trim((string) $raw);

        return ($raw !== '' && ctype_digit($raw)) ? (int) $raw : null;
    }

    private static function intOf($param, string $name): int
    {
        $raw = is_array($param) && isset($param[$name]) && is_scalar($param[$name]) ? trim((string) $param[$name]) : '';

        return ($raw !== '' && ctype_digit($raw)) ? (int) $raw : 0;
    }

    /** Requer TTransaction('permission') aberta. */
    private static function makeRequestService(\CentralVet\Tenancy\TenantContext $context): \CentralVet\Application\DocumentRequestService
    {
        $connection = TTransaction::get();

        return new \CentralVet\Application\DocumentRequestService(
            new \CentralVet\Persistence\GeneratedDocumentRepository($context, $connection),
            new \CentralVet\Persistence\DocumentSourceQuery($context, $connection),
            new \CentralVet\Persistence\DocumentTemplateRepository($context, $connection),
            \CentralVet\Storage\DocumentStorageFactory::fromEnvironment($context),
            self::makeAuthorization($connection),
            $context,
        );
    }

    /** Requer TTransaction('permission') aberta. */
    private static function makeTemplateService(\CentralVet\Tenancy\TenantContext $context): \CentralVet\Application\DocumentTemplateService
    {
        $connection = TTransaction::get();

        return new \CentralVet\Application\DocumentTemplateService(
            new \CentralVet\Persistence\DocumentTemplateRepository($context, $connection),
            new \CentralVet\Persistence\DocumentSourceQuery($context, $connection),
            new \CentralVet\Persistence\SenderNamesQuery($context, $connection),
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
     * CommunicationComposeForm::resolveTenantContext()).
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
