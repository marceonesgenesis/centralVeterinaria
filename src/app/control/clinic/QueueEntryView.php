<?php
/**
 * QueueEntryView
 *
 * Fila de atendimento do dia por unidade (mock 04). Consome exclusivamente
 * `CentralVet\Application\QueueEntryService` (T-08) — `listToday()` para
 * carregar as entradas ativas da unidade e `advanceStatus()` para avançar o
 * status de uma entrada. Nenhuma regra de negocio (transicao de status,
 * validacao) vive aqui: tudo delega para o Application service.
 *
 * PENDING: a tabela `queue_entry` (migration T-01) ainda nao foi aplicada.
 * Esta tela e' preparada e validada apenas com `php -l`; qualquer falha de
 * banco ao navegar ate' aqui antes da migration e' capturada e exibida como
 * TMessage, nunca como erro fatal (ver loadData()/onAdvance()).
 *
 * @version    8.6
 * @package    control
 * @subpackage clinic
 * @license    https://adiantiframework.com.br/license-template
 */
class QueueEntryView extends TPage
{
    private const LIMIT = 20;

    protected $datagrid;
    protected $searchField;
    protected $counters;
    protected $pageNavigation;
    protected $footerBox;

    /**
     * Page constructor
     */
    public function __construct($param = null)
    {
        parent::__construct();

        // quick search form (filtra as linhas ja' carregadas por id do
        // paciente/profissional/entrada e tambem pelo nome resolvido de
        // paciente/profissional — ver loadData(), que resolve os nomes via
        // PatientService::findById()/SystemUser::findInTransaction() antes
        // de montar o haystack)
        $this->searchField = new TEntry('term');
        $this->searchField->setSize('100%');
        $this->searchField->placeholder = _t('Search by id, patient or professional');

        $btn = new TButton('find');
        $btn->setAction(new TAction([$this, 'onSearch']), _t('Find'));
        $btn->setImage('fa:search');
        $btn->{'class'} = 'btn btn-primary';

        $form_search = new TForm('form_search_QueueEntryView');
        $form_search->add(CvPage::filterBar([$this->searchField, $btn]));
        $form_search->setFields([$this->searchField, $btn]);

        // counters (aguardando / em atendimento / atendidos)
        $this->counters = new TElement('div');
        $this->counters->{'class'} = 'cv-kpi-row';

        // datagrid (linhas construidas a partir de CentralVet\Domain\QueueEntry,
        // nao de um TRecord — nao ha' ActiveRecord para queue_entry)
        $this->datagrid = new BootstrapDatagridWrapper(new TDataGrid);
        CvDatagrid::decorate($this->datagrid);
        $this->datagrid->disableDefaultClick();
        $this->datagrid->setActionSide('right');

        $columns = [
            new TDataGridColumn('id', 'Id', 'center', '8%'),
            new TDataGridColumn('checked_in_at', _t('Time'), 'center', '12%'),
            new TDataGridColumn('patient_label', _t('Patient'), 'left', '22%'),
            new TDataGridColumn('professional_label', _t('Professional'), 'left', '22%'),
            new TDataGridColumn('appointment_label', _t('Appointment'), 'center', '10%'),
        ];
        $status_column = new TDataGridColumn('status_label', _t('Status'), 'center', '15%');
        $status_column->disableHtmlConversion();
        $columns[] = $status_column;

        foreach ($columns as $column)
        {
            $this->datagrid->addColumn($column);
        }

        // acao unica de avancar status, no menu "…" — o rotulo por status
        // ("Chamar" / "Finalizar" / "Concluido" do mock) nao e' reproduzido
        // literalmente porque TDataGridAction usa um rotulo fixo, nao por
        // linha; o badge de status comunica o estado atual e
        // QueueEntryService::advanceStatus() e' a unica fonte de verdade
        // sobre qual e' o proximo status legal.
        $action = new TDataGridAction(array($this, 'onAdvance'), array('id' => '{id}', 'register_state' => 'false'));
        // atalhos para os cadastros da linha: "Avançar status" continua o
        // primeiro item; "Editar agendamento" só aparece quando a entrada
        // veio de um agendamento (no encaixe a linha traz appointment_id = 0)
        $action_patient = new TDataGridAction(['PatientForm', 'onEdit'], ['key' => '{patient_id}', 'register_state' => 'false']);
        $action_appointment = new TDataGridAction(['AppointmentForm', 'onEdit'], ['key' => '{appointment_id}', 'register_state' => 'false']);
        $action_appointment->setDisplayCondition(function ($object) {
            return !empty($object->appointment_id);
        });
        $this->datagrid->addActionGroup(CvDatagrid::actionMenu([
            ['label' => _t('Advance status'), 'action' => $action, 'icon' => 'fa:arrow-circle-right'],
            ['label' => _t('Edit patient'), 'action' => $action_patient, 'icon' => 'fa:paw'],
            ['label' => _t('Edit appointment'), 'action' => $action_appointment, 'icon' => 'far:calendar-alt'],
        ]));

        $this->datagrid->createModel();

        $this->pageNavigation = new TPageNavigation;
        $this->pageNavigation->setAction(new TAction([$this, 'onReload']));
        $this->pageNavigation->setWidth($this->datagrid->getWidth());

        $this->footerBox = new TElement('div');

        $card = new TElement('div');
        $card->{'class'} = 'cv-card';
        $body = new TElement('div');
        $body->{'class'} = 'cv-card__body';
        $body->style = 'overflow-x:auto';
        $body->add($form_search);
        $body->add($this->datagrid);
        $body->add($this->footerBox);
        $card->add($body);

        $container = new TVBox;
        $container->style = 'width: 100%';
        $container->add(CvPage::header(_t('Today queue'), date('d/m/Y'), [
            ['label' => _t('Agenda'), 'icon' => 'far:calendar-alt', 'action' => new TAction(['AgendaView', 'onReload'], ['date' => date('Y-m-d')])],
        ]));
        $container->add($this->counters);
        $container->add($card);

        parent::add($container);

        // só carrega aqui no primeiro acesso: com method, o dispatcher chama
        // onReload()/onSearch()/onAdvance(), que já carregam a fila
        if (empty($param['method']))
        {
            $this->loadData(null, $param['offset'] ?? 0);
        }
    }

