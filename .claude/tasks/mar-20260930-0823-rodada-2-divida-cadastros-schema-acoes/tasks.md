# Tasks

| ID | Camada | Descrição | Dependências | Paralelizável | Complexidade | Agente | Status |
|---|---|---|---|---|---|---|---|
| T-01 | database | Migration 0007 (+ verify) e DML de programas/grupos | — | sim | média | Jaspion | [x] |
| T-02 | backend | AuthorizationRequest escapa `$action`; docblock de applyDiscount | — | sim | simples | Arquimedes | [x] |
| T-03 | backend | Readers: limite ≤ 0, `overview()` com uma varredura, atendimento anterior | — | sim | média | Sherlock | [x] |
| T-04 | frontend | Casca: 403 x 500 em onSwitchUnit, fallback de rótulos, ajuda "Em breve", CvPage `target` | — | sim | média | Aang | [x] |
| T-05 | frontend | CvCatalog único nos combos; comentários/docblocks de PendingExamResultList e PayableList | — | sim | simples | Levi | [x] |
| T-06 | backend | Editar tutor: TutorService::update + TutorForm | — | sim | média | Thanos | [x] |
| T-07 | backend | Editar paciente: PatientService::update + PatientForm | — | sim | média | Naruto | [x] |
| T-08 | backend | Editar agendamento: AppointmentService::reschedule + AppointmentForm | — | sim | alta | Kratos | [x] |
| T-09 | backend | ServiceCatalogService: create com active, duplicate, delete, importCsv | — | sim | alta | Platão | [x] |
| T-10 | frontend | ServiceList Importar/Duplicar/Excluir, ServiceImportForm, ServiceForm em um write | T-01, T-09 | sim | média | Levi | [x] |
| T-11 | backend | Produto: preço de venda e código (Core + ProductForm) e testes de outro tenant | T-01 | sim | alta | Darwin | [x] |
| T-12 | backend | Paciente: alergia e foto (Core + PatientForm + onPhoto) | T-01, T-07 | sim | alta | Tesla | [x] |
| T-13 | backend | Prescrição: validade e modelos (Core) | T-01 | sim | alta | Platão | [x] |
| T-14 | backend | Forma de pagamento no lançamento (Core + FinancialEntryForm/List) | T-01 | sim | média | Arquimedes | [x] |
| T-15 | backend | Conta bancária com saldo (Core) | T-01 | sim | alta | Athena | [x] |
| T-16 | backend | Pausa de atendimento (Core) | T-01 | sim | média | Yoda | [x] |
| T-17 | frontend | Fila: Editar paciente e Editar agendamento | T-07, T-08 | sim | simples | Aang | [x] |
| T-18 | frontend | EncounterView: Pausar/Retomar, alergia/foto, onInlineAction, vazio após Finalizar | T-03, T-12, T-16 | sim | alta | Yoda | [x] |
| T-19 | frontend | PrescriptionForm: validade, Salvar como modelo, Aplicar modelo, estilos, onEdit | T-13 | sim | alta | Kratos | [x] |
| T-20 | frontend | FinancialOverview: saldo bancário, Exportar CSV, recentes no período, "vs. período anterior" | T-04, T-14, T-15 | sim | alta | Tesla | [x] |
| T-21 | frontend | ProductList: constantes, overview(), preço/código, Gerar relatório PDF | T-03, T-04, T-11 | sim | média | Darwin | [x] |
| T-22 | frontend | BankAccountList/BankAccountForm e aba no CvNav | T-01, T-15 | sim | média | Athena | [x] |
| T-23 | frontend | i18n do board e mensagem de CrossTenantReferenceException nos 14 controllers | T-02, T-04, T-05, T-06, T-07, T-08, T-10, T-11, T-12, T-17, T-18, T-19, T-20, T-21, T-22 | não | média | Platão | [x] |
| T-24 | qa | Validação final: suíte, varredura Playwright, Review Focus, dados preservados | T-23 | não | média | Spock | [ ] |

## Convenções (valem para todas as tasks)
- LINT, SUITE e GATE: definidos em `plan.md § Premissas`. "SUITE verde" = `Failed: 0` com `PASS` em todos os métodos da classe de teste citada.
- Texto novo: `_t('<chave en>')`. Chave ausente em `translations.json` → linha `- [T-xx] i18n: <chave en> → <texto pt>` no board (T-23 grava).
- `$action` dos serviços: `__CLASS__ . '::' . __FUNCTION__` no controller (formato `Classe::metodo` de `AuthorizationRequest::ACTION_PATTERN`).
- Parâmetro novo em entidade/serviço vai **por último e com default**, sem quebrar os chamadores existentes.
- Id inexistente ou de outro tenant: o repositório tenant-aware devolve `null`, e o serviço lança `InvalidArgumentException("<Entidade> {$id} not found for this tenant")` (mensagem exata por task).
- `setNumericMask(0, …)` exige `setProperty('pattern', '[0-9]*')` no campo (convenção PATTERN0 da fase 10).

## Detalhamento

### T-01 — Migration 0007 (+ verify) e DML de programas/grupos

**Camada:** database
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Jaspion

Só redige arquivos. Não executa DDL nem DML (skill `sql-write-approval`); quem aplica é o orquestrador, entre as ondas 1 e 2, depois da aprovação do usuário. Siga `src/app/database/migrations/README.md`, `docs/runbooks/migrations.md` e o formato de `20260925_0006_phase5_financial.sql`: cabeçalho `PREPARED ONLY`, `Target`, `Effects`, `Risk`, `Rollback` (backup pré-migration; migration reversa 0008 se preciso) e, no fim, `INSERT INTO schema_migrations (version, checksum)` com placeholder de checksum.

**Arquivos prováveis**
- `src/app/database/migrations/20260930_0007_rodada2_cadastros_financeiro.sql`
- `src/app/database/migrations/20260930_0007_rodada2_cadastros_financeiro.verify.sql`
- `.claude/tasks/mar-20260930-0823-rodada-2-divida-cadastros-schema-acoes/sql/T-01-programs.sql`

**Interface**
- Produz: `20260930_0007_rodada2_cadastros_financeiro` (version em `schema_migrations`) com exatamente: `product.sale_price_cents` (`int unsigned NULL`, depois de `unit_cost_cents`) e `product.code` (`varchar(60) NULL`) + `UNIQUE KEY product_tenant_code_uq (tenant_id, code)`; `patient.allergies` (`text NULL`), `patient.photo_object_key` (`varchar(512) NULL`), `patient.photo_content_type` (`varchar(100) NULL`); `prescription.valid_until` (`date NULL`); tabela `prescription_template` (`id` bigint PK, `tenant_id` FK tenant, `name varchar(190) NOT NULL`, `orientation_text text NULL`, `created_by_system_user_id int NOT NULL` FK `system_users`, `created_at`, `updated_at`, `UNIQUE KEY prescription_template_tenant_name_uq (tenant_id, name)`); tabela `prescription_template_item` (`id` bigint PK, `tenant_id` FK tenant, `template_id` FK `prescription_template` ON DELETE CASCADE, `position smallint unsigned NOT NULL`, `medication_name varchar(190)`, `dose varchar(40)`, `dose_unit varchar(20)`, `route varchar(40)`, `frequency varchar(60)`, `duration varchar(60)`, todos NOT NULL, `created_at`); `financial_entry.payment_method` (`varchar(20) NULL`) + `CONSTRAINT financial_entry_payment_method_ck CHECK (payment_method IS NULL OR payment_method IN ('cash','debit_card','credit_card','pix','bank_transfer'))` + backfill `UPDATE financial_entry SET payment_method = category WHERE reference_type = 'payment' AND category IN ('cash','debit_card','credit_card','pix','bank_transfer')`; tabela `bank_account` (`id` bigint PK, `tenant_id` FK tenant, `system_unit_id int NOT NULL` FK `system_unit`, `name varchar(120) NOT NULL`, `bank_name varchar(120) NULL`, `balance_cents bigint NOT NULL DEFAULT 0` com sinal, `balance_updated_at timestamp(6) NULL`, `active tinyint(1) NOT NULL DEFAULT 1`, `created_at`, `updated_at`, `UNIQUE KEY bank_account_tenant_unit_name_uq (tenant_id, system_unit_id, name)`); `encounter.paused_at` (`timestamp(6) NULL`) e `encounter.paused_seconds` (`int unsigned NOT NULL DEFAULT 0`).
- Produz: `sql/T-01-programs.sql` com: `system_program` para `BankAccountList`, `BankAccountForm` e `ServiceImportForm` (nomes "Contas bancárias", "Conta bancária", "Importar serviços"; ids a partir do `SELECT MAX(id) FROM system_program` feito na hora, que deve dar 105) e `system_group_program` dos 3 no grupo 1; `system_group_program` do programa `CvShellController` (id 105) para cada `system_group` que ainda não o tem (INSERT … SELECT com `NOT EXISTS`), para que o seletor de unidade e o cartão do usuário funcionem além do grupo 1.
- Consome: nada

**Teste RED**
- sem teste: migration e DML puros, sem comportamento de código; a prova é o `.verify.sql` rodado pelo orquestrador após a aplicação aprovada

**Critério de aceite**
- `grep -c "PREPARED ONLY" <0007>.sql` = 1, e o arquivo cita as 12 colunas/tabelas do Produz; `grep -ciE "\b(insert|update|delete|alter|create|drop|truncate|replace)\b" <0007>.verify.sql` = 0.
- O `.verify.sql` traz:
  - `SELECT` de `information_schema.columns` para as 9 colunas novas;
  - `SELECT` de `information_schema.tables` para as 3 tabelas novas;
  - `SELECT COUNT(*)` de `product`, `patient`, `prescription`, `financial_entry` e `encounter`, para comparar com a contagem de antes;
  - `SELECT COUNT(*) FROM financial_entry WHERE reference_type = 'payment' AND payment_method IS NULL AND category IN (...)`, que deve dar 0.
- `sql/T-01-programs.sql` só contém `INSERT` em `system_program`/`system_group_program` e `SELECT`; `grep -ciE "\b(update|delete|drop|alter|truncate)\b"` = 0.
- Nenhum `ALTER`/`DROP` de CHECK existente (`grep -c "encounter_status_ck" <0007>.sql` = 0).

**Validação**
- `grep -c "PREPARED ONLY" /var/www/html/centralvet/src/app/database/migrations/20260930_0007_rodada2_cadastros_financeiro.sql` (evidência: `1`)
- `grep -ciE "\b(insert|update|delete|alter|create|drop|truncate|replace)\b" /var/www/html/centralvet/src/app/database/migrations/20260930_0007_rodada2_cadastros_financeiro.verify.sql` (evidência: `0`)
- `grep -ciE "\b(update|delete|drop|alter|truncate)\b" /var/www/html/centralvet/.claude/tasks/mar-20260930-0823-rodada-2-divida-cadastros-schema-acoes/sql/T-01-programs.sql` (evidência: `0`)
- Após a aplicação aprovada (orquestrador): `.verify.sql` mostra as 9 colunas, as 3 tabelas e a linha de `schema_migrations`; as 5 contagens são iguais às anotadas antes da migration (registros existentes preservados); a contagem de `payment_method` nulo em pagamentos dá `0`; `SELECT id, controller FROM system_program WHERE controller IN ('BankAccountList','BankAccountForm','ServiceImportForm')` devolve 3 linhas.

### T-02 — AuthorizationRequest escapa `$action`; docblock de applyDiscount

**Camada:** backend
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** simples
**Agente:** Arquimedes

Triagem T-27 (`AuthorizationRequest.php:41` interpola `$action` cru; `AuthorizationRequestTest.php:46` não confere a mensagem) e T-25 (docblock de `applyDiscount`, `EncounterAccountService.php:316-321`, cita só a conta).

**Arquivos prováveis**
- `src/app/Core/Authorization/AuthorizationRequest.php`
- `src/tests/Unit/AuthorizationRequestTest.php`
- `src/app/Core/Application/EncounterAccountService.php`

