# Plano: Rodada 2 — dívida técnica, edição de cadastros, campos com schema e ações

## Objetivo
Fechar as sugestões `[aberta]` corrigíveis da revisão final da fase 10 (`mar-20260923-1630-fidelidade-visual-mocks/reviews/final.md § Triagem`) e três blocos novos. O primeiro é editar tutor, paciente e agendamento a partir da fila e das telas de cadastro. O segundo são os campos que o mock mostra e o schema não tinha, via migration `0007`: preço de venda e código, foto e alergia, validade e modelos de prescrição, forma de pagamento no lançamento, saldo bancário e pausa de atendimento. O terceiro são as ações sem backend: Exportar, Importar, Duplicar/Excluir serviço, Pausar, Salvar como modelo e Gerar relatório.

## Premissas
- Escopo escolhido pelo usuário: os 4 blocos. Itens de framework ou infra da triagem ficam fora: link "Trace" de `layout-basic.html`, tela Log do PHP vazia e CRLF de `layout.html`.
- Branch de trabalho `feat/rodada-2-cadastros-schema-acoes`, criada pelo orquestrador a partir de `feat/fidelidade-visual-mocks` @ `d6dce7a` antes da onda 1. A sugestão é do usuário e prevalece sobre o padrão `task/<contexto>`. Base da revisão final: `feat/fidelidade-visual-mocks`, que ainda não foi mergeada na `main`.
- Toda escrita de SQL no banco exige aprovação. T-01 só redige arquivos: a migration `20260930_0007_rodada2_cadastros_financeiro.sql`, o `.verify.sql` e o DML `sql/T-01-programs.sql`. O orquestrador mostra efeito e risco ao usuário e aplica só depois da aprovação, seguindo `docs/runbooks/migrations.md`: `make backup`, `gzip -t`, SHA-256 no `INSERT INTO schema_migrations`, aplicação única com o usuário de migration e `.verify.sql`. **A onda 2 só abre depois da migration e do DML aplicados e conferidos.** Os testes de integração leem as colunas novas e falham se elas não existirem.
- Rollback: não há "down" (`docs/runbooks/migration-rollback.md`). O backup pré-migration é a opção preferida. A alternativa é uma migration reversa `0008`, que fica fora desta rodada e só é redigida se a 0007 falhar.
- Respostas do usuário (2026-09-30), dadas antes de T-01:
  - as 24 tasks ficam, sem corte;
  - as 4 decisões da migration estão confirmadas: `paused_at`/`paused_seconds` sem status novo, `photo_object_key` no S3, saldo bancário informado à mão e backfill de `payment_method` a partir de `category`;
  - o `CvShellController` vai para todos os grupos via DML (`sql/T-01-programs.sql`). O DML ainda depende da aprovação SQL na hora de aplicar.
- Onda 8 — correção (usuário), pedida em 2026-09-30, com BASE `cbd7ad1`:
  - fontes: `notes.md § Pendências` e `reviews/final.md § Triagem`/`Pós-revisão final`;
  - os dados de teste "R2" ficam no banco, sem limpeza;
  - nada de schema novo;
  - o check-in é uma ação de `AgendaView`, programa já registrado, sem controller novo nem DML.
  Ficam fora, com motivo:
  - T-01, cabeçalho "4 UNIQUE" da 0007: o arquivo está amarrado ao checksum aplicado, e editá-lo quebra a auditoria da migration;
  - T-01, ids fixos 106–108 do DML: já foi aplicado, e não há o que corrigir sem outro DML;
  - datepicker que desfaz o `fill`: comportamento do widget do framework;
  - evidências históricas (contagem de `<option>` de T-05, screenshot da BASE de T-19, "System* 19 telas" de T-24): não há correção em código;
  - fallback de `tenant_user` copiado em 3 controllers (T-10): refatoração transversal, sem mudança de comportamento;
  - UPDATE de `PatientRepository` que grava as colunas da foto (T-12): ruling de T-12, porque só `PatientService` monta `Patient` e copia a foto;
  - render do menu da fila por script (T-17): substituído pelo gate de T-29;
  - formato de reabertura do peso "4,5" (T-27): ruling;
  - filtro/`maxlength` do peso sem teste (T-27): é JS no navegador, um teste PHP não detectaria a regressão, e o gate de T-32/T-27 cobre;
  - mensagens de domínio das telas fora da triagem (`TutorForm`, `ProductForm`, `FinancialEntryForm` e demais): seguem com `getMessage()`; o catálogo de T-28 vale para quem chama `CvFormat::userError`;
  - da fase 10:
    - combos `active=1` nos catálogos `TStandardForm`: decisão de UX, e o caso crítico já foi resolvido;
    - offset do `pageNavigation` e cards à mão: cosmético, sem bug observado;
    - `VaccinationCardView` sem pista: exige desenho de UX;
    - `actionMenu`/`columns` sem uso: API do kit, remover não traz ganho;
    - saldo do caixa sem saídas: exige schema;
    - `GROUP_CONCAT` truncado: limite de sessão do MySQL, infra;
    - `EncounterDocumentService::list()` vazio: exige persistência em `stored_object`;
    - testes sem ruído de T-05/T-06: sem bug;
    - itens documentais (T-28 id 2, T-29 `.page-item.off`, mocks ausentes).
- Decisões de modelagem do planejador (ver `notes.md § Decisões tomadas`):
  - a pausa não cria status: `encounter.status` continua `in_progress`, e `paused_at` não nulo indica pausa (evita recriar o CHECK `encounter_status_ck`);
  - a foto do paciente vai no storage S3 já usado pelos anexos (chave em `patient.photo_object_key`) e é servida por `PatientForm::onPhoto`, sem URL pré-assinada;
  - o saldo bancário é informado à mão por conta (`bank_account.balance_cents`), sem conciliação;
  - `financial_entry.payment_method` é preenchido pelos pagamentos novos, e a migration faz o backfill a partir de `category` nos lançamentos de `reference_type = 'payment'`;
  - Exportar gera CSV, Gerar relatório gera PDF (dompdf, como `SaleForm`/`PrescriptionForm`) e Importar lê CSV;
  - a cópia de um serviço nasce inativa;
  - só é possível excluir serviço sem agendamento; o que tiver agendamento deve ser inativado.
