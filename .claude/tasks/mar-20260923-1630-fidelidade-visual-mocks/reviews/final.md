# Revisão final
Branch feat/fidelidade-visual-mocks @ 720f185 contra main @ 9efef4e (merge-base = 9efef4e); 46 commits, 82 arquivos de código (+10077/−3961). Comandos: `git diff --stat main...feat/fidelidade-visual-mocks`, `git diff main...feat/fidelidade-visual-mocks -- src docker` (sem translations.json), greps read-only abaixo. Suíte não re-executada nesta revisão (evidência de 182/182 só em relatório).
Rótulos da triagem: resolvida = "descartada" pelo pedido do orquestrador (a correção está no diff); aberta = "mantida"; promovida = promovida.

## Triagem
- [aberta] Campos sem schema (preço de venda, código, foto/alergia, validade/modelos, forma de pagamento em financial_entry, saldo bancário, pausa) → fora do escopo por premissa do plano
- [aberta] Ações sem backend (Exportar, Importar/Duplicar/Excluir, Salvar como modelo, Gerar relatório) → Excluído do plano
- [aberta] T-01 layout.html CRLF→LF → informativo, só ruído de diff
- [aberta] T-01 botão de ajuda disabled + pointer-events:none esconde o tooltip → sem correção no diff
- [resolvida] T-01 chave `Toggle menu` ausente → `grep -c '"Toggle menu"' translations.json` = 1
- [resolvida] T-02 `aria-label=""` em ação só com ícone → CvPage.php actionButton só define aria-label quando $title ≠ '' ; `.cv-avatar--species` → cv-components.css:258
- [aberta] T-04 limite ≤ 0 inconsistente; GROUP_CONCAT truncado → sem correção
- [aberta] T-05 saldo do caixa sem subtrair saídas; teste sem ruído de outra sessão → sem correção
- [aberta] T-06 lastEncounter "mais recente diferente do excluído"; testes de tutor sem contato → sem correção
- [aberta] T-07 CvShellController só no grupo 1 → sem DML adicional
- [resolvida] Onda 1 `adianti_right_panel` em 16 *Form.php → `grep -c adianti_right_panel app/control/clinic/*Form.php` = 0 em todos
- [resolvida] T-08 filtro de unidades por tenant → CvShellController.php:122-151 allowedUnitIds() com `system_unit.tenant_id = tenantid`; usado em onContext e onSwitchUnit
- [resolvida] T-08 `.cv-unit-switch__select` sem CSS → custom.css:238-239
- [aberta] T-08 onSwitchUnit muda estado por GET sem token; aria-label "Unidade" fixo em pt → cv-shell.js:106-107; CvShellController.php:90-108
- [aberta] T-09 "Lançamentos recentes" x período; "vs. mês anterior" fixo → sem correção
- [aberta] T-10 "Ver tudo" low x low+out; TMessage sem tenant; products() 3x → sem correção
- [resolvida] T-11 filtro Inativo e correção de ServiceForm → ServiceList.php:157 `listAll()`; ServiceForm onEdit por ServiceCatalogService::findById (tenantQuery) e onSave → update() com id
- [aberta] T-11 clique na linha sem link focável → sem correção
- [resolvida] T-12 "Rota" x "Via" → ruling T-20 (onda 4)
- [aberta] T-12 loadSummary captura só Exception; estilos inline; onEdit vazio → PrescriptionForm.php:299 `catch (Exception $e)`
- [promovida] T-12 "veterinário por TDBCombo sem critério" → a branch repetiu o padrão em 4 formulários; ver Achados
- [aberta] T-13 vazio "Informe um encounter_id…" após Finalizar (pré-existente); docblock de onInlineAction → sem correção
- [resolvida] Onda 2 CvNav `entry_type=revenue` → CvNav.php `FinancialEntryList&entry_type=income`
- [resolvida] Onda 2 `pattern` `{1,0}` → PATTERN0 aplicado (ex.: ServiceForm.php `setProperty('pattern', '[0-9]*')`)
- [aberta] Onda 2 mocks ausentes; EncounterDocumentService::list() sempre vazio → sem correção
- [aberta] Dados de teste (serviço id 2, prescrição 247, atendimento 556) → permanecem no banco
- [aberta] Onda 3: Log do PHP vazio; `<style>` inline redundante em GlobalSearchController; `&query=` não dispara a busca; ProductService sem update() (regra de edição no controller, ProductForm.php:241-287); PayableService::listOpen oculta conta paga → sem correção
- [resolvida] Onda 3 "Message not found: results" → ruling T-20 (0 ocorrências)
- [aberta] Onda 3 fluxos não rodados (exames, catálogos, caixa, PaymentForm, agenda/fila) → exercitados no gate da onda 6 segundo notes.md; sem evidência reproduzível read-only nesta revisão
- [aberta] T-14 fila carregada 2x; edição de tutor/paciente/agendamento → demanda futura (ruling T-14)
- [aberta] T-15 ramo morto "Result available"; VaccinationCardView sem pista; actionMenu/columns sem uso → sem correção
- [aberta] T-16 evidência de tenant não discrimina; offset do pageNavigation; combo só active=1; cards à mão → sem correção
- [aberta] T-17 validar `$action` contra padrão em AuthorizationRequest (opcional); StockBatchForm lê $_GET no construtor → sem correção (ver Rulings, HIGH descartado)
- [aberta] T-17 link "Gerar PDF" do SaleForm sem target=_blank → `grep _blank SaleForm.php` vazio
- [aberta] T-18 UPDATE de PayableRepository sem teste de SQL; docblock; mensagem crua em inglês → sem correção
- [aberta] T-19 itens desabilitados só no grupo 1; textos pt fixos no JS; units sem `current` → sem correção
- [aberta] T-20 chaves minúsculas x maiúsculas → sem correção
- [aberta] T-21 comparação com mocks; seletor no EncounterView; ícone text-primary; dados de teste → sem correção
- [resolvida] T-21 B1/O1/O2 → `grep chart.umd layout.html` vazio (57eec6a); CvBadge no plano (851a947); CvFormat::paymentMethod em FinancialOverview.php:217,268,298 e FinancialEntryList.php:202
- [aberta] Onda 6 layout-basic.html com "Trace" → layout-basic.html:153; arquivo em framework_hashes.php:417 (framework, não editável)
- [aberta] Onda 6 dados de teste no banco → permanecem
- [aberta] T-23 literais em planStatusBadge; status vazio sem badge → EncounterView.php:886-891, 922
- [aberta] T-24 mapa método→rótulo duplicado com literais → CvFormat.php:51-57, PaymentForm.php:275-281

