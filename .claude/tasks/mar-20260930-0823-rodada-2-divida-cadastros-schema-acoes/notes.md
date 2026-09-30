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
- 2026-09-30 · T-01 · entre as ondas 1 e 2 — Com aprovação SQL do usuário, o orquestrador aplicou a 0007 (MIGRATION_DB_USER, checksum 9ef0242d0f4c94986141e331338e951c8a7ce28ac62540c573f8cfef243dd679) e o sql/T-01-programs.sql (programas 106–108; CvShellController nos grupos 2 e 3). Ver Bloqueios (resolvido).
- 2026-09-30 · onda 2 — Ambiente: o orquestrador rebuildou app/worker, subiu o profile minio e criou o bucket `centralvet-local`. O login admin do Playwright foi feito pelo orquestrador; nenhuma credencial registrada.
- 2026-09-30 · T-12 · onda 2 — Achado HIGH (XSS armazenado via Content-Type da foto) corrigido na task por relay (4d719ca): whitelist JPEG/PNG/WEBP com getimagesize, Content-Type só da whitelist, nosniff, CSP sandbox, SVG recusado. Gate confirmou; revisor marcou conformidade "desvio" sem bloqueante (reviews/T-12.md).
- 2026-09-30 · T-15 · onda 2 — Ruling plano-mandou: a autorização por unidade fica em T-15, não em T-22. Fix loop rodada 1: serviço e repositório filtram por TenantContext::unitId(); conta de outra unidade = não encontrada. T-20 e T-22 precisam de contexto com unidade.
- 2026-09-30 · T-10 e demais · onda 2 — "Message not found" de chaves novas (copy, Import, Duplicate etc.) fica para T-23; não reprova.
- 2026-09-30 · T-17 · onda 2 — Gate de navegador do menu da fila não rodado: fila vazia e sem caminho pela UI para criar entrada. Evidência é o render do datagrid; a verificação pela UI vai para a QA de T-24. appointment_id=0 no encaixe aceito.
- 2026-09-30 · onda 2 — A varredura Playwright da validação cruzada foi considerada coberta pelos gates/complementos (console 0 error, rede sem 4xx/5xx). Os 403 de onSwitchUnit da sessão antiga não se repetiram.
- 2026-09-30 · T-18/T-19 · onda 3 — O gate de navegador de Pausar/Retomar/Finalizar, do alerta de alergia/foto e dos modelos de prescrição (salvar, aplicar, valid_until, PDF "Válida até") ficou `não rodado`: não há atendimento em andamento nem caminho pela UI para abrir um. Passa para a QA de T-24, que precisa de um atendimento de teste (criá-lo por SQL exige aprovação do usuário).
- 2026-09-30 · T-19 · onda 3 — Um modelo recém-salvo só entra no combo depois de recarregar a tela. O revisor aceitou.
- 2026-09-30 · T-21 · onda 3 — O custo no PDF vem de ProductService::listActive (desvio aceito). A contagem de linhas dentro do PDF ficou `não rodado`; o onReport responde 200 application/pdf.
- 2026-09-30 · T-22 · onda 3 — A edição do saldo para −50,00 pela UI ficou `não rodado` (classificador negou); a conversão foi provada no relatório.
- 2026-09-30 · onda 3 — "Message not found" de chaves novas fica para T-23; as chaves estão no board.
- 2026-09-30 · onda 3 — O orquestrador rebuildou o container e refez o login admin do Playwright, que caiu com o rebuild. Nenhuma credencial registrada.
- 2026-09-30 · T-23 · onda 4 — Fora do escopo por lista de arquivos, seguem como pendência: "Patient sex must be one of M, F, U" (Core/Patient.php) e as mensagens em inglês de BankAccount.
- 2026-09-30 · T-23 · onda 4 — As chaves ausentes "%s days" (VaccineProtocolForm:180) e "This entry cannot advance right now: ^1" (QueueEntryView:385) já existiam antes da rodada; viram pendência.
- 2026-09-30 · T-23 · onda 4 — Ficaram como não rodado e passam para a QA de T-24: o POST adulterado em EncounterAccountForm e os fluxos com registro em EncounterView e PrescriptionForm.
- 2026-09-30 · onda 4 — O validador rodou `git stash` por engano e desfez com `git stash pop`; o orquestrador conferiu: `git stash list` vazio, working tree só com os artefatos de <DIR>.
- 2026-09-30 · onda 4 — O orquestrador rebuildou o container e refez o login admin do Playwright. Nenhuma credencial registrada.

