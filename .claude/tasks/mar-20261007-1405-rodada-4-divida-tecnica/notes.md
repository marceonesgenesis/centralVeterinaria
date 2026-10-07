# Notas de execução

## Decisões tomadas
- 2026-10-07 · plano · onda 0 — **Branch e base.** A branch de trabalho é `feat/rodada-4-divida-tecnica`, nome dado pelo orquestrador e confirmado pelo usuário e já criada a partir de `origin/main` @ `0d5ac47`; o padrão da skill seria `task/rodada-4-divida-tecnica`. Base `main`, a branch de produção declarada no `CLAUDE.md` (`branches.py`: produção `main`, teste `dev`).
- 2026-10-07 · plano · onda 0 — **Driver dos anexos.** Uma `StorageFactory` nova lê `STORAGE_DRIVER`, a variável que já era dos anexos e que já vira o `storage_provider` gravado.
  - `local` → `LocalFilesystemStorage`; qualquer outro valor → `S3CompatibleStorage` com esse provider.
  - Vazio → `s3` só com `S3_ENDPOINT` e `S3_BUCKET` definidos; senão `local`.
  - Raiz: `STORAGE_LOCAL_ROOT` → `DOCUMENT_STORAGE_LOCAL_ROOT` → `/var/www/html/var/documents`, no mesmo volume `app_documents`. Não há Dockerfile, volume nem compose novos.
  - O Docker (`STORAGE_DRIVER=s3` no compose) não muda de comportamento.
- 2026-10-07 · plano · onda 0 — **Leitura de objetos antigos.**
  - Anexos indexados (atendimento e resultado de exame): o storage vem do `stored_object.storage_provider` da linha (a coluna real; a demanda dizia `provider`).
  - Foto do paciente: não tem `stored_object` nem coluna de provider. Usa `FallbackReadStorage`: o driver configurado e, se a chave não existir ali, o S3, quando ele está configurado. Isso vale também para o `delete` da foto anterior.
  - Sem migration (premissa). Na base dev há 9 linhas em `stored_object` (todas `s3`/`centralvet-local`) e 1 paciente com foto.
- 2026-10-07 · plano · onda 0 — **`forWrites` preguiçoso (`LazyStorage`).** O construtor do driver local lança se a raiz não existe. Se fosse construído na hora, a lista de anexos do `EncounterView` e a foto do paciente quebrariam numa hospedagem mal configurada; com o lazy, o erro aparece só na operação.
- 2026-10-07 · plano · onda 0 — **Deadlock 1213 dentro de transação: não tratar.** O InnoDB desfaz a transação inteira no 1213, então retentar só o `INSERT ... SELECT` de `GeneratedDocumentRepository::insertNextVersion` dentro dela é incorreto. Por isso hoje ele só retenta com `!inTransaction()`.
  - Retentar a unidade de trabalho inteira em `DocumentRequestForm::requestDocument` exigiria refazer auditoria e publicação do job, para um caso raro: dois pedidos simultâneos da mesma fonte.
  - O `onSave` já faz rollback e mostra `CvFormat::userError`, e o usuário repete o pedido.
  - `DocumentList::onRetry` é um UPDATE condicional (`requeueFailed`), com risco baixo.
  - Reavaliar se aparecer 1213 nos logs.
- 2026-10-07 · plano · onda 0 — **Pendências da 7B já resolvidas** (sem task, conferidas no código):
  - O docblock de `listStaleQueuedIds` já cita `updated_at` (e18c4ca).
  - O docblock de `PendingCenterService::countsByType` (não existe `totalsByType`) já diz "all 9 types" (eb348e1).
  - `requeueFailed() === false` está coberto por `DocumentRequestServiceTest::testRetryThatLosesTheRequeueRaceThrows`.
  - O ramo `opted_out` está coberto por `DocumentGenerationServiceTest:308,339`.
  - "Nome de profissional ausente → ''" está coberto por `DocumentSourceQueryIntegrationTest::testMissingProfessionalNameReadsAsEmptyString`.
- 2026-10-07 · plano · onda 0 — **Cabeçalho.** Menu, Notificações e Ajuda já são 44 px desde a T-25 da 7B (9ca61a9, `cv-components.css:876-887`, `TopbarTouchTargetTest`). A T-07 cobre o que sobrou no cabeçalho: a busca global, com 40 px.
- 2026-10-07 · plano · onda 0 — **Baseline.** O php-lint dos PHP que a rodada toca e dos testes deu 0 linhas, gravado em `baseline/php-lint.txt`.
- 2026-10-07 · T-01 · onda 1 — FallbackReadStorage memoiza também a falha ao resolver o secundário (get/delete relançam; exists devolve false).
- 2026-10-07 · onda 1 — Validação cruzada: ocorrências de `S3CompatibleStorage::fromEnvironment` em PatientForm:580/711, EncounterView:2068 e ExamResultForm:306 são esperadas (arquivos da T-03, onda 2); não abre fix loop.
- 2026-10-07 · T-08 · onda 1 — Pendente do usuário: E2E de anexo com STORAGE_DRIVER=local na T-08 (cria stored_object e arquivo; limpeza exige SQL aprovado), sem resposta.
- 2026-10-07 · onda 2 — A validação cruzada da onda 1 (grep `fromEnvironment`) agora fecha (exit=1), resolvida pela T-03.
- 2026-10-07 · T-06 · onda 2 — Desvio de processo: `git commit --amend --only` só de mensagem (fe565fb→c761b2a, diff vazio) com escritor paralelo ativo; o validador confirmou que nenhum commit da T-03 foi afetado. Aceito.
- 2026-10-07 · T-03 · onda 2 — Commit RED d511f1f sem linha Co-Authored-By: aceito (cosmético; entrega.py desfaz os commits internos).
- 2026-10-07 · T-08 · onda 3 — Medição de 44 px no navegador aceita como pendência para o próximo deploy em dev (container sem rebuild, sem credencial de admin autorizada); cobertura por TopbarTouchTargetTest aceita (ruling do usuário).
- 2026-10-07 · T-08 · onda 3 — E2E de anexo com STORAGE_DRIVER=local não executado (sem resposta do usuário, nenhuma escrita no banco); driver local coberto por StorageFactoryTest/AttachmentStorageWiringTest.
- 2026-10-07 · onda 3 — Sem gate do validador: nenhum commit de código na onda (HEAD = BASE); evidência vem do relatório da T-08 sobre a árvore final (SUITE 1186/1186, LINT ok, arquivos protegidos intactos, stored_object = 9).

