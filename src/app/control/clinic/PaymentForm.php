<?php
/**
 * PaymentForm
 *
 * Tela de registrar pagamento (T-10), consumindo exclusivamente
 * CentralVet\Application\PaymentService::register() (T-06) — nenhuma regra
 * de negocio propria: overpayment, status da CashSession e o incremento de
 * Receivable::paidCents()/status/o FinancialEntry gerado sao inteiramente
 * responsabilidade do Application service, mesmo padrao de
 * EncounterAccountForm.php (T-07)/PayableForm.php (T-09) para "recebe
 * identificador via querystring + delega tudo ao service".
 *
 * Recebe receivable_id via $_GET (com fallback para $param), navegada a
 * partir de EncounterAccountForm::onClose() depois de fechar a conta
 * (EncounterAccountForm mostra o total do Receivable gerado; e o
 * receivable_id dele que esta tela consome).
 *
 * Sessao de caixa: exige uma cash_session aberta da unidade ativa,
 * localizada via CashSessionRepositoryInterface::findOpenBySystemUnit()
 * (T-02) — mesma tecnica de leitura direta ja usada por
 * CashSessionForm::resolveOpenSession() (T-08) e por
 * EncounterAccountForm::loadItems() (T-07) para leituras que nao tem
 * cobertura de um metodo de Application service proprio. Sem sessao aberta,
 * a tela mostra um TAlert de aviso e NAO renderiza o formulario/botao de
 * registrar pagamento (criterio de aceite: o botao fica indisponivel e
 * PaymentService::register() nunca e chamado nesse caso) — a mesma checagem
 * e refeita no inicio de onSave() como defesa em profundidade contra uma
 * sessao que tenha fechado entre o carregamento da tela e o submit.
 *
 * Saldo devido mostrado como Receivable::totalCents() - Receivable::
 * paidCents() (leitura via ReceivableRepositoryInterface::findById(),
 * mesmo padrao de leitura direta acima). Pagamento bem-sucedido recarrega a
 * mesma tela (mesmo receivable_id), que entao mostra o saldo devido
 * recalculado a partir do Receivable ja atualizado por
 * PaymentService::register() (criterio de aceite). OverpaymentException
 * (T-06) e capturada e mostrada como TMessage de erro, sem navegar
 * (criterio de aceite) — a mesma tela permanece com o formulario de
 * pagamento disponivel para uma nova tentativa com um valor menor.
 *
 * Sem model Adianti proprio: como EncounterAccountForm/ProcedureExecutionForm,
 * esta tela consome apenas os objetos de Domain devolvidos pelo Application
 * service (Receivable/Payment/CashSession), nunca um TRecord.
 *
 * As tabelas usadas aqui vem da migration
 * src/app/database/migrations/20260925_0006_phase5_financial.sql, ja
 * aplicada (Fase 5).
 *
 * @version    1.0
 * @package    control
 * @subpackage clinic
 */
class PaymentForm extends TPage
{
    protected $form;
    private ?int $receivableId;
    private ?\CentralVet\Domain\Receivable $receivable = null;

    /**
     * Page constructor.
     */
    public function __construct($param = null)
    {
        parent::__construct();

        $this->receivableId = self::paramInt('receivable_id', $param);

        // page container (design system: .cv-page, T-09)
        // No TXMLBreadCrumb here: PaymentForm is never itself a menu.xml
        // entry (fase 09 replaced its old bare entry with
        // PendingReceivableList's) — reached only contextually, from
        // EncounterAccountForm::onClose()'s link and PendingReceivableList's
        // row action, both of which pass `receivable_id`. TXMLBreadCrumb
        // throws when the target class is not listed in menu.xml, which
        // made every real navigation to this screen fatal after that
        // change; same precedent already documented in PayableList's own
        // constructor for a class not (yet) listed there.
        $container = new TElement('div');
        $container->class = 'cv-page';

        // cabeçalho do kit Cv* com voltar para as contas a receber
        $container->add(CvPage::header(_t('Register payment'), _t('Financial'), [
            ['icon' => 'fa:arrow-left', 'href' => 'index.php?class=PendingReceivableList'],
        ]));
        $container->add(CvNav::tabs('finance', 'receivables'));

        if ($this->receivableId === null)
        {
            $container->add($this->emptyStatePanel());
            parent::add($container);
            return;
        }

        $load = $this->loadState();

        if ($load === null)
        {
            // A refusal (not found / cross-tenant / missing context) was
            // already reported as a TMessage inside loadState().
            $container->add($this->emptyStatePanel());
            parent::add($container);
            return;
        }

        [$receivable, $open_session] = $load;
        $this->receivable = $receivable;

        // two-column layout (design system tokens, T-09): balance due
        // summary on the left, payment action on the right
        $row = new TElement('div');
        $row->class = 'row g-4';

        $summary_col = new TElement('div');
        $summary_col->class = 'col-lg-5';
        $summary_col->add($this->buildSummaryPanel($receivable));
        $row->add($summary_col);

        $action_col = new TElement('div');
        $action_col->class = 'col-lg-7';

        if ($open_session === null)
        {
            $alert = new TAlert('warning', _t('There is no open cash session for this unit. Open a cash session before registering a payment.'));
            $action_col->add($alert);
        }
        elseif ($receivable->status() === \CentralVet\Domain\Receivable::STATUS_PAID)
        {
            $action_col->add(new TAlert('info', _t('This receivable is already fully paid.')));
        }
        else
        {
            $action_col->add($this->buildPaymentForm($receivable, $open_session));
        }

        $row->add($action_col);
        $container->add($row);

        parent::add($container);
    }

