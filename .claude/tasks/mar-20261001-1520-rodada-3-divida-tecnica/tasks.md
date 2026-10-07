# Tasks

| ID | Camada | Descrição | Dependências | Paralelizável | Complexidade | Agente | Status |
|---|---|---|---|---|---|---|---|
| T-01 | backend | Catálogo UserMessage: modificador D em todas as regex e mensagens de domínio que hoje saem em inglês | — | sim | média | Platão | [x] |
| T-02 | backend | DateTimeInput em camada neutra (`CentralVet\Support`), sem import de Presentation no Core | — | sim | simples | Darwin | [x] |
| T-03 | backend | Anexos: findByPublicId só devolve objeto disponível; download confere unidade e existência do atendimento | — | sim | média | Jaspion | [x] |
| T-04 | backend | TutorService com TenantContext injetado (tenant não vem mais de `$data`) | — | sim | média | Athena | [x] |
| T-05 | qa | Testes MySQL: guarda contra commit fora da transação, `TestDatabase::resolveName` e script de criação do `centralvet_test` | — | sim | média | Naruto | [x] |
| T-06 | qa | RedisQueueIntegrationTest estável sob suítes simultâneas | — | sim | simples | Naruto | [x] |
| T-07 | backend | RedisConnectionFactory fecha a conexão antes de lançar; ramo select() falso com teste | — | sim | simples | Saitama | [x] |
| T-08 | backend | Lista única de formas de pagamento (`Payment::METHODS` público, `FinancialEntry::PAYMENT_METHODS` derivada) | — | sim | simples | Arquimedes | [x] |
| T-09 | qa | Lacunas de teste: reschedule com chave ausente, CvAvatar placeholder→titleFor, prescription.valid_until no banco | — | sim | simples | Sherlock | [x] |
| T-20 | backend | Uploads: extensão conferida sem `extensions` na URL, MIME no Drive, trim e docblock de tmp/ | — | sim | alta | Kratos | [x] |
| T-21 | frontend | SystemWikiPagePicker: título de wiki escapado no select2 (CvSafeLabelTrait) | — | sim | simples | Aang | [x] |
| T-10 | frontend | Escape dos catches: financeiro e vendas (30 catches) e PaymentForm com `Payment::METHODS` | T-01, T-08 | sim | média | Levi | [x] |
| T-11 | frontend | Escape dos catches: clínico, vacinas, exames e procedimentos (25 catches) e "%s days" | T-01 | sim | média | Kratos | [x] |
| T-12 | frontend | Escape dos catches: cadastros, produtos, serviços e busca (15 catches) | T-01, T-04 | sim | simples | Thanos | [x] |
| T-13 | frontend | EncounterView sem screenError (CvFormat::userError direto) | T-03 | sim | simples | Yoda | [x] |
| T-14 | backend | Defesa na entrada: recusar `<`/`>` em nomes de Patient, Tutor, Service e Product (aprovado pelo usuário) | T-01, T-04 | sim | média | Jaspion | [x] |
| T-15 | frontend | Pequenos: SystemMessageForm mantém dados no erro, SystemDatabaseExplorer sem `$table` indefinido, cv_uploads limpo no login | — | sim | simples | Maquiavel | [x] |
| T-16 | frontend | translations.json (escritor único): chaves novas, "^1 days" e remoção da chave órfã | T-01, T-11, T-14 | sim | simples | Platão | [x] |
| T-17 | qa | Trava de regressão: nenhum `new TMessage(... getMessage())` cru nos controllers da clínica | T-10, T-11, T-12 | sim | simples | Levi | [x] |
| T-19 | qa | SUITE passa a usar o banco `centralvet_test` por padrão e recusa o banco da aplicação | T-05 | sim | simples | Naruto | [x] |
| T-18 | qa | Validação final: varredura Playwright e i18n das telas tocadas | T-13, T-15, T-16, T-17, T-19, T-20, T-21 | não | média | Spock | [x] |

## Detalhamento

### T-01 — Catálogo UserMessage: modificador D e mensagens de domínio em pt

**Camada:** backend
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Platão

Fontes: `reviews/final.md` da rodada 2 (sugestão "regex sem D", UserMessage.php:36-49) e triagem `[aberta]` T-23/T-24 (mensagens de domínio em inglês). Mensagens hoje fora do catálogo (explorador, caminho:linha em `src/app/Core`): `Application/BankAccountService.php:49,171` (`Bank account must belong to the current unit {id}`), `Domain/Encounter.php:285` ("Encounter %s is already finished"), `Domain/Encounter.php:310,316,333` (o rótulo `(new)` não casa com `(\d+)`), `Application/PatientService.php:202,206` (foto), `"X {id} not found for this tenant"` (Appointment:188, Patient:134,198, Tutor:128, Product:102, Payable:110), `"{$required} is required"` (10 ocorrências, ex.: AppointmentService:88), `Domain/PrescriptionTemplate.php:92` (`items[].{$field} is required`) e `:176` (`{$field} must have at most {$max} characters`). Como `ServiceImportForm` já passa a reason dinâmica por `UserMessage::resolve` (ServiceImportForm.php:108-111), a reason de `ServiceCatalogService.php:194` passa a sair em pt sem tocar o form. Não editar `translations.json` (escritor único: T-16); as chaves estão fixadas na Interface.

**Arquivos prováveis**
- `src/app/Core/Presentation/UserMessage.php`
- `src/tests/Unit/UserMessageTest.php`

**Interface**
- Produz: todas as regex de `UserMessage::PATTERNS` terminam em `$/D` (as 14 atuais e as novas).
- Produz: as 3 regex de Encounter passam a capturar `(\d+|\(new\))` no lugar de `(\d+)`, mantendo as chaves atuais.
- Produz: `UserMessage::STATIC` ganha `'Photo must be a JPEG, PNG or WEBP image' => 'Photo must be a JPEG, PNG or WEBP image'` e `'Photo must be at most 2 MB' => 'Photo must be at most 2 MB'` (17 entradas).
- Produz: `UserMessage::PATTERNS` ganha, nesta ordem e depois das 14 atuais: `'/^Encounter (\d+|\(new\)) is already finished$/D' => 'Encounter ^1 is already finished'`; `'/^Bank account must belong to the current unit \d+$/D' => 'Bank account must belong to the current unit'`; `'/^(?:Appointment|Patient|Tutor|Product|Payable) \d+ not found for this tenant$/D' => 'Record not found'`; `'/^items\[\]\.([a-z_]+) is required$/D' => 'Fill in ^1 on every item'`; `'/^([a-z_]+) must have at most (\d+) characters$/D' => '^1 must have at most ^2 characters'`; `'/^([a-z_]+) is required$/D' => '^1 is required'` (20 padrões; os dois genéricos por último, porque STATIC é consultado antes e os específicos vêm primeiro).
- Consome: nada

**Teste RED**
- `src/tests/Unit/UserMessageTest.php` — métodos novos `testPatternsRejectTrailingNewline` (`resolve("Encounter 5 is not paused\n")` devolve null) e `testDomainMessagesOutsideTheCatalogResolve` (`'Patient 12 not found for this tenant'` → chave `Record not found` sem params; `'scheduled_at is required'` → `^1 is required` com `['scheduled_at']`; `'Encounter (new) is already paused'` → `Encounter ^1 is already paused` com `['(new)']`; `'items[].dosage is required'` → `Fill in ^1 on every item`; `'Bank account must belong to the current unit 3'` → `Bank account must belong to the current unit`) e `testCatalogHasExactlyTheContractEntries` atualizado para 17/20; falham antes porque as regex não têm D e as mensagens não estão no catálogo (comando: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php`)

**Critério de aceite**
- SUITE: `PASS  Unit\UserMessageTest::testPatternsRejectTrailingNewline`, `PASS  Unit\UserMessageTest::testDomainMessagesOutsideTheCatalogResolve` e `PASS  Unit\UserMessageTest::testCatalogHasExactlyTheContractEntries`, com `Failed: 0`.
- `grep -c "\$/D'" src/app/Core/Presentation/UserMessage.php` imprime `20`.

**Validação**
- LINT de `UserMessage.php` e `UserMessageTest.php` (evidência: 2× `No syntax errors detected`)
- SUITE (evidência: as 3 linhas `PASS  Unit\UserMessageTest::` do critério e `Failed: 0`)
- `grep -c "\$/D'" /var/www/html/centralvet/src/app/Core/Presentation/UserMessage.php` (evidência: `20`)
- Board: uma linha `- [T-01] i18n: <chave en> → <texto pt>` para cada uma das 8 chaves novas (as 2 STATIC e as 6 PATTERNS), com o texto pt de T-16

### T-02 — DateTimeInput em camada neutra

**Camada:** backend
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** simples
**Agente:** Darwin

Fonte: triagem `[aberta]` T-53 (`AppointmentService` importa `CentralVet\Presentation\DateTimeInput`, AppointmentService.php:16,98,201), contra a regra de `src/app/Core/README.md` (Application não depende de adaptadores). O parser vai para `src/app/Core/Support/DateTimeInput.php` (PSR-4 `CentralVet\` → `app/Core/`), e o de Presentation vira delegação de uma linha, para `AppointmentForm.php:3,365`, `EncounterView.php:3,1801` e `AppointmentFormPostIntegrationTest` não mudarem.

**Arquivos prováveis**
- `src/app/Core/Support/DateTimeInput.php`
- `src/app/Core/Presentation/DateTimeInput.php`
- `src/app/Core/Application/AppointmentService.php`
- `src/tests/Unit/CoreLayerDependencyTest.php`

**Interface**
- Produz: `final class CentralVet\Support\DateTimeInput` com `public static function parse(string $raw): DateTimeImmutable`, com o corpo, os formatos, o intervalo 1900–2100 e a mensagem `Invalid date and time` de hoje.
- Produz: `CentralVet\Presentation\DateTimeInput::parse(string $raw): DateTimeImmutable` devolve `\CentralVet\Support\DateTimeInput::parse($raw)` (mesma assinatura, sem lógica própria).
- Produz: `AppointmentService` importa `CentralVet\Support\DateTimeInput`.
- Consome: nada

**Teste RED**
- `src/tests/Unit/CoreLayerDependencyTest.php` — `testApplicationDomainAndPersistenceDoNotReferencePresentation` varre os `.php` de `app/Core/Application`, `app/Core/Domain` e `app/Core/Persistence` e falha se algum contém `CentralVet\Presentation\` (hoje falha em `AppointmentService.php`); `testSupportParserMatchesPresentationParser` confere que `'01/10/2026 11:00'` dá `2026-10-01 11:00` nos dois e que `'31/02/2026 11:00'` lança `InvalidArgumentException` nos dois (comando: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php`)