**Interface**
- Produz: `InvalidArgumentException` com mensagem exata `'Invalid authorization action format: ' . json_encode($action, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)`. Para `"bad\naction"`, a mensagem é `Invalid authorization action format: "bad\naction"`, com barra invertida e `n` literais, sem quebra de linha. `ACTION_PATTERN` não muda.
- Produz: docblock de `EncounterAccountService::applyDiscount` com os quatro `@throws`: `CrossTenantReferenceException` para conta fora do tenant **ou** autorizador fora do tenant/inativo; `InvalidArgumentException` para desconto inválido; `AuthorizationDenied`; `InvalidStatusTransitionException`, se o método já a lança.
  Só comentário.
- Consome: nada

**Teste RED**
- `src/tests/Unit/AuthorizationRequestTest.php` — `new AuthorizationRequest(... action: "bad\naction" ...)` captura a exceção em try/catch (`Assert::throws` devolve void) e afirma com `Assert::same` a mensagem `Invalid authorization action format: "bad\naction"` e que ela não contém `"\n"` real; antes da correção falha porque a mensagem usa aspas simples e a quebra crua (comando: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php`)

**Critério de aceite**
- SUITE: `PASS  Unit\AuthorizationRequestTest::` em todos os métodos e `Failed: 0`.
- `grep -c "@throws" src/app/Core/Application/EncounterAccountService.php` aumenta em relação à BASE, e o bloco de `applyDiscount` cita `authorized_by`.

**Validação**
- LINT de `app/Core/Authorization/AuthorizationRequest.php` e `app/Core/Application/EncounterAccountService.php` (evidência: 2 `No syntax errors detected`) + SUITE (evidência: `PASS  Unit\AuthorizationRequestTest::`, `Failed: 0`)
- `git -C /var/www/html/centralvet diff <BASE>..HEAD -- src/app/Core/Application/EncounterAccountService.php | grep '^[+-]' | grep -v '^[+-]\s*\*\|^[+-]\s*/\*\*\|^+++\|^---'` (evidência: vazio — só comentário mudou)

### T-03 — Readers: limite ≤ 0, `overview()` com uma varredura, atendimento anterior

**Camada:** backend
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Sherlock

Triagem:
- T-04: `recentSales(0)` devolve 1 linha porque o reader força `max(1)` (`StockSalesOverviewReader.php:124`), e `lowStock(0)` devolve `[]`;
- T-10: `products()` roda 3x por carga, em `summary()` :36, `lowStock()` :103 e `ProductList.php:100`;
- T-06/T-13: `findLatestEncounter` (`ClinicalSummaryReader.php:52`) exclui só pelo id e pode devolver um atendimento posterior ao atual.

**Arquivos prováveis**
- `src/app/Core/Persistence/StockSalesOverviewReader.php`
- `src/app/Core/Application/StockSalesOverviewService.php`
- `src/tests/Integration/StockSalesOverviewIntegrationTest.php`
- `src/app/Core/Persistence/ClinicalSummaryReader.php`
- `src/tests/Integration/ClinicalSummaryIntegrationTest.php`

**Interface**
- Produz: `StockSalesOverviewService::recentSales(int $limit = 5): array` e `StockSalesOverviewService::lowStock(int $limit = 5): array` devolvem `[]` quando `$limit <= 0`; o reader não força mais `max(1)`.
- Produz: `StockSalesOverviewService::overview(DateTimeImmutable $month, ?string $search = null, ?string $category = null, ?string $status = null, int $lowStockLimit = 5): array` → `array{summary: array, products: list<array>, low_stock: list<array>}`: `summary` tem as mesmas chaves de `summary()`; `products` é igual a `products($search, $category, $status)`; `low_stock` é igual a `lowStock($lowStockLimit)`; `summary` e `low_stock` são calculados sobre o conjunto sem filtro; `StockSalesOverviewReader::productStocks()` roda **uma** vez sem filtro e, só se houver `$search`/`$category`, mais uma com filtro.
  `summary()`, `products()` e `lowStock()` continuam públicos com o comportamento atual.
- Produz: `ClinicalSummaryService::lastEncounter(int $patientId, ?int $excludeEncounterId = null): ?array` (assinatura atual) passa a devolver, com `$excludeEncounterId`, o atendimento mais recente do paciente com `started_at` **anterior** ao `started_at` do excluído (empate: menor id); sem `$excludeEncounterId`, o mais recente. Formato de retorno inalterado.
- Consome: nada

**Teste RED**
- `src/tests/Integration/StockSalesOverviewIntegrationTest.php`, `src/tests/Integration/ClinicalSummaryIntegrationTest.php` — cobrem três casos, que falham antes da correção (`recentSales(0)` devolve 1 linha, `overview` não existe e hoje vem o posterior): `recentSales(0)` e `lowStock(0)` devolvem `[]`; `overview(...)` devolve `products` e `low_stock` iguais aos de `products()`/`lowStock()` sobre os mesmos dados semeados; com atendimentos A (ontem), B (hoje, atual) e C (amanhã) do mesmo paciente, `lastEncounter($p, B)` devolve A. (comando: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php`)

**Critério de aceite**
- SUITE: `PASS  Integration\StockSalesOverviewIntegrationTest::` e `PASS  Integration\ClinicalSummaryIntegrationTest::` em todos os métodos (inclusive os existentes), `Failed: 0`.

**Validação**
- LINT dos 3 arquivos de Core (evidência: 3 `No syntax errors detected`) + SUITE (evidência: as linhas `PASS` das duas classes, `Failed: 0`)

### T-04 — Casca: 403 x 500 em onSwitchUnit, fallback de rótulos, ajuda "Em breve", CvPage `target`

**Camada:** frontend
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Aang

Triagem:
- T-26: o catch genérico de `onSwitchUnit` (`CvShellController.php:131-135`) devolve 403 para falha de servidor e não faz rollback;
- sem `labels`, `cv-shell.js:138-140` põe `aria-label=""`, e `disableMenuLink` (:232-235) sai sem selo nem title;
- T-01: o botão de ajuda `disabled` + `pointer-events: none` (`layout.html:105`, `custom.css:299-302`) esconde o tooltip;
- T-19: units sem `current` / seletor com unidade única habilitado (`cv-shell.js:141-159`).
Também entrega a opção `target` de `CvPage::header`, que T-20/T-21 usam para links de download: hoje todo link leva `generator=adianti`.

**Arquivos prováveis**
- `src/app/control/clinic/CvShellController.php`
- `src/app/templates/adminbs5/js/cv-shell.js`
- `src/app/templates/adminbs5/layout.html`
- `src/app/templates/adminbs5/custom.css`
- `src/app/lib/widget/CvPage.php`

**Interface**
- Produz: `onSwitchUnit` responde: 403 `{"error": ...}` só para as recusas conhecidas: token inválido (mensagem atual :113) e unidade fora de `allowedUnitIds()` (mensagem atual :123); qualquer outra `\Throwable` → `TTransaction::rollback()` (se houver transação aberta), `error_log` com a classe e a mensagem e **HTTP 500** `{"error": _t('Could not switch unit')}` (chave nova no board).
- Produz: no `CvPage::header`, o slot `data-cv-unit-switch` ganha `data-cv-label="<_t('Unit')>"`, e `cv-shell.js` usa `labels.unit || slot.dataset.cvLabel` no `aria-label`. `disableMenuLink` sem `labels` ainda aplica `aria-disabled="true"` e a classe de item desabilitado. Com uma unidade só, o `<select>` fica `disabled`. Sem unidade `current`, o placeholder `—` fica selecionado e `disabled`.
- Produz: botão de ajuda sem o atributo `disabled`, com `aria-disabled="true"`, `title` e `aria-label` "Em breve" mantidos e `onclick="return false"`. O CSS trata `.cv-topbar-icon[aria-disabled="true"]` com `cursor: not-allowed` e `opacity`, **sem** `pointer-events: none`, para que o tooltip apareça no hover.
- Produz: `CvPage::header` aceita no spec de ação a chave `'target' => '_blank'`: o link sai com `target="_blank"` e `rel="noopener"` e **sem** `generator="adianti"`; sem a chave, o comportamento atual não muda.
- Consome: nada

**Teste RED**
- sem teste: controller Adianti, JS e template não são carregados por `tests/run.php`; a prova é `php -r` com `init.php`, grep e o gate

**Critério de aceite**
- `grep -c "pointer-events: none" src/app/templates/adminbs5/custom.css` não cobre mais `.cv-topbar-icon` (grep contextual em :295-305 vazio), e `grep -c 'aria-disabled="true"' src/app/templates/adminbs5/layout.html` ≥ 1 no botão de ajuda.
- GATE:
  - hover no botão de ajuda mostra o tooltip "Em breve";
  - POST de `onSwitchUnit` com token `x` → 403;
  - POST com token válido e unidade atual → 200 `{"switched":true}`;
  - o seletor tem `aria-label` não vazio;
  - console 0 `error` em `ServiceList` e `ProductList`.
- `php -r` que renderiza `CvPage::header('t', null, [['label'=>'x','href'=>'engine.php?a=1','target'=>'_blank']])` imprime `target="_blank"` e não imprime `generator="adianti"` nesse link.

**Validação**
- LINT de `CvShellController.php` e `CvPage.php` (evidência: 2 `No syntax errors detected`)
- `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php -r 'chdir("/var/www/html/src"); require "init.php"; echo CvPage::header("t", null, [["label"=>"x","href"=>"engine.php?a=1","target"=>"_blank"]]);'` (evidência: `target="_blank"` presente e `generator="adianti"` ausente no `<a>` de `engine.php?a=1`)
- GATE → `browser_evaluate` com `fetch('engine.php?class=CvShellController&method=onSwitchUnit', {method:'POST', body: new URLSearchParams({unit_id: <atual>, csrf_token:'x'})})` (evidência: `403`); com o `csrf_token` do `onContext` e a unidade atual (evidência: `200`, `switched:true`); hover no ícone de ajuda (evidência: tooltip "Em breve" no snapshot); `document.querySelector('.cv-unit-switch__select').getAttribute('aria-label')` (evidência: não vazio)
- Caminho de 500 (sem reprodução pela UI): leitura do diff mostra `catch (\Throwable` separado das recusas com `http_response_code(500)` ou `sendJson(..., 500)` (evidência: trecho citado no relatório)

### T-05 — CvCatalog único nos combos; comentários/docblocks de PendingExamResultList e PayableList

**Camada:** frontend
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** simples
**Agente:** Levi

Triagem:
- T-33: `catalogCriteria()` está duplicado byte a byte em `ProcedureInputForm.php:341-358` e `VaccineProtocolForm.php:317-334`;
- T-30: o comentário de `PendingExamResultList.php:109` não cita `ExamRequest::STATUS_REQUESTED`, que é o filtro de `ExamRequestRepository.php:85`;
- T-28: o docblock de `PayableList::resolveStatus` (:183-187) diz que grava na sessão sem parâmetro, e não grava.

**Arquivos prováveis**
- `src/app/lib/widget/CvCatalog.php`
- `src/app/control/clinic/ProcedureInputForm.php`
- `src/app/control/clinic/VaccineProtocolForm.php`
- `src/app/control/clinic/PendingExamResultList.php`
- `src/app/control/clinic/PayableList.php`

**Interface**
- Produz: `CvCatalog::activeOrCurrentCriteria(int $tenantId, ?int $currentId): TCriteria` com a mesma lógica do `catalogCriteria()` atual (copiada literalmente do corpo existente, só parametrizada). Os dois forms passam a chamá-lo, e o método privado sai dos dois.
- Produz: comentário em `PendingExamResultList.php` citando `ExamRequest::STATUS_REQUESTED` e `ExamRequestRepository::listPending()`; docblock de `resolveStatus` dizendo que ela lê `$_REQUEST['status']` porque `TStandardList::show()` chama `onReload()` sem parâmetro, e que só grava na sessão quando `status` vem na requisição. Só comentário.
- Consome: nada

