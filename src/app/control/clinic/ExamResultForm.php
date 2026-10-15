<?php
/**
 * ExamResultForm
 *
 * Exam result registration screen (T-07), consuming exclusively
 * CentralVet\Application\ExamService::recordResult() (T-04). No business
 * rule (unit-scope authorization, status transition) lives here —
 * everything is delegated to the Application service.
 *
 * Entry: this screen accepts `exam_request_id` as a parameter (read from
 * $_GET, falling back to $param, same convention as
 * EncounterView::paramInt()). With no parameter set (the validation command
 * for this task, `new ExamResultForm()`), it renders an empty state instead
 * of touching the database, so it never throws a fatal error.
 *
 * File attachment reuses the exact same pattern as EncounterView's
 * "Documento" action / CentralVet\Application\EncounterDocumentService
 * (T-05): a TFile field leaves the uploaded file in tmp/ (Adianti's own
 * convention), which onSave() reads and hands to
 * EncounterDocumentService::attach() — keyed by the exam request's *origin
 * encounter id* (CentralVet\Domain\ExamRequest::encounterId(), resolved via
 * ExamService::findById()), never a new storage mechanism of its own. The
 * resulting StoredObjectMetadata::$objectKey is what is passed as
 * `stored_object_key` to ExamService::recordResult(). The attachment is
 * optional: a structured text result alone is also accepted.
 *
 * PENDING: depends on the `exam_request`/`exam_result` tables created by the
 * not-yet-applied migration
 * src/app/database/migrations/20260922_0004_phase3_prescription_exam_vaccine.sql
 * (T-01).
 *
 * @version    1.0
 * @package    control
 * @subpackage clinic
 */
class ExamResultForm extends TPage
{
    protected $form;

    /**
     * T-15 (Fase 8A): saved result shown read-only with the AI exam summary
     * panel. Null when the screen is recording a new result (legacy form).
     *
     * @var array{result_id: int, text: string, available: bool, latest: ?\CentralVet\Domain\AiInteraction}|null
     */
    private ?array $aiState = null;

    /** Container re-rendered by onAiExamSummary()/onAiDecide(). */
    private ?TElement $aiBox = null;

    /**
     * Page constructor.
     */
    public function __construct($param = null)
    {
        parent::__construct();

        $requestId = self::paramInt('exam_request_id', $param);
        $resultId = self::paramInt('exam_result_id', $param);
        $encounterId = self::paramInt('encounter_id', $param);

        $container = new TVBox;
        $container->style = 'width: 100%';
        $container->add(CvPage::header(_t('Exam result'), null, [[
            'icon' => 'fa:arrow-left',
            'href' => self::returnUrl($encounterId),
        ]]));

        // T-15: an already recorded result (exam_result_id, or the request of
        // a pending-review item) is shown read-only with the AI panel; the
        // record form below is only for a request still without a result.
        if ($requestId !== null || $resultId !== null)
        {
            $this->aiState = self::loadSavedResult($requestId, $resultId);
        }

        if ($this->aiState !== null)
        {
            $container->add($this->savedResultPanel());
            $this->aiBox = new TElement('div');
            $this->aiBox->{'id'} = 'exam_result_ai_' . $this->aiState['result_id'];
            $this->renderAiPanel(null);
            $container->add($this->aiBox);
            parent::add($container);
            return;
        }

        if ($requestId === null)
        {
            $container->add($this->emptyStatePanel());
            parent::add($container);
            return;
        }

        $this->form = new BootstrapFormBuilder('form_ExamResult');
        $this->form->setFormTitle(_t('Exam result') . ' #' . $requestId);
        $this->form->enableClientValidation();

        // contexto (vem da URL, sem edição)
        $exam_request_id = new THidden('exam_request_id');
        $exam_request_id->setValue($requestId);
        $encounter_id = new THidden('encounter_id');
        $encounter_id->setValue($encounterId);

        $structured_result = new TText('structured_result');
        $structured_result->setSize('100%', 140);

        $file = new TFile('filename');
        $file->setAllowedExtensions(['pdf', 'jpg', 'jpeg', 'png', 'txt', 'csv']);
        // T-63: nome imprevisível em tmp/, vinculado à sessão (CvUpload)
        $file->setService('CvUploaderService');

        $pending_review = new TCombo('pending_review');
        $pending_review->addItems([1 => _t('Yes'), 0 => _t('No')]);
        $pending_review->setValue(1);

        $hiddenRow = $this->form->addFields([$exam_request_id, $encounter_id]);
        $hiddenRow->style = 'display: none';

        $this->form->addFields([new TLabel(_t('Structured result'))]);
        $this->form->addFields([$structured_result]);
        $this->form->addFields(
            [new TLabel(_t('Attachment'))], [$file],
            [new TLabel(_t('Pending review'))], [$pending_review]
        );

        $btn = $this->form->addAction(_t('Record result'), new TAction([$this, 'onSave']), 'fa:check');
        $btn->class = 'btn btn-primary';

        CvForm::decorate($this->form, 2);

        $container->add($this->form);

        parent::add($container);
    }

