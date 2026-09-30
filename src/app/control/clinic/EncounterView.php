<?php
/**
 * EncounterView
 *
 * Central de Atendimento — tela unica de consulta clinica (mock 04,
 * artboard "Atendimento"), consumindo exclusivamente os Application
 * services do Core: CentralVet\Application\EncounterService para
 * abrir/autosalvar/finalizar o atendimento, CentralVet\Application\
 * ClinicalSummaryService (cartao do paciente, atendimento anterior e itens
 * do plano clinico), CentralVet\Application\EncounterDocumentService
 * (anexos), CentralVet\Application\AppointmentService (acao "Retorno") e
 * CentralVet\Application\ServiceCatalogService (preco no resumo
 * financeiro). Nenhuma regra de negocio (autorizacao por unidade,
 * transicao de status, conflito de agenda) vive aqui.
 *
 * Layout (fase 10): cabecalho com voltar, "Atendimento" + selo de status,
 * cronometro desde started_at, Imprimir e Finalizar; cabecalho do paciente;
 * wizard de 5 etapas (Anamnese, Exame fisico, Diagnostico, Plano clinico,
 * Finalizacao) SO NO CLIENTE (CvWizard) sobre um unico formulario
 * `form_EncounterView_<id>`: todas as etapas continuam no DOM, entao o
 * autosave de 20 s le e envia todos os DRAFT_FIELDS mesmo com a etapa
 * oculta. Coluna direita: historico/timeline, anexos, retorno e resumo
 * financeiro.
 *
 * IA: NullAiClinicalAssistant continua carregado (onAcceptAiSummary), mas
 * os blocos de IA nao sao exibidos nesta fase (aiPanel/suggestionsPanel
 * mantidos sem uso).
 *
 * Pausa (rodada 2): Pausar/Retomar no cabecalho (EncounterService::pause/
 * resume, encounter.paused_at/paused_seconds, status continua
 * in_progress). Pausado, o selo "Pausado" aparece, o cronometro para e o
 * tempo exibido desconta pausedSeconds(); o autosave continua.
 *
 * Recarregamento: Finalizar, Pausar e Retomar recarregam a tela sempre
 * com `encounter_id` (nunca `id`), para o construtor achar o atendimento.
 *
 * Entrada: `encounter_id` (atendimento ja iniciado) OU `patient_id`
 * (+ `appointment_id`/`service_id` opcionais) para iniciar um novo
 * atendimento via EncounterService::start() com action
 * 'EncounterView::onStart', redirecionando (__adianti_goto_page) para a
 * propria tela com o encounter_id recem-criado.
 *
 * Resumo financeiro: conta do atendimento (EncounterAccountRepository::
 * findByEncounterId(), somente leitura — a conta so e criada pela
 * EncounterAccountForm) quando existir; senao o preco do servico do
 * agendamento de origem (Encounter::appointmentId() →
 * AppointmentService::findById() → ServiceCatalogService::findById()).
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
     * tick (see autosaveScript()).
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
     * Inline actions of the clinical plan: kind => [label key, icon, target
     * screen]. Every target receives encounter_id/patient_id.
     */
    private const PLAN_ACTIONS = [
        'prescription' => ['Prescribe', 'fa:file-medical', 'PrescriptionForm'],
        'exam' => ['Request exam', 'fa:vial', 'ExamRequestForm'],
        'procedure' => ['Procedure', 'fa:syringe', 'ProcedureExecutionForm'],
        'vaccine' => ['Vaccine', 'fa:shield-alt', 'VaccinationForm'],
        'account' => ['Account', 'fa:file-invoice-dollar', 'EncounterAccountForm'],
    ];

    /**
     * Page constructor.
     *
     * Reads encounter_id / patient_id / appointment_id / service_id from
     * $_GET (falling back to $param). `new EncounterView()` with none of
     * these set must never throw — it renders the empty state instead.
     */
    public function __construct($param = null)
    {
        parent::__construct();

        $encounterId = self::paramInt('encounter_id', $param);
        $patientId = self::paramInt('patient_id', $param);
        $appointmentId = self::paramInt('appointment_id', $param);
        $serviceId = self::paramInt('service_id', $param);

        $container = new TVBox;
        $container->style = 'width: 100%';

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
                $container->add(CvPage::header(_t('Encounter'), null, [], false));
                parent::add($container);
                return;
            }

            // start() refused (TMessage already shown by startEncounter()):
            // fall through to the empty state below instead of a fatal error.
        }

        $data = $encounterId !== null ? $this->loadEncounterData($encounterId, $serviceId) : null;

        if ($data === null)
        {
            $container->add(CvPage::header(_t('Encounter'), null, [], false));
            $container->add($this->emptyStatePanel());
            parent::add($container);
            return;
        }

        $container->add($this->pageHeader($data));
        $container->add($this->patientHeader($data));

        $main = new TElement('div');
        $main->style = 'display:flex; flex-direction:column; gap:var(--cv-space-4)';
        $main->add($this->clinicalForm($data));

        $side = new TElement('div');
        $side->style = 'display:flex; flex-direction:column; gap:var(--cv-space-4)';
        $side->add($this->historyCard($data));
        $side->add($this->attachmentsCard($data));
        $side->add($this->followUpCard($data));
        $side->add($this->financePanel($data));

        $container->add(CvPage::columns($main, $side));

        parent::add($container);
    }

    /**
     * Empty state shown both for `new EncounterView()` (no parameters at
     * all) and for an encounter_id that failed to load.
     */
    private function emptyStatePanel(): TElement
    {
        return CvCard::create(
            _t('Encounter'),
            '<p>' . CvFormat::e(_t('Provide an encounter_id to resume an encounter, or a patient_id (optionally with appointment_id/service_id) to start a new one.')) . '</p>'
        );
    }

    /**
     * Starts a new encounter via EncounterService::start(), action string
     * literally 'EncounterView::onStart'. The professional is always the
     * authenticated user (TSession's userid).
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
            error_log(__METHOD__ . ': ' . $e->getMessage());
            new TMessage('error', CvFormat::userError($e));
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
     * Loads everything the screen needs: the aggregate
     * (EncounterService::findById()), the patient card, previous encounter
     * and clinical plan items (ClinicalSummaryService), professional/unit
     * names (best-effort), the audit timeline, attachments
     * (EncounterDocumentService::list()) and the financial summary.
     * Secondary lookups are best-effort: a failure there never blocks the
     * rest of the screen.
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

            $patientCard = null;
            $previousEncounter = null;
            $planItems = ['prescriptions' => [], 'exams' => [], 'procedures' => [], 'vaccines' => []];

            try
            {
                $summary = new \CentralVet\Application\ClinicalSummaryService(
                    new \CentralVet\Persistence\ClinicalSummaryReader($context, TTransaction::get())
                );
                $patientCard = $summary->patientCard($encounter->patientId());
                $previousEncounter = $summary->lastEncounter($encounter->patientId(), $encounterId);
                $planItems = $summary->encounterPlanItems($encounterId);
            }
            catch (Exception $e)
            {
                // display only — never blocks the rest of the screen
            }

            // Patient aggregate (tenant-aware repository) only for the
            // allergy alert and the photo — best-effort, like the card.
            $patient = null;

            try
            {
                $patient = (new \CentralVet\Persistence\PatientRepository($context, TTransaction::get()))
                    ->findById($encounter->patientId());
            }
            catch (Exception $e)
            {
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

            // AI stays wired (hidden in this phase, see class docblock).
            $assistant = new \CentralVet\Assistant\NullAiClinicalAssistant();
            $aiSummary = $assistant->summarizePatientHistory($encounter->patientId());
            $suggestions = $assistant->suggestNextSteps($encounterId);

            $timeline = $service->timeline($encounterId);

            $documents = [];

            try
            {
                $documents = self::makeEncounterDocumentService($context)->list($encounterId);
            }
            catch (Exception $e)
            {
            }

            // Financial summary: the encounter account (read-only lookup,
            // never created here) or, without one, the price of the origin
            // appointment's service — never a service_id from TSession.
            $account = null;

            try
            {
                $accounts = new \CentralVet\Persistence\EncounterAccountRepository($context, TTransaction::get());
                $account = $accounts->findByEncounterId($encounterId);
            }
            catch (Exception $e)
            {
            }

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
                'patientCard' => $patientCard,
                'patient' => $patient,
                'previousEncounter' => $previousEncounter,
                'planItems' => $planItems,
                'professional' => $professional,
                'unit' => $unit,
                'aiSummary' => $aiSummary,
                'suggestions' => $suggestions,
                'timeline' => $timeline,
                'documents' => $documents,
                'account' => $account,
                'priceCents' => $priceCents,
                'serviceName' => $serviceName,
                'serviceId' => $serviceId,
                'tenantId' => $context->tenantId(),
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
            error_log(__METHOD__ . ': ' . $e->getMessage());
            new TMessage('error', CvFormat::userError($e));
            return null;
        }
        catch (Exception $e)
        {
            TTransaction::rollback();
            new TMessage('error', $e->getMessage());
            return null;
        }
    }

    private static function isFinished($encounter): bool
    {
        return $encounter->status() === \CentralVet\Domain\Encounter::STATUS_FINISHED;
    }

    /**
     * Page header: back, "Atendimento" + status badge (+ "Pausado" when
     * paused), timer since started_at (minus paused time), Print
     * (window.print()), Pause/Resume (EncounterService::pause/resume) and
     * Finish encounter (EncounterService::finish(), action
     * 'EncounterView::onFinish').
     */
    private function pageHeader(array $data): TElement
    {
        $encounter = $data['encounter'];
        $finished = self::isFinished($encounter);

        $header = new TElement('header');
        $header->{'class'} = 'cv-page-head';

        $text = new TElement('div');
        $text->{'class'} = 'cv-page-head__text';
        $text->style = 'display:flex; align-items:center; flex-wrap:wrap; gap:var(--cv-space-3)';

        $back = TElement::tag('a', '', [
            'class' => 'btn btn-default',
            'href' => 'index.php?class=QueueEntryView',
            'generator' => 'adianti',
            'title' => CvFormat::e(_t('Back')),
            'aria-label' => CvFormat::e(_t('Back')),
        ]);
        $back->add(new TImage('fa:arrow-left'));
        $text->add($back);
        $text->add(TElement::tag('h1', CvFormat::e(_t('Encounter')), ['class' => 'cv-page-head__title']));
        $text->add($finished ? CvBadge::create(_t('Finished'), 'success') : CvBadge::create(_t('In service'), 'info'));

        if (!$finished && $encounter->isPaused())
        {
            $text->add(CvBadge::create(_t('Paused'), 'warning'));
        }

        $header->add($text);

        $actions = new TElement('div');
        $actions->{'class'} = 'cv-page-head__actions';

        $actions->add($this->timerElement($encounter));

        $print = new TButton('print_encounter');
        $print->setLabel(_t('Print'));
        $print->setImage('fa:print');
        $print->addFunction('window.print();');
        $print->class = 'btn btn-default';
        $actions->add($print);

        if (!$finished)
        {
            $actions->add($encounter->isPaused()
                ? $this->headerButton($encounter->id(), 'resume_encounter', 'onResume', _t('Resume'), 'fa:play', 'btn btn-default')
                : $this->headerButton($encounter->id(), 'pause_encounter', 'onPause', _t('Pause'), 'fa:pause', 'btn btn-default'));
            $actions->add($this->finishButton($encounter->id(), 'finish_encounter'));
        }

        $header->add($actions);

        return $header;
    }

    /**
     * "Finish encounter" button bound to form_EncounterView_<id> (phase 08
     * pattern: new TButton + setAction + setFormName, never
     * the static TButton factory with a ready TAction).
     */
    private function finishButton(int $encounterId, string $name): TButton
    {
        return $this->headerButton($encounterId, $name, 'onFinish', _t('Finish encounter'), 'fa:check-circle', 'btn btn-success');
    }

    /**
     * Header action button (Finish/Pause/Resume) bound to
     * form_EncounterView_<id>, phase 08 pattern: new TButton + setAction +
     * setFormName. The action carries `encounter_id`, so the constructor
     * that runs before the method renders the encounter, not the empty state.
     */
    private function headerButton(int $encounterId, string $name, string $method, string $label, string $icon, string $class): TButton
    {
        $action = new TAction([$this, $method]);
        $action->setParameter('encounter_id', $encounterId);

        $button = new TButton($name);
        $button->setAction($action, $label);
        $button->setImage($icon);
        $button->setFormName('form_EncounterView_' . $encounterId);
        $button->class = $class;

        return $button;
    }

    /**
     * Elapsed time since started_at. The elapsed seconds are computed on the
     * server (same clock/timezone that wrote started_at) and only counted up
     * on the client, so a client clock skew never shows a wrong duration.
     * Finished encounters show the fixed started→finished duration. Paused
     * time (pausedSeconds()) is always discounted; a paused encounter shows
     * the duration up to paused_at and does not count up.
     */
    private function timerElement($encounter): TElement
    {
        $paused = !self::isFinished($encounter) && $encounter->isPaused();

        if (self::isFinished($encounter) && $encounter->finishedAt() !== null)
        {
            $end = $encounter->finishedAt();
        }
        elseif ($paused && $encounter->pausedAt() !== null)
        {
            $end = $encounter->pausedAt();
        }
        else
        {
            $end = new DateTimeImmutable('now', $encounter->startedAt()->getTimezone());
        }

        $elapsed = max(0, $end->getTimestamp() - $encounter->startedAt()->getTimestamp() - $encounter->pausedSeconds());
        $timerId = 'encounter_timer_' . $encounter->id();

        $wrap = new TElement('span');
        $wrap->{'class'} = 'cv-encounter-timer';
        $wrap->{'title'} = CvFormat::e(_t('Elapsed time'));
        $wrap->style = 'display:inline-flex; align-items:center; gap:6px; font-variant-numeric:tabular-nums; font-weight:600';
        $wrap->add(new TImage('fa:clock'));
        $wrap->add(TElement::tag('span', self::formatDuration($elapsed), ['id' => $timerId]));

        if (!self::isFinished($encounter) && !$paused)
        {
            $script = new TElement('script');
            $script->add(<<<JS
                (function() {
                    var elapsed = {$elapsed};
                    var started = Date.now();
                    var pad = function(n) { return (n < 10 ? '0' : '') + n; };
                    var timer = setInterval(function() {
                        var el = document.getElementById('{$timerId}');
                        if (!el) { clearInterval(timer); return; }
                        var s = elapsed + Math.floor((Date.now() - started) / 1000);
                        el.textContent = pad(Math.floor(s / 3600)) + ':' + pad(Math.floor(s % 3600 / 60)) + ':' + pad(s % 60);
                    }, 1000);
                })();
                JS
            );
            $wrap->add($script);
        }

        return $wrap;
    }

    private static function formatDuration(int $seconds): string
    {
        return sprintf('%02d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
    }

    /**
     * Patient header: photo (PatientForm::onPhoto) when the patient has
     * photoObjectKey, otherwise the avatar placeholder; name, breed, age,
     * weight, tutor and phone (ClinicalSummaryService::patientCard()), plus
     * professional/unit; allergy alert (.cv-alert-allergy) when
     * Patient::$allergies is not empty.
     */
    private function patientHeader(array $data): TElement
    {
        $encounter = $data['encounter'];
        $card = $data['patientCard'];

        $name = $card !== null ? (string) $card['name'] : (_t('Patient') . ' #' . $encounter->patientId());
        // 0/empty weight means "not measured yet": fall back to the patient card
        $weight = (float) ($encounter->weightKg() ?? 0) > 0 ? (float) $encounter->weightKg() : null;
        $weight = $weight ?? ((float) ($card['weight_kg'] ?? 0) > 0 ? (float) $card['weight_kg'] : null);

        $facts = [
            _t('Breed') => $card['breed'] ?? null,
            _t('Age') => $card['age_label'] ?? null,
            _t('Weight') => $weight !== null ? number_format((float) $weight, 1, ',', '.') . ' kg' : null,
            _t('Tutor') => $card['tutor_name'] ?? null,
            _t('Phone') => $card['tutor_phone'] ?? null,
            _t('Professional') => $data['professional'] !== null ? $data['professional']->name : null,
            _t('Unit') => $data['unit'] !== null ? $data['unit']->name : null,
        ];

        $body = new TElement('div');
        $body->style = 'display:flex; align-items:center; gap:var(--cv-space-4); flex-wrap:wrap';

        $patient = $data['patient'] ?? null;

        if ($patient !== null && trim((string) $patient->photoObjectKey) !== '')
        {
            $body->add(TElement::tag('img', '', [
                'class' => 'cv-patient-photo',
                // &v= muda a cada troca de foto: o cache privado (max-age) é por URL (T-32)
                'src' => 'engine.php?class=PatientForm&method=onPhoto&static=1&key=' . (int) $patient->id
                       . '&v=' . \CentralVet\Application\PatientService::photoVersion($patient),
                'alt' => CvFormat::e($name),
            ]));
        }
        else
        {
            $avatar = CvAvatar::placeholder($name, $card['species'] ?? null);
            $avatar->{'class'} .= ' cv-avatar--lg';
            $body->add($avatar);
        }

        $info = new TElement('div');
        $info->style = 'flex:1 1 auto; min-width:0';
        $info->add(TElement::tag('div', CvFormat::e($name), ['style' => 'font-size:1.25rem; font-weight:700']));

        $list = new TElement('dl');
        $list->style = 'display:flex; flex-wrap:wrap; gap:var(--cv-space-2) var(--cv-space-5); margin:var(--cv-space-2) 0 0';

        foreach ($facts as $label => $value)
        {
            $item = new TElement('div');
            $item->add(TElement::tag('dt', CvFormat::e($label), ['style' => 'font-size:12px; font-weight:400; color:var(--cv-color-text-muted)']));
            $item->add(TElement::tag('dd', CvFormat::e($value !== null && $value !== '' ? (string) $value : '—'), ['style' => 'margin:0; font-weight:600']));
            $list->add($item);
        }

        $info->add($list);

        $allergies = $patient !== null ? trim((string) $patient->allergies) : '';

        if ($allergies !== '')
        {
            $alert = new TElement('div');
            $alert->{'class'} = 'cv-alert-allergy';
            $alert->{'role'} = 'alert';
            $alert->add(new TImage('fa:exclamation-triangle'));
            $alert->add(TElement::tag('span', CvFormat::e(_t('Allergies') . ': ' . $allergies)));
            $info->add($alert);
        }

        $body->add($info);

        $section = new TElement('section');
        $section->{'class'} = 'cv-card';
        $section->style = 'margin-bottom:var(--cv-space-4)';
        $inner = new TElement('div');
        $inner->{'class'} = 'cv-card__body';
        $inner->add($body);
        $section->add($inner);

        return $section;
    }

    /**
     * Clinical record as a 5-step client-side wizard (CvWizard) over ONE
     * form, form_EncounterView_<id>. Field names match self::DRAFT_FIELDS /
     * EncounterService::autosave() 1:1; hidden steps stay in the DOM so the
     * 20 s autosave (setInterval + __adianti_ajax_exec) keeps sending them.
     * Anamnesis keeps TText::enableSpeechRecognition().
     */
    private function clinicalForm(array $data): TElement
    {
        $encounter = $data['encounter'];
        $finished = self::isFinished($encounter);
        $formName = 'form_EncounterView_' . $encounter->id();
        $prefix = 'encounter_step_' . $encounter->id() . '_';

        $form = new TForm($formName);

        $anamnesis = new TText('anamnesis_text');
        $anamnesis->setSize('100%', 180);
        $anamnesis->enableSpeechRecognition();

        $temperature = new TEntry('temperature_c');
        $heartRate = new TEntry('heart_rate_bpm');
        $respiratoryRate = new TEntry('respiratory_rate_mpm');
        $weight = new TEntry('weight_kg');
        $mucous = new TEntry('mucous_membranes');
        $capillaryRefill = new TEntry('capillary_refill_seconds');
        $physicalExam = new TText('physical_exam_text');
        $physicalExam->setSize('100%', 120);
        $diagnosis = new TText('diagnosis_text');
        $diagnosis->setSize('100%', 140);
        $plan = new TText('clinical_plan_text');
        $plan->setSize('100%', 120);

        $vitalFields = [
            'Temperature (C)' => $temperature,
            'Heart rate (bpm)' => $heartRate,
            'Respiratory rate (mpm)' => $respiratoryRate,
            'Weight (kg)' => $weight,
            'Mucous membranes' => $mucous,
            'Capillary refill (s)' => $capillaryRefill,
        ];

        foreach ($vitalFields as $field)
        {
            $field->setSize('100%');
        }

        $textFields = [$anamnesis, $physicalExam, $diagnosis, $plan];

        if ($finished)
        {
            foreach (array_merge(array_values($vitalFields), $textFields) as $field)
            {
                $field->setEditable(false);
            }
        }

        $steps = [
            _t('Anamnesis'),
            _t('Physical exam'),
            _t('Diagnosis'),
            _t('Clinical plan'),
            _t('Finalization'),
        ];

        $content = new TElement('div');
        $content->add(CvWizard::steps($steps, $finished ? 5 : 1, $prefix));

        // 1. Anamnese
        $step1 = self::stepPanel($prefix . '1');
        $step1->add(self::fieldBlock(_t('Anamnesis (voice dictation available)'), $anamnesis));
        $content->add($step1);

        // 2. Exame fisico: sinais vitais em grid + texto livre
        $step2 = self::stepPanel($prefix . '2');
        $step2->add(TElement::tag('h3', CvFormat::e(_t('Vital signs')), ['style' => 'font-size:1rem; font-weight:600; margin:0 0 var(--cv-space-3)']));
        $grid = new TElement('div');
        $grid->style = 'display:grid; grid-template-columns:repeat(auto-fit, minmax(150px, 1fr)); gap:var(--cv-space-3); margin-bottom:var(--cv-space-4)';

        foreach ($vitalFields as $labelKey => $field)
        {
            $grid->add(self::fieldBlock(_t($labelKey), $field));
        }

        $step2->add($grid);
        $step2->add(self::fieldBlock(_t('Physical exam'), $physicalExam));
        $content->add($step2);

        // 3. Diagnostico
        $step3 = self::stepPanel($prefix . '3');
        $step3->add(self::fieldBlock(_t('Diagnosis'), $diagnosis));
        $content->add($step3);

        // 4. Plano clinico: texto + acoes inline + abas com itens reais
        $step4 = self::stepPanel($prefix . '4');
        $step4->add(self::fieldBlock(_t('Clinical plan'), $plan));
        $step4->add($this->planActions($encounter));
        $step4->add($this->planTabs($encounter->id(), $data['planItems']));
        $content->add($step4);

        // 5. Finalizacao: resumo + Finalizar
        $step5 = self::stepPanel($prefix . '5');
        $step5->add($this->finalizationSummary($data));

        if (!$finished)
        {
            $finishStep = $this->finishButton($encounter->id(), 'finish_encounter_step');
            $step5->add(TElement::tag('div', $finishStep, ['style' => 'margin-top:var(--cv-space-4); text-align:right']));
        }

        $content->add($step5);

        $form->add($content);
        $form->setFields(array_merge(array_values($vitalFields), $textFields));

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

        $body = new TElement('div');
        $body->add($form);

        $status = TElement::tag('span', CvFormat::e($finished ? _t('Finished') : _t('Autosave active')), [
            'id' => 'encounter_autosave_indicator',
            'style' => 'font-size:12px; color:var(--cv-color-text-muted)',
        ]);
        $body->add(TElement::tag('div', $status, ['style' => 'margin-top:var(--cv-space-3)']));

        if (!$finished)
        {
            $body->add($this->autosaveScript($encounter->id()));
        }

        return CvCard::create(_t('Clinical record'), $body);
    }

    private static function stepPanel(string $id): TElement
    {
        $panel = new TElement('div');
        $panel->{'id'} = $id;
        $panel->{'class'} = 'cv-wizard-panel';
        $panel->style = 'padding-top:var(--cv-space-4)';

        return $panel;
    }

    /**
     * Label above field.
     */
    private static function fieldBlock(string $label, $field): TElement
    {
        $block = new TElement('div');
        $block->style = 'margin-bottom:var(--cv-space-3)';
        $block->add(TElement::tag('label', CvFormat::e($label), ['style' => 'display:block; font-weight:600; margin-bottom:4px']));
        $block->add($field);

        return $block;
    }

    /**
     * Prescrever / Solicitar exame / Procedimento / Mais (Vacina, Conta):
     * client-side navigation (__adianti_goto_page) to the dedicated screen,
     * always passing encounter_id/patient_id. No server round-trip here.
     */
    private function planActions($encounter): TElement
    {
        $bar = new TElement('div');
        $bar->style = 'display:flex; flex-wrap:wrap; gap:var(--cv-space-2); margin:var(--cv-space-2) 0 var(--cv-space-4)';

        $more = new TElement('ul');
        $more->{'class'} = 'dropdown-menu';

        foreach (self::PLAN_ACTIONS as $kind => [$labelKey, $icon, $targetClass])
        {
            $url = "index.php?class={$targetClass}&encounter_id=" . $encounter->id() . '&patient_id=' . $encounter->patientId();

            if ($kind === 'vaccine' || $kind === 'account')
            {
                $link = TElement::tag('a', '', [
                    'class' => 'dropdown-item',
                    'href' => CvFormat::e($url),
                    'generator' => 'adianti',
                    'id' => 'inline_' . $kind,
                ]);
                $link->add(new TImage($icon));
                $link->add(TElement::tag('span', CvFormat::e(_t($labelKey)), ['style' => 'margin-left:6px']));
                $more->add(TElement::tag('li', $link, []));
                continue;
            }

            $button = new TButton('inline_' . $kind);
            $button->setLabel(_t($labelKey));
            $button->setImage($icon);
            $button->addFunction("__adianti_goto_page('{$url}')");
            $button->class = $kind === 'prescription' ? 'btn btn-primary' : 'btn btn-default';
            $bar->add($button);
        }

        $dropdown = new TElement('div');
        $dropdown->{'class'} = 'dropdown';
        $toggle = TElement::tag('button', '', [
            'type' => 'button',
            'class' => 'btn btn-default dropdown-toggle',
            'data-bs-toggle' => 'dropdown',
            'aria-expanded' => 'false',
        ]);
        $toggle->add(new TImage('fa:ellipsis-h'));
        $toggle->add(TElement::tag('span', CvFormat::e(_t('More')), ['style' => 'margin-left:6px']));
        $dropdown->add($toggle);
        $dropdown->add($more);
        $bar->add($dropdown);

        return $bar;
    }

    /**
     * Client-side tabs Prescricoes/Exames/Procedimentos/Vacinas (real counts
     * from ClinicalSummaryService::encounterPlanItems()) + Orientacoes
     * (disabled: no per-item guidance in the schema).
     */
    private function planTabs(int $encounterId, array $planItems): TElement
    {
        $tabs = [
            'prescriptions' => _t('Prescriptions'),
            'exams' => _t('Exams'),
            'procedures' => _t('Procedures'),
            'vaccines' => _t('Vaccines'),
        ];
        $prefix = 'encounter_plan_' . $encounterId . '_';

        $wrap = new TElement('div');
        $wrap->{'class'} = 'cv-plan-tabs';

        $nav = new TElement('nav');
        $nav->{'class'} = 'cv-tabs';
        $list = new TElement('ul');
        $list->{'class'} = 'cv-tabs__list';
        $list->{'role'} = 'tablist';

        $panels = new TElement('div');
        $first = true;

        foreach ($tabs as $key => $label)
        {
            $items = $planItems[$key] ?? [];

            $link = TElement::tag('a', CvFormat::e($label . ' (' . count($items) . ')'), [
                'class' => 'cv-tab' . ($first ? ' cv-tab--active' : ''),
                'href' => '#',
                'role' => 'tab',
                'data-cv-plan-tab' => $prefix . $key,
                'onclick' => "return cvEncounterPlanTab(this, '{$prefix}');",
            ]);
            $list->add(TElement::tag('li', $link, ['class' => 'cv-tabs__item']));

            $panel = new TElement('div');
            $panel->{'id'} = $prefix . $key;
            $panel->{'role'} = 'tabpanel';
            $panel->style = 'padding:var(--cv-space-3) 0' . ($first ? '' : '; display:none');
            $panel->add(self::planItemList($items, in_array($key, ['prescriptions', 'exams'], true)));
            $panels->add($panel);

            $first = false;
        }

        $list->add(TElement::tag('li', TElement::tag('span', CvFormat::e(_t('Guidance')), [
            'class' => 'cv-tab cv-tab--disabled',
            'title' => CvFormat::e(_t('Coming soon')),
            'aria-disabled' => 'true',
        ]), ['class' => 'cv-tabs__item']));

        $nav->add($list);
        $wrap->add($nav);
        $wrap->add($panels);

        $script = new TElement('script');
        $script->add(
            "window.cvEncounterPlanTab = window.cvEncounterPlanTab || function (link, prefix) {"
            . " var nav = link.closest('.cv-tabs');"
            . " nav.querySelectorAll('[data-cv-plan-tab]').forEach(function (a) {"
            . "  var active = a === link;"
            . "  a.classList.toggle('cv-tab--active', active);"
            . "  var panel = document.getElementById(a.getAttribute('data-cv-plan-tab'));"
            . "  if (panel) { panel.style.display = active ? '' : 'none'; }"
            . " });"
            . " return false;"
            . "};"
        );
        $wrap->add($script);

        return $wrap;
    }

    /**
     * Prescription/exam status as a translated CvBadge; an empty status shows
     * a neutral "—" and unknown statuses stay neutral with the raw value.
     */
    private static function planStatusBadge(string $status): TElement
    {
        $map = [
            \CentralVet\Domain\Prescription::STATUS_DRAFT => ['Draft', 'neutral'],
            \CentralVet\Domain\Prescription::STATUS_ISSUED => ['Issued', 'success'],
            \CentralVet\Domain\ExamRequest::STATUS_REQUESTED => ['Requested', 'info'],
            \CentralVet\Domain\ExamRequest::STATUS_RESULT_AVAILABLE => ['Result available', 'success'],
        ];

        if ($status === '')
        {
            return CvBadge::create('—', 'neutral');
        }

        if (isset($map[$status]))
        {
            return CvBadge::create(_t($map[$status][0]), $map[$status][1]);
        }

        return CvBadge::create($status, 'neutral');
    }

    /**
     * @param bool $detailIsStatus prescriptions/exams: `detail` is the raw status
     */
    private static function planItemList(array $items, bool $detailIsStatus = false): TElement
    {
        if (empty($items))
        {
            return TElement::tag('p', CvFormat::e(_t('No items yet')), ['class' => 'text-muted', 'style' => 'margin:0']);
        }

        $list = new TElement('ul');
        $list->style = 'list-style:none; padding-left:0; margin:0';

        foreach ($items as $item)
        {
            $li = new TElement('li');
            $li->style = 'display:flex; justify-content:space-between; gap:var(--cv-space-3); padding:var(--cv-space-2) 0; border-bottom:1px solid var(--cv-color-border)';

            $text = new TElement('div');
            $text->add(TElement::tag('div', CvFormat::e((string) $item['title']), ['style' => 'font-weight:600']));

            if ($detailIsStatus)
            {
                $text->add(TElement::tag('div', self::planStatusBadge((string) ($item['detail'] ?? '')), ['style' => 'margin-top:2px']));
            }
            elseif (!empty($item['detail']))
            {
                $text->add(TElement::tag('div', CvFormat::e((string) $item['detail']), ['style' => 'font-size:12px; color:var(--cv-color-text-muted)']));
            }

            $li->add($text);
            $li->add(TElement::tag('span', CvFormat::e(self::formatDate((string) $item['created_at'])), ['style' => 'font-size:12px; color:var(--cv-color-text-muted); white-space:nowrap']));
            $list->add($li);
        }

        return $list;
    }

    private static function formatDate(string $value, string $format = 'd/m/Y H:i'): string
    {
        $time = strtotime($value);

        return $time === false ? $value : date($format, $time);
    }

    /**
     * Finalization step: saved-state summary (diagnosis, plan item counts,
     * encounter total). Values reflect the last saved draft.
     */
    private function finalizationSummary(array $data): TElement
    {
        $encounter = $data['encounter'];
        $items = $data['planItems'];

        $rows = [
            _t('Status') => self::isFinished($encounter) ? _t('Finished') : _t('In service'),
            _t('Diagnosis') => (string) ($encounter->diagnosisText() ?? '') !== '' ? (string) $encounter->diagnosisText() : '—',
            _t('Prescriptions') => (string) count($items['prescriptions'] ?? []),
            _t('Exams') => (string) count($items['exams'] ?? []),
            _t('Procedures') => (string) count($items['procedures'] ?? []),
            _t('Vaccines') => (string) count($items['vaccines'] ?? []),
            _t('Encounter total') => self::encounterTotalLabel($data),
        ];

        $box = new TElement('div');
        $box->add(TElement::tag('h3', CvFormat::e(_t('Encounter summary')), ['style' => 'font-size:1rem; font-weight:600; margin:0 0 var(--cv-space-3)']));

        $list = new TElement('dl');
        $list->style = 'display:grid; grid-template-columns:max-content 1fr; gap:var(--cv-space-2) var(--cv-space-4); margin:0';

        foreach ($rows as $label => $value)
        {
            $list->add(TElement::tag('dt', CvFormat::e($label), ['style' => 'font-weight:400; color:var(--cv-color-text-muted)']));
            $list->add(TElement::tag('dd', CvFormat::e($value), ['style' => 'margin:0; white-space:pre-line']));
        }

        $box->add($list);

        return $box;
    }

    /**
     * Builds the client-side autosave loop: every 20s, reads the current
     * value of every self::DRAFT_FIELDS input of form_EncounterView_<id>
     * (by `[name="..."]` selector — hidden wizard steps included) and calls
     * onAutosave() through __adianti_ajax_exec() with automatic_output
     * false (the re-rendered page HTML is discarded). The loop stops itself
     * once the form is gone (navigated away), so it never posts another
     * encounter's fields under this encounter id.
     */
    private function autosaveScript(int $encounterId): TElement
    {
        $autosaveAction = new TAction([$this, 'onAutosave']);
        $autosaveAction->setParameter('encounter_id', $encounterId);
        $serializedAction = $autosaveAction->serialize(false);

        $fieldsJs = implode(',', array_map(static function ($field)
        {
            return "'" . addslashes($field) . "'";
        }, self::DRAFT_FIELDS));

        $savedLabel = addslashes(_t('Saved at'));
        $formId = 'form_EncounterView_' . $encounterId;

        $script = new TElement('script');
        $script->add(<<<JS
            (function() {
                var draftFields = [{$fieldsJs}];
                var autosave = setInterval(function() {
                    var form = $('#{$formId}');
                    if (form.length === 0) {
                        clearInterval(autosave);
                        return;
                    }
                    var query = '{$serializedAction}';
                    draftFields.forEach(function(name) {
                        var el = form.find('[name="' + name + '"]');
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
     * Right column: previous encounter (ClinicalSummaryService::
     * lastEncounter()) + audit timeline (EncounterService::timeline()).
     */
    private function historyCard(array $data): TElement
    {
        $body = new TElement('div');
        $previous = $data['previousEncounter'];

        if ($previous !== null)
        {
            $box = new TElement('div');
            $box->style = 'padding-bottom:var(--cv-space-3); margin-bottom:var(--cv-space-3); border-bottom:1px solid var(--cv-color-border)';
            $box->add(TElement::tag('div', CvFormat::e(_t('Previous encounter') . ' · ' . self::formatDate($previous['started_at'], 'd/m/Y')), ['style' => 'font-weight:600']));

            $excerpt = $previous['diagnosis_excerpt'] ?? $previous['anamnesis_excerpt'] ?? null;

            if ($excerpt !== null)
            {
                $box->add(TElement::tag('div', CvFormat::e($excerpt), ['style' => 'font-size:12px; color:var(--cv-color-text-muted)']));
            }

            $link = TElement::tag('a', CvFormat::e(_t('Open')), [
                'href' => 'index.php?class=EncounterView&encounter_id=' . (int) $previous['id'],
                'generator' => 'adianti',
                'style' => 'font-size:12px',
            ]);
            $box->add($link);
            $body->add($box);
        }

        $body->add(TElement::tag('div', CvFormat::e(_t('Timeline')), ['style' => 'font-weight:600; margin-bottom:var(--cv-space-2)']));

        if (empty($data['timeline']))
        {
            $body->add(TElement::tag('p', CvFormat::e(_t('No events yet')), ['class' => 'text-muted', 'style' => 'margin:0']));
        }
        else
        {
            $list = new TElement('ul');
            $list->style = 'list-style:none; padding-left:0; margin:0';

            foreach ($data['timeline'] as $event)
            {
                $item = new TElement('li');
                $item->style = 'padding:var(--cv-space-1) 0; border-bottom:1px solid var(--cv-color-border); font-size:12px';
                $item->add('<strong>' . $event['created_at']->format('d/m H:i') . '</strong> &mdash; ' . CvFormat::e((string) $event['action']));
                $list->add($item);
            }

            $body->add($list);
        }

        return CvCard::create(_t('History'), $body);
    }

    /**
     * Attachments (EncounterDocumentService::list()) + real upload via TFile
     * and onAttachDocument() (EncounterDocumentService::attach()).
     */
    private function attachmentsCard(array $data): TElement
    {
        $encounter = $data['encounter'];
        $body = new TElement('div');

        if (empty($data['documents']))
        {
            $body->add(TElement::tag('p', CvFormat::e(_t('No attachments yet')), ['class' => 'text-muted']));
        }
        else
        {
            $list = new TElement('ul');
            $list->style = 'padding-left:1rem';

            foreach ($data['documents'] as $document)
            {
                $label = is_object($document) && isset($document->key) ? basename((string) $document->key) : (string) json_encode($document);
                $list->add(TElement::tag('li', CvFormat::e($label), []));
            }

            $body->add($list);
        }

        $docForm = new BootstrapFormBuilder('form_EncounterDocument_' . $encounter->id());

        $docFile = new TFile('filename');
        $docForm->addFields([new TLabel(_t('File'))]);
        $docForm->addFields([$docFile]);

        $attachAction = new TAction([$this, 'onAttachDocument']);
        $attachAction->setParameter('encounter_id', $encounter->id());
        $attachButton = $docForm->addAction(_t('Attach'), $attachAction, 'fa:paperclip');
        $attachButton->class = 'btn btn-sm btn-secondary';

        $body->add($docForm);

        return CvCard::create(_t('Attachments'), $body);
    }

    /**
     * Retorno — real scheduling via onScheduleFollowUp()
     * (AppointmentService::schedule()); service picked from a TDBCombo of
     * Service filtered by the session tenant.
     */
    private function followUpCard(array $data): TElement
    {
        $encounter = $data['encounter'];

        $followUpForm = new BootstrapFormBuilder('form_EncounterFollowUp_' . $encounter->id());

        $followUpDate = new TDateTime('followup_scheduled_at');
        $followUpDate->setSize('100%');
        $followUpDate->addValidation(_t('Date/time'), new TRequiredValidator);

        $tenantCriteria = new TCriteria;
        $tenantCriteria->add(new TFilter('tenant_id', '=', (int) $data['tenantId']));

        $followUpService = new TDBCombo('followup_service_id', 'permission', 'Service', 'id', 'name', 'name', $tenantCriteria);
        $followUpService->setSize('100%');
        $followUpService->addValidation(_t('Service'), new TRequiredValidator);

        if ($data['serviceId'] !== null)
        {
            $followUpService->setValue($data['serviceId']);
        }

        $followUpForm->addFields([new TLabel(_t('Date/time'))]);
        $followUpForm->addFields([$followUpDate]);
        $followUpForm->addFields([new TLabel(_t('Service'))]);
        $followUpForm->addFields([$followUpService]);

        $followUpAction = new TAction([$this, 'onScheduleFollowUp']);
        $followUpAction->setParameter('encounter_id', $encounter->id());
        $followUpButton = $followUpForm->addAction(_t('Schedule follow-up'), $followUpAction, 'fa:calendar-plus');
        $followUpButton->class = 'btn btn-sm btn-primary';

        return CvCard::create(_t('Follow-up'), $followUpForm);
    }

    /**
     * "Total do atendimento": the encounter account total when an account
     * exists, otherwise the origin appointment's service price; "—" when
     * neither is known.
     */
    private static function encounterTotalLabel(array $data): string
    {
        if ($data['account'] !== null)
        {
            return CvFormat::money((int) $data['account']->totalCents());
        }

        return $data['priceCents'] !== null ? CvFormat::money((int) $data['priceCents']) : '—';
    }

    /**
     * Resumo financeiro: account subtotal/discount (read-only) or the
     * origin service price, always ending with "Total do atendimento".
     */
    private function financePanel(array $data): TElement
    {
        $encounter = $data['encounter'];
        $body = new TElement('div');

        $rows = [];

        if ($data['account'] !== null)
        {
            $rows[_t('Subtotal')] = CvFormat::money((int) $data['account']->subtotalCents());

            if ((int) $data['account']->discountCents() > 0)
            {
                $rows[_t('Discount')] = '-' . CvFormat::money((int) $data['account']->discountCents());
            }
        }
        elseif ($data['priceCents'] !== null)
        {
            $rows[(string) ($data['serviceName'] ?? _t('Service'))] = CvFormat::money((int) $data['priceCents']);
        }
        else
        {
            $body->add(TElement::tag('p', CvFormat::e(_t('No price available for this encounter yet')), ['class' => 'text-muted']));
        }

        $list = new TElement('div');

        foreach ($rows as $label => $value)
        {
            $line = new TElement('div');
            $line->style = 'display:flex; justify-content:space-between; gap:var(--cv-space-3); padding:var(--cv-space-1) 0';
            $line->add(TElement::tag('span', CvFormat::e($label), []));
            $line->add(TElement::tag('span', CvFormat::e($value), []));
            $list->add($line);
        }

        $total = new TElement('div');
        $total->style = 'display:flex; justify-content:space-between; gap:var(--cv-space-3); padding-top:var(--cv-space-2); margin-top:var(--cv-space-2); border-top:1px solid var(--cv-color-border); font-weight:700';
        $total->add(TElement::tag('span', CvFormat::e(_t('Encounter total')), []));
        $total->add(TElement::tag('span', CvFormat::e(self::encounterTotalLabel($data)), []));
        $list->add($total);

        $body->add($list);

        return CvCard::create(
            _t('Financial summary'),
            $body,
            _t('Encounter account'),
            'index.php?class=EncounterAccountForm&encounter_id=' . $encounter->id() . '&patient_id=' . $encounter->patientId()
        );
    }

    /**
     * AI summary panel — kept but NOT rendered in phase 10 (no AI block
     * visible). onAcceptAiSummary() stays available.
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
            $acceptAction->setParameter('encounter_id', $data['encounter']->id());

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
     * Suggested next steps — kept but NOT rendered in phase 10 (no AI block
     * visible).
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
     * "Finish encounter" (EncounterService::finish(), action
     * 'EncounterView::onFinish' per the task's acceptance criterion).
     * AuthorizationDenied/InvalidStatusTransitionException are handled TMessages,
     * never fatal errors.
     */
    public function onFinish($param)
    {
        try
        {
            $id = self::encounterIdParam($param);

            if ($id <= 0)
            {
                throw new InvalidArgumentException(_t('Invalid encounter id'));
            }

            $context = self::resolveTenantContext();

            TTransaction::open('permission');

            $service = self::makeEncounterService($context);
            $service->finish($id, 'EncounterView::onFinish');

            TTransaction::close();

            new TMessage('info', _t('Encounter finished'), new TAction(['EncounterView', 'onReload'], ['encounter_id' => $id]));
        }
        catch (\CentralVet\Authorization\Exception\AuthorizationDenied $e)
        {
            TTransaction::rollback();
            new TMessage('error', _t('You are not allowed to finish this encounter'));
        }
        catch (\CentralVet\Domain\Exception\CrossTenantReferenceException $e)
        {
            TTransaction::rollback();
            error_log(__METHOD__ . ': ' . $e->getMessage());
            new TMessage('error', CvFormat::userError($e));
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
     * "Pausar" (EncounterService::pause(), action 'EncounterView::onPause'):
     * stamps paused_at, status stays in_progress, then reloads with
     * encounter_id.
     */
    public function onPause($param)
    {
        $this->changePause($param, 'pause', __CLASS__ . '::' . __FUNCTION__);
    }

    /**
     * "Retomar" (EncounterService::resume(), action 'EncounterView::onResume'):
     * adds the paused stretch to paused_seconds, then reloads with
     * encounter_id.
     */
    public function onResume($param)
    {
        $this->changePause($param, 'resume', __CLASS__ . '::' . __FUNCTION__);
    }

    /**
     * Shared body of onPause/onResume: $operation is 'pause' or 'resume'.
     * Refusals (AuthorizationDenied, already paused / not paused / finished)
     * are handled TMessages, never fatal errors.
     */
    private function changePause($param, string $operation, string $action): void
    {
        try
        {
            $id = self::encounterIdParam($param);

            if ($id <= 0)
            {
                throw new InvalidArgumentException(_t('Invalid encounter id'));
            }

            $context = self::resolveTenantContext();

            TTransaction::open('permission');

            $service = self::makeEncounterService($context);

            if ($operation === 'pause')
            {
                $service->pause($id, $action);
            }
            else
            {
                $service->resume($id, $action);
            }

            TTransaction::close();

            $this->onReload(['encounter_id' => $id]);
        }
        catch (\CentralVet\Authorization\Exception\AuthorizationDenied $e)
        {
            TTransaction::rollback();
            new TMessage('error', _t('Permission denied'));
        }
        catch (Exception $e)
        {
            TTransaction::rollback();
            error_log(__METHOD__ . ': ' . $e->getMessage());
            new TMessage('error', CvFormat::userError($e));
        }
    }

    /**
     * Reloads the page for the same encounter_id (used after finishing,
     * pausing and resuming). Reads `encounter_id` (falls back to `id`).
     */
    public function onReload($param)
    {
        $id = self::encounterIdParam($param);
        TScript::create("__adianti_goto_page('index.php?class=EncounterView&encounter_id={$id}')");
    }

    /**
     * Encounter id of an action: `encounter_id`, falling back to the legacy
     * `id` parameter. 0 when neither is present.
     */
    private static function encounterIdParam($param): int
    {
        if (is_array($param) && isset($param['encounter_id']) && $param['encounter_id'] !== '')
        {
            return (int) $param['encounter_id'];
        }

        return is_array($param) && isset($param['id']) ? (int) $param['id'] : 0;
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
            $id = self::encounterIdParam($param);

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

            new TMessage('info', _t('Summary accepted'), new TAction(['EncounterView', 'onReload'], ['encounter_id' => $id]));
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
            $id = self::encounterIdParam($param);

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
     * Records an inline clinical-plan action in the audit log. `kind` must
     * be one of array_keys(self::PLAN_ACTIONS) (prescription, exam,
     * procedure, vaccine, account): any other value is refused with
     * TMessage('error', _t('Invalid action')) before any transaction, so
     * nothing is written to audit_log. The encounter id comes from
     * `encounter_id` (falls back to `id`). The plan buttons themselves
     * navigate client-side to the target screen of PLAN_ACTIONS
     * (planActions()), so this action only
     * writes a single audit_log event with action
     * `EncounterView::onInlineAction:<kind>` (entity `encounter`, afterData
     * ['kind' => <kind>]) through CentralVet\Audit\PdoAuditLogWriter and
     * reloads the page so the event shows up in the timeline. Never writes
     * to any table of its own.
     */
    public function onInlineAction($param)
    {
        try
        {
            $id = self::encounterIdParam($param);
            $kind = isset($param['kind']) ? (string) $param['kind'] : '';

            if (!in_array($kind, array_keys(self::PLAN_ACTIONS), true))
            {
                new TMessage('error', _t('Invalid action'));
                return;
            }

            if ($id <= 0)
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

            new TMessage('info', _t('Recorded') . ': ' . $kind, new TAction(['EncounterView', 'onReload'], ['encounter_id' => $id]));
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
            $id = self::encounterIdParam($param);
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

            new TMessage('info', _t('Follow-up scheduled successfully'), new TAction(['EncounterView', 'onReload'], ['encounter_id' => $id]));
        }
        catch (\CentralVet\Domain\Exception\SchedulingConflictException $e)
        {
            TTransaction::rollback();
            new TMessage('error', $e->getMessage());
        }
        catch (\CentralVet\Domain\Exception\CrossTenantReferenceException $e)
        {
            TTransaction::rollback();
            error_log(__METHOD__ . ': ' . $e->getMessage());
            new TMessage('error', CvFormat::userError($e));
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
            $id = self::encounterIdParam($param);
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

            new TMessage('info', _t('Document attached successfully'), new TAction(['EncounterView', 'onReload'], ['encounter_id' => $id]));
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
