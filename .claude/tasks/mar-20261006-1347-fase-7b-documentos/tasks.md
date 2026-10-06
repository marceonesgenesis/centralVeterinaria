# Tasks

| ID | Camada | Descrição | Dependências | Paralelizável | Complexidade | Agente | Status |
|---|---|---|---|---|---|---|---|
| T-01 | database | Migration 0013 (`document_template`, `generated_document`), verify e provision do banco de teste | — | sim | alta | Darwin | [x] |
| T-02 | backend | Domain de documentos (tipo, documento gerado, template, renderizador, padrões, conteúdo, exceções) + contratos | — | sim | média | Platão | [x] |
| T-03 | infra | Storage local fora do webroot + `DocumentStorageFactory` + variáveis, volume e pasta na imagem | — | sim | média | Tesla | [x] |
| T-04 | infra | Programas RBAC das 4 telas para os grupos 1, 2, 4 e 5 (seed + DML, verify e rollback preparados) | — | sim | simples | Jaspion | [x] |
| T-05 | shared | Fakes de documento, template, fontes, renderer, fábrica de conteúdo e nomes | T-02 | sim | média | Platão | [x] |
| T-06 | backend | Repositórios PDO de documento gerado e de template | T-01, T-02 | sim | alta | Athena | [x] |
| T-07 | backend | Consulta PDO das fontes (paciente, vacinas, receita, cirurgia, contato do tutor) | T-02 | sim | média | Sherlock | [x] |
| T-08 | backend | HTML escapado e renderizador dompdf seguro | T-02 | sim | média | Arquimedes | [x] |
| T-09 | backend | Pendência `document_failed` na Central de Pendências | T-01 | sim | média | Batman | [x] |
| T-10 | backend | DocumentRequestService (pedir, tentar de novo, listar, baixar) e DocumentJobPublisher | T-02, T-05 | sim | alta | Athena | [x] |
| T-11 | backend | DocumentContentFactory (conteúdo por tipo) | T-02, T-05 | sim | média | Sherlock | [x] |
| T-12 | backend | DocumentGenerationService + DocumentReadyNotifier (geração idempotente e aviso com consentimento) | T-02, T-05 | sim | alta | Naruto | [x] |
| T-13 | backend | DocumentTemplateService (cadastro e merge para paciente) | T-02, T-05 | sim | simples | Saitama | [x] |
| T-14 | infra | Worker: handler `document.generate`, varredor, `worker.php` e `bin/document-sweep.php` | T-03, T-06, T-07, T-08, T-10, T-11, T-12 | sim | alta | Aang | [x] |
| T-15 | frontend | Tela de pedido de documento | T-06, T-07, T-10, T-13 | sim | média | Kratos | [x] |
| T-16 | frontend | Lista de documentos, download e nova tentativa | T-03, T-06, T-10 | sim | média | Yoda | [x] |
| T-17 | frontend | Telas de template de documento (lista e formulário) | T-06, T-13 | sim | simples | Saitama | [x] |
| T-18 | frontend | Navegação (menu, CvNav, ações em PatientForm, VaccinationCardView, SurgeryView e PrescriptionForm) | T-04 | sim | simples | Jaspion | [x] |
| T-19 | frontend | i18n pt/en das telas e mensagens de domínio | T-09, T-14, T-15, T-16, T-17, T-18 | sim | média | Levi | [x] |
| T-20 | docs | Runbook de documentos, índice e passo da 0013 e cron na hospedagem 5.7 | T-01, T-03, T-04, T-14 | sim | simples | Gandalf | [x] |
| T-21 | qa | Validação final ponta a ponta e SQL de limpeza `F7B teste` | T-19, T-20 | não | média | Spock | [x] |
| T-22 | frontend | Correção pós-revisão final (onda 7): alvos ≥44 px (PrescriptionForm, voltar da DocumentTemplateForm) e rota de download no runbook | T-21 | não | simples | correção | [x] |
| T-23 | backend | Onda 8 (correção da revisão final): domínio/aplicação (fileName sem id, source_type x kind, TOKEN_PATTERN, breed opcional, signatário, objeto da tentativa) | T-22 | não | média | correção | [x] |
| T-24 | backend | Onda 8: persistência/storage/varredor (updated_at, deadlock 1213, realpath, renderHtml) | T-22 | não | média | correção | [x] |
| T-25 | frontend | Onda 8: telas/i18n/CSS 44 px/docs (consentimento com RBAC e unidade, retry com patient_id, título traduzido, ações à esquerda) | T-22 | não | média | correção | [x] |
| T-26 | database | Onda 8: SQL de limpeza dos registros do gate da onda 8 | T-23, T-24, T-25 | não | simples | correção | [x] |
| T-27 | qa | Onda 8: E2E cross-tenant com segundo tenant de teste (setup e limpeza SQL) | T-26 | não | média | correção | [x] |

## Detalhamento

### T-01 — Migration 0013 (`document_template`, `generated_document`), verify e provision do banco de teste

**Camada:** database
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** alta
**Agente:** Darwin

**Arquivos prováveis**
- `src/app/database/migrations/20261006_0013_phase7b_documents.sql`
- `src/app/database/migrations/20261006_0013_phase7b_documents.verify.sql`
- `scripts/test-db/provision.sh`

**Notas de implementação**

- **Modelo**: `src/app/database/migrations/20261006_0012_phase7a_communication.sql` e o `.verify.sql` dela.
- **Cabeçalho**:
  - `Migration: 20261006_0013_phase7b_documents`
  - `Status: PREPARED ONLY. Do not execute without a fresh backup and explicit SQL approval.`
  - `Target: MySQL 8.0.x, database centralvet, after 20261006_0012_phase7a_communication`
  - seções Effects (2 tabelas, 9 CHECKs, 3 UNIQUEs, FKs), Risk e Rollback (`docs/runbooks/migration-rollback.md`).
- **Última instrução**: `INSERT INTO schema_migrations (version, checksum)` com o placeholder de 64 zeros, como nas linhas 219-221 da 0012.
- **Regras de DDL**:
  - Todo CHECK é `CONSTRAINT <nome> CHECK (...)` nomeado, com no máximo 61 caracteres, só com colunas, literais, `IN`, `IS`, `NULL`, `NOT`, `AND`, `OR` e comparações (sem `BETWEEN`, `LIKE`, `CASE` nem funções).
  - `timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)`; opcionais `timestamp(6) NULL DEFAULT NULL`; `updated_at` com `ON UPDATE CURRENT_TIMESTAMP(6)`.
  - FKs `ON UPDATE RESTRICT ON DELETE RESTRICT`.
  - `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci`.
- **`provision.sh`**: acrescentar `$migrations_dir/20261006_0013_phase7b_documents.sql` depois da linha da 0012 (linha 57) e trocar `0001..0012` por `0001..0013` no comentário da linha 10.
- **`.verify.sql`** (só SELECT): lista as 2 tabelas, os 9 CHECKs (`information_schema.check_constraints` com `table_name IN (...)`), as 3 UNIQUEs e as contagens de `patient`, `vaccination`, `prescription`, `surgery`, `stored_object` e `communication_message`.

**Interface**
- Produz: `document_template(id bigint unsigned AUTO_INCREMENT, tenant_id bigint unsigned, kind varchar(30), name varchar(120), body_text text, status varchar(20) DEFAULT 'active', created_by_system_user_id int, updated_by_system_user_id int NULL, created_at, updated_at)` com:
  - `document_template_tenant_name_uq (tenant_id, name)`;
  - índice `document_template_kind_idx (tenant_id, kind, status)`;
  - CHECKs `document_template_kind_ck` (`kind IN ('medical_certificate')`) e `document_template_status_ck` (`status IN ('active', 'inactive')`);
  - FKs para `tenant` e `system_users` (2).
- Produz: `generated_document(id bigint unsigned AUTO_INCREMENT, tenant_id bigint unsigned, system_unit_id int, patient_id bigint unsigned, tutor_id bigint unsigned, kind varchar(30), source_type varchar(30), source_id bigint unsigned, version int unsigned, template_id bigint unsigned NULL, title varchar(190), body_text mediumtext NULL, notify_tutor tinyint(1) NOT NULL DEFAULT 0, status varchar(20) DEFAULT 'queued', attempt_count int unsigned DEFAULT 0, claimed_at timestamp(6) NULL, stored_object_id bigint unsigned NULL, storage_key varchar(255) NULL, size_bytes bigint unsigned NULL, sha256 char(64) NULL, last_error_code varchar(60) NULL, ready_at timestamp(6) NULL, failed_at timestamp(6) NULL, notified_at timestamp(6) NULL, requested_by_system_user_id int, created_at, updated_at)` com:
  - UNIQUEs `generated_document_version_uq (tenant_id, kind, source_type, source_id, version)` e `generated_document_stored_object_uq (stored_object_id)`;
  - índices `generated_document_unit_status_idx (tenant_id, system_unit_id, status, created_at)` e `generated_document_patient_idx (tenant_id, patient_id, created_at)`;
  - CHECKs:
    - `generated_document_kind_ck` (`kind IN ('vaccination_card', 'prescription', 'medical_certificate', 'surgery_consent')`)
    - `generated_document_source_ck` (`source_type IN ('patient', 'prescription', 'surgery')`)
    - `generated_document_status_ck` (`status IN ('queued', 'ready', 'failed')`)
    - `generated_document_version_ck` (`version >= 1`)
    - `generated_document_notify_ck` (`notify_tutor IN (0, 1)`)
    - `generated_document_ready_ck` (`(status = 'ready' AND stored_object_id IS NOT NULL AND storage_key IS NOT NULL AND ready_at IS NOT NULL) OR (status <> 'ready' AND stored_object_id IS NULL AND ready_at IS NULL)`)
    - `generated_document_failed_ck` (`(status = 'failed' AND failed_at IS NOT NULL AND last_error_code IS NOT NULL) OR (status <> 'failed' AND failed_at IS NULL)`)
  - FKs para `tenant`, `system_unit`, `patient`, `tutor`, `document_template`, `stored_object` e `system_users`.
- Consome: nada

**Teste RED**
- sem teste: migration SQL preparada e não executada (PREPARED ONLY); a estrutura é conferida por grep, pelo preparador 5.7 e, depois do bloqueio, pelo `.verify.sql` aplicado pelo orquestrador

**Critério de aceite**
- `grep -c "CREATE TABLE"` na 0013 imprime `2`; o arquivo contém `Status: PREPARED ONLY` e 64 zeros no `INSERT INTO schema_migrations`.
- A 0013 tem 9 CHECKs nomeados, e o maior nome tem no máximo 61 caracteres.
- O preparador 5.7 sobre a cópia `17-20261006_0013_phase7b_documents.sql` imprime `Prepared` sem `Unsupported CHECK clause`.
- O `.verify.sql` só tem `SELECT`.
- `scripts/test-db/provision.sh` lista a 0013 depois da 0012.

**Validação**
- `/usr/bin/grep -c "CREATE TABLE" src/app/database/migrations/20261006_0013_phase7b_documents.sql` (evidência: `2`)
- `/usr/bin/grep -oE "CONSTRAINT [a-z_]+_ck" src/app/database/migrations/20261006_0013_phase7b_documents.sql | sort -u | wc -l` (evidência: `9`)
- `/usr/bin/grep -oE "CONSTRAINT [a-z_]+_ck" src/app/database/migrations/20261006_0013_phase7b_documents.sql | awk '{print length($2)}' | sort -n | tail -1` (evidência: número ≤ 61)
- `d=$(mktemp -d) && cp src/app/database/migrations/20261006_0013_phase7b_documents.sql $d/17-20261006_0013_phase7b_documents.sql && python3 scripts/prepare-mysql57.py $d $d/out` (evidência: `Prepared` sem `Unsupported CHECK clause`)
- `/usr/bin/grep -ciE "^[[:space:]]*(insert|update|delete|alter|create|drop)" src/app/database/migrations/20261006_0013_phase7b_documents.verify.sql` (evidência: `0`)
- `/usr/bin/grep -n "0013" scripts/test-db/provision.sh` (evidência: linha da 0013 depois da 0012)
- `python3 scripts/test-prepare-mysql57.py` (evidência: `Ran 8 tests` e `OK`, iguais a `baseline/test-prepare-mysql57.txt`)
- Depois do bloqueio (orquestrador): o `.verify.sql` em `centralvet` e `centralvet_test` mostra as 2 tabelas e os 9 CHECKs; `SELECT COUNT(*)` de `patient`, `vaccination`, `prescription`, `surgery`, `stored_object` e `communication_message` igual ao de antes (registros existentes preservados).

### T-02 — Domain de documentos (tipo, documento gerado, template, renderizador, padrões, conteúdo, exceções) + contratos

**Camada:** backend
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Platão

**Arquivos prováveis**
- `src/app/Core/Domain/DocumentKind.php`
- `src/app/Core/Domain/GeneratedDocument.php`
- `src/app/Core/Domain/DocumentTemplate.php`
- `src/app/Core/Domain/DocumentTemplateRenderer.php`
- `src/app/Core/Domain/DocumentTemplateDefaults.php`
- `src/app/Core/Domain/DocumentContent.php`
- `src/app/Core/Domain/Exception/DocumentGenerationFailed.php`
- `src/app/Core/Domain/Exception/DocumentNotAvailableException.php`
- `src/app/Core/Domain/Exception/DocumentSourceNotFoundException.php`
- `src/app/Core/Domain/Contract/GeneratedDocumentRepositoryInterface.php`
- `src/app/Core/Domain/Contract/DocumentTemplateRepositoryInterface.php`
- `src/app/Core/Domain/Contract/DocumentSourceQueryInterface.php`
- `src/app/Core/Domain/Contract/DocumentRendererInterface.php`
- `src/app/Core/Domain/Contract/DocumentContentFactoryInterface.php`
- `src/tests/Unit/DocumentDomainTest.php`

**Notas de implementação**

Namespaces: `CentralVet\Domain`, `CentralVet\Domain\Exception` e `CentralVet\Domain\Contract`. Modelos: `MessagePurpose.php`, `OutboundMessage.php`, `MessageTemplateRenderer.php`, `MessageTemplateDefaults.php` e `Contract/OutboundMessageRepositoryInterface.php` (7A). Mensagens de exceção em inglês, sem dado pessoal; cada uma vai ao board como `i18n-domínio`.