## Rulings
- plano · onda 0 — Financeiro vira FinancialOverview (DML em T-07 com aprovação); itens e abas sem tela desabilitados "Em breve"
- plano · onda 0 — [substituída em 2026-09-29] RED sem git
- plano · onda 0 — Campos do mock sem schema: omitir/placeholder, sem migration; "Saldo do caixa aberto"
- plano · onda 0 — Comandos LINT/SUITE/GATE fixados; implementadores não rebuildam nem usam Playwright
- plano · onda 0 — Menu: Pacientes → GlobalSearchController; Prescrições desabilitado; Atendimentos/Exames/Vacinação mapeados; catálogos e admin em Configurações
- plano · onda 0 — translations.json com escritor único (T-03, T-20); chaves pedidas pelo board
- plano · onda 0 — CvShellController registrado como programa, reaproveita setUnit()
- plano (revisão) · onda 0 — Projeto em git; trailer Task:, RED antes da implementação, comandos git proibidos
- plano (revisão) · onda 0 — Varredura Playwright em todo gate; bugs sem dona viram task de correção
- plano · onda 0 — EncounterView: wizard só no cliente; onStart/onAutosave/onFinish mantidos; Pausar omitido
- T-08..T-13 · onda 2 — "Message not found" aceito até T-20
- T-08..T-13 · onda 2 — Mocks ausentes: telas pela especificação escrita
- T-11 · onda 2 — plano-mandou: correção de ServiceForm (update com id, onEdit por tenant) movida para T-17
- T-08 · onda 2 — Troca de unidade sem recalcular tenantid não vaza dados; defesa em profundidade como pendência (resolvida em T-19)
- plano (revisão) · onda 3 — Correções da onda 2 distribuídas em T-17, T-18, T-19
- plano (revisão) · onda 3 — PATTERN0 por campo por causa de TEntry::setNumericMask(0) do framework
- plano (revisão) · onda 3 — Só tenant 1 no banco: outro tenant coberto por teste unitário
- T-13 · onda 2 — Vazio após Finalizar pré-existente; timer não reproduzido: sugestões
- onda 3 · onda 3 — Login do gate feito pelo orquestrador com senha dada na conversa (não registrada)
- T-17 · onda 3 — StockService.php/StockServiceTest.php fora dos Arquivos prováveis autorizados; HIGH "Spoofable Policy Key" descartado
- T-18 · onda 3 — Arquivos de Core/teste de Payable fora dos Arquivos prováveis autorizados
- cruzada-onda-3 · onda 3 — Trailer `Task: cruzada-onda-3` aceito
- T-14 · onda 3 — Abrir tutor/paciente/agendamento só para leitura é pré-existente
- T-15 · onda 3 — "Aplicar vacina" e badge "Solicitado" julgados pelo revisor de T-15
- plano · onda 3 — Sem fixture de tenant 2
- plano · onda 3 — Mocks ausentes; "Message not found" até T-20
- T-20 · onda 4 — `Route` → "Via" conforme o mock
- T-20 · onda 4 — Ruling "Message not found até T-20" encerrado
- plano (revisão) · onda 6 — Onda 6 (T-22, T-23, T-24) aprovada pelo usuário; gate cobre caixa/PaymentForm/catálogos
- plano · onda 6 — Usuário autorizou fechar a sessão de caixa id 1
- cruzada-onda-6 · onda 6 — Prova CLI do rótulo "Dinheiro" no CashSessionForm aceita
- cruzada-onda-6 · onda 6 — Trailer `Task: cruzada-onda-6` (d006c24) aceito
### Decisões que tomei (revisor final)
- final · onda final — StockService::receiveBatch com `$action` do chamador: HIGH descartado, confirmo o ruling de T-17. O único chamador passa a constante `__CLASS__ . '::' . __FUNCTION__` (StockBatchForm.php:197); é o mesmo contrato dos outros 12 serviços de Application (PayableService, SaleService, CashSessionService…); AdiantiProgramPermissionProvider confere contra os programas da sessão (fail-closed). O parâmetro não vem do request. Fica só a sugestão de desenho (pendência T-17 aberta).
- final · onda final — ServiceForm: tenant ok. onEdit usa ServiceCatalogService::findById → ServiceRepository::findById com tenantQuery; update() recusa id de outro tenant e o UPDATE tem WHERE por tenant.
- final · onda final — PayableService::update: tenant e unidade ok. findById é escopado, há checagem explícita de tenantId, AuthorizationRequest com resourceUnitId da conta (unidade da sessão), changeDetails só com status open, UPDATE com WHERE por tenant; PayableForm::onEdit carrega pelo repositório escopado.
- final · onda final — CvShellController: tenant ok. allowedUnitIds() cruza as unidades do usuário com system_unit.tenant_id da sessão e usa placeholders; onSwitchUnit recusa fora da lista antes de setUnit(). Ficam sugestões: GET sem token (aberta) e mensagem de exceção no JSON 500.
- final · onda final — Escape do kit Cv*: ok. TElement::openTag só troca aspas; todos os textos e atributos variáveis do kit passam por CvFormat::e (sem escape duplo: `&amp;` em href é decodificado pelo navegador); CvWizard sanitiza o prefixo; FinancialOverview embute JSON com JSON_HEX_*; cv-shell.js usa textContent. CvCard recebe `$content` cru por contrato (documentado).
- final · onda final — Catálogos TStandardForm (Exam/Procedure/VaccineCatalogForm) mantêm o onEdit herdado sem tenant, mas as listas da branch só linkam "Novo" (sem key): não há exposição nova; é pré-existente.
- final · onda final — Pendência de T-12 promovida: o TDBCombo de SystemUser sem critério de tenant, antes em um formulário, agora está em quatro. A própria branch já tem o padrão correto (EncounterAccountForm::tenantUsersCriteria).