    /**
     * Loads (or reloads) the datagrid and counters from
     * QueueEntryService::listToday(), scoped to the authenticated user's
     * unit. Any failure (missing tenant/unit context, DB error because the
     * queue_entry migration has not been applied yet, etc.) is caught here
     * and shown as a handled TMessage — never a fatal error.
     */
    private function loadData($term = null, $offset = 0)
    {
        $this->datagrid->clear();

        try
        {
            $context = self::resolveTenantContext();
            $unitId = $context->requireUnitId();
            $service = self::makeQueueEntryService($context);
            $entries = $service->listToday($unitId);
        }
        catch (Exception $e)
        {
            error_log(__METHOD__ . ': ' . $e->getMessage());
            new TMessage('error', CvFormat::userError($e));
            $this->renderCounters(0, 0, 0);
            $this->renderFooter(0, 0, 0, null);
            return;
        }

        $term = is_string($term) ? trim($term) : null;
        $now = new DateTimeImmutable();

        $waiting = 0;
        $inProgress = 0;
        $done = 0;

        // patient_id => name and professional_system_user_id => name, resolved
        // once per distinct id (never once per row) within this same request.
        $patientNames = [];
        $professionalNames = [];
        $patientService = self::makePatientService($context);
        $rows = [];

        foreach ($entries as $entry)
        {
            switch ($entry->status())
            {
                case CentralVet\Domain\QueueEntry::STATUS_AGUARDANDO:
                    $waiting++;
                    break;
                case CentralVet\Domain\QueueEntry::STATUS_EM_ATENDIMENTO:
                    $inProgress++;
                    break;
                case CentralVet\Domain\QueueEntry::STATUS_ATENDIDO:
                    $done++;
                    break;
            }

            $patientId = (int) $entry->patientId();
            $professionalId = (int) $entry->professionalSystemUserId();

            if (!array_key_exists($patientId, $patientNames))
            {
                try
                {
                    $patient = $patientService->findById($patientId);
                    $patientNames[$patientId] = $patient !== null ? $patient->name : null;
                }
                catch (Exception $e)
                {
                    $patientNames[$patientId] = null;
                }
            }

            if (!array_key_exists($professionalId, $professionalNames))
            {
                try
                {
                    $professional = SystemUser::findInTransaction('permission', $professionalId);
                    $professionalNames[$professionalId] = $professional !== null ? $professional->name : null;
                }
                catch (Exception $e)
                {
                    $professionalNames[$professionalId] = null;
                }
            }

            $patientLabel = $patientNames[$patientId] !== null
                ? $patientNames[$patientId]
                : _t('Patient') . ' #' . $patientId . ' (' . _t('not found') . ')';

            $professionalLabel = $professionalNames[$professionalId] !== null
                ? $professionalNames[$professionalId]
                : _t('Professional') . ' #' . $professionalId . ' (' . _t('not found') . ')';

            if (!empty($term))
            {
                $haystack = $entry->id() . ' ' . $patientId . ' ' . $professionalId . ' ' . $patientLabel . ' ' . $professionalLabel;
                if (stripos($haystack, $term) === false)
                {
                    continue;
                }
            }

            $row = new stdClass;
            $row->id = $entry->id();
            $row->patient_id = $patientId;
            // 0 (e nao null) no encaixe: TDataGridAction::prepare() exige o
            // campo {appointment_id} setado antes da display condition
            $row->appointment_id = $entry->appointmentId() ? (int) $entry->appointmentId() : 0;
            $row->checked_in_at = $entry->checkedInAt()->format('H:i');
            $row->patient_label = $patientLabel;
            $row->professional_label = $professionalLabel;
            $row->appointment_label = $entry->appointmentId() ? ('#' . $entry->appointmentId()) : '-';
            $row->status_label = $this->statusBadge($entry->displayStatus($now));

            $rows[] = $row;
        }

        $total = count($rows);
        $offset = max(0, (int) $offset);
        if ($offset >= $total)
        {
            $offset = 0;
        }
        $page_rows = array_slice($rows, $offset, self::LIMIT);

        foreach ($page_rows as $row)
        {
            $this->datagrid->addItem($row);
        }

        $this->renderCounters($waiting, $inProgress, $done);
        $this->renderFooter($offset, count($page_rows), $total, $term);
    }

