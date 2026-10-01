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
| T-24 | qa | Validação final: suíte, varredura Playwright, Review Focus, dados preservados | T-23 | não | média | Spock | [x] |
| T-25 | frontend | Correção (usuário): AgendaView mostra agendamento fora do slot exato de 30 min | — | sim | média | Sherlock | [x] |
| T-26 | backend | Correção (code-review): importCsv valida tamanhos e limites por linha | — | sim | média | Levi | [x] |
| T-27 | backend | Correção (code-review): peso do paciente com vírgula decimal e recusa de texto | — | sim | média | Naruto | [x] |
| T-28 | frontend | Onda 8: catálogo de mensagens de domínio em pt, `CvFormat::userError` testado e i18n da onda | — | sim | alta | Platão | [x] |
| T-29 | frontend | Onda 8: check-in da fila pela Agenda, menu da fila e agendamento inexistente | — | sim | alta | Aang | [x] |
| T-30 | frontend | Onda 8: PrescriptionForm sem patient_id, combo de modelos e remoção de item | — | sim | média | Kratos | [x] |
| T-31 | frontend | Onda 8: EncounterView com encounter_id nos retornos, foto/alergia por CSS e mensagens de pausa | — | sim | alta | Yoda | [x] |
| T-32 | backend | Onda 8: PatientForm/PatientService: foto (órfã, cache, nosniff), normalização e não encontrado | — | sim | alta | Naruto | [x] |
| T-33 | backend | Onda 8: ServiceList (Duplicar com confirmação, mensagens) e limites/testes de ServiceCatalogService | — | sim | média | Levi | [x] |
| T-34 | frontend | Onda 8: BankAccountForm/List: escape, mensagens e valor não numérico | — | sim | simples | Athena | [x] |
| T-35 | frontend | Onda 8: FinancialOverview, ProductList e formas de pagamento | — | sim | simples | Tesla | [x] |
| T-36 | backend | Onda 8: AuthorizationRequest UTF-8, TutorService, testes de reschedule e ordem da guarda de desconto | — | sim | média | Arquimedes | [x] |
| T-37 | backend | Onda 8: testes de integração faltantes e verify da 0007 | — | sim | média | Sherlock | [x] |
| T-38 | backend | Onda 8: modelos de prescrição (varchar, duplicado, N+1) e testes de produto | — | sim | média | Darwin | [x] |
| T-39 | frontend | Onda 8: CvPage (docblock, target restrito) e seletor de unidade única | — | sim | simples | Thanos | [x] |
| T-40 | database | Onda 9: migration 0008 — UNIQUE em queue_entry.appointment_id com dedupe prévio | — | sim | média | Jaspion | [x] |
| T-41 | backend | Onda 10: check-in traduz a violação de UNIQUE; Agenda troca Check-in por "Na fila" | T-40 | sim | média | Aang | [x] |
| T-42 | qa | Onda 9: Redis dos testes isolado das sessões e RedisQueueIntegrationTest determinístico | — | sim | média | Naruto | [x] |
| T-43 | frontend | Onda 9: ServiceList com o nome nas confirmações e i18n da onda 9 | — | sim | simples | Levi | [x] |
| T-44 | frontend | Onda 9: PrescriptionForm com userError nos catches restantes | — | sim | simples | Kratos | [x] |
| T-45 | frontend | Onda 9: EncounterView com userError nos catches restantes e gate de anexo/retorno/IA | — | sim | média | Yoda | [x] |
| T-46 | frontend | Onda 9: FinancialOverview sem nosniff pelo PHP | — | sim | simples | Thanos | [x] |
| T-47 | backend | Onda 9: foto anterior apagada só depois do commit e teste do discardPhoto | — | sim | média | Tesla | [x] |
| T-48 | backend | Onda 9: MoneyInput compartilhado e BankAccountForm com teto | — | sim | média | Athena | [x] |
| T-49 | backend | Onda 9: testes mais fortes de T-28, T-36 e T-38 | — | sim | simples | Arquimedes | [x] |
| T-50 | frontend | Onda 10: MoneyInput nos 9 formulários com toCents | T-48 | sim | média | Darwin | [x] |
| T-51 | frontend | Onda 10: mensagens de agendamento em pt e i18n da onda 10 | — | sim | simples | Platão | [x] |
| T-52 | backend | Onda 10: anexos do atendimento registrados em stored_object e listados | — | sim | alta | Sherlock | [x] |
| T-53 | backend | Onda 11: data/hora do agendamento sem troca dia/mês; PDO com texto genérico | — | sim | alta | Kratos | [x] |
| T-54 | frontend | Onda 11: FinancialEntryForm append-only, MoneyInput com zeros à esquerda, limpeza de T-50, i18n | — | sim | média | Darwin | [x] |
| T-55 | qa | Onda 11: queda da sessão do navegador depois da suíte e intervalo de TEST_REDIS_DATABASE | — | sim | alta | Naruto | [x] |
| T-56 | backend | Onda 11: anexos do ExamResultForm em stored_object, sem órfão e com nome UTF-8 | — | sim | média | Sherlock | [x] |
| T-57 | frontend | Onda 11: badge "Na fila" só para agendamento ativo e ordem do Fake da fila | — | sim | simples | Aang | [x] |
| T-58 | backend | Onda 11: foto nova apagada se o commit falhar e teste do error_log | — | sim | simples | Tesla | [x] |
| T-59 | backend | Onda 11: contador de save no FakeEncounterAccountRepository | — | sim | simples | Arquimedes | [x] |
| T-60 | frontend | Onda 12: retorno do EncounterView pelo DateTimeInput e catches do ExamResultForm | T-53, T-56 | não | simples | Yoda | [x] |
| T-61 | backend | Onda 12: RedisConnectionFactory recusa DB inválido e SELECT com falha | — | sim | simples | Naruto | [x] |

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

### T-25 — Correção (usuário): AgendaView mostra agendamento fora do slot exato de 30 min

**Camada:** frontend
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Sherlock

Achado da QA de T-24 (`reports/T-24.md`), com correção pedida pelo usuário. Em `AgendaView::renderGrid`, `src/app/control/clinic/AgendaView.php:302` só desenha o agendamento quando `$appointment->scheduledAt->format('H:i') === $slot`. Os slots vão de 07:00 a 18:30, de 30 em 30 min (`SLOT_START_MINUTES`/`SLOT_END_MINUTES`/`SLOT_STEP_MINUTES`, :26-28). Com isso, os agendamentos 3 (14:21) e 1 (15:59) não aparecem na grade, e o mesmo acontece com qualquer horário fora de 07:00–18:30. Reprodução exigida em `## RED`:
- `SELECT id, scheduled_at, professional_system_user_id FROM appointment WHERE id IN (1, 3)`;
- `AgendaView` na data desses agendamentos, onde os blocos ficam ausentes, com o snapshot da BASE.
T-24 roda em paralelo e não toca estes arquivos.

**Arquivos prováveis**
- `src/app/Core/Application/AgendaSlots.php`
- `src/tests/Unit/AgendaSlotsTest.php`
- `src/app/control/clinic/AgendaView.php`

**Interface**
- Produz: `CentralVet\Application\AgendaSlots` (final, sem dependência de Adianti) com `AgendaSlots::__construct(int $startMinutes, int $endMinutes, int $stepMinutes)`, `AgendaSlots::slots(): array` (`list<string>` `H:i`, igual ao `buildTimeSlots()` atual) e `AgendaSlots::slotFor(DateTimeImmutable $at): string`:
  - horário dentro da grade → o slot de início imediatamente anterior ou igual (arredonda para baixo ao múltiplo de `stepMinutes` a partir de `startMinutes`): 14:21 → `14:00`, 15:59 → `15:30`, 14:30 → `14:30`;
  - antes de `startMinutes` → o primeiro slot (`07:00`);
  - em `endMinutes` ou depois → o último slot (`18:30`);
  - `stepMinutes <= 0` ou `endMinutes <= startMinutes` no construtor → `InvalidArgumentException('Invalid agenda slot configuration')`.
- Produz: `AgendaView` monta a grade com `new AgendaSlots(self::SLOT_START_MINUTES, self::SLOT_END_MINUTES, self::SLOT_STEP_MINUTES)`. `renderGrid` agrupa os agendamentos por `slotFor($appointment->scheduledAt)` e não compara mais `format('H:i') === $slot`, e `buildTimeSlots()` delega a `slots()`. Na célula, os agendamentos vêm em ordem de `scheduledAt`. O bloco (`renderAppointmentBlock`) mostra o horário exato `H:i` antes do paciente (`<span class="agenda-block-time">14:21</span>`); o link e o badge não mudam.
- Consome: nada

