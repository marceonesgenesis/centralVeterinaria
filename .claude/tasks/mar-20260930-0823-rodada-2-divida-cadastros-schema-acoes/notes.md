# Notas de execução

## Decisões tomadas
- 2026-09-30 · plano · onda 0 — Fonte: `mar-20260923-1630-fidelidade-visual-mocks/reviews/final.md § Triagem` (itens `[aberta]`), com os 4 blocos escolhidos pelo usuário. Itens de framework ou infra ficam fora por premissa: "Trace" de `layout-basic.html`, Log do PHP vazio e CRLF de `layout.html`.
- 2026-09-30 · plano · onda 0 — Branch `feat/rodada-2-cadastros-schema-acoes` (sugestão do usuário, que prevalece sobre o padrão `task/<contexto>`), a partir de `feat/fidelidade-visual-mocks` @ `d6dce7a`, criada pelo orquestrador antes da onda 1. Repositório único, checkout compartilhado e isolamento `caminho exclusivo`.
- 2026-09-30 · plano · onda 0 — Migration única `20260930_0007_rodada2_cadastros_financeiro` (T-01). É só arquivo: o orquestrador aplica depois da aprovação SQL do usuário, com backup, checksum, usuário de migration e `.verify.sql` (`docs/runbooks/migrations.md`). O DML de programas vai em `sql/T-01-programs.sql`. A onda 2 só abre depois dos dois aplicados, porque os testes de integração leem as colunas novas.
- 2026-09-30 · plano · onda 0 — Pausa por `encounter.paused_at`/`paused_seconds`, sem status novo: recriar o CHECK `encounter_status_ck` exigiria DROP/ADD (DDL não transacional) e mexeria nos filtros por `in_progress`.
- 2026-09-30 · plano · onda 0 — Foto do paciente no storage S3 existente (`S3CompatibleStorage::fromEnvironment`), com chave em `patient.photo_object_key`, servida por `PatientForm::onPhoto`. Não usa FK para `stored_object`, porque nada no projeto grava essa tabela (`EncounterDocumentService::list()` sempre vazio). Também não usa URL pré-assinada, que apontaria para o host interno do storage.
- 2026-09-30 · plano · onda 0 — `financial_entry.payment_method` é nulo nos lançamentos manuais e de contas a pagar. O backfill na migration cobre só `reference_type = 'payment'`, a partir de `category`, que continua igual (donut e `CvFormat::paymentMethod` não mudam).
- 2026-09-30 · plano · onda 0 — Saldo bancário em `bank_account.balance_cents`, informado à mão, sem conciliação nem vínculo com lançamentos.
- 2026-09-30 · plano · onda 0 — Serviço: a cópia nasce inativa; excluir só é possível sem agendamento (a FK `appointment_service_fk` é RESTRICT), e o serviço com agendamento deve ser inativado. A importação usa CSV com `;` e o cabeçalho `name;category;duration_minutes;price`.
- 2026-09-30 · plano · onda 0 — Exportar gera CSV com BOM, separador `;` e valores com vírgula. Gerar relatório gera PDF por dompdf, no padrão de `SaleForm`/`PrescriptionForm`. Os links usam o spec `'target' => '_blank'` de `CvPage::header` (T-04), sem `generator=adianti`.
- 2026-09-30 · plano · onda 0 — A mensagem de `CrossTenantReferenceException` nos 14 controllers é trocada numa task única da onda 4 (T-23, com `CvFormat::userError`). Os controllers colidem com as features das ondas 1–3, e T-23 também é o escritor único de `translations.json`.
- 2026-09-30 · plano · onda 0 — Parâmetros novos de entidade e serviço vão por último, com default: `Product::create/reconstitute`, `new Patient(`, `FinancialEntry::record`, `FinancialEntryService::record` e `PatientService::__construct`. Assim os chamadores existentes não quebram.
- 2026-09-30 · plano · onda 0 — "Itens desabilitados só no grupo 1" e "seletor negado a grupos ≠ 1" são resolvidos pelo DML de T-01 (`system_group_program` do `CvShellController` para todos os grupos), com aprovação do usuário. Na fase 10 isso tinha ficado fora como decisão de produto.

- 2026-09-30 · plano (revisão) · onda 0 — Respostas do usuário:
  - (1) as 24 tasks ficam, sem corte;
  - (2) as 4 decisões da migration estão confirmadas: pausa por `paused_at`/`paused_seconds`, foto por `photo_object_key` no S3, saldo bancário informado à mão e backfill de `payment_method` a partir de `category`;
  - (3) está aprovado dar o `CvShellController` a todos os grupos via DML de T-01. A aplicação da 0007 e do DML ainda segue o runbook, com a aprovação SQL específica na hora.
