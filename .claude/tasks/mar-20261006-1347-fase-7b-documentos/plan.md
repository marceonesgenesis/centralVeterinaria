# Plano: Fase 7B — Documentos (PDF assíncrono, storage privado, aviso document_ready)

## Objetivo
Entregar o MVP do PRD §8.21 ("templates por tenant e merge de dados; PDF assíncrono; storage privado e versionamento") e o item "documentos" da Central de Pendências (§8.23). Quatro documentos são gerados em PDF pelo worker existente: carteira de vacinação, receita, atestado e termo de consentimento cirúrgico. Os PDFs ficam num storage privado, que pode ser local (pasta fora do webroot) ou S3/MinIO, e são versionados. A listagem e o download passam por RBAC e isolamento por tenant e por unidade. Quando o pedido marcar "avisar o tutor", o aviso `document_ready` sai pelo módulo de comunicação da 7A, respeitando o consentimento.

## Premissas
- Repositório único `/var/www/html/centralvet`. O orquestrador cria a branch `feat/fase-7b-documentos` a partir de `feat/fase-7a-comunicacao` @ `83029c3` (`repos.py --preparar`). O checkout é compartilhado, com caminho exclusivo, RED antes da implementação e trailers `Task: T-xx` / `Task: T-xx (RED)`.
- Comandos rodados a partir de `/var/www/html/centralvet` (mesmas convenções da 7A):
  - **LINT**: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php -l <arquivo relativo a src/>`.
  - **SUITE**: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php`. Não filtra testes; use `| /usr/bin/grep -E '<Classe>|Failed:'`. Roda no `centralvet_test`. SUITEs simultâneas podem dar falso FAIL em testes de Redis ou deadlock. No fim da 7A eram 947 testes com `Failed: 0`.
  - **PYTEST57**: `python3 scripts/test-prepare-mysql57.py`.
  - **GATE de tela**: o orquestrador roda `docker compose build app worker && docker compose up -d app worker && docker compose restart nginx`. O `up` cria o volume `app_documents`. Depois faz login de admin no Playwright, com autorização do usuário. O validador navega só em `http://127.0.0.1:8081`.
- **Inventário de hoje.** Explorado nesta fase:
  - Todo PDF é gerado de forma síncrona, com dompdf 3.1.5 (`new \Dompdf\Dompdf()`). O HTML é montado inline no controller e enviado com `echo`/`exit`, sem arquivo gravado. Isso acontece em `PrescriptionForm::onGeneratePdf` (receita), `SaleForm::onGenerateReceiptPdf` (recibo de venda) e `ProductList::onReport`.
  - O atendimento só tem `window.print()`.
  - Não há impressão para carteira de vacinação, recebimento (`PaymentForm`), consentimento de cirurgia (texto em `surgery.consent_text`, gravado pela 6B), atestado, orçamento nem laudo.
  - Não existe tabela de orçamento.
  - `pablodalloglio/fpdf` e `adianti/pdfdesigner` estão instalados e sem uso.
  - Já existem `StorageInterface`, `S3CompatibleStorage` (assinatura SigV4 própria, sem aws-sdk) e `stored_object` (0001), com `StoredObjectRepository::record`. Os anexos do atendimento usam esse caminho (`EncounterDocumentService`, download seguro em `EncounterView::onDownloadDocument`).
- **Documentos do MVP:**
  - `vaccination_card`: carteira de vacinação do paciente, todas as doses do tenant.
  - `prescription`: receita, a partir de `prescription` e `prescription_item`.
  - `medical_certificate`: atestado, com texto livre a partir de template por tenant e merge.
  - `surgery_consent`: termo de consentimento cirúrgico, com snapshot de `surgery.consent_text` e do signatário, que exige consentimento já registrado.
  - Ficam fora orçamento (sem tabela), recibo/recebimento (financeiro já tem recibo síncrono de venda), laudo de exame (já é upload) e relatório de alta. Ver Excluído.
- **Storage.**
  - A interface `StorageInterface` (já existente) ganha o driver `LocalFilesystemStorage`, que grava em pasta fora do webroot (`src/`). A fábrica `DocumentStorageFactory` escolhe o driver por `DOCUMENT_STORAGE_DRIVER`: `local` (padrão) ou `s3`, que usa o `S3CompatibleStorage` existente.
  - No Docker, a pasta é `/var/www/html/var/documents`, num volume nomeado novo, `app_documents`.
  - Na hospedagem compartilhada, `DOCUMENT_STORAGE_LOCAL_ROOT` aponta para uma pasta acima de `public_html`.
  - Os anexos existentes (`S3CompatibleStorage::fromEnvironment` em `PatientForm`, `EncounterView` e `ExamResultForm`) não mudam.
- **Download.**
  - Só por id numérico do documento: `DocumentList&method=onDownload&id=<id>&static=1`. Cada download passa por checagem de RBAC, do tenant da sessão e da unidade ativa.
  - O documento de outra unidade ou de outro tenant responde como inexistente (404, mesmo texto, sem oráculo).
  - Nada de URL pré-assinada, token público ou dado pessoal na URL.
  - O nome do arquivo é `<kind>-<id>-v<versão>.pdf`, sem nome de paciente.
- **Aviso ao tutor.**
  - O pedido tem a opção "Avisar o tutor quando ficar pronto" (`notify_tutor`, desmarcada por padrão).
  - Quando o PDF fica pronto, o worker cria um `communication_message` de finalidade `document_ready`, origem `automation` e base legal `consent`, para cada canal em que o tutor tem `opted_in` explícito e contato. A regra é `CommunicationPreference::permitsSending` da 7A.
  - O e-mail é publicado na fila da 7A. O WhatsApp fica `queued` para envio manual e aparece na Central.
  - O texto é o template `document_ready` ativo ou o padrão da 7A ("Entre em contato para recebê-lo"), **sem link e sem anexo**.
  - A deduplicação usa `dedupe_key = document_ready:document:<id>:<canal>`, com `source_type`/`source_id` NULL. Assim o CHECK `communication_message_source_ck` da 0012 não muda, porque alterar CHECK quebraria o preparador 5.7.
