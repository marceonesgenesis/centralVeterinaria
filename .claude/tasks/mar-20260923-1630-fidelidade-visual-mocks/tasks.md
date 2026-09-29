# Tasks

Convenções usadas nos blocos Validação (definidas em `notes.md § Decisões tomadas`):
- LINT = `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php -l <arquivo>` (rodar de `/var/www/html/centralvet`).
- SUITE = `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php`.
- GATE = orquestrador roda `docker compose build app worker && docker compose up -d app worker && docker compose restart nginx`; depois o validador usa o Playwright MCP em `http://127.0.0.1:8081` (sessão admin) e salva screenshots em `/var/www/html/centralvet/.playwright-mcp/f10-<Tela>.png`.
- VARREDURA = no gate de cada onda, depois dos passos GATE das tasks, o validador percorre as telas da onda listadas em `plan.md § Critérios gerais de aceite` (item "Varredura Playwright no gate"), exercitando abrir/listar/filtrar/salvar/voltar e lendo console (`error`) e rede (≥ 400); bug em tela da task reprova a task (fix loop).
- PATTERN0 = correção do gate da onda 2: `TEntry::setNumericMask(0, …)` do framework (`src/lib/adianti/widget/form/TEntry.php:148`, não editável) grava no campo um `pattern` com `\d{1,0}`, regex inválida que o navegador reporta no console. Em cada arquivo da task, todo `setNumericMask(0, …)` que permanecer é seguido, no mesmo campo, de `$campo->setProperty('pattern', '[0-9]*');`. Verificação por arquivo: `f=<arquivo>; echo "$(grep -c 'setNumericMask(0' $f) $(grep -cF "setProperty('pattern', '[0-9]*')" $f)"` imprime dois números iguais; no gate, `browser_console_messages` da tela não traz linha com `pattern`.
- COMMITS = branch `feat/fidelidade-visual-mocks` (base `main` @ `9efef4e`); todo commit da task lista só os caminhos dela e leva o trailer `Task: <ID>`; nas tasks com teste (T-04, T-05, T-06, T-17) o primeiro commit contém só os arquivos do bloco Teste RED (teste e fixtures) falhando, com trailer `Task: <ID> (RED)`. O relatório registra o hash em "Commit RED" (ou `sem teste: <motivo>`) e os hashes da implementação em `## Evidência`.

| ID | Camada | Descrição | Dependências | Paralelizável | Complexidade | Agente | Status |
|---|---|---|---|---|---|---|---|
| T-01 | frontend | Casca: layout.html, flags de navbar, estilos da casca, buildid | — | sim | alta | Athena | [x] |
| T-02 | frontend | Kit de componentes Cv* + cv-components.css | — | sim | alta | Platão | [x] |
| T-03 | frontend | Vocabulário novo em translations.json | — | sim | simples | Platão | [x] |
| T-04 | backend | Leitura de indicadores de estoque e vendas | — | sim | média | Arquimedes | [x] |
| T-05 | backend | Leitura de indicadores financeiros | — | sim | média | Arquimedes | [x] |
| T-06 | backend | Leitura de resumo clínico (paciente, último atendimento, prescrições, itens do atendimento) | — | sim | média | Sherlock | [x] |
| T-07 | database | DML de registro de FinancialOverview e CvShellController (aprovação na hora) | — | sim | simples | Jaspion | [x] |
| T-08 | frontend | CvShellController + cv-shell.js (papel do usuário, seletor de unidade, busca global) | T-01, T-02, T-07 | sim | média | Aang | [x] |
| T-09 | frontend | Tela nova FinancialOverview (Financeiro — Visão geral) | T-02, T-03, T-05, T-07 | sim | alta | Tesla | [x] |
| T-10 | frontend | ProductList → Estoque e Vendas | T-02, T-03, T-04 | sim | alta | Darwin | [x] |
| T-11 | frontend | ServiceList com painel de detalhe | T-02, T-03 | sim | média | Levi | [x] |
| T-12 | frontend | PrescriptionForm em 2 colunas | T-02, T-03, T-06 | sim | alta | Kratos | [x] |
| T-13 | frontend | EncounterView com cabeçalho do paciente, wizard e plano clínico | T-02, T-03, T-06 | sim | alta | Yoda | [x] |
| T-14 | frontend | Lote Recepção/cadastros no padrão | T-02 | sim | média | Thanos | [x] |
| T-15 | frontend | Lote Clínico no padrão (+ PATTERN0) | T-02, T-13 | sim | média | Yoda | [x] |
| T-16 | frontend | Lote Catálogos no padrão (+ PATTERN0) | T-02 | sim | média | Levi | [x] |
| T-17 | frontend/backend | Lote Estoque/vendas (formulários e PDV) no padrão + correções de serviço da onda 2 (update/tenant em ServiceForm, filtro Inativo, PATTERN0) | T-02, T-10, T-11 | sim | alta | Darwin | [x] |
| T-18 | frontend | Lote Financeiro no padrão, com abas financeiras (+ aba Receitas com `entry_type=income`, PATTERN0) | T-02, T-09 | sim | média | Tesla | [x] |
| T-19 | frontend | menu.xml reorganizado + sidebar final (desabilitados, rodapé fixo) + unidades por tenant no CvShellController e CSS do seletor | T-01, T-07, T-08, T-09 | sim | média | Athena | [x] |
| T-20 | frontend | Consolidação de chaves i18n pedidas no board | T-03, T-08, T-09, T-10, T-11, T-12, T-13, T-14, T-15, T-16, T-17, T-18, T-19 | não | simples | Platão | [ ] |
| T-21 | qa | Validação visual lado a lado com os mocks + regressão + varredura Playwright de todas as telas do menu | T-20 | não | média | Spock | [ ] |

## Detalhamento

### T-01 — Casca: layout.html, flags de navbar, estilos da casca, buildid

**Camada:** frontend
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** alta
**Agente:** Athena

**Arquivos prováveis**
- `src/app/templates/adminbs5/layout.html`
- `src/app/templates/adminbs5/custom.css`
- `src/app/config/application.php`
- `docker/php/Dockerfile`

**Interface**
- Produz: `layout.html` com `<span id="cv-user-role">` no cartão do usuário (nome `{username}` + papel preenchido por JS), `<form id="cv-global-search">` na topbar com input `name="query"` (placeholder "Buscar paciente, tutor, atendimento..."), links `app/templates/{template}/cv-components.css?appver={buildid}`, `app/templates/{template}/js/cv-shell.js?appver={buildid}` e `lib/independent/js/chart.umd.min.js`; rodapé `CENTRAL VET PRO v1.0.0 | Tecnologia que cuida de vidas 🐾 | Clínica. Gestão. Resultado.`; `application.php` com `'has_master_menu' => '0'`; Dockerfile gera `/var/www/html/src/buildid`
- Consome: nada

**Teste RED**
- sem teste: casca HTML/CSS e configuração do template, sem comportamento coberto pela suíte PHP; verificação visual no gate

**Critério de aceite**
- Página `index.php?class=ServiceList` renderizada mostra sidebar única escura (sem trilho de ícones à esquerda), logo pata + "CENTRAL VET PRO", topbar com hambúrguer, campo de busca, sino, ícone de ajuda e cartão com o nome do usuário; nenhum texto "Shortcut"; rodapé com "CENTRAL VET PRO v1.0.0" e "Clínica. Gestão. Resultado." e nenhum dos textos Trace, DB, PHP, Framework, Sistema, Notícias, Manuais, Abrir chamado, "Unit A".
- `curl -s http://127.0.0.1:8081/index.php` (sessão) ou o HTML da página contém `custom.css?appver=` seguido de um valor diferente do literal `{buildid}`.

**Validação**
- LINT `app/config/application.php` (evidência: `No syntax errors detected`)
- GATE → `browser_evaluate` com `document.querySelector('link[href*="custom.css"]').href` (evidência: não contém `%7Bbuildid%7D` nem `{buildid}`)
- GATE → screenshot `f10-Shell.png` de `ServiceList` + `browser_evaluate` com `document.body.innerText.includes('Shortcut')` (evidência: `false`) e `document.querySelector('footer').innerText` (evidência: contém `Tecnologia que cuida de vidas` e não contém `Abrir chamado`)

### T-02 — Kit de componentes Cv* + cv-components.css

**Camada:** frontend
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** alta
**Agente:** Platão

**Arquivos prováveis**
- `src/app/lib/widget/CvPage.php`
- `src/app/lib/widget/CvNav.php`
- `src/app/lib/widget/CvBadge.php`
- `src/app/lib/widget/CvKpiCard.php`
- `src/app/lib/widget/CvCard.php`
- `src/app/lib/widget/CvDatagrid.php`
- `src/app/lib/widget/CvForm.php`
- `src/app/lib/widget/CvAvatar.php`
- `src/app/lib/widget/CvWizard.php`
- `src/app/lib/widget/CvFormat.php`
- `src/app/templates/adminbs5/cv-components.css`

