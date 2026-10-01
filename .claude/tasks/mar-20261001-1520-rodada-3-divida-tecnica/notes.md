# Notas de execução

## Decisões tomadas
- 2026-10-01 · plano · onda 0 — Fontes: `notes.md` da rodada 2 (§ Pendências, § Descobertas, § Decisões), `reviews/final.md` (duas triagens, itens `[aberta]`, e Achados), sugestões abertas de `reviews/T-62.md`, `T-63.md` e `T-64.md`, e a seção T-65 de `tasks.md` (proposta de defesa na entrada). Repositório único, checkout compartilhado, isolamento `caminho exclusivo`.
- 2026-10-01 · plano · onda 0 — Branch `feat/rodada-3-divida-tecnica` (pedido do usuário, que prevalece sobre o padrão `task/<contexto>`), a partir do HEAD de `feat/rodada-2-cadastros-schema-acoes` depois do fechamento da rodada 2; o orquestrador a cria antes da onda 1.
- 2026-10-01 · plano · onda 0 — A contagem real de `new TMessage(..., $e->getMessage())` é 173 (não ~40): 70 fora do template (68 em `control/clinic`, `SearchBox.php:64`, `log/SystemRequestLogView.php:27`) e 103 em `control/admin`/`control/communication`. A rodada cobre os 70 (T-10, T-11, T-12) e deixa os 103 do template fora (decisão do usuário). Nenhum desses arquivos está em `framework_hashes.php`.
- 2026-10-01 · plano · onda 0 — Mensagens de domínio continuam em inglês no Core; a tradução é pelo catálogo `UserMessage` (T-01) + `translations.json` (T-16). Os padrões genéricos `^1 is required` e `^1 must have at most ^2 characters` mostram o nome técnico do campo (ex.: "Campo obrigatório: scheduled_at"). É melhor que a frase inteira em inglês; trocar por rótulo amigável exigiria um mapa campo → rótulo por tela, fora desta rodada.
- 2026-10-01 · plano · onda 0 — `EncounterDocumentService::download` nega quando não recebe o `EncounterRepository` (4º argumento). O `ExamResultForm` só usa `attach`, então não precisa passá-lo.
- 2026-10-01 · plano (revisão) · onda 0 — Respostas do usuário:
  - (1) T-14 aprovada: recusar `<`/`>` nos nomes de Patient, Tutor, Service e Product na criação e na edição; na importação CSV, a linha vira `skipped`. A validação fica no caminho de gravação dos services, nunca no `reconstitute`, para os registros já gravados com `<`/`>` (payloads R2) continuarem listando;
  - (2) os 103 catches do template admin/communication ficam fora; só os 70 do produto;
  - (3) criar o `centralvet_test` com as migrations. T-05 redige `scripts/test-db/provision.sh` e `verify.sql`; o orquestrador os aplica entre as ondas 1 e 2 com aprovação SQL na hora (backup do dev por precaução); T-19 (onda 2) troca o default da SUITE para o banco de teste e faz o `run.php` recusar o banco da aplicação.
- 2026-10-01 · plano · onda 0 — Sem migration no banco da aplicação.
- 2026-10-01 · plano (revisão) · onda 0 — Achados abertos da revisão final das ondas 13–16 da rodada 2, só código do produto:
  - (1) `CvUploaderService:60` sem checagem de extensão quando a URL não traz `extensions`, e o Drive sem MIME → T-20 (onda 1, prioridade alta, arquivos disjuntos). Ela também leva o docblock e o trim de `UploadedTmpFile`, o `unlink` do `SystemProfileForm` e o teste de `\0` nas pontas;
  - (2) `SystemMessageForm` sem `setData` e (3) `$table` sem rollback no `SystemDatabaseExplorer` já estavam em T-15 (linhas atualizadas para o HEAD 39de5ef: 208-212 e 496-501);
  - (4) `CvAvatar::placeholder` → `titleFor` já estava em T-09 (`testPlaceholderTitleGoesThroughTitleFor`);
  - (5) `SystemWikiPagePicker:24-25` é `TDBCombo` com `enableSearch` (select2), e não "select nativo" como diz `reports/T-65.md:60`. O título de wiki é editado por um perfil e exibido a outros, então o combo entra na mitigação do `CvSafeLabelTrait` → T-21. O relatório da rodada 2 não é editado (evidência histórica);
  - framework (TMultiEntry, theme.js, hash do mask) e `CvPage::header`/`cvEscapeTitle` sem teste ficam em `plan.md § Excluído`. Os GATEs `não rodado` da rodada 2 entram na varredura de T-18.
