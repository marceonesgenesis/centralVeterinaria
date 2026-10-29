<?php
/**
 * SaleForm
 *
 * Tela de venda/PDV (T-10), consumindo exclusivamente:
 *  - CentralVet\Application\SaleService::create()/findById() (T-06)
 *  - CentralVet\Application\ProductService::listActive() (T-03)
 *  - CentralVet\Application\ProcedureCatalogService::listActive() (T-04)
 * Nao contem nenhuma regra de negocio propria: toda validacao (produto/
 * procedimento inexistente ou inativo, saldo de estoque insuficiente,
 * autorizacao por unidade) e feita inteiramente por SaleService::create(),
 * mirando o padrao ja estabelecido em AppointmentForm.php (Fase 1) e
 * PrescriptionForm.php (Fase 3).
 *
 * Carrinho: cada "Adicionar produto"/"Adicionar procedimento" resolve o id
 * digitado contra ProductService::listActive()/ProcedureCatalogService::
 * listActive() (nunca contra Persistence/Domain diretamente), grava a linha
 * resolvida (tipo, referencia, quantidade, descricao e preco unitario) num
 * rascunho em TSession e recarrega a propria tela (__adianti_goto_page, como
 * PrescriptionForm::onAddItem()) para redesenhar o carrinho num TDataGrid —
 * nenhuma linha e persistida no banco antes do "Finalizar venda", que e o
 * unico ponto que chama SaleService::create(). O rascunho e uma unica cesta
 * ativa por sessao (nao ha um identificador natural como encounter_id para
 * chavear multiplas cestas simultaneas, ao contrario do rascunho de
 * PrescriptionForm).
 *
 * Quando SaleService::create() recusa por InsufficientStockException (saldo
 * insuficiente de algum item de produto), CrossTenantReferenceException
 * (produto/procedimento inexistente, inativo ou de outro tenant) ou por
 * AuthorizationDenied (unidade ativa sem permissao), esta tela captura a
 * excecao e exibe a mensagem de recusa em tela via TMessage, sem propagar um
 * erro fatal e sem gerar recibo (criterio de aceite T-10).
 *
 * Finalizar venda (T-15, Etapa 2): onSave() chama SaleCheckoutService::
 * checkout() numa unica TTransaction — venda, recebivel e, com forma de
 * pagamento informada, o recebimento a vista no caixa aberto da unidade. O
 * combo vazio significa a prazo (recebivel em aberto). O THidden
 * submission_token, gerado a cada renderizacao, impede gravar a mesma venda
 * duas vezes (DuplicateSubmissionException leva a venda ja gravada).
 *
 * PDF do recibo: onGenerateReceiptPdf() le a venda gravada (SaleService::
 * findById()), seus itens (SaleItemRepository::listBySale()) e o resumo
 * financeiro (SaleFinanceReader::receiptSummary()) e entrega o HTML de
 * CentralVet\Presentation\SaleReceiptHtml::render() ao dompdf. Nada vem do
 * carrinho em sessao: o recibo reflete exatamente o que foi gravado,
 * inclusive recebido, saldo e cancelamento.
 *
 * PENDENTE: as tabelas `product`, `procedure_catalog_item`, `sale` e
 * `sale_item` ainda dependem da migration
 * src/app/database/migrations/20260924_0005_phase4_procedure_stock_sale.sql
 * (T-01), que ainda nao foi aplicada ao MySQL. Esta classe e apenas
 * preparada/validada com `php -l` e `new SaleForm()` (sem parametros, sem
 * erro fatal); nao deve ser exercida contra um banco real antes da migration
 * ser aplicada.
 *
 * @version    8.6
 * @package    control
 * @subpackage clinic
 * @license    https://adiantiframework.com.br/license-template
 */
class SaleForm extends TPage
{
    protected $form; // header + item-entry form
    protected $datagrid; // cart listing

    private ?int $encounterId = null;
    private ?int $patientId = null;
    private ?int $savedSaleId = null;
    private ?int $tutorId = null;