    /**
     * Badge de status do recebível: Aberto / Parcial / Pago.
     */
    private static function statusBadge(string $status): TElement
    {
        if ($status === \CentralVet\Domain\Receivable::STATUS_PAID)
        {
            return CvBadge::create(_t('Paid'), 'success');
        }
        if ($status === \CentralVet\Domain\Receivable::STATUS_PARTIALLY_PAID)
        {
            return CvBadge::create(_t('Partially paid'), 'warning');
        }

        return CvBadge::create(_t('Open (status)'), 'info');
    }

    private function emptyStatePanel(): TPanelGroup
    {
        $panel = new TPanelGroup(_t('Register payment'));
        $panel->add('<p>' . _t('Provide a valid receivable_id to register a payment.') . '</p>');

        return $panel;
    }

    /**
     * Loads the Receivable and the active unit's open CashSession (if any)
     * in a single read-only transaction. Any refusal is caught and shown as
     * a TMessage; returns null instead of throwing, matching
     * EncounterAccountForm's "never a fatal error" convention.
     *
     * @return array{0: \CentralVet\Domain\Receivable, 1: object|null}|null
     */
    private function loadState(): ?array
    {
        try
        {
            $context = self::resolveTenantContext();

            TTransaction::open('permission');

            $connection = TTransaction::get();

            $receivables = new \CentralVet\Persistence\ReceivableRepository($context, $connection);
            /** @var \CentralVet\Domain\Receivable|null $receivable */
            $receivable = $receivables->findById($this->receivableId);

            if ($receivable === null)
            {
                TTransaction::close();
                new TMessage('error', _t('Receivable not found'));

                return null;
            }

            $cashSessions = new \CentralVet\Persistence\CashSessionRepository($context, $connection);
            $open_session = $cashSessions->findOpenBySystemUnit($context->requireUnitId());

            TTransaction::close();

            return [$receivable, $open_session];
        }
        catch (\CentralVet\Tenancy\Exception\MissingTenantContext $e)
        {
            TTransaction::rollback();
            new TMessage('error', _t('An authenticated session with an active unit is required'));
        }
        catch (Exception $e)
        {
            TTransaction::rollback();
            new TMessage('error', $e->getMessage());
        }

        return null;
    }

    private function buildSummaryPanel(\CentralVet\Domain\Receivable $receivable): TPanelGroup
    {
        $panel = new TPanelGroup(_t('Receivable') . ' #' . $receivable->id());

        $balanceDueCents = $receivable->totalCents() - $receivable->paidCents();

        $status = new TElement('p');
        $status->add(CvFormat::e(_t('Status')) . ': ');
        $status->add(self::statusBadge($receivable->status()));
        $panel->add($status);

        $lines = [];
        $lines[] = CvFormat::e(_t('Total') . ': ' . CvFormat::money($receivable->totalCents()));
        $lines[] = CvFormat::e(_t('Paid') . ': ' . CvFormat::money($receivable->paidCents()));

        $panel->add('<p>' . implode('<br>', $lines) . '</p>');

        // balance due highlight (design system tokens, T-09)
        $balance_box = new TElement('div');
        $balance_box->style = 'background: var(--cv-color-primary-soft); '
            . 'border-radius: var(--cv-radius-md); '
            . 'padding: var(--cv-space-4); '
            . 'margin-top: var(--cv-space-3);';

        $balance_label = new TElement('p');
        $balance_label->style = 'margin: 0; text-transform: uppercase; font-size: .75rem; '
            . 'letter-spacing: .05em; color: var(--cv-color-text-muted);';
        $balance_label->add(_t('Balance due'));

        $balance_value = new TElement('p');
        $balance_value->style = 'margin: 0; font-size: 1.75rem; font-weight: 600; color: var(--cv-color-primary);';
        $balance_value->add(CvFormat::e(CvFormat::money($balanceDueCents)));

        $balance_box->add($balance_label);
        $balance_box->add($balance_value);
        $panel->add($balance_box);

        return $panel;
    }

