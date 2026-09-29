# Tasks

| ID | Camada | Descrição | Dependências | Paralelizável | Complexidade | Agente | Status |
|---|---|---|---|---|---|---|---|
| T-01 | database | Preparar migration de conta/recebível/caixa/pagamento/pagar/lançamento (7 tabelas) | — | não | alta | Athena | [x] |
| T-02 | backend | Criar os 7 contratos de Domain | T-01 | não | média | Athena | [x] |
| T-03 | backend | Conta do atendimento e recebível — Domain, Repository e Application service | T-02 | sim | alta | Athena | [x] |
| T-04 | backend | Sessão de caixa — Domain, Repository e Application service | T-02 | sim | média | Jaspion | [x] |
| T-05 | backend | Conta a pagar e lançamento financeiro — Domain, Repository e Application service | T-02 | sim | média | Jaspion | [x] |
| T-06 | backend | Pagamento — Domain, Repository e Application service | T-03,T-04,T-05 | não | alta | Athena | [x] |
| T-07 | backend/frontend | Tela de conta do atendimento | T-03 | sim | alta | Tesla | [x] |
| T-08 | backend/frontend | Telas de sessão de caixa | T-04 | sim | média | Aang | [x] |
| T-09 | backend/frontend | Telas de conta a pagar e lançamento financeiro | T-05 | sim | média | Aang | [x] |
| T-10 | backend/frontend | Tela de registrar pagamento | T-06,T-07,T-08 | não | alta | Tesla | [x] |
| T-11 | backend/frontend | Conectar EncounterView à conta do atendimento | T-07 | não | média | Aang | [x] |
| T-12 | infra | Registrar as telas novas em menu.xml e RBAC nativo, incluindo permissão de desconto | T-08,T-09,T-10,T-11 | sim | alta | Naruto | [x] |
| T-13 | shared | Testes unitários de conta, pagamento e caixa | T-03,T-04,T-05,T-06 | sim | alta | Levi | [x] |
| T-14 | shared | Revisão de segurança e quality gate da Fase 5 | T-12,T-13 | não | alta | Levi | [x] |

## Detalhamento

### T-01 — Preparar migration de conta/recebível/caixa/pagamento/pagar/lançamento (7 tabelas)

**Camada:** database
**Dependências:** nenhuma
**Paralelizável:** não
**Complexidade:** alta
**Agente:** Athena

**Arquivos prováveis**
- `src/app/database/migrations/20260925_0006_phase5_financial.sql`
- `src/app/database/migrations/20260925_0006_phase5_financial.verify.sql`

**Interface**
- Produz: tabela `encounter_account` (id, tenant_id, encounter_id, patient_id, tutor_id, system_unit_id, status CHECK IN ('open','closed','cancelled') DEFAULT 'open', subtotal_cents, discount_cents DEFAULT 0, discount_authorized_by_system_user_id NULL, total_cents, closed_at NULL, created_at, updated_at; UNIQUE encounter_id; CHECK discount_cents<=subtotal_cents; CHECK total_cents=subtotal_cents-discount_cents; FK tenant_id -> tenant, FK encounter_id -> encounter, FK patient_id -> patient, FK tutor_id -> tutor, FK system_unit_id -> system_unit, FK discount_authorized_by_system_user_id -> system_users)
- Produz: tabela `encounter_account_item` (id, tenant_id, account_id, source_type CHECK IN ('procedure_execution','exam_request','manual'), source_id NULL, description_text, amount_cents, created_at; UNIQUE account_id+source_type+source_id; FK tenant_id -> tenant, FK account_id -> encounter_account)
- Produz: tabela `receivable` (id, tenant_id, encounter_account_id, tutor_id, total_cents, paid_cents DEFAULT 0, status CHECK IN ('open','partially_paid','paid','cancelled') DEFAULT 'open', created_at, updated_at; UNIQUE encounter_account_id; CHECK paid_cents<=total_cents; FK tenant_id -> tenant, FK encounter_account_id -> encounter_account, FK tutor_id -> tutor)
- Produz: tabela `cash_session` (id, tenant_id, system_unit_id, opened_by_system_user_id, opening_balance_cents DEFAULT 0, closed_by_system_user_id NULL, closing_balance_cents NULL, status CHECK IN ('open','closed') DEFAULT 'open', opened_at, closed_at NULL, created_at; FK tenant_id -> tenant, FK system_unit_id -> system_unit, FK opened_by_system_user_id -> system_users, FK closed_by_system_user_id -> system_users)
- Produz: tabela `payment` (id, tenant_id, receivable_id, payment_method CHECK IN ('cash','debit_card','credit_card','pix','bank_transfer'), amount_cents CHECK (>=1), cash_session_id, system_user_id, paid_at, created_at; FK tenant_id -> tenant, FK receivable_id -> receivable, FK cash_session_id -> cash_session, FK system_user_id -> system_users)
- Produz: tabela `payable` (id, tenant_id, system_unit_id, description_text, category, amount_cents, due_date NULL, status CHECK IN ('open','paid','cancelled') DEFAULT 'open', paid_at NULL, system_user_id, created_at, updated_at; FK tenant_id -> tenant, FK system_unit_id -> system_unit, FK system_user_id -> system_users)
- Produz: tabela `financial_entry` (id, tenant_id, system_unit_id, entry_type CHECK IN ('income','expense'), category, amount_cents, reference_type NULL, reference_id NULL, occurred_at, system_user_id, created_at; FK tenant_id -> tenant, FK system_unit_id -> system_unit, FK system_user_id -> system_users)
- Consome: nada

