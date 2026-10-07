# Plano: Fase 10 — Fidelidade visual aos mocks

## Objetivo
Levar o Central Vet Pro do layout adminbs5 padrão (trilho duplo, "Shortcut", rodapé de desenvolvedor, formulários em painel lateral, TDataGrid cru) ao visual dos mocks: casca única (sidebar escura em lista, topbar com busca/sino/ajuda/cartão do usuário, seletor de unidade, rodapé da marca), um kit de componentes reutilizável e as 5 telas com mock (Prescrições, Estoque e Vendas, Financeiro, Serviços, Atendimento) reproduzidas com dados reais; as demais ~35 telas herdam o padrão. IA fica oculta.

## Premissas
- Escopo decidido pelo usuário: "Tudo menos IA". Blocos de IA ("Resumo do paciente", "Próximos passos", "Organizar com IA", "Sugerir diagnósticos") ficam ocultos, sem conteúdo falso.
- Nenhuma migration nem mudança de schema. Campo que o mock mostra e o schema não tem (preço de venda, código/barcode e imagem de produto, foto/alergia/"ativo" do paciente, validade/modelos/anexos/obs. por item da prescrição, descrição/preparo/observações de serviço, forma de pagamento/status em `financial_entry`, saldo bancário) é omitido ou vira placeholder neutro (avatar com inicial/ícone de espécie) e entra em `notes.md § Pendências`. "Saldo em conta" vira "Saldo do caixa aberto" (sessão de caixa aberta da unidade), rotulado como tal.
- Itens de menu e abas sem tela real (Dashboard clínico, Prontuário, Cirurgias, CRM/Comunicação, Relatórios, Ajuda; abas Movimentações/Categorias/Fornecedores/Relatórios/Pacotes/Preçários/Modelos) aparecem desabilitados (cinza, "em breve", sem abrir nada) — resposta do usuário.
- Financeiro: tela nova `FinancialOverview` (aba "Visão geral"). Registro em `system_program`/`system_group_program` (e o de `CvShellController`, necessário ao seletor de unidade e ao cartão do usuário) vai redigido em T-07 e só é executado após aprovação do SQL pelo usuário na hora; últimos ids conhecidos 102/103 (fase 09) — T-07 confirma com `SELECT MAX(id)`.
- "Prescrições" e "Pacientes" no menu: `PrescriptionForm` só existe no contexto do atendimento (a fase 09 removeu o item solto por ser beco sem saída) → item "Prescrições" desabilitado com dica "abra pelo atendimento"; `PatientList` exige `tutor_id` → item "Pacientes" abre `GlobalSearchController` (busca de paciente/tutor). "Atendimentos" → `QueueEntryView`; "Exames" → `PendingExamResultList`; "Vacinação" → `VaccinationCardView`.
- Projeto em git desde 2026-09-29 (decisão do usuário): repositório único `/var/www/html/centralvet`, branch `main`, commit inicial `9efef4e` ("chore: estado inicial do projeto"). Trabalho na branch `feat/fidelidade-visual-mocks` (base `main` @ `9efef4e`); um ou mais commits por task com trailer `Task: <ID>`, listando só os caminhos da task. Nas tasks com teste (T-04, T-05, T-06, T-17) o teste falhando é commitado antes da implementação com trailer `Task: <ID> (RED)`; as demais seguem `sem teste: <motivo>`. Evidência detalhada continua em `reports/`.
- A imagem do container copia `src/` (COPY). Suíte e lint rodam contra o código-fonte do host sem rebuild: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app …` (comprovado no planejamento: 155/155). Verificação visual exige `docker compose build app worker && docker compose up -d app worker && docker compose restart nginx`, feita só pelo orquestrador no gate de cada onda; o navegador Playwright MCP (sessão admin logada) é estado global e só o validador o usa.
- Ondas 7–8 — correção (usuário), pedidas em 2026-09-29 a partir de `reviews/final.md` (Triagem, Achados, Rodada 2), `reports/final-fix.md` e `notes.md § Pendências`. BASE `2c7611d`, na mesma branch `feat/fidelidade-visual-mocks`. Premissas fechadas pelo orquestrador ou pelo usuário:
  - (a) formato de `$action` em `AuthorizationRequest`: `Classe::metodo` (inclusive namespaced e com a classe minúscula, como `test::action`) ou chave pontuada (`patient.view`); o resto é recusado (T-27);
  - (b) `PayableList` ganha o filtro Em aberto (padrão) / Pagas / Todas; `listOpen()` continua "em aberto" (resposta do usuário, T-28);
  - (c) i18n: uma chave canônica por par de caixa, com os usos migrados na mesma task que remove a variante (resposta do usuário, T-36, onda 8, depois das tasks que tocam os mesmos controllers);
  - (d) `StockBatchForm` lendo `$_GET['product_id']` com fallback em `$param` é o padrão do projeto (`paramInt()` em ~9 controllers): fica sem task;
  - (e) "units sem `current`" quando a unidade da sessão não pertence ao tenant já é tratado pelo placeholder "—" do seletor (`cv-shell.js`): fica sem task;
  - (f) itens desabilitados visíveis só ao grupo 1 exigem DML em `system_group_program`, decisão de produto: fora;
  - (g) os 4 PNGs soltos na raiz (`f10-*.png`) são evidência de T-21/T-22..T-24 e o fechador da onda 7 os move para `reports/`, sem task de código.
- Métodos `EncounterView::onFinish`, `onAutosave`, `onStart` não mudam de nome (strings de auditoria fixas em `tests/Integration/EncounterTimelineIntegrationTest.php:69-86`).

## Escopo

### Incluso
- (a) Casca: `layout.html` (sidebar única em lista com ícone+rótulo, logo pata + "CENTRAL VET PRO", topbar com hambúrguer, busca global ligada ao `GlobalSearchController`, sino, ajuda, cartão do usuário com nome + papel/grupo e dropdown, rodapé da marca sem links de desenvolvedor, sem "Shortcut"), `application.php` (`has_master_menu` = 0 e flags de navbar), cache-busting de `custom.css` (`{buildid}` real), seletor de unidade no cabeçalho da página, `menu.xml` reorganizado (itens admin do Adianti e catálogos dentro de Configurações; Configurações e Ajuda fixos no rodapé da sidebar; itens sem tela desabilitados).
- (b) Kit de componentes reutilizável (PHP em `app/lib/widget/Cv*.php` + `cv-components.css`): cabeçalho de página, abas, cards, KPI, badges, tabela com checkbox e menu "…", rodapé "Mostrando X–Y de N" com paginação estilizada, formulário em página cheia com grid de colunas, avatar placeholder, wizard.
- (c) Telas com mock: `PrescriptionForm`, `ProductList` (Estoque e Vendas), `FinancialOverview` (nova), `ServiceList` (tabela + painel de detalhe), `EncounterView` (cabeçalho do paciente, timer, Imprimir/Finalizar, wizard de 5 etapas, sinais vitais, plano clínico com abas, histórico, resumo financeiro).
- Consultas de leitura novas por tenant para os indicadores (estoque/vendas, financeiro, resumo clínico), com teste de integração.
- (d) As demais telas de `src/app/control/clinic/` no padrão (b): formulários em página cheia (sem `adianti_right_panel`), campos relacionais por `TDBCombo`/`TDBUniqueSearch` com filtro de tenant onde hoje há `TEntry` de id, listas com badges, "…" e rodapé de paginação.
- Vocabulário novo em `translations.json`.
- DML de registro de `FinancialOverview` e `CvShellController` (aprovação na hora).
- (f) Onda 6 — correção dos achados de `reports/T-21.md` aprovada pelo usuário em 2026-09-29: B1 (carga duplicada do Chart.js no `layout.html` → TypeError no `SystemAdministrationDashboard`, T-22), O1 (status crus `draft`/`result_available` nas abas do plano do `EncounterView`, T-23), O2 (categoria crua `cash` em `FinancialOverview`/`FinancialEntryList`, T-24), mais a validação no gate dos fluxos que T-21 não exercitou (fechar caixa, salvar `PaymentForm`, salvar os 3 formulários de catálogo).
- (e) Correções apontadas no gate/revisão da onda 2, na task da onda 3 dona de cada arquivo: `ServiceForm` atualiza quando há `id` e carrega por tenant, filtro "Inativo" de `ServiceList` e PATTERN0 (T-17); aba Receitas com `entry_type=income` (T-18); unidades por tenant em `CvShellController` e CSS de `.cv-unit-switch__select` (T-19); PATTERN0 nos formulários de T-15, T-16 e T-18.
- (g) Ondas 7–8 — correção (usuário): (A) segurança: autorizador do desconto revalidado no save (T-25), troca de unidade só por POST com token e `onContext` sem mensagem de exceção (T-26), formato de `$action` (T-27); (B) bugs: filtro de status e mensagem pt em contas a pagar, teste de SQL do UPDATE (T-28), `&query=` na busca global (T-29), badge para status vazio (T-30); (C) limpeza: constantes `STATUS_*`/`METHOD_*` (T-30, T-31), comentários obsoletos (T-32), link focável e combos de catálogo (T-33), `ProductService::update()` (T-34), "Ver tudo", erro de tenant e PDF em nova aba (T-35), textos pt do `cv-shell.js` (T-26), chaves i18n unificadas (T-36).
- Validação visual lado a lado com os 4 mocks + regressão da suíte.

### Excluído
- Qualquer conteúdo ou bloco de IA visível.
- Migrations, colunas novas, tabelas de modelos de prescrição, anexos de prescrição, foto/alergia de paciente, preço de venda/código de produto.
- Telas novas vazias ou falsas (Dashboard clínico, Prontuário, Cirurgias, CRM, Relatórios, Ajuda, Categorias, Fornecedores, Pacotes, Preçários).
- Ações sem suporte no backend: Exportar, Importar, Duplicar/Excluir serviço, Pausar atendimento, Salvar como modelo, Gerar relatório.
- Reestilização das telas administrativas nativas do Adianti (só mudam de lugar no menu) e da tela de login.
- Ondas 7–8: evidência/QA de exames, agenda e fila; dados "F10 varredura" no banco; seletor de unidade no `EncounterView`; comparação com os mocks; itens sem schema/backend já fora; DML para mostrar itens desabilitados a outros grupos; `products()` varrido 3x em `ProductList` (exige mudar o contrato de leitura de T-04); offset do `pageNavigation` e cards montados à mão nos catálogos (T-16); pista de vacina e `actionMenu`/`columns` sem uso (T-15); estilos inline e `onEdit` vazio de `PrescriptionForm` (T-12); título/KPI de estoque (T-10); `StockBatchForm` com `$_GET` (padrão do projeto).
- Mudança de regra de negócio em Application/Domain existentes (as regras de `create()`/`listActive()` ficam como estão; `ServiceCatalogService::update()`/`listAll()` e `Service::changeDetails()` são casos de uso novos de T-17, pedidos pela correção da onda 2).

## Contexto técnico
- Camadas envolvidas: frontend (layout/CSS/JS do template adminbs5, controllers Adianti em `app/control/clinic`, kit em `app/lib/widget`), backend (novas classes de leitura em `app/Core/Persistence` e `app/Core/Application`), database (DML de `system_program`, sem schema), infra (`docker/php/Dockerfile` para o `buildid`), qa (validação visual Playwright).
- Projeto/base analisada: `/var/www/html/centralvet` (repositório git único; `git -C /var/www/html/centralvet rev-parse --show-toplevel` = `/var/www/html/centralvet`), código em `src/`. Ondas 7–8: BASE `2c7611d`, suíte 186/186 (148 unit) no final-fix.
- Integrações: Chart.js já presente em `src/lib/independent/js/chart.umd.min.js` (não carregado hoje); `GlobalSearchController::onSearch` (`query`); `ApplicationAuthenticationService::setUnit()` (`multiunit` = 1).

## Baseline
- nenhuma — `php -l` em `app/control/clinic`, `app/lib/widget`, `app/Core/Application` e `app/Core/Persistence` (no container) sem erros prévios: `baseline/php-lint.txt` com 0 linhas; suíte `tests/run.php` contra o código do host: `Total: 155, Passed: 155, Failed: 0`.

## Exploração read-only
- Caminhos relevantes: `src/app/templates/adminbs5/layout.html` (256 linhas; `{MENU}` :74, `{MENUTOP}` :87, cartão do usuário :121, rodapé :157-219), `src/app/templates/adminbs5/custom.css` (304 linhas; tokens :5-56, sidebar :79, `.cv-page-header` :107, `.cv-section` :133), `src/app/templates/adminbs5/js/theme.js` (`generateMasterMenu` :319, `setNavbarOptions` :784, right panel :101-255), `src/app/config/application.php` (tema :16, navbar :76-94), `src/lib/adianti/core/AdiantiTemplateParser.php:61` (`{buildid}` só com arquivo `src/buildid`), `src/menu.xml` (234 linhas), `src/app/config/translations.json` (587 pares en/pt), `src/app/control/clinic/*.php` (39 telas), `src/app/lib/widget/` (só `TAccordion.php`), `src/app/Core/Persistence/{AbstractTenantRepository,TenantQuery,FinancialEntryRepository,PrescriptionRepository}.php`, `src/app/service/auth/ApplicationAuthenticationService.php:50` (`setUnit`), `src/lib/independent/js/chart.umd.min.js`, `src/tests/Support/MysqlIntegrationTestCase.php`, `docker/php/Dockerfile:63-76`.
- Padrões identificados: listas estendem `TStandardList` com `BootstrapDatagridWrapper` + `TPageNavigation` (paginação em memória por `array_slice`); 21 formulários chamam `parent::setTargetContainer('adianti_right_panel')`; filtros de lista abrem em cortina no right panel; `resolveTenantContext()` duplicado em cada controller + `buildXService()` estático com `TTransaction::get()`; precedente de `TDBUniqueSearch`/`TDBCombo` com `TFilter('tenant_id','=',$tenant_id)` em `AppointmentForm.php:49-66`, `SaleForm.php:99-160`, `VaccinationCardView.php:125-139`; `SystemUser` sem filtro de tenant (exceção documentada); `AdiantiApplicationLoader` carrega `app/lib` recursivamente (classe nova em `app/lib/widget` dispensa registro); PSR-4 `CentralVet\` → `app/Core`; README do Core: lógica em services, nunca em TPage; `TenantQuery` só `andEquals` — faixas via SQL após `whereSql()` (ver `FinancialEntryRepository::listBySystemUnitAndPeriod`).
- Scripts úteis: suíte `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php` (sem filtro por arquivo; saída `Total: N, Passed: N, Failed: 0`); lint `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php -l <arquivo>`; rebuild `docker compose build app worker && docker compose up -d app worker && docker compose restart nginx`; Playwright MCP em `http://127.0.0.1:8081`, screenshots só em `/var/www/html/centralvet/.playwright-mcp/`.
- Riscos identificados: `layout.html`, `custom.css`, `menu.xml`, `translations.json`, `cv-shell.js` são arquivos únicos disputados → escritor único por onda, serializados; `EncounterView.php` (1328 linhas) concentra autosave (`setInterval` 20 s → `onAutosave`), ditado por voz, `__adianti_goto_page` (5 ações inline + recarga) e os fixes de `TButton` da fase 08; adicionar método a `*RepositoryInterface` obriga editar Fakes → consultas novas vão em classes de leitura separadas; menu builder oculta item cujo programa não está em `system_program`; rebuild/`up -d` e o navegador Playwright são estado global → só no gate.

## Mapa de arquivos

| Arquivo | Responsabilidade | Ação | Tasks que tocam |
|---|---|---|---|
| `src/app/templates/adminbs5/layout.html` | casca: sidebar, topbar, cartão do usuário, rodapé, links de CSS/JS | modificar | ⚠ T-01 (onda 1), T-22 (onda 6: remove a carga duplicada de `chart.umd.min.js`) |
| `src/app/templates/adminbs5/custom.css` | estilos da casca e do menu | modificar | ⚠ T-01 (onda 1), T-19 (onda 3) |
| `src/app/config/application.php` | flags de navbar (`has_master_menu` = 0 etc.) | modificar | T-01 |
| `docker/php/Dockerfile` | gerar `src/buildid` na imagem | modificar | T-01 |
| `src/app/templates/adminbs5/cv-components.css` | estilos do kit | criar | T-02 |
| `src/app/lib/widget/CvPage.php` | cabeçalho, colunas, abas, barra de filtro | criar | T-02 |
| `src/app/lib/widget/CvNav.php` | abas de navegação por grupo (finance, stock, services, prescription) | criar/modificar | ⚠ T-02 (onda 1), T-18 (onda 3: aba Receitas → `entry_type=income`) |
| `src/app/lib/widget/CvBadge.php` | badge de status por tom | criar | T-02 |
| `src/app/lib/widget/CvKpiCard.php` | card de indicador com variação | criar | T-02 |
| `src/app/lib/widget/CvCard.php` | card com título e link "Ver todos" | criar | T-02 |
| `src/app/lib/widget/CvDatagrid.php` | tabela com checkbox, menu "…", rodapé de paginação | criar | T-02 |
| `src/app/lib/widget/CvForm.php` | formulário página cheia em grid de colunas | criar | T-02 |
| `src/app/lib/widget/CvAvatar.php` | avatar placeholder (inicial/ícone de espécie) | criar | T-02 |
| `src/app/lib/widget/CvWizard.php` | etapas numeradas | criar | T-02 |
| `src/app/lib/widget/CvFormat.php` | dinheiro, variação percentual e rótulo de forma de pagamento | criar/modificar | ⚠ T-02 (onda 1), T-24 (onda 6: `paymentMethod()`), T-31 (onda 7: `Payment::METHOD_*`) |
| `src/app/config/translations.json` | vocabulário en/pt | modificar | ⚠ T-03 (onda 1), T-20 (onda 4), T-36 (onda 8: pares de caixa e 7 chaves novas) |
| `src/app/Core/Persistence/StockSalesOverviewReader.php` | SQL de indicadores de estoque/vendas | criar | T-04 |
| `src/app/Core/Application/StockSalesOverviewService.php` | fachada de leitura estoque/vendas | criar | T-04 |
| `src/tests/Integration/StockSalesOverviewIntegrationTest.php` | teste de integração | criar | T-04 |
| `src/app/Core/Persistence/FinancialOverviewReader.php` | SQL de indicadores financeiros | criar | T-05 |
| `src/app/Core/Application/FinancialOverviewService.php` | fachada de leitura financeira | criar | T-05 |
| `src/tests/Integration/FinancialOverviewIntegrationTest.php` | teste de integração | criar | T-05 |
| `src/app/Core/Persistence/ClinicalSummaryReader.php` | SQL de resumo clínico do paciente/atendimento | criar | T-06 |
| `src/app/Core/Application/ClinicalSummaryService.php` | fachada de leitura clínica | criar | T-06 |
| `src/tests/Integration/ClinicalSummaryIntegrationTest.php` | teste de integração | criar | T-06 |
| `.claude/tasks/mar-20260923-1630-fidelidade-visual-mocks/sql/T-07-register-programs.sql` | rascunho do DML (executado após aprovação) | criar | T-07 |
| `src/app/control/clinic/CvShellController.php` | contexto do usuário/unidades e troca de unidade | criar/modificar | ⚠ T-08 (onda 2), T-19 (onda 3: unidades por tenant), T-26 (onda 7: POST + token, 500 genérico) |
| `src/app/templates/adminbs5/js/cv-shell.js` | papel no cartão, seletor de unidade, busca global, itens desabilitados | criar/modificar | ⚠ T-08 (onda 2), T-19 (onda 3), T-26 (onda 7: troca por POST, textos via `labels`) |
| `src/app/control/clinic/FinancialOverview.php` | tela nova "Financeiro — Visão geral" | criar/modificar | ⚠ T-09 (onda 2), T-24 (onda 6: categoria traduzida) |
| `src/app/control/clinic/ProductList.php` | tela "Estoque e Vendas" | modificar | ⚠ T-10 (onda 2), T-35 (onda 7: `status=attention`, erro único) |
| `src/app/control/clinic/ServiceList.php` | tabela + painel de detalhe | modificar | ⚠ T-11 (onda 2), T-17 (onda 3: filtro Inativo via `listAll()`), T-33 (onda 7: link focável), T-36 (onda 8: `Services`) |
| `src/app/control/clinic/PrescriptionForm.php` | prescrição em 2 colunas | modificar | ⚠ T-12 (onda 2), T-32 (onda 7: docblock, `Throwable`) |
| `src/app/control/clinic/EncounterView.php` | atendimento com wizard | modificar | ⚠ T-13 (onda 2), T-23 (onda 6: status do plano em `CvBadge`), T-30 (onda 7: constantes e status vazio) |
| `src/app/control/clinic/TutorList.php` | padrão de lista | modificar | ⚠ T-14 (onda 3), T-36 (onda 8: `Tutors`) |
| `src/app/control/clinic/TutorForm.php` | página cheia | modificar | T-14 |
| `src/app/control/clinic/PatientList.php` | padrão de lista | modificar | ⚠ T-14 (onda 3), T-36 (onda 8: `Patients`) |
| `src/app/control/clinic/PatientForm.php` | página cheia, `tutor_id` relacional | modificar | T-14 |
| `src/app/control/clinic/AppointmentForm.php` | página cheia | modificar | ⚠ T-14 (onda 3), T-36 (onda 8: `Date/time`) |
| `src/app/control/clinic/AgendaView.php` | cabeçalho/cards | modificar | T-14 |
| `src/app/control/clinic/QueueEntryView.php` | padrão de lista/badges | modificar | ⚠ T-14 (onda 3), T-29 (onda 7: carga única), T-36 (onda 8: `Patients`) |
| `src/app/control/clinic/GlobalSearchController.php` | resultados no padrão | modificar | ⚠ T-14 (onda 3), T-29 (onda 7: `&query=`), T-36 (onda 8: `Results`) |
| `src/app/control/clinic/ExamRequestForm.php` | página cheia, profissional relacional | modificar | ⚠ T-15 (onda 3), T-32 (onda 7: comentário do combo) |
| `src/app/control/clinic/ExamResultForm.php` | página cheia | modificar | T-15 |
| `src/app/control/clinic/PendingExamResultList.php` | padrão de lista | modificar | ⚠ T-15 (onda 3), T-30 (onda 7: ramo morto) |
| `src/app/control/clinic/ProcedureExecutionForm.php` | página cheia, profissional relacional | modificar | ⚠ T-15 (onda 3), T-32 (onda 7: comentário do combo) |
| `src/app/control/clinic/VaccinationForm.php` | página cheia, ids de contexto ocultos | modificar | ⚠ T-15 (onda 3), T-32 (onda 7: comentário do combo) |
| `src/app/control/clinic/VaccinationCardView.php` | cabeçalho do paciente/cards | modificar | T-15 |
| `src/app/control/clinic/ExamCatalogList.php` | padrão de lista | modificar | T-16 |
| `src/app/control/clinic/ExamCatalogForm.php` | página cheia | modificar | T-16 |
| `src/app/control/clinic/ProcedureCatalogList.php` | padrão de lista | modificar | T-16 |
| `src/app/control/clinic/ProcedureCatalogForm.php` | página cheia | modificar | T-16 |
| `src/app/control/clinic/VaccineCatalogList.php` | padrão de lista | modificar | T-16 |
| `src/app/control/clinic/VaccineCatalogForm.php` | página cheia | modificar | T-16 |
| `src/app/control/clinic/ProcedureInputForm.php` | página cheia, item de catálogo relacional | modificar | ⚠ T-16 (onda 3), T-33 (onda 7: item atual inativo no combo) |
| `src/app/control/clinic/VaccineProtocolForm.php` | página cheia, vacina relacional | modificar | ⚠ T-16 (onda 3), T-33 (onda 7: item atual inativo no combo) |
| `src/app/control/clinic/ProductForm.php` | página cheia | modificar | ⚠ T-17 (onda 3), T-34 (onda 7: edição via `ProductService::update`) |
| `src/app/control/clinic/StockBatchForm.php` | página cheia, produto relacional | modificar | T-17 |
| `src/app/control/clinic/ServiceForm.php` | página cheia | modificar | T-17 |
| `src/app/control/clinic/SaleForm.php` | PDV no padrão | modificar | ⚠ T-17 (onda 3), T-35 (onda 7: PDF com `target=_blank`) |
| `src/app/Core/Application/ServiceCatalogService.php` | casos de uso `update()` e `listAll()` do catálogo de serviços | modificar | T-17 |
| `src/app/Core/Domain/Service.php` | `changeDetails()` com as validações de `create()` | modificar | T-17 |
| `src/app/Core/Domain/Contract/ServiceRepositoryInterface.php` | `listAll()` | modificar | T-17 |
| `src/app/Core/Persistence/ServiceRepository.php` | `listAll()` escopado por tenant | modificar | T-17 |
| `src/tests/Unit/ServiceCatalogServiceTest.php` | testes de `update()`/`listAll()` (RED) | modificar | T-17 |
| `src/tests/Support/FakeServiceRepository.php` | `listAll()` no Fake (fixture do RED) | modificar | T-17 |
| `src/app/control/clinic/FinancialEntryList.php` | abas financeiras, filtro `entry_type` | modificar | ⚠ T-18 (onda 3), T-24 (onda 6: categoria traduzida) |
| `src/app/control/clinic/FinancialEntryForm.php` | página cheia | modificar | T-18 |
| `src/app/control/clinic/PayableList.php` | abas financeiras, badges | modificar | ⚠ T-18 (onda 3), T-28 (onda 7: filtro de status) |
| `src/app/control/clinic/PayableForm.php` | página cheia | modificar | ⚠ T-18 (onda 3), T-28 (onda 7: mensagem pt, docblock) |
| `src/app/control/clinic/PendingReceivableList.php` | abas financeiras, badges | modificar | T-18 |
| `src/app/control/clinic/PaymentForm.php` | página cheia | modificar | ⚠ T-18 (onda 3), T-31 (onda 7: rótulos via `CvFormat::paymentMethod`) |
| `src/app/control/clinic/CashSessionList.php` | aba "Fluxo de caixa" | modificar | T-18 |
| `src/app/control/clinic/CashSessionForm.php` | página cheia | modificar | T-18 |
| `src/app/control/clinic/EncounterAccountForm.php` | página cheia, autorizador relacional | modificar | ⚠ T-18 (onda 3), T-25 (onda 7: diretório de usuários do tenant no serviço) |
| `src/menu.xml` | menu reorganizado | modificar | T-19 |
| `src/app/Core/Application/EncounterAccountService.php` | `applyDiscount()` revalida o autorizador | modificar | T-25 |
| `src/tests/Unit/EncounterAccountServiceTest.php` | teste do autorizador fora do tenant (RED) | modificar | T-25 |
| `src/app/Core/Security/CsrfToken.php` | geração e validação de token (POST + `hash_equals`) | criar | T-26 |
| `src/tests/Unit/CsrfTokenTest.php` | teste de `CsrfToken` (RED) | criar | T-26 |
| `src/app/Core/Authorization/AuthorizationRequest.php` | `ACTION_PATTERN` e recusa de ação malformada | modificar | T-27 |
| `src/tests/Unit/AuthorizationRequestTest.php` | teste do formato de `$action` (RED) | criar | T-27 |
| `src/app/Core/Domain/Contract/PayableRepositoryInterface.php` | `listBySystemUnitAndStatus()` | modificar | T-28 |
| `src/app/Core/Persistence/PayableRepository.php` | `listBySystemUnitAndStatus()` escopado | modificar | T-28 |
| `src/app/Core/Application/PayableService.php` | `listByStatus()` | modificar | T-28 |
| `src/tests/Support/FakePayableRepository.php` | `listBySystemUnitAndStatus()` no Fake (fixture do RED) | modificar | T-28 |
| `src/tests/Unit/PayableServiceTest.php` | testes de `listByStatus()` (RED) | modificar | T-28 |
| `src/tests/Integration/PayableRepositoryIntegrationTest.php` | SQL do UPDATE e da listagem por status (RED) | criar | T-28 |
| `src/app/Core/Application/ProductService.php` | `update()` | modificar | T-34 |
| `src/tests/Unit/ProductServiceTest.php` | testes de `update()` (RED) | criar | T-34 |
| banco `system_program`/`system_group_program` (mesmo banco da aplicação) | registrar 2 programas | DML | T-07 |

Os 11 arquivos com ⚠ são serializados em ondas diferentes (escritor único por arquivo em cada onda); na onda 6 cada arquivo está em uma só task (T-22 `layout.html`; T-23 `EncounterView.php`; T-24 `CvFormat.php`, `FinancialOverview.php`, `FinancialEntryList.php`). Ondas 7–8: cada arquivo está em uma só task da onda 7. Os controllers de T-36 que também são de T-29/T-33 (`GlobalSearchController.php`, `QueueEntryView.php`, `ServiceList.php`) ficam serializados na onda 8, e `translations.json` tem escritor único (T-36). Os arquivos de Core de Payable foram tocados por T-18 fora dos Arquivos prováveis, com autorização (notes.md); agora são de T-28. Os arquivos acrescentados na revisão antes da onda 3 (correções da onda 2) não colidem entre T-14..T-19: cada um está em uma só task da onda.

## Decisões de arquitetura

| Decisão | Alternativas consideradas | Motivo da escolha |
|---|---|---|
| Kit em `app/lib/widget/Cv*.php` + `cv-components.css` separado de `custom.css` | Tudo em `custom.css`; trait por tela | `AdiantiApplicationLoader` já carrega `app/lib`; CSS separado deixa T-01 (casca) e T-02 (kit) paralelos sem colisão |
| Consultas de indicador em classes de leitura novas (`*Reader` + `*Service`) | Métodos novos em `*RepositoryInterface` | Evita editar interfaces e Fakes compartilhados; segue `AbstractTenantRepository`/`TenantQuery` |
| `has_master_menu` = 0 + CSS/JS próprios | Reescrever `theme.js` | `theme.js` já gera lista única com a flag em 0; manter o arquivo do framework intacto |
| `buildid` gerado no Dockerfile (`RUN date … > buildid`) | Editar `AdiantiTemplateParser`/`index.php` | Parser e index estão em `framework_hashes.php`; o parser já substitui quando o arquivo existe |
| `CvShellController` (programa registrado) com `onContext` (JSON) e `onSwitchUnit` | Placeholder novo no parser; método em tela admin | Parser não expõe grupo do usuário; troca de unidade reaproveita `ApplicationAuthenticationService::setUnit()`, que valida acesso |
| Seletor de unidade renderizado por `CvPage::header` como slot `data-cv-unit-switch`, preenchido por `cv-shell.js` | Seletor no `layout.html` | O mock põe o seletor no cabeçalho da página, que é por tela |
| Wizard do `EncounterView` só no cliente (abas sobre o mesmo formulário) | Um POST por etapa | Mantém os `DRAFT_FIELDS` num único formulário: autosave e finalizar continuam enviando todos os campos |
| Suíte/lint contra `src/` do host via `docker compose run -v src:ro` | Rebuild a cada verificação | Rebuild e `up -d` são estado global; com vários agentes em paralelo só o orquestrador reconstrói, no gate |
| Itens/abas sem tela: desabilitados "em breve" | Omitir | Resposta do usuário |

## Diagrama de dependências

```text
Onda 1: T-01 casca | T-02 kit | T-03 i18n | T-04 leitura estoque | T-05 leitura financeiro | T-06 leitura clínica | T-07 DML
Onda 2: T-08 (T-01,T-02,T-07) shell JS/controller
        T-09 (T-02,T-03,T-05,T-07) FinancialOverview
        T-10 (T-02,T-03,T-04) Estoque e Vendas
        T-11 (T-02,T-03) Serviços
        T-12 (T-02,T-03,T-06) Prescrição
        T-13 (T-02,T-03,T-06) Atendimento
Onda 3: T-14 (T-02) Recepção | T-15 (T-02,T-13) Clínico | T-16 (T-02) Catálogos
        T-17 (T-02,T-10,T-11) Estoque/vendas forms | T-18 (T-02,T-09) Financeiro
        T-19 (T-01,T-07,T-08,T-09) menu + sidebar
Onda 4: T-20 (T-03, ondas 2-3) consolidação i18n
Onda 5: T-21 (todas) validação visual lado a lado + regressão
Onda 6 — correção (usuário): T-22 (T-01,T-21) Chart.js duplicado | T-23 (T-13,T-21) status do plano | T-24 (T-09,T-18,T-21) forma de pagamento
Onda 7 — correção (usuário): T-25 (T-18) autorizador | T-26 (T-08,T-19) POST+token | T-27 ação | T-28 (T-18) contas a pagar
        T-29 (T-14) busca/fila | T-30 (T-13,T-15,T-23) status | T-31 (T-18,T-24) métodos | T-32 (T-12,T-15) comentários
        T-33 (T-11,T-16,T-17) link/combos | T-34 (T-17) ProductService::update | T-35 (T-10,T-17) estoque/PDF
Onda 8 — correção (usuário): T-36 (T-26,T-28,T-29,T-33,T-35) i18n
```

## Estratégia de execução
- Branch de trabalho: `feat/fidelidade-visual-mocks`
- Branch base: `main`
- Nota de branch: criada pelo orquestrador antes da onda 1 a partir de `main` @ `9efef4e` ("chore: estado inicial do projeto"); todos os agentes trabalham nela, sem trocar de branch.
- Commits da onda: cada implementador commita os próprios caminhos (`git -C /var/www/html/centralvet add <caminhos>` + `git -C /var/www/html/centralvet commit -m "<assunto>" -m "Task: <ID>" -- <caminhos>`), um ou mais commits por task, todos com o trailer `Task: <ID>`. Tasks com teste (T-04, T-05, T-06, T-17): primeiro o commit só do arquivo de teste falhando com trailer `Task: <ID> (RED)`, depois os commits da implementação com `Task: <ID>`. Proibidos na árvore compartilhada: `git add -A`, `git add .`, `git commit -a`, `git checkout`, `git switch`, `git reset`, `git stash`, `git restore`, `git rebase`, `git push`. Commit que falhar por `index.lock` é repetido após alguns segundos, sem apagar o lock. Arquivos da pasta do plano (`reports/`, `board.md`, `notes.md`, `tasks.md`) não são commitados pelos implementadores: o fechador usa a skill `new-commit --auto` só se sobrou algo sem commit e sempre registra o estado das tasks num `chore(tasks): registra commits da onda N` (commits `chore(tasks)` só tocam `.claude/tasks/` e são isentos do trailer). O validador confere trailer, escopo e ordem RED por `git -C /var/www/html/centralvet log <BASE da onda>..HEAD`.
- Isolamento em ondas com edições paralelas: caminho exclusivo (cada agente só nos arquivos da própria task no checkout compartilhado da branch `feat/fidelidade-visual-mocks`, commitando por caminho listado; nenhum arquivo é tocado por duas tasks na mesma onda; rebuild do container e navegador Playwright são estado global — só o orquestrador reconstrói e só o validador usa o navegador, no gate de cada onda)
- Gate de cada onda: orquestrador roda `docker compose build app worker && docker compose up -d app worker && docker compose restart nginx`; validador confere os commits da onda (trailer, escopo, RED antes da implementação), roda o lint dos arquivos da onda, a suíte, os passos Playwright do bloco Validação de cada task e a varredura Playwright da onda (`§ Critérios gerais de aceite`, item "Varredura Playwright no gate").
- Ondas 7–8 — correção (usuário): BASE da onda 7 = `2c7611d`; mesma branch, mesmas regras de commit e mesmo isolamento. Tasks com teste: T-25, T-26, T-27, T-28, T-34 (commit `Task: <ID> (RED)` antes da implementação). Na onda 7 as 11 tasks editam arquivos disjuntos. Darwin tem T-34 e T-35 (arquivos disjuntos, em sequência no mesmo agente). A onda 8 tem só T-36, escritor único de `translations.json`. Nenhuma task das ondas 7–8 roda DML; o gate só lê o banco (SELECT) e grava pela UI em registros "F10 varredura"/de teste já existentes.
- DML (T-07): o agente redige o SQL em `sql/T-07-register-programs.sql` e para; o orquestrador mostra o SQL ao usuário e só executa após aprovação explícita (skill `sql-write-approval`).

## Ondas de execução

### Onda 1
- T-01, T-02, T-03, T-04, T-05, T-06, T-07

### Onda 2
- T-08, T-09, T-10, T-11, T-12, T-13

### Onda 3
- T-14, T-15, T-16, T-17, T-18, T-19

### Onda 4
- T-20

### Onda 5
- T-21

### Onda 6 — correção (usuário)
- T-22, T-23, T-24

### Onda 7 — correção (usuário)
- T-25, T-26, T-27, T-28, T-29, T-30, T-31, T-32, T-33, T-34, T-35

### Onda 8 — correção (usuário)
- T-36

## Agentes

| Agente | subagent_type | model | Tasks |
|---|---|---|---|
| Sun Tzu — orquestrador | — | — | todas |
| Athena | general-purpose | inherit | T-01, T-19, T-22 |
| Platão | general-purpose | inherit | T-02, T-03, T-20, T-31, T-36 |
| Arquimedes | general-purpose | inherit | T-04, T-05, T-27 |
| Sherlock | general-purpose | inherit | T-06 |
| Jaspion | general-purpose | inherit | T-07, T-25 |
| Aang | general-purpose | inherit | T-08, T-26 |
| Tesla | general-purpose | inherit | T-09, T-18, T-24, T-28 |
| Darwin | general-purpose | inherit | T-10, T-17, T-34, T-35 |
| Levi | general-purpose | inherit | T-11, T-16, T-33 |
| Kratos | general-purpose | inherit | T-12, T-32 |
| Yoda | general-purpose | inherit | T-13, T-15, T-23, T-30 |
| Thanos | general-purpose | inherit | T-14, T-29 |
| Spock — validador | geduc:validador | sonnet | T-21, gates das ondas 6, 7 e 8 |

## Review Focus
- Aba aberta antes do deploy, com o `cv-shell.js` antigo em cache, troca a unidade por GET, ou a sessão não tem `cv_shell_csrf` → `onSwitchUnit` devolve 403 com a mensagem de requisição inválida, a unidade da sessão não muda e não há laço de reload → T-26
- POST de desconto com autorizador do próprio tenant mas inativo (`active='N'`) → `CrossTenantReferenceException`, desconto não gravado → T-25
- Filtro `paid` salvo na sessão e volta à `PayableList` pelo menu, sem `status` → a lista abre em Pagas, o combo mostra "Pagas" e "Pagar" não aparece em conta paga → T-28
- `GlobalSearchController&query=a` (1 caractere) pela URL → mesma mensagem de tamanho mínimo do `onSearch`, sem erro na tela → T-29
- Renomear um produto para o nome de outro produto do tenant → diálogo de erro e nenhum dos dois produtos muda → T-34

## Critérios gerais de aceite
- Suíte `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php` termina com `Failed: 0` e `Total` ≥ 158 (155 + 3 testes de integração novos) ao fim de cada onda.
- `php -l` de todo arquivo PHP tocado imprime `No syntax errors detected` (baseline sem erros).
- Nenhuma tela de `app/control/clinic` chama `setTargetContainer('adianti_right_panel')` para formulário (`grep -c "adianti_right_panel" src/app/control/clinic/*Form.php` = 0 em todos).
- Screenshot de Prescrições, Estoque e Vendas, Financeiro, Serviços e Atendimento, lado a lado com o mock, mostra a mesma estrutura (casca, cabeçalho, abas, cards, tabela, coluna direita) sem bloco de IA visível e sem dado inventado.
- Nenhuma página renderizada mostra "Shortcut", "Unit A" como rodapé, nem os links Trace/DB/PHP/Framework/Sistema/Notícias/Manuais/Abrir chamado.
- Commits (todo gate): `git -C /var/www/html/centralvet log --format='%h %s%n%b' <BASE da onda>..HEAD` mostra só commits com trailer `Task: <ID>` de tasks da onda (ou `chore(tasks)` restrito a `.claude/tasks/`); `git show --stat` de cada um lista só caminhos de "Arquivos prováveis" da task; em T-04/T-05/T-06/T-17 o commit `Task: <ID> (RED)` toca só os arquivos do bloco Teste RED e vem antes de todo commit `Task: <ID>` da implementação.
- Varredura Playwright no gate (pedido do usuário, 2026-09-29): além dos passos de Validação das tasks, o validador percorre com o Playwright MCP (sessão admin, `http://127.0.0.1:8081`) cada tela tocada na onda, exercitando os fluxos principais que a tela oferece — abrir, listar (paginação), filtrar/buscar, abrir registro, salvar (registros de teste prefixados `F10 varredura`, só em telas clínicas) e voltar — e, após cada tela, lê `browser_console_messages` (nível `error`) e `browser_network_requests` (status ≥ 400 ou falha, exceto `favicon`); "Fatal error", "Warning:", "Exception" ou diálogo de erro do Adianti na tela também contam. Critério: a tabela `Tela | Fluxos exercitados | Console (errors) | Rede (≥400) | Erro na tela | Veredito | Task dona` do relatório do gate tem uma linha por tela da lista com `0` erros de console, `0` requisições ≥ 400 e nenhum erro na tela. Bug numa tela ou arquivo de task da onda reprova a task dona (fix loop, commits `Task: <ID>`); bug sem task dona na onda vira task de correção da onda (ID seguinte ao último do plano). Telas por onda:
  - Onda 1 (casca T-01, afeta todas as páginas): `ServiceList`, `AgendaView`, `TutorList`, `QueueEntryView`, `ProductList`, `FinancialEntryList`, `SystemUserList` — navegar entre elas pelo menu, abrir e fechar a sidebar pelo hambúrguer, abrir o dropdown do cartão do usuário.
  - Onda 2: busca global e seletor de unidade da topbar/cabeçalho (T-08) em `ServiceList` e `ProductList`; `FinancialOverview` (T-09, filtro de período); `ProductList` (T-10, busca/Categoria/Status, "…"); `ServiceList` (T-11, clique na linha, painel); `PrescriptionForm` (T-12, a partir do atendimento e sem parâmetros); `EncounterView` (T-13, etapas do wizard, ações inline, autosave).
  - Onda 3: as 36 telas do Mapa de arquivos de T-14 a T-18 (listas e formulários, com salvar/voltar nos formulários; inclui `ServiceList`, que entrou em T-17 com o filtro Inativo), a sidebar de T-19 (clicar cada item de primeiro nível e cada item de Configurações) e o seletor de unidade de T-19 (`onContext`/`onSwitchUnit`); os passos de correção da onda 2 estão no bloco Validação de T-17, T-18 e T-19.
  - Onda 4: as telas das tasks citadas nas linhas `i18n:` do board (confere que nenhum rótulo pedido segue em inglês).
  - Onda 5 (T-21): todas as telas alcançáveis pelo `menu.xml` final — inclusive as administrativas do Adianti dentro de Configurações (só abrir, listar, filtrar e voltar) — mais as contextuais (`EncounterView`, `PrescriptionForm`, formulários abertos pelas listas), mapeando também bugs pré-existentes; bugs encontrados abrem `### Onda 6 — correção (usuário)` com IDs a partir de T-22 (bug em `src/lib/adianti` ou arquivo de `framework_hashes.php` é mapeado como "framework — não editável" e vai para Pendências).
  - Onda 6 — correção (usuário), BASE `0a72b8a`: `SystemAdministrationDashboard` (T-22, com hover nos gráficos), `FinancialOverview` (T-22/T-24, gráficos e categorias), `EncounterView` 1547 (T-23, abas do plano), `FinancialEntryList` (T-24) e os fluxos que T-21 não exercitou, nesta ordem, todos com registros `F10 varredura`: (1) `EncounterAccountForm&encounter_id=1547` → adicionar item com valor > 0 → Fechar conta (gera o recebível); (2) `PendingReceivableList` → receber o recebível novo → `PaymentForm` com forma Dinheiro e o valor total → Salvar (evidência: `receivable.status` = `paid`, `SELECT COUNT(*) FROM payment` +1 e `financial_entry` nova `income`/`cash`, exibida como "Dinheiro" em `FinancialEntryList`); (3) `CashSessionForm` → fechar a sessão aberta da unidade 1 (hoje a id 1, aberta em 2026-09-23) com o saldo de fechamento sugerido → Fechar caixa (evidência: `cash_session.status` = `closed` e `closed_at` preenchido) → abrir nova sessão pelo mesmo formulário (evidência: nova linha `open`, para `PaymentForm` seguir utilizável); (4) `ExamCatalogForm`, `ProcedureCatalogForm` e `VaccineCatalogForm` → novo "F10 varredura <Exame|Procedimento|Vacina> T22" → Salvar (evidência: mensagem de registro salvo, item na lista correspondente e `SELECT COUNT(*)` do catálogo +1, sem duplicar). Cada tela: console 0 `error`, rede 0 ≥ 400, nenhum erro na tela; bug nesses fluxos sem task dona na onda 6 abre task nova (T-25 em diante).
  - Ondas 7–8 — correção (usuário), BASE `2c7611d`. Suíte com `Failed: 0` e `Total` ≥ 186 + os testes novos de T-25, T-26, T-27, T-28 e T-34. Varredura da onda 7: `EncounterAccountForm` (conta #46), `ServiceList` e `ProductList` (casca, seletor de unidade, sidebar), `PayableList` (3 filtros), `PayableForm` (conta paga 2), `GlobalSearchController` (com e sem `method`), `QueueEntryView`, `EncounterView` 1547 (abas do plano), `PendingExamResultList`, `PendingReceivableList` → `PaymentForm` (sem salvar), os 4 formulários clínicos do atendimento 1708, `ProcedureInputForm`, `VaccineProtocolForm`, `ProductForm` (produto F10), `ProductList&status=attention` e `SaleForm`. Varredura da onda 8: as telas do critério de T-36, sem "Message not found". Cada tela: console 0 `error`, rede 0 ≥ 400, nenhum erro na tela.