- **Assíncrono e idempotência** (lições da 6A, 6B e 7A):
  - O controller grava `generated_document` `queued` e publica o job `document.generate` (payload `{type, document_id}`, sem dado pessoal) depois do commit.
  - O worker faz o claim condicional (`UPDATE ... WHERE status='queued' AND (claimed_at IS NULL OR claimed_at < agora-10min)` + `rowCount`), renderiza, grava no storage e, numa transação, registra `stored_object`, `markReady` condicional e os avisos.
  - Se a transação falha, o objeto gravado é apagado.
  - Um job repetido encontra o documento `ready` e devolve `skipped`.
  - São 3 tentativas. Na última, o documento fica `failed` com `last_error_code`. Fonte inexistente ou consentimento cirúrgico ausente falham na hora.
  - Um varredor (`bin/document-sweep.php` e tick do worker) republica documentos `queued` sem claim há mais de 10 minutos.
- **Versionamento**: cada pedido é uma linha nova com `version = última + 1` por `(tenant, kind, source_type, source_id)`, garantida pela UNIQUE `generated_document_version_uq` e por nova tentativa em 1062. O retry de um documento `failed` reaproveita a linha (`failed → queued`).
- **Worker**: um PDO por job e por tick (`PdoConnectionFactory::fromEnvironment`), `TenantContext::authenticated($tenantId, DOCUMENT_SYSTEM_USER_ID)` (padrão 1). `stored_object.created_by` recebe `requested_by_system_user_id` do documento. Logs só com ids, `kind`, `version` e códigos. O dompdf roda com `isRemoteEnabled=false`, `isPhpEnabled=false`, `tempDir` em `sys_get_temp_dir()` e `chroot` restrito.
- **Migration `0013`**: duas tabelas, `document_template` e `generated_document`, compatíveis com o preparador 5.7 (prefixo `17-`), com placeholder de SHA-256 e `.verify.sql`. É preparada pela T-01 e aplicada só pelo orquestrador, com backup, `gzip -t`, SHA-256 e aprovação SQL explícita do usuário (skill `sql-write-approval`), no **bloqueio entre a Onda 1 e a Onda 2**: migration em `centralvet` e `centralvet_test`, DML de programas só em `centralvet`.
- **RBAC**: 4 programas novos (`DocumentList`, `DocumentRequestForm`, `DocumentTemplateList`, `DocumentTemplateForm`) concedidos aos grupos 1, 2, `Clínico – Internação` e `Clínico – Cirurgia`, como a 7A decidiu. O grupo 3 não recebe nada. São 16 concessões. A autorização usa `AuthorizationRequest` com `requiresUnitScope: true` e `resourceUnitId` = unidade da fonte (receita: unidade do atendimento; cirurgia: `surgery.system_unit_id`) ou unidade ativa (carteira, atestado). Templates são do tenant.
- **Telas** novas em `app/control/clinic`, com `CvPage::header`, catches `AuthorizationDenied` → `MissingTenantContext` → `Exception` (`CvFormat::userError`), `CvFormat::e` em todo dado vindo do banco, `CvCombo` para recarga de combo, texto livre só por POST, rótulos à esquerda (`CLAUDE.md`, `.docs/design-system.md` § Alinhamento de rótulos) e alvos `.cv-touch-target` ≥ 44 px.
- **i18n com escritor único** (T-19): até a Onda 5, as telas usam `_t('<en>')` e as exceptions têm texto em inglês. Cada texto vai para o board como `- [T-xx] i18n: <en> → <pt>` ou `- [T-xx] i18n-domínio: <mensagem>`.
- Registros dos gates levam o prefixo `F7B teste`: tutor `F7B teste Tutor` com e-mail `f7b.teste@example.invalid`, paciente, vacinação, cirurgia com consentimento e template `F7B teste Atestado`. O SQL de limpeza (T-21) os cobre e lista os `storage_key` dos arquivos. Só o orquestrador executa, com aprovação. Os gates usam o driver de e-mail `log`.
- Nada em `src/lib/adianti` nem nos arquivos de `src/app/config/framework_hashes.php`.

## Escopo

### Incluso
- Migration `0013` (`document_template`, `generated_document`), `.verify.sql` e `provision.sh` → T-01.
- Domain: tipos de documento, documento gerado, template, renderizador e padrões de template, conteúdo, exceções e contratos (repositórios, consulta de fontes, renderer e fábrica de conteúdo) → T-02.
- Storage local fora do webroot + `DocumentStorageFactory` (local/s3) + variáveis em `.env.example`/`docker-compose.yml` + volume `app_documents` e pasta no `Dockerfile` → T-03.
- Programas RBAC das 4 telas (seed + DML, verify e rollback preparados) → T-04.
- Fakes → T-05. Repositórios PDO (versão, claim, transições condicionais) → T-06. Consulta das fontes (paciente, vacinas, receita, cirurgia, contato do tutor) → T-07. HTML + dompdf seguro → T-08.
- Pendência `document_failed` na Central de Pendências → T-09.
- `DocumentRequestService` (pedir, tentar de novo, listar, baixar) e `DocumentJobPublisher` → T-10. `DocumentContentFactory` → T-11. `DocumentGenerationService` + `DocumentReadyNotifier` (aviso `document_ready` com consentimento) → T-12. `DocumentTemplateService` (cadastro e merge) → T-13.
- Worker: handler `document.generate`, varredor, `bin/worker.php` e `bin/document-sweep.php` → T-14.
- Telas: pedir documento → T-15. Lista e download → T-16. Templates → T-17. Navegação (menu, `CvNav`, ações em `PatientForm`, `VaccinationCardView`, `SurgeryView` e `PrescriptionForm`) → T-18.
- i18n pt/en → T-19. Runbook de documentos e passo da 0013 e cron na hospedagem 5.7 → T-20.
- Validação final ponta a ponta e SQL de limpeza `F7B teste` → T-21.