**Critério de aceite**
- As 7 tabelas existem em `information_schema.tables` com as colunas e tipos acima.
- `schema_migrations` contém a linha da versão com o checksum SHA-256 real (não o placeholder).
- Um SELECT de contagem nas 7 tabelas logo após aplicar retorna 0 em todas.

**Validação**
- `docker compose exec -T mysql mysql -uroot -p"$MYSQL_ROOT_PASSWORD" centralvet -e "DESCRIBE encounter_account; DESCRIBE encounter_account_item; DESCRIBE receivable; DESCRIBE cash_session; DESCRIBE payment; DESCRIBE payable; DESCRIBE financial_entry;"` (evidência: 7 saídas de DESCRIBE, uma por tabela, sem erro de tabela inexistente — só depois de aplicada; antes disso a validação é revisar manualmente a contagem de 7 `CREATE TABLE` e as `CHECK` declaradas em Produz no arquivo)

---

### T-02 — Criar os 7 contratos de Domain

**Camada:** backend
**Dependências:** T-01
**Paralelizável:** não
**Complexidade:** média
**Agente:** Athena

**Arquivos prováveis**
- `src/app/Core/Domain/Contract/EncounterAccountRepositoryInterface.php`
- `src/app/Core/Domain/Contract/EncounterAccountItemRepositoryInterface.php`
- `src/app/Core/Domain/Contract/ReceivableRepositoryInterface.php`
- `src/app/Core/Domain/Contract/CashSessionRepositoryInterface.php`
- `src/app/Core/Domain/Contract/PaymentRepositoryInterface.php`
- `src/app/Core/Domain/Contract/PayableRepositoryInterface.php`
- `src/app/Core/Domain/Contract/FinancialEntryRepositoryInterface.php`

**Interface**
- Produz: `EncounterAccountRepositoryInterface extends TenantRepositoryInterface` com `findByEncounterId(int $encounterId): ?object`
- Produz: `EncounterAccountItemRepositoryInterface extends TenantRepositoryInterface` com `listByAccount(int $accountId): array`
- Produz: `ReceivableRepositoryInterface extends TenantRepositoryInterface` com `findByEncounterAccountId(int $accountId): ?object`
- Produz: `CashSessionRepositoryInterface extends TenantRepositoryInterface` com `findOpenBySystemUnit(int $systemUnitId): ?object`
- Produz: `PaymentRepositoryInterface extends TenantRepositoryInterface` com `listByReceivable(int $receivableId): array`, `listByCashSession(int $cashSessionId): array`
- Produz: `PayableRepositoryInterface extends TenantRepositoryInterface` com `listOpenBySystemUnit(int $systemUnitId): array`
- Produz: `FinancialEntryRepositoryInterface extends TenantRepositoryInterface` com `listBySystemUnitAndPeriod(int $systemUnitId, string $from, string $to): array`
- Consome: T-01 `encounter_account`
- Consome: T-01 `encounter_account_item`
- Consome: T-01 `receivable`
- Consome: T-01 `cash_session`
- Consome: T-01 `payment`
- Consome: T-01 `payable`
- Consome: T-01 `financial_entry`

