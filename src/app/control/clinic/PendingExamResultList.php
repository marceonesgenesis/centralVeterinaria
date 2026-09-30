<?php
/**
 * PendingExamResultList
 *
 * Listing screen for exam requests still awaiting a result (T-03). Mirrors
 * PayableList.php's structure exactly: this listing has no data-access
 * logic of its own — onReload() is overridden to source every row from
 * CentralVet\Application\ExamService::listPending() (T-01) — the
 * Persistence layer (CentralVet\Persistence\ExamRequestRepository) is never
 * touched from here.
 *
 * Patient/exam names are not carried by CentralVet\Domain\ExamRequest
 * itself (it only holds patientId()/examCatalogItemId()), so this screen
 * resolves them for display through the existing, already-tested
 * CentralVet\Application\PatientService::findById()/
 * CentralVet\Application\ExamCatalogService::listActive() — both read-only
 * Application-layer calls, never a direct Persistence/Domain touch.
 *
 * The "Register result" row action opens ExamResultForm passing the
 * current row's id as `exam_request_id` in the querystring — same
 * TDataGridAction(['ClassName','onEdit'], [...]) technique as
 * ProductList.php's "Batch" row action, targeting a plain TPage
 * (ExamResultForm) that reads the id back from $_GET/$param in its own
 * constructor.
 *
 * No TXMLBreadCrumb here on purpose: registering PendingExamResultList in
 * menu.xml is explicitly T-06's job, not T-03's (same precedent as
 * PayableList::__construct()'s own docblock) — TXMLBreadCrumb throws when
 * the class is not yet listed there, which would make `new
 * PendingExamResultList()` fatal ahead of that registration.
 *
 * @version    1.0
 * @package    control
 * @subpackage clinic
 */
class PendingExamResultList extends TStandardList
{
    protected $form;     // registration form
    protected $datagrid; // listing
    protected $pageNavigation;
    protected $footerBox;