**Interface**
- Produz: `CvPage::header(string $title, ?string $subtitle = null, array $actions = [], bool $unitSwitch = true): TElement` (título grande, subtítulo, ações à direita e, com `$unitSwitch`, o slot `<div class="cv-unit-switch" data-cv-unit-switch></div>`; voltar = ação com ícone `fa:arrow-left`)
- Produz: `CvPage::columns(mixed $main, mixed $side): TElement` (grid 8/4, empilha abaixo de 992px)
- Produz: `CvPage::tabs(array $tabs, string $active): TElement` (`$tabs` = `['chave' => ['label' => string, 'href' => ?string]]`; `href` null → aba desabilitada com classe `cv-tab--disabled` e título "Em breve")
- Produz: `CvPage::filterBar(array $elements): TElement` (busca + selects em linha, substitui a cortina de filtros do right panel)
- Produz: `CvNav::tabs(string $group, string $active): TElement` com grupos `finance` (overview → `index.php?class=FinancialOverview`, revenues → `index.php?class=FinancialEntryList&entry_type=revenue`, expenses → `index.php?class=FinancialEntryList&entry_type=expense`, payables → `PayableList`, receivables → `PendingReceivableList`, cashflow → `CashSessionList`), `stock` (products → `ProductList`, sales → `SaleForm`, movements/categories/suppliers/reports desabilitadas), `services` (services → `ServiceList`, categories/packages/pricing desabilitadas), `prescription` (new, history, templates desabilitada)
- Produz: `CvBadge::create(string $label, string $tone): TElement` com tons `success|warning|danger|info|neutral`
- Produz: `CvKpiCard::create(string $icon, string $tone, string $value, string $label, ?float $deltaPercent = null): TElement` (variação null → linha "vs. mês anterior" omitida)
- Produz: `CvCard::create(string $title, mixed $content, ?string $linkLabel = null, ?string $linkHref = null): TElement`
- Produz: `CvDatagrid::decorate(BootstrapDatagridWrapper $datagrid, bool $checkbox = true): void` (classe `cv-table`, coluna de checkbox, cabeçalho claro)
- Produz: `CvDatagrid::actionMenu(array $actions): TDataGridActionGroup` (botão "…" com dropdown)
- Produz: `CvDatagrid::footer(TPageNavigation $pageNavigation, int $from, int $to, int $total, string $noun): TElement` ("Mostrando X–Y de N <noun>" + paginação `cv-pager` sem o azul `#0d6efd`)
- Produz: `CvForm::decorate(BootstrapFormBuilder $form, int $columns = 2): void` (página cheia, rótulo acima do campo, grid de colunas, ações no rodapé do card)
- Produz: `CvAvatar::placeholder(string $name, ?string $species = null): TElement` (inicial do nome ou ícone de espécie `fa:dog`/`fa:cat`/`fa:paw`)
- Produz: `CvWizard::steps(array $labels, int $active, string $targetPrefix): TElement` (etapas numeradas; clique mostra `#<targetPrefix><n>` e oculta as demais, só no cliente)
- Produz: `CvFormat::money(int $cents): string` (`R$ 1.234,56`) e `CvFormat::delta(int $current, int $previous): ?float` (null quando `$previous` = 0)
- Consome: nada