**Critério de aceite**
- Os 7 arquivos existem, cada um estendendo `TenantRepositoryInterface`, sem referência a classes Adianti (`TPage`, `TForm`).

**Validação**
- `docker compose exec app php -l src/app/Core/Domain/Contract/EncounterAccountRepositoryInterface.php` (evidência: `No syntax errors detected`, repetido para os 7 arquivos)

---

### T-03 — Conta do atendimento e recebível — Domain, Repository e Application service

**Camada:** backend
**Dependências:** T-02
**Paralelizável:** sim
**Complexidade:** alta
**Agente:** Athena

**Arquivos prováveis**
- `src/app/Core/Domain/EncounterAccount.php`
- `src/app/Core/Domain/EncounterAccountItem.php`
- `src/app/Core/Domain/Receivable.php`
- `src/app/Core/Persistence/EncounterAccountRepository.php`
- `src/app/Core/Persistence/EncounterAccountItemRepository.php`
- `src/app/Core/Persistence/ReceivableRepository.php`
- `src/app/Core/Application/EncounterAccountService.php`
- `src/app/Core/Domain/Exception/DiscountExceedsSubtotalException.php`

**Interface**
- Produz: `EncounterAccountService::openOrGet(int $encounterId, string $action): EncounterAccount` (autoriza pela unidade REAL do encontro, lida via `EncounterRepositoryInterface`; cria a conta em `status='open'` se ainda não existir para o encontro, ou retorna a existente — idempotente)
- Produz: `EncounterAccountService::syncAutomaticItems(int $accountId, string $action): array` (busca `procedure_execution`/`exam_request` do encontro da conta ainda não presentes em `encounter_account_item` — checagem por `source_type`+`source_id` já existente — e adiciona um item por cada um encontrado, com `amount_cents` vindo do preço do catálogo correspondente; idempotente, chamar de novo não duplica; retorna só os itens novos adicionados nesta chamada)
- Produz: `EncounterAccountService::addManualItem(int $accountId, string $descriptionText, int $amountCents, string $action): EncounterAccountItem`
- Produz: `EncounterAccountService::applyDiscount(int $accountId, int $discountCents, int $authorizedBySystemUserId, string $action): EncounterAccount` (autorização PRÓPRIA e distinta da autorização padrão da conta — ação/permissão específica de desconto; lança `DiscountExceedsSubtotalException` sem gravar se `discountCents` > soma dos itens)
- Produz: `EncounterAccountService::close(int $accountId, string $action): Receivable` (recalcula subtotal a partir dos itens, aplica o desconto já registrado, grava `total_cents`, muda status para `closed`, cria e retorna o `Receivable` correspondente com o mesmo total)
- Consome: T-02 `EncounterAccountRepositoryInterface`
- Consome: T-02 `EncounterAccountItemRepositoryInterface`
- Consome: T-02 `ReceivableRepositoryInterface`

**Critério de aceite**
- `syncAutomaticItems()` chamado duas vezes seguidas para o mesmo encontro não duplica nenhum item (segunda chamada retorna lista vazia se nada novo aconteceu).
- `applyDiscount()` com valor maior que o subtotal atual lança `DiscountExceedsSubtotalException` e não altera `discount_cents`/`total_cents` da conta.
- `close()` produz `Receivable::totalCents` igual a `EncounterAccount::totalCents` (subtotal menos desconto), nunca recalculado de forma diferente.

**Validação**
- `docker compose exec app php -l src/app/Core/Application/EncounterAccountService.php` (evidência: `No syntax errors detected`)
- Cobertura completa fica em T-13; aqui a validação é sintática e de leitura de código pelo gate da onda.

---

### T-04 — Sessão de caixa — Domain, Repository e Application service