- 2026-09-30 · T-01 · onda 1 — A migration 0007 e o DML NÃO estão aplicados no banco. A aplicação exige aprovação SQL do usuário e ocorre entre as ondas 1 e 2; até lá é o bloqueio para abrir a onda 2.
- 2026-09-30 · T-03 · onda 1 — Revisor aceitou as duas escolhas: id excluído, de outro tenant ou de outro paciente devolve null; no empate de started_at conta como anterior o id menor.
- 2026-09-30 · T-07 · onda 1 — Falhas de RedisQueue durante a onda vieram de concorrência entre execuções paralelas da suíte, não de regressão: o gate deu 232/232.
- 2026-09-30 · onda 1 — O orquestrador rebuildou o container (app/worker/nginx) antes do gate de navegador.

## Bloqueios
- Planejamento: nenhum. Antes da onda 1 o orquestrador cria a branch. Entre as ondas 1 e 2, T-01 depende da aprovação do usuário para aplicar a 0007 e o DML. O orquestrador anota aqui as contagens de antes e de depois (`product`, `patient`, `prescription`, `financial_entry`, `encounter`), o hash do backup e o SHA-256.

## Descobertas
- Não há CLAUDE.md no projeto. As regras estão em `src/app/Core/README.md`, `docs/runbooks/*.md` e `src/app/database/migrations/README.md`.
- A migration 0006 foi aplicada, mas o arquivo em disco ainda tem o checksum de zeros (explorador de banco).
- `TutorRepository`, `PatientRepository` e `AppointmentRepository` já fazem UPDATE quando há id; `Tutor::withDetails()` existe e não é usado.
- `TutorService` não recebe `TenantContext`: `create()` lê `tenant_id` de `$data`, o que contraria o README do Core. Não foi corrigido nesta rodada; ver Pendências.
- `ServiceRepository`, `FakeServiceRepository` e `AbstractTenantRepository` já têm `remove()`.
- `CvPage::header` sempre põe `generator="adianti"` no link, e por isso T-04 cria o spec `'target'`.
- Baseline de lint: 316 arquivos sem erro (`baseline/php-lint.txt`, 0 linhas).
- [T-04] i18n: "Could not switch unit" traduzido para "Não foi possível trocar de unidade". `CvPage::header` aceita `'target' => '_blank'` (com rel="noopener", sem generator="adianti"); o slot do seletor tem `data-cv-label`.
- [T-03] `StockSalesOverviewService::overview(...)` disponível; `recentSales`/`lowStock` com limit <= 0 devolvem []; `ClinicalSummaryService::lastEncounter` só devolve atendimento anterior ao atual.
- [T-09] `ServiceCatalogService` tem create(active), duplicate, delete, importCsv e `CSV_HEADER`; `ServiceRepositoryInterface::hasAppointments(int)`.
- [T-09] A conexão do banco da clínica no TTransaction é 'permission', não 'centralvet'.
- [T-01] Migration 0007 redigida (b532c63) e não aplicada; DML em `sql/T-01-programs.sql`: BankAccountList=106, BankAccountForm=107, ServiceImportForm=108 (grupo 1) e CvShellController(105) para os grupos 2 e 3.
- [T-07] `PatientService::update` monta `new Patient(...)` com argumentos nomeados; T-12 deve repassar alergia e foto ali, senão o UPDATE as zera. Edição via `onEdit&key=<id>&tutor_id=<tutor>`, com onSave desviando para `saveExisting`.

## Pendências
- Fora do escopo por decisão do planejador (ver `plan.md § Excluído`):
  - preço de venda automático em `SaleForm`;
  - tela de gestão de modelos de prescrição;
  - conciliação bancária;
  - foto nas listas;
  - exportar ou importar outras telas;
  - `TutorService` sem `TenantContext`.
