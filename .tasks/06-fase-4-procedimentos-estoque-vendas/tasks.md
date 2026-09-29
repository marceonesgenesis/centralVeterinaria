# Tasks

| ID | Camada | Descrição | Dependências | Paralelizável | Complexidade | Agente | Status |
|---|---|---|---|---|---|---|---|
| T-01 | database | Preparar migration de produto/estoque/procedimento/venda (8 tabelas) | — | não | alta | Athena | [x] |
| T-02 | backend | Criar os 8 contratos de Domain | T-01 | não | média | Athena | [x] |
| T-03 | backend | Produto e estoque — Domain, Repository e Application service | T-02 | sim | alta | Athena | [x] |
| T-04 | backend | Catálogo de procedimento e insumos — Domain, Repository e Application service | T-02 | sim | média | Jaspion | [x] |
| T-05 | backend | Execução de procedimento — Domain, Repository e Application service | T-03,T-04 | sim | alta | Jaspion | [x] |
| T-06 | backend | Venda/PDV — Domain, Repository e Application service | T-03,T-04 | sim | alta | Athena | [x] |
| T-07 | backend/frontend | Telas de Produto e entrada de lote | T-03 | sim | média | Aang | [x] |
| T-08 | backend/frontend | Telas de catálogo de procedimento e insumos | T-04 | sim | média | Tesla | [x] |
| T-09 | backend/frontend | Tela de execução de procedimento | T-05 | sim | média | Tesla | [x] |
| T-10 | backend/frontend | Tela de venda/PDV com recibo em PDF | T-06 | sim | média | Aang | [x] |
| T-11 | backend/frontend | Conectar EncounterView à tela de execução de procedimento | T-07,T-08,T-09,T-10 | não | média | Aang | [x] |
| T-12 | infra | Registrar as telas novas em menu.xml e RBAC nativo | T-11 | sim | média | Naruto | [x] |
| T-13 | shared | Testes unitários de estoque, execução de procedimento e venda | T-03,T-04,T-05,T-06 | sim | alta | Levi | [x] |
| T-14 | shared | Revisão de segurança e quality gate da Fase 4 | T-12,T-13 | não | alta | Levi | [x] |

## Detalhamento

### T-01 — Preparar migration de produto/estoque/procedimento/venda (8 tabelas)

**Camada:** database
**Dependências:** nenhuma
**Paralelizável:** não
**Complexidade:** alta
**Agente:** Athena

**Arquivos prováveis**
- `src/app/database/migrations/20260924_0005_phase4_procedure_stock_sale.sql`
- `src/app/database/migrations/20260924_0005_phase4_procedure_stock_sale.verify.sql`