### Excluído
- Assinatura digital ICP-Brasil, certificado A1/A3, carimbo de tempo.
- Workflows configuráveis genéricos (etapas, aprovações, gatilhos por evento).
- E-mail com anexo PDF ou link público/pré-assinado para o tutor baixar. O aviso só informa que o documento está disponível na clínica.
- Orçamento (não há tabela), recibo de recebimento/`PaymentForm`, laudo de exame gerado, relatório de alta/internação e template de layout visual (logo, cores) por tenant.
- Alterar o PDF síncrono existente (`PrescriptionForm::onGeneratePdf`, `SaleForm::onGenerateReceiptPdf`, `ProductList::onReport`), os anexos (`EncounterDocumentService`, `S3CompatibleStorage` e seus pontos de uso) e o CHECK `communication_message_source_ck` da 0012.
- Expurgo/retenção automática de PDFs, exclusão de documento pela tela e antivírus.
- Executar migration, DML, SQL de limpeza ou remoção de arquivos do volume (só o orquestrador, com aprovação).

## Contexto técnico
- Camadas envolvidas: database, backend, infra, frontend, shared (testes), docs, qa.
- Projeto/base analisada: `/var/www/html/centralvet` (repositório único; `git -C <DIR> rev-parse --show-toplevel` = `/var/www/html/centralvet`), branch `feat/fase-7a-comunicacao` @ `83029c3`, Adianti 8.6, PHP 8.4, MySQL 8.0 local / 5.7 na hospedagem, Redis (fila), dompdf 3.1.5, MinIO (profile `minio`).
- Integrações: `RedisQueue`/`QueueInterface`/`QueueWorkerLoop` e `src/bin/worker.php` (7A, com `--once`), módulo de comunicação da 7A (`OutboundMessage::compose`, `OutboundMessageRepositoryInterface::insertIfNew`, `MessageTemplateRepositoryInterface::findActiveFor`, `MessageTemplateDefaults::for`, `CommunicationPreference::permitsSending`, `MessageQueuePublisher::publish`, `SenderNamesQueryInterface::namesForUnit`), `StorageInterface`/`S3CompatibleStorage`/`ObjectKeyNamespace`, `StoredObjectRepository::record`, `RbacAuthorizationService` + `PdoAuditLogWriter`, `TenantContext`, Central de Pendências (`PendingItem`, `PendingItemQuery`, `PendingCenter`).

## Baseline
- php-lint: `php -l` (via LINT com `sh -c`) em todo `.php` de `app/Core`, `app/control/clinic`, `app/lib/widget`, `bin`, `tests/Unit`, `tests/Support` e `tests/Integration` (593 arquivos, todos `No syntax errors detected`), só as linhas diferentes disso e das linhas `Container` do docker, em raiz → baseline/php-lint.txt (0 linhas). Critério: nenhum erro novo em relação a `baseline/php-lint.txt`, ou seja, `No syntax errors detected` em cada PHP tocado.
- test-prepare-mysql57: `python3 scripts/test-prepare-mysql57.py` em raiz → baseline/test-prepare-mysql57.txt (5 linhas: `Ran 8 tests`, `OK`).

## Exploração read-only
- Caminhos relevantes:
  - **PDF hoje.**
    - `src/app/control/clinic/PrescriptionForm.php:1044` (`onGeneratePdf`, HTML em :1097, link `engine.php?...&static=1` em :155).
    - `SaleForm.php:620` e `ProductList.php:433` (dompdf inline).
    - `EncounterView.php:469-472` (`window.print()`).
    - `composer.json`: `dompdf/dompdf` ^3, `pablodalloglio/fpdf` e `adianti/pdfdesigner` sem uso.
  - **Storage.**
    - `src/app/Core/Storage/{StorageInterface,S3CompatibleStorage,ObjectKeyNamespace,StoredObjectMetadata}.php`, `Storage/S3/*` e `Storage/Exception/StorageException.php`.
    - `src/app/Core/Persistence/StoredObjectRepository.php` (`record(StoredObjectMetadata, string $originalName, ?int $systemUnitId, int $createdBy): array`).
    - `src/tests/Support/{FakeStorage,FakeStoredObjectRepository}.php`.
    - `EncounterView::onDownloadDocument` (:1909-1956), o modelo de download: `ob_end_clean`, `Content-Disposition` RFC 5987, `Cache-Control: private, no-store`, `Content-Security-Policy: sandbox`, 404 com texto fixo.
    - `src/download.php` (confinado a `files/`, sem tenant; não usar).
  - **Fila e worker.**
    - `src/bin/worker.php:76-102`: handler lazy e despacho por `payload.type`, com `if` para `MessageQueuePublisher::JOB_TYPE`.
    - `src/app/Core/Queue/QueueWorkerLoop.php`.
    - `src/app/Core/Communication/CommunicationJobHandler.php:45-97` (`forEnvironment`, `supports`, `handle`, PDO e `TenantContext` por job, `finalAttempt` = `attempts + 1 >= maxAttempts`).
    - `src/app/Core/Application/MessageQueuePublisher.php` (`JOB_TYPE`, `QUEUE = 'default'`, `publish(int $tenantId, int $messageId): string`).
    - `src/app/Core/Communication/CommunicationScheduler.php` (lista de tenants ativos e isolamento de falha por tenant).
  - **Comunicação.**
    - `Domain/MessagePurpose.php:25/68` (`DOCUMENT_READY`, base `consent`).
    - `Domain/MessageTemplateDefaults.php:20/29` (texto `document_ready` sem link).
    - `Domain/OutboundMessage.php:82-135` (`compose(...)`; `SOURCE_TYPES` fechado em appointment/vaccination/receivable; `buildDedupeKey(string, string, int, string)` não valida o tipo).
    - `Application/ReminderGenerationService.php:72-140`: modelo de ordem opt-out → sem consentimento → sem contato → `permitsSending` → `insertIfNew`.
    - `Domain/Contract/SenderNamesQueryInterface.php` (`namesForUnit(int $unitId): array{unit_name: ?string, clinic_name: ?string}`).
  - **Banco.**
    - `src/app/database/migrations/20261006_0012_phase7a_communication.sql`, que é o modelo: cabeçalho PREPARED ONLY e placeholder de 64 zeros nas linhas 219-221.
    - `scripts/prepare-mysql57.py:119` (glob `[0-9][0-9]-*.sql`; a 0013 vira `17-`).
    - `scripts/test-db/provision.sh` (lista nas linhas 42-57, 0012 na 57; comentário `0001..0012` na linha 10).
    - Seed `src/app/database/seeds/initial-application-programs.sql` (bloco 7A nas linhas 708-900).
    - Modelos `.claude/tasks/mar-20261006-0842-fase-7a-comunicacao/sql/T-04-programs*.sql` e `T-23-cleanup.sql`.
    - Tabelas `patient` (sem unidade), `tutor` (`full_name`, `phone` NOT NULL, `email` NULL), `vaccination` (unidade por `encounter`), `vaccine_catalog_item`, `prescription` e `prescription_item` (unidade por `encounter`), `surgery` (`system_unit_id`, `consent_signer_name`, `consent_text`, `consent_recorded_at`).
  - **Telas.**
    - `CvPage::header(string $title, ?string $subtitle = null, array $actions = [], bool $unitSwitch = true)` (`src/app/lib/widget/CvPage.php:22`; ação `['label','icon','class','action'|'href','target','title']`).
    - Usado em `PatientForm.php:248`, `VaccinationCardView.php:92`, `SurgeryView.php:469` e `PrescriptionForm.php:127`.
    - `CommunicationMessageList.php`/`CommunicationMessageView.php` (tabela `cv-table` à mão, `makeMessageService` :317, `resolveTenantContext` :339, `ask()` com TQuestion :186, `const TOUCH` :37).
    - Central: `src/app/Core/Domain/PendingItem.php` (`TYPES`, `DEEP_LINK_KEYS`), `PendingItemPriority.php`, `Persistence/PendingItemQuery.php` e `control/clinic/PendingCenter.php` (`TYPE_META`).
  - **Navegação e i18n.**
    - `src/menu.xml:90-101` (bloco CRM / Communication) e `src/app/lib/widget/CvNav.php:50` (grupo `communication`).
    - `src/app/config/translations.json` (objetos `{en, pt}` em ordem alfabética por `en`).
    - `src/app/Core/Presentation/UserMessage.php` (STATIC :17, PATTERNS :86) e `src/tests/Unit/UserMessageTest.php:122-123` (totais 62/86).
    - `ControllerRawExceptionMessageTest` e `{Communication,Surgery}NavigationIntegrationTest`.
  - **Infra.**
    - `docker-compose.yml`: `x-php-service`, com `environment` nas linhas 3-51 (S3_* nas linhas 46-51) e `volumes` na linha 52 (`app_files`). O serviço é `read_only` e tem `tmpfs` em `/tmp` e em `src/tmp`.
    - `minio` está no profile `minio`, sem bucket criado.
    - `docker/php/Dockerfile` (`COPY --chown=www-data` :64, `USER www-data` :84).
    - `.env.example:102-118` (storage/MinIO).
    - O nginx tem root em `src` e bloqueia `/files/`.
