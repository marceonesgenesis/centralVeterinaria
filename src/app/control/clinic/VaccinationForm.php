<?php
/**
 * VaccinationForm
 *
 * Vaccine application screen (T-08): receives encounter_id/patient_id by
 * parameter (from EncounterView, wired in T-09 — out of this task's scope)
 * and lets the user pick a catalog vaccine/lot/expiry/dose, consuming
 * CentralVet\Application\VaccinationService::apply() (T-05) entirely. No
 * business rule of its own: stock decrement, next-dose-at calculation and
 * unit-scope authorization all live in VaccinationService::apply(), mirroring
 * how AppointmentForm (Fase 1) delegates to AppointmentService::schedule().
 *
 * When VaccinationService::apply() refuses the request
 * (AuthorizationDenied, CrossTenantReferenceException or a plain
 * InvalidArgumentException from a missing/invalid field), this screen
 * catches it and shows the refusal as a treated TMessage, never a fatal
 * error.
 *
 * PENDING: this screen depends on the `vaccination` table created by the
 * not-yet-applied migration
 * src/app/database/migrations/20260922_0004_phase3_prescription_exam_vaccine.sql
 * (T-01). Validated only with `php -l` / `new VaccinationForm()` (no fatal
 * error) until that migration is applied.
 *
 * @version    1.0
 * @package    control
 * @subpackage clinic
 */
class VaccinationForm extends TPage
{
    protected $encounter_id;
    protected $patient_id;
    protected $form;

    /**
     * Class constructor
     */
    public function __construct($param = null)
    {
        parent::__construct();

        parent::setTargetContainer('adianti_right_panel');

        $this->encounter_id = (isset($param['encounter_id']) && $param['encounter_id'] !== '')
            ? (int) $param['encounter_id']
            : null;
        $this->patient_id = (isset($param['patient_id']) && $param['patient_id'] !== '')
            ? (int) $param['patient_id']
            : null;

        // creates the form
        $this->form = new BootstrapFormBuilder('form_Vaccination');
        $this->form->setFormTitle(_t('Apply vaccine'));
        $this->form->enableClientValidation();

        // create the form fields
        $encounter_id = new TEntry('encounter_id');
        $patient_id = new TEntry('patient_id');
        $vaccine_catalog_item_id = new TEntry('vaccine_catalog_item_id');
        $lot = new TEntry('lot');
        $expiry_date = new TDate('expiry_date');
        $dose_number = new TEntry('dose_number');
        $professional_system_user_id = new TEntry('professional_system_user_id');

        $encounter_id->setValue($this->encounter_id);
        $patient_id->setValue($this->patient_id);
        $encounter_id->setEditable(FALSE);
        $patient_id->setEditable(FALSE);
        $vaccine_catalog_item_id->setNumericMask(0, '', '', false, false, false);
        $dose_number->setNumericMask(0, '', '');
        $professional_system_user_id->setNumericMask(0, '', '', false, false, false);
        $expiry_date->setMask('dd/mm/yyyy');
        $expiry_date->setDatabaseMask('yyyy-mm-dd');

        // add the fields
        $this->form->addFields( [new TLabel(_t('Encounter'))] );
        $this->form->addFields( [$encounter_id] );
        $this->form->addFields( [new TLabel(_t('Patient'))] );
        $this->form->addFields( [$patient_id] );
        $this->form->addFields( [new TLabel(_t('Vaccine (catalog item id)'))] );
        $this->form->addFields( [$vaccine_catalog_item_id] );
        $this->form->addFields( [new TLabel(_t('Lot'))] );
        $this->form->addFields( [$lot] );
        $this->form->addFields( [new TLabel(_t('Expiry date'))] );
        $this->form->addFields( [$expiry_date] );
        $this->form->addFields( [new TLabel(_t('Dose number'))] );
        $this->form->addFields( [$dose_number] );
        $this->form->addFields( [new TLabel(_t('Professional'))] );
        $this->form->addFields( [$professional_system_user_id] );

        $encounter_id->setSize('30%');
        $patient_id->setSize('30%');
        $vaccine_catalog_item_id->setSize('100%');
        $lot->setSize('100%');
        $expiry_date->setSize('50%');
        $dose_number->setSize('30%');
        $professional_system_user_id->setSize('50%');

        $vaccine_catalog_item_id->addValidation( _t('Vaccine'), new TRequiredValidator );
        $dose_number->addValidation( _t('Dose number'), new TRequiredValidator );
        $professional_system_user_id->addValidation( _t('Professional'), new TRequiredValidator );

        // create the form actions
        $btn = $this->form->addAction(_t('Apply'), new TAction(array($this, 'onSave')), 'fa:check');
        $btn->class = 'btn btn-sm btn-primary';
        $this->form->addActionLink(_t('Clear'), new TAction(array($this, 'onEdit')), 'fa:eraser red');

        $this->form->addHeaderActionLink(_t('Close'), new TAction([$this, 'onClose']), 'fa:times red');

        // vertical box container
        $container = new TVBox;
        $container->style = 'width: 100%';

        $pageHeader = new TElement('header');
        $pageHeader->class = 'cv-page-header';
        $pageHeaderTitleWrap = new TElement('div');
        $pageHeaderTitle = new TElement('h1');
        $pageHeaderTitle->class = 'cv-page-title';
        $pageHeaderTitle->add(_t('Apply vaccine'));
        $pageHeaderTitleWrap->add($pageHeaderTitle);
        $pageHeader->add($pageHeaderTitleWrap);
        $container->add($pageHeader);

        $container->add($this->form);

        parent::add($container);
    }