**Teste RED**
- sem teste: controllers Adianti e widget com `TCriteria` não são carregados por `tests/run.php`; a prova é grep, `php -r` e o gate

**Critério de aceite**
- `grep -c "function catalogCriteria" src/app/control/clinic/ProcedureInputForm.php src/app/control/clinic/VaccineProtocolForm.php` = 0 e 0; `grep -c "CvCatalog::activeOrCurrentCriteria" ` = ≥ 1 em cada.
- GATE: os combos de `ProcedureInputForm` e `VaccineProtocolForm` listam as mesmas opções que na BASE (contagem de `<option>` igual à anotada antes do rebuild) e console 0 `error`.

**Validação**
- LINT dos 5 PHP (evidência: 5 `No syntax errors detected`)
- `grep -c "function catalogCriteria" /var/www/html/centralvet/src/app/control/clinic/ProcedureInputForm.php /var/www/html/centralvet/src/app/control/clinic/VaccineProtocolForm.php` (evidência: `:0` nos dois)
- `grep -n "STATUS_REQUESTED" /var/www/html/centralvet/src/app/control/clinic/PendingExamResultList.php` (evidência: 1 linha de comentário)
- GATE → contar `<option>` do combo nas duas telas antes (BASE) e depois (evidência: iguais), console 0 `error`

### T-06 — Editar tutor: TutorService::update + TutorForm

**Camada:** backend
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Thanos

Demanda futura de T-14 da fase 10: `TutorForm` com `key` abre só leitura (:73-76), e `onSave` só cria (:172). `TutorRepository::save` já faz UPDATE quando há id (:108), e `Tutor::withDetails()` (`Domain/Tutor.php:83`) já existe.

**Arquivos prováveis**
- `src/app/Core/Application/TutorService.php`
- `src/tests/Unit/TutorServiceTest.php`
- `src/app/control/clinic/TutorForm.php`

**Interface**
- Produz: `TutorService::update(int $id, array $data): Tutor`: chaves obrigatórias `full_name` e `phone` (aparadas, não vazias → `InvalidArgumentException('full_name is required')` / `('phone is required')`, as mesmas mensagens de `create`); opcionais `document`, `email` e `address` (`''` → `null`); `findById($id)` nulo → `InvalidArgumentException("Tutor {$id} not found for this tenant")`; `document` de **outro** tutor do tenant → `InvalidArgumentException('A tutor with this document already exists in this tenant')`; manter o próprio passa; grava `$tutor->withDetails(...)` pelo repositório e devolve o salvo; `id`, `tenantId` e `publicId` não mudam.
- Produz: `TutorForm` com `key`/`id` abre os campos **editáveis**, preenchidos, com o botão Salvar. `onSave` com `id` chama `update()`, e sem `id` chama `create()` como hoje. Sucesso mostra `_t('Record saved')` e reabre `onEdit` com `key`. Erro de validação mostra a mensagem em `TMessage('error', ...)`, e os dados digitados ficam.
- Consome: nada

**Teste RED**
- `src/tests/Unit/TutorServiceTest.php` — com `FakeTutorRepository` e 2 tutores do tenant 1 (A e B com documentos distintos), falha antes da implementação porque `TutorService::update` não existe (comando: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php`): `update(A, [...])` muda nome e telefone e mantém `id`/`publicId`; `update(A, ['document' => docB, ...])` e `update(999, ...)` lançam `InvalidArgumentException`, com as mensagens do Produz conferidas em try/catch; um tutor do tenant 2 no mesmo Fake não é encontrado;

**Critério de aceite**
- SUITE: `PASS  Unit\TutorServiceTest::` em todos os métodos, `Failed: 0`.
- GATE: `TutorForm&method=onEdit&key=<id do tutor "R2 varredura Tutor">` → mudar o telefone → Salvar mostra a mensagem de salvo; `SELECT phone FROM tutor WHERE id=<id>` traz o novo; `SELECT COUNT(*) FROM tutor` igual antes e depois.

**Validação**
- LINT de `TutorService.php` e `TutorForm.php` (evidência: 2 `No syntax errors detected`) + SUITE (evidência: `PASS  Unit\TutorServiceTest::`, `Failed: 0`)
- GATE → editar e salvar o tutor de teste (evidência: mensagem de salvo, `SELECT phone` novo, `COUNT(*)` igual, console 0 `error`)

### T-07 — Editar paciente: PatientService::update + PatientForm

**Camada:** backend
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Naruto

`PatientForm` com `key` abre só leitura (:175), e `onSave` só cria (:270). `PatientRepository::save` faz UPDATE com id (:118). `Patient` tem construtor público e imutável.

**Arquivos prováveis**
- `src/app/Core/Application/PatientService.php`
- `src/tests/Unit/PatientServiceTest.php`
- `src/app/control/clinic/PatientForm.php`

**Interface**
- Produz: `PatientService::update(int $id, array $data): Patient`: chaves obrigatórias `name` e `species` (aparadas, não vazias → `InvalidArgumentException('name is required')` / `('species is required')`); opcionais `breed`, `sex`, `birth_date`, `weight_kg`, `color` e `notes`, com a mesma normalização de `create()` (`''` → `null`); `tutor_id` é ignorado: o tutor não muda; `findById($id)` nulo → `InvalidArgumentException("Patient {$id} not found for this tenant")`; grava um `new Patient(...)` com `id`, `tenantId`, `tutorId` e `createdAt` do atual e devolve o salvo; as regras de `sex`/`weight_kg` do construtor valem.
- Produz: `PatientForm` com `key`/`id` abre editável, com o tutor só leitura, e Salvar chama `update()`. Sem `key`, o comportamento de hoje fica. Sucesso mostra `_t('Record saved')` e reabre `onEdit` com `key` e `tutor_id`. Com `key` inexistente ou de outro tenant, a tela mostra `_t('Record not found')`, sem campos editáveis e sem Salvar.
- Consome: nada

**Teste RED**
- `src/tests/Unit/PatientServiceTest.php` — com `FakePatientRepository`/`FakeTutorRepository`, falha antes da implementação porque `update` não existe (comando: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php`): `update(P, ['name' => 'Rex 2', 'species' => 'dog', 'tutor_id' => outro])` muda o nome e mantém `tutorId`/`id`; `update(999, ...)` lança `InvalidArgumentException` com a mensagem do Produz; paciente do tenant 2 não é encontrado; `sex = 'X'` lança `InvalidArgumentException`;

**Critério de aceite**
- SUITE: `PASS  Unit\PatientServiceTest::` em todos os métodos, `Failed: 0`.
- GATE:
  - `PatientForm&method=onEdit&key=<id do paciente "R2 varredura Pet">&tutor_id=<tutor>` → mudar a cor → Salvar mostra a mensagem de salvo, `SELECT color FROM patient WHERE id=<id>` traz a nova e `SELECT COUNT(*) FROM patient` fica igual;
  - `key=999999` mostra "Registro não encontrado" (ou a chave, até T-23) sem botão Salvar.

**Validação**
- LINT de `PatientService.php` e `PatientForm.php` (evidência: 2 `No syntax errors detected`) + SUITE (evidência: `PASS  Unit\PatientServiceTest::`, `Failed: 0`)
- GATE → editar e salvar o paciente de teste (evidência: mensagem, `SELECT color`, `COUNT(*)` igual, console 0 `error`)
- Review Focus: `PatientForm&method=onEdit&key=999999` (evidência: mensagem de não encontrado, `document.querySelectorAll('button[name=btn_save], [id*=onSave]').length` = 0 e nenhum `UPDATE`: `SELECT MAX(updated_at) FROM patient` igual)

### T-08 — Editar agendamento: AppointmentService::reschedule + AppointmentForm

**Camada:** backend
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** alta
**Agente:** Kratos

`AppointmentForm` com `key` abre só leitura (:99), e `onSave` só chama `schedule()` (:184). `assertNoConflict` (`AppointmentService.php:187`) já ignora o próprio id. O docblock de :24-33 está desatualizado: diz que `ServiceCatalogService` só tem create/listActive.

**Arquivos prováveis**
- `src/app/Core/Application/AppointmentService.php`
- `src/tests/Unit/AppointmentServiceTest.php`
- `src/app/control/clinic/AppointmentForm.php`

**Interface**
- Produz: `AppointmentService::reschedule(int $id, array $data, string $action): Appointment`: chaves obrigatórias `service_id`, `professional_system_user_id` e `scheduled_at` (`DateTimeImmutable` ou string) → `InvalidArgumentException("{$required} is required")`; `findById($id)` nulo → `InvalidArgumentException("Appointment {$id} not found for this tenant")`; status fora de `Appointment::STATUS_SCHEDULED`/`STATUS_CONFIRMED` → `InvalidStatusTransitionException("Appointment {$id} cannot be rescheduled from status {$status}")`; `service_id` fora do tenant → `CrossTenantReferenceException`, como em `schedule()`; `AuthorizationRequest` com `action: $action`, `requiresUnitScope: true`, `resourceUnitId` = unidade do agendamento, `entityType: 'appointment'` e `entityId: $id`; conflito de horário → `SchedulingConflictException`, como em `schedule()`, ignorando o próprio id; `patientId`, `systemUnitId` e `status` não mudam; grava um `new Appointment(...)` com o mesmo `id` e devolve o salvo; docblock da classe atualizado (sem a frase sobre `ServiceCatalogService`).
- Produz: `AppointmentForm` com `key` abre editável (serviço, profissional, data/hora), com paciente só leitura e botão `_t('Save')` que chama `reschedule($id, [...], __CLASS__ . '::onSave')`. Sem `key`, o fluxo de `schedule()` fica. Erros de conflito, status ou tenant aparecem em `TMessage('error')`.
- Consome: nada

