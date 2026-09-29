<?php
/**
 * EncounterView
 *
 * Central de Atendimento — tela unica de consulta clinica (mock 04,
 * artboard "Atendimento"), consumindo exclusivamente os Application
 * services do Core: CentralVet\Application\EncounterService (T-03) para
 * abrir/autosalvar/finalizar o atendimento e aceitar o resumo de IA,
 * CentralVet\Assistant\NullAiClinicalAssistant (T-04) para o resumo/
 * sugestoes, CentralVet\Application\EncounterDocumentService (T-05) para
 * anexar documentos e, da Fase 1, CentralVet\Application\AppointmentService
 * (acao "Retorno") e CentralVet\Application\ServiceCatalogService (preco no
 * resumo financeiro). Nenhuma regra de negocio (autorizacao por unidade,
 * transicao de status, conflito de agenda) vive aqui: tudo delega para os
 * Application services.
 *
 * Entrada: a tela aceita `encounter_id` (atendimento ja iniciado) OU
 * `patient_id` (+ `appointment_id`/`service_id` opcionais) para iniciar um
 * novo atendimento. Quando recebe os parametros de inicio, chama
 * EncounterService::start() com action = 'EncounterView::onStart' e
 * redireciona o navegador (via __adianti_goto_page, TScript) para si mesma
 * com o encounter_id recem-criado — nunca segue construindo a tela com o
 * id antigo/ausente.
 *
 * Resumo financeiro: o `service_id` usado aqui e' derivado diretamente do
 * agendamento de origem do atendimento — `Encounter::appointmentId()`,
 * resolvido via `AppointmentService::findById()` (passthrough tenant-scoped
 * para `AppointmentRepositoryInterface::findById()`) para obter o
 * `Appointment` e, dali, o `serviceId` real usado para consultar
 * `ServiceCatalogService::findById()`. Nenhum valor de `service_id` e' lido
 * de TSession ou de parametro de URL para esse fim. Quando
 * `appointmentId()` e' null (atendimento walk-in, sem agendamento previo),
 * o resumo financeiro fica vazio — gap esperado, no mesmo espirito do
 * list() sempre-vazio de EncounterDocumentService (T-05).
 *
 * Prescricao/Exame/Vacina (T-09) abrem, cada uma, sua tela dedicada —
 * PrescriptionForm (T-06), ExamRequestForm (T-07) e VaccinationForm (T-08),
 * respectivamente — via navegacao client-side (__adianti_goto_page),
 * passando `encounter_id`/`patient_id` como parametro. Procedimento
 * permanece apenas casca de UI nesta fase (decisao do usuario, ver
 * plan.md): o clique grava, no maximo, um evento em `audit_log` via
 * CentralVet\Audit\PdoAuditLogWriter + CentralVet\Audit\AuditEvent
 * diretamente (uso explicitamente autorizado pela task, ja que nao existe
 * um Application service dedicado a essa acao nesta fase) — nenhuma tabela
 * propria e criada ou gravada.
 *
 * PENDING: a tabela `encounter` e' criada pela migration ainda nao aplicada
 * src/app/database/migrations/20260922_0003_phase2_encounter.sql (T-01).
 * Esta classe e' preparada e validada apenas com `php -l` /
 * `new EncounterView()` (sem parametros, sem erro fatal); qualquer falha de
 * banco ao navegar ate' aqui antes da migration e' capturada e exibida como
 * TMessage, nunca como erro fatal.
 *
 * @version    8.6
 * @package    control
 * @subpackage clinic
 * @license    https://adiantiframework.com.br/license-template
 */
class EncounterView extends TPage
{
    /**
     * Draft field names accepted by EncounterService::autosave(), matching
     * CentralVet\Domain\Encounter::applyDraft() 1:1. Also the exact set of
     * `[name="..."]` selectors the autosave client script reads on every
     * tick (see clinicalForm()).
     */
    private const DRAFT_FIELDS = [
        'anamnesis_text',
        'temperature_c',
        'heart_rate_bpm',
        'respiratory_rate_mpm',
        'weight_kg',
        'mucous_membranes',
        'capillary_refill_seconds',
        'physical_exam_text',
        'diagnosis_text',
        'clinical_plan_text',
    ];