- Padrões identificados:
  - Services recebem repositórios por interface, `AuthorizationPolicyInterface`, `TenantContext` e `?Closure $clock = null` por último, e autorizam com `decide(new AuthorizationRequest(...))->assertAllowed()`. Services não abrem transação: quem abre é o controller (`TTransaction::open('permission')`). No worker, a transação é injetada como `Closure`.
  - Repositórios: `__construct(TenantContext $context, PDO $connection)` e `TenantQuery::forTenant` em todo SQL. Transições usam UPDATE condicional + `rowCount`. Tratam 1062 sem `INSERT IGNORE`.
  - Migration: CHECK nomeado (≤ 61 caracteres) sem `BETWEEN`/`LIKE`/`CASE`/funções; `timestamp(6)`; FKs `ON UPDATE RESTRICT ON DELETE RESTRICT`; `utf8mb4_0900_ai_ci`.
- Scripts úteis: LINT, SUITE e PYTEST57 (Premissas); `python3 scripts/prepare-mysql57.py <privdir> <outdir>`; `./scripts/backup.sh`; `docker compose exec -T worker php bin/document-sweep.php` e `docker compose exec -T worker php bin/worker.php --once --max-jobs=20` (a partir do gate da Onda 4, depois do rebuild).
- Riscos identificados:
  - **Container `read_only`.** A pasta local precisa existir na imagem com dono `www-data` e um volume nomeado próprio. Sem isso o `put` falha com permissão negada. Mitigação: T-03 cria a pasta no `Dockerfile`, e o gate da Onda 4 gera um PDF real.
  - **Fonte do dompdf no container `read_only`.** O cache de fontes fica em `vendor` (somente leitura). Mitigação: só fontes core/DejaVu já empacotadas, `tempDir` em `/tmp`, e o teste da T-08 renderiza no container da SUITE (também `ro`).
  - **HTML injection no PDF.** Nome de paciente e texto do atestado vão para o HTML. Mitigação: `htmlspecialchars` em todo valor (`DocumentHtmlBuilder`) e `isRemoteEnabled=false` (Review Focus).
  - **Corrida de versão** entre dois pedidos simultâneos: UNIQUE + nova tentativa em 1062 (T-01/T-06).
  - **Redelivery do job e varredor republicando um documento já em geração**: claim condicional, `markReady` condicional e dedupe do aviso (T-06/T-12/T-14).
  - **Objeto órfão** quando a transação final falha: `delete` do storage no catch (T-12).
  - **Dado pessoal**: nada de e-mail, telefone, nome ou texto do atestado em log, URL, payload de fila, nome de arquivo ou motivo de dead-letter. `DocumentGenerationFailed` carrega só código.
  - **Arquivos compartilhados entre módulos**, cada um com um escritor só:
    - `docker-compose.yml`, `.env.example` e `docker/php/Dockerfile` (T-03);
    - `provision.sh` (T-01);
    - seed de programas (T-04);
    - `PendingItem.php`, `PendingItemQuery.php`, `PendingCenter.php` e `PendingItemDomainTest.php` (T-09);
    - `src/bin/worker.php` (T-14);
    - `menu.xml`, `CvNav.php`, `PatientForm.php`, `VaccinationCardView.php`, `SurgeryView.php` e `PrescriptionForm.php` (T-18);
    - `translations.json`, `UserMessage.php` e `UserMessageTest.php` (T-19);
    - runbooks compartilhados (T-20).

## Mapa de arquivos