    /**
     * Destino de "voltar" e do pós-salvar: o atendimento de contexto quando
     * a URL traz encounter_id, senão a lista de resultados pendentes (de
     * onde esta tela é aberta).
     */
    private static function returnUrl(?int $encounterId): string
    {
        return ($encounterId !== null && $encounterId > 0)
            ? 'index.php?class=EncounterView&encounter_id=' . $encounterId
            : 'index.php?class=PendingExamResultList';
    }

    private function emptyStatePanel(): TPanelGroup
    {
        $panel = new TPanelGroup(_t('Exam result'));
        $panel->add('<p>' . _t('Provide an exam_request_id to record its result.') . '</p>');

        return $panel;
    }

    /**
     * Persists the exam result through ExamService::recordResult(). No
     * validation/decision is made here — everything (unit-scope
     * authorization, status transition) is enforced inside the Application
     * service. The optional file, if present, is attached through
     * EncounterDocumentService::attach() (same pattern EncounterView uses
     * for "Documento") before recordResult() is called, and its object key
     * is passed along as `stored_object_key`.
     * AuthorizationDenied/InvalidStatusTransitionException/CrossTenantReferenceException
     * are caught and shown as a handled TMessage, never a fatal error.
     */
    public function onSave($param)
    {
        $sourcePath = null;
        $documents = null;
        $metadata = null;

        try
        {
            $requestId = isset($param['exam_request_id']) ? (int) $param['exam_request_id'] : 0;
            $structuredResult = isset($param['structured_result']) && $param['structured_result'] !== ''
                ? (string) $param['structured_result']
                : null;
            $fileName = isset($param['filename']) ? (string) $param['filename'] : '';
            $pendingReview = !isset($param['pending_review']) || $param['pending_review'] !== '0';

            if ($requestId <= 0)
            {
                throw new InvalidArgumentException(_t('Invalid exam request id'));
            }

            if ($structuredResult === null && $fileName === '')
            {
                throw new InvalidArgumentException(_t('Provide a structured result or an attachment'));
            }

            $context = self::resolveTenantContext();

            TTransaction::open('permission');

            $examService = self::makeExamService($context);
            $request = $examService->findById($requestId);

            if ($request === null)
            {
                throw new InvalidArgumentException(_t('Exam request not found'));
            }

            $storedObjectKey = null;

            if ($fileName !== '')
            {
                // T-62/T-63: only a regular file inside tmp/ (no ../, separators
                // or symlink out) uploaded by this session through
                // CvUploaderService; anything else throws 'Invalid file' before attach()
                $sourcePath = CvUpload::resolve($fileName);
                $uploadName = trim($fileName);
                $fileName = CvUpload::originalName($uploadName);

                $contents = (string) file_get_contents($sourcePath);
                $contentType = function_exists('mime_content_type')
                    ? ((string) (mime_content_type($sourcePath) ?: 'application/octet-stream'))
                    : 'application/octet-stream';

                $documents = self::makeEncounterDocumentService($context);
                $metadata = $documents->attach($request->encounterId(), $fileName, $contents, $contentType);

                $storedObjectKey = $metadata->objectKey;
            }

            $result = $examService->recordResult($requestId, [
                'structured_result' => $structuredResult,
                'stored_object_key' => $storedObjectKey,
                'pending_review' => $pendingReview,
            ], 'ExamResultForm::onSave');

            TTransaction::close();
            // committed: from here on the object is referenced by stored_object and exam_result
            $metadata = null;

            if ($sourcePath !== null)
            {
                @unlink($sourcePath);
                CvUpload::forget($uploadName);
            }

            TToast::show('success', _t('Exam result recorded successfully') . ' (#' . $result->id() . ')');
            $encounterContext = isset($param['encounter_id']) && $param['encounter_id'] !== '' ? (int) $param['encounter_id'] : null;
            TScript::create("__adianti_goto_page('" . self::returnUrl($encounterContext) . "')");
        }
        catch (\CentralVet\Authorization\Exception\AuthorizationDenied $e)
        {
            TTransaction::rollback();
            self::discardAttachment($documents, $metadata);
            new TMessage('error', _t('You are not allowed to record this exam result'));
        }
        catch (\CentralVet\Domain\Exception\CrossTenantReferenceException $e)
        {
            TTransaction::rollback();
            self::discardAttachment($documents, $metadata);
            error_log(__METHOD__ . ': ' . $e->getMessage());
            new TMessage('error', CvFormat::userError($e));
        }
        catch (\CentralVet\Domain\Exception\InvalidStatusTransitionException $e)
        {
            TTransaction::rollback();
            self::discardAttachment($documents, $metadata);
            error_log(__METHOD__ . ': ' . $e->getMessage());
            new TMessage('error', CvFormat::userError($e));
        }
        catch (Exception $e)
        {
            TTransaction::rollback();
            self::discardAttachment($documents, $metadata);
            error_log(__METHOD__ . ': ' . $e->getMessage());
            new TMessage('error', CvFormat::userError($e));
        }
    }

