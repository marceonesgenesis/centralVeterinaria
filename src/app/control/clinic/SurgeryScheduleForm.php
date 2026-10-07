<?php
/**
 * SurgeryScheduleForm
 *
 * Surgery scheduling screen (Fase 6B, T-13), with two modes:
 *
 * - schedule: `index.php?class=SurgeryScheduleForm&encounter_id=<id>&patient_id=<id>`.
 *   Rooms come from SurgeryRoomService::listActiveForUnit (unit of the
 *   encounter, "code — name"), procedures from SurgeryService::listProcedures,
 *   professionals are the active users of the tenant (CvTenantUsers) and the
 *   surgeon is pre-selected with the encounter professional. Without an
 *   active room the page shows an empty state linking to SurgeryRoomList.
 * - team: `index.php?class=SurgeryScheduleForm&id=<surgery_id>`. Only the
 *   three team fields, saved with SurgeryService::replaceTeam.
 *
 * UI shell only: every rule (unit scope, room lock and overlap, open
 * account, active users, duration range, status) lives in
 * CentralVet\Application\SurgeryService (T-08). Each save runs inside a
 * single TTransaction. Loading failures degrade to a TMessage, never a
 * fatal error. notes_text travels only by POST (form submission).
 *
 * @version    1.0
 * @package    control
 * @subpackage clinic
 */
class SurgeryScheduleForm extends TPage
{
    private const ACTION_LOAD = 'SurgeryScheduleForm::onLoad';
    private const ACTION_SAVE = 'SurgeryScheduleForm::onSave';
    private const ACTION_SAVE_TEAM = 'SurgeryScheduleForm::onSaveTeam';

    private const DEFAULT_DURATION_MINUTES = 60;

    /** Team field => SurgeryTeamMember role (the surgeon is set apart). */
    private const TEAM_FIELDS = [
        'anesthetist_system_user_id' => \CentralVet\Domain\SurgeryTeamMember::ROLE_ANESTHETIST,
        'assistant_system_user_id' => \CentralVet\Domain\SurgeryTeamMember::ROLE_ASSISTANT,
        'circulating_system_user_id' => \CentralVet\Domain\SurgeryTeamMember::ROLE_CIRCULATING,
    ];

    protected $form;