## Bloqueios
- Planejamento: nenhum. Antes da onda 1 o orquestrador cria a branch. Entre as ondas 1 e 2, T-01 depende da aprovação do usuário para aplicar a 0007 e o DML. O orquestrador anota aqui as contagens de antes e de depois (`product`, `patient`, `prescription`, `financial_entry`, `encounter`), o hash do backup e o SHA-256.
- Resolvido (T-01, entre as ondas 1 e 2): com aprovação SQL do usuário, backup var/backups/centralvet-20260930T122254Z.sql.gz (gzip -t ok; `make` ausente, usado ./scripts/backup.sh); migration 0007 aplicada com MIGRATION_DB_USER, checksum 9ef0242d0f4c94986141e331338e951c8a7ce28ac62540c573f8cfef243dd679; .verify.sql: product/patient/prescription/financial_entry/encounter = 2/5/2/6/5 antes e depois, 0 violações, 2 pagamentos com payment_method; sql/T-01-programs.sql aplicado (programas 106–108; group_program 109, 110). Nomes acentuados em system_program ficaram double-encoded, como o id 104; o menu exibe corretamente.

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
- [T-10] ServiceList ganhou onDuplicate, onAskDelete (static, TQuestion) e onDelete; ServiceImportForm::onImport lê tmp/<csv_file>; a cópia sai com "Message not found: copy" até T-23.
- [T-11] Product::salePriceCents()/code(), ProductRepositoryInterface::findByCode e ProductService::create/update(..., ?int $salePriceCents, ?string $code); StockSalesOverviewReader::productStocks() devolve também 'code' e 'sale_price_cents' (T-21 consome).
- [T-12] PatientService::attachPhoto/photo e constantes PHOTO_CONTENT_TYPES/PHOTO_MAX_BYTES; foto servida por PatientForm::onPhoto (static=1&key=<id>); T-18 pode reusar o src.
- [T-13] Prescription::validUntil(); PrescriptionService::create aceita 'valid_until'; PrescriptionTemplate/Service/Repository prontos (saveFromItems/listAll/findById).
- [T-14] FinancialEntry::paymentMethod() e ?string $paymentMethod por último em record/reconstitute/FinancialEntryService::record; T-20 pode ler nos recentes.
- [T-15] BankAccountService: create/update/listByUnit/findById/totalBalanceCents(unit) → ?int; restrito à unidade corrente (serviço e repositório).
- [T-16] EncounterService::pause/resume(int $id, string $action, ?DateTimeImmutable $now = null); Encounter::isPaused()/pausedAt()/pausedSeconds(); finish() de pausado soma o trecho.
- [T-17] QueueEntryView: linha tem patient_id e appointment_id (0 no encaixe); menu com Editar paciente e Editar agendamento (só se appointment_id ≠ 0).
- [T-18] EncounterView: onPause/onResume novos; Finalizar/Pausar/Retomar/onReload usam `encounter_id` (onReload/onFinish/onInlineAction aceitam `id` como fallback); autosave, anexo, retorno e resumo de IA seguem com `id`. Alerta de alergia e foto com estilo inline.
- [T-19] PrescriptionForm: onEdit removido; onAskTemplateName, onSaveTemplate (static), onApplyTemplate; campos valid_until e template_id; rascunho de cabeçalho em TSession; bloco .cv-rx-* em cv-components.css.
- [T-20] CvKpiCard ganhou deltaPercent/deltaLabel; FinancialOverviewService::recentEntries($unit, $limit, ?from, ?to); Exportar em onExport (static, CSV com csvSafe).
- [T-21] ProductList: um overview() por carga (cacheado por filtros), colunas Code/Sale price, onReport (PDF estoque-<data>.pdf).
- [T-22] BankAccountList/BankAccountForm (TPage) e aba 'bank_accounts' no CvNav; rodapé com total das contas ativas.
- [T-23] translations.json: 45 chaves novas das ondas 1–3 (dup=0, missing=0); CvFormat::userError(\Throwable) pronto e os 20 catch de CrossTenantReferenceException nos 14 controllers usam error_log + TMessage(CvFormat::userError($e)) (6db7e48); PatientForm troca a mensagem de tutor pelo texto genérico.

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
- T-10: Duplicar grava via GET sem confirmação; `getMessage()` cru do Core em inglês em onDuplicate/onDelete/onImport; `catch (DomainException)` amplo em onDelete; fallback de tenant_user copiado pela terceira vez em ServiceImportForm.
- T-11: sem teste de integração de INSERT/findByCode do ProductRepository; asserção vazia em testUpdateRejectsProductOfAnotherTenant (ProductServiceTest:98); Fake findByCode sensível a caixa e o banco não; shapes de @return de StockSalesOverviewService sem code/sale_price_cents.
- T-12: header `X-Content-Type-Options: nosniff, nosniff` duplicado (nginx + PHP, cosmético); UPDATE do repositório grava sempre as colunas da foto (um save() com Patient sem foto apagaria); correção de segurança sem teste automatizado de uploadedPhoto/onPhoto; foto antiga fica órfã no storage ao trocar; Cache-Control 300s pode mostrar foto antiga; style inline na pré-visualização.
- T-13: modelo aceita campo de item vazio e não valida tamanhos (varchar) — vira PDOException; checagem de duplicado não atômica; sem teste de integração de prescription.valid_until; listAll com N+1; mensagem de duplicado usa o nome já com trim.
- T-14: lista de formas de pagamento repetida em FinancialEntry::PAYMENT_METHODS e FinancialEntryForm (Payment::METHODS é private); sem teste de integração de payment_method no FinancialEntryRepository; gate usou receita Pix R$ 12,34, não a despesa do critério.
- T-15: sugestão de revisão sobre `(int)` silencioso em balance_cents tratada no fix loop; sem pendência aberta.
- T-16: paused_at/paused_seconds sem teste contra o banco; accumulatePause descarta em silêncio $now anterior a pausedAt, sem teste do clamp.
- T-17: render do menu por linha vem só do relatório (script não versionado); comentário em QueueEntryView.php:92 diz appointment_id nulo, mas o valor é 0.
- Validador (onda 2): o agendamento de teste 3 (paciente 2772, serviço 3) não aparece na grade da Agenda de 30/09 e não pode ser removido pela UI; enquanto existir, o serviço 3 não pode ser excluído. Investigar na QA (T-24) se é bug da AgendaView.
- Validador (onda 2): PatientForm key=999999 mantém os radios editáveis (onda 1, mantido).
- Validador (onda 2): gate de navegador do menu da fila (T-17) não rodado; verificar na QA de T-24.
- T-18: alerta de alergia e foto com `style` inline (classes sem regra CSS); foto decidida por `photoObjectKey !== null` (string vazia geraria <img> quebrado); autosave/anexar/retorno/resumo de IA ainda passam `id` e o construtor renderiza o vazio antes do método.
- T-19: onSaveTemplate poderia recarregar o combo com TCombo::reload; onRemoveItem (GET) restaura o rascunho de cabeçalho antigo; gate de navegador (COUNT=2, valid_until, PDF "Válida até") não rodado, vai para T-24.
- T-20: onExport engole Throwable sem error_log; link "Bank accounts" do KPI depende de $card->get(1); recentEntries com um só limite sem teste.
- T-21: LOW_STOCK_LIMIT também limita recentSales (separar RECENT_SALES_LIMIT); contar `<tr>` de renderReportHtml via CLI no gate da onda 4.
- T-22: mensagens do serviço em inglês por `getMessage()` sem `_t()`; toCents converte entrada não numérica em 0 em silêncio.
- Validador (onda 3): o agendamento 3 (hoje, paciente 2772, serviço 3) não aparece na Agenda nem na Fila; bug provável da AgendaView ou da fila, investigar na T-24.
- Validador (onda 3): a varredura completa de todas as telas ficou parcial.
- Validador (onda 3): gate de navegador de Pausar/Retomar/Finalizar, alergia/foto e modelos de prescrição não rodado (sem atendimento em andamento); T-24 precisa de atendimento de teste.
- T-23: "Patient sex must be one of M, F, U" (Core/Patient.php) e as mensagens em inglês de BankAccountService seguem sem tradução (fora da lista de arquivos).
- T-23: chaves ausentes já antes da rodada: "%s days" (VaccineProtocolForm:180) e "This entry cannot advance right now: ^1" (QueueEntryView:385).
- T-23: POST adulterado em EncounterAccountForm (conta #46, authorized_by_system_user_id=999999) e fluxos com registro em EncounterView e PrescriptionForm não rodados; levar à QA de T-24.
- T-23: CvFormat::userError sem teste unitário (tests/run.php; CvFormat.php:75); chave "Selected tutor was not found for your account" órfã em translations.json.

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
- Registros da onda 2: serviços 3 (R2 varredura Serviço, inativo), 5 e 6 (importados); agendamento 3; financial_entry 5947 (Pix) e 5948; payment 113 (recebível 42 "Tutor Teste Levi", agora com R$ 1,00 pago); produto 946 (code R2-001, sale_price 1990); paciente 2772 com alergia Dipirona e foto no minio tenant/1/patient/2772/photo-r2-foto.png.
- Registros da onda 3: bank_account 101 "R2 varredura Conta", saldo 100000.
- Registros da onda 4: serviço 7 "R2 varredura Imp A (cópia)", criado pelo Duplicar do serviço 5.

## Retomada
- Pasta: `.claude/tasks/mar-20260930-0823-rodada-2-divida-cadastros-schema-acoes/`
- Sessões: 72051bcd-769a-4db4-bb19-021f9565544c
- Branch de trabalho: feat/rodada-2-cadastros-schema-acoes (base: feat/fidelidade-visual-mocks)
- BASE da onda 1: d6dce7a
- Commits por onda:
  - Onda 1: BASE d6dce7a → HEAD c664c3f (b532c63, 7ad33ce, 180a03a, e521126, bc695d6, 7f449dc, f492567, fa0c143, 02e1c5f, 5681449, efaed8c, 4f197c9, 3c40dc4, 169233e, c664c3f)
  - Onda 2: BASE b16bbbd → HEAD 181aac2 (348aead, 5388404, d36ea5c, 4d446a1, 1a1099d, 4d719ca, 8ce4e81, b2a31ff, 777018f, 46494e5, edaf460, 4003ce7, 5d6b1f1, 181aac2, e419b9c, e79489f, 160526b)
  - Onda 3: BASE 37797c6 → HEAD 1927ef6 (6c04826, 0e25755, 22947f6, 052e9c2, ab837e6, 1927ef6)
  - Onda 4: BASE 62cc510 → HEAD 6db7e48 (6db7e48)
- Último status conhecido: onda 4 concluída (T-23 [x]); migration 0007 e DML aplicados.
- Próxima onda recomendada: onda 5 (T-24).
