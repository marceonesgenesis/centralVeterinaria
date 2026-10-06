<?php
/**
 * SurgeryEventForm
 *
 * Evento clínico da cirurgia (Fase 6B, T-15): `type` é um de
 * SurgeryEvent::CLINICAL_TYPES (pre_op, anesthesia, intra_op, complication,
 * post_op) e `notes_text` o registro → SurgeryService::recordClinicalEvent().
 * Tipo fora da lista (ex.: `type=foo`) abre um estado vazio com mensagem,
 * sem formulário e sem tocar no banco; o service revalida o tipo.
 *
 * Entrada: `surgery_id` e `type` ($_GET, senão $param). Sem cirurgia,
 * estado vazio sem tocar no banco. O texto clínico só vem do corpo do POST
 * (nunca da URL). Depois de salvar volta para SurgeryView&id=<surgery_id>.
 *
 * @version    1.0
 * @package    control
 * @subpackage clinic
 */
class SurgeryEventForm extends TPage
{
    protected $form;

    private const ACTION_SAVE = 'SurgeryEventForm::onSave';
    private const ACTION_LOAD = 'SurgeryEventForm::onLoad';

    private const FORM_NAME = 'form_SurgeryEvent';

    public function __construct($param = null)
    {
        parent::__construct();

        $surgeryId = self::paramInt('surgery_id', $param);
        $type = self::paramType($param);
        $title = $type !== null ? self::typeLabels()[$type] : _t('Surgery event');

        $container = new TVBox;
        $container->style = 'width: 100%';
        $container->add(CvPage::header($title, null, [[
            'icon' => 'fa:arrow-left',
            'href' => self::returnUrl($surgeryId),
        ]]));

        if ($surgeryId === null || $surgeryId <= 0)
        {
            $container->add(self::emptyState(_t('Provide a surgery_id to record the event.')));
            parent::add($container);
            return;
        }

        if ($type === null)
        {
            $container->add(self::emptyState(_t('Unknown surgery event type')));
            parent::add($container);
            return;
        }

        $procedure = self::loadProcedureName($surgeryId);

        $this->form = new BootstrapFormBuilder(self::FORM_NAME);
        $this->form->setFormTitle($title . ' — #' . $surgeryId . ($procedure !== null ? ' · ' . $procedure : ''));

        $surgery_id = new THidden('surgery_id');
        $surgery_id->setValue($surgeryId);
        $type_field = new THidden('type');
        $type_field->setValue($type);

        $hiddenRow = $this->form->addFields([$surgery_id, $type_field]);
        $hiddenRow->style = 'display: none';

        $notes_text = new TText('notes_text');
        $notes_text->setSize('100%', 180);
        $notes_text->setProperty('maxlength', '5000');
        $notes_text->setProperty('required', 'required');
        $notes_text->setProperty('aria-required', 'true');

        $this->form->addFields([new TLabel(_t('Notes') . ' *', '#dc3545')]);
        $this->form->addFields([$notes_text]);

        $btn = $this->form->addAction(_t('Save'), new TAction([$this, 'onSave']), 'fa:check');
        $btn->class = 'btn btn-primary btn-lg cv-touch-target';
        // um toque: evita registro em dobro por clique repetido
        $btn->addFunction("this.disabled=true");

        CvForm::decorate($this->form, 2);

        $container->add($this->form);

        parent::add($container);
    }

