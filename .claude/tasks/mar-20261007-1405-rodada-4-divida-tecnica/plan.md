# Plano: Rodada 4 de dívida técnica — storage configurável dos anexos e pendências da 7B

## Objetivo
Fazer os anexos existentes (foto do paciente, anexos do atendimento e resultado de exame) gravarem por uma fábrica com driver configurável, local fora do webroot ou S3, para o sistema rodar na hospedagem compartilhada MySQL 5.7 sem S3, sem perder a leitura dos objetos já gravados. A rodada também fecha as pendências que ainda restam da Fase 7B e registra a decisão sobre o retry do deadlock 1213.

## Premissas
- Repositório único `/var/www/html/centralvet`. Branch de trabalho `feat/rodada-4-divida-tecnica`, já criada pelo orquestrador a partir de `origin/main` @ `0d5ac47`. Checkout compartilhado com caminho exclusivo, RED antes da implementação e trailers `Task: T-xx` / `Task: T-xx (RED)`.
- Comandos rodados a partir de `/var/www/html/centralvet` (mesmas convenções da 7B):
  - **LINT**: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php -l <arquivo relativo a src/>`.
  - **SUITE**: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php | /usr/bin/grep -E '<Classe>|Failed:'`. O runner (`src/tests/run.php`) não filtra e imprime `PASS`/`FAIL <rótulo>` e `Total: N, Passed: N, Failed: N, Skipped: N`. Os testes de integração usam `centralvet_test`. SUITEs simultâneas podem dar falso FAIL em testes de Redis ou deadlock. Na 7B o total era 1157/1157.
- Objetos já gravados continuam legíveis. Nos anexos indexados em `stored_object`, a leitura escolhe o storage pela coluna `storage_provider`: `local` vai para o driver local e qualquer outro valor (`s3`, `minio`…) vai para o S3. Só as gravações novas usam o driver configurado. Não há migração de arquivos nesta rodada.
- Driver padrão por variável de ambiente. Com `STORAGE_DRIVER` vazio ou ausente e sem S3 configurado (`S3_ENDPOINT` e `S3_BUCKET` não vazios), o driver é `local`.
- Sem migration de schema. A foto do paciente não tem linha em `stored_object` (só `patient.photo_object_key`), então a leitura dela tenta o driver configurado e, se o objeto não estiver lá, cai no S3 (ver Decisões). Não há coluna nova.
- Nenhum SQL de escrita sem aprovação do usuário (skill `sql-write-approval`).
- Regras de UI do `CLAUDE.md`: rótulos à esquerda e alvos de pelo menos 44 px. `custom.css` e `layout.html` são protegidos por `src/app/config/framework_hashes.php` (sha512); CSS novo vai em `cv-components.css`.
- **Estado real das pendências da 7B** (exploração desta fase):
  - Já resolvidos no código, sem task:
    - O docblock de `listStaleQueuedIds` já cita `updated_at` (e18c4ca).
    - O docblock de `PendingCenterService::countsByType` (o nome real; não existe `totalsByType`) já diz "all 9 types" (eb348e1).
    - `requeueFailed() === false` está coberto por `DocumentRequestServiceTest::testRetryThatLosesTheRequeueRaceThrows`.
    - O ramo `opted_out` está coberto por `DocumentGenerationServiceTest::testOptOutOnEmailBlocksItAndOptInOnWhatsAppStillQueues` e `testOptOutOnEveryChannelCreatesNoMessage`.
    - "Nome de profissional ausente → ''" está coberto por `DocumentSourceQueryIntegrationTest::testMissingProfessionalNameReadsAsEmptyString`.
  - Abertos: `find` por id no `DocumentTemplateService` (T-05) e os testes de dedupe via `insertIfNew` e do "voltar" da `DocumentTemplateForm` (T-06).