    /**
     * Class constructor
     * Creates the sale/PDV form. Reads encounter_id/patient_id/sale_id from
     * $_GET (falling back to $param), mirroring PrescriptionForm's
     * paramInt(). `new SaleForm()` with no parameters at all must never
     * throw — it renders an empty-state form/cart instead (validation
     * command for this task).
     */
    public function __construct($param = null)
    {
        parent::__construct();

        $this->encounterId = self::paramInt('encounter_id', $param);
        $this->patientId = self::paramInt('patient_id', $param);
        $this->savedSaleId = self::paramInt('sale_id', $param);
        $this->tutorId = TSession::getValue($this->tutorSessionKey());

        $this->form = new BootstrapFormBuilder('form_Sale');
        $this->form->setFormTitle(_t('Sale'));
        $this->form->enableClientValidation();

        // resolveTenantContext() is only guaranteed after an authenticated
        // session; per this class's own contract, `new SaleForm()` must
        // never throw (see class docblock), so an unresolved tenant here
        // falls back to an impossible tenant_id — the search widgets just
        // return zero matches instead of a fatal error.
        try
        {
            $tenant_id = self::resolveTenantContext()->tenantId();
        }
        catch (\CentralVet\Tenancy\Exception\MissingTenantContext $e)
        {
            $tenant_id = -1;
        }

        $tenant_criteria = new TCriteria;
        $tenant_criteria->add(new TFilter('tenant_id', '=', $tenant_id));

        // header fields
        $tutor_id = new TDBUniqueSearch('tutor_id', 'permission', 'Tutor', 'id', 'full_name', 'full_name', $tenant_criteria);
        $patient_id = new TDBUniqueSearch('patient_id', 'permission', 'Patient', 'id', 'name', 'name', $tenant_criteria);
        // T-65: rótulos escapados no select2 (busca/ordem seguem na coluna real)
        $tutor_id->setMask(Tutor::safeSearchMask('full_name_safe'));
        $patient_id->setMask(Patient::safeSearchMask('name_safe'));
        $encounter_id = new TEntry('encounter_id');

        $encounter_id->setNumericMask(0, '', '', false, false, false);
        $encounter_id->setProperty('pattern', '[0-9]*');

        $this->form->addFields(
            [new TLabel(_t('Tutor'))], [$tutor_id],
            [new TLabel(_t('Patient'))], [$patient_id]
        );
        $this->form->addFields( [new TLabel(_t('Encounter'))], [$encounter_id] );

        $patient_id->setValue($this->patientId);
        $encounter_id->setValue($this->encounterId);

        // Restores the tutor selected before the last cart reload
        // (onAddProductItem()/onAddProcedureItem()/onRemoveItem() all
        // rebuild this whole page via reloadSelf(), which — unlike
        // patient_id/encounter_id — never carried tutor_id through the URL,
        // so the required field came back empty and blocked "Finalizar
        // venda" via client-side validation even after the tutor had
        // already been picked once).
        if ($this->tutorId !== null)
        {
            $tutor_id->setValue($this->tutorId);
        }

        $tutor_id->addValidation( _t('Tutor'), new TRequiredValidator );

        // item entry: product
        $product_id = new TDBUniqueSearch('product_id', 'permission', 'Product', 'id', 'name', 'name', $tenant_criteria);
        $product_id->setMask(Product::safeSearchMask('name_safe')); // T-65
        $product_quantity = new TEntry('product_quantity');

        $product_quantity->setNumericMask(0, '', '', false, false, false);
        $product_quantity->setProperty('pattern', '[0-9]*');
        $product_quantity->setValue(1);

        $this->form->addFields(
            [new TLabel(_t('Product'))], [$product_id],
            [new TLabel(_t('Product quantity'))], [$product_quantity]
        );

        // item entry: procedure
        $procedure_id = new TDBUniqueSearch('procedure_id', 'permission', 'ProcedureCatalogItem', 'id', 'name', 'name', $tenant_criteria);
        $procedure_id->setMask(ProcedureCatalogItem::safeSearchMask('name_safe')); // T-65
        $procedure_quantity = new TEntry('procedure_quantity');

        $procedure_quantity->setNumericMask(0, '', '', false, false, false);
        $procedure_quantity->setProperty('pattern', '[0-9]*');
        $procedure_quantity->setValue(1);

        $this->form->addFields(
            [new TLabel(_t('Procedure'))], [$procedure_id],
            [new TLabel(_t('Procedure quantity'))], [$procedure_quantity]
        );

        // T-15: forma de pagamento — vazio = a prazo (recebível em aberto);
        // lista única de formas (Payment::METHODS), rótulos de
        // CvFormat::paymentMethod(), igual ao PaymentForm.
        $payment_method = new TCombo('payment_method');
        $payment_method->setDefaultOption(_t('On account (open receivable)'));
        $payment_items = [];
        foreach (\CentralVet\Domain\Payment::METHODS as $method)
        {
            $payment_items[$method] = CvFormat::paymentMethod($method);
        }
        $payment_method->addItems($payment_items);
        $payment_method->setSize('100%');

        $this->form->addFields( [new TLabel(_t('Payment method'))], [$payment_method] );

        // T-15: token de envio gerado a cada renderização; o checkout o
        // reivindica na mesma transação da venda (repetição não grava de novo).
        $submission_token = new THidden('submission_token');
        $submission_token->setValue(\CentralVet\Domain\SubmissionToken::generate());
        $this->form->add($submission_token);

        CvForm::decorate($this->form, 2);

        // form actions
        $addProductBtn = $this->form->addAction(_t('Add product'), new TAction(array($this, 'onAddProductItem')), 'fa:plus');
        $addProductBtn->class = 'btn btn-outline-secondary';

        $addProcedureBtn = $this->form->addAction(_t('Add procedure'), new TAction(array($this, 'onAddProcedureItem')), 'fa:plus');
        $addProcedureBtn->class = 'btn btn-outline-secondary';

        $this->form->addActionLink(_t('Clear cart'), new TAction(array($this, 'onClearCart')), 'fa:eraser');

        $saveBtn = $this->form->addAction(_t('Finalize sale'), new TAction(array($this, 'onSave')), 'fa:check');
        $saveBtn->class = 'btn btn-primary cv-touch-target';

        // vertical box container
        $container = new TVBox;
        $container->style = 'width: 100%';

        $headerActions = [
            ['icon' => 'fa:arrow-left', 'href' => 'index.php?class=ProductList'],
        ];
        if ($this->savedSaleId !== null)
        {
            // widget pronto (sem generator="adianti"): o PDF abre em nova aba
            $pdfLink = new TElement('a');
            $pdfLink->{'href'}   = CvFormat::e('engine.php?class=SaleForm&method=onGenerateReceiptPdf&static=1&sale_id=' . $this->savedSaleId);
            $pdfLink->{'target'} = '_blank';
            $pdfLink->{'rel'}    = 'noopener';
            $pdfLink->{'class'}  = 'btn btn-outline-secondary';
            $pdfLink->add(new TImage('fa:file-pdf'));
            $pdfLink->add(TElement::tag('span', CvFormat::e(_t('Generate PDF')), []));
            $headerActions[] = $pdfLink;
        }

        $container->add(CvPage::header(_t('Sale'), null, $headerActions));
        $container->add(CvNav::tabs('stock', 'sales'));
        $container->add($this->form);

        $cartBody = new TElement('div');
        $cartBody->add($this->buildCartDatagrid());

        $cartTotal = new TElement('p');
        $cartTotal->class = 'fw-semibold text-end mb-0';
        $cartTotal->style = 'margin-top: var(--cv-space-3); color: var(--cv-color-text)';
        $cartTotal->add(CvFormat::e(_t('Total') . ': ' . self::formatCents($this->cartTotalCents())));
        $cartBody->add($cartTotal);

        $cartCard = CvCard::create(_t('Cart'), $cartBody);
        $cartCard->style = 'margin-top: var(--cv-space-4)';
        $container->add($cartCard);

        parent::add($container);
    }

