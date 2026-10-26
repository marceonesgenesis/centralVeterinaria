# Tasks

| ID | Camada | Descrição | Dependências | Paralelizável | Complexidade | Agente | Status |
|---|---|---|---|---|---|---|---|
| T-01 | backend | `StorageFactory` com driver configurável, `FallbackReadStorage` e `LazyStorage` | — | sim | média | Platão | [x] |
| T-02 | backend | `EncounterDocumentService` lê o anexo pelo `storage_provider` da linha | — | sim | média | Aang | [x] |
| T-05 | backend | `DocumentTemplateService::findById` e `DocumentTemplateForm::onEdit` usando-o | — | sim | simples | Spock | [x] |
| T-07 | frontend | Busca global do cabeçalho com pelo menos 44 px | — | sim | simples | Levi | [x] |
| T-03 | frontend | `PatientForm`, `EncounterView` e `ExamResultForm` usam a `StorageFactory` | T-01, T-02 | sim | média | Darwin | [x] |
| T-04 | docs | `.env.example` e runbooks com o driver dos anexos | T-01 | sim | simples | Gandalf | [x] |
| T-06 | qa | Testes de caracterização: dedupe do `document_ready` e "voltar" da `DocumentTemplateForm` | T-05 | sim | simples | Arquimedes | [x] |
| T-08 | qa | Validação final: SUITE, LINT, arquivos protegidos e medida no navegador | T-01, T-02, T-03, T-04, T-05, T-06, T-07 | não | simples | Yoda | [x] |

## Detalhamento

### T-01 — StorageFactory com driver configurável, FallbackReadStorage e LazyStorage

**Camada:** backend
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Platão

**Arquivos prováveis**
- `src/app/Core/Storage/StorageFactory.php`
- `src/app/Core/Storage/FallbackReadStorage.php`
- `src/app/Core/Storage/LazyStorage.php`
- `src/app/Core/Storage/DocumentStorageFactory.php`
- `src/tests/Unit/StorageFactoryTest.php`

**Interface**
- Produz: `final class StorageFactory` (namespace `CentralVet\Storage`, `private function __construct()`) com `StorageFactory::driverFromEnvironment(): string`, `StorageFactory::forWrites(TenantContext $tenant): StorageInterface`, `StorageFactory::forProvider(string $storageProvider, TenantContext $tenant): StorageInterface` e `StorageFactory::forPatientPhotos(TenantContext $tenant): StorageInterface`.
- Produz: regras de ambiente com variáveis `STORAGE_DRIVER` e `STORAGE_LOCAL_ROOT`:
  - `driverFromEnvironment()` lê `STORAGE_DRIVER` com `strtolower(trim(...))`. Com `local`, devolve `local`. Com qualquer outro valor não vazio, devolve esse valor (provider S3). Vazio ou ausente: devolve `s3` se `getenv('S3_ENDPOINT')` e `getenv('S3_BUCKET')` forem ambos não vazios, senão `local`.
  - Raiz local = `STORAGE_LOCAL_ROOT`, senão `DOCUMENT_STORAGE_LOCAL_ROOT`, senão `DocumentStorageFactory::DEFAULT_LOCAL_ROOT`.
- Produz: `forProvider('local', $t)` devolve `new LocalFilesystemStorage(<raiz local>, ObjectKeyNamespace::fromEnvironmentVariable(), $t)`. Qualquer outro provider devolve `S3CompatibleStorage::fromEnvironment($t)`.
- Produz: `forWrites($t)` devolve `new LazyStorage(fn (): StorageInterface => StorageFactory::forProvider(StorageFactory::driverFromEnvironment(), $t))`, que nunca lança na construção.
- Produz: `forPatientPhotos($t)` devolve `new FallbackReadStorage(StorageFactory::forWrites($t), fn (): StorageInterface => S3CompatibleStorage::fromEnvironment($t))` quando `driverFromEnvironment() === 'local'` e o S3 está configurado (`S3_ENDPOINT` e `S3_BUCKET` não vazios); senão devolve `forWrites($t)`.
- Produz: `final class FallbackReadStorage implements StorageInterface` com `FallbackReadStorage::__construct(StorageInterface $primary, Closure $secondary)`. O secundário é resolvido uma vez, no primeiro uso.
  - `put` e `presignedUrl` vão para o primário.
  - `get($k)` usa o primário se `$primary->exists($k)`; senão, o secundário.
  - `exists($k)` é o primário ou o secundário. Erro ao resolver ou consultar o secundário conta como `false`.
  - `delete($k)` vai para o primário se `exists` ali; senão, para o secundário.
