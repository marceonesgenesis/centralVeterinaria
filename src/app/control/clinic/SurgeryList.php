<?php
/**
 * SurgeryList
 *
 * Agenda cirúrgica do dia da unidade ativa (Fase 6B, T-17): filtro de data
 * (`date`, padrão hoje, setas dia anterior/próximo), KPIs por status e uma
 * linha por cirurgia, ordenada pelo início, com horário, sala, paciente,
 * procedimento, cirurgião, status e link para a ficha (`SurgeryView&id=`).
 *
 * Rota: `index.php?class=SurgeryList&date=YYYY-MM-DD`. Leitura por
 * `SurgeryService::listForDay()` com a ação `SurgeryList::onReload`;
 * contagem e ordenação em `CentralVet\Presentation\SurgeryAgendaView`.
 * Nomes de sala, paciente e cirurgião resolvidos em lote (uma consulta por
 * tipo, sem N+1), sempre filtrados pelo tenant da sessão.
 *
 * @version    8.6
 * @package    control
 * @subpackage clinic
 * @license    https://adiantiframework.com.br/license-template
 */
class SurgeryList extends TPage
{
    private const ACTION_RELOAD = 'SurgeryList::onReload';
    private const FORM_NAME = 'form_SurgeryList_filter';

    /** Surgery::STATUS_* → [rótulo en, tom do CvBadge]. */
    private const STATUS_BADGE = [
        'scheduled'   => ['Scheduled', 'info'],
        'pre_op'      => ['Pre-op', 'warning'],
        'in_progress' => ['In progress', 'warning'],
        'completed'   => ['Completed', 'success'],
        'cancelled'   => ['Cancelled', 'neutral'],
    ];

    private bool $loaded = false;

    public function __construct($param = null)
    {
        parent::__construct();

        // com method, o dispatcher chama onReload(), que já carrega
        if (empty($param['method']))
        {
            $this->onReload(['date' => $param['date'] ?? null]);
        }
    }

    /**
     * Recarrega a página para a data pedida (`date` em Y-m-d ou d/m/Y;
     * inválida ou vazia → hoje). Alvo das setas e do botão do filtro.
     */
    public function onReload($param = null)
    {
        if ($this->loaded)
        {
            return;
        }
        $this->loaded = true;

        $date = self::validDate($param['date'] ?? null);

        $container = new TVBox;
        $container->style = 'width: 100%';
        $container->add(self::header($date));
        $container->add($this->filter($date));

        $content = new TElement('div');
        $content->{'class'} = 'cv-surgery-agenda';
        $container->add($content);

        try
        {
            $context = self::resolveTenantContext();

            TTransaction::open('permission');
            $connection = TTransaction::get();

            $surgeries = self::makeSurgeryService($context, $connection)->listForDay($date, self::ACTION_RELOAD);
            $surgeries = CentralVet\Presentation\SurgeryAgendaView::sortByStart($surgeries);

            $rooms = self::roomNames($context, $connection);
            $patients = self::patientNames($context, $connection, array_map(static fn ($s) => $s->patientId(), $surgeries));
            $surgeons = self::userNames($context, $connection, array_map(static fn ($s) => $s->surgeonSystemUserId(), $surgeries));

            TTransaction::close();
        }
        catch (\CentralVet\Authorization\Exception\AuthorizationDenied $e)
        {
            TTransaction::rollback();
            new TMessage('error', _t('You are not allowed to view the surgical agenda'));
            $content->add(self::kpiRow(CentralVet\Presentation\SurgeryAgendaView::countByStatus([])));
            parent::add($container);
            return;
        }
        catch (\CentralVet\Tenancy\Exception\MissingTenantContext $e)
        {
            TTransaction::rollback();
            new TMessage('error', _t('An authenticated session with an active unit is required'));
            $content->add(self::kpiRow(CentralVet\Presentation\SurgeryAgendaView::countByStatus([])));
            parent::add($container);
            return;
        }
        catch (Exception $e)
        {
            TTransaction::rollback();
            error_log(__METHOD__ . ': ' . $e->getMessage());
            new TMessage('error', CvFormat::userError($e));
            $content->add(self::kpiRow(CentralVet\Presentation\SurgeryAgendaView::countByStatus([])));
            parent::add($container);
            return;
        }

        $content->add(self::kpiRow(CentralVet\Presentation\SurgeryAgendaView::countByStatus($surgeries)));

        if ($surgeries === [])
        {
            $content->add(self::emptyState());
        }
        else
        {
            $content->add(self::table($surgeries, $rooms, $patients, $surgeons));
        }

        parent::add($container);
    }

