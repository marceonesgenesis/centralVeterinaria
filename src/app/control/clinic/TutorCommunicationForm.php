<?php
/**
 * TutorCommunicationForm
 *
 * Preferências de comunicação do tutor por canal (Fase 7A, T-18): para
 * e-mail e WhatsApp, o status atual (aceita, não aceita ou não registrado),
 * o novo status (Aceita/Não aceita) e a origem do consentimento. Salvar
 * chama CommunicationPreferenceService::record() para cada canal alterado,
 * numa única TTransaction.
 *
 * A tela diz só se o tutor tem e-mail e se o telefone serve para WhatsApp:
 * o endereço e o telefone nunca são impressos (LGPD).
 *
 * Entrada: `tutor_id` ($_GET, senão $param). Sem tutor, estado vazio sem
 * tocar no banco. Depois de salvar recarrega a própria tela.
 *
 * @version    1.0
 * @package    control
 * @subpackage clinic
 */
class TutorCommunicationForm extends TPage
{
    protected $form;

    private const ACTION_SAVE = 'TutorCommunicationForm::onSave';
    private const ACTION_LOAD = 'TutorCommunicationForm::onLoad';

    private const FORM_NAME = 'form_TutorCommunication';

    private const STATUS_OPTED_IN = 'opted_in';
    private const STATUS_OPTED_OUT = 'opted_out';
    private const NOT_RECORDED = 'not_recorded';

    private const CHANNELS = ['email', 'whatsapp'];

    public function __construct($param = null)
    {
        parent::__construct();

        $tutorId = self::paramInt('tutor_id', $param);

        $tutor = null;
        $preferences = null;

        if ($tutorId !== null && $tutorId > 0)
        {
            $tutor = static::loadTutor($tutorId);
            $preferences = static::loadPreferences($tutorId);
        }

        $subtitle = $tutor !== null ? (string) $tutor['name'] : null;

        $container = new TVBox;
        $container->style = 'width: 100%';
        // CvPage::header escapa título e subtítulo
        $container->add(CvPage::header(_t('Communication preferences'), $subtitle, [[
            'icon' => 'fa:arrow-left',
            'href' => self::returnUrl($tutorId),
        ]]));

        if ($tutorId === null || $tutorId <= 0)
        {
            $container->add(self::emptyState(_t('Provide a tutor_id to manage the communication preferences.')));
            parent::add($container);
            return;
        }

        $this->form = new BootstrapFormBuilder(self::FORM_NAME);
        // BootstrapFormBuilder não escapa o título
        $this->form->setFormTitle(CvFormat::e($tutor !== null ? (string) $tutor['name'] : _t('Tutor') . ' #' . $tutorId));

        $tutor_id = new THidden('tutor_id');
        $tutor_id->setValue($tutorId);
        $hiddenRow = $this->form->addFields([$tutor_id]);
        $hiddenRow->style = 'display: none';

        $legalNote = TElement::tag('p', CvFormat::e(_t('Appointment confirmations and return reminders are sent unless the tutor refuses; the other messages only with the tutor acceptance.')), [
            'class' => 'alert alert-info',
            'role'  => 'note',
        ]);
        $this->form->addContent([$legalNote]);

        if ($tutor !== null)
        {
            $hasEmail = trim((string) ($tutor['email'] ?? '')) !== '';
            $whatsAppReady = \CentralVet\Communication\WhatsAppLinkBuilder::normalizePhone((string) ($tutor['phone'] ?? '')) !== null;

            $this->form->addFields(
                [new TLabel(_t('E-mail on file'))],
                [TElement::tag('span', CvFormat::e($hasEmail ? _t('Yes') : _t('No')))]
            );
            $this->form->addFields(
                [new TLabel(_t('Phone usable for WhatsApp'))],
                [TElement::tag('span', CvFormat::e($whatsAppReady ? _t('Yes') : _t('No')))]
            );
        }

        $sources = self::sourceOptions();

        foreach (self::CHANNELS as $channel)
        {
            $current = $preferences[$channel] ?? null;

            $this->form->addContent([TElement::tag('h5', CvFormat::e(self::channelLabel($channel)), ['class' => 'mt-3'])]);

            $this->form->addFields(
                [new TLabel(_t('Current status'))],
                [TElement::tag('span', CvFormat::e($current === null ? '—' : self::statusLabel($current)))]
            );

            $status = new TRadioGroup($channel . '_status');
            $status->addItems([
                self::STATUS_OPTED_IN => _t('Accepts'),
                self::STATUS_OPTED_OUT => _t('Does not accept'),
            ]);
            $status->setLayout('horizontal');

            if ($current === self::STATUS_OPTED_IN || $current === self::STATUS_OPTED_OUT)
            {
                $status->setValue($current);
            }

            $source = new TCombo($channel . '_source');
            $source->addItems($sources);

            $this->form->addFields([new TLabel(_t('Preference'))], [$status]);
            $this->form->addFields([new TLabel(_t('Consent source'))], [$source]);
        }

        $btn = $this->form->addAction(_t('Save'), new TAction([$this, 'onSave']), 'fa:check');
        $btn->class = 'btn btn-primary btn-lg cv-touch-target';
        $btn->addFunction("this.disabled=true");

        $compose = $this->form->addActionLink(_t('Compose message'), new TAction(['CommunicationComposeForm', 'onReload'], ['tutor_id' => $tutorId]), 'fa:envelope');
        $compose->class = 'btn btn-outline-secondary btn-lg cv-touch-target';

        CvForm::decorate($this->form, 2);

        $container->add($this->form);

        parent::add($container);
    }

