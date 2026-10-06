# Tasks

| ID | Camada | Descrição | Dependências | Paralelizável | Complexidade | Agente | Status |
|---|---|---|---|---|---|---|---|
| T-01 | database | Migration 0012 (4 tabelas + 2 índices), verify e provision do banco de teste | — | sim | alta | Darwin | [x] |
| T-02 | backend | Domain de comunicação (canal, finalidade, preferência, template, renderizador, padrões, mensagem) + contratos | — | sim | média | Platão | [x] |
| T-03 | backend | Domain da Central de Pendências e do candidato a lembrete + contratos das consultas | — | sim | média | Arquimedes | [x] |
| T-04 | infra | Programas RBAC das 7 telas para os grupos 1, 2, 4 e 5 (seed + DML, verify e rollback preparados) | — | sim | média | Jaspion | [x] |
| T-05 | backend | Provedores desacoplados (log, SMTP por env, link wa.me) + variáveis em .env.example e docker-compose | — | sim | média | Tesla | [x] |
| T-06 | shared | Fakes dos repositórios, consultas, fila e provedor | T-02, T-03, T-05 | sim | média | Platão | [x] |
| T-07 | backend | Repositórios PDO de comunicação + PdoConnectionFactory | T-01, T-02 | sim | alta | Athena | [x] |
| T-08 | backend | Consultas PDO de pendências e de candidatos a lembrete | T-01, T-03 | sim | alta | Sherlock | [x] |
| T-09 | backend | CommunicationPreferenceService e MessageTemplateService | T-02, T-06 | sim | média | Jaspion | [x] |
| T-10 | backend | MessageService (ciclo manual com base legal) e MessageQueuePublisher | T-02, T-05, T-06 | sim | alta | Athena | [x] |
| T-11 | backend | ReminderGenerationService (lembretes idempotentes com base legal e opt-out) | T-02, T-03, T-06 | sim | alta | Sherlock | [x] |
| T-12 | backend | MessageDeliveryService (entrega no worker, reconferindo a base legal) | T-02, T-05, T-06 | sim | alta | Tesla | [x] |
| T-13 | backend | PendingCenterService | T-03, T-06 | sim | simples | Arquimedes | [x] |
| T-14 | backend | Ligação de retorno (AppointmentFollowupService + EncounterView::onScheduleFollowUp) | T-02, T-06, T-07 | sim | média | Aang | [x] |
| T-15 | infra | Worker e agendador (handler do job, CommunicationScheduler, worker.php, comando) | T-05, T-07, T-08, T-10, T-11, T-12 | sim | alta | Aang | [x] |
| T-16 | frontend | Telas de templates (lista e formulário) | T-07, T-09 | sim | média | Saitama | [x] |
| T-17 | frontend | Histórico e ficha da mensagem | T-05, T-07, T-10 | sim | média | Kratos | [x] |
| T-18 | frontend | Compor mensagem e preferências do tutor | T-07, T-09, T-10 | sim | média | Naruto | [x] |
| T-19 | frontend | Central de Pendências (tela) | T-08, T-13 | sim | média | Batman | [x] |
| T-20 | frontend | Navegação (menu, CvNav, ações no TutorForm) | T-04 | sim | simples | Jaspion | [x] |
| T-21 | frontend | i18n pt/en das telas e mensagens de domínio | T-15, T-16, T-17, T-18, T-19, T-20 | sim | média | Levi | [x] |
| T-22 | docs | Runbook de comunicação, índice e passo da 0012 na hospedagem 5.7 | T-01, T-04, T-05, T-15 | sim | simples | Gandalf | [x] |
| T-23 | qa | Validação final ponta a ponta e SQL de limpeza `F7A teste` | T-21, T-22 | não | média | Spock | [x] |
| T-24 | backend | Onda 7 (correção da revisão final): XSS do template no compose + JOIN system_unit com tenant em ReminderSourceQuery | T-23 | sim | média | — | [x] |
| T-25 | backend | Onda 7 (correção da revisão final): opt-out barra WhatsApp manual na fila (reconferência em whatsAppLink/markManualSent, cancelamento em lote ao registrar opt-out) | T-23 | sim | média | — | [x] |

## Detalhamento

### T-01 — Migration 0012 (4 tabelas + 2 índices), verify e provision do banco de teste

**Camada:** database
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** alta
**Agente:** Darwin

**Arquivos prováveis**
- `src/app/database/migrations/20261006_0012_phase7a_communication.sql`
- `src/app/database/migrations/20261006_0012_phase7a_communication.verify.sql`
- `scripts/test-db/provision.sh`

**Notas de implementação**

Modelo: `src/app/database/migrations/20261005_0011_phase6b_surgery.sql` e seu `.verify.sql`. Cabeçalho `Migration: 20261006_0012_phase7a_communication`, `Status: PREPARED ONLY. Do not execute without a fresh backup and explicit SQL approval.`, `Target: MySQL 8.0.x, database centralvet, after 20261005_0011_phase6b_surgery`, seções Effects (contagem de tabelas, CHECKs, UNIQUEs, FKs, índices novos em `vaccination` e `receivable`), Risk e Rollback (`docs/runbooks/migration-rollback.md`). Última instrução: `INSERT INTO schema_migrations (version, checksum)` com o placeholder de 64 zeros, como nas linhas 260-262 da 0011. Tabelas com PK `id bigint unsigned AUTO_INCREMENT`, `tenant_id bigint unsigned`, `system_unit_id int`, `*_system_user_id int`, `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci`. Todo CHECK é `CONSTRAINT <nome> CHECK (...)` nomeado, com no máximo 61 caracteres, só com colunas, literais, `IN`, `IS`, `NULL`, `NOT`, `AND`, `OR` e comparações (sem `BETWEEN`, `LIKE`, `CASE` nem funções). Todo `timestamp(6) NOT NULL` leva `DEFAULT CURRENT_TIMESTAMP(6)`, e todo timestamp opcional é `timestamp(6) NULL DEFAULT NULL`. FKs `ON UPDATE RESTRICT ON DELETE RESTRICT`. Os índices em tabelas existentes vão em `ALTER TABLE ... ADD KEY` separados. `provision.sh`: acrescentar a 0012 depois da linha da 0011 e trocar `0001..0011` por `0001..0012` no comentário da linha 10 (ou equivalente). A tabela de mensagem se chama `communication_message` para não confundir com `system_message` do Adianti.

**Interface**
- Produz: `communication_preference(id, tenant_id, tutor_id bigint unsigned, channel varchar(20), status varchar(20), consent_source varchar(20), changed_by_system_user_id int, changed_at timestamp(6), created_at, updated_at)` com `communication_preference_tutor_channel_uq (tenant_id, tutor_id, channel)`, `communication_preference_channel_ck` (`channel IN ('email', 'whatsapp')`), `communication_preference_status_ck` (`status IN ('opted_in', 'opted_out')`), `communication_preference_source_ck` (`consent_source IN ('in_person', 'phone', 'written', 'online')`), FKs para `tenant`, `tutor` e `system_users`
- Produz: `message_template(id, tenant_id, purpose varchar(30), channel varchar(20), name varchar(120), subject varchar(190) NULL, body_text text, status varchar(20) DEFAULT 'active', created_by_system_user_id int, updated_by_system_user_id int NULL, created_at, updated_at)` com `message_template_tenant_name_uq (tenant_id, name)`, índice `message_template_purpose_idx (tenant_id, purpose, channel, status)`, `message_template_purpose_ck` (`purpose IN ('appointment_confirmation', 'vaccine_due', 'return_reminder', 'receivable_open', 'document_ready', 'custom')`), `message_template_channel_ck` (`channel IN ('email', 'whatsapp')`), `message_template_status_ck` (`status IN ('active', 'inactive')`), `message_template_subject_ck` (`channel <> 'email' OR subject IS NOT NULL`), FKs para `tenant` e `system_users` (2)
- Produz: `communication_message(id, tenant_id, system_unit_id int, tutor_id bigint unsigned, patient_id bigint unsigned NULL, template_id bigint unsigned NULL, purpose varchar(30), channel varchar(20), origin varchar(20), source_type varchar(30) NULL, source_id bigint unsigned NULL, dedupe_key varchar(120) NULL, legal_basis varchar(30), recipient varchar(190), subject varchar(190) NULL, body_text text, status varchar(20) DEFAULT 'queued', attempt_count int unsigned DEFAULT 0, last_error_code varchar(60) NULL, provider varchar(30) NULL, provider_message_id varchar(190) NULL, claimed_at timestamp(6) NULL, sent_at timestamp(6) NULL, failed_at timestamp(6) NULL, cancelled_at timestamp(6) NULL, manual_sent_by_system_user_id int NULL, cancelled_by_system_user_id int NULL, created_by_system_user_id int NULL, created_at, updated_at)` com `communication_message_tenant_dedupe_uq (tenant_id, dedupe_key)`, índices `communication_message_unit_status_idx (tenant_id, system_unit_id, status, created_at)` e `communication_message_tutor_idx (tenant_id, tutor_id, created_at)`, CHECKs `communication_message_status_ck` (`status IN ('queued', 'sent', 'failed', 'manual', 'cancelled')`), `communication_message_channel_ck` (`channel IN ('email', 'whatsapp')`), `communication_message_origin_ck` (`origin IN ('automation', 'manual')`), `communication_message_purpose_ck` (mesma lista de `message_template_purpose_ck`), `communication_message_source_ck` (`source_type IS NULL OR source_type IN ('appointment', 'vaccination', 'receivable')`), `communication_message_legal_basis_ck` (`legal_basis IN ('legitimate_interest', 'consent')`), `communication_message_sent_ck` (`(status IN ('sent', 'manual') AND sent_at IS NOT NULL) OR (status IN ('queued', 'failed', 'cancelled') AND sent_at IS NULL)`), `communication_message_manual_ck` (`(status = 'manual' AND channel = 'whatsapp' AND manual_sent_by_system_user_id IS NOT NULL) OR (status <> 'manual' AND manual_sent_by_system_user_id IS NULL)`), `communication_message_failed_ck` (`(status = 'failed' AND failed_at IS NOT NULL AND last_error_code IS NOT NULL) OR (status <> 'failed' AND failed_at IS NULL)`), `communication_message_cancelled_ck` (`(status = 'cancelled' AND cancelled_at IS NOT NULL) OR (status <> 'cancelled' AND cancelled_at IS NULL)`), `communication_message_subject_ck` (`channel <> 'email' OR subject IS NOT NULL`), FKs para `tenant`, `system_unit`, `tutor`, `patient`, `message_template` e `system_users` (3)
- Produz: `appointment_followup(id, tenant_id, appointment_id bigint unsigned, encounter_id bigint unsigned, created_by_system_user_id int, created_at)` com `appointment_followup_appointment_uq (appointment_id)`, índice `appointment_followup_encounter_idx (tenant_id, encounter_id)`, FKs para `tenant`, `appointment`, `encounter` e `system_users`
- Produz: `ALTER TABLE vaccination ADD KEY vaccination_tenant_next_dose_idx (tenant_id, next_dose_at)` e `ALTER TABLE receivable ADD KEY receivable_tenant_status_idx (tenant_id, status)`
- Consome: nada

**Teste RED**
- sem teste: migration SQL preparada e não executada (PREPARED ONLY); a estrutura é conferida por grep, pelo preparador 5.7 e, depois do bloqueio, pelo `.verify.sql` aplicado pelo orquestrador

**Critério de aceite**
- `grep -c "CREATE TABLE"` na 0012 imprime `4`; o arquivo contém `Status: PREPARED ONLY` e 64 zeros no `INSERT INTO schema_migrations`.
- A 0012 tem 18 CHECKs nomeados, e o maior nome tem no máximo 61 caracteres.
- O preparador 5.7 sobre a cópia `16-20261006_0012_phase7a_communication.sql` imprime `Prepared` sem `Unsupported CHECK clause`.
- O `.verify.sql` só tem `SELECT` e lista as 4 tabelas, os 18 CHECKs, as 3 UNIQUEs, os 2 índices novos e as contagens de `vaccination`, `receivable` e `appointment`; toda consulta a `check_constraints` cita `table_name IN (...)` ou `table_name = '...'`.
- `scripts/test-db/provision.sh` lista `$migrations_dir/20261006_0012_phase7a_communication.sql` depois da 0011.

**Validação**
- `/usr/bin/grep -c "CREATE TABLE" src/app/database/migrations/20261006_0012_phase7a_communication.sql` (evidência: `4`)
- `/usr/bin/grep -oE "CONSTRAINT [a-z_]+_ck" src/app/database/migrations/20261006_0012_phase7a_communication.sql | sort -u | wc -l` (evidência: `18`)
- `/usr/bin/grep -oE "CONSTRAINT [a-z_]+_ck" src/app/database/migrations/20261006_0012_phase7a_communication.sql | awk '{print length($2)}' | sort -n | tail -1` (evidência: número ≤ 61)
- `d=$(mktemp -d) && cp src/app/database/migrations/20261006_0012_phase7a_communication.sql $d/16-20261006_0012_phase7a_communication.sql && python3 scripts/prepare-mysql57.py $d $d/out` (evidência: `Prepared` sem `Unsupported CHECK clause`)
- `/usr/bin/grep -ciE "^[[:space:]]*(insert|update|delete|alter|create|drop)" src/app/database/migrations/20261006_0012_phase7a_communication.verify.sql` (evidência: `0`)
- `/usr/bin/grep -n "0012" scripts/test-db/provision.sh` (evidência: linha da 0012 depois da 0011)
- `python3 scripts/test-prepare-mysql57.py` (evidência: `Ran 8 tests` e `OK`, iguais a `baseline/test-prepare-mysql57.txt`)
- Depois do bloqueio (orquestrador): o `.verify.sql` em `centralvet` e `centralvet_test` mostra as 4 tabelas e os 18 CHECKs; `SELECT COUNT(*)` de `vaccination`, `receivable` e `appointment` igual ao de antes (registros existentes preservados).

### T-02 — Domain de comunicação (canal, finalidade, preferência, template, renderizador, padrões, mensagem) + contratos

**Camada:** backend
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Platão

**Arquivos prováveis**
- `src/app/Core/Domain/CommunicationChannel.php`
- `src/app/Core/Domain/MessagePurpose.php`
- `src/app/Core/Domain/CommunicationPreference.php`
- `src/app/Core/Domain/MessageTemplate.php`
- `src/app/Core/Domain/MessageTemplateRenderer.php`
- `src/app/Core/Domain/MessageTemplateDefaults.php`
- `src/app/Core/Domain/OutboundMessage.php`
- `src/app/Core/Domain/Exception/CommunicationConsentRequiredException.php`
- `src/app/Core/Domain/Contract/CommunicationPreferenceRepositoryInterface.php`
- `src/app/Core/Domain/Contract/MessageTemplateRepositoryInterface.php`
- `src/app/Core/Domain/Contract/OutboundMessageRepositoryInterface.php`
- `src/app/Core/Domain/Contract/AppointmentFollowupRepositoryInterface.php`
- `src/tests/Unit/CommunicationDomainTest.php`

**Notas de implementação**

Namespace `CentralVet\Domain` (contratos em `CentralVet\Domain\Contract`, exceção em `CentralVet\Domain\Exception`), no padrão de `Surgery.php`/`SurgeryRoom.php` (factory estática + `reconstitute(array $row)` + `assignId`). As listas fechadas espelham os CHECKs da T-01 (mesmos literais). Base legal (decisão do usuário, `notes.md`): `appointment_confirmation` e `return_reminder` → `legitimate_interest` (envia salvo opt-out no canal); `vaccine_due`, `receivable_open`, `document_ready` e `custom` → `consent` (exige opt-in explícito no canal). O opt-out sempre bloqueia, qualquer que seja a base. Os textos padrão de `MessageTemplateDefaults` são pt-BR, curtos e sem dado clínico além do nome do paciente, da vacina e da data. Exemplo de corpo de confirmação: `Olá {{tutor_name}}, lembramos a consulta de {{patient_name}} em {{appointment_date}} às {{appointment_time}} na {{unit_name}}. Responda para confirmar ou remarcar.`. Toda finalidade tem padrão nos dois canais, e `custom` e `document_ready` têm corpo genérico. O renderizador troca só os tokens `{{placeholder}}` da lista; variável ausente vira string vazia; o texto é puro (sem HTML). As mensagens de exceção ficam em inglês e cada uma vai para o board como `- [T-02] i18n-domínio: <mensagem>` (T-21 traduz). Os contratos de repositório de entidade estendem `CentralVet\Persistence\TenantRepositoryInterface` no padrão dos contratos da 6B.

