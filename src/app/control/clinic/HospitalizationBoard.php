<?php
/**
 * HospitalizationBoard
 *
 * Flowboard do turno da internação (T-16), pensado para tablet: KPIs
 * (internados, atrasadas, próximas 2 h, feitas no turno), três colunas
 * (Atrasadas, Próximas, Feitas) com um cartão por administração e a faixa
 * dos internados da unidade ativa.
 *
 * Consome só Application services: `HospitalizationOrderService::
 * boardRowsForCurrentUnit()` (linhas com `timeliness`) e
 * `HospitalizationService::listActiveForCurrentUnit()`. O agrupamento em
 * colunas vive em `CentralVet\Presentation\HospitalizationBoardView::group()`.
 * Recarregamento só pelo botão "Atualizar" (sem polling).
 *
 * @version    8.6
 * @package    control
 * @subpackage clinic
 * @license    https://adiantiframework.com.br/license-template
 */
class HospitalizationBoard extends TPage
{
    /** Janela das próximas administrações, em horas. */
    private const WINDOW_HOURS = 2;

    private const ROUTE_LABELS = [
        'oral' => 'Oral',
        'iv' => 'IV',
        'im' => 'IM',
        'sc' => 'SC',
        'topical' => 'Topical',
        'inhalation' => 'Inhalation',
        'other' => 'Other',
    ];

    protected $content;

    public function __construct($param = null)
    {
        parent::__construct();

        $this->content = new TElement('div');
        $this->content->{'class'} = 'cv-board-page';

        $container = new TVBox;
        $container->style = 'width: 100%';
        $container->add(CvPage::header(_t('Shift board'), date('d/m/Y H:i'), [
            ['label' => _t('Refresh'), 'icon' => 'fa:sync-alt', 'class' => 'btn btn-default cv-board-refresh', 'action' => new TAction([__CLASS__, 'onReload'])],
        ]));
        $container->add($this->content);

        parent::add($container);

        // com method, o dispatcher chama onReload(), que já carrega
        if (empty($param['method']))
        {
            $this->loadData();
        }
    }

    /**
     * Botão "Atualizar": reconstrói KPIs, colunas e faixa de internados.
     */
    public function onReload($param = null)
    {
        $this->loadData();
    }

    private function loadData()
    {
        $this->content->clearChildren();
        $action = __CLASS__ . '::onReload';

        try
        {
            $context = self::resolveTenantContext();

            TTransaction::open('permission');
            $connection = TTransaction::get();

            $authorization = new CentralVet\Authorization\RbacAuthorizationService(
                new CentralVet\Authorization\AdiantiProgramPermissionProvider(new CentralVet\Tenancy\AdiantiSessionContextSource()),
                new CentralVet\Audit\PdoAuditLogWriter($connection),
            );

            $hospitalizations = self::makeHospitalizationService($context, $connection, $authorization)->listActiveForCurrentUnit($action);
            $rows = self::makeOrderService($context, $connection, $authorization)->boardRowsForCurrentUnit(self::WINDOW_HOURS, $action);

            $patients = new CentralVet\Persistence\PatientRepository($context, $connection);
            $beds = new CentralVet\Persistence\BedRepository($context, $connection);
            $admitted = self::admittedCards($hospitalizations, $patients, $beds);

            TTransaction::close();
        }
        catch (\CentralVet\Authorization\Exception\AuthorizationDenied $e)
        {
            TTransaction::rollback();
            new TMessage('error', _t('You are not allowed to view the shift board'));
            $this->content->add(self::kpiRow(0, 0, 0, 0));
            return;
        }
        catch (\CentralVet\Tenancy\Exception\MissingTenantContext $e)
        {
            TTransaction::rollback();
            new TMessage('error', _t('An authenticated session with an active unit is required'));
            $this->content->add(self::kpiRow(0, 0, 0, 0));
            return;
        }
        catch (Exception $e)
        {
            TTransaction::rollback();
            error_log(__METHOD__ . ': ' . $e->getMessage());
            new TMessage('error', CvFormat::userError($e));
            $this->content->add(self::kpiRow(0, 0, 0, 0));
            return;
        }

        $board = CentralVet\Presentation\HospitalizationBoardView::group($rows);
        $counts = $board['counts'];

        $this->content->add(self::kpiRow(count($admitted), $counts['late'], $counts['upcoming'], $counts['done']));

        if (count($admitted) === 0)
        {
            $this->content->add(self::emptyState());
            return;
        }

        $columns = new TElement('div');
        $columns->{'class'} = 'cv-board';
        $columns->add(self::column('late', _t('Late'), 'danger', $board['late']));
        $columns->add(self::column('upcoming', _t('Upcoming'), 'info', $board['upcoming']));
        $columns->add(self::column('done', _t('Done'), 'success', $board['done']));
        $this->content->add($columns);

        $this->content->add(self::admittedStrip($admitted));
    }

