<?php
/**
 * HospitalizationAdmissionForm
 *
 * Admission screen (Fase 6A, T-13), opened from an encounter:
 * `index.php?class=HospitalizationAdmissionForm&encounter_id=<id>&patient_id=<id>`.
 * UI shell only: every rule (unit scope, bed availability, patient already
 * hospitalized, open account, responsible user membership) lives in
 * CentralVet\Application\HospitalizationService::admit() (T-09).
 *
 * The form is always built (so its fields exist even without context), but
 * data is loaded only when `encounter_id` is given: the encounter's unit
 * defines the bed combo (BedService::listAvailableForUnit, "code — name")
 * and its professional pre-selects the responsible user. Without
 * encounter_id the page shows an empty state; with no available bed it
 * shows an empty state linking to BedList. Loading failures degrade to a
 * TMessage, never a fatal error.
 *
 * onSave runs admit() inside a single TTransaction: if occupy() loses the
 * race after the hospitalization row was saved, the exception rolls the
 * whole admission back.
 *
 * @version    1.0
 * @package    control
 * @subpackage clinic
 */
class HospitalizationAdmissionForm extends TPage
{
    private const ACTION_LOAD = 'HospitalizationAdmissionForm::onLoad';
    private const ACTION_SAVE = 'HospitalizationAdmissionForm::onSave';

    protected $form;

    public function __construct($param = null)
    {
        parent::__construct();

        $encounterId = self::paramInt('encounter_id', $param);

        $this->form = new BootstrapFormBuilder('form_HospitalizationAdmission');
        $this->form->setFormTitle(_t('Hospitalization admission'));
        $this->form->enableClientValidation();

        $encounter_id = new THidden('encounter_id');
        $encounter_id->setValue($encounterId);

        $bed_id = new TCombo('bed_id');
        $bed_id->addValidation(_t('Bed'), new TRequiredValidator);

        $responsible_system_user_id = new TCombo('responsible_system_user_id');
        $responsible_system_user_id->addValidation(_t('Responsible veterinarian'), new TRequiredValidator);

        $reason_text = new TText('reason_text');
        $reason_text->setSize('100%', 90);
        $reason_text->addValidation(_t('Admission reason'), new TRequiredValidator);

        $expected_discharge_date = new TDate('expected_discharge_date');
        $expected_discharge_date->setMask('dd/mm/yyyy');

        $hiddenRow = $this->form->addFields([$encounter_id]);
        $hiddenRow->style = 'display: none';

        $this->form->addFields(
            [new TLabel(_t('Bed'))], [$bed_id],
            [new TLabel(_t('Responsible veterinarian'))], [$responsible_system_user_id]
        );
        $this->form->addFields(
            [new TLabel(_t('Expected discharge date'))], [$expected_discharge_date]
        );
        $this->form->addFields(
            [new TLabel(_t('Admission reason'))], [$reason_text]
        );

        $btn = $this->form->addAction(_t('Admit'), new TAction([$this, 'onSave']), 'fa:bed');
        $btn->class = 'btn btn-primary';

        CvForm::decorate($this->form, 2);

        $container = new TVBox;
        $container->style = 'width: 100%';
        $container->add(CvPage::header(_t('Hospitalization admission'), null, self::backAction($encounterId)));

        if ($encounterId === null)
        {
            $container->add(self::emptyState(_t('Open the admission from an encounter.'), null));
            parent::add($container);
            return;
        }

        $options = self::loadOptions($encounterId);

        if ($options === null)
        {
            parent::add($container);
            return;
        }

        if (empty($options['beds']))
        {
            $container->add(self::emptyState(
                _t('There is no available bed in this unit.'),
                ['href' => 'index.php?class=BedList', 'label' => _t('Manage beds')]
            ));
            parent::add($container);
            return;
        }

        $bed_id->addItems($options['beds']);
        $responsible_system_user_id->addItems($options['users']);

        if ($options['professional_id'] !== null && isset($options['users'][$options['professional_id']]))
        {
            $responsible_system_user_id->setValue($options['professional_id']);
        }

        $container->add($this->form);

        parent::add($container);
    }

    /**
     * Admits through HospitalizationService::admit() in one TTransaction.
     * Refusals (bed occupied, patient already hospitalized, closed account,
     * unit scope) arrive as exceptions and are shown via CvFormat::userError.
     */
    public function onSave($param)
    {
        try
        {
            $data = $this->form->getData();
            $this->form->setData($data);

            $encounterId = isset($param['encounter_id']) ? (int) $param['encounter_id'] : 0;
            $bedId = isset($param['bed_id']) && $param['bed_id'] !== '' ? (int) $param['bed_id'] : 0;
            $responsibleId = isset($param['responsible_system_user_id']) && $param['responsible_system_user_id'] !== ''
                ? (int) $param['responsible_system_user_id']
                : 0;
            $reasonText = trim((string) ($param['reason_text'] ?? ''));

            if ($encounterId <= 0 || $bedId <= 0 || $responsibleId <= 0 || $reasonText === '')
            {
                throw new InvalidArgumentException(_t('All fields are required'));
            }

            $context = self::resolveTenantContext();

            TTransaction::open('permission');

            $hospitalization = self::makeHospitalizationService($context)->admit(
                $encounterId,
                $bedId,
                $responsibleId,
                $reasonText,
                self::dateToIso($param['expected_discharge_date'] ?? null),
                self::ACTION_SAVE
            );

            TTransaction::close();

            $id = (int) $hospitalization->id();

            TToast::show('success', _t('Patient admitted'));
            TScript::create("__adianti_goto_page('index.php?class=HospitalizationView&id={$id}')");
        }
        catch (\CentralVet\Authorization\Exception\AuthorizationDenied $e)
        {
            TTransaction::rollback();
            new TMessage('error', _t('You are not allowed to admit patients in this unit'));
        }
        catch (Exception $e)
        {
            TTransaction::rollback();
            error_log(__METHOD__ . ': ' . $e->getMessage());
            new TMessage('error', CvFormat::userError($e));
        }
    }