    /**
     * T-15: asks the AI for a summary of the saved exam result and shows it
     * for review (accept, save edit or discard). Nothing is written to
     * exam_result or exam_request: the suggestion lives only in
     * ai_interaction. Any failure becomes a warning and the screen goes on.
     * AiUnavailableException (disabled, quota, timeout, refusal...) commits,
     * like EncounterView: the failed interaction, its audit and its tokens
     * stay recorded; only unexpected exceptions roll back.
     */
    public function onAiExamSummary($param)
    {
        $opened = false;

        try
        {
            $examResultId = self::requiredInt($param, 'exam_result_id');
            $this->assertSameResult($examResultId);

            $context = self::resolveTenantContext();

            TTransaction::open('permission');
            $opened = true;
            $assistant = self::makeAiAssistant($context);
            $suggestion = $assistant->summarizeExamResult($examResultId);
            TTransaction::close();
            $opened = false;

            $this->renderAiPanel($suggestion);
        }
        catch (\CentralVet\Domain\Exception\AiUnavailableException $e)
        {
            if ($opened)
            {
                TTransaction::close();
            }

            error_log(__METHOD__ . ': ' . get_class($e) . ' ' . $e->reason());
            new TMessage('warning', CvFormat::userError($e));
        }
        catch (Exception $e)
        {
            TTransaction::rollback();
            error_log(__METHOD__ . ': ' . get_class($e));
            new TMessage('warning', CvFormat::userError($e));
        }
    }