    /**
     * Pager + "Showing X–Y of N" footer.
     */
    private function renderFooter($offset, $count, $total, $term)
    {
        $this->pageNavigation->setAction(new TAction([$this, 'onSearch'], ['term' => (string) $term]));
        $this->pageNavigation->setCount($total);
        $this->pageNavigation->setLimit(self::LIMIT);
        $this->pageNavigation->setProperties(['offset' => $offset, 'page' => intdiv($offset, self::LIMIT) + 1]);

        $from = $total > 0 ? $offset + 1 : 0;
        $this->footerBox->clearChildren();
        $this->footerBox->add(CvDatagrid::footer($this->pageNavigation, $from, $offset + $count, $total, mb_strtolower(_t('Patients'))));
    }

    /**
     * Rebuilds the counters strip (aguardando / em atendimento / atendidos).
     */
    private function renderCounters($waiting, $inProgress, $done)
    {
        $this->counters->clearChildren();
        $this->counters->add($this->counterBox(_t('Waiting'), $waiting, 'warning'));
        $this->counters->add($this->counterBox(_t('In progress'), $inProgress, 'info'));
        $this->counters->add($this->counterBox(_t('Attended'), $done, 'success'));
    }

    private function counterBox($label, $count, $tone)
    {
        $box = new TElement('div');
        $box->{'class'} = 'cv-kpi cv-kpi--' . $tone;

        $body = new TElement('div');
        $body->{'class'} = 'cv-kpi__body';
        $body->add(TElement::tag('div', CvFormat::e($label), ['class' => 'cv-kpi__label']));
        $body->add(TElement::tag('div', (string) (int) $count, ['class' => 'cv-kpi__value']));
        $box->add($body);

        return $box;
    }