**Critério de aceite**
- SUITE: `PASS  Unit\CoreLayerDependencyTest::` nos 2 métodos, `PASS  Unit\DateTimeInputTest::` em todos os métodos de antes e `Failed: 0`.
- `grep -rln 'CentralVet\\Presentation' src/app/Core/Application src/app/Core/Domain src/app/Core/Persistence` não imprime nada (exit 1).

**Validação**
- LINT dos 4 arquivos (evidência: 4× `No syntax errors detected`)
- SUITE (evidência: linhas `PASS  Unit\CoreLayerDependencyTest::`, `PASS  Integration\AppointmentFormPostIntegrationTest::` e `Failed: 0`)
- `grep -rln 'CentralVet\\Presentation' /var/www/html/centralvet/src/app/Core/Application /var/www/html/centralvet/src/app/Core/Domain /var/www/html/centralvet/src/app/Core/Persistence` (evidência: saída vazia)

### T-03 — Anexos: objeto disponível, unidade e atendimento conferidos no download

**Camada:** backend
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Jaspion

Fonte: `reviews/final.md` (ondas 8–12) § Achados, duas sugestões: `StoredObjectRepository::findByPublicId` (:91-95) faz `SELECT *` só por tenant e public_id, sem `status = 'available'` nem `deleted_at IS NULL` (que `listByObjectKeyFragment` :70-76 já usa); `EncounterDocumentService::download` (:121-148) confere tenant e prefixo da chave, mas não `stored_object.system_unit_id` nem a existência do atendimento. `stored_object.system_unit_id` já é gravado por `record()` (StoredObjectRepository.php:29,48). Sem schema novo. O `EncounterView::makeEncounterDocumentService` (:2060-2066) passa o repositório de atendimento, que o arquivo já instancia em :2010.

**Arquivos prováveis**
- `src/app/Core/Persistence/StoredObjectRepository.php`
- `src/app/Core/Application/EncounterDocumentService.php`
- `src/tests/Support/FakeStoredObjectRepository.php`
- `src/tests/Unit/EncounterDocumentServiceTest.php`
- `src/tests/Integration/StoredObjectRepositoryIntegrationTest.php`
- `src/app/control/clinic/EncounterView.php`

**Interface**
- Produz: `StoredObjectRepository::findByPublicId(string $publicId): ?array` devolve null quando `status <> 'available'` ou `deleted_at IS NOT NULL`; `FakeStoredObjectRepository::findByPublicId` aplica o mesmo filtro aos campos `status`/`deleted_at` da linha.
- Produz: `EncounterDocumentService::__construct(StorageInterface $storage, TenantContext $tenant, ?StoredObjectRepositoryInterface $objects = null, ?EncounterRepositoryInterface $encounters = null)`.
- Produz: `EncounterDocumentService::download(int $encounterId, string $publicId): ?array` devolve null quando `$objects` ou `$encounters` é null, quando `TenantContext::unitId()` é null, quando `$encounters->findById($encounterId)` é null ou tem `systemUnitId()` diferente de `unitId()`, quando a linha tem `system_unit_id` não nulo diferente de `unitId()`, e nos casos de hoje (public_id desconhecido, prefixo de outro atendimento).
- Produz: `EncounterView::makeEncounterDocumentService` passa `new \CentralVet\Persistence\EncounterRepository($context, TTransaction::get())` como 4º argumento; o 404 sem corpo de `onDownloadDocument` continua para todo null.
- Consome: nada

**Teste RED**
- `src/tests/Unit/EncounterDocumentServiceTest.php`, `src/tests/Integration/StoredObjectRepositoryIntegrationTest.php` — `testDownloadRefusesAnotherUnitDeletedObjectOrMissingEncounter` (contexto `TenantContext::authenticated(7, 3, 5)`: linha com `system_unit_id` 9 → null; linha com `status` `'deleted'` → null; atendimento ausente no `FakeEncounterRepository` → null; atendimento da unidade 5 e linha da unidade 5 → bytes) e, na integração, `testFindByPublicIdIgnoresDeletedObject` (linha gravada por `record()` e marcada `status = 'deleted'` dentro da transação do teste → null); falham antes porque o download e o SELECT não filtram (comando: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php`)

**Critério de aceite**
- SUITE: `PASS  Unit\EncounterDocumentServiceTest::testDownloadRefusesAnotherUnitDeletedObjectOrMissingEncounter`, `PASS  Integration\StoredObjectRepositoryIntegrationTest::testFindByPublicIdIgnoresDeletedObject`, os métodos de antes dos dois arquivos em PASS e `Failed: 0`.
- GATE (com a unidade do atendimento selecionada no seletor): download de um anexo do atendimento 4304 (stored_object 1096) pelo `EncounterView` responde 200 `application/pdf`; o mesmo public_id com `encounter_id=999999` responde 404 sem corpo.

**Validação**
- LINT dos 6 arquivos (evidência: 6× `No syntax errors detected`)
- SUITE (evidência: as 2 linhas `PASS` do critério e `Failed: 0`)
- `SELECT COUNT(*) FROM stored_object` antes e depois do gate (evidência: o mesmo número; nenhuma linha some)
- GATE → download legítimo e `encounter_id=999999` (evidência: status 200 com `Content-Type: application/pdf`; status 404 com corpo vazio)
- Review Focus: URL de download com public_id válido e `encounter_id` de outro atendimento da mesma unidade → 404 sem corpo (evidência: status 404 no `browser_network_requests`)

### T-04 — TutorService com TenantContext

**Camada:** backend
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Athena

Fonte: pendência "TutorService sem TenantContext" (notes da rodada 2 § Descobertas; `TutorService.php:19` só recebe o repositório e `create()` lê `$data['tenant_id']` em :54). Construtores: `TutorForm.php:119,173` (passa `['tenant_id' => $tenant_context->tenantId()] + $values` em :189), `TutorList.php:145`, `GlobalSearchController.php:159`, `PatientForm.php:85` e `TutorServiceTest.php` (a partir de :24). Padrão a seguir: `PatientService.php:45` e `ServiceCatalogService.php:32,66`.

**Arquivos prováveis**
- `src/app/Core/Application/TutorService.php`
- `src/tests/Unit/TutorServiceTest.php`
- `src/app/control/clinic/TutorForm.php`
- `src/app/control/clinic/TutorList.php`
- `src/app/control/clinic/GlobalSearchController.php`
- `src/app/control/clinic/PatientForm.php`

**Interface**
- Produz: `TutorService::__construct(TutorRepositoryInterface $repository, TenantContext $context)` (`CentralVet\Tenancy\TenantContext`).
- Produz: `TutorService::create(array $data): Tutor` usa `$this->context->tenantId()` e ignora `$data['tenant_id']`; a mensagem `tenant_id is required and must be a positive integer` deixa de existir.
- Produz: os 4 controllers passam o contexto que já resolvem (`resolveTenantContext()`); `TutorForm` para de somar `tenant_id` aos valores.
- Consome: nada

**Teste RED**
- `src/tests/Unit/TutorServiceTest.php` — `testCreateUsesTenantFromContextNotFromData`: com `TenantContext::authenticated(1, 1)` e `$data['tenant_id'] = 2`, o tutor criado tem `tenantId()` 1; e `testCreateWithoutTenantIdInDataUsesContext`: sem `tenant_id` em `$data`, cria no tenant 1 sem lançar; falham antes porque o tenant vem de `$data` (comando: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php`)

