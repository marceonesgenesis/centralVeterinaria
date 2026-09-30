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
- [T-19] i18n: Encounters → Atendimentos
- [T-19] i18n: Surgeries → Cirurgias
- [T-19] i18n: CRM / Communication → CRM / Comunicação
- [T-19] i18n: Clinical catalogs → Catálogos clínicos
- [T-19] menu.xml (e68adc2): itens sem tela apontam para `CvShellController#method=onComingSoon#item=<chave>` (Prescrições com `#hint=encounter`); cv-shell.js marca `a`/`li` com `.cv-menu-disabled`, remove href/generator, acrescenta `span.cv-menu-soon` "Em breve" e bloqueia o clique em captura. Como o programa 105 só está no grupo 1, usuários de outros grupos não veem os itens desabilitados. Rodapé: ícone com classe `cv-menu-footer` (Configurações, Ajuda). Logout e itens soltos (formulários "Novo", Fila, Caixa, Contas a pagar etc.) saíram do menu: continuam alcançáveis pelas telas/abas CvNav. Sessão admin precisa de re-login só se o menu for cacheado.
- [T-19] CvShellController: `allowedUnitIds()` = unidades do usuário com `system_unit.tenant_id` = `TSession tenantid` (sem tenant na sessão → nenhuma); onContext filtra `units` e onSwitchUnit recusa fora da lista com "Acesso não autorizado à esta unidade" (JSON inalterado).
- [T-16] Catálogos no padrão (490b414): listas de exames/procedimentos/vacinas seguem TStandardList com barra `form_search_<Record>` (campo `name`); forms em página cheia com voltar para a lista. ProcedureInputForm/VaccineProtocolForm: combo TDBCombo com TFilter tenant_id + active=1 e opção vazia padrão (no gate contar `option[value!=""]`); trocar o item recarrega a página (onChangeProcedure/onChangeVaccine). Dev DB: 1 procedimento ativo, 0 vacinas no tenant 1.
- [T-15] i18n: Requested → Solicitado
- [T-15] i18n: Result available → Resultado disponível
- [T-15] Lote clínico (78e1b4b): ExamRequestForm/ProcedureExecutionForm/VaccinationForm voltam a `EncounterView&encounter_id=` após salvar (TToast + __adianti_goto_page); ExamResultForm volta a `EncounterView` se a URL trouxer encounter_id, senão a `PendingExamResultList`. VaccinationForm sem encounter_id/patient_id mostra só o aviso "Open from the encounter" (apply exige atendimento); vacina agora é TCombo de VaccineCatalogService::listActive(). VaccinationCardView perdeu a ação "Aplicar vacina" (abria o right panel sem atendimento). PendingExamResultList: onShowCurtainFilters/onAfterSearch removidos; form de busca = TForm `form_search_ExamRequest` (campo patient_name).
- [T-17] ServiceCatalogService::update(int, array)/listAll() e ServiceRepositoryInterface::listAll() prontos (5e4f840); ServiceForm/ProductForm/StockBatchForm agora são TPage (não TStandardForm), sem right panel, com onEdit por id escopado ao tenant e retorno à lista após salvar (ServiceList&service_id=<id> / ProductList). ServiceList lê listAll().
- [T-17] ProductService não tem update(): ProductForm edita reaplicando Product::create() (validação) + nome único + ProductRepository::save() escopado, no controller — candidato a ProductService::update() numa task futura.
- [T-17] SaleForm: formatCents() delega a CvFormat::money(); link Gerar PDF foi para as ações do CvPage::header; botão Fechar/onClose removidos.
- [T-14] i18n: tutors → tutores; patients → pacientes; results → resultados; Scheduled → Agendado; Confirmed → Confirmado; Canceled → Cancelado; No-show → Faltou (AgendaView também usa "In service", já pedido por T-13)
- [T-14] TutorForm/PatientForm/AppointmentForm em página cheia; com `key` (ou `id`) na URL abrem o registro em modo leitura (serviços sem update); salvar tutor/paciente reabre `TutorForm|PatientForm&method=onEdit&key=<id>`. PatientForm sem `tutor_id` na URL usa TDBUniqueSearch de Tutor (tenant_id da sessão). AgendaView só se autocarrega sem `method` (antes renderizava 2x). Listas paginam em memória (TutorList/Global 10, PatientList 10, Fila 20).
- [T-18] i18n: entries → lançamentos; accounts → contas; sessions → sessões; Authorized by → Autorizado por
- [T-18] CvNav finance `revenues` → `FinancialEntryList&entry_type=income` (f300a8b). FinancialEntryList: entry_type=income|expense filtra e ativa a aba; outro valor lista tudo sem aba ativa; período lido de `FinancialEntryList_filter_data` (antes lia `FinancialEntry_filter_data`, nunca gravado por onSearch — filtro de período não funcionava). Listas financeiras e CashSessionForm perderam onShowCurtainFilters/onAfterSearch e TXMLBreadCrumb (menu.xml da T-19 não quebra as telas).
- [T-18] PayableService só expõe listOpen(): depois de "…" → Pagar, a conta paga aparece na mesma renderização com badge Pago (devolvida por pay()); num novo carregamento ela some da lista. Banco sem payable: o gate precisa criar um "F10 varredura" pelo PayableForm antes de testar Pagar.
- [T-17] Correção 1: StockService::receiveBatch() ganhou parâmetro obrigatório `string $action` (padrão de SaleService::create etc.); StockBatchForm passa `__CLASS__ . '::' . __FUNCTION__` = 'StockBatchForm::onSave' (system_program 88, grupo 1). Sem SQL. Quem chamar receiveBatch() precisa passar a ação.
- [T-14] TPageNavigation (framework) sempre desenha 10 páginas (as inexistentes como `li.off.page-item`): afeta toda lista com CvDatagrid::footer (TutorList, PatientList, QueueEntryView, ServiceList, ProductList...). GlobalSearchController corrigido localmente (pager oculto com 1 página; `.off` oculto por style escopado). Correção geral cabe em cv-components.css (`.cv-pager .page-item.off{display:none}`) — dono do CSS decide.
- [T-18] PayableService::update(int $payableId, string $descriptionText, string $category, int $amountCents, ?string $dueDate, string $action): Payable (novo; tenant + unidade + só status open); Payable::changeDetails(); PayableRepository UPDATE agora grava description_text/category/amount_cents/due_date. PayableForm::onEdit(key|id) lê pelo repositório escopado. FinancialEntryForm::onEdit só limpa (append-only); antes o herdado mostrava "Active Record não definido" em Novo/Limpar.
- [T-21] B1: layout.html (T-01) recarrega lib/independent/js/chart.umd.min.js, mas independent-plugins.min.js já embute Chart.js v4.5.1; a 2ª carga quebra o tooltip do SystemAdministrationDashboard ("TypeError: Cannot read properties of null (reading 'x')"). Sem o script extra, FinancialOverview desenha os 2 gráficos normalmente. Mocks images/1-4.png ausentes no host: GATE lado a lado não feito.
- [T-33] ServiceList: coluna Nome agora renderiza `<a class="cv-row-link text-reset" ... generator="adianti">` (linha ~85-95); T-36 migra `_t('services')` na mesma classe e a linha mudou de número. Commit e3478b3.
- [T-26] i18n: Could not load the user context → Não foi possível carregar o contexto do usuário
- [T-26] i18n: Invalid or expired request. Reload the page → Requisição inválida ou expirada. Recarregue a página
- [T-26] CvShellController: onContext agora devolve também `csrf_token` (TSession `cv_shell_csrf`) e `labels` {coming_soon, open_from_encounter, unit, error}; 500 genérico com error_log. onSwitchUnit só aceita POST com `csrf_token` (CentralVet\Security\CsrfToken) e responde só JSON: 403 {error} / 200 {switched:true}; o reload é do cv-shell.js. Menu desabilitado é neutralizado no init e recebe o selo/dica quando labels chega.
- [T-35] i18n: Low or out of stock → Estoque baixo ou zerado
- [T-34] ProductService::update() pronto (1d4941c); ProductForm::updateProduct() removido. Id inexistente agora lança "Product <id> not found for this tenant" (antes _t('Record not found')).
- [T-28] i18n: Open (filter) → Em aberto
- [T-28] i18n: Paid (filter) → Pagas
- [T-28] i18n: All (filter) → Todas
- [T-28] i18n: Only open payables can be edited → Só contas em aberto podem ser editadas
- [T-28] PayableRepositoryInterface ganhou listBySystemUnitAndStatus(int, ?string) (listOpenBySystemUnit delega a ela); PayableService::listByStatus() pronto (ba02a97). No banco atual payable id 2 está 'open' e ids 1 e 3 'paid' (tenant 1/unidade 1): o gate que cita "conta paga id 2" deve usar id 1 ou 3.
