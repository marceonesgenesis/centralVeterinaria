<?php

use CentralVet\Presentation\DateTimeInput;

/**
 * SurgeryView
 *
 * Ficha da cirurgia (Fase 6B, T-14): cabeçalho com paciente, procedimento,
 * sala, horário previsto, cirurgião e status; abas Resumo (equipe e
 * consentimento), Checklist (3 fases), Materiais e Eventos; coluna lateral
 * com as ações do status atual (pré-op, início, cancelamento, conclusão,
 * retorno e internação pós-operatória).
 *
 * Toda regra vive nos Application services (SurgeryService,
 * SurgeryChecklistService, SurgeryMaterialService, SurgeryCompletionService);
 * esta tela só lê o que eles devolvem e repositórios tenant-aware para
 * rótulos de exibição (paciente, sala, usuários, serviços).
 *
 * A conclusão cruza cirurgia, conta do atendimento e estoque e roda num
 * único TTransaction('permission'): estoque insuficiente (ou qualquer outra
 * exceção) desfaz tudo e a cirurgia segue em andamento. Cada outra ação
 * também roda num TTransaction só.
 *
 * O motivo do cancelamento (texto clínico) só vem do corpo do POST: a
 * confirmação reenvia o formulário por POST e o mesmo campo na query string
 * é ignorado (padrão de HospitalizationView::onAskDischarge).
 *
 * Sem `id` → estado vazio antes de resolver o tenant.
 *
 * @version    1.0
 * @package    control
 * @subpackage clinic
 */
class SurgeryView extends TPage
{
    private const ACTION_READ = 'SurgeryView::onReload';
    private const ACTION_START_PRE_OP = 'SurgeryView::onStartPreOp';
    private const ACTION_START = 'SurgeryView::onStart';
    private const ACTION_CANCEL = 'SurgeryView::onCancel';
    private const ACTION_COMPLETE = 'SurgeryView::onComplete';
    private const ACTION_FOLLOW_UP = 'SurgeryView::onScheduleFollowUp';

    private const TABS = ['summary', 'checklist', 'materials', 'events'];

    private const TOUCH = 'min-height:var(--cv-touch-target)';

    private const CANCEL_FORM = 'form_SurgeryView_cancel';
    private const FOLLOW_UP_FORM = 'form_SurgeryView_followup';

    private ?int $surgeryId;

    public function __construct($param = null)
    {
        parent::__construct();

        $this->surgeryId = self::paramInt('id', $param);

        $tab = (string) ($_GET['tab'] ?? (is_array($param) ? ($param['tab'] ?? '') : ''));
        if (!in_array($tab, self::TABS, true))
        {
            $tab = 'summary';
        }

        $container = new TVBox;
        $container->style = 'width: 100%';

        $back = ['icon' => 'fa:arrow-left', 'href' => 'index.php?class=SurgeryList'];

        if ($this->surgeryId === null)
        {
            $container->add(CvPage::header(_t('Surgery'), null, [$back]));
            $container->add(self::statePanel('empty', _t('Open a surgery from the surgical schedule or from the encounter.')));
            parent::add($container);
            return;
        }

        $data = $this->loadData($this->surgeryId);

        if ($data === null)
        {
            // a recusa já foi mostrada como TMessage; nenhum dado do paciente
            $container->add(CvPage::header(_t('Surgery'), null, [$back]));
            $container->add(self::statePanel('error', _t('This surgery could not be loaded.')));
            parent::add($container);
            return;
        }

        $container->add($this->buildHeader($data, $back));

        $base = 'index.php?class=SurgeryView&id=' . $this->surgeryId . '&tab=';
        $container->add(CvPage::tabs([
            'summary'   => ['label' => _t('Summary'), 'href' => $base . 'summary'],
            'checklist' => ['label' => _t('Checklist'), 'href' => $base . 'checklist'],
            'materials' => ['label' => _t('Materials'), 'href' => $base . 'materials'],
            'events'    => ['label' => _t('Events'), 'href' => $base . 'events'],
        ], $tab));

        $main = match ($tab) {
            'checklist' => $this->buildChecklistPanel($data),
            'materials' => $this->buildMaterialsPanel($data),
            'events'    => $this->buildEventsPanel($data),
            default     => $this->buildSummaryPanel($data),
        };

        $container->add(CvPage::columns($main, $this->buildActionsPanel($data)));

        parent::add($container);
    }

    /**
     * Destino das ações de mensagem (OK do TMessage): o construtor já
     * renderiza a ficha com o `id` da URL.
     */
    public function onReload($param = null)
    {
    }

    /**
     * scheduled → pre_op (SurgeryService::startPreOp).
     */
    public static function onStartPreOp($param)
    {
        $id = self::paramInt('id', $param);

        self::runChange($id, function (\CentralVet\Tenancy\TenantContext $context, int $id): void {
            self::makeSurgeryService($context)->startPreOp($id, self::ACTION_START_PRE_OP);
        }, _t('Surgery moved to pre-op'), 'checklist');
    }