    /**
     * Beds (available in the encounter's unit), tenant users and the
     * encounter professional. Null when loading failed (message already shown).
     *
     * @return array{beds: array<int, string>, users: array<int, string>, professional_id: ?int}|null
     */
    private static function loadOptions(int $encounterId): ?array
    {
        try
        {
            $context = self::resolveTenantContext();

            TTransaction::open('permission');

            $connection = TTransaction::get();
            $encounter = (new \CentralVet\Persistence\EncounterRepository($context, $connection))->findById($encounterId);

            if (!$encounter instanceof \CentralVet\Domain\Encounter)
            {
                throw new \CentralVet\Domain\Exception\CrossTenantReferenceException(
                    "encounter_id {$encounterId} was not found for the authenticated tenant"
                );
            }

            $beds = [];

            foreach (self::makeBedService($context)->listAvailableForUnit($encounter->systemUnitId(), self::ACTION_LOAD) as $bed)
            {
                $beds[(int) $bed->id()] = $bed->code() . ' — ' . $bed->name();
            }

            $users = [];
            $criteria = CvTenantUsers::criteria(static fn () => $context);
            $criteria->setProperty('order', 'name');

            foreach ((new TRepository('SystemUser'))->load($criteria, false) ?? [] as $user)
            {
                $users[(int) $user->id] = (string) $user->name;
            }

            TTransaction::close();

            return [
                'beds' => $beds,
                'users' => $users,
                'professional_id' => $encounter->professionalSystemUserId(),
            ];
        }
        catch (\CentralVet\Authorization\Exception\AuthorizationDenied $e)
        {
            TTransaction::rollback();
            new TMessage('error', _t('You are not allowed to admit patients in this unit'));

            return null;
        }
        catch (Exception $e)
        {
            TTransaction::rollback();
            error_log(__METHOD__ . ': ' . $e->getMessage());
            new TMessage('error', CvFormat::userError($e));

            return null;
        }
    }

    /**
     * @param array{href: string, label: string}|null $link
     */
    private static function emptyState(string $text, ?array $link): TPanelGroup
    {
        $panel = new TPanelGroup(_t('Hospitalization admission'));
        $html = '<p>' . CvFormat::e($text) . '</p>';

        if ($link !== null)
        {
            $html .= '<a class="btn btn-outline-primary" generator="adianti" href="' . CvFormat::e($link['href']) . '">'
                . CvFormat::e($link['label']) . '</a>';
        }

        $panel->add($html);

        return $panel;
    }

    private static function backAction(?int $encounterId): array
    {
        if ($encounterId === null)
        {
            return [];
        }

        return [[
            'icon' => 'fa:arrow-left',
            'href' => 'index.php?class=EncounterView&encounter_id=' . $encounterId,
        ]];
    }

    /**
     * dd/mm/aaaa → Y-m-d; vazio → null. Texto fora do formato segue como
     * veio, para o HospitalizationService recusar
     * ("expected_discharge_date must be a Y-m-d date").
     */
    private static function dateToIso($value): ?string
    {
        $value = trim((string) $value);

        if ($value === '')
        {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!d/m/Y', $value);

        if ($date === false || $date->format('d/m/Y') !== $value)
        {
            return $value;
        }

        return $date->format('Y-m-d');
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

    private static function makeAuthorization(PDO $connection): \CentralVet\Authorization\RbacAuthorizationService
    {
        return new \CentralVet\Authorization\RbacAuthorizationService(
            new \CentralVet\Authorization\AdiantiProgramPermissionProvider(new \CentralVet\Tenancy\AdiantiSessionContextSource()),
            new \CentralVet\Audit\PdoAuditLogWriter($connection),
        );
    }

    /**
     * Requires an already-open TTransaction('permission') connection.
     */
    private static function makeBedService(\CentralVet\Tenancy\TenantContext $context): \CentralVet\Application\BedService
    {
        $connection = TTransaction::get();

        return new \CentralVet\Application\BedService(
            new \CentralVet\Persistence\BedRepository($context, $connection),
            self::makeAuthorization($connection),
            $context,
        );
    }

    /**
     * Requires an already-open TTransaction('permission') connection.
     */
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
            self::makeAuthorization($connection),
            $context,
        );
    }

    /**
     * Resolves the tenant context of the authenticated session (same
     * fallback as ExamRequestForm/EncounterView for legacy sessions).
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