**`DocumentKind`**
- `sourceTypeFor`: `vaccination_card` e `medical_certificate` → `patient`, `prescription` → `prescription`, `surgery_consent` → `surgery`.
- `titleFor` (pt-BR, texto do PDF): `Carteira de vacinação`, `Receita`, `Atestado`, `Termo de consentimento cirúrgico`.
- `usesTemplate`: só `medical_certificate`.
- `assertValid` lança `InvalidArgumentException('Unknown document kind "<kind>"')`.

**`GeneratedDocument::request`**
- Valida ids positivos e o tipo.
- `body_text` é obrigatório: não vazio depois do `trim`, até 20000 caracteres, para `medical_certificate` e `surgery_consent`. Para os outros dois tipos é `null`; se vier preenchido, `InvalidArgumentException`.
- O `title` vem de `DocumentKind::titleFor`, e `source_type` vem de `sourceTypeFor`.
- Nasce com `status` `queued`, `version` 0 (o repositório atribui a versão), `attempt_count` 0 e `id` null.
- `assignIdentity(int $id, int $version)` é usado pelo repositório.
- `fileName()` = `"<kind>-<id>-v<version>.pdf"`.
- `isDownloadable()` = `status === 'ready' && storageKey !== null`.

**`DocumentTemplateRenderer`**
- `render` troca cada marcador da lista fechada (ex.: `{{patient_name}}`) pelo valor, e um nome com valor ausente fica como está.
- `unknownPlaceholders` lista nomes fora da lista.
- `unresolvedPlaceholders` lista marcadores da lista que sobraram no texto.

**`DocumentTemplateDefaults::bodyFor('medical_certificate')`** devolve texto pt-BR com `{{patient_name}}`, `{{species}}`, `{{tutor_name}}` e `{{today}}`.

**`DocumentContent`** tem propriedades públicas `readonly` com os nomes do construtor.

**Shapes de `DocumentSourceQueryInterface`** (docblock):
- `patientSummary` → `array{patient_id: int, patient_name: string, species: string, breed: ?string, tutor_id: int, tutor_name: string}|null`
- `vaccinations` → `list<array{vaccine_name: string, dose_number: int, applied_at: string, lot: ?string, next_dose_at: ?string, professional_name: string}>` (ordem `applied_at`)
- `prescription` → `array{prescription_id: int, patient_id: int, system_unit_id: int, professional_name: string, orientation_text: ?string, created_at: string, items: list<array{medication_name: string, dose: string, dose_unit: string, route: string, frequency: string, duration: string}>}|null`
- `surgery` → `array{surgery_id: int, patient_id: int, system_unit_id: int, procedure_name: string, scheduled_start_at: string, surgeon_name: string, consent_signer_name: ?string, consent_text: ?string, consent_recorded_at: ?string}|null`
- `tutorContact` → `array{tutor_name: string, email: ?string, phone: string}|null`

Todas devolvem null/[] fora do tenant do contexto. `prescription` e `surgery` devolvem também a unidade, para o service checar.

**Contrato do repositório** (docblock):
- `insertNextVersion` atribui `MAX(version)+1` e repete até 3 vezes em 1062.
- `claim`: `status='queued'` e `claimed_at` nulo ou anterior a `now − 10 min`; incrementa `attempt_count`.
- `markReady`: exige `status='queued'` e `claimed_at` não nulo.
- `releaseClaim`: zera `claimed_at` e grava o código.
- `markFailed`: exige `status='queued'`.
- `requeueFailed`: `failed → queued`, zerando `failed_at` e `claimed_at`.
- `markNotified`: só com `notified_at` nulo.
- `listForUnit`: limite, mais novo primeiro.
- `listStaleQueuedIds`: `queued` com `claimed_at` nulo e `created_at < olderThan`, ou `claimed_at < olderThan`.
- Todo método filtra o tenant do contexto.

**Interface**
- Produz: `DocumentKind::VACCINATION_CARD = 'vaccination_card'`, `DocumentKind::PRESCRIPTION = 'prescription'`, `DocumentKind::MEDICAL_CERTIFICATE = 'medical_certificate'`, `DocumentKind::SURGERY_CONSENT = 'surgery_consent'`, `DocumentKind::ALL`, `DocumentKind::assertValid(string $kind): void`, `DocumentKind::sourceTypeFor(string $kind): string`, `DocumentKind::titleFor(string $kind): string`, `DocumentKind::usesTemplate(string $kind): bool`
- Produz: `GeneratedDocument::STATUS_QUEUED = 'queued'`, `GeneratedDocument::STATUS_READY = 'ready'`, `GeneratedDocument::STATUS_FAILED = 'failed'`, `GeneratedDocument::request(int $tenantId, int $systemUnitId, int $patientId, int $tutorId, string $kind, int $sourceId, ?int $templateId, ?string $bodyText, bool $notifyTutor, int $requestedBySystemUserId): GeneratedDocument`, `GeneratedDocument::reconstitute(array $row): GeneratedDocument`, `GeneratedDocument::assignIdentity(int $id, int $version): void`, getters `id(): ?int`, `tenantId(): int`, `systemUnitId(): int`, `patientId(): int`, `tutorId(): int`, `kind(): string`, `sourceType(): string`, `sourceId(): int`, `version(): int`, `templateId(): ?int`, `title(): string`, `bodyText(): ?string`, `notifyTutor(): bool`, `status(): string`, `attemptCount(): int`, `storedObjectId(): ?int`, `storageKey(): ?string`, `lastErrorCode(): ?string`, `readyAt(): ?DateTimeImmutable`, `notifiedAt(): ?DateTimeImmutable`, `requestedBySystemUserId(): int`, `createdAt(): ?DateTimeImmutable`, `GeneratedDocument::fileName(): string`, `GeneratedDocument::isDownloadable(): bool`
- Produz: `DocumentTemplate::STATUS_ACTIVE = 'active'`, `DocumentTemplate::STATUS_INACTIVE = 'inactive'`, `DocumentTemplate::create(int $tenantId, string $kind, string $name, string $bodyText, int $createdBySystemUserId): DocumentTemplate`, `DocumentTemplate::reconstitute(array $row): DocumentTemplate`, `DocumentTemplate::update(string $name, string $bodyText, string $status, int $updatedBySystemUserId): void`, getters `id(): ?int`, `kind(): string`, `name(): string`, `bodyText(): string`, `status(): string`, `isActive(): bool`
- Produz: `DocumentTemplateRenderer::PLACEHOLDERS = ['patient_name', 'species', 'breed', 'tutor_name', 'unit_name', 'clinic_name', 'today']`, `DocumentTemplateRenderer::render(string $body, array $variables): string`, `DocumentTemplateRenderer::unknownPlaceholders(string $body): array`, `DocumentTemplateRenderer::unresolvedPlaceholders(string $body): array`, `DocumentTemplateDefaults::bodyFor(string $kind): string`
- Produz: `new DocumentContent(string $title, string $clinicName, string $unitName, array $subjectLines, array $paragraphs, array $tableHeader, array $tableRows, ?string $signatureName, DateTimeImmutable $issuedAt)`
- Produz: `DocumentGenerationFailed::SOURCE_NOT_FOUND = 'source_not_found'`, `DocumentGenerationFailed::RENDER_FAILED = 'render_failed'`, `DocumentGenerationFailed::STORAGE_FAILED = 'storage_failed'`, `DocumentGenerationFailed::PERSIST_FAILED = 'persist_failed'`, `new DocumentGenerationFailed(string $errorCode)`, `DocumentGenerationFailed::errorCode(): string` (mensagem `Document generation failed: <code>`), `new DocumentNotAvailableException()` (mensagem `Document not found`), `new DocumentSourceNotFoundException()` (mensagem `Document source not found`)
- Produz: `GeneratedDocumentRepositoryInterface::insertNextVersion(GeneratedDocument $document): GeneratedDocument`, `GeneratedDocumentRepositoryInterface::findById(int $id): ?GeneratedDocument`, `GeneratedDocumentRepositoryInterface::claim(int $id, DateTimeImmutable $now): bool`, `GeneratedDocumentRepositoryInterface::markReady(int $id, int $storedObjectId, string $storageKey, int $sizeBytes, string $sha256, DateTimeImmutable $readyAt): bool`, `GeneratedDocumentRepositoryInterface::releaseClaim(int $id, string $errorCode): bool`, `GeneratedDocumentRepositoryInterface::markFailed(int $id, string $errorCode, DateTimeImmutable $failedAt): bool`, `GeneratedDocumentRepositoryInterface::requeueFailed(int $id): bool`, `GeneratedDocumentRepositoryInterface::markNotified(int $id, DateTimeImmutable $notifiedAt): bool`, `GeneratedDocumentRepositoryInterface::listForUnit(int $systemUnitId, ?int $patientId, int $limit): array`, `GeneratedDocumentRepositoryInterface::listStaleQueuedIds(DateTimeImmutable $olderThan, int $limit): array`
- Produz: `DocumentTemplateRepositoryInterface::findById(int $id): ?DocumentTemplate`, `DocumentTemplateRepositoryInterface::listAll(): array`, `DocumentTemplateRepositoryInterface::listActive(string $kind): array`, `DocumentTemplateRepositoryInterface::save(DocumentTemplate $template): DocumentTemplate`
- Produz: `DocumentSourceQueryInterface::patientSummary(int $patientId): ?array`, `DocumentSourceQueryInterface::vaccinations(int $patientId): array`, `DocumentSourceQueryInterface::prescription(int $prescriptionId): ?array`, `DocumentSourceQueryInterface::surgery(int $surgeryId): ?array`, `DocumentSourceQueryInterface::tutorContact(int $tutorId): ?array`
- Produz: `DocumentRendererInterface::render(DocumentContent $content): string`, `DocumentContentFactoryInterface::build(GeneratedDocument $document): DocumentContent`
- Consome: nada

**Teste RED**
- `src/tests/Unit/DocumentDomainTest.php` — `DocumentKind::sourceTypeFor('surgery_consent')` devolve `surgery`; `GeneratedDocument::request` com `medical_certificate` e corpo vazio lança `InvalidArgumentException`; `fileName()` depois de `assignIdentity(42, 3)` em `vaccination_card` é `vaccination_card-42-v3.pdf`; `DocumentTemplateRenderer::unknownPlaceholders('{{cpf}} {{patient_name}}')` devolve `['cpf']`; `DocumentGenerationFailed::errorCode()` devolve o código; falha porque as classes ainda não existem (comando: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php | /usr/bin/grep -E 'DocumentDomainTest|Failed:'`)

**Critério de aceite**
- `DocumentDomainTest` com `PASS`; SUITE com `Failed: 0`.
- LINT imprime `No syntax errors detected` nos 15 arquivos.
- `/usr/bin/grep -rn "getenv\|PDO\|TTransaction" src/app/Core/Domain/Document* src/app/Core/Domain/GeneratedDocument.php` não encontra nada (Domain puro).

**Validação**
- `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php | /usr/bin/grep -E 'DocumentDomainTest|Failed:'` (evidência: `PASS` e `Failed: 0`)
- LINT nos 15 arquivos (evidência: `No syntax errors detected`)
- `/usr/bin/grep -rln "getenv\|PDO\|TTransaction" src/app/Core/Domain/Document* src/app/Core/Domain/GeneratedDocument.php | wc -l` (evidência: `0`)

### T-03 — Storage local fora do webroot + `DocumentStorageFactory` + variáveis, volume e pasta na imagem

**Camada:** infra
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Tesla

**Arquivos prováveis**
- `src/app/Core/Storage/LocalFilesystemStorage.php`
- `src/app/Core/Storage/DocumentStorageFactory.php`
- `.env.example`
- `docker-compose.yml`
- `docker/php/Dockerfile`
- `src/tests/Unit/LocalFilesystemStorageTest.php`

**Notas de implementação**

**`LocalFilesystemStorage implements StorageInterface`** (namespace `CentralVet\Storage`)
- Caminho físico = `<root>/<ObjectKeyNamespace::tenantKey(tenantId, 'objects', $key)>`. Essa convenção já sanitiza `..` e caracteres inseguros; mesmo assim, o driver confere que o caminho resolvido começa com o root.
- `$webRoot` padrão = `dirname(__DIR__, 3)`, ou seja, `src/`.
- **Construtor**: lança `StorageException` com `Local storage root must be outside the web root` quando `realpath($rootDirectory)` está dentro de `realpath($webRoot)`. Lança `StorageException` com `Local storage root is not a directory` quando o diretório não existe.
- **`put`**:
  - cria os diretórios com `0750`;
  - grava num arquivo temporário no mesmo diretório e faz `rename` (escrita atômica), com `chmod 0640`;
  - devolve `StoredObjectMetadata('local', 'local', <chave física relativa ao root>, null, $contentType, strlen, sha256)`.
- **`get`**: arquivo ausente → `StorageException('Stored object not found')`.
- **`exists`**: devolve se o arquivo existe.
- **`delete`**: idempotente.
- **`presignedUrl`**: lança `StorageException('Presigned URLs are not supported by the local storage driver')`.
- Nenhum caminho absoluto ou chave vai para mensagem de exceção.

**`DocumentStorageFactory::fromEnvironment`**
- `DOCUMENT_STORAGE_DRIVER` (`local` padrão; `s3` → `S3CompatibleStorage::fromEnvironment($tenant)`; outro valor → `StorageException('Unknown document storage driver')`).
- `DOCUMENT_STORAGE_LOCAL_ROOT` (padrão `/var/www/html/var/documents`).

**Variáveis** (em `.env.example` e no `environment` do `x-php-service` do `docker-compose.yml`, nunca no `.env`), com comentário pt-BR na seção nova "Documentos (Fase 7B)":
- `DOCUMENT_STORAGE_DRIVER=local`
- `DOCUMENT_STORAGE_LOCAL_ROOT=/var/www/html/var/documents`
- `DOCUMENT_SYSTEM_USER_ID=1`
- `DOCUMENT_SWEEP_INTERVAL_SECONDS=600`

O comentário diz que na hospedagem compartilhada a pasta fica acima do `public_html`.

**Volume**: `app_documents:/var/www/html/var/documents` em `x-php-service.volumes` (app e worker) e na lista `volumes:` do fim do arquivo.

**`docker/php/Dockerfile`**: antes de `USER www-data`, `RUN mkdir -p /var/www/html/var/documents && chown www-data:www-data /var/www/html/var/documents && chmod 0750 /var/www/html/var/documents`, para o volume nomeado herdar o dono.

**Testes**
- Usam um diretório em `sys_get_temp_dir()`.
- A recusa de webroot é testada passando um `$webRoot` temporário que contém o root.

**Interface**
- Produz: `new LocalFilesystemStorage(string $rootDirectory, ObjectKeyNamespace $keys, TenantContext $tenant, ?string $webRoot = null)`, `LocalFilesystemStorage::PROVIDER = 'local'`
- Produz: `DocumentStorageFactory::fromEnvironment(TenantContext $tenant): StorageInterface`
- Produz: variáveis `DOCUMENT_STORAGE_DRIVER`, `DOCUMENT_STORAGE_LOCAL_ROOT`, `DOCUMENT_SYSTEM_USER_ID`, `DOCUMENT_SWEEP_INTERVAL_SECONDS` e o volume `app_documents:/var/www/html/var/documents`
- Consome: nada

**Teste RED**
- `src/tests/Unit/LocalFilesystemStorageTest.php` — `put` + `get` devolve os mesmos bytes e `sha256`; dois tenants com a mesma chave lógica não se leem; chave `../../etc/passwd` grava dentro do root; root dentro do `$webRoot` lança `StorageException`; `presignedUrl` lança `StorageException`; `DocumentStorageFactory::fromEnvironment` com `DOCUMENT_STORAGE_DRIVER=ftp` lança `StorageException`; falha porque as classes ainda não existem (comando: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php | /usr/bin/grep -E 'LocalFilesystemStorageTest|ObjectKeyNamespaceTest|Failed:'`)