**Teste RED**
- `src/tests/Unit/AppointmentServiceTest.php` — com os Fakes e a `FakeAuthorizationPolicy` do teste atual, falha antes da implementação porque `reschedule` não existe (comando: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php`): `reschedule(A, [novo horário livre])` muda `scheduledAt` e mantém `id`/`patientId`; `reschedule(A, [horário de B])` lança `SchedulingConflictException`; `reschedule(A, [mesmo horário])` passa; agendamento `cancelado` lança `InvalidStatusTransitionException`; `reschedule(999, ...)` lança `InvalidArgumentException` com a mensagem do Produz;

**Critério de aceite**
- SUITE: `PASS  Unit\AppointmentServiceTest::` em todos os métodos, `Failed: 0`.
- GATE: `AgendaView` → abrir um agendamento `R2 varredura` → mudar a hora para um horário livre → Salvar mostra a mensagem de salvo e o agendamento aparece no novo horário; `SELECT scheduled_at FROM appointment WHERE id=<id>` traz o novo; `SELECT COUNT(*) FROM appointment` fica igual.

**Validação**
- LINT de `AppointmentService.php` e `AppointmentForm.php` (evidência: 2 `No syntax errors detected`) + SUITE (evidência: `PASS  Unit\AppointmentServiceTest::`, `Failed: 0`)
- GATE → remarcar o agendamento de teste (evidência: mensagem, `SELECT scheduled_at`, `COUNT(*)` igual, console 0 `error`)
- Review Focus: remarcar para o horário de outro agendamento ativo do mesmo profissional pela tela (evidência: diálogo de conflito e `SELECT scheduled_at` inalterado); salvar sem mudar o horário (evidência: mensagem de salvo)

### T-09 — ServiceCatalogService: create com active, duplicate, delete, importCsv

**Camada:** backend
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** alta
**Agente:** Platão

Triagem "Final: ServiceForm cria com active=0 em dois writes" (`ServiceForm.php:183-188`) e ações Importar/Duplicar/Excluir. A única FK que aponta para `service` é `appointment_service_fk` (RESTRICT, migration 0002 :113).

**Arquivos prováveis**
- `src/app/Core/Application/ServiceCatalogService.php`
- `src/app/Core/Domain/Contract/ServiceRepositoryInterface.php`
- `src/app/Core/Persistence/ServiceRepository.php`
- `src/tests/Support/FakeServiceRepository.php`
- `src/tests/Unit/ServiceCatalogServiceTest.php`

**Interface**
- Produz: `ServiceCatalogService::create(array $data): Service` aceita a chave opcional `active` (bool). Com `active === false`, o serviço é criado inativo (`deactivate()` antes do `save()`), num único `save()`.
- Produz: `ServiceCatalogService::duplicate(int $id, string $copyLabel = 'copy'): Service`: cria um serviço com mesma categoria, duração e preço, **inativo**, e nome `"{$name} ({$copyLabel})"`; se o nome existe, tenta `"{$name} ({$copyLabel} 2)"`, `3`… até achar um livre; id inexistente ou de outro tenant → `InvalidArgumentException('Service not found for this tenant')`, a mensagem de `update()`.
- Produz: `ServiceCatalogService::delete(int $id): void`: id inexistente → `InvalidArgumentException('Service not found for this tenant')`; `hasAppointments($id)` → `DomainException("Service {$id} has appointments; deactivate it instead")`; senão `remove()`.
- Produz: `ServiceRepositoryInterface::hasAppointments(int $serviceId): bool`: `SELECT 1 FROM appointment WHERE tenant_id = :tenant AND service_id = :id LIMIT 1` no repositório real. No Fake, o setter `FakeServiceRepository::markHasAppointments(int $serviceId): void` marca o serviço, e `hasAppointments()` devolve `true` só para os marcados (o construtor variádico não muda).
- Produz: `ServiceCatalogService::importCsv(string $csv): array` → `array{created: int, skipped: list<array{line: int, reason: string}>}`: separador `;` e cabeçalho exato `name;category;duration_minutes;price` (outro cabeçalho → `InvalidArgumentException('Invalid header: expected name;category;duration_minutes;price')`); BOM UTF-8 e `\r\n` aceitos; `price` em reais com vírgula ou ponto (`120,50` → 12050 centavos); linha vazia ignorada, sem entrar em `skipped`; `line` é o número da linha do arquivo (cabeçalho = 1); `reason` é um de `name is required`, `invalid duration_minutes`, `invalid price`, `duplicated name`; nome existente ou repetido no próprio arquivo → `duplicated name`; cada linha válida vira `create()` ativo.
- Consome: nada

**Teste RED**
- `src/tests/Unit/ServiceCatalogServiceTest.php`, `src/tests/Support/FakeServiceRepository.php` — falha antes da implementação porque `duplicate`/`delete`/`importCsv`/`hasAppointments` não existem (comando: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php`): `create([... 'active' => false])` deixa `storedCount()` +1 com `isActive()` false; `duplicate(A, 'cópia')` duas vezes gera `"A (cópia)"` e `"A (cópia 2)"`, inativos; `delete` de serviço com agendamento lança `DomainException` e o serviço segue no Fake; `delete` sem agendamento remove; `importCsv` com cabeçalho válido, 2 linhas boas, 1 com nome existente e 1 com preço `abc` devolve `created: 2` e `skipped` com as linhas 4 e 5 e os motivos exatos;

**Critério de aceite**
- SUITE: `PASS  Unit\ServiceCatalogServiceTest::` em todos os métodos (inclusive os existentes), `Failed: 0`.

**Validação**
- LINT dos 4 PHP de Core/Support (evidência: 4 `No syntax errors detected`) + SUITE (evidência: `PASS  Unit\ServiceCatalogServiceTest::`, `Failed: 0`)
- Review Focus: `ServiceRepository::hasAppointments` com um serviço que tem agendamento no banco real — `php -r` com `init.php` no container, dentro de `TTransaction::open('centralvet')` (evidência: `true` para o serviço de um agendamento existente, pelo `SELECT service_id FROM appointment LIMIT 1`, e `false` para o `id` de um serviço sem agendamento)

### T-10 — ServiceList Importar/Duplicar/Excluir, ServiceImportForm, ServiceForm em um write

**Camada:** frontend
**Dependências:** T-01, T-09
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Levi

Os botões Importar, Duplicar e Excluir não existem no `ServiceList`: o cabeçalho está em :138, o menu da linha em :119 e o painel em :327. `ServiceImportForm` precisa do programa registrado pelo DML de T-01.

**Arquivos prováveis**
- `src/app/control/clinic/ServiceList.php`
- `src/app/control/clinic/ServiceForm.php`
- `src/app/control/clinic/ServiceImportForm.php`

**Interface**
- Produz: `ServiceForm::onSave` sem id chama `create([... 'active' => <valor do form>])` **uma** vez; o segundo write (`update` com `active=false`) sai.
- Produz: `ServiceList`: cabeçalho com a ação `_t('Import')` → `ServiceImportForm`; menu da linha e painel com `_t('Duplicate')` → `onDuplicate` (chama `duplicate($id, _t('copy'))` e recarrega com `service_id` da cópia) e `_t('Delete')` → `TQuestion` de confirmação → `onDelete`, que chama `delete($id)`; o `DomainException` de agendamento vira `TMessage('error', _t('This service has appointments; deactivate it instead'))`.
- Produz: `ServiceImportForm` (TPage): `TFile` `csv_file` (extensões `csv`, 1 MB), texto de ajuda com o cabeçalho esperado e botão `_t('Import')` → `onImport`; `onImport` lê o arquivo de `tmp/`, chama `importCsv()` e mostra `TMessage('info')` com `created` e a lista `linha: motivo` de `skipped`; o voltar leva a `ServiceList`.
- Consome: T-09 `ServiceCatalogService::duplicate(int $id, string $copyLabel = 'copy'): Service`, T-09 `ServiceCatalogService::delete(int $id): void`, T-09 `ServiceCatalogService::importCsv(string $csv): array`, T-01 `ServiceImportForm`

**Teste RED**
- sem teste: controllers Adianti não são carregados por `tests/run.php`; a regra está coberta pelo RED de T-09 e a tela pelo gate

**Critério de aceite**
- `grep -c -- "->update(" src/app/control/clinic/ServiceForm.php` fica igual ou menor que na BASE e o ramo sem id tem um único `create(`.
- GATE:
  - `ServiceForm` novo "R2 varredura Serviço" inativo → Salvar faz `SELECT active FROM service WHERE name='R2 varredura Serviço'` dar `0`, com `COUNT(*)` +1;
  - Duplicar cria "R2 varredura Serviço (cópia)" inativo;
  - Excluir a cópia remove a linha (`COUNT(*)` −1);
  - Excluir um serviço com agendamento mostra o diálogo de erro, e `COUNT(*)` fica igual;
  - Importar um CSV de 3 linhas (2 boas + 1 duplicada) mostra "2" criados e a linha 4 pulada, com `COUNT(*)` +2.

**Validação**
- LINT dos 3 PHP (evidência: 3 `No syntax errors detected`)
- GATE → os 5 fluxos do critério, com `SELECT COUNT(*) FROM service` antes e depois de cada um (evidência: +1, +1, −1, 0, +2; mensagens citadas; console 0 `error`, rede 0 ≥ 400). CSV de teste criado pelo validador em `.playwright-mcp/r2-servicos.csv`

### T-11 — Produto: preço de venda e código (Core + ProductForm) e testes de outro tenant

**Camada:** backend
**Dependências:** T-01
**Paralelizável:** sim
**Complexidade:** alta
**Agente:** Darwin

Campos sem schema: preço de venda e código. Triagem T-34: os testes de `ProductService` só afirmam a classe da exceção, não têm caso de outro tenant e não conferem que B fica intacto no nome duplicado. `update()` (`ProductService.php:78`) não confere o tenant além do `findById`.

**Arquivos prováveis**
- `src/app/Core/Domain/Product.php`
- `src/app/Core/Domain/Contract/ProductRepositoryInterface.php`
- `src/app/Core/Persistence/ProductRepository.php`
- `src/tests/Support/FakeProductRepository.php`
- `src/app/Core/Application/ProductService.php`
- `src/tests/Unit/ProductServiceTest.php`
- `src/app/control/clinic/ProductForm.php`
- `src/app/Core/Persistence/StockSalesOverviewReader.php`
- `src/tests/Integration/StockSalesOverviewIntegrationTest.php`

**Interface**
- Produz: `Product::salePriceCents(): ?int` e `Product::code(): ?string`: `Product::create(...)` e `Product::reconstitute(...)` ganham `?int $salePriceCents = null, ?string $code = null` **por último**; `salePriceCents < 0` → `InvalidArgumentException('sale_price_cents cannot be negative')`; `code` é aparado e vazio vira `null`, com máximo 60 caracteres (`InvalidArgumentException('code must have at most 60 characters')`).
- Produz: `ProductRepositoryInterface::findByCode(string $code): ?object`, tenant-aware, no repositório real e no Fake. `ProductRepository` grava e lê `sale_price_cents` e `code` no INSERT, no UPDATE e no hydrate.
- Produz: `ProductService::create(...)` e `ProductService::update(...)` ganham `?int $salePriceCents = null, ?string $code = null` por último: código de **outro** produto do tenant → `InvalidArgumentException("A product with code \"{$code}\" already exists for this tenant")`; manter o próprio código passa; `update()` de id de outro tenant → `InvalidArgumentException("Product {$productId} not found for this tenant")`, a mensagem atual.
- Produz: `StockSalesOverviewReader::productStocks()` inclui em cada linha as chaves `code` (`?string`) e `sale_price_cents` (`?int`), que chegam a `products()`/`overview()` sem outra mudança no service.
- Produz: `ProductForm` com os campos `sale_price` (moeda, gravado em centavos) e `code`, que o `onEdit` carrega e o `onSave` repassa.
- Consome: T-01 `product.sale_price_cents`, T-01 `product.code`