    /**
     * Renders the (derived) status as a CvBadge. `atrasado` is a
     * read-time-only derivation (CentralVet\Domain\QueueEntry::displayStatus())
     * — not a status advanceStatus() ever writes.
     */
    private function statusBadge($status)
    {
        $map = array(
            CentralVet\Domain\QueueEntry::STATUS_AGUARDANDO     => array(_t('Waiting'), 'warning'),
            CentralVet\Domain\QueueEntry::STATUS_EM_ATENDIMENTO => array(_t('In progress'), 'info'),
            CentralVet\Domain\QueueEntry::STATUS_ATENDIDO       => array(_t('Attended'), 'success'),
            CentralVet\Domain\QueueEntry::STATUS_ATRASADO       => array(_t('Late'), 'danger'),
        );

        [$label, $tone] = isset($map[$status]) ? $map[$status] : array((string) $status, 'neutral');

        return (string) CvBadge::create($label, $tone);
    }

    /**
     * Triggered by the quick-search button (and by the pager, with the term).
     */
    public function onSearch($param)
    {
        $term = isset($param['term']) ? $param['term'] : null;
        $this->searchField->setValue($term);
        $this->loadData($term, $param['offset'] ?? 0);
    }

    /**
     * Reloads the whole queue panel from scratch — used after a status
     * advance and as a generic "refresh" entry point.
     */
    public function onReload($param = null)
    {
        $this->loadData(null, is_array($param) ? ($param['offset'] ?? 0) : 0);
    }

    /**
     * Advances one queue entry by exactly one legal step, via
     * QueueEntryService::advanceStatus(). When the transition is illegal
     * (e.g. the entry is already `atendido`), the service throws
     * InvalidStatusTransitionException, which is caught here and shown as a
     * handled TMessage — the screen never surfaces a fatal error for this
     * case. On success, the whole queue panel (counters + datagrid) is
     * rebuilt server-side and pushed back through Adianti's own AJAX
     * action-call mechanism (the same TDataGridAction click that triggered
     * this method): no manual browser refresh (no F5 / full navigation) is
     * required from the user. This is a panel-wide redraw, not a
     * single-row patch — see notes.md discussion below.
     */
    public function onAdvance($param)
    {
        $advanced = false;

        try
        {
            $id = isset($param['id']) ? (int) $param['id'] : 0;

            if ($id <= 0)
            {
                throw new InvalidArgumentException(_t('Invalid queue entry id'));
            }

            $context = self::resolveTenantContext();
            $service = self::makeQueueEntryService($context);
            $service->advanceStatus($id, __CLASS__ . '::' . __FUNCTION__);

            $advanced = true;
        }
        catch (CentralVet\Domain\Exception\InvalidStatusTransitionException $e)
        {
            error_log(__METHOD__ . ': ' . $e->getMessage());
            new TMessage('error', _t('This entry cannot advance right now: ^1', CvFormat::userError($e)));
        }
        catch (CentralVet\Authorization\Exception\AuthorizationDenied $e)
        {
            // Unit-scope authorization refusal: the entry's real
            // system_unit_id (read back from storage by
            // QueueEntryService::advanceStatus() itself) does not match the
            // caller's active unit. Handled like every other business-rule
            // refusal above, never a fatal error.
            new TMessage('error', _t('You are not allowed to advance a queue entry from another unit'));
        }
        catch (Exception $e)
        {
            error_log(__METHOD__ . ': ' . $e->getMessage());
            new TMessage('error', CvFormat::userError($e));
        }

        // recarrega a fila no sucesso e na recusa: o construtor não carrega
        // quando há method, então a tela nunca fica sem linhas/contadores
        $this->loadData();

        if ($advanced)
        {
            new TMessage('info', _t('Status updated'));
        }
    }