    public function __construct($param = null)
    {
        parent::__construct();

        $surgeryId = self::paramInt('id', $param);
        $encounterId = $surgeryId === null ? self::paramInt('encounter_id', $param) : null;

        $this->form = new BootstrapFormBuilder('form_SurgerySchedule');
        $this->form->setFormTitle($surgeryId === null ? _t('Schedule surgery') : _t('Surgical team'));
        $this->form->enableClientValidation();

        $teamCombos = [];

        foreach (array_keys(self::TEAM_FIELDS) as $name)
        {
            $teamCombos[$name] = new TCombo($name);
        }

        $container = new TVBox;
        $container->style = 'width: 100%';

        if ($surgeryId !== null)
        {
            $this->buildTeamMode($container, $surgeryId, $teamCombos);
            parent::add($container);
            return;
        }

        $encounter_id = new THidden('encounter_id');
        $encounter_id->setValue($encounterId);

        $room_id = new TCombo('room_id');
        $room_id->addValidation(_t('Surgery room'), new TRequiredValidator);

        $procedure_catalog_item_id = new TCombo('procedure_catalog_item_id');
        $procedure_catalog_item_id->addValidation(_t('Procedure'), new TRequiredValidator);
        $procedure_catalog_item_id->setChangeAction(new TAction([__CLASS__, 'onChangeProcedure']));

        $surgeon_system_user_id = new TCombo('surgeon_system_user_id');
        $surgeon_system_user_id->addValidation(_t('Surgeon'), new TRequiredValidator);

        // TEntry com máscara (padrão do AppointmentForm, T-53): o texto
        // digitado vai cru ao DateTimeInput::parse (estrito, d/m/Y H:i).
        $scheduled_start_at = new TEntry('scheduled_start_at');
        $scheduled_start_at->setMask('99/99/9999 99:99');
        $scheduled_start_at->placeholder = 'dd/mm/aaaa hh:mm';
        $scheduled_start_at->setProperty('inputmode', 'numeric');
        $scheduled_start_at->setProperty('autocomplete', 'off');
        $scheduled_start_at->addValidation(_t('Start date/time'), new TRequiredValidator);

        $duration_minutes = new TEntry('duration_minutes');
        $duration_minutes->setProperty('inputmode', 'numeric');
        $duration_minutes->setValue((string) self::DEFAULT_DURATION_MINUTES);
        $duration_minutes->addValidation(_t('Duration (minutes)'), new TRequiredValidator);

        $notes_text = new TText('notes_text');
        $notes_text->setSize('100%', 90);

        $hiddenRow = $this->form->addFields([$encounter_id]);
        $hiddenRow->style = 'display: none';

        $this->form->addFields(
            [new TLabel(_t('Surgery room'))], [$room_id],
            [new TLabel(_t('Procedure'))], [$procedure_catalog_item_id]
        );
        $this->form->addFields(
            [new TLabel(_t('Start date/time'))], [$scheduled_start_at],
            [new TLabel(_t('Duration (minutes)'))], [$duration_minutes]
        );
        $this->form->addFields(
            [new TLabel(_t('Surgeon'))], [$surgeon_system_user_id],
            [new TLabel(_t('Anesthetist'))], [$teamCombos['anesthetist_system_user_id']]
        );
        $this->form->addFields(
            [new TLabel(_t('Surgical assistant'))], [$teamCombos['assistant_system_user_id']],
            [new TLabel(_t('Circulating nurse'))], [$teamCombos['circulating_system_user_id']]
        );
        $this->form->addFields(
            [new TLabel(_t('Notes'))], [$notes_text]
        );

        $btn = $this->form->addAction(_t('Schedule'), new TAction([$this, 'onSave']), 'fa:calendar-check');
        $btn->class = 'btn btn-primary cv-touch-target';

        CvForm::decorate($this->form, 2);

        $container->add(CvPage::header(_t('Schedule surgery'), null, self::backToEncounter($encounterId)));

        if ($encounterId === null)
        {
            $container->add(self::emptyState(_t('Open the surgery scheduling from an encounter.'), null));
            parent::add($container);
            return;
        }

        $options = self::loadScheduleOptions($encounterId);

        if ($options === null)
        {
            parent::add($container);
            return;
        }

        if (empty($options['rooms']))
        {
            $container->add(self::emptyState(
                _t('There is no active surgery room in this unit.'),
                ['href' => 'index.php?class=SurgeryRoomList', 'label' => _t('Manage surgery rooms')]
            ));
            parent::add($container);
            return;
        }

        $room_id->addItems($options['rooms']);
        $procedure_catalog_item_id->addItems($options['procedures']);
        $surgeon_system_user_id->addItems($options['users']);

        foreach ($teamCombos as $combo)
        {
            $combo->addItems($options['users']);
        }

        if ($options['professional_id'] !== null && isset($options['users'][$options['professional_id']]))
        {
            $surgeon_system_user_id->setValue($options['professional_id']);
        }

        $container->add($this->form);

        parent::add($container);
    }

