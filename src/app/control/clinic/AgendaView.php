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

    /** Appointment::STATUS_* (T-07) → [rótulo en, tom do CvBadge]. */
    private const STATUS_BADGE = [
        'agendado'       => ['Scheduled', 'info'],
        'confirmado'     => ['Confirmed', 'info'],
        'em_atendimento' => ['In service', 'warning'],
        'atendido'       => ['Attended', 'success'],
        'cancelado'      => ['Canceled', 'neutral'],
        'faltou'         => ['No-show', 'danger'],
    ];

    /**
     * Class constructor.
     * Renders the grid for today's date on first load (when no method was
     * requested — otherwise the dispatcher calls onReload() itself and the
     * page would be rendered twice).
     */
    public function __construct($param = null)
    {
        parent::__construct();

        if (empty($param['method']))
        {
            $this->onReload(['date' => date('Y-m-d')]);
        }
    }

    /**
     * Rebuilds the whole page body for the requested date.
     * Also the target of the date-navigator actions ("Anterior"/"Hoje"/"Proximo").
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

        $container = new TVBox;
        $container->style = 'width: 100%';
        $container->add($this->buildHeader($date));

        $card = new TElement('div');
        $card->{'class'} = 'cv-card';
        $body = new TElement('div');
        $body->{'class'} = 'cv-card__body';
        $body->style = 'overflow-x:auto';
        $card->add($body);

        try
        {
            $body->add($this->buildGrid($date));
        }
        catch (\CentralVet\Tenancy\Exception\MissingTenantContext $e)
        {
            $body->add(new TAlert('danger', _t('An authenticated session with a tenant is required')));
        }
        catch (Exception $e)
        {
            $body->add(new TAlert('danger', $e->getMessage()));
        }

        $container->add($card);

        parent::add($container);
    }

    /**
     * CvPage header with the date navigator: previous day / today / next
     * day + "new appointment" (links, no form involved).
     */
    private function buildHeader(DateTimeImmutable $date)
    {
        return CvPage::header(_t('Agenda'), $date->format('d/m/Y'), [
            ['label' => _t('Previous'), 'icon' => 'fa:chevron-left', 'action' => new TAction([$this, 'onReload'], ['date' => $date->modify('-1 day')->format('Y-m-d')])],
            ['label' => _t('Today'), 'action' => new TAction([$this, 'onReload'], ['date' => date('Y-m-d')])],
            ['label' => _t('Next'), 'icon' => 'fa:chevron-right', 'action' => new TAction([$this, 'onReload'], ['date' => $date->modify('+1 day')->format('Y-m-d')])],
            ['label' => _t('New appointment'), 'icon' => 'fa:plus', 'class' => 'btn btn-primary', 'action' => new TAction(['AppointmentForm', 'onEdit'], ['scheduled_at' => $date->format('Y-m-d')])],
        ]);
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
        $in_queue = $this->resolveAppointmentsInQueue($tenant_context, $appointments_by_professional);

        TTransaction::close();

        return $this->renderGrid($professionals, $appointments_by_professional, $patient_names, $service_names, $in_queue);
    }

    /**
     * Ids of the day's appointments that already have a queue entry (T-41),
     * in one QueueEntryService::appointmentIdsInQueue() call per load. Must
     * run inside buildGrid()'s open 'permission' transaction.
     *
     * @param array<int|string, list<\CentralVet\Domain\Appointment>> $appointments_by_professional
     * @return array<int, true> appointment_id => true
     */
    private function resolveAppointmentsInQueue(\CentralVet\Tenancy\TenantContext $tenant_context, array $appointments_by_professional): array
    {
        $appointment_ids = [];

        foreach ($appointments_by_professional as $appointments)
        {
            foreach ($appointments as $appointment)
            {
                $appointment_ids[] = (int) $appointment->id;
            }
        }

        if (empty($appointment_ids))
        {
            return [];
        }

        $ids = self::buildQueueEntryService($tenant_context)->appointmentIdsInQueue($appointment_ids);

        return array_fill_keys($ids, true);
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
     * @param array<int, true> $in_queue appointment_id => true when already in the queue (T-41)
     */
    private function renderGrid(array $professionals, array $appointments_by_professional, array $patient_names = [], array $service_names = [], array $in_queue = [])
    {
        if (empty($professionals))
        {
            return new TAlert('info', _t('No professionals found for the authenticated tenant'));
        }

        $table = new TElement('table');
        $table->class = 'table cv-table agenda-grid';
        $table->style = 'width:100%';

        $thead = new TElement('thead');
        $header_row = new TElement('tr');

        $time_header = new TElement('th');
        $time_header->add(_t('Time'));
        $header_row->add($time_header);

        foreach ($professionals as $professional)
        {
            $professional_header = new TElement('th');
            $professional_header->add(CvFormat::e((string) $professional->name));
            $header_row->add($professional_header);
        }

        $thead->add($header_row);
        $table->add($thead);

        $tbody = new TElement('tbody');
        $agenda_slots = self::agendaSlots();

        // agrupa por linha da grade: horário fora do slot exato cai no slot
        // anterior (14:21 → 14:00); fora da grade, no primeiro/último slot
        $appointments_by_cell = [];

        foreach ($professionals as $professional)
        {
            $appointments = $appointments_by_professional[$professional->id] ?? [];
            usort($appointments, static fn ($a, $b) => $a->scheduledAt <=> $b->scheduledAt);

            foreach ($appointments as $appointment)
            {
                $appointments_by_cell[$agenda_slots->slotFor($appointment->scheduledAt)][$professional->id][] = $appointment;
            }
        }

        foreach ($this->buildTimeSlots() as $slot)
        {
            $row = new TElement('tr');

            $time_cell = new TElement('td');
            $time_cell->add($slot);
            $row->add($time_cell);

            foreach ($professionals as $professional)
            {
                $cell = new TElement('td');

                foreach ($appointments_by_cell[$slot][$professional->id] ?? [] as $appointment)
                {
                    $cell->add($this->renderAppointmentBlock($appointment, $patient_names, $service_names, isset($in_queue[(int) $appointment->id])));
                }

                $row->add($cell);
            }

            $tbody->add($row);
        }

        $table->add($tbody);

        return $table;
    }

    private static function agendaSlots(): \CentralVet\Application\AgendaSlots
    {
        return new \CentralVet\Application\AgendaSlots(self::SLOT_START_MINUTES, self::SLOT_END_MINUTES, self::SLOT_STEP_MINUTES);
    }

    /** @return string[] list of "H:i" slots between SLOT_START_MINUTES and SLOT_END_MINUTES */
    private function buildTimeSlots(): array
    {
        return self::agendaSlots()->slots();
    }

    /**
     * @param array<int, string|null> $patient_names patient_id => name (null when not found)
     * @param array<int, string|null> $service_names service_id => name (null when not found)
     * @param bool $in_queue the appointment already has a queue entry (T-41)
     */
    private function renderAppointmentBlock(\CentralVet\Domain\Appointment $appointment, array $patient_names = [], array $service_names = [], bool $in_queue = false)
    {
        $block = new TElement('div');
        $block->class = 'agenda-block';
        $block->style = 'padding:2px 4px; margin-bottom:2px; border-radius:4px';

        [$status_label, $status_tone] = isset(self::STATUS_BADGE[$appointment->status])
            ? [_t(self::STATUS_BADGE[$appointment->status][0]), self::STATUS_BADGE[$appointment->status][1]]
            : [$appointment->status, 'neutral'];
        $badge = CvBadge::create($status_label, $status_tone);

        $patient_id = (int) $appointment->patientId;
        $service_id = (int) $appointment->serviceId;

        $patient_label = ($patient_names[$patient_id] ?? null) !== null
            ? $patient_names[$patient_id]
            : _t('Patient') . ' #' . $patient_id . ' (' . _t('not found') . ')';

        $service_label = ($service_names[$service_id] ?? null) !== null
            ? $service_names[$service_id]
            : _t('Service') . ' #' . $service_id . ' (' . _t('not found') . ')';

        $block->add($badge);

        // horário exato, já que a linha da grade é o slot arredondado
        $block->add(TElement::tag('span', $appointment->scheduledAt->format('H:i'), ['class' => 'agenda-block-time ms-1']));

        // abre o agendamento em página cheia (AppointmentForm, modo leitura)
        $edit_link = TElement::tag('a', CvFormat::e($patient_label . ' - ' . $service_label), [
            'href' => 'index.php?class=AppointmentForm&method=onEdit&key=' . (int) $appointment->id
                    . '&scheduled_at=' . $appointment->scheduledAt->format('Y-m-d'),
            'generator' => 'adianti',
            'class' => 'agenda-block-link ms-1',
        ]);
        $block->add($edit_link);

        // já na fila (T-41): badge no lugar do Check-in
        if ($in_queue)
        {
            $queued_badge = CvBadge::create(_t('In queue'), 'info');
            $queued_badge->class .= ' agenda-block-queued ms-1';
            $block->add($queued_badge);
        }
        // check-in na fila (T-29): só agendado/confirmado; confirma antes
        elseif (in_array($appointment->status, [\CentralVet\Domain\Appointment::STATUS_SCHEDULED, \CentralVet\Domain\Appointment::STATUS_CONFIRMED], true))
        {
            $block->add(TElement::tag('a', CvFormat::e(_t('Check-in')), [
                'href' => 'index.php?class=AgendaView&method=onAskCheckIn&static=1&appointment_id=' . (int) $appointment->id
                        . '&date=' . $appointment->scheduledAt->format('Y-m-d'),
                'generator' => 'adianti',
                'class' => 'agenda-block-checkin ms-1',
            ]));
        }

        return $block;
    }

    /**
     * Confirmação do check-in (T-29): TQuestion que, confirmada, chama
     * onCheckIn() com o agendamento e a data da grade.
     */
    public static function onAskCheckIn($param = null)
    {
        $action = new TAction([__CLASS__, 'onCheckIn']);
        $action->setParameter('appointment_id', (int) ($param['appointment_id'] ?? 0));
        $action->setParameter('date', self::validDate($param['date'] ?? null));

        new TQuestion(_t('Check in this appointment?'), $action);
    }

    /**
     * Põe o paciente do agendamento na fila por QueueEntryService::checkIn()
     * (T-29). Só agendado/confirmado; um agendamento já na fila é recusado
     * pelo serviço (DomainException). Sempre recarrega a Agenda na mesma
     * data, no sucesso e na recusa.
     */
    public function onCheckIn($param)
    {
        $appointment_id = (int) ($param['appointment_id'] ?? 0);
        $date = self::validDate($param['date'] ?? null);
        $checked_in = false;

        try
        {
            $context = self::resolveTenantContext();

            TTransaction::open('permission');

            $appointment = $appointment_id > 0 ? self::buildAppointmentService($context)->findById($appointment_id) : null;

            if ($appointment === null)
            {
                TTransaction::close();
                new TMessage('error', _t('Record not found'));
            }
            elseif (!in_array($appointment->status, [\CentralVet\Domain\Appointment::STATUS_SCHEDULED, \CentralVet\Domain\Appointment::STATUS_CONFIRMED], true))
            {
                TTransaction::close();
                new TMessage('error', _t('Only scheduled or confirmed appointments can be checked in'));
            }
            else
            {
                self::buildQueueEntryService($context)->checkIn([
                    'patient_id' => $appointment->patientId,
                    'professional_system_user_id' => $appointment->professionalSystemUserId,
                    'system_unit_id' => $appointment->systemUnitId,
                    'appointment_id' => $appointment->id,
                ], __CLASS__ . '::onCheckIn');

                TTransaction::close();
                $checked_in = true;
            }
        }
        catch (DomainException | \CentralVet\Domain\Exception\CrossTenantReferenceException | \CentralVet\Authorization\Exception\AuthorizationDenied $e)
        {
            TTransaction::rollback();
            error_log(__METHOD__ . ': ' . $e->getMessage());
            new TMessage('error', CvFormat::userError($e));
        }
        catch (Exception $e)
        {
            TTransaction::rollback();
            error_log(__METHOD__ . ': ' . $e->getMessage());
            new TMessage('error', CvFormat::userError($e));
        }

        $this->onReload(['date' => $date]);

        if ($checked_in)
        {
            TToast::show('success', _t('Patient checked in'));
        }
    }

    /** 'Y-m-d' válido ou a data de hoje. */
    private static function validDate($value): string
    {
        $value = is_string($value) ? $value : '';
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return ($parsed !== false && $parsed->format('Y-m-d') === $value) ? $value : date('Y-m-d');
    }

    /**
     * QueueEntryService (T-08) na transação 'permission' já aberta, com o
     * mesmo wiring de QueueEntryView::makeQueueEntryService().
     */
    private static function buildQueueEntryService(\CentralVet\Tenancy\TenantContext $context): \CentralVet\Application\QueueEntryService
    {
        $connection = TTransaction::get();

        $queue_entries = new \CentralVet\Persistence\QueueEntryRepository($context, $connection);
        $patients = new \CentralVet\Application\PatientService(
            new \CentralVet\Persistence\PatientRepository($context, $connection),
            new \CentralVet\Persistence\TutorRepository($context, $connection),
            $context,
        );

        $authorization = new \CentralVet\Authorization\RbacAuthorizationService(
            new \CentralVet\Authorization\AdiantiProgramPermissionProvider(new \CentralVet\Tenancy\AdiantiSessionContextSource()),
            new \CentralVet\Audit\PdoAuditLogWriter($connection),
        );

        return new \CentralVet\Application\QueueEntryService($queue_entries, $patients, $context, $authorization);
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