| Arquivo | Responsabilidade | Ação | Tasks que tocam |
|---|---|---|---|
| `src/app/database/migrations/20261006_0013_phase7b_documents.sql` | DDL de `document_template` e `generated_document` | criar | T-01 |
| `src/app/database/migrations/20261006_0013_phase7b_documents.verify.sql` | Verificação só com SELECT | criar | T-01 |
| `scripts/test-db/provision.sh` | Inclui a 0013 no banco de teste | modificar | T-01 |
| `src/app/Core/Domain/DocumentKind.php` | Tipos de documento, fonte e título por tipo | criar | T-02 |
| `src/app/Core/Domain/GeneratedDocument.php` | Documento gerado (status, versão, nome de arquivo) | criar | T-02 |
| `src/app/Core/Domain/DocumentTemplate.php` | Template de documento por tenant | criar | T-02 |
| `src/app/Core/Domain/DocumentTemplateRenderer.php` | Placeholders fechados e merge | criar | T-02 |
| `src/app/Core/Domain/DocumentTemplateDefaults.php` | Texto padrão pt-BR do atestado | criar | T-02 |
| `src/app/Core/Domain/DocumentContent.php` | Conteúdo neutro a renderizar | criar | T-02 |
| `src/app/Core/Domain/Exception/DocumentGenerationFailed.php` | Falha de geração só com código | criar | T-02 |
| `src/app/Core/Domain/Exception/DocumentNotAvailableException.php` | Documento inexistente/fora do escopo/não pronto | criar | T-02 |
| `src/app/Core/Domain/Exception/DocumentSourceNotFoundException.php` | Fonte do documento fora do tenant/unidade | criar | T-02 |
| `src/app/Core/Domain/Contract/GeneratedDocumentRepositoryInterface.php` | Contrato do documento gerado | criar | T-02 |
| `src/app/Core/Domain/Contract/DocumentTemplateRepositoryInterface.php` | Contrato de template | criar | T-02 |
| `src/app/Core/Domain/Contract/DocumentSourceQueryInterface.php` | Contrato da consulta de fontes | criar | T-02 |
| `src/app/Core/Domain/Contract/DocumentRendererInterface.php` | Contrato do renderizador PDF | criar | T-02 |
| `src/app/Core/Domain/Contract/DocumentContentFactoryInterface.php` | Contrato da fábrica de conteúdo | criar | T-02 |
| `src/tests/Unit/DocumentDomainTest.php` | Testes do Domain de documentos | criar | T-02 |
| `src/app/Core/Storage/LocalFilesystemStorage.php` | Driver local fora do webroot | criar | T-03 |
| `src/app/Core/Storage/DocumentStorageFactory.php` | Escolha do driver por `DOCUMENT_STORAGE_DRIVER` | criar | T-03 |
| `.env.example` | Variáveis `DOCUMENT_*` documentadas | modificar | T-03 |
| `docker-compose.yml` | Variáveis `DOCUMENT_*` e volume `app_documents` | modificar | T-03 |
| `docker/php/Dockerfile` | Pasta `/var/www/html/var/documents` com dono `www-data` | modificar | T-03 |
| `src/tests/Unit/LocalFilesystemStorageTest.php` | Testes do driver local e da fábrica | criar | T-03 |
| `src/app/database/seeds/initial-application-programs.sql` | 4 programas e concessões em instalação nova | modificar | T-04 |
| `.claude/tasks/mar-20261006-1347-fase-7b-documentos/sql/T-04-programs.sql` | DML dos 4 programas e 16 concessões | criar | T-04 |
| `.claude/tasks/mar-20261006-1347-fase-7b-documentos/sql/T-04-programs.verify.sql` | Verificação só com SELECT | criar | T-04 |
| `.claude/tasks/mar-20261006-1347-fase-7b-documentos/sql/T-04-programs.rollback.sql` | Reversão preparada por nome de controller | criar | T-04 |
| `src/tests/Support/FakeGeneratedDocumentRepository.php` | Dublê do documento (versão, claim, transições) | criar | T-05 |
| `src/tests/Support/FakeDocumentTemplateRepository.php` | Dublê de template | criar | T-05 |
| `src/tests/Support/FakeDocumentSourceQuery.php` | Dublê da consulta de fontes | criar | T-05 |
| `src/tests/Support/FakeDocumentRenderer.php` | Dublê do renderizador (sucesso ou falha) | criar | T-05 |
| `src/tests/Support/FakeDocumentContentFactory.php` | Dublê da fábrica de conteúdo | criar | T-05 |
| `src/tests/Support/FakeSenderNamesQuery.php` | Dublê de nomes de unidade/clínica | criar | T-05 |
| `src/tests/Unit/DocumentFakesTest.php` | Contrato dos dublês | criar | T-05 |
| `src/app/Core/Persistence/GeneratedDocumentRepository.php` | PDO do documento (versão 1062, claim, UPDATE condicional) | criar | T-06 |
| `src/app/Core/Persistence/DocumentTemplateRepository.php` | PDO de template | criar | T-06 |
| `src/tests/Integration/DocumentRepositoryIntegrationTest.php` | Integração no `centralvet_test` | criar | T-06 |
| `src/app/Core/Persistence/DocumentSourceQuery.php` | Consulta de paciente, vacinas, receita, cirurgia, contato | criar | T-07 |
| `src/tests/Integration/DocumentSourceQueryIntegrationTest.php` | Integração da consulta (tenant e unidade) | criar | T-07 |
| `src/app/Core/Document/DocumentHtmlBuilder.php` | HTML escapado a partir de `DocumentContent` | criar | T-08 |
| `src/app/Core/Document/DompdfDocumentRenderer.php` | PDF via dompdf com opções seguras | criar | T-08 |
| `src/tests/Unit/DocumentRendererTest.php` | Testes do HTML e do PDF | criar | T-08 |
| `src/app/Core/Domain/PendingItem.php` | Tipo `document_failed` e deep-link `DocumentList` | modificar | T-09 |
| `src/app/Core/Domain/PendingItemPriority.php` | Prioridade do tipo novo | modificar | T-09 |
| `src/app/Core/Persistence/PendingItemQuery.php` | Fonte `failedDocuments` por unidade | modificar | T-09 |
| `src/app/control/clinic/PendingCenter.php` | Card e rótulo do tipo novo | modificar | T-09 |
| `src/tests/Unit/PendingItemDomainTest.php` | Tipo novo e allowlist do deep-link | modificar | T-09 |
| `src/tests/Integration/DocumentPendingItemIntegrationTest.php` | Pendência de documento falho por unidade | criar | T-09 |
| `src/app/Core/Application/DocumentRequestService.php` | Pedir, tentar de novo, listar e baixar com RBAC | criar | T-10 |
| `src/app/Core/Application/DocumentJobPublisher.php` | Publicação do job `document.generate` | criar | T-10 |
| `src/tests/Unit/DocumentRequestServiceTest.php` | Testes do pedido e do download | criar | T-10 |
| `src/app/Core/Application/DocumentContentFactory.php` | Monta `DocumentContent` por tipo | criar | T-11 |
| `src/tests/Unit/DocumentContentFactoryTest.php` | Testes do conteúdo por tipo | criar | T-11 |
| `src/app/Core/Application/DocumentGenerationService.php` | Geração no worker (claim, render, storage, ready) | criar | T-12 |
| `src/app/Core/Application/DocumentGenerationResult.php` | Resultado da geração e e-mails a publicar | criar | T-12 |
| `src/app/Core/Application/DocumentReadyNotifier.php` | Aviso `document_ready` com consentimento | criar | T-12 |
| `src/tests/Unit/DocumentGenerationServiceTest.php` | Testes de geração, idempotência e aviso | criar | T-12 |
| `src/app/Core/Application/DocumentTemplateService.php` | Cadastro de template e merge para paciente | criar | T-13 |
| `src/tests/Unit/DocumentTemplateServiceTest.php` | Testes de template | criar | T-13 |
| `src/app/Core/Document/DocumentJobHandler.php` | Handler do job `document.generate` | criar | T-14 |
| `src/app/Core/Document/DocumentSweeper.php` | Republica documentos `queued` presos | criar | T-14 |
| `src/bin/worker.php` | Despacho de `document.generate` e tick do varredor | modificar | T-14 |
| `src/bin/document-sweep.php` | Comando de varredura única (cron) | criar | T-14 |
| `src/tests/Unit/DocumentWorkerTest.php` | Testes do handler e do varredor | criar | T-14 |
| `src/app/control/clinic/DocumentRequestForm.php` | Pedir documento (tipo, template, texto, aviso) | criar | T-15 |
| `src/tests/Integration/DocumentRequestFormIntegrationTest.php` | Campos, POST, escape, permissão | criar | T-15 |
| `src/app/control/clinic/DocumentList.php` | Lista, download e nova tentativa | criar | T-16 |
| `src/tests/Integration/DocumentListIntegrationTest.php` | Lista, 404 sem oráculo, cabeçalhos do download | criar | T-16 |
| `src/app/control/clinic/DocumentTemplateList.php` | Lista de templates | criar | T-17 |
| `src/app/control/clinic/DocumentTemplateForm.php` | Cadastro/edição de template | criar | T-17 |
| `src/tests/Integration/DocumentTemplateScreensIntegrationTest.php` | Campos e ações das telas de template | criar | T-17 |
| `src/menu.xml` | Menu Documentos | modificar | T-18 |
| `src/app/lib/widget/CvNav.php` | Grupo de abas `documents` | modificar | T-18 |
| `src/app/control/clinic/PatientForm.php` | Ações Documentos e Atestado no cabeçalho | modificar | T-18 |
| `src/app/control/clinic/VaccinationCardView.php` | Ação Gerar PDF no cabeçalho | modificar | T-18 |
| `src/app/control/clinic/SurgeryView.php` | Ação Termo em PDF no cabeçalho | modificar | T-18 |
| `src/app/control/clinic/PrescriptionForm.php` | Link Arquivar PDF por receita | modificar | T-18 |
| `src/tests/Integration/DocumentNavigationIntegrationTest.php` | Navegação registrada | criar | T-18 |
| `src/app/config/translations.json` | Chaves pt/en da 7B | modificar | T-19 |
| `src/app/Core/Presentation/UserMessage.php` | Mensagens de domínio da 7B | modificar | T-19 |
| `src/tests/Unit/UserMessageTest.php` | Casos e totais novos | modificar | T-19 |
| `docs/runbooks/documentos.md` | Fluxos, regras, env, storage, schema, programas, operação | criar | T-20 |
| `docs/runbooks/README.md` | Índice dos runbooks | modificar | T-20 |
| `docs/runbooks/shared-hosting-mysql57.md` | Passo da 0013 (`17-`), pasta fora do `public_html`, cron do varredor | modificar | T-20 |
| `.claude/tasks/mar-20261006-1347-fase-7b-documentos/sql/T-21-cleanup.sql` | Limpeza dos registros `F7B teste` (preparada) | criar | T-21 |