    /**
     * Page constructor.
     *
     * Reads encounter_id / patient_id / appointment_id / service_id from
     * $_GET (falling back to $param for consistency with other pages in
     * this package). `new EncounterView()` with none of these set (the
     * validation command for this task) must never throw — it renders the
     * empty state below instead.
     */
    public function __construct($param = null)
    {
        parent::__construct();

        $encounterId = self::paramInt('encounter_id', $param);
        $patientId = self::paramInt('patient_id', $param);
        $appointmentId = self::paramInt('appointment_id', $param);
        $serviceId = self::paramInt('service_id', $param);

        // No TXMLBreadCrumb here on purpose: registering EncounterView in
        // menu.xml is explicitly T-07's job, not T-06's (see aang.md's
        // restriction), so this screen is not listed there yet. Mirrors
        // AppointmentForm.php, itself only registered in a later task, which
        // omits TXMLBreadCrumb for the exact same reason.
        $container = new TVBox;
        $container->style = 'width: 100%';

        // page header (design system: .cv-page-header/.cv-page-title, T-06)
        $pageHeader = new TElement('header');
        $pageHeader->class = 'cv-page-header';
        $pageHeaderTitleWrap = new TElement('div');
        $pageHeaderTitle = new TElement('h1');
        $pageHeaderTitle->class = 'cv-page-title';
        $pageHeaderTitle->add(_t('Encounter'));
        $pageHeaderTitleWrap->add($pageHeaderTitle);
        $pageHeader->add($pageHeaderTitleWrap);
        $container->add($pageHeader);

        if ($encounterId === null && $patientId !== null)
        {
            $newId = $this->startEncounter($patientId, $appointmentId, $serviceId);

            if ($newId !== null)
            {
                $query = "class=EncounterView&encounter_id={$newId}";

                if ($serviceId !== null)
                {
                    $query .= "&service_id={$serviceId}";
                }

                TScript::create("__adianti_goto_page('index.php?{$query}')");
                parent::add($container);
                return;
            }

            // start() refused (TMessage already shown by startEncounter()):
            // fall through to the empty state below instead of a fatal error.
        }

        if ($encounterId === null)
        {
            $container->add($this->emptyStatePanel());
            parent::add($container);
            return;
        }

        $data = $this->loadEncounterData($encounterId, $serviceId);

        if ($data === null)
        {
            $container->add($this->emptyStatePanel());
            parent::add($container);
            return;
        }

        $container->add($this->contextStrip($data));

        $columns = new TElement('div');
        $columns->style = 'display:flex; gap:var(--cv-space-4); align-items:flex-start; flex-wrap:wrap';

        $left = new TElement('div');
        $left->style = 'flex:1 1 260px; min-width:240px; display:flex; flex-direction:column; gap:var(--cv-space-4)';
        $left->add($this->timelinePanel($data));

        $center = new TElement('div');
        $center->style = 'flex:2 1 420px; min-width:320px; display:flex; flex-direction:column; gap:var(--cv-space-4)';
        $center->add($this->aiPanel($data));
        $center->add($this->suggestionsPanel($data));
        $center->add($this->clinicalForm($data));

        $right = new TElement('div');
        $right->style = 'flex:1 1 260px; min-width:260px; display:flex; flex-direction:column; gap:var(--cv-space-4)';
        $right->add($this->inlineActionsPanel($data));
        $right->add($this->financePanel($data));

        $columns->add($left);
        $columns->add($center);
        $columns->add($right);

        $container->add($columns);

        parent::add($container);
    }

    /**
     * Empty state shown both for `new EncounterView()` (no parameters at
     * all) and for an encounter_id that failed to load.
     */
    private function emptyStatePanel(): TPanelGroup
    {
        $panel = new TPanelGroup(_t('Encounter'));
        $panel->class = 'cv-section';
        $panel->add('<p>' . _t('Provide an encounter_id to resume an encounter, or a patient_id (optionally with appointment_id/service_id) to start a new one.') . '</p>');

        return $panel;
    }

    /**
     * Starts a new encounter via EncounterService::start(), action string
     * literally 'EncounterView::onStart' per the task's acceptance
     * criterion. The professional is always the authenticated user
     * (TSession's userid) — this screen has no "attending professional"
     * picker, consistent with a single clinician using their own session to
     * open an encounter.
     *
     * @return int|null the new encounter id, or null when start() refused
     *         the request (a TMessage has already been shown in that case).
     */
    private function startEncounter(int $patientId, ?int $appointmentId, ?int $serviceId): ?int
    {
        try
        {
            $context = self::resolveTenantContext();

            TTransaction::open('permission');

            $service = self::makeEncounterService($context);

            $encounter = $service->start([
                'patient_id' => $patientId,
                'professional_system_user_id' => (int) TSession::getValue('userid'),
                'system_unit_id' => $context->requireUnitId(),
                'appointment_id' => $appointmentId,
            ], 'EncounterView::onStart');

            TTransaction::close();

            return $encounter->id();
        }
        catch (\CentralVet\Authorization\Exception\AuthorizationDenied $e)
        {
            TTransaction::rollback();
            new TMessage('error', _t('You are not allowed to start an encounter for this unit'));
        }
        catch (\CentralVet\Domain\Exception\CrossTenantReferenceException $e)
        {
            TTransaction::rollback();
            new TMessage('error', $e->getMessage());
        }
        catch (\CentralVet\Tenancy\Exception\MissingTenantContext $e)
        {
            TTransaction::rollback();
            new TMessage('error', _t('An authenticated session with an active unit is required'));
        }
        catch (Exception $e)
        {
            TTransaction::rollback();
            new TMessage('error', $e->getMessage());
        }

        return null;
    }

