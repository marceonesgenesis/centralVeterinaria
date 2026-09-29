<?php
/**
 * VaccinationCardView
 *
 * Tela de carteira de vacinacao de um paciente (T-08). Recebe o patient_id
 * por parametro de querystring (?patient_id=...), mirroring PatientList's
 * tutor_id-by-parameter pattern (Fase 1), e delega toda leitura a
 * CentralVet\Application\VaccinationService::historyByPatient() (T-05) —
 * nenhuma consulta ou regra de negocio propria vive neste controller.
 *
 * Extends TPage (not TStandardList): VaccinationService only exposes
 * apply()/historyByPatient() (no generic search on the `vaccination`
 * table), so the full TStandardList CRUD trait does not fit this
 * narrower, patient-scoped read surface — same reasoning as PatientList.
 *
 * PENDING: this screen depends on the `vaccination` table created by the
 * not-yet-applied migration
 * src/app/database/migrations/20260922_0004_phase3_prescription_exam_vaccine.sql
 * (T-01). Validated only with `php -l` / `new VaccinationCardView()` (no
 * fatal error) until that migration is applied.
 *
 * @version    1.0
 * @package    control
 * @subpackage clinic
 */
class VaccinationCardView extends TPage
{
    protected $patient_id;
    protected $datagrid;
    protected $panel;

    /**
     * Page constructor
     */
    public function __construct($param = null)
    {
        parent::__construct();

        $this->patient_id = (isset($param['patient_id']) && $param['patient_id'] !== '')
            ? (int) $param['patient_id']
            : null;

        // creates a DataGrid
        $this->datagrid = new BootstrapDatagridWrapper(new TDataGrid);
        $this->datagrid->style = 'width: 100%';
        $this->datagrid->setHeight(320);

        $column_vaccine     = new TDataGridColumn('vaccine_catalog_item_id', _t('Vaccine (catalog item id)'), 'center', 130);
        $column_dose        = new TDataGridColumn('dose_number', _t('Dose'), 'center', 70);
        $column_lot         = new TDataGridColumn('lot', _t('Lot'), 'left');
        $column_expiry      = new TDataGridColumn('expiry_date', _t('Expiry date'), 'center', 110);
        $column_applied_at  = new TDataGridColumn('applied_at', _t('Applied at'), 'center', 140);
        $column_next_dose   = new TDataGridColumn('next_dose_at', _t('Next dose'), 'center', 140);

        $this->datagrid->addColumn($column_vaccine);
        $this->datagrid->addColumn($column_dose);
        $this->datagrid->addColumn($column_lot);
        $this->datagrid->addColumn($column_expiry);
        $this->datagrid->addColumn($column_applied_at);
        $this->datagrid->addColumn($column_next_dose);

        $this->datagrid->createModel();

        $this->panel = new TPanelGroup(_t('Vaccination card'));
        $this->panel->add($this->datagrid);

        $new_action = new TAction(['VaccinationForm', 'onEdit'], ['register_state' => 'false']);
        if ($this->patient_id !== null)
        {
            $new_action->setParameter('patient_id', $this->patient_id);
        }
        $this->panel->addHeaderActionLink(_t('Apply vaccine'), $new_action, 'fa:syringe');

        // T-10: when no patient_id comes by querystring, show a patient
        // picker instead of leaving the screen blank. The contextual path
        // (patient_id already set, coming from VaccinationForm) is
        // untouched — this block only renders when it is absent.
        $patient_picker_panel = null;
        if ($this->patient_id === null)
        {
            $patient_picker_panel = $this->buildPatientPickerPanel();
        }

        // vertical box container
        // No TXMLBreadCrumb here on purpose: registering VaccinationCardView
        // in menu.xml is explicitly T-10's job, not T-08's (same precedent
        // as ExamCatalogList::__construct()'s own docblock) — TXMLBreadCrumb
        // throws when the class is not yet listed there, which would make
        // `new VaccinationCardView()` fatal ahead of that registration.
        $container = new TVBox;
        $container->style = 'width: 100%';

        $pageHeader = new TElement('header');
        $pageHeader->class = 'cv-page-header';
        $pageHeaderTitleWrap = new TElement('div');
        $pageHeaderTitle = new TElement('h1');
        $pageHeaderTitle->class = 'cv-page-title';
        $pageHeaderTitle->add(_t('Vaccination card'));
        $pageHeaderTitleWrap->add($pageHeaderTitle);
        $pageHeader->add($pageHeaderTitleWrap);
        $container->add($pageHeader);

        if ($patient_picker_panel !== null)
        {
            $container->add($patient_picker_panel);
        }

        $container->add($this->panel);

        parent::add($container);

        $this->onReload($param);
    }

