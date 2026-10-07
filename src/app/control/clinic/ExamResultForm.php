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
     * Page constructor.
     */
    public function __construct($param = null)
    {
        parent::__construct();

        $requestId = self::paramInt('exam_request_id', $param);
        $encounterId = self::paramInt('encounter_id', $param);

        $container = new TVBox;
        $container->style = 'width: 100%';
        $container->add(CvPage::header(_t('Exam result'), null, [[
            'icon' => 'fa:arrow-left',
            'href' => self::returnUrl($encounterId),
        ]]));

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
     * Wires EncounterDocumentService (T-05) against the configured storage
     * driver, CentralVet\Storage\StorageFactory::forWrites() (rodada 4), the
     * same one EncounterView::makeEncounterDocumentService() writes with (no
     * reader: this screen only attaches), plus the
     * `stored_object` index (T-56) on the open TTransaction connection, so
     * the result shows up in the origin encounter's attachment list.
     * Callers must have TTransaction::open('permission') first.
     */
    private static function makeEncounterDocumentService(\CentralVet\Tenancy\TenantContext $context): \CentralVet\Application\EncounterDocumentService
    {
        return new \CentralVet\Application\EncounterDocumentService(
            \CentralVet\Storage\StorageFactory::forWrites($context),
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