- Produz: `final class LazyStorage implements StorageInterface` com `LazyStorage::__construct(Closure $factory)`. Os 5 métodos de `StorageInterface` delegam ao storage criado no primeiro uso (memoizado). Uma falha da fábrica sobe da operação, por exemplo `StorageException('Local storage root is not a directory')`.
- Produz: o docblock de `DocumentStorageFactory` deixa de dizer que os anexos usam `S3CompatibleStorage` direto e passa a citar `StorageFactory`. O código dela não muda.
- Consome: nada

**Teste RED**
- `src/tests/Unit/StorageFactoryTest.php` — `StorageFactory`, `FallbackReadStorage` e `LazyStorage` ainda não existem. Os testes de `driverFromEnvironment` (sem S3 → `local`; `STORAGE_DRIVER=minio` → `minio`), de `forWrites` com `STORAGE_DRIVER=local` e `STORAGE_LOCAL_ROOT` num diretório temporário fora do webroot (o `put` devolve `storageProvider` `local` e cria o arquivo sob a raiz), de `forProvider` e do fallback de leitura com dois `FakeStorage` falham por classe inexistente. Cada teste salva e restaura no `finally` as variáveis que altera com `putenv` (padrão de `LocalFilesystemStorageTest::testFactoryBuildsLocalDriverFromEnvironment`). (comando: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php | /usr/bin/grep -E 'StorageFactoryTest|Failed:'`)

**Critério de aceite**
- A SUITE filtrada mostra `PASS` em todos os testes de `StorageFactoryTest`, inclusive os cinco do Review Focus (foto no secundário, delete no secundário, root inexistente sem exceção na construção e com `StorageException` no `put`, `S3_BUCKET` vazio → `local`, `forProvider('minio', ...)` instância de `S3CompatibleStorage`), e a linha `Total:` termina com `Failed: 0`.
- `LocalFilesystemStorageTest` continua com todos os testes em `PASS`.
- LINT dos 4 PHP de `src/app/Core/Storage/` tocados imprime `No syntax errors detected`.

**Validação**
- SUITE filtrada por `StorageFactoryTest|LocalFilesystemStorageTest|Failed:` (evidência: linhas `PASS` de cada teste das duas classes e `Failed: 0`).
- Testes do Review Focus em `StorageFactoryTest`:
  - `testFallbackReadsSecondaryWhenPrimaryLacksTheKey`
  - `testFallbackDeleteGoesToSecondaryWhenPrimaryLacksTheKey`
  - `testForWritesWithMissingLocalRootThrowsOnlyOnPut`
  - `testEmptyDriverWithoutS3BucketFallsBackToLocal`
  - `testForProviderTreatsAnyNonLocalProviderAsS3`

  Evidência: as 5 linhas `PASS`.
- LINT de `app/Core/Storage/StorageFactory.php`, `FallbackReadStorage.php`, `LazyStorage.php` e `DocumentStorageFactory.php` (evidência: `No syntax errors detected` nas 4 saídas, nenhum erro novo em relação a `baseline/php-lint.txt`).

### T-02 — EncounterDocumentService lê o anexo pelo storage_provider da linha

**Camada:** backend
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Aang

**Arquivos prováveis**
- `src/app/Core/Application/EncounterDocumentService.php`
- `src/tests/Unit/EncounterDocumentServiceTest.php`

**Interface**
- Produz: `EncounterDocumentService::__construct(StorageInterface $storage, TenantContext $tenant, ?StoredObjectRepositoryInterface $objects = null, ?EncounterRepositoryInterface $encounters = null, ?Closure $readerForProvider = null)`. A closure tem a forma `fn (string $storageProvider): StorageInterface`.
- Produz: com a closure, `download()` lê com `($this->readerForProvider)((string) $row['storage_provider'])->get(<chave lógica>)`; sem ela, com `$storage`, como hoje.
  - `attach`, `discard` e `list` continuam no `$storage` (gravação).
  - A chave lógica continua sendo o recorte de `object_key` a partir de `tenant/<id>/encounter/<id>/`.
  - A closure só é chamada depois de todas as checagens de unidade, encontro e `public_id`: linha recusada não resolve storage.
- Consome: nada

**Teste RED**
- `src/tests/Unit/EncounterDocumentServiceTest.php` — `testDownloadReadsThroughTheStorageOfTheRowProvider`. Anexa com um `FakeStorage` A (a linha fica com `storage_provider` `fake`, via `toStoredObjectRow()`). Depois cria o service com um `FakeStorage` B vazio como `$storage` e uma closure que registra o provider recebido e devolve A. `download()` tem de devolver os bytes anexados, e a closure tem de receber `fake`. Hoje o 5º argumento é ignorado e a leitura vai a B, então a asserção dos bytes falha. (comando: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php | /usr/bin/grep -E 'EncounterDocumentServiceTest|Failed:'`)