    /**
     * method onEdit()
     * Clears the header form (cart draft in TSession is untouched).
     */
    public function onEdit($param)
    {
        $this->form->clear();
    }

    /**
     * method onClearCart()
     * Discards the entire cart draft (every item added so far) without
     * touching the header form.
     */
    public function onClearCart($param)
    {
        TSession::setValue($this->draftSessionKey(), null);
        TSession::setValue($this->tutorSessionKey(), null);
        $this->reloadSelf();
    }

    /**
     * Builds the cart TDataGrid from the current TSession draft. No
     * business rule here — purely a read of the draft array assembled by
     * onAddProductItem()/onAddProcedureItem()/onRemoveItem(), mirrors
     * VaccineCatalogList's BootstrapDatagridWrapper usage.
     */
    private function buildCartDatagrid()
    {
        $this->datagrid = new BootstrapDatagridWrapper(new TDataGrid);
        CvDatagrid::decorate($this->datagrid, false);

        $column_type = new TDataGridColumn('type_label', _t('Type'), 'left', 100);
        $column_description = new TDataGridColumn('description', _t('Description'), 'left');
        $column_quantity = new TDataGridColumn('quantity', _t('Qty'), 'left', 60);
        $column_unit_price = new TDataGridColumn('unit_price_label', _t('Unit price'), 'left', 110);
        $column_total = new TDataGridColumn('total_label', _t('Total'), 'left', 110);

        $this->datagrid->addColumn($column_type);
        $this->datagrid->addColumn($column_description);
        $this->datagrid->addColumn($column_quantity);
        $this->datagrid->addColumn($column_unit_price);
        $this->datagrid->addColumn($column_total);

        $action_remove = new TDataGridAction(array($this, 'onRemoveItem'), ['index' => '{index}', 'register_state' => 'false']);
        $action_remove->setLabel(_t('Remove'));
        $action_remove->setImage('fa:trash red');
        $this->datagrid->addAction($action_remove);

        $this->datagrid->createModel();

        foreach ($this->draftItems() as $index => $item)
        {
            $row = new stdClass;
            $row->index = $index;
            $row->type_label = $item['type'] === 'product' ? _t('Product') : _t('Procedure');
            $row->description = $item['description'];
            $row->quantity = (int) $item['quantity'];
            $row->unit_price_label = self::formatCents((int) $item['unitPriceCents']);
            $row->total_label = self::formatCents((int) $item['unitPriceCents'] * (int) $item['quantity']);

            $this->datagrid->addItem($row);
        }

        return $this->datagrid;
    }

