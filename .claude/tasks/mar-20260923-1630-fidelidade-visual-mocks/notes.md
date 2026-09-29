# Notas de execução

## Decisões tomadas
- 2026-09-23 · plano · onda 0 — Respostas do usuário: Financeiro vira tela nova `FinancialOverview` (DML redigido em T-07, executado só após aprovação do SQL na hora); itens de menu sem tela real (Dashboard clínico, Cirurgias, CRM/Comunicação, Relatórios) aparecem desabilitados ("Em breve", sem navegação). O mesmo critério vale para abas sem tela (Movimentações, Categorias, Fornecedores, Relatórios, Pacotes, Preçários, Modelos), Prontuário e Ajuda.
- 2026-09-23 · plano · onda 0 — RED sem git (aceito pelo usuário): `sem teste: <motivo>` nas tasks puramente visuais; teste PHP real (`src/tests/Integration/*IntegrationTest.php`, base `MysqlIntegrationTestCase`, rollback) nas tasks de consulta T-04/T-05/T-06, com a falha comprovada pela execução da suíte colada em `## RED` do relatório antes de escrever a implementação. Campo "Commit RED" do relatório: escrever "não se aplica (sem git)".
- 2026-09-23 · plano · onda 0 — Campos do mock sem schema: não criar migration; omitir o elemento ou usar placeholder neutro (avatar com inicial/ícone de espécie) e registrar em Pendências. "Saldo em conta" → "Saldo do caixa aberto" (sessão de caixa aberta da unidade). Preço de venda/código do produto → sem coluna.
- 2026-09-23 · plano · onda 0 — Comandos do plano (rodar de `/var/www/html/centralvet`): LINT = `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php -l <arquivo>`; SUITE = `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php` (comprovado no planejamento: `Total: 155, Passed: 155, Failed: 0`); a suíte não filtra por arquivo — ler as linhas `PASS/FAIL  Integration\<Classe>::`. GATE = orquestrador reconstrói (`docker compose build app worker && docker compose up -d app worker && docker compose restart nginx`) e só então o validador usa o Playwright MCP.
- 2026-09-23 · plano · onda 0 — Implementadores não rodam `docker compose build`/`up`/`restart` nem usam o navegador Playwright MCP: são estado global compartilhado pelos agentes em paralelo.
- 2026-09-23 · plano · onda 0 — Menu: "Pacientes" → `GlobalSearchController` (PatientList exige `tutor_id`); "Prescrições" desabilitado com dica "abra pelo atendimento" (PrescriptionForm é contextual; a fase 09 removeu o item solto); "Atendimentos" → `QueueEntryView`; "Exames" → `PendingExamResultList`; "Vacinação" → `VaccinationCardView`; catálogos clínicos e itens admin/Logs do Adianti dentro de Configurações; grupo demo "Common pages" removido.
- 2026-09-23 · plano · onda 0 — `translations.json` tem escritor único por onda (T-03 na onda 1, T-20 na onda 4). Task das ondas 2–3 que precisar de chave nova usa `_t('<chave en>')` e registra no board `- [T-xx] i18n: <chave en> → <texto pt>`; até T-20 o Adianti exibe a chave em inglês.
- 2026-09-23 · plano · onda 0 — `CvShellController` registrado como programa (T-07) porque o parser do template não expõe o grupo do usuário e não existe tela de troca de unidade; ele reaproveita `ApplicationAuthenticationService::setUnit()`, que valida o vínculo do usuário com a unidade.
- 2026-09-23 · plano · onda 0 — `EncounterView`: wizard só no cliente sobre o mesmo formulário (mantém `DRAFT_FIELDS`, autosave de 20 s e finalizar enviando todos os campos); não renomear `onStart`/`onAutosave`/`onFinish` (strings de auditoria em `EncounterTimelineIntegrationTest.php:69-86`); manter o padrão `new TButton(); ->setAction(); ->setFormName('form_EncounterView_' . id)` da fase 08 (nunca `TButton::create()` com `TAction` pronta). Botão Pausar omitido (sem dado de pausa no schema).

## Bloqueios
- nenhum no planejamento. T-07 para por definição aguardando a aprovação do SQL pelo usuário; T-08, T-09 e T-19 só verificam no navegador depois do DML executado.

## Descobertas
- `{buildid}` só é substituído por `AdiantiTemplateParser` (`src/lib/adianti/core/AdiantiTemplateParser.php:61`) quando existe o arquivo `src/buildid`; hoje ele não existe, daí o cache-busting quebrado. Parser e `index.php` estão em `framework_hashes.php` → a correção vai no `docker/php/Dockerfile` (T-01).
- Chart.js está em `src/lib/independent/js/chart.umd.min.js` mas não é carregado por `libraries.html`; T-01 inclui o script no `layout.html`.
- `EncounterDocumentService::list()`/`attach()` já dão anexos reais por atendimento: a área "Fotos e anexos" do mock é atendida em `EncounterView`; na prescrição não há anexos.
- Suíte contra o código do host via `docker compose run --rm --no-deps -v src:ro` funciona sem rebuild (155/155 no planejamento).
- Baseline de lint: `php -l` em `app/control/clinic`, `app/lib/widget`, `app/Core/Application`, `app/Core/Persistence` sem erros (`baseline/php-lint.txt`, 0 linhas).

## Pendências
- Sem schema, ficam fora desta fase: preço de venda, código/barcode e imagem de produto; foto, alergia e "ativo" do paciente; validade, modelos, anexos e orientação por item da prescrição; descrição/preparo/observações e ícone de serviço; forma de pagamento/status em `financial_entry`; saldo bancário; variação vs mês anterior de "Produtos em estoque"/"Estoque baixo" (sem histórico de estoque); pausa de atendimento.
- Ações sem backend: Exportar (financeiro), Importar/Duplicar/Excluir (serviços), Salvar como modelo e Pré-visualizar (prescrição, a menos que o implementador de T-12 encontre geração de documento existente), Gerar relatório (estoque).

## Riscos
- `EncounterView.php` (1328 linhas) concentra autosave, ditado, `__adianti_goto_page` e os fixes da fase 08 → um único agente (Yoda) com critério de grep + fluxo real no gate; o Review Focus cobre autosave com etapa oculta.
- Remover o right panel de 21 formulários quebra ações de "Fechar"/`closeRightPanel` e `register_state=false` → cada lote troca "Fechar" por voltar à lista e é conferido por grep (`adianti_right_panel` = 0) e por um fluxo salvar/voltar no gate.
- Menu builder oculta item cujo programa não está em `system_program` → T-19 depende do DML de T-07 executado; se o usuário não aprovar o SQL, Financeiro aponta para `FinancialEntryList` e o seletor de unidade/papel do usuário ficam ocultos (registrar como ruling).
- Até T-20, rótulos novos pedidos nas ondas 2–3 aparecem em inglês → a validação visual final (T-21) acontece depois de T-20.
- Integration tests inserem dados na transação e fazem rollback no banco de desenvolvimento real (convenção existente); dados criados pela UI no gate (tutor/paciente "F10") permanecem, como nas fases 08/09.

## Retomada
- Pasta: `.claude/tasks/mar-20260923-1630-fidelidade-visual-mocks/`
- Branch de trabalho: não se aplica — projeto sem git (base: não se aplica)
- BASE da onda 1: {hash7}
- Commits por onda: {Onda N: BASE <hash7> → HEAD <hash7> (<hashes dos commits>)}
- Último status conhecido: {resumo}
- Próxima onda recomendada: {onda}