- **Cabeçalho.** Menu, Notificações e Ajuda já medem 44 px desde a T-25 da 7B (9ca61a9), pela regra de `cv-components.css:876-887` coberta pelo `TopbarTouchTargetTest`. Sobra só a busca global (`.cv-global-search__input`, `height: 2.5rem` em `custom.css:321`), que é a T-07.

## Escopo

### Incluso
- Fábrica `StorageFactory` com driver configurável (`STORAGE_DRIVER`: `local` ou S3) que reaproveita `LocalFilesystemStorage` e a raiz de `DocumentStorageFactory` (T-01).
- Leitura por `storage_provider` nos anexos do atendimento e do resultado de exame (T-02) e fallback de leitura para a foto do paciente (T-01, T-03).
- `PatientForm`, `EncounterView` e `ExamResultForm` sem `S3CompatibleStorage::fromEnvironment` (T-03).
- Documentação das variáveis no `.env.example` e nos runbooks da hospedagem compartilhada e de documentos (T-04).
- `DocumentTemplateService::findById` e `DocumentTemplateForm::onEdit` usando-o (T-05).
- Testes de caracterização: dedupe do aviso `document_ready` via `insertIfNew` e o link "voltar" da `DocumentTemplateForm` (T-06).
- Busca global do cabeçalho com pelo menos 44 px (T-07).
- Validação final: SUITE, LINT e medida no navegador (T-08).
- Decisão registrada sobre o retry do deadlock 1213 dentro de transação (Decisões; sem código).

### Excluído
- Migrar arquivos já gravados no S3 para o local.
- Leitura por `storage_provider` no download de documentos gerados (`DocumentRequestService::download` continua no driver de `DOCUMENT_STORAGE_DRIVER`).
- Migration de schema (por exemplo, coluna de provider em `patient`).
- Retry de deadlock 1213 no controller.
- Fase 8, Intelligence, Ei e Vet!.
- Alterar `custom.css`, `layout.html` ou qualquer arquivo listado em `framework_hashes.php`.

## Contexto técnico
- Camadas envolvidas: backend (Core/Storage, Core/Application), frontend (controllers Adianti, CSS), docs, qa
- Projeto/base analisada: /var/www/html/centralvet
- Integrações: S3 compatível (SigV4 própria, `S3CompatibleStorage`) e sistema de arquivos local fora do webroot

## Baseline
- php-lint: `php -l` (via LINT com `sh -c`) em `app/Core/Storage/*.php`, `app/Core/Application/{EncounterDocumentService,PatientService,DocumentTemplateService}.php`, `app/control/clinic/{PatientForm,EncounterView,ExamResultForm,DocumentTemplateForm}.php`, `tests/Unit/*.php`, `tests/Integration/*.php` e `tests/Support/*.php`, guardando só as linhas que não são `No syntax errors detected` nem `Container`, em raiz → baseline/php-lint.txt (0 linhas). Critério: nenhum erro novo em relação a `baseline/php-lint.txt`, ou seja, cada PHP tocado imprime `No syntax errors detected`.

## Exploração read-only
- Caminhos relevantes:
  - `src/app/Core/Storage/`: `StorageInterface`, `LocalFilesystemStorage`, `S3CompatibleStorage`, `DocumentStorageFactory`, `ObjectKeyNamespace`, `StoredObjectMetadata`.
  - `src/app/Core/Application/EncounterDocumentService.php`: attach, discard, list e download por `public_id`, com `object_key` físico cortado de volta à chave lógica.
  - `src/app/Core/Application/PatientService.php`: a foto fica em `patient.photo_object_key`, sem `stored_object`.
  - `src/app/control/clinic/PatientForm.php:580,711`, `EncounterView.php:2068` e `ExamResultForm.php:306`: os 3 controllers que chamam `S3CompatibleStorage::fromEnvironment`.
  - `src/app/Core/Persistence/StoredObjectRepository.php`: `findByPublicId` faz `SELECT *`, então traz `storage_provider`.
  - `src/tests/Support/FakeStorage.php` e `FakeStoredObjectRepository.php`: a linha leva `storage_provider` via `toStoredObjectRow()`.
  - `src/app/Core/Application/DocumentTemplateService.php`, `src/app/control/clinic/DocumentTemplateForm.php:151-185` (`onEdit` varre `listAll`), `DocumentTemplateRepositoryInterface::findById(int): ?DocumentTemplate`.
  - `src/app/Core/Application/DocumentReadyNotifier.php:119` (`insertIfNew`), `src/tests/Support/FakeOutboundMessageRepository.php:51`.
  - `src/app/templates/adminbs5/cv-components.css:876-887`, `custom.css:321` e `src/tests/Unit/TopbarTouchTargetTest.php`.
  - `.env.example:102-125`, `docker-compose.yml:46-58`, `docs/runbooks/shared-hosting-mysql57.md:282-307` e `docs/runbooks/documentos.md:56-70,147`.