    public function onSave($param)
    {
        try
        {
            $rawId = is_array($param) && isset($param['surgery_id']) ? trim((string) $param['surgery_id']) : '';

            if ($rawId === '' || !ctype_digit($rawId) || (int) $rawId <= 0)
            {
                throw new InvalidArgumentException('surgery_id must be a positive integer');
            }

            $surgeryId = (int) $rawId;
            $type = self::paramType($param);

            if ($type === null)
            {
                throw new InvalidArgumentException('Unknown surgery event type');
            }

            // texto clínico só pelo corpo do POST (nunca pela query string)
            $notes = trim((string) ($_POST['notes_text'] ?? ''));

            if ($notes === '')
            {
                throw new InvalidArgumentException('notes_text is required');
            }

            $context = self::resolveTenantContext();

            TTransaction::open('permission');

            self::makeSurgeryService($context)->recordClinicalEvent($surgeryId, $type, $notes, self::ACTION_SAVE);

            TTransaction::close();

            TToast::show('success', _t('Event recorded successfully'));
            TScript::create("__adianti_goto_page('" . self::returnUrl($surgeryId) . "')");
        }
        catch (\CentralVet\Authorization\Exception\AuthorizationDenied $e)
        {
            TTransaction::rollback();
            $this->keepTypedData();
            new TMessage('error', _t('You are not allowed to record events for this surgery'));
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
     * Nome do procedimento para o título, ou null. Sem sessão, sem conexão
     * ou sem permissão o formulário abre sem ele: o erro real aparece ao
     * salvar.
     */
    private static function loadProcedureName(int $surgeryId): ?string
    {
        try
        {
            $context = self::resolveTenantContext();

            TTransaction::open('permission');
            $name = self::makeSurgeryService($context)->get($surgeryId, self::ACTION_LOAD)->procedureName();
            TTransaction::close();

            return $name;
        }
        catch (Throwable $e)
        {
            TTransaction::rollback();
            error_log(__METHOD__ . ': ' . $e->getMessage());
            return null;
        }
    }

    /** A página é reconstruída no POST: mantém o texto digitado. */
    private function keepTypedData(): void
    {
        if ($this->form !== null && array_key_exists('notes_text', $_POST))
        {
            $this->form->setData((object) ['notes_text' => (string) $_POST['notes_text']]);
        }

        TScript::create("document.querySelectorAll('#" . self::FORM_NAME . " button').forEach(function(b){b.disabled=false;})");
    }

    /**
     * Mensagem de erro com o rótulo no lugar do nome técnico
     * (`notes_text is required` → "Campo obrigatório: Observações").
     */
    private static function fieldError(Throwable $e): string
    {
        $generic = CvFormat::userError($e);

        if (!$e instanceof InvalidArgumentException || $e->getPrevious() instanceof PDOException)
        {
            return $generic;
        }

        $resolved = \CentralVet\Presentation\UserMessage::resolve((string) $e->getMessage());

        if ($resolved === null || ($resolved['params'][0] ?? null) !== 'notes_text')
        {
            return $generic;
        }

        $params = $resolved['params'];
        $params[0] = _t('Notes');

        return _t($resolved['key'], ...array_map([CvFormat::class, 'e'], $params));
    }

    /**
     * Rótulos dos tipos clínicos, na ordem e com exatamente as chaves de
     * SurgeryEvent::CLINICAL_TYPES.
     *
     * @return array<string, string>
     */
    public static function typeLabels(): array
    {
        $labels = [
            \CentralVet\Domain\SurgeryEvent::TYPE_PRE_OP => _t('Pre-operative note'),
            \CentralVet\Domain\SurgeryEvent::TYPE_ANESTHESIA => _t('Anesthesia note'),
            \CentralVet\Domain\SurgeryEvent::TYPE_INTRA_OP => _t('Intra-operative note'),
            \CentralVet\Domain\SurgeryEvent::TYPE_COMPLICATION => _t('Complication'),
            \CentralVet\Domain\SurgeryEvent::TYPE_POST_OP => _t('Post-operative note'),
        ];

        $items = [];

        foreach (\CentralVet\Domain\SurgeryEvent::CLINICAL_TYPES as $type)
        {
            $items[$type] = $labels[$type] ?? $type;
        }

        return $items;
    }

    /** Tipo clínico válido ou null (inválido/ausente). */
    private static function paramType($param): ?string
    {
        $raw = $_GET['type'] ?? (is_array($param) ? ($param['type'] ?? '') : '');
        $raw = is_string($raw) ? trim($raw) : '';

        return in_array($raw, \CentralVet\Domain\SurgeryEvent::CLINICAL_TYPES, true) ? $raw : null;
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