    /**
     * Builds the patient picker shown when patient_id is absent (T-10):
     * a TDBUniqueSearch over Patient (tenant-scoped) + a "View card" button
     * that reloads this same screen (onReload) with patient_id set in the
     * querystring.
     */
    private function buildPatientPickerPanel()
    {
        try
        {
            $tenant_id = self::resolveTenantContext()->tenantId();
        }
        catch (\CentralVet\Tenancy\Exception\MissingTenantContext $e)
        {
            $tenant_id = -1;
        }

        $tenant_criteria = new TCriteria;
        $tenant_criteria->add(new TFilter('tenant_id', '=', $tenant_id));

        $picker_form = new BootstrapFormBuilder('form_VaccinationCardView_picker');
        $picker_form->setFormTitle(_t('Select a patient'));

        $patient_id_picker = new TDBUniqueSearch('patient_id_picker', 'permission', 'Patient', 'id', 'name', 'name', $tenant_criteria);
        $patient_id_picker->setSize('100%');
        $patient_id_picker->addValidation(_t('Patient'), new TRequiredValidator);

        $picker_form->addFields( [new TLabel(_t('Patient'))] );
        $picker_form->addFields( [$patient_id_picker] );

        $btn = $picker_form->addAction(_t('View card'), new TAction([$this, 'onSelectPatient']), 'fa:search');
        $btn->class = 'btn btn-sm btn-primary';

        return $picker_form;
    }

    /**
     * method onSelectPatient()
     * Executed when the picker's "View card" button is clicked. Reloads
     * this same screen (onReload) with the chosen patient_id in the
     * querystring, mirroring AdiantiCoreApplication::loadPage() usage
     * elsewhere in this package (e.g. ProductList::onReload()/PayableList).
     */
    public function onSelectPatient($param)
    {
        if (!empty($param['patient_id_picker']))
        {
            AdiantiCoreApplication::loadPage('VaccinationCardView', 'onReload', ['patient_id' => (int) $param['patient_id_picker']]);
        }
    }

    /**
     * method onReload()
     * Reloads the datagrid with the current patient's vaccination history,
     * calling VaccinationService::historyByPatient(). Never lets a
     * domain/tenancy exception escape as a fatal error — every failure is
     * translated into a treated TMessage.
     */
    public function onReload($param = null)
    {
        try
        {
            if (empty($this->patient_id))
            {
                return;
            }

            $this->datagrid->clear();

            TTransaction::open('permission');

            $service = $this->buildVaccinationService();
            $history = $service->historyByPatient($this->patient_id);

            TTransaction::close();

            foreach ($history as $vaccination)
            {
                $row = new stdClass;
                $row->vaccine_catalog_item_id = $vaccination->vaccineCatalogItemId();
                $row->dose_number             = $vaccination->doseNumber();
                $row->lot                      = $vaccination->lot();
                $row->expiry_date              = $vaccination->expiryDate() ? $vaccination->expiryDate()->format('d/m/Y') : '';
                $row->applied_at               = $vaccination->appliedAt()->format('d/m/Y H:i');
                $row->next_dose_at             = $vaccination->nextDoseAt() ? $vaccination->nextDoseAt()->format('d/m/Y') : _t('Not scheduled');

                $this->datagrid->addItem($row);
            }
        }
        catch (\CentralVet\Tenancy\Exception\MissingTenantContext $e)
        {
            TTransaction::rollback();
            new TMessage('error', _t('Your session does not have an active tenant. Please log in again'));
        }
        catch (\CentralVet\Authorization\Exception\AuthorizationDenied $e)
        {
            TTransaction::rollback();
            new TMessage('error', _t('You are not allowed to perform this action'));
        }
        catch (Exception $e) // in case of exception, never let it escape as a fatal error
        {
            TTransaction::rollback();
            new TMessage('error', $e->getMessage());
        }
    }

    /**
     * Builds CentralVet\Application\VaccinationService with tenant-aware
     * repositories, reusing the authenticated session's tenant context.
     * Must be called inside an open 'permission' TTransaction. Mirrors the
     * wiring already exercised by VaccinationForm::buildVaccinationService()
     * — historyByPatient() only reaches VaccinationRepositoryInterface, but
     * the service's constructor still requires every collaborator.
     */
    private function buildVaccinationService()
    {
        $tenant_context = self::resolveTenantContext();
        $connection = TTransaction::get();

        $vaccinations = new \CentralVet\Persistence\VaccinationRepository($tenant_context, $connection);
        $catalog = new \CentralVet\Persistence\VaccineCatalogRepository($tenant_context, $connection);
        $protocols = new \CentralVet\Persistence\VaccineProtocolRepository($tenant_context, $connection);
        $encounters = new \CentralVet\Persistence\EncounterRepository($tenant_context, $connection);

        $authorization = new \CentralVet\Authorization\RbacAuthorizationService(
            new \CentralVet\Authorization\AdiantiProgramPermissionProvider(new \CentralVet\Tenancy\AdiantiSessionContextSource()),
            new \CentralVet\Audit\PdoAuditLogWriter($connection),
        );

        return new \CentralVet\Application\VaccinationService($vaccinations, $catalog, $protocols, $encounters, $authorization, $tenant_context);
    }

    /**
     * Resolves the tenant context of the authenticated session, mirroring
     * the helper used by PatientList/SystemUnitForm (T-03). Duplicated here
     * (rather than shared) to avoid touching files outside T-08 scope.
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