    /**
     * Reads the cart draft stashed in TSession. A single active cart per
     * session, cleared by onClearCart()/a successful onSave().
     *
     * @return list<array{type: string, referenceId: int, quantity: int, description: string, unitPriceCents: int}>
     */
    private function draftItems(): array
    {
        $items = TSession::getValue($this->draftSessionKey());

        return is_array($items) ? $items : [];
    }

    private function draftSessionKey(): string
    {
        return 'sale_form_cart_draft';
    }

    /**
     * Session key holding the tutor selected for the cart currently being
     * built — stashed alongside the item draft so it survives the full
     * page reload every add/remove action performs (see __construct()'s
     * own comment on this).
     */
    private function tutorSessionKey(): string
    {
        return 'sale_form_cart_tutor_id';
    }

    private function cartTotalCents(): int
    {
        $total = 0;

        foreach ($this->draftItems() as $item)
        {
            $total += (int) $item['unitPriceCents'] * (int) $item['quantity'];
        }

        return $total;
    }

    /**
     * method onAddProductItem()
     * Resolves the typed product id against ProductService::listActive()
     * (T-03) — unknown/inactive is refused here as a TMessage, never
     * appended to the cart — and appends the resolved line (description,
     * unit price captured at add time) to the TSession draft, then reloads
     * the screen so the cart TDataGrid is redrawn.
     */
    public function onAddProductItem($param)
    {
        try
        {
            $data = $this->form->getData();
            $productId = !empty($data->product_id) ? (int) $data->product_id : 0;
            $quantity = !empty($data->product_quantity) ? (int) $data->product_quantity : 0;

            if ($productId <= 0)
            {
                throw new Exception(_t('Inform the product id'));
            }

            if ($quantity < 1)
            {
                throw new Exception(_t('Quantity must be at least 1'));
            }

            TTransaction::open('permission');

            $tenant_context = self::resolveTenantContext();
            $service = self::buildProductService($tenant_context);

            $product = null;

            foreach ($service->listActive($tenant_context->tenantId()) as $candidate)
            {
                if ($candidate->id() === $productId)
                {
                    $product = $candidate;
                    break;
                }
            }

            TTransaction::close();

            if ($product === null)
            {
                throw new Exception(_t('Product not found or inactive'));
            }

            // F1: the cart line carries the sale price, never the cost; a
            // product without a sale price throws here, before the line is
            // appended to the draft.
            $items = $this->draftItems();
            $items[] = [
                'type' => 'product',
                'referenceId' => $productId,
                'quantity' => $quantity,
                'description' => $product->name(),
                'unitPriceCents' => $product->requireSalePriceCents(),
            ];

            TSession::setValue($this->draftSessionKey(), $items);

            if (!empty($data->tutor_id))
            {
                TSession::setValue($this->tutorSessionKey(), (int) $data->tutor_id);
            }

            $this->reloadSelf();
        }
        catch (\CentralVet\Domain\Exception\ProductWithoutSalePriceException $e)
        {
            TTransaction::rollback();
            new TMessage('error', CvFormat::userError($e));
        }
        catch (Exception $e)
        {
            TTransaction::rollback();
            error_log(__METHOD__ . ': ' . $e->getMessage());
            new TMessage('error', CvFormat::userError($e));
        }
    }