- Comandos do plano (rodar de `/var/www/html/centralvet`):
  - **LINT** = `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php -l <arquivo>`;
  - **SUITE** = `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php`. Não filtra por arquivo: leia as linhas `PASS/FAIL  <Suite>\<Classe>::`. Na BASE: `Total: 205, Passed: 205, Failed: 0`;
  - **GATE**: o orquestrador reconstrói (`docker compose build app worker && docker compose up -d app worker && docker compose restart nginx`), e só então o validador usa o Playwright MCP em `http://127.0.0.1:8081`, com a sessão admin logada pelo orquestrador. A credencial é dada pelo usuário e não fica gravada.
- Controllers Adianti não são carregados pela suíte. Nas tasks só de controller, a verificação é por grep, `php -r` com `init.php` e gate: `Teste RED` = `sem teste`.
- `translations.json` tem escritor único: T-23, na onda 4. A task que precisar de chave nova usa `_t('<chave en>')` e registra no board `- [T-xx] i18n: <chave en> → <texto pt>`. Até T-23 o Adianti mostra "Message not found", aceito até o gate da onda 4.
- Registros de teste criados pelo gate levam o prefixo `R2 varredura`. Só há o tenant 1 no banco; o caso de outro tenant é coberto por teste unitário com Fake `tenantId` 2 e, na UI, pelo id `999999`.

## Escopo

### Incluso
- Dívida (bloco 1):
  - docblock de `EncounterAccountService::applyDiscount` (T-02);
  - mensagem de `AuthorizationRequest` com `$action` escapado e teste da mensagem (T-02);
  - limite ≤ 0 de `recentSales`/`lowStock` (T-03);
  - `products()` varrido uma vez por carga (T-03, T-21);
  - `lastEncounter` como atendimento anterior (T-03);
  - catch de `onSwitchUnit` separando recusa (403) de falha de servidor (500) (T-04);
  - `aria-label` do seletor sem `labels`, seletor de unidade única e itens desabilitados sem `labels` (T-04);
  - botão de ajuda "Em breve" com tooltip visível (T-04);
  - itens desabilitados/seletor para grupos além do 1 (DML em T-01);
  - `catalogCriteria` único (T-05);
  - comentário de `listPending`/`STATUS_REQUESTED` (T-05);
  - docblock de `PayableList::resolveStatus` (T-05);
  - `ServiceForm` criando em um write (T-09, T-10);
  - testes de `ProductService` para outro tenant e para as mensagens (T-11);
  - literais `'low'`/`'out'` em `ProductList` (T-21);
  - estilos inline e `onEdit` vazio de `PrescriptionForm` (T-19);
  - docblock/validação de `onInlineAction` e vazio após Finalizar (T-18);
  - "Lançamentos recentes" no período e rótulo "vs. período anterior" (T-20);
  - mensagem em inglês de `CrossTenantReferenceException` nos 14 controllers (T-23).
- Edição de cadastros (bloco 2):
  - `TutorService::update` + `TutorForm` editável (T-06);
  - `PatientService::update` + `PatientForm` editável (T-07);
  - `AppointmentService::reschedule` + `AppointmentForm` editável (T-08);
  - ações "Editar paciente"/"Editar agendamento" na fila (T-17);
  - as listas e a busca global já abrem os formulários com `key`, que passam a ser editáveis.
- Campos com schema (bloco 3):
  - migration 0007 (T-01);
  - preço de venda e código do produto (T-11, exibidos em T-21);
  - alergia e foto do paciente (T-12, exibidas em T-18);
  - validade e modelos de prescrição (T-13, T-19);
  - forma de pagamento do lançamento (T-14, exportada em T-20);
  - contas bancárias com saldo (T-15, T-22, KPI em T-20);
  - pausa de atendimento (T-16, T-18).
- Ações (bloco 4):
  - Exportar CSV do financeiro (T-20);
  - Importar CSV, Duplicar e Excluir serviço (T-09, T-10);
  - Pausar/Retomar atendimento (T-16, T-18);
  - Salvar como modelo e Aplicar modelo na prescrição (T-13, T-19);
  - Gerar relatório PDF de estoque (T-21).
- Consolidação i18n (T-23) e validação final com varredura Playwright (T-24).

### Excluído
- Infra/framework: link "Trace" de `layout-basic.html`, Log do PHP vazio (error_log em stderr), CRLF de `layout.html`, `src/lib/adianti` e arquivos de `framework_hashes.php`.
- Preencher o preço de venda automaticamente em `SaleForm`, usar código de barras em leitor e histórico de preço.
- Tela de gestão de modelos de prescrição (listar/renomear/excluir): a aba "Modelos" do `CvNav` segue desabilitada, e o modelo é aplicado dentro de `PrescriptionForm`.
- Conciliação bancária, extrato e vínculo de lançamentos a conta bancária.
- Foto do paciente em listas (`PatientList`, busca global, fila): ela aparece só em `PatientForm` e no cabeçalho de `EncounterView`.
- Importar outros cadastros além de serviço, exportar outras telas além de `FinancialOverview` e relatório de vendas.
- Sugestões da triagem já aceitas como padrão ou premissa:
  - `StockBatchForm` lendo `$_GET`;
  - `.page-item.off` informativo;
  - evidência de tenant de T-16;
  - offset do `pageNavigation`;
  - cards montados à mão;
  - `VaccinationCardView` sem pista;
  - `actionMenu`/`columns` sem uso;
  - comparação com mocks ausentes;
  - dados de teste antigos;
  - RBAC antes de isActiveMember no desconto (ordem nova, T-36; antes a guarda de T-25 vinha antes do RBAC);
  - saldo do caixa sem saídas (schema não liga saídas ao caixa);
  - `GROUP_CONCAT` de `items_label`;
  - testes de integração sem ruído de T-05/T-06 da fase 10;
  - teste de UPDATE de `PayableRepositoryIntegrationTest` (informativo).
- Fixture real de tenant 2 no banco (exigiria DML).

