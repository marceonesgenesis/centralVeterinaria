<?php
/**
 * AgendaView
 *
 * Grade de agenda por profissional/dia (mock 03), consumindo
 * CentralVet\Application\AppointmentService::listByProfessionalAndDate()
 * (T-07). Nenhuma regra de negocio (conflito de horario, etc.) vive aqui:
 * ela ja foi aplicada em AppointmentService::schedule() no momento do
 * agendamento; esta tela apenas le e exibe o resultado.
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
class AgendaView extends TPage
{
    private const SLOT_START_MINUTES = 7 * 60;   // 07:00
    private const SLOT_END_MINUTES   = 19 * 60;  // 19:00
    private const SLOT_STEP_MINUTES  = 30;

    /** Bootstrap badge classes per Appointment::STATUS_* (T-07). */
    private const STATUS_BADGE_CLASS = [
        'agendado'       => 'badge bg-info text-dark',
        'confirmado'     => 'badge bg-primary',
        'em_atendimento' => 'badge bg-warning text-dark',
        'atendido'       => 'badge bg-success',
        'cancelado'      => 'badge bg-secondary',
        'faltou'         => 'badge bg-danger',
    ];

    /**
     * Class constructor.
     * Renders the grid for today's date on first load.
     */
    public function __construct()
    {
        parent::__construct();

        $this->onReload(['date' => date('Y-m-d')]);
    }

    /**
     * Rebuilds the whole page body for the requested date.
     * Also the target of the date-navigator buttons ("Anterior"/"Hoje"/"Proximo").
     */
    public function onReload($param)
    {
        $date_string = (isset($param['date']) && $param['date'] !== '') ? $param['date'] : date('Y-m-d');

        try
        {
            $date = new DateTimeImmutable($date_string);
        }
        catch (Exception $e)
        {
            $date = new DateTimeImmutable('today');
        }

        // page header (design system: .cv-page-header/.cv-page-title, T-02)
        $page_header = new TElement('header');
        $page_header->class = 'cv-page-header';
        $page_header_titlebox = new TElement('div');
        $page_header_title = new TElement('h1');
        $page_header_title->class = 'cv-page-title';
        $page_header_title->add(_t('Agenda'));
        $page_header_titlebox->add($page_header_title);
        $page_header->add($page_header_titlebox);

        $container = new TVBox;
        $container->style = 'width: 100%';
        $container->add($this->buildNavigator($date));

        try
        {
            $container->add($this->buildGrid($date));
        }
        catch (\CentralVet\Tenancy\Exception\MissingTenantContext $e)
        {
            $container->add(new TAlert('danger', _t('An authenticated session with a tenant is required')));
        }
        catch (Exception $e)
        {
            $container->add(new TAlert('danger', $e->getMessage()));
        }

        parent::add($page_header);
        parent::add($container);
    }

    /**
     * Date navigator: previous day / today / next day + "new appointment".
     */
    private function buildNavigator(DateTimeImmutable $date)
    {
        $bar = new TElement('div');
        $bar->class = 'agenda-navigator';
        $bar->style = 'display:flex; gap:8px; align-items:center; margin-bottom:10px';

        $title = new TElement('h4');
        $title->add(_t('Agenda') . ' - ' . $date->format('d/m/Y'));
        $title->style = 'margin:0 16px 0 0';

        // AgendaView is a pure TPage with no BootstrapFormBuilder/TForm of its
        // own, so there is no real <form> for these buttons to belong to —
        // setFormName() still must be non-empty (TButton::show() throws
        // otherwise, same bug/fix as EncounterView's context-strip buttons),
        // so an arbitrary consistent name is used; none of these actions read
        // posted form fields, they carry their data via TAction parameters.
        $prev = new TButton('agenda_prev');
        $prev->setAction(new TAction([$this, 'onReload'], ['date' => $date->modify('-1 day')->format('Y-m-d')]), _t('Previous'));
        $prev->setFormName('agenda_navigator');
        $prev->class = 'btn btn-sm btn-light';

        $today = new TButton('agenda_today');
        $today->setAction(new TAction([$this, 'onReload'], ['date' => date('Y-m-d')]), _t('Today'));
        $today->setFormName('agenda_navigator');
        $today->class = 'btn btn-sm btn-light';

        $next = new TButton('agenda_next');
        $next->setAction(new TAction([$this, 'onReload'], ['date' => $date->modify('+1 day')->format('Y-m-d')]), _t('Next'));
        $next->setFormName('agenda_navigator');
        $next->class = 'btn btn-sm btn-light';

        $new_appointment = new TButton('agenda_new');
        $new_appointment->setAction(new TAction(['AppointmentForm', 'onEdit'], ['scheduled_at' => $date->format('Y-m-d')]), _t('New appointment'));
        $new_appointment->setFormName('agenda_navigator');
        $new_appointment->class = 'btn btn-sm btn-primary';

        $bar->add($title);
        $bar->add($prev);
        $bar->add($today);
        $bar->add($next);
        $bar->add($new_appointment);

        return $bar;
    }

    /**
     * Builds the professional x time-slot grid for $date.
     *
     * @throws \CentralVet\Tenancy\Exception\MissingTenantContext when no
     *         authenticated tenant session is available.
     */
    private function buildGrid(DateTimeImmutable $date)
    {
        $tenant_context = self::resolveTenantContext();

        TTransaction::open('permission');

        $professionals = $this->listProfessionals($tenant_context);

        $service = self::buildAppointmentService($tenant_context);

        // professional_system_user_id => list<Appointment> for the day
        $appointments_by_professional = [];

        foreach ($professionals as $professional)
        {
            $appointments_by_professional[$professional->id] = $service->listByProfessionalAndDate((int) $professional->id, $date);
        }

        $patient_names = $this->resolvePatientNames($tenant_context, $appointments_by_professional);
        $service_names = $this->resolveServiceNames($tenant_context, $appointments_by_professional);

        TTransaction::close();

        return $this->renderGrid($professionals, $appointments_by_professional, $patient_names, $service_names);
    }

    /**
     * Resolves the patient name for every distinct patient_id referenced by
     * $appointments_by_professional, once per id (never once per grid cell),
     * via PatientService::findById() (T-05). Must run inside the same open
     * 'permission' transaction as buildGrid()'s other lookups.
     *
     * @param array<int|string, list<\CentralVet\Domain\Appointment>> $appointments_by_professional
     * @return array<int, string> patient_id => name
     */
    private function resolvePatientNames(\CentralVet\Tenancy\TenantContext $tenant_context, array $appointments_by_professional): array
    {
        $patients_repository = new \CentralVet\Persistence\PatientRepository($tenant_context, TTransaction::get());
        $tutors_repository = new \CentralVet\Persistence\TutorRepository($tenant_context, TTransaction::get());
        $patient_service = new \CentralVet\Application\PatientService($patients_repository, $tutors_repository, $tenant_context);

        $names = [];

        foreach ($appointments_by_professional as $appointments)
        {
            foreach ($appointments as $appointment)
            {
                $patient_id = (int) $appointment->patientId;

                if (array_key_exists($patient_id, $names))
                {
                    continue;
                }

                try
                {
                    $patient = $patient_service->findById($patient_id);
                    $names[$patient_id] = $patient !== null ? $patient->name : null;
                }
                catch (Exception $e)
                {
                    $names[$patient_id] = null;
                }
            }
        }

        return $names;
    }

    /**
     * Resolves the service name for every distinct service_id referenced by
     * $appointments_by_professional, once per id, via
     * ServiceCatalogService::findById(). Must run inside the same open
     * 'permission' transaction as buildGrid()'s other lookups.
     *
     * @param array<int|string, list<\CentralVet\Domain\Appointment>> $appointments_by_professional
     * @return array<int, string> service_id => name
     */
    private function resolveServiceNames(\CentralVet\Tenancy\TenantContext $tenant_context, array $appointments_by_professional): array
    {
        $services_repository = new \CentralVet\Persistence\ServiceRepository($tenant_context, TTransaction::get());
        $service_catalog = new \CentralVet\Application\ServiceCatalogService($services_repository, $tenant_context);

        $names = [];

        foreach ($appointments_by_professional as $appointments)
        {
            foreach ($appointments as $appointment)
            {
                $service_id = (int) $appointment->serviceId;

                if (array_key_exists($service_id, $names))
                {
                    continue;
                }

                try
                {
                    $catalog_service = $service_catalog->findById($service_id);
                    $names[$service_id] = $catalog_service !== null ? $catalog_service->name() : null;
                }
                catch (Exception $e)
                {
                    $names[$service_id] = null;
                }
            }
        }

        return $names;
    }

    /**
     * Professionals shown as grid columns: active system users of the
     * authenticated tenant. There is no dedicated "professional" flag on
     * system_user yet (out of scope of T-07/T-12), so every active user of
     * the tenant is a candidate column, exactly like the tenant-scoped
     * listing already used by SystemUserList (T-03).
     *
     * @return SystemUser[]
     */
    private function listProfessionals(\CentralVet\Tenancy\TenantContext $tenant_context): array
    {
        $criteria = new TCriteria;
        $criteria->add(new TFilter('active', '=', 'Y'));
        $criteria->add(new TFilter('id', 'IN', '(SELECT system_user_id FROM tenant_user WHERE tenant_id = ' . $tenant_context->tenantId() . ')'));

        return SystemUser::getObjects($criteria) ?: [];
    }

    /**
     * @param SystemUser[] $professionals
     * @param array<int|string, list<\CentralVet\Domain\Appointment>> $appointments_by_professional
     * @param array<int, string|null> $patient_names patient_id => name (null when not found)
     * @param array<int, string|null> $service_names service_id => name (null when not found)
     */
    private function renderGrid(array $professionals, array $appointments_by_professional, array $patient_names = [], array $service_names = [])
    {
        if (empty($professionals))
        {
            return new TAlert('info', _t('No professionals found for the authenticated tenant'));
        }

        $table = new TElement('table');
        $table->class = 'table table-bordered table-sm agenda-grid';
        $table->style = 'width:100%';

        $thead = new TElement('thead');
        $header_row = new TElement('tr');

        $time_header = new TElement('th');
        $time_header->add(_t('Time'));
        $header_row->add($time_header);

        foreach ($professionals as $professional)
        {
            $professional_header = new TElement('th');
            $professional_header->add($professional->name);
            $header_row->add($professional_header);
        }

        $thead->add($header_row);
        $table->add($thead);

        $tbody = new TElement('tbody');

        foreach ($this->buildTimeSlots() as $slot)
        {
            $row = new TElement('tr');

            $time_cell = new TElement('td');
            $time_cell->add($slot);
            $row->add($time_cell);

            foreach ($professionals as $professional)
            {
                $cell = new TElement('td');
                $appointments = $appointments_by_professional[$professional->id] ?? [];

                foreach ($appointments as $appointment)
                {
                    if ($appointment->scheduledAt->format('H:i') === $slot)
                    {
                        $cell->add($this->renderAppointmentBlock($appointment, $patient_names, $service_names));
                    }
                }

                $row->add($cell);
            }

            $tbody->add($row);
        }

        $table->add($tbody);

        return $table;
    }

    /** @return string[] list of "H:i" slots between SLOT_START_MINUTES and SLOT_END_MINUTES */
    private function buildTimeSlots(): array
    {
        $slots = [];

        for ($minutes = self::SLOT_START_MINUTES; $minutes < self::SLOT_END_MINUTES; $minutes += self::SLOT_STEP_MINUTES)
        {
            $slots[] = sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
        }

        return $slots;
    }

    /**
     * @param array<int, string|null> $patient_names patient_id => name (null when not found)
     * @param array<int, string|null> $service_names service_id => name (null when not found)
     */
    private function renderAppointmentBlock(\CentralVet\Domain\Appointment $appointment, array $patient_names = [], array $service_names = [])
    {
        $block = new TElement('div');
        $block->class = 'agenda-block';
        $block->style = 'padding:2px 4px; margin-bottom:2px; border-radius:4px';

        $badge = new TElement('span');
        $badge->class = self::STATUS_BADGE_CLASS[$appointment->status] ?? 'badge bg-light text-dark';
        $badge->add($appointment->status);

        $patient_id = (int) $appointment->patientId;
        $service_id = (int) $appointment->serviceId;

        $patient_label = ($patient_names[$patient_id] ?? null) !== null
            ? $patient_names[$patient_id]
            : _t('Patient') . ' #' . $patient_id . ' (' . _t('not found') . ')';

        $service_label = ($service_names[$service_id] ?? null) !== null
            ? $service_names[$service_id]
            : _t('Service') . ' #' . $service_id . ' (' . _t('not found') . ')';

        $block->add($badge);
        $block->add(' ' . $patient_label);
        $block->add(' - ' . $service_label);

        $edit_link = TElement::tag('a', '', [
            'href' => "javascript:__adianti_load_page('index.php?class=AppointmentForm&method=onEdit&key=" . $appointment->id . "')",
            'class' => 'agenda-block-link',
        ]);
        $block->add($edit_link);

        return $block;
    }

    /**
     * Wires an Application-layer AppointmentService instance, consistent
     * with the wiring already prepared (but not yet exercised) by
     * AppointmentRepository/ServiceRepository/PatientService (T-07/T-05).
     *
     * AppointmentService now requires an AuthorizationPolicyInterface
     * dependency (post-Fase-1 unit-scope check on schedule()); this view
     * never calls schedule() itself (only listByProfessionalAndDate()), but
     * still has to satisfy the constructor. Wired the same way as
     * AppointmentForm::buildAppointmentService() for consistency: the real
     * RbacAuthorizationService backed by AdiantiProgramPermissionProvider
     * and PdoAuditLogWriter against this same 'permission' connection.
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