Nenhum arquivo é tocado por mais de uma task. Os compartilhados entre módulos têm uma task dona cada (lista em Riscos).

## Decisões de arquitetura

| Decisão | Alternativas consideradas | Motivo da escolha |
|---|---|---|
| Tabela `generated_document` própria (um pedido = uma linha com `version`), apontando para `stored_object` (FK `stored_object_id`) e guardando o `storage_key` lógico | Só `stored_object` com prefixo na chave (como os anexos); tabela de versões separada | Status assíncrono (`queued`/`ready`/`failed`), claim, código de erro, aviso e versão precisam de colunas próprias. `stored_object` continua como índice único de objetos, sem coluna nova |
| `LocalFilesystemStorage` implementando a `StorageInterface` existente + `DocumentStorageFactory` (`DOCUMENT_STORAGE_DRIVER` = `local` padrão, ou `s3`) | Trocar `STORAGE_DRIVER` global; Flysystem/aws-sdk | Hospedagem compartilhada sem S3 funciona por padrão, e os anexos atuais (S3) não mudam. Nenhuma dependência nova de composer. O driver recusa raiz dentro do webroot |
| Download só pelo controller (`DocumentList::onDownload&id=`), com RBAC + tenant + unidade ativa; documento fora do escopo = 404 igual ao inexistente | URL pré-assinada do S3; token assinado de curta duração | Não há usuário tutor no sistema e o driver local não tem URL pré-assinada. Uma rota só, auditada e sem oráculo. `presignedUrl` do driver local lança `StorageException` |
| Geração no worker pela fila existente (`document.generate`, payload `{type, document_id}`, `MAX_ATTEMPTS = 3`), com claim condicional e transação injetada (`?Closure $transaction`) para `stored_object` + `markReady` + avisos | Gerar na requisição (como a receita hoje); fila dedicada | O PRD pede PDF assíncrono (risco "PDF/jobs travarem PHP-FPM"). Um job repetido vira `skipped`. A transação evita `ready` sem aviso e aviso sem `ready` |
| Varredor `DocumentSweeper` (comando `bin/document-sweep.php` + tick do worker por `DOCUMENT_SWEEP_INTERVAL_SECONDS`, padrão 600) republica `queued` sem claim há mais de 10 min | Publicar dentro da transação; depender só do Redis | O Redis não participa da transação MySQL: a publicação é pós-commit e pode falhar. Na hospedagem, o cron chama o comando |
| Aviso `document_ready` automático só com `notify_tutor` marcado e `opted_in` explícito no canal, sem link nem anexo, `source_type` NULL e dedupe `document_ready:document:<id>:<canal>` | Ampliar `communication_message_source_ck` na 0013; enviar o PDF anexo; link público | A 7A fixou `document_ready` = consent. Mudar CHECK exige DROP/ADD, que o preparador 5.7 (CHECK → triggers) não suporta. Anexo e link público estão fora do MVP |
| Templates por tenant só para `medical_certificate`, com placeholders fechados (`DocumentTemplateRenderer::PLACEHOLDERS`) e texto padrão em `DocumentTemplateDefaults`. O texto final (editável) é congelado em `generated_document.body_text` | Template HTML livre; template para todos os tipos | O termo cirúrgico já tem o texto registrado na 6B (snapshot). Carteira e receita têm layout fixo de dados estruturados. Texto puro escapado evita HTML injection |
| Layout fixo em `DocumentHtmlBuilder` (título, clínica/unidade, bloco do paciente, parágrafos, tabela, assinatura, data) a partir de `DocumentContent`; dompdf com `isRemoteEnabled=false`, `isPhpEnabled=false` | Um HTML por tipo no controller (padrão atual); Puppeteer | Uma fonte de escape para os 4 tipos. O dompdf já está instalado e é o "Adianti/PHP simples" do PRD |
| Pendência `document_failed` lida por consulta (`PendingItemQuery`), com deep-link `DocumentList&patient_id=` | Tabela de pendências | Mesmo princípio da 7A: a pendência some quando a nova tentativa deixa o documento `ready` |