## Contexto técnico
- Camadas envolvidas: database (migration 0007, DML de `system_program`/`system_group_program`), backend (`src/app/Core`: Domain, Application, Persistence, testes Unit/Integration e Fakes), frontend (controllers Adianti em `src/app/control/clinic`, kit `src/app/lib/widget`, template `src/app/templates/adminbs5`), qa (varredura Playwright).
- Projeto/base analisada: `/var/www/html/centralvet` (repositório git único; `git -C /var/www/html/centralvet rev-parse --show-toplevel` = `/var/www/html/centralvet`), código em `src/`, BASE `feat/fidelidade-visual-mocks` @ `d6dce7a`, suíte 205/205 (reviews/T-35.md, T-36.md da fase 10).
- Integrações: `CentralVet\Storage\S3CompatibleStorage::fromEnvironment($context)` (foto do paciente, precedente `EncounterView.php:1734`); dompdf (`\Dompdf\Dompdf`, precedentes `PrescriptionForm::onGeneratePdf` :716-750 e `SaleForm::onGenerateReceiptPdf` :189); `TFile` + `tmp/` (precedente `EncounterView.php:1126`, `ExamResultForm.php:77`).

## Baseline
- php-lint: `php -l` em todo `.php` de `app/control/clinic`, `app/lib/widget`, `app/Core` e `tests` (316 arquivos, via LINT com `sh -c` no container) em raiz → baseline/php-lint.txt (0 linhas). Sem erros prévios: o critério "nenhum erro novo em relação a `baseline/php-lint.txt`" equivale a `No syntax errors detected` em cada arquivo tocado.

## Exploração read-only
- Caminhos relevantes:
  - `src/app/database/migrations/` (última `20260925_0006_phase5_financial`, par `.verify.sql`, cabeçalho `PREPARED ONLY`/Effects/Risk e `INSERT INTO schema_migrations` com checksum placeholder), `src/app/database/migrations/README.md`, `docs/runbooks/migrations.md`, `docs/runbooks/migration-rollback.md`, `docs/architecture/adr/0003-versioned-mysql-migrations.md`;
  - `src/app/Core/README.md`;
  - `src/app/Core/Domain/{Product,Patient,Prescription,PrescriptionItem,FinancialEntry,Encounter,Tutor,Appointment,Service}.php`;
  - `src/app/Core/Application/{TutorService,PatientService,AppointmentService,ServiceCatalogService,ProductService,PrescriptionService,FinancialEntryService,PaymentService,EncounterService,StockSalesOverviewService,ClinicalSummaryService,FinancialOverviewService,EncounterAccountService}.php`;
  - `src/app/Core/Persistence/*Repository.php`: os de Tutor, Patient e Appointment já fazem UPDATE com id;
  - `src/app/Core/Authorization/AuthorizationRequest.php:25-42`;
  - `src/tests/Support/Fake*Repository.php`, `src/tests/Unit/*Test.php`, `src/tests/Integration/*IntegrationTest.php`, `src/tests/run.php` (descoberta automática de `Unit|Integration/*Test.php`, autoload próprio);
  - `src/app/control/clinic/`: `TutorForm` :38-76/157-186, `PatientForm` :47/175/251-305, `AppointmentForm` :44/99/172-207, `QueueEntryView` :88-92, `ServiceList` :119/138/327, `ServiceForm` :183-188, `ProductList` :71/100-104, `PrescriptionForm` :246-248/313-455/716, `EncounterView` :27/399-445/1352/1482-1528, `FinancialOverview` :32/49/166-188/207/247, `CvShellController` :35-135, `PendingExamResultList` :109, `PayableList` :183-187, `ProcedureInputForm` :341-358, `VaccineProtocolForm` :317-334;
  - `src/app/lib/widget/{CvPage,CvNav,CvKpiCard,CvFormat}.php`;
  - `src/app/templates/adminbs5/{layout.html:105,custom.css:299-302,js/cv-shell.js:138-159,232-235,297}`;
  - `src/app/config/translations.json` (685 chaves).
- Padrões identificados:
  - lógica em services do Core, nunca em TPage;
  - tenant vem do `TenantContext` injetado;
  - repositórios tenant-aware devolvem null para id de outro tenant;
  - update segue `ServiceCatalogService::update` (confere tenant, nome único exceto o próprio) e `PayableService::update` (`AuthorizationRequest` com `$action` = `__CLASS__ . '::' . __FUNCTION__`);
  - entidades novas seguem `Product::create/reconstitute`, repositórios estendem `AbstractTenantRepository` + `TenantQuery`, e todo repositório tem Fake em `tests/Support`;
  - controllers usam `resolveTenantContext()` + `buildXService()` por classe e `CvPage::header(title, subtitle, [spec])`;
  - `CvNav` com href `null` deixa a aba desabilitada;
  - PDF por `engine.php?class=X&method=Y&static=1&...` com `target=_blank`;
  - commits por caminho com trailer `Task: T-xx` e `Task: T-xx (RED)`.
- Scripts úteis: LINT, SUITE e GATE (em Premissas); `make backup`; `sha256sum`; `php -r 'chdir("/var/www/html/src"); require "init.php"; …'` no container para render de controller (precedente fase 10 `reports/T-31.md:34`).
- Riscos identificados:
  - DDL MySQL não é transacional;
  - testes de integração falham antes da migration aplicada;
  - adicionar parâmetro a `Product::create`/`reconstitute`, ao construtor de `Patient` ou a `FinancialEntry::record` quebra chamadores: parâmetros novos vão por último, com default;
  - `Fake*Repository` e interfaces de repositório são compartilhados: cada um fica numa só task por onda;
  - arquivos disputados: `EncounterView.php` (1779 linhas), `PrescriptionForm.php`, `translations.json`, `CvNav.php`, `CvPage.php`, `CvKpiCard.php` e os 14 controllers com `getMessage()` de `CrossTenantReferenceException`;
  - rebuild e Playwright são estado global.

## Mapa de arquivos