**Teste RED**
- `src/tests/Unit/ProductServiceTest.php`, `src/tests/Support/FakeProductRepository.php`, `src/tests/Integration/StockSalesOverviewIntegrationTest.php` — falha antes da implementação porque os parâmetros, `findByCode` e as chaves não existem (comando: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php`): `create(... salePriceCents: 1990, code: 'SKU-1')` devolve os dois; `create` de outro produto com `code: 'SKU-1'` lança a mensagem exata do Produz; `update(idA, 'B', ...)` lança `A product named "B" already exists for this tenant` e B continua com o mesmo nome e custo; `update(999, ...)` lança `Product 999 not found for this tenant`; um produto do tenant 2 semeado no Fake não é encontrado por `update(idDoTenant2, ...)`; no teste de integração, um produto semeado com `sale_price_cents = 1990` e `code = 'R2-T'` aparece em `products()` com as duas chaves;

**Critério de aceite**
- SUITE: `PASS  Integration\StockSalesOverviewIntegrationTest::`, `PASS  Unit\ProductServiceTest::` e `PASS  Unit\StockServiceTest::`/`SaleServiceTest::` (chamadores de `Product::`) em todos os métodos, `Failed: 0`.
- GATE: `ProductForm&method=onEdit&key=<produto "F10 varredura Produto T21">` → preço de venda 19,90 e código `R2-001` → Salvar mostra a mensagem; `SELECT sale_price_cents, code FROM product WHERE id=<id>` = `1990`, `R2-001`; `SELECT COUNT(*) FROM product` fica igual.

**Validação**
- LINT dos 7 PHP de Core/Support/controller (evidência: 7 `No syntax errors detected`) + SUITE (evidência: as linhas `PASS` citadas, `Failed: 0`)
- GATE → editar o produto de teste (evidência: `SELECT sale_price_cents, code`, `COUNT(*)` igual, console 0 `error`); salvar outro produto com o código `R2-001` (evidência: diálogo de erro com a mensagem de código duplicado)

### T-12 — Paciente: alergia e foto (Core + PatientForm + onPhoto)

**Camada:** backend
**Dependências:** T-01, T-07
**Paralelizável:** sim
**Complexidade:** alta
**Agente:** Tesla

Campos sem schema: foto e alergia. O storage segue o precedente de `EncounterDocumentService::attach` (chave `tenant/<id>/...`, `S3CompatibleStorage::fromEnvironment($context)`) e o `TFile` + `tmp/` de `EncounterView.php:1126`.

**Arquivos prováveis**
- `src/app/Core/Domain/Patient.php`
- `src/app/Core/Persistence/PatientRepository.php`
- `src/tests/Support/FakePatientRepository.php`
- `src/tests/Support/FakeStorage.php`
- `src/app/Core/Application/PatientService.php`
- `src/tests/Unit/PatientServiceTest.php`
- `src/app/control/clinic/PatientForm.php`

**Interface**
- Produz: `Patient::$allergies`, `Patient::$photoObjectKey` e `Patient::$photoContentType` (`?string`, `public readonly`). São parâmetros do construtor **depois** de `updatedAt`, com default `null`; `fromRow()` lê `allergies`, `photo_object_key` e `photo_content_type` com `?? null`. `withId()` preserva os três. `PatientRepository` grava e lê as 3 colunas: o INSERT e o UPDATE gravam `allergies`, e só `attachPhoto` grava a foto.
- Produz: `PatientService::create()` e `PatientService::update()` aceitam a chave opcional `allergies` (`''` → `null`).
- Produz: `PatientService::__construct(PatientRepositoryInterface $patients, TutorRepositoryInterface $tutors, TenantContext $context, ?StorageInterface $storage = null)`. Os chamadores atuais com 3 argumentos continuam válidos.
- Produz: `PatientService::attachPhoto(int $patientId, string $fileName, string $contents, string $contentType): Patient`: sem storage → `LogicException('Storage not configured')`; paciente não encontrado → `InvalidArgumentException("Patient {$patientId} not found for this tenant")`; `contentType` fora de `image/jpeg`, `image/png`, `image/webp` → `InvalidArgumentException('Photo must be a JPEG, PNG or WEBP image')`; mais de 2 MB → `InvalidArgumentException('Photo must be at most 2 MB')`; chave `sprintf('tenant/%d/patient/%d/photo-%s', $tenantId, $patientId, <nome saneado>)`; grava no storage e depois no paciente, e devolve o paciente salvo.
- Produz: `PatientService::photo(int $patientId): ?array` → `array{contents: string, content_type: string}` ou `null` quando não há foto ou paciente.
- Produz: `PatientForm`: campo `allergies` (TText); campo `photo` (TFile, `jpg,jpeg,png,webp`), que no `onSave` com arquivo chama `attachPhoto`; pré-visualização `<img class="cv-patient-photo" src="engine.php?class=PatientForm&method=onPhoto&static=1&key=<id>">` quando `photoObjectKey` não é nulo; `PatientForm::onPhoto($param)` (static) responde `Content-Type` do arquivo e os bytes, ou 404 sem corpo quando `photo()` é `null`.
- Consome: T-07 `PatientService::update(int $id, array $data): Patient`, T-01 `patient.allergies`, T-01 `patient.photo_object_key`, T-01 `patient.photo_content_type`

**Teste RED**
- `src/tests/Unit/PatientServiceTest.php`, `src/tests/Support/FakeStorage.php` — falha antes da implementação porque `allergies`/`attachPhoto`/`photo` não existem (comando: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php`): `create([... 'allergies' => 'Dipirona'])` devolve `allergies = 'Dipirona'`; `attachPhoto(P, 'foto.png', <bytes>, 'image/png')` grava no `FakeStorage` sob `tenant/1/patient/<P>/photo-foto.png` e devolve `photoObjectKey` igual; `photo(P)` devolve os mesmos bytes; `attachPhoto` com `application/pdf` lança a mensagem exata; `photo()` de paciente sem foto devolve `null`;

**Critério de aceite**
- SUITE: `PASS  Unit\PatientServiceTest::` e `PASS  Unit\QueueEntryServiceTest::` (chamador de `PatientService`) em todos os métodos, `Failed: 0`.
- GATE:
  - `PatientForm` do paciente "R2 varredura Pet" → alergia "Dipirona" + foto PNG → Salvar mostra a mensagem;
  - `SELECT allergies, photo_object_key IS NOT NULL FROM patient WHERE id=<id>` = `Dipirona`, `1`;
  - a pré-visualização carrega (rede 200, `image/png`);
  - `onPhoto&key=999999` devolve 404.

**Validação**
- LINT dos 7 PHP (evidência: 7 `No syntax errors detected`) + SUITE (evidência: as linhas `PASS` citadas, `Failed: 0`)
- GATE → salvar alergia e foto (evidência: `SELECT`, requisição de `onPhoto` 200 com `content-type: image/png`, console 0 `error`); `fetch('engine.php?class=PatientForm&method=onPhoto&static=1&key=999999')` (evidência: `404`); `SELECT COUNT(*) FROM patient` igual

### T-13 — Prescrição: validade e modelos (Core)

**Camada:** backend
**Dependências:** T-01
**Paralelizável:** sim
**Complexidade:** alta
**Agente:** Platão

Campos sem schema: validade e modelos. `PrescriptionService::create($data, $action)` exige `encounter_id`, `patient_id`, `professional_system_user_id` e `items` (:95-155).

**Arquivos prováveis**
- `src/app/Core/Domain/Prescription.php`
- `src/app/Core/Persistence/PrescriptionRepository.php`
- `src/tests/Support/FakePrescriptionRepository.php`
- `src/app/Core/Application/PrescriptionService.php`
- `src/tests/Unit/PrescriptionServiceTest.php`
- `src/app/Core/Domain/PrescriptionTemplate.php`
- `src/app/Core/Domain/Contract/PrescriptionTemplateRepositoryInterface.php`
- `src/app/Core/Persistence/PrescriptionTemplateRepository.php`
- `src/tests/Support/FakePrescriptionTemplateRepository.php`
- `src/app/Core/Application/PrescriptionTemplateService.php`
- `src/tests/Unit/PrescriptionTemplateServiceTest.php`
- `src/tests/Integration/PrescriptionTemplateRepositoryIntegrationTest.php`

**Interface**
- Produz: `Prescription::validUntil(): ?DateTimeImmutable`. As fábricas de `Prescription` ganham `?DateTimeImmutable $validUntil = null` por último, e `PrescriptionRepository` grava e lê `valid_until` (`Y-m-d`).
- Produz: `PrescriptionService::create()` aceita a chave opcional `valid_until` (`Y-m-d` ou `DateTimeImmutable`): data anterior a hoje → `InvalidArgumentException('valid_until cannot be in the past')`; formato inválido → `InvalidArgumentException('valid_until must be a Y-m-d date')`.
- Produz: `PrescriptionTemplate` (final) com `id(): ?int`, `tenantId(): int`, `name(): string`, `orientationText(): ?string`, `createdBySystemUserId(): int` e `items(): array` (`list<array{medication_name: string, dose: string, dose_unit: string, route: string, frequency: string, duration: string}>`, na ordem de `position`), mais `PrescriptionTemplate::create(int $tenantId, string $name, ?string $orientationText, array $items, int $createdBySystemUserId): self`: nome vazio → `InvalidArgumentException('name is required')`; `items` vazio → `InvalidArgumentException('items must be a non-empty list')`; item sem um dos 6 campos → `InvalidArgumentException("items[].{$field} is required")`.
- Produz: `PrescriptionTemplateRepositoryInterface` com `findById(int|string $id): ?object`, `findByName(string $name): ?object`, `listAll(): array` (ordem por nome), `save(object $entity): object` e `remove(object $entity): void`. `PrescriptionTemplateRepository` estende `AbstractTenantRepository` e grava o cabeçalho e os itens (`position` 1..n) em `prescription_template`/`prescription_template_item`.
- Produz: `PrescriptionTemplateService::__construct(PrescriptionTemplateRepositoryInterface $repository, TenantContext $context)`, com: `PrescriptionTemplateService::saveFromItems(string $name, ?string $orientationText, array $items, int $systemUserId): PrescriptionTemplate`, em que nome existente → `InvalidArgumentException("A template named \"{$name}\" already exists for this tenant")`; `PrescriptionTemplateService::listAll(): array`; `PrescriptionTemplateService::findById(int $id): ?PrescriptionTemplate`.
- Consome: T-01 `prescription.valid_until`, T-01 `prescription_template`, T-01 `prescription_template_item`

**Teste RED**
- `src/tests/Unit/PrescriptionTemplateServiceTest.php`, `src/tests/Support/FakePrescriptionTemplateRepository.php`, `src/tests/Unit/PrescriptionServiceTest.php`, `src/tests/Integration/PrescriptionTemplateRepositoryIntegrationTest.php` — falha antes da implementação porque as classes e a chave não existem (comando: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php`): `saveFromItems('Otite', null, [2 itens], 1)` devolve `items()` com os 2 na ordem; repetir o nome lança a mensagem exata; `create([... 'valid_until' => <ontem>])` lança `valid_until cannot be in the past`; `create([... 'valid_until' => <hoje+30>])` devolve `validUntil()` igual; no teste de integração (transação com rollback), `save` + `findById` devolve os 2 itens com os mesmos campos, e `findById` com outro `tenant_id` no contexto devolve `null`;

**Critério de aceite**
- SUITE: `PASS  Unit\PrescriptionTemplateServiceTest::`, `PASS  Unit\PrescriptionServiceTest::` e `PASS  Integration\PrescriptionTemplateRepositoryIntegrationTest::` em todos os métodos, `Failed: 0`.
- `SELECT COUNT(*) FROM prescription_template` = 0 depois da suíte (rollback).

**Validação**
- LINT dos 10 PHP de Core/Support (evidência: 10 `No syntax errors detected`) + SUITE (evidência: as linhas `PASS` citadas, `Failed: 0`)
- `SELECT COUNT(*) FROM prescription_template` depois da suíte (evidência: `0`)

### T-14 — Forma de pagamento no lançamento (Core + FinancialEntryForm/List)

**Camada:** backend
**Dependências:** T-01
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Arquimedes

Campo sem schema: forma de pagamento em `financial_entry`. Hoje `PaymentService` grava o método em `category` (:146-155). `category` fica como está: donut e rótulos não mudam.

**Arquivos prováveis**
- `src/app/Core/Domain/FinancialEntry.php`
- `src/app/Core/Persistence/FinancialEntryRepository.php`
- `src/tests/Support/FakeFinancialEntryRepository.php`
- `src/app/Core/Application/FinancialEntryService.php`
- `src/app/Core/Application/PaymentService.php`
- `src/tests/Unit/PaymentServiceTest.php`
- `src/app/control/clinic/FinancialEntryForm.php`
- `src/app/control/clinic/FinancialEntryList.php`

**Interface**
- Produz: `FinancialEntry::paymentMethod(): ?string`: `FinancialEntry::record(...)` e `reconstitute` ganham `?string $paymentMethod = null` por último; valor fora de `Payment::METHOD_CASH`, `METHOD_DEBIT_CARD`, `METHOD_CREDIT_CARD`, `METHOD_PIX` e `METHOD_BANK_TRANSFER` → `InvalidArgumentException('payment_method must be one of: cash, debit_card, credit_card, pix, bank_transfer')`; `FinancialEntryRepository` grava e lê `payment_method`.
- Produz: `FinancialEntryService::record(int $systemUnitId, string $entryType, string $category, int $amountCents, ?string $referenceType, ?int $referenceId, int $systemUserId, string $action, ?string $paymentMethod = null): FinancialEntry`.
- Produz: `PaymentService` repassa `paymentMethod: $paymentMethod` ao `record()`, com `category` inalterada.
- Produz: `FinancialEntryForm` ganha o combo opcional `payment_method`, com rótulos de `CvFormat::paymentMethod()`, repassado ao `record()`. `FinancialEntryList` ganha a coluna `_t('Payment method')` (`CvFormat::paymentMethod()` ou `—` quando nulo).
- Consome: T-01 `financial_entry.payment_method`

**Teste RED**
- `src/tests/Unit/PaymentServiceTest.php` — com os Fakes do teste atual, falha antes da implementação porque `paymentMethod()` não existe (comando: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php`): um pagamento com `pix` grava no `FakeFinancialEntryRepository` um lançamento `income` com `paymentMethod() === 'pix'` e `category() === 'pix'`; `FinancialEntry::record(... paymentMethod: 'cheque')` lança a mensagem exata;