**Interface**
- Produz: tabela `product` (id, tenant_id, name, category NULL, unit_of_measure, unit_cost_cents, minimum_stock_quantity, active, created_at, updated_at; UNIQUE tenant_id+name; FK tenant_id -> tenant)
- Produz: tabela `stock_batch` (id, tenant_id, system_unit_id, product_id, lot NULL, expiry_date NULL, quantity, received_at, created_at; FK tenant_id -> tenant, FK system_unit_id -> system_unit, FK product_id -> product; índice composto system_unit_id+product_id+expiry_date para consumo por validade)
- Produz: tabela `stock_movement` (id, tenant_id, system_unit_id, product_id, stock_batch_id, movement_type CHECK IN ('in','out','adjustment'), quantity, reason CHECK IN ('purchase_entry','procedure_consumption','sale_consumption','manual_adjustment'), reference_type NULL, reference_id NULL, professional_system_user_id, created_at; FK tenant_id -> tenant, FK system_unit_id -> system_unit, FK product_id -> product, FK stock_batch_id -> stock_batch, FK professional_system_user_id -> system_users)
- Produz: tabela `procedure_catalog_item` (id, tenant_id, name, price_cents, duration_minutes NULL, preparation_text NULL, active, created_at, updated_at; UNIQUE tenant_id+name; FK tenant_id -> tenant)
- Produz: tabela `procedure_catalog_item_input` (id, tenant_id, procedure_catalog_item_id, product_id, quantity_per_execution CHECK (>=1), created_at; UNIQUE procedure_catalog_item_id+product_id; FK tenant_id -> tenant, FK procedure_catalog_item_id -> procedure_catalog_item, FK product_id -> product)
- Produz: tabela `procedure_execution` (id, tenant_id, encounter_id, patient_id, procedure_catalog_item_id, professional_system_user_id, notes_text NULL, executed_at, created_at; FK tenant_id -> tenant, FK encounter_id -> encounter, FK patient_id -> patient, FK procedure_catalog_item_id -> procedure_catalog_item, FK professional_system_user_id -> system_users)
- Produz: tabela `sale` (id, tenant_id, system_unit_id, tutor_id, patient_id NULL, encounter_id NULL, system_user_id, status CHECK IN ('completed','cancelled') DEFAULT 'completed', total_amount_cents, sold_at, created_at; FK tenant_id -> tenant, FK system_unit_id -> system_unit, FK tutor_id -> tutor, FK patient_id -> patient, FK encounter_id -> encounter, FK system_user_id -> system_users)
- Produz: tabela `sale_item` (id, tenant_id, sale_id, item_type CHECK IN ('product','procedure'), item_reference_id, description_text, unit_price_cents, quantity CHECK (>=1), total_cents, created_at; FK tenant_id -> tenant, FK sale_id -> sale; sem FK de banco para item_reference_id, documentado como simplificação em notes.md)
- Consome: nada

**Critério de aceite**
- As 8 tabelas existem em `information_schema.tables` com as colunas e tipos acima.
- `schema_migrations` contém a linha da versão com o checksum SHA-256 real (não o placeholder).
- Um SELECT de contagem nas 8 tabelas logo após aplicar retorna 0 em todas.

**Validação**
- `docker compose exec -T mysql mysql -uroot -p"$MYSQL_ROOT_PASSWORD" centralvet -e "DESCRIBE product; DESCRIBE stock_batch; DESCRIBE stock_movement; DESCRIBE procedure_catalog_item; DESCRIBE procedure_catalog_item_input; DESCRIBE procedure_execution; DESCRIBE sale; DESCRIBE sale_item;"` (evidência: 8 saídas de DESCRIBE, uma por tabela, sem erro de tabela inexistente — só depois de aplicada; antes disso a validação é revisar manualmente a contagem de 8 `CREATE TABLE` e 6 `CHECK` no arquivo, conforme os 6 constraints declarados em Produz)

---

### T-02 — Criar os 8 contratos de Domain

**Camada:** backend
**Dependências:** T-01
**Paralelizável:** não
**Complexidade:** média
**Agente:** Athena

**Arquivos prováveis**
- `src/app/Core/Domain/Contract/ProductRepositoryInterface.php`
- `src/app/Core/Domain/Contract/StockBatchRepositoryInterface.php`
- `src/app/Core/Domain/Contract/StockMovementRepositoryInterface.php`
- `src/app/Core/Domain/Contract/ProcedureCatalogRepositoryInterface.php`
- `src/app/Core/Domain/Contract/ProcedureCatalogItemInputRepositoryInterface.php`
- `src/app/Core/Domain/Contract/ProcedureExecutionRepositoryInterface.php`
- `src/app/Core/Domain/Contract/SaleRepositoryInterface.php`
- `src/app/Core/Domain/Contract/SaleItemRepositoryInterface.php`