**Critério de aceite**
- SUITE: os 2 métodos novos e os 16 de antes de `Unit\TutorServiceTest::` em PASS, `Failed: 0`.
- `grep -rn -A2 "new TutorService(" src/app src/tests` mostra, em cada uma das chamadas (4 controllers e o teste), o repositório e um `TenantContext` como argumentos.
- GATE: novo tutor `R3 tutor contexto` pelo `TutorForm` grava `tutor.tenant_id = 1`; editar o telefone dele grava o telefone novo no mesmo id.

**Validação**
- LINT dos 6 arquivos (evidência: 6× `No syntax errors detected`)
- SUITE (evidência: `PASS  Unit\TutorServiceTest::testCreateUsesTenantFromContextNotFromData` e `Failed: 0`)
- `SELECT id, tenant_id, phone FROM tutor WHERE full_name = 'R3 tutor contexto'` (evidência: uma linha, `tenant_id` 1, telefone editado) e `SELECT COUNT(*) FROM tutor` antes e depois (evidência: +1)
- Review Focus: `TutorForm` novo e editar, e o cadastro rápido de tutor no `PatientForm` → gravam com o tenant da sessão (evidência: SELECT acima e console 0 `error`)

### T-05 — Isolamento dos testes MySQL: guarda, nome do banco de teste e script de criação

**Camada:** qa
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Naruto

Fontes: pendências da rodada 2 ("a suíte grava no banco local", T-37) e a resposta do usuário de 2026-10-01: criar o banco `centralvet_test` com as migrations, com aprovação SQL na hora. Hoje os 15 testes MySQL abrem transação no `setUp` e fazem rollback no `tearDown` (`tests/Support/MysqlIntegrationTestCase.php:51,54-58`) no banco de dev `centralvet`. Nada detecta um commit no meio do teste, seja explícito ou implícito (DDL).

Esta task **não executa SQL de escrita**. Ela entrega três coisas: a guarda, o resolvedor do nome do banco (com o default de hoje, `DB_DATABASE`) e os arquivos de provisionamento. Quem aplica é o orquestrador, entre as ondas 1 e 2, com aprovação SQL (ver `plan.md § Estratégia de execução`). T-19 (onda 2) troca o default para `centralvet_test`.

O schema de teste reproduz o do dev, nesta ordem e sem os `.verify.sql`:
- a base Adianti (`src/app/database/permission.sql`, `communication.sql`, `log.sql`), que também semeia `system_users`, exigido por `ClinicalSummaryIntegrationTest.php:29-30`;
- `20260919_add_missing_adianti_foreign_keys.sql`;
- as migrations `0001`…`0008`.

O usuário de migration só tem privilégio em `centralvet`.* (`migrations/README.md`). Por isso o `CREATE DATABASE` e os `GRANT` em `centralvet_test`.* para `MIGRATION_DB_USER` e `MYSQL_USER` rodam uma vez com o root do container `mysql`.

**Arquivos prováveis**
- `src/tests/Support/MysqlIntegrationTestCase.php`
- `src/tests/Support/TestDatabase.php`
- `src/tests/Unit/TestDatabaseTest.php`
- `src/tests/Integration/MysqlIsolationGuardIntegrationTest.php`
- `scripts/test-db/provision.sh`
- `scripts/test-db/verify.sql`
- `docs/runbooks/tests.md`

**Interface**
- Produz: `MysqlIntegrationTestCase::tearDown()` lança `\RuntimeException('Integration test left the test transaction; writes may have been committed to the development database')` quando o `setUp` abriu a transação e `$this->pdo->inTransaction()` é false. Com a transação aberta, faz o rollback de hoje.
- Produz: `final class CentralVet\Tests\Support\TestDatabase`, com `public const DEFAULT_NAME = null` e `public static function resolveName(array $env): string`:
  - devolve `$env['TEST_DB_DATABASE']` quando não vazio;
  - senão, devolve `self::DEFAULT_NAME ?? ($env['DB_DATABASE'] ?? 'centralvet')`;
  - lança `\RuntimeException('Refusing to run: test MySQL database equals the application database (<nome>)')` quando o `$env['TEST_DB_DATABASE']` não vazio é igual a `$env['DB_DATABASE']`.
  `MysqlIntegrationTestCase` usa `TestDatabase::resolveName(getenv())` no `dbname` do DSN.
- Produz: `scripts/test-db/provision.sh` (`set -eu`, roda de `/var/www/html/centralvet`; nenhuma task o executa):
  - passo 1, com root via `docker compose exec -T mysql`: `CREATE DATABASE centralvet_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci` e `GRANT ALL PRIVILEGES ON centralvet_test.* TO` o `MIGRATION_DB_USER` e o `MYSQL_USER`;
  - passo 2, com o usuário de migration: cada arquivo da lista acima, em ordem, parando no primeiro erro;
  - recusa (exit 1) se `centralvet_test` já existir.
- Produz: `scripts/test-db/verify.sql`, só com `SELECT` em `information_schema`:
  - tabelas de `centralvet` ausentes em `centralvet_test` (esperado: nenhuma linha);
  - `COUNT(*)` de `system_users` e de `tenant` em `centralvet_test`;
  - as linhas de `centralvet_test.schema_migrations`.
- Produz: `docs/runbooks/tests.md` ganha a seção `## Banco MySQL de teste`, com a guarda, `TEST_DB_DATABASE`, o provisionamento com aprovação SQL e o checksum de zeros herdado de arquivos como a 0006.
- Consome: nada

**Teste RED**
- `src/tests/Unit/TestDatabaseTest.php`, `src/tests/Integration/MysqlIsolationGuardIntegrationTest.php` — `TestDatabaseTest`: `resolveName(['DB_DATABASE' => 'centralvet'])` = `centralvet`; `resolveName(['DB_DATABASE' => 'centralvet', 'TEST_DB_DATABASE' => 'centralvet_test'])` = `centralvet_test`; `resolveName(['DB_DATABASE' => 'centralvet', 'TEST_DB_DATABASE' => 'centralvet'])` lança `RuntimeException` com `Refusing to run: test MySQL database equals the application database (centralvet)`. `MysqlIsolationGuardIntegrationTest` não estende a base: cria `new class extends MysqlIntegrationTestCase { public function connection(): \PDO { return $this->pdo; } }`, chama `setUp()`, faz `connection()->commit()` sem escrever nada e espera `Assert::throws(\RuntimeException::class, fn () => $case->tearDown())`; um segundo método confere que o caminho normal (`setUp`, INSERT em `tutor` com nome `R3 guard`, `tearDown`) não lança e deixa `COUNT(*)` de `tutor` igual. Falham antes porque a classe não existe e o tearDown não lança (comando: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php`)

**Critério de aceite**
- SUITE: `PASS  Unit\TestDatabaseTest::` e `PASS  Integration\MysqlIsolationGuardIntegrationTest::` em todos os métodos, os demais `Integration\` em PASS e `Failed: 0`.
- `SELECT COUNT(*) FROM tutor WHERE full_name = 'R3 guard'` = 0 depois da SUITE.
- `bash -n scripts/test-db/provision.sh` termina com exit 0, e o script não foi executado: `SHOW DATABASES LIKE 'centralvet_test'` vem vazio ao fim da task.

**Validação**
- LINT dos 4 PHP (evidência: 4× `No syntax errors detected`)
- SUITE (evidência: as linhas `PASS` do critério e `Failed: 0`)
- `SELECT COUNT(*) FROM tutor WHERE full_name = 'R3 guard'` e `SELECT COUNT(*) FROM tutor` antes e depois da SUITE (evidência: `0`; o total igual)
- `bash -n /var/www/html/centralvet/scripts/test-db/provision.sh` (evidência: exit 0) e `SHOW DATABASES LIKE 'centralvet_test'` (evidência: vazio)

### T-06 — RedisQueueIntegrationTest estável sob suítes simultâneas

**Camada:** qa
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** simples
**Agente:** Naruto

Fonte: pendência "RedisQueueIntegrationTest flaky sob paralelismo". A fila fixa já virou única (commit 73600c2, `RedisQueueIntegrationTest.php:19-29`, tearDown :40-42). O que sobra, segundo a exploração: timeouts curtos de `pop` (:51, :61, :90) e `recoverDue` comparando `microtime` com backoff 0 (:79-80). A task primeiro mede (4 suítes simultâneas, 5 rodadas) e só corrige o que a medição apontar; se o defeito estiver em `RedisQueue::recoverDue` (comparação estrita do score), a correção vai no código de produção.

**Arquivos prováveis**
- `src/tests/Integration/RedisQueueIntegrationTest.php`
- `src/app/Core/Queue/RedisQueue.php`

**Interface**
- Produz: nada (contrato de `RedisQueue` inalterado; um ajuste de comparação em `recoverDue`, se houver, mantém a assinatura)
- Consome: nada

**Teste RED**
- sem teste: a falha é intermitente e depende de concorrência; a prova é a medição antes e depois com 4 suítes simultâneas, registrada no relatório

**Critério de aceite**
- Depois da correção, 5 rodadas de 4 SUITEs simultâneas: as 20 saídas trazem `PASS  Integration\RedisQueueIntegrationTest::` em todos os métodos e `Failed: 0`.
- O relatório traz a contagem de FAIL do `RedisQueueIntegrationTest` nas mesmas 5 rodadas antes da correção e a causa encontrada.

**Validação**
- LINT dos arquivos tocados (evidência: `No syntax errors detected`)
- `cd /var/www/html/centralvet && for r in 1 2 3 4 5; do for i in 1 2 3 4; do docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php > /tmp/claude-1000/r3-t06-$r-$i.txt 2>&1 & done; wait; done; grep -h "RedisQueueIntegrationTest\|^Total" /tmp/claude-1000/r3-t06-*.txt | sort | uniq -c` (evidência: 0 linhas `FAIL  Integration\RedisQueueIntegrationTest::`)

### T-07 — RedisConnectionFactory fecha a conexão antes de lançar

**Camada:** backend
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** simples
**Agente:** Saitama

Fonte: pendência T-61 (RedisConnectionFactory.php:54-55 lança sem `close()`; o ramo `select() !== true` com DB válido não tem teste). `MAX_DATABASE` já é constante (:29), nada a fazer nela. Para testar o ramo sem um Redis que recuse SELECT, `connect` aceita um cliente injetado, por último e com default.

**Arquivos prováveis**
- `src/app/Core/Redis/RedisConnectionFactory.php`
- `src/tests/Unit/RedisConnectionFactoryTest.php`

**Interface**
- Produz: `RedisConnectionFactory::connect(string $host, int $port, int $database, ?string $password, float $timeout, ?\Redis $client = null): \Redis`; com `$client` null, usa `new \Redis()` como hoje.
- Produz: quando `auth()` ou `select()` falha depois do `connect()`, chama `$redis->close()` antes de lançar a mesma `RuntimeException` de hoje.
- Consome: nada

**Teste RED**
- `src/tests/Unit/RedisConnectionFactoryTest.php` — com `new class extends \Redis` cujo `connect()` devolve true, `select()` devolve false e `close()` marca `$closed = true`, `connect('redis', 6379, 3, null, 1.0, $fake)` lança `RuntimeException` com a mensagem `Unable to select Redis database 3` e `$fake->closed` fica true; o mesmo para `auth()` false com senha `'x'` (mensagem `Unable to authenticate with the Redis backend`); falha antes porque o 6º argumento é ignorado e a conexão real com o DB 3 não lança (comando: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php`)