**Camada:** backend
**Dependências:** T-02
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Jaspion

**Arquivos prováveis**
- `src/app/Core/Domain/CashSession.php`
- `src/app/Core/Persistence/CashSessionRepository.php`
- `src/app/Core/Application/CashSessionService.php`
- `src/app/Core/Domain/Exception/CashSessionAlreadyOpenException.php`

**Interface**
- Produz: `CashSessionService::open(int $systemUnitId, int $openingBalanceCents, int $openedBySystemUserId, string $action): CashSession` (autoriza pela unidade recebida; lança `CashSessionAlreadyOpenException` sem gravar se `findOpenBySystemUnit()` já retornar uma sessão aberta para a unidade)
- Produz: `CashSessionService::close(int $cashSessionId, int $closingBalanceCents, int $closedBySystemUserId, string $action): CashSession` (só permite fechar sessão em `status='open'`; muda para `closed`, grava `closing_balance_cents`/`closed_at`)
- Produz: `CashSessionService::totalsByPaymentMethod(int $cashSessionId): array` (soma `payment.amount_cents` da sessão agrupado por `payment_method`, para a tela de fechamento)
- Consome: T-02 `CashSessionRepositoryInterface`

**Critério de aceite**
- `open()` para uma unidade que já tem sessão `status='open'` lança `CashSessionAlreadyOpenException` e não grava uma segunda linha.
- `close()` em uma sessão já `status='closed'` lança exceção de domínio sem sobrescrever `closing_balance_cents`.

**Validação**
- `docker compose exec app php -l src/app/Core/Application/CashSessionService.php` (evidência: `No syntax errors detected`)

---

### T-05 — Conta a pagar e lançamento financeiro — Domain, Repository e Application service

**Camada:** backend
**Dependências:** T-02
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Jaspion

**Arquivos prováveis**
- `src/app/Core/Domain/Payable.php`
- `src/app/Core/Domain/FinancialEntry.php`
- `src/app/Core/Persistence/PayableRepository.php`
- `src/app/Core/Persistence/FinancialEntryRepository.php`
- `src/app/Core/Application/PayableService.php`
- `src/app/Core/Application/FinancialEntryService.php`

**Interface**
- Produz: `FinancialEntryService::record(int $systemUnitId, string $entryType, string $category, int $amountCents, ?string $referenceType, ?int $referenceId, int $systemUserId, string $action): FinancialEntry` (autoriza pela unidade recebida; `entryType` restrito a 'income'/'expense')
- Produz: `PayableService::create(int $systemUnitId, string $descriptionText, string $category, int $amountCents, ?string $dueDate, int $systemUserId, string $action): Payable`
- Produz: `PayableService::pay(int $payableId, int $systemUserId, string $action): Payable` (só permite pagar `payable` em `status='open'`; muda para `paid`, grava `paid_at`, chama `FinancialEntryService::record()` internamente com `entryType='expense'`, `referenceType='payable'`, `referenceId=$payableId`)
- Produz: `PayableService::listOpen(int $systemUnitId): array`
- Consome: T-02 `PayableRepositoryInterface`
- Consome: T-02 `FinancialEntryRepositoryInterface`

**Critério de aceite**
- `PayableService::pay()` em uma `payable` já `status='paid'` lança exceção de domínio sem gravar um segundo `financial_entry`.
- `PayableService::pay()` bem-sucedido grava exatamente um `financial_entry` com `entry_type='expense'` referenciando o `payable_id`.

**Validação**
- `docker compose exec app php -l src/app/Core/Application/PayableService.php` (evidência: `No syntax errors detected`, repetido para `FinancialEntryService.php`)

---

### T-06 — Pagamento — Domain, Repository e Application service

**Camada:** backend
**Dependências:** T-03,T-04,T-05
**Paralelizável:** não
**Complexidade:** alta
**Agente:** Athena

**Arquivos prováveis**
- `src/app/Core/Domain/Payment.php`
- `src/app/Core/Persistence/PaymentRepository.php`
- `src/app/Core/Application/PaymentService.php`
- `src/app/Core/Domain/Exception/OverpaymentException.php`

