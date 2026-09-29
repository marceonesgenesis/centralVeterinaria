<?php
/**
 * ExamRequestForm
 *
 * Exam request screen (T-07), consuming exclusively
 * CentralVet\Application\ExamService::requestExam() (T-04). No business
 * rule (unit-scope authorization, initial status) lives here — everything
 * is delegated to the Application service, mirroring EncounterView's own
 * "casca de UI" convention for its inline actions.
 *
 * Entry: this screen accepts `encounter_id` and `patient_id` as parameters
 * (read from $_GET, falling back to $param, same convention as
 * EncounterView::paramInt()) — it never resolves them from TSession. With
 * neither parameter set (the validation command for this task, `new
 * ExamRequestForm()`), it renders an empty state instead of touching the
 * database, so it never throws a fatal error.
 *
 * The exam catalog combo is populated from
 * CentralVet\Application\ExamCatalogService::listActive() — best effort: a
 * database failure while loading it (e.g. the T-01 migration not yet
 * applied) is caught and shown as a TMessage, never a fatal error, leaving
 * the combo empty.
 *
 * PENDING: depends on the `exam_request`/`exam_catalog_item` tables created
 * by the not-yet-applied migration
 * src/app/database/migrations/20260922_0004_phase3_prescription_exam_vaccine.sql
 * (T-01).
 *
 * @version    1.0
 * @package    control
 * @subpackage clinic
 */
class ExamRequestForm extends TPage
{
    protected $form;

    /**
     * Page constructor.
     */
    public function __construct($param = null)
    {
        parent::__construct();

        $encounterId = self::paramInt('encounter_id', $param);
        $patientId = self::paramInt('patient_id', $param);

        $container = new TVBox;
        $container->style = 'width: 100%';

        $pageHeader = new TElement('header');
        $pageHeader->class = 'cv-page-header';
        $pageHeaderTitleWrap = new TElement('div');
        $pageHeaderTitle = new TElement('h1');
        $pageHeaderTitle->class = 'cv-page-title';
        $pageHeaderTitle->add(_t('Exam request'));
        $pageHeaderTitleWrap->add($pageHeaderTitle);
        $pageHeader->add($pageHeaderTitleWrap);
        $container->add($pageHeader);

        if ($encounterId === null || $patientId === null)
        {
            $container->add($this->emptyStatePanel());
            parent::add($container);
            return;
        }

        $this->form = new BootstrapFormBuilder('form_ExamRequest');
        $this->form->setFormTitle(_t('Exam request'));
        $this->form->enableClientValidation();

        $encounter_id = new THidden('encounter_id');
        $encounter_id->setValue($encounterId);
        $patient_id = new THidden('patient_id');
        $patient_id->setValue($patientId);

        $exam_catalog_item_id = new TCombo('exam_catalog_item_id');
        $exam_catalog_item_id->addItems($this->loadCatalogOptions());
        $exam_catalog_item_id->setSize('100%');
        $exam_catalog_item_id->addValidation(_t('Exam'), new TRequiredValidator);

        $professional_system_user_id = new TEntry('professional_system_user_id');
        $professional_system_user_id->setSize('100%');
        $professional_system_user_id->setValue(TSession::getValue('userid'));
        $professional_system_user_id->addValidation(_t('Professional'), new TRequiredValidator);

        $this->form->add($encounter_id);
        $this->form->add($patient_id);

        $this->form->addFields([new TLabel(_t('Encounter') . ': ' . $encounterId . ' &middot; ' . _t('Patient') . ': ' . $patientId)]);
        $this->form->addFields([new TLabel(_t('Exam'))]);
        $this->form->addFields([$exam_catalog_item_id]);
        $this->form->addFields([new TLabel(_t('Professional (system user id)'))]);
        $this->form->addFields([$professional_system_user_id]);

        $btn = $this->form->addAction(_t('Request exam'), new TAction([$this, 'onSave']), 'fa:vial');
        $btn->class = 'btn btn-sm btn-primary';

        $container->add($this->form);

        parent::add($container);
    }

