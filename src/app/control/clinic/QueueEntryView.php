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
    protected $datagrid;
    protected $panel;
    protected $searchField;
    protected $counters;

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
        $this->searchField->setSize('260px');
        $this->searchField->placeholder = _t('Search by id, patient or professional');

        $btn = TButton::create('find', array($this, 'onSearch'), '', 'fa:search');
        $btn->style = 'height:37px; margin-left:4px';

        $form_search = new TForm('form_search_QueueEntryView');
        $form_search->style = 'float:left; display:flex';
        $form_search->add($this->searchField, true);
        $form_search->add($btn, true);

        // counters (aguardando / em atendimento / atendidos)
        $this->counters = new TElement('div');
        $this->counters->style = 'display:flex; gap:16px; margin:8px 0';

        // datagrid (linhas construidas a partir de CentralVet\Domain\QueueEntry,
        // nao de um TRecord — nao ha' ActiveRecord para queue_entry)
        $this->datagrid = new BootstrapDatagridWrapper(new TQuickGrid);
        $this->datagrid->style = 'width: 100%';
        $this->datagrid->setHeight(400);

        $this->datagrid->addQuickColumn('Id', 'id', 'center', '8%');
        $this->datagrid->addQuickColumn(_t('Time'), 'checked_in_at', 'center', '12%');
        $this->datagrid->addQuickColumn(_t('Patient'), 'patient_label', 'left', '20%');
        $this->datagrid->addQuickColumn(_t('Professional'), 'professional_label', 'left', '20%');
        $this->datagrid->addQuickColumn(_t('Appointment'), 'appointment_label', 'center', '10%');
        $status_column = $this->datagrid->addQuickColumn(_t('Status'), 'status_label', 'center', '15%');
        $status_column->disableHtmlConversion();

        // acao unica de avancar status — o rotulo por status ("Chamar" /
        // "Finalizar" / "Concluido" do mock) nao e' reproduzido literalmente
        // porque TDataGridAction usa um rotulo fixo por coluna, nao por
        // linha; o badge de status (coluna anterior) comunica o estado
        // atual e QueueEntryService::advanceStatus() e' a unica fonte de
        // verdade sobre qual e' o proximo status legal.
        $action = new TDataGridAction(array($this, 'onAdvance'), array('id' => '{id}', 'register_state' => 'false'));
        $action->setUseButton(true);
        $action->setButtonClass('btn btn-default');
        $this->datagrid->addAction($action, _t('Advance status'), 'fa:arrow-circle-right green');

        $this->datagrid->createModel();

        $this->panel = new TPanelGroup(_t('Today queue'));
        $this->panel->add($this->counters);
        $this->panel->addHeaderWidget($form_search);
        $this->panel->add($this->datagrid)->style = 'overflow-x:auto';

        // page header (design system: .cv-page-header/.cv-page-title, T-02)
        $page_header = new TElement('header');
        $page_header->class = 'cv-page-header';
        $page_header_titlebox = new TElement('div');
        $page_header_title = new TElement('h1');
        $page_header_title->class = 'cv-page-title';
        $page_header_title->add(_t('Today queue'));
        $page_header_titlebox->add($page_header_title);
        $page_header->add($page_header_titlebox);

        $container = new TVBox;
        $container->style = 'width: 100%';
        $container->add(new TXMLBreadCrumb('menu.xml', __CLASS__));
        $container->add($this->panel);

        parent::add($page_header);
        parent::add($container);

        $this->loadData();
    }

    /**
     * Loads (or reloads) the datagrid and counters from
     * QueueEntryService::listToday(), scoped to the authenticated user's
     * unit. Any failure (missing tenant/unit context, DB error because the
     * queue_entry migration has not been applied yet, etc.) is caught here
     * and shown as a handled TMessage — never a fatal error.
     */
    private function loadData($term = null)
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
            new TMessage('error', $e->getMessage());
            $this->renderCounters(0, 0, 0);
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
            $row->checked_in_at = $entry->checkedInAt()->format('H:i');
            $row->patient_label = $patientLabel;
            $row->professional_label = $professionalLabel;
            $row->appointment_label = $entry->appointmentId() ? ('#' . $entry->appointmentId()) : '-';
            $row->status_label = $this->statusBadge($entry->displayStatus($now));

            $this->datagrid->addItem($row);
        }

        $this->renderCounters($waiting, $inProgress, $done);
    }

    /**
     * Rebuilds the counters strip (aguardando / em atendimento / atendidos).
     */
    private function renderCounters($waiting, $inProgress, $done)
    {
        $this->counters->clearChildren();
        $this->counters->add($this->counterBox(_t('Waiting'), $waiting, '#f0ad4e'));
        $this->counters->add($this->counterBox(_t('In progress'), $inProgress, '#5bc0de'));
        $this->counters->add($this->counterBox(_t('Attended'), $done, '#5cb85c'));
    }

    private function counterBox($label, $count, $color)
    {
        $box = new TElement('div');
        $box->style = "border-left:4px solid {$color}; padding:2px 12px";
        $box->add("<div style='font-size:20px;font-weight:bold'>{$count}</div><div>{$label}</div>");

        return $box;
    }

    /**
     * Renders the (derived) status as a Bootstrap badge. `atrasado` is a
     * read-time-only derivation (CentralVet\Domain\QueueEntry::displayStatus())
     * — not a status advanceStatus() ever writes.
     */
    private function statusBadge($status)
    {
        $map = array(
            CentralVet\Domain\QueueEntry::STATUS_AGUARDANDO    => array('label' => _t('Waiting'),     'class' => 'label label-warning'),
            CentralVet\Domain\QueueEntry::STATUS_EM_ATENDIMENTO => array('label' => _t('In progress'), 'class' => 'label label-info'),
            CentralVet\Domain\QueueEntry::STATUS_ATENDIDO      => array('label' => _t('Attended'),     'class' => 'label label-success'),
            CentralVet\Domain\QueueEntry::STATUS_ATRASADO      => array('label' => _t('Late'),         'class' => 'label label-danger'),
        );

        $info = isset($map[$status]) ? $map[$status] : array('label' => $status, 'class' => 'label label-default');

        return '<span class="' . $info['class'] . '">' . $info['label'] . '</span>';
    }

    /**
     * Triggered by the quick-search button.
     */
    public function onSearch($param)
    {
        $this->loadData(isset($param['term']) ? $param['term'] : null);
    }

    /**
     * Reloads the whole queue panel from scratch — used after a status
     * advance and as a generic "refresh" entry point.
     */
    public function onReload($param = null)
    {
        $this->loadData();
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

            $this->loadData();

            new TMessage('info', _t('Status updated'));
        }
        catch (CentralVet\Domain\Exception\InvalidStatusTransitionException $e)
        {
            new TMessage('error', _t('This entry cannot advance right now: ^1', $e->getMessage()));
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
            new TMessage('error', $e->getMessage());
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