**Critério de aceite**
- `LocalFilesystemStorageTest` e `ObjectKeyNamespaceTest` com `PASS`; SUITE com `Failed: 0`.
- `docker compose config` sai com 0 e mostra `app_documents` montado em `/var/www/html/var/documents` nos serviços `app` e `worker`.
- `.env.example` e `docker-compose.yml` têm as 4 variáveis `DOCUMENT_*`; o `Dockerfile` cria a pasta com dono `www-data`.
- LINT imprime `No syntax errors detected` nos 3 PHP.

**Validação**
- `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php | /usr/bin/grep -E 'LocalFilesystemStorageTest|ObjectKeyNamespaceTest|Failed:'` (evidência: `PASS` e `Failed: 0`)
- `docker compose config | /usr/bin/grep -c "/var/www/html/var/documents"` (evidência: número ≥ 2)
- `/usr/bin/grep -cE "^DOCUMENT_(STORAGE_DRIVER|STORAGE_LOCAL_ROOT|SYSTEM_USER_ID|SWEEP_INTERVAL_SECONDS)=" .env.example` (evidência: `4`)
- `/usr/bin/grep -n "var/documents" docker/php/Dockerfile` (evidência: linha com `mkdir` e `chown www-data`)
- LINT nos 3 PHP (evidência: `No syntax errors detected`)
- Gate da Onda 4 (orquestrador rebuilda): `docker compose exec -T app sh -c 'test -w /var/www/html/var/documents && echo writable'` (evidência: `writable`)

### T-04 — Programas RBAC das 4 telas para os grupos 1, 2, 4 e 5 (seed + DML, verify e rollback preparados)

**Camada:** infra
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** simples
**Agente:** Jaspion

**Arquivos prováveis**
- `src/app/database/seeds/initial-application-programs.sql`
- `.claude/tasks/mar-20261006-1347-fase-7b-documentos/sql/T-04-programs.sql`
- `.claude/tasks/mar-20261006-1347-fase-7b-documentos/sql/T-04-programs.verify.sql`
- `.claude/tasks/mar-20261006-1347-fase-7b-documentos/sql/T-04-programs.rollback.sql`

**Notas de implementação**

- **Modelos**: o bloco 7A do seed (linhas 708-900) e `.claude/tasks/mar-20261006-0842-fase-7a-comunicacao/sql/T-04-programs.sql`, `.verify.sql` e `.rollback.sql`.
- **Concessão** (decisão da 7A, mantida na 7B): grupos 1 (`Template - Admin`) e 2 (`Template - Users`) por id; `Clínico – Internação` e `Clínico – Cirurgia` pelo nome exato (í e travessão U+2013), por subconsulta no próprio INSERT. O grupo 3 não recebe nada.
- **`SET NAMES utf8mb4;`** é a primeira instrução dos três arquivos.
- **Ids**: sem id literal. Cada INSERT deriva o id com `SELECT (SELECT COALESCE(MAX(id),0)+1 FROM t cur) ... FROM DUAL WHERE NOT EXISTS (...)`, uma linha por comando.
- **`T-04-programs.sql`**, em `START TRANSACTION`: 4 INSERTs em `system_program` + 16 concessões + SELECTs + `COMMIT`.
- **`.verify.sql`** (só SELECT): 4 controllers; concessões numa linha por grupo (1 = 4, 2 = 4, `Clínico – Internação` = 4, `Clínico – Cirurgia` = 4, grupo 3 = 0); `COUNT(*)` de `system_program` e `system_group_program`.
- **`.rollback.sql`**, preparado e nunca executado sem nova aprovação: `DELETE` com `WHERE` por nome de controller em `system_group_program`, `system_user_program`, `system_program_method_role` e `system_program`; `COMMIT` comentado.
- **Seed**: recebe os mesmos programas e concessões, de forma idempotente, dentro da transação existente.

**Interface**
- Produz: programas `DocumentList` (`Central Vet - Document List`), `DocumentRequestForm` (`Central Vet - Document Request Form`), `DocumentTemplateList` (`Central Vet - Document Template List`) e `DocumentTemplateForm` (`Central Vet - Document Template Form`), cada um em `system_group_program` dos grupos 1, 2, `Clínico – Internação` e `Clínico – Cirurgia`, e em nenhum outro
- Consome: nada

**Teste RED**
- sem teste: DML SQL preparada (programas, verify e rollback), sem execução; conferida por grep e, depois do bloqueio, pelo verify do orquestrador

**Critério de aceite**
- `/usr/bin/grep -oE "controller='(DocumentList|DocumentRequestForm|DocumentTemplateList|DocumentTemplateForm)'" src/app/database/seeds/initial-application-programs.sql | sort -u | wc -l` imprime `4`.
- Os três arquivos de `sql/` começam com `SET NAMES utf8mb4;`. `T-04-programs.sql` não tem id numérico literal nem `ROW_NUMBER`/`WITH` e não cita o grupo 3. O rollback não tem `DELETE` sem `WHERE`.
- Depois do bloqueio (orquestrador): o verify mostra 4 concessões em cada grupo (1, 2, `Clínico – Internação`, `Clínico – Cirurgia`) e 0 no grupo 3. `system_program` = anterior + 4 e `system_group_program` = anterior + 16, com os registros existentes preservados.

**Validação**
- `/usr/bin/grep -oE "controller='(DocumentList|DocumentRequestForm|DocumentTemplateList|DocumentTemplateForm)'" src/app/database/seeds/initial-application-programs.sql | sort -u | wc -l` (evidência: `4`)
- `cd /var/www/html/centralvet/.claude/tasks/mar-20261006-1347-fase-7b-documentos && for f in sql/T-04-programs.sql sql/T-04-programs.verify.sql sql/T-04-programs.rollback.sql; do /usr/bin/grep -v '^--' $f | /usr/bin/grep -m1 -v '^[[:space:]]*$'; done` (evidência: três linhas `SET NAMES utf8mb4;`)
- `/usr/bin/grep -ciE "row_number|with recursive|values *\( *[0-9]{2,}" .claude/tasks/mar-20261006-1347-fase-7b-documentos/sql/T-04-programs.sql` (evidência: `0`)
- `/usr/bin/grep -ciE "delete from [a-z_]+ *;" .claude/tasks/mar-20261006-1347-fase-7b-documentos/sql/T-04-programs.rollback.sql` (evidência: `0`)
- `/usr/bin/grep -cE "group_id *= *3([^0-9]|$)" .claude/tasks/mar-20261006-1347-fase-7b-documentos/sql/T-04-programs.sql` (evidência: `0`)
- Depois do bloqueio (orquestrador): `docker compose exec -T mysql sh -c 'mysql --default-character-set=utf8mb4 -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" centralvet' < .claude/tasks/mar-20261006-1347-fase-7b-documentos/sql/T-04-programs.verify.sql` (evidência: grupos 1, 2, `Clínico – Internação` e `Clínico – Cirurgia` = 4 cada, grupo 3 = 0, contagens = anterior + 4 / + 16)

### T-05 — Fakes de documento, template, fontes, renderer, fábrica de conteúdo e nomes

**Camada:** shared
**Dependências:** T-02
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Platão

**Arquivos prováveis**
- `src/tests/Support/FakeGeneratedDocumentRepository.php`
- `src/tests/Support/FakeDocumentTemplateRepository.php`
- `src/tests/Support/FakeDocumentSourceQuery.php`
- `src/tests/Support/FakeDocumentRenderer.php`
- `src/tests/Support/FakeDocumentContentFactory.php`
- `src/tests/Support/FakeSenderNamesQuery.php`
- `src/tests/Unit/DocumentFakesTest.php`

**Notas de implementação**

Namespace `CentralVet\Tests\Support`. Modelos: `FakeOutboundMessageRepository.php` e `FakeMessageTemplateRepository.php` (7A), `FakeStorage.php` e `FakeStoredObjectRepository.php` (existentes, reaproveitados pelas tasks seguintes sem alteração).

- **`FakeGeneratedDocumentRepository`**: segue o contrato do docblock da T-02.
  - Tenant pelo contexto.
  - Versão por `(kind, source_type, source_id)`.
  - Claim com janela de 10 min.
  - Transições condicionais que devolvem `false` fora do estado esperado.
  - Extras: `seed(GeneratedDocument $d): void`, `all(): array` e `simulateConcurrentClaim(int $id): void` (marca `claimed_at` agora, como outro worker).
- **`FakeDocumentTemplateRepository`**: guarda os templates em memória, com `seed`.
- **`FakeDocumentSourceQuery`**: `seedPatient(array $row)`, `seedVaccinations(int $patientId, array $rows)`, `seedPrescription(array $row)`, `seedSurgery(array $row)` e `seedTutorContact(int $tutorId, array $row)`, com os shapes da T-02.
- **`FakeDocumentRenderer`**: `render` devolve `"%PDF-FAKE " . $content->title` e registra as chamadas em `calls(): array`. `failWith(Throwable $e): void` faz a próxima chamada lançar.
- **`FakeDocumentContentFactory`**: devolve um `DocumentContent` mínimo com o título do documento. `failWith(Throwable $e): void`.
- **`FakeSenderNamesQuery`** implementa `SenderNamesQueryInterface`.

**Interface**
- Produz: `new FakeGeneratedDocumentRepository(TenantContext $context)`, `FakeGeneratedDocumentRepository::seed(GeneratedDocument $document): void`, `FakeGeneratedDocumentRepository::all(): array`, `FakeGeneratedDocumentRepository::simulateConcurrentClaim(int $id): void`
- Produz: `new FakeDocumentTemplateRepository(TenantContext $context)`, `FakeDocumentTemplateRepository::seed(DocumentTemplate $template): void`
- Produz: `new FakeDocumentSourceQuery()`, `FakeDocumentSourceQuery::seedPatient(array $row): void`, `FakeDocumentSourceQuery::seedVaccinations(int $patientId, array $rows): void`, `FakeDocumentSourceQuery::seedPrescription(array $row): void`, `FakeDocumentSourceQuery::seedSurgery(array $row): void`, `FakeDocumentSourceQuery::seedTutorContact(int $tutorId, array $row): void`
- Produz: `new FakeDocumentRenderer()`, `FakeDocumentRenderer::calls(): array`, `FakeDocumentRenderer::failWith(Throwable $e): void`, `new FakeDocumentContentFactory()`, `FakeDocumentContentFactory::failWith(Throwable $e): void`, `new FakeSenderNamesQuery(array $namesByUnit)`
- Consome: T-02 `GeneratedDocumentRepositoryInterface::insertNextVersion(GeneratedDocument $document): GeneratedDocument`, T-02 `DocumentSourceQueryInterface::patientSummary(int $patientId): ?array`, T-02 `DocumentRendererInterface::render(DocumentContent $content): string`, T-02 `DocumentContentFactoryInterface::build(GeneratedDocument $document): DocumentContent`