Rotas fixadas (todas `index.php?class=...`, só ids e códigos fixos):
- `DocumentList` (unidade ativa) e `DocumentList&patient_id=<id>`. Download: `DocumentList&method=onDownload&id=<document_id>&static=1`. Nova tentativa: `DocumentList&method=onRetry&id=<document_id>`, por TQuestion.
- `DocumentRequestForm&kind=<vaccination_card|prescription|medical_certificate|surgery_consent>&source_id=<id>`, onde `source_id` é o paciente para `vaccination_card`/`medical_certificate`, a receita para `prescription` e a cirurgia para `surgery_consent`.
- `DocumentTemplateList`; `DocumentTemplateForm` (`&id=<template_id>` para editar).
- Deep-link da Central: `DocumentList&patient_id=<id>`.

## Diagrama de dependências

```text
Onda 1: T-01  T-02  T-03  T-04
        [bloqueio: 0013 em centralvet + centralvet_test; sql/T-04-programs.sql em centralvet]
Onda 2: T-05 (T-02)  T-06 (T-01,T-02)  T-07 (T-02)  T-08 (T-02)  T-09 (T-01)
Onda 3: T-10 (T-02,T-05)  T-11 (T-02,T-05)  T-12 (T-02,T-05)  T-13 (T-02,T-05)
Onda 4: T-14 (T-03,T-06,T-07,T-08,T-10,T-11,T-12)  T-15 (T-06,T-07,T-10,T-13)
        T-16 (T-03,T-06,T-10)  T-17 (T-06,T-13)  T-18 (T-04)
Onda 5: T-19 (T-09,T-14..T-18)   T-20 (T-01,T-03,T-04,T-14)
Onda 6: T-21 (T-19,T-20)
```

## Estratégia de execução
- Branch de trabalho: `feat/fase-7b-documentos`
- Branch base: `feat/fase-7a-comunicacao`
- Ponto de partida: `feat/fase-7a-comunicacao` @ `83029c3`. O orquestrador cria a branch de trabalho com `repos.py --preparar`. O nome `feat/` foi dado pelo orquestrador em vez de `task/fase-7b-documentos`.
- Commits da onda: cada implementador commita os próprios caminhos (`git commit -- <caminhos>`); com `worktree por agente` eles chegam pelos merges de `wave<N>/T-XX`. O fechador usa a skill `new-commit --auto` só se sobrou algo sem commit e sempre registra o estado das tasks num `chore(tasks): registra commits da onda N`.
- Isolamento em ondas com edições paralelas: caminho exclusivo. Nenhum arquivo é dividido entre tasks; os compartilhados entre módulos têm um escritor só (Mapa de arquivos).
- **Bloqueio entre a Onda 1 e a Onda 2** (orquestrador, com aprovação SQL):
  1. Backup + `gzip -t`.
  2. SHA-256 da 0013 numa cópia.
  3. Aplicação em `centralvet` e `centralvet_test` com `MIGRATION_DB_USER`, e `.verify.sql` nos dois.
  4. `sql/T-04-programs.sql` (4 programas + 16 concessões) + `.verify.sql` em `centralvet` com `--default-character-set=utf8mb4`. Últimas contagens conhecidas, do fim da 7A: `system_program` 133 e `system_group_program` 173 (reconferir por SELECT).
  5. Contagens antes/depois de `patient`, `vaccination`, `prescription`, `surgery`, `stored_object`, `communication_message`, `system_program` e `system_group_program` vão para `notes.md § Bloqueios`.

  A Onda 2 não abre sem isso: T-06 e T-09 rodam integração no `centralvet_test`.