    /**
     * Payment form, only rendered when there IS an open cash session for
     * the active unit and the receivable is not yet fully paid (criterio de
     * aceite: sem sessao aberta o botao de registrar fica indisponivel e
     * PaymentService::register() nunca e chamado).
     */
    private function buildPaymentForm(\CentralVet\Domain\Receivable $receivable, object $open_session): TPanelGroup
    {
        $panel = new TPanelGroup(_t('Register payment'));

        $this->form = new BootstrapFormBuilder('form_Payment');
        $this->form->enableClientValidation();
        CvForm::decorate($this->form, 2);

        $receivable_id = new THidden('receivable_id');
        $receivable_id->setValue($receivable->id());
        $this->form->add($receivable_id);

        $payment_method = new TCombo('payment_method');
        $payment_method->addItems([
            \CentralVet\Domain\Payment::METHOD_CASH => CvFormat::paymentMethod(\CentralVet\Domain\Payment::METHOD_CASH),
            \CentralVet\Domain\Payment::METHOD_DEBIT_CARD => CvFormat::paymentMethod(\CentralVet\Domain\Payment::METHOD_DEBIT_CARD),
            \CentralVet\Domain\Payment::METHOD_CREDIT_CARD => CvFormat::paymentMethod(\CentralVet\Domain\Payment::METHOD_CREDIT_CARD),
            \CentralVet\Domain\Payment::METHOD_PIX => CvFormat::paymentMethod(\CentralVet\Domain\Payment::METHOD_PIX),
            \CentralVet\Domain\Payment::METHOD_BANK_TRANSFER => CvFormat::paymentMethod(\CentralVet\Domain\Payment::METHOD_BANK_TRANSFER),
        ]);
        $payment_method->setSize('100%');
        $payment_method->addValidation(_t('Payment method'), new TRequiredValidator);

        $amount_cents = new TEntry('amount_cents');
        $amount_cents->setNumericMask(2, ',', '.', false);
        $amount_cents->setSize('100%');
        $amount_cents->addValidation(_t('Amount'), new TRequiredValidator);

        $this->form->addFields([new TLabel(_t('Payment method'))], [$payment_method], [new TLabel(_t('Amount (R$)'))], [$amount_cents]);

        $btn = $this->form->addAction(_t('Register payment'), new TAction([$this, 'onSave']), 'fa:money-bill');
        $btn->class = 'btn btn-primary';

        $panel->add($this->form);

        return $panel;
    }