**Interface**
- Produz: `ProductRepositoryInterface extends TenantRepositoryInterface` com `findActive(): array`, `findByName(int $tenantId, string $name): ?Product`
- Produz: `StockBatchRepositoryInterface extends TenantRepositoryInterface` com `listByProductOrderedByExpiry(int $productId, int $systemUnitId): array`
- Produz: `StockMovementRepositoryInterface extends TenantRepositoryInterface` com `listByProduct(int $productId): array`
- Produz: `ProcedureCatalogRepositoryInterface extends TenantRepositoryInterface` com `findActive(): array`
- Produz: `ProcedureCatalogItemInputRepositoryInterface extends TenantRepositoryInterface` com `listByProcedureCatalogItem(int $procedureCatalogItemId): array`
- Produz: `ProcedureExecutionRepositoryInterface extends TenantRepositoryInterface` com `listByEncounter(int $encounterId): array`
- Produz: `SaleRepositoryInterface extends TenantRepositoryInterface` com `listByTutor(int $tutorId): array`
- Produz: `SaleItemRepositoryInterface extends TenantRepositoryInterface` com `listBySale(int $saleId): array`
- Consome: T-01 `product`
- Consome: T-01 `stock_batch`
- Consome: T-01 `stock_movement`
- Consome: T-01 `procedure_catalog_item`
- Consome: T-01 `procedure_catalog_item_input`
- Consome: T-01 `procedure_execution`
- Consome: T-01 `sale`
- Consome: T-01 `sale_item`

**Critério de aceite**
- Os 8 arquivos existem, cada um estendendo `TenantRepositoryInterface`, sem referência a classes Adianti (`TPage`, `TForm`).

**Validação**
- `docker compose exec app php -l src/app/Core/Domain/Contract/ProductRepositoryInterface.php` (evidência: `No syntax errors detected`, repetido para os 8 arquivos)

---

### T-03 — Produto e estoque — Domain, Repository e Application service

**Camada:** backend
**Dependências:** T-02
**Paralelizável:** sim
**Complexidade:** alta
**Agente:** Athena

**Arquivos prováveis**
- `src/app/Core/Domain/Product.php`
- `src/app/Core/Domain/StockBatch.php`
- `src/app/Core/Domain/StockMovement.php`
- `src/app/Core/Persistence/ProductRepository.php`
- `src/app/Core/Persistence/StockBatchRepository.php`
- `src/app/Core/Persistence/StockMovementRepository.php`
- `src/app/Core/Application/ProductService.php`
- `src/app/Core/Application/StockService.php`
- `src/app/Core/Domain/Exception/InsufficientStockException.php`

**Interface**
- Produz: `ProductService::create(int $tenantId, string $name, ?string $category, string $unitOfMeasure, int $unitCostCents, int $minimumStockQuantity): Product`
- Produz: `ProductService::listActive(int $tenantId): array`
- Produz: `StockService::receiveBatch(int $tenantId, int $systemUnitId, int $productId, ?string $lot, ?string $expiryDate, int $quantity, int $professionalSystemUserId): StockBatch`
- Produz: `StockService::consume(int $tenantId, int $systemUnitId, int $productId, int $quantity, string $reason, ?string $referenceType, ?int $referenceId, int $professionalSystemUserId): void` (consome dos lotes do produto na unidade por ordem de validade crescente; lança `InsufficientStockException` sem gravar nada se a soma dos saldos for menor que `$quantity`)
- Produz: `InsufficientStockException` (estende exceção de domínio, carrega `productId` e `shortfall`)
- Consome: T-02 `ProductRepositoryInterface`
- Consome: T-02 `StockBatchRepositoryInterface`
- Consome: T-02 `StockMovementRepositoryInterface`

**Critério de aceite**
- `StockService::consume()` com saldo total insuficiente lança `InsufficientStockException` e não grava nenhuma linha em `stock_batch`/`stock_movement`.
- `StockService::consume()` com saldo suficiente distribuído em 2+ lotes decrementa primeiro o lote de validade mais próxima até esgotá-lo, depois o seguinte, e grava uma linha de `stock_movement` por lote afetado.