| Arquivo | Responsabilidade | Ação | Tasks que tocam |
|---|---|---|---|
| `src/app/database/migrations/20260930_0007_rodada2_cadastros_financeiro.sql` | DDL das colunas/tabelas novas + backfill de `payment_method` | criar | T-01 |
| `src/app/database/migrations/20260930_0007_rodada2_cadastros_financeiro.verify.sql` ⚠ | verificação só com SELECT; onda 8 (T-37) | criar | T-01, T-37 |
| `.claude/tasks/mar-20260930-0823-rodada-2-divida-cadastros-schema-acoes/sql/T-01-programs.sql` | DML: 3 programas novos e CvShellController para todos os grupos | criar | T-01 |
| `src/app/Core/Authorization/AuthorizationRequest.php` ⚠ | mensagem com `$action` escapado; onda 8 (T-36) | modificar | T-02, T-36 |
| `src/tests/Unit/AuthorizationRequestTest.php` ⚠ | teste da mensagem (RED); onda 8 (T-36) | modificar | T-02, T-36 |
| `src/app/Core/Application/EncounterAccountService.php` ⚠ | docblock de `applyDiscount`; onda 8 (T-36) | modificar | T-02, T-36 |
| `src/app/Core/Persistence/StockSalesOverviewReader.php` ⚠ | limite ≤ 0 (T-03); chaves `code`/`sale_price_cents` (T-11) | modificar | T-03, T-11 |
| `src/app/Core/Application/StockSalesOverviewService.php` ⚠ | `overview()` com uma varredura; limites; onda 8 (T-38) | modificar | T-03, T-38 |
| `src/tests/Integration/StockSalesOverviewIntegrationTest.php` ⚠ | limites e `overview()` (T-03); colunas novas (T-11) (RED) | modificar | T-03, T-11 |
| `src/app/Core/Persistence/ClinicalSummaryReader.php` | atendimento anterior por `started_at` | modificar | T-03 |
| `src/tests/Integration/ClinicalSummaryIntegrationTest.php` ⚠ | atendimento anterior (RED); onda 8 (T-37) | modificar | T-03, T-37 |
| `src/app/control/clinic/CvShellController.php` | 403 x 500 em `onSwitchUnit` | modificar | T-04 |
| `src/app/templates/adminbs5/js/cv-shell.js` ⚠ | fallback de rótulos, unidade única; onda 8 (T-39) | modificar | T-04, T-39 |
| `src/app/templates/adminbs5/layout.html` | botão de ajuda com `aria-disabled` | modificar | T-04 |
| `src/app/templates/adminbs5/custom.css` | estilo do ícone desabilitado sem `pointer-events: none` | modificar | T-04 |
| `src/app/lib/widget/CvPage.php` ⚠ | spec `'target' => '_blank'` e `data-cv-label` no slot de unidade; onda 8 (T-39) | modificar | T-04, T-39 |
| `src/app/lib/widget/CvCatalog.php` | critério "ativos + o atual" dos combos de catálogo | criar | T-05 |
| `src/app/control/clinic/ProcedureInputForm.php` ⚠ | usar `CvCatalog` (T-05); mensagem de tenant (T-23) | modificar | T-05, T-23 |
| `src/app/control/clinic/VaccineProtocolForm.php` ⚠ | usar `CvCatalog` (T-05); mensagem de tenant (T-23) | modificar | T-05, T-23 |
| `src/app/control/clinic/PendingExamResultList.php` | comentário com `ExamRequest::STATUS_REQUESTED` | modificar | T-05 |
| `src/app/control/clinic/PayableList.php` | docblock de `resolveStatus` | modificar | T-05 |
| `src/app/Core/Application/TutorService.php` ⚠ | `update()`; onda 8 (T-36) | modificar | T-06, T-36 |
| `src/tests/Unit/TutorServiceTest.php` ⚠ | testes de `update()` (RED); onda 8 (T-36) | modificar | T-06, T-36 |
| `src/app/control/clinic/TutorForm.php` | edição com `key` | modificar | T-06 |
| `src/app/Core/Application/PatientService.php` ⚠ | `update()` (T-07); alergia/foto (T-12); peso (T-27); onda 8 (T-32) | modificar | T-07, T-12, T-27, T-32 |
| `src/tests/Unit/PatientServiceTest.php` ⚠ | testes de `update()` (T-07), foto/alergia (T-12) e peso (T-27) (RED); onda 8 (T-32) | modificar | T-07, T-12, T-27, T-32 |
| `src/app/control/clinic/PatientForm.php` ⚠ | edição (T-07); alergia/foto/`onPhoto` (T-12); mensagem (T-23); máscara e erro de peso (T-27); onda 8 (T-32) | modificar | T-07, T-12, T-23, T-27, T-32 |
| `src/app/Core/Application/AppointmentService.php` | `reschedule()` e docblock | modificar | T-08 |
| `src/tests/Unit/AppointmentServiceTest.php` ⚠ | testes de `reschedule()` (RED); onda 8 (T-36) | modificar | T-08, T-36 |
| `src/app/control/clinic/AppointmentForm.php` ⚠ | edição (T-08); mensagem (T-23); onda 8 (T-29) | modificar | T-08, T-23, T-29 |
| `src/app/Core/Application/ServiceCatalogService.php` ⚠ | `create` com `active`, `duplicate`, `delete`, `importCsv` (T-09); limites por linha (T-26); onda 8 (T-33) | modificar | T-09, T-26, T-33 |
| `src/app/Core/Domain/Contract/ServiceRepositoryInterface.php` | `hasAppointments()` | modificar | T-09 |
| `src/app/Core/Persistence/ServiceRepository.php` | `hasAppointments()` | modificar | T-09 |
| `src/tests/Support/FakeServiceRepository.php` ⚠ | `hasAppointments()` no Fake (fixture do RED); onda 8 (T-33) | modificar | T-09, T-33 |
| `src/tests/Unit/ServiceCatalogServiceTest.php` ⚠ | testes das ações (T-09); limites (T-26) (RED); onda 8 (T-33) | modificar | T-09, T-26, T-33 |
| `src/app/control/clinic/ServiceList.php` ⚠ | Importar, Duplicar, Excluir; onda 8 (T-33) | modificar | T-10, T-33 |
| `src/app/control/clinic/ServiceForm.php` | create em um write | modificar | T-10 |
| `src/app/control/clinic/ServiceImportForm.php` ⚠ | upload do CSV (T-10); erro de banco sem mensagem crua (T-26); onda 8 (T-28) | criar/modificar | T-10, T-26, T-28 |
| `src/app/Core/Domain/Product.php` | `salePriceCents`, `code` | modificar | T-11 |
| `src/app/Core/Domain/Contract/ProductRepositoryInterface.php` | `findByCode()` | modificar | T-11 |
| `src/app/Core/Persistence/ProductRepository.php` | colunas novas | modificar | T-11 |
| `src/tests/Support/FakeProductRepository.php` ⚠ | `findByCode()` (fixture do RED); onda 8 (T-38) | modificar | T-11, T-38 |
| `src/app/Core/Application/ProductService.php` | create/update com preço e código | modificar | T-11 |
| `src/tests/Unit/ProductServiceTest.php` ⚠ | preço/código, outro tenant, mensagens (RED); onda 8 (T-38) | modificar | T-11, T-38 |
| `src/app/control/clinic/ProductForm.php` ⚠ | campos (T-11); mensagem (T-23) | modificar | T-11, T-23 |
| `src/app/Core/Domain/Patient.php` | `allergies`, `photoObjectKey`, `photoContentType` | modificar | T-12 |
| `src/app/Core/Persistence/PatientRepository.php` | colunas novas | modificar | T-12 |
| `src/tests/Support/FakePatientRepository.php` | sem mudança de contrato; ajuste se o Fake reconstruir `Patient` | modificar | T-12 |
| `src/tests/Support/FakeStorage.php` | storage em memória (fixture do RED; já existe) | modificar | T-12 |
| `src/app/Core/Domain/Prescription.php` | `validUntil` | modificar | T-13 |
| `src/app/Core/Persistence/PrescriptionRepository.php` | coluna `valid_until` | modificar | T-13 |
| `src/tests/Support/FakePrescriptionRepository.php` | sem mudança de contrato; ajuste se reconstruir | modificar | T-13 |
| `src/app/Core/Application/PrescriptionService.php` | `valid_until` em `create` | modificar | T-13 |
| `src/tests/Unit/PrescriptionServiceTest.php` | validade (RED) | modificar | T-13 |
| `src/app/Core/Domain/PrescriptionTemplate.php` ⚠ | modelo com itens; onda 8 (T-38) | criar | T-13, T-38 |
| `src/app/Core/Domain/Contract/PrescriptionTemplateRepositoryInterface.php` | contrato do repositório | criar | T-13 |
| `src/app/Core/Persistence/PrescriptionTemplateRepository.php` ⚠ | SQL do modelo e dos itens; onda 8 (T-38) | criar | T-13, T-38 |
| `src/tests/Support/FakePrescriptionTemplateRepository.php` | Fake (fixture do RED) | criar | T-13 |
| `src/app/Core/Application/PrescriptionTemplateService.php` | salvar/listar/buscar modelo | criar | T-13 |
| `src/tests/Unit/PrescriptionTemplateServiceTest.php` ⚠ | testes do serviço (RED); onda 8 (T-38) | criar | T-13, T-38 |
| `src/tests/Integration/PrescriptionTemplateRepositoryIntegrationTest.php` ⚠ | SQL do repositório (RED); onda 8 (T-38) | criar | T-13, T-38 |
| `src/app/Core/Domain/FinancialEntry.php` ⚠ | `paymentMethod`; onda 8 (T-35) | modificar | T-14, T-35 |
| `src/app/Core/Persistence/FinancialEntryRepository.php` | coluna `payment_method` | modificar | T-14 |
| `src/tests/Support/FakeFinancialEntryRepository.php` | sem mudança de contrato; ajuste se reconstruir | modificar | T-14 |
| `src/app/Core/Application/FinancialEntryService.php` | `record(..., ?string $paymentMethod = null)` | modificar | T-14 |
| `src/app/Core/Application/PaymentService.php` | repassa o método | modificar | T-14 |
| `src/tests/Unit/PaymentServiceTest.php` | lançamento com método (RED) | modificar | T-14 |
| `src/app/control/clinic/FinancialEntryForm.php` ⚠ | campo forma de pagamento; onda 8 (T-35) | modificar | T-14, T-35 |
| `src/app/control/clinic/FinancialEntryList.php` | coluna forma de pagamento | modificar | T-14 |
| `src/app/Core/Domain/BankAccount.php` | conta bancária | criar | T-15 |
| `src/app/Core/Domain/Contract/BankAccountRepositoryInterface.php` | contrato | criar | T-15 |
| `src/app/Core/Persistence/BankAccountRepository.php` | SQL | criar | T-15 |
| `src/tests/Support/FakeBankAccountRepository.php` | Fake (fixture do RED) | criar | T-15 |
| `src/app/Core/Application/BankAccountService.php` | CRUD e saldo total | criar | T-15 |
| `src/tests/Unit/BankAccountServiceTest.php` | testes do serviço (RED) | criar | T-15 |
| `src/tests/Integration/BankAccountRepositoryIntegrationTest.php` | SQL do repositório (RED) | criar | T-15 |
| `src/app/Core/Domain/Encounter.php` | `pause`/`resume`/`pausedSeconds` | modificar | T-16 |
| `src/app/Core/Persistence/EncounterRepository.php` | colunas `paused_at`/`paused_seconds` | modificar | T-16 |
| `src/tests/Support/FakeEncounterRepository.php` | sem mudança de contrato; ajuste se reconstruir | modificar | T-16 |
| `src/app/Core/Application/EncounterService.php` | `pause()`/`resume()` | modificar | T-16 |
| `src/tests/Unit/EncounterServiceTest.php` ⚠ | testes de pausa (RED); onda 8 (T-37) | modificar | T-16, T-37 |
| `src/app/control/clinic/QueueEntryView.php` ⚠ | ações Editar paciente/agendamento; onda 8 (T-29) | modificar | T-17, T-29 |
| `src/app/control/clinic/EncounterView.php` ⚠ | Pausar/Retomar, alergia/foto, `onInlineAction`, vazio após Finalizar (T-18); mensagem (T-23); onda 8 (T-31) | modificar | T-18, T-23, T-31 |
| `src/app/control/clinic/PrescriptionForm.php` ⚠ | validade, modelos, estilos e `onEdit` (T-19); mensagem (T-23); onda 8 (T-30) | modificar | T-19, T-23, T-30 |
| `src/app/templates/adminbs5/cv-components.css` ⚠ | classes que substituem os estilos inline da prescrição; onda 8 (T-31) | modificar | T-19, T-31 |
| `src/app/control/clinic/FinancialOverview.php` ⚠ | saldo bancário, Exportar, recentes no período, rótulo; onda 8 (T-35) | modificar | T-20, T-35 |
| `src/app/Core/Application/FinancialOverviewService.php` | `recentEntries` com período | modificar | T-20 |
| `src/app/Core/Persistence/FinancialOverviewReader.php` | `recentEntries` com período | modificar | T-20 |
| `src/tests/Integration/FinancialOverviewIntegrationTest.php` ⚠ | recentes no período (RED); onda 8 (T-37) | modificar | T-20, T-37 |
| `src/app/lib/widget/CvKpiCard.php` | `$deltaLabel` | modificar | T-20 |
| `src/app/control/clinic/ProductList.php` ⚠ | constantes, `overview()`, colunas, Gerar relatório; onda 8 (T-35) | modificar | T-21, T-35 |
| `src/app/control/clinic/BankAccountList.php` ⚠ | lista de contas; onda 8 (T-34) | criar | T-22, T-34 |
| `src/app/control/clinic/BankAccountForm.php` ⚠ | formulário e saldo; onda 8 (T-34) | criar | T-22, T-34 |
| `src/app/lib/widget/CvNav.php` | aba `bank_accounts` no grupo finance | modificar | T-22 |
| `src/app/config/translations.json` ⚠ | chaves do board (T-23); chaves de T-26/T-27 (T-27); onda 8 (T-28) | modificar | T-23, T-27, T-28 |
| `src/app/lib/widget/CvFormat.php` ⚠ | `userError()`; onda 8 (T-28) | modificar | T-23, T-28 |
| `src/app/control/clinic/EncounterAccountForm.php` | mensagem de tenant | modificar | T-23 |
| `src/app/control/clinic/ExamResultForm.php` | mensagem de tenant | modificar | T-23 |
| `src/app/control/clinic/ProcedureExecutionForm.php` | mensagem de tenant | modificar | T-23 |
| `src/app/control/clinic/VaccinationForm.php` | mensagem de tenant | modificar | T-23 |
| `src/app/control/clinic/ExamRequestForm.php` | mensagem de tenant | modificar | T-23 |
| `src/app/control/clinic/StockBatchForm.php` | mensagem de tenant | modificar | T-23 |
| `src/app/control/clinic/SaleForm.php` | mensagem de tenant | modificar | T-23 |
| `.claude/tasks/mar-20260930-0823-rodada-2-divida-cadastros-schema-acoes/reports/T-24.md` | relatório da validação final | criar | T-24 |
| `src/app/Core/Application/AgendaSlots.php` | horário → slot da grade da agenda | criar | T-25 |
| `src/tests/Unit/AgendaSlotsTest.php` | testes do mapeamento (RED) | criar | T-25 |
| `src/app/control/clinic/AgendaView.php` ⚠ | grade agrupada por `slotFor()`; horário exato no bloco; onda 8 (T-29) | modificar | T-25, T-29 |
| `src/app/Core/Presentation/UserMessage.php` | ver T-28 (onda 8) | criar | T-28 |
| `src/tests/Unit/UserMessageTest.php` | ver T-28 (onda 8) | criar | T-28 |
| `src/tests/Unit/CvFormatUserErrorTest.php` | ver T-28 (onda 8) | criar | T-28 |
| `src/app/Core/Application/QueueEntryService.php` | ver T-29 (onda 8) | modificar | T-29 |
| `src/tests/Unit/QueueEntryServiceTest.php` | ver T-29 (onda 8) | modificar | T-29 |
| `docker/nginx/default.conf` | ver T-32 (onda 8) | modificar | T-32 |
| `src/tests/Unit/EncounterAccountServiceTest.php` | ver T-36 (onda 8) | modificar | T-36 |
| `src/tests/Integration/ProductRepositoryIntegrationTest.php` | ver T-37 (onda 8) | criar | T-37 |
| `src/tests/Integration/FinancialEntryRepositoryIntegrationTest.php` | ver T-37 (onda 8) | criar | T-37 |
| `src/tests/Integration/EncounterRepositoryIntegrationTest.php` | ver T-37 (onda 8) | criar | T-37 |
| banco `centralvet` (schema e `system_program`/`system_group_program`) | aplicar 0007 e o DML de T-01 | DDL/DML pelo orquestrador, com aprovação | T-01 |

