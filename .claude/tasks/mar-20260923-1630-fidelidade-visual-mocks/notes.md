# Notas de execução

## Decisões tomadas
- 2026-09-23 · plano · onda 0 — Respostas do usuário: Financeiro vira tela nova `FinancialOverview` (DML redigido em T-07, executado só após aprovação do SQL na hora); itens de menu sem tela real (Dashboard clínico, Cirurgias, CRM/Comunicação, Relatórios) aparecem desabilitados ("Em breve", sem navegação). O mesmo critério vale para abas sem tela (Movimentações, Categorias, Fornecedores, Relatórios, Pacotes, Preçários, Modelos), Prontuário e Ajuda.
- 2026-09-23 · plano · onda 0 — [substituída em 2026-09-29, ver abaixo] RED sem git (aceito pelo usuário): `sem teste: <motivo>` nas tasks puramente visuais; teste PHP real (`src/tests/Integration/*IntegrationTest.php`, base `MysqlIntegrationTestCase`, rollback) nas tasks de consulta T-04/T-05/T-06, com a falha comprovada pela execução da suíte colada em `## RED` do relatório antes de escrever a implementação. Campo "Commit RED" do relatório: escrever "não se aplica (sem git)".
- 2026-09-23 · plano · onda 0 — Campos do mock sem schema: não criar migration; omitir o elemento ou usar placeholder neutro (avatar com inicial/ícone de espécie) e registrar em Pendências. "Saldo em conta" → "Saldo do caixa aberto" (sessão de caixa aberta da unidade). Preço de venda/código do produto → sem coluna.
- 2026-09-23 · plano · onda 0 — Comandos do plano (rodar de `/var/www/html/centralvet`): LINT = `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php -l <arquivo>`; SUITE = `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php` (comprovado no planejamento: `Total: 155, Passed: 155, Failed: 0`); a suíte não filtra por arquivo — ler as linhas `PASS/FAIL  Integration\<Classe>::`. GATE = orquestrador reconstrói (`docker compose build app worker && docker compose up -d app worker && docker compose restart nginx`) e só então o validador usa o Playwright MCP.
- 2026-09-23 · plano · onda 0 — Implementadores não rodam `docker compose build`/`up`/`restart` nem usam o navegador Playwright MCP: são estado global compartilhado pelos agentes em paralelo.
- 2026-09-23 · plano · onda 0 — Menu: "Pacientes" → `GlobalSearchController` (PatientList exige `tutor_id`); "Prescrições" desabilitado com dica "abra pelo atendimento" (PrescriptionForm é contextual; a fase 09 removeu o item solto); "Atendimentos" → `QueueEntryView`; "Exames" → `PendingExamResultList`; "Vacinação" → `VaccinationCardView`; catálogos clínicos e itens admin/Logs do Adianti dentro de Configurações; grupo demo "Common pages" removido.
- 2026-09-23 · plano · onda 0 — `translations.json` tem escritor único por onda (T-03 na onda 1, T-20 na onda 4). Task das ondas 2–3 que precisar de chave nova usa `_t('<chave en>')` e registra no board `- [T-xx] i18n: <chave en> → <texto pt>`; até T-20 o Adianti exibe a chave em inglês.
- 2026-09-23 · plano · onda 0 — `CvShellController` registrado como programa (T-07) porque o parser do template não expõe o grupo do usuário e não existe tela de troca de unidade; ele reaproveita `ApplicationAuthenticationService::setUnit()`, que valida o vínculo do usuário com a unidade.
- 2026-09-29 · plano (revisão) · onda 0 — O projeto passou a ter git (decisão do usuário): repositório único `/var/www/html/centralvet`, `main` @ `9efef4e` ("chore: estado inicial do projeto"). Deixa de valer tudo o que dizia "sem git / sem commits / Commit RED não se aplica". Branch de trabalho `feat/fidelidade-visual-mocks` (base `main`), criada pelo orquestrador antes da onda 1; commits por task listando os caminhos, com trailer `Task: T-NN`; nas tasks com teste (T-04/T-05/T-06) o teste falhando vai antes, num commit só do arquivo de teste com `Task: T-NN (RED)`, e o hash entra em "Commit RED" do relatório; as tasks visuais seguem `sem teste: <motivo>`. Proibidos na árvore compartilhada: `git add -A`/`.`, `commit -a`, `checkout`, `switch`, `reset`, `stash`, `restore`, `rebase`, `push`. Isolamento continua `caminho exclusivo` no checkout compartilhado (repositório único, sem worktree).
- 2026-09-29 · plano (revisão) · onda 0 — Varredura Playwright (pedido do usuário): em cada gate, além da Validação das tasks, o validador percorre com o Playwright MCP (sessão admin) as telas tocadas na onda (lista por onda em `plan.md § Critérios gerais de aceite`), exercitando abrir/listar/filtrar/salvar/voltar e checando console (`error`) e rede (≥ 400); bug em tela de task da onda reprova a task dona (fix loop), bug sem dona vira task de correção da onda. Em T-21 a varredura cobre todas as telas do `menu.xml` (inclusive as administrativas do Adianti, só leitura) e mapeia bugs pré-existentes; os bugs abrem `### Onda 6 — correção (usuário)` com IDs a partir de T-22. Registros de teste criados pela varredura levam o prefixo `F10 varredura`; nada de excluir, estornar, fechar caixa ou finalizar atendimento que não seja da própria varredura.
- 2026-09-23 · plano · onda 0 — `EncounterView`: wizard só no cliente sobre o mesmo formulário (mantém `DRAFT_FIELDS`, autosave de 20 s e finalizar enviando todos os campos); não renomear `onStart`/`onAutosave`/`onFinish` (strings de auditoria em `EncounterTimelineIntegrationTest.php:69-86`); manter o padrão `new TButton(); ->setAction(); ->setFormName('form_EncounterView_' . id)` da fase 08 (nunca `TButton::create()` com `TAction` pronta). Botão Pausar omitido (sem dado de pausa no schema).