    /**
     * on close
     */
    public static function onClose($param)
    {
        TScript::create("Template.closeRightPanel()");
    }

    /**
     * method onEdit()
     * Clears the form, keeping encounter_id/patient_id prefilled from the
     * constructor's parameters.
     */
    public function onEdit($param)
    {
        $this->form->clear();
        $this->form->setData((object) [
            'encounter_id' => $this->encounter_id,
            'patient_id'   => $this->patient_id,
        ]);
    }

    /**
     * method onSave()
     * Executed whenever the user clicks "Apply". Calls
     * VaccinationService::apply() and turns every business-rule refusal
     * (unit-scope authorization, cross-tenant reference, invalid data) into
     * a message shown on screen instead of a fatal error.
     */
    public function onSave($param = null)
    {
        try
        {
            $data = $this->form->getData();
            $this->form->validate();

            TTransaction::open('permission');

            $tenant_context = self::resolveTenantContext();
            $service = self::buildVaccinationService($tenant_context);

            $vaccination = $service->apply([
                'encounter_id'                 => $data->encounter_id,
                'patient_id'                    => $data->patient_id,
                'vaccine_catalog_item_id'      => $data->vaccine_catalog_item_id,
                'lot'                            => $data->lot,
                'expiry_date'                    => $data->expiry_date,
                'dose_number'                    => $data->dose_number,
                'professional_system_user_id'  => $data->professional_system_user_id,
            ], __CLASS__ . '::' . __FUNCTION__);

            TTransaction::close();

            new TMessage('info', _t('Vaccine applied successfully'));
            TScript::create("Template.closeRightPanel(); VaccinationCardView.onReload();");

            return $vaccination;
        }
        catch (\CentralVet\Domain\Exception\CrossTenantReferenceException $e)
        {
            TTransaction::rollback();
            $this->form->setData($data ?? null);
            new TMessage('error', $e->getMessage());
        }
        catch (\CentralVet\Authorization\Exception\AuthorizationDenied $e)
        {
            // Unit-scope authorization refusal (the active unit does not
            // match the origin encounter's own unit): handled the same way
            // as the other business-rule refusals above, never a fatal
            // error.
            TTransaction::rollback();
            $this->form->setData($data ?? null);
            new TMessage('error', _t('You are not allowed to apply a vaccine for this unit'));
        }
        catch (\CentralVet\Tenancy\Exception\MissingTenantContext $e)
        {
            TTransaction::rollback();
            new TMessage('error', _t('An authenticated session with a tenant is required'));
        }
        catch (InvalidArgumentException $e)
        {
            TTransaction::rollback();
            $this->form->setData($data ?? null);
            new TMessage('error', $e->getMessage());
        }
        catch (Exception $e) // catch-all: never let a fatal error reach the screen
        {
            TTransaction::rollback();
            $this->form->setData($data ?? null);
            new TMessage('error', $e->getMessage());
        }
    }

    /**
     * Wires an Application-layer VaccinationService instance, following the
     * same wiring convention already exercised by
     * AppointmentForm::buildAppointmentService() (Fase 1): a real
     * RbacAuthorizationService (Fase 0), backed by
     * AdiantiProgramPermissionProvider (reads the same programs/methods
     * session keys SystemPermission::checkPermission() already uses) and
     * PdoAuditLogWriter against this same 'permission' connection, so every
     * apply() call is both unit-scope-checked and audited to `audit_log`.
     */
    private static function buildVaccinationService(\CentralVet\Tenancy\TenantContext $context): \CentralVet\Application\VaccinationService
    {
        $connection = TTransaction::get();

        $vaccinations = new \CentralVet\Persistence\VaccinationRepository($context, $connection);
        $catalog = new \CentralVet\Persistence\VaccineCatalogRepository($context, $connection);
        $protocols = new \CentralVet\Persistence\VaccineProtocolRepository($context, $connection);
        $encounters = new \CentralVet\Persistence\EncounterRepository($context, $connection);

        $authorization = new \CentralVet\Authorization\RbacAuthorizationService(
            new \CentralVet\Authorization\AdiantiProgramPermissionProvider(new \CentralVet\Tenancy\AdiantiSessionContextSource()),
            new \CentralVet\Audit\PdoAuditLogWriter($connection),
        );

        return new \CentralVet\Application\VaccinationService($vaccinations, $catalog, $protocols, $encounters, $authorization, $context);
    }

    /**
     * Resolves the tenant context of the authenticated session (T-03),
     * mirroring SystemUnitForm::resolveTenantContext() (same fallback for
     * legacy sessions where TSession does not carry 'tenantid' yet).
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