Os 15 arquivos com ⚠ são serializados em ondas diferentes: T-03, T-05, T-07, T-08 e T-09 (onda 1), T-10, T-11 e T-12 (onda 2), T-26 e T-27 (onda 7, disjuntos entre si), T-18 e T-19 (onda 3), T-23 (onda 4). Em cada onda, cada arquivo está numa só task. `translations.json` tem escritor único (T-23). `FakeStorage.php` e os Fakes de Patient, Prescription, FinancialEntry e Encounter só são tocados pela task dona da entidade. `CvPage.php` só por T-04, `CvKpiCard.php` só por T-20 e `CvNav.php` só por T-22.

## Decisões de arquitetura

| Decisão | Alternativas consideradas | Motivo da escolha |
|---|---|---|
| Uma migration 0007 com todos os campos, redigida na onda 1 e aplicada pelo orquestrador antes da onda 2 | Uma migration por feature | Uma aprovação, um backup, um `.verify.sql`; numeração sem disputa entre agentes |
| Pausa por `encounter.paused_at`/`paused_seconds`, sem novo status | Status `paused` recriando o CHECK | Evita DROP/ADD de CHECK (DDL não transacional) e não mexe nas telas que filtram por `in_progress` |
| Foto em storage S3 com `photo_object_key`, servida por `PatientForm::onPhoto` | FK para `stored_object`; URL pré-assinada | Nada no projeto grava `stored_object` (`EncounterDocumentService::list()` sempre vazio); a URL pré-assinada aponta para o host interno do storage |
| `payment_method` nulo para lançamentos manuais e de contas a pagar, com backfill só de `reference_type = 'payment'` | Obrigatório; migrar `category` | `category` continua alimentando o donut e `CvFormat::paymentMethod`; nenhum relatório existente muda |
| Saldo bancário informado à mão em `bank_account` | Derivar de lançamentos | O schema não liga lançamentos a conta; conciliação está fora do escopo |
| Parâmetros novos de entidade/serviço por último e com default | Reescrever assinaturas | Mantém os 4 chamadores de `Product::`/`FinancialEntry::` e de `new Patient(` sem mudança |
| CSV (Exportar/Importar) e PDF por dompdf (Gerar relatório) | TTableWriterXLS do vendor | Sem dependência nova; dompdf já é usado em 2 telas |
| Mensagem de `CrossTenantReferenceException` por `CvFormat::userError()` numa task única da onda 4 | Corrigir em cada task dona | Os 14 controllers colidem com as features das ondas 1–3; a varredura fica mecânica e revisável |

