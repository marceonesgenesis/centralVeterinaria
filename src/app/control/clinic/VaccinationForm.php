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

        $this->encounter_id = self::paramInt('encounter_id', $param);
        $this->patient_id = self::paramInt('patient_id', $param);

        // creates the form
        $this->form = new BootstrapFormBuilder('form_Vaccination');
        $this->form->setFormTitle(_t('Apply vaccine'));
        $this->form->enableClientValidation();

        // contexto (vem da URL, sem edição)
        $encounter_id = new THidden('encounter_id');
        $patient_id = new THidden('patient_id');
        $encounter_id->setValue($this->encounter_id);
        $patient_id->setValue($this->patient_id);

        $vaccine_catalog_item_id = new TCombo('vaccine_catalog_item_id');
        $vaccine_catalog_item_id->addItems($this->loadCatalogOptions());
        $lot = new TEntry('lot');
        $expiry_date = new TDate('expiry_date');
        $dose_number = new TEntry('dose_number');

        // system_user não tem tenant_id: combo sem filtro de tenant (mesma
        // exceção documentada em PrescriptionForm)
        $professional_system_user_id = CvTenantUsers::combo('professional_system_user_id', static fn () => self::resolveTenantContext());
        $professional_system_user_id->setValue(TSession::getValue('userid'));

        // PATTERN0: a máscara numérica com 0 decimais grava pattern \d{1,0} (inválido)
        $dose_number->setNumericMask(0, '', '');
        $dose_number->setProperty('pattern', '[0-9]*');
        $expiry_date->setMask('dd/mm/yyyy');
        $expiry_date->setDatabaseMask('yyyy-mm-dd');

        $hiddenRow = $this->form->addFields([$encounter_id, $patient_id]);
        $hiddenRow->style = 'display: none';

        $this->form->addFields(
            [new TLabel(_t('Vaccine'))], [$vaccine_catalog_item_id],
            [new TLabel(_t('Professional'))], [$professional_system_user_id]
        );
        $this->form->addFields(
            [new TLabel(_t('Lot'))], [$lot],
            [new TLabel(_t('Expiry date'))], [$expiry_date]
        );
        $this->form->addFields(
            [new TLabel(_t('Dose number'))], [$dose_number]
        );

        $vaccine_catalog_item_id->addValidation( _t('Vaccine'), new TRequiredValidator );
        $dose_number->addValidation( _t('Dose number'), new TRequiredValidator );
        $professional_system_user_id->addValidation( _t('Professional'), new TRequiredValidator );

        // create the form actions
        $btn = $this->form->addAction(_t('Apply'), new TAction(array($this, 'onSave')), 'fa:check');
        $btn->class = 'btn btn-primary';
        $this->form->addActionLink(_t('Clear'), new TAction(array($this, 'onEdit'), [
            'encounter_id' => $this->encounter_id,
            'patient_id'   => $this->patient_id,
        ]), 'fa:eraser');

        CvForm::decorate($this->form, 2);

        // vertical box container
        $container = new TVBox;
        $container->style = 'width: 100%';

        $headerActions = [];
        if ($this->encounter_id !== null)
        {
            $headerActions[] = [
                'icon' => 'fa:arrow-left',
                'href' => self::returnUrl($this->encounter_id, $this->patient_id),
            ];
        }
        $container->add(CvPage::header(_t('Apply vaccine'), null, $headerActions));

        if ($this->encounter_id === null || $this->patient_id === null)
        {
            $notice = new TElement('div');
            $notice->class = 'alert alert-warning';
            $notice->role = 'status';
            $notice->add(new TImage('fa:info-circle'));
            $notice->add(' ' . CvFormat::e(_t('Open from the encounter')));
            $container->add($notice);
        }
        else
        {
            $container->add($this->form);
        }

        parent::add($container);
    }

    /**
     * Pós-salvar/voltar: o atendimento de contexto; sem ele, a carteira do
     * paciente.
     */
    private static function returnUrl(?int $encounterId, ?int $patientId): string
    {
        if ($encounterId !== null && $encounterId > 0)
        {
            return 'index.php?class=EncounterView&encounter_id=' . $encounterId;
        }

        return 'index.php?class=VaccinationCardView' . ($patientId ? '&patient_id=' . $patientId : '');
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
     * Opções do combo de vacinas a partir de VaccineCatalogService::listActive().
     * Falha de banco vira TMessage e deixa o combo vazio (nunca fatal).
     */
    private function loadCatalogOptions(): array
    {
        try
        {
            $context = self::resolveTenantContext();

            TTransaction::open('permission');

            $catalog = new \CentralVet\Application\VaccineCatalogService(
                new \CentralVet\Persistence\VaccineCatalogRepository($context, TTransaction::get()),
                $context
            );

            $options = [];
            foreach ($catalog->listActive() as $item)
            {
                $options[$item->id()] = $item->name() . ' (' . _t('Stock') . ': ' . $item->stockQuantity() . ')';
            }

            TTransaction::close();

            return $options;
        }
        catch (Exception $e)
        {
            TTransaction::rollback();
            new TMessage('warning', $e->getMessage());

            return [];
        }
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
            'professional_system_user_id' => TSession::getValue('userid'),
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

            TToast::show('success', _t('Vaccine applied successfully'));
            $encounterId = (isset($data->encounter_id) && $data->encounter_id !== '') ? (int) $data->encounter_id : null;
            $patientId = (isset($data->patient_id) && $data->patient_id !== '') ? (int) $data->patient_id : null;
            TScript::create("__adianti_goto_page('" . self::returnUrl($encounterId, $patientId) . "')");

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

        return new \CentralVet\Application\VaccinationService($vaccinations, $catalog, $protocols, $encounters, $authorization, $context, new \CentralVet\Persistence\TenantUserDirectory($context, $connection));
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
