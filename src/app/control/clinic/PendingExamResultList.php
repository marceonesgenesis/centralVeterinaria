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

        parent::setAfterSearchCallback( [$this, 'onAfterSearch' ] );

        // creates the form
        $this->form = new BootstrapFormBuilder('form_search_ExamRequest');
        $this->form->setFormTitle(_t('Pending exam results'));

        // create the form fields
        $patient_name = new TEntry('patient_name');

        // add the fields
        $this->form->addFields( [new TLabel(_t('Patient'))] );
        $this->form->addFields( [$patient_name] );

        $patient_name->setSize('100%');

        // keep the form filled during navigation with session data
        $this->form->setData( TSession::getValue('ExamRequest_filter_data') );

        // add the search form actions
        $btn = $this->form->addAction(_t('Find'), new TAction(array($this, 'onSearch')), 'fa:search');
        $btn->class = 'btn btn-sm btn-primary';

        // creates a DataGrid
        $this->datagrid = new BootstrapDatagridWrapper(new TDataGrid);
        $this->datagrid->style = 'width: 100%';
        $this->datagrid->setHeight(320);

        // row action: record the result for this exam request. ExamResultForm
        // is a plain TPage (not a TStandardForm), so it has no onEdit() of
        // its own — only its constructor reads exam_request_id back from
        // $_GET/$param. A TDataGridAction (which extends TAction) requires a
        // real class+method callback validated via method_exists(); even a
        // real inherited method like TPage::show() is unsafe here, because
        // AdiantiCoreApplication::run() already calls show() once for a bare
        // navigation, and TPage::show() itself calls $this->run()
        // (AdiantiPageControlTrait), which re-reads $_GET['method'] and,
        // since class === get_class($this), invokes that very same method
        // again — 'show' calling itself forever (confirmed via a real
        // browser click: "Maximum call stack size... Infinite recursion?").
        // The safe, already-proven pattern in this codebase for linking to a
        // plain TPage with a querystring parameter is a raw <a href> (see
        // EncounterAccountForm::onClose()'s link to PaymentForm), which never
        // sends a `method=` parameter at all.
        $column_action = new TDataGridColumn('id', _t('Register result'), 'center', 40);
        $column_action->setTransformer(function ($value) {
            $url = 'index.php?class=ExamResultForm&exam_request_id=' . (int) $value;
            return '<a href="' . $url . '" title="' . _t('Register result') . '"><i class="fa fa-file-medical-alt text-primary"></i></a>';
        });
        $column_action->disableHtmlConversion();

        // creates the datagrid columns
        $column_id                = new TDataGridColumn('id', 'Id', 'center', 50);
        $column_patient           = new TDataGridColumn('patient_name', _t('Patient'), 'left');
        $column_exam               = new TDataGridColumn('exam_name', _t('Exam'), 'left');
        $column_requested_at       = new TDataGridColumn('requested_at_label', _t('Requested at'), 'center', 130);

        // add the columns to the DataGrid
        $this->datagrid->addColumn($column_action);
        $this->datagrid->addColumn($column_id);
        $this->datagrid->addColumn($column_patient);
        $this->datagrid->addColumn($column_exam);
        $this->datagrid->addColumn($column_requested_at);

        // create the datagrid model
        $this->datagrid->createModel();

        // create the page navigation
        $this->pageNavigation = new TPageNavigation;
        $this->pageNavigation->enableCounters();
        $this->pageNavigation->setAction(new TAction(array($this, 'onReload')));
        $this->pageNavigation->setWidth($this->datagrid->getWidth());

        $panel = new TPanelGroup;
        $panel->add($this->datagrid);
        $panel->addFooter($this->pageNavigation);

        $btnf = TButton::create('find', [$this, 'onSearch'], '', 'fa:search');
        $btnf->style = 'height: 37px; margin-right:4px;';

        $form_search = new TForm('form_search_patient_name');
        $form_search->style = 'float:left;display:flex';
        $form_search->add($patient_name, true);
        $form_search->add($btnf, true);

        $panel->addHeaderWidget($form_search);

        $this->filter_label = $panel->addHeaderActionLink(_t('Filters'), new TAction([$this, 'onShowCurtainFilters']), 'fa:filter');

        if (TSession::getValue(get_class($this).'_filter_counter') > 0)
        {
            $this->filter_label->class = 'btn btn-primary';
            $this->filter_label->setLabel(_t('Filters') . ' ('. TSession::getValue(get_class($this).'_filter_counter').')');
        }

        // page header (design system: .cv-page-header / .cv-page-title,
        // mirrors src/design-system.html)
        $page_header = new TElement('header');
        $page_header->class = 'cv-page-header';

        $page_header_content = new TElement('div');

        $page_header_title = new TElement('h1');
        $page_header_title->class = 'cv-page-title';
        $page_header_title->add(_t('Pending exam results'));

        $page_header_content->add($page_header_title);
        $page_header->add($page_header_content);

        // vertical box container
        // No TXMLBreadCrumb here on purpose — see class docblock.
        $container = new TVBox;
        $container->style = 'width: 100%';
        $container->add($page_header);
        $container->add($panel);

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
    public function onAfterSearch($datagrid, $options)
    {
        if (TSession::getValue(get_class($this).'_filter_counter') > 0)
        {
            $this->filter_label->class = 'btn btn-primary';
            $this->filter_label->setLabel(_t('Filters') . ' ('. TSession::getValue(get_class($this).'_filter_counter').')');
        }
        else
        {
            $this->filter_label->class = 'btn btn-default';
            $this->filter_label->setLabel(_t('Filters'));
        }

        if (!empty(TSession::getValue(get_class($this).'_filter_data')))
        {
            $obj = new stdClass;
            $obj->patient_name = TSession::getValue(get_class($this).'_filter_data')->patient_name;
            TForm::sendData('form_search_patient_name', $obj);
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
     *
     */
    public static function onShowCurtainFilters($param = null)
    {
        try
        {
            // create empty page for right panel
            $page = new TPage;
            $page->setTargetContainer('adianti_right_panel');
            $page->setProperty('override', 'true');
            $page->setPageName(__CLASS__);

            $btn_close = new TButton('closeCurtain');
            $btn_close->onClick = "Template.closeRightPanel();";
            $btn_close->setLabel(_t('Close'));
            $btn_close->setImage('fas:times red');

            // instantiate self class, populate filters in construct
            $embed = new self;
            $embed->form->addHeaderWidget($btn_close);

            // embed form inside curtain
            $page->add($embed->form);
            $page->setIsWrapped(true);
            $page->show();
        }
        catch (Exception $e)
        {
            new TMessage('error', $e->getMessage());
        }
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

        return new \CentralVet\Application\ExamService($examRequests, $examResults, $encounters, $authorization, $context);
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
