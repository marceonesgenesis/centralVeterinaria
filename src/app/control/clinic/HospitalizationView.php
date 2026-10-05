<?php

/**
 * HospitalizationView
 *
 * Ficha da internação (Fase 6A, T-14): cabeçalho com paciente, leito,
 * responsável, admissão, previsão de alta e status; abas de prescrições,
 * administrações e evolução/parâmetros; ações de transferência de leito e
 * de alta integrada.
 *
 * Toda regra vive nos Application services (HospitalizationService,
 * HospitalizationOrderService, HospitalizationDischargeService); esta tela
 * só lê o que eles devolvem e repositórios tenant-aware para rótulos de
 * exibição (paciente, códigos de leito), como o EncounterView.
 *
 * A alta cruza internação, conta do atendimento e estoque e roda dentro de
 * um único TTransaction('permission'): qualquer exceção (estoque
 * insuficiente, conta fechada, leito já liberado) desfaz tudo. A
 * transferência também (occupy() perdido após o save precisa de rollback).
 *
 * Sem `id` → estado vazio antes de resolver o tenant. Usuário de outra
 * unidade → AuthorizationDenied em get(), mensagem de permissão negada e
 * nenhum dado do paciente na página.
 *
 * @version    1.0
 * @package    control
 * @subpackage clinic
 */
class HospitalizationView extends TPage
{
    private const ACTION_READ = 'HospitalizationView::onReload';
    private const ACTION_TRANSFER = 'HospitalizationView::onTransfer';
    private const ACTION_DISCHARGE = 'HospitalizationView::onDischarge';
    private const ACTION_SUSPEND = 'HospitalizationView::onSuspendOrder';

    private const TABS = ['orders', 'administrations', 'timeline'];

    private const TOUCH = 'min-height:var(--cv-touch-target)';

    private const DISCHARGE_FORM = 'form_HospitalizationView_discharge';

    private ?int $hospitalizationId;

    public function __construct($param = null)
    {
        parent::__construct();

        $this->hospitalizationId = self::paramInt('id', $param);

        $tab = (string) ($_GET['tab'] ?? (is_array($param) ? ($param['tab'] ?? '') : ''));
        if (!in_array($tab, self::TABS, true))
        {
            $tab = 'orders';
        }

        $container = new TVBox;
        $container->style = 'width: 100%';

        $back = ['icon' => 'fa:arrow-left', 'href' => 'index.php?class=HospitalizationBoard'];

        if ($this->hospitalizationId === null)
        {
            $container->add(CvPage::header(_t('Hospitalization'), null, [$back]));
            $container->add(self::statePanel('empty', _t('Open a hospitalization from the hospitalization board or from the encounter.')));
            parent::add($container);
            return;
        }

        $data = $this->loadData($this->hospitalizationId);

        if ($data === null)
        {
            // a recusa já foi mostrada como TMessage; nenhum dado do paciente
            $container->add(CvPage::header(_t('Hospitalization'), null, [$back]));
            $container->add(self::statePanel('error', _t('This hospitalization could not be loaded.')));
            parent::add($container);
            return;
        }

        $container->add($this->buildHeader($data, $back));

        $base = 'index.php?class=HospitalizationView&id=' . $this->hospitalizationId . '&tab=';
        $container->add(CvPage::tabs([
            'orders'          => ['label' => _t('Prescriptions'), 'href' => $base . 'orders'],
            'administrations' => ['label' => _t('Administrations'), 'href' => $base . 'administrations'],
            'timeline'        => ['label' => _t('Evolution and vital signs'), 'href' => $base . 'timeline'],
        ], $tab));

        $main = match ($tab) {
            'administrations' => $this->buildAdministrationsPanel($data),
            'timeline'        => $this->buildTimelinePanel($data),
            default           => $this->buildOrdersPanel($data),
        };

        $container->add(CvPage::columns($main, $this->buildSidePanel($data)));

        parent::add($container);
    }

    /**
     * Destino das ações de mensagem (OK do TMessage): o construtor já
     * renderiza a ficha com o `id` da URL.
     */
    public function onReload($param = null)
    {
    }

