<?php
/**
 * EncounterAccountForm
 *
 * Tela de conta do atendimento (T-07), consumindo exclusivamente
 * CentralVet\Application\EncounterAccountService (T-03):
 * openOrGet()/syncAutomaticItems()/addManualItem()/applyDiscount()/close().
 * Nenhuma regra de negocio propria: bookkeeping de subtotal/desconto/total,
 * idempotencia dos itens automaticos e autorizacao por unidade sao
 * inteiramente responsabilidade do Application service, mirando o mesmo
 * padrao de ProcedureExecutionForm.php/PrescriptionForm.php (recebe
 * encounter_id via querystring, $_GET com fallback para $param) e o
 * carrinho em TDataGrid de SaleForm.php (aqui os itens ja estao persistidos
 * pelo service, nao num rascunho de TSession).
 *
 * Fluxo: ao carregar com um encounter_id valido, chama
 * EncounterAccountService::openOrGet() (cria a conta 'open' se ainda nao
 * existir, idempotente) e, em seguida, syncAutomaticItems() (adiciona os
 * itens de procedure_execution/exam_request ainda nao espelhados na conta,
 * idempotente por UNIQUE account_id+source_type+source_id) — exatamente o
 * criterio de aceite "abrir a tela para um encontro sem conta ainda cria
 * uma encounter_account em status='open' e mostra os itens automaticos ja
 * sincronizados". Os itens sao listados num TDataGrid somente leitura,
 * populado com EncounterAccountItemRepositoryInterface::listByAccount()
 * (unica leitura direta de Persistence nesta tela, mesmo precedente ja
 * usado por CashSessionService::totalsByPaymentMethod() — o service nao
 * expoe um metodo de listagem de itens, so de sincronizacao/insercao,
 * entao a leitura de exibicao usa o mesmo Repository que o proprio service
 * injeta, nunca Domain diretamente).
 *
 * Acao de desconto com $action PROPRIO (decisao de design de T-03,
 * documentada no docblock de EncounterAccountService e em notes.md):
 * 'EncounterAccountForm::onApplyDiscount', nunca reaproveitado pelos demais
 * botoes ('EncounterAccountForm::onSave' para "Adicionar item manual" e
 * 'EncounterAccountForm::onClose' para "Fechar conta") — e essa distincao
 * de string de acao que permite RBAC (T-12) conceder a permissao de dar
 * desconto separadamente da permissao de apenas fechar a conta.
 *
 * Quando EncounterAccountService recusa por
 * DiscountExceedsSubtotalException (desconto maior que o subtotal),
 * InvalidStatusTransitionException (conta ja fechada/cancelada),
 * CrossTenantReferenceException (encounter_id/account_id de outro tenant
 * ou inexistente) ou AuthorizationDenied (unidade ativa sem permissao para
 * a acao), esta tela captura a excecao e exibe a recusa como TMessage de
 * erro, mantendo a conta aberta e sem navegar para outra tela (criterio de
 * aceite: "clicar em Fechar conta com DiscountExceedsSubtotalException/
 * AuthorizationDenied mostra TMessage de erro e mantem a conta aberta").
 * Fechar com sucesso mostra o total do Receivable gerado (criterio de
 * aceite) e recarrega a tela, agora em estado somente-leitura (conta
 * 'closed').
 *
 * Sem model Adianti proprio: como ProcedureExecutionForm/SaleForm, esta
 * tela consome apenas os objetos de Domain devolvidos pelo Application
 * service (EncounterAccount/EncounterAccountItem/Receivable), nunca um
 * TRecord — src/app/model/clinic/EncounterAccount.php listado em tasks.md
 * como "arquivo provavel" nao chegou a ser necessario, mesma constatacao
 * ja registrada por T-04/T-05/T-06 (CashSession.php/Payable.php/
 * FinancialEntry.php/Payment.php tambem nao foram criados sob
 * src/app/model/clinic pelas telas correspondentes).
 *
 * PENDENTE: depende das 7 tabelas da migration ainda nao aplicada
 * src/app/database/migrations/20260925_0006_phase5_financial.sql (T-01).
 * Esta classe e apenas preparada/validada com `php -l` e `new
 * EncounterAccountForm()` (sem parametros, sem erro fatal) ate a migration
 * ser aplicada.
 *
 * @version    1.0
 * @package    control
 * @subpackage clinic
 */