    /** Ação de navegação: a tela é montada no construtor. */
    public function onReload($param = null)
    {
    }

    /**
     * Grava cada canal cujo status escolhido difere do atual. Origem
     * obrigatória para o canal alterado. Tudo numa TTransaction.
     */
    public function onSave($param)
    {
        $tutorId = null;

        try
        {
            $rawId = is_array($param) && isset($param['tutor_id']) ? trim((string) $param['tutor_id']) : '';

            if ($rawId === '' || !ctype_digit($rawId) || (int) $rawId <= 0)
            {
                throw new InvalidArgumentException('tutor_id must be a positive integer');
            }

            $tutorId = (int) $rawId;

            $context = self::resolveTenantContext();

            TTransaction::open('permission');

            $service = self::makePreferenceService($context);
            $current = $service->preferencesFor($tutorId, self::ACTION_SAVE);
            $changed = 0;

            foreach (self::CHANNELS as $channel)
            {
                $status = trim((string) ($_POST[$channel . '_status'] ?? ''));
                $source = trim((string) ($_POST[$channel . '_source'] ?? ''));

                if ($status === '' || $status === ($current[$channel] ?? self::NOT_RECORDED))
                {
                    continue;
                }

                if ($source === '')
                {
                    throw new InvalidArgumentException(_t('Select the consent source for ^1', self::channelLabel($channel)));
                }

                $service->record($tutorId, $channel, $status, $source, self::ACTION_SAVE);
                $changed++;
            }

            TTransaction::close();

            TToast::show('success', $changed > 0 ? _t('Communication preferences saved') : _t('No preference was changed'));
            TScript::create("__adianti_goto_page('" . self::selfUrl($tutorId) . "')");
        }
        catch (\CentralVet\Authorization\Exception\AuthorizationDenied $e)
        {
            TTransaction::rollback();
            $this->keepTypedData();
            new TMessage('error', _t('You are not allowed to change the communication preferences of this tutor'));
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
            // mensagens de domínio carregam só ids (sem contato)
            error_log(__METHOD__ . ': ' . get_class($e) . ' ' . $e->getMessage());
            new TMessage('error', CvFormat::userError($e));
        }
    }

    private function keepTypedData(): void
    {
        if ($this->form !== null)
        {
            $data = [];

            foreach (self::CHANNELS as $channel)
            {
                foreach (['_status', '_source'] as $suffix)
                {
                    if (array_key_exists($channel . $suffix, $_POST))
                    {
                        $data[$channel . $suffix] = (string) $_POST[$channel . $suffix];
                    }
                }
            }

            $this->form->setData((object) $data);
        }

        TScript::create("document.querySelectorAll('#" . self::FORM_NAME . " button').forEach(function(b){b.disabled=false;})");
    }