- Padrões identificados:
  - Fábrica estática com `private __construct` (`DocumentStorageFactory`).
  - Testes com `putenv` e `Assert` próprio (`src/tests/Support/Assert.php`), fakes em memória e integração em subprocesso Adianti (`runInAdianti`).
  - Services não abrem transação; o controller abre `TTransaction::open('permission')`.
  - Mensagens de exceção em inglês.
- Scripts úteis: LINT e SUITE (Premissas). `composer test:unit` = `php tests/run.php`. Não há PHPUnit, phpstan nem phpcs.
- Riscos identificados:
  - O construtor de `LocalFilesystemStorage` lança se o root não existe. Construir o storage antes do uso quebraria a lista de anexos do `EncounterView` numa hospedagem mal configurada; por isso `forWrites` é preguiçoso (`LazyStorage`).
  - A foto do paciente não registra provider.
  - Os PDFs gerados ficam presos ao `DOCUMENT_STORAGE_DRIVER` atual (Excluído).
  - Testes que mexem em `putenv` precisam restaurar as variáveis no `finally`, senão contaminam a SUITE.

## Mapa de arquivos

| Arquivo | Responsabilidade | Ação | Tasks que tocam |
|---|---|---|---|
| `src/app/Core/Storage/StorageFactory.php` | Driver configurável para gravação e storage por provider para leitura | criar | T-01 |
| `src/app/Core/Storage/FallbackReadStorage.php` | Leitura no primário com recurso ao secundário (foto legada no S3) | criar | T-01 |
| `src/app/Core/Storage/LazyStorage.php` | Storage resolvido no primeiro uso (construção nunca lança) | criar | T-01 |
| `src/app/Core/Storage/DocumentStorageFactory.php` | Docblock: anexos agora usam `StorageFactory` | modificar | T-01 |
| `src/tests/Unit/StorageFactoryTest.php` | Testes da fábrica, do fallback e do lazy | criar | T-01 |
| `src/app/Core/Application/EncounterDocumentService.php` | download lê pelo storage do `storage_provider` da linha | modificar | T-02 |
| `src/tests/Unit/EncounterDocumentServiceTest.php` | Teste do leitor por provider | modificar | T-02 |
| `src/app/control/clinic/PatientForm.php` | Foto via `StorageFactory::forPatientPhotos` | modificar | T-03 |
| `src/app/control/clinic/EncounterView.php` | Anexos via `forWrites` + leitor `forProvider` | modificar | T-03 |
| `src/app/control/clinic/ExamResultForm.php` | Resultado de exame via `forWrites` + leitor `forProvider` | modificar | T-03 |
| `src/tests/Unit/AttachmentStorageWiringTest.php` | Nenhum controller/service chama `S3CompatibleStorage::fromEnvironment` | criar | T-03 |
| `.env.example` | `STORAGE_DRIVER`/`STORAGE_LOCAL_ROOT` documentados | modificar | T-04 |
| `docs/runbooks/shared-hosting-mysql57.md` | Anexos com driver local na hospedagem | modificar | T-04 |
| `docs/runbooks/documentos.md` | Remove a pendência "anexos continuam no STORAGE_DRIVER" | modificar | T-04 |
| `src/app/Core/Application/DocumentTemplateService.php` | `findById` com RBAC e escopo do tenant | modificar | T-05 |
| `src/app/control/clinic/DocumentTemplateForm.php` | `onEdit` usa `findById` | modificar | T-05 |
| `src/tests/Unit/DocumentTemplateServiceTest.php` | Teste de `findById` | modificar | T-05 |
| `src/tests/Unit/DocumentGenerationServiceTest.php` | Teste de dedupe do aviso via `insertIfNew` | modificar | T-06 |
| `src/tests/Integration/DocumentTemplateScreensIntegrationTest.php` | Teste do link "voltar" | modificar | T-06 |
| `src/app/templates/adminbs5/cv-components.css` | Busca global com `var(--cv-touch-target)` | modificar | T-07 |
| `src/tests/Unit/TopbarTouchTargetTest.php` | Teste da altura da busca global | modificar | T-07 |