    /**
     * Team mode: only the three team fields (the surgeon is fixed at
     * scheduling), pre-filled with the current members of each role.
     *
     * @param array<string, TCombo> $teamCombos
     */
    private function buildTeamMode(TVBox $container, int $surgeryId, array $teamCombos): void
    {
        $id = new THidden('id');
        $id->setValue($surgeryId);

        $hiddenRow = $this->form->addFields([$id]);
        $hiddenRow->style = 'display: none';

        $this->form->addFields(
            [new TLabel(_t('Anesthetist'))], [$teamCombos['anesthetist_system_user_id']],
            [new TLabel(_t('Surgical assistant'))], [$teamCombos['assistant_system_user_id']]
        );
        $this->form->addFields(
            [new TLabel(_t('Circulating nurse'))], [$teamCombos['circulating_system_user_id']]
        );

        $btn = $this->form->addAction(_t('Save team'), new TAction([$this, 'onSaveTeam']), 'fa:users');
        $btn->class = 'btn btn-primary cv-touch-target';

        CvForm::decorate($this->form, 2);

        $container->add(CvPage::header(_t('Surgical team'), null, [[
            'icon' => 'fa:arrow-left',
            'href' => 'index.php?class=SurgeryView&id=' . $surgeryId,
        ]]));

        $options = self::loadTeamOptions($surgeryId);

        if ($options === null)
        {
            return;
        }

        foreach ($teamCombos as $name => $combo)
        {
            $combo->addItems($options['users']);

            $current = $options['team'][self::TEAM_FIELDS[$name]] ?? null;

            if ($current !== null && isset($options['users'][$current]))
            {
                $combo->setValue($current);
            }
        }

        $panel = new TPanelGroup(_t('Surgery'));
        $panel->add('<p>' . CvFormat::e($options['summary']) . '</p>');
        $container->add($panel);
        $container->add($this->form);
    }

    /**
     * Fills duration_minutes with the procedure default (or 60) when the
     * procedure changes.
     */
    public static function onChangeProcedure($param)
    {
        $procedureId = isset($param['procedure_catalog_item_id']) && $param['procedure_catalog_item_id'] !== ''
            ? (int) $param['procedure_catalog_item_id']
            : 0;

        try
        {
            $minutes = self::DEFAULT_DURATION_MINUTES;

            if ($procedureId > 0)
            {
                $context = self::resolveTenantContext();

                TTransaction::open('permission');
                $minutes = self::procedureDuration(self::makeSurgeryService($context), $procedureId);
                TTransaction::close();
            }

            $data = new stdClass;
            $data->duration_minutes = (string) $minutes;
            TForm::sendData('form_SurgerySchedule', $data, false, false);
        }
        catch (Exception $e)
        {
            TTransaction::rollback();
            error_log(__METHOD__ . ': ' . $e->getMessage());
        }
    }