**Validação**
- `docker compose exec app php -l src/app/Core/Application/StockService.php` (evidência: `No syntax errors detected`)
- Cobertura completa fica em T-13; aqui a validação é sintática e de leitura de código pelo gate da onda.

---

### T-04 — Catálogo de procedimento e insumos — Domain, Repository e Application service

**Camada:** backend
**Dependências:** T-02
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Jaspion

**Arquivos prováveis**
- `src/app/Core/Domain/ProcedureCatalogItem.php`
- `src/app/Core/Domain/ProcedureCatalogItemInput.php`
- `src/app/Core/Persistence/ProcedureCatalogRepository.php`
- `src/app/Core/Persistence/ProcedureCatalogItemInputRepository.php`
- `src/app/Core/Application/ProcedureCatalogService.php`

**Interface**
- Produz: `ProcedureCatalogService::create(int $tenantId, string $name, int $priceCents, ?int $durationMinutes, ?string $preparationText): ProcedureCatalogItem`
- Produz: `ProcedureCatalogService::listActive(int $tenantId): array`
- Produz: `ProcedureCatalogService::findById(int $id): ?ProcedureCatalogItem`
- Produz: `ProcedureCatalogService::addInput(int $procedureCatalogItemId, int $productId, int $quantityPerExecution): ProcedureCatalogItemInput`
- Produz: `ProcedureCatalogService::listInputs(int $procedureCatalogItemId): array`
- Consome: T-02 `ProcedureCatalogRepositoryInterface`
- Consome: T-02 `ProcedureCatalogItemInputRepositoryInterface`

**Critério de aceite**
- `ProcedureCatalogService::addInput()` recusa quantidade menor que 1 sem gravar (exceção de domínio, não `TMessage` — tratamento de UI é do controller).
- `ProcedureCatalogService::listInputs()` retorna os insumos na ordem em que foram cadastrados.

**Validação**
- `docker compose exec app php -l src/app/Core/Application/ProcedureCatalogService.php` (evidência: `No syntax errors detected`)

---

### T-05 — Execução de procedimento — Domain, Repository e Application service

**Camada:** backend
**Dependências:** T-03,T-04
**Paralelizável:** sim
**Complexidade:** alta
**Agente:** Jaspion

**Arquivos prováveis**
- `src/app/Core/Domain/ProcedureExecution.php`
- `src/app/Core/Persistence/ProcedureExecutionRepository.php`
- `src/app/Core/Application/ProcedureExecutionService.php`

**Interface**
- Produz: `ProcedureExecutionService::execute(int $encounterId, int $procedureCatalogItemId, int $professionalSystemUserId, ?string $notesText): ProcedureExecution` (carrega o `Encounter` de origem via `EncounterRepositoryInterface` para autorizar pela unidade REAL do encontro, não por parâmetro; carrega os insumos via `ProcedureCatalogService::listInputs()` e chama `StockService::consume()` para cada um, com `reason='procedure_consumption'`, `referenceType='procedure_execution'`; só grava a execução depois que todos os consumos de estoque foram aplicados com sucesso)
- Produz: `ProcedureExecutionService::listByEncounter(int $encounterId): array`
- Consome: T-02 `ProcedureExecutionRepositoryInterface`
- Consome: T-03 `StockService::consume(int $tenantId, int $systemUnitId, int $productId, int $quantity, string $reason, ?string $referenceType, ?int $referenceId, int $professionalSystemUserId): void`
- Consome: T-04 `ProcedureCatalogService::listInputs(int $procedureCatalogItemId): array`

**Critério de aceite**
- `execute()` nega e não grava `procedure_execution` nem `stock_movement` quando `AuthorizationPolicyInterface` recusa, usando o `resourceUnitId` do encontro real (não da sessão ativa do chamador).
- `execute()` propaga `InsufficientStockException` sem gravar `procedure_execution` quando qualquer insumo não tem saldo suficiente.

