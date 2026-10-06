<?php
/**
 * SurgeryConsentForm
 *
 * Consentimento da cirurgia (Fase 6B, T-15): signatário e texto integral
 * aceito → SurgeryService::recordConsent(). O texto vem pré-preenchido com
 * a tradução de DEFAULT_TEXT_KEY e pode ser ajustado antes do aceite.
 * Regravar só antes do início (regra do domínio). Quando já há aceite, a
 * tela mostra quem registrou e quando, e os campos trazem o que foi aceito.
 *
 * Entrada: `surgery_id` ($_GET, senão $param). Sem cirurgia, estado vazio
 * sem tocar no banco. Signatário e texto só pelo corpo do POST (o texto
 * clínico nunca vai pela URL). Depois de salvar volta para
 * SurgeryView&id=<surgery_id>.
 *
 * @version    1.0
 * @package    control
 * @subpackage clinic
 */
class SurgeryConsentForm extends TPage
{
    protected $form;

    /** Chave de tradução do texto padrão do termo (T-19 traduz por um parágrafo pt). */
    public const DEFAULT_TEXT_KEY = 'Surgery consent default text';

    private const ACTION_SAVE = 'SurgeryConsentForm::onSave';
    private const ACTION_LOAD = 'SurgeryConsentForm::onLoad';

    private const FORM_NAME = 'form_SurgeryConsent';

    /** Obrigatórios na ordem da tela: a mensagem cita o primeiro vazio. */
    private const REQUIRED_FIELDS = ['consent_signer_name', 'consent_text'];

    public function __construct($param = null)
    {
        parent::__construct();

        $surgeryId = self::paramInt('surgery_id', $param);
        $title = _t('Surgery consent');

        $container = new TVBox;
        $container->style = 'width: 100%';
        $container->add(CvPage::header($title, null, [[
            'icon' => 'fa:arrow-left',
            'href' => self::returnUrl($surgeryId),
        ]]));

        if ($surgeryId === null || $surgeryId <= 0)
        {
            $container->add(self::emptyState(_t('Provide a surgery_id to record the consent.')));
            parent::add($container);
            return;
        }

        $this->form = new BootstrapFormBuilder(self::FORM_NAME);
        $this->form->setFormTitle($title . ' — #' . $surgeryId);

        $surgery_id = new THidden('surgery_id');
        $surgery_id->setValue($surgeryId);

        $hiddenRow = $this->form->addFields([$surgery_id]);
        $hiddenRow->style = 'display: none';

        $consent_signer_name = new TEntry('consent_signer_name');
        $consent_signer_name->setProperty('maxlength', '190');
        $consent_signer_name->setProperty('autocomplete', 'off');

        $consent_text = new TText('consent_text');
        $consent_text->setSize('100%', 260);
        $consent_text->setValue(_t(self::DEFAULT_TEXT_KEY));

        foreach ([$consent_signer_name, $consent_text] as $requiredField)
        {
            $requiredField->setProperty('required', 'required');
            $requiredField->setProperty('aria-required', 'true');
        }

        $recorded = self::loadRecordedConsent($surgeryId);

        if ($recorded !== null)
        {
            $consent_signer_name->setValue($recorded['signer']);
            $consent_text->setValue($recorded['text']);

            $info = TElement::tag('p', CvFormat::e(_t('Consent recorded by ^1 on ^2', $recorded['by'], $recorded['at'])), [
                'class' => 'alert alert-info',
                'role'  => 'status',
            ]);
            $this->form->addContent([$info]);
        }

        $this->form->addFields([self::label('consent_signer_name')], [$consent_signer_name]);
        $this->form->addFields([self::label('consent_text')]);
        $this->form->addFields([$consent_text]);

        $btn = $this->form->addAction(_t('Record consent'), new TAction([$this, 'onSave']), 'fa:check');
        $btn->class = 'btn btn-primary btn-lg cv-touch-target';
        // um toque: evita aceite em dobro por clique repetido
        $btn->addFunction("this.disabled=true");

        CvForm::decorate($this->form, 2);

        $container->add($this->form);

        parent::add($container);
    }