    /**
     * Loads everything the screen needs to render an in-progress or
     * finished encounter: the aggregate itself (EncounterService::findById()),
     * display names for patient/tutor/professional/unit (best-effort,
     * mirroring AgendaView/QueueEntryView's own name-resolution pattern:
     * PatientService::findById() + SystemUser::findInTransaction(), never a
     * failure here blocks the rest of the screen), the AI summary/
     * suggestions (NullAiClinicalAssistant), the audit timeline
     * (EncounterService::timeline()) and the financial summary (see the
     * class docblock's documented limitation on service_id).
     *
     * @return array<string, mixed>|null null when the encounter does not
     *         exist for this tenant or a business-rule exception was
     *         refused (a TMessage has already been shown in that case).
     */
    private function loadEncounterData(int $encounterId, ?int $serviceIdParam): ?array
    {
        try
        {
            $context = self::resolveTenantContext();

            TTransaction::open('permission');

            $service = self::makeEncounterService($context);
            $encounter = $service->findById($encounterId);

            if ($encounter === null)
            {
                TTransaction::close();
                new TMessage('error', _t('Encounter not found'));
                return null;
            }

            $patient = null;
            $tutorName = null;

            try
            {
                $patientService = self::makePatientService($context);
                $patient = $patientService->findById($encounter->patientId());

                if ($patient !== null)
                {
                    $tutorService = self::makeTutorService($context);
                    $tutor = $tutorService->findById((int) $patient->tutorId);
                    $tutorName = $tutor !== null ? $tutor->fullName : null;
                }
            }
            catch (Exception $e)
            {
                // Name resolution is best-effort for display only — never
                // blocks the rest of the screen from rendering.
            }

            $professional = null;
            $unit = null;

            try
            {
                $professional = SystemUser::findInTransaction('permission', $encounter->professionalSystemUserId());
            }
            catch (Exception $e)
            {
            }

            try
            {
                $unit = SystemUnit::findInTransaction('permission', $encounter->systemUnitId());
            }
            catch (Exception $e)
            {
            }

            $assistant = new \CentralVet\Assistant\NullAiClinicalAssistant();
            $aiSummary = $assistant->summarizePatientHistory($encounter->patientId());
            $suggestions = $assistant->suggestNextSteps($encounterId);

            $timeline = $service->timeline($encounterId);

            // Financial summary: the service_id comes from the encounter's
            // own origin appointment (Encounter::appointmentId()), resolved
            // through AppointmentService::findById() — never from TSession.
            // A walk-in encounter (no appointment_id) simply leaves the
            // financial summary empty, handled without error.
            $appointment = null;

            if ($encounter->appointmentId() !== null)
            {
                try
                {
                    $appointments = self::makeAppointmentService($context);
                    $appointment = $appointments->findById($encounter->appointmentId());
                }
                catch (Exception $e)
                {
                    // Best-effort: an unresolved appointment just leaves the
                    // financial summary empty, never blocks the rest of the
                    // screen from rendering.
                }
            }

            $serviceId = $appointment !== null ? $appointment->serviceId : $serviceIdParam;
            $priceCents = null;
            $serviceName = null;

            if ($appointment !== null)
            {
                try
                {
                    $catalog = self::makeServiceCatalogService($context);
                    $catalogService = $catalog->findById($appointment->serviceId);

                    if ($catalogService !== null)
                    {
                        $priceCents = $catalogService->priceCents();
                        $serviceName = $catalogService->name();
                    }
                }
                catch (Exception $e)
                {
                }
            }

            TTransaction::close();

            return [
                'encounter' => $encounter,
                'patient' => $patient,
                'tutorName' => $tutorName,
                'professional' => $professional,
                'unit' => $unit,
                'aiSummary' => $aiSummary,
                'suggestions' => $suggestions,
                'timeline' => $timeline,
                'priceCents' => $priceCents,
                'serviceName' => $serviceName,
                'serviceId' => $serviceId,
            ];
        }
        catch (\CentralVet\Authorization\Exception\AuthorizationDenied $e)
        {
            TTransaction::rollback();
            new TMessage('error', _t('You are not allowed to view this encounter'));
            return null;
        }
        catch (\CentralVet\Domain\Exception\CrossTenantReferenceException $e)
        {
            TTransaction::rollback();
            new TMessage('error', $e->getMessage());
            return null;
        }
        catch (Exception $e)
        {
            TTransaction::rollback();
            new TMessage('error', $e->getMessage());
            return null;
        }
    }

    /**
     * Top context strip: patient/tutor/professional/unit (resolved once by
     * loadEncounterData(), never re-fetched per widget), a textual autosave
     * indicator (updated by the autosave script itself, see
     * clinicalForm()) and the "Finish encounter" button
     * (EncounterService::finish(), action 'EncounterView::onFinish').
     */
    private function contextStrip(array $data): TElement
    {
        $encounter = $data['encounter'];

        $strip = new TElement('div');
        $strip->class = 'cv-section';
        $strip->style = 'display:flex; flex-wrap:wrap; justify-content:space-between; align-items:center; gap:var(--cv-space-3); padding:var(--cv-space-3) var(--cv-space-4); margin-bottom:var(--cv-space-3)';

        $patientLabel = $data['patient'] !== null ? $data['patient']->name : (_t('Patient') . ' #' . $encounter->patientId());
        $tutorLabel = $data['tutorName'] !== null ? $data['tutorName'] : '-';
        $professionalLabel = $data['professional'] !== null ? $data['professional']->name : (_t('Professional') . ' #' . $encounter->professionalSystemUserId());
        $unitLabel = $data['unit'] !== null ? $data['unit']->name : (_t('Unit') . ' #' . $encounter->systemUnitId());
        $statusLabel = $encounter->status() === \CentralVet\Domain\Encounter::STATUS_FINISHED ? _t('Finished') : _t('In progress');

        $info = new TElement('div');
        $info->add(
            '<div><strong>' . htmlspecialchars($patientLabel) . '</strong> &middot; '
            . _t('Tutor') . ': ' . htmlspecialchars($tutorLabel) . ' &middot; '
            . _t('Professional') . ': ' . htmlspecialchars($professionalLabel) . ' &middot; '
            . _t('Unit') . ': ' . htmlspecialchars($unitLabel) . '</div>'
        );
        $info->add(
            '<div style="font-size:12px;color:var(--cv-color-text-muted)">' . _t('Status') . ': ' . $statusLabel
            . ' &middot; <span id="encounter_autosave_indicator">' . _t('Autosave active') . '</span></div>'
        );

        $actions = new TElement('div');

        if ($encounter->status() !== \CentralVet\Domain\Encounter::STATUS_FINISHED)
        {
            $finishAction = new TAction([$this, 'onFinish']);
            $finishAction->setParameter('id', $encounter->id());

            $finishButton = new TButton('finish_encounter');
            $finishButton->setAction($finishAction, _t('Finish encounter'));
            $finishButton->setImage('fa:check-circle');
            $finishButton->setFormName('form_EncounterView_' . $encounter->id());
            $finishButton->class = 'btn btn-sm btn-success';
            $actions->add($finishButton);
        }

        $strip->add($info);
        $strip->add($actions);

        return $strip;
    }

