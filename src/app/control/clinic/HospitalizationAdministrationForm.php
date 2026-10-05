<?php
/**
 * HospitalizationAdministrationForm
 *
 * Checagem de uma administração da internação no tablet (Fase 6A, T-15):
 * mostra paciente, item, dose, via e horário, com dois botões grandes
 * "Feito" e "Não feito" e a observação (obrigatória para "Não feito",
 * regra do domínio). Os dois botões ficam desabilitados no primeiro toque;
 * o servidor recusa o segundo envio (`Administration <id> is not pending`,
 * HospitalizationOrderService::recordAdministration() + UPDATE condicional
 * do repositório).
 *
 * Entrada: `administration_id` ($_GET, senão $param). Sem ele, estado vazio
 * sem tocar no banco. Depois de registrar volta para
 * HospitalizationView&id=<hospitalization_id>.
 *
 * @version    1.0
 * @package    control
 * @subpackage clinic
 */
class HospitalizationAdministrationForm extends TPage
{
    protected $form;

    private const ACTION_VIEW = 'HospitalizationAdministrationForm';
    private const ACTION_DONE = 'HospitalizationAdministrationForm::onDone';
    private const ACTION_SKIP = 'HospitalizationAdministrationForm::onSkip';

    private const SUBMIT_CLASS = 'cv-administration-submit';

    public function __construct($param = null)
    {
        parent::__construct();

        $administrationId = self::paramInt('administration_id', $param);

        $container = new TVBox;
        $container->style = 'width: 100%';

        if ($administrationId === null)
        {
            $container->add(CvPage::header(_t('Administration'), null, [[
                'icon' => 'fa:arrow-left',
                'href' => self::returnUrl(null),
            ]]));
            $panel = new TPanelGroup(_t('Administration'));
            $panel->add('<p>' . _t('Provide an administration_id to record it.') . '</p>');
            $container->add($panel);
            parent::add($container);
            return;
        }

        $details = null;

        try
        {
            $details = self::loadDetails($administrationId);
        }
        catch (Exception $e)
        {
            TTransaction::rollback();
            error_log(__METHOD__ . ': ' . $e->getMessage());
            new TMessage('error', CvFormat::userError($e));
        }

        $hospitalizationId = $details['hospitalization_id'] ?? null;

        $container->add(CvPage::header(_t('Administration'), null, [[
            'icon' => 'fa:arrow-left',
            'href' => self::returnUrl($hospitalizationId),
        ]]));

        $this->form = new BootstrapFormBuilder('form_HospitalizationAdministration');
        $this->form->setFormTitle(_t('Administration') . ' #' . $administrationId);

        $administration_id = new THidden('administration_id');
        $administration_id->setValue($administrationId);
        $hospitalization_id = new THidden('hospitalization_id');
        $hospitalization_id->setValue($hospitalizationId);

        $hiddenRow = $this->form->addFields([$administration_id, $hospitalization_id]);
        $hiddenRow->style = 'display: none';

        if ($details !== null)
        {
            $this->form->addContent([self::summaryHtml($details)]);
        }

        $notes_text = new TText('notes_text');
        $notes_text->setSize('100%', 90);
        $notes_text->setProperty('maxlength', '500');
        $notes_text->placeholder = _t('Required when not done');

        $this->form->addFields([new TLabel(_t('Notes'))]);
        $this->form->addFields([$notes_text]);

        if ($details !== null && $details['status'] === \CentralVet\Domain\HospitalizationAdministration::STATUS_PENDING)
        {
            $disableAll = "document.querySelectorAll('." . self::SUBMIT_CLASS . "').forEach(function(b){b.disabled=true;})";

            $done = $this->form->addAction(_t('Done'), new TAction([$this, 'onDone']), 'fa:check');
            $done->class = 'btn btn-success btn-lg ' . self::SUBMIT_CLASS;
            $done->style = 'min-height:56px;min-width:160px;font-size:1.15rem';
            $done->addFunction($disableAll);

            $skip = $this->form->addAction(_t('Not done'), new TAction([$this, 'onSkip']), 'fa:times');
            $skip->class = 'btn btn-danger btn-lg ' . self::SUBMIT_CLASS;
            $skip->style = 'min-height:56px;min-width:160px;font-size:1.15rem';
            $skip->addFunction($disableAll);
        }

        CvForm::decorate($this->form, 1);

        $container->add($this->form);

        parent::add($container);
    }

    public function onDone($param)
    {
        self::record($param, \CentralVet\Application\HospitalizationOrderService::OUTCOME_DONE, self::ACTION_DONE);
    }

    public function onSkip($param)
    {
        self::record($param, \CentralVet\Application\HospitalizationOrderService::OUTCOME_SKIPPED, self::ACTION_SKIP);
    }