    /**
     * Nome, e-mail e telefone do tutor (o e-mail e o telefone só servem
     * para os indicadores sim/não) ou null sem sessão, sem conexão ou fora
     * do tenant: o erro real aparece ao salvar.
     *
     * @return array{name: string, email: ?string, phone: string}|null
     */
    protected static function loadTutor(int $tutorId): ?array
    {
        try
        {
            $context = self::resolveTenantContext();

            TTransaction::open('permission');
            $tutor = (new \CentralVet\Persistence\TutorRepository($context, TTransaction::get()))->findById($tutorId);
            TTransaction::close();

            if (!$tutor instanceof \CentralVet\Domain\Tutor)
            {
                return null;
            }

            return ['name' => $tutor->fullName, 'email' => $tutor->email, 'phone' => $tutor->phone];
        }
        catch (Throwable $e)
        {
            TTransaction::rollback();
            error_log(__METHOD__ . ': ' . get_class($e) . ' tutor_id=' . $tutorId);

            return null;
        }
    }

    /**
     * Status por canal (`opted_in`, `opted_out`, `not_recorded`) ou null
     * quando não dá para ler (sem sessão, sem permissão, sem conexão).
     *
     * @return array<string, string>|null
     */
    protected static function loadPreferences(int $tutorId): ?array
    {
        try
        {
            $context = self::resolveTenantContext();

            TTransaction::open('permission');
            $map = self::makePreferenceService($context)->preferencesFor($tutorId, self::ACTION_LOAD);
            TTransaction::close();

            return $map;
        }
        catch (Throwable $e)
        {
            TTransaction::rollback();
            error_log(__METHOD__ . ': ' . get_class($e) . ' tutor_id=' . $tutorId);

            return null;
        }
    }

    /** @return array<string, string> origem → rótulo traduzido */
    private static function sourceOptions(): array
    {
        $labels = [
            'in_person' => _t('In person'),
            'phone' => _t('By phone'),
            'written' => _t('In writing'),
            'online' => _t('Online'),
        ];

        $options = [];

        foreach (\CentralVet\Domain\CommunicationPreference::SOURCES as $source)
        {
            $options[$source] = $labels[$source] ?? $source;
        }

        return $options;
    }

    private static function channelLabel(string $channel): string
    {
        return $channel === 'email' ? _t('E-mail') : _t('WhatsApp');
    }

    private static function statusLabel(string $status): string
    {
        return match ($status)
        {
            self::STATUS_OPTED_IN => _t('Accepts'),
            self::STATUS_OPTED_OUT => _t('Does not accept'),
            default => _t('Not recorded'),
        };
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

    private static function selfUrl(int $tutorId): string
    {
        return 'index.php?class=TutorCommunicationForm&tutor_id=' . $tutorId;
    }

    private static function paramInt(string $name, $param): ?int
    {
        $raw = $_GET[$name] ?? (is_array($param) ? ($param[$name] ?? null) : null);
        $raw = $raw === null ? '' : trim((string) $raw);

        return ($raw !== '' && ctype_digit($raw)) ? (int) $raw : null;
    }

    /** Requer TTransaction('permission') aberta. */
    private static function makePreferenceService(\CentralVet\Tenancy\TenantContext $context): \CentralVet\Application\CommunicationPreferenceService
    {
        $connection = TTransaction::get();

        return new \CentralVet\Application\CommunicationPreferenceService(
            new \CentralVet\Persistence\CommunicationPreferenceRepository($context, $connection),
            new \CentralVet\Persistence\TutorRepository($context, $connection),
            new \CentralVet\Authorization\RbacAuthorizationService(
                new \CentralVet\Authorization\AdiantiProgramPermissionProvider(new \CentralVet\Tenancy\AdiantiSessionContextSource()),
                new \CentralVet\Audit\PdoAuditLogWriter($connection),
            ),
            $context,
            null,
            // opt-out cancela as mensagens na fila do tutor naquele canal (T-25)
            new \CentralVet\Persistence\OutboundMessageRepository($context, $connection),
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