    /**
     * method onSave()
     * Registers the payment through PaymentService::register(). Re-resolves
     * the active unit's open cash session here too (defense in depth
     * against the session having closed between page load and submit — the
     * constructor already gates rendering the form/button on there being an
     * open session, but this repeats the check server-side before ever
     * calling the service), then delegates the actual write entirely to
     * PaymentService: overpayment/closed-session refusals are surfaced as
     * TMessage without navigating (criterio de aceite), a successful
     * payment reloads this same screen so the summary panel shows the
     * Receivable's now-updated balance due (criterio de aceite).
     */
    public function onSave($param)
    {
        try
        {
            $receivableId = isset($param['receivable_id']) ? (int) $param['receivable_id'] : 0;
            $paymentMethod = isset($param['payment_method']) ? (string) $param['payment_method'] : '';
            $amountCents = \CentralVet\Presentation\MoneyInput::toCents((string) ($param['amount_cents'] ?? null), false, \CentralVet\Presentation\MoneyInput::MAX_UNSIGNED_INT_CENTS);

            if ($receivableId <= 0)
            {
                throw new InvalidArgumentException(_t('Invalid receivable'));
            }

            $context = self::resolveTenantContext();

            TTransaction::open('permission');

            $connection = TTransaction::get();
            $cashSessions = new \CentralVet\Persistence\CashSessionRepository($context, $connection);
            $open_session = $cashSessions->findOpenBySystemUnit($context->requireUnitId());

            if ($open_session === null)
            {
                TTransaction::close();
                new TMessage('error', _t('There is no open cash session for this unit. Open a cash session before registering a payment.'));

                return;
            }

            $service = self::buildPaymentService($context, $connection);

            $service->register(
                $receivableId,
                (int) $open_session->id(),
                $paymentMethod,
                $amountCents,
                $context->userId(),
                'PaymentForm::onSave'
            );

            TTransaction::close();

            new TMessage('info', _t('Payment registered successfully'));
            $this->reloadSelf($receivableId);
        }
        catch (\CentralVet\Domain\Exception\OverpaymentException $e)
        {
            TTransaction::rollback();
            new TMessage('error', $e->getMessage());
        }
        catch (\CentralVet\Domain\Exception\InvalidStatusTransitionException $e)
        {
            TTransaction::rollback();
            new TMessage('error', $e->getMessage());
        }
        catch (\CentralVet\Authorization\Exception\AuthorizationDenied $e)
        {
            TTransaction::rollback();
            new TMessage('error', _t('You are not allowed to register a payment for this unit'));
        }
        catch (\CentralVet\Tenancy\Exception\TenantBoundaryViolation $e)
        {
            TTransaction::rollback();
            new TMessage('error', _t('You are not allowed to perform this action'));
        }
        catch (\CentralVet\Tenancy\Exception\MissingTenantContext $e)
        {
            TTransaction::rollback();
            new TMessage('error', _t('An authenticated session with an active unit is required'));
        }
        catch (InvalidArgumentException $e)
        {
            TTransaction::rollback();
            new TMessage('error', CvFormat::userError($e));
        }
        catch (Exception $e) // catch-all: never let a fatal error reach the screen
        {
            TTransaction::rollback();
            new TMessage('error', $e->getMessage());
        }
    }

    /**
     * Reloads this same screen (same receivable_id), forcing the
     * constructor to re-run and re-read the (now updated) Receivable —
     * mirrors EncounterAccountForm::reloadSelf().
     */
    private function reloadSelf(int $receivableId): void
    {
        TScript::create(
            "__adianti_goto_page('index.php?class=PaymentForm&receivable_id={$receivableId}')"
        );
    }

    private static function paramInt(string $name, $param): ?int
    {
        if (isset($_GET[$name]) && $_GET[$name] !== '')
        {
            return (int) $_GET[$name];
        }

        if (is_array($param) && isset($param[$name]) && $param[$name] !== '')
        {
            return (int) $param[$name];
        }

        return null;
    }

    /**
     * Wires CentralVet\Application\PaymentService (T-06) from its
     * Persistence/PDO implementations, mirroring PayableForm::
     * buildPayableService()/EncounterAccountForm::makeEncounterAccountService():
     * the real RbacAuthorizationService (Fase 0), backed by
     * AdiantiProgramPermissionProvider and PdoAuditLogWriter against this
     * same 'permission' connection, so every register() call is both
     * unit-scope-checked (against the CashSession's own real
     * system_unit_id, per PaymentService's own docblock) and audited to
     * `audit_log`. The same authorization instance is reused to build the
     * FinancialEntryService dependency PaymentService::register() needs
     * internally. Requires an already-open TTransaction('permission')
     * connection.
     */
    private static function buildPaymentService(
        \CentralVet\Tenancy\TenantContext $context,
        $connection
    ): \CentralVet\Application\PaymentService {
        $payments = new \CentralVet\Persistence\PaymentRepository($context, $connection);
        $receivables = new \CentralVet\Persistence\ReceivableRepository($context, $connection);
        $cashSessions = new \CentralVet\Persistence\CashSessionRepository($context, $connection);
        $entries = new \CentralVet\Persistence\FinancialEntryRepository($context, $connection);

        $authorization = new \CentralVet\Authorization\RbacAuthorizationService(
            new \CentralVet\Authorization\AdiantiProgramPermissionProvider(new \CentralVet\Tenancy\AdiantiSessionContextSource()),
            new \CentralVet\Audit\PdoAuditLogWriter($connection),
        );

        $financial_entries = new \CentralVet\Application\FinancialEntryService($entries, $authorization, $context);

        return new \CentralVet\Application\PaymentService(
            $payments,
            $receivables,
            $cashSessions,
            $financial_entries,
            $authorization,
            $context,
        );
    }

    /**
     * Resolves the tenant context of the authenticated session. Copied
     * verbatim from EncounterAccountForm::resolveTenantContext()/
     * PayableForm::resolveTenantContext() (same fallback for legacy
     * sessions where TSession does not carry 'tenantid' yet).
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
