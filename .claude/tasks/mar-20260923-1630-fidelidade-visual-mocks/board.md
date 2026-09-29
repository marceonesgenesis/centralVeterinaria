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