    /**
     * Wires a standalone PatientService (T-05), used only to resolve
     * patient names for display in loadData() — reuses the 'permission'
     * transaction/connection already left open by makeQueueEntryService()
     * for this same request, so it does not open a second one.
     */
    private static function makePatientService(CentralVet\Tenancy\TenantContext $context)
    {
        $connection = TTransaction::get();

        $patients = new CentralVet\Persistence\PatientRepository($context, $connection);
        $tutors = new CentralVet\Persistence\TutorRepository($context, $connection);

        return new CentralVet\Application\PatientService($patients, $tutors, $context);
    }

    /**
     * Wires QueueEntryService (T-08) from its Persistence/PDO
     * implementations. Mirrors CentralVet\Application\PatientService's own
     * dependency (QueueEntryService needs it to validate patient_id on
     * check-in — not used by listToday()/advanceStatus(), but required by
     * the constructor).
     *
     * PENDING: QueueEntryRepository/PatientRepository/TutorRepository are
     * PDO-backed against tutor/patient/queue_entry, tables created by the
     * not-yet-applied migration 20260921_0002_phase1_clinic_core.sql.
     * Building this wiring does not execute any SQL by itself — only
     * listToday()/advanceStatus() do, and both are called exclusively from
     * loadData()/onAdvance() above, which already catch any resulting
     * database error and show it as a handled TMessage.
     *
     * The authorization dependency is the real RbacAuthorizationService
     * (Fase 0), backed by AdiantiProgramPermissionProvider (reads the same
     * programs/methods session keys SystemPermission::checkPermission()
     * already uses) and PdoAuditLogWriter against this same 'permission'
     * connection, so every advanceStatus() call is both unit-scope-checked
     * and audited to `audit_log`.
     */
    private static function makeQueueEntryService(CentralVet\Tenancy\TenantContext $context)
    {
        TTransaction::open('permission');
        $connection = TTransaction::get();

        $queueEntries = new CentralVet\Persistence\QueueEntryRepository($context, $connection);
        $tutors = new CentralVet\Persistence\TutorRepository($context, $connection);
        $patients = new CentralVet\Persistence\PatientRepository($context, $connection);

        $patientService = new CentralVet\Application\PatientService($patients, $tutors, $context);

        $authorization = new CentralVet\Authorization\RbacAuthorizationService(
            new CentralVet\Authorization\AdiantiProgramPermissionProvider(new CentralVet\Tenancy\AdiantiSessionContextSource()),
            new CentralVet\Audit\PdoAuditLogWriter($connection),
        );

        return new CentralVet\Application\QueueEntryService($queueEntries, $patientService, $context, $authorization);
    }

    /**
     * Resolves the tenant context of the authenticated session.
     * Copied verbatim from SystemUnitList::resolveTenantContext() (T-03
     * pattern): falls back to the tenant_user membership table for legacy
     * sessions created before TSession carried 'tenantid'.
     */
    private static function resolveTenantContext()
    {
        $source = new CentralVet\Tenancy\AdiantiSessionContextSource();

        try
        {
            return CentralVet\Tenancy\TenantContext::fromAuthenticatedSession($source);
        }
        catch (CentralVet\Tenancy\Exception\MissingTenantContext $e)
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

            return CentralVet\Tenancy\TenantContext::authenticated((int) $tenant_id, (int) $userid, $unit_id ? (int) $unit_id : null);
        }
    }
}