**Validação**
- `docker compose exec app php -l src/app/Core/Application/ProcedureExecutionService.php` (evidência: `No syntax errors detected`)

---

### T-06 — Venda/PDV — Domain, Repository e Application service

**Camada:** backend
**Dependências:** T-03,T-04
**Paralelizável:** sim
**Complexidade:** alta
**Agente:** Athena

**Arquivos prováveis**
- `src/app/Core/Domain/Sale.php`
- `src/app/Core/Domain/SaleItem.php`
- `src/app/Core/Persistence/SaleRepository.php`
- `src/app/Core/Persistence/SaleItemRepository.php`
- `src/app/Core/Application/SaleService.php`

**Interface**
- Produz: `SaleService::create(int $tenantId, int $systemUnitId, int $tutorId, ?int $patientId, ?int $encounterId, int $systemUserId, array $items): Sale` (cada item de `$items` é um array com `type` em `product`/`procedure`, `referenceId`, `quantity`; para `type='product'` busca preço em `ProductService`/custo em `unit_cost_cents` como referência e chama `StockService::consume()` com `reason='sale_consumption'`, `referenceType='sale'`; para `type='procedure'` busca preço em `ProcedureCatalogService::findById()` sem tocar estoque; grava `SaleItem` com `description_text`/`unit_price_cents` capturados no momento da venda; soma os `total_cents` em `Sale::totalAmountCents`)
- Produz: `SaleService::findById(int $id): ?Sale`
- Consome: T-02 `SaleRepositoryInterface`
- Consome: T-02 `SaleItemRepositoryInterface`
- Consome: T-03 `ProductService::listActive(int $tenantId): array`
- Consome: T-03 `StockService::consume(int $tenantId, int $systemUnitId, int $productId, int $quantity, string $reason, ?string $referenceType, ?int $referenceId, int $professionalSystemUserId): void`
- Consome: T-04 `ProcedureCatalogService::findById(int $id): ?ProcedureCatalogItem`

**Critério de aceite**
- `create()` nega e não grava `sale` nem `sale_item` quando `AuthorizationPolicyInterface` recusa, usando `$systemUnitId` recebido como `resourceUnitId`.
- `create()` com um item de produto sem saldo suficiente propaga `InsufficientStockException` e não grava a venda nem os itens já processados antes do item que falhou (tudo ou nada).
- `Sale::totalAmountCents` bate com a soma exata de `SaleItem::totalCents` de todos os itens gravados.

**Validação**
- `docker compose exec app php -l src/app/Core/Application/SaleService.php` (evidência: `No syntax errors detected`)

---

### T-07 — Telas de Produto e entrada de lote

**Camada:** backend/frontend
**Dependências:** T-03
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Aang

**Arquivos prováveis**
- `src/app/control/clinic/ProductForm.php`
- `src/app/control/clinic/ProductList.php`
- `src/app/control/clinic/StockBatchForm.php`
- `src/app/model/clinic/Product.php`

**Interface**
- Produz: tela `ProductForm` (TStandardForm; campos name, category, unit_of_measure, unit_cost_cents, minimum_stock_quantity, active; chama `ProductService::create`)
- Produz: tela `ProductList` (TStandardList; lista `ProductService::listActive`, ação para abrir `StockBatchForm` filtrado pelo produto)
- Produz: tela `StockBatchForm` (TStandardForm; campos product_id, lot, expiry_date, quantity; chama `StockService::receiveBatch`)
- Consome: T-03 `ProductService::create(int $tenantId, string $name, ?string $category, string $unitOfMeasure, int $unitCostCents, int $minimumStockQuantity): Product`
- Consome: T-03 `ProductService::listActive(int $tenantId): array`
- Consome: T-03 `StockService::receiveBatch(int $tenantId, int $systemUnitId, int $productId, ?string $lot, ?string $expiryDate, int $quantity, int $professionalSystemUserId): StockBatch`