    /**
     * Transferência de leito (HospitalizationService::transfer) num único
     * TTransaction: occupy() perdido após o save desfaz a troca. Estática:
     * recusa (inclusive sem leito de destino) mostra a mensagem e mantém a
     * ficha e o formulário como estão; sucesso recarrega pelo OK.
     */
    public static function onTransfer($param)
    {
        $id = self::paramInt('id', $param);

        try
        {
            $toBedId = (int) ($param['to_bed_id'] ?? 0);

            if ($id === null || $toBedId <= 0)
            {
                throw new InvalidArgumentException(_t('Select the destination bed'));
            }

            $context = self::resolveTenantContext();

            TTransaction::open('permission');
            self::makeHospitalizationService($context)->transfer($id, $toBedId, self::ACTION_TRANSFER);
            TTransaction::close();

            new TMessage('info', _t('Patient transferred'), self::reloadAction($id, 'timeline'));
        }
        catch (\CentralVet\Authorization\Exception\AuthorizationDenied $e)
        {
            TTransaction::rollback();
            new TMessage('error', _t('You are not allowed to change this hospitalization'));
        }
        catch (\CentralVet\Tenancy\Exception\MissingTenantContext $e)
        {
            TTransaction::rollback();
            new TMessage('error', _t('An authenticated session with an active unit is required'));
        }
        catch (Exception $e)
        {
            TTransaction::rollback();
            error_log(__METHOD__ . ': ' . $e->getMessage());
            new TMessage('error', CvFormat::userError($e));
        }
    }

    /**
     * Confirmação da alta: o resumo é obrigatório; confirmada, chama
     * onDischarge() com `id` e `summary_text`.
     */
    public static function onAskDischarge($param = null)
    {
        $id = (int) ($param['id'] ?? 0);

        if ($id <= 0 || self::postedSummary() === '')
        {
            new TMessage('error', _t('The discharge summary is required'));
            return;
        }

        // o "Sim" reenvia o formulário da alta por POST: o resumo (texto
        // clínico) vai no corpo, nunca na URL (TQuestion só carrega URL)
        $action = new TAction([__CLASS__, 'onDischarge']);
        $action->setParameter('id', $id);
        $action->setParameter('static', '1');

        $yes = "function () { __adianti_post_data('" . self::DISCHARGE_FORM . "', '"
            . addslashes($action->serialize(false)) . "'); }";

        TScript::create(sprintf(
            "__adianti_question('%s', '%s', %s, function () {}, '%s', '%s')",
            addslashes(AdiantiCoreTranslator::translate('Question')),
            addslashes(_t('Discharge this patient? The stay and the performed administrations will be billed to the encounter account and their products consumed from stock.')),
            $yes,
            addslashes(AdiantiCoreTranslator::translate('Yes')),
            addslashes(AdiantiCoreTranslator::translate('No'))
        ));
    }

    /**
     * Resumo da alta só do corpo do POST: o mesmo campo na query string é
     * ignorado.
     */
    private static function postedSummary(): string
    {
        return trim((string) ($_POST['summary_text'] ?? ''));
    }

    /**
     * Alta integrada (HospitalizationDischargeService::discharge) dentro de
     * um único TTransaction('permission'): qualquer exceção — estoque
     * insuficiente, conta fechada, leito já liberado — dá rollback de tudo.
     * Estática e com o resumo só do POST (ver onAskDischarge()).
     */
    public static function onDischarge($param)
    {
        $id = self::paramInt('id', $param);

        try
        {
            $summary = self::postedSummary();

            if ($id === null || $summary === '')
            {
                throw new InvalidArgumentException(_t('The discharge summary is required'));
            }

            $context = self::resolveTenantContext();

            TTransaction::open('permission');

            $result = self::makeDischargeService($context)->discharge($id, $summary, self::ACTION_DISCHARGE);
            $encounterId = self::makeHospitalizationService($context)->get($id, self::ACTION_READ)->encounterId();

            TTransaction::close();

            $accountUrl = 'index.php?class=EncounterAccountForm&encounter_id=' . (int) $encounterId;

            new TMessage(
                'info',
                _t('Patient discharged')
                . '<br>' . _t('Billed days') . ': ' . (int) $result['billable_days']
                . '<br>' . _t('Items added to the account') . ': ' . (int) $result['items_added']
                . '<br>' . _t('Products consumed') . ': ' . (int) $result['consumed_products']
                . '<br><a href="' . $accountUrl . '">' . _t('Open encounter account') . '</a>',
                self::reloadAction($id, 'timeline')
            );
        }
        catch (\CentralVet\Authorization\Exception\AuthorizationDenied $e)
        {
            TTransaction::rollback();
            new TMessage('error', _t('You are not allowed to change this hospitalization'));
        }
        catch (\CentralVet\Tenancy\Exception\MissingTenantContext $e)
        {
            TTransaction::rollback();
            new TMessage('error', _t('An authenticated session with an active unit is required'));
        }
        catch (Exception $e)
        {
            TTransaction::rollback();
            error_log(__METHOD__ . ': ' . $e->getMessage());
            new TMessage('error', CvFormat::userError($e));
        }
    }