    private static function header(DateTimeImmutable $date): TElement
    {
        return CvPage::header(_t('Surgical agenda'), $date->format('d/m/Y'), [
            ['label' => '', 'title' => _t('Previous day'), 'icon' => 'fa:chevron-left', 'class' => 'btn btn-default cv-touch-target', 'action' => new TAction([__CLASS__, 'onReload'], ['date' => $date->modify('-1 day')->format('Y-m-d')])],
            ['label' => _t('Today'), 'class' => 'btn btn-default cv-touch-target', 'action' => new TAction([__CLASS__, 'onReload'], ['date' => date('Y-m-d')])],
            ['label' => '', 'title' => _t('Next day'), 'icon' => 'fa:chevron-right', 'class' => 'btn btn-default cv-touch-target', 'action' => new TAction([__CLASS__, 'onReload'], ['date' => $date->modify('+1 day')->format('Y-m-d')])],
        ]);
    }

    private function filter(DateTimeImmutable $date): TForm
    {
        $form = new TForm(self::FORM_NAME);

        $field = new TDate('date');
        $field->setMask('dd/mm/yyyy');
        $field->setSize('100%');
        $field->setValue($date->format('d/m/Y'));

        $button = new TButton('filter');
        $button->setAction(new TAction([$this, 'onReload']), _t('Apply'));
        $button->setImage('fa:filter');
        $button->class = 'btn btn-primary cv-touch-target';

        $form->setFields([$field, $button]);

        $box = new TElement('div');
        $box->add(new TLabel(_t('Date')));
        $box->add($field);

        $form->add(CvPage::filterBar([$box, $button]));

        return $form;
    }

    /**
     * @param array{scheduled: int, pre_op: int, in_progress: int, completed: int, cancelled: int} $counts
     */
    private static function kpiRow(array $counts): TElement
    {
        $row = new TElement('div');
        $row->{'class'} = 'cv-kpi-row';
        $row->add(CvKpiCard::create('far:calendar', 'info', (string) $counts['scheduled'], _t('Scheduled surgeries')));
        $row->add(CvKpiCard::create('fa:clipboard-check', 'warning', (string) $counts['pre_op'], _t('In pre-op')));
        $row->add(CvKpiCard::create('fa:procedures', 'warning', (string) $counts['in_progress'], _t('Surgeries in progress')));
        $row->add(CvKpiCard::create('fa:check', 'success', (string) $counts['completed'], _t('Completed surgeries')));
        $row->add(CvKpiCard::create('fa:ban', 'neutral', (string) $counts['cancelled'], _t('Cancelled surgeries')));

        return $row;
    }

    private static function emptyState(): TElement
    {
        $state = new TElement('div');
        $state->{'class'} = 'cv-state cv-state--empty';
        $state->{'role'} = 'status';

        $icon = new TElement('span');
        $icon->{'class'} = 'cv-state__icon';
        $icon->{'aria-hidden'} = 'true';
        $icon->add(new TImage('fa:procedures'));
        $state->add($icon);

        $text = new TElement('div');
        $text->add(TElement::tag('p', CvFormat::e(_t('No surgeries on this day')), ['class' => 'cv-state__title']));
        $text->add(TElement::tag('p', CvFormat::e(_t('Schedule a surgery from an encounter to see it on the agenda.')), ['class' => 'cv-state__message']));
        $state->add($text);

        return $state;
    }

    /**
     * @param list<CentralVet\Domain\Surgery> $surgeries ordenadas pelo início
     * @param array<int, string> $rooms
     * @param array<int, string> $patients
     * @param array<int, string> $surgeons
     */
    private static function table(array $surgeries, array $rooms, array $patients, array $surgeons): TElement
    {
        $card = new TElement('div');
        $card->{'class'} = 'cv-card';
        $body = new TElement('div');
        $body->{'class'} = 'cv-card__body';
        $body->style = 'overflow-x:auto';
        $card->add($body);

        $table = new TElement('table');
        $table->{'class'} = 'table cv-table';
        $table->style = 'width:100%';

        $thead = new TElement('thead');
        $head = new TElement('tr');
        foreach ([_t('Time'), _t('Room'), _t('Patient'), _t('Procedure'), _t('Surgeon'), _t('Status'), ''] as $title)
        {
            $head->add(TElement::tag('th', CvFormat::e($title), ['scope' => 'col']));
        }
        $thead->add($head);
        $table->add($thead);

        $tbody = new TElement('tbody');
        foreach ($surgeries as $surgery)
        {
            $id = (int) $surgery->id();
            [$label, $tone] = self::STATUS_BADGE[$surgery->status()] ?? [$surgery->status(), 'neutral'];

            $tr = new TElement('tr');
            $tr->add(TElement::tag('td', CvFormat::e($surgery->scheduledStartAt()->format('H:i') . '–' . $surgery->scheduledEndAt()->format('H:i'))));
            $tr->add(TElement::tag('td', CvFormat::e($rooms[$surgery->roomId()] ?? '#' . $surgery->roomId())));
            $tr->add(TElement::tag('td', CvFormat::e($patients[$surgery->patientId()] ?? _t('Patient') . ' #' . $surgery->patientId())));
            $tr->add(TElement::tag('td', CvFormat::e($surgery->procedureName())));
            $tr->add(TElement::tag('td', CvFormat::e($surgeons[$surgery->surgeonSystemUserId()] ?? '#' . $surgery->surgeonSystemUserId())));

            $status = new TElement('td');
            $status->add(CvBadge::create(_t($label), $tone));
            $tr->add($status);

            $link = new TElement('a');
            $link->{'class'} = 'btn btn-sm btn-outline-secondary cv-touch-target';
            $link->{'href'} = 'index.php?class=SurgeryView&id=' . $id;
            $link->{'generator'} = 'adianti';
            $link->add(new TImage('fa:eye'));
            $link->add(TElement::tag('span', CvFormat::e(_t('Open'))));

            $action = new TElement('td');
            $action->add($link);
            $tr->add($action);

            $tbody->add($tr);
        }
        $table->add($tbody);

        $body->add($table);

        return $card;
    }