## Bloqueios
- nenhum

## Descobertas
- **Exploração.**
  - Há 5 chamadas de `S3CompatibleStorage::fromEnvironment`: `PatientForm:580,711`, `EncounterView:2068`, `ExamResultForm:306` e o ramo `s3` de `DocumentStorageFactory`.
  - Todo anexo é servido por streaming no controller; ninguém usa `presignedUrl`.
  - `exam_result.stored_object_key` guarda a chave física sem provider. O download do resultado passa pela lista de anexos do atendimento (`stored_object`).
  - Não há PHPUnit, phpstan nem phpcs: o runner é `src/tests/run.php`, sem filtro.
  - `.cv-touch-target` (`cv-components.css:741`) usa `44px` literal em vez da variável. Fica fora do escopo.
- [T-02] `EncounterDocumentService::__construct` ganhou o 5º argumento `?Closure $readerForProvider`, usado só em `download()` após as checagens (72b954c).
- [T-01] StorageFactory/FallbackReadStorage/LazyStorage prontas em src/app/Core/Storage/ (0236011); FallbackReadStorage memoiza também a falha ao resolver o secundário.

## Pendências
- `DocumentRequestService::download` (documentos gerados) continua lendo pelo driver de `DOCUMENT_STORAGE_DRIVER`. Trocar o driver deixa os PDFs antigos ilegíveis (Excluído desta rodada).
- E2E de upload e download de anexo com `STORAGE_DRIVER=local` não está no plano. Ele cria `stored_object` e um arquivo no volume, e a limpeza depende de SQL aprovado pelo usuário (ver as perguntas do planejador).
- T-01: FallbackReadStorage: nome do parâmetro `$secondaryFactory` difere de `$secondary` do plano (sem impacto posicional).
- T-01: com driver local e raiz ausente, `$primary->exists()` lança e a foto legada no S3 fica ilegível mesmo com secundário disponível (conforme o contrato).
- T-01: `testFallbackDeleteGoesToSecondary...` usa FakeStorage cujo delete nunca lança; não prova "não lança no primário". Falta teste ponta a ponta de `forPatientPhotos()->get`.
- T-02: `testRefusedDownloadNeverResolvesAReader` não cobre recusa por anexo de outro encontro (fora do prefixo); `testRefused...` passou já no RED (guarda de regressão).
- T-05: `testFindById...` não cobre template de outro tenant; `testDeniedFindByIdThrows` não verifica leitura nem entityId pedido à policy.
- Onda 1: SUITE 1180/1180 no gate.
- Onda 2: SUITE 1186/1186 no gate.
- T-08: medida de 44 px no navegador (busca a 1366x768 e 820x1180; #sidebar-toggle e .cv-topbar-icon) pendente para o próximo deploy em dev.
- T-08: E2E de anexo com STORAGE_DRIVER=local não executado (sem resposta do usuário).
- Onda 3: SUITE 1186/1186 (relatório da T-08, sem gate).

## Riscos
- **`putenv` nos testes da T-01**: variável não restaurada contamina a SUITE inteira (`S3_*`, `STORAGE_DRIVER`). Mitigação: `try/finally` restaurando o valor original, padrão de `LocalFilesystemStorageTest`.
- **Foto legada com S3 fora do ar**: o `exists` do secundário falha e vira `false`, então `onPhoto` devolve 404 sem 500. É o mesmo comportamento de hoje sem storage.
- **Classmap autoritativo do container**: as classes novas da T-01 podem não ser vistas pelo app/worker sem rebuild. O teste usa o PSR-4 do host. O gate da T-08 inclui o restart, ou o rebuild, a cargo do orquestrador.
- **SUITEs simultâneas na onda 1** (4 escritores): falso FAIL em Redis ou deadlock. Cada agente filtra a própria classe, e o validador roda a SUITE inteira sozinho.

## Retomada
- Pasta: `.claude/tasks/mar-20261007-1405-rodada-4-divida-tecnica/`
- Sessões: c2da22ef-8644-4fa0-aa7c-cb2020291aaf
- Branch de trabalho: feat/rodada-4-divida-tecnica (base: main)
- BASE da onda 1: 0d5ac47
- Commits por onda:
- Entrega: . HEAD 039976a → BASE 0d5ac47 (commits internos desfeitos; alterações staged)
  - Onda 1: BASE 0d5ac47 → HEAD 0236011 (0236011, 18c4026, 72b954c, c297acb, 1fc1ae7, 94968b7, 0f01f1d, 91d6207)
  - Onda 2: BASE b330536 → HEAD 563edf6 (563edf6, c761b2a, d511f1f, 2910a9b)
  - Onda 3: BASE 5c8be17 → HEAD e57f4cd (só chore; T-08 sem código)
- Último status conhecido: onda 3 concluída (T-08 [x], relatório parcial aceito); SUITE 1186/1186.
- Próxima onda recomendada: nenhuma (revisão final)