    /**
     * AI summary panel (NullAiClinicalAssistant::summarizePatientHistory()).
     * Empty state when null (the current, always-null Null implementation).
     * "Accept" re-derives the summary server-side inside onAcceptAiSummary()
     * instead of round-tripping the free text through the URL.
     */
    private function aiPanel(array $data): TPanelGroup
    {
        $panel = new TPanelGroup(_t('AI summary'));
        $panel->class = 'cv-section';

        if ($data['aiSummary'] === null)
        {
            $panel->add('<p class="text-muted">' . _t('No summary available yet') . '</p>');
        }
        else
        {
            $panel->add('<p>' . nl2br(htmlspecialchars($data['aiSummary'])) . '</p>');

            $acceptAction = new TAction([$this, 'onAcceptAiSummary']);
            $acceptAction->setParameter('id', $data['encounter']->id());

            $acceptButton = new TButton('accept_ai_summary');
            $acceptButton->setAction($acceptAction, _t('Accept'));
            $acceptButton->setImage('fa:check');
            $acceptButton->setFormName('form_EncounterView_' . $data['encounter']->id());
            $acceptButton->class = 'btn btn-sm btn-primary';
            $panel->add($acceptButton);
        }

        if ($data['encounter']->aiSummaryAcceptedAt() !== null)
        {
            $panel->add(
                '<p class="text-muted" style="margin-top:6px">' . _t('Accepted summary') . ': '
                . nl2br(htmlspecialchars((string) $data['encounter']->aiSummaryText())) . '</p>'
            );
        }

        return $panel;
    }

    /**
     * Suggested next steps (NullAiClinicalAssistant::suggestNextSteps()),
     * each with its own "Apply" button. Applying never round-trips to the
     * server: TButton::addFunction() appends the suggestion text straight
     * into the clinical_plan_text textarea client-side (same
     * TButton::addFunction() pattern already identified in
     * BootstrapFormBuilder's own tab links) — never automatic.
     */
    private function suggestionsPanel(array $data): TPanelGroup
    {
        $panel = new TPanelGroup(_t('Suggested next steps'));
        $panel->class = 'cv-section';

        if (empty($data['suggestions']))
        {
            $panel->add('<p class="text-muted">' . _t('No suggestions at the moment') . '</p>');
            return $panel;
        }

        foreach ($data['suggestions'] as $index => $suggestion)
        {
            $line = new TElement('div');
            $line->style = 'display:flex; justify-content:space-between; align-items:center; gap:var(--cv-space-2); margin-bottom:var(--cv-space-1)';
            $line->add('<span>' . htmlspecialchars($suggestion) . '</span>');

            $applyButton = new TButton('apply_suggestion_' . $index);
            $applyButton->setLabel(_t('Apply'));
            $applyButton->class = 'btn btn-xs btn-outline-primary';
            $applyButton->addFunction(
                "var f = $('[name=\"clinical_plan_text\"]'); "
                . "f.val((f.val() ? f.val() + '\\n' : '') + " . json_encode($suggestion) . ");"
            );

            $line->add($applyButton);
            $panel->add($line);
        }

        return $panel;
    }

    /**
     * Anamnese (TText::enableSpeechRecognition() — never a hand-rolled Web
     * Speech API integration), sinais vitais, exame fisico, diagnostico,
     * plano clinico. Field names match self::DRAFT_FIELDS /
     * EncounterService::autosave() 1:1. Also injects the periodic autosave
     * script (setInterval + __adianti_ajax_exec, T-06's real, non-decorative
     * autosave requirement).
     */
    private function clinicalForm(array $data): TElement
    {
        $encounter = $data['encounter'];
        $finished = $encounter->status() === \CentralVet\Domain\Encounter::STATUS_FINISHED;

        $form = new BootstrapFormBuilder('form_EncounterView_' . $encounter->id());
        $form->setFormTitle(_t('Clinical record'));

        $anamnesis = new TText('anamnesis_text');
        $anamnesis->setSize('100%', 100);
        $anamnesis->enableSpeechRecognition();

        $temperature = new TEntry('temperature_c');
        $heartRate = new TEntry('heart_rate_bpm');
        $respiratoryRate = new TEntry('respiratory_rate_mpm');
        $weight = new TEntry('weight_kg');
        $mucous = new TEntry('mucous_membranes');
        $capillaryRefill = new TEntry('capillary_refill_seconds');
        $physicalExam = new TText('physical_exam_text');
        $physicalExam->setSize('100%', 90);
        $diagnosis = new TText('diagnosis_text');
        $diagnosis->setSize('100%', 70);
        $plan = new TText('clinical_plan_text');
        $plan->setSize('100%', 90);

        $numericFields = [$temperature, $heartRate, $respiratoryRate, $weight, $mucous, $capillaryRefill];

        foreach ($numericFields as $field)
        {
            $field->setSize('100%');
        }

        if ($finished)
        {
            foreach (array_merge($numericFields, [$anamnesis, $physicalExam, $diagnosis, $plan]) as $field)
            {
                $field->setEditable(false);
            }
        }

        $form->addFields([new TLabel(_t('Anamnesis (voice dictation available)'))]);
        $form->addFields([$anamnesis]);

        $form->addFields([new TLabel(_t('Temperature (C)')), new TLabel(_t('Heart rate (bpm)')), new TLabel(_t('Respiratory rate (mpm)'))]);
        $form->addFields([$temperature, $heartRate, $respiratoryRate]);

        $form->addFields([new TLabel(_t('Weight (kg)')), new TLabel(_t('Mucous membranes')), new TLabel(_t('Capillary refill (s)'))]);
        $form->addFields([$weight, $mucous, $capillaryRefill]);

        $form->addFields([new TLabel(_t('Physical exam'))]);
        $form->addFields([$physicalExam]);

        $form->addFields([new TLabel(_t('Diagnosis'))]);
        $form->addFields([$diagnosis]);

        $form->addFields([new TLabel(_t('Clinical plan'))]);
        $form->addFields([$plan]);

        $formData = new stdClass;
        $formData->anamnesis_text = $encounter->anamnesisText();
        $formData->temperature_c = $encounter->temperatureC();
        $formData->heart_rate_bpm = $encounter->heartRateBpm();
        $formData->respiratory_rate_mpm = $encounter->respiratoryRateMpm();
        $formData->weight_kg = $encounter->weightKg();
        $formData->mucous_membranes = $encounter->mucousMembranes();
        $formData->capillary_refill_seconds = $encounter->capillaryRefillSeconds();
        $formData->physical_exam_text = $encounter->physicalExamText();
        $formData->diagnosis_text = $encounter->diagnosisText();
        $formData->clinical_plan_text = $encounter->clinicalPlanText();

        $form->setData($formData);

        $wrapper = new TElement('div');
        $wrapper->class = 'cv-section';
        $wrapper->add($form);

        if (!$finished)
        {
            $wrapper->add($this->autosaveScript($encounter->id()));
        }

        return $wrapper;
    }