**Interface**
- Produz: `CommunicationChannel::EMAIL = 'email'`, `CommunicationChannel::WHATSAPP = 'whatsapp'`, `CommunicationChannel::all(): array`
- Produz: `MessagePurpose::APPOINTMENT_CONFIRMATION = 'appointment_confirmation'`, `MessagePurpose::VACCINE_DUE = 'vaccine_due'`, `MessagePurpose::RETURN_REMINDER = 'return_reminder'`, `MessagePurpose::RECEIVABLE_OPEN = 'receivable_open'`, `MessagePurpose::DOCUMENT_READY = 'document_ready'`, `MessagePurpose::CUSTOM = 'custom'`, `MessagePurpose::all(): array`, `MessagePurpose::LEGAL_BASIS_LEGITIMATE_INTEREST = 'legitimate_interest'`, `MessagePurpose::LEGAL_BASIS_CONSENT = 'consent'`, `MessagePurpose::legalBasisFor(string $purpose): string`
- Produz: `CommunicationPreference::STATUS_OPTED_IN = 'opted_in'`, `CommunicationPreference::STATUS_OPTED_OUT = 'opted_out'`, `CommunicationPreference::SOURCES = ['in_person', 'phone', 'written', 'online']`, `CommunicationPreference::record(int $tenantId, int $tutorId, string $channel, string $status, string $consentSource, int $changedBySystemUserId, DateTimeImmutable $changedAt): self`, getters `tutorId(): int`, `channel(): string`, `status(): string`, `consentSource(): string`, `changedBySystemUserId(): int`, `changedAt(): DateTimeImmutable`, `isOptedIn(): bool`, `CommunicationPreference::permitsSending(?CommunicationPreference $preference, string $legalBasis): bool` (`consent`: só com preferência `opted_in`; `legitimate_interest`: sem preferência ou `opted_in`; `opted_out` sempre falso)
- Produz: `MessageTemplate::create(int $tenantId, string $purpose, string $channel, string $name, ?string $subject, string $bodyText, int $createdBySystemUserId): self`, `MessageTemplate::update(string $purpose, string $channel, string $name, ?string $subject, string $bodyText, int $updatedBySystemUserId): void`, `activate(): void`, `deactivate(): void`, getters `id(): ?int`, `purpose(): string`, `channel(): string`, `name(): string`, `subject(): ?string`, `bodyText(): string`, `status(): string`, `isActive(): bool`; nome com 1 a 120 caracteres, assunto obrigatório no e-mail (até 190), corpo com 1 a 2000 caracteres, placeholders conferidos por `MessageTemplateRenderer::assertKnownPlaceholders`
- Produz: `MessageTemplateRenderer::PLACEHOLDERS = ['tutor_name', 'patient_name', 'unit_name', 'clinic_name', 'appointment_date', 'appointment_time', 'vaccine_name', 'due_date', 'amount_due']`, `MessageTemplateRenderer::render(string $text, array $variables): string`, `MessageTemplateRenderer::assertKnownPlaceholders(string $text): void` (lança `InvalidArgumentException` `Unknown placeholder "<nome>" in template`)
- Produz: `MessageTemplateDefaults::for(string $purpose, string $channel): array` devolvendo `['subject' => ?string, 'body' => string]` (assunto não nulo no e-mail)
- Produz: `OutboundMessage::STATUS_QUEUED = 'queued'`, `OutboundMessage::STATUS_SENT = 'sent'`, `OutboundMessage::STATUS_FAILED = 'failed'`, `OutboundMessage::STATUS_MANUAL = 'manual'`, `OutboundMessage::STATUS_CANCELLED = 'cancelled'`, `OutboundMessage::ORIGIN_AUTOMATION = 'automation'`, `OutboundMessage::ORIGIN_MANUAL = 'manual'`, `OutboundMessage::compose(int $tenantId, int $systemUnitId, int $tutorId, ?int $patientId, ?int $templateId, string $purpose, string $channel, string $origin, string $legalBasis, ?string $sourceType, ?int $sourceId, ?string $dedupeKey, string $recipient, ?string $subject, string $bodyText, ?int $createdBySystemUserId): self`, `OutboundMessage::buildDedupeKey(string $purpose, string $sourceType, int $sourceId, string $channel): string` (formato `<purpose>:<sourceType>:<sourceId>:<channel>`), `OutboundMessage::reconstitute(array $row): self`, getters `id(): ?int`, `tenantId(): int`, `systemUnitId(): int`, `tutorId(): int`, `patientId(): ?int`, `templateId(): ?int`, `purpose(): string`, `channel(): string`, `origin(): string`, `legalBasis(): string`, `sourceType(): ?string`, `sourceId(): ?int`, `dedupeKey(): ?string`, `recipient(): string`, `subject(): ?string`, `bodyText(): string`, `status(): string`, `attemptCount(): int`, `lastErrorCode(): ?string`, `sentAt(): ?DateTimeImmutable`, `failedAt(): ?DateTimeImmutable`, `createdAt(): ?DateTimeImmutable`
- Produz: `CommunicationConsentRequiredException::notOptedIn(int $tutorId, string $channel): self` (mensagem `Tutor <id> has not opted in to <channel> messages`) e `CommunicationConsentRequiredException::optedOut(int $tutorId, string $channel): self` (mensagem `Tutor <id> has opted out of <channel> messages`)
- Produz: `CommunicationPreferenceRepositoryInterface::findForTutor(int $tutorId): array` (mapa canal → `CommunicationPreference`, só canais com linha), `CommunicationPreferenceRepositoryInterface::upsert(CommunicationPreference $preference): void`
- Produz: `MessageTemplateRepositoryInterface::findById(int|string $id): ?object`, `MessageTemplateRepositoryInterface::listAll(): array`, `MessageTemplateRepositoryInterface::findActiveFor(string $purpose, string $channel): ?MessageTemplate`, `MessageTemplateRepositoryInterface::countActiveFor(string $purpose, string $channel, ?int $exceptTemplateId): int`, `MessageTemplateRepositoryInterface::save(object $entity): object`
- Produz: `OutboundMessageRepositoryInterface::insertIfNew(OutboundMessage $message): ?OutboundMessage` (null quando a `dedupe_key` já existe no tenant), `OutboundMessageRepositoryInterface::findById(int|string $id): ?object`, `OutboundMessageRepositoryInterface::claim(int $messageId, DateTimeImmutable $now): bool`, `OutboundMessageRepositoryInterface::markSent(int $messageId, string $provider, ?string $providerMessageId, DateTimeImmutable $sentAt): bool`, `OutboundMessageRepositoryInterface::releaseClaim(int $messageId, string $errorCode): bool`, `OutboundMessageRepositoryInterface::markFailed(int $messageId, string $errorCode, DateTimeImmutable $failedAt): bool`, `OutboundMessageRepositoryInterface::markManualSent(int $messageId, int $systemUserId, DateTimeImmutable $sentAt): bool`, `OutboundMessageRepositoryInterface::cancel(int $messageId, ?int $systemUserId, string $reasonCode, DateTimeImmutable $cancelledAt): bool`, `OutboundMessageRepositoryInterface::requeue(int $messageId): bool`, `OutboundMessageRepositoryInterface::listForUnit(int $systemUnitId, array $filters, int $limit): array`, `OutboundMessageRepositoryInterface::listStaleQueuedEmailIds(DateTimeImmutable $olderThan, int $limit): array`
- Produz: `AppointmentFollowupRepositoryInterface::link(int $appointmentId, int $encounterId, int $createdBySystemUserId): void`, `AppointmentFollowupRepositoryInterface::isFollowup(int $appointmentId): bool`
- Consome: nada

Semântica dos métodos de transição (todos devolvem `true` só quando uma linha do tenant mudou): `claim` = `queued` + canal `email` + (`claimed_at` nulo ou anterior a `$now` − 10 min) → grava `claimed_at`; `markSent` = `queued` com claim → `sent`; `releaseClaim` = `queued` → `claimed_at` nulo, `attempt_count` + 1, `last_error_code`; `markFailed` = `queued` → `failed` (`attempt_count` + 1); `markManualSent` = `queued` + canal `whatsapp` → `manual`; `cancel` = `queued` → `cancelled` (motivo em `last_error_code`: `discarded`, `opted_out` ou `consent_missing`); `requeue` = `failed` → `queued` (limpa `failed_at` e `claimed_at`). Filtros de `listForUnit`: `status`, `channel`, `purpose`, `tutor_id` (todos opcionais), ordem `created_at DESC`.

**Teste RED**
- `src/tests/Unit/CommunicationDomainTest.php` — render troca `{{tutor_name}}` e esvazia variável ausente, `assertKnownPlaceholders` recusa `{{cpf}}`, template de e-mail sem assunto é recusado, `buildDedupeKey('vaccine_due', 'vaccination', 7, 'email')` devolve `vaccine_due:vaccination:7:email`, `MessageTemplateDefaults::for` tem padrão para as 6 finalidades × 2 canais, `legalBasisFor('appointment_confirmation')` é `legitimate_interest` e `legalBasisFor('vaccine_due')` é `consent`, e `permitsSending` cobre as 6 combinações (sem linha, `opted_in`, `opted_out` × 2 bases); falha porque as classes ainda não existem (comando: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php | /usr/bin/grep -E 'CommunicationDomainTest|Failed:'`)

**Critério de aceite**
- `CommunicationDomainTest` com `PASS` em todos os casos e SUITE com `Failed: 0`.
- As listas de `CommunicationChannel::all()`, `MessagePurpose::all()`, `CommunicationPreference::SOURCES`, as constantes `LEGAL_BASIS_*` e os `STATUS_*` de `OutboundMessage` têm os mesmos literais dos CHECKs produzidos pela T-01.
- `permitsSending(null, 'consent')` e `permitsSending(<opted_out>, 'legitimate_interest')` são falsos; `permitsSending(null, 'legitimate_interest')` é verdadeiro.
- LINT imprime `No syntax errors detected` nos 13 arquivos.

**Validação**
- SUITE `| /usr/bin/grep -E 'CommunicationDomainTest|Failed:'` (evidência: linhas `PASS` de `CommunicationDomainTest` e `Failed: 0`)
- LINT em cada arquivo de "Arquivos prováveis" (evidência: `No syntax errors detected` em todos)
- `/usr/bin/grep -c "'appointment_confirmation'\|'vaccine_due'\|'return_reminder'\|'receivable_open'\|'document_ready'\|'custom'" src/app/Core/Domain/MessagePurpose.php` (evidência: `6` ou mais)

### T-03 — Domain da Central de Pendências e do candidato a lembrete + contratos das consultas

**Camada:** backend
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Arquimedes

**Arquivos prováveis**
- `src/app/Core/Domain/PendingItem.php`
- `src/app/Core/Domain/PendingItemPriority.php`
- `src/app/Core/Domain/ReminderCandidate.php`
- `src/app/Core/Domain/Contract/PendingItemQueryInterface.php`
- `src/app/Core/Domain/Contract/ReminderSourceQueryInterface.php`
- `src/tests/Unit/PendingItemDomainTest.php`

**Notas de implementação**

Regras fixas (o Domain é a única fonte; a T-08 só preenche `dueAt`, e a T-13/T-19 só leem):
- Prazo (`dueAt`) por tipo, montado pela consulta: `exam_result` = `requested_at` + 72 h; `exam_review` = `received_at` + 24 h; `return_appointment` = `scheduled_at`; `vaccine_due` = `next_dose_at` 00:00 no fuso da aplicação; `hospitalization_administration` = `scheduled_at` + 30 min; `message_failed` = `failed_at`; `message_whatsapp_manual` = `created_at` + 4 h; `receivable_open` = `created_at` + 7 dias.
- `PendingItemPriority::classify`: tipo `hospitalization_administration` → `urgent`; senão `$now > $dueAt` → `high`; `$dueAt <= $now + 24 h` → `normal`; senão `low`. `rank`: urgent 0, high 1, normal 2, low 3.
- `PendingItem::status($now)`: `overdue` se `$now > dueAt`, senão `open`.
- Deep-link: `deepLinkUrl()` monta `index.php?class=<classe>&<chave>=<valor>` só com valores inteiros positivos, data `Y-m-d` ou o literal `administrations`. Qualquer outro valor lança `InvalidArgumentException` (`Invalid deep-link parameter <chave>`), para nenhum texto livre ou dado pessoal ir para a URL. Classes permitidas: `ExamResultForm`, `AgendaView`, `VaccinationCardView`, `HospitalizationView`, `CommunicationMessageView`, `PaymentForm`.
- `ReminderCandidate` carrega o contato do tutor só em memória (nunca em log). `variables` já vem formatado (`appointment_date` `d/m/Y`, `appointment_time` `H:i`, `due_date` `d/m/Y`, `amount_due` `R$ 1.234,56`).

**Interface**
- Produz: `PendingItem::TYPE_EXAM_RESULT = 'exam_result'`, `PendingItem::TYPE_EXAM_REVIEW = 'exam_review'`, `PendingItem::TYPE_RETURN_APPOINTMENT = 'return_appointment'`, `PendingItem::TYPE_VACCINE_DUE = 'vaccine_due'`, `PendingItem::TYPE_HOSPITALIZATION_ADMINISTRATION = 'hospitalization_administration'`, `PendingItem::TYPE_MESSAGE_FAILED = 'message_failed'`, `PendingItem::TYPE_MESSAGE_WHATSAPP_MANUAL = 'message_whatsapp_manual'`, `PendingItem::TYPE_RECEIVABLE_OPEN = 'receivable_open'`, `PendingItem::TYPES`, `PendingItem::STATUS_OVERDUE = 'overdue'`, `PendingItem::STATUS_OPEN = 'open'`
- Produz: `new PendingItem(string $type, int $sourceId, ?string $patientName, string $subjectLabel, DateTimeImmutable $dueAt, ?int $responsibleSystemUserId, string $deepLinkClass, array $deepLinkParams)`, getters `type(): string`, `sourceId(): int`, `patientName(): ?string`, `subjectLabel(): string`, `dueAt(): DateTimeImmutable`, `responsibleSystemUserId(): ?int`, `priority(DateTimeImmutable $now): string`, `status(DateTimeImmutable $now): string`, `deepLinkUrl(): string`
- Produz: `PendingItemPriority::URGENT = 'urgent'`, `PendingItemPriority::HIGH = 'high'`, `PendingItemPriority::NORMAL = 'normal'`, `PendingItemPriority::LOW = 'low'`, `PendingItemPriority::classify(string $type, DateTimeImmutable $dueAt, DateTimeImmutable $now): string`, `PendingItemPriority::rank(string $priority): int`
- Produz: `new ReminderCandidate(string $purpose, string $sourceType, int $sourceId, int $systemUnitId, int $tutorId, ?int $patientId, ?string $tutorEmail, string $tutorPhone, array $variables)`, getters `purpose(): string`, `sourceType(): string`, `sourceId(): int`, `systemUnitId(): int`, `tutorId(): int`, `patientId(): ?int`, `tutorEmail(): ?string`, `tutorPhone(): string`, `variables(): array`
- Produz: `PendingItemQueryInterface::listForUnit(int $systemUnitId, DateTimeImmutable $now, int $limitPerType): array` (lista de `PendingItem`)
- Produz: `ReminderSourceQueryInterface::appointmentsBetween(DateTimeImmutable $from, DateTimeImmutable $to): array`, `ReminderSourceQueryInterface::vaccinesDueBetween(DateTimeImmutable $fromDate, DateTimeImmutable $toDate): array`, `ReminderSourceQueryInterface::openReceivablesCreatedBefore(DateTimeImmutable $before): array` (listas de `ReminderCandidate`; `appointmentsBetween` devolve `return_reminder` para retorno e `appointment_confirmation` para os demais)
- Consome: nada

**Teste RED**
- `src/tests/Unit/PendingItemDomainTest.php` — `classify` devolve `urgent` para administração, `high` para prazo vencido, `normal` dentro de 24 h e `low` depois; `deepLinkUrl()` de `HospitalizationView` com `id` 5 e `tab` `administrations` é `index.php?class=HospitalizationView&id=5&tab=administrations`, e um parâmetro com texto livre lança `InvalidArgumentException`; falha porque as classes ainda não existem (comando: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php | /usr/bin/grep -E 'PendingItemDomainTest|Failed:'`)