**Critério de aceite**
- `ProductForm::onSave()` grava um produto novo e redireciona para `ProductList` (mesmo padrão de `PatientForm`/`ServiceForm`).
- `StockBatchForm::onSave()` com produto e quantidade válidos grava um `stock_batch` e um `stock_movement` do tipo `in`/`purchase_entry`.
- Exceções de domínio (`AuthorizationDenied`, `CrossTenantReferenceException`) são capturadas como `TMessage`, nunca erro fatal.

**Validação**
- `docker compose exec app php -l src/app/control/clinic/ProductForm.php` (evidência: `No syntax errors detected`, repetido para `ProductList.php` e `StockBatchForm.php`)

---

### T-08 — Telas de catálogo de procedimento e insumos

**Camada:** backend/frontend
**Dependências:** T-04
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Tesla

**Arquivos prováveis**
- `src/app/control/clinic/ProcedureCatalogForm.php`
- `src/app/control/clinic/ProcedureCatalogList.php`
- `src/app/control/clinic/ProcedureInputForm.php`
- `src/app/model/clinic/ProcedureCatalogItem.php`

**Interface**
- Produz: tela `ProcedureCatalogForm` (TStandardForm; campos name, price_cents, duration_minutes, preparation_text, active; chama `ProcedureCatalogService::create`)
- Produz: tela `ProcedureCatalogList` (TStandardList; lista `ProcedureCatalogService::listActive`, ação para abrir `ProcedureInputForm` filtrado pelo item de catálogo)
- Produz: tela `ProcedureInputForm` (TStandardForm; campos procedure_catalog_item_id, product_id, quantity_per_execution; chama `ProcedureCatalogService::addInput`, lista via `ProcedureCatalogService::listInputs`)
- Consome: T-04 `ProcedureCatalogService::create(int $tenantId, string $name, int $priceCents, ?int $durationMinutes, ?string $preparationText): ProcedureCatalogItem`
- Consome: T-04 `ProcedureCatalogService::listActive(int $tenantId): array`
- Consome: T-04 `ProcedureCatalogService::addInput(int $procedureCatalogItemId, int $productId, int $quantityPerExecution): ProcedureCatalogItemInput`
- Consome: T-04 `ProcedureCatalogService::listInputs(int $procedureCatalogItemId): array`
- Consome: T-03 `ProductService::listActive(int $tenantId): array`

**Critério de aceite**
- `ProcedureCatalogForm::onSave()` grava um item de catálogo novo e redireciona para `ProcedureCatalogList`.
- `ProcedureInputForm` lista os produtos disponíveis via `ProductService::listActive` no campo de seleção de insumo.
- Exceções de domínio são capturadas como `TMessage`, nunca erro fatal.

**Validação**
- `docker compose exec app php -l src/app/control/clinic/ProcedureCatalogForm.php` (evidência: `No syntax errors detected`, repetido para `ProcedureCatalogList.php` e `ProcedureInputForm.php`)

---

### T-09 — Tela de execução de procedimento

**Camada:** backend/frontend
**Dependências:** T-05
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Tesla

**Arquivos prováveis**
- `src/app/control/clinic/ProcedureExecutionForm.php`

**Interface**
- Produz: tela `ProcedureExecutionForm` (TPage; recebe `encounter_id`/`patient_id` via parâmetro de URL, como `PrescriptionForm`/`ExamRequestForm`/`VaccinationForm` da Fase 3; campos procedure_catalog_item_id, notes_text; chama `ProcedureExecutionService::execute`)
- Consome: T-05 `ProcedureExecutionService::execute(int $encounterId, int $procedureCatalogItemId, int $professionalSystemUserId, ?string $notesText): ProcedureExecution`
- Consome: T-04 `ProcedureCatalogService::listActive(int $tenantId): array`

**Critério de aceite**
- `ProcedureExecutionForm::onSave()` com saldo de estoque insuficiente mostra `TMessage` de erro e não navega para outra tela.
- `ProcedureExecutionForm::onSave()` bem-sucedido grava a execução e retorna ao `EncounterView` do encontro de origem.