## Bloqueios
- nenhum no planejamento. Antes da onda 1 o orquestrador cria a branch `feat/fidelidade-visual-mocks` a partir de `main` (os agentes recusam trabalhar em outra branch). T-07 para por definição aguardando a aprovação do SQL pelo usuário; T-08, T-09 e T-19 só verificam no navegador depois do DML executado.
- 2026-09-29 · T-07 · onda 1 — Usuário aprovou o SQL como estava, com nomes em português; DML executado e conferido por SELECT: system_program 104 'Visão geral financeira'/FinancialOverview e 105 'Contexto da casca'/CvShellController; COUNT(system_program)=105; system_group_program 104/105 no grupo 1.
- 2026-09-29 · T-05 · onda 1 — Saldo do caixa aberto = abertura + pagamentos da sessão, sem subtrair saídas, porque o schema não liga saídas ao caixa; revisor aprovou.
- 2026-09-29 · T-01 · onda 1 — Stub de cv-shell.js criado em T-01 para eliminar o erro de console até T-08, que substitui o arquivo inteiro.
- 2026-09-29 · plano · onda 1 — Linhas `Branch de trabalho`/`Branch base` do plan.md ajustadas ao formato do repos.py; projeto passou a usar git (commit inicial 9efef4e em main, decisão do usuário).
- 2026-09-29 · plano · onda 1 — Varredura Playwright em cada onda e varredura completa em T-21 (decisão do usuário).
- 2026-09-29 · T-02 · onda 1 — CvNav `prescription`: `new`/`history` apontam para PrescriptionForm com encounter_id/patient_id de $_REQUEST; CvForm::decorate delega o grid ao CSS (`cv-form__slot`); helpers extras CvFormat::percent() e CvFormat::e().
- 2026-09-29 · T-06 · onda 1 — ClinicalSummaryService aceita 2º parâmetro opcional `?DateTimeImmutable $today`; ClinicalSummaryReader não estende AbstractTenantRepository (usa TenantQuery::forTenant).

