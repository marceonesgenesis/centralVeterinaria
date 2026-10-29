<?php
/**
 * PendingCenter
 *
 * Central de Pendências da unidade ativa (Fase 7A, T-19, PRD §8.23): um card
 * por tipo com a contagem (`countsByType`; o clique filtra por `&type=`),
 * filtro "Só meus" (`&mine=1`) e uma linha por pendência com prioridade,
 * tipo, paciente, assunto, prazo, status, responsável e o botão "Resolver"
 * que abre a tela de origem pelo deep-link do item (`PendingItem::deepLinkUrl`,
 * só com ids).
 *
 * Rota: `index.php?class=PendingCenter[&type=<tipo>][&mine=1]`. `type`
 * desconhecido lista todos. Leitura por `PendingCenterService` com a ação
 * `PendingCenter::onReload`; a consulta das 8 fontes roda uma vez por
 * render (memoizada entre `countsByType` e `list`).
 *
 * @version    8.6
 * @package    control
 * @subpackage clinic
 * @license    https://adiantiframework.com.br/license-template
 */
class PendingCenter extends TPage
{
    private const ACTION_RELOAD = 'PendingCenter::onReload';

    /** PendingItem::TYPES → [rótulo en, ícone, tom do card]. */
    private const TYPE_META = [
        'exam_result'                    => ['Exam results to record', 'fa:vial', 'info'],
        'exam_review'                    => ['Exam results to review', 'fa:microscope', 'info'],
        'return_appointment'             => ['Return appointments', 'far:calendar-check', 'info'],
        'vaccine_due'                    => ['Vaccines due', 'fa:syringe', 'warning'],
        'hospitalization_administration' => ['Medication administrations', 'fa:pills', 'danger'],
        'message_failed'                 => ['Failed messages', 'fa:exclamation-triangle', 'danger'],
        'message_whatsapp_manual'        => ['WhatsApp messages to send', 'fab:whatsapp', 'success'],
        'receivable_open'                => ['Open receivables', 'fa:file-invoice-dollar', 'warning'],
        'document_failed'                => ['Failed documents', 'fas:file-circle-exclamation', 'danger'],
    ];

    /** PendingItemPriority → [rótulo en, tom do CvBadge]. */
    private const PRIORITY_BADGE = [
        'urgent' => ['Urgent', 'danger'],
        'high'   => ['High', 'warning'],
        'normal' => ['Normal', 'info'],
        'low'    => ['Low', 'neutral'],
    ];

    private bool $loaded = false;

    public function __construct($param = null)
    {
        parent::__construct();

        // com method, o dispatcher chama onReload(), que já carrega
        if (empty($param['method']))
        {
            $this->onReload(is_array($param) ? $param : []);
        }
    }

    /**
     * Recarrega a central com os filtros `type` e `mine` da URL.
     */
    public function onReload($param = null)
    {
        if ($this->loaded)
        {
            return;
        }
        $this->loaded = true;

        $param = is_array($param) ? $param : [];

        $container = new TVBox;
        $container->style = 'width: 100%';
        $container->add(CvPage::header(_t('Pending items'), _t('What needs attention in this unit')));

        try
        {
            $context = self::resolveTenantContext();

            TTransaction::open('permission');
            $connection = TTransaction::get();

            $content = self::buildContent(
                self::makeService($context, $connection),
                $param,
                static fn (array $ids): array => self::userNames($context, $connection, $ids),
            );

            TTransaction::close();
        }
        catch (\CentralVet\Authorization\Exception\AuthorizationDenied $e)
        {
            TTransaction::rollback();
            new TMessage('error', _t('You are not allowed to view the pending items'));
            parent::add($container);
            return;
        }
        catch (\CentralVet\Tenancy\Exception\MissingTenantContext $e)
        {
            TTransaction::rollback();
            new TMessage('error', _t('An authenticated session with an active unit is required'));
            parent::add($container);
            return;
        }
        catch (Exception $e)
        {
            TTransaction::rollback();
            error_log(__METHOD__ . ': ' . get_class($e));
            new TMessage('error', CvFormat::userError($e));
            parent::add($container);
            return;
        }

        $container->add($content);
        parent::add($container);
    }