    /**
     * method onAddProcedureItem()
     * Same as onAddProductItem() but against ProcedureCatalogService::
     * listActive() (T-04) — procedure lines never touch stock (that only
     * happens for product lines, inside SaleService::create() itself, on
     * "Finalize sale").
     */
    public function onAddProcedureItem($param)
    {
        try
        {
            $data = $this->form->getData();
            $procedureId = !empty($data->procedure_id) ? (int) $data->procedure_id : 0;
            $quantity = !empty($data->procedure_quantity) ? (int) $data->procedure_quantity : 0;

            if ($procedureId <= 0)
            {
                throw new Exception(_t('Inform the procedure id'));
            }

            if ($quantity < 1)
            {
                throw new Exception(_t('Quantity must be at least 1'));
            }

            TTransaction::open('permission');

            $tenant_context = self::resolveTenantContext();
            $service = self::buildProcedureCatalogService($tenant_context);

            $procedure = null;

            foreach ($service->listActive() as $candidate)
            {
                if ($candidate->id() === $procedureId)
                {
                    $procedure = $candidate;
                    break;
                }
            }

            TTransaction::close();

            if ($procedure === null)
            {
                throw new Exception(_t('Procedure not found or inactive'));
            }

            $items = $this->draftItems();
            $items[] = [
                'type' => 'procedure',
                'referenceId' => $procedureId,
                'quantity' => $quantity,
                'description' => $procedure->name(),
                'unitPriceCents' => $procedure->priceCents(),
            ];

            TSession::setValue($this->draftSessionKey(), $items);

            if (!empty($data->tutor_id))
            {
                TSession::setValue($this->tutorSessionKey(), (int) $data->tutor_id);
            }

            $this->reloadSelf();
        }
        catch (Exception $e)
        {
            TTransaction::rollback();
            error_log(__METHOD__ . ': ' . $e->getMessage());
            new TMessage('error', CvFormat::userError($e));
        }
    }

    /**
     * method onRemoveItem()
     * Removes one line from the TSession cart draft by its index and
     * reloads.
     */
    public function onRemoveItem($param)
    {
        $index = isset($param['index']) ? (int) $param['index'] : -1;
        $items = $this->draftItems();

        if (isset($items[$index]))
        {
            unset($items[$index]);
            TSession::setValue($this->draftSessionKey(), array_values($items));
        }

        $this->reloadSelf();
    }