    private static function kpiRow(int $admitted, int $late, int $upcoming, int $done): TElement
    {
        $row = new TElement('div');
        $row->{'class'} = 'cv-kpi-row';
        $row->add(CvKpiCard::create('fa:procedures', 'info', (string) $admitted, _t('Hospitalized')));
        $row->add(CvKpiCard::create('fa:exclamation-triangle', 'danger', (string) $late, _t('Late')));
        $row->add(CvKpiCard::create('far:clock', 'warning', (string) $upcoming, _t('Next 2 h')));
        $row->add(CvKpiCard::create('fa:check', 'success', (string) $done, _t('Done in shift')));

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
        $icon->add(new TImage('fa:bed'));
        $state->add($icon);

        $text = new TElement('div');
        $text->add(TElement::tag('p', CvFormat::e(_t('No hospitalized patients in this unit')), ['class' => 'cv-state__title']));
        $text->add(TElement::tag('p', CvFormat::e(_t('Admit a patient from an encounter to see it on the board.')), ['class' => 'cv-state__message']));
        $state->add($text);

        return $state;
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private static function column(string $key, string $title, string $tone, array $rows): TElement
    {
        $column = new TElement('section');
        $column->{'class'} = 'cv-board-col cv-board-col--' . $key;
        $column->{'aria-labelledby'} = 'cv-board-col-' . $key;

        $head = new TElement('header');
        $head->{'class'} = 'cv-board-col__head';
        $head->add(TElement::tag('h2', CvFormat::e($title), ['class' => 'cv-board-col__title', 'id' => 'cv-board-col-' . $key]));
        $head->add(CvBadge::create((string) count($rows), $tone));
        $column->add($head);

        $list = new TElement('div');
        $list->{'class'} = 'cv-board-col__list';

        if (count($rows) === 0)
        {
            $list->add(TElement::tag('p', CvFormat::e(_t('Nothing here')), ['class' => 'cv-board-col__empty']));
        }

        foreach ($rows as $row)
        {
            $list->add(self::card($row));
        }

        $column->add($list);

        return $column;
    }

    /**
     * Cartão de uma administração; o cartão inteiro é o alvo de toque para
     * o formulário de administração (T-15).
     *
     * @param array<string, mixed> $row
     */
    private static function card(array $row): TElement
    {
        $timeliness = (string) ($row['timeliness'] ?? '');
        [$label, $tone] = self::timelinessBadge($timeliness);

        $card = new TElement('a');
        $card->{'class'} = 'cv-board-card cv-board-card--' . preg_replace('/[^a-z_]/', '', $timeliness);
        $card->{'href'} = 'index.php?class=HospitalizationAdministrationForm&administration_id=' . (int) $row['administration_id'];
        $card->{'generator'} = 'adianti';

        $top = new TElement('span');
        $top->{'class'} = 'cv-board-card__top';
        $top->add(TElement::tag('span', CvFormat::e(self::time((string) $row['scheduled_at'])), ['class' => 'cv-board-card__time']));
        $top->add(CvBadge::create($label, $tone));
        $card->add($top);

        $card->add(TElement::tag('span', CvFormat::e((string) $row['patient_name']), ['class' => 'cv-board-card__patient']));
        $card->add(TElement::tag('span', CvFormat::e(_t('Bed') . ' ' . (string) $row['bed_code']), ['class' => 'cv-board-card__bed']));
        $card->add(TElement::tag('span', CvFormat::e((string) $row['description_text']), ['class' => 'cv-board-card__item']));

        $dose = trim((string) ($row['dose_text'] ?? ''));
        $route = (string) ($row['route'] ?? '');
        $routeLabel = isset(self::ROUTE_LABELS[$route]) ? _t(self::ROUTE_LABELS[$route]) : $route;
        $doseRoute = implode(' · ', array_filter([$dose, $routeLabel], static fn ($part) => $part !== ''));

        if ($doseRoute !== '')
        {
            $card->add(TElement::tag('span', CvFormat::e($doseRoute), ['class' => 'cv-board-card__dose']));
        }

        return $card;
    }

    /**
     * @return array{0: string, 1: string} rótulo traduzido e tom do CvBadge
     */
    private static function timelinessBadge(string $timeliness): array
    {
        $map = [
            'late' => [_t('Late'), 'danger'],
            'due' => [_t('Due now'), 'warning'],
            'upcoming' => [_t('Upcoming'), 'info'],
            'done' => [_t('Done'), 'success'],
            'done_late' => [_t('Done late'), 'warning'],
            'skipped' => [_t('Skipped'), 'neutral'],
        ];

        return $map[$timeliness] ?? [$timeliness, 'neutral'];
    }

    /**
     * `H:i` para hoje; `d/m H:i` para outro dia.
     */
    private static function time(string $scheduledAt): string
    {
        try
        {
            $at = new DateTimeImmutable($scheduledAt);
        }
        catch (Exception $e)
        {
            return $scheduledAt;
        }

        return $at->format('Y-m-d') === date('Y-m-d') ? $at->format('H:i') : $at->format('d/m H:i');
    }

    /**
     * @param list<CentralVet\Domain\Hospitalization> $hospitalizations
     * @return list<array{id: int, patient: string, bed: string, since: string}>
     */
    private static function admittedCards(array $hospitalizations, $patients, $beds): array
    {
        $cards = [];

        foreach ($hospitalizations as $hospitalization)
        {
            $patient = $patients->findById($hospitalization->patientId());
            $bed = $beds->findById($hospitalization->bedId());

            $cards[] = [
                'id' => (int) $hospitalization->id(),
                'patient' => $patient !== null ? (string) $patient->name : _t('Patient') . ' #' . $hospitalization->patientId(),
                'bed' => $bed !== null ? $bed->code() : '#' . $hospitalization->bedId(),
                'since' => $hospitalization->admittedAt()->format('d/m H:i'),
            ];
        }

        return $cards;
    }

    /**
     * @param list<array{id: int, patient: string, bed: string, since: string}> $admitted
     */
    private static function admittedStrip(array $admitted): TElement
    {
        $section = new TElement('section');
        $section->{'class'} = 'cv-board-strip';
        $section->{'aria-labelledby'} = 'cv-board-strip-title';
        $section->add(TElement::tag('h2', CvFormat::e(_t('Hospitalized')), ['class' => 'cv-board-col__title', 'id' => 'cv-board-strip-title']));

        $list = new TElement('div');
        $list->{'class'} = 'cv-board-strip__list';

        foreach ($admitted as $item)
        {
            $card = new TElement('a');
            $card->{'class'} = 'cv-board-card cv-board-card--patient';
            $card->{'href'} = 'index.php?class=HospitalizationView&id=' . $item['id'];
            $card->{'generator'} = 'adianti';
            $card->add(TElement::tag('span', CvFormat::e($item['patient']), ['class' => 'cv-board-card__patient']));
            $card->add(TElement::tag('span', CvFormat::e(_t('Bed') . ' ' . $item['bed']), ['class' => 'cv-board-card__bed']));
            $card->add(TElement::tag('span', CvFormat::e(_t('Since ^1', $item['since'])), ['class' => 'cv-board-card__dose']));
            $list->add($card);
        }

        $section->add($list);

        return $section;
    }

    private static function makeHospitalizationService(CentralVet\Tenancy\TenantContext $context, $connection, $authorization)
    {
        return new CentralVet\Application\HospitalizationService(
            new CentralVet\Persistence\HospitalizationRepository($context, $connection),
            new CentralVet\Persistence\BedRepository($context, $connection),
            new CentralVet\Persistence\HospitalizationEventRepository($context, $connection),
            new CentralVet\Persistence\EncounterRepository($context, $connection),
            new CentralVet\Persistence\EncounterAccountRepository($context, $connection),
            new CentralVet\Persistence\TenantUserDirectory($context, $connection),
            $authorization,
            $context,
        );
    }

    private static function makeOrderService(CentralVet\Tenancy\TenantContext $context, $connection, $authorization)
    {
        return new CentralVet\Application\HospitalizationOrderService(
            new CentralVet\Persistence\HospitalizationOrderRepository($context, $connection),
            new CentralVet\Persistence\HospitalizationAdministrationRepository($context, $connection),
            new CentralVet\Persistence\HospitalizationRepository($context, $connection),
            new CentralVet\Persistence\ProductRepository($context, $connection),
            $authorization,
            $context,
        );
    }

    /**
     * Resolves the tenant context of the authenticated session (mesmo padrão
     * de QueueEntryView::resolveTenantContext()).
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