    /**
     * T-15: records the professional decision on an exam summary
     * (accepted, edited or discarded). The accepted/edited text is kept only
     * in ai_interaction.final_text; the original result is never changed.
     * An interaction of another exam (or another purpose) is reported as
     * "AI suggestion not found", same as a missing one.
     */
    public function onAiDecide($param)
    {
        try
        {
            $examResultId = self::requiredInt($param, 'exam_result_id');
            $interactionId = self::requiredInt($param, 'interaction_id');
            $decision = isset($param['decision']) ? (string) $param['decision'] : '';
            $finalText = isset($param['ai_final_text']) ? (string) $param['ai_final_text'] : null;
            $this->assertSameResult($examResultId);

            $context = self::resolveTenantContext();

            TTransaction::open('permission');
            $connection = TTransaction::get();

            $interaction = (new \CentralVet\Persistence\AiInteractionRepository($context, $connection))->find($interactionId);

            if ($interaction === null
                || $interaction->purpose() !== \CentralVet\Domain\AiPurpose::EXAM_SUMMARY
                || $interaction->subjectId() !== $examResultId)
            {
                throw new \CentralVet\Domain\Exception\AiInteractionNotFoundException();
            }

            $decided = self::makeAiAssistant($context)->decide($interactionId, $decision, $finalText);
            TTransaction::close();

            if ($this->aiState !== null)
            {
                $this->aiState['latest'] = $decided->finalText() !== null ? $decided : $this->aiState['latest'];
                $this->renderAiPanel(null);
            }

            TToast::show('success', $decided->status() === \CentralVet\Domain\AiInteraction::STATUS_DISCARDED
                ? _t('AI suggestion discarded')
                : _t('AI summary saved'));
        }
        catch (\CentralVet\Authorization\Exception\AuthorizationDenied $e)
        {
            // decision on an interaction of another unit: same answer as a missing one
            TTransaction::rollback();
            new TMessage('warning', CvFormat::userError(new \CentralVet\Domain\Exception\AiInteractionNotFoundException()));
        }
        catch (Exception $e)
        {
            TTransaction::rollback();
            error_log(__METHOD__ . ': ' . get_class($e));
            new TMessage('warning', CvFormat::userError($e));
        }
    }

    /**
     * Loads the saved result (by id, or by its exam request) plus the AI
     * availability and the latest reviewed summary. Null when there is no
     * saved result or it cannot be read (the legacy form is shown instead).
     *
     * @return array{result_id: int, text: string, available: bool, latest: ?\CentralVet\Domain\AiInteraction}|null
     */
    private static function loadSavedResult(?int $requestId, ?int $resultId): ?array
    {
        try
        {
            $context = self::resolveTenantContext();

            TTransaction::open('permission');
            $connection = TTransaction::get();
            $results = new \CentralVet\Persistence\ExamResultRepository($context, $connection);
            $result = $resultId !== null ? $results->findById($resultId) : $results->findByExamRequest((int) $requestId);

            if (!$result instanceof \CentralVet\Domain\ExamResult || $result->id() === null)
            {
                TTransaction::close();
                return null;
            }

            $state = [
                'result_id' => (int) $result->id(),
                'text' => (string) $result->structuredResultText(),
                'available' => false,
                'latest' => null,
            ];

            if (trim($state['text']) !== '')
            {
                try
                {
                    $assistant = self::makeAiAssistant($context);
                    $state['available'] = $assistant->isAvailable();
                    $state['latest'] = $state['available']
                        ? $assistant->latestDecided(\CentralVet\Domain\AiPurpose::EXAM_SUMMARY, $state['result_id'])
                        : null;
                }
                catch (Exception $e)
                {
                    // AI unavailable (not configured, no active unit): hide the panel
                    error_log(__METHOD__ . ': ' . get_class($e));
                    $state['available'] = false;
                    $state['latest'] = null;
                }
            }

            TTransaction::close();

            return $state;
        }
        catch (Exception $e)
        {
            TTransaction::rollback();
            error_log(__METHOD__ . ': ' . get_class($e));

            return null;
        }
    }

    /** Original result, read-only (the AI flow never changes it). */
    private function savedResultPanel(): TPanelGroup
    {
        $panel = new TPanelGroup(_t('Exam result') . ' #' . $this->aiState['result_id']);
        $panel->class = 'cv-section';

        $label = new TLabel(_t('Structured result'));
        $panel->add($label);

        $text = $this->aiState['text'];
        $panel->add(trim($text) !== ''
            ? '<p class="cv-exam-result-text" style="text-align:left">' . nl2br(CvFormat::e($text)) . '</p>'
            : '<p class="text-muted" style="text-align:left">' . CvFormat::e(_t('No structured result (attachment only)')) . '</p>');

        return $panel;
    }