## Descobertas
- `{buildid}` só é substituído por `AdiantiTemplateParser` (`src/lib/adianti/core/AdiantiTemplateParser.php:61`) quando existe o arquivo `src/buildid`; hoje ele não existe, daí o cache-busting quebrado. Parser e `index.php` estão em `framework_hashes.php` → a correção vai no `docker/php/Dockerfile` (T-01).
- Chart.js está em `src/lib/independent/js/chart.umd.min.js` mas não é carregado por `libraries.html`; T-01 inclui o script no `layout.html`.
- `EncounterDocumentService::list()`/`attach()` já dão anexos reais por atendimento: a área "Fotos e anexos" do mock é atendida em `EncounterView`; na prescrição não há anexos.
- Suíte contra o código do host via `docker compose run --rm --no-deps -v src:ro` funciona sem rebuild (155/155 no planejamento).
- Baseline de lint: `php -l` em `app/control/clinic`, `app/lib/widget`, `app/Core/Application`, `app/Core/Persistence` sem erros (`baseline/php-lint.txt`, 0 linhas).
- [T-04] StockSalesOverviewService (728a64a): status por produto 'normal'|'low'|'out'; só produtos active=1 e vendas completed; items_label = descrições dos itens separadas por ", ".
- [T-05] FinancialOverviewService (132d88f): $from/$to inclusivos; reference = "<reference_type> #<id>" ou null.
- [T-06] Readers sem CRUD não podem estender AbstractTenantRepository sem implementar findById/save/remove (Fatal aborta tests/run.php); T-04/T-05 lançam LogicException, T-06 usa TenantQuery::forTenant.
- [T-06] ClinicalSummaryService::encounterPlanItems: prescriptions.title = medicamentos separados por ", ", detail = status; exams.detail = status; procedures.detail = notes_text (até 80).
- [T-01] layout.html: {MENUTOP}, {MENUBOTTOM}, {userunitname}, #search-box, trilho master e painel Trace removidos; flags de navbar em '0'; i18n pendente: Toggle menu, Help, Coming soon (T-20).
- [T-01] cv-shell.js existe como stub (b325eb4); T-08 substitui o conteúdo inteiro. `#sidebar.collapsed` recolhe para largura 0.
- [T-02] Kit Cv* (d2989e4): `CvDatagrid::decorate()` antes dos demais addColumn; textos são escapados internamente (não passar HTML pronto); `.cv-kpi-row` é só classe CSS. Correção 1 (e1e5ae6): atributos escapados com CvFormat::e(); CvForm com grid só em linhas de slot.
- [T-03] translations.json: 43 chaves novas (total 630); `Showing %1–%2 of %3` usa %1..%3, substituição em CvDatagrid::footer.