Nenhum arquivo é tocado por mais de uma task. A T-06 só lê `DocumentTemplateForm.php` (da T-05), que fica em onda anterior.

## Decisões de arquitetura

| Decisão | Alternativas consideradas | Motivo da escolha |
|---|---|---|
| Nova `StorageFactory` lendo `STORAGE_DRIVER` (`local` ou qualquer outro valor = S3 com aquele provider); vazio → `s3` só se `S3_ENDPOINT` e `S3_BUCKET` estão definidos, senão `local` | Reusar `DocumentStorageFactory`/`DOCUMENT_STORAGE_DRIVER` para tudo; variável nova `ATTACHMENT_STORAGE_DRIVER` | `STORAGE_DRIVER` já é a variável dos anexos e já vira o `storage_provider` gravado. Docker (`STORAGE_DRIVER=s3` no compose) não muda, e a hospedagem sem S3 cai em `local` sem configurar nada. |
| Raiz local dos anexos: `STORAGE_LOCAL_ROOT`, senão `DOCUMENT_STORAGE_LOCAL_ROOT`, senão `DocumentStorageFactory::DEFAULT_LOCAL_ROOT` | Raiz e volume próprios | O volume `app_documents` já existe e é gravável. As chaves dos anexos (`tenant/<id>/patient|encounter/...`) não colidem com as de documentos, e não há Dockerfile nem volume novo. |
| Leitura de anexo indexado pelo `storage_provider` da linha, via closure `?Closure $readerForProvider` no `EncounterDocumentService` | Ler sempre do driver configurado; sondar os dois drivers | Atende a premissa (objeto antigo do S3 continua no S3) e mantém compatíveis os construtores dos testes atuais. |
| Foto do paciente: `FallbackReadStorage` (primário = driver configurado; secundário S3 só quando o driver é `local` e o S3 está configurado) | Coluna de provider em `patient` (migration); registrar `stored_object` para fotos | A foto não tem `stored_object`; sem migration, sondar com `exists()` é o único jeito de achar a foto legada. |
| `forWrites` devolve `LazyStorage` | Construir o driver na hora | Root local ausente não pode derrubar a lista de anexos nem a tela do paciente; o erro aparece só na operação, como `StorageException`. |
| Retry do deadlock 1213 dentro de transação: não tratar nesta rodada | Retentar o `INSERT ... SELECT` dentro da transação; retentar a unidade de trabalho inteira no `DocumentRequestForm::requestDocument` | O InnoDB desfaz a transação inteira no 1213, então retentar só o comando dentro dela é incorreto. Retentar a unidade inteira exige refazer auditoria e publicação na fila, para um caso raro (dois pedidos simultâneos da mesma fonte). Hoje o `onSave` faz rollback e mostra `CvFormat::userError`, e o usuário repete. `DocumentList::onRetry` é um UPDATE condicional, com risco baixo. Reavaliar se aparecer 1213 nos logs. |