- Gates econômicos (limite de turnos do validador):
  - Ondas 1–3: LINT dos arquivos da onda + uma SUITE inteira (+ PYTEST57 e preparador 5.7 sobre a 0013 na Onda 1). Sem navegador.
  - Onda 4:
    - Rebuild (`build app worker` + `up -d app worker`, que cria o volume) e login admin pelo orquestrador.
    - SUITE.
    - `docker compose exec -T app sh -c 'test -w /var/www/html/var/documents && echo writable'`.
    - Pedido real de uma carteira de vacinação `F7B teste` pela tela e `docker compose exec -T worker php bin/worker.php --once --max-jobs=20`. O documento fica `ready`, e o download devolve `%PDF-`.
    - `docker compose exec -T worker php bin/document-sweep.php` (JSON, exit 0).
    - `docker compose logs --since 10m worker` sem `example.invalid` nem nome do paciente de teste.
    - Smoke Playwright **só desktop (1366×768)** abrindo as 4 telas uma vez: render, console sem mensagem de nível error, rede sem resposta ≥ 400.
  - Onda 5: SUITE + fetch autenticado em pt das 4 telas (nenhum `Message not found`).
  - Onda 6 (T-21): E2E em **dois disparos de validador**. Roteiro A: fluxo completo dos 4 tipos em desktop e tablet 820×1180. Roteiro B: os 5 itens de Review Focus + permissão negada.
- i18n: até a Onda 5, as telas usam `_t('<en>')` e anotam `- [T-xx] i18n: <en> → <pt>` no board. "Message not found" no gate da Onda 4 é aceito até a T-19.

## Ondas de execução

### Onda 1
- T-01
- T-02
- T-03
- T-04

### Onda 2
- T-05
- T-06
- T-07
- T-08
- T-09

### Onda 3
- T-10
- T-11
- T-12
- T-13

### Onda 4
- T-14
- T-15
- T-16
- T-17
- T-18

### Onda 5
- T-19
- T-20

### Onda 6
- T-21

## Agentes

| Agente | subagent_type | model | Tasks |
|---|---|---|---|
| Sun Tzu — orquestrador | — | — | todas |
| Darwin | general-purpose | inherit | T-01 |
| Platão | general-purpose | inherit | T-02, T-05 |
| Tesla | general-purpose | inherit | T-03 |
| Jaspion | general-purpose | inherit | T-04, T-18 |
| Athena | general-purpose | inherit | T-06, T-10 |
| Sherlock | general-purpose | inherit | T-07, T-11 |
| Arquimedes | general-purpose | inherit | T-08 |
| Batman | general-purpose | inherit | T-09 |
| Naruto | general-purpose | inherit | T-12 |
| Saitama | general-purpose | inherit | T-13, T-17 |
| Aang | general-purpose | inherit | T-14 |
| Kratos | general-purpose | inherit | T-15 |
| Yoda | general-purpose | inherit | T-16 |
| Levi | general-purpose | inherit | T-19 |
| Gandalf | geduc:documentador | sonnet | T-20 |
| Spock | general-purpose | inherit | T-21 |

## Review Focus
- Download por `DocumentList&method=onDownload&id=<id>&static=1` de documento de outra unidade, de outro tenant, ainda `queued` ou com id inexistente → HTTP 404 com o mesmo texto `Document not found` nos quatro casos, sem bytes de PDF e sem `Content-Disposition` → T-16
- Job `document.generate` entregue duas vezes (redelivery do Redis ou varredor + fila) para o mesmo documento → uma linha em `stored_object`, um arquivo no storage, `version` inalterada, a segunda execução devolve `skipped`, e o aviso existe uma vez por canal (`dedupe_key` `document_ready:document:<id>:email`) → T-12
- Pedido com "Avisar o tutor" para tutor sem linha de preferência ou com `opted_out` → documento `ready`, `notified_at` preenchido e nenhuma `communication_message` `document_ready`; com `opted_in` só no e-mail → exatamente uma mensagem `queued` de canal `email`, base `consent`, publicada na fila, sem link no corpo → T-12
- Nome do paciente ou texto do atestado com `<script>alert(1)</script>` e `<img src="http://127.0.0.1:9/x.png">` → o PDF mostra o texto literal e o dompdf não faz requisição remota (`isRemoteEnabled` falso); a lista e o formulário mostram o texto escapado → T-08
- Telas `DocumentRequestForm`, `DocumentList` e `DocumentTemplateForm` no tablet 820×1180 → rótulos e cabeçalhos de tabela com `text-align` `left`/`start` (CLAUDE.md, `.docs/design-system.md` § Alinhamento de rótulos) e botões Gerar PDF, Baixar e Tentar novamente com altura ≥ 44 px (`cv-touch-target`) → T-21

## Critérios gerais de aceite
- SUITE com `Failed: 0` e `Total` maior ou igual ao da BASE da onda somado aos testes novos.
- Nenhum erro novo em relação a `baseline/php-lint.txt`: cada PHP tocado imprime `No syntax errors detected`.
- PYTEST57 com `Ran 8 tests` (ou mais) e nenhuma falha.
- Toda query nova filtra `tenant_id` (`TenantQuery::forTenant`), e toda leitura/mutação feita por tela autoriza com `resourceUnitId` da fonte ou do documento persistido.
- Nenhum e-mail, telefone, nome de paciente/tutor ou texto de atestado em log, URL, payload de fila, nome de arquivo ou motivo de dead-letter (grep nos logs do worker do gate = 0).
- Arquivos do driver local ficam fora de `src/` e só são servidos por `DocumentList::onDownload` (`curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:8081/var/documents/` ≠ 200).
- Toda tela nova mostra estado vazio, erro traduzido via `CvFormat::userError` e permissão negada (captura `AuthorizationDenied` e `MissingTenantContext`). Rótulos à esquerda, botões ≥ 44 px, texto livre só por POST.
- Contagens de `patient`, `vaccination`, `prescription`, `surgery`, `stored_object` e `communication_message` antes e depois do bloqueio e dos gates: as linhas existentes continuam lá (só crescem até a limpeza).