    /**
     * Builds the client-side autosave loop: every 20s, reads the current
     * value of every self::DRAFT_FIELDS input (by `[name="..."]` selector,
     * not by generated id — the convention already identified in
     * TCalendar/TTreeView/TNotebook) and calls onAutosave() through
     * __adianti_ajax_exec(), the same raw AJAX mechanism those widgets use
     * for their own periodic/on-demand server calls. automatic_output is
     * false: the (re-rendered) page HTML that a "static" action call always
     * returns is discarded rather than replacing anything on screen — only
     * the textual autosave indicator is updated from the callback.
     */
    private function autosaveScript(int $encounterId): TElement
    {
        $autosaveAction = new TAction([$this, 'onAutosave']);
        $autosaveAction->setParameter('id', $encounterId);
        $serializedAction = $autosaveAction->serialize(false);

        $fieldsJs = implode(',', array_map(static function ($field)
        {
            return "'" . addslashes($field) . "'";
        }, self::DRAFT_FIELDS));

        $savedLabel = addslashes(_t('Saved at'));

        $script = new TElement('script');
        $script->add(<<<JS
            (function() {
                var draftFields = [{$fieldsJs}];
                setInterval(function() {
                    var query = '{$serializedAction}';
                    draftFields.forEach(function(name) {
                        var el = $('[name="' + name + '"]');
                        if (el.length > 0) {
                            query += '&' + encodeURIComponent(name) + '=' + encodeURIComponent(el.val() || '');
                        }
                    });
                    __adianti_ajax_exec(query, function() {
                        var indicator = document.getElementById('encounter_autosave_indicator');
                        if (indicator) {
                            indicator.textContent = '{$savedLabel} ' + new Date().toLocaleTimeString();
                        }
                    }, false);
                }, 20000);
            })();
            JS
        );

        return $script;
    }

    /**
     * Audit timeline (EncounterService::timeline(), reading `audit_log`
     * directly — no dedicated table for this, per T-03's own docblock).
     */
    private function timelinePanel(array $data): TPanelGroup
    {
        $panel = new TPanelGroup(_t('Timeline'));
        $panel->class = 'cv-section';

        if (empty($data['timeline']))
        {
            $panel->add('<p class="text-muted">' . _t('No events yet') . '</p>');
            return $panel;
        }

        $list = new TElement('ul');
        $list->style = 'list-style:none; padding-left:0; margin:0';

        foreach ($data['timeline'] as $event)
        {
            $item = new TElement('li');
            $item->style = 'padding:var(--cv-space-1) 0; border-bottom:1px solid var(--cv-color-border); font-size:12px';
            $item->add('<strong>' . $event['created_at']->format('d/m H:i') . '</strong> &mdash; ' . htmlspecialchars($event['action']));
            $list->add($item);
        }

        $panel->add($list);

        return $panel;
    }

    /**
     * Inline actions: Prescricao/Exame/Vacina (T-09) navigate client-side
     * (__adianti_goto_page, same mechanism as the constructor's own
     * post-start() redirect and onReload()) straight to their dedicated
     * screen — PrescriptionForm (T-06), ExamRequestForm (T-07),
     * VaccinationForm (T-08) — passing encounter_id/patient_id as query
     * parameters, no server round-trip through this page. Procedimento
     * remains UI shell only — its click still records, at most, one
     * audit_log event via onInlineAction(), never its own table. Documento
     * is a real upload (EncounterDocumentService::attach()) and Retorno a
     * real scheduling (AppointmentService::schedule()).
     */
    private function inlineActionsPanel(array $data): TPanelGroup
    {
        $encounter = $data['encounter'];
        $panel = new TPanelGroup(_t('Inline actions'));
        $panel->class = 'cv-section';

        $quickActions = new TElement('div');
        $quickActions->style = 'display:flex; flex-wrap:wrap; gap:var(--cv-space-2); margin-bottom:var(--cv-space-4)';

        $kinds = [
            'prescription' => [_t('Prescription'), 'fa:file-medical', 'PrescriptionForm'],
            'exam' => [_t('Exam'), 'fa:vial', 'ExamRequestForm'],
            'procedure' => [_t('Procedure'), 'fa:syringe', 'ProcedureExecutionForm'],
            'vaccine' => [_t('Vaccine'), 'fa:shield-alt', 'VaccinationForm'],
            'account' => [_t('Account'), 'fa:file-invoice-dollar', 'EncounterAccountForm'],
        ];

        foreach ($kinds as $kind => $meta)
        {
            [$label, $icon, $targetClass] = $meta;

            if ($targetClass === null)
            {
                // Procedimento: unchanged — still a UI shell recording a
                // single audit_log event via onInlineAction().
                $action = new TAction([$this, 'onInlineAction']);
                $action->setParameter('id', $encounter->id());
                $action->setParameter('kind', $kind);

                $button = new TButton('inline_' . $kind);
                $button->setAction($action, $label);
                $button->setImage($icon);
                $button->setFormName('form_EncounterView_' . $encounter->id());
            }
            else
            {
                // Prescricao/Exame/Vacina: client-side navigation to the
                // dedicated screen, no TAction/server round-trip.
                $button = new TButton('inline_' . $kind);
                $button->setLabel($label);
                $button->setImage($icon);
                $button->addFunction(
                    "__adianti_goto_page('index.php?class={$targetClass}"
                    . "&encounter_id=" . $encounter->id()
                    . "&patient_id=" . $encounter->patientId() . "')"
                );
            }

            $button->class = 'btn btn-sm btn-outline-secondary';
            $quickActions->add($button);
        }

        $panel->add($quickActions);

        // Documento — upload real via TFile + EncounterDocumentService::attach().
        $docForm = new BootstrapFormBuilder('form_EncounterDocument_' . $encounter->id());
        $docForm->setFormTitle(_t('Document'));

        $docFile = new TFile('filename');
        $docForm->addFields([new TLabel(_t('File'))]);
        $docForm->addFields([$docFile]);

        $attachAction = new TAction([$this, 'onAttachDocument']);
        $attachAction->setParameter('id', $encounter->id());
        $attachButton = $docForm->addAction(_t('Attach'), $attachAction, 'fa:paperclip');
        $attachButton->class = 'btn btn-sm btn-secondary';

        $panel->add($docForm);

        // Retorno — agendamento real via AppointmentService::schedule().
        $followUpForm = new BootstrapFormBuilder('form_EncounterFollowUp_' . $encounter->id());
        $followUpForm->setFormTitle(_t('Follow-up'));

        $followUpDate = new TDateTime('followup_scheduled_at');
        $followUpDate->setSize('100%');
        $followUpDate->addValidation(_t('Date/time'), new TRequiredValidator);

        $followUpService = new TEntry('followup_service_id');
        $followUpService->setNumericMask(0, '', '', false, false, false);
        $followUpService->setSize('100%');
        $followUpService->addValidation(_t('Service id'), new TRequiredValidator);

        if ($data['serviceId'] !== null)
        {
            $followUpService->setValue($data['serviceId']);
        }

        $followUpForm->addFields([new TLabel(_t('Date/time'))]);
        $followUpForm->addFields([$followUpDate]);
        $followUpForm->addFields([new TLabel(_t('Service id'))]);
        $followUpForm->addFields([$followUpService]);

        $followUpAction = new TAction([$this, 'onScheduleFollowUp']);
        $followUpAction->setParameter('id', $encounter->id());
        $followUpButton = $followUpForm->addAction(_t('Schedule follow-up'), $followUpAction, 'fa:calendar-plus');
        $followUpButton->class = 'btn btn-sm btn-primary';

        $panel->add($followUpForm);

        return $panel;
    }