    /**
     * method onSave()
     * Executed whenever the user clicks "Finalize sale". Calls
     * SaleCheckoutService::checkout() (T-13) with the header fields, the
     * TSession cart draft, the payment method (empty = on account) and the
     * submission token rendered with this form, all inside one
     * TTransaction: sale, receivable and the cash payment commit together or
     * not at all. Every refusal (insufficient stock, cross-tenant/unknown
     * reference, unit-scope authorization, no open cash session, repeated
     * submission, lock wait/deadlock) becomes a message on screen after a
     * rollback, never a fatal error.
     */
    public function onSave($param)
    {
        try
        {
            $data = $this->form->getData();
            $this->form->validate();

            $items = $this->draftItems();

            if (empty($items))
            {
                throw new InvalidArgumentException(_t('Add at least one item to the cart before finalizing the sale'));
            }

            $paymentMethod = isset($param['payment_method']) && (string) $param['payment_method'] !== ''
                ? (string) $param['payment_method']
                : null;

            TTransaction::open('permission');

            $tenant_context = self::resolveTenantContext();
            $service = self::buildSaleCheckoutService($tenant_context);

            // $items' extra 'description'/'unitPriceCents' keys are ignored
            // by SaleService::readItem(), which only reads type/referenceId/
            // quantity — the catalog data is re-resolved and re-priced
            // there, so totals can never drift from what this cart displayed.
            $result = $service->checkout(
                tenantId: $tenant_context->tenantId(),
                systemUnitId: $tenant_context->requireUnitId(),
                tutorId: (int) $data->tutor_id,
                patientId: !empty($data->patient_id) ? (int) $data->patient_id : null,
                encounterId: !empty($data->encounter_id) ? (int) $data->encounter_id : null,
                systemUserId: $tenant_context->userId(),
                items: $items,
                paymentMethod: $paymentMethod,
                submissionToken: (string) ($param['submission_token'] ?? ''),
                action: 'SaleForm::onSave',
            );

            TTransaction::close();

            $saleId = (int) $result->sale->id();
            TSession::setValue($this->draftSessionKey(), null);
            TSession::setValue($this->tutorSessionKey(), null);

            new TMessage('info', _t('Sale completed successfully'));
            TScript::create(
                "__adianti_goto_page('index.php?class=SaleForm&sale_id={$saleId}')"
            );
        }
        catch (\CentralVet\Domain\Exception\DuplicateSubmissionException $e)
        {
            TTransaction::rollback();
            // repeated "Finalize sale" (double click, refresh, retry): the
            // first submission already committed the sale — show it instead
            // of writing a second one.
            $referenceId = $e->referenceId();
            new TMessage('info', _t('This submission was already processed'));

            if ($referenceId !== null)
            {
                $saleId = (int) $referenceId;
                TScript::create(
                    "__adianti_goto_page('index.php?class=SaleForm&sale_id={$saleId}')"
                );
            }
        }
        catch (\CentralVet\Domain\Exception\CashSessionRequiredException $e)
        {
            TTransaction::rollback();
            new TMessage('error', CvFormat::userError($e));
        }
        catch (PDOException $e)
        {
            TTransaction::rollback();
            error_log(__METHOD__ . ': ' . $e->getMessage());
            $driverCode = (int) ($e->errorInfo[1] ?? 0);

            // 1205 lock wait timeout / 1213 deadlock: another sale touched the
            // same stock batches; nothing was written, the cart is intact.
            if ($driverCode === 1205 || $driverCode === 1213)
            {
                new TMessage('error', _t('Stock changed during the sale; review the cart and try again'));
            }
            else
            {
                new TMessage('error', CvFormat::userError($e));
            }
        }
        catch (\CentralVet\Domain\Exception\InsufficientStockException $e)
        {
            TTransaction::rollback();
            error_log(__METHOD__ . ': ' . $e->getMessage());
            new TMessage('error', CvFormat::userError($e));
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
            new TMessage('error', _t('You are not allowed to register a sale for this unit'));
        }
        catch (\CentralVet\Tenancy\Exception\MissingTenantContext $e)
        {
            TTransaction::rollback();
            new TMessage('error', _t('An authenticated session with an active unit is required'));
        }
        catch (InvalidArgumentException $e)
        {
            TTransaction::rollback();
            error_log(__METHOD__ . ': ' . $e->getMessage());
            new TMessage('error', CvFormat::userError($e));
        }
        catch (Exception $e) // catch-all: never let a fatal error reach the screen
        {
            TTransaction::rollback();
            error_log(__METHOD__ . ': ' . $e->getMessage());
            new TMessage('error', CvFormat::userError($e));
        }
    }