## Achados
- [bloqueante] plano-mandou (promovida de T-12): TDBCombo de `SystemUser` sem critério de tenant lista usuários de todos os tenants (nomes) e deixa escolher profissional de outro tenant em 4 formulários novos da branch. Antes era TEntry de id. O Incluso (d) pede "TDBCombo/TDBUniqueSearch com filtro de tenant"; plan.md § Padrões registra "SystemUser sem filtro de tenant (exceção documentada)". O filtro pronto está em EncounterAccountForm.php:681-695 → ExamRequestForm.php, PrescriptionForm.php, ProcedureExecutionForm.php, VaccinationForm.php (`new TDBCombo('professional_system_user_id', 'permission', 'SystemUser', 'id', 'name', 'name')`)
- [sugestão] CvShellController::onContext devolve `$e->getMessage()` no JSON 500 (pode expor erro de SQL) → CvShellController.php:79-81
- [sugestão] Regra de edição de produto no controller (valida com Product::create descartável + reconstitute), contra o README do Core → ProductForm.php:241-287
- [sugestão] `$action` livre em StockService::receiveBatch e demais serviços: validar formato `Classe::metodo` em AuthorizationRequest → StockService.php:64,74
- [sugestão] ServiceForm cria com active=0 em dois writes (create + update) na mesma transação; um parâmetro `active` em create() evitaria isso → ServiceForm.php:185-192