    /**
     * Financial summary: shows the origin appointment's service price
     * (ServiceCatalogService::findById()) only when a service_id could be
     * resolved (see the class docblock's documented limitation).
     */
    private function financePanel(array $data): TPanelGroup
    {
        $panel = new TPanelGroup(_t('Financial summary'));
        $panel->class = 'cv-section';

        if ($data['priceCents'] === null)
        {
            $panel->add('<p class="text-muted">' . _t('No price available for this encounter yet') . '</p>');
            return $panel;
        }

        $price = number_format($data['priceCents'] / 100, 2, ',', '.');
        $panel->add('<p><strong>' . htmlspecialchars((string) $data['serviceName']) . '</strong><br>R$ ' . $price . '</p>');

        return $panel;
    }

    /**
     * "Finish encounter" (EncounterService::finish(), action
     * 'EncounterView::onFinish' per the task's acceptance criterion).
     * AuthorizationDenied/InvalidStatusTransitionException are handled TMessages,
     * never fatal errors.
     */
    public function onFinish($param)
    {
        try
        {
            $id = isset($param['id']) ? (int) $param['id'] : 0;

            if ($id <= 0)
            {
                throw new InvalidArgumentException(_t('Invalid encounter id'));
            }

            $context = self::resolveTenantContext();

            TTransaction::open('permission');

            $service = self::makeEncounterService($context);
            $service->finish($id, 'EncounterView::onFinish');

            TTransaction::close();

            new TMessage('info', _t('Encounter finished'), new TAction(['EncounterView', 'onReload'], ['id' => $id]));
        }
        catch (\CentralVet\Authorization\Exception\AuthorizationDenied $e)
        {
            TTransaction::rollback();
            new TMessage('error', _t('You are not allowed to finish this encounter'));
        }
        catch (\CentralVet\Domain\Exception\CrossTenantReferenceException $e)
        {
            TTransaction::rollback();
            new TMessage('error', $e->getMessage());
        }
        catch (\CentralVet\Domain\Exception\InvalidStatusTransitionException $e)
        {
            TTransaction::rollback();
            new TMessage('error', $e->getMessage());
        }
        catch (Exception $e)
        {
            TTransaction::rollback();
            new TMessage('error', $e->getMessage());
        }
    }

    /**
     * Reloads the page for the same encounter_id (used after finishing).
     */
    public function onReload($param)
    {
        $id = isset($param['id']) ? (int) $param['id'] : 0;
        TScript::create("__adianti_goto_page('index.php?class=EncounterView&encounter_id={$id}')");
    }

    /**
     * "Accept AI summary": re-derives the summary text server-side
     * (NullAiClinicalAssistant::summarizePatientHistory()) instead of
     * trusting a client-echoed value, then persists it via
     * EncounterService::acceptAiSummary().
     */
    public function onAcceptAiSummary($param)
    {
        try
        {
            $id = isset($param['id']) ? (int) $param['id'] : 0;

            if ($id <= 0)
            {
                throw new InvalidArgumentException(_t('Invalid encounter id'));
            }

            $context = self::resolveTenantContext();

            TTransaction::open('permission');

            $service = self::makeEncounterService($context);
            $encounter = $service->findById($id);

            if ($encounter === null)
            {
                throw new InvalidArgumentException(_t('Encounter not found'));
            }

            $assistant = new \CentralVet\Assistant\NullAiClinicalAssistant();
            $summary = $assistant->summarizePatientHistory($encounter->patientId());

            if ($summary === null)
            {
                TTransaction::close();
                new TMessage('warning', _t('No summary available to accept'));
                return;
            }

            $service->acceptAiSummary($id, $summary);

            TTransaction::close();

            new TMessage('info', _t('Summary accepted'));
        }
        catch (Exception $e)
        {
            TTransaction::rollback();
            new TMessage('error', $e->getMessage());
        }
    }