**Interface**
- Produz: `PaymentService::register(int $receivableId, int $cashSessionId, string $paymentMethod, int $amountCents, int $systemUserId, string $action): Payment` (carrega o `Receivable` e a `CashSession` reais; autoriza pela unidade da `CashSession` (`system_unit_id`), não por parâmetro do chamador; recusa se `cash_session.status != 'open'`; lança `OverpaymentException` sem gravar se `receivable.paidCents + amountCents > receivable.totalCents`; grava o `Payment`, incrementa `receivable.paidCents`, atualiza `receivable.status` para `'paid'` quando `paidCents == totalCents` ou `'partially_paid'` quando menor; chama `FinancialEntryService::record()` internamente com `entryType='income'`, `referenceType='payment'`)
- Consome: T-02 `ReceivableRepositoryInterface`
- Consome: T-02 `CashSessionRepositoryInterface`
- Consome: T-03 `Receivable`
- Consome: T-04 `CashSession`
- Consome: T-05 `FinancialEntryService::record(int $systemUnitId, string $entryType, string $category, int $amountCents, ?string $referenceType, ?int $referenceId, int $systemUserId, string $action): FinancialEntry`

**Critério de aceite**
- `register()` com `amountCents` que ultrapassa o saldo devido do recebível lança `OverpaymentException` e não grava `payment` nem altera `receivable.paidCents`.
- `register()` bem-sucedido, somado a pagamentos anteriores, muda `receivable.status` para `'paid'` exatamente quando `paidCents` iguala `totalCents`, e para `'partially_paid'` quando menor — nunca `'paid'` com saldo residual.
- `register()` contra uma `cash_session` já fechada lança exceção de domínio sem gravar nada.

**Validação**
- `docker compose exec app php -l src/app/Core/Application/PaymentService.php` (evidência: `No syntax errors detected`)

---

### T-07 — Tela de conta do atendimento

**Camada:** backend/frontend
**Dependências:** T-03
**Paralelizável:** sim
**Complexidade:** alta
**Agente:** Tesla

**Arquivos prováveis**
- `src/app/control/clinic/EncounterAccountForm.php`
- `src/app/model/clinic/EncounterAccount.php`

**Interface**
- Produz: tela `EncounterAccountForm` (TPage; recebe `encounter_id` via parâmetro de URL, mesmo padrão de `ProcedureExecutionForm`/`PrescriptionForm` da Fase 3/4; chama `EncounterAccountService::openOrGet()` ao carregar, depois `syncAutomaticItems()`; lista os itens em `TDataGrid`; botão "Adicionar item manual" chama `addManualItem()`; campo de desconto com botão próprio chama `applyDiscount()`; botão "Fechar conta" chama `close()` e mostra o `Receivable` gerado)
- Consome: T-03 `EncounterAccountService::openOrGet(int $encounterId, string $action): EncounterAccount`
- Consome: T-03 `EncounterAccountService::syncAutomaticItems(int $accountId, string $action): array`
- Consome: T-03 `EncounterAccountService::addManualItem(int $accountId, string $descriptionText, int $amountCents, string $action): EncounterAccountItem`
- Consome: T-03 `EncounterAccountService::applyDiscount(int $accountId, int $discountCents, int $authorizedBySystemUserId, string $action): EncounterAccount`
- Consome: T-03 `EncounterAccountService::close(int $accountId, string $action): Receivable`

**Critério de aceite**
- Abrir a tela para um encontro sem conta ainda cria uma `encounter_account` em `status='open'` e mostra os itens automáticos já sincronizados.
- Clicar em "Fechar conta" com `DiscountExceedsSubtotalException`/`AuthorizationDenied` mostra `TMessage` de erro e mantém a conta aberta.
- Fechar com sucesso mostra o total do `Receivable` gerado.

**Validação**
- `docker compose exec app php -l src/app/control/clinic/EncounterAccountForm.php` (evidência: `No syntax errors detected`)

---

### T-08 — Telas de sessão de caixa

**Camada:** backend/frontend
**Dependências:** T-04
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Aang

**Arquivos prováveis**
- `src/app/control/clinic/CashSessionForm.php`
- `src/app/control/clinic/CashSessionList.php`
- `src/app/model/clinic/CashSession.php`