## Diagrama de dependências

```text
Onda 1: T-01 migration+DML | T-02 authz | T-03 readers | T-04 casca | T-05 catálogos | T-06 tutor | T-07 paciente | T-08 agendamento | T-09 serviço Core
   ── orquestrador aplica 0007 + DML (aprovação do usuário) ──
Onda 2: T-10 (T-01,T-09) serviços UI | T-11 (T-01) produto | T-12 (T-01,T-07) foto/alergia | T-13 (T-01) prescrição Core
        T-14 (T-01) forma de pagamento | T-15 (T-01) conta bancária Core | T-16 (T-01) pausa Core | T-17 (T-06,T-07,T-08) fila
Onda 3: T-18 (T-03,T-12,T-16) EncounterView | T-19 (T-13) PrescriptionForm | T-20 (T-04,T-14,T-15) FinancialOverview
        T-21 (T-03,T-04,T-11) ProductList | T-22 (T-01,T-15) contas bancárias UI
Onda 4: T-23 (ondas 1–3) i18n + mensagens de tenant
Onda 5: T-24 (todas) validação final e varredura
Onda 6 — correção (usuário): T-25 AgendaView slot (em paralelo a T-24)
Onda 7 — correção (code-review): T-26 importCsv limites | T-27 peso do paciente
Onda 8 — correção (usuário): T-28 mensagens/i18n | T-29 check-in | T-30 prescrição | T-31 atendimento | T-32 paciente/foto | T-33 serviços
        T-34 contas | T-35 financeiro/estoque | T-36 authz/tutor/agenda | T-37 integração | T-38 modelos/produto | T-39 CvPage/seletor
```