    /**
     * Periodic autosave (EncounterService::autosave()). Triggered by the
     * client-side setInterval() injected in autosaveScript(), via
     * __adianti_ajax_exec() with automatic_output=false, so its (re-rendered)
     * HTML response is discarded rather than shown — failures here are
     * swallowed on purpose (never surfaced as a dialog mid-typing) but never
     * left to become a fatal error either.
     */
    public function onAutosave($param)
    {
        try
        {
            $id = isset($param['id']) ? (int) $param['id'] : 0;

            if ($id <= 0)
            {
                return;
            }

            $context = self::resolveTenantContext();

            TTransaction::open('permission');

            $service = self::makeEncounterService($context);

            $draft = [];

            foreach (self::DRAFT_FIELDS as $field)
            {
                if (array_key_exists($field, $param))
                {
                    $draft[$field] = $param[$field] !== '' ? $param[$field] : null;
                }
            }

            $service->autosave($id, $draft);

            TTransaction::close();
        }
        catch (Exception $e)
        {
            TTransaction::rollback();
        }
    }

    /**
     * Procedimento (T-09: the only inline "kind" still routed here —
     * Prescricao/Exame/Vacina now navigate straight to their own dedicated
     * screen from inlineActionsPanel() instead of calling this action):
     * casca de UI. Records a single audit_log event
     * (CentralVet\Audit\AuditEvent + PdoAuditLogWriter, used directly here
     * — no dedicated Application service exists for this action in this
     * phase, and the task explicitly allows this choice) and reloads the
     * page so the new event shows up in the timeline. Never writes to any
     * table of its own.
     */
    public function onInlineAction($param)
    {
        try
        {
            $id = isset($param['id']) ? (int) $param['id'] : 0;
            $kind = isset($param['kind']) ? (string) $param['kind'] : '';

            if ($id <= 0 || $kind === '')
            {
                throw new InvalidArgumentException(_t('Invalid inline action'));
            }

            $context = self::resolveTenantContext();

            TTransaction::open('permission');

            $writer = new \CentralVet\Audit\PdoAuditLogWriter(TTransaction::get());
            $writer->record(new \CentralVet\Audit\AuditEvent(
                tenantId: $context->tenantId(),
                unitId: $context->unitId(),
                userId: (int) TSession::getValue('userid'),
                correlationId: \CentralVet\Observability\CorrelationId\CorrelationIdContext::current()
                    ?? \CentralVet\Observability\CorrelationId\CorrelationIdContext::start(),
                action: 'EncounterView::onInlineAction:' . $kind,
                entityType: 'encounter',
                entityId: $id,
                beforeData: null,
                afterData: ['kind' => $kind],
                metadata: [],
                ipAddress: $_SERVER['REMOTE_ADDR'] ?? null,
                userAgent: $_SERVER['HTTP_USER_AGENT'] ?? null,
            ));

            TTransaction::close();

            new TMessage('info', _t('Recorded') . ': ' . $kind, new TAction(['EncounterView', 'onReload'], ['id' => $id]));
        }
        catch (Exception $e)
        {
            TTransaction::rollback();
            new TMessage('error', $e->getMessage());
        }
    }

    /**
     * "Retorno" — the only inline action besides "Documento" with a real
     * effect: schedules a genuine follow-up Appointment via
     * AppointmentService::schedule(), reusing the encounter's own
     * patient/professional/unit. SchedulingConflictException (a real
     * refusal when the slot is already taken) is caught and shown as a
     * handled TMessage, never a fatal error.
     */
    public function onScheduleFollowUp($param)
    {
        try
        {
            $id = isset($param['id']) ? (int) $param['id'] : 0;
            $scheduledAt = isset($param['followup_scheduled_at']) ? (string) $param['followup_scheduled_at'] : '';
            $serviceId = isset($param['followup_service_id']) && $param['followup_service_id'] !== ''
                ? (int) $param['followup_service_id']
                : null;

            if ($id <= 0 || $scheduledAt === '' || $serviceId === null)
            {
                throw new InvalidArgumentException(_t('Date/time and service id are required to schedule a follow-up'));
            }

            $context = self::resolveTenantContext();

            TTransaction::open('permission');

            $encounterService = self::makeEncounterService($context);
            $encounter = $encounterService->findById($id);

            if ($encounter === null)
            {
                throw new InvalidArgumentException(_t('Encounter not found'));
            }

            $appointments = self::makeAppointmentService($context);

            $appointments->schedule([
                'patient_id' => $encounter->patientId(),
                'service_id' => $serviceId,
                'professional_system_user_id' => $encounter->professionalSystemUserId(),
                'scheduled_at' => $scheduledAt,
                'system_unit_id' => $context->requireUnitId(),
            ], 'EncounterView::onScheduleFollowUp');

            TTransaction::close();

            new TMessage('info', _t('Follow-up scheduled successfully'));
        }
        catch (\CentralVet\Domain\Exception\SchedulingConflictException $e)
        {
            TTransaction::rollback();
            new TMessage('error', $e->getMessage());
        }
        catch (\CentralVet\Domain\Exception\CrossTenantReferenceException $e)
        {
            TTransaction::rollback();
            new TMessage('error', $e->getMessage());
        }
        catch (\CentralVet\Authorization\Exception\AuthorizationDenied $e)
        {
            TTransaction::rollback();
            new TMessage('error', _t('You are not allowed to schedule an appointment for this unit'));
        }
        catch (Exception $e)
        {
            TTransaction::rollback();
            new TMessage('error', $e->getMessage());
        }
    }