**Teste RED**
- `src/tests/Unit/DocumentFakesTest.php` — dois `insertNextVersion` para a mesma fonte devolvem versões 1 e 2; `claim` duas vezes no mesmo instante devolve `true` e depois `false`; `markReady` de documento `failed` devolve `false`; `listStaleQueuedIds` ignora documento de outro tenant; `FakeDocumentRenderer::failWith` faz `render` lançar; falha porque os fakes ainda não existem (comando: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php | /usr/bin/grep -E 'DocumentFakesTest|Failed:'`)

**Critério de aceite**
- `DocumentFakesTest` com `PASS`; SUITE com `Failed: 0`.
- LINT imprime `No syntax errors detected` nos 7 arquivos.

**Validação**
- `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php | /usr/bin/grep -E 'DocumentFakesTest|Failed:'` (evidência: `PASS` e `Failed: 0`)
- LINT nos 7 arquivos (evidência: `No syntax errors detected`)

### T-06 — Repositórios PDO de documento gerado e de template

**Camada:** backend
**Dependências:** T-01, T-02
**Paralelizável:** sim
**Complexidade:** alta
**Agente:** Athena

**Arquivos prováveis**
- `src/app/Core/Persistence/GeneratedDocumentRepository.php`
- `src/app/Core/Persistence/DocumentTemplateRepository.php`
- `src/tests/Integration/DocumentRepositoryIntegrationTest.php`

**Notas de implementação**

Modelos: `OutboundMessageRepository.php` (claim, UPDATE condicional + `rowCount`, 1062 sem `INSERT IGNORE`) e `MessageTemplateRepository.php` (7A); `StoredObjectRepository.php` (`TenantQuery::forTenant`, linhas como array). Todo SQL tem `tenant_id` do contexto e nunca da entrada.

- **`insertNextVersion`**:
  - `INSERT ... SELECT COALESCE(MAX(version),0)+1 FROM generated_document WHERE tenant_id=? AND kind=? AND source_type=? AND source_id=?`, ou SELECT + INSERT.
  - Em `SQLSTATE 23000`/1062 da `generated_document_version_uq`, tenta de novo até 3 vezes.
  - Na 4ª, lança `RuntimeException('Could not allocate document version')`.
  - Chama `assignIdentity` no documento.
- **`claim`**: `UPDATE ... SET claimed_at = :now, attempt_count = attempt_count + 1 WHERE id AND tenant_id AND status = 'queued' AND (claimed_at IS NULL OR claimed_at < :limit)`, com `:limit` = `now − 10 min` calculado em PHP. Devolve `rowCount() === 1`.
- **Demais transições**: seguem o contrato da T-02.
- **`listForUnit`**: `ORDER BY created_at DESC, id DESC LIMIT`.
- **Teste**: `MysqlIntegrationTestCase` no `centralvet_test`, que exige a 0013. Cria tenant/unidade/tutor/paciente/usuário de teste ou reaproveita as fixtures do suporte, e limpa no `tearDown`.

**Interface**
- Produz: `new GeneratedDocumentRepository(TenantContext $context, PDO $connection)` implementando `GeneratedDocumentRepositoryInterface`
- Produz: `new DocumentTemplateRepository(TenantContext $context, PDO $connection)` implementando `DocumentTemplateRepositoryInterface`
- Consome: T-02 `GeneratedDocumentRepositoryInterface::insertNextVersion(GeneratedDocument $document): GeneratedDocument`, T-02 `GeneratedDocumentRepositoryInterface::claim(int $id, DateTimeImmutable $now): bool`, T-02 `GeneratedDocumentRepositoryInterface::markReady(int $id, int $storedObjectId, string $storageKey, int $sizeBytes, string $sha256, DateTimeImmutable $readyAt): bool`, T-02 `DocumentTemplateRepositoryInterface::listActive(string $kind): array`, T-02 `GeneratedDocument::reconstitute(array $row): GeneratedDocument`, T-02 `DocumentTemplate::reconstitute(array $row): DocumentTemplate`

**Teste RED**
- `src/tests/Integration/DocumentRepositoryIntegrationTest.php` — dois `insertNextVersion` da mesma fonte gravam `version` 1 e 2; o segundo `claim` no mesmo instante devolve `false`; `markReady` grava `stored_object_id`, `storage_key` e `ready_at` e um segundo `markReady` devolve `false`; `markFailed` + `requeueFailed` volta a `queued` com `failed_at` NULL; `findById` de documento de outro tenant devolve `null`; `listActive('medical_certificate')` ignora template `inactive`; falha porque os repositórios ainda não existem (comando: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php | /usr/bin/grep -E 'DocumentRepositoryIntegrationTest|Failed:'`)

**Critério de aceite**
- `DocumentRepositoryIntegrationTest` com `PASS` (não `SKIP`) no `centralvet_test`; SUITE com `Failed: 0`.
- `/usr/bin/grep -ci "insert ignore" src/app/Core/Persistence/GeneratedDocumentRepository.php` imprime `0`.
- LINT imprime `No syntax errors detected` nos 3 arquivos.
- `SELECT COUNT(*) FROM generated_document` no `centralvet_test` antes e depois da SUITE é igual (o teste limpa o que cria).

**Validação**
- `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php | /usr/bin/grep -E 'DocumentRepositoryIntegrationTest|Failed:'` (evidência: `PASS` e `Failed: 0`)
- `/usr/bin/grep -ci "insert ignore" src/app/Core/Persistence/GeneratedDocumentRepository.php` (evidência: `0`)
- LINT nos 3 arquivos (evidência: `No syntax errors detected`)

### T-07 — Consulta PDO das fontes (paciente, vacinas, receita, cirurgia, contato do tutor)

**Camada:** backend
**Dependências:** T-02
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Sherlock

**Arquivos prováveis**
- `src/app/Core/Persistence/DocumentSourceQuery.php`
- `src/tests/Integration/DocumentSourceQueryIntegrationTest.php`

**Notas de implementação**

Modelos: `ReminderSourceQuery.php` e `SenderNamesQuery.php` (7A; `TenantQuery::forTenant` em cada SQL, sem `AbstractTenantRepository`; `LEFT JOIN` com condição de tenant para tabelas auxiliares).

Fontes:
- `patient` + `tutor` (`full_name`).
- `vaccination` + `vaccine_catalog_item` (nome da vacina) + `system_users` (nome do profissional, `professional_system_user_id`).
- `prescription` + `prescription_item` + `encounter.system_unit_id` + `system_users`.
- `surgery` (`system_unit_id`, `procedure_name`, `scheduled_start_at`, `consent_*`) + `system_users` (`surgeon_system_user_id`).
- `tutor` (`full_name`, `email`, `phone`).

Regras:
- Datas devolvidas como string `Y-m-d H:i:s` (ou `Y-m-d` para `next_dose_at`).
- Nome de profissional ausente → `''`.
- Nenhum dado é logado.

**Interface**
- Produz: `new DocumentSourceQuery(TenantContext $context, PDO $connection)` implementando `DocumentSourceQueryInterface`
- Consome: T-02 `DocumentSourceQueryInterface::patientSummary(int $patientId): ?array`, T-02 `DocumentSourceQueryInterface::vaccinations(int $patientId): array`, T-02 `DocumentSourceQueryInterface::prescription(int $prescriptionId): ?array`, T-02 `DocumentSourceQueryInterface::surgery(int $surgeryId): ?array`, T-02 `DocumentSourceQueryInterface::tutorContact(int $tutorId): ?array`