    /**
     * pre_op → in_progress (SurgeryService::start): exige consentimento e
     * as fases sign_in e time_out confirmadas.
     */
    public static function onStart($param)
    {
        $id = self::paramInt('id', $param);

        self::runChange($id, function (\CentralVet\Tenancy\TenantContext $context, int $id): void {
            self::makeSurgeryService($context)->start($id, self::ACTION_START);
        }, _t('Surgery started'), 'materials');
    }

    /**
     * Confirmação do cancelamento: o motivo é obrigatório; confirmado, o
     * "Sim" reenvia o formulário do cancelamento por POST para onCancel().
     * O motivo (texto clínico) vai no corpo, nunca na URL nem no script.
     */
    public static function onAskCancel($param = null)
    {
        $id = (int) ($param['id'] ?? 0);

        if ($id <= 0 || self::postedReason() === '')
        {
            new TMessage('error', _t('The cancellation reason is required'));
            return;
        }

        $action = new TAction([__CLASS__, 'onCancel']);
        $action->setParameter('id', $id);
        $action->setParameter('static', '1');

        $yes = "function () { __adianti_post_data('" . self::CANCEL_FORM . "', '"
            . addslashes($action->serialize(false)) . "'); }";

        TScript::create(sprintf(
            "__adianti_question('%s', '%s', %s, function () {}, '%s', '%s')",
            addslashes(AdiantiCoreTranslator::translate('Question')),
            addslashes(_t('Cancel this surgery? To reschedule, schedule a new surgery from the encounter.')),
            $yes,
            addslashes(AdiantiCoreTranslator::translate('Yes')),
            addslashes(AdiantiCoreTranslator::translate('No'))
        ));
    }

    /**
     * Motivo do cancelamento só do corpo do POST: o mesmo campo na query
     * string é ignorado.
     */
    private static function postedReason(): string
    {
        return trim((string) ($_POST['cancellation_reason_text'] ?? ''));
    }

    /**
     * scheduled/pre_op → cancelled (SurgeryService::cancel) com o motivo
     * só do POST (ver onAskCancel()).
     */
    public static function onCancel($param)
    {
        $id = self::paramInt('id', $param);
        $reason = self::postedReason();

        if ($id === null || $reason === '')
        {
            new TMessage('error', _t('The cancellation reason is required'));
            return;
        }

        self::runChange($id, function (\CentralVet\Tenancy\TenantContext $context, int $id) use ($reason): void {
            self::makeSurgeryService($context)->cancel($id, $reason, self::ACTION_CANCEL);
        }, _t('Surgery cancelled'), 'events');
    }

    /**
     * Confirmação da conclusão: o TQuestion carrega só o id.
     */
    public static function onAskComplete($param = null)
    {
        $action = new TAction([__CLASS__, 'onComplete']);
        $action->setParameter('id', (int) ($param['id'] ?? 0));
        $action->setParameter('static', '1');

        new TQuestion(_t('Complete this surgery? The procedure and the recorded materials will be billed to the encounter account and the materials consumed from stock.'), $action);
    }