    /**
     * (Re)builds the AI panel: the pending suggestion under review, or the
     * latest reviewed summary with its origin seal, plus the "Summarize
     * exam" action. Hidden when the AI is off/unavailable or the result has
     * no structured text. Every text is escaped.
     */
    private function renderAiPanel(?\CentralVet\Domain\AiSuggestion $pending): void
    {
        if ($this->aiBox === null || $this->aiState === null)
        {
            return;
        }

        $this->aiBox->clearChildren();

        if (!$this->aiState['available'] || trim($this->aiState['text']) === '')
        {
            return;
        }

        $resultId = $this->aiState['result_id'];

        $panel = new TElement('section');
        $panel->{'class'} = 'cv-ai-panel';
        $panel->{'aria-label'} = _t('AI exam summary');
        $panel->add(TElement::tag('h4', CvFormat::e(_t('AI exam summary')), ['style' => 'text-align:left;margin:0']));

        if ($pending !== null)
        {
            $panel->add($this->suggestionForm($resultId, $pending));
        }
        else
        {
            $latest = $this->aiState['latest'];

            if ($latest !== null && $latest->finalText() !== null)
            {
                $reviewedAt = $latest->decidedAt();
                $panel->add('<span class="cv-ai-origin"><i class="fa fa-robot" aria-hidden="true"></i> '
                    . CvFormat::e(_t('AI-generated summary, reviewed on ^1', $reviewedAt !== null ? $reviewedAt->format('d/m/Y H:i') : '—'))
                    . '</span>');
                $panel->add('<div class="cv-ai-summary" style="text-align:left">' . nl2br(CvFormat::e($latest->finalText())) . '</div>');
                $panel->add('<p class="text-muted" style="text-align:left;margin:0">' . CvFormat::e(_t('The original result was not changed')) . '</p>');
            }

            $panel->add('<div class="cv-ai-actions">'
                . '<a class="btn btn-default cv-touch-target" generator="adianti" href="index.php?class=ExamResultForm&amp;method=onAiExamSummary&amp;exam_result_id=' . $resultId . '">'
                . '<i class="fa fa-wand-magic-sparkles" aria-hidden="true"></i> ' . CvFormat::e(_t('Summarize exam')) . '</a>'
                . '<span class="cv-ai-status" role="status" aria-live="polite"></span>'
                . '</div>');
        }

        $this->aiBox->add($panel);
    }

    /** Editable suggestion with Accept, Save edit and Discard (POST to onAiDecide). */
    private function suggestionForm(int $resultId, \CentralVet\Domain\AiSuggestion $suggestion): TForm
    {
        $form = new TForm('form_ExamResultAi_' . $resultId);

        $examResultField = new THidden('exam_result_id');
        $examResultField->setValue($resultId);
        $interactionField = new THidden('interaction_id');
        $interactionField->setValue($suggestion->interactionId);

        $finalText = new TText('ai_final_text');
        $finalText->setId('ai_final_text_' . $resultId);
        $finalText->setSize('100%', 160);
        $finalText->setValue($suggestion->text);

        $label = new TLabel(_t('Suggested summary (review before saving)'));
        $label->{'for'} = 'ai_final_text_' . $resultId;

        $buttons = [];
        foreach ([
            ['accepted', _t('Accept'), 'fa:check', 'btn btn-primary cv-touch-target'],
            ['edited', _t('Save edit'), 'fa:save', 'btn btn-default cv-touch-target'],
            ['discarded', _t('Discard'), 'fa:times', 'btn btn-default cv-touch-target'],
        ] as [$decision, $text, $icon, $class])
        {
            $action = new TAction([$this, 'onAiDecide']);
            $action->setParameter('decision', $decision);

            $button = new TButton('ai_decide_' . $decision);
            $button->setAction($action, $text);
            $button->setImage($icon);
            $button->{'class'} = $class;
            $buttons[] = $button;
        }

        $actions = new TElement('div');
        $actions->{'class'} = 'cv-ai-actions';
        foreach ($buttons as $button)
        {
            $actions->add($button);
        }
        $actions->add('<span class="cv-ai-status" role="status" aria-live="polite"></span>');

        $body = new TElement('div');
        $body->{'style'} = 'display:flex;flex-direction:column;gap:8px;text-align:left';
        $body->add('<span class="cv-ai-origin"><i class="fa fa-robot" aria-hidden="true"></i> ' . CvFormat::e(_t('AI suggestion')) . '</span>');
        $body->add($examResultField);
        $body->add($interactionField);
        $body->add($label);
        $body->add($finalText);
        $body->add($actions);

        $form->add($body);
        $form->setFields(array_merge([$examResultField, $interactionField, $finalText], $buttons));

        return $form;
    }