    /**
     * Monta cards, filtro e lista a partir do service (sem banco aqui; os
     * nomes dos responsáveis vêm de `$userNames`, que recebe os ids e
     * devolve id => nome). Público para o teste de integração.
     *
     * @param array<string, mixed> $param `type` e `mine` da URL
     * @param callable(list<int>): array<int, string> $userNames
     * @throws \CentralVet\Authorization\Exception\AuthorizationDenied
     */
    public static function buildContent(CentralVet\Application\PendingCenterService $service, array $param, callable $userNames): TElement
    {
        $type = self::typeParam($param['type'] ?? null);
        $mine = (string) ($param['mine'] ?? '') === '1';

        $counts = $service->countsByType(self::ACTION_RELOAD);
        $items = $service->list($type, $mine, self::ACTION_RELOAD);
        $now = $service->now();

        $ids = [];
        foreach ($items as $item)
        {
            if ($item->responsibleSystemUserId() !== null)
            {
                $ids[] = $item->responsibleSystemUserId();
            }
        }
        $names = $ids === [] ? [] : $userNames(array_values(array_unique($ids)));

        $content = new TElement('div');
        $content->{'class'} = 'cv-pending';
        $content->add(self::cards($counts, $type, $mine));
        $content->add(self::filterBar($type, $mine));
        $content->add($items === [] ? self::emptyState() : self::table($items, $names, $now));

        return $content;
    }

    /** Tipo conhecido (PendingItem::TYPES) ou null (lista todos). */
    private static function typeParam($value): ?string
    {
        return is_string($value) && in_array($value, CentralVet\Domain\PendingItem::TYPES, true) ? $value : null;
    }

    private static function url(?string $type, bool $mine): string
    {
        $url = 'index.php?class=' . __CLASS__;
        if ($type !== null)
        {
            $url .= '&type=' . $type;
        }
        if ($mine)
        {
            $url .= '&mine=1';
        }

        return $url;
    }

    /**
     * @param array<string, int> $counts
     */
    private static function cards(array $counts, ?string $active, bool $mine): TElement
    {
        $row = new TElement('div');
        $row->{'class'} = 'cv-pending-cards';

        foreach (self::TYPE_META as $type => [$label, $icon, $tone])
        {
            $selected = $active === $type;
            $link = new TElement('a');
            $link->{'class'} = 'cv-pending-card' . ($selected ? ' cv-pending-card--active' : '');
            // clicar no card ativo volta para todos os tipos
            $link->{'href'} = CvFormat::e(self::url($selected ? null : $type, $mine));
            $link->{'generator'} = 'adianti';
            if ($selected)
            {
                $link->{'aria-current'} = 'true';
            }
            $link->add(CvKpiCard::create($icon, $tone, (string) ($counts[$type] ?? 0), _t($label)));
            $row->add($link);
        }

        return $row;
    }

    private static function filterBar(?string $type, bool $mine): TElement
    {
        $bar = new TElement('div');
        $bar->{'class'} = 'cv-pending-filters';

        $all = new TElement('a');
        $all->{'class'} = 'btn ' . ($type === null ? 'btn-primary' : 'btn-default') . ' cv-touch-target';
        $all->{'href'} = CvFormat::e(self::url(null, $mine));
        $all->{'generator'} = 'adianti';
        $all->add(TElement::tag('span', CvFormat::e(_t('All types'))));
        $bar->add($all);

        $toggle = new TElement('a');
        $toggle->{'class'} = 'btn ' . ($mine ? 'btn-primary' : 'btn-default') . ' cv-touch-target';
        $toggle->{'href'} = CvFormat::e(self::url($type, !$mine));
        $toggle->{'generator'} = 'adianti';
        $toggle->{'aria-pressed'} = $mine ? 'true' : 'false';
        $toggle->add(new TImage('fa:user'));
        $toggle->add(TElement::tag('span', CvFormat::e(_t('Only mine'))));
        $bar->add($toggle);

        return $bar;
    }

    private static function emptyState(): TElement
    {
        $state = new TElement('div');
        $state->{'class'} = 'cv-state cv-state--empty cv-pending-empty';
        $state->{'role'} = 'status';

        $icon = new TElement('span');
        $icon->{'class'} = 'cv-state__icon';
        $icon->{'aria-hidden'} = 'true';
        $icon->add(new TImage('fa:check-circle'));
        $state->add($icon);

        $text = new TElement('div');
        $text->add(TElement::tag('p', CvFormat::e(_t('No pending items')), ['class' => 'cv-state__title']));
        $state->add($text);

        return $state;
    }