## Diagrama de dependências

```text
T-01 ─┬─> T-03 ─┐
T-02 ─┘         │
T-01 ───> T-04 ─┤
T-05 ───> T-06 ─┼─> T-08
T-07 ───────────┘
```

## Estratégia de execução
- Branch de trabalho: `feat/rodada-4-divida-tecnica`
- Branch base: `main`
- Commits da onda: cada implementador commita os próprios caminhos (`git commit -- <caminhos>`); com `worktree por agente` eles chegam pelos merges de `wave<N>/T-XX`. O fechador usa a skill `new-commit --auto` só se sobrou algo sem commit e sempre registra o estado das tasks num `chore(tasks): registra commits da onda N`. Esses commits são internos: a entrega da Fase 5 (`entrega.py`) os desfaz e deixa o código staged para o desenvolvedor revisar; commit final e push só a pedido dele.
- Isolamento em ondas com edições paralelas: caminho exclusivo (checkout compartilhado; cada agente só nos arquivos da própria task e roda a SUITE filtrando a própria classe)
- Gates:
  - Ondas 1 e 2: LINT dos PHP da onda e uma SUITE inteira, sem navegador.
  - Onda 3 (T-08): SUITE, LINT e medida da busca global no Playwright em `http://127.0.0.1:8081` a 1366 e 820 px. Antes da medida, o orquestrador roda `docker compose up -d app && docker compose restart nginx` (o CSS é servido do volume; o rebuild só é preciso se o classmap autoritativo não enxergar as classes novas, como na 7B: então `docker compose build app worker && docker compose up -d app worker && docker compose restart nginx`).

## Ondas de execução

### Onda 1
- T-01, T-02, T-05, T-07

### Onda 2
- T-03, T-04, T-06

### Onda 3
- T-08

## Agentes

| Agente | subagent_type | model | Tasks |
|---|---|---|---|
| Sun Tzu — orquestrador | — | — | todas |
| Platão | general-purpose | inherit | T-01 |
| Aang | general-purpose | inherit | T-02 |
| Spock | general-purpose | inherit | T-05 |
| Levi | general-purpose | inherit | T-07 |
| Darwin | general-purpose | inherit | T-03 |
| Gandalf | geduc:documentador | sonnet | T-04 |
| Arquimedes | general-purpose | inherit | T-06 |
| Yoda | geduc:validador | sonnet | T-08 |

## Review Focus
- Foto do paciente gravada no S3 antes da troca para `STORAGE_DRIVER=local` → `forPatientPhotos(...)->get(key)` devolve os bytes do secundário quando o primário não tem a chave → T-01
- Troca de foto depois da migração para local → `delete` da chave antiga sem arquivo no primário vai para o secundário e não lança no primário → T-01
- Root local inexistente na hospedagem → `StorageFactory::forWrites` não lança na construção; o primeiro `put` lança `StorageException` → T-01
- `STORAGE_DRIVER` vazio com `S3_ENDPOINT` definido e `S3_BUCKET` vazio → `driverFromEnvironment()` devolve `local` → T-01
- Linha de `stored_object` com `storage_provider` `minio` (STORAGE_DRIVER legado) → `forProvider('minio', ...)` devolve `S3CompatibleStorage` → T-01

## Critérios gerais de aceite
- SUITE com `Failed: 0` e `Total` maior ou igual a 1157 mais os testes novos.
- Nenhum erro novo em relação a `baseline/php-lint.txt`: cada PHP tocado imprime `No syntax errors detected`.
- `/usr/bin/grep -rn "S3CompatibleStorage::fromEnvironment" src/app/control src/app/Core/Application` sem nenhuma linha.
- `git diff main -- src/app/templates/adminbs5/custom.css src/app/templates/adminbs5/layout.html src/app/config/framework_hashes.php` vazio.