    /**
     * Salas da unidade ativa (uma consulta): id => "código – nome".
     *
     * @return array<int, string>
     */
    private static function roomNames(CentralVet\Tenancy\TenantContext $context, $connection): array
    {
        $names = [];
        $repository = new CentralVet\Persistence\SurgeryRoomRepository($context, $connection);

        foreach ($repository->listByUnit($context->requireUnitId()) as $room)
        {
            $names[(int) $room->id()] = $room->code() . ' – ' . $room->name();
        }

        return $names;
    }

    /**
     * Nomes dos pacientes do tenant em uma consulta.
     *
     * @param list<int> $ids
     * @return array<int, string>
     */
    private static function patientNames(CentralVet\Tenancy\TenantContext $context, $connection, array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === [])
        {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $statement = $connection->prepare("SELECT id, name FROM patient WHERE tenant_id = ? AND id IN ({$placeholders})");
        $statement->execute([$context->tenantId(), ...$ids]);

        $names = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row)
        {
            $names[(int) $row['id']] = (string) $row['name'];
        }

        return $names;
    }

    /**
     * Nomes dos usuários vinculados ao tenant (tenant_user) em uma consulta.
     *
     * @param list<int> $ids
     * @return array<int, string>
     */
    private static function userNames(CentralVet\Tenancy\TenantContext $context, $connection, array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === [])
        {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $statement = $connection->prepare(
            "SELECT u.id, u.name FROM system_users u"
            . " INNER JOIN tenant_user tu ON tu.system_user_id = u.id AND tu.tenant_id = ?"
            . " WHERE u.id IN ({$placeholders})"
        );
        $statement->execute([$context->tenantId(), ...$ids]);

        $names = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row)
        {
            $names[(int) $row['id']] = (string) $row['name'];
        }

        return $names;
    }

    /** `Y-m-d` ou `d/m/Y` válidos; senão, hoje. */
    private static function validDate($value): DateTimeImmutable
    {
        $value = is_string($value) ? trim($value) : '';

        foreach (['Y-m-d', 'd/m/Y'] as $format)
        {
            $parsed = DateTimeImmutable::createFromFormat('!' . $format, $value);
            if ($parsed !== false && $parsed->format($format) === $value)
            {
                return $parsed;
            }
        }

        return new DateTimeImmutable('today');
    }

    private static function makeSurgeryService(CentralVet\Tenancy\TenantContext $context, $connection)
    {
        $authorization = new CentralVet\Authorization\RbacAuthorizationService(
            new CentralVet\Authorization\AdiantiProgramPermissionProvider(new CentralVet\Tenancy\AdiantiSessionContextSource()),
            new CentralVet\Audit\PdoAuditLogWriter($connection),
        );

        return new CentralVet\Application\SurgeryService(
            new CentralVet\Persistence\SurgeryRepository($context, $connection),
            new CentralVet\Persistence\SurgeryRoomRepository($context, $connection),
            new CentralVet\Persistence\SurgeryTeamRepository($context, $connection),
            new CentralVet\Persistence\SurgeryChecklistRepository($context, $connection),
            new CentralVet\Persistence\SurgeryEventRepository($context, $connection),
            new CentralVet\Persistence\EncounterRepository($context, $connection),
            new CentralVet\Persistence\EncounterAccountRepository($context, $connection),
            new CentralVet\Persistence\ProcedureCatalogRepository($context, $connection),
            new CentralVet\Persistence\TenantUserDirectory($context, $connection),
            $authorization,
            $context,
        );
    }

    /**
     * Resolves the tenant context of the authenticated session (mesmo padrão
     * de HospitalizationBoard::resolveTenantContext()).
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