- 2026-10-01 · T-03 · onda 1 — 404 de download com corpo genérico "Anexo não encontrado" (text/plain, 21 bytes) aceito no lugar de "sem corpo", porque é idêntico para inexistente, outro atendimento e encounter 999999 (Complemento). O caso "apagado" vale pelo teste testFindByPublicIdIgnoresDeletedObject (mesmo ramo); não rodado no navegador porque exigiria UPDATE.
- 2026-10-01 · T-03 · onda 1 — RED 662c637 tocar src/tests/Support/FakeStoredObjectRepository.php, fora do bloco Teste RED, aceito (o teste precisa do Fake).
- 2026-10-01 · T-04 · onda 1 — 5e058b0 tocou PatientList.php:150 e PendingReceivableList.php:302 (só a construção do TutorService, evita ArgumentCountError): extensão de escopo aceita.
- 2026-10-01 · T-20 · onda 1 — Fix loop rodada 1 (validador): Drive aceitava HTML como .pdf → MIMES_BY_EXTENSION/mimeMatchesExtension (c0fd1b1 RED, 2fb13d0). Fix loop rodada 2 (revisor, plano-mandou): DEFAULT_EXTENSIONS fica restrita; SystemMessageForm e SystemSupportForm declaram ATTACHMENT_EXTENSIONS = DEFAULT + doc, docx, xls, xlsx, odt, ods, gif, zip, sem markup (75a411c RED, 7687d16), com os dois forms como extensão de escopo.
- 2026-10-01 · T-06 · onda 1 — Critério de Redis cumprido (RedisQueueIntegrationTest 0 FAIL em 20 saídas de 4 SUITEs simultâneas); as 2 saídas com Failed 1 foram deadlock MySQL 1213 em PrescriptionTemplateRepositoryIntegrationTest, fora do escopo: pendência de ambiente (a T-19/centralvet_test deve reduzir).
- 2026-10-01 · T-02 e T-08 · onda 1 — GATE de navegador do AppointmentForm ("01/10/2026 11:00") e do PaymentForm não rodados por limite de turnos; passam para a varredura da T-18 (validação final).
- 2026-10-01 · T-01 · onda 1 — o grep do PATH é ugrep; o comando do critério vale com `grep -F` (20).
- 2026-10-01 · orquestrador · onda 1 — Validação cruzada pós-correção: a SUITE da Re-validação 2 (T-20) rodou no HEAD final, árvore parada: 436/436.
- 2026-10-01 · orquestrador · onda 1 — Rebuild e recreate do centralvet-app-1 antes de cada gate; sessão admin no Playwright mantida pelo orquestrador.

## Bloqueios
- (resolvido) Pré-requisito da rodada 2 cumprido: T-62..T-65 `[x]`, HEAD `39de5ef` de `feat/rodada-2-cadastros-schema-acoes` e revisão final das ondas 13–16 aprovada. O orquestrador cria `feat/rodada-3-divida-tecnica` a partir de `39de5ef`.
- Entre as ondas 1 e 2: provisionamento do `centralvet_test` (`scripts/test-db/provision.sh` + `verify.sql`, de T-05). Desbloqueio: aprovação SQL específica do usuário no momento; o orquestrador registra aqui comandos, contagens e `schema_migrations`. Sem ela, T-19 fica `[!]` e a SUITE segue no banco de dev.