class EncounterAccountForm extends TPage
{
    protected $form;
    protected $datagrid;
    private ?int $encounterId;
    private ?\CentralVet\Domain\EncounterAccount $account = null;

    /**
     * Page constructor.
     */
    public function __construct($param = null)
    {
        parent::__construct();

        $this->encounterId = self::paramInt('encounter_id', $param);

        $container = new TVBox;
        $container->style = 'width: 100%';

        // cabeçalho do kit Cv*: voltar para o atendimento de origem
        $actions = [];
        if ($this->encounterId !== null)
        {
            $actions[] = ['icon' => 'fa:arrow-left', 'href' => 'index.php?class=EncounterView&encounter_id=' . $this->encounterId];
        }
        $container->add(CvPage::header(
            $this->encounterId !== null
                ? _t('Encounter account') . ' #' . $this->encounterId
                : _t('Encounter account'),
            _t('Financial'),
            $actions
        ));

        if ($this->encounterId === null)
        {
            $container->add($this->emptyStatePanel());
            parent::add($container);
            return;
        }

        $this->account = $this->loadAccount();

        if (!$this->account instanceof \CentralVet\Domain\EncounterAccount)
        {
            // openOrGet()/syncAutomaticItems() already reported the refusal
            // as a TMessage inside loadAccount(); render nothing further.
            $container->add($this->emptyStatePanel());
            parent::add($container);
            return;
        }

        // colunas 8/4 do kit: itens + item manual à esquerda, resumo,
        // desconto e fechar conta à direita
        $left = new TElement('div');
        $left->add($this->buildItemsPanel());

        $right = new TElement('div');
        $right->add($this->buildSummaryPanel());

        if ($this->account->status() === \CentralVet\Domain\EncounterAccount::STATUS_OPEN)
        {
            $left->add($this->buildManualItemForm());
            $right->add($this->buildDiscountForm());
            $right->add($this->buildCloseButton());
        }

        $container->add(CvPage::columns($left, $right));

        parent::add($container);
    }

    private function emptyStatePanel(): TPanelGroup
    {
        $panel = new TPanelGroup(_t('Encounter account'));
        $panel->class = 'cv-section';
        $panel->add('<p>' . _t('Provide a valid encounter_id to open its account.') . '</p>');

        return $panel;
    }