    /**
     * Conclusão integrada (SurgeryCompletionService::complete) num único
     * TTransaction('permission'): estoque insuficiente, conta fechada ou
     * checklist incompleto desfazem tudo e a cirurgia segue em andamento.
     */
    public static function onComplete($param)
    {
        $id = self::paramInt('id', $param);

        try
        {
            if ($id === null)
            {
                throw new InvalidArgumentException(_t('Invalid surgery'));
            }

            $context = self::resolveTenantContext();

            TTransaction::open('permission');

            $result = self::makeCompletionService($context)->complete($id, self::ACTION_COMPLETE);
            $surgery = self::makeSurgeryService($context)->get($id, self::ACTION_READ);

            TTransaction::close();

            new TMessage(
                'info',
                _t('Surgery completed')
                . '<br>' . _t('Items added to the account') . ': ' . (int) $result['items_added']
                . '<br>' . _t('Products consumed') . ': ' . (int) $result['consumed_products']
                . '<br><a href="' . CvFormat::e(self::accountUrl($surgery->encounterId())) . '">' . _t('Open encounter account') . '</a>',
                self::reloadAction($id, 'summary')
            );
        }
        catch (\CentralVet\Authorization\Exception\AuthorizationDenied $e)
        {
            TTransaction::rollback();
            new TMessage('error', _t('You are not allowed to change this surgery'));
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
     * Retorno com o cirurgião (SurgeryCompletionService::scheduleFollowUp):
     * data/hora e serviço só do POST do formulário de retorno.
     */
    public static function onScheduleFollowUp($param)
    {
        $id = self::paramInt('id', $param);

        try
        {
            $rawAt = trim((string) ($_POST['followup_scheduled_at'] ?? ''));
            $serviceId = (int) ($_POST['followup_service_id'] ?? 0);

            if ($id === null || $rawAt === '' || $serviceId <= 0)
            {
                throw new InvalidArgumentException(_t('Date/time and service id are required to schedule a follow-up'));
            }

            // estrito, como EncounterView::onScheduleFollowUp; fora do formato
            // InvalidArgumentException('Invalid date and time') → userError
            $scheduledAt = DateTimeInput::parse($rawAt);

            $context = self::resolveTenantContext();

            TTransaction::open('permission');
            self::makeCompletionService($context)->scheduleFollowUp($id, $serviceId, $scheduledAt, self::ACTION_FOLLOW_UP);
            TTransaction::close();

            new TMessage('info', _t('Follow-up scheduled successfully'), self::reloadAction($id, 'summary'));
        }
        catch (\CentralVet\Authorization\Exception\AuthorizationDenied $e)
        {
            TTransaction::rollback();
            new TMessage('error', _t('You are not allowed to change this surgery'));
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
     * Roda uma mudança de status num único TTransaction('permission') e
     * recarrega a ficha pelo OK; qualquer exceção dá rollback.
     *
     * @param callable(\CentralVet\Tenancy\TenantContext, int): void $change
     */
    private static function runChange(?int $id, callable $change, string $success, string $tab): void
    {
        try
        {
            if ($id === null)
            {
                throw new InvalidArgumentException(_t('Invalid surgery'));
            }

            $context = self::resolveTenantContext();

            TTransaction::open('permission');
            $change($context, $id);
            TTransaction::close();

            new TMessage('info', $success, self::reloadAction($id, $tab));
        }
        catch (\CentralVet\Authorization\Exception\AuthorizationDenied $e)
        {
            TTransaction::rollback();
            new TMessage('error', _t('You are not allowed to change this surgery'));
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
     * Lê tudo o que a ficha mostra numa transação só leitura. A primeira
     * chamada é get(): de outra unidade, AuthorizationDenied antes de
     * qualquer dado do paciente.
     *
     * @return array<string, mixed>|null
     */
    private function loadData(int $id): ?array
    {
        try
        {
            $context = self::resolveTenantContext();

            TTransaction::open('permission');
            $connection = TTransaction::get();

            $surgeryService = self::makeSurgeryService($context);

            $surgery = $surgeryService->get($id, self::ACTION_READ);
            $team = $surgeryService->listTeam($id, self::ACTION_READ);
            $events = $surgeryService->listEvents($id, self::ACTION_READ);
            $phases = self::makeChecklistService($context)->phaseStatus($id, self::ACTION_READ);
            $materials = self::makeMaterialService($context)->listMaterials($id, self::ACTION_READ);

            $patient = (new \CentralVet\Persistence\PatientRepository($context, $connection))
                ->findById($surgery->patientId());

            $room = (new \CentralVet\Persistence\SurgeryRoomRepository($context, $connection))
                ->findById($surgery->roomId());

            $services = [];
            if ($surgery->status() === \CentralVet\Domain\Surgery::STATUS_COMPLETED && $surgery->followupAppointmentId() === null)
            {
                $services = (new \CentralVet\Persistence\ServiceRepository($context, $connection))->listActive();
            }

            $userIds = [$surgery->surgeonSystemUserId()];
            foreach ($team as $member)
            {
                $userIds[] = $member->systemUserId();
            }

            $users = [];
            foreach (array_unique($userIds) as $userId)
            {
                try
                {
                    $user = SystemUser::findInTransaction('permission', $userId);
                    if ($user)
                    {
                        $users[(int) $userId] = (string) $user->name;
                    }
                }
                catch (Exception $e)
                {
                    // rótulo de exibição: nunca bloqueia a ficha
                }
            }

            TTransaction::close();

            return [
                'surgery'      => $surgery,
                'team'         => $team,
                'events'       => $events,
                'phases'       => $phases,
                'materials'    => $materials,
                'patient_name' => $patient !== null ? (string) $patient->name : null,
                'room_label'   => $room !== null ? $room->code() . ' — ' . $room->name() : null,
                'surgeon'      => $users[$surgery->surgeonSystemUserId()] ?? null,
                'users'        => $users,
                'services'     => $services,
            ];
        }
        catch (\CentralVet\Authorization\Exception\AuthorizationDenied $e)
        {
            TTransaction::rollback();
            new TMessage('error', _t('You are not allowed to access this surgery'));
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

        return null;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, string> $back
     */
    private function buildHeader(array $data, array $back): TElement
    {
        /** @var \CentralVet\Domain\Surgery $s */
        $s = $data['surgery'];

        $title = $data['patient_name'] ?? (_t('Surgery') . ' #' . (int) $s->id());

        $parts = [
            _t('Procedure') . ': ' . $s->procedureName(),
            _t('Room') . ': ' . ($data['room_label'] ?? ('#' . $s->roomId())),
            _t('Scheduled for') . ': ' . $s->scheduledStartAt()->format('d/m/Y H:i') . ' – ' . $s->scheduledEndAt()->format('H:i'),
            _t('Surgeon') . ': ' . ($data['surgeon'] ?? '—'),
        ];

        return CvPage::header($title, implode(' · ', $parts), [self::statusBadge($s->status()), $back]);
    }

    /**
     * Resumo: equipe, consentimento e notas do agendamento.
     *
     * @param array<string, mixed> $data
     */
    private function buildSummaryPanel(array $data): TElement
    {
        /** @var \CentralVet\Domain\Surgery $s */
        $s = $data['surgery'];
        $id = (int) $s->id();
        $open = $s->isOpenForPreOp();
        $users = $data['users'] ?? [];

        $wrap = new TElement('div');

        // equipe
        $teamPanel = new TPanelGroup(_t('Surgical team'));
        $teamPanel->class = 'cv-section';

        if ($open)
        {
            $teamPanel->add(self::linkButton(_t('Team'), 'index.php?class=SurgeryScheduleForm&id=' . $id, 'fa:users', 'btn btn-default'));
        }

        if ($data['team'] === [])
        {
            $teamPanel->add(self::statePanel('empty', _t('No team members recorded.')));
        }
        else
        {
            $table = self::table([_t('Role'), _t('Name')]);

            /** @var \CentralVet\Domain\SurgeryTeamMember $member */
            foreach ($data['team'] as $member)
            {
                self::addRow($table, [
                    CvFormat::e(self::roleLabel($member->role())),
                    CvFormat::e($users[$member->systemUserId()] ?? ('#' . $member->systemUserId())),
                ]);
            }

            $teamPanel->add($table);
        }

        $wrap->add($teamPanel);

        // consentimento
        $consentPanel = new TPanelGroup(_t('Consent'));
        $consentPanel->class = 'cv-section';

        if ($s->hasConsent())
        {
            $consentPanel->add(TElement::tag('p', CvFormat::e(
                _t('Signed by') . ': ' . (string) $s->consentSignerName()
                . ' · ' . ($s->consentRecordedAt() !== null ? $s->consentRecordedAt()->format('d/m/Y H:i') : '—')
            ), ['style' => 'margin:0 0 var(--cv-space-2)']));
            $consentPanel->add(CvBadge::create(_t('Consent recorded'), 'success'));
        }
        else
        {
            $consentPanel->add(self::statePanel('empty', _t('No consent recorded yet.')));
        }

        if ($open)
        {
            $consentPanel->add(self::linkButton(
                $s->hasConsent() ? _t('Update consent') : _t('Record consent'),
                'index.php?class=SurgeryConsentForm&surgery_id=' . $id,
                'fa:file-signature',
                'btn btn-primary'
            ));
        }

        $wrap->add($consentPanel);

        if ($s->notesText() !== null)
        {
            $notesPanel = new TPanelGroup(_t('Notes'));
            $notesPanel->class = 'cv-section';
            $notesPanel->add(TElement::tag('p', nl2br(CvFormat::e($s->notesText())), ['style' => 'margin:0']));
            $wrap->add($notesPanel);
        }

        return $wrap;
    }

    /**
     * Checklist: as 3 fases com badge e link para confirmar a fase quando o
     * status permite (sign_in/time_out em pré-op, sign_out em andamento).
     *
     * @param array<string, mixed> $data
     */
    private function buildChecklistPanel(array $data): TPanelGroup
    {
        /** @var \CentralVet\Domain\Surgery $s */
        $s = $data['surgery'];
        $id = (int) $s->id();

        $panel = new TPanelGroup(_t('Checklist'));
        $panel->class = 'cv-section';

        $table = self::table([_t('Phase'), _t('Status'), _t('Confirmed at'), '']);

        foreach (\CentralVet\Domain\SurgeryChecklist::PHASES as $phase)
        {
            $state = $data['phases'][$phase] ?? ['confirmed' => false, 'checked_at' => null];
            $confirmed = (bool) ($state['confirmed'] ?? false);

            $allowed = $phase === \CentralVet\Domain\SurgeryChecklist::PHASE_SIGN_OUT
                ? $s->status() === \CentralVet\Domain\Surgery::STATUS_IN_PROGRESS
                : $s->status() === \CentralVet\Domain\Surgery::STATUS_PRE_OP;

            $action = new TElement('span');
            if ($allowed && !$confirmed)
            {
                $action->add(self::linkButton(
                    _t('Confirm'),
                    'index.php?class=SurgeryChecklistForm&surgery_id=' . $id . '&phase=' . $phase,
                    'fa:clipboard-check',
                    'btn btn-primary'
                ));
            }

            $checkedAt = $state['checked_at'] ?? null;

            self::addRow($table, [
                CvFormat::e(_t(\CentralVet\Domain\SurgeryChecklist::phaseLabel($phase))),
                $confirmed ? CvBadge::create(_t('Confirmed'), 'success') : CvBadge::create(_t('Pending'), 'neutral'),
                CvFormat::e($checkedAt instanceof DateTimeInterface ? $checkedAt->format('d/m/Y H:i') : '—'),
                $action,
            ]);
        }

        $panel->add($table);

        return $panel;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function buildMaterialsPanel(array $data): TPanelGroup
    {
        /** @var \CentralVet\Domain\Surgery $s */
        $s = $data['surgery'];

        $panel = new TPanelGroup(_t('Materials'));
        $panel->class = 'cv-section';

        if ($s->status() === \CentralVet\Domain\Surgery::STATUS_IN_PROGRESS)
        {
            $panel->add(self::linkButton(
                _t('Record materials'),
                'index.php?class=SurgeryMaterialForm&surgery_id=' . (int) $s->id(),
                'fa:box-open',
                'btn btn-primary'
            ));
        }

        if ($data['materials'] === [])
        {
            $panel->add(self::statePanel('empty', _t('No materials recorded yet.')));
            return $panel;
        }

        $table = self::table([_t('Product'), _t('Quantity'), _t('Recorded at')]);

        foreach ($data['materials'] as $row)
        {
            /** @var \CentralVet\Domain\SurgeryMaterial $material */
            $material = $row['material'];

            self::addRow($table, [
                CvFormat::e((string) $row['product_name']),
                CvFormat::e((string) $material->quantity()),
                CvFormat::e($material->recordedAt()->format('d/m/Y H:i')),
            ]);
        }

        $panel->add($table);

        return $panel;
    }

    /**
     * Linha do tempo de listEvents() (mais recente primeiro) e links para
     * registrar eventos clínicos.
     *
     * @param array<string, mixed> $data
     */
    private function buildEventsPanel(array $data): TPanelGroup
    {
        /** @var \CentralVet\Domain\Surgery $s */
        $s = $data['surgery'];

        $panel = new TPanelGroup(_t('Events'));
        $panel->class = 'cv-section';

        if ($s->status() !== \CentralVet\Domain\Surgery::STATUS_CANCELLED)
        {
            $base = 'index.php?class=SurgeryEventForm&surgery_id=' . (int) $s->id() . '&type=';
            $buttons = new TElement('div');
            $buttons->style = 'display:flex; gap:var(--cv-space-2); flex-wrap:wrap; margin-bottom:var(--cv-space-3)';
            foreach (\CentralVet\Domain\SurgeryEvent::CLINICAL_TYPES as $type)
            {
                $buttons->add(self::linkButton(self::eventLabel($type), $base . $type, 'fa:notes-medical', 'btn btn-default'));
            }
            $panel->add($buttons);
        }

        if ($data['events'] === [])
        {
            $panel->add(self::statePanel('empty', _t('No events recorded yet.')));
            return $panel;
        }

        $list = new TElement('ol');
        $list->{'class'} = 'cv-timeline';
        $list->style = 'list-style:none; padding:0; margin:0';

        /** @var \CentralVet\Domain\SurgeryEvent $event */
        foreach ($data['events'] as $event)
        {
            $item = new TElement('li');
            $item->style = 'padding:var(--cv-space-2) 0; border-bottom:1px solid var(--cv-color-border)';

            $head = new TElement('div');
            $head->style = 'display:flex; gap:var(--cv-space-2); align-items:center';
            $head->add(CvBadge::create(self::eventLabel($event->eventType()), 'neutral'));
            $head->add(TElement::tag('time', CvFormat::e($event->recordedAt()->format('d/m/Y H:i')), [
                'datetime' => $event->recordedAt()->format('Y-m-d\TH:i'),
                'style'    => 'color:var(--cv-color-text-muted)',
            ]));
            $item->add($head);

            if ($event->notesText() !== null)
            {
                $notes = $event->eventType() === \CentralVet\Domain\SurgeryEvent::TYPE_STATUS
                    ? self::statusLabel($event->notesText())
                    : $event->notesText();
                $item->add(TElement::tag('p', nl2br(CvFormat::e($notes)), ['style' => 'margin:var(--cv-space-1) 0 0']));
            }

            $list->add($item);
        }

        $panel->add($list);

        return $panel;
    }

    /**
     * Coluna lateral com as ações do status atual.
     *
     * @param array<string, mixed> $data
     */
    private function buildActionsPanel(array $data): TElement
    {
        /** @var \CentralVet\Domain\Surgery $s */
        $s = $data['surgery'];
        $id = (int) $s->id();

        $side = new TElement('div');

        $panel = new TPanelGroup(_t('Actions'));
        $panel->class = 'cv-section';

        $buttons = new TElement('div');
        $buttons->style = 'display:flex; flex-direction:column; gap:var(--cv-space-2)';

        switch ($s->status())
        {
            case \CentralVet\Domain\Surgery::STATUS_SCHEDULED:
                $buttons->add(self::actionButton(_t('Start pre-op'), 'onStartPreOp', $id, 'fa:clipboard-list', 'btn btn-primary'));
                break;

            case \CentralVet\Domain\Surgery::STATUS_PRE_OP:
                $buttons->add(self::actionButton(_t('Start surgery'), 'onStart', $id, 'fa:play', 'btn btn-primary'));
                break;

            case \CentralVet\Domain\Surgery::STATUS_IN_PROGRESS:
                $buttons->add(self::actionButton(_t('Complete surgery'), 'onAskComplete', $id, 'fa:check', 'btn btn-primary'));
                break;

            case \CentralVet\Domain\Surgery::STATUS_COMPLETED:
                $buttons->add(self::linkButton(
                    _t('Admit for post-operative care'),
                    'index.php?class=HospitalizationAdmissionForm&encounter_id=' . (int) $s->encounterId() . '&patient_id=' . (int) $s->patientId(),
                    'fa:procedures',
                    'btn btn-primary'
                ));
                $buttons->add(self::linkButton(
                    _t('Open encounter account'),
                    self::accountUrl($s->encounterId()),
                    'fa:file-invoice-dollar',
                    'btn btn-default'
                ));
                break;
        }

        $panel->add($buttons);

        if ($s->status() === \CentralVet\Domain\Surgery::STATUS_COMPLETED)
        {
            $panel->add(TElement::tag('p', CvFormat::e(
                _t('Completed at') . ': ' . ($s->completedAt() !== null ? $s->completedAt()->format('d/m/Y H:i') : '—')
                . ' · ' . _t('Materials') . ': ' . count($data['materials'])
            ), ['style' => 'margin:var(--cv-space-2) 0 0']));
        }

        if ($s->status() === \CentralVet\Domain\Surgery::STATUS_CANCELLED)
        {
            $panel->add(TElement::tag('p', CvFormat::e(
                _t('Cancelled at') . ': ' . ($s->cancelledAt() !== null ? $s->cancelledAt()->format('d/m/Y H:i') : '—')
            ), ['style' => 'margin:0']));
            if ($s->cancellationReasonText() !== null)
            {
                $panel->add(TElement::tag('p', nl2br(CvFormat::e($s->cancellationReasonText())), ['style' => 'margin:var(--cv-space-1) 0 0']));
            }
        }

        $side->add($panel);

        if ($s->isOpenForPreOp())
        {
            $side->add($this->buildCancelForm($id));
        }

        if ($s->status() === \CentralVet\Domain\Surgery::STATUS_COMPLETED)
        {
            if ($s->followupAppointmentId() !== null)
            {
                $followUp = new TPanelGroup(_t('Follow-up'));
                $followUp->class = 'cv-section';
                $followUp->add(CvBadge::create(_t('Follow-up scheduled'), 'success'));
                $side->add($followUp);
            }
            else
            {
                $side->add($this->buildFollowUpForm($id, $data['services'] ?? []));
            }
        }

        return $side;
    }

    private function buildCancelForm(int $id): BootstrapFormBuilder
    {
        $form = new BootstrapFormBuilder(self::CANCEL_FORM);
        $form->setFormTitle(_t('Cancel surgery'));

        $reason = new TText('cancellation_reason_text');
        $reason->setSize('100%', 80);
        $reason->setProperty('maxlength', '2000');

        $form->addFields([new TLabel(_t('Cancellation reason'))]);
        $form->addFields([$reason]);

        $action = new TAction([__CLASS__, 'onAskCancel']);
        $action->setParameter('id', $id);
        $action->setParameter('static', '1');
        $button = $form->addAction(_t('Cancel surgery'), $action, 'fa:ban');
        $button->class = 'btn btn-default cv-touch-target';
        $button->style = self::TOUCH;

        CvForm::decorate($form, 1);

        return $form;
    }

    /**
     * @param list<\CentralVet\Domain\Service> $services
     */
    private function buildFollowUpForm(int $id, array $services): BootstrapFormBuilder
    {
        $form = new BootstrapFormBuilder(self::FOLLOW_UP_FORM);
        $form->setFormTitle(_t('Follow-up'));

        // TEntry com máscara, sem TDateTime (ver EncounterView::followUpCard)
        $at = new TEntry('followup_scheduled_at');
        $at->setMask('99/99/9999 99:99');
        $at->placeholder = 'dd/mm/aaaa hh:mm';
        $at->setProperty('inputmode', 'numeric');
        $at->setProperty('autocomplete', 'off');
        $at->setSize('100%');

        $options = [];
        foreach ($services as $service)
        {
            $options[(int) $service->id()] = $service->name();
        }

        $service = new TCombo('followup_service_id');
        $service->addItems($options);
        $service->setSize('100%');

        $form->addFields([new TLabel(_t('Date/time'))]);
        $form->addFields([$at]);
        $form->addFields([new TLabel(_t('Service'))]);
        $form->addFields([$service]);

        $action = new TAction([__CLASS__, 'onScheduleFollowUp']);
        $action->setParameter('id', $id);
        $action->setParameter('static', '1');
        $button = $form->addAction(_t('Schedule follow-up'), $action, 'fa:calendar-plus');
        $button->class = 'btn btn-primary cv-touch-target';
        $button->style = self::TOUCH;

        CvForm::decorate($form, 1);

        return $form;
    }

    private static function actionButton(string $label, string $method, int $id, string $icon, string $class): TElement
    {
        $action = new TAction([__CLASS__, $method]);
        $action->setParameter('id', $id);
        $action->setParameter('static', '1');

        return self::linkButton($label, $action->serialize(true), $icon, $class);
    }

    private static function accountUrl(int $encounterId): string
    {
        return 'index.php?class=EncounterAccountForm&encounter_id=' . $encounterId;
    }

    private static function statusBadge(string $status): TElement
    {
        return match ($status) {
            \CentralVet\Domain\Surgery::STATUS_SCHEDULED   => CvBadge::create(self::statusLabel($status), 'neutral'),
            \CentralVet\Domain\Surgery::STATUS_PRE_OP      => CvBadge::create(self::statusLabel($status), 'warning'),
            \CentralVet\Domain\Surgery::STATUS_IN_PROGRESS => CvBadge::create(self::statusLabel($status), 'info'),
            \CentralVet\Domain\Surgery::STATUS_COMPLETED   => CvBadge::create(self::statusLabel($status), 'success'),
            default                                         => CvBadge::create(self::statusLabel($status), 'danger'),
        };
    }

    private static function statusLabel(string $status): string
    {
        return match ($status) {
            \CentralVet\Domain\Surgery::STATUS_SCHEDULED   => _t('Scheduled'),
            \CentralVet\Domain\Surgery::STATUS_PRE_OP      => _t('Pre-op'),
            \CentralVet\Domain\Surgery::STATUS_IN_PROGRESS => _t('In progress'),
            \CentralVet\Domain\Surgery::STATUS_COMPLETED   => _t('Completed'),
            \CentralVet\Domain\Surgery::STATUS_CANCELLED   => _t('Cancelled'),
            default                                         => $status,
        };
    }

    private static function roleLabel(string $role): string
    {
        return match ($role) {
            \CentralVet\Domain\SurgeryTeamMember::ROLE_SURGEON     => _t('Surgeon'),
            \CentralVet\Domain\SurgeryTeamMember::ROLE_ANESTHETIST => _t('Anesthetist'),
            \CentralVet\Domain\SurgeryTeamMember::ROLE_ASSISTANT   => _t('Assistant'),
            \CentralVet\Domain\SurgeryTeamMember::ROLE_CIRCULATING => _t('Circulating nurse'),
            default                                                => $role,
        };
    }

    private static function eventLabel(string $type): string
    {
        return match ($type) {
            \CentralVet\Domain\SurgeryEvent::TYPE_PRE_OP       => _t('Pre-op'),
            \CentralVet\Domain\SurgeryEvent::TYPE_ANESTHESIA   => _t('Anesthesia'),
            \CentralVet\Domain\SurgeryEvent::TYPE_INTRA_OP     => _t('Intra-op'),
            \CentralVet\Domain\SurgeryEvent::TYPE_COMPLICATION => _t('Complication'),
            \CentralVet\Domain\SurgeryEvent::TYPE_POST_OP      => _t('Post-op'),
            \CentralVet\Domain\SurgeryEvent::TYPE_SCHEDULED    => _t('Scheduled'),
            \CentralVet\Domain\SurgeryEvent::TYPE_CONSENT      => _t('Consent'),
            \CentralVet\Domain\SurgeryEvent::TYPE_CHECKLIST    => _t('Checklist'),
            \CentralVet\Domain\SurgeryEvent::TYPE_STATUS       => _t('Status'),
            \CentralVet\Domain\SurgeryEvent::TYPE_MATERIAL     => _t('Material'),
            \CentralVet\Domain\SurgeryEvent::TYPE_CANCELLATION => _t('Cancellation'),
            \CentralVet\Domain\SurgeryEvent::TYPE_COMPLETION   => _t('Completion'),
            \CentralVet\Domain\SurgeryEvent::TYPE_FOLLOWUP     => _t('Follow-up'),
            default                                            => $type,
        };
    }

    private static function statePanel(string $state, string $message): TElement
    {
        $box = new TElement('div');
        $box->{'class'} = 'cv-state cv-state--' . $state;
        $box->style = 'padding:var(--cv-space-4); text-align:center; color:var(--cv-color-text-muted)';
        $box->add(TElement::tag('p', CvFormat::e($message), ['style' => 'margin:0']));

        return $box;
    }

    private static function linkButton(string $label, string $href, string $icon, string $class): TElement
    {
        $link = new TElement('a');
        $link->{'class'} = $class . ' cv-touch-target';
        $link->{'href'} = CvFormat::e($href);
        $link->{'generator'} = 'adianti';
        $link->style = self::TOUCH . '; display:inline-flex; align-items:center; gap:var(--cv-space-1)';
        $link->add(new TImage($icon));
        $link->add(TElement::tag('span', CvFormat::e($label), []));

        return $link;
    }

    /**
     * @param list<string> $headers
     */
    private static function table(array $headers): TElement
    {
        $table = new TElement('table');
        $table->{'class'} = 'table table-sm align-middle';

        $head = new TElement('thead');
        $row = new TElement('tr');
        foreach ($headers as $header)
        {
            $row->add(TElement::tag('th', CvFormat::e($header), ['scope' => 'col']));
        }
        $head->add($row);
        $table->add($head);

        $body = new TElement('tbody');
        $table->add($body);

        return $table;
    }

    /**
     * @param list<string|TElement> $cells conteúdo já escapado ou widget
     */
    private static function addRow(TElement $table, array $cells): void
    {
        $row = new TElement('tr');
        foreach ($cells as $cell)
        {
            $td = new TElement('td');
            $td->add($cell);
            $row->add($td);
        }

        $children = $table->getChildren();
        end($children)->add($row);
    }

    private static function reloadAction(int $id, string $tab): TAction
    {
        $action = new TAction([__CLASS__, 'onReload']);
        $action->setParameter('id', $id);
        $action->setParameter('tab', $tab);

        return $action;
    }

    private static function paramInt(string $name, $param): ?int
    {
        if (isset($_GET[$name]) && $_GET[$name] !== '' && (int) $_GET[$name] > 0)
        {
            return (int) $_GET[$name];
        }

        if (is_array($param) && isset($param[$name]) && $param[$name] !== '' && (int) $param[$name] > 0)
        {
            return (int) $param[$name];
        }

        return null;
    }

    /**
     * RBAC real (Fase 0) com auditoria na mesma conexão. Exige um
     * TTransaction('permission') aberto.
     */
    private static function makeAuthorization(): \CentralVet\Authorization\RbacAuthorizationService
    {
        return new \CentralVet\Authorization\RbacAuthorizationService(
            new \CentralVet\Authorization\AdiantiProgramPermissionProvider(new \CentralVet\Tenancy\AdiantiSessionContextSource()),
            new \CentralVet\Audit\PdoAuditLogWriter(TTransaction::get()),
        );
    }

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
            self::makeAuthorization(),
            $context,
        );
    }

    private static function makeChecklistService(\CentralVet\Tenancy\TenantContext $context): \CentralVet\Application\SurgeryChecklistService
    {
        $connection = TTransaction::get();

        return new \CentralVet\Application\SurgeryChecklistService(
            new \CentralVet\Persistence\SurgeryRepository($context, $connection),
            new \CentralVet\Persistence\SurgeryChecklistRepository($context, $connection),
            new \CentralVet\Persistence\SurgeryEventRepository($context, $connection),
            self::makeAuthorization(),
            $context,
        );
    }

    private static function makeMaterialService(\CentralVet\Tenancy\TenantContext $context): \CentralVet\Application\SurgeryMaterialService
    {
        $connection = TTransaction::get();

        return new \CentralVet\Application\SurgeryMaterialService(
            new \CentralVet\Persistence\SurgeryRepository($context, $connection),
            new \CentralVet\Persistence\SurgeryMaterialRepository($context, $connection),
            new \CentralVet\Persistence\SurgeryEventRepository($context, $connection),
            new \CentralVet\Persistence\ProductRepository($context, $connection),
            self::makeAuthorization(),
            $context,
        );
    }

    /**
     * Conclusão integrada: cirurgia + conta do atendimento (montada como no
     * EncounterAccountForm) + estoque + agenda, todos na mesma conexão.
     */
    private static function makeCompletionService(\CentralVet\Tenancy\TenantContext $context): \CentralVet\Application\SurgeryCompletionService
    {
        $connection = TTransaction::get();
        $authorization = self::makeAuthorization();

        $accounts = new \CentralVet\Application\EncounterAccountService(
            new \CentralVet\Persistence\EncounterAccountRepository($context, $connection),
            new \CentralVet\Persistence\EncounterAccountItemRepository($context, $connection),
            new \CentralVet\Persistence\ReceivableRepository($context, $connection),
            new \CentralVet\Persistence\EncounterRepository($context, $connection),
            new \CentralVet\Persistence\PatientRepository($context, $connection),
            new \CentralVet\Persistence\ProcedureExecutionRepository($context, $connection),
            new \CentralVet\Persistence\ExamRequestRepository($context, $connection),
            new \CentralVet\Application\ProcedureCatalogService(
                new \CentralVet\Persistence\ProcedureCatalogRepository($context, $connection),
                new \CentralVet\Persistence\ProcedureCatalogItemInputRepository($context, $connection),
                $context
            ),
            new \CentralVet\Persistence\ExamCatalogRepository($context, $connection),
            $authorization,
            $context,
            new \CentralVet\Persistence\TenantUserDirectory($context, $connection),
        );

        $stock = new \CentralVet\Application\StockService(
            new \CentralVet\Persistence\StockBatchRepository($context, $connection),
            new \CentralVet\Persistence\StockMovementRepository($context, $connection),
            $authorization,
            $context
        );

        $appointments = new \CentralVet\Application\AppointmentService(
            new \CentralVet\Persistence\AppointmentRepository($context, $connection),
            new \CentralVet\Persistence\ServiceRepository($context, $connection),
            new \CentralVet\Application\PatientService(
                new \CentralVet\Persistence\PatientRepository($context, $connection),
                new \CentralVet\Persistence\TutorRepository($context, $connection),
                $context
            ),
            $context,
            $authorization
        );

        return new \CentralVet\Application\SurgeryCompletionService(
            new \CentralVet\Persistence\SurgeryRepository($context, $connection),
            new \CentralVet\Persistence\SurgeryChecklistRepository($context, $connection),
            new \CentralVet\Persistence\SurgeryMaterialRepository($context, $connection),
            new \CentralVet\Persistence\SurgeryEventRepository($context, $connection),
            new \CentralVet\Persistence\ProductRepository($context, $connection),
            $accounts,
            $stock,
            $appointments,
            $authorization,
            $context,
        );
    }

    /**
     * Contexto de tenant da sessão autenticada, com o mesmo fallback de
     * EncounterAccountForm::resolveTenantContext() para sessões legadas.
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