    public function onSave($param)
    {
        $surgeryId = null;

        try
        {
            $rawId = is_array($param) && isset($param['surgery_id']) ? trim((string) $param['surgery_id']) : '';

            if ($rawId === '' || !ctype_digit($rawId) || (int) $rawId <= 0)
            {
                throw new InvalidArgumentException('surgery_id must be a positive integer');
            }

            $surgeryId = (int) $rawId;

            // texto clínico só pelo corpo do POST (nunca pela query string)
            $signer = trim((string) ($_POST['consent_signer_name'] ?? ''));
            $text = trim((string) ($_POST['consent_text'] ?? ''));

            foreach (self::REQUIRED_FIELDS as $name)
            {
                if (($name === 'consent_signer_name' ? $signer : $text) === '')
                {
                    throw new InvalidArgumentException("{$name} is required");
                }
            }

            $context = self::resolveTenantContext();

            TTransaction::open('permission');

            self::makeSurgeryService($context)->recordConsent($surgeryId, $signer, $text, self::ACTION_SAVE);

            TTransaction::close();

            TToast::show('success', _t('Consent recorded successfully'));
            TScript::create("__adianti_goto_page('" . self::returnUrl($surgeryId) . "')");
        }
        catch (\CentralVet\Authorization\Exception\AuthorizationDenied $e)
        {
            TTransaction::rollback();
            $this->keepTypedData();
            new TMessage('error', _t('You are not allowed to record the consent for this surgery'));
        }
        catch (\CentralVet\Tenancy\Exception\MissingTenantContext $e)
        {
            TTransaction::rollback();
            $this->keepTypedData();
            new TMessage('error', _t('An authenticated session with an active unit is required'));
        }
        catch (Exception $e)
        {
            TTransaction::rollback();
            $this->keepTypedData();
            error_log(__METHOD__ . ': ' . $e->getMessage());
            new TMessage('error', self::fieldError($e));
        }
    }

    /**
     * A página é reconstruída no POST: sem isto o formulário voltaria com o
     * texto padrão e o usuário perderia o que digitou.
     */
    private function keepTypedData(): void
    {
        if ($this->form !== null)
        {
            $data = [];

            foreach (self::REQUIRED_FIELDS as $name)
            {
                if (array_key_exists($name, $_POST))
                {
                    $data[$name] = (string) $_POST[$name];
                }
            }

            $this->form->setData((object) $data);
        }

        TScript::create("document.querySelectorAll('#" . self::FORM_NAME . " button').forEach(function(b){b.disabled=false;})");
    }