**Teste RED**
- sem teste: helpers de apresentação Adianti em `app/lib` ficam fora do autoload PSR-4 do runner (`CentralVet\` → `app/Core`); cobertos por lint e pela verificação visual das telas que os usam

**Critério de aceite**
- `php -l` de cada um dos 10 arquivos PHP imprime `No syntax errors detected`.
- `CvFormat::money(123456)` retorna `R$ 1.234,56` e `CvFormat::delta(100, 0)` retorna `null` quando executados via `php -r` no container com o autoload do Adianti.
- `cv-components.css` não contém `#0d6efd` e define as classes `.cv-kpi`, `.cv-badge--success`, `.cv-badge--warning`, `.cv-badge--danger`, `.cv-tabs`, `.cv-table`, `.cv-pager`, `.cv-form`, `.cv-wizard`, `.cv-avatar`, `.cv-unit-switch`, `.cv-tab--disabled`.

**Validação**
- LINT de cada arquivo `src/app/lib/widget/Cv*.php` (evidência: 10 linhas `No syntax errors detected`)
- `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php -r 'require "init.php"; echo CvFormat::money(123456), "|", var_export(CvFormat::delta(100,0), true);'` (evidência: `R$ 1.234,56|NULL`)
- `grep -c "#0d6efd" src/app/templates/adminbs5/cv-components.css` (evidência: `0`) e `grep -oE "\.cv-(kpi|badge--success|badge--warning|badge--danger|tabs|table|pager|form|wizard|avatar|unit-switch|tab--disabled)\b" src/app/templates/adminbs5/cv-components.css | sort -u | wc -l` (evidência: `12`)

### T-03 — Vocabulário novo em translations.json

**Camada:** frontend
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** simples
**Agente:** Platão

**Arquivos prováveis**
- `src/app/config/translations.json`

**Interface**
- Produz: pares `{"en","pt"}` novos para os textos dos mocks e do kit, incluindo no mínimo as chaves `Coming soon`, `Showing %1–%2 of %3`, `Overview`, `Revenues`, `Expenses`, `Cash flow`, `Revenue x Expenses`, `Revenue by category`, `Recent entries`, `Products in stock`, `Low stock products`, `Sales this month`, `Items sold`, `vs. previous month`, `Recent sales`, `Normal`, `Low stock`, `Out of stock`, `New prescription`, `Prescription history`, `Templates`, `Last encounter`, `Add another medication`, `Additional instructions`, `Preview`, `In progress`, `Anamnesis`, `Physical exam`, `Diagnosis`, `Clinical plan`, `Finish`, `Vital signs`, `Financial summary`, `Encounter total`, `Open cash balance`, `Settings`, `Help`, `Open from the encounter`
- Consome: nada

**Teste RED**
- sem teste: arquivo de dados de tradução; verificação por parse JSON e contagem

**Critério de aceite**
- `translations.json` continua JSON válido, sem chave `en` duplicada, e cada uma das 38 chaves listadas em Produz existe com `pt` não vazio.

**Validação**
- `python3 -c "import json;d=json.load(open('/var/www/html/centralvet/src/app/config/translations.json'));en=[x['en'] for x in d];print(len(en)-len(set(en)), len(d))"` (evidência: primeiro número `0`; segundo ≥ 625)
- `python3 -c "import json;d={x['en']:x['pt'] for x in json.load(open('/var/www/html/centralvet/src/app/config/translations.json'))};ks=['Coming soon','Overview','Low stock','Out of stock','Open cash balance','Open from the encounter','Encounter total','Vital signs'];print([k for k in ks if not d.get(k)])"` (evidência: `[]`)

### T-04 — Leitura de indicadores de estoque e vendas

**Camada:** backend
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Arquimedes

**Arquivos prováveis**
- `src/app/Core/Persistence/StockSalesOverviewReader.php`
- `src/app/Core/Application/StockSalesOverviewService.php`
- `src/tests/Integration/StockSalesOverviewIntegrationTest.php`

**Interface**
- Produz: `new StockSalesOverviewService(new StockSalesOverviewReader(TenantContext $context, PDO $connection))` (namespaces `CentralVet\Application` / `CentralVet\Persistence`; reader estende `AbstractTenantRepository`, todo SQL escopado por `tenantQuery()`)
- Produz: `StockSalesOverviewService::summary(DateTimeImmutable $month): array` → `['products_in_stock' => int, 'low_stock' => int, 'out_of_stock' => int, 'sales_month_cents' => int, 'sales_prev_month_cents' => int, 'items_sold_month' => int, 'items_sold_prev_month' => int]`
- Produz: `StockSalesOverviewService::products(?string $search = null, ?string $category = null, ?string $status = null): array` → lista de `['id' => int, 'name' => string, 'category' => string, 'unit' => string, 'stock_quantity' => float, 'minimum_stock_quantity' => float, 'status' => 'normal'|'low'|'out']` (out: estoque ≤ 0; low: 0 < estoque ≤ mínimo; estoque = soma de `stock_batch.quantity` do produto)
- Produz: `StockSalesOverviewService::recentSales(int $limit = 5): array` → `['id' => int, 'sold_at' => string, 'total_cents' => int, 'items_label' => string, 'patient_name' => ?string]`
- Produz: `StockSalesOverviewService::lowStock(int $limit = 5): array` → mesmo formato de `products()` só com status `low`/`out`, ordenado por estoque crescente
- Produz: `StockSalesOverviewService::categories(): array` → `list<string>` distintas e ordenadas
- Consome: nada

**Teste RED**
- `src/tests/Integration/StockSalesOverviewIntegrationTest.php` — com produtos/lotes/vendas inseridos na transação do teste para dois tenants, `summary()`/`products()`/`lowStock()`/`recentSales()` do tenant A devolvem só os dados de A, status `low`/`out` pela regra acima e `sales_prev_month_cents` = 0 quando o mês anterior não tem venda; falha antes da implementação porque a classe `StockSalesOverviewService` não existe (comando: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php`)

**Critério de aceite**
- A suíte imprime `PASS  Integration\StockSalesOverviewIntegrationTest::` para cada método de teste, `Failed: 0` e `Total` = total anterior + métodos novos; `Skipped` não aumenta.
- Produto do tenant B nunca aparece em `products()` do tenant A (asserção no teste).

**Validação**
- SUITE (evidência: linhas `PASS  Integration\StockSalesOverviewIntegrationTest::…` e `Failed: 0`; a mesma execução no commit RED mostrou `FAIL  Integration\StockSalesOverviewIntegrationTest`, colada em `## RED` com o hash do commit `Task: T-04 (RED)`)
- COMMITS → `git -C /var/www/html/centralvet log --reverse --format='%h %s | %b' 9efef4e..HEAD -- src/tests/Integration/StockSalesOverviewIntegrationTest.php src/app/Core/Persistence/StockSalesOverviewReader.php src/app/Core/Application/StockSalesOverviewService.php` (evidência: o primeiro commit com `T-04` tem trailer `Task: T-04 (RED)` e `git show --stat` dele lista só `src/tests/Integration/StockSalesOverviewIntegrationTest.php`; os commits seguintes de `T-04` têm `Task: T-04`)
- Review Focus: o teste cobre mês anterior sem venda → `sales_prev_month_cents` = 0 e tenant sem produto → `products_in_stock` = 0 (evidência: nome do método `testPreviousMonthWithoutSalesYieldsZero` com `PASS`)
- LINT dos 3 arquivos (evidência: `No syntax errors detected`)

### T-05 — Leitura de indicadores financeiros

**Camada:** backend
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Arquimedes

**Arquivos prováveis**
- `src/app/Core/Persistence/FinancialOverviewReader.php`
- `src/app/Core/Application/FinancialOverviewService.php`
- `src/tests/Integration/FinancialOverviewIntegrationTest.php`

**Interface**
- Produz: `new FinancialOverviewService(new FinancialOverviewReader(TenantContext $context, PDO $connection))` (mesmo padrão de T-04; faixa de datas via SQL após `whereSql()`, como `FinancialEntryRepository::listBySystemUnitAndPeriod`)
- Produz: `FinancialOverviewService::totals(int $systemUnitId, DateTimeImmutable $from, DateTimeImmutable $to): array` → `['revenue_cents' => int, 'expense_cents' => int, 'result_cents' => int, 'prev_revenue_cents' => int, 'prev_expense_cents' => int, 'prev_result_cents' => int]` (período anterior = mesmo número de dias imediatamente antes de `$from`)
- Produz: `FinancialOverviewService::dailySeries(int $systemUnitId, DateTimeImmutable $from, DateTimeImmutable $to): array` → um item por dia do período, dias sem lançamento com zero: `['date' => 'Y-m-d', 'revenue_cents' => int, 'expense_cents' => int]`
- Produz: `FinancialOverviewService::revenueByCategory(int $systemUnitId, DateTimeImmutable $from, DateTimeImmutable $to): array` → `['category' => string, 'amount_cents' => int, 'share' => float]` ordenado por valor decrescente, `share` somando 1.0 (ou lista vazia)
- Produz: `FinancialOverviewService::recentEntries(int $systemUnitId, int $limit = 5): array` → `['id' => int, 'occurred_at' => string, 'entry_type' => string, 'category' => string, 'reference' => ?string, 'amount_cents' => int]`
- Produz: `FinancialOverviewService::openCashBalanceCents(int $systemUnitId): ?int` (saldo da sessão de caixa aberta = abertura + entradas − saídas registradas nela; null sem sessão aberta)
- Consome: nada

**Teste RED**
- `src/tests/Integration/FinancialOverviewIntegrationTest.php` — com lançamentos de receita/despesa de duas unidades e dois tenants no mês corrente e no anterior, `totals()` soma só a unidade/tenant pedidos, `dailySeries()` devolve um item por dia com zeros, `revenueByCategory()` soma `share` = 1.0 e `openCashBalanceCents()` é null sem sessão aberta; falha antes da implementação porque `FinancialOverviewService` não existe (comando: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php`)

**Critério de aceite**
- A suíte imprime `PASS  Integration\FinancialOverviewIntegrationTest::` para cada método, `Failed: 0`, e o teste de série de 30 dias verifica `count(dailySeries) === 30`.
- Lançamento de outro tenant ou outra unidade não altera `revenue_cents` (asserção no teste).

**Validação**
- SUITE (evidência: linhas `PASS  Integration\FinancialOverviewIntegrationTest::…`, `Failed: 0`; falha RED colada em `## RED` com o hash do commit `Task: T-05 (RED)`)
- COMMITS → `git -C /var/www/html/centralvet log --reverse --format='%h %s | %b' 9efef4e..HEAD -- src/tests/Integration/FinancialOverviewIntegrationTest.php src/app/Core/Persistence/FinancialOverviewReader.php src/app/Core/Application/FinancialOverviewService.php` (evidência: o primeiro commit com `T-05` tem trailer `Task: T-05 (RED)` e `git show --stat` dele lista só `src/tests/Integration/FinancialOverviewIntegrationTest.php`; os commits seguintes de `T-05` têm `Task: T-05`)
- LINT dos 3 arquivos (evidência: `No syntax errors detected`)

### T-06 — Leitura de resumo clínico

**Camada:** backend
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Sherlock

**Arquivos prováveis**
- `src/app/Core/Persistence/ClinicalSummaryReader.php`
- `src/app/Core/Application/ClinicalSummaryService.php`
- `src/tests/Integration/ClinicalSummaryIntegrationTest.php`

**Interface**
- Produz: `new ClinicalSummaryService(new ClinicalSummaryReader(TenantContext $context, PDO $connection))`
- Produz: `ClinicalSummaryService::patientCard(int $patientId): ?array` → `['patient_id' => int, 'name' => string, 'species' => string, 'breed' => ?string, 'sex' => ?string, 'birth_date' => ?string, 'age_label' => ?string, 'weight_kg' => ?float, 'tutor_id' => int, 'tutor_name' => string, 'tutor_phone' => ?string, 'tutor_email' => ?string]` (`age_label` "5 anos"/"8 meses"; null sem data)
- Produz: `ClinicalSummaryService::lastEncounter(int $patientId, ?int $excludeEncounterId = null): ?array` → `['id' => int, 'started_at' => string, 'status' => string, 'professional_system_user_id' => ?int, 'anamnesis_excerpt' => ?string, 'diagnosis_excerpt' => ?string]` (trechos com até 80 caracteres)
- Produz: `ClinicalSummaryService::prescriptionHistory(int $patientId, int $limit = 5): array` → `['id' => int, 'created_at' => string, 'status' => string, 'first_medication' => ?string, 'items_count' => int, 'professional_system_user_id' => ?int]` mais recente primeiro
- Produz: `ClinicalSummaryService::encounterPlanItems(int $encounterId): array` → `['prescriptions' => list, 'exams' => list, 'procedures' => list, 'vaccines' => list]`, cada item `['id' => int, 'title' => string, 'detail' => ?string, 'created_at' => string]`
- Consome: nada

**Teste RED**
- `src/tests/Integration/ClinicalSummaryIntegrationTest.php` — com tutor, paciente, dois atendimentos, prescrição com itens, solicitação de exame e vacinação inseridos na transação, `patientCard()` traz tutor e `age_label`, `lastEncounter($p, $atual)` devolve o atendimento anterior, `prescriptionHistory()` ordena do mais recente e `encounterPlanItems()` agrupa por tipo; paciente de outro tenant devolve null; falha antes da implementação porque `ClinicalSummaryService` não existe (comando: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php`)

**Critério de aceite**
- A suíte imprime `PASS  Integration\ClinicalSummaryIntegrationTest::` para cada método e `Failed: 0`; `patientCard()` de paciente de outro tenant retorna `null` (asserção).

**Validação**
- SUITE (evidência: `PASS  Integration\ClinicalSummaryIntegrationTest::…`, `Failed: 0`; falha RED colada em `## RED` com o hash do commit `Task: T-06 (RED)`)
- COMMITS → `git -C /var/www/html/centralvet log --reverse --format='%h %s | %b' 9efef4e..HEAD -- src/tests/Integration/ClinicalSummaryIntegrationTest.php src/app/Core/Persistence/ClinicalSummaryReader.php src/app/Core/Application/ClinicalSummaryService.php` (evidência: o primeiro commit com `T-06` tem trailer `Task: T-06 (RED)` e `git show --stat` dele lista só `src/tests/Integration/ClinicalSummaryIntegrationTest.php`; os commits seguintes de `T-06` têm `Task: T-06`)
- LINT dos 3 arquivos (evidência: `No syntax errors detected`)

### T-07 — DML de registro de FinancialOverview e CvShellController

**Camada:** database
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** simples
**Agente:** Jaspion

**Arquivos prováveis**
- `.claude/tasks/mar-20260923-1630-fidelidade-visual-mocks/sql/T-07-register-programs.sql`

**Interface**
- Produz: programas `FinancialOverview` (nome "Visão geral financeira") e `CvShellController` (nome "Contexto da casca") em `system_program` com ids `MAX(id)+1` e `MAX(id)+2` (104/105 se o máximo ainda for 103), e `system_group_program` ligando `FinancialOverview` aos grupos que hoje têm `FinancialEntryList` e `CvShellController` a todos os grupos que têm algum programa clínico
- Consome: nada

**Teste RED**
- sem teste: DML de registro de permissão; verificado por SELECT após a execução aprovada

**Critério de aceite**
- Antes: `SELECT MAX(id) FROM system_program` e a lista de grupos de `FinancialEntryList` registrados no relatório; o arquivo SQL contém só `INSERT` explícitos (sem `DELETE`/`UPDATE`) e o agente para com `Status: bloqueado` aguardando aprovação.
- Depois da aprovação do usuário e execução pelo orquestrador: `SELECT id, controller FROM system_program WHERE controller IN ('FinancialOverview','CvShellController')` devolve 2 linhas e `SELECT COUNT(*) FROM system_group_program WHERE system_program_id IN (<ids>)` devolve o número de linhas do arquivo SQL; `SELECT COUNT(*) FROM system_program` = contagem anterior + 2 (nenhum registro existente perdido).

**Validação**
- `docker compose exec -T mysql sh -c 'mysql -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE" -e "SELECT id, controller FROM system_program WHERE controller IN (\"FinancialOverview\",\"CvShellController\"); SELECT COUNT(*) FROM system_program;"'` (evidência: 2 linhas com os ids do SQL e contagem = anterior + 2; as tabelas de permissão ficam no mesmo banco da aplicação — `app/config/permission.php` devolve `database.php`; só SELECT)

### T-08 — CvShellController + cv-shell.js

**Camada:** frontend
**Dependências:** T-01, T-02, T-07
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Aang

**Arquivos prováveis**
- `src/app/control/clinic/CvShellController.php`
- `src/app/templates/adminbs5/js/cv-shell.js`

**Interface**
- Produz: `CvShellController::onContext` (via `engine.php?class=CvShellController&method=onContext&static=1`) → JSON `{"user":{"name":string,"role":string},"units":[{"id":int,"name":string,"current":bool}]}` (`role` = nome do primeiro grupo do usuário; `units` = `getSystemUserUnitIds()`)
- Produz: `CvShellController::onSwitchUnit` (parâmetro `unit_id`) → chama `ApplicationAuthenticationService::setUnit()`; sucesso recarrega a página atual; unidade não vinculada → `TMessage('error', ...)` e sessão inalterada
- Produz: `cv-shell.js` com `CvShell.init()` que preenche `#cv-user-role`, monta um select em cada `[data-cv-unit-switch]` e envia `#cv-global-search` para `index.php?class=GlobalSearchController&method=onSearch&query=<termo>` via `__adianti_load_page`
- Consome: T-01 `id="cv-user-role"`, T-01 `cv-global-search`, T-01 `js/cv-shell.js`, T-02 `data-cv-unit-switch`, T-07 `CvShellController`

**Teste RED**
- sem teste: controller Adianti e JS de template; a suíte não instancia controllers (nenhum teste existente cobre `app/control`); verificação HTTP/Playwright no gate

**Critério de aceite**
- `engine.php?class=CvShellController&method=onContext&static=1` com a sessão admin responde JSON com `user.name` = nome do admin e `units` não vazio, com exatamente um `current: true`.
- Na topbar, `#cv-user-role` mostra o nome do grupo; no cabeçalho de `ServiceList` aparece o select de unidade com a unidade atual selecionada; digitar "Rex" na busca global e Enter abre `GlobalSearchController` com resultados de paciente.
- `onSwitchUnit` com `unit_id` não vinculado exibe a mensagem de acesso não autorizado e `onContext` seguinte mantém o mesmo `current`.

**Validação**
- LINT `app/control/clinic/CvShellController.php` (evidência: `No syntax errors detected`)
- GATE → `browser_evaluate` com `fetch('engine.php?class=CvShellController&method=onContext&static=1').then(r=>r.json())` (evidência: objeto com `user.role` preenchido e um único `current: true`)
- GATE → Review Focus: `browser_navigate` para `engine.php?class=CvShellController&method=onSwitchUnit&unit_id=999999` e novo `onContext` (evidência: mensagem "Unauthorized access to that unit"/pt equivalente e `current` inalterado)
- GATE → screenshot `f10-Shell-unit.png` com o select de unidade e o papel no cartão

### T-09 — Tela nova FinancialOverview

**Camada:** frontend
**Dependências:** T-02, T-03, T-05, T-07
**Paralelizável:** sim
**Complexidade:** alta
**Agente:** Tesla

**Arquivos prováveis**
- `src/app/control/clinic/FinancialOverview.php`

**Interface**
- Produz: `class FinancialOverview extends TPage` (parâmetros opcionais `from`/`to` `Y-m-d`, padrão mês corrente; unidade = `userunitid` da sessão)
- Consome: T-02 `CvPage::header`, T-02 `CvNav::tabs`, T-02 `CvKpiCard::create`, T-02 `CvCard::create`, T-02 `CvBadge::create`, T-02 `CvFormat::money`, T-02 `CvFormat::delta`, T-05 `FinancialOverviewService::totals`, T-05 `FinancialOverviewService::dailySeries`, T-05 `FinancialOverviewService::revenueByCategory`, T-05 `FinancialOverviewService::recentEntries`, T-05 `FinancialOverviewService::openCashBalanceCents`, T-07 `FinancialOverview`

**Teste RED**
- sem teste: tela Adianti de apresentação sobre `FinancialOverviewService`, cujo cálculo T-05 já testa; verificação visual no gate

**Critério de aceite**
- `index.php?class=FinancialOverview` mostra título "Financeiro" + subtítulo, abas do grupo `finance` com "Visão geral" ativa, filtro de período, 4 cards (Receitas, Despesas, Resultado com variação vs período anterior quando o anterior ≠ 0; "Saldo do caixa aberto" ou "Nenhum caixa aberto"), gráfico de linha Receitas x Despesas (canvas Chart.js com 2 datasets) e donut Receitas por categoria, e tabela "Lançamentos recentes" com Data, Descrição, Categoria, Tipo (badge Receita verde / Despesa vermelha) e Valor.
- O valor do card Receitas é igual à soma das receitas do período em `FinancialEntryList` para o mesmo período (conferência manual no gate).
- Nenhum botão Exportar e nenhuma coluna Forma de pagamento/Status (pendência registrada).

**Validação**
- LINT `app/control/clinic/FinancialOverview.php` (evidência: `No syntax errors detected`)
- GATE → screenshot `f10-FinancialOverview.png` lado a lado com a metade esquerda de `images/3.png` + `browser_evaluate` com `document.querySelectorAll('canvas').length` (evidência: `2`)
- GATE → comparar o card Receitas com o total de receitas de `FinancialEntryList` de `01/09/2026` a `30/09/2026` (evidência: mesmo valor em R$)

### T-10 — ProductList → Estoque e Vendas

**Camada:** frontend
**Dependências:** T-02, T-03, T-04
**Paralelizável:** sim
**Complexidade:** alta
**Agente:** Darwin

**Arquivos prováveis**
- `src/app/control/clinic/ProductList.php`

**Interface**
- Produz: `ProductList` com parâmetros de filtro `search`, `category`, `status` (`normal`|`low`|`out`) na querystring, ação de linha "…" com Editar (`index.php?class=ProductForm&method=onEdit&id=<id>`) e Entrada de lote (`index.php?class=StockBatchForm&product_id=<id>`), botão "Novo produto" → `index.php?class=ProductForm`
- Consome: T-02 `CvPage::header`, T-02 `CvPage::columns`, T-02 `CvPage::filterBar`, T-02 `CvNav::tabs`, T-02 `CvKpiCard::create`, T-02 `CvCard::create`, T-02 `CvBadge::create`, T-02 `CvDatagrid::decorate`, T-02 `CvDatagrid::actionMenu`, T-02 `CvDatagrid::footer`, T-02 `CvAvatar::placeholder`, T-02 `CvFormat::money`, T-02 `CvFormat::delta`, T-04 `StockSalesOverviewService::summary`, T-04 `StockSalesOverviewService::products`, T-04 `StockSalesOverviewService::recentSales`, T-04 `StockSalesOverviewService::lowStock`, T-04 `StockSalesOverviewService::categories`

**Teste RED**
- sem teste: tela Adianti de apresentação sobre `StockSalesOverviewService` (testado em T-04); verificação visual no gate

**Critério de aceite**
- `index.php?class=ProductList` mostra título "Estoque e Vendas", 4 cards (Produtos em estoque, Produtos com estoque baixo, Vendas no mês com variação quando o mês anterior ≠ 0, Itens vendidos), abas do grupo `stock` com Produtos ativo e Movimentações/Categorias/Fornecedores/Relatórios desabilitadas, barra com busca + Categoria + Status, tabela com checkbox, Produto, Categoria, Estoque atual, Estoque mínimo, Status (badge Normal verde / Estoque baixo amarelo / Sem estoque vermelho) e "…", rodapé "Mostrando X–Y de N produtos", coluna direita "Vendas recentes" e "Produtos com estoque baixo".
- Filtro Status = Sem estoque deixa só linhas com badge "Sem estoque"; nenhuma coluna de preço de venda ou código.

**Validação**
- LINT `app/control/clinic/ProductList.php` (evidência: `No syntax errors detected`)
- GATE → screenshot `f10-ProductList.png` lado a lado com `images/2.png`
- GATE → `browser_navigate` `index.php?class=ProductList&status=out` + `browser_evaluate` contando badges diferentes de "Sem estoque" na tabela (evidência: `0`)

### T-11 — ServiceList com painel de detalhe

**Camada:** frontend
**Dependências:** T-02, T-03
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Levi

**Arquivos prováveis**
- `src/app/control/clinic/ServiceList.php`

**Interface**
- Produz: `ServiceList` com parâmetro `service_id` (serviço selecionado no painel direito; padrão = primeiro da página), filtros `search`, `category`, `status`; "Novo serviço" → `index.php?class=ServiceForm`; Editar → `index.php?class=ServiceForm&method=onEdit&id=<id>`
- Consome: T-02 `CvPage::header`, T-02 `CvPage::columns`, T-02 `CvPage::tabs`, T-02 `CvPage::filterBar`, T-02 `CvNav::tabs`, T-02 `CvBadge::create`, T-02 `CvDatagrid::decorate`, T-02 `CvDatagrid::actionMenu`, T-02 `CvDatagrid::footer`, T-02 `CvFormat::money`

**Teste RED**
- sem teste: tela Adianti de apresentação sobre `ServiceCatalogService` existente; verificação visual no gate

**Critério de aceite**
- `index.php?class=ServiceList` mostra título "Serviços", abas do grupo `services` (Categorias/Pacotes/Preçários desabilitadas), tabela com checkbox, ícone + nome, Categoria em badge, Preço padrão, Duração ("30 min"/"1h 30min"), Status em badge (Ativo verde/Inativo cinza) e "…", rodapé "Mostrando X–Y de N serviços"; painel direito com nome do serviço, abas Dados (ativa) / Preços / Vinculações / Histórico (desabilitadas), campos Categoria, Preço padrão, Duração estimada, Status e botão Editar.
- Clicar numa linha troca o painel para o serviço clicado (`service_id` na URL); nenhum texto de descrição/preparo inventado.

**Validação**
- LINT `app/control/clinic/ServiceList.php` (evidência: `No syntax errors detected`)
- GATE → screenshot `f10-ServiceList.png` lado a lado com a metade direita de `images/3.png`
- GATE → `browser_click` na 2ª linha (se houver) e `browser_evaluate` com `location.search` (evidência: contém `service_id=` do serviço clicado e o painel mostra o mesmo nome)

### T-12 — PrescriptionForm em 2 colunas

**Camada:** frontend
**Dependências:** T-02, T-03, T-06
**Paralelizável:** sim
**Complexidade:** alta
**Agente:** Kratos

**Arquivos prováveis**
- `src/app/control/clinic/PrescriptionForm.php`

**Interface**
- Produz: `PrescriptionForm` com `encounter_id`/`patient_id` só como contexto (ocultos, vindos da URL), profissional por `TDBCombo` de `SystemUser` (sem filtro de tenant, exceção documentada da fase 09), aba `tab=history` listando `prescriptionHistory`; `onAddItem`, `onSave` e a chave de sessão dos itens preservados
- Consome: T-02 `CvPage::header`, T-02 `CvPage::columns`, T-02 `CvNav::tabs`, T-02 `CvCard::create`, T-02 `CvBadge::create`, T-02 `CvForm::decorate`, T-02 `CvAvatar::placeholder`, T-06 `ClinicalSummaryService::patientCard`, T-06 `ClinicalSummaryService::lastEncounter`, T-06 `ClinicalSummaryService::prescriptionHistory`

**Teste RED**
- sem teste: reestruturação visual de controller; `PrescriptionServiceTest` existente cobre o salvamento; verificação visual e de fluxo no gate

**Critério de aceite**
- `index.php?class=PrescriptionForm&encounter_id=<id>&patient_id=<id>` em página cheia (sem right panel) mostra título "Prescrições", abas Nova prescrição (ativa) / Histórico de prescrições / Modelos (desabilitada), coluna esquerda "Dados da prescrição" com data (hoje, somente leitura) e Veterinário (combo), bloco de medicamento em grid (Medicamento, Dose + Unidade, Via, Frequência, Duração), "Adicionar outro medicamento", "Orientações adicionais" (`orientation_text`), botões Limpar e Salvar prescrição; coluna direita com card do paciente (avatar placeholder, raça, idade, peso, tutor, telefone, e-mail), "Último atendimento" e "Histórico de prescrições" com badge de status.
- Salvar com 2 medicamentos grava 1 prescrição com 2 itens (conferido na aba Histórico: `items_count` 2) e nenhum campo de validade/modelo/anexo aparece.
- Sem `encounter_id`: aviso "abra pelo atendimento" e nenhum erro fatal PHP.

**Validação**
- LINT `app/control/clinic/PrescriptionForm.php` (evidência: `No syntax errors detected`)
- `grep -c "adianti_right_panel" src/app/control/clinic/PrescriptionForm.php` (evidência: `0`)
- GATE → a partir de um `EncounterView` em andamento, ação inline Prescrição, adicionar 2 medicamentos, salvar, abrir `tab=history` (evidência: linha nova com 2 itens) + screenshot `f10-PrescriptionForm.png` lado a lado com `images/1.png`
- GATE → Review Focus: `browser_navigate` `index.php?class=PrescriptionForm` sem parâmetros (evidência: texto do aviso visível e `browser_console_messages` sem erro PHP/`Fatal`)

### T-13 — EncounterView com cabeçalho do paciente, wizard e plano clínico

**Camada:** frontend
**Dependências:** T-02, T-03, T-06
**Paralelizável:** sim
**Complexidade:** alta
**Agente:** Yoda

**Arquivos prováveis**
- `src/app/control/clinic/EncounterView.php`

**Interface**
- Produz: `EncounterView` com wizard cliente de 5 etapas sobre o mesmo formulário `form_EncounterView_<id>` (Anamnese: `anamnesis_text` + ditado; Exame físico: `temperature_c`, `heart_rate_bpm`, `respiratory_rate_mpm`, `weight_kg`, `mucous_membranes`, `capillary_refill_seconds`, `physical_exam_text`; Diagnóstico: `diagnosis_text`; Plano clínico: `clinical_plan_text` + abas Prescrições/Exames/Procedimentos/Vacinas/Orientações com `encounterPlanItems` e botões Prescrever / Solicitar exame / Procedimento / Mais (Vacina, Conta); Finalização: resumo + Finalizar), `followup_service_id` por `TDBCombo` de `Service` com filtro de tenant; métodos `onStart`, `onAutosave`, `onFinish`, `onAttachDocument` com os mesmos nomes
- Consome: T-02 `CvPage::columns`, T-02 `CvWizard::steps`, T-02 `CvBadge::create`, T-02 `CvCard::create`, T-02 `CvAvatar::placeholder`, T-02 `CvFormat::money`, T-06 `ClinicalSummaryService::patientCard`, T-06 `ClinicalSummaryService::encounterPlanItems`

**Teste RED**
- sem teste: reestruturação visual; `EncounterTimelineIntegrationTest` e `EncounterServiceTest` existentes guardam os fluxos de start/autosave/finish e precisam continuar passando; verificação de fluxo no gate

**Critério de aceite**
- Atendimento em andamento mostra: voltar, "Atendimento" + badge "Em atendimento", timer contando desde `started_at`, botões Imprimir (`window.print()`) e Finalizar atendimento; cabeçalho do paciente (avatar placeholder, nome, raça, idade, peso, tutor, telefone); wizard de 5 etapas; sinais vitais em grid; plano clínico com abas e contagens reais; coluna direita com histórico/timeline, anexos de `EncounterDocumentService::list` e "Resumo financeiro" com "Total do atendimento"; nenhum bloco de IA visível e nenhum botão Pausar.
- `grep -c "TButton::create(" EncounterView.php` = 0; os 5 pares `prescription→PrescriptionForm`, `exam→ExamRequestForm`, `procedure→ProcedureExecutionForm`, `vaccine→VaccinationForm`, `account→EncounterAccountForm` presentes; `setInterval` do autosave, `enableSpeechRecognition` e `setFormName('form_EncounterView_'` presentes.
- Texto digitado na etapa Anamnese, com o usuário na etapa Diagnóstico quando o autosave dispara (20 s), aparece no campo após recarregar a página; clicar nas 5 ações inline leva a URLs com `encounter_id=<id>`; Finalizar mostra "Atendimento finalizado".

**Validação**
- LINT `app/control/clinic/EncounterView.php` (evidência: `No syntax errors detected`)
- `grep -c "TButton::create(" src/app/control/clinic/EncounterView.php; grep -cE "PrescriptionForm|ExamRequestForm|ProcedureExecutionForm|VaccinationForm|EncounterAccountForm" src/app/control/clinic/EncounterView.php; grep -c "enableSpeechRecognition\|setInterval" src/app/control/clinic/EncounterView.php` (evidência: `0`, ≥ 5, ≥ 2)
- SUITE (evidência: `PASS  Integration\EncounterTimelineIntegrationTest::…` e `Failed: 0`)
- GATE → Review Focus: digitar "teste autosave f10" em Anamnese, ir para a etapa Diagnóstico, aguardar 25 s, recarregar (evidência: texto presente em `anamnesis_text`)
- GATE → clicar nas 5 ações inline (evidência: 5 URLs com `encounter_id=`), Finalizar (evidência: "Atendimento finalizado") + screenshot `f10-EncounterView.png` lado a lado com `images/4.png`

### T-14 — Lote Recepção/cadastros no padrão

**Camada:** frontend
**Dependências:** T-02
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Thanos

**Arquivos prováveis**
- `src/app/control/clinic/TutorList.php`
- `src/app/control/clinic/TutorForm.php`
- `src/app/control/clinic/PatientList.php`
- `src/app/control/clinic/PatientForm.php`
- `src/app/control/clinic/AppointmentForm.php`
- `src/app/control/clinic/AgendaView.php`
- `src/app/control/clinic/QueueEntryView.php`
- `src/app/control/clinic/GlobalSearchController.php`

**Interface**
- Produz: formulários do lote em página cheia (sem `setTargetContainer('adianti_right_panel')`, "Fechar" vira voltar para a lista), `PatientForm` com `tutor_id` por `TDBUniqueSearch` de `Tutor` filtrado por tenant quando não vem na URL, listas com `CvDatagrid`; `GlobalSearchController::onSearch` com o mesmo parâmetro `query`
- Consome: T-02 `CvPage::header`, T-02 `CvPage::filterBar`, T-02 `CvBadge::create`, T-02 `CvDatagrid::decorate`, T-02 `CvDatagrid::actionMenu`, T-02 `CvDatagrid::footer`, T-02 `CvForm::decorate`, T-02 `CvAvatar::placeholder`

**Teste RED**
- sem teste: reestilização de controllers; `TutorServiceTest`/`PatientServiceTest`/`AppointmentServiceTest`/`QueueEntryServiceTest` existentes guardam as regras

**Critério de aceite**
- `grep -c "adianti_right_panel"` = 0 nos 8 arquivos; cada tela renderizada mostra cabeçalho `CvPage`, listas com checkbox/"…"/rodapé "Mostrando X–Y de N" e formulários em grid de 2 colunas.
- Criar tutor e paciente (sem data de nascimento) pela UI salva e reabre o registro em página cheia; busca global por nome de paciente lista o paciente.

**Validação**
- LINT dos 8 arquivos (evidência: 8 `No syntax errors detected`)
- `grep -c "adianti_right_panel" src/app/control/clinic/{TutorList,TutorForm,PatientList,PatientForm,AppointmentForm,AgendaView,QueueEntryView,GlobalSearchController}.php` (evidência: todas as linhas `:0`)
- GATE → screenshots `f10-TutorList.png`, `f10-PatientForm.png`, `f10-AgendaView.png`, `f10-QueueEntryView.png` + criar tutor "Tutor F10" e paciente "Pet F10" sem data (evidência: mensagem de registro salvo e registro reaberto)

### T-15 — Lote Clínico no padrão

**Camada:** frontend
**Dependências:** T-02, T-13
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Yoda

**Arquivos prováveis**
- `src/app/control/clinic/ExamRequestForm.php`
- `src/app/control/clinic/ExamResultForm.php`
- `src/app/control/clinic/PendingExamResultList.php`
- `src/app/control/clinic/ProcedureExecutionForm.php`
- `src/app/control/clinic/VaccinationForm.php`
- `src/app/control/clinic/VaccinationCardView.php`

**Interface**
- Produz: formulários do lote em página cheia, com PATTERN0 aplicado; `encounter_id`/`patient_id` só como contexto oculto vindo da URL; `professional_system_user_id` por `TDBCombo` de `SystemUser` (sem filtro de tenant, exceção documentada); após salvar, retorno ao `EncounterView` do `encounter_id` de contexto
- Consome: T-02 `CvPage::header`, T-02 `CvPage::columns`, T-02 `CvBadge::create`, T-02 `CvDatagrid::decorate`, T-02 `CvDatagrid::actionMenu`, T-02 `CvDatagrid::footer`, T-02 `CvForm::decorate`, T-02 `CvAvatar::placeholder`

**Teste RED**
- sem teste: reestilização de controllers; `ExamServiceTest`/`ProcedureExecutionServiceTest`/`VaccinationServiceTest` existentes guardam as regras

**Critério de aceite**
- `grep -c "adianti_right_panel"` = 0 nos 6 arquivos; nenhum `TEntry` para `encounter_id`, `patient_id` ou `professional_system_user_id` visível ao usuário.
- A partir de um atendimento em andamento, Solicitar exame → salvar volta ao `EncounterView` e a aba Exames do plano clínico mostra o exame (contagem +1); `PendingExamResultList` mostra o exame com badge de status.
- PATTERN0 (achado da onda 2): em `VaccinationForm.php` a contagem de `setNumericMask(0` é igual à de `setProperty('pattern', '[0-9]*')`, e `browser_console_messages` de `VaccinationForm` não mostra linha com `pattern`.

**Validação**
- LINT dos 6 arquivos (evidência: 6 `No syntax errors detected`)
- `grep -c "adianti_right_panel" src/app/control/clinic/{ExamRequestForm,ExamResultForm,PendingExamResultList,ProcedureExecutionForm,VaccinationForm,VaccinationCardView}.php` (evidência: todas `:0`)
- GATE → fluxo Solicitar exame a partir do atendimento (evidência: URL final `class=EncounterView&encounter_id=` e contagem "Exames (n+1)") + screenshots `f10-ExamRequestForm.png`, `f10-VaccinationCardView.png`
- PATTERN0 → `f=src/app/control/clinic/VaccinationForm.php; echo "$(grep -c 'setNumericMask(0' $f) $(grep -cF "setProperty('pattern', '[0-9]*')" $f)"` (evidência: dois números iguais) + GATE → abrir `VaccinationForm` pela ação Vacina do atendimento e `browser_console_messages` (evidência: nenhuma linha com `pattern`)

### T-16 — Lote Catálogos no padrão

**Camada:** frontend
**Dependências:** T-02
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Levi

**Arquivos prováveis**
- `src/app/control/clinic/ExamCatalogList.php`
- `src/app/control/clinic/ExamCatalogForm.php`
- `src/app/control/clinic/ProcedureCatalogList.php`
- `src/app/control/clinic/ProcedureCatalogForm.php`
- `src/app/control/clinic/VaccineCatalogList.php`
- `src/app/control/clinic/VaccineCatalogForm.php`
- `src/app/control/clinic/ProcedureInputForm.php`
- `src/app/control/clinic/VaccineProtocolForm.php`

**Interface**
- Produz: listas/formulários do lote no padrão, formulários em página cheia com PATTERN0 aplicado; `ProcedureInputForm.procedure_catalog_item_id` por `TDBCombo` de `ProcedureCatalogItem` e `VaccineProtocolForm.vaccine_catalog_item_id` por `TDBCombo` de `VaccineCatalogItem`, ambos com `TFilter('tenant_id', '=', $tenant_id)` (fallback `-1`)
- Consome: T-02 `CvPage::header`, T-02 `CvPage::filterBar`, T-02 `CvBadge::create`, T-02 `CvDatagrid::decorate`, T-02 `CvDatagrid::actionMenu`, T-02 `CvDatagrid::footer`, T-02 `CvForm::decorate`

**Teste RED**
- sem teste: reestilização de controllers; serviços de catálogo já cobertos pela suíte

**Critério de aceite**
- `grep -c "adianti_right_panel"` = 0 nos 8 arquivos; `grep -c "TFilter('tenant_id'"` ≥ 1 em `ProcedureInputForm.php` e `VaccineProtocolForm.php`.
- Os combos de item de catálogo listam só itens do tenant da sessão (quantidade de opções = `SELECT COUNT(*) FROM procedure_catalog_item WHERE tenant_id = <tenant>` ativos).
- PATTERN0 (achado da onda 2): em `ProcedureInputForm.php`, `ProcedureCatalogForm.php`, `VaccineProtocolForm.php` e `VaccineCatalogForm.php` a contagem de `setNumericMask(0` é igual à de `setProperty('pattern', '[0-9]*')`, e `browser_console_messages` desses 4 formulários não mostra linha com `pattern`.

**Validação**
- LINT dos 8 arquivos (evidência: 8 `No syntax errors detected`)
- `grep -c "TFilter('tenant_id'" src/app/control/clinic/ProcedureInputForm.php src/app/control/clinic/VaccineProtocolForm.php` (evidência: ambos ≥ 1)
- PATTERN0 → `for f in src/app/control/clinic/{ProcedureInputForm,ProcedureCatalogForm,VaccineProtocolForm,VaccineCatalogForm}.php; do echo "$f $(grep -c 'setNumericMask(0' $f) $(grep -cF "setProperty('pattern', '[0-9]*')" $f)"; done` (evidência: em cada linha os dois números iguais) + GATE → abrir os 4 formulários e `browser_console_messages` (evidência: nenhuma linha com `pattern`)
- GATE → Review Focus: `browser_evaluate` contando `option` do combo em `ProcedureInputForm` e comparar com o SELECT somente-leitura por tenant (evidência: mesma quantidade) + screenshots `f10-ExamCatalogList.png`, `f10-ProcedureInputForm.png`

### T-17 — Lote Estoque/vendas (formulários e PDV) no padrão + correções de serviço da onda 2

**Camada:** frontend/backend
**Dependências:** T-02, T-10, T-11
**Paralelizável:** sim
**Complexidade:** alta
**Agente:** Darwin

Achados do gate/revisão da onda 2 que esta task corrige (`notes.md § Decisões`, onda 2): (1) `ServiceForm::onSave` só chama `create()` — editar duplica o serviço ou dá erro de nome duplicado; `onEdit` herdado de `TStandardForm` carrega o `Service` por id sem filtro de tenant (bloqueante, segurança); (2) filtro "Inativo" de `ServiceList` sempre vazio porque a tela lê `listActive()`; (3) PATTERN0 em `ServiceForm` (`duration_minutes`; o relatório do gate o atribuiu a `price`, mas quem gera `\d{1,0}` é o `setNumericMask(0, …)`) e nos demais formulários do lote. Reprodução exigida antes da correção: editar "F10 varredura Banho" (id 2) e salvar reproduz o erro de nome duplicado ou uma linha nova em `service`.

**Arquivos prováveis**
- `src/app/control/clinic/ProductForm.php`
- `src/app/control/clinic/StockBatchForm.php`
- `src/app/control/clinic/ServiceForm.php`
- `src/app/control/clinic/SaleForm.php`
- `src/app/control/clinic/ServiceList.php`
- `src/app/Core/Application/ServiceCatalogService.php`
- `src/app/Core/Domain/Service.php`
- `src/app/Core/Domain/Contract/ServiceRepositoryInterface.php`
- `src/app/Core/Persistence/ServiceRepository.php`
- `src/tests/Unit/ServiceCatalogServiceTest.php`
- `src/tests/Support/FakeServiceRepository.php`

**Interface**
- Produz: `ProductForm`/`ServiceForm`/`StockBatchForm` em página cheia com `onEdit` por `id` e retorno à lista (`ProductList`/`ServiceList`) após salvar; `StockBatchForm.product_id` oculto quando vem na URL, senão `TDBUniqueSearch` de `Product` filtrado por tenant; `SaleForm` com cabeçalho, abas do grupo `stock` (Vendas ativa) e carrinho em `CvDatagrid`, preservando `SaleService::create()` e os widgets relacionais da fase 09; PATTERN0 aplicado nos 4 formulários
- Produz: `ServiceCatalogService::update(int $id, array $data): Service` (`$data` = `['name' => string, 'category' => ?string, 'duration_minutes' => int, 'price_cents' => int, 'active' => bool]`; mantém o `id` e nunca insere; serviço inexistente no tenant → `InvalidArgumentException('Service not found for this tenant')`; nome já usado por outro serviço do tenant → a mesma exceção de nome duplicado de `create()`)
- Produz: `ServiceCatalogService::listAll(): array` → `list<Service>` ativos e inativos do tenant, ordenados por nome; `ServiceRepositoryInterface::listAll(): array` implementado em `ServiceRepository` (SQL escopado por `tenantQuery()`, `ORDER BY name ASC`) e em `FakeServiceRepository`
- Produz: `Service::changeDetails(string $name, ?string $category, int $durationMinutes, int $priceCents): void` (mesmas validações de `Service::create()`; ativo/inativo pelos `activate()`/`deactivate()` existentes)
- Produz: `ServiceForm::onEdit($param)` sobrescrito: carrega por `ServiceCatalogService::findById()` (escopado ao tenant) e preenche o formulário; id inexistente ou de outro tenant → `new TMessage('error', _t('Record not found'))` e formulário vazio; nenhum carregamento pelo ActiveRecord `Service` sem tenant. `ServiceForm::onSave` chama `update()` quando o campo `id` vem preenchido e `create()` quando vazio
- Produz: `ServiceList` lendo `listAll()`: sem filtro de status mostra ativos e inativos; `status=inactive` só inativos; `status=active` só ativos
- Consome: T-02 `CvPage::header`, T-02 `CvPage::columns`, T-02 `CvNav::tabs`, T-02 `CvDatagrid::decorate`, T-02 `CvForm::decorate`, T-02 `CvFormat::money`, T-10 `index.php?class=ProductForm&method=onEdit&id=<id>`, T-10 `index.php?class=StockBatchForm&product_id=<id>`, T-11 `index.php?class=ServiceForm&method=onEdit&id=<id>`

**Teste RED**
- `src/tests/Unit/ServiceCatalogServiceTest.php`, `src/tests/Support/FakeServiceRepository.php` — `testUpdateKeepsIdAndDoesNotDuplicate` (update de serviço existente altera nome/categoria/duração/preço/ativo, mantém o `id` e o repositório segue com 1 serviço), `testUpdateRejectsNameOfAnotherService` (nome de outro serviço do tenant lança exceção), `testUpdateRejectsServiceFromAnotherTenant` (serviço semeado no Fake com `tenantId` 2: `update()` lança `Service not found for this tenant` e `findById()` devolve null) e `testListAllIncludesInactiveServices` (serviço desativado aparece em `listAll()` e não em `listActive()`); falham antes da implementação porque `ServiceCatalogService::update` e `ServiceCatalogService::listAll` não existem (comando: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php`)

**Critério de aceite**
- `grep -c "adianti_right_panel"` = 0 nos 4 formulários.
- "…" → Editar em `ProductList` abre `ProductForm` em página cheia com os dados do produto; salvar volta a `ProductList`; "…" → Entrada de lote abre `StockBatchForm` com o produto fixo e, após receber 5 unidades, o card "Produtos em estoque"/estoque atual da linha aumenta em 5.
- A suíte imprime `PASS  Unit\ServiceCatalogServiceTest::` para os 4 métodos novos e para os 4 existentes, `Failed: 0`; o commit `Task: T-17 (RED)` toca só os 2 arquivos do bloco Teste RED e vem antes dos commits da implementação.
- Editar "F10 varredura Banho" (id 2) pela UI e salvar: `SELECT COUNT(*) FROM service WHERE tenant_id = 1` igual antes e depois, a linha id 2 com os valores novos e nenhuma mensagem de nome duplicado.
- `index.php?class=ServiceForm&method=onEdit&id=<id fora do tenant>` mostra "Registro não encontrado" e o campo `name` vazio.
- `index.php?class=ServiceList&status=inactive` lista o serviço desativado com badge Inativo e nenhuma linha com badge Ativo.
- PATTERN0: nos 4 formulários a contagem de `setNumericMask(0` é igual à de `setProperty('pattern', '[0-9]*')`, e `browser_console_messages` de `ServiceForm`, `ProductForm`, `StockBatchForm` e `SaleForm` não mostra linha com `pattern`.

**Validação**
- LINT dos 9 arquivos PHP de `src/app` e dos 2 de `src/tests` (evidência: 11 `No syntax errors detected`)
- `grep -c "adianti_right_panel" src/app/control/clinic/{ProductForm,StockBatchForm,ServiceForm,SaleForm}.php` (evidência: todas `:0`)
- SUITE (evidência: `PASS  Unit\ServiceCatalogServiceTest::testUpdateKeepsIdAndDoesNotDuplicate`, `…::testUpdateRejectsNameOfAnotherService`, `…::testUpdateRejectsServiceFromAnotherTenant`, `…::testListAllIncludesInactiveServices` e `Failed: 0`; a execução no commit RED mostrou `FAIL  Unit\ServiceCatalogServiceTest::testUpdateKeepsIdAndDoesNotDuplicate`, colada em `## RED` com o hash)
- COMMITS → `git -C /var/www/html/centralvet log --reverse --format='%h %s | %b' f48ebe0..HEAD -- src/tests/Unit/ServiceCatalogServiceTest.php src/tests/Support/FakeServiceRepository.php src/app/Core/Application/ServiceCatalogService.php src/app/Core/Domain/Service.php src/app/control/clinic/ServiceForm.php` (evidência: o primeiro commit tem `Task: T-17 (RED)` e `git show --stat` dele lista só os 2 arquivos do Teste RED)
- PATTERN0 → `for f in src/app/control/clinic/{ProductForm,StockBatchForm,ServiceForm,SaleForm}.php; do echo "$f $(grep -c 'setNumericMask(0' $f) $(grep -cF "setProperty('pattern', '[0-9]*')" $f)"; done` (evidência: em cada linha os dois números iguais)
- GATE → editar sem duplicar: `docker compose exec -T mysql sh -c 'mysql -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE" -e "SELECT COUNT(*) FROM service WHERE tenant_id = 1; SELECT id, name, duration_minutes, active FROM service WHERE id = 2;"'` antes; `browser_navigate` `index.php?class=ServiceList` → "…" da linha "F10 varredura Banho" → Editar → Duração `45` e Status Inativo → Salvar; o mesmo SELECT depois (evidência: contagem igual, id 2 com `duration_minutes` 45 e `active` 0, nenhuma mensagem "already exists"/duplicado)
- GATE → filtro Inativo: `browser_navigate` `index.php?class=ServiceList&status=inactive` + `browser_evaluate` contando linhas da tabela e badges diferentes de "Inativo" (evidência: ≥ 1 linha, com "F10 varredura Banho", e `0` badges diferentes); em seguida reabrir o serviço id 2, Status Ativo, Salvar (evidência: `active` 1 no SELECT)
- GATE → id de outro tenant negado: `docker compose exec -T mysql sh -c 'mysql -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE" -e "SELECT id FROM service WHERE tenant_id <> 1 LIMIT 1;"'` — com resultado, usar esse id; vazio (hoje só existe o tenant 1), usar `999999`; `browser_navigate` `index.php?class=ServiceForm&method=onEdit&id=<id>` + `browser_evaluate` `document.querySelector('[name=name]').value` (evidência: mensagem "Registro não encontrado" visível e valor `''`); o outro tenant real fica coberto por `testUpdateRejectsServiceFromAnotherTenant`
- GATE → `browser_console_messages` em `ServiceForm`, `ProductForm`, `StockBatchForm` e `SaleForm` (evidência: nenhuma linha com `pattern`) + fluxo Entrada de lote de 5 unidades (evidência: estoque atual da linha +5 em `ProductList`) + screenshots `f10-ProductForm.png`, `f10-SaleForm.png`

### T-18 — Lote Financeiro no padrão, com abas financeiras

**Camada:** frontend
**Dependências:** T-02, T-09
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Tesla

**Arquivos prováveis**
- `src/app/control/clinic/FinancialEntryList.php`
- `src/app/control/clinic/FinancialEntryForm.php`
- `src/app/control/clinic/PayableList.php`
- `src/app/control/clinic/PayableForm.php`
- `src/app/control/clinic/PendingReceivableList.php`
- `src/app/control/clinic/PaymentForm.php`
- `src/app/control/clinic/CashSessionList.php`
- `src/app/control/clinic/CashSessionForm.php`
- `src/app/control/clinic/EncounterAccountForm.php`
- `src/app/lib/widget/CvNav.php`

**Interface**
- Produz: `CvNav::tabs('finance', …)` com a aba `revenues` → `index.php?class=FinancialEntryList&entry_type=income` (correção da onda 2: o schema usa `income`, valor de `FinancialEntry::TYPE_INCOME`; só essa linha de `CvNav.php` muda, demais grupos e chaves intactos)
- Produz: `FinancialEntryList` aceitando `entry_type=income|expense` (aba Receitas/Despesas ativa conforme o parâmetro; outro valor lista tudo com a aba "Receitas" e "Despesas" inativas), listas financeiras com `CvNav::tabs('finance', …)` e badges (Receita/Despesa, Pago/Aberto/Parcial), formulários em página cheia; `EncounterAccountForm.authorized_by_system_user_id` por `TDBCombo` de `SystemUser`; nomes de `$action` de `EncounterAccountForm` (`onLoad`/`onSave`/`onApplyDiscount`/`onClose`) e `PaymentService::register()` preservados; PATTERN0 aplicado em `EncounterAccountForm`
- Consome: T-02 `CvPage::header`, T-02 `CvPage::filterBar`, T-02 `CvNav::tabs`, T-02 `CvBadge::create`, T-02 `CvDatagrid::decorate`, T-02 `CvDatagrid::actionMenu`, T-02 `CvDatagrid::footer`, T-02 `CvForm::decorate`, T-02 `CvFormat::money`, T-09 `class FinancialOverview extends TPage`

**Teste RED**
- sem teste: reestilização de controllers; `PaymentServiceTest`/`CashSessionServiceTest`/`EncounterAccountServiceTest` existentes guardam as regras

**Critério de aceite**
- `grep -c "adianti_right_panel"` = 0 nos 9 arquivos; as 5 telas de lista mostram as abas financeiras com a aba correspondente ativa.
- `index.php?class=FinancialEntryList&entry_type=expense` lista só linhas com badge Despesa; `PayableList` → "…" → Pagar conclui o pagamento e a linha passa a badge Pago.
- Aba Receitas (achado da onda 2): `grep -rc "entry_type=revenue" src/app/lib/widget/CvNav.php src/app/control/clinic/` = 0 em todos; clicar em "Receitas" a partir de `FinancialOverview` leva a URL com `entry_type=income`, a aba Receitas fica ativa, a tabela não tem badge Despesa e o total "de N" do rodapé = `SELECT COUNT(*) FROM financial_entry WHERE tenant_id = 1 AND entry_type = 'income'` no mesmo período/unidade do filtro exibido (hoje há 1 lançamento `income`).
- PATTERN0: em `EncounterAccountForm.php` a contagem de `setNumericMask(0` é igual à de `setProperty('pattern', '[0-9]*')` e `browser_console_messages` da tela não mostra linha com `pattern`.

**Validação**
- LINT dos 9 arquivos (evidência: 9 `No syntax errors detected`)
- `grep -c "adianti_right_panel" src/app/control/clinic/{FinancialEntryList,FinancialEntryForm,PayableList,PayableForm,PendingReceivableList,PaymentForm,CashSessionList,CashSessionForm,EncounterAccountForm}.php` (evidência: todas `:0`)
- GATE → `browser_navigate` `FinancialEntryList&entry_type=expense` e contar badges Receita (evidência: `0`) + screenshots `f10-FinancialEntryList.png`, `f10-PayableList.png`, `f10-EncounterAccountForm.png`
- LINT `app/lib/widget/CvNav.php` + `grep -rc "entry_type=revenue" src/app/lib/widget/CvNav.php src/app/control/clinic/ | grep -v ':0$' | wc -l` (evidência: `No syntax errors detected` e `0`)
- GATE → filtro de receitas: `browser_navigate` `index.php?class=FinancialOverview` → `browser_click` na aba "Receitas" + `browser_evaluate` com `location.search`, a aba ativa (`.cv-tabs .active`), a contagem de badges "Despesa" na tabela e o texto do rodapé (evidência: `entry_type=income`, aba "Receitas", `0` badges Despesa e N do rodapé igual ao `docker compose exec -T mysql sh -c 'mysql -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE" -e "SELECT COUNT(*) FROM financial_entry WHERE tenant_id = 1 AND entry_type = 'income';"'` restrito ao período/unidade da tela)
- PATTERN0 → `f=src/app/control/clinic/EncounterAccountForm.php; echo "$(grep -c 'setNumericMask(0' $f) $(grep -cF "setProperty('pattern', '[0-9]*')" $f)"` (evidência: dois números iguais) + GATE → abrir `EncounterAccountForm` pela ação Conta do atendimento e `browser_console_messages` (evidência: nenhuma linha com `pattern`)

### T-19 — menu.xml reorganizado + sidebar final

**Camada:** frontend
**Dependências:** T-01, T-07, T-08, T-09
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Athena

**Arquivos prováveis**
- `src/menu.xml`
- `src/app/templates/adminbs5/custom.css`
- `src/app/templates/adminbs5/js/cv-shell.js`
- `src/app/control/clinic/CvShellController.php`

**Interface**
- Produz: menu de primeiro nível na ordem Dashboard (desabilitado), Agenda (`AgendaView`), Tutores (`TutorList`), Pacientes (`GlobalSearchController`), Atendimentos (`QueueEntryView`), Prontuário (desabilitado), Vacinação (`VaccinationCardView`), Exames (`PendingExamResultList`), Cirurgias (desabilitado), Prescrições (desabilitado, dica "abra pelo atendimento"), Estoque (`ProductList`), Vendas (`SaleForm`), Financeiro (`FinancialOverview`), Serviços (`ServiceList`), CRM / Comunicação (desabilitado), Relatórios (desabilitado); no rodapé da sidebar Configurações (submenu: catálogos clínicos, Administração e Logs do Adianti) e Ajuda (desabilitado); item desabilitado com classe `cv-menu-disabled` e rótulo "Em breve", sem navegação; grupo "Common pages" removido
- Produz (correção da onda 2, defesa em profundidade): `CvShellController::onContext` devolve em `units` só as unidades de `getSystemUserUnits()` cujo `system_unit.tenant_id` = `TSession::getValue('tenantid')` (JSON de T-08 inalterado); `CvShellController::onSwitchUnit` recusa, antes de `ApplicationAuthenticationService::setUnit()`, `unit_id` fora dessa lista com `TMessage('error', _t('Unauthorized access to that unit'))` e sessão inalterada; ambos pela mesma função privada `allowedUnitIds(): array`
- Produz: regras `.cv-unit-switch__select` em `custom.css` (largura mínima 180px, altura/borda/raio/cor dos tokens da casca, foco com o anel da casca, sem o azul `#0d6efd`)
- Consome: T-01 `'has_master_menu' => '0'`, T-07 `FinancialOverview`, T-08 `CvShell.init()`, T-08 `CvShellController::onContext`, T-09 `class FinancialOverview extends TPage`

**Teste RED**
- sem teste: configuração de menu e estilo; verificação visual e por clique no gate

**Critério de aceite**
- `menu.xml` é XML válido; a sidebar renderizada mostra os 16 itens na ordem acima com ícone + rótulo, Configurações e Ajuda colados ao rodapé da sidebar, o item da tela atual destacado, e nenhum item "Common page".
- Clicar num item desabilitado não muda `location.href`; clicar em Financeiro abre `FinancialOverview`; Programas/Grupos/Unidades/Usuários só aparecem dentro de Configurações.
- Unidades por tenant (achado da onda 2): `onContext` devolve em `units` exatamente as unidades de `SELECT su.id FROM system_user_unit suu JOIN system_unit su ON su.id = suu.system_unit_id WHERE suu.system_user_id = <admin> AND su.tenant_id = 1`, com um único `current: true`; `onSwitchUnit` com `unit_id` fora dessa lista mostra "Unauthorized access to that unit" (ou o equivalente em pt) e o `current` do `onContext` seguinte não muda; `grep -c "tenant_id" src/app/control/clinic/CvShellController.php` ≥ 1.
- O select `.cv-unit-switch__select` renderizado tem `getComputedStyle(...).minWidth` = `180px` e `grep -c "#0d6efd" src/app/templates/adminbs5/custom.css` = 0.

**Validação**
- `python3 -c "import xml.dom.minidom as m;m.parse('/var/www/html/centralvet/src/menu.xml');print('xml valido')"` (evidência: `xml valido`)
- GATE → screenshot `f10-Sidebar.png` + `browser_click` em "Cirurgias" e `browser_evaluate` `location.href` antes/depois (evidência: iguais) + `browser_click` em "Financeiro" (evidência: URL com `class=FinancialOverview`)
- LINT `app/control/clinic/CvShellController.php` (evidência: `No syntax errors detected`)
- GATE → unidades por tenant: `docker compose exec -T mysql sh -c 'mysql -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE" -e "SELECT su.id, su.tenant_id FROM system_user_unit suu JOIN system_unit su ON su.id = suu.system_unit_id WHERE suu.system_user_id = 1;"'` + `browser_evaluate` com `fetch('engine.php?class=CvShellController&method=onContext&static=1').then(r=>r.json())` (evidência: ids de `units` = ids do SELECT com `tenant_id` 1, um único `current: true`)
- GATE → troca recusada: `docker compose exec -T mysql sh -c 'mysql -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE" -e "SELECT id FROM system_unit WHERE tenant_id <> 1 LIMIT 1;"'` — com resultado, usar esse id; vazio (hoje só existe o tenant 1), usar `999999`; `browser_navigate` `engine.php?class=CvShellController&method=onSwitchUnit&unit_id=<id>` e novo `onContext` (evidência: mensagem de acesso não autorizado e `current` inalterado)
- GATE → `browser_navigate` `index.php?class=ServiceList` + `browser_evaluate` `getComputedStyle(document.querySelector('.cv-unit-switch__select')).minWidth` (evidência: `180px`) + screenshot `f10-Shell-unit.png`

### T-20 — Consolidação de chaves i18n pedidas no board

**Camada:** frontend
**Dependências:** T-03, T-08, T-09, T-10, T-11, T-12, T-13, T-14, T-15, T-16, T-17, T-18, T-19
**Paralelizável:** não
**Complexidade:** simples
**Agente:** Platão

**Arquivos prováveis**
- `src/app/config/translations.json`

**Interface**
- Produz: pares `{"en","pt"}` para toda linha `- [T-14] i18n: <chave en> → <texto pt>` (formato; T-14 é exemplo) registrada em `board.md` nas ondas 2 e 3
- Consome: nada

**Teste RED**
- sem teste: arquivo de dados de tradução; verificação por parse JSON e pelas chaves do board

**Critério de aceite**
- Toda chave pedida no board existe em `translations.json` com `pt` não vazio; JSON válido e sem chave `en` duplicada; contagem de pares = contagem após T-03 + chaves novas do board (nenhum par existente removido).

**Validação**
- `python3 -c "import json;d=json.load(open('/var/www/html/centralvet/src/app/config/translations.json'));en=[x['en'] for x in d];print(len(en)-len(set(en)), len(d))"` (evidência: `0` e contagem = anterior + novas)
- `grep -c "i18n:" /var/www/html/centralvet/.claude/tasks/mar-20260923-1630-fidelidade-visual-mocks/board.md` comparado com o número de chaves adicionadas (evidência: iguais, descontadas repetidas)

### T-21 — Validação visual lado a lado com os mocks + regressão + varredura Playwright de todas as telas do menu

**Camada:** qa
**Dependências:** T-20
**Paralelizável:** não
**Complexidade:** média
**Agente:** Spock

**Arquivos prováveis**

**Interface**
- Produz: `reports/T-21.md` com a tabela tela × mock × divergências estruturais, a saída da suíte e a tabela da varredura `Tela | Fluxos exercitados | Console (errors) | Rede (≥400) | Erro na tela | Veredito | Task dona` (Task dona = ID da task no Mapa de arquivos, `pré-existente` para tela fora do Mapa, `framework — não editável` para `src/lib/adianti`/`framework_hashes.php`), com passos de reprodução, mensagem exata e screenshot `f10-bug-<Tela>-<n>.png` por bug
- Consome: nada

**Teste RED**
- sem teste: task de validação, não implementa comportamento

**Critério de aceite**
- Screenshots `f10-PrescriptionForm.png`, `f10-ProductList.png`, `f10-FinancialOverview.png`, `f10-ServiceList.png`, `f10-EncounterView.png` comparados com `images/1.png`–`4.png`: cada região do mock (sidebar, topbar, cabeçalho + seletor de unidade, abas, cards, tabela, coluna direita, rodapé) presente, ou ausente com pendência registrada em `notes.md` (dado inexistente no schema, IA oculta).
- Amostra de 10 telas do item (d) mostra cabeçalho `CvPage`, formulário em página cheia e paginação sem `#0d6efd`.
- SUITE termina com `Failed: 0` e `Total` ≥ 158; `grep -l "adianti_right_panel" src/app/control/clinic/*Form.php` não lista nenhum arquivo.
- A tabela da varredura tem uma linha por item navegável de `src/menu.xml` (primeiro nível e submenus de Configurações, inclusive as telas administrativas do Adianti) mais `EncounterView`, `PrescriptionForm` e um formulário aberto por lista; cada linha com veredito `aprovado` (0 erros de console, 0 requisições ≥ 400, nenhum erro na tela) ou `reprovado` com o bug (passos, mensagem exata, screenshot); itens desabilitados aparecem como `desabilitado — sem navegação`.
- Com ao menos um bug de veredito diferente de `aprovado`, o relatório termina com `Status: parcial` e a lista de bugs que o orquestrador leva à `### Onda 6 — correção (usuário)` (IDs a partir de T-22); sem bugs, `Status: concluído`.

**Validação**
- GATE → os 5 screenshots acima + 10 da amostra (evidência: tabela de divergências no relatório com "nenhuma estrutural" ou pendência nomeada por região)
- SUITE (evidência: `Failed: 0`, `Total` ≥ 158)
- `grep -l "adianti_right_panel" src/app/control/clinic/*Form.php | wc -l` (evidência: `0`)
- VARREDURA completa → para cada item de `src/menu.xml` (lista extraída com `grep -oE '<action>[^<]+' /var/www/html/centralvet/src/menu.xml | sort -u`) e para as telas contextuais: `browser_navigate` pelo menu + `browser_snapshot`, fluxos abrir/listar/filtrar/abrir registro/salvar (só telas clínicas, registros `F10 varredura`)/voltar, depois `browser_console_messages` e `browser_network_requests` (evidência: tabela da varredura em `reports/T-21.md` com uma linha por tela, contagens de console/rede e veredito; número de linhas ≥ `grep -oE '<action>[^<]+' /var/www/html/centralvet/src/menu.xml | sort -u | wc -l`)
- COMMITS → `git -C /var/www/html/centralvet log --format='%h|%s|%(trailers:key=Task,valueonly,separator=%x2C)' 9efef4e..HEAD` (evidência: toda linha cujo assunto não começa com `chore(tasks)` tem o terceiro campo não vazio, no formato `T-01`…`T-21` ou `T-04 (RED)`)

## Legenda

- [ ] pendente
- [~] em andamento
- [x] concluído com evidência
- [!] bloqueado