**Interface**
- Produz: tela `CashSessionForm` (TPage; sem sessão aberta na unidade ativa, mostra campo de saldo inicial e botão "Abrir caixa" chamando `CashSessionService::open()`; com sessão aberta, mostra os totais por forma de pagamento via `CashSessionService::totalsByPaymentMethod()` e campo de saldo final com botão "Fechar caixa" chamando `CashSessionService::close()`)
- Produz: tela `CashSessionList` (TStandardList; histórico de sessões da unidade)
- Consome: T-04 `CashSessionService::open(int $systemUnitId, int $openingBalanceCents, int $openedBySystemUserId, string $action): CashSession`
- Consome: T-04 `CashSessionService::close(int $cashSessionId, int $closingBalanceCents, int $closedBySystemUserId, string $action): CashSession`
- Consome: T-04 `CashSessionService::totalsByPaymentMethod(int $cashSessionId): array`

**Critério de aceite**
- `CashSessionForm::onOpen()` com uma sessão já aberta na unidade mostra `TMessage` de erro (`CashSessionAlreadyOpenException`) sem criar uma segunda sessão.
- `CashSessionForm::onClose()` bem-sucedido muda o formulário para o estado "sem sessão aberta" (permitindo abrir uma nova).

**Validação**
- `docker compose exec app php -l src/app/control/clinic/CashSessionForm.php` (evidência: `No syntax errors detected`, repetido para `CashSessionList.php`)

---

### T-09 — Telas de conta a pagar e lançamento financeiro

**Camada:** backend/frontend
**Dependências:** T-05
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Aang

**Arquivos prováveis**
- `src/app/control/clinic/PayableForm.php`
- `src/app/control/clinic/PayableList.php`
- `src/app/control/clinic/FinancialEntryForm.php`
- `src/app/control/clinic/FinancialEntryList.php`
- `src/app/model/clinic/Payable.php`

**Interface**
- Produz: tela `PayableForm` (TStandardForm; campos description_text, category, amount_cents, due_date; chama `PayableService::create`)
- Produz: tela `PayableList` (TStandardList; lista `PayableService::listOpen`, ação de linha "Pagar" chamando `PayableService::pay`)
- Produz: tela `FinancialEntryForm` (TStandardForm; campos entry_type, category, amount_cents; chama `FinancialEntryService::record` com `referenceType=null`)
- Produz: tela `FinancialEntryList` (TStandardList; lista `FinancialEntryRepositoryInterface::listBySystemUnitAndPeriod` via um service passthrough, ou diretamente por um método já exposto em `FinancialEntryService`)
- Consome: T-05 `PayableService::create(int $systemUnitId, string $descriptionText, string $category, int $amountCents, ?string $dueDate, int $systemUserId, string $action): Payable`
- Consome: T-05 `PayableService::pay(int $payableId, int $systemUserId, string $action): Payable`
- Consome: T-05 `PayableService::listOpen(int $systemUnitId): array`
- Consome: T-05 `FinancialEntryService::record(int $systemUnitId, string $entryType, string $category, int $amountCents, ?string $referenceType, ?int $referenceId, int $systemUserId, string $action): FinancialEntry`

**Critério de aceite**
- `PayableList` ação "Pagar" numa conta já paga mostra `TMessage` de erro sem gerar um segundo lançamento.
- `FinancialEntryForm::onSave()` grava um lançamento novo e redireciona para `FinancialEntryList`.

**Validação**
- `docker compose exec app php -l src/app/control/clinic/PayableForm.php` (evidência: `No syntax errors detected`, repetido para `PayableList.php`, `FinancialEntryForm.php`, `FinancialEntryList.php`)

---

### T-10 — Tela de registrar pagamento

**Camada:** backend/frontend
**Dependências:** T-06,T-07,T-08
**Paralelizável:** não
**Complexidade:** alta
**Agente:** Tesla

**Arquivos prováveis**
- `src/app/control/clinic/PaymentForm.php`