    /**
     * Confirmação da suspensão de uma prescrição.
     */
    public static function onAskSuspendOrder($param = null)
    {
        $action = new TAction([__CLASS__, 'onSuspendOrder']);
        $action->setParameter('id', (int) ($param['id'] ?? 0));
        $action->setParameter('order_id', (int) ($param['order_id'] ?? 0));

        new TQuestion(_t('Suspend this prescription? Its future pending administrations will be cancelled.'), $action);
    }

    public function onSuspendOrder($param)
    {
        $id = self::paramInt('id', $param);

        try
        {
            $orderId = (int) ($param['order_id'] ?? 0);

            if ($orderId <= 0)
            {
                throw new InvalidArgumentException(_t('Invalid prescription'));
            }

            $context = self::resolveTenantContext();

            TTransaction::open('permission');
            self::makeOrderService($context)->suspend($orderId, self::ACTION_SUSPEND);
            TTransaction::close();

            new TMessage('info', _t('Prescription suspended'), self::reloadAction((int) $id, 'orders'));
        }
        catch (\CentralVet\Authorization\Exception\AuthorizationDenied $e)
        {
            TTransaction::rollback();
            new TMessage('error', _t('You are not allowed to change this hospitalization'));
        }
        catch (\CentralVet\Tenancy\Exception\MissingTenantContext $e)
        {
            TTransaction::rollback();
            new TMessage('error', _t('An authenticated session with an active unit is required'));
        }
        catch (Exception $e)
        {
            TTransaction::rollback();
            error_log(__METHOD__ . ': ' . $e->getMessage());
            new TMessage('error', CvFormat::userError($e));
        }
    }