## Estratégia de execução
- Branch de trabalho: `feat/rodada-2-cadastros-schema-acoes`
- Branch base: `feat/fidelidade-visual-mocks`
- Nota de branch: criada pelo orquestrador antes da onda 1 a partir de `feat/fidelidade-visual-mocks` @ `d6dce7a`; todos os agentes trabalham nela, no checkout compartilhado, sem trocar de branch.
- Commits da onda: cada implementador commita os próprios caminhos (`git -C /var/www/html/centralvet add <caminhos>` + `git -C /var/www/html/centralvet commit -m "<assunto>" -m "Task: T-xx" -- <caminhos>`). Nas tasks com teste, o commit do teste falhando (só os arquivos do bloco Teste RED) leva `Task: T-xx (RED)` e vem antes da implementação. Proibidos: `git add -A`/`.`, `commit -a`, `checkout`, `switch`, `reset`, `stash`, `restore`, `rebase`, `push`. Commit que falhar por `index.lock` é repetido após alguns segundos. O fechador usa a skill `new-commit --auto` só se sobrou algo sem commit e sempre registra o estado das tasks num `chore(tasks): registra commits da onda N`.
- Isolamento em ondas com edições paralelas: caminho exclusivo (cada agente só nos arquivos da própria task no checkout compartilhado; nenhum arquivo é tocado por duas tasks na mesma onda; rebuild do container e navegador Playwright são estado global — só o orquestrador reconstrói e só o validador usa o navegador, no gate)
- Migration e DML (T-01): o agente redige os 3 arquivos e para. Entre a onda 1 e a onda 2, o orquestrador:
  1. mostra ao usuário objetos, efeito e risco (skill `sql-write-approval`);
  2. roda `make backup` e `gzip -t`;
  3. roda `sha256sum` e substitui o placeholder;
  4. aplica a 0007 com o usuário de migration e roda o `.verify.sql`;
  5. roda o DML `sql/T-01-programs.sql` com aprovação e confere por SELECT;
  6. registra em `notes.md § Bloqueios`.
  Sem aprovação, as ondas 2–3 ficam bloqueadas; só T-17 não depende do schema nem do DML (T-10 precisa do programa `ServiceImportForm`).
- Gate de cada onda: rebuild pelo orquestrador; o validador confere trailer, escopo e ordem RED por `git -C /var/www/html/centralvet log <BASE da onda>..HEAD`, roda LINT dos arquivos da onda, SUITE, os passos de Validação e a varredura Playwright da onda (`§ Critérios gerais de aceite`).

## Ondas de execução

### Onda 1
- T-01, T-02, T-03, T-04, T-05, T-06, T-07, T-08, T-09

### Onda 2
- T-10, T-11, T-12, T-13, T-14, T-15, T-16, T-17

### Onda 3
- T-18, T-19, T-20, T-21, T-22

### Onda 4
- T-23

### Onda 5
- T-24

### Onda 6 — correção (usuário)
- T-25 — bug da `AgendaView` achado na QA de T-24 (`reports/T-24.md`). Sem dependência de código (ondas 1–4 fechadas); roda em paralelo a T-24, que não toca a `AgendaView`.

### Onda 7 — correção (code-review)
- T-26, T-27 — achados do `/code-review` da branch, depois da revisão final. São independentes, com arquivos disjuntos; T-27 é o escritor único de `translations.json` na onda (inclui as 3 chaves fixadas por T-26).

### Onda 8 — correção (usuário)
- T-28, T-29, T-30, T-31, T-32, T-33, T-34, T-35, T-36, T-37, T-38, T-39 — pendências da rodada (`notes.md § Pendências`, `reviews/final.md § Triagem`/`Pós-revisão final`) e check-in da fila. BASE `cbd7ad1`. As 12 tasks são independentes, com arquivos disjuntos. T-28 é o escritor único de `translations.json` e já grava as chaves que T-29, T-30, T-33 e T-34 usam (lista fixada na Interface de T-28). `CvFormat::userError` mantém a assinatura: as tasks que passam a chamá-lo não dependem de T-28 para compilar, só para a tradução aparecer.