**Critério de aceite**
- SUITE: `PASS  Unit\PaymentServiceTest::` e `PASS  Unit\PayableServiceTest::` (chamador de `record()`) em todos os métodos, `Failed: 0`.
- GATE: `FinancialEntryForm` → despesa "R2 varredura" R$ 1,00 com forma Pix → Salvar; `SELECT payment_method FROM financial_entry ORDER BY id DESC LIMIT 1` = `pix`; a coluna "Forma de pagamento" de `FinancialEntryList` mostra "Pix".

**Validação**
- LINT dos 7 PHP (evidência: 7 `No syntax errors detected`) + SUITE (evidência: as linhas `PASS` citadas, `Failed: 0`)
- GATE → lançamento manual com Pix (evidência: `SELECT payment_method`, coluna na lista, console 0 `error`)
- Review Focus: `PendingReceivableList` → receber um recebível de teste com Pix pelo `PaymentForm` (evidência: `SELECT category, payment_method FROM financial_entry WHERE reference_type='payment' ORDER BY id DESC LIMIT 1` = `pix`, `pix`; `SELECT COUNT(*) FROM financial_entry` +1; o donut de `FinancialOverview` segue mostrando a categoria "Pix")

### T-15 — Conta bancária com saldo (Core)

**Camada:** backend
**Dependências:** T-01
**Paralelizável:** sim
**Complexidade:** alta
**Agente:** Athena

Campo sem schema: saldo bancário. O saldo é informado à mão por conta, sem conciliação.

**Arquivos prováveis**
- `src/app/Core/Domain/BankAccount.php`
- `src/app/Core/Domain/Contract/BankAccountRepositoryInterface.php`
- `src/app/Core/Persistence/BankAccountRepository.php`
- `src/tests/Support/FakeBankAccountRepository.php`
- `src/app/Core/Application/BankAccountService.php`
- `src/tests/Unit/BankAccountServiceTest.php`
- `src/tests/Integration/BankAccountRepositoryIntegrationTest.php`

**Interface**
- Produz: `BankAccount` (final) com `id(): ?int`, `tenantId(): int`, `systemUnitId(): int`, `name(): string`, `bankName(): ?string`, `balanceCents(): int` (com sinal), `balanceUpdatedAt(): ?DateTimeImmutable` e `isActive(): bool`, mais as fábricas `BankAccount::create(int $tenantId, int $systemUnitId, string $name, ?string $bankName, int $balanceCents, DateTimeImmutable $now): self` e `BankAccount::reconstitute(array $row): self`. Nome vazio → `InvalidArgumentException('name is required')`.
- Produz: `BankAccountRepositoryInterface` com `findById(int|string $id): ?object`, `findByName(int $systemUnitId, string $name): ?object`, `listBySystemUnit(int $systemUnitId): array`, `save(object $entity): object` e `remove(object $entity): void`, e `BankAccountRepository` (`AbstractTenantRepository` + `TenantQuery`).
- Produz: `BankAccountService::__construct(BankAccountRepositoryInterface $repository, TenantContext $context)`, com: `BankAccountService::create(array $data): BankAccount`: chaves `system_unit_id`, `name`, `bank_name?`, `balance_cents`; nome repetido na unidade → `InvalidArgumentException("A bank account named \"{$name}\" already exists for this unit")`; `BankAccountService::update(int $id, array $data): BankAccount`: `name`, `bank_name`, `balance_cents` e `active`; quando `balance_cents` muda, `balance_updated_at` recebe agora; id inexistente → `InvalidArgumentException("Bank account {$id} not found for this tenant")`; `BankAccountService::listByUnit(int $systemUnitId): array`; `BankAccountService::findById(int $id): ?BankAccount`; `BankAccountService::totalBalanceCents(int $systemUnitId): ?int`: soma de `balanceCents()` das contas **ativas** da unidade; `null` quando a unidade não tem conta ativa.
- Consome: T-01 `bank_account`

**Teste RED**
- `src/tests/Unit/BankAccountServiceTest.php`, `src/tests/Support/FakeBankAccountRepository.php`, `src/tests/Integration/BankAccountRepositoryIntegrationTest.php` — falha antes da implementação porque as classes não existem (comando: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php`): duas contas ativas (1000 e −250) e uma inativa (500) na unidade 1 dão `totalBalanceCents(1) === 750`; a unidade sem conta dá `null`; nome repetido lança a mensagem exata; `update` do saldo preenche `balanceUpdatedAt`; no teste de integração, `save` + `findById` preserva `balance_cents` negativo e `listBySystemUnit` não traz conta de outro tenant;

**Critério de aceite**
- SUITE: `PASS  Unit\BankAccountServiceTest::` e `PASS  Integration\BankAccountRepositoryIntegrationTest::` em todos os métodos, `Failed: 0`.
- `SELECT COUNT(*) FROM bank_account` = 0 depois da suíte (rollback).

**Validação**
- LINT dos 6 PHP de Core/Support (evidência: 6 `No syntax errors detected`) + SUITE (evidência: as linhas `PASS` citadas, `Failed: 0`)
- `SELECT COUNT(*) FROM bank_account` depois da suíte (evidência: `0`)

### T-16 — Pausa de atendimento (Core)

**Camada:** backend
**Dependências:** T-01
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Yoda

Campo sem schema: pausa. O status continua `in_progress`, e `paused_at` não nulo indica pausa. `EncounterService` já tem `start`/`finish` com `$action` e autorização. Os nomes `onStart`/`onAutosave`/`onFinish` de `EncounterView` não mudam, porque as strings de auditoria de `EncounterTimelineIntegrationTest.php:69-86` dependem deles.

**Arquivos prováveis**
- `src/app/Core/Domain/Encounter.php`
- `src/app/Core/Persistence/EncounterRepository.php`
- `src/tests/Support/FakeEncounterRepository.php`
- `src/app/Core/Application/EncounterService.php`
- `src/tests/Unit/EncounterServiceTest.php`

**Interface**
- Produz: `Encounter::pause(DateTimeImmutable $now): void` e `Encounter::resume(DateTimeImmutable $now): void`: `pause` em atendimento finalizado ou já pausado → `InvalidStatusTransitionException`; `resume` sem pausa → `InvalidStatusTransitionException`.
  `resume` soma `now - pausedAt` (segundos inteiros) a `pausedSeconds` e zera `pausedAt`.
- Produz: `Encounter::isPaused(): bool`, `Encounter::pausedAt(): ?DateTimeImmutable` e `Encounter::pausedSeconds(): int`. `finish()` de atendimento pausado soma o trecho pausado antes de finalizar e deixa `pausedAt` nulo; `reconstitute` lê `paused_at`/`paused_seconds` com `?? null`/`?? 0`; `EncounterRepository` grava e lê as duas colunas.
- Produz: `EncounterService::pause(int $id, string $action, ?DateTimeImmutable $now = null): Encounter` e `EncounterService::resume(int $id, string $action, ?DateTimeImmutable $now = null): Encounter` (`$now` nulo = `new DateTimeImmutable()`, como em `finish()`), com a mesma autorização de `finish()` (mesmo `entityType`, unidade do atendimento). Id inexistente → a mesma exceção de `finish()`.
- Consome: T-01 `encounter.paused_at`, T-01 `encounter.paused_seconds`

**Teste RED**
- `src/tests/Unit/EncounterServiceTest.php` — com os Fakes do teste atual e o `$now` passado explicitamente a `pause`/`resume`, falha antes da implementação porque `pause`/`resume` não existem (comando: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php`): `pause` → `isPaused()` true; `resume` 90 s depois → `pausedSeconds() === 90` e `isPaused()` false; `pause` duas vezes lança `InvalidStatusTransitionException`; `finish` de atendimento pausado grava `finishedAt`, `pausedAt()` nulo e `pausedSeconds` > 0; `pause` de atendimento finalizado lança `InvalidStatusTransitionException`;

**Critério de aceite**
- SUITE: `PASS  Unit\EncounterServiceTest::` e `PASS  Integration\EncounterTimelineIntegrationTest::` em todos os métodos, `Failed: 0`.

**Validação**
- LINT dos 4 PHP de Core/Support (evidência: 4 `No syntax errors detected`) + SUITE (evidência: as linhas `PASS` citadas, `Failed: 0`)
- Review Focus: finalizar atendimento pausado e pausar atendimento finalizado estão no RED; no gate da onda 3 (T-18), pela tela (evidência: `SELECT status, paused_at, paused_seconds, finished_at FROM encounter WHERE id=<id>` = `finished`, `NULL`, > 0, preenchido)

### T-17 — Fila: Editar paciente e Editar agendamento

**Camada:** frontend
**Dependências:** T-07, T-08
**Paralelizável:** sim
**Complexidade:** simples
**Agente:** Aang

`QueueEntryView` não tem link para os cadastros; o único item do menu da linha é "Avançar status" (:88-92). `queue_entry` tem `patient_id` e `appointment_id` (nulo quando é encaixe).

**Arquivos prováveis**
- `src/app/control/clinic/QueueEntryView.php`

**Interface**
- Produz: o menu da linha (`CvDatagrid::actionMenu`) ganha: `_t('Edit patient')` → `PatientForm&method=onEdit&key={patient_id}`; `_t('Edit appointment')` → `AppointmentForm&method=onEdit&key={appointment_id}`, só quando `appointment_id` não é nulo (`setDisplayCondition`).
  "Avançar status" continua o primeiro item.
- Consome: T-07 `PatientService::update(int $id, array $data): Patient`, T-08 `AppointmentService::reschedule(int $id, array $data, string $action): Appointment`

**Teste RED**
- sem teste: controller Adianti não é carregado por `tests/run.php`; a prova é o gate

**Critério de aceite**
- GATE:
  - numa entrada da fila com agendamento, "Editar paciente" abre `PatientForm` editável do paciente da linha, e "Editar agendamento" abre `AppointmentForm` editável do agendamento da linha;
  - numa entrada sem agendamento, o item "Editar agendamento" não aparece;
  - console 0 `error`.

**Validação**
- LINT de `QueueEntryView.php` (evidência: `No syntax errors detected`)
- GATE → os 3 casos do critério (evidência: URL com `key=<patient_id>`/`key=<appointment_id>` iguais a `SELECT patient_id, appointment_id FROM queue_entry WHERE id=<linha>`; campo editável no form; console 0 `error`). Sem entrada na fila (limitação da fase 10), o validador cria uma pelo `QueueEntryView` com o paciente "R2 varredura Pet"; se a tela não permitir, registra `[não rodado]` com o motivo

### T-18 — EncounterView: Pausar/Retomar, alergia/foto, onInlineAction, vazio após Finalizar

**Camada:** frontend
**Dependências:** T-03, T-12, T-16
**Paralelizável:** sim
**Complexidade:** alta
**Agente:** Yoda

O docblock de :27 diz "Sem botão Pausar"; o cabeçalho está em `pageHeader` (:399-445). Triagem:
- T-30: o docblock de `onInlineAction` (:1482-1492) diz que `kind` é validado contra `PLAN_ACTIONS` (:71-77), e não é;
- T-13: depois de Finalizar, o `TMessage` passa `['id']` a `onReload` (:1352, :1528), o construtor lê `encounter_id` (:90) e cai no vazio (:128-133);
- "Atendimento anterior" passa a vir de `lastEncounter` corrigido em T-03, sem mudança de chamada.
Manter o padrão `new TButton(); ->setAction(); ->setFormName('form_EncounterView_' . id)` da fase 08.

**Arquivos prováveis**
- `src/app/control/clinic/EncounterView.php`