    private static function record($param, string $outcome, string $action): void
    {
        try
        {
            $raw = is_array($param) && isset($param['administration_id']) ? trim((string) $param['administration_id']) : '';

            if ($raw === '' || !ctype_digit($raw) || (int) $raw <= 0)
            {
                throw new InvalidArgumentException('administration_id must be a positive integer');
            }

            $context = self::resolveTenantContext();

            TTransaction::open('permission');

            $administration = self::makeOrderService($context)->recordAdministration(
                (int) $raw,
                $outcome,
                trim((string) ($param['notes_text'] ?? '')),
                $action,
            );

            TTransaction::close();

            TToast::show('success', $outcome === \CentralVet\Application\HospitalizationOrderService::OUTCOME_DONE
                ? _t('Administration recorded as done')
                : _t('Administration recorded as not done'));
            TScript::create("__adianti_goto_page('" . self::returnUrl($administration->hospitalizationId()) . "')");
        }
        catch (\CentralVet\Authorization\Exception\AuthorizationDenied $e)
        {
            TTransaction::rollback();
            self::enableSubmit();
            new TMessage('error', _t('You are not allowed to record this administration'));
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
        TScript::create("document.querySelectorAll('." . self::SUBMIT_CLASS . "').forEach(function(b){b.disabled=false;})");
    }

    /**
     * Administração (autorizada pelo service), prescrição e paciente.
     *
     * @return array{hospitalization_id: int, status: string, patient: string, item: string, dose: string, route: string, scheduled_at: string}
     */
    private static function loadDetails(int $administrationId): array
    {
        $context = self::resolveTenantContext();

        TTransaction::open('permission');
        $connection = TTransaction::get();

        $administration = self::makeOrderService($context)->getAdministration($administrationId, self::ACTION_VIEW);

        $order = (new \CentralVet\Persistence\HospitalizationOrderRepository($context, $connection))->findById($administration->orderId());
        $hospitalization = (new \CentralVet\Persistence\HospitalizationRepository($context, $connection))->findById($administration->hospitalizationId());
        $patient = $hospitalization !== null
            ? (new \CentralVet\Persistence\PatientRepository($context, $connection))->findById($hospitalization->patientId())
            : null;

        TTransaction::close();

        $routes = HospitalizationOrderForm::routeLabels();

        return [
            'hospitalization_id' => (int) $administration->hospitalizationId(),
            'status' => $administration->status(),
            'patient' => $patient !== null ? (string) $patient->name : '',
            'item' => $order !== null ? $order->descriptionText() : '',
            'dose' => $order !== null ? $order->doseText() : '',
            'route' => $order !== null ? ($routes[$order->route()] ?? $order->route()) : '',
            'scheduled_at' => $administration->scheduledAt()->format('d/m/Y H:i'),
        ];
    }

    /** @param array<string, mixed> $details */
    private static function summaryHtml(array $details): TElement
    {
        $statusLabels = [
            \CentralVet\Domain\HospitalizationAdministration::STATUS_PENDING => _t('Pending'),
            \CentralVet\Domain\HospitalizationAdministration::STATUS_DONE => _t('Done'),
            \CentralVet\Domain\HospitalizationAdministration::STATUS_SKIPPED => _t('Not done'),
            \CentralVet\Domain\HospitalizationAdministration::STATUS_CANCELLED => _t('Cancelled'),
        ];

        $rows = [
            _t('Patient') => $details['patient'],
            _t('Item') => $details['item'],
            _t('Dose') => $details['dose'],
            _t('Route') => $details['route'],
            _t('Scheduled at') => $details['scheduled_at'],
            _t('Status') => $statusLabels[$details['status']] ?? $details['status'],
        ];

        $html = '<dl class="row mb-2" style="font-size:1.1rem">';

        foreach ($rows as $label => $value)
        {
            $html .= '<dt class="col-4 col-md-3">' . CvFormat::e((string) $label) . '</dt>'
                . '<dd class="col-8 col-md-9">' . CvFormat::e((string) $value) . '</dd>';
        }

        $html .= '</dl>';

        $element = new TElement('div');
        $element->add($html);

        return $element;
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
    private static function makeOrderService(\CentralVet\Tenancy\TenantContext $context): \CentralVet\Application\HospitalizationOrderService
    {
        $connection = TTransaction::get();

        return new \CentralVet\Application\HospitalizationOrderService(
            new \CentralVet\Persistence\HospitalizationOrderRepository($context, $connection),
            new \CentralVet\Persistence\HospitalizationAdministrationRepository($context, $connection),
            new \CentralVet\Persistence\HospitalizationRepository($context, $connection),
            new \CentralVet\Persistence\ProductRepository($context, $connection),
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