- T-01: cabeçalho da migration diz "4 UNIQUE", mas o DDL cria 3 (`.sql:35`).
- T-01: ids 106–108 fixos no DML; se MAX(id) mudar, o PK duplicado aborta e o operador reajusta à mão (registrar no runbook ou derivar de MAX(id)).
- T-01: `.verify.sql` ordena por `ordinal_position` sem selecioná-la; incluir no SELECT (`.verify.sql:12`).
- T-02: `json_encode` devolve false com UTF-8 inválido em `$action`; usar `JSON_INVALID_UTF8_SUBSTITUTE` (`AuthorizationRequest.php:41-44`).
- T-03: faltam testes de id excluído de outro tenant e de empate de `started_at`; relatório diz "único chamador", mas há dois (EncounterView e PrescriptionForm); o teste de `overview()` não prova varredura única.
- T-04: docblock de `CvPage::header` desalinhado; com uma unidade e sem `current`, o seletor fica sem como escolher; `target` aceita qualquer valor, não só `_blank`.
- T-06: normalização duplicada entre create() e update() em `TutorService`; faltam testes explícitos de address ''→null e document próprio.
- T-07: update() normaliza '' → null e create() não (alinhar create() depois); weight_kg com vírgula vira 12.0 em silêncio; testes não conferem createdAt nem a mensagem de sex 'X'.
- T-08: reschedule() sem testes de service_id de outro tenant, AuthorizationDenied, chave ausente e mensagem de status; `AppointmentForm` com key inexistente continua editável com Salvar.
- T-09: importCsv não limita name (190), category (60) nem teto de duration/price (PDOException no meio do import; T-10 deve envolver em transação); duplicate() pode passar de 190 caracteres; cabeçalho comparado com trim; falta teste de linha vazia no meio.
- Validador (onda 1): o datepicker desfaz o valor colocado com `fill` (só digitar e Tab funciona; comportamento do widget).
- Validador (onda 1): PatientForm mostra em inglês "Patient sex must be one of M, F, U" (anterior à onda); levar para T-23 (i18n) se couber.
- Validador (onda 1): PatientForm com key=999999 mantém os radios de espécie e sexo editáveis, mas sem botão Salvar.
- Validador (onda 1): T-05 sem contagem numérica de `<option>` da BASE; a comparação foi contra o banco.
- `sql/T-01-programs.sql` estava sem commit; entra no chore(tasks) desta onda.

## Riscos
- DDL MySQL não é transacional. Em falha parcial, o orquestrador para, inspeciona `information_schema` e não tenta de novo (runbook). O rollback preferido é restaurar o backup pré-migration.
- Colunas novas quebram os testes de integração se a 0007 não estiver aplicada. Mitigação: a onda 2 só abre depois da aplicação conferida pelo `.verify.sql`.
- Parâmetros novos nas fábricas de `Product`, `Patient` e `FinancialEntry` podem quebrar chamadores que usam argumentos posicionais. Mitigação: os parâmetros vão por último, com default, e a SUITE inclui `StockServiceTest`, `SaleServiceTest`, `QueueEntryServiceTest` e `PayableServiceTest`.
- `EncounterView.php` (1779 linhas: autosave, ditado, anexos, pausa) fica com um único agente por onda (Yoda em T-18, Platão em T-23). O gate cobre Pausar, Retomar e Finalizar.
- O storage S3 pode não estar configurado no ambiente local para a foto. Nesse caso, T-12 relata a falha de ambiente em Pendências (`LogicException('Storage not configured')` ou erro de conexão), sem contornar.
- `QueueEntryView` pode não ter caminho pela UI para criar entrada na fila (limitação da fase 10, T-29). Nesse caso, T-17 registra `[não rodado]` com o motivo.
- Volume: 24 tasks em 5 ondas, 9 delas `alta`. O usuário decidiu manter todas (2026-09-30).

## Dados de teste
- Registros "R2 varredura" criados pelo validador pela UI na onda 1: tutor id 3141 (T-06), paciente id 2772 (T-07) e agendamento id 2, remarcado para 2026-10-01 11:00 (T-08).

## Retomada
- Pasta: `.claude/tasks/mar-20260930-0823-rodada-2-divida-cadastros-schema-acoes/`
- Sessões: 72051bcd-769a-4db4-bb19-021f9565544c
- Branch de trabalho: feat/rodada-2-cadastros-schema-acoes (base: feat/fidelidade-visual-mocks)
- BASE da onda 1: d6dce7a
- Commits por onda:
  - Onda 1: BASE d6dce7a → HEAD c664c3f (b532c63, 7ad33ce, 180a03a, e521126, bc695d6, 7f449dc, f492567, fa0c143, 02e1c5f, 5681449, efaed8c, 4f197c9, 3c40dc4, 169233e, c664c3f)
- Último status conhecido: onda 1 concluída (T-01 a T-09 [x]); migration 0007 e DML redigidos, NÃO aplicados.
- Próxima onda recomendada: onda 2, só depois de aplicar a 0007 e o DML (aprovação SQL do usuário) e conferir com o `.verify.sql`.