**Critério de aceite**
- SUITE: `PASS  Unit\RedisConnectionFactoryTest::` nos métodos novos, `PASS  Integration\RedisConnectionFactoryIntegrationTest::` nos de antes e `Failed: 0`.

**Validação**
- LINT dos 2 arquivos (evidência: 2× `No syntax errors detected`)
- SUITE (evidência: as linhas `PASS  Unit\RedisConnectionFactoryTest::` e `Failed: 0`)
- GATE: login admin em `http://127.0.0.1:8081` depois do rebuild (evidência: tela inicial carregada, sessão em Redis)

### T-08 — Lista única de formas de pagamento

**Camada:** backend
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** simples
**Agente:** Arquimedes

Fonte: pendência T-14. `Payment::METHODS` é privado (`Domain/Payment.php:43`, usado em :81 e :120) e `FinancialEntry::PAYMENT_METHODS` (`Domain/FinancialEntry.php:37`) repete a lista; `PaymentForm.php:278-284` escreve as 5 constantes à mão (o form é de T-10). `FinancialEntryForm.php:73` já itera `FinancialEntry::PAYMENT_METHODS`.

**Arquivos prováveis**
- `src/app/Core/Domain/Payment.php`
- `src/app/Core/Domain/FinancialEntry.php`
- `src/tests/Unit/PaymentMethodsTest.php`

**Interface**
- Produz: `public const Payment::METHODS` (mesmos 5 valores, mesma ordem de hoje) como fonte única.
- Produz: `FinancialEntry::PAYMENT_METHODS = Payment::METHODS` (constante derivada, pública, nome mantido).
- Consome: nada

