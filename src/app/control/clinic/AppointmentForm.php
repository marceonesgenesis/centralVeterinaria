<?php
/**
 * AppointmentForm
 *
 * Formulario de agendamento (mock 03), consumindo
 * CentralVet\Application\AppointmentService::schedule() (T-07) e, com
 * `key` na URL, AppointmentService::reschedule() (rodada 2, T-08). Nao
 * contem regra de negocio propria: validacao de conflito de horario e
 * de referencias entre tenants e feita inteiramente por AppointmentService.
 *
 * Quando AppointmentService::schedule() recusa um conflito de horario
 * (CentralVet\Domain\Exception\SchedulingConflictException), esta tela
 * captura a excecao e exibe a mensagem de recusa em tela, sem propagar
 * um erro fatal.
 *
 * PENDENTE: a tabela `appointment` ainda depende da migration
 * src/app/database/migrations/20260921_0002_phase1_clinic_core.sql (T-01),
 * que ainda nao foi aplicada ao MySQL. Esta classe e apenas
 * preparada/validada com `php -l`; nao deve ser executada contra um banco
 * real antes da migration ser aplicada.
 *
 * @version    8.6
 * @package    control
 * @subpackage clinic
 * @author     Pablo Dall'Oglio
 * @copyright  Copyright (c) 2006 Adianti Solutions Ltd. (http://www.adianti.com.br)
 * @license    https://adiantiframework.com.br/license-template
 */
class AppointmentForm extends TPage
{
    protected $form; // form

    /** @var int|null agendamento aberto para remarcar (key na URL, vindo da AgendaView) */
    protected $viewId = null;

    /**
     * Class constructor
     * Creates the appointment scheduling form in full page (kit Cv*); with
     * key in the URL the appointment opens for rescheduling (service,
     * professional and date/time editable, patient read-only).
     */
    public function __construct($param = null)
    {
        parent::__construct();

        $key = $param['key'] ?? null;
        $this->viewId = (is_numeric($key) && (int) $key > 0) ? (int) $key : null;

        // resolveTenantContext() is only guaranteed after an authenticated
        // session; the constructor must never throw (mirrors onSave()'s own
        // MissingTenantContext handling below), so an unresolved tenant here
        // falls back to an impossible tenant_id — the search widgets just
        // return zero matches instead of a fatal error.
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

        // creates the form
        $this->form = new BootstrapFormBuilder('form_Appointment');
        $this->form->enableClientValidation();

        // create the form fields
        $patient_id = new TDBUniqueSearch('patient_id', 'permission', 'Patient', 'id', 'name', 'name', $tenant_criteria);
        $service_id = new TDBCombo('service_id', 'permission', 'Service', 'id', 'name', 'name', $tenant_criteria);
        // professional_system_user_id: SystemUser is native Adianti (no
        // tenant_id column of its own, the link is via tenant_user, which
        // TCriteria cannot reach) — documented exception, same pattern as
        // the only precedent (SystemUserForm.php's frontpage_id search).
        $professional_system_user_id = new TDBUniqueSearch('professional_system_user_id', 'permission', 'SystemUser', 'id', 'name', 'name');
        $scheduled_at = new TDateTime('scheduled_at');

        // add the fields (pares rótulo/campo em 2 colunas)
        $this->form->addFields( [new TLabel(_t('Patient'))], [$patient_id], [new TLabel(_t('Service'))], [$service_id] );
        $this->form->addFields( [new TLabel(_t('Professional'))], [$professional_system_user_id], [new TLabel(_t('Date/time'))], [$scheduled_at] );

        if ($this->viewId === null)
        {
            // remarcar: paciente só leitura, fora da validação
            $patient_id->addValidation( _t('Patient'), new TRequiredValidator );
        }
        $service_id->addValidation( _t('Service'), new TRequiredValidator );
        $professional_system_user_id->addValidation( _t('Professional'), new TRequiredValidator );
        $scheduled_at->addValidation( _t('Date/time'), new TRequiredValidator );

        $back_date = isset($param['scheduled_at']) ? substr((string) $param['scheduled_at'], 0, 10) : date('Y-m-d');
        $back = ['label' => '', 'icon' => 'fa:arrow-left', 'action' => new TAction(['AgendaView', 'onReload'], ['date' => $back_date])];

        if ($this->viewId === null)
        {
            // create the form actions
            $btn = $this->form->addAction(_t('Schedule'), new TAction(array($this, 'onSave')), 'fa:check');
            $btn->class = 'btn btn-sm btn-primary';
            $this->form->addActionLink(_t('Clear'), new TAction(array($this, 'onEdit')), 'fa:eraser');
        }
        else
        {
            // remarcar: o paciente não muda (AppointmentService::reschedule)
            $patient_id->setEditable(FALSE);

            $btn = $this->form->addAction(_t('Save'), new TAction(array($this, 'onSave'), ['key' => $this->viewId]), 'fa:check');
            $btn->class = 'btn btn-sm btn-primary';
        }

        CvForm::decorate($this->form, 2);

        // vertical box container
        $container = new TVBox;
        $container->style = 'width: 100%';
        $container->add(CvPage::header($this->viewId === null ? _t('New appointment') : _t('Appointment'), null, [$back]));
        $container->add($this->form);

        parent::add($container);
    }