**Teste RED**
- `src/tests/Unit/AgendaSlotsTest.php` — cobre dois grupos de casos e falha antes da implementação porque a classe `AgendaSlots` não existe (comando: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php`): com `new AgendaSlots(420, 1140, 30)`, `slotFor` de `2026-09-30 14:21` = `14:00`, de `15:59` = `15:30`, de `14:30` = `14:30`, de `06:45` = `07:00` e de `19:10` = `18:30`; `slots()` tem 24 itens, de `07:00` a `18:30`; `new AgendaSlots(420, 1140, 0)` lança `InvalidArgumentException` com a mensagem exata

**Critério de aceite**
- SUITE: `PASS  Unit\AgendaSlotsTest::` em todos os métodos, `Failed: 0`.
- `grep -c "format('H:i') === \$slot" src/app/control/clinic/AgendaView.php` = 0.
- GATE:
  - na data do agendamento 3, a linha `14:00` da coluna do profissional dele mostra o bloco do agendamento 3 com `14:21`;
  - na data do agendamento 1, a linha `15:30` da coluna do profissional dele mostra o bloco do agendamento 1 com `15:59`;
  - os agendamentos em slot exato seguem na mesma linha que antes;
  - o número de blocos `.agenda-block` do dia = `SELECT COUNT(*) FROM appointment WHERE DATE(scheduled_at) = <data> AND professional_system_user_id IN (<profissionais da grade>)`;
  - clicar no bloco abre `AppointmentForm&key=<id>`;
  - console 0 `error`.

**Validação**
- LINT de `AgendaSlots.php` e `AgendaView.php` (evidência: 2 `No syntax errors detected`) + SUITE (evidência: `PASS  Unit\AgendaSlotsTest::`, `Failed: 0`)
- `grep -c "format('H:i') === \$slot" /var/www/html/centralvet/src/app/control/clinic/AgendaView.php` (evidência: `0`)
- GATE → `AgendaView` na(s) data(s) de `SELECT id, DATE(scheduled_at), TIME(scheduled_at), professional_system_user_id FROM appointment WHERE id IN (1, 3)` (evidência: snapshot com o bloco do 3 na linha 14:00 e do 1 na linha 15:30, com os horários 14:21/15:59; `document.querySelectorAll('.agenda-block').length` = `SELECT COUNT(*)` do dia; clique abre `key=<id>`; console 0 `error`)
- Review Focus: agendamento `R2 varredura` criado pelo `AppointmentForm` às 06:45 ou às 19:10 da mesma data, se a regra de agendamento aceitar esse horário (evidência: o bloco aparece na linha `07:00` ou `18:30` com o horário exato; se o formulário recusar o horário, fica registrado com a mensagem, e o caso vale pelo RED)

### T-26 — Correção (code-review): importCsv valida tamanhos e limites por linha

**Camada:** backend
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Levi

Achado do `/code-review` da branch, em `src/app/Core/Application/ServiceCatalogService.php:173`. `importCsv()` não valida o tamanho de `name` (coluna `varchar(190)`) nem de `category` (`varchar(60)`), nem o teto de `duration_minutes`/`price_cents` (`int unsigned`, máx. 4294967295; `ctype_digit` aceita dígitos além do tipo). O INSERT falha, `ServiceImportForm` (catch `Exception` → `TTransaction::rollback()` + `$e->getMessage()`) desfaz a importação inteira e mostra o erro cru do banco. Reprodução exigida em `## RED`: CSV com uma linha válida e uma linha com nome de 191 caracteres. Na BASE, `importCsv` devolve erro de banco e `SELECT COUNT(*) FROM service` fica igual; a evidência é o `php -r` no container dentro de `TTransaction` com rollback, ou só a leitura do código se o `php -r` gravar.

**Arquivos prováveis**
- `src/app/Core/Application/ServiceCatalogService.php`
- `src/tests/Unit/ServiceCatalogServiceTest.php`
- `src/app/control/clinic/ServiceImportForm.php`

**Interface**
- Produz: `ServiceCatalogService::importCsv(string $csv): array` (assinatura e formato de retorno de T-09 mantidos) valida cada linha antes do `create()`, e a linha inválida entra em `skipped` sem abortar as demais:
  - `mb_strlen(trim($name)) > 190` → `reason` `name too long`;
  - `mb_strlen(trim($category)) > 60` → `reason` `category too long`;
  - `duration_minutes` fora de `1..4294967295` (comparar como string de dígitos com até 10 caracteres antes do cast) → `invalid duration_minutes`;
  - `price_cents` calculado acima de `4294967295` → `invalid price`;
  - `InvalidArgumentException` lançada por `create()`/`Service::create` numa linha → `skipped` com `reason` = mensagem da exceção.
  As constantes `ServiceCatalogService::MAX_NAME_LENGTH = 190`, `MAX_CATEGORY_LENGTH = 60` e `MAX_UNSIGNED_INT = 4294967295` são usadas nas checagens.
- Produz: `ServiceImportForm::onImport` troca a mensagem crua de `\PDOException` (e de qualquer `Exception` que não seja `InvalidArgumentException`) por `TMessage('error', _t('Could not import the file. No service was created'))`, grava a mensagem original em `error_log` e mantém o rollback. `InvalidArgumentException` (cabeçalho inválido, arquivo) continua mostrando a própria mensagem.
- Produz: as chaves i18n `name too long` → "nome muito longo (máx. 190 caracteres)", `category too long` → "categoria muito longa (máx. 60 caracteres)" e `Could not import the file. No service was created` → "Não foi possível importar o arquivo. Nenhum serviço foi criado". Elas são gravadas por T-27, escritor único de `translations.json` na onda 7; T-26 não edita o JSON.
- Consome: nada

**Teste RED**
- `src/tests/Unit/ServiceCatalogServiceTest.php` — `importCsv` com cabeçalho válido e 5 linhas (1 válida, nome de 191 caracteres, categoria de 61, `duration_minutes` = `4294967296`, preço `42949672,96`) devolve `created: 1` e `skipped` com as linhas 3, 4, 5 e 6 e os motivos `name too long`, `category too long`, `invalid duration_minutes` e `invalid price`, e `storedCount()` +1; falha antes da correção porque hoje as 5 linhas chegam ao `create()` e os motivos novos não existem (comando: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php`)

**Critério de aceite**
- SUITE: `PASS  Unit\ServiceCatalogServiceTest::` em todos os métodos (inclusive os de T-09), `Failed: 0`.
- GATE:
  - `ServiceImportForm` com um CSV de 3 linhas (1 válida "R2 varredura Import 1", 1 com nome de 191 caracteres e 1 com categoria de 61) mostra "Serviços criados: 1" e as linhas 3 e 4 como ignoradas, com "nome muito longo"/"categoria muito longa" (ou a chave, se T-27 ainda não gravou o JSON);
  - `SELECT COUNT(*) FROM service` fica +1;
  - nenhum texto `SQLSTATE` na tela;
  - console 0 `error`.

**Validação**
- LINT de `ServiceCatalogService.php` e `ServiceImportForm.php` (evidência: 2 `No syntax errors detected`) + SUITE (evidência: `PASS  Unit\ServiceCatalogServiceTest::`, `Failed: 0`)
- `grep -n "SQLSTATE\|getMessage()" /var/www/html/centralvet/src/app/control/clinic/ServiceImportForm.php` (evidência: `getMessage()` só no `error_log` e no ramo `InvalidArgumentException`)
- GATE → importação do CSV de 3 linhas criado em `.playwright-mcp/r2-import-limites.csv` (evidência: mensagem com 1 criado e 2 ignorados, `SELECT COUNT(*) FROM service` antes e depois +1, snapshot sem `SQLSTATE`, console 0 `error`)
- Review Focus: CSV com o nome de 190 caracteres exatos → a linha é criada, sem ser ignorada (evidência: `SELECT CHAR_LENGTH(name) FROM service ORDER BY id DESC LIMIT 1` = 190)

### T-27 — Correção (code-review): peso do paciente com vírgula decimal e recusa de texto

**Camada:** backend
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Naruto

Achado do `/code-review` da branch. `PatientService::update()` (a linha citada no achado, :1114, é do diff; no arquivo é `src/app/Core/Application/PatientService.php:138-148`) faz `(float) $weight`: `"4,5"` grava `4` e `"abc"` grava `0` sem erro, e o peso alimenta dose clínica. `create()` (:84) tem o mesmo cast. O campo `weight_kg` do `PatientForm` (:140) é um `TEntry` sem máscara. A coluna é `patient.weight_kg decimal(6,2)`, com máximo 9999,99. Reprodução exigida em `## RED`: o teste abaixo antes da correção, que mostra `weightKg` = 4.0 para `"4,5"` e 0.0 para `"abc"`.

**Arquivos prováveis**
- `src/app/Core/Application/PatientService.php`
- `src/tests/Unit/PatientServiceTest.php`
- `src/app/control/clinic/PatientForm.php`
- `src/app/config/translations.json`

**Interface**
- Produz: `PatientService::INVALID_WEIGHT_MESSAGE = 'weight_kg must be a number between 0 and 9999.99, e.g. 4,5'` (public const) e a conversão única `private static function parseWeightKg(mixed $value): ?float`, usada por `create()` e `update()`:
  - `null` ou `''`/espaços → `null`;
  - `int`/`float` → `(float)`;
  - string aparada casando `^\d{1,4}([.,]\d{1,2})?$` → vírgula trocada por ponto e `(float)` (`"4,5"` → 4.5, `"4.50"` → 4.5, `"12"` → 12.0);
  - qualquer outro valor (`"abc"`, `"4,5kg"`, `"-1"`, `"1.234,5"`) → `InvalidArgumentException(PatientService::INVALID_WEIGHT_MESSAGE)`;
  - valor numérico acima de 9999.99 → a mesma exceção.
- Produz: `PatientForm`:
  - `weight_kg` com `setNumericMask(2, ',', '.', true)` (o POST chega com ponto);
  - `onEdit` exibe o peso salvo com vírgula;
  - no `catch (InvalidArgumentException $e)` do `onSave`, a mensagem igual a `PatientService::INVALID_WEIGHT_MESSAGE` vira `TMessage('error', _t(PatientService::INVALID_WEIGHT_MESSAGE))`, e as demais seguem como hoje;
  - os dados digitados ficam no formulário.
- Produz: em `translations.json`, `weight_kg must be a number between 0 and 9999.99, e.g. 4,5` → "Peso inválido: informe um número entre 0 e 9999,99, ex.: 4,5", mais as 3 chaves que T-26 fixou na Interface (`name too long`, `category too long`, `Could not import the file. No service was created`) com o `pt` de lá. Sem duplicata nem duplicata por caixa.
- Consome: nada

**Teste RED**
- `src/tests/Unit/PatientServiceTest.php` — `update(P, [... 'weight_kg' => '4,5'])` devolve `weightKg === 4.5`; `create([... 'weight_kg' => '4,5'])` devolve 4.5; `update(P, [... 'weight_kg' => 'abc'])` e `update(P, [... 'weight_kg' => '10000'])` lançam `InvalidArgumentException` com a mensagem exata `PatientService::INVALID_WEIGHT_MESSAGE`, e o paciente no Fake mantém o peso anterior; `weight_kg` `''` grava `null`. Falha antes da correção porque hoje `'4,5'` vira 4.0 e `'abc'` vira 0.0 sem exceção (comando: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php`)

**Critério de aceite**
- SUITE: `PASS  Unit\PatientServiceTest::` e `PASS  Unit\QueueEntryServiceTest::` em todos os métodos, `Failed: 0`.
- `grep -c "(float) \$weight\|(float) \$data\['weight_kg'\]" src/app/Core/Application/PatientService.php` = 0.
- Script python sobre `translations.json`: 0 duplicatas `en` exatas e 0 por `casefold()`, e as 4 chaves presentes.
- GATE:
  - `PatientForm` do paciente "R2 varredura Pet" → peso "4,5" → Salvar faz `SELECT weight_kg FROM patient WHERE id=<id>` dar `4.50`, e o campo reabre como "4,50";
  - POST com `weight_kg=abc` (valor forçado por `browser_evaluate`, porque a máscara bloqueia a digitação) mostra "Peso inválido: informe um número entre 0 e 9999,99, ex.: 4,5", e `weight_kg` continua `4.50`;
  - console 0 `error`.

**Validação**
- LINT de `PatientService.php` e `PatientForm.php` (evidência: 2 `No syntax errors detected`) + SUITE (evidência: as linhas `PASS` citadas, `Failed: 0`)
- `python3 -c "import json;d=json.load(open('/var/www/html/centralvet/src/app/config/translations.json'));…"` — conta duplicatas `en` e `casefold()` e confere as 4 chaves (evidência: `dup=0 dupcase=0 missing=0`)
- GATE → os 2 fluxos do critério (evidência: `SELECT weight_kg` depois de cada um, texto da mensagem no snapshot, console 0 `error`)
- Review Focus: paciente com `weight_kg` já gravado (ex.: 12.30) aberto e salvo sem mexer no peso → `weight_kg` continua `12.30`, sem virar `1230` nem `12` pela máscara (evidência: `SELECT weight_kg` antes e depois iguais); `SELECT COUNT(*) FROM patient WHERE weight_kg IS NOT NULL` igual antes e depois do gate

### T-28 — Onda 8: catálogo de mensagens de domínio em pt, `CvFormat::userError` testado e i18n da onda

**Camada:** frontend
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** alta
**Agente:** Platão

Pendências:
- T-23/T-24: "Patient sex must be one of M, F, U" (`Core/Domain/Patient.php:40`) e as mensagens de `BankAccountService` (:49, :84, :195) aparecem em inglês; `onPause` de atendimento finalizado mostra "Encounter 3408 is finished and cannot be paused" (`reports/T-24.md:72`);
- T-26: o `reason` dinâmico de `importCsv` passa por `_t()` e sai em inglês (`ServiceImportForm.php:105`);
- T-23: `CvFormat::userError` sem teste (`CvFormat.php:75`) e chave órfã "Selected tutor was not found for your account" (`translations.json:2291`);
- T-23: chaves ausentes `%s days` (`VaccineProtocolForm.php:180`) e `This entry cannot advance right now: ^1` (`QueueEntryView.php:385`);
- revisão final: o nome da conta vai para `TMessage` sem escape (self-XSS).
Escritor único de `translations.json` na onda 8: grava as chaves desta Interface e as que as outras tasks da onda fixaram (lista abaixo). Linha de board `i18n:` nova na onda vale só se a chave estiver nesta lista; fora dela, vira pendência.

**Arquivos prováveis**
- `src/app/Core/Presentation/UserMessage.php`
- `src/tests/Unit/UserMessageTest.php`
- `src/app/lib/widget/CvFormat.php`
- `src/tests/Unit/CvFormatUserErrorTest.php`
- `src/app/control/clinic/ServiceImportForm.php`
- `src/app/config/translations.json`

**Interface**
- Produz: `CentralVet\Presentation\UserMessage` (final, sem Adianti) com `UserMessage::resolve(string $message): ?array`. O retorno é `array{key: string, params: list<string>}` quando `$message` bate com uma entrada do catálogo, ou `null`. O catálogo são as constantes `UserMessage::STATIC` (mensagem exata → chave) e `UserMessage::PATTERNS` (regex ancorada → chave com `^1`/`^2`), com exatamente estas entradas: `STATIC`, com a chave igual à mensagem: `Patient sex must be one of M, F, U`, `Patient weight_kg cannot be negative`, `bank_name must have at most 120 characters`, `name must have at most 120 characters`, `balance_cents must be an integer`, `Service not found for this tenant`, `valid_until cannot be in the past`, `valid_until must be a Y-m-d date`, `items must be a non-empty list`, `full_name is required`, `phone is required`, `A tutor with this document already exists in this tenant`, `Invalid amount`; `PATTERNS`: `/^Encounter (\d+) is finished and cannot be paused$/` → `Encounter ^1 is finished and cannot be paused`; `/^Encounter (\d+) is already paused$/` → `Encounter ^1 is already paused`; `/^Encounter (\d+) is not paused$/` → `Encounter ^1 is not paused`; `/^A bank account named "(.+)" already exists for this unit$/` → `A bank account named "^1" already exists for this unit`; `/^Bank account \d+ not found for this tenant$/` → `Bank account not found`; `/^Service \d+ has appointments; deactivate it instead$/` → `This service has appointments; deactivate it instead`; `/^A service named "(.+)" already exists for this tenant$/` → `A service named "^1" already exists`; `/^A product named "(.+)" already exists for this tenant$/` → `A product named "^1" already exists`; `/^A product with code "(.+)" already exists for this tenant$/` → `A product with code "^1" already exists`; `/^A template named "(.+)" already exists for this tenant$/` → `A template named "^1" already exists`; `/^Appointment (\d+) cannot be rescheduled from status (\S+)$/` → `Appointment ^1 cannot be rescheduled from status ^2`; `/^Appointment (\d+) is already in the queue$/` → `This appointment is already in the queue`.
- Produz: `CvFormat::userError(\Throwable $e): string` (assinatura mantida) devolve texto seguro para `TMessage`: `CrossTenantReferenceException` → `_t('The selected record does not belong to this clinic')`, como hoje; senão, `UserMessage::resolve($e->getMessage())` não nulo → `_t($key, ...array_map([CvFormat::class, 'e'], $params))`; senão → `CvFormat::e($e->getMessage())`.
- Produz: `CvFormat::userMessage(string $message): string`, com a mesma regra para uma string.
- Produz: `ServiceImportForm` mostra cada `reason` com `CvFormat::userMessage($reason)` quando `UserMessage::resolve` o reconhece, e com `_t($reason)` para os motivos fixos de T-09/T-26.
- Produz: `translations.json`, sem duplicata exata nem por `casefold()`, com: as chaves das 2 listas acima, com o `pt`: `O sexo do paciente deve ser M, F ou U`; `O peso do paciente não pode ser negativo`; `O nome do banco deve ter no máximo 120 caracteres`; `O nome deve ter no máximo 120 caracteres`; `O saldo deve ser um valor numérico`; `Serviço não encontrado`; `A validade não pode estar no passado`; `Validade inválida`; `Informe ao menos um item`; `Informe o nome completo`; `Informe o telefone`; `Já existe um tutor com este documento`; `Valor inválido`; `O atendimento ^1 está finalizado e não pode ser pausado`; `O atendimento ^1 já está pausado`; `O atendimento ^1 não está pausado`; `Já existe uma conta bancária chamada "^1" nesta unidade`; `Conta bancária não encontrada`; `This service has appointments; deactivate it instead` fica como já está; `Já existe um serviço chamado "^1"`; `Já existe um produto chamado "^1"`; `Já existe um produto com o código "^1"`; `Já existe um modelo chamado "^1"`; `O agendamento ^1 não pode ser remarcado no status ^2`; `Este agendamento já está na fila`; `%s days` → `%s dias`; `This entry cannot advance right now: ^1` → `Esta entrada não pode avançar agora: ^1`; as chaves de T-29 `Check-in` → `Check-in`, `Patient checked in` → `Paciente incluído na fila`, `Only scheduled or confirmed appointments can be checked in` → `Só agendamentos marcados ou confirmados podem fazer check-in` e `Check in this appointment?` → `Fazer check-in deste agendamento?`; a chave de T-30 `Open the prescription from the encounter` → `Abra a prescrição pelo atendimento`; a chave de T-33 `Duplicate this service?` → `Duplicar este serviço?`; sem a chave `Selected tutor was not found for your account`.
- Consome: nada

**Teste RED**
- `src/tests/Unit/UserMessageTest.php`, `src/tests/Unit/CvFormatUserErrorTest.php` — o primeiro cobre `UserMessage::resolve`: `'Patient sex must be one of M, F, U'` → key igual e params `[]`; `'Encounter 3408 is finished and cannot be paused'` → key `Encounter ^1 is finished and cannot be paused`, params `['3408']`; `'A bank account named "<b>x</b>" already exists for this unit'` → params `['<b>x</b>']`; `'qualquer outra'` → `null`. O segundo faz `require` de `app/lib/widget/CvFormat.php` com um `_t` de teste definido só se `function_exists('_t')` for falso (substitui `^1`/`^2`); `userError(new \InvalidArgumentException('A bank account named "<b>x</b>" already exists for this unit'))` contém `&lt;b&gt;x&lt;/b&gt;` e não contém `<b>`; `CrossTenantReferenceException` devolve a chave de clínica. Falha antes da implementação porque a classe e o escape não existem (comando: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php`)

**Critério de aceite**
- SUITE: `PASS  Unit\UserMessageTest::` e `PASS  Unit\CvFormatUserErrorTest::` em todos os métodos, `Failed: 0`.
- Script python sobre `translations.json`: JSON válido, `dup=0 dupcase=0`, as chaves desta Interface presentes e `Selected tutor was not found for your account` ausente; `grep -rn "Selected tutor was not found" src/app` só no JSON anterior (0 no código).
- GATE:
  - `VaccineProtocolForm` mostra "dias" no lugar de "%s days";
  - `ServiceImportForm` com uma linha cujo `reason` venha de `create()` mostra o texto em pt;
  - "Message not found" não aparece em nenhuma tela da onda.

**Validação**
- LINT de `UserMessage.php`, `CvFormat.php` e `ServiceImportForm.php` (evidência: 3 `No syntax errors detected`) + SUITE (evidência: as linhas `PASS` citadas, `Failed: 0`)
- `python3 -c "import json;d=json.load(open('/var/www/html/centralvet/src/app/config/translations.json'));…"` (evidência: `dup=0 dupcase=0 missing=0 orphan=0`)
- `grep -rn "Selected tutor was not found for your account" /var/www/html/centralvet/src/app --include=*.php` (evidência: vazio)
- GATE → `VaccineProtocolForm` e `ServiceImportForm` (evidência: textos em pt no snapshot, console 0 `error`)

### T-29 — Onda 8: check-in da fila a partir da Agenda, menu da fila e agendamento inexistente

**Camada:** frontend
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** alta
**Agente:** Aang

Demanda nova do usuário: não existe caminho pela UI para pôr um paciente agendado na fila. Por isso o gate do menu da fila de T-17 (Editar paciente / Editar agendamento) nunca rodou (`reports/T-24.md:9`). `QueueEntryService::checkIn(array $data, string $action)` já existe (:73) e `QueueEntryRepositoryInterface::findByAppointment(int $appointmentId)` também (:29). A ação fica num controller já registrado (`AgendaView`), sem programa novo e sem DML. Pendências da mesma área:
- T-08: `AppointmentForm` com `key` inexistente segue editável com Salvar;
- T-17: comentário de `QueueEntryView.php:92` diz `null`, mas o valor é `0`;
- T-25: `span.agenda-block-time` sem `ms-1` (`AgendaView.php:371`).

**Arquivos prováveis**
- `src/app/Core/Application/QueueEntryService.php`
- `src/tests/Unit/QueueEntryServiceTest.php`
- `src/app/control/clinic/AgendaView.php`
- `src/app/control/clinic/AppointmentForm.php`
- `src/app/control/clinic/QueueEntryView.php`

**Interface**
- Produz: `QueueEntryService::checkIn(array $data, string $action): QueueEntry` (assinatura mantida). Com `appointment_id` > 0 e `findByAppointment($appointmentId)` não nulo, lança `\DomainException("Appointment {$appointmentId} is already in the queue")` antes de gravar. A checagem vem depois da de tenant do paciente e antes da autorização, e nada é gravado.
- Produz: no bloco do agendamento (`renderAppointmentBlock`) com status `Appointment::STATUS_SCHEDULED` ou `STATUS_CONFIRMED`, o link `_t('Check-in')` (`class="agenda-block-checkin ms-1"`) abre `TQuestion(_t('Check in this appointment?'))`. A confirmação chama `AgendaView::onCheckIn(['appointment_id' => <id>, 'date' => <Y-m-d>])`, que: carrega o agendamento por `AppointmentService::findById`; com `null` → `TMessage('error', _t('Record not found'))`; com status fora dos dois → `TMessage('error', _t('Only scheduled or confirmed appointments can be checked in'))`; chama `checkIn(['patient_id' => $a->patientId, 'professional_system_user_id' => $a->professionalSystemUserId, 'system_unit_id' => $a->systemUnitId, 'appointment_id' => $a->id], __CLASS__ . '::onCheckIn')`; no sucesso, `TToast::show('success', _t('Patient checked in'))` e recarrega a Agenda na mesma data; `DomainException`/`CrossTenantReferenceException`/`AuthorizationDenied` → `TMessage('error', CvFormat::userError($e))`, com `error_log` da mensagem original.
  `span.agenda-block-time` ganha `ms-1`.
- Produz: `AppointmentForm` com `key` inexistente ou de outro tenant mostra `_t('Record not found')`, todos os campos com `setEditable(FALSE)` e sem o botão Salvar.
- Produz: comentário de `QueueEntryView.php:92` corrigido para dizer que o encaixe tem `appointment_id` = 0. Só comentário.
- Consome: nada

**Teste RED**
- `src/tests/Unit/QueueEntryServiceTest.php` — com os Fakes do teste atual: `checkIn` com `appointment_id` 7 grava 1 entrada; um segundo `checkIn` com o mesmo `appointment_id` lança `DomainException` com a mensagem exata `Appointment 7 is already in the queue`, e o Fake segue com 1 entrada; `checkIn` sem `appointment_id` duas vezes grava 2. Falha antes da correção porque hoje o segundo `checkIn` grava outra entrada (comando: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php`)

**Critério de aceite**
- SUITE: `PASS  Unit\QueueEntryServiceTest::` em todos os métodos, `Failed: 0`.
- GATE (agendamento `R2 varredura` de hoje, `agendado`):
  - `AgendaView` → Check-in → confirmar mostra "Paciente incluído na fila", e `SELECT COUNT(*) FROM queue_entry WHERE appointment_id=<id>` = 1;
  - repetir o Check-in mostra "Este agendamento já está na fila", e a contagem continua 1;
  - `QueueEntryView` mostra a entrada;
  - o menu da linha (T-17) → "Editar paciente" abre `PatientForm&key=<patient_id>` editável, e "Editar agendamento" abre `AppointmentForm&key=<appointment_id>` editável;
  - `AppointmentForm&method=onEdit&key=999999` mostra não encontrado, sem Salvar;
  - console 0 `error`.

**Validação**
- LINT dos 4 PHP (evidência: 4 `No syntax errors detected`) + SUITE (evidência: `PASS  Unit\QueueEntryServiceTest::`, `Failed: 0`)
- GATE → os fluxos do critério, com `SELECT patient_id, appointment_id FROM queue_entry WHERE appointment_id=<id>` (evidência: 1 linha; URLs dos 2 itens do menu com os mesmos ids; mensagens citadas; console 0 `error`, rede 0 ≥ 400)
- Review Focus: dois cliques em Check-in do mesmo agendamento → uma única entrada na fila (evidência: `COUNT(*)` = 1)

### T-30 — Onda 8: PrescriptionForm sem patient_id, combo de modelos e remoção de item

**Camada:** frontend
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Kratos

Pendências:
- T-24: `PrescriptionForm` aberto sem `patient_id`/`encounter_id` mostra erro cru ao salvar (`onSave` monta `'patient_id' => (int) $data->patient_id`, :786-787, e o Core recusa com mensagem em inglês ou erro de banco);
- T-19: o modelo recém-salvo só entra no combo depois de recarregar (`TCombo::reload`), e `onRemoveItem` (GET) restaura o rascunho de cabeçalho antigo.

**Arquivos prováveis**
- `src/app/control/clinic/PrescriptionForm.php`

**Interface**
- Produz: `onSave` e `onSaveTemplate` sem `encounter_id` ou sem `patient_id` válidos (0/ausentes) → `TMessage('error', _t('Open the prescription from the encounter'))`, sem chamar o serviço. Os demais `catch` de `onSave` usam `CvFormat::userError($e)` e `error_log`.
- Produz: `onSaveTemplate` bem-sucedido chama `TCombo::reload('<nome do form>', 'template_id', <modelos por listAll()>)`, e o modelo novo aparece no combo sem recarregar a página.
- Produz: o botão de remover item vira `TButton` com `setAction(new TAction([$this, 'onRemoveItem'], ['index' => <n>]))` e `setFormName(<form da prescrição>)`, e passa a postar o formulário. `onRemoveItem($param)` restaura o cabeçalho (`valid_until`, orientação, profissional) a partir de `$param` e não do rascunho anterior da sessão.
- Consome: nada

**Teste RED**
- sem teste: controller Adianti não é carregado por `tests/run.php`; a prova é o gate

**Critério de aceite**
- GATE:
  - `PrescriptionForm` sem parâmetros → preencher 1 item → Salvar mostra "Abra a prescrição pelo atendimento" (ou a chave, se T-28 ainda não gravou), e `SELECT COUNT(*) FROM prescription` fica igual;
  - no atendimento `R2 varredura`, Salvar como modelo "R2 varredura Modelo 2" faz o combo `template_id` listar "R2 varredura Modelo 2" sem recarregar;
  - com validade preenchida e 2 itens, remover 1 item mantém a validade e a orientação digitadas;
  - console 0 `error`.

**Validação**
- LINT de `PrescriptionForm.php` (evidência: `No syntax errors detected`)
- GATE → os 3 fluxos do critério (evidência: mensagem no snapshot e `COUNT(*)` igual; `<option>` do modelo novo no snapshot antes de recarregar; valores dos campos depois da remoção; console 0 `error`)
- Review Focus: Salvar sem `patient_id` não grava prescrição nem mostra texto `SQLSTATE`/inglês (evidência: `SELECT COUNT(*) FROM prescription` igual e snapshot)

### T-31 — Onda 8: EncounterView com encounter_id em todos os retornos, foto/alergia por CSS e mensagens de pausa

**Camada:** frontend
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** alta
**Agente:** Yoda

Pendências de T-18:
- o alerta de alergia e a foto usam `style` inline, com classes sem regra CSS (`EncounterView.php:609-616`, `:639`);
- a foto é decidida por `photoObjectKey !== null`, e string vazia geraria `<img>` quebrado;
- autosave, anexar, retorno e resumo de IA ainda passam `id`, e o construtor renderiza o vazio antes do método.
Da T-24: `onPause`/`onResume` mostram a mensagem de domínio em inglês. Também são deste arquivo de CSS as classes da pré-visualização de foto de `PatientForm` (T-32), porque `cv-components.css` fica com uma única task por onda.

**Arquivos prováveis**
- `src/app/control/clinic/EncounterView.php`
- `src/app/templates/adminbs5/cv-components.css`

**Interface**
- Produz: nenhum `style=` no alerta de alergia nem na foto. As regras vão para `.cv-alert-allergy` e `.cv-patient-photo`, que valem também para `PatientForm`: `.cv-patient-photo` com `width: 96px; height: 96px; object-fit: cover; border-radius: 50%`.
- Produz: a foto só é renderizada quando `photoObjectKey` não é nulo **nem** vazio (`trim(...) !== ''`).
- Produz: toda ação que recarrega a tela (`onAutosave`, anexar documento, retorno, resumo de IA, `onPause`, `onResume`, `onFinish`) recarrega com `encounter_id`, e nenhuma passa só `id`: `grep -n "'id' =>" EncounterView.php` só em chamadas que não recarregam a tela, e cada ocorrência que sobrar é justificada no relatório.
- Produz: os `catch` de `onPause`/`onResume` usam `TMessage('error', CvFormat::userError($e))` com `error_log`.
- Consome: nada

**Teste RED**
- sem teste: controller Adianti e CSS não são carregados por `tests/run.php`; a prova é grep e o gate

**Critério de aceite**
- `grep -c 'style=' ` restrito às linhas do alerta e da foto em `EncounterView.php` = 0, e `grep -c "cv-alert-allergy\|cv-patient-photo" src/app/templates/adminbs5/cv-components.css` ≥ 2.
- `SUITE` com `PASS  Integration\EncounterTimelineIntegrationTest::`.
- GATE (atendimento `R2 varredura` em andamento do paciente 2772):
  - o alerta de alergia e a foto aparecem com as classes;
  - anexar um documento, esperar um autosave (20 s) e o resumo de IA (se exibido) mantêm a tela no atendimento, sem o vazio "Informe um encounter_id";
  - Pausar num atendimento finalizado (id 3408, pela URL de `onPause`) mostra "O atendimento 3408 está finalizado e não pode ser pausado" (ou a chave, se T-28 ainda não gravou);
  - console 0 `error`.

**Validação**
- LINT de `EncounterView.php` (evidência: `No syntax errors detected`) + SUITE (evidência: `PASS  Integration\EncounterTimelineIntegrationTest::`, `Failed: 0`)
- `grep -n "'id' =>" /var/www/html/centralvet/src/app/control/clinic/EncounterView.php` (evidência: as linhas que sobraram e a justificativa no relatório)
- GATE → os fluxos do critério (evidência: snapshot com as classes, texto "Informe um encounter_id" ausente depois de cada ação, mensagem em pt, console 0 `error`)
- Review Focus: autosave depois da troca para `encounter_id` grava no atendimento certo (evidência: `SELECT updated_at, anamnesis_text FROM encounter WHERE id=<id>` com o texto digitado)

### T-32 — Onda 8: PatientForm e PatientService: foto (órfã, cache, nosniff), normalização e não encontrado

**Camada:** backend
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** alta
**Agente:** Naruto

Pendências:
- T-12: o header `X-Content-Type-Options: nosniff, nosniff` sai duplicado (nginx `docker/nginx/default.conf:11` + `PatientForm.php:548`);
- T-12: a foto antiga fica órfã no storage ao trocar;
- T-12: `Cache-Control` de 300 s mostra a foto antiga, porque a chave é `photo-<nome>` e o mesmo nome reusa a chave;
- revisão final: `attachPhoto` grava no storage antes do banco e deixa objeto órfão se o banco falhar (`PatientService.php:203-224`);
- T-12: style inline na pré-visualização;
- T-07: `create()` não normaliza `''` → `null` como `update()`;
- T-07/validador: com `key=999999`, os radios de espécie e sexo ficam editáveis (`PatientForm.php:215-219`);
- T-27: o comentário do catch em `PatientForm.php:455` cita "not found";
- T-23/T-24: "Patient sex must be one of M, F, U" chega em inglês, porque o catch genérico usa `getMessage()`.

**Arquivos prováveis**
- `src/app/Core/Application/PatientService.php`
- `src/tests/Unit/PatientServiceTest.php`
- `src/app/control/clinic/PatientForm.php`
- `docker/nginx/default.conf`

**Interface**
- Produz: `PatientService::attachPhoto(...)` (assinatura mantida): chave `sprintf('tenant/%d/patient/%d/photo-%s-%s', $tenantId, $patientId, bin2hex(random_bytes(6)), <nome saneado>)`, nova a cada upload; depois do `save()` no banco, se a chave anterior não era nula nem igual, chama `$storage->delete(<chave anterior>)` (falha do delete → `error_log`, sem exceção); se o `save()` lançar, chama `$storage->delete(<chave nova>)` e relança.
- Produz: `create()` normaliza `breed`, `sex`, `birth_date`, `color`, `notes` e `allergies` pelo mesmo `optional()` de `update()` (`''`/espaços → `null`).
- Produz: `PatientForm`: não chama mais `header('X-Content-Type-Options: nosniff')`; `docker/nginx/default.conf` ganha `fastcgi_hide_header X-Content-Type-Options;` no bloco de `fastcgi_pass`, e o `add_header ... always` do servidor segue como fonte única do header; com `key` inexistente, todos os campos (inclusive `TRadioGroup` de espécie e sexo) ficam `setEditable(FALSE)`; a pré-visualização usa só `class="cv-patient-photo"`, sem `style`, com a regra CSS de T-31; o catch genérico de `onSave` usa `TMessage('error', CvFormat::userError($e))`, e a mensagem de peso de T-27 continua como está; o comentário de :455 é corrigido.
- Consome: nada

**Teste RED**
- `src/tests/Unit/PatientServiceTest.php` — com `FakeStorage` e `FakePatientRepository`: dois `attachPhoto` seguidos com o mesmo nome geram chaves diferentes, e a primeira some do `FakeStorage` (`exists()` false); com um repositório que lança no `save()` (classe anônima no teste), `attachPhoto` relança e o `FakeStorage` fica sem objeto novo; `create([... 'breed' => ''])` devolve `breed === null`. Falha antes da correção porque hoje a chave se repete, o objeto antigo fica e o `breed` vazio fica `''` (comando: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php`)

**Critério de aceite**
- SUITE: `PASS  Unit\PatientServiceTest::` em todos os métodos, `Failed: 0`.
- GATE (depois do rebuild e do restart do nginx pelo orquestrador):
  - `curl -sI` da URL de `onPhoto` do paciente 2772 (com o cookie da sessão, pelo `browser_evaluate` `fetch` + `headers.get`) mostra `x-content-type-options: nosniff` uma vez, sem `nosniff, nosniff`;
  - trocar a foto do paciente 2772 faz a pré-visualização mostrar a nova e `SELECT photo_object_key` mudar;
  - `PatientForm&method=onEdit&key=999999` tem todos os `input` desabilitados (`document.querySelectorAll('form input:not([disabled]):not([type=hidden])').length` = 0);
  - `sex` adulterado para `X` por `browser_evaluate` mostra "O sexo do paciente deve ser M, F ou U" (ou a chave, se T-28 ainda não gravou);
  - console 0 `error`.

**Validação**
- LINT de `PatientService.php` e `PatientForm.php` (evidência: 2 `No syntax errors detected`) + SUITE (evidência: `PASS  Unit\PatientServiceTest::`, `Failed: 0`)
- `docker compose exec -T nginx nginx -t` depois do restart (evidência: `syntax is ok` e `test is successful`)
- GATE → os 4 fluxos do critério (evidência: valor do header, `SELECT photo_object_key` antes e depois, contagem de inputs, mensagem; console 0 `error`)
- Review Focus: depois da troca da foto, `SELECT photo_object_key` novo e o objeto anterior ausente do bucket (evidência: `docker compose exec -T minio` ou `mc ls` do bucket `centralvet-local` sem a chave antiga, só leitura; se o cliente não estiver disponível, a evidência é o RED)

### T-33 — Onda 8: ServiceList (Duplicar com confirmação, mensagens) e ServiceCatalogService (limites e testes de T-26)

**Camada:** backend
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Levi

Pendências:
- T-10: Duplicar grava por GET sem confirmação, `getMessage()` cru do Core em inglês em `onDuplicate`/`onDelete`, e `catch (DomainException)` amplo em `onDelete` (`ServiceList.php:254`, `:281`, `:318-326`);
- T-09: `duplicate()` pode passar de 190 caracteres; falta teste de linha vazia no meio;
- T-26: o ramo `InvalidArgumentException` de `create()` → `skipped` não tem teste (`ServiceCatalogService.php:192`), e `testImportCsvAcceptsValuesAtColumnLimits` só assere `priceCents` (`ServiceCatalogServiceTest.php:363`).

**Arquivos prováveis**
- `src/app/control/clinic/ServiceList.php`
- `src/app/Core/Application/ServiceCatalogService.php`
- `src/tests/Unit/ServiceCatalogServiceTest.php`
- `src/tests/Support/FakeServiceRepository.php`

**Interface**
- Produz: `ServiceList`: Duplicar abre `TQuestion(_t('Duplicate this service?'))` antes de `onDuplicate`; `onDuplicate` e `onDelete` mostram `TMessage('error', CvFormat::userError($e))` com `error_log`; `onDelete` captura só `\DomainException` vinda de `delete()`, e as demais caem no catch genérico com `userError`.
- Produz: `ServiceCatalogService::duplicate(int $id, string $copyLabel = 'copy'): Service` (assinatura mantida) corta o nome-base com `mb_substr` para que `"{$base} ({$copyLabel} N)"` tenha no máximo `MAX_NAME_LENGTH` (190) caracteres.
- Produz: `FakeServiceRepository::failNextSaveWith(\Throwable $e): void`: o próximo `save()` lança `$e` uma vez, e depois volta ao normal.
- Consome: nada

**Teste RED**
- `src/tests/Unit/ServiceCatalogServiceTest.php`, `src/tests/Support/FakeServiceRepository.php` — cobre quatro casos e falha antes da correção porque `failNextSaveWith` não existe e `duplicate` gera 198 caracteres (comando: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php`): `duplicate` de um serviço com nome de 190 caracteres gera nome com `mb_strlen` ≤ 190 terminado em `(cópia)`; `importCsv` com `failNextSaveWith(new \InvalidArgumentException('x'))` e 2 linhas válidas devolve `created: 1` e `skipped` com a linha 2 e `reason` `x`; `importCsv` com linha vazia entre duas válidas devolve `created: 2`, sem `skipped`; `testImportCsvAcceptsValuesAtColumnLimits` também assere `durationMinutes() === 4294967295`, `category` de 60 caracteres e nome de 190.

**Critério de aceite**
- SUITE: `PASS  Unit\ServiceCatalogServiceTest::` em todos os métodos, `Failed: 0`.
- GATE:
  - `ServiceList` → Duplicar "R2 varredura Serviço" abre a confirmação; cancelar deixa `SELECT COUNT(*) FROM service` igual, e confirmar faz +1;
  - Excluir um serviço com agendamento mostra "Este serviço tem agendamentos; inative-o", sem texto em inglês;
  - console 0 `error`.

**Validação**
- LINT de `ServiceList.php` e `ServiceCatalogService.php` (evidência: 2 `No syntax errors detected`) + SUITE (evidência: `PASS  Unit\ServiceCatalogServiceTest::`, `Failed: 0`)
- GATE → os 3 fluxos do critério (evidência: `COUNT(*)` antes e depois, mensagem no snapshot, console 0 `error`)

### T-34 — Onda 8: BankAccountForm/List: escape, mensagens e valor não numérico

**Camada:** frontend
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** simples
**Agente:** Athena

Pendências:
- revisão final: o nome da conta vai interpolado na mensagem mostrada por `TMessage` sem escape (self-XSS, `BankAccountForm.php:122,176`) e há escape duplo na coluna Banco (`BankAccountList.php:37-38`, porque o `TDataGrid` já aplica `htmlspecialchars`);
- T-22: as mensagens do serviço aparecem em inglês por `getMessage()`, e `toCents` converte entrada não numérica em 0 em silêncio.

**Arquivos prováveis**
- `src/app/control/clinic/BankAccountForm.php`
- `src/app/control/clinic/BankAccountList.php`

**Interface**
- Produz: os `catch` de `onSave`/`onEdit` de `BankAccountForm` usam `TMessage('error', CvFormat::userError($e))`, que traduz e escapa (T-28), com `error_log`.
- Produz: `toCents` recusa entrada fora de `^-?\d{1,3}(\.\d{3})*(,\d{1,2})?$` ou `^-?\d+(,\d{1,2})?$` com `\InvalidArgumentException('Invalid amount')` (chave de T-28); vazio → 0.
- Produz: o transformer da coluna Banco em `BankAccountList` não aplica `CvFormat::e` sobre valor que o `TDataGrid` já escapou: "&" aparece como "&".
- Consome: nada

**Teste RED**
- sem teste: controllers Adianti não são carregados por `tests/run.php`; a tradução e o escape são provados pelo RED de T-28 e a tela pelo gate

**Critério de aceite**
- GATE:
  - `BankAccountForm` → nova conta com o nome de uma conta existente da unidade ("R2 varredura Conta") mostra "Já existe uma conta bancária chamada "R2 varredura Conta" nesta unidade" (ou a chave, se T-28 ainda não gravou), e um nome `<b>R2</b>` repetido aparece literal, sem negrito;
  - saldo "abc" (forçado por `browser_evaluate`) mostra "Valor inválido", e `SELECT COUNT(*) FROM bank_account` fica igual;
  - banco "Itaú & Cia" aparece como "Itaú & Cia" na lista;
  - console 0 `error`.

**Validação**
- LINT dos 2 PHP (evidência: 2 `No syntax errors detected`)
- GATE → os 3 fluxos do critério (evidência: textos no snapshot, `COUNT(*)` igual, console 0 `error`)
- Review Focus: nome `<b>R2</b>` duplicado → a mensagem mostra as tags como texto (evidência: `innerHTML` do diálogo contém `&lt;b&gt;`)

### T-35 — Onda 8: FinancialOverview, ProductList e formas de pagamento

**Camada:** frontend
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** simples
**Agente:** Tesla

Pendências:
- T-20: `onExport` engole `Throwable` sem `error_log`, e o link "Bank accounts" do KPI depende de `$card->get(1)` (`FinancialOverview.php:316`);
- T-21: `LOW_STOCK_LIMIT` também limita `recentSales` (`ProductList.php:27`, `:67`), e a contagem de `<tr>` do PDF não foi rodada;
- T-14: a lista de formas de pagamento está repetida em `FinancialEntry::PAYMENT_METHODS` (private) e em `FinancialEntryForm`.

**Arquivos prováveis**
- `src/app/control/clinic/FinancialOverview.php`
- `src/app/control/clinic/ProductList.php`
- `src/app/control/clinic/FinancialEntryForm.php`
- `src/app/Core/Domain/FinancialEntry.php`

**Interface**
- Produz: o `catch (\Throwable $e)` de `onExport` grava `error_log` (classe e mensagem) antes da resposta de erro. O link "Contas bancárias" do KPI é montado junto do card, por `CvKpiCard::create` + `TElement` próprio, sem `$card->get(1)`.
- Produz: `ProductList::RECENT_SALES_LIMIT = 5` separado de `LOW_STOCK_LIMIT`, e `recentSales()` usa o novo.
- Produz: `FinancialEntry::PAYMENT_METHODS` passa a `public const`, e `FinancialEntryForm` monta o combo a partir dela com os rótulos de `CvFormat::paymentMethod()`, sem lista própria.
- Consome: nada

**Teste RED**
- sem teste: controllers Adianti fora de `tests/run.php`; `FinancialEntry` só muda a visibilidade da constante, coberta por `PaymentServiceTest` na SUITE

**Critério de aceite**
- SUITE: `PASS  Unit\PaymentServiceTest::`, `Failed: 0`.
- `grep -c "get(1)" src/app/control/clinic/FinancialOverview.php` = 0; `grep -c "'cash'\|'pix'" src/app/control/clinic/FinancialEntryForm.php` = 0.
- GATE:
  - `FinancialOverview` mostra o KPI de saldo com o link "Contas bancárias";
  - `FinancialEntryForm` lista as 5 formas;
  - `ProductList` → Gerar relatório: o número de `<tr>` do corpo do PDF (pelo HTML gerado por `php -r` de `renderReportHtml` no container) = número de linhas da tela com os mesmos filtros;
  - console 0 `error`.

**Validação**
- LINT dos 4 PHP (evidência: 4 `No syntax errors detected`) + SUITE (evidência: `PASS  Unit\PaymentServiceTest::`, `Failed: 0`)
- `grep -c "get(1)" /var/www/html/centralvet/src/app/control/clinic/FinancialOverview.php` (evidência: `0`)
- GATE → os fluxos do critério (evidência: snapshot, contagem de `<tr>` × linhas da tela, console 0 `error`)

### T-36 — Onda 8: AuthorizationRequest UTF-8, TutorService, testes de reschedule e ordem da guarda de desconto

**Camada:** backend
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Arquimedes

Pendências:
- T-02: `json_encode` devolve `false` com UTF-8 inválido em `$action` (`AuthorizationRequest.php:41-44`);
- T-06: normalização duplicada entre `create()` e `update()`, sem testes de `address` `''` → `null` e do próprio `document`;
- T-08: `reschedule()` sem testes de `service_id` de outro tenant, `AuthorizationDenied`, chave ausente e mensagem de status (`AppointmentServiceTest.php:284-359`);
- T-25 da fase 10 / triagem: a guarda `isActiveMember` roda antes do RBAC, e um usuário sem permissão de desconto consegue sondar ids ativos (`EncounterAccountService.php:331-339`).

**Arquivos prováveis**
- `src/app/Core/Authorization/AuthorizationRequest.php`
- `src/tests/Unit/AuthorizationRequestTest.php`
- `src/app/Core/Application/TutorService.php`
- `src/tests/Unit/TutorServiceTest.php`
- `src/tests/Unit/AppointmentServiceTest.php`
- `src/app/Core/Application/EncounterAccountService.php`
- `src/tests/Unit/EncounterAccountServiceTest.php`

**Interface**
- Produz: a mensagem de `AuthorizationRequest` usa `json_encode($action, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)`, e nunca devolve `false`.
- Produz: `TutorService` com `private static function normalize(array $data): array`, usado por `create()` e `update()` (aparar `full_name`/`phone`; `''` → `null` em `document`, `email` e `address`). As mensagens não mudam.
- Produz: `EncounterAccountService::applyDiscount` faz a autorização (`assertAllowed` da ação) **antes** de `isActiveMember`: sem permissão, a exceção é `AuthorizationDenied` para qualquer autorizador. O docblock `@throws` é atualizado.
- Consome: nada

**Teste RED**
- `src/tests/Unit/AuthorizationRequestTest.php`, `src/tests/Unit/TutorServiceTest.php`, `src/tests/Unit/AppointmentServiceTest.php`, `src/tests/Unit/EncounterAccountServiceTest.php` — falha antes da correção porque hoje a mensagem de UTF-8 inválido termina em `: ` e a guarda vem antes do RBAC (comando: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php`): `new AuthorizationRequest(action: "a\xB1b")` lança com a mensagem contendo `"a�b"`; `update(A, [... 'address' => ''])` devolve `address === null`, e `update(A, [... 'document' => <o próprio>])` passa; `reschedule` com `service_id` de outro tenant lança `CrossTenantReferenceException`, com política negando lança `AuthorizationDenied`, sem `scheduled_at` lança `scheduled_at is required` e com status `cancelado` lança a mensagem exata `Appointment <id> cannot be rescheduled from status cancelado`; `applyDiscount` com política negando e autorizador inexistente lança `AuthorizationDenied`, e não `CrossTenantReferenceException`.

**Critério de aceite**
- SUITE: `PASS` em todos os métodos de `Unit\AuthorizationRequestTest`, `Unit\TutorServiceTest`, `Unit\AppointmentServiceTest` e `Unit\EncounterAccountServiceTest`, `Failed: 0`.

**Validação**
- LINT dos 3 PHP de Core (evidência: 3 `No syntax errors detected`) + SUITE (evidência: as 4 classes com `PASS`, `Failed: 0`)

### T-37 — Onda 8: testes de integração faltantes e verify da 0007

**Camada:** backend
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Sherlock

Pendências:
- T-03: faltam testes de id excluído de outro tenant e de empate de `started_at` (`ClinicalSummaryIntegrationTest.php:226-275`);
- T-11: falta integração de INSERT/`findByCode` do `ProductRepository`;
- T-14: falta integração de `payment_method` no `FinancialEntryRepository`;
- T-16: `paused_at`/`paused_seconds` sem teste contra o banco, e o clamp de `$now` anterior a `pausedAt` sem teste;
- T-20: `recentEntries` com um só limite do período sem teste;
- T-01: `.verify.sql` ordena por `ordinal_position` sem selecioná-la (`20260930_0007_rodada2_cadastros_financeiro.verify.sql:22`). O `.verify.sql` não entra no checksum.
Só testes e o verify; código de produção muda só se um teste revelar bug, e nesse caso o implementador para e devolve `precisa de contexto` com o caminho.

**Arquivos prováveis**
- `src/tests/Integration/ClinicalSummaryIntegrationTest.php`
- `src/tests/Integration/ProductRepositoryIntegrationTest.php`
- `src/tests/Integration/FinancialEntryRepositoryIntegrationTest.php`
- `src/tests/Integration/EncounterRepositoryIntegrationTest.php`
- `src/tests/Integration/FinancialOverviewIntegrationTest.php`
- `src/tests/Unit/EncounterServiceTest.php`
- `src/app/database/migrations/20260930_0007_rodada2_cadastros_financeiro.verify.sql`

**Interface**
- Produz: testes (transação com rollback, base `MysqlIntegrationTestCase`): `lastEncounter($p, <id de outro tenant>)` → `null` e empate de `started_at` → o de menor id conta como anterior (ruling T-03); `ProductRepository` `save` + `findById` preservam `sale_price_cents`/`code`, e `findByCode('r2-x')` acha `R2-X` (collation `ai_ci`); `FinancialEntryRepository` `save` + leitura preservam `payment_method`; `EncounterRepository` `save` + `findById` preservam `paused_at`/`paused_seconds`; `recentEntries($u, 10, $from, $from)` (um dia só) traz só os lançamentos daquele dia; `resume` com `$now` anterior a `pausedAt` soma 0 (clamp documentado no teste).
- Produz: `.verify.sql` com `ordinal_position` no SELECT que ordena por ela. Só SELECT.
- Consome: nada

**Teste RED**
- sem teste: a task só acrescenta testes de caracterização sobre comportamento já entregue e aprovado (T-03, T-11, T-14, T-16, T-20). Eles passam na primeira execução por desenho; falha revela bug, que volta como pendência

**Critério de aceite**
- SUITE: `PASS` em todos os métodos de `Integration\ClinicalSummaryIntegrationTest`, `Integration\ProductRepositoryIntegrationTest`, `Integration\FinancialEntryRepositoryIntegrationTest`, `Integration\EncounterRepositoryIntegrationTest`, `Integration\FinancialOverviewIntegrationTest` e `Unit\EncounterServiceTest`, `Failed: 0`.
- `SELECT COUNT(*)` de `product`, `financial_entry` e `encounter` igual antes e depois da SUITE (rollback).
- `grep -ciE "\b(insert|update|delete|alter|create|drop|truncate|replace)\b"` no `.verify.sql` = 0.

**Validação**
- SUITE (evidência: as linhas `PASS` das 6 classes, `Failed: 0`)
- `SELECT COUNT(*)` das 3 tabelas antes e depois (evidência: iguais)
- `grep -ciE "\b(insert|update|delete|alter|create|drop|truncate|replace)\b" /var/www/html/centralvet/src/app/database/migrations/20260930_0007_rodada2_cadastros_financeiro.verify.sql` (evidência: `0`)

### T-38 — Onda 8: modelos de prescrição (varchar, duplicado atômico, N+1) e testes de produto

**Camada:** backend
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Darwin

Pendências:
- T-13: o modelo aceita campo de item vazio e não valida tamanhos (varchar), e um PDOException derruba a operação; a checagem de duplicado não é atômica; `listAll` tem N+1;
- T-11: asserção vazia em `testUpdateRejectsProductOfAnotherTenant` (`ProductServiceTest.php:98`); `FakeProductRepository::findByCode` diferencia caixa e o banco não; os shapes de `@return` de `StockSalesOverviewService` não trazem `code`/`sale_price_cents`.

**Arquivos prováveis**
- `src/app/Core/Domain/PrescriptionTemplate.php`
- `src/app/Core/Persistence/PrescriptionTemplateRepository.php`
- `src/tests/Unit/PrescriptionTemplateServiceTest.php`
- `src/tests/Integration/PrescriptionTemplateRepositoryIntegrationTest.php`
- `src/tests/Unit/ProductServiceTest.php`
- `src/tests/Support/FakeProductRepository.php`
- `src/app/Core/Application/StockSalesOverviewService.php`

**Interface**
- Produz: `PrescriptionTemplate::create` valida: campo de item aparado vazio → `InvalidArgumentException("items[].{$field} is required")`; tamanhos `name` ≤ 190, `medication_name` ≤ 190, `dose` ≤ 40, `dose_unit` ≤ 20, `route` ≤ 40, `frequency` ≤ 60 e `duration` ≤ 60 → `InvalidArgumentException("{$field} must have at most {$max} characters")`.
- Produz: `PrescriptionTemplateRepository::save` converte `PDOException` de SQLSTATE `23000` na unique `prescription_template_tenant_name_uq` em `InvalidArgumentException("A template named \"{$name}\" already exists for this tenant")`, a mensagem do serviço. `listAll()` carrega os itens de todos os modelos numa só consulta (`WHERE template_id IN (...)`).
- Produz: `FakeProductRepository::findByCode` compara com `mb_strtolower`; o `@return` de `StockSalesOverviewService::products()`/`overview()` cita `code` e `sale_price_cents`; `testUpdateRejectsProductOfAnotherTenant` assere a mensagem `Product <id> not found for this tenant`.
- Consome: nada

**Teste RED**
- `src/tests/Unit/PrescriptionTemplateServiceTest.php`, `src/tests/Integration/PrescriptionTemplateRepositoryIntegrationTest.php`, `src/tests/Unit/ProductServiceTest.php`, `src/tests/Support/FakeProductRepository.php` — falha antes da correção porque hoje `dose` longa e item vazio passam, e o Fake diferencia caixa (comando: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php`): `saveFromItems` com `dose` de 41 caracteres lança `dose must have at most 40 characters`; item com `route` `'  '` lança `items[].route is required`; na integração, dois `save` de modelos com o mesmo nome no mesmo tenant (sem passar pela checagem do serviço) lançam `InvalidArgumentException`; `listAll()` de 3 modelos devolve os itens de cada um; `create(... code: 'SKU-1')` seguido de `create(... code: 'sku-1')` lança a mensagem de código duplicado.

**Critério de aceite**
- SUITE: `PASS` em todos os métodos de `Unit\PrescriptionTemplateServiceTest`, `Integration\PrescriptionTemplateRepositoryIntegrationTest` e `Unit\ProductServiceTest`, `Failed: 0`.
- `SELECT COUNT(*) FROM prescription_template` igual antes e depois da SUITE.

**Validação**
- LINT dos 4 PHP de Core/Support (evidência: 4 `No syntax errors detected`) + SUITE (evidência: as 3 classes com `PASS`, `Failed: 0`)

### T-39 — Onda 8: CvPage (docblock, `target` restrito) e seletor de unidade única sem `current`

**Camada:** frontend
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** simples
**Agente:** Thanos

Pendências de T-04: o docblock de `CvPage::header` está desalinhado e `target` aceita qualquer valor (`CvPage.php:13-14`, `:156-159`); com uma unidade e sem `current`, o seletor desabilitado fica sem como escolher a única unidade (`cv-shell.js`).

**Arquivos prováveis**
- `src/app/lib/widget/CvPage.php`
- `src/app/templates/adminbs5/js/cv-shell.js`

**Interface**
- Produz: `CvPage::header` aceita em `target` só `_blank`. Outro valor é ignorado (link sem `target`, com `generator="adianti"`, como sem a chave), e o docblock descreve as chaves do spec (`label`, `action`, `href`, `icon`, `class`, `title`, `target`).
- Produz: em `cv-shell.js`, com uma unidade só e nenhuma `current`, essa unidade fica selecionada no `<select>`, que continua `disabled`, sem placeholder `—`. Com 2+ unidades e nenhuma `current`, o placeholder `—` continua.
- Consome: nada

**Teste RED**
- sem teste: widget com `TElement` e JS fora de `tests/run.php`; a prova é `php -r` e o gate

**Critério de aceite**
- `php -r` que renderiza `CvPage::header('t', null, [['label'=>'x','href'=>'engine.php?a=1','target'=>'_top']])` não imprime `target=` e imprime `generator="adianti"`; com `'target'=>'_blank'` imprime `target="_blank"`, como em T-04.
- GATE: `ServiceList` e `ProductList` com o seletor `aria-label` não vazio; console 0 `error`. A unidade única sem `current` não é reproduzível com os dados atuais: a regra é conferida por leitura do diff, com o trecho citado no relatório.

**Validação**
- LINT de `CvPage.php` (evidência: `No syntax errors detected`)
- `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php -r 'chdir("/var/www/html/src"); require "init.php"; echo CvPage::header("t", null, [["label"=>"x","href"=>"engine.php?a=1","target"=>"_top"]]);'` (evidência: sem `target=` e com `generator="adianti"`)
- GATE → as 2 telas (evidência: `aria-label` e console 0 `error`)

### T-40 — Onda 9: migration 0008 — UNIQUE em queue_entry.appointment_id com dedupe prévio

**Camada:** database
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Jaspion

Pendência T-29: o check-in faz check-then-act (`findByAppointment` e depois `save`), sem UNIQUE, e duas requisições simultâneas gravam duas entradas. Hoje há 1 duplicata, envolvendo `queue_entry` id 1. `queue_entry.appointment_id` é `NULL` no encaixe (FK `queue_entry_appointment_fk` para `appointment`); o `0` visto na fila é só da linha do grid. No MySQL, UNIQUE aceita vários `NULL`. O agente só redige os arquivos: o orquestrador aplica depois da aprovação SQL do usuário, entre as ondas 9 e 10, pelo runbook: `make backup` + `gzip -t`, SHA-256, `MIGRATION_DB_USER` e `.verify.sql`. Antes de aplicar, o orquestrador anota em `notes.md § Bloqueios` a saída de `SELECT id, appointment_id, status, checked_in_at FROM queue_entry WHERE appointment_id IN (SELECT appointment_id FROM queue_entry WHERE appointment_id IS NOT NULL GROUP BY appointment_id HAVING COUNT(*) > 1)` e o `COUNT(*)` de `queue_entry`.

**Arquivos prováveis**
- `src/app/database/migrations/20260930_0008_queue_entry_appointment_unique.sql`
- `src/app/database/migrations/20260930_0008_queue_entry_appointment_unique.verify.sql`

**Interface**
- Produz: `20260930_0008_queue_entry_appointment_unique` (version em `schema_migrations`), no formato da 0007 (cabeçalho `PREPARED ONLY`, `Target` = após 0007, `Effects`, `Risk`, `Rollback` e `INSERT INTO schema_migrations` com placeholder de checksum). Efeitos, nesta ordem: (1) dedupe sem apagar linha: em cada `appointment_id` não nulo com mais de uma entrada, a de **menor id** (o primeiro check-in) fica, e as demais recebem `appointment_id = NULL` (viram encaixe). A consulta é `UPDATE queue_entry q JOIN (SELECT appointment_id, MIN(id) AS keep_id FROM queue_entry WHERE appointment_id IS NOT NULL GROUP BY appointment_id HAVING COUNT(*) > 1) d ON q.appointment_id = d.appointment_id AND q.id <> d.keep_id SET q.appointment_id = NULL`; (2) `ALTER TABLE queue_entry ADD UNIQUE KEY queue_entry_appointment_uq (appointment_id)`. O índice `queue_entry_appointment_idx` e a FK ficam.
- Produz: `Rollback` no cabeçalho. A opção preferida é restaurar o backup pré-migration. A alternativa é uma migration 0009 com `ALTER TABLE queue_entry DROP INDEX queue_entry_appointment_uq` e o `UPDATE` que restaura os `appointment_id` anotados em `notes.md § Bloqueios`; ela não é redigida agora.
- Consome: nada

**Teste RED**
- sem teste: migration pura (DDL + DML de dedupe); a prova é o `.verify.sql` rodado pelo orquestrador após a aplicação aprovada

**Critério de aceite**
- `grep -c "PREPARED ONLY"` = 1 e `grep -c "queue_entry_appointment_uq"` ≥ 1 no `.sql`; `grep -ciE "\b(delete|drop|truncate)\b"` = 0 fora das linhas de comentário do `Rollback`.
- `.verify.sql` só com SELECT (`grep -ciE "\b(insert|update|delete|alter|create|drop|truncate|replace)\b"` = 0), e traz:
  - `SELECT` de `information_schema.statistics` com `index_name = 'queue_entry_appointment_uq'` e `non_unique = 0`;
  - `SELECT COUNT(*) FROM (SELECT appointment_id FROM queue_entry WHERE appointment_id IS NOT NULL GROUP BY appointment_id HAVING COUNT(*) > 1) x`, que deve dar 0;
  - `SELECT COUNT(*) FROM queue_entry`, para comparar com a contagem anterior;
  - `SELECT COUNT(*) FROM queue_entry WHERE appointment_id IS NULL`;
  - a linha de `schema_migrations`.

**Validação**
- `grep -c "PREPARED ONLY" /var/www/html/centralvet/src/app/database/migrations/20260930_0008_queue_entry_appointment_unique.sql` (evidência: `1`)
- `grep -ciE "\b(insert|update|delete|alter|create|drop|truncate|replace)\b" /var/www/html/centralvet/src/app/database/migrations/20260930_0008_queue_entry_appointment_unique.verify.sql` (evidência: `0`)
- Após a aplicação aprovada (orquestrador):
  - `.verify.sql` mostra o índice com `non_unique = 0` e 0 grupos duplicados;
  - `COUNT(*) FROM queue_entry` igual ao anotado antes (nenhuma linha perdida);
  - `COUNT(*) ... IS NULL` = anterior + número de linhas desvinculadas;
  - a linha de menor id de cada grupo anotado mantém o `appointment_id`.

### T-41 — Onda 10: check-in traduz a violação de UNIQUE e a Agenda esconde o Check-in de quem já está na fila

**Camada:** backend
**Dependências:** T-40
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Aang

Consome o índice da 0008, aplicado entre as ondas 9 e 10. Pendência T-29: depois do check-in o link Check-in continua no bloco (`AgendaView.php:383`), e um clique novo só leva a "já está na fila". Na corrida, o segundo INSERT agora bate na unique e precisa virar a mesma mensagem pt de "Este agendamento já está na fila" (catálogo de T-28, `Appointment ^1 is already in the queue`).

**Arquivos prováveis**
- `src/app/Core/Persistence/QueueEntryRepository.php`
- `src/app/Core/Domain/Contract/QueueEntryRepositoryInterface.php`
- `src/tests/Support/FakeQueueEntryRepository.php`
- `src/app/Core/Application/QueueEntryService.php`
- `src/tests/Unit/QueueEntryServiceTest.php`
- `src/tests/Integration/QueueEntryRepositoryIntegrationTest.php`
- `src/app/control/clinic/AgendaView.php`

**Interface**
- Produz: `QueueEntryRepository::save` captura `\PDOException` com SQLSTATE `23000` cuja mensagem cita `queue_entry_appointment_uq` e lança `\DomainException("Appointment {$appointmentId} is already in the queue")`, a mesma mensagem de `QueueEntryService::checkIn` (T-29). Os demais `PDOException` são relançados.
- Produz: `QueueEntryRepositoryInterface::listAppointmentIdsInQueue(array $appointmentIds): array` → `list<int>` dos ids que já têm entrada, com uma consulta `IN (...)` tenant-aware; lista vazia → `[]` sem consulta. Implementado no repositório e no Fake. `QueueEntryService::appointmentIdsInQueue(array $appointmentIds): array` delega a ele.
- Produz: `AgendaView` chama `appointmentIdsInQueue` uma vez por carga com os ids do dia. No bloco de agendamento que já está na fila, o link Check-in dá lugar ao badge `CvBadge::create(_t('In queue'), 'info')`. A chave `In queue` → "Na fila" é gravada por T-43 na onda 9, e antes disso o gate aceita a chave.
- Consome: T-40 `20260930_0008_queue_entry_appointment_unique`

**Teste RED**
- `src/tests/Integration/QueueEntryRepositoryIntegrationTest.php`, `src/tests/Unit/QueueEntryServiceTest.php`, `src/tests/Support/FakeQueueEntryRepository.php` — a integração, em transação com rollback, grava uma entrada com `appointment_id` de um agendamento semeado e força outra com o mesmo `appointment_id` direto pelo repositório; a segunda lança `DomainException` com a mensagem exata `Appointment <id> is already in the queue`, e `COUNT(*)` dessa `appointment_id` fica 1. Na unidade, `appointmentIdsInQueue([7, 8])` com o 7 na fila devolve `[7]`. Falha antes da correção porque o `save` relança `PDOException` e o método não existe (comando: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php`)

**Critério de aceite**
- SUITE: `PASS  Integration\QueueEntryRepositoryIntegrationTest::` e `PASS  Unit\QueueEntryServiceTest::` em todos os métodos, `Failed: 0`.
- GATE:
  - `AgendaView` com um agendamento `R2 varredura` que já está na fila mostra o badge "Na fila" (ou `In queue`), sem link Check-in;
  - um agendamento `agendado` fora da fila mostra o Check-in, e depois do check-in o bloco passa a mostrar o badge;
  - duas requisições simultâneas de `onCheckIn` para o mesmo agendamento (`browser_evaluate` com `Promise.all` de 2 `fetch`) deixam `SELECT COUNT(*) FROM queue_entry WHERE appointment_id=<id>` = 1, e a resposta da segunda contém "Este agendamento já está na fila";
  - console 0 `error`.

**Validação**
- LINT dos 5 PHP de Core/Support/controller (evidência: 5 `No syntax errors detected`) + SUITE (evidência: as linhas `PASS` citadas, `Failed: 0`)
- GATE → os 3 fluxos do critério (evidência: badge no snapshot, `COUNT(*)` = 1 depois da corrida, texto da segunda resposta, console 0 `error`)
- Review Focus: corrida de 2 check-ins do mesmo agendamento (evidência: `COUNT(*)` = 1 e mensagem pt na segunda)

### T-42 — Onda 9: Redis dos testes isolado das sessões e RedisQueueIntegrationTest determinístico

**Camada:** qa
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Naruto

Pendências da onda 8:
- a suíte limpa as sessões do navegador (o login do Playwright cai depois de cada SUITE);
- `RedisQueueIntegrationTest` falha sob suítes paralelas (`testPushPopAckRoundTrip`, reports/T-37.md § Pendências).
Causa provável, a confirmar antes de corrigir: `tests/run.php:41-42` só força `REDIS_DATABASE=15` quando a variável não existe, mas o `docker-compose.yml` injeta `REDIS_DATABASE=0` no container `app`. Assim a suíte roda no mesmo DB das sessões (`SessionHandlerFactory`, prefixo `SESSION_PREFIX`, padrão `centralvet:session:`). Além disso, a fila fixa `t11-queue` (`RedisQueueIntegrationTest.php:19`) é compartilhada entre execuções paralelas. Reprodução exigida em `## RED`:
- `docker compose exec -T redis redis-cli -n 0 --scan --pattern 'centralvet:session:*' | wc -l` antes e depois de uma SUITE, com uma sessão aberta;
- `printenv REDIS_DATABASE` dentro do `docker compose run`;
- o teste ou a chamada exata que apaga as chaves (grep por `del`, `flushdb`, `forget`, `destroy` e `scan` em `tests/`).
Se a causa for outra, o implementador para e devolve `precisa de contexto` com a evidência, antes de editar.

**Arquivos prováveis**
- `src/tests/run.php`
- `src/tests/Support/RedisIntegrationTestCase.php`
- `src/tests/Integration/RedisQueueIntegrationTest.php`
- `src/tests/Integration/SessionRedisIntegrationTest.php`
- `docs/runbooks/tests.md`

**Interface**
- Produz: `tests/run.php` sempre define `REDIS_DATABASE` para o processo como `getenv('TEST_REDIS_DATABASE') ?: '15'`, ignorando o valor herdado. Se o DB resultante for igual ao `REDIS_DATABASE` herdado (o da aplicação), a suíte aborta antes de rodar, com `Refusing to run: test Redis database equals the application database (<n>)` e exit 1. O `SESSION_PREFIX` dos testes de sessão ganha o sufixo `test:<uniqid>:`.
- Produz: `RedisQueueIntegrationTest` usa um nome de fila único por instância (`'t11-queue-' . bin2hex(random_bytes(4))`, em propriedade e não em constante) e limpa só as próprias chaves no `tearDown`. Nenhum teste chama `FLUSHDB`/`FLUSHALL` nem `SCAN` + `DEL` fora do prefixo `testing` do próprio teste.
- Produz: `docs/runbooks/tests.md` explica `TEST_REDIS_DATABASE`, a recusa e o motivo (sessões no DB da aplicação).
- Consome: nada

**Teste RED**
- sem teste: é a infraestrutura do runner de testes, sem comportamento de produção. A prova é a reprodução da causa em `## RED` e a contagem de chaves de sessão e as execuções paralelas da Validação

**Critério de aceite**
- Com uma sessão admin aberta no navegador, `redis-cli -n 0 --scan --pattern 'centralvet:session:*' | wc -l` fica igual antes e depois de uma SUITE, e o navegador continua logado depois da SUITE (a próxima navegação não cai no login).
- 3 rodadas de 2 SUITEs em paralelo (2 `docker compose run` em background) terminam todas com `Failed: 0`, e `PASS  Integration\RedisQueueIntegrationTest::` nas 6 saídas.
- `TEST_REDIS_DATABASE=0 docker compose run ... php tests/run.php` sai com código 1 e a mensagem `Refusing to run`.

**Validação**
- LINT dos 4 PHP de `tests/` (evidência: 4 `No syntax errors detected`)
- `docker compose exec -T redis redis-cli -n 0 --scan --pattern 'centralvet:session:*' | wc -l` antes e depois de uma SUITE (evidência: iguais) + navegação do validador depois da SUITE (evidência: página interna, sem tela de login)
- 3 × (`docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php > /tmp/claude-1000/sA.txt & … > /tmp/claude-1000/sB.txt & wait`) (evidência: `Failed: 0` nas 6 saídas)
- `docker compose run --rm --no-deps -T -e TEST_REDIS_DATABASE=0 -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php; echo $?` (evidência: `Refusing to run` e `1`)

### T-43 — Onda 9: ServiceList com o nome do serviço nas confirmações e i18n da onda 9

**Camada:** frontend
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** simples
**Agente:** Levi

Pendência T-33: a confirmação de Excluir mostra "Deseja realmente excluir ?", sem o nome (`ServiceList.php:310`, string padrão do Adianti), e a de Duplicar também não traz o nome (`:270`). Escritor único de `translations.json` na onda 9: grava as chaves desta task e a de T-41 (`In queue`).

**Arquivos prováveis**
- `src/app/control/clinic/ServiceList.php`
- `src/app/config/translations.json`

**Interface**
- Produz: `onAskDelete` usa `TQuestion(_t('Delete the service "^1"?', CvFormat::e($service->name())), $action)` e a confirmação de Duplicar usa `_t('Duplicate the service "^1"?', CvFormat::e($service->name()))`. O serviço é carregado por `findById` no tenant; id inexistente → `TMessage('error', _t('Record not found'))`, sem pergunta.
- Produz: em `translations.json`: `Delete the service "^1"?` → `Excluir o serviço "^1"?`; `Duplicate the service "^1"?` → `Duplicar o serviço "^1"?`; `In queue` → `Na fila` (de T-41); sem a chave `Duplicate this service?`, que fica órfã; sem duplicata exata nem por `casefold()`.
- Consome: nada

**Teste RED**
- sem teste: controller Adianti e dicionário JSON fora de `tests/run.php`; a prova é o gate e o script de duplicatas

**Critério de aceite**
- `grep -c "Do you really want to delete" src/app/control/clinic/ServiceList.php` = 0; `grep -rn "Duplicate this service?" src/app --include=*.php` vazio.
- Script python: `dup=0 dupcase=0` e as 3 chaves presentes.
- GATE:
  - Excluir "R2 varredura Serviço (cópia)" pergunta `Excluir o serviço "R2 varredura Serviço (cópia)"?`, e cancelar deixa `COUNT(*)` igual;
  - Duplicar pergunta `Duplicar o serviço "R2 varredura Serviço"?`;
  - um serviço renomeado para `R2 <b>x</b>` aparece com as tags literais na pergunta;
  - console 0 `error`.

**Validação**
- LINT de `ServiceList.php` (evidência: `No syntax errors detected`)
- `python3 -c "import json;…"` sobre `translations.json` (evidência: `dup=0 dupcase=0 missing=0`)
- GATE → as 3 confirmações (evidência: texto do diálogo no snapshot, `innerHTML` com `&lt;b&gt;`, `COUNT(*)` igual ao cancelar, console 0 `error`)

### T-44 — Onda 9: PrescriptionForm com mensagens de domínio em pt nos demais catches

**Camada:** frontend
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** simples
**Agente:** Kratos

Pendência T-30: o catch-all de `onSaveTemplate` mostra `$e->getMessage()` cru, e o nome de modelo repetido aparece em inglês e sem escape (`PrescriptionForm.php:980`). O mesmo acontece em `onAskTemplateName` (:760), `onAddItem` (:926), `onApplyTemplate` (:1026) e `onGeneratePdf` (:1084). Os catches de `AuthorizationDenied`/`MissingTenantContext` com `_t` próprio ficam como estão, porque são seguros e traduzidos. A evidência de `COUNT(*)` da guarda de `onSave` no navegador entra no gate.

**Arquivos prováveis**
- `src/app/control/clinic/PrescriptionForm.php`

**Interface**
- Produz: os 5 catch-all citados passam a `error_log(<classe>: <mensagem>)` + `TMessage('error', CvFormat::userError($e))`. Nenhum `TMessage` de `PrescriptionForm` recebe `$e->getMessage()` direto.
- Consome: nada

**Teste RED**
- sem teste: controller Adianti fora de `tests/run.php`; a tradução e o escape estão cobertos por `CvFormatUserErrorTest` (T-28/T-49)

**Critério de aceite**
- `grep -n "TMessage('error', \$e->getMessage())" src/app/control/clinic/PrescriptionForm.php` vazio.
- GATE (atendimento `R2 varredura` em andamento):
  - Salvar como modelo com o nome de um modelo existente ("R2 varredura Modelo") mostra `Já existe um modelo chamado "R2 varredura Modelo"`;
  - `PrescriptionForm` sem parâmetros → Salvar com 1 item deixa `SELECT COUNT(*) FROM prescription` igual antes e depois;
  - console 0 `error`.

**Validação**
- LINT de `PrescriptionForm.php` (evidência: `No syntax errors detected`)
- `grep -c "getMessage()" /var/www/html/centralvet/src/app/control/clinic/PrescriptionForm.php` (evidência: só nas linhas de `error_log`, listadas no relatório)
- GATE → os 2 fluxos do critério (evidência: texto do diálogo, `COUNT(*)` antes e depois, console 0 `error`)

### T-45 — Onda 9: EncounterView com userError nos catches restantes e gate de anexo, retorno e resumo de IA

**Camada:** frontend
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Yoda

Pendência T-31: os catches genéricos de `onAcceptAiSummary` (:1604), `onScheduleFollowUp` (`SchedulingConflictException` e `Exception`, :1770, :1786) e `onAttachDocument` (:1831) mostram `$e->getMessage()` cru, sem `error_log`. O gate de anexar documento, retorno e aceitar resumo de IA não foi exercitado.

**Arquivos prováveis**
- `src/app/control/clinic/EncounterView.php`

**Interface**
- Produz: os 4 catches citados passam a `error_log` + `TMessage('error', CvFormat::userError($e))`. Nenhum `TMessage` de `EncounterView` recebe `$e->getMessage()` direto.
- Consome: nada

**Teste RED**
- sem teste: controller Adianti fora de `tests/run.php`; a prova é grep e o gate

**Critério de aceite**
- `grep -n "TMessage('error', \$e->getMessage())" src/app/control/clinic/EncounterView.php` vazio; SUITE com `PASS  Integration\EncounterTimelineIntegrationTest::`.
- GATE (atendimento `R2 varredura` em andamento do paciente 2772):
  - anexar um PNG pequeno recarrega o próprio atendimento, e o texto "Informe um encounter_id" não aparece;
  - agendar retorno num horário livre recarrega o atendimento, e `SELECT COUNT(*) FROM appointment` fica +1;
  - agendar retorno num horário ocupado mostra a mensagem de conflito em pt;
  - "aceitar resumo de IA", se o bloco estiver visível (IA oculta por premissa da fase 10), recarrega o atendimento; se o bloco não existir na tela, fica `[não aplicável]` com o motivo;
  - console 0 `error`.

**Validação**
- LINT de `EncounterView.php` (evidência: `No syntax errors detected`) + SUITE (evidência: `PASS  Integration\EncounterTimelineIntegrationTest::`, `Failed: 0`)
- GATE → os fluxos do critério (evidência: snapshot depois de cada ação, `COUNT(*)` de `appointment`, texto da mensagem de conflito, console 0 `error`)

### T-46 — Onda 9: FinancialOverview sem nosniff pelo PHP

**Camada:** frontend
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** simples
**Agente:** Thanos

Pendência T-32: `FinancialOverview.php:166` ainda envia `X-Content-Type-Options: nosniff` pelo PHP, o que contraria a decisão da onda 8 (fonte única no nginx, `fastcgi_hide_header`).

**Arquivos prováveis**
- `src/app/control/clinic/FinancialOverview.php`

**Interface**
- Produz: `onExport` não chama mais `header('X-Content-Type-Options: ...')`. `Content-Type` e `Content-Disposition` não mudam.
- Consome: nada

**Teste RED**
- sem teste: header HTTP de controller Adianti; a prova é grep e o header lido no gate

**Critério de aceite**
- `grep -rn "X-Content-Type-Options" src/app/control` vazio.
- GATE: `fetch` de `FinancialOverview&method=onExport&static=1&from=…&to=…` mostra `x-content-type-options: nosniff` uma vez (vindo do nginx) e `content-type: text/csv`; console 0 `error`.

**Validação**
- LINT de `FinancialOverview.php` (evidência: `No syntax errors detected`)
- `grep -rn "X-Content-Type-Options" /var/www/html/centralvet/src/app/control` (evidência: vazio)
- GATE → `fetch` do export (evidência: `headers.get('x-content-type-options')` = `nosniff`, sem vírgula)

### T-47 — Onda 9: foto anterior apagada só depois do commit e teste do deleteQuietly

**Camada:** backend
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Tesla

Pendências T-32:
- a foto anterior é apagada do storage dentro da transação, antes de `TTransaction::close()`. Se o commit falhar, o banco volta para a chave antiga, que já não existe no storage (`PatientService.php:247-249`, `PatientForm.php:443-446`);
- `deleteQuietly` não tem teste (`PatientService.php:255-262`);
- 3 itens do GATE sem evidência: inputs com `key=999999`, sexo `X` em pt e console.

**Arquivos prováveis**
- `src/app/Core/Application/PatientService.php`
- `src/tests/Unit/PatientServiceTest.php`
- `src/app/control/clinic/PatientForm.php`

**Interface**
- Produz: `PatientService::attachPhoto(...)` (assinatura mantida) não apaga mais a chave anterior e passa a guardá-la, lida por `PatientService::previousPhotoKey(): ?string` (a do último `attachPhoto`, ou `null`). Se o `save()` lançar, a chave **nova** é apagada, como hoje.
- Produz: `PatientService::discardPhoto(string $objectKey): void` (público, com a lógica do `deleteQuietly` atual). Falha do storage → `error_log`, sem exceção.
- Produz: `PatientForm::onSave` chama `discardPhoto($service->previousPhotoKey())` só **depois** de `TTransaction::close()` bem-sucedido, e só quando a chave anterior não é nula e é diferente da nova.
- Consome: nada

**Teste RED**
- `src/tests/Unit/PatientServiceTest.php` — cobre três casos e falha antes da correção porque hoje a antiga some dentro de `attachPhoto` e os métodos não existem (comando: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php`): dois `attachPhoto` seguidos deixam a chave antiga **ainda** no `FakeStorage`, e `previousPhotoKey()` a devolve; `discardPhoto(<antiga>)` a remove; `discardPhoto` com um storage cujo `delete` lança (classe anônima) não propaga exceção.

**Critério de aceite**
- SUITE: `PASS  Unit\PatientServiceTest::` em todos os métodos, `Failed: 0`.
- GATE:
  - trocar a foto do paciente 2772 faz a nova aparecer, e a chave antiga some do bucket (se o cliente do storage estiver disponível; senão vale o RED);
  - `PatientForm&method=onEdit&key=999999` → `document.querySelectorAll('form[name="form_Patient"] input:not([disabled]):not([type=hidden])').length` = 0;
  - `sex` adulterado para `X` mostra "O sexo do paciente deve ser M, F ou U";
  - console 0 `error` nas 3 telas.

**Validação**
- LINT de `PatientService.php` e `PatientForm.php` (evidência: 2 `No syntax errors detected`) + SUITE (evidência: `PASS  Unit\PatientServiceTest::`, `Failed: 0`)
- GATE → os 3 fluxos do critério (evidência: `SELECT photo_object_key` antes e depois, contagem de inputs, texto em pt, console 0 `error`)

### T-48 — Onda 9: parser de moeda compartilhado (MoneyInput) e BankAccountForm com teto

**Camada:** backend
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Athena

Pendências T-34:
- entrada dentro da regex, mas fora do alcance de int, é gravada como lixo (`BankAccountForm.php:215`; `99999999999999999999` → `1864712049423024128`);
- "abc" digitado some sem diálogo visível;
- as outras 9 cópias de `toCents` convertem texto em 0 (a migração delas é T-50, na onda 10).
Gate de T-34 sem evidência: nome duplicado com `<b>R2</b>` e "Itaú & Cia".

**Arquivos prováveis**
- `src/app/Core/Presentation/MoneyInput.php`
- `src/tests/Unit/MoneyInputTest.php`
- `src/app/control/clinic/BankAccountForm.php`

**Interface**
- Produz: `CentralVet\Presentation\MoneyInput::toCents(string $raw, bool $allowNegative = false): int` (final, estático, sem Adianti): aparado vazio → 0; com vírgula → formato BR `^-?\d{1,3}(\.\d{3})*(,\d{1,2})?$` ou `^-?\d+(,\d{1,2})?$`; sem vírgula → `^-?\d+(\.\d{1,2})?$` (ponto decimal, do `replaceOnPost` das máscaras) ou `^-?\d{1,3}(\.\d{3})+$` (milhar BR sem centavos: `1.234` → 123400); parte inteira com mais de 13 dígitos, sinal negativo com `$allowNegative = false` ou qualquer outro texto → `\InvalidArgumentException('Invalid amount')`; a conversão é por string (sem `float`): `1234,5` → 123450, `0,01` → 1, `-50,00` → -5000.
- Produz: `BankAccountForm` usa `MoneyInput::toCents($raw, true)` no lugar do `toCents` privado, que sai. O `oninput` do campo de saldo deixa de apagar texto não numérico: o valor digitado chega ao servidor, e o `onSave` responde `TMessage('error', CvFormat::userError($e))` ("Valor inválido"), mantendo os dados do formulário.
- Consome: nada

**Teste RED**
- `src/tests/Unit/MoneyInputTest.php` — tabela de casos: `''` → 0, `'1.234,56'` → 123456, `'1234,5'` → 123450, `'1234.56'` → 123456, `'1.234'` → 123400, `'0,01'` → 1, `'-50,00'` com `allowNegative` → -5000; e lançam `Invalid amount`: `'-50,00'` sem `allowNegative`, `'abc'`, `'12,345'`, `'99999999999999,00'` (14 dígitos) e `'1,2,3'`. Falha antes da implementação porque a classe não existe (comando: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php`)

**Critério de aceite**
- SUITE: `PASS  Unit\MoneyInputTest::` em todos os métodos, `Failed: 0`.
- `grep -c "function toCents" src/app/control/clinic/BankAccountForm.php` = 0.
- GATE:
  - saldo `99999999999999999999` (forçado por `browser_evaluate`) mostra "Valor inválido", e `SELECT MAX(balance_cents) FROM bank_account` fica igual;
  - "abc" digitado mostra aviso visível;
  - nova conta com o nome de outra da unidade mostra a mensagem em pt, e `<b>R2</b>` aparece literal (`innerHTML` com `&lt;b&gt;`);
  - banco "Itaú & Cia" aparece como "Itaú & Cia" na lista;
  - console 0 `error`.

**Validação**
- LINT de `MoneyInput.php` e `BankAccountForm.php` (evidência: 2 `No syntax errors detected`) + SUITE (evidência: `PASS  Unit\MoneyInputTest::`, `Failed: 0`)
- GATE → os 4 fluxos do critério (evidência: textos e `innerHTML` no snapshot, `SELECT MAX(balance_cents)` igual, console 0 `error`)
- Review Focus: `99999999999999999999` no saldo → recusado, sem gravar valor truncado (evidência: `SELECT MAX(balance_cents)` igual)

### T-49 — Onda 9: testes mais fortes de T-28, T-36 e T-38

**Camada:** backend
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** simples
**Agente:** Arquimedes

Sugestões dos revisores:
- T-28: `testCatalogMessageGoesThroughTranslationKey` passa sem a implementação, porque o `_t` de teste é identidade (`CvFormatUserErrorTest.php:57-64`);
- T-36: o teste de negação por política não verifica que nada foi persistido, e a política anônima duplica `FakeAuthorizationPolicy` (`EncounterAccountServiceTest.php:260-279`);
- T-38: as asserções de outro tenant passam mesmo com sobrescrita, porque o Fake só vê o tenant 1 (`ProductServiceTest.php:98-99`), e o comentário do Fake cita `utf8mb4_unicode_ci`, mas a tabela usa `utf8mb4_0900_ai_ci` (`FakeProductRepository.php:72`).

**Arquivos prováveis**
- `src/tests/Unit/CvFormatUserErrorTest.php`
- `src/tests/Unit/EncounterAccountServiceTest.php`
- `src/tests/Support/FakeAuthorizationPolicy.php`
- `src/tests/Unit/ProductServiceTest.php`
- `src/tests/Support/FakeProductRepository.php`

**Interface**
- Produz: o `_t` de teste de `CvFormatUserErrorTest` devolve `'[t]' . <chave com ^n substituídos>`, e o teste do catálogo assere o prefixo `[t]`. Se o `_t` global já existir (Adianti carregado), o teste é marcado `SkippedTestException` com o motivo.
- Produz: `FakeAuthorizationPolicy::setAllowed(bool $allowed): void`, com `$allowed` mutável e o construtor mantido. O teste de negação usa o Fake compartilhado e confere que a conta ficou sem desconto (`discountCents() === 0`) e que o repositório não recebeu `save`.
- Produz: `FakeProductRepository` guarda os produtos de todos os tenants e filtra por `$tenantId` nas leituras, com o construtor e as assinaturas mantidos. `ProductServiceTest` semeia um produto do tenant 2 no mesmo armazenamento e assere que ele segue com nome e custo originais depois do `update` recusado. O comentário cita `utf8mb4_0900_ai_ci` e que `mb_strtolower` não iguala acentos.
- Consome: nada

**Teste RED**
- `src/tests/Unit/CvFormatUserErrorTest.php`, `src/tests/Unit/ProductServiceTest.php`, `src/tests/Support/FakeProductRepository.php`, `src/tests/Support/FakeAuthorizationPolicy.php` — os testes reforçados rodam contra uma versão sabotada, anotada no relatório: um `userError` que devolve `getMessage()` e um `update` que sobrescreve o produto do tenant 2. Falham nessa versão e passam no HEAD; o commit RED leva só os testes e os Fakes (comando: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php`)

**Critério de aceite**
- SUITE: `PASS` em todos os métodos de `Unit\CvFormatUserErrorTest`, `Unit\EncounterAccountServiceTest` e `Unit\ProductServiceTest`, e nos demais usuários de `FakeAuthorizationPolicy`/`FakeProductRepository` (`StockServiceTest`, `SaleServiceTest`), `Failed: 0`.
- `## RED` do relatório mostra a falha dos testes reforçados contra a versão sabotada (diff da sabotagem colado, não commitado).

**Validação**
- LINT dos 5 PHP (evidência: 5 `No syntax errors detected`) + SUITE (evidência: as linhas `PASS` citadas, `Failed: 0`)

### T-50 — Onda 10: MoneyInput nos 9 formulários com toCents

**Camada:** frontend
**Dependências:** T-48
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Darwin

Pendência T-34: as outras 9 cópias de `toCents` convertem texto em 0 em silêncio. Estão em `ServiceForm`, `ProductForm`, `FinancialEntryForm`, `PaymentForm`, `ProcedureCatalogForm`, `ExamCatalogForm`, `CashSessionForm`, `PayableForm` e `EncounterAccountForm` (`grep -rln "function toCents" src/app/control`).

**Arquivos prováveis**
- `src/app/control/clinic/ServiceForm.php`
- `src/app/control/clinic/ProductForm.php`
- `src/app/control/clinic/FinancialEntryForm.php`
- `src/app/control/clinic/PaymentForm.php`
- `src/app/control/clinic/ProcedureCatalogForm.php`
- `src/app/control/clinic/ExamCatalogForm.php`
- `src/app/control/clinic/CashSessionForm.php`
- `src/app/control/clinic/PayableForm.php`
- `src/app/control/clinic/EncounterAccountForm.php`

**Interface**
- Produz: cada um dos 9 formulários chama `MoneyInput::toCents($raw)`, com `allowNegative` `false` em todos, e o `toCents` privado sai. `InvalidArgumentException('Invalid amount')` chega ao usuário por `TMessage('error', CvFormat::userError($e))` ("Valor inválido"), e o formulário mantém os dados. Antes de trocar, o implementador confere no relatório o formato que cada máscara posta (`setNumericMask` com ou sem `replaceOnPost`) contra os formatos aceitos por `MoneyInput`.
- Consome: T-48 `CentralVet\Presentation\MoneyInput::toCents(string $raw, bool $allowNegative = false): int`

**Teste RED**
- sem teste: controllers Adianti fora de `tests/run.php`; o parser está coberto pelo RED de T-48 e cada tela pelo gate

**Critério de aceite**
- `grep -rln "function toCents" src/app/control` vazio.
- GATE:
  - salvar com valor válido ("12,34") em `ServiceForm`, `ProductForm`, `FinancialEntryForm`, `ExamCatalogForm`, `ProcedureCatalogForm` e `PayableForm` (registros `R2 varredura`) grava `1234` na coluna de centavos;
  - valor "abc" forçado em `ServiceForm` e `FinancialEntryForm` mostra "Valor inválido", e `COUNT(*)` fica igual;
  - `PaymentForm`, `CashSessionForm` e `EncounterAccountForm` só abrem e conferem o formato do campo (sem gravar), porque a gravação altera caixa e conta;
  - console 0 `error`.

**Validação**
- LINT dos 9 PHP (evidência: 9 `No syntax errors detected`)
- `grep -rln "function toCents" /var/www/html/centralvet/src/app/control` (evidência: vazio)
- GATE → os fluxos do critério (evidência: `SELECT <coluna>_cents` dos registros salvos = 1234, mensagem "Valor inválido", `COUNT(*)` igual, console 0 `error`)

### T-51 — Onda 10: mensagens de agendamento em pt e i18n da onda 10

**Camada:** frontend
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** simples
**Agente:** Platão

Achado do gate da onda 9 (`reviews/T-45.md § Complemento`, `reports/T-45.md`): o conflito de retorno e de agendamento mostra a `SchedulingConflictException` em inglês. A mensagem é `Requested slot <Y-m-d H:i>-<H:i> conflicts with an existing appointment for professional_system_user_id <n>` (`AppointmentService.php:314-316`). `AppointmentForm.php:225` faz `new TMessage('error', $e->getMessage())` sem `error_log`. Escritor único de `translations.json` na onda 10.

**Arquivos prováveis**
- `src/app/Core/Presentation/UserMessage.php`
- `src/tests/Unit/UserMessageTest.php`
- `src/app/control/clinic/AppointmentForm.php`
- `src/app/config/translations.json`

**Interface**
- Produz: `UserMessage::PATTERNS` ganha `/^Requested slot .+ conflicts with an existing appointment for professional_system_user_id \d+$/` → `Requested slot conflicts with an existing appointment`, sem parâmetros. As entradas existentes não mudam.
- Produz: o catch de `SchedulingConflictException` de `AppointmentForm` (:225) passa a `TTransaction::rollback()` + `error_log(__METHOD__ . ': ' . $e->getMessage())` + `TMessage('error', CvFormat::userError($e))`. Nenhum `TMessage` de `AppointmentForm` recebe `$e->getMessage()` direto.
- Produz: `translations.json`, sem duplicata exata nem por `casefold()`, com: `Requested slot conflicts with an existing appointment` → `O horário solicitado conflita com um agendamento existente`; `Attachment not found` → `Anexo não encontrado` (de T-52); cada chave das linhas `- [T-45] i18n:`, `- [T-41] i18n:`, `- [T-50] i18n:` e `- [T-52] i18n:` do board, com o `pt` pedido (inclui a chave de data e hora inválidas que T-45 registrar, com pt `Data e hora inválidas`).
  T-41, T-50 e T-52 não editam o JSON: registram a chave no board antes de terminar. T-51 fecha por último na onda e relê o board antes do commit final.
- Consome: nada

**Teste RED**
- `src/tests/Unit/UserMessageTest.php` — `resolve('Requested slot 2026-09-30 14:00-14:30 conflicts with an existing appointment for professional_system_user_id 1')` devolve key `Requested slot conflicts with an existing appointment` e params `[]`; falha antes da correção porque hoje devolve `null` (comando: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php`)

**Critério de aceite**
- SUITE: `PASS  Unit\UserMessageTest::` em todos os métodos, `Failed: 0`.
- `grep -n "TMessage('error', \$e->getMessage())" src/app/control/clinic/AppointmentForm.php` vazio.
- Script python: `dup=0 dupcase=0`, a chave de conflito e as chaves `i18n:` de T-41/T-45/T-50/T-52 do board presentes.
- GATE:
  - `AppointmentForm` → agendar num horário ocupado do mesmo profissional mostra "O horário solicitado conflita com um agendamento existente";
  - no `EncounterView` (T-45), agendar retorno num horário ocupado mostra o mesmo texto;
  - console 0 `error`.

**Validação**
- LINT de `UserMessage.php` e `AppointmentForm.php` (evidência: 2 `No syntax errors detected`) + SUITE (evidência: `PASS  Unit\UserMessageTest::`, `Failed: 0`)
- `python3 -c "import json;…"` sobre `translations.json` (evidência: `dup=0 dupcase=0 missing=0`)
- GATE → os 2 fluxos do critério (evidência: texto do diálogo no snapshot, `SELECT COUNT(*) FROM appointment` igual, console 0 `error`)

### T-52 — Onda 10: anexos do atendimento registrados em stored_object e listados

**Camada:** backend
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** alta
**Agente:** Sherlock

Achado do gate da onda 9 (`reviews/T-45.md § Complemento`, `reports/T-45.md`): anexar no `EncounterView` grava o objeto no MinIO (`…/encounter/4304/r2-anexo.pdf`), mas `SELECT COUNT(*) FROM stored_object` = 0 e a lista mostra "Nenhum anexo ainda". É a mesma pendência antiga de `EncounterDocumentService::list()` vazio. A causa provável está no docblock de `EncounterDocumentService.php:28-36`: `list()` devolve `[]` por desenho, porque nenhum repositório grava ou lê `stored_object`, e `attach()` só chama `StorageInterface::put()`. A tabela `stored_object` já existe (migration 0001: `public_id`, `tenant_id`, `system_unit_id`, `storage_provider`, `bucket`, `object_key`, `version_id`, `original_name`, `content_type`, `size_bytes`, `sha256`, `status`, `created_by`, `deleted_at`, índice `stored_object_locator_idx (tenant_id, bucket, object_key(255))`). Assim, nenhum schema novo é necessário: a ligação com o atendimento é o prefixo lógico `tenant/<t>/encounter/<id>/` dentro de `object_key`. Reprodução exigida em `## RED`: a confirmação dessa causa (grep de `stored_object` em `src/app/Core` e `SELECT COUNT(*) FROM stored_object`). Se a causa for outra, ou se a correção exigir coluna ou tabela nova, o implementador para antes de editar e devolve `precisa de contexto` com a evidência; schema novo fica fora do escopo.

**Arquivos prováveis**
- `src/app/Core/Domain/Contract/StoredObjectRepositoryInterface.php`
- `src/app/Core/Persistence/StoredObjectRepository.php`
- `src/tests/Support/FakeStoredObjectRepository.php`
- `src/app/Core/Application/EncounterDocumentService.php`
- `src/tests/Unit/EncounterDocumentServiceTest.php`
- `src/tests/Integration/StoredObjectRepositoryIntegrationTest.php`
- `src/app/control/clinic/EncounterView.php`

**Interface**
- Produz: `StoredObjectRepositoryInterface` com: `record(StoredObjectMetadata $metadata, string $originalName, ?int $systemUnitId, int $createdBy): array`: grava uma linha em `stored_object` com `public_id` UUID v4, `tenant_id` do contexto, `status = 'available'` e os campos de `$metadata->toStoredObjectRow()`, e devolve a linha; `listByObjectKeyFragment(string $fragment): array`: devolve `list<array{public_id: string, original_name: string, content_type: string, size_bytes: int, created_at: string, object_key: string}>` do tenant com `object_key LIKE CONCAT('%', :fragment, '%')`, `status = 'available'` e `deleted_at IS NULL`, ordenada por `created_at DESC, id DESC`; `findByPublicId(string $publicId): ?array`, com escopo de tenant.
  `StoredObjectRepository` (PDO + `TenantQuery`) e `FakeStoredObjectRepository` (em memória, por tenant) implementam a interface.
- Produz: `EncounterDocumentService::__construct(StorageInterface $storage, TenantContext $tenant, ?StoredObjectRepositoryInterface $objects = null)`. Os chamadores com 2 argumentos, como `ExamResultForm`, continuam válidos, e sem `$objects` o comportamento de hoje fica. Com `$objects`: `attach(int $encounterId, string $fileName, string $contents, string $contentType): object` faz `put()` e depois `record($metadata, $fileName, $tenant->unitId(), $tenant->userId())`, e devolve o metadata como hoje; se o `record` lançar, faz `delete()` do objeto recém-gravado e relança; `list(int $encounterId): array` devolve `listByObjectKeyFragment(sprintf('tenant/%d/encounter/%d/', $tenantId, $encounterId))`; `download(int $encounterId, string $publicId): ?array` → `array{contents: string, content_type: string, original_name: string}` via `findByPublicId` + `$storage->get(<object_key>)`, só quando o `object_key` contém o fragmento do mesmo atendimento; senão `null`.
  O docblock de "Known limitation" é atualizado.
- Produz: `EncounterView`: `makeEncounterDocumentService` passa `new StoredObjectRepository($context, TTransaction::get())`; `onAttachDocument` roda `attach` dentro de `TTransaction::open('permission')`/`close()` (rollback no catch); a lista mostra, por anexo, `original_name`, tamanho (KB) e data `d/m/Y H:i`, com link `engine.php?class=EncounterView&method=onDownloadDocument&static=1&encounter_id=<id>&public_id=<uuid>` (`target="_blank"`); `EncounterView::onDownloadDocument($param)` (static) responde os bytes com `Content-Type` do registro e `Content-Disposition: attachment; filename="<nome saneado>"`, ou 404 sem corpo quando `download()` é `null`; chave nova `Attachment not found` no board (gravada por T-51).
- Consome: nada

**Teste RED**
- `src/tests/Unit/EncounterDocumentServiceTest.php`, `src/tests/Support/FakeStoredObjectRepository.php`, `src/tests/Integration/StoredObjectRepositoryIntegrationTest.php` — falha antes da correção porque a interface não existe e `list()` devolve `[]` (comando: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php`): com `FakeStorage` e `FakeStoredObjectRepository`, `attach(10, 'r2.pdf', …)` seguido de `list(10)` devolve 1 item com `original_name = 'r2.pdf'`, e `list(11)` devolve `[]`; `download(10, <public_id>)` devolve os bytes; `download(11, <public_id do 10>)` devolve `null`; `record` que lança deixa o `FakeStorage` sem o objeto; `testListReturnsEmptyArrayPerDocumentedGap` passa a cobrir só o caso sem `$objects`; na integração (transação com rollback), `record` + `listByObjectKeyFragment` devolve a linha, e com outro tenant no contexto devolve `[]`.

**Critério de aceite**
- SUITE: `PASS  Unit\EncounterDocumentServiceTest::` e `PASS  Integration\StoredObjectRepositoryIntegrationTest::` em todos os métodos, `Failed: 0`.
- GATE (atendimento `R2 varredura` em andamento, ex.: 4304):
  - anexar `r2-anexo-2.pdf` faz `SELECT COUNT(*) FROM stored_object WHERE object_key LIKE '%/encounter/4304/%'` ficar +1;
  - a lista mostra "r2-anexo-2.pdf" com tamanho e data, e o link baixa o arquivo (rede 200, `content-disposition: attachment`);
  - `onDownloadDocument` com `public_id` de outro atendimento devolve 404;
  - o anexo antigo `r2-anexo.pdf`, gravado só no MinIO antes da correção, não aparece, porque não tem registro;
  - console 0 `error`.

**Validação**
- LINT dos 6 PHP (evidência: 6 `No syntax errors detected`) + SUITE (evidência: as linhas `PASS` citadas, `Failed: 0`)
- GATE → os fluxos do critério (evidência: `SELECT COUNT(*)` antes e depois, snapshot da lista, `browser_network_requests` do download 200 e do 404, console 0 `error`)
- Review Focus: `onDownloadDocument` de um anexo do atendimento A pedido com `encounter_id` de B → 404, sem bytes (evidência: status da requisição)

### T-53 — Onda 11: data e hora do agendamento sem troca de dia/mês e erros de banco com texto genérico

**Camada:** backend
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** alta
**Agente:** Kratos

Achado do gate da onda 10 (`reviews/T-51.md § Complemento`): no `AppointmentForm`, "01/10/2026 11:00" foi gravado como 10/01/2026 (agendamento R2 id 38). Causa provável, a confirmar antes de corrigir:
- `AppointmentForm.php:78` cria `new TDateTime('scheduled_at')` sem `setMask`/`setDatabaseMask`, ao contrário dos outros campos de data do projeto (`VaccinationForm:69-70`, `PayableForm:59-60`, `PatientForm:162-163`);
- o texto postado chega a `AppointmentService` (:97, :200), que faz `new DateTimeImmutable((string) $data['scheduled_at'])`;
- o PHP lê `01/10/2026` como `m/d/Y`.
Reprodução exigida em `## RED`:
- `php -r 'echo (new DateTimeImmutable("01/10/2026 11:00"))->format("Y-m-d");'` no container (evidência: `2026-01-10`);
- o valor postado pelo formulário, lido do relatório de T-51 ou de um `var_dump` temporário numa cópia em worktree isolada, nunca no checkout compartilhado.
Se for artefato da automação (fill fora do formato do widget), o implementador prova com o teste de parse, registra no relatório e aplica mesmo assim a validação estrita do serviço.
Também entram:
- T-51: o catch-all de `onSave`/`onReschedule` mostra o texto cru de exceções fora do catálogo, inclusive `PDOException` (`AppointmentForm.php:255-259`, `303-308`);
- a mesma regra de "texto genérico para PDO", que hoje só existe em `EncounterView::screenError` (T-45).
Auditoria dos demais campos de data e hora: `VaccinationForm`, `StockBatchForm`, `PatientForm`, `PayableForm`, `FinancialEntryList` e `FinancialOverview` já usam `setMask('dd/mm/yyyy')` + `setDatabaseMask('yyyy-mm-dd')` ou parse próprio; `PrescriptionForm` (`prescription_date`, `valid_until`) usa só `setMask`. O resultado entra no relatório com a evidência por campo, e campo quebrado fora desta lista vira pendência.

**Arquivos prováveis**
- `src/app/Core/Presentation/DateTimeInput.php`
- `src/tests/Unit/DateTimeInputTest.php`
- `src/app/Core/Application/AppointmentService.php`
- `src/tests/Unit/AppointmentServiceTest.php`
- `src/app/control/clinic/AppointmentForm.php`
- `src/app/lib/widget/CvFormat.php`
- `src/tests/Unit/CvFormatUserErrorTest.php`
- `src/app/Core/Presentation/UserMessage.php`

**Interface**
- Produz: `CentralVet\Presentation\DateTimeInput::parse(string $raw): DateTimeImmutable` (final, estático, sem Adianti): aceita, aparado e estrito (`createFromFormat` com `!` + `getLastErrors()` sem warning nem erro), os formatos `Y-m-d H:i`, `Y-m-d H:i:s`, `d/m/Y H:i` e `d/m/Y H:i:s`; ano fora de 1900..2100 ou qualquer outro texto → `\InvalidArgumentException('Invalid date and time')`.
  É a mesma regra de `EncounterView::parseFollowUpScheduledAt`, que T-60 passa a usar.
- Produz: `AppointmentService::schedule()` e `reschedule()` convertem `scheduled_at` string por `DateTimeInput::parse`, no lugar de `new DateTimeImmutable((string) …)`; `DateTimeImmutable` recebido continua aceito.
- Produz: `AppointmentForm`: `scheduled_at` com `setMask('dd/mm/yyyy hh:ii')` e `setDatabaseMask('yyyy-mm-dd hh:ii')`; `onEdit` preenche o campo no formato do banco (`Y-m-d H:i`); os catch-all de `onSave` e `onReschedule` usam `error_log` + `TMessage('error', CvFormat::userError($e))`.
- Produz: `CvFormat::userError(\Throwable $e): string` (assinatura mantida) devolve `CvFormat::e(_t('Could not complete the operation. Please try again'))` quando `$e` ou qualquer `getPrevious()` é `\PDOException` ou tem `SQLSTATE[` na mensagem. É a regra de `EncounterView::screenError`, antes das demais.
- Produz: `UserMessage::STATIC` ganha `Invalid date and time`, com a chave igual à mensagem (pt já gravado por T-51: "Data e hora inválidas").
- Consome: nada

**Teste RED**
- `src/tests/Unit/DateTimeInputTest.php`, `src/tests/Unit/AppointmentServiceTest.php`, `src/tests/Unit/CvFormatUserErrorTest.php` — falha antes da correção porque a classe não existe, o serviço lê 10/jan e o `userError` devolve o SQLSTATE (comando: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php`): `parse('01/10/2026 11:00')` → `2026-10-01 11:00`; `parse('2026-10-01 11:00')` → o mesmo; `parse('10/01/2026 11:00')` → `2026-01-10`; `parse('13/13/2026 11:00')`, `parse('01/10/2026')`, `parse('abc')` e `parse('01/10/2300 11:00')` lançam `Invalid date and time`; `schedule([... 'scheduled_at' => '01/10/2026 11:00'])` grava `scheduledAt` `2026-10-01 11:00`; `userError(new \RuntimeException('x', 0, new \PDOException('SQLSTATE[23000] …')))` devolve o texto genérico.

**Critério de aceite**
- SUITE: `PASS` em todos os métodos de `Unit\DateTimeInputTest`, `Unit\AppointmentServiceTest`, `Unit\CvFormatUserErrorTest` e `Unit\UserMessageTest`, `Failed: 0`.
- `grep -n "new DateTimeImmutable((string) \$data\['scheduled_at'\])" src/app/Core/Application/AppointmentService.php` vazio.
- GATE:
  - `AppointmentForm` novo `R2 varredura` com "01/10/2026 11:00" (digitado no widget) faz `SELECT scheduled_at FROM appointment ORDER BY id DESC LIMIT 1` dar `2026-10-01 11:00:00`, e a Agenda de 01/10 o mostra;
  - editar esse agendamento reabre "01/10/2026 11:00", e remarcar para "02/10/2026 11:00" grava `2026-10-02 11:00:00`;
  - o agendamento id 38 (10/01/2026) fica como está (dado R2);
  - console 0 `error`.

**Validação**
- LINT dos 6 PHP de Core/widget/controller (evidência: 6 `No syntax errors detected`) + SUITE (evidência: as linhas `PASS` citadas, `Failed: 0`)
- GATE → os fluxos do critério (evidência: `SELECT scheduled_at` depois de cada um, snapshot da Agenda, console 0 `error`)
- Review Focus: "01/10/2026 11:00" gravado como 1º de outubro, pela tela e pelo serviço (evidência: `SELECT` e o RED)

### T-54 — Onda 11: FinancialEntryForm append-only, MoneyInput com zeros à esquerda e limpeza de T-50, i18n da onda 11

**Camada:** frontend
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Darwin

Achado do gate de T-50: salvar o `FinancialEntryForm` com um id existente criou um registro novo em vez de editar. `financial_entry` é append-only por desenho: o docblock do form diz, em :27 e :134, "não há edição por key"; o repositório tem um UPDATE só de `category`, inalcançável por `record()`; o domínio é imutável. Reprodução exigida em `## RED`: a URL ou o POST que carregou o id no formulário, com `SELECT COUNT(*) FROM financial_entry` antes e depois. A correção é recusar a edição, não suportá-la. Também entram:
- T-48: zeros à esquerda contam para o teto de 13 dígitos (`MoneyInput.php:77`);
- T-50: comentário duplicado e linhas em branco em `ProductForm.php:67-75`, FQCN `\CentralVet\Presentation\MoneyInput` repetido 12 vezes (um `use` por arquivo) e placeholder `ex.: 12,34` fora do i18n.
Escritor único de `translations.json` na onda 11.

**Arquivos prováveis**
- `src/app/control/clinic/FinancialEntryForm.php`
- `src/app/Core/Presentation/MoneyInput.php`
- `src/tests/Unit/MoneyInputTest.php`
- `src/app/control/clinic/ServiceForm.php`
- `src/app/control/clinic/ProductForm.php`
- `src/app/control/clinic/PaymentForm.php`
- `src/app/control/clinic/ProcedureCatalogForm.php`
- `src/app/control/clinic/ExamCatalogForm.php`
- `src/app/control/clinic/CashSessionForm.php`
- `src/app/control/clinic/PayableForm.php`
- `src/app/control/clinic/EncounterAccountForm.php`
- `src/app/config/translations.json`

**Interface**
- Produz: `FinancialEntryForm`: com `id`/`key` na requisição (`onEdit` ou `onSave` com `$data->id`/`$param['key']` > 0), mostra `TMessage('warning', _t('Financial entries cannot be edited. Register a new entry to correct it'))` e não chama `record()`; `onEdit` com `key` limpa o formulário e mostra o mesmo aviso; o campo `id` oculto, se existir, sai; Novo e Salvar sem id seguem como hoje.
- Produz: `MoneyInput::toCents(...)` (assinatura mantida) descarta os zeros à esquerda da parte inteira antes de comparar com `MAX_INTEGER_DIGITS` (`00000000000000,50` → 50), e `0,50` e `0` continuam válidos.
- Produz: nos 9 formulários de T-50 (`ServiceForm`, `ProductForm`, `PaymentForm`, `ProcedureCatalogForm`, `ExamCatalogForm`, `CashSessionForm`, `PayableForm`, `EncounterAccountForm` e o próprio `FinancialEntryForm`): `use CentralVet\Presentation\MoneyInput;` no topo e chamadas `MoneyInput::toCents(...)`, sem mudança de comportamento; o placeholder `_t('e.g. 12,34')`; o `ProductForm` sem o comentário duplicado.
- Produz: `translations.json` com `Financial entries cannot be edited. Register a new entry to correct it` → `Lançamentos não podem ser editados. Registre um novo lançamento para corrigir` e `e.g. 12,34` → `ex.: 12,34`, mais toda linha `i18n:` que T-53, T-55, T-56, T-57, T-58 ou T-59 registrarem no board, sem duplicata exata nem por `casefold()`.
- Consome: nada

**Teste RED**
- `src/tests/Unit/MoneyInputTest.php` — `toCents('00000000000000,50')` → 50 e `toCents('0000000000000012,34')` → 1234; `toCents('99999999999999,00')` (14 dígitos significativos) segue lançando `Invalid amount`; falha antes da correção porque hoje os zeros contam para o teto (comando: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php`)

**Critério de aceite**
- SUITE: `PASS  Unit\MoneyInputTest::` em todos os métodos, `Failed: 0`.
- `grep -c "\\\\CentralVet\\\\Presentation\\\\MoneyInput::" ` nos 9 formulários = 0, e `grep -c "use CentralVet\\\\Presentation\\\\MoneyInput;"` = 1 em cada; `grep -rn "'ex.: 12,34'" src/app/control` vazio.
- Script python: `dup=0 dupcase=0` e as chaves desta Interface presentes.
- GATE:
  - `FinancialEntryForm&method=onEdit&key=<id de um lançamento R2>` mostra "Lançamentos não podem ser editados…";
  - Salvar nesse estado (POST com `id` forçado por `browser_evaluate`) deixa `SELECT COUNT(*) FROM financial_entry` igual;
  - Novo lançamento "R2 varredura" R$ 1,00 faz `COUNT(*)` +1;
  - `ServiceForm` salva "12,34" como `1234`;
  - console 0 `error`.

**Validação**
- LINT dos 10 PHP (evidência: 10 `No syntax errors detected`) + SUITE (evidência: `PASS  Unit\MoneyInputTest::`, `Failed: 0`)
- `python3 -c "import json;…"` (evidência: `dup=0 dupcase=0 missing=0`)
- GATE → os fluxos do critério (evidência: aviso no snapshot, `COUNT(*)` antes e depois, `SELECT price_cents`, console 0 `error`)
- Review Focus: POST do `FinancialEntryForm` com `id` de lançamento existente → nenhum registro novo nem alterado (evidência: `COUNT(*)` e `SELECT amount_cents, category FROM financial_entry WHERE id=<id>` iguais)

### T-55 — Onda 11: queda da sessão do navegador depois da suíte e intervalo de TEST_REDIS_DATABASE

**Camada:** qa
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** alta
**Agente:** Naruto

Pendência das ondas 8–10: a sessão admin do navegador cai depois de cada SUITE, e T-42 provou que o Redis de sessão não é a causa. Hipóteses a medir:
- testes de integração que mexem em `system_users`, `system_access_log`, `system_user_unit` ou na sessão no MySQL, fora da transação com rollback (DDL implícito faz commit);
- o `SessionRegistry` (sessão única por usuário) registrando uma sessão nova para o usuário 1;
- o `docker compose run app` recriando ou compartilhando `tmp/` ou arquivos de sessão com o container `app`;
- o handler de sessão ativo (`SessionHandlerFactory`, variável de ambiente) ser `files`.
Também entra a sugestão de T-42: `run.php` não valida o intervalo de `TEST_REDIS_DATABASE` (99 ou -1 rodam no DB 0 sem recusar, `run.php:48-`).

Medição exigida em `## RED`, antes e depois de uma SUITE, com o navegador logado. Por hipótese:
- `SELECT id, login, active, frontpage_id, updated_at FROM system_users WHERE id = 1`, `SELECT COUNT(*) FROM system_access_log` e `SELECT MAX(id) FROM system_access_log`;
- no DB 0: `redis-cli -n 0 --scan --pattern '*registry*'`, `redis-cli -n 0 --scan --pattern 'centralvet:session:*'` e o TTL da chave de sessão do navegador;
- `docker compose exec app ls -la /var/www/html/src/tmp` e o diretório de `session.save_path`;
- `printenv | grep -i session` no `app` e no `run`.
Depois, isolar a hipótese rodando só a classe suspeita: copiar `tests/run.php` para um runner temporário na **worktree isolada** (`git worktree add` em `/tmp/claude-1000/wt-T-55`), nunca no checkout compartilhado, e remover a worktree ao fim.
Se a causa estiver em arquivo de teste fora dos "Arquivos prováveis", o implementador para e devolve `precisa de contexto` com o caminho. Se estiver em produção, registra a causa provada e a correção proposta em Pendências, sem editar.

**Arquivos prováveis**
- `src/tests/run.php`
- `src/tests/Support/MysqlIntegrationTestCase.php`
- `src/tests/Support/RedisIntegrationTestCase.php`
- `src/tests/Integration/SessionRedisIntegrationTest.php`
- `src/tests/Integration/Phase1TenantIsolationIntegrationTest.php`
- `src/tests/Integration/TenantIsolationMysqlIntegrationTest.php`
- `docs/runbooks/tests.md`

**Interface**
- Produz: a causa provada, no relatório, com a medição antes e depois que a isola, e a correção nos arquivos acima: o que a hipótese confirmada exigir (ex.: `SessionRegistry` com prefixo de teste, teste que usa usuário `R2 teste` e não o id 1, ou isolamento do `tmp/`).
- Produz: `run.php` recusa `TEST_REDIS_DATABASE` que não seja `ctype_digit` ou esteja fora de `0..15` com `Refusing to run: TEST_REDIS_DATABASE must be an integer between 0 and 15`, exit 1; e confere o retorno de `select()`, abortando se for `false`.
- Produz: `docs/runbooks/tests.md` com a causa e a regra.
- Consome: nada

**Teste RED**
- sem teste: infraestrutura do runner e isolamento entre a suíte e a sessão do navegador. A prova é a medição antes e depois em `## RED` e, depois da correção, a mesma medição sem mudança e o navegador logado

**Critério de aceite**
- Com o navegador logado (admin), depois de uma SUITE a próxima navegação do validador abre uma página interna sem voltar ao login, e as medições da hipótese confirmada ficam iguais antes e depois.
- `TEST_REDIS_DATABASE=99` e `TEST_REDIS_DATABASE=-1` saem com código 1 e a mensagem `Refusing to run`; `TEST_REDIS_DATABASE=15` roda com `Failed: 0`.
- A worktree de prova não existe mais ao fim (`git -C /var/www/html/centralvet worktree list` sem `/tmp/claude-1000/wt-T-55`).

**Validação**
- LINT dos PHP tocados (evidência: `No syntax errors detected` em cada)
- SUITE com o navegador logado + navegação do validador depois (evidência: página interna no snapshot, medições iguais)
- `docker compose run --rm --no-deps -T -e TEST_REDIS_DATABASE=99 -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php; echo $?` e o mesmo com `-1` (evidência: `Refusing to run` e `1` nos dois)
- `git -C /var/www/html/centralvet worktree list` (evidência: sem a worktree de prova)

### T-56 — Onda 11: anexos em stored_object também no ExamResultForm, sem órfão e com nome UTF-8

**Camada:** backend
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Sherlock

Pendências de T-52:
- `ExamResultForm` monta `EncounterDocumentService` com 2 argumentos (:277), então o resultado anexado não vai para `stored_object` e não aparece na lista do `EncounterView`;
- `onAttachDocument` faz `put()` e o INSERT e só depois `TTransaction::close()`: se o commit falhar, o objeto fica órfão (`EncounterView.php:1911-1913`);
- o `Content-Disposition` troca todo caractere não ASCII por `_`;
- a chave `Attachment not found` não tem uso.
Sem schema novo: `stored_object` já existe, e o vínculo com o atendimento é o fragmento `tenant/<t>/encounter/<id>/` do `object_key`.

**Arquivos prováveis**
- `src/app/Core/Application/EncounterDocumentService.php`
- `src/tests/Unit/EncounterDocumentServiceTest.php`
- `src/app/control/clinic/EncounterView.php`
- `src/app/control/clinic/ExamResultForm.php`

**Interface**
- Produz: `EncounterDocumentService::discard(object $metadata): void` apaga do storage o objeto de um `attach` cujo commit falhou (`$storage->delete($metadata->objectKey)`). Falha do storage → `error_log`, sem exceção.
- Produz: `EncounterView::onAttachDocument` e o upload do `ExamResultForm`: se `TTransaction::close()` (ou qualquer passo depois do `attach`) lançar, o catch faz `rollback` e `discard($metadata)` antes da mensagem de erro.
- Produz: `ExamResultForm` constrói `EncounterDocumentService` com `new StoredObjectRepository($context, TTransaction::get())` (3 argumentos) e anexa sob o atendimento de origem do pedido de exame (`encounter_id` do `exam_request`). O `stored_object_key` do resultado continua gravado como hoje, e o anexo passa a aparecer na lista do `EncounterView` desse atendimento.
- Produz: `EncounterView::onDownloadDocument` envia `Content-Disposition: attachment; filename="<nome ASCII saneado>"; filename*=UTF-8''<rawurlencode(nome original)>`. O 404 responde o corpo em texto `_t('Attachment not found')`, que era a chave órfã.
- Consome: nada

**Teste RED**
- `src/tests/Unit/EncounterDocumentServiceTest.php` — com `FakeStorage` e `FakeStoredObjectRepository`: `attach` seguido de `discard($metadata)` deixa o `FakeStorage` sem o objeto; `discard` com um storage cujo `delete` lança não propaga exceção; falha antes da correção porque `discard` não existe (comando: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php`)

**Critério de aceite**
- SUITE: `PASS  Unit\EncounterDocumentServiceTest::` e `PASS  Integration\StoredObjectRepositoryIntegrationTest::` em todos os métodos, `Failed: 0`.
- GATE:
  - `ExamResultForm` de um pedido `R2 varredura` com um PDF faz `SELECT COUNT(*) FROM stored_object WHERE object_key LIKE '%/encounter/<id de origem>/%'` ficar +1, e o anexo aparece na lista do `EncounterView` desse atendimento;
  - baixar um anexo `laudo-ção.pdf` traz `content-disposition` com `filename*=UTF-8''laudo-%C3%A7%C3%A3o.pdf`;
  - `onDownloadDocument` de outro atendimento responde 404 com o texto "Anexo não encontrado";
  - console 0 `error`.

**Validação**
- LINT dos 3 PHP (evidência: 3 `No syntax errors detected`) + SUITE (evidência: as linhas `PASS` citadas, `Failed: 0`)
- GATE → os 3 fluxos do critério (evidência: `COUNT(*)` antes e depois, `browser_network_requests` com o header e o 404, snapshot da lista, console 0 `error`)
- Caminho do órfão (sem reprodução pela UI): o diff mostra `discard($metadata)` nos catches depois de `attach` nos dois controllers (evidência: trecho citado no relatório)

### T-57 — Onda 11: badge "Na fila" só para agendamento ativo e ordem do Fake da fila

**Camada:** frontend
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** simples
**Agente:** Aang

Pendências de T-41:
- o badge "Na fila" aparece para qualquer status, inclusive cancelado ou finalizado (`AgendaView.php:416-421`);
- o Fake devolve os ids na ordem das entradas, e o repositório ordena por `appointment_id` (`FakeQueueEntryRepository.php:75-89` × `QueueEntryRepository.php:204`).

**Arquivos prováveis**
- `src/app/control/clinic/AgendaView.php`
- `src/tests/Support/FakeQueueEntryRepository.php`
- `src/tests/Unit/QueueEntryServiceTest.php`

**Interface**
- Produz: o badge "Na fila" só aparece quando o agendamento está na fila **e** tem status `Appointment::STATUS_SCHEDULED`, `STATUS_CONFIRMED` ou `STATUS_IN_PROGRESS`; nos demais status, o bloco mostra só o badge de status, sem Check-in. O link Check-in segue a regra de T-29/T-41.
- Produz: `FakeQueueEntryRepository::listAppointmentIdsInQueue` devolve os ids em ordem crescente e sem repetição, como o repositório.
- Consome: nada

**Teste RED**
- `src/tests/Unit/QueueEntryServiceTest.php`, `src/tests/Support/FakeQueueEntryRepository.php` — com entradas dos agendamentos 9, 7 e 8 gravadas nessa ordem, `appointmentIdsInQueue([9, 7, 8])` devolve `[7, 8, 9]`; falha antes da correção porque o Fake devolve `[9, 7, 8]` (comando: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php`)

**Critério de aceite**
- SUITE: `PASS  Unit\QueueEntryServiceTest::` em todos os métodos, `Failed: 0`.
- GATE:
  - na Agenda, um agendamento `R2 varredura` na fila e `cancelado` não mostra "Na fila" (se não houver, o validador cancela um agendamento R2 que já esteja na fila pela UI de remarcação/status existente; se a UI não permitir, fica `[não rodado]` com o motivo e vale a leitura do diff);
  - um `agendado` na fila mostra "Na fila";
  - console 0 `error`.

**Validação**
- LINT de `AgendaView.php` (evidência: `No syntax errors detected`) + SUITE (evidência: `PASS  Unit\QueueEntryServiceTest::`, `Failed: 0`)
- GATE → os 2 casos do critério (evidência: snapshot dos blocos, `SELECT status FROM appointment WHERE id=<id>`, console 0 `error`)

### T-58 — Onda 11: foto nova apagada se o commit falhar e teste do error_log do discardPhoto

**Camada:** backend
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** simples
**Agente:** Tesla

Pendências de T-47:
- se `TTransaction::close()` lançar depois de um `attachPhoto` bem-sucedido, o objeto da chave nova fica órfão, porque nenhum catch de `onSave`/`saveExisting` chama `discardPhoto($patient->photoObjectKey)` (`PatientForm.php:358,449`, catches a partir de :376 e :471);
- `testDiscardPhotoSwallowsStorageFailure` não assere o `error_log` (`PatientServiceTest.php:444-455`).

**Arquivos prováveis**
- `src/app/control/clinic/PatientForm.php`
- `src/tests/Unit/PatientServiceTest.php`

**Interface**
- Produz: em `PatientForm::onSave`/`saveExisting`, quando houve `attachPhoto` na requisição e um passo posterior (inclusive `TTransaction::close()`) lança, o catch faz `rollback` e `discardPhoto(<chave nova>)` antes da mensagem, e a chave anterior não é apagada. O caminho de sucesso (`discardPreviousPhoto` depois do commit) não muda.
- Produz: `testDiscardPhotoSwallowsStorageFailure` aponta `error_log` para um arquivo temporário (`ini_set('error_log', <tmp>)`, restaurado no fim) e assere que ele contém a chave e a mensagem da falha.
- Consome: nada

**Teste RED**
- `src/tests/Unit/PatientServiceTest.php` — o teste reforçado, com a asserção do `error_log`, falha contra uma versão sabotada de `discardPhoto` sem `error_log` (sabotagem feita e desfeita numa worktree isolada em `/tmp/claude-1000/wt-T-58`, diff colado no relatório) e passa no HEAD (comando: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php`)

**Critério de aceite**
- SUITE: `PASS  Unit\PatientServiceTest::` em todos os métodos, `Failed: 0`.
- O diff de `PatientForm.php` mostra `discardPhoto(` nos catches posteriores ao `attachPhoto` de `onSave` e `saveExisting` (trecho citado no relatório).
- GATE: trocar a foto do paciente 2772 pelo caminho feliz segue com a foto nova, e a chave antiga some do bucket depois do commit; console 0 `error`.

**Validação**
- LINT de `PatientForm.php` (evidência: `No syntax errors detected`) + SUITE (evidência: `PASS  Unit\PatientServiceTest::`, `Failed: 0`)
- GATE → troca de foto (evidência: `SELECT photo_object_key` antes e depois, console 0 `error`)
- `git -C /var/www/html/centralvet worktree list` (evidência: sem `/tmp/claude-1000/wt-T-58` ao fim)

### T-59 — Onda 11: contador de save no FakeEncounterAccountRepository

**Camada:** backend
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** simples
**Agente:** Arquimedes

Sugestão de T-49: o decorador anônimo de `EncounterAccountRepositoryInterface` (cerca de 40 linhas, só para contar `save`) em `EncounterAccountServiceTest.php:263-298` poderia ser um contador no Fake compartilhado.

**Arquivos prováveis**
- `src/tests/Support/FakeEncounterAccountRepository.php`
- `src/tests/Unit/EncounterAccountServiceTest.php`

**Interface**
- Produz: `FakeEncounterAccountRepository::$saveCount` (`public int`, começa em 0 e soma 1 a cada `save`). O teste de negação por política usa o Fake e assere `saveCount === 0`, e o decorador anônimo sai.
- Consome: nada

**Teste RED**
- sem teste: refatoração de teste (troca do decorador pelo contador do Fake) sem mudança de comportamento; a prova é a SUITE verde com a asserção equivalente e o diff sem a classe anônima

**Critério de aceite**
- SUITE: `PASS  Unit\EncounterAccountServiceTest::` em todos os métodos, `Failed: 0`; `grep -c "new class" src/tests/Unit/EncounterAccountServiceTest.php` menor que na BASE.

**Validação**
- LINT dos 2 PHP (evidência: 2 `No syntax errors detected`) + SUITE (evidência: `PASS  Unit\EncounterAccountServiceTest::`, `Failed: 0`)

### T-60 — Onda 12: retorno do EncounterView pelo DateTimeInput e catches do ExamResultForm

**Camada:** frontend
**Dependências:** T-53, T-56
**Paralelizável:** não
**Complexidade:** simples
**Agente:** Yoda

Sugestão de T-45: `parseFollowUpScheduledAt` é privado e fica sem teste (`EncounterView.php:1585-1610`). A regra passa a ser a de `DateTimeInput::parse` (T-53), coberta por `DateTimeInputTest`. Fica na onda 12 porque consome T-53 e porque `EncounterView.php` é de T-56 na onda 11. Por ruling do orquestrador, entram também os 2 catches de `ExamResultForm` que ainda fazem `new TMessage('error', $e->getMessage())` (`ExamResultForm.php:230`, `:236`), deixados de fora por T-56. Nenhuma task da onda 12 edita esse arquivo.

**Arquivos prováveis**
- `src/app/control/clinic/EncounterView.php`
- `src/app/control/clinic/ExamResultForm.php`

**Interface**
- Produz: `onScheduleFollowUp` converte `followup_scheduled_at` por `DateTimeInput::parse`, e `InvalidArgumentException` vira `TMessage('error', CvFormat::userError($e))` ("Data e hora inválidas"). `parseFollowUpScheduledAt` sai. O campo ganha `setMask('dd/mm/yyyy hh:ii')` + `setDatabaseMask('yyyy-mm-dd hh:ii')`, como em T-53.
- Produz: os 2 catches de `ExamResultForm` (:230, :236) passam a `error_log(__METHOD__ . ': ' . $e->getMessage())` + `TMessage('error', CvFormat::userError($e))`, e nenhum `TMessage` de `ExamResultForm` recebe `$e->getMessage()` direto.
- Consome: T-53 `CentralVet\Presentation\DateTimeInput::parse(string $raw): DateTimeImmutable`

**Teste RED**
- sem teste: controller Adianti fora de `tests/run.php`; a regra está coberta pelo RED de T-53

**Critério de aceite**
- `grep -c "parseFollowUpScheduledAt" src/app/control/clinic/EncounterView.php` = 0; SUITE com `PASS  Integration\EncounterTimelineIntegrationTest::`.
- `grep -n "TMessage('error', \$e->getMessage())" src/app/control/clinic/ExamResultForm.php` vazio.
- GATE (atendimento `R2 varredura` em andamento):
  - retorno em "01/10/2026 15:00" faz `SELECT scheduled_at FROM appointment ORDER BY id DESC LIMIT 1` dar `2026-10-01 15:00:00`;
  - retorno "abc" (forçado) mostra "Data e hora inválidas";
  - console 0 `error`.

**Validação**
- LINT de `EncounterView.php` e `ExamResultForm.php` (evidência: 2 `No syntax errors detected`) + SUITE (evidência: `PASS  Integration\EncounterTimelineIntegrationTest::`, `Failed: 0`)
- GATE → os 2 fluxos do critério (evidência: `SELECT scheduled_at`, texto da mensagem, console 0 `error`); `ExamResultForm` de um pedido `R2 varredura` com arquivo inválido (extensão fora da lista, forçada por `browser_evaluate`) mostra a mensagem em pt, sem texto em inglês (evidência: snapshot, console 0 `error`)

### T-61 — Onda 12: RedisConnectionFactory recusa DB inválido e SELECT com falha

**Camada:** backend
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** simples
**Agente:** Naruto

Achado de produção de T-55 (`reports/T-55.md:52-54`, `:87`). Em `src/app/Core/Redis/RedisConnectionFactory.php:42-44`, `connect()` ignora o `false` de `select()`: com `db=99`, `getDbNum()` segue `0`. Com `$database <= 0`, o SELECT é pulado, e `db=-1` também cai no DB 0. Um `REDIS_DATABASE` fora de 0..15 na aplicação cai calado no DB 0, onde ficam as sessões. Ruling do orquestrador: corrigir em produção. Os arquivos não colidem com T-60.

**Arquivos prováveis**
- `src/app/Core/Redis/RedisConnectionFactory.php`
- `src/tests/Integration/RedisConnectionFactoryIntegrationTest.php`

**Interface**
- Produz: `RedisConnectionFactory::connect(string $host, int $port, int $database, ?string $password, float $timeout): \Redis` (assinatura mantida):
  - `$database` fora de `0..15` → `\RuntimeException("Unable to select Redis database {$database}")`, antes de qualquer comando depois do `connect`;
  - `$database > 0` e `select($database)` devolvendo `false` → a mesma exceção;
  - `$database === 0` segue sem `select`.
  `fromEnvironment()` herda a regra, e as mensagens de conexão e autenticação não mudam.
- Consome: nada

**Teste RED**
- `src/tests/Integration/RedisConnectionFactoryIntegrationTest.php` — estende `RedisIntegrationTestCase` (pula sem Redis): `connect(<host>, <port>, 99, <senha>, 1.0)` e `connect(..., -1, ...)` lançam `RuntimeException` com as mensagens exatas `Unable to select Redis database 99` e `Unable to select Redis database -1`; `connect(..., 15, ...)` devolve conexão com `getDbNum() === 15`. Falha antes da correção porque hoje as duas primeiras voltam conectadas no DB 0 (comando: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php`)

**Critério de aceite**
- SUITE: `PASS  Integration\RedisConnectionFactoryIntegrationTest::`, `PASS  Integration\RedisCacheIntegrationTest::`, `PASS  Integration\RedisQueueIntegrationTest::` e `PASS  Integration\SessionRedisIntegrationTest::` em todos os métodos, `Failed: 0`.
- A aplicação no ar com o `REDIS_DATABASE` atual (0) segue logando: depois do rebuild, o login admin em `http://127.0.0.1:8081` abre o painel.

**Validação**
- LINT de `RedisConnectionFactory.php` e do teste (evidência: 2 `No syntax errors detected`) + SUITE (evidência: as linhas `PASS` citadas, `Failed: 0`)
- `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php -r 'chdir("/var/www/html/src"); require "vendor/autoload.php"; try { CentralVet\Redis\RedisConnectionFactory::connect(getenv("REDIS_HOST"), (int) getenv("REDIS_PORT"), 99, getenv("REDIS_PASSWORD") ?: null, 1.0); echo "sem erro"; } catch (RuntimeException $e) { echo $e->getMessage(); }'` (evidência: `Unable to select Redis database 99`)
- GATE → login em `http://127.0.0.1:8081` depois do rebuild (evidência: painel aberto, console 0 `error`)
- Review Focus: `REDIS_DATABASE=99` falha alto, sem conexão silenciosa no DB 0 (evidência: a mensagem do `php -r`)

## Legenda

- [ ] pendente
- [~] em andamento
- [x] concluído com evidência
- [!] bloqueado