    /**
     * @param list<CentralVet\Domain\PendingItem> $items já ordenados pelo service
     * @param array<int, string> $names
     */
    private static function table(array $items, array $names, DateTimeImmutable $now): TElement
    {
        $card = new TElement('div');
        $card->{'class'} = 'cv-card cv-pending-list';
        $body = new TElement('div');
        $body->{'class'} = 'cv-card__body';
        $body->style = 'overflow-x:auto';
        $card->add($body);

        $table = new TElement('table');
        $table->{'class'} = 'table cv-table cv-pending-table';
        $table->style = 'width:100%';

        $thead = new TElement('thead');
        $head = new TElement('tr');
        foreach ([_t('Priority'), _t('Type'), _t('Patient'), _t('Subject'), _t('Due'), _t('Status'), _t('Responsible'), ''] as $title)
        {
            $head->add(TElement::tag('th', CvFormat::e($title), ['scope' => 'col']));
        }
        $thead->add($head);
        $table->add($thead);

        $tbody = new TElement('tbody');
        foreach ($items as $item)
        {
            [$priorityLabel, $tone] = self::PRIORITY_BADGE[$item->priority($now)] ?? [$item->priority($now), 'neutral'];
            $overdue = $item->status($now) === CentralVet\Domain\PendingItem::STATUS_OVERDUE;
            $responsible = $item->responsibleSystemUserId();

            $tr = new TElement('tr');
            $tr->{'class'} = 'cv-pending-row' . ($overdue ? ' cv-pending-row--overdue' : '');

            $priority = new TElement('td');
            $priority->add(CvBadge::create(_t($priorityLabel), $tone));
            $tr->add($priority);

            $tr->add(TElement::tag('td', CvFormat::e(_t(self::TYPE_META[$item->type()][0]))));
            $tr->add(TElement::tag('td', CvFormat::e($item->patientName() ?? '—')));
            $tr->add(TElement::tag('td', CvFormat::e(self::subjectLabel($item))));
            $tr->add(TElement::tag('td', CvFormat::e($item->dueAt()->format('d/m/Y H:i'))));

            $status = new TElement('td');
            $status->add(CvBadge::create($overdue ? _t('Overdue') : _t('Open (pending status)'), $overdue ? 'danger' : 'neutral'));
            $tr->add($status);

            $tr->add(TElement::tag('td', CvFormat::e($responsible !== null && isset($names[$responsible]) ? $names[$responsible] : '—')));

            $link = new TElement('a');
            $link->{'class'} = 'btn btn-sm btn-primary cv-touch-target cv-pending-resolve';
            $link->{'href'} = CvFormat::e($item->deepLinkUrl());
            $link->{'generator'} = 'adianti';
            $link->add(new TImage('fa:arrow-right'));
            $link->add(TElement::tag('span', CvFormat::e(_t('Resolve'))));

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
     * Assunto exibido: nas mensagens o subjectLabel é o código da finalidade
     * e no recebível é o código fixo `receivable_open`, então ganham rótulo
     * traduzido; nos demais tipos é um nome (exame, vacina, serviço,
     * medicamento) mostrado como está.
     */
    private static function subjectLabel(CentralVet\Domain\PendingItem $item): string
    {
        return match ($item->type())
        {
            CentralVet\Domain\PendingItem::TYPE_MESSAGE_FAILED,
            CentralVet\Domain\PendingItem::TYPE_MESSAGE_WHATSAPP_MANUAL => CommunicationMessageView::purposeLabel($item->subjectLabel()),
            CentralVet\Domain\PendingItem::TYPE_RECEIVABLE_OPEN         => _t('Open receivable'),
            default                                                     => $item->subjectLabel(),
        };
    }

    /**
     * Nomes dos usuários vinculados ao tenant (tenant_user) em uma consulta
     * (mesmo recurso de SurgeryList::userNames()).
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

    /**
     * Service sobre a PendingItemQuery memoizada: countsByType e list do
     * mesmo render leem as 8 fontes uma vez só.
     */
    private static function makeService(CentralVet\Tenancy\TenantContext $context, $connection): CentralVet\Application\PendingCenterService
    {
        $authorization = new CentralVet\Authorization\RbacAuthorizationService(
            new CentralVet\Authorization\AdiantiProgramPermissionProvider(new CentralVet\Tenancy\AdiantiSessionContextSource()),
            new CentralVet\Audit\PdoAuditLogWriter($connection),
        );

        $query = new class(new CentralVet\Persistence\PendingItemQuery($context, $connection)) implements CentralVet\Domain\Contract\PendingItemQueryInterface
        {
            private ?array $cache = null;

            public function __construct(private readonly CentralVet\Domain\Contract\PendingItemQueryInterface $inner)
            {
            }

            public function listForUnit(int $systemUnitId, DateTimeImmutable $now, int $limitPerType): array
            {
                return $this->cache ??= $this->inner->listForUnit($systemUnitId, $now, $limitPerType);
            }
        };

        // relógio fixo por render: prioridade e status coerentes com a consulta
        $now = new DateTimeImmutable();

        return new CentralVet\Application\PendingCenterService($query, $authorization, $context, static fn (): DateTimeImmutable => $now);
    }

    /**
     * Resolves the tenant context of the authenticated session (mesmo padrão
     * de SurgeryList::resolveTenantContext()).
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
