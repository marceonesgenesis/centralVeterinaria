<?php
/**
 * CommunicationMessageList
 *
 * Histórico de mensagens da unidade ativa (Fase 7A, T-17): filtros por
 * POST (status, canal, finalidade), colunas data, tutor, finalidade, canal,
 * status (CvBadge) e destinatário mascarado (e-mail `ab***@dominio`,
 * telefone só com os 4 últimos dígitos), com link para a ficha
 * (`CommunicationMessageView&id=`). O destinatário completo só aparece na
 * ficha. Leitura por MessageService::listForUnit (até 200, mais novas
 * primeiro); nomes de tutor em lote, filtrados pelo tenant.
 *
 * @version    1.0
 * @package    control
 * @subpackage clinic
 */
class CommunicationMessageList extends TPage
{
    private const ACTION_RELOAD = 'CommunicationMessageList::onReload';
    private const FORM_NAME = 'form_CommunicationMessageList_filter';
    private const FILTERS = ['status', 'channel', 'purpose'];

    private bool $loaded = false;

    public function __construct($param = null)
    {
        parent::__construct();

        // com method, o dispatcher chama onReload(), que já carrega
        if (empty($param['method']))
        {
            $this->onReload();
        }
    }

    /**
     * Recarrega o histórico com os filtros do corpo do POST (valores fora
     * das listas fechadas são ignorados; a query string nunca filtra).
     */
    public function onReload($param = null)
    {
        if ($this->loaded)
        {
            return;
        }
        $this->loaded = true;

        $filters = self::postedFilters();

        $container = new TVBox;
        $container->style = 'width: 100%';
        $container->add(CvPage::header(_t('Message history')));
        $container->add($this->filter($filters));

        try
        {
            $context = self::resolveTenantContext();

            TTransaction::open('permission');
            $connection = TTransaction::get();

            $messages = self::makeMessageService($context)->listForUnit($filters, self::ACTION_RELOAD);
            $tutors = self::tutorNames($context, $connection, array_map(static fn ($m) => $m->tutorId(), $messages));

            TTransaction::close();
        }
        catch (\CentralVet\Authorization\Exception\AuthorizationDenied $e)
        {
            TTransaction::rollback();
            new TMessage('error', _t('You are not allowed to view the message history'));
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
            error_log(__METHOD__ . ': ' . $e->getMessage());
            new TMessage('error', CvFormat::userError($e));
            parent::add($container);
            return;
        }

        $container->add($messages === [] ? self::emptyState() : self::table($messages, $tutors));

        parent::add($container);
    }

    /**
     * Destinatário mascarado para a lista: e-mail com os 2 primeiros
     * caracteres do usuário e o domínio; telefone só com os 4 últimos
     * dígitos.
     */
    public static function maskRecipient(string $recipient, string $channel): string
    {
        if ($channel === \CentralVet\Domain\CommunicationChannel::EMAIL)
        {
            $at = strrpos($recipient, '@');
            if ($at === false)
            {
                return '***';
            }

            return mb_substr(substr($recipient, 0, $at), 0, 2) . '***' . substr($recipient, $at);
        }

        $digits = preg_replace('/\D+/', '', $recipient) ?? '';

        return '***' . substr($digits, -4);
    }

    /**
     * @return array<string, string>
     */
    private static function postedFilters(): array
    {
        $allowed = [
            'status' => [
                \CentralVet\Domain\OutboundMessage::STATUS_QUEUED,
                \CentralVet\Domain\OutboundMessage::STATUS_SENT,
                \CentralVet\Domain\OutboundMessage::STATUS_FAILED,
                \CentralVet\Domain\OutboundMessage::STATUS_MANUAL,
                \CentralVet\Domain\OutboundMessage::STATUS_CANCELLED,
            ],
            'channel' => [\CentralVet\Domain\CommunicationChannel::EMAIL, \CentralVet\Domain\CommunicationChannel::WHATSAPP],
            'purpose' => \CentralVet\Domain\MessagePurpose::all(),
        ];

        $filters = [];
        foreach (self::FILTERS as $key)
        {
            $value = (string) ($_POST[$key] ?? '');
            if ($value !== '' && in_array($value, $allowed[$key], true))
            {
                $filters[$key] = $value;
            }
        }

        return $filters;
    }