    /**
     * "Documento": reads the file TFile already moved into tmp/ on upload
     * (same convention as SystemDriveDocumentUploadForm::onSave()) and
     * attaches it via EncounterDocumentService::attach() (T-05), which
     * prefixes the storage key by tenant+encounterId. No new table.
     */
    public function onAttachDocument($param)
    {
        try
        {
            $id = isset($param['id']) ? (int) $param['id'] : 0;
            $fileName = isset($param['filename']) ? (string) $param['filename'] : '';

            if ($id <= 0 || $fileName === '')
            {
                throw new InvalidArgumentException(_t('Choose a file to attach'));
            }

            $sourcePath = 'tmp/' . $fileName;

            if (!file_exists($sourcePath))
            {
                throw new InvalidArgumentException(_t('Uploaded file was not found'));
            }

            $contents = file_get_contents($sourcePath);
            $contentType = function_exists('mime_content_type')
                ? ((string) (mime_content_type($sourcePath) ?: 'application/octet-stream'))
                : 'application/octet-stream';

            $context = self::resolveTenantContext();

            $documents = self::makeEncounterDocumentService($context);
            $documents->attach($id, $fileName, (string) $contents, $contentType);

            @unlink($sourcePath);

            new TMessage('info', _t('Document attached successfully'));
        }
        catch (Exception $e)
        {
            new TMessage('error', $e->getMessage());
        }
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

    /**
     * Wires EncounterService (T-03) from its Persistence/PDO
     * implementation. Mirrors AppointmentForm::buildAppointmentService()/
     * QueueEntryView::makeQueueEntryService(): the real RbacAuthorizationService
     * (Fase 0), backed by AdiantiProgramPermissionProvider (reads the same
     * programs/methods session keys SystemPermission::checkPermission()
     * already uses) and PdoAuditLogWriter against this same 'permission'
     * connection, so every start()/finish() call is both unit-scope-checked
     * and audited to `audit_log`.
     */
    private static function makeEncounterService(\CentralVet\Tenancy\TenantContext $context): \CentralVet\Application\EncounterService
    {
        $connection = TTransaction::get();

        $encounters = new \CentralVet\Persistence\EncounterRepository($context, $connection);

        $authorization = new \CentralVet\Authorization\RbacAuthorizationService(
            new \CentralVet\Authorization\AdiantiProgramPermissionProvider(new \CentralVet\Tenancy\AdiantiSessionContextSource()),
            new \CentralVet\Audit\PdoAuditLogWriter($connection),
        );

        return new \CentralVet\Application\EncounterService($encounters, $authorization, $context, $connection);
    }

    private static function makePatientService(\CentralVet\Tenancy\TenantContext $context): \CentralVet\Application\PatientService
    {
        $connection = TTransaction::get();

        $patients = new \CentralVet\Persistence\PatientRepository($context, $connection);
        $tutors = new \CentralVet\Persistence\TutorRepository($context, $connection);

        return new \CentralVet\Application\PatientService($patients, $tutors, $context);
    }

    private static function makeTutorService(\CentralVet\Tenancy\TenantContext $context): \CentralVet\Application\TutorService
    {
        $connection = TTransaction::get();

        $tutors = new \CentralVet\Persistence\TutorRepository($context, $connection);

        return new \CentralVet\Application\TutorService($tutors);
    }

    private static function makeServiceCatalogService(\CentralVet\Tenancy\TenantContext $context): \CentralVet\Application\ServiceCatalogService
    {
        $connection = TTransaction::get();

        $services = new \CentralVet\Persistence\ServiceRepository($context, $connection);

        return new \CentralVet\Application\ServiceCatalogService($services, $context);
    }

    /**
     * Wires AppointmentService (Fase 1), copied verbatim from
     * AppointmentForm::buildAppointmentService() — the only Fase 1 service
     * this screen consumes for a real (non-UI-shell) effect, per the task's
     * "Retorno" acceptance criterion.
     */
    private static function makeAppointmentService(\CentralVet\Tenancy\TenantContext $context): \CentralVet\Application\AppointmentService
    {
        $connection = TTransaction::get();

        $appointments = new \CentralVet\Persistence\AppointmentRepository($context, $connection);
        $services = new \CentralVet\Persistence\ServiceRepository($context, $connection);
        $patientsRepository = new \CentralVet\Persistence\PatientRepository($context, $connection);
        $tutorsRepository = new \CentralVet\Persistence\TutorRepository($context, $connection);
        $patients = new \CentralVet\Application\PatientService($patientsRepository, $tutorsRepository, $context);

        $authorization = new \CentralVet\Authorization\RbacAuthorizationService(
            new \CentralVet\Authorization\AdiantiProgramPermissionProvider(new \CentralVet\Tenancy\AdiantiSessionContextSource()),
            new \CentralVet\Audit\PdoAuditLogWriter($connection),
        );

        return new \CentralVet\Application\AppointmentService($appointments, $services, $patients, $context, $authorization);
    }

    /**
     * Wires EncounterDocumentService (T-05) against the real S3-compatible
     * storage adapter (Fase 0), same CentralVet\Storage\S3CompatibleStorage::fromEnvironment()
     * factory the storage layer already exposes for this purpose. Needs no
     * PDO connection — StorageInterface never touches MySQL.
     */
    private static function makeEncounterDocumentService(\CentralVet\Tenancy\TenantContext $context): \CentralVet\Application\EncounterDocumentService
    {
        return new \CentralVet\Application\EncounterDocumentService(
            \CentralVet\Storage\S3CompatibleStorage::fromEnvironment($context),
            $context,
        );
    }

    /**
     * Resolves the tenant context of the authenticated session. Copied
     * verbatim from QueueEntryView::resolveTenantContext() /
     * AppointmentForm::resolveTenantContext() (T-03 pattern): falls back to
     * the tenant_user membership table for legacy sessions created before
     * TSession carried 'tenantid'.
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