    /**
     * Page constructor
     */
    public function __construct()
    {
        parent::__construct();

        // 'ExamRequest' is only used here as the session-key namespace for
        // the filter form (see AdiantiStandardCollectionTrait::onSearch());
        // the actual listing never queries the `exam_request` table
        // through it.
        parent::setActiveRecord('ExamRequest');
        parent::setDefaultOrder('id', 'asc');
        parent::addFilterField('patient_name', 'like', 'patient_name'); // filterField, operator, formField
        parent::setLimit(TSession::getValue(__CLASS__ . '_limit') ?? 10);

        // barra de filtros em linha (busca por paciente), no lugar da cortina
        $this->form = new TForm('form_search_ExamRequest');

        $patient_name = new TEntry('patient_name');
        $patient_name->placeholder = _t('Patient');
        $patient_name->setSize('100%');

        $find = new TButton('find');
        $find->setAction(new TAction([$this, 'onSearch']), _t('Find'));
        $find->setImage('fa:search');
        $find->{'class'} = 'btn btn-primary';

        $this->form->add(CvPage::filterBar([$patient_name, $find]));
        $this->form->setFields([$patient_name, $find]);

        // keep the form filled during navigation with session data
        $this->form->setData( TSession::getValue('ExamRequest_filter_data') );

        // tabela
        $this->datagrid = new BootstrapDatagridWrapper(new TDataGrid);
        CvDatagrid::decorate($this->datagrid, false);

        // row action: record the result for this exam request. ExamResultForm
        // is a plain TPage without onEdit(); a TDataGridAction pointing to an
        // inherited method like show() recurses forever (confirmed in a real
        // browser), so the link is a raw <a href> without `method=`.
        $column_action = new TDataGridColumn('id', '', 'center', 48);
        $column_action->setTransformer(function ($value) {
            $url = 'index.php?class=ExamResultForm&exam_request_id=' . (int) $value;
            return '<a class="btn btn-default btn-sm" href="' . CvFormat::e($url) . '" title="' . CvFormat::e(_t('Register result')) . '"'
                 . ' aria-label="' . CvFormat::e(_t('Register result')) . '"><i class="fa fa-file-medical-alt"></i></a>';
        });
        $column_action->disableHtmlConversion();

        $column_patient      = new TDataGridColumn('patient_name', _t('Patient'), 'left');
        $column_exam         = new TDataGridColumn('exam_name', _t('Exam'), 'left');
        $column_requested_at = new TDataGridColumn('requested_at_label', _t('Requested at'), 'center', 140);
        $column_status       = new TDataGridColumn('status', _t('Status'), 'left', 150);

        $column_patient->setTransformer(function ($value) {
            $cell = new TElement('div');
            $cell->style = 'display:flex; align-items:center; gap:var(--cv-space-2)';
            $cell->add(CvAvatar::placeholder((string) $value));
            $cell->add(TElement::tag('span', CvFormat::e((string) $value), []));
            return $cell;
        });
        $column_exam->setTransformer(function ($value) {
            return CvFormat::e((string) $value);
        });
        $column_status->setTransformer(function ($value) {
            // ExamRequestRepository::listPending() filters status = ExamRequest::STATUS_REQUESTED,
            // so every row here is a requested exam
            return CvBadge::create(_t('Requested'), 'warning');
        });

        $this->datagrid->addColumn($column_patient);
        $this->datagrid->addColumn($column_exam);
        $this->datagrid->addColumn($column_requested_at);
        $this->datagrid->addColumn($column_status);
        $this->datagrid->addColumn($column_action);

        // create the datagrid model
        $this->datagrid->createModel();

        // create the page navigation
        $this->pageNavigation = new TPageNavigation;
        $this->pageNavigation->setAction(new TAction(array($this, 'onReload')));
        $this->pageNavigation->setWidth($this->datagrid->getWidth());

        $this->footerBox = new TElement('div');

        $card = new TElement('div');
        $card->{'class'} = 'cv-card';
        $card->{'style'} = 'padding: 16px';
        $card->add($this->form);
        $card->add($this->datagrid);
        $card->add($this->footerBox);

        // No TXMLBreadCrumb here on purpose — see class docblock.
        $container = new TVBox;
        $container->style = 'width: 100%';
        $container->add(CvPage::header(_t('Pending exam results')));
        $container->add($card);

        parent::add($container);
    }