**Validação**
- `docker compose exec app php -l src/app/control/clinic/ProcedureExecutionForm.php` (evidência: `No syntax errors detected`)

---

### T-10 — Tela de venda/PDV com recibo em PDF

**Camada:** backend/frontend
**Dependências:** T-06
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Aang

**Arquivos prováveis**
- `src/app/control/clinic/SaleForm.php`

**Interface**
- Produz: tela `SaleForm` (TPage; carrinho com TDataGrid de itens produto/procedimento adicionados antes de salvar; ao salvar chama `SaleService::create`; gera recibo em PDF com dompdf, mesmo padrão de geração de PDF já usado em `PrescriptionForm`, Fase 3)
- Consome: T-06 `SaleService::create(int $tenantId, int $systemUnitId, int $tutorId, ?int $patientId, ?int $encounterId, int $systemUserId, array $items): Sale`
- Consome: T-03 `ProductService::listActive(int $tenantId): array`
- Consome: T-04 `ProcedureCatalogService::listActive(int $tenantId): array`

**Critério de aceite**
- `SaleForm::onSave()` com item de produto sem saldo suficiente mostra `TMessage` de erro e não gera recibo.
- `SaleForm::onSave()` bem-sucedido grava a venda e oferece download do PDF do recibo com o total calculado.

**Validação**
- `docker compose exec app php -l src/app/control/clinic/SaleForm.php` (evidência: `No syntax errors detected`)

---

### T-11 — Conectar EncounterView à tela de execução de procedimento

**Camada:** backend/frontend
**Dependências:** T-07,T-08,T-09,T-10
**Paralelizável:** não
**Complexidade:** média
**Agente:** Aang

**Arquivos prováveis**
- `src/app/control/clinic/EncounterView.php`

**Interface**
- Produz: `EncounterView::onInlineAction()` com o branch `$kind === 'procedure'` religado para `__adianti_goto_page('index.php?class=ProcedureExecutionForm&encounter_id=...&patient_id=...')`, mesmo padrão usado em T-09 da Fase 3 para prescrição/exame/vacina; os demais branches (`prescription`, `exam`, `vaccine`, `document`, `return`) permanecem exatamente como estão
- Consome: T-09 `ProcedureExecutionForm`

**Critério de aceite**
- O branch `procedure` de `onInlineAction()` não grava mais só em `audit_log`: navega para `ProcedureExecutionForm` com `encounter_id`/`patient_id` corretos.
- Nenhum outro branch de `onInlineAction()` muda de comportamento (diff da função restrito ao branch `procedure`).

**Validação**
- `docker compose exec app php -l src/app/control/clinic/EncounterView.php` (evidência: `No syntax errors detected`)
- `diff` manual do método `onInlineAction()` antes/depois restrito às linhas do branch `procedure` (evidência: nenhuma linha fora desse branch alterada)

---

### T-12 — Registrar as telas novas em menu.xml e RBAC nativo

**Camada:** infra
**Dependências:** T-11
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Naruto

**Arquivos prováveis**
- `src/menu.xml`

**Interface**
- Produz: entradas de `menu.xml` para `ProductForm`, `ProductList`, `StockBatchForm`, `ProcedureCatalogForm`, `ProcedureCatalogList`, `ProcedureInputForm`, `ProcedureExecutionForm`, `SaleForm`
- Produz: SQL redigido (não executado pelo agente) de 8 `INSERT` em `system_program` e 8 `INSERT` em `system_group_program`, com ids calculados a partir de `MAX(id)+1` de cada tabela, consultado só por `SELECT`
- Consome: T-11 `EncounterView`

**Critério de aceite**
- `menu.xml` contém uma entrada por tela nova, sem remover nenhuma entrada existente.
- O SQL redigido cobre exatamente as 8 classes acima, nem mais nem menos.