    /**
     * method onEdit()
     * Prefills the form (e.g. with a date coming from AgendaView's date
     * navigator), loads the appointment for rescheduling when a key is
     * given (AgendaView block link), or clears it.
     */
    public function onEdit($param)
    {
        if ($this->viewId !== null)
        {
            try
            {
                TTransaction::open('permission');

                $appointment = self::buildAppointmentService(self::resolveTenantContext())->findById($this->viewId);

                TTransaction::close();

                if ($appointment === null)
                {
                    // key inexistente ou de outro tenant: só leitura, sem Salvar
                    $this->form->setEditable(FALSE);
                    $this->form->delActions();
                    new TMessage('error', _t('Record not found'));
                    return;
                }

                $this->form->setData((object) [
                    'patient_id' => $appointment->patientId,
                    'service_id' => $appointment->serviceId,
                    'professional_system_user_id' => $appointment->professionalSystemUserId,
                    'scheduled_at' => $appointment->scheduledAt->format('Y-m-d H:i'),
                ]);
            }
            catch (Exception $e)
            {
                TTransaction::rollback();
                new TMessage('error', $e->getMessage());
            }
            return;
        }

        if (isset($param['scheduled_at']))
        {
            $this->form->setData((object) ['scheduled_at' => $param['scheduled_at']]);
        }
        else
        {
            $this->form->clear();
        }
    }

    /**
     * method onSave()
     * Executed whenever the user clicks "Schedule" (new appointment, calls
     * AppointmentService::schedule()) or "Save" (key given, calls
     * AppointmentService::reschedule()). Every business-rule refusal
     * (scheduling conflict, status, cross-tenant reference, invalid data)
     * becomes a message shown on screen instead of a fatal error.
     */
    public function onSave($param)
    {
        $data = null;

        try
        {
            $data = $this->form->getData();

            if ($this->viewId !== null)
            {
                $this->onReschedule($data);
                return;
            }

            $this->form->validate();

            TTransaction::open('permission');

            $tenant_context = self::resolveTenantContext();
            $service = self::buildAppointmentService($tenant_context);

            $appointment = $service->schedule([
                'patient_id' => (int) $data->patient_id,
                'service_id' => (int) $data->service_id,
                'professional_system_user_id' => (int) $data->professional_system_user_id,
                'scheduled_at' => (string) $data->scheduled_at,
                'system_unit_id' => $tenant_context->requireUnitId(),
            ], __CLASS__ . '::' . __FUNCTION__);

            TTransaction::close();

            // volta para a agenda do dia agendado
            new TMessage('info', _t('Appointment scheduled successfully'), new TAction(['AgendaView', 'onReload'], [
                'date' => $appointment->scheduledAt->format('Y-m-d'),
            ]));
        }
        catch (\CentralVet\Domain\Exception\SchedulingConflictException $e)
        {
            // Critério de aceite (T-12): conflito de horário recusado por
            // AppointmentService::schedule() vira mensagem tratada na tela,
            // nunca uma exceção não tratada.
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
            // Unit-scope authorization refusal (e.g. the active unit does
            // not match the appointment's system_unit_id): handled the same
            // way as the other business-rule refusals above, never a fatal
            // error.
            TTransaction::rollback();
            new TMessage('error', _t('You are not allowed to schedule an appointment for this unit'));
        }
        catch (\CentralVet\Tenancy\Exception\MissingTenantContext $e)
        {
            TTransaction::rollback();
            new TMessage('error', _t('An authenticated session with a tenant is required'));
        }
        catch (InvalidArgumentException $e)
        {
            TTransaction::rollback();
            new TMessage('error', $e->getMessage());
        }
        catch (Exception $e) // catch-all: never let a fatal error reach the screen
        {
            TTransaction::rollback();
            new TMessage('error', $e->getMessage());
        }
    }