    /**
     * Aceite já registrado (signatário, texto, quem e quando) ou null. Sem
     * sessão, sem conexão ou sem permissão a tela abre só com o texto
     * padrão: o erro real aparece ao salvar.
     *
     * @return array{signer: string, text: string, by: string, at: string}|null
     */
    private static function loadRecordedConsent(int $surgeryId): ?array
    {
        try
        {
            $context = self::resolveTenantContext();

            TTransaction::open('permission');
            $surgery = self::makeSurgeryService($context)->get($surgeryId, self::ACTION_LOAD);

            if (!$surgery->hasConsent())
            {
                TTransaction::close();
                return null;
            }

            $by = '#' . (int) $surgery->consentRecordedBySystemUserId();
            $user = SystemUser::find((int) $surgery->consentRecordedBySystemUserId());

            if ($user)
            {
                $by = (string) $user->name;
            }

            TTransaction::close();

            $at = $surgery->consentRecordedAt();

            return [
                'signer' => (string) $surgery->consentSignerName(),
                'text'   => (string) $surgery->consentText(),
                'by'     => $by,
                'at'     => $at !== null ? $at->format('d/m/Y H:i') : '',
            ];
        }
        catch (Throwable $e)
        {
            TTransaction::rollback();
            error_log(__METHOD__ . ': ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Mensagem de erro com o rótulo do campo no lugar do nome técnico
     * (`consent_signer_name is required` → "Campo obrigatório: Signatário").
     * Falha de banco e mensagens sem campo seguem CvFormat::userError().
     */
    private static function fieldError(Throwable $e): string
    {
        $labels = self::fieldLabels();
        $generic = CvFormat::userError($e);

        if (!$e instanceof InvalidArgumentException || $e->getPrevious() instanceof PDOException)
        {
            return $generic;
        }

        $resolved = \CentralVet\Presentation\UserMessage::resolve((string) $e->getMessage());

        if ($resolved === null || $resolved['params'] === [] || !isset($labels[$resolved['params'][0]]))
        {
            return $generic;
        }

        $params = $resolved['params'];
        $params[0] = $labels[$params[0]];

        return _t($resolved['key'], ...array_map([CvFormat::class, 'e'], $params));
    }

    /** @return array<string, string> nome do campo → rótulo traduzido */
    private static function fieldLabels(): array
    {
        return [
            'consent_signer_name' => _t('Signer name'),
            'consent_text' => _t('Consent text'),
        ];
    }

    /** Rótulo do campo; obrigatório ganha " *" em vermelho (convenção Adianti). */
    private static function label(string $name): TLabel
    {
        $text = self::fieldLabels()[$name];

        return in_array($name, self::REQUIRED_FIELDS, true)
            ? new TLabel($text . ' *', '#dc3545')
            : new TLabel($text);
    }

    private static function emptyState(string $message): TElement
    {
        $state = new TElement('div');
        $state->{'class'} = 'cv-state cv-state--empty';
        $state->add(TElement::tag('p', CvFormat::e($message), ['class' => 'cv-state__title']));

        return $state;
    }

    private static function returnUrl(?int $surgeryId): string
    {
        return ($surgeryId !== null && $surgeryId > 0)
            ? 'index.php?class=SurgeryView&id=' . $surgeryId
            : 'index.php?class=SurgeryList';
    }

    private static function paramInt(string $name, $param): ?int
    {
        $raw = $_GET[$name] ?? (is_array($param) ? ($param[$name] ?? null) : null);
        $raw = $raw === null ? '' : trim((string) $raw);

        return ($raw !== '' && ctype_digit($raw)) ? (int) $raw : null;
    }

    /** Requer TTransaction('permission') aberta. */
    private static function makeSurgeryService(\CentralVet\Tenancy\TenantContext $context): \CentralVet\Application\SurgeryService
    {
        $connection = TTransaction::get();

        return new \CentralVet\Application\SurgeryService(
            new \CentralVet\Persistence\SurgeryRepository($context, $connection),
            new \CentralVet\Persistence\SurgeryRoomRepository($context, $connection),
            new \CentralVet\Persistence\SurgeryTeamRepository($context, $connection),
            new \CentralVet\Persistence\SurgeryChecklistRepository($context, $connection),
            new \CentralVet\Persistence\SurgeryEventRepository($context, $connection),
            new \CentralVet\Persistence\EncounterRepository($context, $connection),
            new \CentralVet\Persistence\EncounterAccountRepository($context, $connection),
            new \CentralVet\Persistence\ProcedureCatalogRepository($context, $connection),
            new \CentralVet\Persistence\TenantUserDirectory($context, $connection),
            new \CentralVet\Authorization\RbacAuthorizationService(
                new \CentralVet\Authorization\AdiantiProgramPermissionProvider(new \CentralVet\Tenancy\AdiantiSessionContextSource()),
                new \CentralVet\Audit\PdoAuditLogWriter($connection),
            ),
            $context,
        );
    }

    /**
     * Contexto do tenant da sessão autenticada (cópia de
     * HospitalizationEventForm::resolveTenantContext()).
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