**Interface**
- Produz: botões no cabeçalho: `_t('Pause')` → `EncounterView::onPause`, quando `in_progress` e não pausado; `_t('Resume')` → `EncounterView::onResume`, quando pausado.
  Os dois chamam `EncounterService::pause/resume($id, __CLASS__ . '::' . __FUNCTION__)` e recarregam com `encounter_id`. Pausado, o cabeçalho mostra o badge `_t('Paused')`, o cronômetro para, o tempo exibido desconta `pausedSeconds()` e o autosave continua.
- Produz: no cartão do paciente: alerta `.cv-alert-allergy` com `_t('Allergies')`: <texto> quando `allergies` não é vazio; `<img class="cv-patient-photo" src="engine.php?class=PatientForm&method=onPhoto&static=1&key=<patient_id>">` quando `photoObjectKey` não é nulo; senão, o avatar atual.
- Produz: `onInlineAction` recusa `kind` fora de `array_keys(self::PLAN_ACTIONS)` com `TMessage('error', _t('Invalid action'))`, sem gravar auditoria, e o docblock descreve isso.
- Produz: depois de Finalizar, Pausar e Retomar, o recarregamento passa `encounter_id` (não `id`): a tela mostra o atendimento, e não o vazio "Informe um encounter_id…".
- Consome: T-16 `EncounterService::pause(int $id, string $action, ?DateTimeImmutable $now = null): Encounter`, T-16 `EncounterService::resume(int $id, string $action, ?DateTimeImmutable $now = null): Encounter`, T-16 `Encounter::isPaused(): bool`, T-16 `Encounter::pausedSeconds(): int`, T-12 `Patient::$allergies`, T-12 `Patient::$photoObjectKey`, T-03 `ClinicalSummaryService::lastEncounter(int $patientId, ?int $excludeEncounterId = null): ?array`

**Teste RED**
- sem teste: controller Adianti não é carregado por `tests/run.php`; a regra de pausa está no RED de T-16 e a tela no gate

**Critério de aceite**
- GATE (atendimento `R2 varredura` em andamento do paciente com alergia e foto de T-12):
  - Pausar mostra o badge "Pausado", e `SELECT paused_at IS NOT NULL FROM encounter WHERE id=<id>` = 1;
  - Retomar zera o badge, e `paused_seconds` > 0;
  - o alerta de alergia mostra "Dipirona" e a foto carrega (rede 200);
  - Finalizar mostra o próprio atendimento finalizado, e o texto "Informe um encounter_id" some.
- `php -r` chamando `onInlineAction(['encounter_id' => <id>, 'kind' => 'xyz'])` não grava `audit_log` (`SELECT COUNT(*) FROM audit_log` igual).

**Validação**
- LINT de `EncounterView.php` (evidência: `No syntax errors detected`) + SUITE (evidência: `PASS  Integration\EncounterTimelineIntegrationTest::`, `Failed: 0`)
- GATE → Pausar, Retomar e Finalizar no atendimento de teste (evidência: badge no snapshot; `SELECT status, paused_at, paused_seconds, finished_at`; texto "Informe um encounter_id" ausente após Finalizar; console 0 `error`)
- Review Focus (T-16): pausar e depois Finalizar sem retomar (evidência: `status=finished`, `paused_at` NULL, `paused_seconds` > 0)
- `SELECT COUNT(*) FROM audit_log` antes e depois do `php -r` com `kind=xyz` (evidência: igual)

### T-19 — PrescriptionForm: validade, Salvar como modelo, Aplicar modelo, estilos, onEdit

**Camada:** frontend
**Dependências:** T-13
**Paralelizável:** sim
**Complexidade:** alta
**Agente:** Kratos

Triagem T-12: 18 `style` inline (:313-455) e `onEdit` vazio sem chamador (:246-248). O docblock de :31 lista validade e modelos como omitidos, e o PDF é gerado em `onGeneratePdf` (:716-750, dompdf).

**Arquivos prováveis**
- `src/app/control/clinic/PrescriptionForm.php`
- `src/app/templates/adminbs5/cv-components.css`

**Interface**
- Produz: campo `valid_until` (TDate, `dd/mm/yyyy`, opcional), repassado como `Y-m-d` ao `create()`, e linha "Válida até dd/mm/aaaa" (`_t('Valid until')`) no PDF quando preenchido.
- Produz: botão `_t('Save as template')` → `TInputDialog` com o nome → `PrescriptionForm::onSaveTemplate`, que chama `saveFromItems($name, $orientation, $items, <usuário logado>)` com os itens da prescrição em edição. Sucesso mostra `TMessage('info', _t('Template saved'))`; nome repetido mostra `TMessage('error')`.
- Produz: combo `template_id` (modelos do tenant por `listAll()`) e botão `_t('Apply template')` → `PrescriptionForm::onApplyTemplate`, que substitui a lista de itens em edição pelos itens do modelo e a orientação pela do modelo; `findById` nulo → `TMessage('error', _t('Record not found'))`.
- Produz: nenhum atributo `style=` inline no PHP (`grep -c "style=" PrescriptionForm.php` = 0 e `grep -c "'style'"` = 0); as regras vão para classes `.cv-rx-*` em `cv-components.css`. `onEdit` vazio removido.
- Consome: T-13 `PrescriptionTemplateService::saveFromItems(string $name, ?string $orientationText, array $items, int $systemUserId): PrescriptionTemplate`, T-13 `PrescriptionTemplateService::listAll(): array`, T-13 `PrescriptionTemplateService::findById(int $id): ?PrescriptionTemplate`, T-13 `Prescription::validUntil(): ?DateTimeImmutable`

**Teste RED**
- sem teste: controller Adianti não é carregado por `tests/run.php`; a regra está no RED de T-13 e a tela no gate

**Critério de aceite**
- `grep -cE "style=|'style'" src/app/control/clinic/PrescriptionForm.php` = 0 e `grep -c "function onEdit" src/app/control/clinic/PrescriptionForm.php` = 0.
- GATE (atendimento `R2 varredura` em andamento):
  - prescrição com 2 itens e validade hoje+30 → Salvar como modelo "R2 varredura Modelo" mostra "Modelo salvo" e `SELECT COUNT(*) FROM prescription_template_item WHERE template_id=<id>` = 2;
  - nova prescrição → Aplicar modelo preenche os 2 itens;
  - Salvar → `SELECT valid_until FROM prescription ORDER BY id DESC LIMIT 1` = hoje+30;
  - o PDF abre em nova aba com "Válida até";
  - o layout da tela segue igual ao da BASE (screenshot lado a lado);
  - console 0 `error`.

**Validação**
- LINT de `PrescriptionForm.php` (evidência: `No syntax errors detected`)
- `grep -cE "style=|'style'" /var/www/html/centralvet/src/app/control/clinic/PrescriptionForm.php` (evidência: `0`)
- GATE → os fluxos do critério (evidência: `SELECT`s citados, texto "Válida até" no PDF, screenshots antes e depois em `.playwright-mcp/r2-prescricao-{base,depois}.png`, console 0 `error`)

### T-20 — FinancialOverview: saldo bancário, Exportar CSV, recentes no período, "vs. período anterior"

**Camada:** frontend
**Dependências:** T-04, T-14, T-15
**Paralelizável:** sim
**Complexidade:** alta
**Agente:** Tesla

Triagem T-09: "Lançamentos recentes" ignora o período (:49), mas o vazio diz "neste período" (:247). O KPI mostra "vs. previous month" fixo (`CvKpiCard.php:43`), mas `FinancialOverviewService::totals` compara com o período anterior de mesmo tamanho. Ação Exportar (hoje omitida, cabeçalho em :32) e saldo bancário.

**Arquivos prováveis**
- `src/app/control/clinic/FinancialOverview.php`
- `src/app/Core/Application/FinancialOverviewService.php`
- `src/app/Core/Persistence/FinancialOverviewReader.php`
- `src/tests/Integration/FinancialOverviewIntegrationTest.php`
- `src/app/lib/widget/CvKpiCard.php`

**Interface**
- Produz: `FinancialOverviewService::recentEntries(int $systemUnitId, int $limit = 5, ?DateTimeImmutable $from = null, ?DateTimeImmutable $to = null): array`, com os mesmos limites inclusivos de `totals()`. Sem período, o comportamento de hoje fica; `FinancialOverviewReader::recentEntries` ganha o filtro de período.
- Produz: `CvKpiCard::create(string $icon, string $tone, string $value, string $label, ?float $deltaPercent = null, ?string $deltaLabel = null): TElement`. Com `$deltaLabel` nulo o texto continua `_t('vs. previous month')`, e `ProductList` não muda. `FinancialOverview` passa `_t('vs. previous period')`.
- Produz: KPI `_t('Bank balance')` com `CvFormat::money(totalBalanceCents($unitId))`, ao lado do KPI de caixa. Com `null`, mostra `—` e o rótulo `_t('No bank account')`, com link para `BankAccountList`.
- Produz: ação de cabeçalho `_t('Export')`, com spec `'target' => '_blank'` e href `engine.php?class=FinancialOverview&method=onExport&static=1&from=<Y-m-d>&to=<Y-m-d>`. `FinancialOverview::onExport($param)` (static) responde: cabeçalhos `Content-Type: text/csv; charset=UTF-8` e `Content-Disposition: attachment; filename="financeiro-<from>-<to>.csv"`; corpo com BOM UTF-8 e separador `;`; cabeçalho com `_t('Date')`, `_t('Type')`, `_t('Category')`, `_t('Payment method')`, `_t('Reference')` e `_t('Amount')`; uma linha por lançamento de `FinancialEntryService::listByPeriod($unitId, $from, $to)`, com data `dd/mm/yyyy HH:ii`, tipo traduzido, categoria por `CvFormat::paymentMethod()`, forma de pagamento por `CvFormat::paymentMethod()` ou vazio e valor `1234,56` com sinal negativo nas despesas.
- Consome: T-15 `BankAccountService::totalBalanceCents(int $systemUnitId): ?int`, T-14 `FinancialEntry::paymentMethod(): ?string`, T-04 `'target' => '_blank'`