    /**
     * Reschedule branch of onSave() (key given): the patient field is
     * read-only, so only service, professional and date/time are validated
     * and sent. Refusals are shown in TMessage('error') and the typed values
     * stay on the form.
     */
    private function onReschedule($data)
    {
        try
        {
            $this->form->validate();

            TTransaction::open('permission');

            $service = self::buildAppointmentService(self::resolveTenantContext());

            $appointment = $service->reschedule($this->viewId, [
                'service_id' => (int) $data->service_id,
                'professional_system_user_id' => (int) $data->professional_system_user_id,
                'scheduled_at' => (string) $data->scheduled_at,
            ], __CLASS__ . '::onSave');

            TTransaction::close();

            new TMessage('info', _t('Record saved'), new TAction(['AgendaView', 'onReload'], [
                'date' => $appointment->scheduledAt->format('Y-m-d'),
            ]));
        }
        catch (\CentralVet\Authorization\Exception\AuthorizationDenied $e)
        {
            TTransaction::rollback();
            $this->keepRescheduleData($data);
            new TMessage('error', _t('You are not allowed to schedule an appointment for this unit'));
        }
        catch (\CentralVet\Tenancy\Exception\MissingTenantContext $e)
        {
            TTransaction::rollback();
            $this->keepRescheduleData($data);
            new TMessage('error', _t('An authenticated session with a tenant is required'));
        }
        catch (Exception $e) // conflito, status, outro tenant, dado inválido
        {
            TTransaction::rollback();
            $this->keepRescheduleData($data);
            new TMessage('error', $e->getMessage());
        }
    }

    /** Keeps the typed values (and the read-only patient) after a refused reschedule. */
    private function keepRescheduleData($data)
    {
        try
        {
            TTransaction::open('permission');
            $current = self::buildAppointmentService(self::resolveTenantContext())->findById($this->viewId);
            TTransaction::close();

            if ($current !== null)
            {
                $data->patient_id = $current->patientId;
            }
        }
        catch (Exception $e)
        {
            TTransaction::rollback();
        }

        $this->form->setData($data);
    }

    /**
     * Wires an Application-layer AppointmentService instance, consistent
     * with the wiring already prepared (but not yet exercised) by
     * AppointmentRepository/ServiceRepository/PatientService (T-07/T-05).
     *
     * The authorization dependency is the real RbacAuthorizationService
     * (Fase 0), backed by AdiantiProgramPermissionProvider (reads the same
     * programs/methods session keys SystemPermission::checkPermission()
     * already uses) and PdoAuditLogWriter against this same 'permission'
     * connection, so every schedule() call is both unit-scope-checked and
     * audited to `audit_log`.
     */
    private static function buildAppointmentService(\CentralVet\Tenancy\TenantContext $context): \CentralVet\Application\AppointmentService
    {
        $connection = TTransaction::get();

        $appointments = new \CentralVet\Persistence\AppointmentRepository($context, $connection);
        $services = new \CentralVet\Persistence\ServiceRepository($context, $connection);
        $patients_repository = new \CentralVet\Persistence\PatientRepository($context, $connection);
        $tutors_repository = new \CentralVet\Persistence\TutorRepository($context, $connection);
        $patients = new \CentralVet\Application\PatientService($patients_repository, $tutors_repository, $context);

        $authorization = new \CentralVet\Authorization\RbacAuthorizationService(
            new \CentralVet\Authorization\AdiantiProgramPermissionProvider(new \CentralVet\Tenancy\AdiantiSessionContextSource()),
            new \CentralVet\Audit\PdoAuditLogWriter($connection),
        );

        return new \CentralVet\Application\AppointmentService($appointments, $services, $patients, $context, $authorization);
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