    /**
     * Lê tudo o que a ficha mostra numa transação só leitura. A primeira
     * chamada é get(): de outra unidade, AuthorizationDenied antes de
     * qualquer dado do paciente.
     *
     * @return array<string, mixed>|null
     */
    private function loadData(int $id): ?array
    {
        try
        {
            $context = self::resolveTenantContext();

            TTransaction::open('permission');
            $connection = TTransaction::get();

            $hospitalizationService = self::makeHospitalizationService($context);
            $orderService = self::makeOrderService($context);

            $hospitalization = $hospitalizationService->get($id, self::ACTION_READ);
            $events = $hospitalizationService->listEvents($id, self::ACTION_READ);
            $orders = $orderService->listOrders($id, self::ACTION_READ);
            $administrations = $orderService->listAdministrations($id, self::ACTION_READ);

            $patient = (new \CentralVet\Persistence\PatientRepository($context, $connection))
                ->findById($hospitalization->patientId());

            $beds = [];
            foreach ((new \CentralVet\Persistence\BedRepository($context, $connection))->listByUnit($hospitalization->systemUnitId()) as $bed)
            {
                $beds[(int) $bed->id()] = $bed;
            }

            $responsible = null;
            try
            {
                $user = SystemUser::findInTransaction('permission', $hospitalization->responsibleSystemUserId());
                $responsible = $user ? (string) $user->name : null;
            }
            catch (Exception $e)
            {
                // rótulo de exibição: nunca bloqueia a ficha
            }

            TTransaction::close();

            return [
                'hospitalization' => $hospitalization,
                'events'          => $events,
                'orders'          => $orders,
                'administrations' => $administrations,
                'patient_name'    => $patient !== null ? (string) $patient->name : null,
                'beds'            => $beds,
                'responsible'     => $responsible,
            ];
        }
        catch (\CentralVet\Authorization\Exception\AuthorizationDenied $e)
        {
            TTransaction::rollback();
            new TMessage('error', _t('You are not allowed to access this hospitalization'));
        }
        catch (\CentralVet\Tenancy\Exception\MissingTenantContext $e)
        {
            TTransaction::rollback();
            new TMessage('error', _t('An authenticated session with an active unit is required'));
        }
        catch (Exception $e)
        {
            TTransaction::rollback();
            error_log(__METHOD__ . ': ' . $e->getMessage());
            new TMessage('error', CvFormat::userError($e));
        }

        return null;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, string> $back
     */
    private function buildHeader(array $data, array $back): TElement
    {
        /** @var \CentralVet\Domain\Hospitalization $h */
        $h = $data['hospitalization'];

        $title = $data['patient_name'] ?? (_t('Hospitalization') . ' #' . (int) $h->id());

        $parts = [
            _t('Bed') . ': ' . self::bedLabel($data['beds'], $h->bedId()),
            _t('Responsible') . ': ' . ($data['responsible'] ?? '—'),
            _t('Admitted at') . ': ' . $h->admittedAt()->format('d/m/Y H:i'),
            _t('Expected discharge') . ': ' . ($h->expectedDischargeDate() !== null ? $h->expectedDischargeDate()->format('d/m/Y') : '—'),
        ];

        if ($h->dischargedAt() !== null)
        {
            $parts[] = _t('Discharged at') . ': ' . $h->dischargedAt()->format('d/m/Y H:i');
        }

        $badge = $h->status() === \CentralVet\Domain\Hospitalization::STATUS_ADMITTED
            ? CvBadge::create(_t('Hospitalized'), 'info')
            : CvBadge::create(_t('Discharged'), 'success');

        return CvPage::header($title, implode(' · ', $parts), [$badge, $back]);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function buildOrdersPanel(array $data): TPanelGroup
    {
        /** @var \CentralVet\Domain\Hospitalization $h */
        $h = $data['hospitalization'];
        $admitted = $h->status() === \CentralVet\Domain\Hospitalization::STATUS_ADMITTED;

        $panel = new TPanelGroup(_t('Prescriptions'));
        $panel->class = 'cv-section';

        if ($admitted)
        {
            $panel->add(self::linkButton(
                _t('New prescription'),
                'index.php?class=HospitalizationOrderForm&hospitalization_id=' . (int) $h->id(),
                'fa:plus',
                'btn btn-primary'
            ));
        }

        if ($data['orders'] === [])
        {
            $panel->add(self::statePanel('empty', _t('No prescriptions yet.')));
            return $panel;
        }

        $table = self::table([_t('Description'), _t('Dose'), _t('Route'), _t('Frequency'), _t('Period'), _t('Status'), '']);

        /** @var \CentralVet\Domain\HospitalizationOrder $order */
        foreach ($data['orders'] as $order)
        {
            $active = $order->status() === \CentralVet\Domain\HospitalizationOrder::STATUS_ACTIVE;

            $actions = new TElement('span');
            if ($active)
            {
                $suspend = new TAction([__CLASS__, 'onAskSuspendOrder']);
                $suspend->setParameter('id', (int) $h->id());
                $suspend->setParameter('order_id', (int) $order->id());
                $actions->add(self::linkButton(_t('Suspend'), $suspend->serialize(true, true), 'fa:pause', 'btn btn-default'));
            }

            self::addRow($table, [
                CvFormat::e($order->descriptionText()),
                CvFormat::e($order->doseText()),
                CvFormat::e($order->route()),
                CvFormat::e(_t('Every ^1 h', (string) $order->frequencyHours())),
                CvFormat::e($order->startsAt()->format('d/m/Y H:i') . ' – ' . $order->endsAt()->format('d/m/Y H:i')),
                $active ? CvBadge::create(_t('Active'), 'info') : CvBadge::create(_t('Suspended'), 'neutral'),
                $actions,
            ]);
        }

        $panel->add($table);

        return $panel;
    }

    /**
     * Administrações de hoje e próximas, com o badge de classify().
     *
     * @param array<string, mixed> $data
     */
    private function buildAdministrationsPanel(array $data): TPanelGroup
    {
        /** @var \CentralVet\Domain\Hospitalization $h */
        $h = $data['hospitalization'];
        $admitted = $h->status() === \CentralVet\Domain\Hospitalization::STATUS_ADMITTED;

        $panel = new TPanelGroup(_t('Administrations'));
        $panel->class = 'cv-section';

        $descriptions = [];
        foreach ($data['orders'] as $order)
        {
            $descriptions[(int) $order->id()] = $order->descriptionText();
        }

        $now = new DateTimeImmutable();
        $today = $now->setTime(0, 0);

        $rows = array_filter(
            $data['administrations'],
            static fn (\CentralVet\Domain\HospitalizationAdministration $a): bool => $a->scheduledAt() >= $today
        );

        if ($rows === [])
        {
            $panel->add(self::statePanel('empty', _t('No administrations scheduled for today or later.')));
            return $panel;
        }

        $table = self::table([_t('Scheduled at'), _t('Prescription'), _t('Status'), _t('Performed at'), '']);

        /** @var \CentralVet\Domain\HospitalizationAdministration $administration */
        foreach ($rows as $administration)
        {
            $timeliness = \CentralVet\Domain\HospitalizationAdministration::classify(
                $administration->status(),
                $administration->scheduledAt(),
                $administration->performedAt(),
                $now
            );

            $action = new TElement('span');
            if ($admitted && $administration->status() === \CentralVet\Domain\HospitalizationAdministration::STATUS_PENDING)
            {
                $action->add(self::linkButton(
                    _t('Record'),
                    'index.php?class=HospitalizationAdministrationForm&administration_id=' . (int) $administration->id(),
                    'fa:check',
                    'btn btn-default'
                ));
            }

            self::addRow($table, [
                CvFormat::e($administration->scheduledAt()->format('d/m/Y H:i')),
                CvFormat::e($descriptions[$administration->orderId()] ?? ('#' . $administration->orderId())),
                self::timelinessBadge($timeliness),
                CvFormat::e($administration->performedAt() !== null ? $administration->performedAt()->format('d/m/Y H:i') : '—'),
                $action,
            ]);
        }

        $panel->add($table);

        return $panel;
    }

    /**
     * Linha do tempo de listEvents() (mais recente primeiro).
     *
     * @param array<string, mixed> $data
     */
    private function buildTimelinePanel(array $data): TPanelGroup
    {
        /** @var \CentralVet\Domain\Hospitalization $h */
        $h = $data['hospitalization'];

        $panel = new TPanelGroup(_t('Evolution and vital signs'));
        $panel->class = 'cv-section';

        if ($h->status() === \CentralVet\Domain\Hospitalization::STATUS_ADMITTED)
        {
            $base = 'index.php?class=HospitalizationEventForm&hospitalization_id=' . (int) $h->id() . '&type=';
            $buttons = new TElement('div');
            $buttons->style = 'display:flex; gap:var(--cv-space-2); flex-wrap:wrap; margin-bottom:var(--cv-space-3)';
            $buttons->add(self::linkButton(_t('Record evolution'), $base . 'evolution', 'fa:notes-medical', 'btn btn-primary'));
            $buttons->add(self::linkButton(_t('Record vital signs'), $base . 'vitals', 'fa:heartbeat', 'btn btn-default'));
            $panel->add($buttons);
        }

        if ($data['events'] === [])
        {
            $panel->add(self::statePanel('empty', _t('No events recorded yet.')));
            return $panel;
        }

        $list = new TElement('ol');
        $list->{'class'} = 'cv-timeline';
        $list->style = 'list-style:none; padding:0; margin:0';

        /** @var \CentralVet\Domain\HospitalizationEvent $event */
        foreach ($data['events'] as $event)
        {
            $item = new TElement('li');
            $item->style = 'padding:var(--cv-space-2) 0; border-bottom:1px solid var(--cv-color-border)';

            $head = new TElement('div');
            $head->style = 'display:flex; gap:var(--cv-space-2); align-items:center';
            $head->add(CvBadge::create(self::eventLabel($event->eventType()), 'neutral'));
            $head->add(TElement::tag('time', CvFormat::e($event->recordedAt()->format('d/m/Y H:i')), [
                'datetime' => $event->recordedAt()->format('Y-m-d\TH:i'),
                'style'    => 'color:var(--cv-color-text-muted)',
            ]));
            $item->add($head);

            if ($event->eventType() === \CentralVet\Domain\HospitalizationEvent::TYPE_TRANSFER)
            {
                $item->add(TElement::tag('p', CvFormat::e(
                    self::bedLabel($data['beds'], $event->fromBedId()) . ' → ' . self::bedLabel($data['beds'], $event->toBedId())
                ), ['style' => 'margin:var(--cv-space-1) 0 0']));
            }

            $vitals = self::vitalsLine($event);
            if ($vitals !== '')
            {
                $item->add(TElement::tag('p', CvFormat::e($vitals), ['style' => 'margin:var(--cv-space-1) 0 0']));
            }

            if ($event->notesText() !== null)
            {
                $item->add(TElement::tag('p', nl2br(CvFormat::e($event->notesText())), ['style' => 'margin:var(--cv-space-1) 0 0']));
            }

            $list->add($item);
        }

        $panel->add($list);

        return $panel;
    }

    /**
     * Coluna lateral: transferência e alta (internação ativa) ou o resumo
     * da alta com link para a conta do atendimento.
     *
     * @param array<string, mixed> $data
     */
    private function buildSidePanel(array $data): TElement
    {
        /** @var \CentralVet\Domain\Hospitalization $h */
        $h = $data['hospitalization'];
        $id = (int) $h->id();

        $side = new TElement('div');

        if ($h->status() !== \CentralVet\Domain\Hospitalization::STATUS_ADMITTED)
        {
            $panel = new TPanelGroup(_t('Discharge'));
            $panel->class = 'cv-section';
            if ($h->dischargeSummaryText() !== null)
            {
                $panel->add(TElement::tag('p', nl2br(CvFormat::e($h->dischargeSummaryText())), []));
            }
            $panel->add(self::linkButton(
                _t('Open encounter account'),
                'index.php?class=EncounterAccountForm&encounter_id=' . (int) $h->encounterId(),
                'fa:file-invoice-dollar',
                'btn btn-default'
            ));
            $side->add($panel);

            return $side;
        }

        // transferência: leitos disponíveis da mesma unidade
        $options = [];
        /** @var \CentralVet\Domain\Bed $bed */
        foreach ($data['beds'] as $bed)
        {
            if ($bed->isAvailable() && (int) $bed->id() !== $h->bedId())
            {
                $options[(int) $bed->id()] = $bed->code() . ' — ' . $bed->name();
            }
        }

        $transferForm = new BootstrapFormBuilder('form_HospitalizationView_transfer');
        $transferForm->setFormTitle(_t('Transfer bed'));

        $toBed = new TCombo('to_bed_id');
        $toBed->addItems($options);
        $toBed->setSize('100%');

        $transferForm->addFields([new TLabel(_t('Destination bed'))]);
        $transferForm->addFields([$toBed]);

        $transferAction = new TAction([__CLASS__, 'onTransfer']);
        $transferAction->setParameter('id', $id);
        $transferButton = $transferForm->addAction(_t('Transfer'), $transferAction, 'fa:exchange-alt');
        $transferButton->class = 'btn btn-default cv-touch-target';
        $transferButton->style = self::TOUCH;

        CvForm::decorate($transferForm, 1);
        $side->add($transferForm);

        // alta: resumo obrigatório, confirmação por TQuestion
        $dischargeForm = new BootstrapFormBuilder(self::DISCHARGE_FORM);
        $dischargeForm->setFormTitle(_t('Discharge'));

        $summary = new TText('summary_text');
        $summary->setSize('100%', 100);
        $summary->setProperty('maxlength', '2000');

        $dischargeForm->addFields([new TLabel(_t('Discharge summary'))]);
        $dischargeForm->addFields([$summary]);

        $dischargeAction = new TAction([__CLASS__, 'onAskDischarge']);
        $dischargeAction->setParameter('id', $id);
        $dischargeButton = $dischargeForm->addAction(_t('Discharge'), $dischargeAction, 'fa:sign-out-alt');
        $dischargeButton->class = 'btn btn-primary cv-touch-target';
        $dischargeButton->style = self::TOUCH;

        CvForm::decorate($dischargeForm, 1);
        $side->add($dischargeForm);

        return $side;
    }

    private static function statePanel(string $state, string $message): TElement
    {
        $box = new TElement('div');
        $box->{'class'} = 'cv-state cv-state--' . $state;
        $box->style = 'padding:var(--cv-space-4); text-align:center; color:var(--cv-color-text-muted)';
        $box->add(TElement::tag('p', CvFormat::e($message), ['style' => 'margin:0']));

        return $box;
    }

    private static function linkButton(string $label, string $href, string $icon, string $class): TElement
    {
        $link = new TElement('a');
        $link->{'class'} = $class . ' cv-touch-target';
        $link->{'href'} = CvFormat::e($href);
        $link->{'generator'} = 'adianti';
        $link->style = self::TOUCH . '; display:inline-flex; align-items:center; gap:var(--cv-space-1)';
        $link->add(new TImage($icon));
        $link->add(TElement::tag('span', CvFormat::e($label), []));

        return $link;
    }

    /**
     * @param list<string> $headers
     */
    private static function table(array $headers): TElement
    {
        $table = new TElement('table');
        $table->{'class'} = 'table table-sm align-middle';

        $head = new TElement('thead');
        $row = new TElement('tr');
        foreach ($headers as $header)
        {
            $row->add(TElement::tag('th', CvFormat::e($header), ['scope' => 'col']));
        }
        $head->add($row);
        $table->add($head);

        $body = new TElement('tbody');
        $table->add($body);

        return $table;
    }

    /**
     * @param list<string|TElement> $cells conteúdo já escapado ou widget
     */
    private static function addRow(TElement $table, array $cells): void
    {
        $row = new TElement('tr');
        foreach ($cells as $cell)
        {
            $td = new TElement('td');
            $td->add($cell);
            $row->add($td);
        }

        $children = $table->getChildren();
        end($children)->add($row);
    }

    private static function timelinessBadge(string $timeliness): TElement
    {
        return match ($timeliness) {
            \CentralVet\Domain\HospitalizationAdministration::TIMELINESS_DUE       => CvBadge::create(_t('Due now'), 'info'),
            \CentralVet\Domain\HospitalizationAdministration::TIMELINESS_LATE      => CvBadge::create(_t('Late'), 'danger'),
            \CentralVet\Domain\HospitalizationAdministration::TIMELINESS_DONE      => CvBadge::create(_t('Done'), 'success'),
            \CentralVet\Domain\HospitalizationAdministration::TIMELINESS_DONE_LATE => CvBadge::create(_t('Done late'), 'warning'),
            \CentralVet\Domain\HospitalizationAdministration::TIMELINESS_SKIPPED   => CvBadge::create(_t('Skipped'), 'warning'),
            \CentralVet\Domain\HospitalizationAdministration::TIMELINESS_CANCELLED => CvBadge::create(_t('Cancelled'), 'neutral'),
            default                                                                 => CvBadge::create(_t('Upcoming'), 'neutral'),
        };
    }

    private static function eventLabel(string $type): string
    {
        return match ($type) {
            \CentralVet\Domain\HospitalizationEvent::TYPE_ADMISSION => _t('Admission'),
            \CentralVet\Domain\HospitalizationEvent::TYPE_TRANSFER  => _t('Transfer'),
            \CentralVet\Domain\HospitalizationEvent::TYPE_EVOLUTION => _t('Evolution'),
            \CentralVet\Domain\HospitalizationEvent::TYPE_VITALS    => _t('Vital signs'),
            \CentralVet\Domain\HospitalizationEvent::TYPE_DISCHARGE => _t('Discharge'),
            default                                                 => $type,
        };
    }

    private static function vitalsLine(\CentralVet\Domain\HospitalizationEvent $event): string
    {
        $parts = [];
        if ($event->temperatureC() !== null)
        {
            $parts[] = _t('Temperature') . ' ' . number_format($event->temperatureC(), 1, ',', '') . ' °C';
        }
        if ($event->heartRateBpm() !== null)
        {
            $parts[] = _t('HR') . ' ' . $event->heartRateBpm() . ' bpm';
        }
        if ($event->respiratoryRateRpm() !== null)
        {
            $parts[] = _t('RR') . ' ' . $event->respiratoryRateRpm() . ' rpm';
        }
        if ($event->weightKg() !== null)
        {
            $parts[] = _t('Weight') . ' ' . number_format($event->weightKg(), 2, ',', '') . ' kg';
        }
        if ($event->painScore() !== null)
        {
            $parts[] = _t('Pain') . ' ' . $event->painScore();
        }

        return implode(' · ', $parts);
    }

    /**
     * @param array<int, \CentralVet\Domain\Bed> $beds
     */
    private static function bedLabel(array $beds, ?int $bedId): string
    {
        if ($bedId === null)
        {
            return '—';
        }

        return isset($beds[$bedId]) ? $beds[$bedId]->code() : '#' . $bedId;
    }

    private static function reloadAction(int $id, string $tab): TAction
    {
        $action = new TAction([__CLASS__, 'onReload']);
        $action->setParameter('id', $id);
        $action->setParameter('tab', $tab);

        return $action;
    }

    private static function paramInt(string $name, $param): ?int
    {
        if (isset($_GET[$name]) && $_GET[$name] !== '' && (int) $_GET[$name] > 0)
        {
            return (int) $_GET[$name];
        }

        if (is_array($param) && isset($param[$name]) && $param[$name] !== '' && (int) $param[$name] > 0)
        {
            return (int) $param[$name];
        }

        return null;
    }

    /**
     * RBAC real (Fase 0) com auditoria na mesma conexão. Exige um
     * TTransaction('permission') aberto.
     */
    private static function makeAuthorization(): \CentralVet\Authorization\RbacAuthorizationService
    {
        return new \CentralVet\Authorization\RbacAuthorizationService(
            new \CentralVet\Authorization\AdiantiProgramPermissionProvider(new \CentralVet\Tenancy\AdiantiSessionContextSource()),
            new \CentralVet\Audit\PdoAuditLogWriter(TTransaction::get()),
        );
    }

    private static function makeHospitalizationService(
        \CentralVet\Tenancy\TenantContext $context
    ): \CentralVet\Application\HospitalizationService {
        $connection = TTransaction::get();

        return new \CentralVet\Application\HospitalizationService(
            new \CentralVet\Persistence\HospitalizationRepository($context, $connection),
            new \CentralVet\Persistence\BedRepository($context, $connection),
            new \CentralVet\Persistence\HospitalizationEventRepository($context, $connection),
            new \CentralVet\Persistence\EncounterRepository($context, $connection),
            new \CentralVet\Persistence\EncounterAccountRepository($context, $connection),
            new \CentralVet\Persistence\TenantUserDirectory($context, $connection),
            self::makeAuthorization(),
            $context,
        );
    }

    private static function makeOrderService(
        \CentralVet\Tenancy\TenantContext $context
    ): \CentralVet\Application\HospitalizationOrderService {
        $connection = TTransaction::get();

        return new \CentralVet\Application\HospitalizationOrderService(
            new \CentralVet\Persistence\HospitalizationOrderRepository($context, $connection),
            new \CentralVet\Persistence\HospitalizationAdministrationRepository($context, $connection),
            new \CentralVet\Persistence\HospitalizationRepository($context, $connection),
            new \CentralVet\Persistence\ProductRepository($context, $connection),
            self::makeAuthorization(),
            $context,
        );
    }

    /**
     * Alta integrada: internação + conta do atendimento (montada como no
     * EncounterAccountForm) + estoque, todos na mesma conexão.
     */
    private static function makeDischargeService(
        \CentralVet\Tenancy\TenantContext $context
    ): \CentralVet\Application\HospitalizationDischargeService {
        $connection = TTransaction::get();
        $authorization = self::makeAuthorization();

        $accounts = new \CentralVet\Application\EncounterAccountService(
            new \CentralVet\Persistence\EncounterAccountRepository($context, $connection),
            new \CentralVet\Persistence\EncounterAccountItemRepository($context, $connection),
            new \CentralVet\Persistence\ReceivableRepository($context, $connection),
            new \CentralVet\Persistence\EncounterRepository($context, $connection),
            new \CentralVet\Persistence\PatientRepository($context, $connection),
            new \CentralVet\Persistence\ProcedureExecutionRepository($context, $connection),
            new \CentralVet\Persistence\ExamRequestRepository($context, $connection),
            new \CentralVet\Application\ProcedureCatalogService(
                new \CentralVet\Persistence\ProcedureCatalogRepository($context, $connection),
                new \CentralVet\Persistence\ProcedureCatalogItemInputRepository($context, $connection),
                $context
            ),
            new \CentralVet\Persistence\ExamCatalogRepository($context, $connection),
            $authorization,
            $context,
            new \CentralVet\Persistence\TenantUserDirectory($context, $connection),
        );

        $stock = new \CentralVet\Application\StockService(
            new \CentralVet\Persistence\StockBatchRepository($context, $connection),
            new \CentralVet\Persistence\StockMovementRepository($context, $connection),
            $authorization,
            $context
        );

        return new \CentralVet\Application\HospitalizationDischargeService(
            new \CentralVet\Persistence\HospitalizationRepository($context, $connection),
            new \CentralVet\Persistence\BedRepository($context, $connection),
            new \CentralVet\Persistence\HospitalizationOrderRepository($context, $connection),
            new \CentralVet\Persistence\HospitalizationAdministrationRepository($context, $connection),
            new \CentralVet\Persistence\HospitalizationEventRepository($context, $connection),
            new \CentralVet\Persistence\ProductRepository($context, $connection),
            $accounts,
            $stock,
            $authorization,
            $context,
        );
    }

    /**
     * Contexto de tenant da sessão autenticada, com o mesmo fallback de
     * EncounterAccountForm::resolveTenantContext() para sessões legadas.
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