**Critério de aceite**
- `PendingItemDomainTest` com `PASS` em todos os casos (as 4 prioridades, os 2 status, o deep-link de cada uma das 6 classes e a recusa de texto livre) e SUITE com `Failed: 0`.
- LINT imprime `No syntax errors detected` nos 6 arquivos.

**Validação**
- SUITE `| /usr/bin/grep -E 'PendingItemDomainTest|Failed:'` (evidência: linhas `PASS` e `Failed: 0`)
- LINT em cada arquivo de "Arquivos prováveis" (evidência: `No syntax errors detected` em todos)

### T-04 — Programas RBAC das 7 telas (seed + DML, verify e rollback preparados)

**Camada:** infra
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Jaspion

**Arquivos prováveis**
- `src/app/database/seeds/initial-application-programs.sql`
- `.claude/tasks/mar-20261006-0842-fase-7a-comunicacao/sql/T-04-programs.sql`
- `.claude/tasks/mar-20261006-0842-fase-7a-comunicacao/sql/T-04-programs.verify.sql`
- `.claude/tasks/mar-20261006-0842-fase-7a-comunicacao/sql/T-04-programs.rollback.sql`

**Notas de implementação**

Modelos: o bloco "Fase 6B" do seed (linhas 557-708) e `.claude/tasks/mar-20261005-2234-fase-6b-cirurgia/sql/T-04-programs.sql`, `.verify.sql` e `.rollback.sql`. Concessão (decisão do usuário, `notes.md`): grupos 1 (`Template - Admin`), 2 (`Template - Users`), 4 (`Clínico – Internação`) e 5 (`Clínico – Cirurgia`); o grupo 3 (`Application - Programs`) não recebe nada. No padrão das fases anteriores, os grupos 1 e 2 do Adianti são localizados por id (`group_id = 1`/`= 2`, ids fixos de `permission.sql`) e os grupos criados na 6A e na 6B pelo nome exato (`name = 'Clínico – Internação'`, `name = 'Clínico – Cirurgia'`, com í e travessão U+2013; nunca id literal), por subconsulta no próprio INSERT. Se um dos dois nomes não existir, o INSERT daquele grupo não grava nada e o verify mostra 0.

Regras dos três arquivos SQL (lições da 6A/6B):
- **`SET NAMES utf8mb4;`** é a primeira instrução; o orquestrador aplica com `--default-character-set=utf8mb4`.
- **Sem id fixo.** Cada INSERT deriva o id no próprio comando, `INSERT INTO t (id, ...) SELECT (SELECT COALESCE(MAX(id),0)+1 FROM t cur), ... FROM DUAL WHERE NOT EXISTS (...)`, uma linha por comando (idempotente; 5.7: sem `ROW_NUMBER`, CTE nem variável de sessão para id).
- **Nomes de programas** ASCII em inglês.

`T-04-programs.sql` em `START TRANSACTION`: 7 INSERTs em `system_program`; 28 concessões em `system_group_program` (7 para cada um dos grupos 1, 2, `Clínico – Internação` e `Clínico – Cirurgia`), cada uma com `WHERE NOT EXISTS`; SELECTs de conferência e `COMMIT`.
`T-04-programs.verify.sql` (só SELECT): 7 controllers em `system_program`; concessões dos 7 controllers numa linha por grupo, com id e nome (grupo 1 = 7, grupo 2 = 7, `Clínico – Internação` = 7, `Clínico – Cirurgia` = 7, grupo 3 = 0); `COUNT(*)` de `system_program` e `system_group_program`.
`T-04-programs.rollback.sql` (preparado, nunca executado sem nova aprovação), em `START TRANSACTION`: SELECT prévio das concessões dos 7 controllers por grupo; DELETE em `system_group_program`, `system_user_program` e `system_program_method_role` dos 7 controllers (todos os grupos); DELETE dos 7 controllers em `system_program`; SELECTs e `COMMIT` comentado. Todo DELETE tem `WHERE` por nome de controller. Os grupos não são tocados.
O seed (instalação nova) recebe os mesmos programas e concessões, de forma idempotente, dentro da transação existente.

**Interface**
- Produz: programas `MessageTemplateList` (`Central Vet - Message Template List`), `MessageTemplateForm` (`Central Vet - Message Template Form`), `CommunicationMessageList` (`Central Vet - Communication Message List`), `CommunicationMessageView` (`Central Vet - Communication Message View`), `CommunicationComposeForm` (`Central Vet - Communication Compose Form`), `TutorCommunicationForm` (`Central Vet - Tutor Communication Form`), `PendingCenter` (`Central Vet - Pending Center`), cada um em `system_group_program` dos grupos 1, 2, `Clínico – Internação` e `Clínico – Cirurgia`, e em nenhum outro
- Consome: nada

**Teste RED**
- sem teste: DML SQL preparada (programas, verify e rollback), sem execução; conferida por grep e, depois do bloqueio, pelo verify do orquestrador

**Critério de aceite**
- `/usr/bin/grep -oE "controller='(MessageTemplate(List|Form)|CommunicationMessage(List|View)|CommunicationComposeForm|TutorCommunicationForm|PendingCenter)'" src/app/database/seeds/initial-application-programs.sql | sort -u | wc -l` imprime `7`; o seed continua numa transação só.
- Os três arquivos de `sql/` começam com `SET NAMES utf8mb4;`; `T-04-programs.sql` não tem id numérico literal nos INSERTs nem `ROW_NUMBER`/`WITH`, concede os 7 programas aos grupos 1 e 2 (por id) e aos grupos localizados por `name = 'Clínico – Internação'` e `name = 'Clínico – Cirurgia'`, e não cita o grupo 3.
- `T-04-programs.rollback.sql` não tem DELETE sem `WHERE` e cobre `system_group_program`, `system_user_program`, `system_program_method_role` e `system_program`.
- Depois do bloqueio (orquestrador), o verify mostra 7 concessões em cada grupo (1, 2, `Clínico – Internação`, `Clínico – Cirurgia`) e 0 no grupo 3, com `COUNT(*)` de `system_program` = anterior + 7 e de `system_group_program` = anterior + 28, e os registros existentes preservados.

**Validação**
- `/usr/bin/grep -oE "controller='(MessageTemplate(List|Form)|CommunicationMessage(List|View)|CommunicationComposeForm|TutorCommunicationForm|PendingCenter)'" src/app/database/seeds/initial-application-programs.sql | sort -u | wc -l` (evidência: `7`)
- `cd /var/www/html/centralvet/.claude/tasks/mar-20261006-0842-fase-7a-comunicacao && for f in sql/T-04-programs.sql sql/T-04-programs.verify.sql sql/T-04-programs.rollback.sql; do /usr/bin/grep -v '^--' $f | /usr/bin/grep -m1 -v '^[[:space:]]*$'; done` (evidência: três linhas `SET NAMES utf8mb4;`)
- `/usr/bin/grep -ciE "row_number|with recursive|values *\( *[0-9]{2,}" .claude/tasks/mar-20261006-0842-fase-7a-comunicacao/sql/T-04-programs.sql` (evidência: `0`)
- `/usr/bin/grep -ciE "delete from [a-z_]+ *;" .claude/tasks/mar-20261006-0842-fase-7a-comunicacao/sql/T-04-programs.rollback.sql` (evidência: `0`)
- `/usr/bin/grep -cE "Clínico – (Internação|Cirurgia)" .claude/tasks/mar-20261006-0842-fase-7a-comunicacao/sql/T-04-programs.sql` (evidência: número ≥ 2) e `/usr/bin/grep -cE "group_id *= *3([^0-9]|$)" .claude/tasks/mar-20261006-0842-fase-7a-comunicacao/sql/T-04-programs.sql` (evidência: `0`)
- Depois do bloqueio (orquestrador): `docker compose exec -T mysql sh -c 'mysql --default-character-set=utf8mb4 -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" centralvet' < .claude/tasks/mar-20261006-0842-fase-7a-comunicacao/sql/T-04-programs.verify.sql` (evidência: grupos 1, 2, `Clínico – Internação` e `Clínico – Cirurgia` = 7 cada, grupo 3 = 0, contagens = anterior + 7 / + 28)

### T-05 — Provedores desacoplados (log, SMTP por env, link wa.me) + variáveis em .env.example e docker-compose

**Camada:** backend
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Tesla

**Arquivos prováveis**
- `src/app/Core/Communication/MessageChannelProviderInterface.php`
- `src/app/Core/Communication/OutgoingMessage.php`
- `src/app/Core/Communication/MessageDeliveryFailed.php`
- `src/app/Core/Communication/LogEmailProvider.php`
- `src/app/Core/Communication/SmtpConfig.php`
- `src/app/Core/Communication/SmtpEmailProvider.php`
- `src/app/Core/Communication/EmailProviderFactory.php`
- `src/app/Core/Communication/WhatsAppLinkBuilder.php`
- `.env.example`
- `docker-compose.yml`
- `src/tests/Unit/CommunicationProviderTest.php`

**Notas de implementação**