**Validação**
- `docker compose exec app php -l src/menu.xml` não se aplica (XML); validar com `xmllint --noout src/menu.xml` (evidência: sem saída = XML válido)
- `docker compose exec -T mysql mysql -uroot -p"$MYSQL_ROOT_PASSWORD" centralvet -e "SELECT MAX(id) FROM system_program; SELECT MAX(id) FROM system_group_program;"` (evidência: os dois valores usados como base do SQL redigido)

---

### T-13 — Testes unitários de estoque, execução de procedimento e venda

**Camada:** shared
**Dependências:** T-03,T-04,T-05,T-06
**Paralelizável:** sim
**Complexidade:** alta
**Agente:** Levi

**Arquivos prováveis**
- `src/tests/Unit/StockServiceTest.php`
- `src/tests/Unit/ProcedureExecutionServiceTest.php`
- `src/tests/Unit/SaleServiceTest.php`
- `src/tests/Support/FakeProductRepository.php`
- `src/tests/Support/FakeStockBatchRepository.php`
- `src/tests/Support/FakeStockMovementRepository.php`
- `src/tests/Support/FakeProcedureCatalogRepository.php`
- `src/tests/Support/FakeProcedureExecutionRepository.php`
- `src/tests/Support/FakeSaleRepository.php`

**Interface**
- Produz: cobertura dos critérios de aceite de T-03, T-05 e T-06 (consumo por ordem de validade, recusa por saldo insuficiente sem gravação parcial, recusa de autorização pela unidade real, venda tudo-ou-nada) com fakes em memória das interfaces envolvidas, sem depender do banco real
- Consome: T-03 `StockService`
- Consome: T-05 `ProcedureExecutionService`
- Consome: T-06 `SaleService`

**Critério de aceite**
- `docker compose exec app php tests/run.php` reporta `Failed: 0` incluindo os testes novos desta task.
- Existe pelo menos um teste que prova consumo cruzando 2 lotes (o primeiro esgotado, o segundo parcialmente consumido) na ordem de validade.
- Existe pelo menos um teste que prova que `InsufficientStockException` não deixa nenhuma linha gravada nos fakes de `stock_batch`/`stock_movement`.

**Validação**
- `docker compose exec app php tests/run.php` (evidência: linha `Total: N, Passed: N, Failed: 0` com N maior que 139)

---

### T-14 — Revisão de segurança e quality gate da Fase 4

**Camada:** shared
**Dependências:** T-12,T-13
**Paralelizável:** não
**Complexidade:** alta
**Agente:** Levi

**Arquivos prováveis**
- `.tasks/06-fase-4-procedimentos-estoque-vendas/notes.md`

**Interface**
- Produz: parecer final registrado em `notes.md` cobrindo fail-closed por tenant/unidade nas novas Application services, tudo-ou-nada no consumo de estoque, ausência de módulo de compras/financeiro/cirurgia/internação fora do escopo, e confirmação de que a migration de T-01 segue não aplicada (a menos que o usuário já a tenha autorizado)
- Consome: nada formalmente (revisão consome as evidências já reportadas por T-01 a T-13)

**Critério de aceite**
- Suíte completa roda com `Failed: 0`.
- Nenhum controller novo acessa `Persistence`/`Domain` diretamente.
- `EncounterView::onInlineAction()` tem só o branch `procedure` alterado em relação à Fase 3.
- Nenhuma tabela/módulo de compras formal, financeiro completo, cirurgia ou internação foi criada fora do escopo documentado em `plan.md`.

**Validação**
- `docker compose exec app php tests/run.php` (evidência: `Failed: 0`)
- Leitura de `StockService`, `ProcedureExecutionService`, `SaleService` confirmando autorização antes de qualquer `save()`

## Legenda

- [ ] pendente
- [~] em andamento
- [x] concluído com evidência
- [!] bloqueado