    /**
     * @param array<string, string> $filters
     */
    private function filter(array $filters): TForm
    {
        $form = new TForm(self::FORM_NAME);

        $status = new TCombo('status');
        $status->addItems([
            \CentralVet\Domain\OutboundMessage::STATUS_QUEUED    => CommunicationMessageView::statusLabel(\CentralVet\Domain\OutboundMessage::STATUS_QUEUED),
            \CentralVet\Domain\OutboundMessage::STATUS_SENT      => CommunicationMessageView::statusLabel(\CentralVet\Domain\OutboundMessage::STATUS_SENT),
            \CentralVet\Domain\OutboundMessage::STATUS_MANUAL    => CommunicationMessageView::statusLabel(\CentralVet\Domain\OutboundMessage::STATUS_MANUAL),
            \CentralVet\Domain\OutboundMessage::STATUS_FAILED    => CommunicationMessageView::statusLabel(\CentralVet\Domain\OutboundMessage::STATUS_FAILED),
            \CentralVet\Domain\OutboundMessage::STATUS_CANCELLED => CommunicationMessageView::statusLabel(\CentralVet\Domain\OutboundMessage::STATUS_CANCELLED),
        ]);

        $channel = new TCombo('channel');
        $channel->addItems([
            \CentralVet\Domain\CommunicationChannel::EMAIL    => CommunicationMessageView::channelLabel(\CentralVet\Domain\CommunicationChannel::EMAIL),
            \CentralVet\Domain\CommunicationChannel::WHATSAPP => CommunicationMessageView::channelLabel(\CentralVet\Domain\CommunicationChannel::WHATSAPP),
        ]);

        $purposes = [];
        foreach (\CentralVet\Domain\MessagePurpose::all() as $purpose)
        {
            $purposes[$purpose] = CommunicationMessageView::purposeLabel($purpose);
        }
        $purpose = new TCombo('purpose');
        $purpose->addItems($purposes);

        foreach (['status' => $status, 'channel' => $channel, 'purpose' => $purpose] as $key => $field)
        {
            $field->setSize('100%');
            $field->setValue($filters[$key] ?? '');
        }

        $button = new TButton('filter');
        $button->setAction(new TAction([$this, 'onReload']), _t('Apply'));
        $button->setImage('fa:filter');
        $button->class = 'btn btn-primary cv-touch-target';

        $form->setFields([$status, $channel, $purpose, $button]);

        $boxes = [];
        foreach ([_t('Status') => $status, _t('Channel') => $channel, _t('Purpose') => $purpose] as $label => $field)
        {
            $box = new TElement('div');
            $box->add(new TLabel($label));
            $box->add($field);
            $boxes[] = $box;
        }
        $boxes[] = $button;

        $form->add(CvPage::filterBar($boxes));

        return $form;
    }

    private static function emptyState(): TElement
    {
        $state = new TElement('div');
        $state->{'class'} = 'cv-state cv-state--empty';
        $state->{'role'} = 'status';

        $icon = new TElement('span');
        $icon->{'class'} = 'cv-state__icon';
        $icon->{'aria-hidden'} = 'true';
        $icon->add(new TImage('fa:envelope'));
        $state->add($icon);

        $text = new TElement('div');
        $text->add(TElement::tag('p', CvFormat::e(_t('No messages found')), ['class' => 'cv-state__title']));
        $text->add(TElement::tag('p', CvFormat::e(_t('Messages sent to tutors appear here.')), ['class' => 'cv-state__message']));
        $state->add($text);

        return $state;
    }

    /**
     * @param list<\CentralVet\Domain\OutboundMessage> $messages
     * @param array<int, string> $tutors
     */
    private static function table(array $messages, array $tutors): TElement
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
        foreach ([_t('Date'), _t('Tutor'), _t('Purpose'), _t('Channel'), _t('Status'), _t('Recipient'), ''] as $title)
        {
            $head->add(TElement::tag('th', CvFormat::e($title), ['scope' => 'col']));
        }
        $thead->add($head);
        $table->add($thead);

        $tbody = new TElement('tbody');
        foreach ($messages as $message)
        {
            $id = (int) $message->id();
            $createdAt = $message->createdAt();

            $tr = new TElement('tr');
            $tr->add(TElement::tag('td', CvFormat::e($createdAt !== null ? $createdAt->format('d/m/Y H:i') : '—')));
            $tr->add(TElement::tag('td', CvFormat::e($tutors[$message->tutorId()] ?? _t('Tutor') . ' #' . $message->tutorId())));
            $tr->add(TElement::tag('td', CvFormat::e(CommunicationMessageView::purposeLabel($message->purpose()))));
            $tr->add(TElement::tag('td', CvFormat::e(CommunicationMessageView::channelLabel($message->channel()))));

            $status = new TElement('td');
            $status->add(CommunicationMessageView::statusBadge($message->status()));
            $tr->add($status);

            $tr->add(TElement::tag('td', CvFormat::e(self::maskRecipient($message->recipient(), $message->channel()))));

            $link = new TElement('a');
            $link->{'class'} = 'btn btn-sm btn-outline-secondary cv-touch-target';
            $link->{'href'} = 'index.php?class=CommunicationMessageView&id=' . $id;
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
     * Nomes dos tutores em lote (uma consulta), filtrados pelo tenant.
     *
     * @param list<int> $ids
     * @return array<int, string>
     */
    private static function tutorNames(\CentralVet\Tenancy\TenantContext $context, PDO $connection, array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, static fn ($id) => $id > 0)));
        if ($ids === [])
        {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $statement = $connection->prepare("SELECT id, full_name FROM tutor WHERE tenant_id = ? AND id IN ({$placeholders})");
        $statement->execute(array_merge([$context->tenantId()], $ids));

        $names = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row)
        {
            $names[(int) $row['id']] = (string) $row['full_name'];
        }

        return $names;
    }

    private static function makeMessageService(\CentralVet\Tenancy\TenantContext $context): \CentralVet\Application\MessageService
    {
        $connection = TTransaction::get();

        return new \CentralVet\Application\MessageService(
            new \CentralVet\Persistence\OutboundMessageRepository($context, $connection),
            new \CentralVet\Persistence\MessageTemplateRepository($context, $connection),
            new \CentralVet\Persistence\CommunicationPreferenceRepository($context, $connection),
            new \CentralVet\Persistence\TutorRepository($context, $connection),
            new \CentralVet\Persistence\PatientRepository($context, $connection),
            new \CentralVet\Authorization\RbacAuthorizationService(
                new \CentralVet\Authorization\AdiantiProgramPermissionProvider(new \CentralVet\Tenancy\AdiantiSessionContextSource()),
                new \CentralVet\Audit\PdoAuditLogWriter($connection),
            ),
            $context,
        );
    }

    /**
     * Contexto de tenant da sessão autenticada, com o mesmo fallback de
     * SurgeryList::resolveTenantContext() para sessões legadas.
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