    /**
     * method onReload()
     * Loads the datagrid exclusively from
     * CentralVet\Application\ExamService::listPending() — the tenant
     * scoping happens inside that service/repository, never here. Names
     * shown for display (patient/exam) are resolved through
     * PatientService::findById()/ExamCatalogService::listActive(), both
     * already-existing Application-layer reads.
     */
    public function onReload($param = NULL)
    {
        if (!isset($this->datagrid))
        {
            return;
        }

        try
        {
            // open a transaction with database
            TTransaction::open('permission');

            $tenant_context = self::resolveTenantContext();
            $connection = TTransaction::get();

            $service = self::buildExamService($tenant_context, $connection);

            // every row this listing can ever show comes from this call
            $requests = $service->listPending();

            $patient_service = self::buildPatientService($tenant_context, $connection);
            $exam_catalog_service = self::buildExamCatalogService($tenant_context, $connection);

            // small catalog: populate a lookup map once, same technique
            // AppointmentForm/ProcedureInputForm::loadProductOptions() use
            // for a small catalog rather than one findById() call per row
            $exam_names = [];
            foreach ($exam_catalog_service->listActive() as $exam_item)
            {
                $exam_names[$exam_item->id()] = $exam_item->name();
            }

            $patient_name_filter = TSession::getValue('ExamRequest_filter_patient_name');
            $patient_name_filter = !empty($patient_name_filter) ? mb_strtolower((string) $patient_name_filter) : null;

            $rows = [];
            foreach ($requests as $request)
            {
                $patient = $patient_service->findById($request->patientId());
                $patient_name = $patient !== null ? $patient->name : ('#' . $request->patientId());

                if ($patient_name_filter !== null && mb_strpos(mb_strtolower($patient_name), $patient_name_filter) === false)
                {
                    continue;
                }

                $row = new stdClass;
                $row->id                  = $request->id();
                $row->patient_name        = $patient_name;
                $row->exam_name           = $exam_names[$request->examCatalogItemId()] ?? ('#' . $request->examCatalogItemId());
                $row->requested_at_label  = $request->requestedAt()->format('d/m/Y H:i');
                $row->status              = $request->status();

                $rows[] = $row;
            }

            // total count for this tenant (after the optional patient-name
            // filter, mirroring TStandardList's own filtered-count
            // semantics)
            $count = count($rows);

            $offset = isset($param['offset']) ? (int) $param['offset'] : 0;
            $limit  = isset($this->limit) ? ( $this->limit > 0 ? $this->limit : NULL) : 10;

            $page_rows = $limit ? array_slice($rows, $offset, $limit) : $rows;

            $this->datagrid->clear();
            foreach ($page_rows as $row)
            {
                $this->datagrid->addItem($row);
            }

            if (isset($this->pageNavigation))
            {
                $this->pageNavigation->setCount($count); // count of records
                $this->pageNavigation->setProperties($param); // order, page
                $this->pageNavigation->setLimit($limit); // limit
            }

            $this->footerBox->add(CvDatagrid::footer(
                $this->pageNavigation,
                $offset + 1,
                $offset + count($page_rows),
                $count,
                mb_strtolower(_t('Exams'), 'UTF-8')
            ));

            // close the transaction
            TTransaction::close();
            $this->loaded = true;

            return $rows;
        }
        catch (\CentralVet\Authorization\Exception\AuthorizationDenied $e)
        {
            new TMessage('error', _t('You are not allowed to perform this action'));
            TTransaction::rollback();
        }
        catch (\CentralVet\Tenancy\Exception\MissingTenantContext $e)
        {
            new TMessage('error', _t('An authenticated session with a tenant is required'));
            TTransaction::rollback();
        }
        catch (Exception $e) // in case of exception
        {
            // shows the exception error message
            new TMessage('error', $e->getMessage());
            // undo all pending operations
            TTransaction::rollback();
        }
    }

    /**
     *
     */
    public static function onChangeLimit($param)
    {
        TSession::setValue(__CLASS__ . '_limit', $param['limit'] );
        AdiantiCoreApplication::loadPage(__CLASS__, 'onReload');
    }

    /**
     * Wires an Application-layer ExamService instance, mirroring
     * ExamResultForm::makeExamService(). Requires an already-open
     * TTransaction('permission') connection.
     */
    private static function buildExamService(\CentralVet\Tenancy\TenantContext $context, $connection): \CentralVet\Application\ExamService
    {
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
     * Wires an Application-layer PatientService instance, used only to
     * resolve a patient's display name for this listing's grid — never to
     * create/mutate a patient. Requires an already-open
     * TTransaction('permission') connection.
     */
    private static function buildPatientService(\CentralVet\Tenancy\TenantContext $context, $connection): \CentralVet\Application\PatientService
    {
        $patients = new \CentralVet\Persistence\PatientRepository($context, $connection);
        $tutors = new \CentralVet\Persistence\TutorRepository($context, $connection);

        return new \CentralVet\Application\PatientService($patients, $tutors, $context);
    }

    /**
     * Wires an Application-layer ExamCatalogService instance, used only to
     * resolve an exam catalog item's display name for this listing's grid.
     * Requires an already-open TTransaction('permission') connection.
     */
    private static function buildExamCatalogService(\CentralVet\Tenancy\TenantContext $context, $connection): \CentralVet\Application\ExamCatalogService
    {
        $catalog = new \CentralVet\Persistence\ExamCatalogRepository($context, $connection);

        return new \CentralVet\Application\ExamCatalogService($catalog, $context);
    }

    /**
     * Resolves the tenant context of the authenticated session. Copied
     * verbatim from PayableList::resolveTenantContext() (falls back to the
     * tenant_user membership table for legacy sessions created before this
     * task, since TSession does not carry 'tenantid' yet).
     */
    private static function resolveTenantContext()
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