    /**
     * method onGenerateReceiptPdf()
     * Executed when the user clicks "Generate PDF". Reloads the saved sale
     * (SaleService::findById(), tenant-scoped — a cross-tenant id resolves to
     * null), its persisted lines (SaleItemRepository::listBySale()) and its
     * financial summary (SaleFinanceReader::receiptSummary()), and streams
     * the PDF of SaleReceiptHtml::render() built with dompdf. Only reads
     * data already validated and persisted: no session snapshot of the cart.
     */
    public function onGenerateReceiptPdf($param)
    {
        try
        {
            $id = isset($param['sale_id']) ? (int) $param['sale_id'] : 0;

            if ($id <= 0)
            {
                throw new InvalidArgumentException(_t('Invalid sale'));
            }

            TTransaction::open('permission');

            $tenant_context = self::resolveTenantContext();
            $connection = TTransaction::get();
            $service = self::buildSaleService($tenant_context);
            $sale = $service->findById($id);

            $items = [];
            $summary = null;

            if ($sale !== null)
            {
                $items = (new \CentralVet\Persistence\SaleItemRepository($tenant_context, $connection))->listBySale($id);
                $summary = (new \CentralVet\Persistence\SaleFinanceReader($tenant_context, $connection))->receiptSummary($id);
            }

            TTransaction::close();

            if ($sale === null || $summary === null)
            {
                new TMessage('error', _t('Record not found'));

                return;
            }

            $html = \CentralVet\Presentation\SaleReceiptHtml::render(
                $sale,
                $items,
                $summary,
                static fn (string $key, string ...$params): string => _t($key, ...$params),
            );

            $dompdf = new \Dompdf\Dompdf();
            $dompdf->loadHtml($html);
            $dompdf->setPaper('A4');
            $dompdf->render();

            header('Content-Type: application/pdf');
            header('Content-Disposition: inline; filename="sale-receipt-' . $id . '.pdf"');
            echo $dompdf->output();
            exit;
        }
        catch (\CentralVet\Tenancy\Exception\MissingTenantContext $e)
        {
            TTransaction::rollback();
            new TMessage('error', _t('An authenticated session with a tenant is required'));
        }
        catch (Exception $e)
        {
            TTransaction::rollback();
            error_log(__METHOD__ . ': ' . $e->getMessage());
            new TMessage('error', CvFormat::userError($e));
        }
    }

    /**
     * Formats an integer cents amount as "R$ x,xx", matching
     * ServiceForm::toCents()'s inverse.
     */
    private static function formatCents(int $cents): string
    {
        return CvFormat::money($cents);
    }

    /**
     * Wires ProductService (T-03) from its Persistence/PDO implementation.
     * Requires an already-open TTransaction('permission') connection.
     */
    private static function buildProductService(\CentralVet\Tenancy\TenantContext $context): \CentralVet\Application\ProductService
    {
        $connection = TTransaction::get();

        $products = new \CentralVet\Persistence\ProductRepository($context, $connection);

        return new \CentralVet\Application\ProductService($products, $context);
    }

    /**
     * Wires ProcedureCatalogService (T-04) from its Persistence/PDO
     * implementation. Requires an already-open TTransaction('permission')
     * connection.
     */
    private static function buildProcedureCatalogService(\CentralVet\Tenancy\TenantContext $context): \CentralVet\Application\ProcedureCatalogService
    {
        $connection = TTransaction::get();

        $catalog = new \CentralVet\Persistence\ProcedureCatalogRepository($context, $connection);
        $inputs = new \CentralVet\Persistence\ProcedureCatalogItemInputRepository($context, $connection);

        return new \CentralVet\Application\ProcedureCatalogService($catalog, $inputs, $context);
    }