    private function emptyStatePanel(): TPanelGroup
    {
        $panel = new TPanelGroup(_t('Exam request'));
        $panel->add('<p>' . _t('Provide encounter_id and patient_id to request an exam.') . '</p>');

        return $panel;
    }

    /**
     * Loads the exam catalog combo options from ExamCatalogService::listActive().
     * Best effort: any failure (e.g. migration not applied yet) is caught
     * and shown as a TMessage, returning an empty list instead of throwing.
     */
    private function loadCatalogOptions(): array
    {
        try
        {
            $context = self::resolveTenantContext();

            TTransaction::open('permission');

            $catalog = self::makeExamCatalogService($context);
            $items = $catalog->listActive();

            TTransaction::close();

            $options = [];

            foreach ($items as $item)
            {
                $options[$item->id()] = $item->name() . ' (R$ ' . number_format($item->priceCents() / 100, 2, ',', '.') . ')';
            }

            return $options;
        }
        catch (Exception $e)
        {
            TTransaction::rollback();
            new TMessage('warning', _t('Could not load the exam catalog') . ': ' . $e->getMessage());

            return [];
        }
    }

    /**
     * Persists the exam request through ExamService::requestExam(). No
     * validation/decision is made here — everything (unit-scope
     * authorization, initial status) is enforced inside the Application
     * service. AuthorizationDenied/CrossTenantReferenceException are caught
     * and shown as a handled TMessage, never a fatal error.
     */
    public function onSave($param)
    {
        try
        {
            $encounterId = isset($param['encounter_id']) ? (int) $param['encounter_id'] : 0;
            $patientId = isset($param['patient_id']) ? (int) $param['patient_id'] : 0;
            $examCatalogItemId = isset($param['exam_catalog_item_id']) && $param['exam_catalog_item_id'] !== ''
                ? (int) $param['exam_catalog_item_id']
                : 0;
            $professionalId = isset($param['professional_system_user_id']) && $param['professional_system_user_id'] !== ''
                ? (int) $param['professional_system_user_id']
                : 0;

            if ($encounterId <= 0 || $patientId <= 0 || $examCatalogItemId <= 0 || $professionalId <= 0)
            {
                throw new InvalidArgumentException(_t('All fields are required'));
            }

            $context = self::resolveTenantContext();

            TTransaction::open('permission');

            $examService = self::makeExamService($context);

            $request = $examService->requestExam([
                'encounter_id' => $encounterId,
                'patient_id' => $patientId,
                'exam_catalog_item_id' => $examCatalogItemId,
                'professional_system_user_id' => $professionalId,
            ], 'ExamRequestForm::onSave');

            TTransaction::close();

            new TMessage('info', _t('Exam requested successfully') . ' (#' . $request->id() . ')');
        }
        catch (\CentralVet\Authorization\Exception\AuthorizationDenied $e)
        {
            TTransaction::rollback();
            new TMessage('error', _t('You are not allowed to request an exam for this unit'));
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
     * Wires ExamCatalogService (T-04) from its Persistence/PDO
     * implementation. Requires an already-open TTransaction('permission')
     * connection.
     */
    private static function makeExamCatalogService(\CentralVet\Tenancy\TenantContext $context): \CentralVet\Application\ExamCatalogService
    {
        $connection = TTransaction::get();

        $catalog = new \CentralVet\Persistence\ExamCatalogRepository($context, $connection);

        return new \CentralVet\Application\ExamCatalogService($catalog, $context);
    }

    /**
     * Wires ExamService (T-04) from its Persistence/PDO implementations.
     * Mirrors EncounterView::makeEncounterService(): the real
     * RbacAuthorizationService (Fase 0), backed by
     * AdiantiProgramPermissionProvider and PdoAuditLogWriter against this
     * same 'permission' connection, so every requestExam()/recordResult()
     * call is both unit-scope-checked and audited to `audit_log`. Requires
     * an already-open TTransaction('permission') connection.
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

        return new \CentralVet\Application\ExamService($examRequests, $examResults, $encounters, $authorization, $context);
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