**Critério de aceite**
- A SUITE filtrada mostra `PASS` em `testDownloadReadsThroughTheStorageOfTheRowProvider` e nos 12 testes já existentes de `EncounterDocumentServiceTest`, com `Failed: 0`.
- Num download recusado (outra unidade, `public_id` inexistente), a closure registra 0 chamadas: asserção no mesmo arquivo, `testRefusedDownloadNeverResolvesAReader`.

**Validação**
- SUITE filtrada por `EncounterDocumentServiceTest|Failed:` (evidência: `PASS` dos 14 testes e `Failed: 0`).
- LINT de `app/Core/Application/EncounterDocumentService.php` e `tests/Unit/EncounterDocumentServiceTest.php` (evidência: `No syntax errors detected`).

### T-05 — DocumentTemplateService::findById e DocumentTemplateForm::onEdit usando-o

**Camada:** backend
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** simples
**Agente:** Spock

**Arquivos prováveis**
- `src/app/Core/Application/DocumentTemplateService.php`
- `src/app/control/clinic/DocumentTemplateForm.php`
- `src/tests/Unit/DocumentTemplateServiceTest.php`

**Interface**
- Produz: `DocumentTemplateService::findById(int $id, string $action): DocumentTemplate`.
  - Chama `$this->authorize($action, $id)` antes de ler, como `save`.
  - Lê com `DocumentTemplateRepositoryInterface::findById(int $id): ?DocumentTemplate` (já existe; o fake está em `src/tests/Support/FakeDocumentTemplateRepository.php:34`).
  - Com `null`, lança `CrossTenantReferenceException` com a mesma mensagem que `DocumentTemplateForm::onEdit` lança hoje (`DocumentTemplateForm.php`, linha do `throw` logo depois do laço).
- Produz: `DocumentTemplateForm::onEdit` troca o laço sobre `listAll(self::ACTION_EDIT)` por `findById($template_id, self::ACTION_EDIT)`. O tratamento de exceção não muda (`CvFormat::userError`), e os docblocks da classe (linha 11) e do método (linha 148) passam a citar `findById`.
- Consome: nada