**Teste RED**
- `src/tests/Integration/DocumentSourceQueryIntegrationTest.php` — com fixtures no `centralvet_test`, `patientSummary` devolve `tutor_name`; `vaccinations` devolve as doses em ordem de `applied_at` com `vaccine_name`; `prescription` devolve `items` e `system_unit_id` do atendimento; `surgery` devolve `consent_text`; as cinco consultas devolvem `null`/`[]` para ids de outro tenant; falha porque `DocumentSourceQuery` ainda não existe (comando: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php | /usr/bin/grep -E 'DocumentSourceQueryIntegrationTest|Failed:'`)

**Critério de aceite**
- `DocumentSourceQueryIntegrationTest` com `PASS` (não `SKIP`); SUITE com `Failed: 0`.
- Cada SQL do arquivo usa `TenantQuery::forTenant` ou um parâmetro `tenant_id` do contexto (`/usr/bin/grep -c "tenant_id" src/app/Core/Persistence/DocumentSourceQuery.php` ≥ 5).
- LINT imprime `No syntax errors detected` nos 2 arquivos.

**Validação**
- `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php | /usr/bin/grep -E 'DocumentSourceQueryIntegrationTest|Failed:'` (evidência: `PASS` e `Failed: 0`)
- `/usr/bin/grep -c "tenant_id" src/app/Core/Persistence/DocumentSourceQuery.php` (evidência: número ≥ 5)
- LINT nos 2 arquivos (evidência: `No syntax errors detected`)

### T-08 — HTML escapado e renderizador dompdf seguro

**Camada:** backend
**Dependências:** T-02
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Arquimedes

**Arquivos prováveis**
- `src/app/Core/Document/DocumentHtmlBuilder.php`
- `src/app/Core/Document/DompdfDocumentRenderer.php`
- `src/tests/Unit/DocumentRendererTest.php`

**Notas de implementação**

Namespace `CentralVet\Document`.

**`DocumentHtmlBuilder::build`**
- Monta HTML 5 com CSS inline simples (A4, fonte `DejaVu Sans`).
- Conteúdo, nesta ordem:
  - título;
  - clínica e unidade;
  - linhas do sujeito (`label: valor`, rótulo à esquerda);
  - parágrafos (quebra de linha → `<br>`);
  - tabela (cabeçalho alinhado à esquerda);
  - linha de assinatura com `signatureName`;
  - rodapé `Emitido em dd/mm/aaaa HH:ii`.
- **Todo** valor passa por `htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')`.
- Nenhum `<img>`, `<link>` ou URL externa.

**`DompdfDocumentRenderer::render`**
- `new \Dompdf\Options()` com `isRemoteEnabled` false, `isPhpEnabled` false, `isJavascriptEnabled` false, `tempDir` = `sys_get_temp_dir()` e `chroot` = `sys_get_temp_dir()`.
- `setPaper('A4')`.
- Devolve `output()`.
- Exceção do dompdf vira `DocumentGenerationFailed(DocumentGenerationFailed::RENDER_FAILED)`, sem a mensagem original.
- Modelo da chamada atual: `PrescriptionForm::onGeneratePdf` (:1044), que não é alterado.

**Interface**
- Produz: `DocumentHtmlBuilder::build(DocumentContent $content): string`
- Produz: `new DompdfDocumentRenderer()` implementando `DocumentRendererInterface`
- Consome: T-02 `new DocumentContent(string $title, string $clinicName, string $unitName, array $subjectLines, array $paragraphs, array $tableHeader, array $tableRows, ?string $signatureName, DateTimeImmutable $issuedAt)`, T-02 `DocumentRendererInterface::render(DocumentContent $content): string`, T-02 `DocumentGenerationFailed::RENDER_FAILED = 'render_failed'`

**Teste RED**
- `src/tests/Unit/DocumentRendererTest.php` — `DocumentHtmlBuilder::build` com parágrafo `<script>alert(1)</script>` e linha `<img src="http://127.0.0.1:9/x.png">` contém `&lt;script&gt;` e não contém `<img`; `DompdfDocumentRenderer::render` devolve bytes que começam com `%PDF-`; a opção `isRemoteEnabled` do dompdf usada é falsa; falha porque as classes ainda não existem (comando: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php | /usr/bin/grep -E 'DocumentRendererTest|Failed:'`)

**Critério de aceite**
- `DocumentRendererTest` com `PASS` no container da SUITE (montado `ro`); SUITE com `Failed: 0`.
- `/usr/bin/grep -c "isRemoteEnabled" src/app/Core/Document/DompdfDocumentRenderer.php` ≥ 1, com valor `false`.
- LINT imprime `No syntax errors detected` nos 3 arquivos.

**Validação**
- `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php | /usr/bin/grep -E 'DocumentRendererTest|Failed:'` (evidência: `PASS` e `Failed: 0`)
- `/usr/bin/grep -n "isRemoteEnabled\|isPhpEnabled" src/app/Core/Document/DompdfDocumentRenderer.php` (evidência: as duas opções com `false`)
- LINT nos 3 arquivos (evidência: `No syntax errors detected`)
- Review Focus 4 (injeção): o caso `<script>`/`<img src="http://127.0.0.1:9/x.png">` do teste passa, e a T-21 confere o texto literal no PDF gerado pelo worker (`pdftotext` ou leitura do PDF pelo navegador)

### T-09 — Pendência `document_failed` na Central de Pendências

**Camada:** backend
**Dependências:** T-01
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Batman

**Arquivos prováveis**
- `src/app/Core/Domain/PendingItem.php`
- `src/app/Core/Domain/PendingItemPriority.php`
- `src/app/Core/Persistence/PendingItemQuery.php`
- `src/app/control/clinic/PendingCenter.php`
- `src/tests/Unit/PendingItemDomainTest.php`
- `src/tests/Integration/DocumentPendingItemIntegrationTest.php`

**Notas de implementação**

- **`PendingItem`**:
  - constante `TYPE_DOCUMENT_FAILED = 'document_failed'`, incluída em `TYPES`;
  - `DEEP_LINK_KEYS` ganha `'DocumentList' => ['patient_id']` (allowlist fechada, valor inteiro: lição da T-03 da 7A).
- **`PendingItemPriority`**: `document_failed` tem prioridade `high`.
- **`PendingItemQuery::failedDocuments(int $unitId, int $limit)`**:
  - `generated_document` com `status = 'failed'` da unidade e do tenant, mais novo primeiro;
  - título `<DocumentKind::titleFor> v<versão>` e `due_at` = `failed_at`;
  - responsável = `requested_by_system_user_id`;
  - deep-link `DocumentList` com `patient_id`;
  - entra em `listForUnit`.
- **`PendingCenter::TYPE_META`**: ganha `document_failed` → `['Failed documents', 'fas:file-circle-exclamation', <tom de alerta já usado>]`.
- O nome do paciente não vai para a URL.
- Os testes existentes de pendência (`PendingItemDomainTest`, `PendingCenterServiceTest`, `PendingCenterIntegrationTest`, `CommunicationReadModelIntegrationTest`) continuam passando. Se algum trava a contagem de tipos (8), a T-09 atualiza o `PendingItemDomainTest`; outro arquivo travado vai para o board.

**Interface**
- Produz: `PendingItem::TYPE_DOCUMENT_FAILED = 'document_failed'`, deep-link `index.php?class=DocumentList&patient_id=<id>`
- Consome: T-01 `generated_document(id bigint unsigned AUTO_INCREMENT, tenant_id bigint unsigned, system_unit_id int, patient_id bigint unsigned, tutor_id bigint unsigned, kind varchar(30), source_type varchar(30), source_id bigint unsigned, version int unsigned, template_id bigint unsigned NULL, title varchar(190), body_text mediumtext NULL, notify_tutor tinyint(1) NOT NULL DEFAULT 0, status varchar(20) DEFAULT 'queued', attempt_count int unsigned DEFAULT 0, claimed_at timestamp(6) NULL, stored_object_id bigint unsigned NULL, storage_key varchar(255) NULL, size_bytes bigint unsigned NULL, sha256 char(64) NULL, last_error_code varchar(60) NULL, ready_at timestamp(6) NULL, failed_at timestamp(6) NULL, notified_at timestamp(6) NULL, requested_by_system_user_id int, created_at, updated_at)`

**Teste RED**
- `src/tests/Integration/DocumentPendingItemIntegrationTest.php` — um `generated_document` `failed` na unidade A aparece em `PendingItemQuery::listForUnit(A, ...)` com tipo `document_failed` e deep-link `DocumentList` com só `patient_id`; o mesmo documento não aparece para a unidade B; um documento `ready` não aparece; falha porque o tipo e a fonte ainda não existem (comando: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php | /usr/bin/grep -E 'DocumentPendingItemIntegrationTest|PendingItemDomainTest|PendingCenter|Failed:'`)

**Critério de aceite**
- `DocumentPendingItemIntegrationTest`, `PendingItemDomainTest`, `PendingCenterServiceTest` e `PendingCenterIntegrationTest` com `PASS`; SUITE com `Failed: 0`.
- LINT imprime `No syntax errors detected` nos 6 arquivos.

**Validação**
- `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php | /usr/bin/grep -E 'DocumentPendingItemIntegrationTest|PendingItemDomainTest|PendingCenter|Failed:'` (evidência: `PASS` em cada classe e `Failed: 0`)
- LINT nos 6 arquivos (evidência: `No syntax errors detected`)

### T-10 — DocumentRequestService (pedir, tentar de novo, listar, baixar) e DocumentJobPublisher

**Camada:** backend
**Dependências:** T-02, T-05
**Paralelizável:** sim
**Complexidade:** alta
**Agente:** Athena

**Arquivos prováveis**
- `src/app/Core/Application/DocumentRequestService.php`
- `src/app/Core/Application/DocumentJobPublisher.php`
- `src/tests/Unit/DocumentRequestServiceTest.php`

**Notas de implementação**

Modelos: `MessageService.php` e `MessageQueuePublisher.php` (7A). O service não abre transação nem publica: o controller abre `TTransaction`, chama `request`, faz commit e então publica o job.

**`request`**
- Valida `kind` (`DocumentKind::assertValid`) e exige unidade ativa (`requireUnitId`).
- Resolve a fonte por tipo:
  - **`vaccination_card`**: `patientSummary($sourceId)`. Sem paciente → `DocumentSourceNotFoundException`. Sem dose (`vaccinations` vazio) → `InvalidArgumentException('Patient has no vaccinations to print')`. Unidade = ativa.
  - **`prescription`**: `prescription($sourceId)`. `null` ou `system_unit_id` ≠ unidade ativa → `DocumentSourceNotFoundException`. Paciente/tutor por `patientSummary`.
  - **`surgery_consent`**: `surgery($sourceId)`, com a mesma regra de unidade. `consent_recorded_at` null → `InvalidArgumentException('Surgery consent has not been recorded')`. `body_text` = `consent_signer_name` + linha em branco + `consent_text` (snapshot); o `$bodyText` da entrada é ignorado.
  - **`medical_certificate`**: `patientSummary`. `$bodyText` obrigatório. `DocumentTemplateRenderer::unresolvedPlaceholders($bodyText) !== []` → `InvalidArgumentException('Document text has unresolved placeholders')`. Com `$templateId`, o template precisa existir, estar ativo e ser `medical_certificate`; senão, `InvalidArgumentException('Document template is not available')`.
- Autoriza com `AuthorizationRequest(context, action, requiresUnitScope: true, resourceUnitId: <unidade>, entityType: 'generated_document', entityId: null, metadata: ['kind' => ..., 'source_id' => ...])`. Na metadata, nada de nome ou texto.
- Grava com `insertNextVersion`.

**Demais métodos**
- **`retry`**: o documento precisa existir, ser da unidade ativa e estar `failed` (senão `DocumentNotAvailableException`). Autoriza e chama `requeueFailed`; `false` → `RuntimeException('Document <id> can no longer be retried')`.
- **`listForUnit`**: autoriza (unidade ativa) e devolve `GeneratedDocumentRepositoryInterface::listForUnit(unidade, patientId, 200)`.
- **`download`**:
  - Inexistente, de outra unidade ou não `isDownloadable()` → `DocumentNotAvailableException`. A checagem vem **antes** da autorização por unidade, para não haver oráculo.
  - Em seguida autoriza com `resourceUnitId` = unidade do documento (audita `document_id`, `kind`, `version`).
  - Devolve `['contents' => $storage->get(storageKey), 'content_type' => 'application/pdf', 'file_name' => fileName()]`. `StorageException` vira `DocumentNotAvailableException`.

**Publisher**: `push(QUEUE, ['type' => JOB_TYPE, 'document_id' => $documentId], $tenantId, null, 0, MAX_ATTEMPTS)`.

**Interface**
- Produz: `new DocumentRequestService(GeneratedDocumentRepositoryInterface $documents, DocumentSourceQueryInterface $sources, DocumentTemplateRepositoryInterface $templates, StorageInterface $storage, AuthorizationPolicyInterface $authorization, TenantContext $context, ?Closure $clock = null)`
- Produz: `DocumentRequestService::request(string $kind, int $sourceId, ?int $templateId, ?string $bodyText, bool $notifyTutor, string $action): GeneratedDocument`, `DocumentRequestService::retry(int $documentId, string $action): void`, `DocumentRequestService::listForUnit(?int $patientId, string $action): array`, `DocumentRequestService::download(int $documentId, string $action): array`
- Produz: `DocumentJobPublisher::JOB_TYPE = 'document.generate'`, `DocumentJobPublisher::QUEUE = 'default'`, `DocumentJobPublisher::MAX_ATTEMPTS = 3`, `new DocumentJobPublisher(QueueInterface $queue)`, `DocumentJobPublisher::publish(int $tenantId, int $documentId): string`
- Consome: T-02 `GeneratedDocument::request(int $tenantId, int $systemUnitId, int $patientId, int $tutorId, string $kind, int $sourceId, ?int $templateId, ?string $bodyText, bool $notifyTutor, int $requestedBySystemUserId): GeneratedDocument`, T-02 `GeneratedDocumentRepositoryInterface::insertNextVersion(GeneratedDocument $document): GeneratedDocument`, T-02 `GeneratedDocumentRepositoryInterface::requeueFailed(int $id): bool`, T-02 `DocumentTemplateRenderer::unresolvedPlaceholders(string $body): array`, T-05 `new FakeGeneratedDocumentRepository(TenantContext $context)`, T-05 `new FakeDocumentSourceQuery()`, T-05 `new FakeDocumentTemplateRepository(TenantContext $context)`

**Teste RED**
- `src/tests/Unit/DocumentRequestServiceTest.php` — `request('vaccination_card', ...)` de paciente com doses grava `queued` com `version` 1 e o segundo pedido grava `version` 2; `request('prescription', ...)` de receita de outra unidade lança `DocumentSourceNotFoundException`; `request('surgery_consent', ...)` sem consentimento lança `InvalidArgumentException`; `request('medical_certificate', ...)` com `{{patient_name}}` no texto lança `InvalidArgumentException`; `download` de documento `queued`, de outra unidade e inexistente lança `DocumentNotAvailableException` nos três casos sem chamar o storage; `download` de `ready` devolve `file_name` `vaccination_card-<id>-v1.pdf`; `DocumentJobPublisher::publish` empurra `{type: document.generate, document_id}` no `FakeQueue` com `maxAttempts` 3; falha porque as classes ainda não existem (comando: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php | /usr/bin/grep -E 'DocumentRequestServiceTest|Failed:'`)

**Critério de aceite**
- `DocumentRequestServiceTest` com `PASS`; SUITE com `Failed: 0`.
- O payload publicado tem só as chaves `type` e `document_id`.
- LINT imprime `No syntax errors detected` nos 3 arquivos.

**Validação**
- `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php | /usr/bin/grep -E 'DocumentRequestServiceTest|Failed:'` (evidência: `PASS` e `Failed: 0`)
- LINT nos 3 arquivos (evidência: `No syntax errors detected`)

### T-11 — DocumentContentFactory (conteúdo por tipo)

**Camada:** backend
**Dependências:** T-02, T-05
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Sherlock

**Arquivos prováveis**
- `src/app/Core/Application/DocumentContentFactory.php`
- `src/tests/Unit/DocumentContentFactoryTest.php`

**Notas de implementação**

`build(GeneratedDocument $document): DocumentContent`. Comum a todos os tipos:
- `title` = `$document->title()`.
- `clinicName`/`unitName` vêm de `SenderNamesQueryInterface::namesForUnit($document->systemUnitId())`; null vira `''`.
- `subjectLines` = `['Paciente' => patient_name, 'Espécie' => species (+ raça), 'Tutor' => tutor_name]`, a partir de `patientSummary($document->patientId())`.
- `issuedAt` = relógio.

Por tipo:
- **`vaccination_card`**: `tableHeader` = `['Vacina', 'Dose', 'Aplicação', 'Lote', 'Próxima dose', 'Profissional']`; linhas de `vaccinations`, com datas `d/m/Y`; `signatureName` null.
- **`prescription`**: tabela `['Medicamento', 'Dose', 'Via', 'Frequência', 'Duração']`; `orientation_text` em `paragraphs`; `signatureName` = `professional_name`.
- **`medical_certificate`**: `paragraphs` = linhas de `bodyText()`; `signatureName` = null (a linha de assinatura sai em branco, para o veterinário assinar à mão; o nome do solicitante não está na consulta de fontes).
- **`surgery_consent`**: `paragraphs` = `bodyText()` (snapshot); `subjectLines` + `Procedimento` e `Data prevista`; `signatureName` = `consent_signer_name` do snapshot (primeira linha).

Fonte ausente (`patientSummary`/`prescription`/`surgery` null) → `DocumentGenerationFailed(DocumentGenerationFailed::SOURCE_NOT_FOUND)`.

**Interface**
- Produz: `new DocumentContentFactory(DocumentSourceQueryInterface $sources, SenderNamesQueryInterface $names, ?Closure $clock = null)` implementando `DocumentContentFactoryInterface`
- Consome: T-02 `DocumentContentFactoryInterface::build(GeneratedDocument $document): DocumentContent`, T-02 `DocumentGenerationFailed::SOURCE_NOT_FOUND = 'source_not_found'`, T-05 `new FakeDocumentSourceQuery()`, T-05 `new FakeSenderNamesQuery(array $namesByUnit)`

**Teste RED**
- `src/tests/Unit/DocumentContentFactoryTest.php` — `build` de `vaccination_card` com duas doses devolve 2 `tableRows` e `tableHeader` começando por `Vacina`; `build` de `prescription` devolve `signatureName` igual a `professional_name`; `build` de `surgery_consent` devolve o texto do snapshot em `paragraphs`; `build` com paciente inexistente lança `DocumentGenerationFailed` com `errorCode()` `source_not_found`; falha porque `DocumentContentFactory` ainda não existe (comando: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php | /usr/bin/grep -E 'DocumentContentFactoryTest|Failed:'`)

**Critério de aceite**
- `DocumentContentFactoryTest` com `PASS`; SUITE com `Failed: 0`.
- LINT imprime `No syntax errors detected` nos 2 arquivos.

**Validação**
- `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php | /usr/bin/grep -E 'DocumentContentFactoryTest|Failed:'` (evidência: `PASS` e `Failed: 0`)
- LINT nos 2 arquivos (evidência: `No syntax errors detected`)

### T-12 — DocumentGenerationService + DocumentReadyNotifier (geração idempotente e aviso com consentimento)

**Camada:** backend
**Dependências:** T-02, T-05
**Paralelizável:** sim
**Complexidade:** alta
**Agente:** Naruto

**Arquivos prováveis**
- `src/app/Core/Application/DocumentGenerationService.php`
- `src/app/Core/Application/DocumentGenerationResult.php`
- `src/app/Core/Application/DocumentReadyNotifier.php`
- `src/tests/Unit/DocumentGenerationServiceTest.php`

**Notas de implementação**

Modelos: `MessageDeliveryService.php` (claim, `finalAttempt`, códigos) e `ReminderGenerationService.php:72-140` (ordem de consentimento, `insertIfNew`). Ator de sistema: os services do worker não autorizam.

**`generate($documentId, $finalAttempt)`**
1. `findById`. Null, ou `status` ≠ `queued` → `SKIPPED`.
2. `claim(id, now)`. `false` → `SKIPPED`.
3. `$content = contents->build($doc)`. `DocumentGenerationFailed` com `SOURCE_NOT_FOUND` → `markFailed` na hora → `FAILED`, sem relançar.
4. `$pdf = renderer->render($content)`.
5. `$key = sprintf('documents/%d/%s.pdf', id, bin2hex(random_bytes(8)))`; `$meta = storage->put($key, $pdf, 'application/pdf')`. Exceção → código `storage_failed`.
6. Dentro de `($this->transaction)(fn)` (padrão: executa direto):
   - `$row = objects->record($meta, $doc->fileName(), $doc->systemUnitId(), $doc->requestedBySystemUserId())`;
   - `markReady(id, (int) $row['id'], $key, strlen($pdf), hash('sha256', $pdf), now)`. `false` → lança, para reverter;
   - se `notifyTutor()`, `$emailIds = notifier->notify($doc)`;
   - `markNotified(id, now)`.
7. Exceção no passo 6 → `storage->delete($key)` (best-effort) e código `persist_failed`.
8. Para qualquer código de falha (exceto o passo 3):
   - `finalAttempt` → `markFailed(id, code, now)` e devolve `FAILED`;
   - senão → `releaseClaim(id, code)` e `throw new DocumentGenerationFailed(code)`.
9. Sucesso → `READY` com os ids de e-mail.

`StoredObjectRepository::record` (PDO) e `FakeStoredObjectRepository::record` devolvem a linha completa com a chave `id` (o PDO relê por `findByPublicId`): use `(int) $row['id']`.

**`DocumentReadyNotifier::notify`**
- Base `MessagePurpose::legalBasisFor(MessagePurpose::DOCUMENT_READY)` (`consent`).
- Contato por `tutorContact(tutorId)`; nomes por `namesForUnit(systemUnitId)`. Se `unit_name` ou `clinic_name` for null, nenhum canal é criado.
- Para cada `CommunicationChannel::all()`, na ordem de `ReminderGenerationService`: `opted_out` → pula; sem linha → pula (consent); sem contato no canal → pula; `!CommunicationPreference::permitsSending` → pula.
- Template: `findActiveFor('document_ready', $channel)`, senão `MessageTemplateDefaults::for(...)`. Variáveis: `tutor_name`, `patient_name`, `unit_name` e `clinic_name`.
- Mensagem: `OutboundMessage::compose(... origin: ORIGIN_AUTOMATION, legalBasis: consent, sourceType: null, sourceId: null, dedupeKey: OutboundMessage::buildDedupeKey('document_ready', 'document', $docId, $channel), createdBySystemUserId: null)`, gravada com `insertIfNew`. `null` (duplicado) → não conta.
- Devolve os ids das mensagens de `email` criadas. O WhatsApp fica `queued` (envio manual da 7A).

**Interface**
- Produz: `new DocumentGenerationService(GeneratedDocumentRepositoryInterface $documents, DocumentContentFactoryInterface $contents, DocumentRendererInterface $renderer, StorageInterface $storage, StoredObjectRepositoryInterface $objects, DocumentReadyNotifier $notifier, TenantContext $context, ?Closure $transaction = null, ?Closure $clock = null)`, `DocumentGenerationService::generate(int $documentId, bool $finalAttempt): DocumentGenerationResult`
- Produz: `DocumentGenerationResult::READY = 'ready'`, `DocumentGenerationResult::SKIPPED = 'skipped'`, `DocumentGenerationResult::FAILED = 'failed'`, `DocumentGenerationResult::status(): string`, `DocumentGenerationResult::emailMessageIds(): array`
- Produz: `new DocumentReadyNotifier(CommunicationPreferenceRepositoryInterface $preferences, MessageTemplateRepositoryInterface $templates, OutboundMessageRepositoryInterface $messages, DocumentSourceQueryInterface $sources, SenderNamesQueryInterface $names, TenantContext $context)`, `DocumentReadyNotifier::notify(GeneratedDocument $document): array`
- Consome: T-02 `GeneratedDocumentRepositoryInterface::claim(int $id, DateTimeImmutable $now): bool`, T-02 `GeneratedDocumentRepositoryInterface::markReady(int $id, int $storedObjectId, string $storageKey, int $sizeBytes, string $sha256, DateTimeImmutable $readyAt): bool`, T-02 `GeneratedDocumentRepositoryInterface::releaseClaim(int $id, string $errorCode): bool`, T-02 `GeneratedDocumentRepositoryInterface::markFailed(int $id, string $errorCode, DateTimeImmutable $failedAt): bool`, T-02 `GeneratedDocumentRepositoryInterface::markNotified(int $id, DateTimeImmutable $notifiedAt): bool`, T-02 `DocumentContentFactoryInterface::build(GeneratedDocument $document): DocumentContent`, T-02 `DocumentRendererInterface::render(DocumentContent $content): string`, T-02 `DocumentGenerationFailed::errorCode(): string`, T-05 `FakeGeneratedDocumentRepository::simulateConcurrentClaim(int $id): void`, T-05 `new FakeDocumentRenderer()`, T-05 `new FakeDocumentContentFactory()`, T-05 `new FakeSenderNamesQuery(array $namesByUnit)`

**Teste RED**
- `src/tests/Unit/DocumentGenerationServiceTest.php` — `generate` de documento `queued` devolve `ready`, grava 1 objeto no `FakeStorage` e 1 linha no `FakeStoredObjectRepository`; um segundo `generate` do mesmo id devolve `skipped`, sem novo objeto; com `simulateConcurrentClaim`, devolve `skipped`; falha de render com `finalAttempt` falso lança `DocumentGenerationFailed` e deixa o documento `queued` sem claim; com `finalAttempt` verdadeiro, deixa `failed` com `render_failed`; transação que lança apaga o objeto do storage; `notify_tutor` com tutor sem preferência não cria mensagem; com `opted_in` só em e-mail, cria 1 mensagem `document_ready` de canal `email`, base `consent` e `dedupe_key` `document_ready:document:<id>:email`, e devolve o id em `emailMessageIds()`; falha porque as classes ainda não existem (comando: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php | /usr/bin/grep -E 'DocumentGenerationServiceTest|Failed:'`)

**Critério de aceite**
- `DocumentGenerationServiceTest` com `PASS`; SUITE com `Failed: 0`.
- `DocumentGenerationFailed` lançado pelo service nunca carrega texto além do código (`/usr/bin/grep -n "getMessage()" src/app/Core/Application/DocumentGenerationService.php` não repassa mensagem de exceção para log nem para nova exceção).
- LINT imprime `No syntax errors detected` nos 4 arquivos.

**Validação**
- `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php | /usr/bin/grep -E 'DocumentGenerationServiceTest|Failed:'` (evidência: `PASS` e `Failed: 0`)
- `/usr/bin/grep -c "getMessage()" src/app/Core/Application/DocumentGenerationService.php` (evidência: `0`)
- LINT nos 4 arquivos (evidência: `No syntax errors detected`)
- Review Focus 2 e 3 (redelivery e consentimento): os casos `skipped`, `simulateConcurrentClaim`, sem preferência e `opted_in` só em e-mail do teste passam, e a T-21 repete o job real com `bin/worker.php --once` depois de republicar o mesmo `document_id` (contagem de `stored_object` do documento = 1)

### T-13 — DocumentTemplateService (cadastro e merge para paciente)

**Camada:** backend
**Dependências:** T-02, T-05
**Paralelizável:** sim
**Complexidade:** simples
**Agente:** Saitama

**Arquivos prováveis**
- `src/app/Core/Application/DocumentTemplateService.php`
- `src/tests/Unit/DocumentTemplateServiceTest.php`

**Notas de implementação**

Modelo: `MessageTemplateService.php` (7A). Templates são do tenant: `requiresUnitScope: false`.

**`save($data, $action)`**
- `$data` = `id` (opcional), `kind`, `name`, `body_text`, `status`.
- `kind` precisa ser `medical_certificate`.
- `name`: `trim`, de 1 a 120 caracteres.
- `body_text`: de 1 a 20000 caracteres.
- `unknownPlaceholders` não vazio → `InvalidArgumentException('Unknown placeholder: {{<nome>}}')`.
- Nome repetido no tenant (1062 do repositório ou checagem prévia) → `InvalidArgumentException('A document template with this name already exists')`.
- Autoriza com `entityType: 'document_template'`.

**`listActive($kind, $action)`**: sem template ativo, a tela usa `DocumentTemplateDefaults::bodyFor`.

**`mergeForPatient($templateId, $patientId, $action)`**
- `$templateId` 0 → texto de `DocumentTemplateDefaults::bodyFor('medical_certificate')`; senão, o template ativo.
- Variáveis:
  - de `patientSummary`: `patient_name`, `species`, `breed` e `tutor_name`;
  - de `namesForUnit(unidade ativa)`: `unit_name` e `clinic_name`;
  - `today` em `d/m/Y`, pelo relógio.
- Paciente inexistente → `DocumentSourceNotFoundException`.

**Interface**
- Produz: `new DocumentTemplateService(DocumentTemplateRepositoryInterface $templates, DocumentSourceQueryInterface $sources, SenderNamesQueryInterface $names, AuthorizationPolicyInterface $authorization, TenantContext $context, ?Closure $clock = null)`
- Produz: `DocumentTemplateService::save(array $data, string $action): DocumentTemplate`, `DocumentTemplateService::listAll(string $action): array`, `DocumentTemplateService::listActive(string $kind, string $action): array`, `DocumentTemplateService::mergeForPatient(int $templateId, int $patientId, string $action): string`
- Consome: T-02 `DocumentTemplate::create(int $tenantId, string $kind, string $name, string $bodyText, int $createdBySystemUserId): DocumentTemplate`, T-02 `DocumentTemplateRenderer::render(string $body, array $variables): string`, T-02 `DocumentTemplateRenderer::unknownPlaceholders(string $body): array`, T-02 `DocumentTemplateDefaults::bodyFor(string $kind): string`, T-05 `new FakeDocumentTemplateRepository(TenantContext $context)`, T-05 `new FakeSenderNamesQuery(array $namesByUnit)`

**Teste RED**
- `src/tests/Unit/DocumentTemplateServiceTest.php` — `save` com `{{cpf}}` no corpo lança `InvalidArgumentException`; `save` válido grava `active`; `mergeForPatient(0, <paciente>)` devolve o texto padrão com o nome do paciente no lugar de `{{patient_name}}`; `mergeForPatient` com template `inactive` lança `InvalidArgumentException`; falha porque o service ainda não existe (comando: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php | /usr/bin/grep -E 'DocumentTemplateServiceTest|Failed:'`)

**Critério de aceite**
- `DocumentTemplateServiceTest` com `PASS`; SUITE com `Failed: 0`.
- LINT imprime `No syntax errors detected` nos 2 arquivos.

**Validação**
- `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php | /usr/bin/grep -E 'DocumentTemplateServiceTest|Failed:'` (evidência: `PASS` e `Failed: 0`)
- LINT nos 2 arquivos (evidência: `No syntax errors detected`)

### T-14 — Worker: handler `document.generate`, varredor, `worker.php` e `bin/document-sweep.php`

**Camada:** infra
**Dependências:** T-03, T-06, T-07, T-08, T-10, T-11, T-12
**Paralelizável:** sim
**Complexidade:** alta
**Agente:** Aang

**Arquivos prováveis**
- `src/app/Core/Document/DocumentJobHandler.php`
- `src/app/Core/Document/DocumentSweeper.php`
- `src/bin/worker.php`
- `src/bin/document-sweep.php`
- `src/tests/Unit/DocumentWorkerTest.php`

**Notas de implementação**

Modelos: `CommunicationJobHandler.php` e `CommunicationScheduler.php` (7A), `bin/communication-scheduler.php`.

**`DocumentJobHandler`**
- `supports`: verdadeiro só com `type` = `DocumentJobPublisher::JOB_TYPE`.
- `handle`:
  - sem `tenantId` ou `document_id` inteiro positivo → log `document.job.invalid` e retorna (ack);
  - `finalAttempt` = `attempts + 1 >= maxAttempts`;
  - fábrica `($tenantId) => [DocumentGenerationService, MessageQueuePublisher]`;
  - chama `generate` e publica cada `emailMessageIds()` com `MessageQueuePublisher::publish($tenantId, $id)` depois do commit;
  - log `document.job.<status>` só com `document_id`, `tenant_id` e código;
  - deixa `DocumentGenerationFailed` subir (o loop faz `fail`/backoff).
- `forEnvironment` monta por job:
  - `PdoConnectionFactory::fromEnvironment()` e `TenantContext::authenticated($tenantId, $systemUserId)`;
  - repositórios PDO (T-06, `StoredObjectRepository`, 7A: `CommunicationPreferenceRepository`, `MessageTemplateRepository`, `OutboundMessageRepository`, `SenderNamesQuery`), `DocumentSourceQuery`;
  - `DocumentStorageFactory::fromEnvironment($context)` e `new DompdfDocumentRenderer()`;
  - a closure de transação sobre o PDO (`beginTransaction`/`commit`/`rollBack`).

**`DocumentSweeper::runOnce()`**
- Para cada tenant de `$activeTenants()`, `listStaleQueuedIds(now − 10 min, 100)` e `publisher->publish`.
- Falha num tenant: log com `tenant_id` e classe, sem parar os outros.
- Devolve `['tenants' => n, 'republished' => n, 'errors' => n]`.

**`src/bin/worker.php`**
- Acrescenta o handler lazy (`$documentHandlerFor`, mesmo padrão das linhas 76-83) e o `if` de despacho para `DocumentJobPublisher::JOB_TYPE` em `$handle`.
- Tick do varredor a cada `DOCUMENT_SWEEP_INTERVAL_SECONDS` (padrão 600, `0` desliga), só no modo contínuo, com exceção capturada pelo `errorTracker`.
- `DOCUMENT_SYSTEM_USER_ID` (padrão 1) via `$envInt`.
- O despacho de comunicação, o heartbeat, os sinais, o `--once` com `flock` e o tick do agendador da 7A ficam intactos.

**`src/bin/document-sweep.php`**: execução única. Imprime o JSON de `runOnce` e sai com 0, ou com 1 quando `errors` > 0 ou há falha de conexão (imprime só a classe).

**Interface**
- Produz: `DocumentJobHandler::forEnvironment(QueueInterface $queue, LoggerInterface $logger, int $systemUserId): DocumentJobHandler`, `new DocumentJobHandler(Closure $servicesFactory, LoggerInterface $logger)`, `DocumentJobHandler::supports(array $payload): bool`, `DocumentJobHandler::handle(QueueMessage $message): void`
- Produz: `new DocumentSweeper(Closure $activeTenants, Closure $repositoryFactory, DocumentJobPublisher $publisher, LoggerInterface $logger, ?Closure $clock = null)`, `DocumentSweeper::runOnce(): array`
- Produz: comando `php bin/document-sweep.php` (JSON com `tenants`, `republished`, `errors`)
- Consome: T-10 `DocumentJobPublisher::JOB_TYPE = 'document.generate'`, T-10 `DocumentJobPublisher::publish(int $tenantId, int $documentId): string`, T-12 `DocumentGenerationService::generate(int $documentId, bool $finalAttempt): DocumentGenerationResult`, T-12 `DocumentGenerationResult::emailMessageIds(): array`, T-06 `new GeneratedDocumentRepository(TenantContext $context, PDO $connection)`, T-07 `new DocumentSourceQuery(TenantContext $context, PDO $connection)`, T-08 `new DompdfDocumentRenderer()`, T-11 `new DocumentContentFactory(DocumentSourceQueryInterface $sources, SenderNamesQueryInterface $names, ?Closure $clock = null)`, T-03 `DocumentStorageFactory::fromEnvironment(TenantContext $tenant): StorageInterface`

**Teste RED**
- `src/tests/Unit/DocumentWorkerTest.php` — `supports` aceita só `document.generate`; `handle` com `attempts` 2 e `maxAttempts` 3 chama `generate` com `finalAttempt` verdadeiro; job sem `tenantId` não chama a fábrica; `DocumentGenerationFailed` sobe; resultado `ready` com um id de e-mail publica `communication.message.send` no `FakeQueue`; `runOnce` com dois tenants republica os ids presos e uma exceção no primeiro não impede o segundo (`errors` 1); falha porque as classes ainda não existem (comando: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php | /usr/bin/grep -E 'DocumentWorkerTest|CommunicationWorkerTest|QueueWorkerLoop|Failed:'`)

**Critério de aceite**
- `DocumentWorkerTest` e `CommunicationWorkerTest` com `PASS`; SUITE com `Failed: 0`.
- Gate da Onda 4, depois do rebuild:
  - um pedido real de carteira de vacinação `F7B teste` sai de `queued` para `ready` depois de `docker compose exec -T worker php bin/worker.php --once --max-jobs=20` (`SELECT status, version, stored_object_id FROM generated_document WHERE id = <id>` mostra `ready`, `1` e id não nulo);
  - o arquivo existe em `/var/www/html/var/documents/cv/` no container;
  - `docker compose exec -T worker php bin/document-sweep.php` sai com 0 e imprime JSON com as 3 chaves.
- O log do worker do gate não contém `example.invalid` nem `F7B teste`.
- LINT imprime `No syntax errors detected` nos 5 arquivos.

**Validação**
- `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php | /usr/bin/grep -E 'DocumentWorkerTest|CommunicationWorkerTest|QueueWorkerLoop|Failed:'` (evidência: `PASS` e `Failed: 0`)
- LINT nos 5 arquivos (evidência: `No syntax errors detected`)
- Gate da Onda 4: `docker compose exec -T worker php bin/worker.php --once --max-jobs=20; echo "exit=$?"` seguido de `SELECT status, version, stored_object_id FROM generated_document ORDER BY id DESC LIMIT 1` (evidência: `exit=0` e `ready`)
- Gate da Onda 4: `docker compose exec -T worker php bin/document-sweep.php; echo "exit=$?"` (evidência: JSON com `tenants`, `republished` e `errors`, e `exit=0`)
- Gate da Onda 4: `docker compose logs --since 10m worker | /usr/bin/grep -cE "example\.invalid|F7B teste"` (evidência: `0`)

### T-15 — Tela de pedido de documento

**Camada:** frontend
**Dependências:** T-06, T-07, T-10, T-13
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Kratos

**Arquivos prováveis**
- `src/app/control/clinic/DocumentRequestForm.php`
- `src/tests/Integration/DocumentRequestFormIntegrationTest.php`

**Notas de implementação**

Modelos: `CommunicationComposeForm.php` (7A: `CvCombo::reload` com JSON_HEX_*, `onChangeTemplate`, catches, `makeMessageService`, `resolveTenantContext`) e `CommunicationMessageView.php` (`const TOUCH`).

- **Rota**: `DocumentRequestForm&kind=<kind>&source_id=<id>`. `kind` fora de `DocumentKind::ALL` ou `source_id` não inteiro → mensagem fixa `Invalid document request` sem formulário. O construtor lê `kind` e `source_id` do request (lição da T-20 da 7A).
- **Cabeçalho**: `CvPage::header(_t('New document'), _t(<rótulo do tipo>), [Voltar])`.
- **Campos**:
  - tipo, somente leitura;
  - para `medical_certificate`, combo de template (`listActive`; opção 0 = `Default text`) e `TText` com o texto (carga inicial e `onChangeTemplate` via `mergeForPatient`, recarga com `CvCombo`/JSON seguro);
  - para `surgery_consent`, aviso de que o texto registrado na cirurgia será usado;
  - `TCheckButton` `notify_tutor` (`Notify the tutor when ready`), com o resumo do consentimento por canal ("e-mail: autorizado / não autorizado"), lido de `CommunicationPreferenceRepository::findForTutor` sem mostrar o contato;
  - botão `Generate PDF` (`btn-primary cv-touch-target`).
- **`onSave`** (POST):
  - `TTransaction::open('permission')` → `request(...)` → `close` → `DocumentJobPublisher::publish` com `RedisQueue::fromEnvironment()`. Falha do Redis é logada só com `document_id` e não desfaz o pedido (o varredor republica).
  - `TToast`/`TMessage` `Document requested. It will be available in the list in a few moments.` e redireciona para `DocumentList&patient_id=<id>`.
- **Erros**: catches `AuthorizationDenied` → `MissingTenantContext` → `DocumentSourceNotFoundException` → `Exception` (`CvFormat::userError`), com os dados do formulário mantidos.
- **Layout**: rótulos à esquerda (sem `text-align:right`).

**Interface**
- Produz: tela `index.php?class=DocumentRequestForm&kind=<kind>&source_id=<id>` com `onSave` (POST) e `onChangeTemplate`
- Consome: T-10 `DocumentRequestService::request(string $kind, int $sourceId, ?int $templateId, ?string $bodyText, bool $notifyTutor, string $action): GeneratedDocument`, T-10 `DocumentJobPublisher::publish(int $tenantId, int $documentId): string`, T-13 `DocumentTemplateService::listActive(string $kind, string $action): array`, T-13 `DocumentTemplateService::mergeForPatient(int $templateId, int $patientId, string $action): string`, T-06 `new GeneratedDocumentRepository(TenantContext $context, PDO $connection)`, T-07 `new DocumentSourceQuery(TenantContext $context, PDO $connection)`

**Teste RED**
- `src/tests/Integration/DocumentRequestFormIntegrationTest.php` — em subprocesso com `require "init.php"` (padrão de `BedFormIntegrationTest`), a tela com `kind=medical_certificate&source_id=<paciente>` renderiza o campo de texto, o combo de template e o botão `Generate PDF` com `cv-touch-target`; `kind=foo` mostra `Invalid document request`; o template com nome `<script>x</script>` aparece escapado; o fonte não usa `getMessage()` na tela; falha porque a tela ainda não existe (comando: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php | /usr/bin/grep -E 'DocumentRequestFormIntegrationTest|ControllerRawExceptionMessageTest|Failed:'`)

**Critério de aceite**
- `DocumentRequestFormIntegrationTest` e `ControllerRawExceptionMessageTest` com `PASS`; SUITE com `Failed: 0`.
- No gate da Onda 4 (desktop 1366×768): `DocumentRequestForm&kind=vaccination_card&source_id=<paciente F7B>` renderiza com 0 mensagens de console de nível error e, ao clicar `Generate PDF`, redireciona para `DocumentList&patient_id=<id>` com o documento `queued` ou `ready`.
- LINT imprime `No syntax errors detected` nos 2 arquivos.

**Validação**
- `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php | /usr/bin/grep -E 'DocumentRequestFormIntegrationTest|ControllerRawExceptionMessageTest|Failed:'` (evidência: `PASS` e `Failed: 0`)
- LINT nos 2 arquivos (evidência: `No syntax errors detected`)
- Gate da Onda 4 (Playwright em `http://127.0.0.1:8081`): snapshot da tela e da lista depois do clique (evidência: linha `Carteira de vacinação` v1 na lista)

### T-16 — Lista de documentos, download e nova tentativa

**Camada:** frontend
**Dependências:** T-03, T-06, T-10
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Yoda

**Arquivos prováveis**
- `src/app/control/clinic/DocumentList.php`
- `src/tests/Integration/DocumentListIntegrationTest.php`

**Notas de implementação**

Modelos: `CommunicationMessageList.php` (tabela `cv-table` à mão, `CvFormat::e`) e `EncounterView::onDownloadDocument` (:1909-1956: `ob_end_clean`, cabeçalhos, 404 com texto fixo).

**Lista**
- Rota `DocumentList[&patient_id=<id>]`.
- Cabeçalho `CvPage::header(_t('Documents'), <subtítulo do paciente ou da unidade>, [Atualizar])`.
- Colunas `Document`, `Version`, `Status` (badge `Queued`/`Ready`/`Failed`), `Requested at` e `Actions`, com cabeçalhos alinhados à esquerda.
- Valores com `CvFormat::e`.
- Estado vazio `No documents yet`.

**Ações por status**
- `ready` → link `Download` (`cv-touch-target`, `href` `index.php?class=DocumentList&method=onDownload&id=<id>&static=1`).
- `failed` → `Try again` por TQuestion com `onRetry&id=` (só id), que chama `retry`, publica e recarrega.
- `queued` → texto `Processing…`.

**`onDownload` (estático)**
- `id` inteiro positivo → `TTransaction::open('permission')` → `download` → `close`.
- Qualquer exceção, inclusive `AuthorizationDenied` → `rollback` + `error_log(__METHOD__ . ': ' . get_class($e))` (sem mensagem) → 404.
- Saída:
  - limpa os buffers;
  - 404 → `Content-Type: text/plain; charset=utf-8` e texto `_t('Document not found')`;
  - sucesso → `Content-Type: application/pdf`, `Content-Disposition: attachment; filename="<file_name>"`, `Content-Length`, `Cache-Control: private, no-store`, `Content-Security-Policy: sandbox`, `X-Content-Type-Options: nosniff`;
  - `exit`.

**Serviço**: `makeService` monta `DocumentRequestService` com `DocumentStorageFactory::fromEnvironment($context)`.

**Interface**
- Produz: tela `index.php?class=DocumentList[&patient_id=<id>]`, download `index.php?class=DocumentList&method=onDownload&id=<document_id>&static=1`, nova tentativa `onRetry&id=<document_id>`
- Consome: T-10 `DocumentRequestService::listForUnit(?int $patientId, string $action): array`, T-10 `DocumentRequestService::download(int $documentId, string $action): array`, T-10 `DocumentRequestService::retry(int $documentId, string $action): void`, T-10 `DocumentJobPublisher::publish(int $tenantId, int $documentId): string`, T-03 `DocumentStorageFactory::fromEnvironment(TenantContext $tenant): StorageInterface`, T-06 `new GeneratedDocumentRepository(TenantContext $context, PDO $connection)`

**Teste RED**
- `src/tests/Integration/DocumentListIntegrationTest.php` — em subprocesso com `require "init.php"`: `onDownload` com `id` inexistente devolve status 404 e o corpo `Document not found` sem `Content-Disposition`; a lista com documento `ready` renderiza o link `method=onDownload&id=` com `cv-touch-target` e sem nome de paciente no `href`; título `<script>` aparece escapado; falha porque a tela ainda não existe (comando: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php | /usr/bin/grep -E 'DocumentListIntegrationTest|ControllerRawExceptionMessageTest|Failed:'`)

**Critério de aceite**
- `DocumentListIntegrationTest` e `ControllerRawExceptionMessageTest` com `PASS`; SUITE com `Failed: 0`.
- No gate da Onda 4, `curl` autenticado no `href` de download de documento `ready` devolve `200`, `Content-Type: application/pdf` e corpo iniciando por `%PDF-`. O mesmo `curl` com o id de um documento de outra unidade devolve `404` com `Document not found`.
- LINT imprime `No syntax errors detected` nos 2 arquivos.

**Validação**
- `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php | /usr/bin/grep -E 'DocumentListIntegrationTest|ControllerRawExceptionMessageTest|Failed:'` (evidência: `PASS` e `Failed: 0`)
- LINT nos 2 arquivos (evidência: `No syntax errors detected`)
- Gate da Onda 4 (Playwright `request` com a sessão do admin): `GET http://127.0.0.1:8081/index.php?class=DocumentList&method=onDownload&id=<id>&static=1` (evidência: status 200, `application/pdf`, corpo `%PDF-`)
- Review Focus 1 (sem oráculo), roteiro B da T-21: o mesmo GET com id de documento de outra unidade, de documento `queued` e com id 999999999 (evidência: os três com status 404, corpo idêntico `Documento não encontrado` e sem `Content-Disposition`)

### T-17 — Telas de template de documento (lista e formulário)

**Camada:** frontend
**Dependências:** T-06, T-13
**Paralelizável:** sim
**Complexidade:** simples
**Agente:** Saitama

**Arquivos prováveis**
- `src/app/control/clinic/DocumentTemplateList.php`
- `src/app/control/clinic/DocumentTemplateForm.php`
- `src/tests/Integration/DocumentTemplateScreensIntegrationTest.php`

**Notas de implementação**

Modelos: `MessageTemplateList.php` e `MessageTemplateForm.php` (7A, incluindo a correção do alvo de 44 px da T-23 da 7A).

- **`DocumentTemplateList`**: colunas `Name`, `Kind` (rótulo `Medical certificate`), `Status` e `Actions` (`Edit`, `cv-touch-target`); botão `New template`; estado vazio.
- **`DocumentTemplateForm`** (`&id=` para editar):
  - campos `Name`, `Text` (`TText`, 12 linhas) e `Status` (`Active`/`Inactive`, combo); `kind` fixo `medical_certificate`, oculto;
  - abaixo do texto, a lista dos placeholders permitidos (`DocumentTemplateRenderer::PLACEHOLDERS`) no formato `{{patient_name}}`;
  - `onSave` por POST;
  - erros por `CvFormat::userError`, com os dados mantidos.
- Rótulos à esquerda.

**Interface**
- Produz: telas `index.php?class=DocumentTemplateList` e `index.php?class=DocumentTemplateForm[&id=<template_id>]`
- Consome: T-13 `DocumentTemplateService::save(array $data, string $action): DocumentTemplate`, T-13 `DocumentTemplateService::listAll(string $action): array`, T-06 `new DocumentTemplateRepository(TenantContext $context, PDO $connection)`

**Teste RED**
- `src/tests/Integration/DocumentTemplateScreensIntegrationTest.php` — em subprocesso: o formulário renderiza os campos `name`, `body_text` e `status` e a lista de placeholders com `{{patient_name}}`; a lista mostra `Edit` com `cv-touch-target`; nome de template `<script>` aparece escapado; falha porque as telas ainda não existem (comando: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php | /usr/bin/grep -E 'DocumentTemplateScreensIntegrationTest|ControllerRawExceptionMessageTest|Failed:'`)

**Critério de aceite**
- `DocumentTemplateScreensIntegrationTest` e `ControllerRawExceptionMessageTest` com `PASS`; SUITE com `Failed: 0`.
- LINT imprime `No syntax errors detected` nos 3 arquivos.

**Validação**
- `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php | /usr/bin/grep -E 'DocumentTemplateScreensIntegrationTest|ControllerRawExceptionMessageTest|Failed:'` (evidência: `PASS` e `Failed: 0`)
- LINT nos 3 arquivos (evidência: `No syntax errors detected`)

### T-18 — Navegação (menu, CvNav, ações em PatientForm, VaccinationCardView, SurgeryView e PrescriptionForm)

**Camada:** frontend
**Dependências:** T-04
**Paralelizável:** sim
**Complexidade:** simples
**Agente:** Jaspion

**Arquivos prováveis**
- `src/menu.xml`
- `src/app/lib/widget/CvNav.php`
- `src/app/control/clinic/PatientForm.php`
- `src/app/control/clinic/VaccinationCardView.php`
- `src/app/control/clinic/SurgeryView.php`
- `src/app/control/clinic/PrescriptionForm.php`
- `src/tests/Integration/DocumentNavigationIntegrationTest.php`

**Notas de implementação**

As rotas estão fixadas em `plan.md § Decisões de arquitetura`. A ligação é feita por `href`, sem `method`. Diffs restritos ao cabeçalho ou ao link.

- **`menu.xml`**: depois do bloco `CRM / Communication` (linhas 90-101), item `_t{Documents}` (`fas:file-pdf fa-fw`) com submenu `_t{Documents}` → `DocumentList` e `_t{Document templates}` → `DocumentTemplateList`.
- **`CvNav`**: grupo `'documents' => ['documents' => ['Documents', 'index.php?class=DocumentList'], 'templates' => ['Document templates', 'index.php?class=DocumentTemplateList']]`.
- **`PatientForm.php:248`** (modo edição): ações `Documents` → `DocumentList&patient_id=<id>` e `Medical certificate` → `DocumentRequestForm&kind=medical_certificate&source_id=<id>`.
- **`VaccinationCardView.php:92`**: ação `Generate PDF` → `DocumentRequestForm&kind=vaccination_card&source_id=<patient_id>`.
- **`SurgeryView.php:469`**: ação `Consent PDF` → `DocumentRequestForm&kind=surgery_consent&source_id=<surgery_id>`.
- **`PrescriptionForm.php:155`**: ao lado do link de PDF síncrono existente, link `Archive PDF` → `DocumentRequestForm&kind=prescription&source_id=<prescription_id>`. O `onGeneratePdf` não é alterado.
- **Teste**: confere o parse do `menu.xml` e roda os testes de navegação existentes.

**Interface**
- Produz: itens de menu `Documents` → `DocumentList` e `Document templates` → `DocumentTemplateList`, `CvNav` grupo `documents`, e os 5 links de entrada acima
- Consome: T-04 programas `DocumentList` (`Central Vet - Document List`), `DocumentRequestForm` (`Central Vet - Document Request Form`), `DocumentTemplateList` (`Central Vet - Document Template List`) e `DocumentTemplateForm` (`Central Vet - Document Template Form`), cada um em `system_group_program` dos grupos 1, 2, `Clínico – Internação` e `Clínico – Cirurgia`, e em nenhum outro

**Teste RED**
- `src/tests/Integration/DocumentNavigationIntegrationTest.php` — `menu.xml` tem `DocumentList` e `DocumentTemplateList`; `CvNav::group('documents')` devolve as 2 abas; os fontes de `PatientForm`, `VaccinationCardView`, `SurgeryView` e `PrescriptionForm` contêm `class=DocumentRequestForm&kind=` com o tipo correto; falha porque a navegação ainda não existe (comando: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php | /usr/bin/grep -E 'NavigationIntegrationTest|Failed:'`)

**Critério de aceite**
- `DocumentNavigationIntegrationTest`, `CommunicationNavigationIntegrationTest`, `SurgeryNavigationIntegrationTest` e `HospitalizationNavigationIntegrationTest` com `PASS`; SUITE com `Failed: 0`.
- LINT imprime `No syntax errors detected` nos 6 PHP; `php -r 'simplexml_load_file("menu.xml") or exit(1);'` sai com 0.

**Validação**
- `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php | /usr/bin/grep -E 'NavigationIntegrationTest|Failed:'` (evidência: `PASS` nas 4 classes e `Failed: 0`)
- LINT nos 6 PHP (evidência: `No syntax errors detected`)
- `git diff --stat feat/fase-7a-comunicacao -- src/app/control/clinic/PrescriptionForm.php` (evidência: poucas linhas, nenhuma dentro de `onGeneratePdf`)

### T-19 — i18n pt/en das telas e mensagens de domínio

**Camada:** frontend
**Dependências:** T-09, T-14, T-15, T-16, T-17, T-18
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Levi

**Arquivos prováveis**
- `src/app/config/translations.json`
- `src/app/Core/Presentation/UserMessage.php`
- `src/tests/Unit/UserMessageTest.php`

**Notas de implementação**

**Fontes do trabalho**
- Todas as linhas `i18n:` e `i18n-domínio:` do board e de `notes.md § Descobertas` das Ondas 1–4.
- Os textos `_t()` de `DocumentRequestForm`, `DocumentList`, `DocumentTemplateList`, `DocumentTemplateForm`, `PendingCenter` (`Failed documents`), `menu.xml` e `CvNav`.
- As ações novas das telas da T-18.

**`translations.json`**: objetos `{en, pt}` em ordem alfabética por `en`, sem duplicar chave existente. Colisão de chave com significado diferente (ex.: `Status`, `Version`) vai para o board e reaproveita a tradução existente quando o sentido é o mesmo.

**`UserMessage`**
- Exceptions de domínio:
  - estáticas: `Document not found`, `Document source not found`, `Patient has no vaccinations to print`, `Surgery consent has not been recorded`, `Document text has unresolved placeholders`, `Document template is not available`, `A document template with this name already exists`, `Unknown document kind`, `Could not allocate document version`;
  - padrões: `Document <id> can no longer be retried`, `Unknown placeholder: {{<nome>}}`, `Document generation failed: <code>`.
- Os totais de `UserMessageTest.php:122-123` são atualizados com o número real.
- Os casos novos entram em `UserMessageTest`.

**Interface**
- Produz: chaves pt/en das telas e mensagens da 7B; `UserMessage` com as mensagens de domínio novas
- Consome: nada

**Teste RED**
- `src/tests/Unit/UserMessageTest.php` — os casos novos (`Document not found` → `Documento não encontrado`; `Document 7 can no longer be retried` → texto pt com o id; `Patient has no vaccinations to print` → texto pt) e a exigência de tradução dos `_t` das 4 telas novas falham antes das entradas existirem (comando: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php | /usr/bin/grep -E 'UserMessageTest|TranslationsJson|Failed:'`)

**Critério de aceite**
- `UserMessageTest` com `PASS`; SUITE com `Failed: 0`.
- `python3 -c "import json;d=json.load(open('src/app/config/translations.json'));e=[x['en'] for x in d];print(len(e)==len(set(e)))"` imprime `True`.
- Fetch autenticado em pt das 4 telas novas no gate da Onda 5 sem a string `Message not found`.

**Validação**
- `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php | /usr/bin/grep -E 'UserMessageTest|TranslationsJson|Failed:'` (evidência: `PASS` e `Failed: 0`)
- `python3 -c "import json;d=json.load(open('src/app/config/translations.json'));e=[x['en'] for x in d];print(len(e)==len(set(e)))"` (evidência: `True`)
- Gate da Onda 5 (validador, sessão admin em pt): `GET` das 4 telas `| /usr/bin/grep -c "Message not found"` (evidência: `0` em cada)

### T-20 — Runbook de documentos, índice e passo da 0013 e cron na hospedagem 5.7

**Camada:** docs
**Dependências:** T-01, T-03, T-04, T-14
**Paralelizável:** sim
**Complexidade:** simples
**Agente:** Gandalf

**Arquivos prováveis**
- `docs/runbooks/documentos.md`
- `docs/runbooks/README.md`
- `docs/runbooks/shared-hosting-mysql57.md`

**Notas de implementação**

Modelos: `docs/runbooks/comunicacao.md` e a seção da 0012 em `shared-hosting-mysql57.md` (7A).

**`documentos.md`** (pt-BR) cobre:
- os 4 tipos e as fontes;
- o fluxo assíncrono (pedido → fila `document.generate` → worker → `ready`/`failed`, 3 tentativas, varredor);
- o versionamento;
- o storage: `DOCUMENT_STORAGE_DRIVER` `local`/`s3`, `DOCUMENT_STORAGE_LOCAL_ROOT` fora do webroot e o volume `app_documents`. Com `s3`, o bucket do MinIO precisa existir (profile `minio`);
- o download só pela tela com RBAC/unidade e 404 sem oráculo;
- o aviso `document_ready` (só com opt-in, sem link e sem anexo);
- as variáveis `DOCUMENT_*`;
- as tabelas da 0013;
- os 4 programas e grupos;
- a operação: `bin/document-sweep.php`, `bin/worker.php --once`, como reprocessar um `failed` pela tela e onde ficam os arquivos;
- os limites do MVP (Excluído do plano);
- retenção e expurgo não definidos.

**`README.md`**: entrada nova no índice.

**`shared-hosting-mysql57.md`**: passo da 0013 com o prefixo `17-`; pasta de documentos acima do `public_html` com permissão 0750; e cron com os comandos exatos:
- `*/5 * * * * php <app>/src/bin/document-sweep.php`;
- o cron do `worker.php --once` já existente processa `document.generate`.

Também cita que o cron exige as variáveis de DB/Redis e `DOCUMENT_*` no ambiente.

**Interface**
- Produz: runbook `docs/runbooks/documentos.md`
- Consome: T-14 comando `php bin/document-sweep.php` (JSON com `tenants`, `republished`, `errors`), T-03 variáveis `DOCUMENT_STORAGE_DRIVER`, `DOCUMENT_STORAGE_LOCAL_ROOT`, `DOCUMENT_SYSTEM_USER_ID`, `DOCUMENT_SWEEP_INTERVAL_SECONDS` e o volume `app_documents:/var/www/html/var/documents`

**Teste RED**
- sem teste: documentação; conferida por grep dos comandos, variáveis e prefixo `17-`

**Critério de aceite**
- `docs/runbooks/documentos.md` cita as 4 variáveis `DOCUMENT_*`, `bin/document-sweep.php`, `document.generate`, `app_documents` e `generated_document`.
- `docs/runbooks/README.md` lista `documentos.md`.
- `shared-hosting-mysql57.md` cita `17-20261006_0013_phase7b_documents.sql` e a linha de cron do `document-sweep.php`.

**Validação**
- `/usr/bin/grep -cE "DOCUMENT_STORAGE_DRIVER|DOCUMENT_STORAGE_LOCAL_ROOT|DOCUMENT_SYSTEM_USER_ID|DOCUMENT_SWEEP_INTERVAL_SECONDS|document-sweep.php|document.generate|app_documents|generated_document" docs/runbooks/documentos.md` (evidência: número ≥ 8)
- `/usr/bin/grep -n "documentos.md" docs/runbooks/README.md` (evidência: uma linha)
- `/usr/bin/grep -nE "17-20261006_0013_phase7b_documents.sql|document-sweep.php" docs/runbooks/shared-hosting-mysql57.md` (evidência: as duas ocorrências)

### T-21 — Validação final ponta a ponta e SQL de limpeza `F7B teste`

**Camada:** qa
**Dependências:** T-19, T-20
**Paralelizável:** não
**Complexidade:** média
**Agente:** Spock

**Arquivos prováveis**
- `.claude/tasks/mar-20261006-1347-fase-7b-documentos/sql/T-21-cleanup.sql`

**Notas de implementação**

Modelo: `.claude/tasks/mar-20261006-0842-fase-7a-comunicacao/sql/T-23-cleanup.sql`. O orquestrador rebuilda e faz o login admin. O validador navega só em `http://127.0.0.1:8081` e cria os registros com o prefixo `F7B teste`: tutor `F7B teste Tutor`, e-mail `f7b.teste@example.invalid`, opt-in só de e-mail; paciente `F7B teste Pet` com uma vacinação; cirurgia com consentimento registrado; template `F7B teste Atestado`.

**Roteiro A** (desktop 1366×768 e tablet 820×1180)
- Gerar os 4 tipos pelas entradas da T-18, rodar `docker compose exec -T worker php bin/worker.php --once --max-jobs=20`, ver `ready` na lista, baixar cada PDF (`%PDF-`) e conferir o título de cada tipo.
- Gerar a carteira de novo (v2).
- Atestado com "Avisar o tutor": 1 `communication_message` `document_ready` `email` `queued` para o tutor de teste, publicada (driver `log`).
- Redelivery (Review Focus 2): republicar o `document_id` de um documento já `ready` com `docker compose exec -T worker php -r '<require autoload; (new CentralVet\Application\DocumentJobPublisher(CentralVet\Queue\RedisQueue::fromEnvironment()))->publish(<tenant>, <id>);>'`, rodar `bin/worker.php --once` e conferir no log `document.job.skipped` e uma única linha de `stored_object` para o documento. Não forçar falha alterando ambiente ou banco: o caminho `failed` é coberto pelos testes da T-12 e da T-09.

**Roteiro B**
- Os 5 itens de Review Focus do `plan.md` e a permissão negada:
  - usuário sem os programas novos, se houver; senão, registrar como pendência coberta por teste;
  - Central de Pendências com um `document_failed`, se for possível produzi-lo sem SQL de escrita; senão, coberto pela T-09.

**`T-21-cleanup.sql`**, preparado e **não executado**:
- `SET NAMES utf8mb4;` e `START TRANSACTION`;
- SELECTs prévios;
- DELETE com WHERE por prefixo/ids, na ordem `communication_message` (`document_ready` do tutor `F7B teste`) → `generated_document` → `stored_object` (ids dos documentos) → `document_template` (`F7B teste%`) → registros clínicos `F7B teste`;
- `COMMIT` comentado;
- comentário com a lista de `storage_key` a remover do volume por `docker compose exec app rm` (também não executado).

**Interface**
- Produz: `sql/T-21-cleanup.sql` (preparado) e o relatório E2E em `reports/T-21.md`
- Consome: nada

**Teste RED**
- sem teste: validação E2E por Playwright e comandos do worker; o SQL de limpeza é preparado e não executado

**Critério de aceite**
- Os 4 tipos chegam a `ready` e o download devolve `200` + `%PDF-`; a segunda carteira tem `version` 2.
- Os 5 itens de Review Focus têm evidência (status HTTP, contagem SQL de leitura ou medida no navegador).
- `SELECT COUNT(*) FROM stored_object WHERE id IN (SELECT stored_object_id FROM generated_document WHERE id = <id republicado>)` = 1.
- `docker compose logs --since 30m worker | /usr/bin/grep -cE "example\.invalid|F7B teste"` = 0.
- O arquivo `T-21-cleanup.sql` tem `SET NAMES utf8mb4;` na primeira instrução e nenhum `DELETE` sem `WHERE`.
- Contagens de `patient`, `vaccination`, `prescription`, `surgery`, `stored_object` e `communication_message` só cresceram em relação ao bloqueio (registros existentes preservados).

**Validação**
- Playwright (roteiro A) em `http://127.0.0.1:8081`, desktop e tablet (evidência: 4 linhas `Pronto` na lista e 4 downloads `%PDF-`)
- Review Focus 5 (UI) no tablet 820×1180: `getComputedStyle(label).textAlign` dos rótulos de `DocumentRequestForm` e `DocumentTemplateForm` e dos `th` de `DocumentList`, e `getBoundingClientRect().height` de `Gerar PDF`, `Baixar` e `Tentar novamente` (evidência: `left`/`start` e altura ≥ 44)
- `SELECT kind, version, status FROM generated_document WHERE patient_id = <F7B teste Pet> ORDER BY id` (evidência: 5 linhas `ready`, carteira com 1 e 2)
- `SELECT channel, legal_basis, status FROM communication_message WHERE purpose = 'document_ready' AND tutor_id = <F7B teste Tutor>` (evidência: uma linha `email`, `consent`)
- `/usr/bin/grep -ciE "delete from [a-z_]+ *;" .claude/tasks/mar-20261006-1347-fase-7b-documentos/sql/T-21-cleanup.sql` (evidência: `0`)
- `docker compose logs --since 30m worker | /usr/bin/grep -cE "example\.invalid|F7B teste"` (evidência: `0`)

## Legenda

- [ ] pendente
- [~] em andamento
- [x] concluído com evidência
- [!] bloqueado