**Interface**
- Produz: tela `PaymentForm` (TPage; recebe `receivable_id` via parâmetro de URL a partir de `EncounterAccountForm` depois de fechar a conta; mostra saldo devido; exige uma `cash_session` aberta da unidade ativa (busca via `CashSessionRepositoryInterface::findOpenBySystemUnit`, se não houver mostra aviso e não permite registrar); campos payment_method, amount_cents; chama `PaymentService::register`)
- Consome: T-06 `PaymentService::register(int $receivableId, int $cashSessionId, string $paymentMethod, int $amountCents, int $systemUserId, string $action): Payment`
- Consome: T-02 `findOpenBySystemUnit(int $systemUnitId): ?object`

**Critério de aceite**
- Sem sessão de caixa aberta na unidade, a tela mostra aviso e o botão de registrar pagamento fica indisponível (não chama `PaymentService::register`).
- `onSave()` com `OverpaymentException` mostra `TMessage` de erro sem navegar.
- Pagamento bem-sucedido mostra o novo saldo devido do recebível.

**Validação**
- `docker compose exec app php -l src/app/control/clinic/PaymentForm.php` (evidência: `No syntax errors detected`)

---

### T-11 — Conectar EncounterView à conta do atendimento

**Camada:** backend/frontend
**Dependências:** T-07
**Paralelizável:** não
**Complexidade:** média
**Agente:** Aang

**Arquivos prováveis**
- `src/app/control/clinic/EncounterView.php`

**Interface**
- Produz: um ponto de acesso em `EncounterView.php` para `EncounterAccountForm` a partir do encontro aberto (mesmo mecanismo já usado nas Fases 3/4 para religar ações inline — se houver uma entrada livre equivalente ao padrão `$kinds`/`inlineActionsPanel()` usado nas fases anteriores, adicionar uma nova entrada lá; caso o mecanismo real exigido seja outro, aplicar o padrão real do arquivo, documentando a decisão em `notes.md`); navega para `index.php?class=EncounterAccountForm&encounter_id=...`
- Consome: T-07 `EncounterAccountForm`

**Critério de aceite**
- Existe um caminho de navegação do `EncounterView` de um encontro aberto para `EncounterAccountForm` com o `encounter_id` correto.
- Nenhum outro comportamento de `EncounterView.php` muda (diff restrito ao ponto de acesso novo).

**Validação**
- `docker compose exec app php -l src/app/control/clinic/EncounterView.php` (evidência: `No syntax errors detected`)
- `diff` manual do arquivo antes/depois restrito às linhas do ponto de acesso novo (evidência: nenhuma linha fora dele alterada)

---

### T-12 — Registrar as telas novas em menu.xml e RBAC nativo, incluindo permissão de desconto

**Camada:** infra
**Dependências:** T-08,T-09,T-10,T-11
**Paralelizável:** sim
**Complexidade:** alta
**Agente:** Naruto

**Arquivos prováveis**
- `src/menu.xml`

**Interface**
- Produz: entradas de `menu.xml` para `EncounterAccountForm`, `CashSessionForm`, `CashSessionList`, `PayableForm`, `PayableList`, `FinancialEntryForm`, `FinancialEntryList`, `PaymentForm`
- Produz: SQL redigido (não executado pelo agente) dos `INSERT` em `system_program`/`system_group_program` para as 8 telas, com ids calculados a partir de `MAX(id)+1` de cada tabela, consultado só por `SELECT`
- Produz: investigação registrada em `notes.md` de como o RBAC nativo do Adianti (`SystemUser::getMethods()`/`SystemPermission::checkPermission()`) expressa permissão por método, e o SQL redigido (não executado) da entrada específica que restringe `EncounterAccountService::applyDiscount()` — se o mecanismo nativo não suportar granularidade por método sem mudança maior de infraestrutura, documentar isso como constatação e propor a alternativa mais simples (ex.: checagem adicional própria dentro de `AuthorizationPolicyInterface`/`AdiantiProgramPermissionProvider`, sem inventar tabela nova)
- Consome: T-11 `EncounterAccountForm` (ponto de acesso)

