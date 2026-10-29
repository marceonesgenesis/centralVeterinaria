<?php
/**
 * HospitalizationEventForm
 *
 * Evolução e parâmetros da internação (Fase 6A, T-15):
 * - `type=vitals`: temperatura (°C), FC, FR, peso (kg), dor 0–10 e
 *   observação → HospitalizationService::recordVitals();
 * - `type=evolution` (padrão): texto → HospitalizationService::recordEvolution().
 * As faixas (dor 0–10, valores não negativos, ao menos um sinal) e a
 * internação admitida são regras do domínio/service; aqui só se converte o
 * texto digitado (vírgula decimal aceita).
 *
 * Entrada: `hospitalization_id` e `type` ($_GET, senão $param). Sem
 * internação, estado vazio sem tocar no banco. Depois de salvar volta para
 * HospitalizationView&id=<hospitalization_id>.
 *
 * @version    1.0
 * @package    control
 * @subpackage clinic
 */
class HospitalizationEventForm extends TPage
{
    protected $form;

    private const ACTION_SAVE = 'HospitalizationEventForm::onSave';

    private const TYPE_VITALS = 'vitals';
    private const TYPE_EVOLUTION = 'evolution';

    public function __construct($param = null)
    {
        parent::__construct();

        $hospitalizationId = self::paramInt('hospitalization_id', $param);
        $type = self::paramType($param);
        $title = $type === self::TYPE_VITALS ? _t('Vital signs') : _t('Evolution');

        $container = new TVBox;
        $container->style = 'width: 100%';
        $container->add(CvPage::header($title, null, [[
            'icon' => 'fa:arrow-left',
            'href' => self::returnUrl($hospitalizationId),
        ]]));

        if ($hospitalizationId === null)
        {
            $panel = new TPanelGroup($title);
            $panel->add('<p>' . _t('Provide a hospitalization_id to record it.') . '</p>');
            $container->add($panel);
            parent::add($container);
            return;
        }

        $this->form = new BootstrapFormBuilder('form_HospitalizationEvent');
        $this->form->setFormTitle($title . ' — #' . $hospitalizationId);

        $hospitalization_id = new THidden('hospitalization_id');
        $hospitalization_id->setValue($hospitalizationId);
        $type_field = new THidden('type');
        $type_field->setValue($type);

        $hiddenRow = $this->form->addFields([$hospitalization_id, $type_field]);
        $hiddenRow->style = 'display: none';

        $notes_text = new TText('notes_text');
        $notes_text->setSize('100%', $type === self::TYPE_VITALS ? 80 : 180);

        if ($type === self::TYPE_VITALS)
        {
            $temperature_c = self::numberEntry('temperature_c', 'decimal', '38,5');
            $heart_rate_bpm = self::numberEntry('heart_rate_bpm', 'numeric', '120');
            $respiratory_rate_rpm = self::numberEntry('respiratory_rate_rpm', 'numeric', '30');
            $weight_kg = self::numberEntry('weight_kg', 'decimal', '12,4');
            $pain_score = self::numberEntry('pain_score', 'numeric', '0–10');

            $this->form->addFields([new TLabel(_t('Temperature (°C)'))], [$temperature_c], [new TLabel(_t('Heart rate (bpm)'))], [$heart_rate_bpm]);
            $this->form->addFields([new TLabel(_t('Respiratory rate (rpm)'))], [$respiratory_rate_rpm], [new TLabel(_t('Weight (kg)'))], [$weight_kg]);
            $this->form->addFields([new TLabel(_t('Pain score (0–10)'))], [$pain_score]);
            $this->form->addFields([new TLabel(_t('Notes'))]);
            $this->form->addFields([$notes_text]);
        }
        else
        {
            $this->form->addFields([new TLabel(_t('Evolution'))]);
            $this->form->addFields([$notes_text]);
        }

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
            $rawId = is_array($param) && isset($param['hospitalization_id']) ? trim((string) $param['hospitalization_id']) : '';

            if ($rawId === '' || !ctype_digit($rawId) || (int) $rawId <= 0)
            {
                throw new InvalidArgumentException('hospitalization_id must be a positive integer');
            }

            $hospitalizationId = (int) $rawId;
            $type = self::paramType($param);
            $notes = trim((string) ($param['notes_text'] ?? ''));

            $temperature = $heartRate = $respiratoryRate = $weight = $pain = null;

            if ($type === self::TYPE_VITALS)
            {
                $temperature = self::optionalFloat($param, 'temperature_c');
                $heartRate = self::optionalInt($param, 'heart_rate_bpm');
                $respiratoryRate = self::optionalInt($param, 'respiratory_rate_rpm');
                $weight = self::optionalFloat($param, 'weight_kg');
                $pain = self::optionalInt($param, 'pain_score');
            }

            $context = self::resolveTenantContext();

            TTransaction::open('permission');

            $service = self::makeHospitalizationService($context);

            if ($type === self::TYPE_VITALS)
            {
                $service->recordVitals($hospitalizationId, $temperature, $heartRate, $respiratoryRate, $weight, $pain, $notes, self::ACTION_SAVE);
            }
            else
            {
                $service->recordEvolution($hospitalizationId, $notes, self::ACTION_SAVE);
            }

            TTransaction::close();

            TToast::show('success', $type === self::TYPE_VITALS ? _t('Vital signs recorded successfully') : _t('Evolution recorded successfully'));
            TScript::create("__adianti_goto_page('" . self::returnUrl($hospitalizationId) . "')");
        }
        catch (\CentralVet\Authorization\Exception\AuthorizationDenied $e)
        {
            TTransaction::rollback();
            self::enableSubmit();
            new TMessage('error', _t('You are not allowed to record events for this hospitalization'));
        }
        catch (Exception $e)
        {
            TTransaction::rollback();
            self::enableSubmit();
            error_log(__METHOD__ . ': ' . $e->getMessage());
            new TMessage('error', CvFormat::userError($e));
        }
    }

    private static function enableSubmit(): void
    {
        TScript::create("document.querySelectorAll('#form_HospitalizationEvent button').forEach(function(b){b.disabled=false;})");
    }

    private static function numberEntry(string $name, string $inputMode, string $placeholder): TEntry
    {
        $entry = new TEntry($name);
        $entry->setProperty('inputmode', $inputMode);
        $entry->setProperty('autocomplete', 'off');
        $entry->placeholder = $placeholder;

        return $entry;
    }

    private static function optionalInt($param, string $name): ?int
    {
        $raw = is_array($param) && isset($param[$name]) ? trim((string) $param[$name]) : '';

        if ($raw === '')
        {
            return null;
        }

        if (!preg_match('/^-?\d+$/', $raw))
        {
            throw new InvalidArgumentException("{$name} must be a whole number");
        }

        return (int) $raw;
    }

    private static function optionalFloat($param, string $name): ?float
    {
        $raw = is_array($param) && isset($param[$name]) ? str_replace(',', '.', trim((string) $param[$name])) : '';

        if ($raw === '')
        {
            return null;
        }

        if (!preg_match('/^-?\d+(\.\d+)?$/', $raw))
        {
            throw new InvalidArgumentException("{$name} must be a number");
        }

        return (float) $raw;
    }

    private static function paramType($param): string
    {
        $raw = $_GET['type'] ?? (is_array($param) ? ($param['type'] ?? '') : '');

        return $raw === self::TYPE_VITALS ? self::TYPE_VITALS : self::TYPE_EVOLUTION;
    }

    private static function returnUrl(?int $hospitalizationId): string
    {
        return ($hospitalizationId !== null && $hospitalizationId > 0)
            ? 'index.php?class=HospitalizationView&id=' . $hospitalizationId
            : 'index.php?class=HospitalizationBoard';
    }

    private static function paramInt(string $name, $param): ?int
    {
        if (isset($_GET[$name]) && $_GET[$name] !== '')
        {
            return (int) $_GET[$name];
        }

        if (is_array($param) && isset($param[$name]) && $param[$name] !== '')
        {
            return (int) $param[$name];
        }

        return null;
    }

    /** Requer TTransaction('permission') aberta. */
    private static function makeHospitalizationService(\CentralVet\Tenancy\TenantContext $context): \CentralVet\Application\HospitalizationService
    {
        $connection = TTransaction::get();

        return new \CentralVet\Application\HospitalizationService(
            new \CentralVet\Persistence\HospitalizationRepository($context, $connection),
            new \CentralVet\Persistence\BedRepository($context, $connection),
            new \CentralVet\Persistence\HospitalizationEventRepository($context, $connection),
            new \CentralVet\Persistence\EncounterRepository($context, $connection),
            new \CentralVet\Persistence\EncounterAccountRepository($context, $connection),
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
     * ExamResultForm::resolveTenantContext(), padrão T-03).
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
