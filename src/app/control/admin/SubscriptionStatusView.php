<?php
/**
 * SubscriptionStatusView
 *
 * Tela da assinatura da clínica (Etapa 3, T-21): plano, estado, período
 * atual, tolerância e as últimas faturas com o link "Pagar fatura" (abre o
 * Stripe em outra aba). Só leitura: nenhuma ação muda dado, por isso fica
 * fora do inventário CSRF. Fica em SubscriptionAccessGuard::ALWAYS_ALLOWED
 * para a clínica bloqueada ainda conseguir regularizar.
 *
 * Etapa 5, T-14: mostra o cancelamento agendado ("Cancelamento agendado
 * para <fim do período>") e, quando SubscriptionStatusPresenter::canCancel
 * permite, o botão "Cancelar assinatura", que abre SubscriptionCancelForm
 * (a confirmação, com POST e CSRF, mora lá).
 *
 * Os dados vêm do tenant da sessão (SubscriptionRepository::findByTenantId)
 * e são montados por CentralVet\Presentation\SubscriptionStatusPresenter.
 *
 * @version    8.6
 * @package    control
 * @subpackage admin
 * @license    https://adiantiframework.com.br/license-template
 */
class SubscriptionStatusView extends TPage
{
    private const INVOICE_LIMIT = 12;

    /** status_key → tom do CvBadge. */
    private const TONES = [
        'Active'                => 'success',
        'Overdue'               => 'warning',
        'Suspended'             => 'danger',
        'Cancelled'             => 'neutral',
        'Incomplete'            => 'neutral',
        'Draft'                 => 'neutral',
        'Open (pending status)' => 'info',
        'Paid'                  => 'success',
        'Canceled'              => 'neutral',
        'Refunded'              => 'neutral',
    ];

    public function __construct($param = null)
    {
        parent::__construct();

        $container = new TVBox;
        $container->style = 'width: 100%';
        $container->add(CvPage::header(_t('Subscription'), null, [], false));

        try
        {
            $tenantId = self::resolveTenantId();
            $now = new DateTimeImmutable();

            TTransaction::open('permission');
            $connection = TTransaction::get();

            $subscription = (new CentralVet\Persistence\SubscriptionRepository($connection))->findByTenantId($tenantId);
            $invoices = $subscription !== null && $subscription->id() !== null
                ? (new CentralVet\Persistence\SubscriptionInvoiceRepository($connection))->listBySubscription($subscription->id(), self::INVOICE_LIMIT)
                : [];

            TTransaction::close();

            $summary = CentralVet\Presentation\SubscriptionStatusPresenter::summary($subscription, $now);
            $cancelScheduledFor = CentralVet\Presentation\SubscriptionStatusPresenter::cancelScheduledFor($subscription);
            $canCancel = CentralVet\Presentation\SubscriptionStatusPresenter::canCancel($subscription);
            $rows = CentralVet\Presentation\SubscriptionStatusPresenter::invoiceRows($invoices, $now);
        }
        catch (CentralVet\Tenancy\Exception\MissingTenantContext $e)
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

        $container->add(self::summaryCard($summary, $cancelScheduledFor, $canCancel));
        if ($summary['plan'] !== null)
        {
            $container->add(self::invoicesCard($rows));
        }

        parent::add($container);
    }

    /**
     * @param array{plan: ?string, status_key: ?string, period: ?string, grace_until: ?string, message_key: ?string} $summary
     */
    private static function summaryCard(array $summary, ?string $cancelScheduledFor, bool $canCancel): TElement
    {
        $card = self::card(_t('Subscription'));
        $body = $card->get(1);

        if ($summary['message_key'] !== null)
        {
            $alert = new TElement('div');
            $alert->{'class'} = 'alert ' . ($summary['plan'] === null ? 'alert-info' : 'alert-danger');
            $alert->{'role'} = 'status';
            $alert->add(CvFormat::e(_t($summary['message_key'])));
            $body->add($alert);
        }

        if ($summary['plan'] === null)
        {
            return $card;
        }

        $table = new TElement('table');
        $table->{'class'} = 'table cv-table cv-subscription-summary';
        $table->style = 'width:100%';

        $status = new TElement('td');
        $status->add(CvBadge::create(_t((string) $summary['status_key']), self::TONES[$summary['status_key']] ?? 'neutral'));

        $lines = [
            [_t('Plan'), TElement::tag('td', CvFormat::e($summary['plan']))],
            [_t('Status'), $status],
            [_t('Current period'), TElement::tag('td', CvFormat::e($summary['period'] ?? '—'))],
        ];
        if ($summary['grace_until'] !== null)
        {
            $lines[] = [_t('Grace period until'), TElement::tag('td', CvFormat::e($summary['grace_until']))];
        }

        $tbody = new TElement('tbody');
        foreach ($lines as [$label, $cell])
        {
            $tr = new TElement('tr');
            $tr->add(TElement::tag('th', CvFormat::e($label), ['scope' => 'row', 'style' => 'text-align:left;width:220px']));
            $tr->add($cell);
            $tbody->add($tr);
        }
        $table->add($tbody);
        $body->add($table);

        if ($cancelScheduledFor !== null)
        {
            $scheduled = new TElement('div');
            $scheduled->{'class'} = 'alert alert-warning';
            $scheduled->{'role'} = 'status';
            $scheduled->add(CvFormat::e(_t('Cancellation scheduled for ^1', $cancelScheduledFor)));
            $body->add($scheduled);
        }

        if ($canCancel)
        {
            $link = new TElement('a');
            $link->{'class'} = 'btn btn-outline-danger cv-touch-target';
            $link->{'href'} = 'index.php?class=SubscriptionCancelForm';
            $link->{'generator'} = 'adianti';
            $link->add(new TImage('fa:ban'));
            $link->add(TElement::tag('span', CvFormat::e(_t('Cancel subscription'))));
            $body->add($link);
        }

        return $card;
    }