## Descobertas
- Já resolvidos na rodada 2 e confirmados na exploração (sem task): `json_encode` com `JSON_INVALID_UTF8_SUBSTITUTE` (`AuthorizationRequest.php:43`); N+1 de `PrescriptionTemplateService::listAll` e validação de item vazio e tamanhos (`PrescriptionTemplate.php:84,91-92,175-176`); `MAX_DATABASE` constante (`RedisConnectionFactory.php:29`); `ms-1` em `span.agenda-block-time` (`AgendaView.php:404`); docblock do `ServiceImportForm`; `FinancialEntryForm` já itera `FinancialEntry::PAYMENT_METHODS` (a lista à mão está em `PaymentForm.php:278-284`).
- Lacunas de teste já cobertas: `payment_method` (FinancialEntryRepositoryIntegrationTest:42,76), INSERT e `findByCode` de produto (ProductRepositoryIntegrationTest:36,90), `paused_*` (EncounterRepositoryIntegrationTest:51,63,97), `CvFormat::userError` (CvFormatUserErrorTest), clamp de `accumulatePause` (EncounterServiceTest:185), `ClinicalSummary` (ClinicalSummaryIntegrationTest:279,306), reschedule de outro tenant e AuthorizationDenied (AppointmentServiceTest:434,456,482).
- As chaves "%s days" e "This entry cannot advance right now: ^1" existem em `translations.json`; o defeito real é que `_t` só troca `^1..^4` (`lib/util/ApplicationTranslator.php:148`), então "%s days" sai literal (T-11 troca para `^1 days`).
- Chaves órfãs: `Uploaded file was not found` está órfã (T-16 remove); `Selected tutor was not found for your account` já não existe; `Attachment not found` é usada (`EncounterView.php:1954`).
- Os testes MySQL já fazem transação + rollback (`MysqlIntegrationTestCase.php:51,54-58`) no banco de dev; não existe banco de teste. `AppointmentFormPostIntegrationTest` não grava (subprocesso só lê o form).
- `RedisQueueIntegrationTest` já usa fila única (commit 73600c2); o que sobra são timeouts curtos e `recoverDue` com backoff 0.
- `EncounterView.php` tem 32 catches e `EncounterAccountForm.php` 22; nenhum outro arquivo é tocado por duas tasks na mesma onda.
- [T-01] Catálogo i18n novo (EN → pt): Photo must be a JPEG, PNG or WEBP image; Photo must be at most 2 MB; Encounter ^1 is already finished; Bank account must belong to the current unit; Record not found; Fill in ^1 on every item; ^1 must have at most ^2 characters; ^1 is required (para a T-16 em translations.json).
- [T-01] O `grep` do PATH é ugrep 7.8.4 (`$` vira âncora em qualquer posição): use `grep -cF "\$/D'"` (20).
- [T-04] TutorService::__construct exige (TutorRepositoryInterface, TenantContext); create() ignora $data['tenant_id']. PatientList.php:150 e PendingReceivableList.php:302 também construíam TutorService (T-10/T-12 e T-14 devem usar o construtor novo).
- [T-02] Parser de data e hora é `CentralVet\Support\DateTimeInput` (src/app/Core/Support/); `Presentation\DateTimeInput::parse` só delega e perdeu MIN_YEAR/MAX_YEAR.
- [T-21] SystemWikiPage usa CvSafeLabelTrait e expõe {title_safe}; SystemWikiPagePicker usa safeSearchMask('title_safe') (commit 6d57324).
- [T-20] CvUploaderService confere a extensão sempre; sem setAllowedExtensions vale UploadedTmpFile::DEFAULT_EXTENSIONS (pdf, jpg, jpeg, png, webp, txt, csv). UploadedTmpFile::resolve/resolveForSession aparam só " \t\n\r\x0B" (byte nulo recusado). Drive usa MIMES_BY_EXTENSION + mimeMatchesExtension (ALLOWED_MIMES removida). SystemMessageForm e SystemSupportForm declaram ATTACHMENT_EXTENSIONS; EncounterView segue com DEFAULT_EXTENSIONS.
- [T-03] EncounterDocumentService::__construct ganhou o 4º argumento opcional ?EncounterRepositoryInterface; sem ele (e sem unidade selecionada) download() devolve null. EncounterView já o passa (317dac1); ExamResultForm (só attach) segue com 3 argumentos.
- [T-05] MysqlIntegrationTestCase::tearDown() lança RuntimeException se o teste encerrou a transação; subclasse que sobrescreve tearDown deve chamar parent::tearDown(). DSN vem de TestDatabase::resolveName(getenv()) (DEFAULT_NAME = null até a T-19).
- [T-06] RedisQueue::recoverDue usa sprintf('%.17g', microtime(true)) como limite do zRangeByScore (assinatura inalterada).

