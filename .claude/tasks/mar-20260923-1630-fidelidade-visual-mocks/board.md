# Board — mar-20260923-1630-fidelidade-visual-mocks

Log append-only de fatos que afetam outras tasks desta execução: contrato divergente, símbolo renomeado, arquivo compartilhado alterado, decisão que outra task precisa conhecer. Uma linha por fato, acrescentada por append com heredoc (abaixo; o delimitador entre aspas aceita qualquer caractere no fato); nunca edite ou remova linhas. Leia antes de começar uma task e antes de usar cada `Consome`. O fechador consolida as linhas em `notes.md § Descobertas`.

Formato: `- [T-NN] <fato>`

Append:

```bash
cat >> "/var/www/html/centralvet/.claude/tasks/mar-20260923-1630-fidelidade-visual-mocks/board.md" <<'EOF'
- [T-NN] <fato>
EOF
```
- [T-07] SQL redigido (sql/T-07-register-programs.sql, commit 70211df): FinancialOverview=system_program 104, CvShellController=105, ambos só no grupo 1; NÃO executado — aguarda aprovação do usuário.
- [T-06] ClinicalSummaryService pronto: construtor aceita 2º parâmetro opcional `?DateTimeImmutable $today` (só para testes); encounterPlanItems: prescriptions.title = medicamentos separados por ", ", detail = status; exams.detail = status; procedures.detail = notes_text (até 80); vaccines.detail = "Dose N · Lote X".
- [T-06] Reader sem CRUD não pode estender AbstractTenantRepository (TenantRepositoryInterface exige findById/save/remove): StockSalesOverviewReader (T-04) dá Fatal e aborta tests/run.php antes do resumo Total/Failed; ClinicalSummaryReader usa TenantQuery::forTenant direto.
- [T-01] i18n: Toggle menu → Alternar menu
- [T-01] i18n: Help → Ajuda
- [T-01] i18n: Coming soon → Em breve
- [T-01] layout.html: `{MENUTOP}`, `{MENUBOTTOM}`, `{userunitname}`, `#search-box` do SearchBox (has_program_search=0), trilho master e painel Trace removidos; flags has_messages/docs/contacts/support_form/wiki/news/menu_mode_switch/main_mode_switch/master_menu = '0'. Busca global `#cv-global-search` tem fallback GET (class=GlobalSearchController, method=onSearch, query) para cv-shell.js (T-08) interceptar. `--ad-menu-size` 370px → 260px em custom.css. Dockerfile aceita `ARG APP_BUILD_ID` (padrão: data UTC).
- [T-04] StockSalesOverviewService pronto (728a64a) conforme Interface; status por produto 'normal'|'low'|'out'; só produtos active=1 e vendas status completed; recentSales().items_label = descrições dos itens separadas por ", ".
- [T-05] FinancialOverviewService pronto (132d88f) conforme Interface; $from/$to são datas inclusivas; openCashBalanceCents = abertura + pagamentos da sessão aberta (schema não liga saídas ao caixa); reference = "<reference_type> #<id>" ou null.
- [T-04] Readers que estendem AbstractTenantRepository precisam implementar findById/save/remove (RepositoryInterface); T-04/T-05 lançam LogicException (modelo só leitura).
- [T-02] Kit Cv* pronto (d2989e4) com as assinaturas do Produz inalteradas. Uso: `CvDatagrid::decorate()` logo após criar o wrapper e ANTES dos demais `addColumn` (a coluna de checkbox `cv_row_check` entra na ordem de chamada; valor = `$object->id`). `CvPage::header` aceita em `$actions` widgets prontos ou arrays `['label','action'=>TAction|'href','icon','class','title']`. `CvDatagrid::actionMenu` aceita `TDataGridAction` com label/imagem ou `['label','action','icon']`. `CvForm::decorate` espera linhas `addFields([TLabel],[campo],...)`: cada par rótulo/campo vira uma coluna, rótulo acima; linha com um único par ocupa a largura toda.
- [T-02] `CvNav::tabs('prescription', ...)`: `new` e `history` apontam para `PrescriptionForm` repassando `encounter_id`/`patient_id` de `$_REQUEST` (history acrescenta `tab=history`); `templates` desabilitada.
- [T-02] Helpers extras (não contratuais): `CvFormat::percent(float)` ("+12,5%") e `CvFormat::e(?string)` (escape HTML); textos passados ao kit são escapados internamente — não passe HTML pronto em título/label.
- [T-03] translations.json: 43 chaves novas (a1da8e8; total 630). `Showing %1–%2 of %3` usa `%1..%3` (não `^1`); a substituição é feita em `CvDatagrid::footer`. Já existiam: In progress, Physical exam, Diagnosis, Clinical plan, Financial summary. Extras do kit: Receivables, Movements, Categories, Suppliers, Reports, Packages, Pricing, Select all, Select row, More actions.
- [T-01] Correção 1: `src/app/templates/adminbs5/js/cv-shell.js` agora existe como stub (IIFE vazio, commit b325eb4); T-08 substitui o conteúdo inteiro. `#sidebar.collapsed` em custom.css recolhe para largura 0 (antes: margem negativa com largura constante).
- [T-02] Correção 1: atributos com dado do chamador (title/href/class/aria-label) agora escapados com `CvFormat::e()`. `CvForm`: grid só em linhas com slots `cv-form__slot`; linha de slot único ocupa a largura toda; linhas com `setLayout` ficam no row Bootstrap. `.cv-kpi-row` é classe CSS sem helper PHP: telas com KPIs envolvem os `CvKpiCard::create` num `TElement('div')` com `class="cv-kpi-row"` (T-09/T-10). Ação só com ícone em `CvPage::header` precisa de `title` (exceto voltar, que recebe "Voltar").
- [T-08] CvShellController + cv-shell.js prontos (9e8ff1b): onContext → {"user":{"name","role"},"units":[{"id","name","current"}]}, role = grupo de menor id; `window.CvShell.init()`/`CvShell.reload()`; select `.cv-unit-switch__select` montado por MutationObserver em cada `[data-cv-unit-switch]` (inclusive páginas via __adianti_load_page). Sessão admin precisa de re-login para o programa 105 valer.
- [T-09] i18n: Revenues, expenses and cash of the unit → Receitas, despesas e caixa da unidade
- [T-09] i18n: Result → Resultado
- [T-09] i18n: No open cash register → Nenhum caixa aberto
- [T-09] i18n: No entries in this period → Nenhum lançamento no período
- [T-09] FinancialOverview pronto: aceita from/to em Y-m-d ou dd/mm/yyyy (TDate `from`/`to` do form `form_FinancialOverview`, ação onFilter); canvas `#cv-fin-line` e `#cv-fin-donut`; coluna Descrição = reference ("<reference_type> #<id>") ou "—" (schema sem descrição). CvNav finance aponta Receitas/Despesas para FinancialEntryList&entry_type=revenue, mas entry_type no schema é 'income'/'expense' (para T-18).
- [T-11] i18n: services → serviços
- [T-11] i18n: Standard price → Preço padrão
- [T-11] i18n: Estimated duration → Duração estimada
- [T-11] i18n: Data → Dados
- [T-11] i18n: Prices → Preços
- [T-11] i18n: Links → Vinculações
- [T-11] i18n: History → Histórico
- [T-11] i18n: All categories → Todas as categorias
- [T-11] i18n: All statuses → Todos os status
- [T-11] i18n: Search services → Buscar serviços
- [T-11] i18n: No services found → Nenhum serviço encontrado
- [T-11] _t() com chave ausente renderiza "Message not found: <chave>" (não a chave em inglês) até T-20 — vale para todas as chaves pedidas no board.
- [T-11] ServiceList agora é TPage (não TStandardList): filtros por GET/POST `search`/`category`/`status`, painel por `service_id`; onShowCurtainFilters/onChangeLimit removidos. ServiceCatalogService só expõe listActive(): filtro "Inativo" sempre vazio e ServiceForm::onSave só cria (Editar abre o form, mas salvar duplica) — escopo de T-17.
- [T-10] i18n: Current stock → Estoque atual
- [T-10] i18n: No recent sales → Nenhuma venda recente
- [T-10] i18n: No low stock products → Nenhum produto com estoque baixo
- [T-10] Chave ausente em translations.json não aparece em inglês: o Adianti exibe "Message not found: <chave>" até T-20 (vale para todas as chaves pedidas no board).
- [T-10] ProductList (TPage, não mais TStandardList): ação "Entrada de lote" gera `index.php?class=StockBatchForm&method=onEdit&product_id=<id>` (method=onEdit acrescentado; o construtor já lê product_id); Editar gera `ProductForm&method=onEdit&id=<id>` — ProductForm ainda não tem onEdit (T-17). `ProductList::onReload` preservado (setAfterSaveAction do ProductForm). Título usa `_t('Stock and sales')` = "Estoque e vendas" (v minúsculo, vindo de T-03).
- [T-12] i18n: Prescription data → Dados da prescrição
- [T-12] i18n: Veterinarian → Veterinário
- [T-12] i18n: Save prescription → Salvar prescrição
- [T-12] i18n: Age → Idade
- [T-12] i18n: Draft → Rascunho
- [T-12] i18n: Issued → Emitida
- [T-12] PrescriptionForm sem right panel: header com voltar para `EncounterView&encounter_id=`; aba history via `tab=history` (célula de itens com `data-items-count`); "Limpar" = novo `onClear` (esvazia o rascunho da sessão); `onSave` inclui o bloco de medicamento preenchido e ainda não adicionado como último item. Tradução existente de `Route` é "Rota" (mock diz "Via") — decidir em T-20.
- [T-13] i18n: In service → Em atendimento; Print → Imprimir; Finalization → Finalização; Prescribe → Prescrever; Guidance → Orientações; History → Histórico; Previous encounter → Atendimento anterior; Age → Idade; Weight → Peso; Elapsed time → Tempo decorrido; Encounter summary → Resumo do atendimento; No items yet → Nenhum item ainda; No attachments yet → Nenhum anexo ainda
- [T-13] EncounterView: formulário agora é TForm `form_EncounterView_<id>` (não BootstrapFormBuilder) com painéis do wizard `#encounter_step_<id>_<n>`; ações inline Vacina/Conta ficam no dropdown "Mais" como links `a#inline_vaccine`/`a#inline_account` (Prescrever/Solicitar exame/Procedimento seguem TButton `#tbutton_inline_<kind>`); abas do plano são client-side (`window.cvEncounterPlanTab`); resumo financeiro lê EncounterAccountRepository::findByEncounterId (somente leitura) antes do preço do serviço; autosave se desliga quando o form some da página.