    /**
     * @param list<array{period: ?string, due_at: ?string, amount_due: string, amount_paid: string, status_key: string, pay_url: ?string}> $rows
     */
    private static function invoicesCard(array $rows): TElement
    {
        $card = self::card(_t('Invoices'));
        $body = $card->get(1);
        $body->style = 'overflow-x:auto';

        if ($rows === [])
        {
            $body->add(TElement::tag('p', CvFormat::e(_t('Nothing here')), ['class' => 'cv-state__title']));
            return $card;
        }

        $table = new TElement('table');
        $table->{'class'} = 'table cv-table cv-subscription-invoices';
        $table->style = 'width:100%';

        $thead = new TElement('thead');
        $head = new TElement('tr');
        foreach ([_t('Period'), _t('Due date'), _t('Amount due'), _t('Amount paid'), _t('Status'), ''] as $title)
        {
            $head->add(TElement::tag('th', CvFormat::e($title), ['scope' => 'col', 'style' => 'text-align:left']));
        }
        $thead->add($head);
        $table->add($thead);

        $tbody = new TElement('tbody');
        foreach ($rows as $row)
        {
            $tr = new TElement('tr');
            $tr->add(TElement::tag('td', CvFormat::e($row['period'] ?? '—')));
            $tr->add(TElement::tag('td', CvFormat::e($row['due_at'] ?? '—')));
            $tr->add(TElement::tag('td', CvFormat::e($row['amount_due'])));
            $tr->add(TElement::tag('td', CvFormat::e($row['amount_paid'])));

            $status = new TElement('td');
            $status->add(CvBadge::create(_t($row['status_key']), self::TONES[$row['status_key']] ?? 'neutral'));
            $tr->add($status);

            $action = new TElement('td');
            if ($row['pay_url'] !== null)
            {
                $link = new TElement('a');
                $link->{'class'} = 'btn btn-sm btn-primary cv-touch-target';
                $link->{'href'} = CvFormat::e($row['pay_url']);
                $link->{'target'} = '_blank';
                $link->{'rel'} = 'noopener';
                $link->add(new TImage('fa:external-link-alt'));
                $link->add(TElement::tag('span', CvFormat::e(_t('Pay invoice'))));
                $action->add($link);
            }
            $tr->add($action);

            $tbody->add($tr);
        }
        $table->add($tbody);
        $body->add($table);

        return $card;
    }

    /** Card com cabeçalho (filho 0) e corpo (filho 1). */
    private static function card(string $title): TElement
    {
        $card = new TElement('div');
        $card->{'class'} = 'cv-card';

        $header = new TElement('div');
        $header->{'class'} = 'cv-card__header';
        $header->add(TElement::tag('h2', CvFormat::e($title), ['class' => 'cv-card__title']));
        $card->add($header);

        $body = new TElement('div');
        $body->{'class'} = 'cv-card__body';
        $card->add($body);

        return $card;
    }

    /**
     * Tenant da sessão autenticada (mesmo padrão de PendingCenter::resolveTenantContext()).
     *
     * @throws CentralVet\Tenancy\Exception\MissingTenantContext
     */
    private static function resolveTenantId(): int
    {
        try
        {
            return CentralVet\Tenancy\TenantContext::fromAuthenticatedSession(new CentralVet\Tenancy\AdiantiSessionContextSource())->tenantId();
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
            $tenantId = $stmt->fetchColumn();
            TTransaction::close();

            if (empty($tenantId))
            {
                throw $e;
            }

            return (int) $tenantId;
        }
    }
}