Namespace `CentralVet\Communication` (PSR-4 `CentralVet\` → `app/Core/`). Config no padrão `fromEnvironment()` com `getenv` (como `RedisQueue::fromEnvironment`). Regras de dado pessoal: nenhuma exceção, log ou retorno leva destinatário ou corpo. `MessageDeliveryFailed` tem mensagem fixa `Message delivery failed: <code>`, porque `worker.php` repassa `getMessage()` ao dead-letter do Redis. O `LogEmailProvider` registra `communication.email.sandbox` com `reference`, `recipient_hash` (12 primeiros hex do SHA-256 do destinatário em minúsculas), `subject_length` e `body_length`. O `SmtpEmailProvider` usa PHPMailer (`isSMTP`, `SMTPAuth` só com usuário, `CharSet` UTF-8, `isHTML(false)`, `Timeout`, `SMTPSecure` por `SMTP_ENCRYPTION`), recebe uma `?Closure $mailerFactory` para teste e mapeia falhas para os códigos `smtp_connect`, `smtp_auth`, `smtp_recipient_rejected` e `smtp_error`, nunca copiando `ErrorInfo`. Ele não envia nada no teste: o teste injeta uma fábrica cujo dublê de PHPMailer registra a configuração e lança a falha desejada.

Variáveis (no `.env.example`, numa seção comentada em pt-BR depois da seção do worker, todas com valor de exemplo seguro; e no `x-php-service.environment` do compose, com padrão `${VAR:-padrão}`; nada no `.env`):
`COMMUNICATION_EMAIL_DRIVER` (`log`), `SMTP_HOST` (vazio), `SMTP_PORT` (`587`), `SMTP_USERNAME` (vazio), `SMTP_PASSWORD` (vazio), `SMTP_ENCRYPTION` (`tls`; aceita `tls`, `ssl`, `none`), `SMTP_FROM_ADDRESS` (`no-reply@example.invalid`), `SMTP_FROM_NAME` (`Central Vet`), `SMTP_TIMEOUT_SECONDS` (`10`), `COMMUNICATION_SCHEDULER_INTERVAL_SECONDS` (`3600`; `0` desliga), `COMMUNICATION_RECEIVABLE_REMINDER_DAYS` (`7`), `COMMUNICATION_SYSTEM_USER_ID` (`1`).

Telefone para WhatsApp: só dígitos; 10 ou 11 dígitos recebem o prefixo `55`; 12 ou 13 dígitos começando por `55` ficam como estão; o resto é inválido.

**Interface**
- Produz: `MessageChannelProviderInterface::channel(): string`, `MessageChannelProviderInterface::name(): string`, `MessageChannelProviderInterface::deliver(OutgoingMessage $message): ?string` (devolve o id do provedor ou null; lança `MessageDeliveryFailed`)
- Produz: `new OutgoingMessage(string $recipient, ?string $subject, string $body, string $reference)` com propriedades públicas `readonly` `recipient`, `subject`, `body`, `reference`
- Produz: `new MessageDeliveryFailed(string $errorCode)`, `MessageDeliveryFailed::errorCode(): string`, mensagem `Message delivery failed: <code>`
- Produz: `new LogEmailProvider(LoggerInterface $logger)` com `name()` = `log`; `new SmtpEmailProvider(SmtpConfig $config, ?Closure $mailerFactory = null)` com `name()` = `smtp`; `SmtpConfig::fromEnvironment(): self`; `EmailProviderFactory::fromEnvironment(LoggerInterface $logger): MessageChannelProviderInterface`
- Produz: `WhatsAppLinkBuilder::normalizePhone(string $phone): ?string`, `WhatsAppLinkBuilder::build(string $phone, string $text): string` (`https://wa.me/<dígitos>?text=<rawurlencode do texto>`; telefone inválido lança `InvalidArgumentException` `Invalid phone number for WhatsApp`)
- Produz: variáveis `COMMUNICATION_EMAIL_DRIVER`, `SMTP_HOST`, `SMTP_PORT`, `SMTP_USERNAME`, `SMTP_PASSWORD`, `SMTP_ENCRYPTION`, `SMTP_FROM_ADDRESS`, `SMTP_FROM_NAME`, `SMTP_TIMEOUT_SECONDS`, `COMMUNICATION_SCHEDULER_INTERVAL_SECONDS`, `COMMUNICATION_RECEIVABLE_REMINDER_DAYS`, `COMMUNICATION_SYSTEM_USER_ID` em `.env.example` e no `x-php-service` do `docker-compose.yml`
- Consome: nada

**Teste RED**
- `src/tests/Unit/CommunicationProviderTest.php` — `WhatsAppLinkBuilder::build('(85) 99999-0000', 'Olá & até')` devolve `https://wa.me/5585999990000?text=Ol%C3%A1%20%26%20at%C3%A9`; `normalizePhone('123')` é null; o driver `log` é o padrão; o `LogEmailProvider` não registra o destinatário; uma falha de conexão no dublê do PHPMailer vira `MessageDeliveryFailed` com código `smtp_connect` e mensagem sem o endereço; falha porque as classes ainda não existem (comando: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php | /usr/bin/grep -E 'CommunicationProviderTest|Failed:'`)

**Critério de aceite**
- `CommunicationProviderTest` com `PASS` em todos os casos e SUITE com `Failed: 0`.
- `docker compose config` sai com código 0 e mostra `COMMUNICATION_EMAIL_DRIVER: log` no serviço `worker`.
- `.env.example` tem as 12 variáveis; `.env` não aparece em `git status`.
- LINT imprime `No syntax errors detected` nos 9 PHP.

**Validação**
- SUITE `| /usr/bin/grep -E 'CommunicationProviderTest|Failed:'` (evidência: linhas `PASS` e `Failed: 0`)
- `docker compose config | /usr/bin/grep -c "COMMUNICATION_EMAIL_DRIVER: log"` (evidência: número ≥ 2, app e worker)
- `/usr/bin/grep -cE "^#? ?(COMMUNICATION_EMAIL_DRIVER|SMTP_HOST|SMTP_PORT|SMTP_USERNAME|SMTP_PASSWORD|SMTP_ENCRYPTION|SMTP_FROM_ADDRESS|SMTP_FROM_NAME|SMTP_TIMEOUT_SECONDS|COMMUNICATION_SCHEDULER_INTERVAL_SECONDS|COMMUNICATION_RECEIVABLE_REMINDER_DAYS|COMMUNICATION_SYSTEM_USER_ID)=" .env.example` (evidência: `12`)
- `git -C /var/www/html/centralvet status --short .env` (evidência: saída vazia)
- LINT em cada PHP de "Arquivos prováveis" (evidência: `No syntax errors detected` em todos)

### T-06 — Fakes dos repositórios, consultas, fila e provedor

**Camada:** shared
**Dependências:** T-02, T-03, T-05
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Platão

**Arquivos prováveis**
- `src/tests/Support/FakeCommunicationPreferenceRepository.php`
- `src/tests/Support/FakeMessageTemplateRepository.php`
- `src/tests/Support/FakeOutboundMessageRepository.php`
- `src/tests/Support/FakeAppointmentFollowupRepository.php`
- `src/tests/Support/FakePendingItemQuery.php`
- `src/tests/Support/FakeReminderSourceQuery.php`
- `src/tests/Support/FakeQueue.php`
- `src/tests/Support/FakeEmailProvider.php`
- `src/tests/Unit/CommunicationFakesTest.php`

**Notas de implementação**

Namespace `CentralVet\Tests\Support`, no padrão de `FakeSurgeryRepository` (a 6B corrigiu os fakes para devolver cópia nova em `findById`, como o PDO). `FakeOutboundMessageRepository` reproduz a semântica de transição da T-02 (cada método devolve `false` quando o status esperado não confere) e a dedupe por `(tenant, dedupe_key)`. Ele expõe `all(): array` para os testes e `simulateConcurrentTransition(int $messageId, string $status)` para provar corridas. `FakeQueue implements QueueInterface` e guarda os pushes em `pushed(): array` (fila, payload, tenantId, maxAttempts). `FakeEmailProvider implements MessageChannelProviderInterface`: `failWith(string $errorCode)` faz `deliver` lançar `MessageDeliveryFailed`, e `deliveries(): array` lista as entregas. `FakeReminderSourceQuery` e `FakePendingItemQuery` recebem as listas no construtor e registram os argumentos recebidos (`calls(): array`).

**Interface**
- Produz: `FakeCommunicationPreferenceRepository`, `FakeMessageTemplateRepository`, `FakeOutboundMessageRepository` (com `all(): array` e `simulateConcurrentTransition(int $messageId, string $status): void`), `FakeAppointmentFollowupRepository`, `FakePendingItemQuery`, `FakeReminderSourceQuery` (com `calls(): array`), `FakeQueue` (com `pushed(): array`), `FakeEmailProvider` (com `failWith(string $errorCode): void` e `deliveries(): array`)
- Consome: T-02 `OutboundMessageRepositoryInterface::insertIfNew(OutboundMessage $message): ?OutboundMessage`, T-02 `CommunicationPreferenceRepositoryInterface::findForTutor(int $tutorId): array`, T-02 `MessageTemplateRepositoryInterface::findActiveFor(string $purpose, string $channel): ?MessageTemplate`, T-02 `AppointmentFollowupRepositoryInterface::isFollowup(int $appointmentId): bool`, T-03 `PendingItemQueryInterface::listForUnit(int $systemUnitId, DateTimeImmutable $now, int $limitPerType): array`, T-03 `ReminderSourceQueryInterface::appointmentsBetween(DateTimeImmutable $from, DateTimeImmutable $to): array`, T-05 `MessageChannelProviderInterface::deliver(OutgoingMessage $message): ?string`

**Teste RED**
- `src/tests/Unit/CommunicationFakesTest.php` — `insertIfNew` com a mesma `dedupe_key` devolve null na segunda vez; `markManualSent` em mensagem de e-mail devolve false; `requeue` só muda `failed`; `FakeQueue` registra o payload; `FakeEmailProvider::failWith('smtp_connect')` lança `MessageDeliveryFailed`; falha porque os dublês ainda não existem (comando: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php | /usr/bin/grep -E 'CommunicationFakesTest|Failed:'`)

**Critério de aceite**
- `CommunicationFakesTest` com `PASS` e SUITE com `Failed: 0`.
- Cada dublê implementa a interface correspondente (`implements` presente nos 8 arquivos).
- LINT imprime `No syntax errors detected` nos 9 arquivos.

**Validação**
- SUITE `| /usr/bin/grep -E 'CommunicationFakesTest|Failed:'` (evidência: `PASS` e `Failed: 0`)
- `/usr/bin/grep -l "implements" src/tests/Support/Fake{CommunicationPreferenceRepository,MessageTemplateRepository,OutboundMessageRepository,AppointmentFollowupRepository,PendingItemQuery,ReminderSourceQuery,Queue,EmailProvider}.php | wc -l` (evidência: `8`)
- LINT nos 9 arquivos (evidência: `No syntax errors detected`)

### T-07 — Repositórios PDO de comunicação + PdoConnectionFactory

**Camada:** backend
**Dependências:** T-01, T-02
**Paralelizável:** sim
**Complexidade:** alta
**Agente:** Athena

**Arquivos prováveis**
- `src/app/Core/Persistence/PdoConnectionFactory.php`
- `src/app/Core/Persistence/CommunicationPreferenceRepository.php`
- `src/app/Core/Persistence/MessageTemplateRepository.php`
- `src/app/Core/Persistence/OutboundMessageRepository.php`
- `src/app/Core/Persistence/AppointmentFollowupRepository.php`
- `src/tests/Integration/CommunicationRepositoryIntegrationTest.php`

**Notas de implementação**

Padrão de `SurgeryRepository.php`/`HospitalizationRepository.php` (`__construct(TenantContext $context, private readonly PDO $connection)`, `tenantQuery()`, `assertEntityTenant()`). Teste no padrão de `SurgeryRepositoryIntegrationTest` (`MysqlIntegrationTestCase`, transação revertida no fim).
- `insertIfNew`: INSERT simples; `PDOException` com `errorInfo[1] === 1062` **e** `dedupe_key` não nula devolve `null`; qualquer outra violação sobe. Nunca `INSERT IGNORE` (no MySQL 8 ele transforma violação de CHECK em aviso).
- Transições: um `UPDATE communication_message SET ... WHERE id = :id AND tenant_id = :tenant AND status = '<esperado>' [AND channel = ...]` por método, com `rowCount() === 1` como retorno (lição 6A/6B). O claim compara `claimed_at < :stale` com `:stale` = `$now` − 10 min calculado em PHP (sem função no SQL além de parâmetros).
- `CommunicationPreferenceRepository::upsert`: `INSERT ... ON DUPLICATE KEY UPDATE status, consent_source, changed_by_system_user_id, changed_at` (igual no 8 e no 5.7).
- `listForUnit`: filtro de unidade e tenant obrigatório; limite aplicado no SQL.
- `listStaleQueuedEmailIds`: `status = 'queued' AND channel = 'email' AND created_at < :olderThan AND (claimed_at IS NULL OR claimed_at < :olderThan)`.
- `PdoConnectionFactory::fromEnvironment()`: DSN `mysql:host=<DB_HOST>;port=<DB_PORT>;dbname=<DB_DATABASE>;charset=utf8mb4`, usuário `DB_USERNAME`, senha `DB_PASSWORD`, `ERRMODE_EXCEPTION`, `EMULATE_PREPARES` falso; mesmas chaves de `app/config/database.php`. A mensagem de falha não inclui a senha.
- Timestamps gravados e lidos na mesma convenção de `SurgeryRepository` (formato `Y-m-d H:i:s.u`).

**Interface**
- Produz: `PdoConnectionFactory::fromEnvironment(): PDO`
- Produz: `new CommunicationPreferenceRepository(TenantContext $context, PDO $connection)`, `new MessageTemplateRepository(TenantContext $context, PDO $connection)`, `new OutboundMessageRepository(TenantContext $context, PDO $connection)`, `new AppointmentFollowupRepository(TenantContext $context, PDO $connection)`, implementando os contratos da T-02
- Consome: T-02 `OutboundMessageRepositoryInterface::insertIfNew(OutboundMessage $message): ?OutboundMessage`, T-02 `OutboundMessageRepositoryInterface::claim(int $messageId, DateTimeImmutable $now): bool`, T-02 `OutboundMessageRepositoryInterface::markManualSent(int $messageId, int $systemUserId, DateTimeImmutable $sentAt): bool`, T-02 `CommunicationPreferenceRepositoryInterface::upsert(CommunicationPreference $preference): void`, T-02 `MessageTemplateRepositoryInterface::countActiveFor(string $purpose, string $channel, ?int $exceptTemplateId): int`, T-02 `AppointmentFollowupRepositoryInterface::link(int $appointmentId, int $encounterId, int $createdBySystemUserId): void`, T-01 `communication_message_tenant_dedupe_uq (tenant_id, dedupe_key)`, T-01 `communication_preference_tutor_channel_uq (tenant_id, tutor_id, channel)`

**Teste RED**
- `src/tests/Integration/CommunicationRepositoryIntegrationTest.php` — no `centralvet_test` com a 0012: a segunda `insertIfNew` com a mesma `dedupe_key` devolve null e deixa uma linha; `claim` duas vezes devolve true e depois false; `markManualSent` duas vezes devolve true e false; `upsert` duas vezes no mesmo tutor e canal deixa uma linha com o último status; `legal_basis` grava e volta igual em `findById`; mensagem de outro tenant não é encontrada por `findById`; falha porque os repositórios ainda não existem (comando: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php | /usr/bin/grep -E 'CommunicationRepositoryIntegrationTest|Failed:'`)

**Critério de aceite**
- `CommunicationRepositoryIntegrationTest` com `PASS` (não `SKIP`) em todos os casos e SUITE com `Failed: 0`.
- Nenhum `INSERT IGNORE` nos repositórios novos; todas as transições usam `rowCount()`.
- LINT imprime `No syntax errors detected` nos 6 arquivos.

**Validação**
- SUITE `| /usr/bin/grep -E 'CommunicationRepositoryIntegrationTest|Failed:'` (evidência: `PASS`, nenhum `SKIP`, `Failed: 0`)
- `/usr/bin/grep -ci "insert ignore" src/app/Core/Persistence/OutboundMessageRepository.php src/app/Core/Persistence/CommunicationPreferenceRepository.php` (evidência: `0` nos dois)
- `/usr/bin/grep -c "rowCount()" src/app/Core/Persistence/OutboundMessageRepository.php` (evidência: número ≥ 7)
- LINT nos 6 arquivos (evidência: `No syntax errors detected`)
- `docker compose exec -T mysql sh -c 'mysql -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" -N -e "SELECT COUNT(*) FROM communication_message" centralvet'` antes e depois da SUITE (evidência: mesmo número; o teste roda só no `centralvet_test`)

### T-08 — Consultas PDO de pendências e de candidatos a lembrete

**Camada:** backend
**Dependências:** T-01, T-03
**Paralelizável:** sim
**Complexidade:** alta
**Agente:** Sherlock

**Arquivos prováveis**
- `src/app/Core/Persistence/PendingItemQuery.php`
- `src/app/Core/Persistence/ReminderSourceQuery.php`
- `src/tests/Integration/CommunicationReadModelIntegrationTest.php`

**Notas de implementação**

Só SELECT, com `tenant_id` em toda tabela da junção (`tenantQuery()` ou parâmetro explícito) e unidade quando o método a recebe. Limite por tipo no SQL. Conversão de datas igual à de `AppointmentRepository::listByUnitAndDate` (a mesma convenção de fuso). Fontes de `PendingItemQuery::listForUnit` (prazo conforme a T-03):
- `exam_result`: `exam_request.status = 'requested'` com unidade de `encounter.system_unit_id`; responsável `exam_request.professional_system_user_id`; deep-link `ExamResultForm` (`exam_request_id`, `encounter_id`).
- `exam_review`: `exam_result.pending_review = 1` com a mesma unidade e o mesmo destino.
- `return_appointment`: `appointment` em `appointment_followup` ou em `surgery.followup_appointment_id`, status `agendado` ou `confirmado`, `scheduled_at` entre `$now` − 7 dias e `$now` + 2 dias; responsável o profissional; deep-link `AgendaView` (`date`).
- `vaccine_due`: `vaccination.next_dose_at <= ` hoje + 7 dias, sem vacinação posterior do mesmo paciente e do mesmo `vaccine_catalog_item_id` (`NOT EXISTS`), unidade do atendimento da vacinação; responsável o profissional que aplicou; deep-link `VaccinationCardView` (`patient_id`).
- `hospitalization_administration`: `status = 'pending'` e `scheduled_at` + 30 min < `$now`, internação `admitted` na unidade; responsável `hospitalization.responsible_system_user_id`; deep-link `HospitalizationView` (`id` da internação, `tab` = `administrations`).
- `message_failed`: `communication_message.status = 'failed'` na unidade; deep-link `CommunicationMessageView` (`id`).
- `message_whatsapp_manual`: `status = 'queued'` e `channel = 'whatsapp'` na unidade; mesmo destino.
- `receivable_open`: `receivable.status IN ('open', 'partially_paid')` com unidade de `encounter_account.system_unit_id`; deep-link `PaymentForm` (`receivable_id`).
O `subjectLabel` é o nome do exame, da vacina, do serviço ou o código da finalidade, sem `_t` (a tela traduz e escapa). `ReminderSourceQuery` (tenant inteiro, sem unidade fixa): `appointmentsBetween` só com status `agendado` (confirmação) ou `agendado`/`confirmado` (retorno); `vaccinesDueBetween` com o mesmo `NOT EXISTS`; `openReceivablesCreatedBefore` com `open`/`partially_paid` e `amount_due` = `total_cents - paid_cents`. Todos trazem o contato do tutor, o nome do paciente, o nome da unidade (`system_unit.name`) e o nome da clínica (`tenant.trade_name`, ou `legal_name`).

**Interface**
- Produz: `new PendingItemQuery(TenantContext $context, PDO $connection)` implementando `PendingItemQueryInterface`; `new ReminderSourceQuery(TenantContext $context, PDO $connection)` implementando `ReminderSourceQueryInterface`
- Consome: T-03 `PendingItemQueryInterface::listForUnit(int $systemUnitId, DateTimeImmutable $now, int $limitPerType): array`, T-03 `ReminderSourceQueryInterface::appointmentsBetween(DateTimeImmutable $from, DateTimeImmutable $to): array`, T-03 `ReminderSourceQueryInterface::vaccinesDueBetween(DateTimeImmutable $fromDate, DateTimeImmutable $toDate): array`, T-03 `ReminderSourceQueryInterface::openReceivablesCreatedBefore(DateTimeImmutable $before): array`, T-01 `appointment_followup(id, tenant_id, appointment_id bigint unsigned, encounter_id bigint unsigned, created_by_system_user_id int, created_at)`

**Teste RED**
- `src/tests/Integration/CommunicationReadModelIntegrationTest.php` — no `centralvet_test`: cada um dos 8 tipos aparece com o deep-link da T-03; a administração atrasada da unidade B não aparece em `listForUnit` da unidade A; a vacina já reaplicada não aparece; o agendamento ligado por `appointment_followup` volta como `return_reminder` e o outro como `appointment_confirmation`; nenhum item de outro tenant; falha porque as classes ainda não existem (comando: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php | /usr/bin/grep -E 'CommunicationReadModelIntegrationTest|Failed:'`)

**Critério de aceite**
- `CommunicationReadModelIntegrationTest` com `PASS` (não `SKIP`), cobrindo os 8 tipos, o isolamento por unidade e por tenant e a regra de vacina reaplicada; SUITE com `Failed: 0`.
- As duas classes só executam `SELECT` (nenhum `INSERT`, `UPDATE` ou `DELETE` no código).
- LINT imprime `No syntax errors detected` nos 3 arquivos.

**Validação**
- SUITE `| /usr/bin/grep -E 'CommunicationReadModelIntegrationTest|Failed:'` (evidência: `PASS`, nenhum `SKIP`, `Failed: 0`)
- `/usr/bin/grep -ciE "\b(insert|update|delete)\b" src/app/Core/Persistence/PendingItemQuery.php src/app/Core/Persistence/ReminderSourceQuery.php` (evidência: `0` nos dois, salvo comentário)
- LINT nos 3 arquivos (evidência: `No syntax errors detected`)

### T-09 — CommunicationPreferenceService e MessageTemplateService

**Camada:** backend
**Dependências:** T-02, T-06
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Jaspion

**Arquivos prováveis**
- `src/app/Core/Application/CommunicationPreferenceService.php`
- `src/app/Core/Application/MessageTemplateService.php`
- `src/tests/Unit/CommunicationPreferenceServiceTest.php`
- `src/tests/Unit/MessageTemplateServiceTest.php`

**Notas de implementação**

Padrão de `SurgeryRoomService` (repositórios por interface, `AuthorizationPolicyInterface`, `TenantContext`, `?Closure $clock = null` por último; sem transação). Preferência e template são do tenant: `requiresUnitScope: false`. A autorização do registro de consentimento leva `entityType: 'communication_preference'`, `entityId` = tutor e `metadata` `['channel' => ..., 'status_before' => ..., 'status_after' => ..., 'consent_source' => ...]`, sem e-mail nem telefone (é o rastro de auditoria do `RbacAuthorizationService`). O tutor tem de existir no tenant (`TutorRepositoryInterface::findById`; senão `CrossTenantReferenceException` `tutor_id <id> was not found for the authenticated tenant`). Template: dois ativos na mesma finalidade e canal são recusados com `Another active template already exists for this purpose and channel` (no `save` com status ativo e no `activate`). Mensagens novas vão para o board como `- [T-09] i18n-domínio: <mensagem>`.

**Interface**
- Produz: `new CommunicationPreferenceService(CommunicationPreferenceRepositoryInterface $preferences, TutorRepositoryInterface $tutors, AuthorizationPolicyInterface $authorization, TenantContext $context, ?Closure $clock = null)`, `CommunicationPreferenceService::preferencesFor(int $tutorId, string $action): array` (mapa `email`/`whatsapp` → `opted_in`, `opted_out` ou `not_recorded`), `CommunicationPreferenceService::record(int $tutorId, string $channel, string $status, string $consentSource, string $action): CommunicationPreference`
- Produz: `new MessageTemplateService(MessageTemplateRepositoryInterface $templates, AuthorizationPolicyInterface $authorization, TenantContext $context)`, `MessageTemplateService::list(string $action): array`, `MessageTemplateService::find(int $templateId, string $action): MessageTemplate`, `MessageTemplateService::save(array $data, string $action): MessageTemplate` (chaves `id` opcional, `purpose`, `channel`, `name`, `subject`, `body_text`, `status`), `MessageTemplateService::setActive(int $templateId, bool $active, string $action): MessageTemplate`
- Consome: T-02 `CommunicationPreferenceRepositoryInterface::upsert(CommunicationPreference $preference): void`, T-02 `CommunicationPreferenceRepositoryInterface::findForTutor(int $tutorId): array`, T-02 `MessageTemplateRepositoryInterface::countActiveFor(string $purpose, string $channel, ?int $exceptTemplateId): int`, T-02 `MessageTemplateRenderer::assertKnownPlaceholders(string $text): void`, T-06 `FakeCommunicationPreferenceRepository`, T-06 `FakeMessageTemplateRepository`

**Teste RED**
- `src/tests/Unit/CommunicationPreferenceServiceTest.php`, `src/tests/Unit/MessageTemplateServiceTest.php` — `preferencesFor` de tutor sem linha devolve `not_recorded` nos dois canais; `record` com origem fora de `CommunicationPreference::SOURCES` é recusado; a política negada lança `AuthorizationDenied` sem gravar; o segundo template ativo na mesma finalidade e canal é recusado; placeholder `{{cpf}}` é recusado; falha porque os services ainda não existem (comando: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php | /usr/bin/grep -E 'CommunicationPreferenceServiceTest|MessageTemplateServiceTest|Failed:'`)

**Critério de aceite**
- Os dois testes com `PASS`, incluindo o caso de permissão negada sem gravação e a metadata de auditoria sem `@` nem dígitos de telefone; SUITE com `Failed: 0`.
- LINT imprime `No syntax errors detected` nos 4 arquivos.

**Validação**
- SUITE `| /usr/bin/grep -E 'CommunicationPreferenceServiceTest|MessageTemplateServiceTest|Failed:'` (evidência: `PASS` e `Failed: 0`)
- LINT nos 4 arquivos (evidência: `No syntax errors detected`)

### T-10 — MessageService (ciclo manual) e MessageQueuePublisher

**Camada:** backend
**Dependências:** T-02, T-05, T-06
**Paralelizável:** sim
**Complexidade:** alta
**Agente:** Athena

**Arquivos prováveis**
- `src/app/Core/Application/MessageService.php`
- `src/app/Core/Application/MessageQueuePublisher.php`
- `src/tests/Unit/MessageServiceTest.php`

**Notas de implementação**

Services não abrem transação nem publicam na fila: o controller compõe dentro do `TTransaction` e chama `MessageQueuePublisher::publish` **depois** do commit (o agendador re-publica e-mail preso). Autorização em toda operação: `requiresUnitScope: true`, `resourceUnitId` = unidade da mensagem persistida (em `compose`, a unidade ativa `requireUnitId()`), `entityType: 'communication_message'`.
- `compose`: tutor do tenant obrigatório; paciente opcional, que tem de ser do tutor (`PatientRepositoryInterface::findById` e `tutor_id`); base legal = `MessagePurpose::legalBasisFor(purpose)`, gravada na mensagem; `CommunicationPreference::permitsSending(preferência do canal, base)` falso → `CommunicationConsentRequiredException::optedOut` quando a preferência é `opted_out`, senão `::notOptedIn` (confirmação e retorno saem sem opt-in, salvo opt-out; as demais finalidades exigem opt-in); contato: e-mail nulo → `InvalidArgumentException` `Tutor <id> has no e-mail address`, e telefone inválido → a exceção de `WhatsAppLinkBuilder::normalizePhone`/`build`; `origin` `manual`, sem `dedupe_key`; destinatário = e-mail ou telefone normalizado; corpo de 1 a 2000 caracteres e assunto obrigatório no e-mail.
- `renderTemplate`: renderiza com `tutor_name` e `patient_name` (demais variáveis vazias no envio manual).
- `markManualSent`/`cancel`/`retry`: chamam o repositório e, quando ele devolve `false`, lançam `InvalidStatusTransitionException` com `Message <id> is no longer awaiting manual send`, `Message <id> is no longer queued` e `Message <id> has not failed`, respectivamente (o segundo toque concorrente vira mensagem de domínio, não erro genérico).
- `whatsAppLink`: só para WhatsApp `queued`; monta com `WhatsAppLinkBuilder::build(recipient, bodyText)`.
- Mensagens novas vão para o board como `- [T-10] i18n-domínio: <mensagem>`.

**Interface**
- Produz: `new MessageService(OutboundMessageRepositoryInterface $messages, MessageTemplateRepositoryInterface $templates, CommunicationPreferenceRepositoryInterface $preferences, TutorRepositoryInterface $tutors, PatientRepositoryInterface $patients, AuthorizationPolicyInterface $authorization, TenantContext $context, ?Closure $clock = null)`
- Produz: `MessageService::compose(array $data, string $action): OutboundMessage` (chaves `tutor_id`, `patient_id` opcional, `channel`, `purpose`, `template_id` opcional, `subject`, `body_text`), `MessageService::renderTemplate(int $templateId, int $tutorId, ?int $patientId, string $action): array` (`['subject' => ?string, 'body' => string]`), `MessageService::markManualSent(int $messageId, string $action): void`, `MessageService::cancel(int $messageId, string $action): void`, `MessageService::retry(int $messageId, string $action): OutboundMessage`, `MessageService::whatsAppLink(int $messageId, string $action): string`, `MessageService::find(int $messageId, string $action): OutboundMessage`, `MessageService::listForUnit(array $filters, string $action): array`
- Produz: `new MessageQueuePublisher(QueueInterface $queue)`, `MessageQueuePublisher::JOB_TYPE = 'communication.message.send'`, `MessageQueuePublisher::QUEUE = 'default'`, `MessageQueuePublisher::MAX_ATTEMPTS = 5`, `MessageQueuePublisher::publish(int $tenantId, int $messageId): string` (payload exatamente `['type' => 'communication.message.send', 'message_id' => <id>]`)
- Consome: T-02 `MessagePurpose::legalBasisFor(string $purpose): string`, T-02 `CommunicationPreference::permitsSending(?CommunicationPreference $preference, string $legalBasis): bool`, T-02 `OutboundMessageRepositoryInterface::markManualSent(int $messageId, int $systemUserId, DateTimeImmutable $sentAt): bool`, T-02 `OutboundMessageRepositoryInterface::cancel(int $messageId, ?int $systemUserId, string $reasonCode, DateTimeImmutable $cancelledAt): bool`, T-02 `OutboundMessageRepositoryInterface::requeue(int $messageId): bool`, T-02 `OutboundMessageRepositoryInterface::listForUnit(int $systemUnitId, array $filters, int $limit): array`, T-02 `CommunicationConsentRequiredException::notOptedIn(int $tutorId, string $channel): self`, T-02 `CommunicationConsentRequiredException::optedOut(int $tutorId, string $channel): self`, T-05 `WhatsAppLinkBuilder::build(string $phone, string $text): string`, T-06 `FakeOutboundMessageRepository`, T-06 `FakeQueue`

**Teste RED**
- `src/tests/Unit/MessageServiceTest.php` — `compose` `custom` para tutor sem preferência lança `Tutor <id> has not opted in to email messages` e não grava; `compose` `appointment_confirmation` para tutor sem preferência grava `queued` com `legal_basis` `legitimate_interest`; `compose` `appointment_confirmation` para tutor `opted_out` lança `Tutor <id> has opted out of email messages`; e-mail `custom` com opt-in vira `queued` com `origin` `manual` e `legal_basis` `consent`; o segundo `markManualSent` (depois de `simulateConcurrentTransition` para `manual`) lança `Message <id> is no longer awaiting manual send`; `retry` de mensagem `sent` é recusado; `whatsAppLink` devolve `https://wa.me/55...` com o corpo codificado; `publish` grava no `FakeQueue` o payload só com `type` e `message_id`; falha porque as classes ainda não existem (comando: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php | /usr/bin/grep -E 'MessageServiceTest|Failed:'`)

**Critério de aceite**
- `MessageServiceTest` com `PASS`, incluindo o caso de corrida no "marcar como enviado" (Review Focus), o de permissão negada sem gravação e os 3 casos de base legal (legítimo interesse sem preferência, legítimo interesse com opt-out, consentimento sem opt-in); SUITE com `Failed: 0`.
- O payload publicado tem exatamente as chaves `type` e `message_id`.
- LINT imprime `No syntax errors detected` nos 3 arquivos.

**Validação**
- SUITE `| /usr/bin/grep -E 'MessageServiceTest|Failed:'` (evidência: `PASS` e `Failed: 0`)
- Review Focus: caso `testSecondManualSentAfterConcurrentTransitionIsRefused` (nome sugerido) em `MessageServiceTest` com `PASS`
- LINT nos 3 arquivos (evidência: `No syntax errors detected`)

### T-11 — ReminderGenerationService (lembretes idempotentes com consentimento)

**Camada:** backend
**Dependências:** T-02, T-03, T-06
**Paralelizável:** sim
**Complexidade:** alta
**Agente:** Sherlock

**Arquivos prováveis**
- `src/app/Core/Application/ReminderGenerationService.php`
- `src/app/Core/Application/ReminderRunSummary.php`
- `src/tests/Unit/ReminderGenerationServiceTest.php`

**Notas de implementação**

Roda como ator de sistema (agendador): não autoriza e não abre transação. Janelas no fuso recebido (o de `tenant.timezone`): confirmação e retorno = amanhã de 00:00 até 24:00 (`appointmentsBetween`); vacina = de hoje até hoje + 7 dias (`vaccinesDueBetween`); cobrança = criados antes de agora − `$receivableReminderDays` dias. Para cada candidato e cada canal (`email`, `whatsapp`): base legal = `MessagePurpose::legalBasisFor(purpose)` (confirmação e retorno = `legitimate_interest`; vacina e cobrança = `consent`); preferência `opted_out` conta `skippedOptedOut` (nas duas bases); base `consent` sem `opted_in` conta `skippedNoConsent`; sem contato (e-mail nulo, ou telefone que `WhatsAppLinkBuilder::normalizePhone` não aceita) conta `skippedNoContact`; a permissão final é `CommunicationPreference::permitsSending`. Template ativo da finalidade e canal, ou `MessageTemplateDefaults::for`; corpo renderizado com `ReminderCandidate::variables()` mais `tutor_name`; `OutboundMessage::compose` com `origin` `automation`, a base legal calculada, `created_by` nulo, `dedupe_key` = `OutboundMessage::buildDedupeKey(purpose, sourceType, sourceId, channel)`, status `queued`. `insertIfNew` que devolve null conta `duplicates`. O resumo devolve os ids de e-mail criados, para o agendador publicar (WhatsApp não vai para a fila: fica para envio manual).

**Interface**
- Produz: `new ReminderGenerationService(ReminderSourceQueryInterface $sources, CommunicationPreferenceRepositoryInterface $preferences, MessageTemplateRepositoryInterface $templates, OutboundMessageRepositoryInterface $messages, TenantContext $context, ?Closure $clock = null)`, `ReminderGenerationService::generate(DateTimeZone $timezone, int $receivableReminderDays): ReminderRunSummary`
- Produz: `ReminderRunSummary::created(): int`, `ReminderRunSummary::duplicates(): int`, `ReminderRunSummary::skippedNoConsent(): int`, `ReminderRunSummary::skippedNoContact(): int`, `ReminderRunSummary::skippedOptedOut(): int`, `ReminderRunSummary::emailMessageIds(): array`, `ReminderRunSummary::toArray(): array` (só contagens)
- Consome: T-03 `ReminderSourceQueryInterface::appointmentsBetween(DateTimeImmutable $from, DateTimeImmutable $to): array`, T-03 `ReminderSourceQueryInterface::vaccinesDueBetween(DateTimeImmutable $fromDate, DateTimeImmutable $toDate): array`, T-03 `ReminderSourceQueryInterface::openReceivablesCreatedBefore(DateTimeImmutable $before): array`, T-02 `MessagePurpose::legalBasisFor(string $purpose): string`, T-02 `CommunicationPreference::permitsSending(?CommunicationPreference $preference, string $legalBasis): bool`, T-02 `OutboundMessageRepositoryInterface::insertIfNew(OutboundMessage $message): ?OutboundMessage`, T-02 `OutboundMessage::buildDedupeKey(string $purpose, string $sourceType, int $sourceId, string $channel): string`, T-02 `MessageTemplateDefaults::for(string $purpose, string $channel): array`, T-02 `MessageTemplateRenderer::render(string $text, array $variables): string`, T-06 `FakeReminderSourceQuery`, T-06 `FakeOutboundMessageRepository`

**Teste RED**
- `src/tests/Unit/ReminderGenerationServiceTest.php` — com o relógio fixo em 2026-10-06 10:00 America/Fortaleza, `appointmentsBetween` recebe 2026-10-07 00:00 e 2026-10-08 00:00; na confirmação, o tutor sem preferência e com e-mail gera e-mail e WhatsApp com `legal_basis` `legitimate_interest`, e o tutor com e-mail `opted_out` gera só o WhatsApp e conta `skippedOptedOut`; na vacina, o tutor sem preferência gera 0 e conta `skippedNoConsent`, e o tutor com e-mail `opted_in` gera 1 e-mail com `legal_basis` `consent`; `generate` duas vezes seguidas deixa as mesmas mensagens e a segunda execução conta `duplicates` igual ao `created` da primeira; sem template ativo, o corpo vem de `MessageTemplateDefaults`; falha porque o service ainda não existe (comando: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php | /usr/bin/grep -E 'ReminderGenerationServiceTest|Failed:'`)

**Critério de aceite**
- `ReminderGenerationServiceTest` com `PASS`, cobrindo as 4 finalidades automáticas, as duas bases legais (legítimo interesse sem preferência e com opt-out; consentimento sem e com opt-in), contato ausente, dedupe na segunda execução (Review Focus) e janela D-1 no fuso do tenant; SUITE com `Failed: 0`.
- LINT imprime `No syntax errors detected` nos 3 arquivos.

**Validação**
- SUITE `| /usr/bin/grep -E 'ReminderGenerationServiceTest|Failed:'` (evidência: `PASS` e `Failed: 0`)
- Review Focus: caso `testSecondRunCreatesNoDuplicates` (nome sugerido) com `PASS`
- LINT nos 3 arquivos (evidência: `No syntax errors detected`)

### T-12 — MessageDeliveryService (entrega no worker)

**Camada:** backend
**Dependências:** T-02, T-05, T-06
**Paralelizável:** sim
**Complexidade:** alta
**Agente:** Tesla

**Arquivos prováveis**
- `src/app/Core/Application/MessageDeliveryService.php`
- `src/tests/Unit/MessageDeliveryServiceTest.php`

**Notas de implementação**

Ator de sistema (worker): não autoriza. Fluxo de `deliver($messageId, $finalAttempt)`:
1. `findById`; inexistente, ou status diferente de `queued`, ou canal diferente de `email` → `skipped` (redelivery depois do envio não reenvia).
2. `CommunicationPreference::permitsSending(preferência atual do canal, message->legalBasis())` falso → `cancel(id, null, <código>, now)` → `cancelled`, sem chamar o provedor; código `opted_out` quando a preferência é `opted_out` e `consent_missing` quando a base é `consent` sem `opted_in`. Mensagem de legítimo interesse sem preferência segue.
3. `claim(id, now)` falso → `skipped` (outro worker está com ela).
4. `provider->deliver(new OutgoingMessage(recipient, subject, body, 'msg-<id>'))`; sucesso → `markSent(id, provider->name(), providerId, now)` → `sent`.
5. `MessageDeliveryFailed`: se `$finalAttempt` → `markFailed(id, code, now)` → `failed` (sem relançar, para o job ser confirmado); senão `releaseClaim(id, code)` e relança a mesma `MessageDeliveryFailed`. Qualquer outra `Throwable` do provedor vira o código `provider_error`, com o mesmo tratamento.
Nenhum log ou exceção com destinatário ou corpo.

**Interface**
- Produz: `new MessageDeliveryService(OutboundMessageRepositoryInterface $messages, CommunicationPreferenceRepositoryInterface $preferences, MessageChannelProviderInterface $emailProvider, TenantContext $context, ?Closure $clock = null)`, `MessageDeliveryService::deliver(int $messageId, bool $finalAttempt): string` (devolve `sent`, `skipped`, `cancelled` ou `failed`; relança `MessageDeliveryFailed` em falha não final)
- Consome: T-02 `CommunicationPreference::permitsSending(?CommunicationPreference $preference, string $legalBasis): bool`, T-02 `OutboundMessageRepositoryInterface::claim(int $messageId, DateTimeImmutable $now): bool`, T-02 `OutboundMessageRepositoryInterface::markSent(int $messageId, string $provider, ?string $providerMessageId, DateTimeImmutable $sentAt): bool`, T-02 `OutboundMessageRepositoryInterface::releaseClaim(int $messageId, string $errorCode): bool`, T-02 `OutboundMessageRepositoryInterface::markFailed(int $messageId, string $errorCode, DateTimeImmutable $failedAt): bool`, T-05 `MessageChannelProviderInterface::deliver(OutgoingMessage $message): ?string`, T-05 `new MessageDeliveryFailed(string $errorCode)`, T-06 `FakeEmailProvider`, T-06 `FakeOutboundMessageRepository`

**Teste RED**
- `src/tests/Unit/MessageDeliveryServiceTest.php` — mensagem `queued` de base `consent` com opt-in vira `sent` com o `name()` do provedor gravado em `provider`; mensagem de `legitimate_interest` sem preferência vira `sent`; tutor que passou a `opted_out` deixa a mensagem (das duas bases) `cancelled` com `opted_out` e `FakeEmailProvider::deliveries()` vazio; mensagem `consent` cuja preferência não está `opted_in` fica `cancelled` com `consent_missing`; `failWith('smtp_connect')` sem tentativa final relança `MessageDeliveryFailed` e deixa `queued` com `attempt_count` 1; na tentativa final, `failed` com `last_error_code` `smtp_connect` e sem exceção; mensagem já `sent` devolve `skipped` sem nova entrega; a mensagem da exceção não contém o destinatário; falha porque o service ainda não existe (comando: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php | /usr/bin/grep -E 'MessageDeliveryServiceTest|Failed:'`)

**Critério de aceite**
- `MessageDeliveryServiceTest` com `PASS` nos casos de envio nas duas bases legais, opt-out antes do envio (Review Focus), consentimento ausente, falha com retentativa, falha final (Review Focus), redelivery e ausência de dado pessoal na exceção; SUITE com `Failed: 0`.
- LINT imprime `No syntax errors detected` nos 2 arquivos.

**Validação**
- SUITE `| /usr/bin/grep -E 'MessageDeliveryServiceTest|Failed:'` (evidência: `PASS` e `Failed: 0`)
- Review Focus: casos `testOptOutAfterQueueingCancelsWithoutDelivery` e `testFinalFailureMarksFailedWithoutPersonalData` (nomes sugeridos) com `PASS`
- LINT nos 2 arquivos (evidência: `No syntax errors detected`)

### T-13 — PendingCenterService

**Camada:** backend
**Dependências:** T-03, T-06
**Paralelizável:** sim
**Complexidade:** simples
**Agente:** Arquimedes

**Arquivos prováveis**
- `src/app/Core/Application/PendingCenterService.php`
- `src/tests/Unit/PendingCenterServiceTest.php`

**Notas de implementação**

Autoriza com `requiresUnitScope: true`, `resourceUnitId` = unidade ativa (`requireUnitId()`), `entityType: 'pending_center'`. Chama `listForUnit(unidade ativa, now, 200)`, filtra por tipo (tipo fora de `PendingItem::TYPES` é ignorado e lista todos) e por "só meus" (`responsibleSystemUserId` = `context->userId()`), e ordena por `PendingItemPriority::rank(priority)`, depois `dueAt` crescente, depois tipo e `sourceId`. `countsByType` conta sobre a lista sem filtro de tipo (os cards mostram o total por tipo).

**Interface**
- Produz: `new PendingCenterService(PendingItemQueryInterface $items, AuthorizationPolicyInterface $authorization, TenantContext $context, ?Closure $clock = null)`, `PendingCenterService::list(?string $type, bool $onlyMine, string $action): array` (lista de `PendingItem` ordenada), `PendingCenterService::countsByType(string $action): array` (mapa tipo → quantidade, com os 8 tipos presentes), `PendingCenterService::now(): DateTimeImmutable`
- Consome: T-03 `PendingItemQueryInterface::listForUnit(int $systemUnitId, DateTimeImmutable $now, int $limitPerType): array`, T-03 `PendingItemPriority::rank(string $priority): int`, T-06 `FakePendingItemQuery`

**Teste RED**
- `src/tests/Unit/PendingCenterServiceTest.php` — a administração atrasada vem antes do exame vencido, que vem antes do recebível recente; "só meus" mantém só os itens do usuário do contexto; `countsByType` traz os 8 tipos (zeros incluídos); a política negada lança `AuthorizationDenied` e a consulta não é chamada (`FakePendingItemQuery::calls()` vazio); falha porque o service ainda não existe (comando: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php | /usr/bin/grep -E 'PendingCenterServiceTest|Failed:'`)

**Critério de aceite**
- `PendingCenterServiceTest` com `PASS` e SUITE com `Failed: 0`.
- LINT imprime `No syntax errors detected` nos 2 arquivos.

**Validação**
- SUITE `| /usr/bin/grep -E 'PendingCenterServiceTest|Failed:'` (evidência: `PASS` e `Failed: 0`)
- LINT nos 2 arquivos (evidência: `No syntax errors detected`)

### T-14 — Ligação de retorno (AppointmentFollowupService + EncounterView::onScheduleFollowUp)

**Camada:** backend
**Dependências:** T-02, T-06, T-07
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Aang

**Arquivos prováveis**
- `src/app/Core/Application/AppointmentFollowupService.php`
- `src/app/control/clinic/EncounterView.php`
- `src/tests/Unit/AppointmentFollowupServiceTest.php`

**Notas de implementação**

`link` confere que o agendamento e o atendimento são do tenant (`AppointmentRepositoryInterface::findById`, `EncounterRepositoryInterface::findById`; ausente → `CrossTenantReferenceException`) e do mesmo paciente (senão `InvalidArgumentException` `Appointment <id> and encounter <id> belong to different patients`). Grava pela `AppointmentFollowupRepositoryInterface::link`. Em `EncounterView::onScheduleFollowUp` (linhas 1766-1830), logo depois de `$appointments->schedule([...], 'EncounterView::onScheduleFollowUp')` e **dentro da mesma transação**, chame `AppointmentFollowupService::link(<id do agendamento devolvido>, $id)` com a fábrica no padrão `make*Service` da própria tela (`new AppointmentFollowupRepository($context, TTransaction::get())`). Nada mais muda no `EncounterView` (nem `PLAN_ACTIONS`, nem os textos). `AppointmentService`, `Appointment` e `AppointmentRepository` ficam intocados. Mensagem nova no board como `- [T-14] i18n-domínio: <mensagem>`.

**Interface**
- Produz: `new AppointmentFollowupService(AppointmentFollowupRepositoryInterface $followups, AppointmentRepositoryInterface $appointments, EncounterRepositoryInterface $encounters, TenantContext $context)`, `AppointmentFollowupService::link(int $appointmentId, int $encounterId): void`
- Consome: T-02 `AppointmentFollowupRepositoryInterface::link(int $appointmentId, int $encounterId, int $createdBySystemUserId): void`, T-06 `FakeAppointmentFollowupRepository`, T-07 `new AppointmentFollowupRepository(TenantContext $context, PDO $connection)`

**Teste RED**
- `src/tests/Unit/AppointmentFollowupServiceTest.php` — `link` de agendamento e atendimento do mesmo paciente grava a ligação com o usuário do contexto; pacientes diferentes lançam `Appointment <id> and encounter <id> belong to different patients`; agendamento de outro tenant lança `CrossTenantReferenceException` sem gravar; falha porque o service ainda não existe (comando: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php | /usr/bin/grep -E 'AppointmentFollowupServiceTest|Failed:'`)

**Critério de aceite**
- `AppointmentFollowupServiceTest` com `PASS` e SUITE com `Failed: 0` (inclusive os testes já existentes do `EncounterView`).
- `EncounterView.php` chama `AppointmentFollowupService` só em `onScheduleFollowUp`, e o diff do arquivo fica restrito a esse método e à fábrica.
- LINT imprime `No syntax errors detected` nos 3 arquivos.

**Validação**
- SUITE `| /usr/bin/grep -E 'AppointmentFollowupServiceTest|EncounterView|Failed:'` (evidência: `PASS` e `Failed: 0`)
- `/usr/bin/grep -n "AppointmentFollowupService" src/app/control/clinic/EncounterView.php` (evidência: linhas só dentro de `onScheduleFollowUp` e da fábrica)
- `git -C /var/www/html/centralvet diff --stat <BASE da onda>..HEAD -- src/app/Core/Application/AppointmentService.php src/app/Core/Domain/Appointment.php src/app/Core/Persistence/AppointmentRepository.php` (evidência: saída vazia)
- LINT nos 3 arquivos (evidência: `No syntax errors detected`)

### T-15 — Worker e agendador (handler do job, CommunicationScheduler, worker.php, comando)

**Camada:** infra
**Dependências:** T-05, T-07, T-08, T-10, T-11, T-12
**Paralelizável:** sim
**Complexidade:** alta
**Agente:** Aang

**Arquivos prováveis**
- `src/app/Core/Communication/CommunicationJobHandler.php`
- `src/app/Core/Communication/CommunicationScheduler.php`
- `src/bin/worker.php`
- `src/bin/communication-scheduler.php`
- `src/tests/Unit/CommunicationWorkerTest.php`

**Notas de implementação**

- `CommunicationJobHandler::supports(array $payload)`: verdadeiro só para `type` = `MessageQueuePublisher::JOB_TYPE`. `handle(QueueMessage $message)`: sem `tenantId` ou com `message_id` inválido → log `communication.job.invalid` e retorna (ack, sem retentativa); `finalAttempt` = `$message->attempts + 1 >= $message->maxAttempts`; chama a fábrica `($tenantId) => MessageDeliveryService` e `deliver`; registra `communication.job.<resultado>` só com `message_id`, `tenant_id` e o código; deixa `MessageDeliveryFailed` subir (o `worker.php` faz `fail` e a fila aplica o backoff).
- `CommunicationScheduler::runOnce()`: para cada tenant de `$activeTenants()` (lista de `['id' => int, 'timezone' => string]`, lida por `SELECT id, timezone FROM tenant WHERE status = 'active'`), monta os services pela fábrica, roda `generate(new DateTimeZone(timezone), receivableDays)`, publica os `emailMessageIds()` e os ids de `listStaleQueuedEmailIds(now − 10 min, 100)`. Uma falha num tenant é registrada (`tenant_id` + classe da exceção) e não para os outros. Devolve `['tenants' => n, 'created' => n, 'duplicates' => n, 'skipped_no_consent' => n, 'skipped_no_contact' => n, 'skipped_opted_out' => n, 'published' => n, 'errors' => n]`.
- `src/bin/worker.php`: troca o `$handle` placeholder por um despacho por `type` (o handler de comunicação quando `supports`, o log atual nos demais). Acrescenta o tick do agendador a cada `COMMUNICATION_SCHEDULER_INTERVAL_SECONDS` (0 desliga; exceção capturada pelo `errorTracker`, sem derrubar o loop). PDO por job e por tick via `PdoConnectionFactory::fromEnvironment()`, `TenantContext::authenticated($tenantId, COMMUNICATION_SYSTEM_USER_ID)`, provedor via `EmailProviderFactory::fromEnvironment($logger)`, fila `RedisQueue` já criada. Mantém heartbeat, sinais e o `recoverDue` intactos.
- `src/bin/communication-scheduler.php`: `require __DIR__ . '/../vendor/autoload.php'`, execução única, imprime o JSON de contagens do `runOnce` em stdout e sai com 0 (ou 1 com `errors` > 0 ou falha de conexão, imprimindo só a classe da exceção).
- A fábrica dos services do worker é uma `Closure` injetada, para o teste rodar com fakes.

**Interface**
- Produz: `new CommunicationJobHandler(Closure $deliveryServiceFactory, LoggerInterface $logger)`, `CommunicationJobHandler::supports(array $payload): bool`, `CommunicationJobHandler::handle(QueueMessage $message): void`
- Produz: `new CommunicationScheduler(Closure $activeTenants, Closure $servicesFactory, MessageQueuePublisher $publisher, LoggerInterface $logger, int $receivableReminderDays, ?Closure $clock = null)`, `CommunicationScheduler::runOnce(): array`
- Produz: comando `php bin/communication-scheduler.php` (JSON com `tenants`, `created`, `duplicates`, `skipped_no_consent`, `skipped_no_contact`, `skipped_opted_out`, `published`, `errors`)
- Consome: T-10 `MessageQueuePublisher::JOB_TYPE = 'communication.message.send'`, T-10 `MessageQueuePublisher::publish(int $tenantId, int $messageId): string`, T-11 `ReminderGenerationService::generate(DateTimeZone $timezone, int $receivableReminderDays): ReminderRunSummary`, T-11 `ReminderRunSummary::emailMessageIds(): array`, T-12 `MessageDeliveryService::deliver(int $messageId, bool $finalAttempt): string`, T-07 `PdoConnectionFactory::fromEnvironment(): PDO`, T-05 `EmailProviderFactory::fromEnvironment(LoggerInterface $logger): MessageChannelProviderInterface`, T-08 `new ReminderSourceQuery(TenantContext $context, PDO $connection)`

**Teste RED**
- `src/tests/Unit/CommunicationWorkerTest.php` — `supports` aceita só `communication.message.send`; `handle` com `attempts` 4 e `maxAttempts` 5 chama `deliver` com `finalAttempt` verdadeiro; job sem `tenantId` não chama a fábrica; `MessageDeliveryFailed` sobe; `runOnce` com dois tenants publica no `FakeQueue` os ids de e-mail criados e os presos, e uma exceção no primeiro tenant não impede o segundo (`errors` 1); falha porque as classes ainda não existem (comando: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php | /usr/bin/grep -E 'CommunicationWorkerTest|RedisQueueIntegrationTest|Failed:'`)

**Critério de aceite**
- `CommunicationWorkerTest` e `RedisQueueIntegrationTest` com `PASS`; SUITE com `Failed: 0`.
- Depois do rebuild do orquestrador (gate da Onda 4): `docker compose exec -T worker php bin/communication-scheduler.php` sai com 0 e imprime JSON com as 8 chaves; o heartbeat do worker continua sendo atualizado (`docker compose ps worker` mostra `healthy`, ou `running` sem reinício, na ausência de healthcheck).
- O log do worker do gate não contém `example.invalid` nem o telefone de teste.
- LINT imprime `No syntax errors detected` nos 5 arquivos.

**Validação**
- SUITE `| /usr/bin/grep -E 'CommunicationWorkerTest|RedisQueueIntegrationTest|Failed:'` (evidência: `PASS` e `Failed: 0`)
- LINT nos 5 arquivos (evidência: `No syntax errors detected`)
- Gate da Onda 4 (orquestrador rebuilda): `docker compose exec -T worker php bin/communication-scheduler.php; echo "exit=$?"` (evidência: JSON com as 8 chaves e `exit=0`)
- Gate da Onda 4: `docker compose logs --since 10m worker | /usr/bin/grep -cE "example\.invalid|85999990000"` (evidência: `0`)

### T-16 — Telas de templates (lista e formulário)

**Camada:** frontend
**Dependências:** T-07, T-09
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Saitama

**Arquivos prováveis**
- `src/app/control/clinic/MessageTemplateList.php`
- `src/app/control/clinic/MessageTemplateForm.php`
- `src/tests/Integration/MessageTemplateScreensIntegrationTest.php`

**Notas de implementação**

Modelo: `SurgeryRoomList.php`/`SurgeryRoomForm.php` da 6B (fábrica `make*Service` com `RbacAuthorizationService` + `PdoAuditLogWriter`, `resolveTenantContext`, 3 catches, `CvPage::header`). Lista: nome, finalidade, canal e status em `CvBadge`; ações Editar e Ativar/Desativar (`static=1`, `cv-touch-target`); estado vazio com o botão "Novo template". Formulário: finalidade (combo de `MessagePurpose::all()`), canal, nome, assunto (obrigatório no e-mail), corpo (textarea por POST) e um bloco de ajuda que lista os placeholders de `MessageTemplateRenderer::PLACEHOLDERS` entre `{{ }}`. Todo nome ou texto vindo do banco passa por `CvFormat::e`. Ações `private const ACTION_X = 'Classe::método'`. Textos em `_t('<en>')`, cada chave nova anotada no board.

**Interface**
- Produz: rotas `index.php?class=MessageTemplateList` e `index.php?class=MessageTemplateForm` (`&id=<template_id>` para editar); ações `MessageTemplateForm::onSave` e `MessageTemplateList::onToggle`
- Consome: T-09 `MessageTemplateService::save(array $data, string $action): MessageTemplate`, T-09 `MessageTemplateService::setActive(int $templateId, bool $active, string $action): MessageTemplate`, T-09 `MessageTemplateService::list(string $action): array`, T-07 `new MessageTemplateRepository(TenantContext $context, PDO $connection)`

**Teste RED**
- `src/tests/Integration/MessageTemplateScreensIntegrationTest.php` — em subprocesso com `require "init.php"` (padrão de `BedFormIntegrationTest`): o formulário tem os campos `purpose`, `channel`, `name`, `subject`, `body_text`; as ações declaradas são `MessageTemplateForm::onSave` e `MessageTemplateList::onToggle`; a ajuda lista os 9 placeholders; um nome `<script>` aparece escapado na lista; falha porque as telas ainda não existem (comando: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php | /usr/bin/grep -E 'MessageTemplateScreensIntegrationTest|ControllerRawExceptionMessageTest|Failed:'`)

**Critério de aceite**
- `MessageTemplateScreensIntegrationTest` e `ControllerRawExceptionMessageTest` com `PASS`; SUITE com `Failed: 0`.
- No gate da Onda 4, as duas telas abrem em `http://127.0.0.1:8081` com console com 0 mensagens de nível error e rede sem resposta ≥ 400.
- LINT imprime `No syntax errors detected` nos 3 arquivos.

**Validação**
- SUITE `| /usr/bin/grep -E 'MessageTemplateScreensIntegrationTest|ControllerRawExceptionMessageTest|Failed:'` (evidência: `PASS` e `Failed: 0`)
- `/usr/bin/grep -c "getMessage()" src/app/control/clinic/MessageTemplateList.php src/app/control/clinic/MessageTemplateForm.php` (evidência: `0` nos dois)
- LINT nos 3 arquivos (evidência: `No syntax errors detected`)

### T-17 — Histórico e ficha da mensagem

**Camada:** frontend
**Dependências:** T-05, T-07, T-10
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Kratos

**Arquivos prováveis**
- `src/app/control/clinic/CommunicationMessageList.php`
- `src/app/control/clinic/CommunicationMessageView.php`
- `src/tests/Integration/CommunicationMessageScreensIntegrationTest.php`

**Notas de implementação**

Histórico: filtros por POST (status, canal, finalidade), unidade ativa, colunas data, tutor, finalidade, canal, status em `CvBadge` e destinatário **mascarado** (e-mail `ab***@dominio`, telefone com os 4 últimos dígitos), link para a ficha; estado vazio. Ficha (`&id=`): status, datas, finalidade, origem, base legal (`legal_basis` traduzida), destinatário completo, assunto e corpo (escapados e com `nl2br` depois do escape), `last_error_code` traduzido. Ações por estado, todas com `cv-touch-target`:
- WhatsApp `queued`: botão "Abrir WhatsApp" (link de `MessageService::whatsAppLink`, `target="_blank" rel="noopener noreferrer"`), "Marcar como enviado" e "Descartar".
- E-mail `queued`: "Descartar".
- `failed`: "Reenviar" (`retry`; depois do commit, `MessageQueuePublisher::publish` com `RedisQueue::fromEnvironment()`; falha no push só registra `error_log` com o id e mostra "Mensagem reenfileirada").
Confirmações via `TQuestion`, com só o id no parâmetro. Mensagem de domínio pelo `CvFormat::userError`. Modelo de controller: `SurgeryView.php` (6B). Textos em `_t('<en>')`, chaves no board.

**Interface**
- Produz: rotas `index.php?class=CommunicationMessageList` e `index.php?class=CommunicationMessageView&id=<message_id>`; ações `CommunicationMessageView::onMarkSent`, `CommunicationMessageView::onCancel`, `CommunicationMessageView::onRetry`
- Consome: T-10 `MessageService::markManualSent(int $messageId, string $action): void`, T-10 `MessageService::cancel(int $messageId, string $action): void`, T-10 `MessageService::retry(int $messageId, string $action): OutboundMessage`, T-10 `MessageService::whatsAppLink(int $messageId, string $action): string`, T-10 `MessageService::listForUnit(array $filters, string $action): array`, T-10 `MessageQueuePublisher::publish(int $tenantId, int $messageId): string`, T-07 `new OutboundMessageRepository(TenantContext $context, PDO $connection)`

**Teste RED**
- `src/tests/Integration/CommunicationMessageScreensIntegrationTest.php` — em subprocesso com `init.php`: a ficha declara `onMarkSent`, `onCancel` e `onRetry`; o link do WhatsApp sai com `rel="noopener noreferrer"`; um corpo com `<script>` aparece como `&lt;script&gt;`; o histórico mascara `fulano@example.invalid` como `fu***@example.invalid`; falha porque as telas ainda não existem (comando: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php | /usr/bin/grep -E 'CommunicationMessageScreensIntegrationTest|ControllerRawExceptionMessageTest|Failed:'`)

**Critério de aceite**
- `CommunicationMessageScreensIntegrationTest` e `ControllerRawExceptionMessageTest` com `PASS`; SUITE com `Failed: 0`.
- No gate da Onda 4, as duas telas abrem com console com 0 mensagens de nível error e rede sem resposta ≥ 400; a URL das ações leva só `id`.
- LINT imprime `No syntax errors detected` nos 3 arquivos.

**Validação**
- SUITE `| /usr/bin/grep -E 'CommunicationMessageScreensIntegrationTest|ControllerRawExceptionMessageTest|Failed:'` (evidência: `PASS` e `Failed: 0`)
- Review Focus (corpo e nome com `<script>` escapados na ficha e no histórico): caso de escape em `CommunicationMessageScreensIntegrationTest` com `PASS`
- LINT nos 3 arquivos (evidência: `No syntax errors detected`)

### T-18 — Compor mensagem e preferências do tutor

**Camada:** frontend
**Dependências:** T-07, T-09, T-10
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Naruto

**Arquivos prováveis**
- `src/app/control/clinic/CommunicationComposeForm.php`
- `src/app/control/clinic/TutorCommunicationForm.php`
- `src/tests/Integration/CommunicationTutorScreensIntegrationTest.php`

**Notas de implementação**

`TutorCommunicationForm&tutor_id=`: cabeçalho com o nome do tutor (`CvFormat::e`); para cada canal, o status atual (`opted_in`, `opted_out` ou `not_recorded`), radio Aceita/Não aceita e origem do consentimento (combo de `CommunicationPreference::SOURCES`); indica se o tutor tem e-mail cadastrado (sim/não, sem mostrar o endereço) e se o telefone serve para WhatsApp; salvar por POST chama `record` para cada canal alterado. `CommunicationComposeForm&tutor_id=` (opcional `&patient_id=`): canal, finalidade, template (combo dos templates ativos do canal; `onChangeTemplate` preenche assunto e corpo via `renderTemplate`), assunto, corpo (POST); salvar chama `compose` no `TTransaction`; depois do commit, e-mail é publicado (`MessageQueuePublisher`, falha só em `error_log` com o id); redireciona para `CommunicationMessageView&id=`. Recusa por base legal (sem opt-in em finalidade de consentimento, ou opt-out em qualquer finalidade) mostra a mensagem traduzida e um link para `TutorCommunicationForm`. A tela de preferências explica, em uma linha, que confirmação e retorno saem por legítimo interesse salvo "Não aceita", e as demais finalidades só com "Aceita". Modelos de controller: `SurgeryConsentForm.php` (POST de texto) e `SurgeryScheduleForm.php` (`onChange*`). Textos em `_t('<en>')`, chaves no board.

**Interface**
- Produz: rotas `index.php?class=TutorCommunicationForm&tutor_id=<id>` e `index.php?class=CommunicationComposeForm&tutor_id=<id>` (opcional `&patient_id=<id>`); ações `TutorCommunicationForm::onSave`, `CommunicationComposeForm::onSave`, `CommunicationComposeForm::onChangeTemplate`
- Consome: T-09 `CommunicationPreferenceService::preferencesFor(int $tutorId, string $action): array`, T-09 `CommunicationPreferenceService::record(int $tutorId, string $channel, string $status, string $consentSource, string $action): CommunicationPreference`, T-10 `MessageService::compose(array $data, string $action): OutboundMessage`, T-10 `MessageService::renderTemplate(int $templateId, int $tutorId, ?int $patientId, string $action): array`, T-10 `MessageQueuePublisher::publish(int $tenantId, int $messageId): string`, T-07 `new CommunicationPreferenceRepository(TenantContext $context, PDO $connection)`

**Teste RED**
- `src/tests/Integration/CommunicationTutorScreensIntegrationTest.php` — em subprocesso com `init.php`: `TutorCommunicationForm` tem os campos `email_status`, `email_source`, `whatsapp_status`, `whatsapp_source` e não imprime o e-mail do tutor; `CommunicationComposeForm` tem `channel`, `purpose`, `template_id`, `subject`, `body_text` e ignora `body_text` vindo da query string; falha porque as telas ainda não existem (comando: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php | /usr/bin/grep -E 'CommunicationTutorScreensIntegrationTest|ControllerRawExceptionMessageTest|Failed:'`)

**Critério de aceite**
- `CommunicationTutorScreensIntegrationTest` e `ControllerRawExceptionMessageTest` com `PASS`; SUITE com `Failed: 0`.
- No gate da Onda 4, as duas telas abrem com `tutor_id` de um tutor `F7A teste`, com console com 0 mensagens de nível error e rede sem resposta ≥ 400.
- LINT imprime `No syntax errors detected` nos 3 arquivos.

**Validação**
- SUITE `| /usr/bin/grep -E 'CommunicationTutorScreensIntegrationTest|ControllerRawExceptionMessageTest|Failed:'` (evidência: `PASS` e `Failed: 0`)
- LINT nos 3 arquivos (evidência: `No syntax errors detected`)

### T-19 — Central de Pendências (tela)

**Camada:** frontend
**Dependências:** T-08, T-13
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Batman

**Arquivos prováveis**
- `src/app/control/clinic/PendingCenter.php`
- `src/app/templates/adminbs5/cv-components.css`
- `src/tests/Integration/PendingCenterIntegrationTest.php`

**Notas de implementação**

Modelos: `HospitalizationBoard.php` e `FinancialOverview.php` (cards `CvKpiCard`) e `SurgeryList.php` (`cv-touch-target`). Topo: um card por tipo com a contagem de `countsByType`, e o clique filtra (`&type=`). Filtro "Só meus" (`&mine=1`). Lista: prioridade em `CvBadge` (urgent/high/normal/low com tons distintos), tipo traduzido por `_t`, paciente e assunto escapados com `CvFormat::e`, prazo (`d/m/Y H:i`), status (vencida/aberta) e responsável (nome por `TenantUserDirectoryInterface` ou pelo mesmo recurso que `SurgeryList` usa; sem responsável, "—"), e o botão "Resolver" com `href` = `PendingItem::deepLinkUrl()` escapado e `generator="adianti"`. Estado vazio "Nenhuma pendência". `cv-components.css` ganha só uma seção `cv-pending-*` no fim do arquivo (layout dos cards e da lista no tablet). Textos em `_t('<en>')`, chaves no board.

**Interface**
- Produz: rota `index.php?class=PendingCenter` (filtros `&type=<tipo>` e `&mine=1`); classes CSS `cv-pending-*`
- Consome: T-13 `PendingCenterService::list(?string $type, bool $onlyMine, string $action): array`, T-13 `PendingCenterService::countsByType(string $action): array`, T-08 `new PendingItemQuery(TenantContext $context, PDO $connection)`

**Teste RED**
- `src/tests/Integration/PendingCenterIntegrationTest.php` — em subprocesso com `init.php` e uma lista de `PendingItem` injetada: o paciente `<script>x</script>` aparece como `&lt;script&gt;`; cada linha tem um `href` igual ao `deepLinkUrl()` do item e a classe `cv-touch-target`; com a lista vazia aparece o estado vazio; um `type` desconhecido na URL lista todos; falha porque a tela ainda não existe (comando: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php | /usr/bin/grep -E 'PendingCenterIntegrationTest|ControllerRawExceptionMessageTest|Failed:'`)

**Critério de aceite**
- `PendingCenterIntegrationTest` e `ControllerRawExceptionMessageTest` com `PASS`; SUITE com `Failed: 0`.
- No gate da Onda 4, a tela abre em desktop com console com 0 mensagens de nível error e rede sem resposta ≥ 400, e o botão "Resolver" mede ≥ 44 px de altura.
- `cv-components.css`: o diff só acrescenta linhas da seção `cv-pending-*`.
- LINT imprime `No syntax errors detected` nos 2 PHP.

**Validação**
- SUITE `| /usr/bin/grep -E 'PendingCenterIntegrationTest|ControllerRawExceptionMessageTest|Failed:'` (evidência: `PASS` e `Failed: 0`)
- Review Focus (nome com `<script>` escapado e deep-link só com ids): caso de escape e de `href` em `PendingCenterIntegrationTest` com `PASS`
- `git -C /var/www/html/centralvet diff <BASE da onda>..HEAD -- src/app/templates/adminbs5/cv-components.css | /usr/bin/grep -E '^-[^-]' | wc -l` (evidência: `0`)
- LINT nos 2 PHP (evidência: `No syntax errors detected`)

### T-20 — Navegação (menu, CvNav, ações no TutorForm)

**Camada:** frontend
**Dependências:** T-04
**Paralelizável:** sim
**Complexidade:** simples
**Agente:** Jaspion

**Arquivos prováveis**
- `src/menu.xml`
- `src/app/lib/widget/CvNav.php`
- `src/app/control/clinic/TutorForm.php`
- `src/tests/Integration/CommunicationNavigationIntegrationTest.php`

**Notas de implementação**

`menu.xml`: novo item `_t{Pending items}` (ícone `fas:list-check fa-fw`, ação `PendingCenter`) logo depois de `Dashboard`. O item `_t{CRM / Communication}` mantém o rótulo e o ícone e troca a ação `CvShellController#method=onComingSoon#item=crm` por um submenu com `_t{Messages}` → `CommunicationMessageList` e `_t{Message templates}` → `MessageTemplateList` (estrutura de submenu igual à de `_t{Settings}`). `CvNav.php`: grupo `communication` com `messages` → `['Messages', 'index.php?class=CommunicationMessageList']` e `templates` → `['Message templates', 'index.php?class=MessageTemplateList']`. `TutorForm.php`: com tutor existente (linha 80, `CvPage::header(_t('Tutor'), null, [...])`), duas ações a mais no cabeçalho: "Comunicação" → `TutorCommunicationForm&tutor_id=<id>` e "Enviar mensagem" → `CommunicationComposeForm&tutor_id=<id>`, com `CvFormat::e` no href e `cv-touch-target`. Nada mais muda no `TutorForm`. Rode também `HospitalizationNavigationIntegrationTest` e `SurgeryNavigationIntegrationTest`, que conferem a ordem do menu. Textos em `_t('<en>')`, chaves no board.

**Interface**
- Produz: item de menu `_t{Pending items}` → `PendingCenter`; submenu de `_t{CRM / Communication}` com `CommunicationMessageList` e `MessageTemplateList`; grupo `CvNav` `communication`; ações no cabeçalho do `TutorForm` para `TutorCommunicationForm&tutor_id=` e `CommunicationComposeForm&tutor_id=`
- Consome: T-04 `CommunicationMessageList` (`Central Vet - Communication Message List`), T-04 `MessageTemplateList` (`Central Vet - Message Template List`), T-04 `PendingCenter` (`Central Vet - Pending Center`)

**Teste RED**
- `src/tests/Integration/CommunicationNavigationIntegrationTest.php` — o `menu.xml` tem `PendingCenter` logo depois de `Dashboard` e não tem mais `item=crm`; o submenu de CRM tem `CommunicationMessageList` e `MessageTemplateList`; `CvNav` tem o grupo `communication`; o `TutorForm.php` contém `TutorCommunicationForm&tutor_id=` e `CommunicationComposeForm&tutor_id=`; falha porque a navegação ainda não existe (comando: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php | /usr/bin/grep -E 'NavigationIntegrationTest|Failed:'`)

**Critério de aceite**
- `CommunicationNavigationIntegrationTest`, `HospitalizationNavigationIntegrationTest` e `SurgeryNavigationIntegrationTest` com `PASS`; SUITE com `Failed: 0`.
- `xmllint --noout src/menu.xml` sai com código 0 (ou o teste de parse do XML passa, se `xmllint` faltar no host).
- LINT imprime `No syntax errors detected` em `CvNav.php`, `TutorForm.php` e no teste.

**Validação**
- SUITE `| /usr/bin/grep -E 'NavigationIntegrationTest|Failed:'` (evidência: 3 classes com `PASS` e `Failed: 0`)
- `/usr/bin/grep -c "item=crm" src/menu.xml` (evidência: `0`)
- LINT nos 3 PHP (evidência: `No syntax errors detected`)

### T-21 — i18n pt/en das telas e mensagens de domínio

**Camada:** frontend
**Dependências:** T-15, T-16, T-17, T-18, T-19, T-20
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Levi

**Arquivos prováveis**
- `src/app/config/translations.json`
- `src/app/Core/Presentation/UserMessage.php`
- `src/tests/Unit/UserMessageTest.php`

**Notas de implementação**

Fonte das chaves: as linhas `i18n:` e `i18n-domínio:` do `board.md` (T-02, T-09, T-10, T-14 a T-20) e um `grep` de `_t('...')` nos 7 controllers novos, no `TutorForm.php` e no `menu.xml`. `translations.json` (1122 entradas) mantém a ordem por `en` sem caixa e não duplica chaves que já existem (`Messages`, `Templates`, `Consent`, `WhatsApp`, `Pending` já existem). Português do Brasil com os termos: Pendências, Central de Pendências, Mensagens, Modelos de mensagem, Consentimento, Aceita/Não aceita, Na fila, Enviada, Falhou, Enviada manualmente, Descartada, Marcar como enviado, Abrir WhatsApp, Reenviar, Resolver, Só meus, e os 8 tipos de pendência. `UserMessage`: as mensagens de domínio com id (`Message <id> is no longer awaiting manual send`, `Tutor <id> has not opted in to <channel> messages`, `Tutor <id> has opted out of <channel> messages`, `Appointment <id> and encounter <id> belong to different patients` etc.) entram em `PATTERNS`, as fixas em `STATIC`, e as mensagens em pt não expõem id interno quando o padrão das fases anteriores não expõe. Os totais travados em `UserMessageTest.php:122-123` (47/64) passam para os novos números, com um caso de teste por mensagem nova e o teste de `_t` das telas cobrindo os 7 controllers novos. Códigos de erro de envio (`smtp_connect`, `smtp_auth`, `smtp_recipient_rejected`, `smtp_error`, `provider_error`, `opted_out`, `consent_missing`, `discarded`) e as bases legais (`legitimate_interest` → Legítimo interesse, `consent` → Consentimento) ganham rótulo pt na ficha via chave de tradução.

**Interface**
- Produz: nada
- Consome: nada

**Teste RED**
- `src/tests/Unit/UserMessageTest.php` — os casos novos (cada mensagem de domínio da 7A traduzida para pt e os `_t` dos 7 controllers novos presentes em `translations.json`) falham antes das chaves e padrões existirem (comando: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php | /usr/bin/grep -E 'UserMessageTest|Failed:'`)

**Critério de aceite**
- `UserMessageTest` com `PASS` e os novos totais de `STATIC`/`PATTERNS`; SUITE com `Failed: 0`.
- `python3 -c "import json;d=json.load(open('src/app/config/translations.json'));print(len(d))"` (ou a estrutura equivalente do arquivo) imprime mais que 1122, e `python3 -m json.tool` sai com 0.
- Gate da Onda 5: fetch autenticado em pt das 7 telas sem `Message not found`.

**Validação**
- SUITE `| /usr/bin/grep -E 'UserMessageTest|Failed:'` (evidência: `PASS` e `Failed: 0`)
- `python3 -m json.tool src/app/config/translations.json > /dev/null; echo $?` (evidência: `0`)
- Gate da Onda 5 (validador, sessão admin do orquestrador): HTML das 7 rotas em pt com `/usr/bin/grep -c "Message not found"` = `0`
- LINT em `UserMessage.php` e `UserMessageTest.php` (evidência: `No syntax errors detected`)

### T-22 — Runbook de comunicação, índice e passo da 0012 na hospedagem 5.7

**Camada:** docs
**Dependências:** T-01, T-04, T-05, T-15
**Paralelizável:** sim
**Complexidade:** simples
**Agente:** Gandalf

**Arquivos prováveis**
- `docs/runbooks/comunicacao.md`
- `docs/runbooks/README.md`
- `docs/runbooks/shared-hosting-mysql57.md`

**Notas de implementação**

Modelo: `docs/runbooks/cirurgia.md` (6B). `comunicacao.md`: visão geral (consentimento, templates, canais, fila, agendador, Central de Pendências); variáveis de ambiente da T-05 com padrões e efeito (sem segredos reais; como configurar SMTP sem escrever credenciais no repositório); status da mensagem e transições; dedupe e idempotência; retentativa (5 tentativas, backoff da `RedisQueue`, dead-letter); como rodar o agendador (`docker compose exec -T worker php bin/communication-scheduler.php`) e como desligar (`COMMUNICATION_SCHEDULER_INTERVAL_SECONDS=0`); LGPD (base legal por finalidade: confirmação e retorno por legítimo interesse salvo opt-out; vacina, cobrança e manuais por consentimento; opt-out sempre respeitado, inclusive pelo worker; coluna `legal_basis`; onde ficam e-mail e telefone; o que nunca vai para log); fontes e regras de prioridade da Central de Pendências e a justificativa da ausência de tabela; schema da 0012; os 7 programas e os grupos 1, 2, 4 e 5; limites conhecidos (retornos anteriores à 7A não reconhecidos; recebível sem vencimento). `README.md`: entrada no índice (linhas 15-16). `shared-hosting-mysql57.md`: seção da 0012 no padrão da 0011 (linhas 164-190), com prefixo `16-`, e cron do comando do agendador para a hospedagem sem worker contínuo (o e-mail só sai com o worker; sem ele, documentar o driver `log` ou um worker por cron).

**Interface**
- Produz: nada
- Consome: nada

**Teste RED**
- sem teste: documentação

**Critério de aceite**
- `docs/runbooks/comunicacao.md` cita as 12 variáveis da T-05, as 4 tabelas da 0012, os 7 programas, os 5 status e o comando `bin/communication-scheduler.php`.
- `docs/runbooks/README.md` lista `comunicacao.md`; `shared-hosting-mysql57.md` tem a seção da 0012 com o prefixo `16-`.
- Nenhum valor de senha diferente de vazio ou de placeholder no runbook.

**Validação**
- `/usr/bin/grep -cE "COMMUNICATION_EMAIL_DRIVER|SMTP_HOST|SMTP_PORT|SMTP_USERNAME|SMTP_PASSWORD|SMTP_ENCRYPTION|SMTP_FROM_ADDRESS|SMTP_FROM_NAME|SMTP_TIMEOUT_SECONDS|COMMUNICATION_SCHEDULER_INTERVAL_SECONDS|COMMUNICATION_RECEIVABLE_REMINDER_DAYS|COMMUNICATION_SYSTEM_USER_ID" docs/runbooks/comunicacao.md` (evidência: número ≥ 12)
- `/usr/bin/grep -c "comunicacao.md" docs/runbooks/README.md` (evidência: `1` ou mais)
- `/usr/bin/grep -c "16-20261006_0012_phase7a_communication" docs/runbooks/shared-hosting-mysql57.md` (evidência: `1` ou mais)

### T-23 — Validação final ponta a ponta e SQL de limpeza `F7A teste`

**Camada:** qa
**Dependências:** T-21, T-22
**Paralelizável:** não
**Complexidade:** média
**Agente:** Spock

**Arquivos prováveis**
- `.claude/tasks/mar-20261006-0842-fase-7a-comunicacao/sql/T-23-cleanup.sql`

**Notas de implementação**

Prepara, sem executar, o SQL de limpeza, no modelo de `.claude/tasks/mar-20261005-2234-fase-6b-cirurgia/sql/T-21-cleanup.sql` (tabelas temporárias de ids, contagens antes, `START TRANSACTION`, DELETEs com `WHERE` explícito pelo prefixo `F7A teste` e pelos ids derivados, na ordem das FKs, SELECTs de conferência e `-- COMMIT;` no fim). Cobre `communication_message` dos tutores `F7A teste` → `communication_preference` → `message_template` com nome `F7A teste%` → `appointment_followup` → agendamentos, atendimentos, contas, recebíveis, vacinações, pacientes e tutores `F7A teste` criados nos gates. Registra no cabeçalho os ids reais vistos antes do ensaio (sugestão da revisão da 6B) e conta antes/depois cada tabela tocada por `patient_id`. `audit_log` fica fora (documentado). Os gates usam o driver `log` e o e-mail `f7a.teste@example.invalid`.

O validador executa o gate da Onda 6 em dois disparos:
- **Roteiro A** (desktop 1366×768 e tablet 820×1180): tutor `F7A teste Tutor` com e-mail e telefone → preferências (e-mail e WhatsApp aceitos, origem `in_person`; um segundo tutor `F7A teste Tutor 2` sem preferência recebe a confirmação por legítimo interesse, e com e-mail "Não aceita" não recebe por e-mail) → template `F7A teste confirmação` (e-mail) → compor e-mail manual → ficha `queued` → `docker compose exec -T worker` processa (driver `log`) → ficha `sent` → compor WhatsApp → "Abrir WhatsApp" (link `wa.me` com o texto) → "Marcar como enviado" → `manual` → atendimento `F7A teste` com retorno agendado → `bin/communication-scheduler.php` com o retorno no dia seguinte → mensagem `return_reminder` criada → Central de Pendências com o WhatsApp aguardando envio e o retorno, e cada "Resolver" abrindo a tela de destino.
- **Roteiro B**: os 5 itens de Review Focus de `plan.md` (agendador 2x; opt-out depois de enfileirar; falha SMTP, simulada pelo `MessageDeliveryServiceTest`, porque o gate não troca o driver; duplo "Marcar como enviado" em duas abas; nome e corpo com `<script>`), mais a permissão negada (a central de outra unidade; sem segunda unidade para o admin, vale `PendingCenterServiceTest` e o validador registra em Pendências).

**Interface**
- Produz: `sql/T-23-cleanup.sql` (só o orquestrador executa, com aprovação)
- Consome: nada

**Teste RED**
- sem teste: validação final e SQL de limpeza preparado; a prova é o gate E2E da Onda 6

**Critério de aceite**
- Os DELETEs de `T-23-cleanup.sql` têm `WHERE` com `F7A teste` ou ids vindos de SELECT por esse prefixo (`/usr/bin/grep -ciE "delete from [a-z_]+ *;"` imprime `0`), e o arquivo termina com `-- COMMIT;`.
- Roteiro A aprovado em desktop e tablet, e roteiro B com a reação descrita em plan.md para cada item de Review Focus.
- SUITE com `Failed: 0`; nenhum erro novo em relação a `baseline/php-lint.txt`; PYTEST57 com `Ran 8 tests` ou mais e nenhuma falha.
- `docker compose logs worker` do período do gate sem `example.invalid` e sem o telefone de teste.
- Contagens de `appointment`, `vaccination`, `receivable`, `tutor`, `encounter` e `system_program` só cresceram desde a BASE da Onda 1.

**Validação**
- `SUITE | /usr/bin/grep -E 'Total|Failed:'` (evidência: `Failed: 0`)
- LINT nos PHP tocados pela branch (`git diff --name-only <BASE da onda 1>..HEAD -- '*.php'`) (evidência: `No syntax errors detected` em todos)
- `python3 scripts/test-prepare-mysql57.py` (evidência: `Ran 8 tests` ou mais, sem `FAILED`)
- `docker compose logs --since 2h worker | /usr/bin/grep -cE "example\.invalid|85999990000"` (evidência: `0`)
- Tabela do gate `Tela|Fluxos|Console|Rede|Erro na tela|Veredito|Task dona` com uma linha por tela das Ondas 4 e 5 (evidência: veredito aprovado em todas)
- Contagens só leitura antes/depois (evidência: nenhuma diminuiu)

## Legenda

- [ ] pendente
- [~] em andamento
- [x] concluído com evidência
- [!] bloqueado