    /**
     * Wires SaleService (T-06) from its Persistence/PDO implementation.
     * Mirrors PrescriptionForm::buildPrescriptionService()/EncounterView::
     * makeEncounterService(): the real RbacAuthorizationService (Fase 0),
     * backed by AdiantiProgramPermissionProvider (reads the same
     * programs/methods session keys SystemPermission::checkPermission()
     * already uses) and PdoAuditLogWriter against this same 'permission'
     * connection, so every create() call is both unit-scope-checked and
     * audited to `audit_log`. SaleService's own ProductService/StockService/
     * ProcedureCatalogService dependencies are wired the same way, via their
     * own build*Service() helpers above, reusing the exact same connection.
     * Requires an already-open TTransaction('permission') connection.
     */
    private static function buildSaleService(\CentralVet\Tenancy\TenantContext $context): \CentralVet\Application\SaleService
    {
        $connection = TTransaction::get();

        $sales = new \CentralVet\Persistence\SaleRepository($context, $connection);
        $saleItems = new \CentralVet\Persistence\SaleItemRepository($context, $connection);

        $productService = self::buildProductService($context);
        $procedureCatalogService = self::buildProcedureCatalogService($context);

        $authorization = new \CentralVet\Authorization\RbacAuthorizationService(
            new \CentralVet\Authorization\AdiantiProgramPermissionProvider(new \CentralVet\Tenancy\AdiantiSessionContextSource()),
            new \CentralVet\Audit\PdoAuditLogWriter($connection),
        );

        $stockBatches = new \CentralVet\Persistence\StockBatchRepository($context, $connection);
        $stockMovements = new \CentralVet\Persistence\StockMovementRepository($context, $connection);
        $stockService = new \CentralVet\Application\StockService($stockBatches, $stockMovements, $authorization, $context);

        return new \CentralVet\Application\SaleService(
            $sales,
            $saleItems,
            $productService,
            $stockService,
            $procedureCatalogService,
            $authorization,
            $context,
            new \CentralVet\Persistence\TutorRepository($context, $connection),
            new \CentralVet\Persistence\PatientRepository($context, $connection),
            new \CentralVet\Persistence\EncounterRepository($context, $connection),
        );
    }

    /**
     * Wires SaleCheckoutService (T-13): SaleService (above), PaymentService
     * the same way PaymentForm::buildPaymentService() does, and the
     * receivable/cash-session/submission-key repositories, all on this same
     * 'permission' connection — the checkout requires the open
     * TTransaction (claim and FOR UPDATE refuse to run outside it).
     */
    private static function buildSaleCheckoutService(\CentralVet\Tenancy\TenantContext $context): \CentralVet\Application\SaleCheckoutService
    {
        $connection = TTransaction::get();

        $receivables = new \CentralVet\Persistence\ReceivableRepository($context, $connection);
        $cashSessions = new \CentralVet\Persistence\CashSessionRepository($context, $connection);
        $submissionKeys = new \CentralVet\Persistence\SubmissionKeyRepository($context, $connection);

        $authorization = new \CentralVet\Authorization\RbacAuthorizationService(
            new \CentralVet\Authorization\AdiantiProgramPermissionProvider(new \CentralVet\Tenancy\AdiantiSessionContextSource()),
            new \CentralVet\Audit\PdoAuditLogWriter($connection),
        );

        $financialEntries = new \CentralVet\Application\FinancialEntryService(
            new \CentralVet\Persistence\FinancialEntryRepository($context, $connection),
            $authorization,
            $context,
        );

        $payments = new \CentralVet\Application\PaymentService(
            new \CentralVet\Persistence\PaymentRepository($context, $connection),
            $receivables,
            $cashSessions,
            $financialEntries,
            $authorization,
            $context,
            $submissionKeys,
        );

        return new \CentralVet\Application\SaleCheckoutService(
            self::buildSaleService($context),
            $receivables,
            $payments,
            $cashSessions,
            $submissionKeys,
            $context,
        );
    }

    /**
     * Resolves the tenant context of the authenticated session, mirroring
     * SystemUnitForm::resolveTenantContext()/AppointmentForm::
     * resolveTenantContext()/PrescriptionForm::resolveTenantContext() (same
     * fallback for legacy sessions where TSession does not carry
     * 'tenantid' yet).
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

    /**
     * Reloads this same screen (same encounter_id/patient_id), forcing the
     * constructor to re-run and redraw the cart from the TSession draft.
     * Mirrors PrescriptionForm::reloadSelf()/EncounterView::onReload().
     */
    private function reloadSelf(): void
    {
        TScript::create(
            "__adianti_goto_page('index.php?class=SaleForm&encounter_id={$this->encounterId}"
            . "&patient_id={$this->patientId}')"
        );
    }

    /**
     * Reads a parameter from $_GET (falling back to $param), mirroring
     * PrescriptionForm::paramInt()/EncounterView::paramInt() exactly.
     */
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
}