## Pendências
- Sem schema, ficam fora desta fase: preço de venda, código/barcode e imagem de produto; foto, alergia e "ativo" do paciente; validade, modelos, anexos e orientação por item da prescrição; descrição/preparo/observações e ícone de serviço; forma de pagamento/status em `financial_entry`; saldo bancário; variação vs mês anterior de "Produtos em estoque"/"Estoque baixo" (sem histórico de estoque); pausa de atendimento.
- Ações sem backend: Exportar (financeiro), Importar/Duplicar/Excluir (serviços), Salvar como modelo e Pré-visualizar (prescrição, a menos que o implementador de T-12 encontre geração de documento existente), Gerar relatório (estoque).
- T-01: layout.html convertido de CRLF para LF (diff mostra reescrita; conferir com --ignore-cr-at-eol -w).
- T-01: botão de ajuda `disabled` + `pointer-events: none` esconde o tooltip "Em breve".
- T-01: chave `Toggle menu` ausente em translations.json até T-20.
- T-02: ação só com ícone e sem title recebe `aria-label=""` em CvPage; `cv-avatar--species` sem regra CSS.
- T-04: limite ≤ 0 inconsistente (recentSales(0) devolve 1, lowStock(0) devolve []); items_label via GROUP_CONCAT trunca em group_concat_max_len.
- T-05: saldo do caixa aberto = abertura + pagamentos da sessão, sem subtrair saídas (schema não liga saídas ao caixa); soma todos os métodos, rotular com cuidado em T-09 ("total recebido na sessão").
- T-05: teste do saldo sem pagamento de ruído (outra sessão/tenant); filtro por cash_session_id não exercitado.
- T-06: lastEncounter($p, $exclude) devolve o mais recente diferente do excluído, não o anterior; filtrar por started_at se T-13 precisar de "anterior"; faltam testes de tutor sem e-mail/telefone e do "…" de truncamento.
- T-07: CvShellController só no grupo 1 (usuário só do grupo 2 teria seletor/cartão negados); ids 104/105 fixos no SQL (já executado).
- Onda 1 (validador): varredura cobriu só abrir, sidebar e dropdown, sem paginar, filtrar ou salvar; `adianti_right_panel` ainda em 16 *Form.php (escopo T-14 a T-18); comparação com os mocks fica para as ondas 2 e 3.

## Riscos
- `EncounterView.php` (1328 linhas) concentra autosave, ditado, `__adianti_goto_page` e os fixes da fase 08 → um único agente (Yoda) com critério de grep + fluxo real no gate; o Review Focus cobre autosave com etapa oculta.
- Remover o right panel de 21 formulários quebra ações de "Fechar"/`closeRightPanel` e `register_state=false` → cada lote troca "Fechar" por voltar à lista e é conferido por grep (`adianti_right_panel` = 0) e por um fluxo salvar/voltar no gate.
- Menu builder oculta item cujo programa não está em `system_program` → T-19 depende do DML de T-07 executado; se o usuário não aprovar o SQL, Financeiro aponta para `FinancialEntryList` e o seletor de unidade/papel do usuário ficam ocultos (registrar como ruling).
- Até T-20, rótulos novos pedidos nas ondas 2–3 aparecem em inglês → a validação visual final (T-21) acontece depois de T-20.
- Commits concorrentes na mesma árvore (até 7 agentes na onda 1): `index.lock` disputado → o agente repete o commit após alguns segundos, sem apagar o lock; commit sempre por caminho listado para não levar arquivo alheio.
- A varredura de T-21 cobre telas fora do escopo da fase (admin do Adianti, telas não tocadas): bugs pré-existentes podem alongar a onda de correção; bug em `src/lib/adianti`/`framework_hashes.php` não é corrigido (vai para Pendências).
- Integration tests inserem dados na transação e fazem rollback no banco de desenvolvimento real (convenção existente); dados criados pela UI no gate (tutor/paciente "F10") permanecem, como nas fases 08/09.

## Retomada
- Pasta: `.claude/tasks/mar-20260923-1630-fidelidade-visual-mocks/`
- Sessões: 004a97f0-faff-49ba-997a-092d97b4dffc, a4d85a4e-ac84-4bb6-b1c2-cdadb6248b2b
- Branch de trabalho: feat/fidelidade-visual-mocks (base: main)
- BASE da onda 1: 9efef4e
- Commits por onda:
  - Onda 1: BASE 9efef4e → HEAD e1e5ae6 (70211df, 646f016, a492cc2, ae079cf, 728a64a, cfcdf05, fb9dee2, 132d88f, d2989e4, a1da8e8, b325eb4, e1e5ae6)
- Último status conhecido: onda 1 fechada, T-01 a T-07 [x]; DML de T-07 executado (programas 104/105).
- Próxima onda recomendada: 2 (T-08, T-09, T-10, T-11, T-12, T-13)