**Critério de aceite**
- `menu.xml` contém uma entrada por tela nova, sem remover nenhuma entrada existente.
- O SQL redigido cobre exatamente as 8 classes acima, nem mais nem menos.
- A investigação da permissão de desconto está registrada em `notes.md` com uma conclusão clara (suportado nativamente com o SQL redigido, ou não suportado com a alternativa proposta).

**Validação**
- `xmllint --noout src/menu.xml` ou `docker compose exec app php -r '$d=new DOMDocument(); var_dump($d->load("/var/www/html/src/menu.xml"));'` (evidência: `bool(true)`/sem saída de erro)
- `docker compose exec -T mysql mysql -uroot -p"$MYSQL_ROOT_PASSWORD" centralvet -e "SELECT MAX(id) FROM system_program; SELECT MAX(id) FROM system_group_program;"` (evidência: os dois valores usados como base do SQL redigido)

---

### T-13 — Testes unitários de conta, pagamento e caixa

**Camada:** shared
**Dependências:** T-03,T-04,T-05,T-06
**Paralelizável:** sim
**Complexidade:** alta
**Agente:** Levi

**Arquivos prováveis**
- `src/tests/Unit/EncounterAccountServiceTest.php`
- `src/tests/Unit/PaymentServiceTest.php`
- `src/tests/Unit/CashSessionServiceTest.php`
- `src/tests/Support/FakeEncounterAccountRepository.php`
- `src/tests/Support/FakeEncounterAccountItemRepository.php`
- `src/tests/Support/FakeReceivableRepository.php`
- `src/tests/Support/FakeCashSessionRepository.php`
- `src/tests/Support/FakePaymentRepository.php`
- `src/tests/Support/FakeFinancialEntryRepository.php`

**Interface**
- Produz: cobertura dos critérios de aceite de T-03, T-04 e T-06 (`syncAutomaticItems()` idempotente, `applyDiscount()` recusa acima do subtotal, `close()` gera `Receivable` com total correto, `CashSessionService::open()` recusa segunda sessão aberta na mesma unidade, `PaymentService::register()` recusa sobre-pagamento sem gravar, transições de status do recebível corretas em pagamento parcial e total) com fakes em memória das interfaces envolvidas, sem depender do banco real
- Consome: T-03 `EncounterAccountService`
- Consome: T-04 `CashSessionService`
- Consome: T-06 `PaymentService`

**Critério de aceite**
- `docker compose exec app php tests/run.php` reporta `Failed: 0` incluindo os testes novos desta task.
- Existe pelo menos um teste com dois pagamentos parciais em sequência: após o primeiro, `receivable.status` é `'partially_paid'` com `paidCents` igual à soma paga até ali; após o segundo, que completa o total, `receivable.status` é `'paid'` com `paidCents` igual a `totalCents`.

**Validação**
- `docker compose exec app php tests/run.php` (evidência: linha `Total: N, Passed: N, Failed: 0` com N maior que 146)

---

### T-14 — Revisão de segurança e quality gate da Fase 5

**Camada:** shared
**Dependências:** T-12,T-13
**Paralelizável:** não
**Complexidade:** alta
**Agente:** Levi

**Arquivos prováveis**
- `.tasks/07-fase-5-financeiro-integrado/notes.md`

**Interface**
- Produz: parecer final registrado em `notes.md` cobrindo fail-closed por tenant/unidade em todas as Application services novas (incluindo operações de escrita simples como `CashSessionService::open()`/`PayableService::create()`, lição explícita da revisão final da Fase 4), conferência de que `sale`/`sale_item` (Fase 4) não foram alteradas, e confirmação de que a migration de T-01 segue não aplicada (a menos que o usuário já a tenha autorizado)
- Consome: nada formalmente (revisão consome as evidências já reportadas por T-01 a T-13)

**Critério de aceite**
- Nenhum bloqueante em aberto; toda pendência conhecida é listada explicitamente em `notes.md`.

**Validação**
- `docker compose exec app php tests/run.php` → `Failed: 0`
- Leitura de `EncounterAccountService`, `CashSessionService`, `PayableService`, `PaymentService` confirmando autorização antes de qualquer `save()`, incluindo as operações mais simples

## Legenda

- [ ] pendente
- [~] em andamento
- [x] concluído com evidência
- [!] bloqueado