**Teste RED**
- `src/tests/Unit/DocumentTemplateServiceTest.php` — `testFindByIdReturnsTenantTemplateAndRejectsUnknownId` (devolve o template salvo pelo id; id inexistente lança `CrossTenantReferenceException`) e `testDeniedFindByIdThrows` (mesmo padrão de `testDeniedMergeThrows`). Os dois falham porque `findById` não existe. (comando: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php | /usr/bin/grep -E 'DocumentTemplateServiceTest|DocumentTemplateScreens|Failed:'`)

**Critério de aceite**
- A SUITE filtrada mostra `PASS` nos 2 testes novos, nos existentes de `DocumentTemplateServiceTest` e em `DocumentTemplateScreensIntegrationTest`, com `Failed: 0`.
- `/usr/bin/grep -n "listAll" src/app/control/clinic/DocumentTemplateForm.php` não mostra nenhuma linha.

**Validação**
- SUITE filtrada por `DocumentTemplateServiceTest|DocumentTemplateScreens|Failed:` (evidência: linhas `PASS` e `Failed: 0`).
- `/usr/bin/grep -n "listAll\|findById" src/app/control/clinic/DocumentTemplateForm.php` (evidência: só linhas com `findById`).
- LINT de `app/Core/Application/DocumentTemplateService.php` e `app/control/clinic/DocumentTemplateForm.php` (evidência: `No syntax errors detected`).

### T-07 — Busca global do cabeçalho com pelo menos 44 px

**Camada:** frontend
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** simples
**Agente:** Levi

**Arquivos prováveis**
- `src/app/templates/adminbs5/cv-components.css`
- `src/tests/Unit/TopbarTouchTargetTest.php`

**Interface**
- Produz: nada. A regra CSS é interna: `.cv-global-search__input` em `cv-components.css`, junto do bloco "Cabeçalho global" (linhas 876-887), com `height: var(--cv-touch-target)` e `min-height: var(--cv-touch-target)`. `custom.css:321` (`height: 2.5rem`) fica intocado, porque é protegido por hash.
- Consome: nada

**Teste RED**
- `src/tests/Unit/TopbarTouchTargetTest.php` — `testGlobalSearchInputIsTouchTarget`: com `finalDeclarations('.cv-global-search__input')`, a última `height` tem de ser `var(--cv-touch-target)`. Hoje é `2.5rem` (`custom.css:322`), então falha. (comando: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php | /usr/bin/grep -E 'TopbarTouchTarget|Failed:'`)

**Critério de aceite**
- A SUITE filtrada mostra `PASS` nos 4 testes de `TopbarTouchTargetTest` e `Failed: 0`.
- `git diff main -- src/app/templates/adminbs5/custom.css src/app/templates/adminbs5/layout.html` não imprime nenhuma linha.

**Validação**
- SUITE filtrada por `TopbarTouchTarget|Failed:` (evidência: 4 `PASS` e `Failed: 0`).
- `git -C /var/www/html/centralvet diff --stat main -- src/app/templates/adminbs5/` (evidência: só `cv-components.css` listado).

### T-03 — PatientForm, EncounterView e ExamResultForm usam a StorageFactory

**Camada:** frontend
**Dependências:** T-01, T-02
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Darwin

**Arquivos prováveis**
- `src/app/control/clinic/PatientForm.php`
- `src/app/control/clinic/EncounterView.php`
- `src/app/control/clinic/ExamResultForm.php`
- `src/tests/Unit/AttachmentStorageWiringTest.php`

**Interface**
- Produz: nada (só a fiação dos controllers).
  - `PatientForm::onPhoto` (linha 580) e `buildPatientService` (linha 711) passam a usar `\CentralVet\Storage\StorageFactory::forPatientPhotos($tenant_context)`.
  - `EncounterView::makeEncounterDocumentService` passa `StorageFactory::forWrites($context)` como `$storage` e, como 5º argumento, `static fn (string $storageProvider): \CentralVet\Storage\StorageInterface => \CentralVet\Storage\StorageFactory::forProvider($storageProvider, $context)`.
  - `ExamResultForm::makeEncounterDocumentService` passa `StorageFactory::forWrites($context)`; sem leitor, porque só anexa.
  - Os docblocks que citam `S3CompatibleStorage::fromEnvironment` (`PatientForm.php:23`, `EncounterView.php:2059`, `ExamResultForm.php:297`) passam a citar a `StorageFactory`.
- Consome: T-01 `StorageFactory::forWrites(TenantContext $tenant): StorageInterface`, T-01 `StorageFactory::forProvider(string $storageProvider, TenantContext $tenant): StorageInterface`, T-01 `StorageFactory::forPatientPhotos(TenantContext $tenant): StorageInterface`, T-02 `?Closure $readerForProvider = null`