    /**
     * Opens (or gets) the account for $this->encounterId and immediately
     * syncs its automatic items, exactly as the class docblock describes.
     * Any refusal is caught and shown as a TMessage; returns null instead
     * of throwing, matching ProcedureExecutionForm's "never a fatal error"
     * convention.
     */
    private function loadAccount(): ?\CentralVet\Domain\EncounterAccount
    {
        try
        {
            $context = self::resolveTenantContext();

            TTransaction::open('permission');

            $service = self::makeEncounterAccountService($context);

            $account = $service->openOrGet($this->encounterId, 'EncounterAccountForm::onLoad');

            if ($account->status() === \CentralVet\Domain\EncounterAccount::STATUS_OPEN)
            {
                $service->syncAutomaticItems((int) $account->id(), 'EncounterAccountForm::onLoad');

                // Re-read so the in-memory account reflects the totals
                // syncAutomaticItems() just persisted.
                $account = $service->openOrGet($this->encounterId, 'EncounterAccountForm::onLoad');
            }

            TTransaction::close();

            return $account;
        }
        catch (\CentralVet\Domain\Exception\CrossTenantReferenceException $e)
        {
            TTransaction::rollback();
            error_log(__METHOD__ . ': ' . $e->getMessage());
            new TMessage('error', CvFormat::userError($e));
        }
        catch (\CentralVet\Authorization\Exception\AuthorizationDenied $e)
        {
            TTransaction::rollback();
            new TMessage('error', _t('You are not allowed to access this encounter account'));
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

    /**
     * Highlighted financial summary panel (T-07 criterio de aceite: "painel
     * de resumo financeiro destacado ... mesmo padrao visual do 'Resumo
     * financeiro em tempo real' da referencia") — same cv-section panel
     * pattern as EncounterView::financePanel(), but with the account's own
     * subtotal/discount/total emphasized in a soft-background block instead
     * of a single inline paragraph. Pure presentation: reads
     * $this->account (already produced by loadAccount()/openOrGet()), no
     * new Application/Domain/Repository call.
     */
    private function buildSummaryPanel(): TPanelGroup
    {
        $panel = new TPanelGroup(_t('Financial summary') . ' #' . $this->account->id());
        $panel->class = 'cv-section';

        $meta = new TElement('div');
        $meta->style = 'display:flex; flex-direction:column; gap:var(--cv-space-1); '
            . 'color:var(--cv-color-text-muted); font-size:12px; margin-bottom:var(--cv-space-3)';
        $meta->add('<div>' . _t('Encounter') . ': ' . $this->account->encounterId() . '</div>');
        $meta->add('<div>' . _t('Patient') . ': ' . $this->account->patientId() . '</div>');
        $status = new TElement('div');
        $status->add(CvFormat::e(_t('Status')) . ': ');
        $status->add(self::statusBadge($this->account->status()));
        $meta->add($status);
        $panel->add($meta);

        $totals = new TElement('div');
        $totals->style = 'display:flex; flex-direction:column; gap:var(--cv-space-2); '
            . 'padding:var(--cv-space-3); border-radius:var(--cv-radius-md); '
            . 'background:var(--cv-color-primary-soft)';
        $totals->add(
            '<div style="display:flex; justify-content:space-between">'
            . '<span>' . _t('Subtotal') . '</span><strong>' . self::formatCents($this->account->subtotalCents()) . '</strong></div>'
        );
        $totals->add(
            '<div style="display:flex; justify-content:space-between">'
            . '<span>' . _t('Discount') . '</span><strong>' . self::formatCents($this->account->discountCents()) . '</strong></div>'
        );
        $totals->add(
            '<div style="display:flex; justify-content:space-between; font-size:1.1rem; '
            . 'padding-top:var(--cv-space-2); border-top:1px solid var(--cv-color-border)">'
            . '<span>' . _t('Total') . '</span><strong>' . self::formatCents($this->account->totalCents()) . '</strong></div>'
        );
        $panel->add($totals);

        return $panel;
    }

    private function buildItemsPanel(): TPanelGroup
    {
        $panel = new TPanelGroup(_t('Items'));
        $panel->class = 'cv-section';
        $panel->add($this->buildItemsDatagrid());

        return $panel;
    }

    /**
     * Read-only listing of the account's items, straight from
     * EncounterAccountItemRepositoryInterface::listByAccount() — see class
     * docblock for why this is the one direct Persistence read in this
     * screen.
     */
    private function buildItemsDatagrid()
    {
        $this->datagrid = new BootstrapDatagridWrapper(new TDataGrid);
        CvDatagrid::decorate($this->datagrid, false);

        $column_type = new TDataGridColumn('source_type', _t('Type'), 'left', 140);
        $column_description = new TDataGridColumn('description_text', _t('Description'), 'left');
        $column_amount = new TDataGridColumn('amount_label', _t('Amount'), 'right', 110);

        $this->datagrid->addColumn($column_type);
        $this->datagrid->addColumn($column_description);
        $this->datagrid->addColumn($column_amount);

        $this->datagrid->createModel();

        foreach ($this->loadItems() as $item)
        {
            /** @var \CentralVet\Domain\EncounterAccountItem $item */
            $row = new stdClass;
            $row->source_type = $item->sourceType();
            $row->description_text = $item->descriptionText();
            $row->amount_label = self::formatCents($item->amountCents());

            $this->datagrid->addItem($row);
        }

        return $this->datagrid;
    }

    /** @return list<\CentralVet\Domain\EncounterAccountItem> */
    private function loadItems(): array
    {
        try
        {
            $context = self::resolveTenantContext();

            TTransaction::open('permission');

            $connection = TTransaction::get();
            $items = new \CentralVet\Persistence\EncounterAccountItemRepository($context, $connection);
            $list = $items->listByAccount((int) $this->account->id());

            TTransaction::close();

            return $list;
        }
        catch (Exception $e)
        {
            TTransaction::rollback();
            new TMessage('error', $e->getMessage());

            return [];
        }
    }

    private function buildManualItemForm(): TPanelGroup
    {
        $panel = new TPanelGroup(_t('Add manual item'));
        $panel->class = 'cv-section';

        $this->form = new BootstrapFormBuilder('form_EncounterAccountManualItem');
        $this->form->enableClientValidation();
        CvForm::decorate($this->form, 2);

        $account_id = new THidden('account_id');
        $account_id->setValue($this->account->id());
        $this->form->add($account_id);

        $description_text = new TEntry('description_text');
        $description_text->setSize('100%');
        $description_text->addValidation(_t('Description'), new TRequiredValidator);

        $amount_cents = new TEntry('amount_cents');
        $amount_cents->setNumericMask(2, ',', '.', false);
        $amount_cents->setSize('100%');
        $amount_cents->addValidation(_t('Amount'), new TRequiredValidator);

        $this->form->addFields([new TLabel(_t('Description'))], [$description_text], [new TLabel(_t('Amount (R$)'))], [$amount_cents]);

        $btn = $this->form->addAction(_t('Add manual item'), new TAction([$this, 'onSave'], ['encounter_id' => $this->encounterId]), 'fa:plus');
        $btn->class = 'btn btn-default';

        $panel->add($this->form);

        return $panel;
    }

    private function buildDiscountForm(): TPanelGroup
    {
        $panel = new TPanelGroup(_t('Discount'));
        $panel->class = 'cv-section';

        $discountForm = new BootstrapFormBuilder('form_EncounterAccountDiscount');
        $discountForm->enableClientValidation();
        CvForm::decorate($discountForm, 1);

        $account_id = new THidden('account_id');
        $account_id->setValue($this->account->id());
        $discountForm->add($account_id);

        $discount_cents = new TEntry('discount_cents');
        $discount_cents->setNumericMask(2, ',', '.', false);
        $discount_cents->setSize('100%');
        $discount_cents->addValidation(_t('Discount'), new TRequiredValidator);

        // quem autorizou: combo de usuários ativos do tenant (antes, id digitado)
        $authorized_by_system_user_id = new TDBCombo(
            'authorized_by_system_user_id', 'permission', 'SystemUser', 'id', 'name', 'name', self::tenantUsersCriteria()
        );
        $authorized_by_system_user_id->enableSearch();
        $authorized_by_system_user_id->setSize('100%');
        $authorized_by_system_user_id->setValue(TSession::getValue('userid'));
        $authorized_by_system_user_id->addValidation(_t('Authorized by'), new TRequiredValidator);

        $discountForm->addFields([new TLabel(_t('Discount (R$)'))], [$discount_cents]);
        $discountForm->addFields([new TLabel(_t('Authorized by'))], [$authorized_by_system_user_id]);

        // Distinct $action from every other button on this screen (see
        // class docblock): 'EncounterAccountForm::onApplyDiscount', never
        // shared with onSave()/onClose() — the RBAC hook T-12 relies on.
        $btn = $discountForm->addAction(_t('Apply discount'), new TAction([$this, 'onApplyDiscount'], ['encounter_id' => $this->encounterId]), 'fa:percent');
        $btn->class = 'btn btn-default';

        $panel->add($discountForm);

        return $panel;
    }

    private function buildCloseButton(): TElement
    {
        $wrapper = new TElement('div');
        $wrapper->class = 'cv-section';

        $form = new BootstrapFormBuilder('form_EncounterAccountClose');
        CvForm::decorate($form, 1);

        $account_id = new THidden('account_id');
        $account_id->setValue($this->account->id());
        $form->add($account_id);

        $btn = $form->addAction(_t('Close account'), new TAction([$this, 'onClose'], ['encounter_id' => $this->encounterId]), 'fa:check-circle');
        $btn->class = 'btn btn-primary';

        $wrapper->add($form);

        return $wrapper;
    }

    /**
     * method onSave()
     * "Adicionar item manual" — calls
     * EncounterAccountService::addManualItem() with its own $action
     * ('EncounterAccountForm::onSave'), distinct from onApplyDiscount()'s
     * (see class docblock).
     */
    public function onSave($param)
    {
        try
        {
            $accountId = isset($param['account_id']) ? (int) $param['account_id'] : 0;
            $descriptionText = isset($param['description_text']) ? (string) $param['description_text'] : '';
            $amountCents = self::toCents($param['amount_cents'] ?? null);

            if ($accountId <= 0)
            {
                throw new InvalidArgumentException(_t('Invalid account'));
            }

            $context = self::resolveTenantContext();

            TTransaction::open('permission');

            $service = self::makeEncounterAccountService($context);
            $service->addManualItem($accountId, $descriptionText, $amountCents, 'EncounterAccountForm::onSave');

            TTransaction::close();

            new TMessage('info', _t('Manual item added successfully'));
            $this->reloadSelf();
        }
        catch (\CentralVet\Domain\Exception\InvalidStatusTransitionException $e)
        {
            TTransaction::rollback();
            new TMessage('error', $e->getMessage());
        }
        catch (\CentralVet\Domain\Exception\CrossTenantReferenceException $e)
        {
            TTransaction::rollback();
            error_log(__METHOD__ . ': ' . $e->getMessage());
            new TMessage('error', CvFormat::userError($e));
        }
        catch (\CentralVet\Authorization\Exception\AuthorizationDenied $e)
        {
            TTransaction::rollback();
            new TMessage('error', _t('You are not allowed to add items to this account'));
        }
        catch (InvalidArgumentException $e)
        {
            TTransaction::rollback();
            new TMessage('error', $e->getMessage());
        }
        catch (Exception $e) // catch-all: never let a fatal error reach the screen
        {
            TTransaction::rollback();
            new TMessage('error', $e->getMessage());
        }
    }

    /**
     * method onApplyDiscount()
     * "Aplicar desconto" — calls EncounterAccountService::applyDiscount()
     * with its OWN $action ('EncounterAccountForm::onApplyDiscount'),
     * never reused by onSave()/onClose() (see class docblock and T-03's
     * design note in notes.md): this is what lets T-12 register discount
     * authorization as an RBAC permission distinct from ordinary account
     * access. DiscountExceedsSubtotalException/AuthorizationDenied are
     * caught and shown as TMessage, leaving the account open/unchanged
     * (criterio de aceite de T-07).
     */
    public function onApplyDiscount($param)
    {
        try
        {
            $accountId = isset($param['account_id']) ? (int) $param['account_id'] : 0;
            $discountCents = self::toCents($param['discount_cents'] ?? null);
            $authorizedBySystemUserId = isset($param['authorized_by_system_user_id']) && $param['authorized_by_system_user_id'] !== ''
                ? (int) $param['authorized_by_system_user_id']
                : 0;

            if ($accountId <= 0)
            {
                throw new InvalidArgumentException(_t('Invalid account'));
            }

            if ($authorizedBySystemUserId <= 0)
            {
                throw new InvalidArgumentException(_t('Inform who authorized the discount'));
            }

            $context = self::resolveTenantContext();

            TTransaction::open('permission');

            $service = self::makeEncounterAccountService($context);
            $service->applyDiscount(
                $accountId,
                $discountCents,
                $authorizedBySystemUserId,
                'EncounterAccountForm::onApplyDiscount'
            );

            TTransaction::close();

            new TMessage('info', _t('Discount applied successfully'));
            $this->reloadSelf();
        }
        catch (\CentralVet\Domain\Exception\DiscountExceedsSubtotalException $e)
        {
            TTransaction::rollback();
            new TMessage('error', $e->getMessage());
        }
        catch (\CentralVet\Domain\Exception\InvalidStatusTransitionException $e)
        {
            TTransaction::rollback();
            new TMessage('error', $e->getMessage());
        }
        catch (\CentralVet\Domain\Exception\CrossTenantReferenceException $e)
        {
            TTransaction::rollback();
            error_log(__METHOD__ . ': ' . $e->getMessage());
            new TMessage('error', CvFormat::userError($e));
        }
        catch (\CentralVet\Authorization\Exception\AuthorizationDenied $e)
        {
            TTransaction::rollback();
            new TMessage('error', _t('You are not allowed to apply a discount to this account'));
        }
        catch (InvalidArgumentException $e)
        {
            TTransaction::rollback();
            new TMessage('error', $e->getMessage());
        }
        catch (Exception $e) // catch-all: never let a fatal error reach the screen
        {
            TTransaction::rollback();
            new TMessage('error', $e->getMessage());
        }
    }

    /**
     * method onClose()
     * "Fechar conta" — calls EncounterAccountService::close() with its own
     * $action ('EncounterAccountForm::onClose', distinct from
     * onApplyDiscount()'s — see class docblock). On success, shows the
     * generated Receivable's total (criterio de aceite) and reloads this
     * same screen, now read-only (account status='closed'). Any refusal
     * (InvalidStatusTransitionException/CrossTenantReferenceException/
     * AuthorizationDenied) is shown as TMessage, leaving the account open
     * (criterio de aceite).
     */
    public function onClose($param)
    {
        try
        {
            $accountId = isset($param['account_id']) ? (int) $param['account_id'] : 0;

            if ($accountId <= 0)
            {
                throw new InvalidArgumentException(_t('Invalid account'));
            }

            $context = self::resolveTenantContext();

            TTransaction::open('permission');

            $service = self::makeEncounterAccountService($context);
            $receivable = $service->close($accountId, 'EncounterAccountForm::onClose');

            TTransaction::close();

            // The account-closing flow's only exit was ever a plain
            // confirmation message: nothing linked forward to actually
            // registering a payment against the new Receivable, leaving
            // PaymentForm reachable only through its bare, dead-end menu
            // entry (real gap found while auditing every screen's real
            // entry path, not a cosmetic issue). PaymentForm is a plain
            // TPage with no re-enterable method for TMessage's own $action
            // parameter to target (its constructor alone reads
            // receivable_id), so the link is a plain <a> inside the
            // message body instead — TMessage::__construct() only
            // addslashes() the text for JS-string embedding, it does not
            // escape HTML, so the anchor renders as a real clickable link.
            $paymentUrl = 'index.php?class=PaymentForm&receivable_id=' . $receivable->id();
            new TMessage(
                'info',
                _t('Account closed successfully') . ' &mdash; ' . _t('Receivable total')
                . ': ' . self::formatCents($receivable->totalCents())
                . '<br><a href="' . $paymentUrl . '">' . _t('Register payment') . '</a>'
            );
            $this->reloadSelf();
        }
        catch (\CentralVet\Domain\Exception\InvalidStatusTransitionException $e)
        {
            TTransaction::rollback();
            new TMessage('error', $e->getMessage());
        }
        catch (\CentralVet\Domain\Exception\CrossTenantReferenceException $e)
        {
            TTransaction::rollback();
            error_log(__METHOD__ . ': ' . $e->getMessage());
            new TMessage('error', CvFormat::userError($e));
        }
        catch (\CentralVet\Authorization\Exception\AuthorizationDenied $e)
        {
            TTransaction::rollback();
            new TMessage('error', _t('You are not allowed to close this account'));
        }
        catch (InvalidArgumentException $e)
        {
            TTransaction::rollback();
            new TMessage('error', $e->getMessage());
        }
        catch (Exception $e) // catch-all: never let a fatal error reach the screen
        {
            TTransaction::rollback();
            new TMessage('error', $e->getMessage());
        }
    }

    /**
     * Converts a "1.234,56"/"1234.56"-style amount typed in a TEntry with a
     * numeric mask into integer cents, matching ServiceForm::toCents()'s
     * convention (SaleForm/PrescriptionForm siblings use the same rounding
     * approach for money fields).
     */
    private static function toCents($value): int
    {
        if ($value === null || $value === '')
        {
            return 0;
        }

        $normalized = str_replace('.', '', (string) $value);
        $normalized = str_replace(',', '.', $normalized);

        return (int) round(((float) $normalized) * 100);
    }

    private static function formatCents(int $cents): string
    {
        return CvFormat::money($cents);
    }

    /**
     * Badge de status da conta: Aberto / Fechado / Cancelado.
     */
    private static function statusBadge(string $status): TElement
    {
        if ($status === \CentralVet\Domain\EncounterAccount::STATUS_OPEN)
        {
            return CvBadge::create(_t('Open (status)'), 'info');
        }
        if ($status === \CentralVet\Domain\EncounterAccount::STATUS_CLOSED)
        {
            return CvBadge::create(_t('Closed'), 'success');
        }

        return CvBadge::create($status, 'neutral');
    }

    /**
     * Usuários ativos vinculados ao tenant da sessão (tenant_user); sem
     * tenant resolvido, nenhum usuário (fail-closed).
     */
    private static function tenantUsersCriteria(): TCriteria
    {
        return CvTenantUsers::criteria(static fn () => self::resolveTenantContext());
    }

    /**
     * Reloads this same screen (same encounter_id), forcing the
     * constructor to re-run — mirrors ProcedureExecutionForm/SaleForm's
     * own reload pattern, but staying on this screen instead of navigating
     * to EncounterView (this account may still need more manual items/a
     * discount before being closed).
     */
    private function reloadSelf(): void
    {
        // as TAction levam encounter_id na URL do post; a conta carregada é
        // o fallback caso o post chegue sem ele
        $encounterId = $this->encounterId
            ?? ($this->account instanceof \CentralVet\Domain\EncounterAccount ? $this->account->encounterId() : null);

        TScript::create(
            "__adianti_goto_page('index.php?class=EncounterAccountForm&encounter_id=" . (int) $encounterId . "')"
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
     * Wires EncounterAccountService (T-03) from its Persistence/PDO
     * implementations. Mirrors ProcedureExecutionForm::
     * makeProcedureExecutionService(): the real RbacAuthorizationService
     * (Fase 0), backed by AdiantiProgramPermissionProvider and
     * PdoAuditLogWriter against this same 'permission' connection, so
     * every mutating call is both unit-scope-checked and audited to
     * `audit_log`. Requires an already-open TTransaction('permission')
     * connection.
     */
    private static function makeEncounterAccountService(
        \CentralVet\Tenancy\TenantContext $context
    ): \CentralVet\Application\EncounterAccountService {
        $connection = TTransaction::get();

        $accounts = new \CentralVet\Persistence\EncounterAccountRepository($context, $connection);
        $items = new \CentralVet\Persistence\EncounterAccountItemRepository($context, $connection);
        $receivables = new \CentralVet\Persistence\ReceivableRepository($context, $connection);
        $encounters = new \CentralVet\Persistence\EncounterRepository($context, $connection);
        $patients = new \CentralVet\Persistence\PatientRepository($context, $connection);
        $procedureExecutions = new \CentralVet\Persistence\ProcedureExecutionRepository($context, $connection);
        $examRequests = new \CentralVet\Persistence\ExamRequestRepository($context, $connection);
        $examCatalog = new \CentralVet\Persistence\ExamCatalogRepository($context, $connection);

        $procedureCatalog = new \CentralVet\Persistence\ProcedureCatalogRepository($context, $connection);
        $procedureCatalogInputs = new \CentralVet\Persistence\ProcedureCatalogItemInputRepository($context, $connection);
        $procedureCatalogService = new \CentralVet\Application\ProcedureCatalogService(
            $procedureCatalog,
            $procedureCatalogInputs,
            $context
        );

        $authorization = new \CentralVet\Authorization\RbacAuthorizationService(
            new \CentralVet\Authorization\AdiantiProgramPermissionProvider(new \CentralVet\Tenancy\AdiantiSessionContextSource()),
            new \CentralVet\Audit\PdoAuditLogWriter($connection),
        );

        return new \CentralVet\Application\EncounterAccountService(
            $accounts,
            $items,
            $receivables,
            $encounters,
            $patients,
            $procedureExecutions,
            $examRequests,
            $procedureCatalogService,
            $examCatalog,
            $authorization,
            $context,
            new \CentralVet\Persistence\TenantUserDirectory($context, $connection),
        );
    }

    /**
     * Resolves the tenant context of the authenticated session. Copied
     * verbatim from ProcedureExecutionForm::resolveTenantContext()/
     * SaleForm::resolveTenantContext() (same fallback for legacy sessions
     * where TSession does not carry 'tenantid' yet).
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