    /** The AI routes act only on the result this page was opened for. */
    private function assertSameResult(int $examResultId): void
    {
        if ($this->aiState === null || $this->aiState['result_id'] !== $examResultId)
        {
            throw new \CentralVet\Domain\Exception\AiSubjectNotFoundException();
        }
    }

    private static function requiredInt($param, string $name): int
    {
        $value = is_array($param) && isset($param[$name]) ? (int) $param[$name] : 0;

        if ($value <= 0)
        {
            throw new \CentralVet\Domain\Exception\AiInteractionNotFoundException();
        }

        return $value;
    }

    /** AiAssistService (T-11) on the open TTransaction('permission') connection. */
    private static function makeAiAssistant(\CentralVet\Tenancy\TenantContext $context): \CentralVet\Application\AiAssistService
    {
        $locale = str_starts_with((string) ApplicationTranslator::getLanguage(), 'pt') ? 'pt' : 'en';

        return \CentralVet\Application\AiAssistService::forContext($context, TTransaction::get(), $locale);
    }

    /**
     * T-56: after a rollback, removes the object an attach() of this request
     * wrote (the stored_object row went with the transaction), so a failed
     * recordResult() or commit leaves no orphan. No-op without an attach.
     */
    private static function discardAttachment(?\CentralVet\Application\EncounterDocumentService $documents, ?object $metadata): void
    {
        if ($documents !== null && $metadata !== null)
        {
            $documents->discard($metadata);
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
     * Wires ExamService (T-04) from its Persistence/PDO implementations.
     * Mirrors EncounterView::makeEncounterService(). Requires an already-open
     * TTransaction('permission') connection.
     */
    private static function makeExamService(\CentralVet\Tenancy\TenantContext $context): \CentralVet\Application\ExamService
    {
        $connection = TTransaction::get();

        $examRequests = new \CentralVet\Persistence\ExamRequestRepository($context, $connection);
        $examResults = new \CentralVet\Persistence\ExamResultRepository($context, $connection);
        $encounters = new \CentralVet\Persistence\EncounterRepository($context, $connection);

        $authorization = new \CentralVet\Authorization\RbacAuthorizationService(
            new \CentralVet\Authorization\AdiantiProgramPermissionProvider(new \CentralVet\Tenancy\AdiantiSessionContextSource()),
            new \CentralVet\Audit\PdoAuditLogWriter($connection),
        );

        return new \CentralVet\Application\ExamService($examRequests, $examResults, $encounters, $authorization, $context, new \CentralVet\Persistence\TenantUserDirectory($context, $connection));
    }

    /**
     * Wires EncounterDocumentService (T-05) against the real S3-compatible
     * storage adapter (Fase 0), same
     * CentralVet\Storage\S3CompatibleStorage::fromEnvironment() factory
     * EncounterView::makeEncounterDocumentService() already uses, plus the
     * `stored_object` index (T-56) on the open TTransaction connection, so
     * the result shows up in the origin encounter's attachment list.
     * Callers must have TTransaction::open('permission') first.
     */
    private static function makeEncounterDocumentService(\CentralVet\Tenancy\TenantContext $context): \CentralVet\Application\EncounterDocumentService
    {
        return new \CentralVet\Application\EncounterDocumentService(
            \CentralVet\Storage\S3CompatibleStorage::fromEnvironment($context),
            $context,
            new \CentralVet\Persistence\StoredObjectRepository($context, TTransaction::get()),
        );
    }

    /**
     * Resolves the tenant context of the authenticated session. Copied
     * verbatim from EncounterView::resolveTenantContext() (T-03 pattern):
     * falls back to the tenant_user membership table for legacy sessions
     * created before TSession carried 'tenantid'.
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