**Teste RED**
- `src/tests/Unit/AttachmentStorageWiringTest.php` — lê as fontes e falha enquanto alguma condição não vale: nenhum `.php` de `app/control` e `app/Core/Application` contém `S3CompatibleStorage::fromEnvironment` (hoje há 5 ocorrências em `src/app/control/clinic`), `PatientForm.php` contém `StorageFactory::forPatientPhotos(` 2 vezes, `EncounterView.php` contém `StorageFactory::forWrites(` e `StorageFactory::forProvider(`, e `ExamResultForm.php` contém `StorageFactory::forWrites(`. (comando: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php | /usr/bin/grep -E 'AttachmentStorageWiring|Failed:'`)

**Critério de aceite**
- `/usr/bin/grep -rn "S3CompatibleStorage::fromEnvironment" src/app/control src/app/Core/Application` não imprime nenhuma linha.
- A SUITE filtrada mostra `PASS` em `AttachmentStorageWiringTest`, `EncounterDocumentServiceTest`, `PatientServiceTest` e nos testes de integração de `EncounterView`/`ExamResultForm`/`PatientForm` que já existem, com `Failed: 0`.
- LINT dos 3 controllers imprime `No syntax errors detected`.

**Validação**
- `/usr/bin/grep -rn "S3CompatibleStorage::fromEnvironment" src/app/control src/app/Core/Application; echo "exit=$?"` (evidência: nenhuma linha e `exit=1`).
- SUITE filtrada por `AttachmentStorageWiring|EncounterDocumentService|PatientService|EncounterView|ExamResult|PatientForm|Failed:` (evidência: linhas `PASS` e `Failed: 0`).
- LINT de `app/control/clinic/PatientForm.php`, `EncounterView.php` e `ExamResultForm.php` (evidência: `No syntax errors detected` nas 3 saídas).

### T-04 — .env.example e runbooks com o driver dos anexos

**Camada:** docs
**Dependências:** T-01
**Paralelizável:** sim
**Complexidade:** simples
**Agente:** Gandalf

**Arquivos prováveis**
- `.env.example`
- `docs/runbooks/shared-hosting-mysql57.md`
- `docs/runbooks/documentos.md`

**Interface**
- Produz: nada.
  - `.env.example`: o bloco de `STORAGE_DRIVER` (linha 106) explica `local` ou `s3`, o padrão automático (sem `S3_ENDPOINT`/`S3_BUCKET`, vira `local`) e `STORAGE_LOCAL_ROOT` comentado, que cai em `DOCUMENT_STORAGE_LOCAL_ROOT` quando ausente.
  - `shared-hosting-mysql57.md` (seção das linhas 282-307): anexos e fotos com `STORAGE_DRIVER=local` e raiz acima de `public_html`; os objetos antigos do S3 continuam legíveis só se as `S3_*` forem mantidas.
  - `documentos.md`: remove a pendência da linha 147 ("Os anexos existentes continuam no `STORAGE_DRIVER`...") e cita a `StorageFactory`.
- Consome: T-01 `STORAGE_DRIVER`, T-01 `STORAGE_LOCAL_ROOT`

**Teste RED**
- sem teste: só documentação e exemplo de ambiente, sem comportamento executável.

**Critério de aceite**
- `/usr/bin/grep -c "STORAGE_LOCAL_ROOT" .env.example docs/runbooks/shared-hosting-mysql57.md` imprime contagem maior ou igual a 1 para cada arquivo.
- `/usr/bin/grep -n "anexos existentes continuam no" docs/runbooks/documentos.md` não imprime nenhuma linha.
- `git diff main -- .env.example` não altera os valores ativos já existentes (`STORAGE_DRIVER=s3`, `S3_*`, `DOCUMENT_STORAGE_*`), só acrescenta comentários e a linha comentada de `STORAGE_LOCAL_ROOT`.

**Validação**
- `/usr/bin/grep -n "STORAGE_DRIVER\|STORAGE_LOCAL_ROOT" .env.example docs/runbooks/shared-hosting-mysql57.md docs/runbooks/documentos.md` (evidência: linhas com as duas variáveis nos três arquivos).
- `git -C /var/www/html/centralvet diff main -- .env.example | /usr/bin/grep -E '^-[A-Z_]+='` (evidência: nenhuma linha, ou seja, nenhuma variável ativa removida ou alterada).

### T-06 — Testes de caracterização: dedupe do document_ready e "voltar" da DocumentTemplateForm

**Camada:** qa
**Dependências:** T-05
**Paralelizável:** sim
**Complexidade:** simples
**Agente:** Arquimedes

**Arquivos prováveis**
- `src/tests/Unit/DocumentGenerationServiceTest.php`
- `src/tests/Integration/DocumentTemplateScreensIntegrationTest.php`

**Interface**
- Produz: nada.
  - `DocumentGenerationServiceTest::testNotifyingTheSameDocumentTwiceQueuesOneMessagePerChannel`: `DocumentReadyNotifier::notify` (ou a geração, que o chama) roda duas vezes para o mesmo documento, com opt-in num canal. `FakeOutboundMessageRepository` fica com exatamente 1 mensagem com `dedupe_key` `document_ready:document:<id>:<canal>`, a chave fixada na 7B.
  - `DocumentTemplateScreensIntegrationTest::testFormBackLinkReturnsToTheTemplateList`: o HTML de `show()` da `DocumentTemplateForm` contém um `href` com `index.php?class=DocumentTemplateList` num elemento com a classe `cv-touch-target` (fonte: `DocumentTemplateForm.php:76`).
- Consome: nada

**Teste RED**
- sem teste: a task só acrescenta testes de caracterização de comportamento já implementado (`insertIfNew` em `DocumentReadyNotifier.php:119` e o link de `DocumentTemplateForm.php:76`), então não há falha anterior a provar.

**Critério de aceite**
- A SUITE filtrada mostra `PASS` em `testNotifyingTheSameDocumentTwiceQueuesOneMessagePerChannel` e `testFormBackLinkReturnsToTheTemplateList`, e `Failed: 0`.
- `git diff main --stat -- src/app` não lista arquivo desta task, porque ela só toca testes.

**Validação**
- SUITE filtrada por `DocumentGenerationServiceTest|DocumentTemplateScreens|Failed:` (evidência: os 2 `PASS` novos e `Failed: 0`).
- LINT dos 2 arquivos de teste (evidência: `No syntax errors detected`).

### T-08 — Validação final: SUITE, LINT, arquivos protegidos e medida no navegador

**Camada:** qa
**Dependências:** T-01, T-02, T-03, T-04, T-05, T-06, T-07
**Paralelizável:** não
**Complexidade:** simples
**Agente:** Yoda

**Arquivos prováveis**
Nenhum: validação read-only, relatório em `reports/T-08.md`.

**Interface**
- Produz: nada
- Consome: nada

**Teste RED**
- sem teste: task de validação read-only, sem código próprio.

**Critério de aceite**
- A SUITE inteira imprime `Failed: 0` e `Total` maior ou igual a 1157 mais os testes novos das T-01, T-02, T-03, T-05, T-06 e T-07.
- LINT de todos os PHP alterados desde `main` (`git diff --name-only main -- src | /usr/bin/grep '\.php$'`) imprime `No syntax errors detected` em cada um.
- `git diff main -- src/app/templates/adminbs5/custom.css src/app/templates/adminbs5/layout.html src/app/config/framework_hashes.php` não imprime nenhuma linha.
- No Playwright em `http://127.0.0.1:8081`, depois do restart do orquestrador, `document.querySelector('#cv-global-search-input').getBoundingClientRect().height` é pelo menos 44 a 1366x768 e a 820x1180, e `#sidebar-toggle` e `.cv-topbar-icon` visíveis medem pelo menos 44x44.

**Validação**
- `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php | tail -3` (evidência: `Total: N, Passed: N, Failed: 0`).
- `/usr/bin/grep -rn "S3CompatibleStorage::fromEnvironment" src/app/control src/app/Core/Application` (evidência: nenhuma linha).
- `browser_evaluate` com as medidas acima nas duas larguras (evidência: alturas impressas, cada uma pelo menos 44).
- `docker compose exec -T mysql ... "SELECT COUNT(*) FROM centralvet.stored_object"` só com SELECT (evidência: o mesmo número de linhas do início da rodada, 9, ou seja, nenhum registro existente perdido).

## Legenda

- [ ] pendente
- [~] em andamento
- [x] concluído com evidência
- [!] bloqueado