## Rodada 2
Re-revisão do bloqueante promovido de T-12. Commits vistos: `git -C /var/www/html/centralvet log --format='%h %s' 720f185..HEAD` → dbe7318 (RED), 12945fa; `git show dbe7318 12945fa`; `git status --short` → só artefatos do plano e PNGs soltos (sem código fora de commit).
- [resolvido] TDBCombo de `SystemUser` sem tenant nos 4 forms → ExamRequestForm.php:74, PrescriptionForm.php:180, ProcedureExecutionForm.php:103 e VaccinationForm.php:64 usam `CvTenantUsers::combo(...)`. O critério tem `active='Y'` e `id IN (SELECT system_user_id FROM tenant_user WHERE tenant_id = N)` e é fail-closed: se a resolução do tenant falha, usa tenant 0 (CvTenantUsers.php:22-37). `grep -rn "'SystemUser'" app` não acha mais combo sem critério nesses 4 forms. O único outro é AppointmentForm.php:75 (TDBUniqueSearch), que é pré-existente e fica fora deste escopo.
- [resolvido] Validação no save: os 4 serviços lançam CrossTenantReferenceException antes de assertAllowed() e de qualquer save(): ExamService.php:107, PrescriptionService.php:128, ProcedureExecutionService.php:153 e VaccinationService.php:148. No VaccinationService, a checagem vem antes de `catalog->save` (linha 175). TenantUserDirectory.php:55-72 usa placeholders, pega o tenant só do TenantContext e retorna false para id ≤ 0. As 7 construções dos serviços foram atualizadas (`grep "new \\CentralVet\\Application\\(Exam|Prescription|ProcedureExecution|Vaccination)Service("`). Nenhuma construção ficou sem o diretório.
- [ok] RED dbe7318: os 4 testes esperam a exceção com o profissional 999 fora de `[10]` e verificam que nada foi persistido e que o estoque não foi baixado. A falha vem da ausência da validação e não de setup (os argumentos extras do construtor são ignorados pelo PHP antes da implementação). GREEN: `docker exec centralvet-app-1 php tests/run.php` → `Total: 186, Passed: 186, Failed: 0, Skipped: 0`.
- [ok] EncounterAccountForm manteve o comportamento. `tenantUsersCriteria()` (EncounterAccountForm.php:681-683) delega a `CvTenantUsers::criteria`, e o diff mostra que as 3 linhas de filtro e o try/catch foram movidos literalmente para lá. O combo `authorized_by_system_user_id` (linha 377) continua usando o mesmo critério. Os `resolveTenantContext()` dos 5 forms são `private static`, e a closure `static fn` preserva o escopo da classe. O gate (reviews/final-fix.md § Complemento 2) aplicou desconto de R$ 5,00 → total R$ 65,00 e confirmou os combos com "1=Administrator".
- [sugestão] Os comentários obsoletos agora contradizem o código: "system_user não tem tenant_id: combo sem filtro de tenant (mesma exceção documentada…)" em ExamRequestForm.php:72-73, ProcedureExecutionForm.php:101-102 e VaccinationForm.php:62-63, e o docblock de PrescriptionForm.php:28.
- [sugestão] EncounterAccountService não revalida `authorized_by_system_user_id` no save (limitação registrada em reports/final-fix.md). O padrão é o mesmo, e o item fica para follow-up.