## Pendências
- T-02: sugestão: trava de camadas não pega `use CentralVet\Presentation;` nem import agrupado; Core/README.md não cita a camada Support.
- T-03: sugestão: ramo "system_unit_id nulo é aceito" sem asserção (EncounterDocumentServiceTest.php:212-240); docblock de StoredObjectRepositoryInterface::findByPublicId sem o filtro de disponibilidade.
- T-04: sugestão: o grep do critério só casa o teste (controllers usam nome qualificado); o PatientForm não cria tutor, então o foco de tenant fica só no TutorForm.
- T-05: sugestões: teste da guarda aceita qualquer RuntimeException (PDOException herda); senha de migration no argv via `-e MYSQL_PWD`; falha parcial do provision.sh sem instrução de recuperação; verify.sql compara só nomes de tabela; runbook sem `tenant` no Esperado; resolveName devolve '' com DB_DATABASE vazio; env_value remove só aspas duplas.
- T-06: sugestão: sem teste determinístico do arredondamento do score (score = now + epsilon); critério de 5 rodadas x 4 SUITEs não reproduzido pelo revisor (o orquestrador mediu 20 saídas).
- T-07: sugestão: `captureRuntimeException` declara `?string` mas nunca devolve null (RedisConnectionFactoryTest.php:46).
- T-20: sugestões: Interface de tasks.md fala em `mimeAllowed`/lista plana, mas a correção usa MIMES_BY_EXTENSION; EncounterView docFile sem setAllowedExtensions (sem `accept` no input); CvUpload::forget/originalName/displayName e `$upload_name` do Drive ainda usam trim() padrão.
- T-21: sugestão: sem evidência de banco (página de wiki R3 criada só no gate pelo validador).
- Validador (onda 1): escopo não verificável: T-02 (GATE do AppointmentForm "01/10/2026 11:00") e T-08 (GATE do PaymentForm) não rodados no navegador, vão para a varredura da T-18; T-06 critério de 5 rodadas x 4 SUITEs só medido pelo orquestrador.
- Ambiente: deadlock MySQL 1213 em PrescriptionTemplateRepositoryIntegrationTest sob SUITEs simultâneas no banco de dev (2 de 20 saídas); deve reduzir com o centralvet_test (T-19).
- Registros R3 criados no banco de dev: tutor 13876 `R3 tutor contexto`; wiki id 1 `<img src=x onerror=alert(1)> R3 wiki`; system_document 5–7; anexos r3-real.pdf e r3.png no atendimento 4304; 2 r3.docx em tmp/.

## Riscos
- A rodada 2 pode mudar arquivos deste plano ao fechar (T-65 mexe em `PatientForm`, `AppointmentForm`, `EncounterView`, `SaleForm` e models). Mitigação: o orquestrador confere `git -C /var/www/html/centralvet diff --stat <HEAD de hoje>..<HEAD de partida>` antes da onda 1 e, se um arquivo do Mapa mudou, as linhas citadas nas tasks são conferidas pelo implementador antes de editar.
- SUITEs simultâneas de vários agentes na onda 1 (9 tasks) podem dar falso FAIL nos testes Redis. Mitigação: o gate roda a SUITE sozinho; T-06 mede e corrige.
- Padrões genéricos de `UserMessage` podem capturar mensagens que hoje caem no fallback e mudar o texto de telas não listadas. Mitigação: ficam por último em `PATTERNS`, e STATIC é consultado antes; a varredura da onda 3 confere as telas.
- O `centralvet_test` nasce dos arquivos em disco. Migrations com checksum de zeros no arquivo (ex.: 0006) gravam zeros em `centralvet_test.schema_migrations`, e algum teste pode depender de dado que só existe no dev. Mitigação: o `verify.sql` compara as tabelas, e T-19 compara `Total`/`Skipped` com a SUITE da BASE; diferença vira pendência com a classe de teste.
- Download negado quando a unidade selecionada difere da unidade do anexo (tenant com 2 unidades). É o comportamento pretendido; o gate de T-03 roda com a unidade do atendimento.

## Retomada
- Pasta: `.claude/tasks/mar-20261001-1520-rodada-3-divida-tecnica/`
- Sessões: ce4d9a4f-5d35-46ec-a771-8ef03d42a254
- Branch de trabalho: feat/rodada-3-divida-tecnica (base: feat/rodada-2-cadastros-schema-acoes)
- BASE da onda 1: b75d7cc
- Commits por onda:
  - Onda 1: BASE b75d7cc → HEAD {HEAD} (7687d16, 75a411c, 2fb13d0, c0fd1b1, 374028d, 0a74522, 317dac1, 6731313, 9f3c639, 662c637, 9e025c9, 5e058b0, 6d57324, c592905, f301e70, 916c57c, 551f2cc, 7e2d2ef, 52b46ba, 9c29731, 4b30140, 57f0b41, 04ddd61, b44bd6e)
- Último status conhecido: onda 1 fechada com T-01..T-09, T-20 e T-21 [x]; bloqueio entre as ondas 1 e 2: provisionamento do centralvet_test pelo orquestrador, com aprovação SQL do usuário.
- Próxima onda recomendada: onda 2 (T-10, T-11, T-12, T-13, T-14, T-15, T-16, T-17, T-19) depois do provisionamento do centralvet_test