## Agentes

| Agente | subagent_type | model | Tasks |
|---|---|---|---|
| Sun Tzu — orquestrador | — | — | todas |
| Jaspion | general-purpose | inherit | T-01 |
| Arquimedes | general-purpose | inherit | T-02, T-14, T-36 |
| Sherlock | general-purpose | inherit | T-03, T-25, T-37 |
| Aang | general-purpose | inherit | T-04, T-17, T-29 |
| Levi | general-purpose | inherit | T-05, T-10, T-26, T-33 |
| Thanos | general-purpose | inherit | T-06, T-39 |
| Naruto | general-purpose | inherit | T-07, T-27, T-32 |
| Kratos | general-purpose | inherit | T-08, T-19, T-30 |
| Platão | general-purpose | inherit | T-09, T-13, T-23, T-28 |
| Darwin | general-purpose | inherit | T-11, T-21, T-38 |
| Tesla | general-purpose | inherit | T-12, T-20, T-35 |
| Athena | general-purpose | inherit | T-15, T-22, T-34 |
| Yoda | general-purpose | inherit | T-16, T-18, T-31 |
| Spock — validador | geduc:validador | sonnet | T-24, gates das ondas 1–8 |

## Review Focus
- Dois cliques em Check-in do mesmo agendamento na Agenda → uma única entrada em `queue_entry` e a segunda tentativa mostra "Este agendamento já está na fila" → T-29
- Conta bancária com nome `<b>R2</b>` repetida na unidade → a mensagem mostra as tags como texto (escape de `CvFormat::userError`), sem HTML interpretado → T-28
- `PrescriptionForm` aberto sem `patient_id`/`encounter_id` → Salvar mostra "Abra a prescrição pelo atendimento", nenhuma prescrição gravada, nenhum texto SQLSTATE → T-30
- Troca da foto do paciente → chave nova em `photo_object_key`, objeto anterior removido do storage e a pré-visualização mostra a foto nova sem esperar o cache → T-32
- Autosave do `EncounterView` depois da troca de `id` por `encounter_id` → o rascunho é gravado no atendimento aberto e a tela não cai no vazio "Informe um encounter_id" → T-31

## Critérios gerais de aceite
- SUITE termina com `Failed: 0` e `Total` ≥ 205 + os testes novos de cada onda, no gate de cada onda.
- LINT de todo arquivo PHP tocado imprime `No syntax errors detected` (nenhum erro novo em relação a `baseline/php-lint.txt`, 0 linhas).
- Depois da migration, `SELECT COUNT(*)` de `product`, `patient`, `prescription`, `financial_entry` e `encounter` é igual antes e depois (registrado pelo orquestrador ao aplicar), e `SELECT COUNT(*) FROM financial_entry WHERE reference_type = 'payment' AND payment_method IS NULL AND category IN ('cash','debit_card','credit_card','pix','bank_transfer')` = 0.
- Commits (todo gate): `git -C /var/www/html/centralvet log --format='%h %s%n%b' <BASE da onda>..HEAD` mostra só commits com trailer `Task: T-xx` de tasks da onda (ou `chore(tasks)` restrito a `.claude/tasks/`); `git show --stat` de cada um lista só caminhos de "Arquivos prováveis" da task; nas tasks com teste o commit `Task: T-xx (RED)` toca só os arquivos do bloco Teste RED e vem antes de todo commit `Task: T-xx` da implementação.
- Varredura Playwright no gate: o validador percorre com o Playwright MCP (sessão admin) cada tela tocada na onda. Em cada uma exercita abrir, listar, filtrar, abrir registro, salvar (registros `R2 varredura`) e voltar. Depois de cada tela lê `browser_console_messages` (nível `error`) e `browser_network_requests` (≥ 400, exceto `favicon`). Critério: tabela `Tela | Fluxos | Console (errors) | Rede (≥400) | Erro na tela | Veredito | Task dona` com 0/0/nenhum em cada linha. Telas por onda:
  - Onda 1: `ServiceList` e `ProductList` (casca, seletor de unidade, ajuda "Em breve"), `TutorForm`, `PatientForm` e `AppointmentForm` (editar e salvar registro `R2 varredura`), `ProcedureInputForm`, `VaccineProtocolForm`, `PendingExamResultList`, `PayableList`;
  - Onda 2: `ServiceList` (Importar, Duplicar, Excluir), `ServiceImportForm`, `ServiceForm`, `ProductForm`, `PatientForm` (foto e alergia), `FinancialEntryForm`, `FinancialEntryList`, `PaymentForm` (Review Focus T-14), `QueueEntryView`;
  - Onda 3: `EncounterView` (Pausar/Retomar, alergia/foto, Finalizar), `PrescriptionForm` (validade, Salvar como modelo, Aplicar modelo, PDF), `FinancialOverview` (Exportar, período, KPI de saldo), `ProductList` (colunas, Gerar relatório), `BankAccountList`, `BankAccountForm`;
  - Onda 4: todas as telas das linhas `i18n:` do board, sem "Message not found";
  - Onda 5 (T-24): todas as telas acima e as do `menu.xml`.
  - Onda 8 — correção (usuário) (T-28..T-39): `AgendaView` (Check-in), `QueueEntryView` (menu Editar paciente/agendamento de T-17), `AppointmentForm` (key 999999), `PrescriptionForm` (sem patient_id, modelo no combo, remover item), `EncounterView` (foto/alergia, autosave, anexo, pausa de finalizado), `PatientForm` (foto, key 999999, sexo adulterado), `ServiceList` (Duplicar com confirmação, Excluir), `ServiceImportForm`, `BankAccountForm`, `BankAccountList`, `FinancialOverview`, `FinancialEntryForm`, `ProductList` (relatório), `VaccineProtocolForm` ("dias"), `ServiceList`/`ProductList` (seletor). Header `X-Content-Type-Options` único na foto.
  - Onda 7 — correção (code-review) (T-26, T-27): `ServiceImportForm` (CSV com limites) e `ServiceList` depois da importação, `PatientForm` (peso com vírgula, texto forçado, salvar sem mexer no peso).
  - Onda 6 — correção (usuário) (T-25): `AgendaView` nas datas dos agendamentos 1 e 3 (navegar entre os dias, abrir um bloco, voltar).