**Teste RED**
- `src/tests/Integration/FinancialOverviewIntegrationTest.php` — com lançamentos semeados dentro e fora de `[from, to]`, `recentEntries($unit, 10, $from, $to)` devolve só os de dentro, do mais novo ao mais antigo; falha antes da implementação porque o parâmetro não existe e hoje vêm também os de fora (comando: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php`)

**Critério de aceite**
- SUITE: `PASS  Integration\FinancialOverviewIntegrationTest::` em todos os métodos, `Failed: 0`.
- GATE:
  - com o filtro num período sem lançamentos, "Lançamentos recentes" mostra o vazio "neste período";
  - com o mês atual, mostra os lançamentos do mês;
  - os KPIs dizem "vs. período anterior";
  - o KPI de saldo mostra a soma das contas de T-22 (ou `—` sem conta);
  - Exportar baixa `financeiro-<from>-<to>.csv`, com o número de linhas de dados = `SELECT COUNT(*) FROM financial_entry WHERE system_unit_id=<u> AND occurred_at >= <from> AND occurred_at < <to>+1`, e a coluna "Forma de pagamento" traz "Pix" no lançamento de T-14;
  - console 0 `error`.

**Validação**
- LINT dos 4 PHP (evidência: 4 `No syntax errors detected`) + SUITE (evidência: `PASS  Integration\FinancialOverviewIntegrationTest::`, `Failed: 0`)
- `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php -r 'chdir("/var/www/html/src"); require "init.php"; echo CvKpiCard::create("fa:x","info","1","L",1.0);'` (evidência: contém `vs. mês anterior` ou a chave — `ProductList` inalterado)
- GATE → os fluxos do critério; `browser_network_requests` do Exportar (evidência: 200, `content-type: text/csv`, `content-disposition` com o nome); contagem de linhas do CSV × `SELECT COUNT(*)` (evidência: iguais)

### T-21 — ProductList: constantes, overview(), preço/código, Gerar relatório PDF

**Camada:** frontend
**Dependências:** T-03, T-04, T-11
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Darwin

Triagem:
- T-35: o filtro `attention` usa os literais `'low'`/`'out'` (:103);
- T-10: `products()` roda 3x por carga (:100 + `summary()` + `lowStock()`).
Também entram as colunas de preço de venda e código, e a ação Gerar relatório (hoje omitida, cabeçalho em :71).

**Arquivos prováveis**
- `src/app/control/clinic/ProductList.php`

**Interface**
- Produz: o filtro `attention` compara com `StockSalesOverviewService::STATUS_LOW`/`STATUS_OUT`; a carga usa **uma** chamada a `overview(...)` no lugar de `summary()` + `products()` + `lowStock()`; a tabela ganha as colunas `_t('Code')` e `_t('Sale price')` (`CvFormat::money` ou `—`), lidas das chaves `code` e `sale_price_cents` de cada linha de `products` do `overview()`.
- Produz: ação de cabeçalho `_t('Generate report')`, com spec `'target' => '_blank'` e href `engine.php?class=ProductList&method=onReport&static=1` mais os filtros atuais (`search`, `category`, `status`). `ProductList::onReport($param)` (static) gera PDF por dompdf, no padrão de `SaleForm::onGenerateReceiptPdf`: `Content-Type: application/pdf` e `Content-Disposition: inline; filename="estoque-<Y-m-d>.pdf"`; título "Relatório de estoque", data e filtros; tabela com nome, código, categoria, estoque, mínimo, status traduzido, custo e preço de venda.
- Consome: T-03 `StockSalesOverviewService::overview(DateTimeImmutable $month, ?string $search = null, ?string $category = null, ?string $status = null, int $lowStockLimit = 5): array`, T-11 `code`, T-11 `sale_price_cents`, T-04 `'target' => '_blank'`

**Teste RED**
- sem teste: controller Adianti não é carregado por `tests/run.php`; `overview()` está no RED de T-03 e a tela no gate

**Critério de aceite**
- `grep -cE "'low'|'out'" src/app/control/clinic/ProductList.php` = 0 e `grep -cE -- "->(summary|products|lowStock)\(" src/app/control/clinic/ProductList.php` = 0.
- GATE:
  - `ProductList` mostra código `R2-001` e preço R$ 19,90 no produto de T-11;
  - `status=attention` lista low+out (contagem igual à da BASE);
  - Gerar relatório abre em nova aba um PDF (rede 200, `application/pdf`) com o mesmo número de linhas que a tela com os mesmos filtros;
  - console 0 `error`.

**Validação**
- LINT de `ProductList.php` (evidência: `No syntax errors detected`)
- `grep -cE "'low'|'out'" /var/www/html/centralvet/src/app/control/clinic/ProductList.php` (evidência: `0`)
- GATE → os fluxos do critério (evidência: células no snapshot, `browser_network_requests` do PDF 200 `application/pdf`, console 0 `error`)

### T-22 — BankAccountList/BankAccountForm e aba no CvNav

**Camada:** frontend
**Dependências:** T-01, T-15
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Athena

Telas do saldo bancário. Os programas são registrados pelo DML de T-01, e o padrão visual é o de `PayableList`/`PayableForm` (TPage, `CvPage::header`, `CvNav::group('finance')`).

**Arquivos prováveis**
- `src/app/control/clinic/BankAccountList.php`
- `src/app/control/clinic/BankAccountForm.php`
- `src/app/lib/widget/CvNav.php`

**Interface**
- Produz: `CvNav::group('finance')` ganha a aba `'bank_accounts' => ['Bank accounts', 'index.php?class=BankAccountList']`, depois de `cashflow`.
- Produz: `BankAccountList`: lista as contas da unidade da sessão (`listByUnit`), com as colunas nome, banco, saldo (`CvFormat::money`, negativo em vermelho), atualizado em e situação; ações Novo (→ `BankAccountForm`) e Editar (→ `BankAccountForm&method=onEdit&key=<id>`); rodapé com o total das contas ativas (`totalBalanceCents`).
- Produz: `BankAccountForm` com os campos nome, banco, saldo (moeda, aceita negativo) e ativo. `onSave` chama `create([... 'system_unit_id' => <unidade da sessão>])` ou `update($id, ...)`; `onEdit` de id de outro tenant mostra `_t('Record not found')`.
- Consome: T-15 `BankAccountService::create(array $data): BankAccount`, T-15 `BankAccountService::update(int $id, array $data): BankAccount`, T-15 `BankAccountService::listByUnit(int $systemUnitId): array`, T-15 `BankAccountService::findById(int $id): ?BankAccount`, T-15 `BankAccountService::totalBalanceCents(int $systemUnitId): ?int`, T-01 `BankAccountList`, T-01 `BankAccountForm`

**Teste RED**
- sem teste: controllers Adianti não são carregados por `tests/run.php`; a regra está no RED de T-15 e as telas no gate

**Critério de aceite**
- GATE:
  - aba "Contas bancárias" no Financeiro → `BankAccountList`;
  - Novo "R2 varredura Conta" com saldo 1.000,00 → Salvar, e `SELECT balance_cents FROM bank_account WHERE name='R2 varredura Conta'` = 100000;
  - editar o saldo para −50,00 faz `balance_cents` = −5000 e preenche `balance_updated_at`;
  - o rodapé mostra −R$ 50,00;
  - `FinancialOverview` mostra o mesmo valor no KPI (T-20);
  - `BankAccountForm&method=onEdit&key=999999` mostra não encontrado;
  - console 0 `error`.

**Validação**
- LINT dos 3 PHP (evidência: 3 `No syntax errors detected`)
- GATE → os fluxos do critério (evidência: `SELECT`s citados, texto do rodapé e do KPI no snapshot, console 0 `error`, rede 0 ≥ 400)

### T-23 — i18n do board e mensagem de CrossTenantReferenceException nos 14 controllers

**Camada:** frontend
**Dependências:** T-02, T-04, T-05, T-06, T-07, T-08, T-10, T-11, T-12, T-17, T-18, T-19, T-20, T-21, T-22
**Paralelizável:** não
**Complexidade:** média
**Agente:** Platão

Escritor único de `translations.json`: grava as linhas `i18n:` do board das ondas 1–3. Triagem "Mensagem em inglês de CrossTenantReferenceException nos forms": hoje os controllers repassam `$e->getMessage()`, em inglês e com o id. Estes são os pontos:
- `EncounterAccountForm` 183, 455, 535, 614;
- `EncounterView` 196, 376, 1359, 1591;
- `ProcedureInputForm` 292;
- `VaccineProtocolForm` 244;
- `SaleForm` 571;
- `ProductForm` 210;
- `ExamRequestForm` 203;
- `StockBatchForm` 205;
- `AppointmentForm` 207;
- `ExamResultForm` 214;
- `VaccinationForm` 250;
- `ProcedureExecutionForm` 251;
- `PrescriptionForm` 674;
- `PatientForm` 299-305, que já traduz e é o modelo.
As linhas mudaram nas ondas 2–3: localizar por `grep -n "CrossTenantReferenceException"`.

**Arquivos prováveis**
- `src/app/config/translations.json`
- `src/app/lib/widget/CvFormat.php`
- `src/app/control/clinic/EncounterAccountForm.php`
- `src/app/control/clinic/EncounterView.php`
- `src/app/control/clinic/ProcedureInputForm.php`
- `src/app/control/clinic/VaccineProtocolForm.php`
- `src/app/control/clinic/SaleForm.php`
- `src/app/control/clinic/ProductForm.php`
- `src/app/control/clinic/ExamRequestForm.php`
- `src/app/control/clinic/StockBatchForm.php`
- `src/app/control/clinic/AppointmentForm.php`
- `src/app/control/clinic/ExamResultForm.php`
- `src/app/control/clinic/VaccinationForm.php`
- `src/app/control/clinic/ProcedureExecutionForm.php`
- `src/app/control/clinic/PrescriptionForm.php`
- `src/app/control/clinic/PatientForm.php`

**Interface**
- Produz: `CvFormat::userError(\Throwable $e): string`: `CrossTenantReferenceException` → `_t('The selected record does not belong to this clinic')`, sem id; demais → `$e->getMessage()`.
  Cada `catch (…CrossTenantReferenceException $e)` dos 14 controllers passa a usar `CvFormat::userError($e)` em `TMessage` e mantém `error_log` da mensagem original.
- Produz: `translations.json` com cada chave `en` das linhas `- [T-xx] i18n:` do board, com o `pt` pedido, e a chave de `userError`; sem duplicata e sem duplicata por caixa.
- Consome: nada

**Teste RED**
- sem teste: dicionário JSON e troca mecânica de mensagem em controllers Adianti, fora de `tests/run.php`; a prova é o script python de duplicatas, grep e o gate

**Critério de aceite**
- `python3 -c` sobre `translations.json`: JSON válido, 0 chaves `en` duplicadas, 0 duplicadas por caixa e todas as chaves do board presentes.
- `grep -rn "CrossTenantReferenceException \$e" src/app/control/clinic | wc -l` = número de `catch` e `grep -rn "CvFormat::userError" src/app/control/clinic | wc -l` ≥ esse número; nenhum `TMessage` desses catches usa `$e->getMessage()`.
- GATE: as telas das linhas `i18n:` do board sem "Message not found"; POST adulterado em `EncounterAccountForm` (conta #46, `authorized_by_system_user_id=999999`) mostra "O registro selecionado não pertence a esta clínica", sem o id.

**Validação**
- LINT dos 15 PHP (evidência: 15 `No syntax errors detected`) + SUITE (evidência: `Failed: 0`)
- `python3 -c "import json,collections;d=json.load(open('/var/www/html/centralvet/src/app/config/translations.json'));…"` — script que conta duplicatas `en` exatas e por `casefold()` e confere as chaves do board (evidência: `dup=0 dupcase=0 missing=0`)
- `grep -rn -A4 "CrossTenantReferenceException \$e" /var/www/html/centralvet/src/app/control/clinic | grep -c "getMessage()"` restrito às linhas de `TMessage` (evidência: `0`)
- GATE → telas do board e POST adulterado (evidência: textos em pt, nenhum "Message not found", console 0 `error`)

### T-24 — Validação final: suíte, varredura Playwright, Review Focus, dados preservados

**Camada:** qa
**Dependências:** T-23
**Paralelizável:** não
**Complexidade:** média
**Agente:** Spock

Task de QA, sem edição de código. Bug encontrado em tela de task das ondas 1–4 abre `### Onda 6 — correção (revisão final)` com IDs a partir de T-25.

**Arquivos prováveis**
- `.claude/tasks/mar-20260930-0823-rodada-2-divida-cadastros-schema-acoes/reports/T-24.md`

**Interface**
- Produz: `reports/T-24.md` com a tabela `Tela | Fluxos | Console (errors) | Rede (≥400) | Erro na tela | Veredito | Task dona` para todas as telas de `plan.md § Critérios gerais de aceite` (ondas 1–4) e do `menu.xml`, os 5 itens do Review Focus com evidência e as contagens de preservação de dados.
- Consome: nada

**Teste RED**
- sem teste: task de validação, não produz código

**Critério de aceite**
- SUITE com `Failed: 0` e `Total` ≥ 205 + os testes novos de T-02, T-03, T-06, T-07, T-08, T-09, T-11, T-12, T-13, T-14, T-15, T-16 e T-20.
- Cada linha da tabela com `0` erros de console, `0` requisições ≥ 400 e nenhum erro na tela, ou com a task dona indicada para a onda de correção.
- `SELECT COUNT(*)` de `product`, `patient`, `prescription`, `financial_entry` e `encounter` ≥ os valores anotados antes da migration (nenhum registro perdido).

**Validação**
- SUITE (evidência: `Total: N, Passed: N, Failed: 0`)
- GATE → varredura completa (evidência: tabela no relatório)
- `SELECT COUNT(*)` das 5 tabelas × contagens de `notes.md § Bloqueios` (evidência: todas ≥)

## Legenda

- [ ] pendente
- [~] em andamento
- [x] concluído com evidência
- [!] bloqueado