    /**
     * Schedules through SurgeryService::schedule() in one TTransaction.
     * Refusals (room booked/inactive, closed account, unit scope, inactive
     * user, bad duration) arrive as exceptions and are shown via
     * CvFormat::userError.
     */
    public function onSave($param)
    {
        try
        {
            $data = $this->form->getData();
            $this->form->setData($data);

            $encounterId = self::intOf($param, 'encounter_id');
            $roomId = self::intOf($param, 'room_id');
            $procedureId = self::intOf($param, 'procedure_catalog_item_id');
            $surgeonId = self::intOf($param, 'surgeon_system_user_id');
            $startRaw = trim((string) ($param['scheduled_start_at'] ?? ''));
            $durationRaw = trim((string) ($param['duration_minutes'] ?? ''));
            $notesText = trim((string) ($_POST['notes_text'] ?? ''));

            if ($encounterId <= 0 || $roomId <= 0 || $procedureId <= 0 || $surgeonId <= 0 || $startRaw === '')
            {
                throw new InvalidArgumentException(_t('All fields are required'));
            }

            if ($durationRaw !== '' && preg_match('/\A\d{1,5}\z/', $durationRaw) !== 1)
            {
                throw new InvalidArgumentException('duration_minutes must be between 15 and 1440');
            }

            $scheduledStartAt = \CentralVet\Presentation\DateTimeInput::parse($startRaw);

            $context = self::resolveTenantContext();

            TTransaction::open('permission');

            $service = self::makeSurgeryService($context);
            $duration = $durationRaw !== '' ? (int) $durationRaw : self::procedureDuration($service, $procedureId);

            $surgery = $service->schedule(
                $encounterId,
                $roomId,
                $procedureId,
                $surgeonId,
                $scheduledStartAt,
                $duration,
                self::teamFromParam($param),
                $notesText !== '' ? $notesText : null,
                self::ACTION_SAVE
            );

            TTransaction::close();

            $id = (int) $surgery->id();

            TToast::show('success', _t('Surgery scheduled'));
            TScript::create("__adianti_goto_page('index.php?class=SurgeryView&id={$id}')");
        }
        catch (\CentralVet\Authorization\Exception\AuthorizationDenied $e)
        {
            TTransaction::rollback();
            new TMessage('error', _t('You are not allowed to schedule surgeries in this unit'));
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
     * Replaces the team (anesthetist, assistant, circulating) through
     * SurgeryService::replaceTeam() in one TTransaction; the surgeon stays.
     */
    public function onSaveTeam($param)
    {
        try
        {
            $data = $this->form->getData();
            $this->form->setData($data);

            $surgeryId = self::intOf($param, 'id');

            if ($surgeryId <= 0)
            {
                throw new InvalidArgumentException(_t('All fields are required'));
            }

            $context = self::resolveTenantContext();

            TTransaction::open('permission');

            self::makeSurgeryService($context)->replaceTeam($surgeryId, self::teamFromParam($param), self::ACTION_SAVE_TEAM);

            TTransaction::close();

            TToast::show('success', _t('Surgical team saved'));
            TScript::create("__adianti_goto_page('index.php?class=SurgeryView&id={$surgeryId}')");
        }
        catch (\CentralVet\Authorization\Exception\AuthorizationDenied $e)
        {
            TTransaction::rollback();
            new TMessage('error', _t('You are not allowed to schedule surgeries in this unit'));
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
     * Rooms (active, of the encounter's unit), procedures, tenant users and
     * the encounter professional. Null when loading failed (message shown).
     *
     * @return array{rooms: array<int, string>, procedures: array<int, string>, users: array<int, string>, professional_id: ?int}|null
     */
    private static function loadScheduleOptions(int $encounterId): ?array
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

            $rooms = [];

            foreach (self::makeSurgeryRoomService($context)->listActiveForUnit($encounter->systemUnitId(), self::ACTION_LOAD) as $room)
            {
                $rooms[(int) $room->id()] = $room->code() . ' — ' . $room->name();
            }

            $procedures = [];

            foreach (self::makeSurgeryService($context)->listProcedures(self::ACTION_LOAD) as $procedure)
            {
                $procedures[(int) $procedure->id()] = $procedure->name();
            }

            $users = self::loadTenantUsers($context);

            TTransaction::close();

            return [
                'rooms' => $rooms,
                'procedures' => $procedures,
                'users' => $users,
                'professional_id' => $encounter->professionalSystemUserId(),
            ];
        }
        catch (\CentralVet\Authorization\Exception\AuthorizationDenied $e)
        {
            TTransaction::rollback();
            new TMessage('error', _t('You are not allowed to schedule surgeries in this unit'));

            return null;
        }
        catch (\CentralVet\Tenancy\Exception\MissingTenantContext $e)
        {
            TTransaction::rollback();
            new TMessage('error', _t('An authenticated session with an active unit is required'));

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
     * Tenant users, current member per team role and a one-line summary of
     * the surgery. Null when loading failed (message already shown).
     *
     * @return array{users: array<int, string>, team: array<string, int>, summary: string}|null
     */
    private static function loadTeamOptions(int $surgeryId): ?array
    {
        try
        {
            $context = self::resolveTenantContext();

            TTransaction::open('permission');

            $service = self::makeSurgeryService($context);
            $surgery = $service->get($surgeryId, self::ACTION_LOAD);

            $team = [];

            foreach ($service->listTeam($surgeryId, self::ACTION_LOAD) as $member)
            {
                $team[$member->role()] ??= $member->systemUserId();
            }

            $users = self::loadTenantUsers($context);

            TTransaction::close();

            return [
                'users' => $users,
                'team' => $team,
                'summary' => $surgery->procedureName() . ' — ' . $surgery->scheduledStartAt()->format('d/m/Y H:i'),
            ];
        }
        catch (\CentralVet\Authorization\Exception\AuthorizationDenied $e)
        {
            TTransaction::rollback();
            new TMessage('error', _t('You are not allowed to schedule surgeries in this unit'));

            return null;
        }
        catch (\CentralVet\Tenancy\Exception\MissingTenantContext $e)
        {
            TTransaction::rollback();
            new TMessage('error', _t('An authenticated session with an active unit is required'));

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
     * Active users of the tenant (same filter as the other user combos).
     * Requires an already-open TTransaction('permission').
     *
     * @return array<int, string>
     */
    private static function loadTenantUsers(\CentralVet\Tenancy\TenantContext $context): array
    {
        $criteria = CvTenantUsers::criteria(static fn () => $context);
        $criteria->setProperty('order', 'name');

        $users = [];

        foreach ((new TRepository('SystemUser'))->load($criteria, false) ?? [] as $user)
        {
            $users[(int) $user->id] = (string) $user->name;
        }

        return $users;
    }

    /**
     * Default duration of a procedure (its duration_minutes, else 60).
     * Requires an already-open TTransaction('permission').
     */
    private static function procedureDuration(\CentralVet\Application\SurgeryService $service, int $procedureId): int
    {
        foreach ($service->listProcedures(self::ACTION_LOAD) as $procedure)
        {
            if ((int) $procedure->id() === $procedureId)
            {
                $minutes = $procedure->durationMinutes();

                return $minutes !== null && $minutes > 0 ? $minutes : self::DEFAULT_DURATION_MINUTES;
            }
        }

        return self::DEFAULT_DURATION_MINUTES;
    }

    /**
     * Team entries from the three optional combos (empty ones are skipped).
     *
     * @return list<array{system_user_id: int, role: string}>
     */
    private static function teamFromParam($param): array
    {
        $team = [];

        foreach (self::TEAM_FIELDS as $field => $role)
        {
            $userId = self::intOf($param, $field);

            if ($userId > 0)
            {
                $team[] = ['system_user_id' => $userId, 'role' => $role];
            }
        }

        return $team;
    }

    private static function intOf($param, string $name): int
    {
        return is_array($param) && isset($param[$name]) && $param[$name] !== '' ? (int) $param[$name] : 0;
    }

    /**
     * @param array{href: string, label: string}|null $link
     */
    private static function emptyState(string $text, ?array $link): TPanelGroup
    {
        $panel = new TPanelGroup(_t('Schedule surgery'));
        $html = '<p>' . CvFormat::e($text) . '</p>';

        if ($link !== null)
        {
            $html .= '<a class="btn btn-outline-primary cv-touch-target" generator="adianti" href="' . CvFormat::e($link['href']) . '">'
                . CvFormat::e($link['label']) . '</a>';
        }

        $panel->add($html);

        return $panel;
    }

    private static function backToEncounter(?int $encounterId): array
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
    private static function makeSurgeryRoomService(\CentralVet\Tenancy\TenantContext $context): \CentralVet\Application\SurgeryRoomService
    {
        $connection = TTransaction::get();

        return new \CentralVet\Application\SurgeryRoomService(
            new \CentralVet\Persistence\SurgeryRoomRepository($context, $connection),
            self::makeAuthorization($connection),
            $context,
        );
    }

    /**
     * Requires an already-open TTransaction('permission') connection.
     */
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
            self::makeAuthorization($connection),
            $context,
        );
    }

    /**
     * Resolves the tenant context of the authenticated session (same
     * fallback as HospitalizationAdmissionForm for legacy sessions).
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