**Teste RED**
- `src/tests/Unit/PaymentMethodsTest.php` — `testPaymentMethodsAreTheSingleSource`: `\CentralVet\Domain\Payment::METHODS` é acessível de fora, tem os 5 valores `METHOD_CASH`, `METHOD_DEBIT_CARD`, `METHOD_CREDIT_CARD`, `METHOD_PIX`, `METHOD_BANK_TRANSFER` e é idêntico (`Assert::same`) a `FinancialEntry::PAYMENT_METHODS`; falha antes com `Cannot access private constant` (comando: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php`)

**Critério de aceite**
- SUITE: `PASS  Unit\PaymentMethodsTest::testPaymentMethodsAreTheSingleSource`, `PASS  Unit\PaymentServiceTest::` em todos os métodos e `Failed: 0`.

**Validação**
- LINT dos 3 arquivos (evidência: 3× `No syntax errors detected`)
- SUITE (evidência: a linha `PASS` do critério e `Failed: 0`)

### T-09 — Lacunas de teste de comportamento existente

**Camada:** qa
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** simples
**Agente:** Sherlock

Fonte: triagem `[aberta]` T-08, T-13 e sugestão de T-64. A exploração confirmou o que já está coberto e fica fora: integração de `payment_method` (FinancialEntryRepositoryIntegrationTest:42,76), INSERT e `findByCode` de produto (ProductRepositoryIntegrationTest:36,90), `paused_*` (EncounterRepositoryIntegrationTest:51,63,97), `CvFormat::userError` (CvFormatUserErrorTest), clamp de `accumulatePause` (EncounterServiceTest:185), service de outro tenant e AuthorizationDenied no reschedule (AppointmentServiceTest:434,456,482). Faltam: reschedule sem `service_id` e sem `professional_system_user_id` (só `scheduled_at` ausente é testado), `CvAvatar::placeholder` → `titleFor` (o teste chama `titleFor` direto, CvAvatarTitleTest:28-45, e reverter `CvAvatar.php:16` não o quebra) e `prescription.valid_until` no banco (só PrescriptionServiceTest unitário). A discriminação de cada teste novo é provada por mutação **só em worktree isolada** (`git -C /var/www/html/centralvet worktree add /tmp/claude-1000/wt-T-09 HEAD`, removida ao fim), nunca no checkout compartilhado.

**Arquivos prováveis**
- `src/tests/Unit/AppointmentServiceTest.php`
- `src/tests/Unit/CvAvatarTitleTest.php`
- `src/tests/Integration/PrescriptionRepositoryIntegrationTest.php`

**Interface**
- Produz: nada (só testes)
- Consome: nada

**Teste RED**
- sem teste: a task só acrescenta cobertura a comportamento que já existe e passa; a discriminação vem da mutação na worktree isolada, registrada em `## RED`

**Critério de aceite**
- SUITE: `PASS  Unit\AppointmentServiceTest::testRescheduleRejectsMissingServiceId`, `PASS  Unit\AppointmentServiceTest::testRescheduleRejectsMissingProfessional`, `PASS  Unit\CvAvatarTitleTest::testPlaceholderTitleGoesThroughTitleFor`, `PASS  Integration\PrescriptionRepositoryIntegrationTest::testValidUntilRoundTripsThroughTheDatabase` e `Failed: 0`.
- O relatório mostra, na worktree, cada teste novo em FAIL com a mutação (ex.: `CvAvatar.php:16` revertido para `CvFormat::e($name)`) e `git -C /var/www/html/centralvet worktree list` sem `/tmp/claude-1000/wt-T-09`.

**Validação**
- LINT dos 3 arquivos (evidência: 3× `No syntax errors detected`)
- SUITE (evidência: as 4 linhas `PASS` do critério e `Failed: 0`)
- `git -C /var/www/html/centralvet worktree list` (evidência: só o checkout principal)

### T-10 — Escape dos catches: financeiro e vendas

**Camada:** frontend
**Dependências:** T-01, T-08
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Levi

Fonte: `reviews/final.md` (ondas 8–12) § Achados, "cerca de 40 `new TMessage(..., $e->getMessage())` sem escape" (self-XSS). A contagem real fora do template admin é 70 em 30 arquivos (explorador); esta task cobre os 30 de financeiro e vendas. Padrão de destino (52 usos já existentes, ex.: `AppointmentForm`): `error_log(__METHOD__ . ': ' . $e->getMessage());` seguido de `new TMessage('<tipo de hoje>', CvFormat::userError($e));`. Concatenação `_t('X') . ': ' . $e->getMessage()` vira `_t('X') . ': ' . CvFormat::userError($e)`. Não aplicar `CvFormat::e` por cima de `userError` (escape duplo). Mensagem de exceção que saía em inglês e o catálogo de T-01 cobre passa a sair em pt depois de T-16. Linhas (explorador): `EncounterAccountForm` 206, 324, 468, 493, 549, 555, 580, 652, 673; `SaleForm` 406, 479, 569, 590, 595, 657; `PaymentForm` 213, 370, 376, 406; `PayableList` 322, 360, 375, 380; `PayableForm` 198, 254; `CashSessionForm` 263; `FinancialEntryList` 262; `FinancialEntryForm` 274; `FinancialOverview` 91; `PendingReceivableList` 248. Também: `PaymentForm.php:278-284` monta o combo a partir de `Payment::METHODS`.

**Arquivos prováveis**
- `src/app/control/clinic/EncounterAccountForm.php`
- `src/app/control/clinic/SaleForm.php`
- `src/app/control/clinic/PaymentForm.php`
- `src/app/control/clinic/PayableList.php`
- `src/app/control/clinic/PayableForm.php`
- `src/app/control/clinic/CashSessionForm.php`
- `src/app/control/clinic/FinancialEntryList.php`
- `src/app/control/clinic/FinancialEntryForm.php`
- `src/app/control/clinic/FinancialOverview.php`
- `src/app/control/clinic/PendingReceivableList.php`

**Interface**
- Produz: nada (controllers)
- Consome: T-08 `public const Payment::METHODS`

**Teste RED**
- sem teste: controllers Adianti não são carregados pela suíte (premissa da rodada 2); a prova é grep, `php -r` com `init.php` e o gate, e a trava permanente é T-17

**Critério de aceite**
- `grep -nE "TMessage\(.*getMessage\(\)" <os 10 arquivos>` não imprime nada (exit 1).
- `grep -c "CvFormat::userError" src/app/control/clinic/EncounterAccountForm.php` ≥ 16 (7 de antes + 9).
- GATE: no `PaymentForm`, o combo de forma de pagamento lista as 5 formas em pt; pagar acima do saldo devedor de uma conta R2 mostra o diálogo em pt, sem tag HTML interpretada; `SaleForm` com quantidade acima do estoque de um produto R2 mostra a mensagem de estoque insuficiente em texto literal.

**Validação**
- LINT dos 10 arquivos (evidência: 10× `No syntax errors detected`)
- `grep -nE "TMessage\(.*getMessage\(\)" /var/www/html/centralvet/src/app/control/clinic/{EncounterAccountForm,SaleForm,PaymentForm,PayableList,PayableForm,CashSessionForm,FinancialEntryList,FinancialEntryForm,FinancialOverview,PendingReceivableList}.php` (evidência: saída vazia)
- SUITE (evidência: `Failed: 0`)
- GATE → `PaymentForm`, `SaleForm`, `EncounterAccountForm`, `PayableList`, `CashSessionForm` (evidência: snapshot do diálogo com texto pt, 0 dialogs JS, console 0 `error`)
- Review Focus: erro de domínio no `EncounterAccountForm` (desconto acima do subtotal) e no `SaleForm` (estoque insuficiente) com produto `<img src=x onerror=alert(1)> R3` → texto literal em pt, 0 dialogs (evidência: snapshot e `browser_handle_dialog` vazio)

### T-11 — Escape dos catches: clínico, vacinas, exames e procedimentos

**Camada:** frontend
**Dependências:** T-01
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Kratos

Mesma fonte e padrão de T-10, para os 25 catches clínicos. Linhas (explorador): `ProcedureExecutionForm` 185 (warning concatenado), 244 (concatenado), 260, 265; `ExamRequestForm` 149 (warning concatenado), 212, 217; `QueueEntryView` 160, 385 (`_t('This entry cannot advance right now: ^1', $e->getMessage())` → parâmetro `CvFormat::userError($e)`), 398; `VaccinationForm` 191 (warning), 276, 282; `ProcedureInputForm` 184, 246, 306; `VaccineProtocolForm` 199, 258, 295; `VaccinationCardView` 267; `PendingExamResultList` 261; `VaccineCatalogList` 210; `VaccineCatalogForm` 140; `ExamCatalogList` 199; `ProcedureCatalogList` 213. Também a pendência "%s days": `VaccineProtocolForm.php:180` chama `_t('%s days', …)`, mas `_t` só substitui `^1..^4` (`lib/util/ApplicationTranslator.php:148`), então a tela mostra "%s dias" literal.

**Arquivos prováveis**
- `src/app/control/clinic/ProcedureExecutionForm.php`
- `src/app/control/clinic/ExamRequestForm.php`
- `src/app/control/clinic/QueueEntryView.php`
- `src/app/control/clinic/VaccinationForm.php`
- `src/app/control/clinic/ProcedureInputForm.php`
- `src/app/control/clinic/VaccineProtocolForm.php`
- `src/app/control/clinic/VaccinationCardView.php`
- `src/app/control/clinic/PendingExamResultList.php`
- `src/app/control/clinic/VaccineCatalogList.php`
- `src/app/control/clinic/VaccineCatalogForm.php`
- `src/app/control/clinic/ExamCatalogList.php`
- `src/app/control/clinic/ProcedureCatalogList.php`

**Interface**
- Produz: `VaccineProtocolForm.php:180` usa `_t('^1 days', $entry->intervalDaysFromPrevious())`; a chave `^1 days` → `^1 dias` vai no board para T-16.
- Consome: nada

**Teste RED**
- sem teste: controllers Adianti não são carregados pela suíte; a prova é grep, `php -r` com `init.php` e o gate, e a trava permanente é T-17

**Critério de aceite**
- `grep -nE "TMessage\(.*getMessage\(\)" <os 12 arquivos>` não imprime nada (exit 1).
- `grep -n "'%s days'" src/app/control/clinic/VaccineProtocolForm.php` não imprime nada e `grep -c "'^1 days'"` no mesmo arquivo imprime `1`.
- GATE (depois de T-16): o `VaccineProtocolForm` de um protocolo com intervalo mostra "<n> dias" com o número; avançar na `QueueEntryView` uma entrada em status que não avança mostra a frase em pt com o motivo em texto literal.

**Validação**
- LINT dos 12 arquivos (evidência: 12× `No syntax errors detected`)
- `grep -nE "TMessage\(.*getMessage\(\)" /var/www/html/centralvet/src/app/control/clinic/{ProcedureExecutionForm,ExamRequestForm,QueueEntryView,VaccinationForm,ProcedureInputForm,VaccineProtocolForm,VaccinationCardView,PendingExamResultList,VaccineCatalogList,VaccineCatalogForm,ExamCatalogList,ProcedureCatalogList}.php` (evidência: saída vazia)
- SUITE (evidência: `Failed: 0`)
- GATE → `VaccineProtocolForm`, `QueueEntryView`, `VaccinationForm`, `ExamRequestForm`, `ProcedureExecutionForm` (evidência: snapshot com "dias" numérico, diálogos em pt, console 0 `error`)

### T-12 — Escape dos catches: cadastros, produtos, serviços e busca

**Camada:** frontend
**Dependências:** T-01, T-04
**Paralelizável:** sim
**Complexidade:** simples
**Agente:** Thanos

Mesma fonte e padrão de T-10, para os 15 catches restantes fora do template. Linhas (explorador): `TutorForm` 150, 217, 222; `TutorList` 183; `PatientList` 167; `GlobalSearchController` 200; `ProductList` 80, 132, 476; `ProductForm` 169 (catch do `onEdit`, sem `error_log`, o exemplo do achado); `ServiceForm` 155; `ServiceList` 255; `StockBatchForm` 234; `app/control/SearchBox.php` 64; `app/control/log/SystemRequestLogView.php` 27. Os construtores de `TutorService` desses arquivos já foram trocados por T-04 (onda 1).

**Arquivos prováveis**
- `src/app/control/clinic/TutorForm.php`
- `src/app/control/clinic/TutorList.php`
- `src/app/control/clinic/PatientList.php`
- `src/app/control/clinic/GlobalSearchController.php`
- `src/app/control/clinic/ProductList.php`
- `src/app/control/clinic/ProductForm.php`
- `src/app/control/clinic/ServiceForm.php`
- `src/app/control/clinic/ServiceList.php`
- `src/app/control/clinic/StockBatchForm.php`
- `src/app/control/SearchBox.php`
- `src/app/control/log/SystemRequestLogView.php`

**Interface**
- Produz: nada (controllers)
- Consome: nada

**Teste RED**
- sem teste: controllers Adianti não são carregados pela suíte; a prova é grep, `php -r` com `init.php` e o gate, e a trava permanente é T-17

**Critério de aceite**
- `grep -nE "TMessage\(.*getMessage\(\)" <os 11 arquivos>` não imprime nada (exit 1).
- GATE: `ProductForm` com `key=999999` mostra a mensagem de registro não encontrado em pt; salvar um produto com o nome de um produto R2 existente que contém `<b>` mostra "Já existe um produto chamado …" com a tag em texto literal.

**Validação**
- LINT dos 11 arquivos (evidência: 11× `No syntax errors detected`)
- `grep -nE "TMessage\(.*getMessage\(\)" /var/www/html/centralvet/src/app/control/clinic/{TutorForm,TutorList,PatientList,GlobalSearchController,ProductList,ProductForm,ServiceForm,ServiceList,StockBatchForm}.php /var/www/html/centralvet/src/app/control/SearchBox.php /var/www/html/centralvet/src/app/control/log/SystemRequestLogView.php` (evidência: saída vazia)
- SUITE (evidência: `Failed: 0`)
- GATE → `ProductForm`, `ServiceForm`, `TutorForm`, `ProductList`, `GlobalSearchController` (evidência: snapshot dos diálogos, 0 dialogs JS, console 0 `error`)
- Review Focus: nome repetido com `<img src=x onerror=alert(1)> R3` no `ProductForm`, `ServiceForm` e `TutorForm` → texto literal no diálogo, 0 dialogs JS (evidência: snapshot e `browser_handle_dialog` vazio)

### T-13 — EncounterView sem screenError

**Camada:** frontend
**Dependências:** T-03
**Paralelizável:** sim
**Complexidade:** simples
**Agente:** Yoda

Fonte: pendência T-60. `EncounterView::screenError` (:1598) repete a regra de PDO/`SQLSTATE[` que `CvFormat::userError` (CvFormat.php:89-97) já aplica e depois chama `userError`; chamadas em 211, 222, 407, 414, 1484, 1490, 1496, 1564, 1660, 1771, 1833, 1839, 1850 e 1911. T-03 (onda 1) mexeu só em `makeEncounterDocumentService`.

**Arquivos prováveis**
- `src/app/control/clinic/EncounterView.php`

**Interface**
- Produz: as 14 chamadas `self::screenError($e)` viram `CvFormat::userError($e)` e o método `screenError` sai.
- Consome: nada

**Teste RED**
- sem teste: controller Adianti fora da suíte; a regra de PDO já é provada por `Unit\CvFormatUserErrorTest::testDatabaseErrorBecomesGenericText`

**Critério de aceite**
- `grep -c "screenError" src/app/control/clinic/EncounterView.php` imprime `0`.
- GATE: retorno do `EncounterView` com data "abc" mostra "Data e hora inválidas"; pausar o atendimento finalizado 3408 mostra a frase em pt.

**Validação**
- LINT de `EncounterView.php` (evidência: `No syntax errors detected`)
- `grep -c "screenError" /var/www/html/centralvet/src/app/control/clinic/EncounterView.php` (evidência: `0`)
- SUITE (evidência: `PASS  Unit\CvFormatUserErrorTest::testDatabaseErrorBecomesGenericText` e `Failed: 0`)
- GATE → `EncounterView` (retorno inválido, pausa de finalizado, download de anexo) (evidência: snapshot dos diálogos, console 0 `error`)

### T-14 — Defesa na entrada: `<` e `>` recusados em nomes

**Camada:** backend
**Dependências:** T-01, T-04
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Jaspion

Aprovada pelo usuário em 2026-10-01 (proposta da T-65 da rodada 2): recusar na criação e na edição; na importação CSV, a linha vira `skipped`. Validação só na criação e na edição (caminho do service): `reconstitute`/leitura do banco não valida, para os registros que já têm `<`/`>` (payloads R2: tutor 10626, pacientes 9179/9180, service 9 `R2 <b>x</b>`) continuarem listando e abrindo. Esses registros passam a recusar a edição até alguém trocar o nome. Na importação CSV de serviços, a linha com `<`/`>` vira `skipped` com a reason resolvida pelo catálogo.

**Arquivos prováveis**
- `src/app/Core/Domain/NameText.php`
- `src/tests/Unit/NameTextTest.php`
- `src/app/Core/Application/PatientService.php`
- `src/app/Core/Application/TutorService.php`
- `src/app/Core/Application/ServiceCatalogService.php`
- `src/app/Core/Application/ProductService.php`
- `src/app/Core/Presentation/UserMessage.php`
- `src/tests/Unit/UserMessageTest.php`

**Interface**
- Produz: `final class CentralVet\Domain\NameText` com `public const MARKUP_MESSAGE = 'Name must not contain < or >'` e `public static function assertNoMarkup(string $name): void`, que lança `\InvalidArgumentException(self::MARKUP_MESSAGE)` quando o nome contém `<` ou `>`.
- Produz: `PatientService` (create/update, `name`), `TutorService` (create/update, `full_name`), `ServiceCatalogService` (create/update/duplicate e cada linha de `importCsv`, `name`) e `ProductService` (create/update, `name`) chamam `NameText::assertNoMarkup` antes de gravar.
- Produz: `UserMessage::STATIC` ganha `'Name must not contain < or >' => 'Name must not contain < or >'` (18 entradas; `testCatalogHasExactlyTheContractEntries` passa a 18/20); a chave `Name must not contain < or >` → `O nome não pode conter < ou >` vai no board para T-16.
- Consome: nada

**Teste RED**
- `src/tests/Unit/NameTextTest.php` — `NameText::assertNoMarkup('<img src=x onerror=alert(1)> R3')` e `('R3 a>b')` lançam `InvalidArgumentException` com `Name must not contain < or >`; `('João & Cia')` e `('Rex d\'Ávila')` não lançam; e um `TutorService` com `FakeTutorRepository` recusa `create(['full_name' => '<b>R3</b>', 'phone' => '11999990000'])` sem gravar (`Assert::count(0, …)` no fake); falha antes porque a classe não existe (comando: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php`)

**Critério de aceite**
- SUITE: `PASS  Unit\NameTextTest::` em todos os métodos, `PASS  Unit\UserMessageTest::testCatalogHasExactlyTheContractEntries`, os testes de `PatientService`, `TutorService`, `ServiceCatalogService` e `ProductService` em PASS e `Failed: 0`.
- GATE (depois de T-16): criar tutor, paciente, serviço e produto com `<b>R3</b>` mostra "O nome não pode conter < ou >" e `SELECT COUNT(*)` de cada tabela fica igual; o tutor 10626 continua abrindo na `TutorList` e no `TutorForm`.

**Validação**
- LINT dos 8 arquivos (evidência: 8× `No syntax errors detected`)
- SUITE (evidência: as linhas `PASS` do critério e `Failed: 0`)
- `SELECT COUNT(*)` de `tutor`, `patient`, `service`, `product` antes e depois do gate (evidência: iguais; nenhum registro existente some)
- GATE → os 4 cadastros e a `TutorList` com o tutor 10626 (evidência: snapshot do diálogo e da lista, console 0 `error`)

### T-15 — Pequenos: SystemMessageForm, SystemDatabaseExplorer e cv_uploads no login

**Camada:** frontend
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** simples
**Agente:** Maquiavel

Fontes: sugestões abertas de `reviews/T-62.md` (Rodada 2: o catch `InvalidArgumentException` de `SystemMessageForm.php:208-212`, no HEAD 39de5ef, não faz `setData` e o formulário perde o que foi digitado; Rodada 1: o catch novo de `SystemDatabaseExplorer.php:496-501`, no HEAD 39de5ef, usa `$table`, que pode estar indefinido, e não faz rollback) e de `reviews/T-63.md` (o login sem logout anterior mantém `cv_uploads` do usuário anterior, `LoginForm.php:172`, `ApplicationAuthenticationService.php:93`). Os três arquivos são do template, fora de `framework_hashes.php`. Os `getMessage()` crus deles ficam como estão (decisão do usuário: template fora).

**Arquivos prováveis**
- `src/app/control/communication/messages/SystemMessageForm.php`
- `src/app/control/admin/SystemDatabaseExplorer.php`
- `src/app/service/auth/ApplicationAuthenticationService.php`

**Interface**
- Produz: o catch `InvalidArgumentException` de `SystemMessageForm::onSend` faz `$this->form->setData($this->form->getData())` antes do `TMessage`, como o catch `Exception`.
- Produz: o catch `InvalidArgumentException` de `SystemDatabaseExplorer` faz `TTransaction::rollback()` e não lê `$table` sem `isset`.
- Produz: `ApplicationAuthenticationService::loadSessionVars` chama `TSession::delValue(\CvUpload::SESSION_KEY)` antes de gravar as variáveis do usuário que entra.
- Consome: nada

**Teste RED**
- sem teste: controllers e serviço de autenticação do template não são carregados pela suíte; a prova é o gate

**Critério de aceite**
- GATE: no `SystemMessageForm`, um envio recusado com "Arquivo inválido" (POST forçado com `fileName` fora de `tmp/`) volta com assunto e texto preenchidos.
- GATE: com um upload pendente na sessão do admin, logar de novo sem logout e anexar o nome antigo no `EncounterView` mostra "Arquivo inválido" e `COUNT(stored_object)` fica igual.

**Validação**
- LINT dos 3 arquivos (evidência: 3× `No syntax errors detected`)
- `grep -n "delValue" /var/www/html/centralvet/src/app/service/auth/ApplicationAuthenticationService.php` (evidência: uma linha com `CvUpload::SESSION_KEY`)
- GATE → `SystemMessageForm` e o fluxo de login (evidência: snapshot com os campos preenchidos; diálogo "Arquivo inválido"; `SELECT COUNT(*) FROM stored_object` igual antes e depois)
- Review Focus: segundo login no mesmo navegador sem logout → `cv_uploads` vazio, o nome pendente do login anterior é recusado (evidência: diálogo "Arquivo inválido")

### T-16 — translations.json: chaves novas, "^1 days" e chave órfã

**Camada:** frontend
**Dependências:** T-01, T-11, T-14
**Paralelizável:** sim
**Complexidade:** simples
**Agente:** Platão

Escritor único de `src/app/config/translations.json` na rodada (array de `{"en":..,"pt":..}` em ordem casefold). Grava as chaves do board (linhas `- [T-xx] i18n:`) e remove a órfã confirmada `Uploaded file was not found` (:2867; `grep -rn` em `src/app` só a acha no JSON). `Attachment not found` não é órfã (EncounterView.php:1954) e fica. As outras 26 órfãs herdadas ficam fora (decisão da rodada 2, onda 9: podem ser usadas por `_t($variável)`).

**Arquivos prováveis**
- `src/app/config/translations.json`

**Interface**
- Produz: as chaves `Photo must be a JPEG, PNG or WEBP image` → `A foto deve ser uma imagem JPEG, PNG ou WEBP`; `Photo must be at most 2 MB` → `A foto deve ter no máximo 2 MB`; `Encounter ^1 is already finished` → `O atendimento ^1 já foi finalizado`; `Bank account must belong to the current unit` → `A conta bancária deve pertencer à unidade atual`; `Record not found` → `Registro não encontrado`; `Fill in ^1 on every item` → `Preencha o campo ^1 em todos os itens`; `^1 must have at most ^2 characters` → `O campo ^1 aceita no máximo ^2 caracteres`; `^1 is required` → `Campo obrigatório: ^1`; `^1 days` → `^1 dias`; e `Name must not contain < or >` → `O nome não pode conter < ou >`. Chave que já exista é mantida, sem duplicar.
- Consome: T-01 `Record not found`, T-01 `^1 is required`, T-01 `Encounter ^1 is already finished`, T-11 `^1 days`, T-14 `Name must not contain < or >`

**Teste RED**
- sem teste: arquivo de dados; a prova é o script de contagem e o gate

**Critério de aceite**
- `python3 -c "import json;d=json.load(open('/var/www/html/centralvet/src/app/config/translations.json'));en=[x['en'] for x in d];need=['Photo must be a JPEG, PNG or WEBP image','Photo must be at most 2 MB','Encounter ^1 is already finished','Bank account must belong to the current unit','Record not found','Fill in ^1 on every item','^1 must have at most ^2 characters','^1 is required','^1 days','Name must not contain < or >'];print(len(en)-len(set(en)), len(en)-len({e.casefold() for e in en}), [k for k in need if k not in en], 'Uploaded file was not found' in en)"` imprime `0 0 [] False`.
- O JSON continua válido e em ordem casefold (`sorted(en, key=str.casefold) == en` → `True`).

**Validação**
- O comando do critério (evidência: `0 0 [] False`)
- `python3 -c "import json;d=json.load(open('/var/www/html/centralvet/src/app/config/translations.json'));en=[x['en'] for x in d];print(sorted(en,key=str.casefold)==en, len(d))"` (evidência: `True` e o total = total da BASE + chaves novas − 1)
- SUITE (evidência: `Failed: 0`)

### T-17 — Trava de regressão contra mensagem de exceção crua

**Camada:** qa
**Dependências:** T-10, T-11, T-12
**Paralelizável:** sim
**Complexidade:** simples
**Agente:** Levi

Teste permanente que impede a volta de `new TMessage(…, $e->getMessage())` nos controllers fora do template. Lê o arquivo inteiro, então pega também a chamada quebrada em várias linhas, que o grep de linha de T-10..T-12 não vê. Escopo: `app/control/clinic`, `app/control/SearchBox.php` e `app/control/log`; o template (`admin/`, `communication/`) fica fora por decisão do usuário.

**Arquivos prováveis**
- `src/tests/Unit/ControllerRawExceptionMessageTest.php`

**Interface**
- Produz: `ControllerRawExceptionMessageTest::testClinicControllersNeverShowRawExceptionMessages` falha listando `arquivo:linha` de cada `new TMessage(` cujo argumento (até o `;`) contém `getMessage()`.
- Consome: nada

**Teste RED**
- sem teste: a trava nasce verde, porque T-10..T-12 já migraram os catches; a discriminação é provada na worktree isolada `/tmp/claude-1000/wt-T-17` (um catch revertido em `ProductForm.php` faz o teste falhar), registrada em `## RED` e removida ao fim

**Critério de aceite**
- SUITE: `PASS  Unit\ControllerRawExceptionMessageTest::testClinicControllersNeverShowRawExceptionMessages` e `Failed: 0`.
- O relatório mostra a saída FAIL do teste na worktree com o catch revertido, com `ProductForm.php:<linha>` na mensagem, e `git -C /var/www/html/centralvet worktree list` sem a worktree.

**Validação**
- LINT do teste (evidência: `No syntax errors detected`)
- SUITE (evidência: a linha `PASS` do critério e `Failed: 0`)
- `git -C /var/www/html/centralvet worktree list` (evidência: só o checkout principal)

### T-18 — Validação final da rodada 3

**Camada:** qa
**Dependências:** T-13, T-15, T-16, T-17, T-19, T-20, T-21
**Paralelizável:** não
**Complexidade:** média
**Agente:** Spock

Varredura Playwright de todas as telas tocadas na rodada (lista em `plan.md § Critérios gerais de aceite`), em `http://127.0.0.1:8081` com a sessão admin logada pelo orquestrador, depois do rebuild. Registros de teste com prefixo `R3 varredura`. Só SELECT no banco.

**Arquivos prováveis**
- `.claude/tasks/mar-20261001-1520-rodada-3-divida-tecnica/reports/T-18.md`

**Interface**
- Produz: nada
- Consome: nada

**Teste RED**
- sem teste: task de validação, sem código

**Critério de aceite**
- Tabela `Tela | Fluxos | Console (errors) | Rede (≥400) | Erro na tela | Veredito | Task dona` com 0, 0 e "nenhum" em cada linha, e nenhuma tela com "Message not found".
- SUITE com `Failed: 0` e `Total` ≥ o da BASE da onda 1 + os testes novos da rodada.

**Validação**
- SUITE (evidência: `Total: <n>, Passed: <n>, Failed: 0`)
- GATE → telas de `plan.md § Critérios gerais de aceite` (evidência: a tabela em `reports/T-18.md`)
- `SELECT COUNT(*)` de `tutor`, `patient`, `service`, `product`, `stored_object`, `financial_entry` na BASE e no fim (evidência: só os acréscimos `R3` registrados no relatório; nenhum registro existente some)
- `SELECT MAX(id) FROM tutor` em `centralvet` antes e depois de uma SUITE (evidência: igual; a suíte já não toca o banco de dev)

### T-19 — SUITE no banco `centralvet_test` por padrão

**Camada:** qa
**Dependências:** T-05
**Paralelizável:** sim
**Complexidade:** simples
**Agente:** Naruto

Depende de T-05 (onda 1) e do provisionamento do `centralvet_test`, feito pelo orquestrador entre as ondas 1 e 2 com aprovação SQL do usuário (`notes.md § Bloqueios`). Sem o banco criado e conferido por `scripts/test-db/verify.sql`, a task para com `bloqueado`.

A task troca o default de `TestDatabase` e faz o `run.php` recusar o banco da aplicação, como ele já faz com o Redis (`tests/run.php:48-112`). A partir do commit dela, toda SUITE usa `centralvet_test`, inclusive a dos outros agentes da onda 2.

**Arquivos prováveis**
- `src/tests/Support/TestDatabase.php`
- `src/tests/Unit/TestDatabaseTest.php`
- `src/tests/run.php`
- `docs/runbooks/tests.md`

**Interface**
- Produz: `TestDatabase::DEFAULT_NAME = 'centralvet_test'`. `resolveName(['DB_DATABASE' => 'centralvet'])` passa a devolver `centralvet_test`.
- Produz: `tests/run.php` faz duas checagens antes de rodar qualquer teste:
  - chama `TestDatabase::resolveName(getenv())`; na exceção, imprime a mensagem e sai com 1;
  - com o MySQL acessível e o banco resolvido inexistente, imprime `Refusing to run: test MySQL database <nome> not found (see docs/runbooks/tests.md)` e sai com 1.
- Consome: T-05 `public static function resolveName(array $env): string`

**Teste RED**
- `src/tests/Unit/TestDatabaseTest.php` — `testDefaultIsTheDedicatedTestDatabase`: `resolveName(['DB_DATABASE' => 'centralvet'])` = `centralvet_test`; falha antes porque o default é o banco da aplicação (comando: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php`)

**Critério de aceite**
- SUITE: `PASS  Unit\TestDatabaseTest::testDefaultIsTheDedicatedTestDatabase`, os testes `Integration\` MySQL em PASS (mesma contagem de PASS e Skipped da SUITE da BASE da onda) e `Failed: 0`.
- `docker compose run --rm --no-deps -T -e TEST_DB_DATABASE=centralvet -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php` imprime `Refusing to run: test MySQL database equals the application database (centralvet)` e sai com 1.
- `SELECT MAX(id)` de `tutor` e de `patient` em `centralvet` é igual antes e depois de uma SUITE.

**Validação**
- LINT dos 3 PHP (evidência: 3× `No syntax errors detected`)
- SUITE (evidência: a linha `PASS` do critério, `Total`/`Skipped` comparados aos da BASE e `Failed: 0`)
- o comando com `TEST_DB_DATABASE=centralvet` do critério (evidência: a linha `Refusing to run` e `echo $?` = 1)
- `SELECT MAX(id) FROM tutor` e `SELECT MAX(id) FROM patient` em `centralvet` antes e depois da SUITE (evidência: iguais)

### T-20 — Uploads: extensão conferida sem `extensions` na URL, MIME no Drive e helpers de tmp/

**Camada:** backend
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** alta
**Agente:** Kratos

Prioridade alta. Fonte: `reviews/final.md` da rodada 2, § Revisão final — ondas 13 a 16, Triagem T-63 e Achados. Há três problemas no upload:
- `CvUploaderService::show` (:60) só confere a extensão quando o parâmetro `extensions` vem na URL. Um POST direto ao serviço sem `extensions`/`hash` grava qualquer extensão fora da blocklist.
- O `SystemDriveDocumentUploadForm` (:42-45) perdeu a checagem finfo/MIME que o `SystemDocumentUploaderService` (:105-106) fazia.
- O `extensions` do serviço é pulado.

Hoje o impacto é contido (`/files` dá 404, e o preview de txt/html/sql sai em `<pre>` escapado), mas a defesa fica só na blocklist (`.xhtml` passa).

A task também fecha três sugestões dos Achados no mesmo código:
- o docblock de `resolve()` está acima de `generateName()` (`UploadedTmpFile.php:38-53`);
- `resolveForSession` compara o nome cru e `resolve()` apara (`UploadedTmpFile.php:161-170`, `CvUpload.php:19-21`);
- `SystemProfileForm` faz `forget` sem `unlink` quando a foto não é JPEG (:169-181), e o arquivo fica em `tmp/`.

Mais um caso, da Triagem T-62: `\0` nas pontas do nome não tem teste. Nenhum desses arquivos está em `framework_hashes.php`. Não editar `SystemDocumentUploaderService` (fora do uso desde T-63).

**Arquivos prováveis**
- `src/app/Core/Presentation/UploadedTmpFile.php`
- `src/app/lib/widget/CvUpload.php`
- `src/app/service/upload/CvUploaderService.php`
- `src/app/control/communication/documents/SystemDriveDocumentUploadForm.php`
- `src/app/control/admin/SystemProfileForm.php`
- `src/tests/Unit/UploadedTmpFileTest.php`

**Interface**
- Produz: `UploadedTmpFile::DEFAULT_EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'txt', 'csv']`.
- Produz: `UploadedTmpFile::extensionAllowed(string $originalName, ?array $requested): bool` devolve se a extensão (minúscula) está em `$requested`. Quando `$requested` é null (sem `extensions` na URL), usa `DEFAULT_EXTENSIONS`.
- Produz: `CvUploaderService::show` usa `extensionAllowed` sempre: com `extensions` + `hash` válidos, a lista da URL; sem `extensions`, `null`. Na recusa, devolve a mesma resposta `Extension not allowed` de hoje.
- Produz: `UploadedTmpFile::mimeAllowed(string $path, array $allowedMimes): bool`, que lê o tipo com `finfo(FILEINFO_MIME_TYPE)`.
- Produz: `SystemDriveDocumentUploadForm::onSave` recusa com `Invalid file` (antes do `store()`, com `unlink` do arquivo e `CvUpload::forget`) quando `mimeAllowed` é false para a lista de tipos do `SystemDocumentUploaderService` (`$content_type_list`, copiada para uma constante do form).
- Produz: `resolveForSession` apara o nome uma vez, na entrada, e o mesmo nome aparado vai a `resolve()` e à comparação com a lista; o docblock de `resolve()` fica acima de `resolve()`.
- Produz: `SystemProfileForm` faz `unlink` do caminho resolvido quando o finfo não dá `image/jpeg`, antes do `forget`.
- Consome: nada

**Teste RED**
- `src/tests/Unit/UploadedTmpFileTest.php` — métodos novos `testExtensionIsCheckedEvenWithoutRequestedList` (`extensionAllowed('a.xhtml', null)` e `('a.html', null)` são false; `('laudo.PDF', null)` é true; `('a.csv', ['pdf'])` é false), `testMimeAllowedReadsTheRealContentType` (arquivo temporário com `<html>` e nome `.pdf` é false para `['application/pdf']`), `testResolveForSessionTrimsTheNameOnce` (`" <nome> "` da sessão resolve para o mesmo caminho de `"<nome>"`) e `testNullByteAtTheEdgesIsRejected` (`"ok.pdf\0"` em `resolveForSession` lança `InvalidArgumentException`); falham antes porque os métodos não existem e o nome com espaço é recusado (comando: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php`)

**Critério de aceite**
- SUITE: os 4 métodos novos e os 13 de antes de `Unit\UploadedTmpFileTest::` em PASS, `Failed: 0`.
- GATE: um POST direto a `engine.php?class=CvUploaderService` sem `extensions`, com `r3.xhtml`, responde `{"type":"error","msg":"Extensão não permitida"}` ou o texto da chave `Extension not allowed`, e nenhum arquivo novo aparece em `tmp/`.
- GATE: no Drive, um arquivo `r3-falso.pdf` com conteúdo HTML é recusado com "Arquivo inválido"; um PDF real `r3-real.pdf` é aceito, e `SELECT COUNT(*) FROM system_document` sobe 1.
- GATE: uploads legítimos seguem aceitos: foto do paciente R2 2772 (PNG), anexo PDF do atendimento 4304 e CSV do `ServiceImportForm`.

**Validação**
- LINT dos 6 arquivos (evidência: 6× `No syntax errors detected`)
- SUITE (evidência: as 4 linhas `PASS  Unit\UploadedTmpFileTest::` novas e `Failed: 0`)
- GATE → POST forçado ao `CvUploaderService`, Drive (falso e real), `PatientForm` foto, `EncounterView` anexo, `ServiceImportForm` (evidência: respostas e diálogos, `docker compose exec -T app ls /var/www/html/src/tmp | wc -l` igual antes e depois da recusa, `SELECT COUNT(*) FROM system_document` +1)
- Review Focus: upload sem `extensions` na URL com `.xhtml` → `Extension not allowed`, nada gravado em `tmp/` (evidência: resposta JSON e contagem de `tmp/`)

### T-21 — SystemWikiPagePicker: título de wiki escapado no select2

**Camada:** frontend
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** simples
**Agente:** Aang

Fonte: `reviews/final.md` da rodada 2, § Revisão final — ondas 13 a 16, Achados. O relatório de T-65 (`reports/T-65.md:60`) classificou `SystemWikiPagePicker:24` como "select nativo", mas a linha 25 chama `enableSearch()`: é `TDBCombo` com select2, e `tcombo.js` renderiza como HTML o título com tag. O título de página de wiki é editável por qualquer perfil com acesso ao módulo de wiki e aparece para outros usuários no picker, então o caso é XSS armazenado entre usuários.

Decisão do planejador: aplicar a mesma mitigação de T-65 (`CvSafeLabelTrait` + `safeSearchMask`), com o mesmo desenho do `TDBCombo` + `enableSearch` do `EncounterAccountForm.php:390-394`. O registro errado da rodada 2 não é editado; a correção da classificação fica no relatório desta task e em `notes.md § Decisões tomadas`. A prova de exploração usa uma página de wiki R3 criada pelo validador no gate.

**Arquivos prováveis**
- `src/app/model/communication/pages/SystemWikiPage.php`
- `src/app/control/communication/pages/SystemWikiPagePicker.php`

**Interface**
- Produz: `SystemWikiPage` usa `CvSafeLabelTrait` e expõe `get_title_safe(): string` (`return $this->safeLabel('title');`).
- Produz: o `TDBCombo('page', …)` de `SystemWikiPagePicker` usa `SystemWikiPage::safeSearchMask('title_safe')` como 5º argumento; a ordem (6º) continua `title`, e o `enableSearch()` fica.
- Consome: nada

**Teste RED**
- sem teste: model `TRecord` e controller Adianti fora da suíte; o trait já é provado por `Unit\CvSafeLabelTraitTest`, e a prova é `php -r` com `init.php` e o gate

**Critério de aceite**
- `php -r` no container: `(new SystemWikiPage(<id R3>))->render('{title_safe}')` contém `&lt;img src=x onerror=alert(1)&gt;`.
- GATE: com a página de wiki `<img src=x onerror=alert(1)> R3 wiki` criada pelo validador, abrir o picker e digitar "R3" mostra o título em texto literal, com 0 dialogs e `document.querySelectorAll('img[src="x"]').length` = 0, com o dropdown aberto e depois da seleção; selecionar grava o `id` da página.

**Validação**
- LINT dos 2 arquivos (evidência: 2× `No syntax errors detected`)
- `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php -r 'chdir("/var/www/html/src"); require "init.php"; TTransaction::open("communication"); $p = SystemWikiPage::where("title", "like", "%R3 wiki%")->first(); echo $p->render("{title_safe}"); TTransaction::close();'` (evidência: `&lt;img src=x onerror=alert(1)&gt; R3 wiki`)
- GATE → picker de wiki (evidência: 0 dialogs, contagem 0, snapshot da option e da seleção, console 0 `error`)

## Legenda

- [ ] pendente
- [~] em andamento
- [x] concluído com evidência
- [!] bloqueado
