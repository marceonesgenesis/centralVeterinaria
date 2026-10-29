# Tasks

| ID | Camada | Descrição | Dependências | Paralelizável | Complexidade | Agente | Status |
|---|---|---|---|---|---|---|---|
| T-01 | database | Migration 0014 (`feature_flag`, `ai_tenant_setting`, `ai_interaction`), verify e provision do banco de teste | — | sim | alta | Darwin | [x] |
| T-02 | backend | Domain e contratos de IA (finalidade, interação, configuração, termo, uso, pedido/resultado, preços, exceções, gateway, repositórios, contexto clínico, `AiAssistantInterface`) | — | sim | alta | Platão | [x] |
| T-03 | infra | `AiConfig` por env, variáveis em `.env.example`/`docker-compose.yml` e mascaramento da chave em `JsonLogger`/`AuditRedactor` | — | sim | simples | Tesla | [x] |
| T-04 | infra | Programas RBAC `AiSettingsForm` e `AiInteractionList` para o grupo 1 (seed + DML, verify e rollback preparados) | — | sim | simples | Jaspion | [x] |
| T-05 | shared | Fakes de gateway, interação, configuração, feature flag e contexto clínico | T-02 | sim | média | Platão | [x] |
| T-06 | backend | Repositórios PDO de interação (uso mensal, decisão condicional), configuração e feature flag | T-01, T-02 | sim | alta | Athena | [x] |
| T-07 | backend | Gateways: Anthropic Messages via curl, stub, não configurado e fábrica | T-02, T-03 | sim | alta | Naruto | [x] |
| T-08 | backend | Minimização de PII e catálogo de prompts versionados | T-02 | sim | média | Spock | [x] |
| T-09 | backend | Consulta PDO do contexto clínico autorizado (atendimento, histórico, exame) | T-01, T-02 | sim | média | Arquimedes | [x] |
| T-10 | frontend | JS de ditado (Web Speech API) e CSS do painel de IA | — | sim | simples | Tesla | [x] |
| T-11 | backend | `AiAssistService` (3 casos de uso, decisão, disponibilidade, cota, auditoria) e `forContext` | T-02, T-03, T-05, T-06, T-07, T-08, T-09 | sim | alta | Batman | [x] |
| T-12 | backend | `AiSettingsService` (ligar/desligar, termo, cota, uso e custo do mês) e `forContext` | T-02, T-05, T-06 | sim | média | Saitama | [x] |
| T-13 | frontend | Telas `AiSettingsForm` e `AiInteractionList` | T-04, T-12 | sim | média | Kratos | [x] |
| T-14 | frontend | Atendimento: resumo do paciente e anamnese com ditado, revisão humana; remove a porta antiga e o no-op | T-02, T-10, T-11 | sim | alta | Aang | [x] |
| T-15 | frontend | Resultado de exame: resumo para revisão, original intacto | T-02, T-10, T-11 | sim | média | Darwin | [x] |
| T-16 | frontend | Navegação: itens de IA no menu Settings | T-04 | sim | simples | Jaspion | [x] |
| T-17 | frontend | i18n pt/en das telas e mensagens de domínio da 8A | T-11, T-12, T-13, T-14, T-15, T-16 | sim | média | Levi | [x] |
| T-18 | docs | Runbook de IA, ADR 0004, índice e passo da 0014/env na hospedagem 5.7 | T-01, T-03, T-04, T-07, T-11 | sim | simples | Gandalf | [x] |
| T-19 | qa | Validação final ponta a ponta e SQL de limpeza `F8A teste` | T-17, T-18 | não | média | Sherlock | [x] |

## Detalhamento

### T-01 — Migration 0014 (`feature_flag`, `ai_tenant_setting`, `ai_interaction`), verify e provision do banco de teste

**Camada:** database
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** alta
**Agente:** Darwin

**Arquivos prováveis**
- `src/app/database/migrations/20261007_0014_phase8a_ai_gateway.sql`
- `src/app/database/migrations/20261007_0014_phase8a_ai_gateway.verify.sql`
- `scripts/test-db/provision.sh`

**Notas de implementação**
- **Modelo**: `src/app/database/migrations/20261006_0013_phase7b_documents.sql` e o `.verify.sql` dela.
- **Cabeçalho**: `Migration: 20261007_0014_phase8a_ai_gateway`; `Status: PREPARED ONLY. Do not execute without a fresh backup and explicit SQL approval.`; `Target: MySQL 8.0.x, database centralvet, after 20261006_0013_phase7b_documents`; seções Effects (3 tabelas, 8 CHECKs, 2 UNIQUEs, FKs), Risk e Rollback (`docs/runbooks/migration-rollback.md`).
- **Última instrução**: `INSERT INTO schema_migrations (version, checksum)` com o placeholder de 64 zeros, como na 0013.
- **Regras de DDL**: CHECK nomeado (≤ 61 caracteres) só com colunas, literais, `IN`, `IS`, `NULL`, `NOT NULL`, `AND`, `OR` e comparações (sem `BETWEEN`, `LIKE`, `CASE`, `NOT IN` nem funções); `timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)`; opcionais `timestamp(6) NULL DEFAULT NULL`; `updated_at` com `ON UPDATE CURRENT_TIMESTAMP(6)`; FKs `ON UPDATE RESTRICT ON DELETE RESTRICT` com índice nomeado; `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci`.
- Nenhum ALTER em tabela existente. `ai_interaction` não tem FK para o sujeito (polimórfico: `encounter` ou `exam_result`) e não tem coluna com a entrada enviada ao provedor.
- **`provision.sh`**: acrescentar `$migrations_dir/20261007_0014_phase8a_ai_gateway.sql` depois da linha da 0013 e trocar `0001..0013` por `0001..0014` no comentário da linha 10.
- **`.verify.sql`** (só SELECT): as 3 tabelas, os 8 CHECKs (`information_schema.check_constraints` com `table_name IN (...)` literal), as 2 UNIQUEs, os 4 índices de `ai_interaction` e as contagens de `encounter`, `exam_result`, `patient` e `audit_log`.

**Interface**
- Produz: `feature_flag(id bigint unsigned AUTO_INCREMENT, tenant_id bigint unsigned, flag_key varchar(64), enabled tinyint(1) NOT NULL DEFAULT 0, updated_by_system_user_id int NULL, created_at, updated_at)` com UNIQUE `feature_flag_tenant_key_uq (tenant_id, flag_key)`, CHECK `feature_flag_enabled_ck` (`enabled IN (0, 1)`) e FKs para `tenant` e `system_users`
- Produz: `ai_tenant_setting(tenant_id bigint unsigned PRIMARY KEY, terms_version varchar(20) NULL, terms_accepted_at timestamp(6) NULL, terms_accepted_by_system_user_id int NULL, monthly_token_quota int unsigned NOT NULL DEFAULT 300000, updated_by_system_user_id int NULL, created_at, updated_at)` com CHECK `ai_tenant_setting_terms_ck` (`(terms_version IS NULL AND terms_accepted_at IS NULL AND terms_accepted_by_system_user_id IS NULL) OR (terms_version IS NOT NULL AND terms_accepted_at IS NOT NULL AND terms_accepted_by_system_user_id IS NOT NULL)`) e FKs para `tenant` e `system_users` (2)
- Produz: `ai_interaction(id bigint unsigned AUTO_INCREMENT, tenant_id bigint unsigned, system_unit_id int, system_user_id int, purpose varchar(40), subject_type varchar(30), subject_id bigint unsigned, prompt_version varchar(40), provider varchar(30), model varchar(80) NULL, status varchar(20), input_sha256 char(64), input_tokens int unsigned NOT NULL DEFAULT 0, output_tokens int unsigned NOT NULL DEFAULT 0, cache_read_tokens int unsigned NOT NULL DEFAULT 0, cache_write_tokens int unsigned NOT NULL DEFAULT 0, cost_micro_usd bigint unsigned NULL, latency_ms int unsigned NULL, stop_reason varchar(30) NULL, provider_request_id varchar(120) NULL, error_code varchar(60) NULL, suggestion_text mediumtext NULL, final_text mediumtext NULL, decided_by_system_user_id int NULL, decided_at timestamp(6) NULL, correlation_id varchar(64), created_at, updated_at)` com:
  - índices `ai_interaction_tenant_created_idx (tenant_id, created_at)`, `ai_interaction_tenant_user_idx (tenant_id, system_user_id, created_at)`, `ai_interaction_subject_idx (tenant_id, subject_type, subject_id, purpose, created_at)` e `ai_interaction_tenant_purpose_idx (tenant_id, purpose, created_at)`;
  - CHECKs `ai_interaction_purpose_ck` (`purpose IN ('patient_summary', 'anamnesis_organize', 'exam_summary')`), `ai_interaction_subject_ck` (`subject_type IN ('encounter', 'exam_result')`), `ai_interaction_status_ck` (`status IN ('pending_review', 'accepted', 'edited', 'discarded', 'failed')`), `ai_interaction_failed_ck` (`(status = 'failed' AND error_code IS NOT NULL AND suggestion_text IS NULL) OR (status <> 'failed' AND error_code IS NULL AND suggestion_text IS NOT NULL)`), `ai_interaction_decided_ck` (`(status IN ('accepted', 'edited', 'discarded') AND decided_at IS NOT NULL AND decided_by_system_user_id IS NOT NULL) OR (status IN ('pending_review', 'failed') AND decided_at IS NULL AND decided_by_system_user_id IS NULL)`) e `ai_interaction_final_ck` (`(status IN ('accepted', 'edited') AND final_text IS NOT NULL) OR (status IN ('pending_review', 'failed', 'discarded') AND final_text IS NULL)`);
  - FKs para `tenant`, `system_unit` e `system_users` (`system_user_id`, `decided_by_system_user_id`).
- Consome: nada

**Teste RED**
- sem teste: migration SQL preparada e não executada (PREPARED ONLY); a estrutura é conferida por grep, pelo preparador 5.7 e, depois do bloqueio, pelo `.verify.sql` aplicado pelo orquestrador

**Critério de aceite**
- `/usr/bin/grep -c "CREATE TABLE"` na 0014 imprime `3`; o arquivo contém `Status: PREPARED ONLY` e 64 zeros no `INSERT INTO schema_migrations`.
- A 0014 tem 8 CHECKs nomeados, o maior nome tem no máximo 61 caracteres, e não há `ALTER TABLE` nem `NOT IN`.
- PREP57 gera `18-20261007_0014_phase8a_ai_gateway.sql` com triggers `_bi`/`_bu` para os CHECKs e sem `utf8mb4_0900_ai_ci`; PYTEST57 imprime `Ran 8 tests` e nenhuma falha (igual a `baseline/test-prepare-mysql57.txt`).
- Depois do bloqueio (orquestrador): o verify lista as 3 tabelas e os 8 CHECKs nos dois bancos, e as contagens de `encounter`, `exam_result`, `patient` e `audit_log` são iguais às de antes.

**Validação**
- `/usr/bin/grep -c "CREATE TABLE" src/app/database/migrations/20261007_0014_phase8a_ai_gateway.sql` (evidência: `3`)
- `/usr/bin/grep -oE "CONSTRAINT [a-z0-9_]+_ck" src/app/database/migrations/20261007_0014_phase8a_ai_gateway.sql | awk '{print length($2)}' | sort -n | tail -1` e `| wc -l` (evidência: maior ≤ 61; total `8`)
- `/usr/bin/grep -ciE "alter table|not in" src/app/database/migrations/20261007_0014_phase8a_ai_gateway.sql` (evidência: `0`)
- `python3 scripts/prepare-mysql57.py src/app/database/migrations /tmp/claude-1000/f8a-out57 && ls /tmp/claude-1000/f8a-out57 | /usr/bin/grep 18-` (evidência: `18-20261007_0014_phase8a_ai_gateway.sql`)
- `python3 scripts/test-prepare-mysql57.py` (evidência: `Ran 8 tests` e `OK`, igual a `baseline/test-prepare-mysql57.txt`)
- `/usr/bin/grep -n "0014\|0001..0014" scripts/test-db/provision.sh` (evidência: linha da 0014 depois da 0013 e comentário `0001..0014`)

### T-02 — Domain e contratos de IA (finalidade, interação, configuração, termo, uso, pedido/resultado, preços, exceções, gateway, repositórios, contexto clínico, `AiAssistantInterface`)

**Camada:** backend
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** alta
**Agente:** Platão

**Arquivos prováveis**
- `src/app/Core/Domain/AiPurpose.php`
- `src/app/Core/Domain/AiInteraction.php`
- `src/app/Core/Domain/AiTenantSetting.php`
- `src/app/Core/Domain/AiTerms.php`
- `src/app/Core/Domain/AiUsage.php`
- `src/app/Core/Domain/AiCompletionRequest.php`
- `src/app/Core/Domain/AiCompletionResult.php`
- `src/app/Core/Domain/AiSuggestion.php`
- `src/app/Core/Domain/AiModelPricing.php`
- `src/app/Core/Domain/Exception/AiUnavailableException.php`
- `src/app/Core/Domain/Exception/AiProviderException.php`
- `src/app/Core/Domain/Exception/AiInteractionNotFoundException.php`
- `src/app/Core/Domain/Exception/AiSubjectNotFoundException.php`
- `src/app/Core/Domain/Contract/AiGatewayInterface.php`
- `src/app/Core/Domain/Contract/AiInteractionRepositoryInterface.php`
- `src/app/Core/Domain/Contract/AiTenantSettingRepositoryInterface.php`
- `src/app/Core/Domain/Contract/FeatureFlagRepositoryInterface.php`
- `src/app/Core/Domain/Contract/AiClinicalContextQueryInterface.php`
- `src/app/Core/Assistant/Contract/AiAssistantInterface.php`
- `src/tests/Unit/AiDomainTest.php`

**Notas de implementação**
- Namespaces `CentralVet\Domain`, `CentralVet\Domain\Exception`, `CentralVet\Domain\Contract` e `CentralVet\Assistant\Contract`; `declare(strict_types=1)`; classes `final` com construtores privados e fábricas nomeadas (padrão de `GeneratedDocument` e `DocumentKind` da 7B). Sem dependência de Adianti, PDO ou rede.
- `AiPurpose::hostAction`: `patient_summary` → `EncounterView::onAiPatientSummary`; `anamnesis_organize` → `EncounterView::onAiOrganizeAnamnesis`; `exam_summary` → `ExamResultForm::onAiExamSummary`. `AiPurpose::decideAction`: as duas primeiras → `EncounterView::onAiDecide`; `exam_summary` → `ExamResultForm::onAiDecide`. `AiPurpose::subjectType`: as duas primeiras → `encounter`; `exam_summary` → `exam_result`. Finalidade desconhecida → `InvalidArgumentException('Unknown AI purpose')`.
- `AiInteraction`: estados `pending_review` → `accepted|edited|discarded`; `failed` é final. `recordSuggestion` exige texto não vazio e copia provedor, modelo, uso, custo, `stop_reason` e id do pedido. `decide('accepted', null, ...)` grava `final_text` = sugestão; `decide('edited', $texto, ...)` exige texto não vazio após `trim` e grava o texto; `decide('discarded', null, ...)` deixa `final_text` NULL. Decidir fora de `pending_review` → `AiInteractionNotFoundException`. Decisão desconhecida → `InvalidArgumentException('Unknown AI decision')`.
- `AiModelPricing` (US$ por milhão de tokens = micro-USD por token): `claude-opus-5-5` 4 / 20 / leitura de cache 0,20 / escrita de cache 5; `claude-sonnet-5-5` e `claude-sonnet-5` 2 / 10 / 0,20 / 2,50; `claude-haiku-4-5` 1 / 5 / 0,10 / 1,25; `claude-opus-5` e `claude-opus-4-8` 5 / 25 / 0,50 / 6,25; `stub` 0. Arredondamento `(int) round(...)`. Modelo fora da tabela → `null`.
- `AiTerms::text('pt')` e `AiTerms::text('en')`: termo curto (envio ao provedor Anthropic de dados clínicos minimizados, sem nome, contato ou documento do tutor; a IA não decide; o profissional revisa; registro em `ai_interaction`; desligável a qualquer momento). Locale fora de `pt`/`en` → `en`.
- `AiUnavailableException::REASONS` = `ai_disabled`, `ai_terms_pending`, `ai_quota_exceeded`, `ai_not_configured`, `ai_auth_failed`, `ai_timeout`, `ai_rate_limited`, `ai_overloaded`, `ai_refused`, `ai_truncated`, `ai_provider_error`, `ai_empty_input`, `ai_input_too_long`; mensagem em inglês fixa por motivo (`AI assistant is unavailable: <reason>`), sem dado do pedido. `AiProviderException` aceita os mesmos motivos de provedor. `AiInteractionNotFoundException` tem a mensagem fixa `AI suggestion not found`; `AiSubjectNotFoundException`, `AI subject not found`.
- `AiAssistantInterface` substitui `AiClinicalAssistantInterface` (removida na T-14); docblock aponta `AiAssistService`.

**Interface**
- Produz: `AiPurpose::PATIENT_SUMMARY = 'patient_summary'`, `AiPurpose::ANAMNESIS_ORGANIZE = 'anamnesis_organize'`, `AiPurpose::EXAM_SUMMARY = 'exam_summary'`, `AiPurpose::ALL`, `AiPurpose::subjectType(string $purpose): string`, `AiPurpose::hostAction(string $purpose): string`, `AiPurpose::decideAction(string $purpose): string`
- Produz: `AiInteraction::start(int $tenantId, int $systemUnitId, int $systemUserId, string $purpose, int $subjectId, string $promptVersion, string $inputSha256, string $correlationId): self`, `AiInteraction::recordSuggestion(AiCompletionResult $result, int $latencyMs): void`, `AiInteraction::recordFailure(string $errorCode, int $latencyMs, ?AiUsage $usage, ?string $model, string $provider): void`, `AiInteraction::decide(string $decision, ?string $finalText, int $systemUserId, DateTimeImmutable $now): void`, `AiInteraction::reconstitute(array $row): self`, `AiInteraction::assignId(int $id): void`, `AiInteraction::toRow(): array`
- Produz: getters de `AiInteraction`: `id(): ?int`, `tenantId(): int`, `systemUnitId(): int`, `systemUserId(): int`, `purpose(): string`, `subjectType(): string`, `subjectId(): int`, `promptVersion(): string`, `provider(): string`, `model(): ?string`, `status(): string`, `usage(): AiUsage`, `costMicroUsd(): ?int`, `latencyMs(): ?int`, `errorCode(): ?string`, `suggestionText(): ?string`, `finalText(): ?string`, `decidedBySystemUserId(): ?int`, `decidedAt(): ?DateTimeImmutable`, `createdAt(): ?DateTimeImmutable`; constantes `AiInteraction::STATUS_PENDING_REVIEW = 'pending_review'`, `STATUS_ACCEPTED = 'accepted'`, `STATUS_EDITED = 'edited'`, `STATUS_DISCARDED = 'discarded'`, `STATUS_FAILED = 'failed'`
- Produz: `AiUsage(int $inputTokens, int $outputTokens, int $cacheReadTokens = 0, int $cacheWriteTokens = 0)`, `AiUsage::totalTokens(): int`
- Produz: `AiCompletionRequest(string $purpose, string $promptVersion, string $system, string $userContent)`
- Produz: `AiCompletionResult(string $text, string $provider, string $model, AiUsage $usage, ?int $costMicroUsd, string $stopReason, ?string $providerRequestId)`
- Produz: `AiSuggestion(int $interactionId, string $purpose, string $text, string $provider, string $model, string $promptVersion)`
- Produz: `AiTenantSetting::defaults(int $tenantId): self`, `AiTenantSetting::reconstitute(array $row): self`, `AiTenantSetting::acceptTerms(string $version, int $systemUserId, DateTimeImmutable $now): void`, `AiTenantSetting::hasAcceptedTerms(string $version): bool`, `AiTenantSetting::changeQuota(int $monthlyTokenQuota): void`, `AiTenantSetting::monthlyTokenQuota(): int`, `AiTenantSetting::termsVersion(): ?string`, `AiTenantSetting::termsAcceptedAt(): ?DateTimeImmutable`, `AiTenantSetting::DEFAULT_MONTHLY_TOKEN_QUOTA = 300000`
- Produz: `AiTerms::VERSION = '2026-10-v1'`, `AiTerms::text(string $locale): string`
- Produz: `AiModelPricing::costMicroUsd(string $model, AiUsage $usage): ?int`, `AiModelPricing::isKnown(string $model): bool`
- Produz: `AiUnavailableException(string $reason)`, `AiUnavailableException::reason(): string`, `AiUnavailableException::REASONS`, `AiProviderException(string $reason, ?int $httpStatus = null, ?AiUsage $usage = null, ?string $model = null)`, `AiProviderException::reason(): string`, `AiProviderException::usage(): ?AiUsage`, `AiProviderException::model(): ?string`, `AiInteractionNotFoundException`, `AiSubjectNotFoundException`
- Produz: `AiGatewayInterface::complete(AiCompletionRequest $request): AiCompletionResult`, `AiGatewayInterface::isConfigured(): bool`, `AiGatewayInterface::providerName(): string`
- Produz: `AiInteractionRepositoryInterface::insert(AiInteraction $interaction): AiInteraction`, `AiInteractionRepositoryInterface::saveDecision(AiInteraction $interaction): bool`, `AiInteractionRepositoryInterface::find(int $id): ?AiInteraction`, `AiInteractionRepositoryInterface::monthlyTokenUsage(DateTimeImmutable $monthStart): int`, `AiInteractionRepositoryInterface::monthlySummary(DateTimeImmutable $monthStart): array`, `AiInteractionRepositoryInterface::latestDecidedFor(string $purpose, int $subjectId): ?AiInteraction`, `AiInteractionRepositoryInterface::listRecent(int $limit): array`; `monthlySummary` devolve `list<array{purpose: string, requests: int, tokens: int, cost_micro_usd: int}>`
- Produz: `AiTenantSettingRepositoryInterface::find(): ?AiTenantSetting`, `AiTenantSettingRepositoryInterface::save(AiTenantSetting $setting, int $systemUserId): void`
- Produz: `FeatureFlagRepositoryInterface::AI_ASSISTANT = 'ai_assistant'`, `FeatureFlagRepositoryInterface::isEnabled(string $flagKey): bool`, `FeatureFlagRepositoryInterface::set(string $flagKey, bool $enabled, int $systemUserId): void`
- Produz: `AiClinicalContextQueryInterface::encounterContext(int $encounterId): ?array`, `AiClinicalContextQueryInterface::patientHistory(int $patientId, ?int $excludeEncounterId): array`, `AiClinicalContextQueryInterface::examResultContext(int $examResultId): ?array`, com os formatos:
  - `encounterContext`: `array{encounter_id: int, system_unit_id: int, patient_id: int, status: string, anamnesis_text: ?string, patient_name: string, tutor_name: ?string, species: string, breed: ?string, sex: ?string, birth_date: ?string, weight_kg: ?string}`
  - `patientHistory`: `array{encounters: list<array{started_at: string, anamnesis_text: ?string, physical_exam_text: ?string, diagnosis_text: ?string, clinical_plan_text: ?string}>, prescriptions: list<array{created_at: string, items: list<string>}>, exam_results: list<array{received_at: string, exam_name: string, result_text: ?string}>}` (até 5 de cada, mais recentes primeiro)
  - `examResultContext`: `array{exam_result_id: int, encounter_id: int, system_unit_id: int, patient_id: int, exam_name: string, result_text: ?string, received_at: string, patient_name: string, tutor_name: ?string, species: string, breed: ?string, sex: ?string, birth_date: ?string}`
- Produz: `AiAssistantInterface::summarizePatient(int $encounterId): AiSuggestion`, `AiAssistantInterface::organizeAnamnesis(int $encounterId, string $freeText): AiSuggestion`, `AiAssistantInterface::summarizeExamResult(int $examResultId): AiSuggestion`, `AiAssistantInterface::decide(int $interactionId, string $decision, ?string $finalText): AiInteraction`, `AiAssistantInterface::latestDecided(string $purpose, int $subjectId): ?AiInteraction`, `AiAssistantInterface::isAvailable(): bool`
- Consome: nada

**Teste RED**
- `src/tests/Unit/AiDomainTest.php` — `AiModelPricing::costMicroUsd('claude-opus-5-5', new AiUsage(1000, 500))` devolve `14000`, `AiInteraction::decide('edited', '  ', ...)` lança, decidir duas vezes lança `AiInteractionNotFoundException` e `AiPurpose::hostAction('exam_summary')` devolve `ExamResultForm::onAiExamSummary`, falhando antes porque as classes não existem (comando: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php | /usr/bin/grep -E 'AiDomainTest|Failed:'`)

**Critério de aceite**
- `AiDomainTest` passa com casos para: as 3 finalidades (`subjectType`, `hostAction`, `decideAction`) e finalidade desconhecida; ciclo `pending_review` → `accepted` (final = sugestão), → `edited` (final = texto), → `discarded` (final NULL); segunda decisão e decisão de `failed` lançam `AiInteractionNotFoundException`; `recordFailure` com uso parcial; custo `14000` para opus-5-5 (1000/500), `7000` para sonnet-5-5 (1000/500), `null` para modelo desconhecido; `AiTenantSetting::defaults` com cota 300000 e termo não aceito; `hasAcceptedTerms('2026-10-v1')` verdadeiro só depois de `acceptTerms`; `AiTerms::text('pt')` e `text('en')` não vazios e diferentes.
- SUITE com `Failed: 0`; cada PHP tocado imprime `No syntax errors detected` (nenhum erro novo em relação a `baseline/php-lint.txt`).

**Validação**
- SUITE `| /usr/bin/grep -E 'AiDomainTest|Failed:'` (evidência: linhas `AiDomainTest` OK e `Failed: 0`)
- LINT de cada arquivo de `src/app/Core/Domain/Ai*.php`, `Domain/Exception/Ai*.php`, `Domain/Contract/Ai*.php`, `Domain/Contract/FeatureFlagRepositoryInterface.php` e `Assistant/Contract/AiAssistantInterface.php` (evidência: `No syntax errors detected` em todos)
- `/usr/bin/grep -rlE "Adianti|PDO|curl_" src/app/Core/Domain/Ai*.php src/app/Core/Assistant/Contract/AiAssistantInterface.php` (evidência: nenhuma linha)

### T-03 — `AiConfig` por env, variáveis em `.env.example`/`docker-compose.yml` e mascaramento da chave em `JsonLogger`/`AuditRedactor`

**Camada:** infra
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** simples
**Agente:** Tesla

**Arquivos prováveis**
- `src/app/Core/Ai/AiConfig.php`
- `.env.example`
- `docker-compose.yml`
- `src/app/Core/Observability/Logging/JsonLogger.php`
- `src/app/Core/Audit/AuditRedactor.php`
- `src/tests/Unit/AiConfigTest.php`

**Notas de implementação**
- Variáveis (padrões entre parênteses): `AI_PROVIDER` (`anthropic`; aceita `anthropic` ou `stub`), `ANTHROPIC_API_KEY` (vazio), `ANTHROPIC_BASE_URL` (`https://api.anthropic.com`), `AI_MODEL` (`claude-sonnet-5-5`), `AI_EFFORT` (`low`; aceita `low|medium|high|xhigh|max`), `AI_MAX_OUTPUT_TOKENS` (`8000`, entre 256 e 32000), `AI_TIMEOUT_SECONDS` (`30`, entre 5 e 60), `AI_CONNECT_TIMEOUT_SECONDS` (`5`, entre 1 e 15), `AI_FALLBACKS` (`default`; aceita `default` ou `off`), `AI_STUB_BEHAVIOR` (`ok`; aceita `ok` ou `timeout`), `APP_ENV` (já existe).
- Valor fora do domínio → `InvalidArgumentException` com o nome da variável e sem o valor. `AI_PROVIDER=stub` com `APP_ENV=production` → `InvalidArgumentException('AI stub provider is not allowed in production')`.
- `__debugInfo()` e qualquer `toArray()` devolvem a chave como `*****`; a chave nunca entra em mensagem de exceção.
- `.env.example`: bloco `# IA (Fase 8A)` com as variáveis, `ANTHROPIC_API_KEY=` vazio e comentário de que a chave só vive no env. `docker-compose.yml`: as mesmas variáveis no `environment` do `x-php-service`, no formato `${VAR:-padrão}` (`ANTHROPIC_API_KEY: ${ANTHROPIC_API_KEY:-}`).
- `JsonLogger::SENSITIVE_KEYS` e `AuditRedactor::SENSITIVE_KEYS` ganham `x-api-key` e `anthropic_api_key` (a comparação já é por chave em minúsculas).

**Interface**
- Produz: `AiConfig::fromEnvironment(): self`, `AiConfig::fromArray(array $env): self`, `AiConfig::provider(): string`, `AiConfig::apiKey(): ?string`, `AiConfig::baseUrl(): string`, `AiConfig::model(): string`, `AiConfig::effort(): string`, `AiConfig::maxOutputTokens(): int`, `AiConfig::timeoutSeconds(): int`, `AiConfig::connectTimeoutSeconds(): int`, `AiConfig::fallbacksEnabled(): bool`, `AiConfig::stubBehavior(): string`, `AiConfig::appEnv(): string`
- Consome: nada

**Teste RED**
- `src/tests/Unit/AiConfigTest.php` — `AiConfig::fromArray([])` devolve modelo `claude-sonnet-5-5`, effort `low` e timeout 30; `AI_PROVIDER=stub` com `APP_ENV=production` lança; `print_r` do config com `ANTHROPIC_API_KEY=sk-ant-test-f8a` não contém `sk-ant-test-f8a`; `JsonLogger` e `AuditRedactor` mascaram `x-api-key` — falhando antes porque `AiConfig` não existe e as chaves não são mascaradas (comando: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php | /usr/bin/grep -E 'AiConfigTest|Failed:'`)

**Critério de aceite**
- `AiConfigTest` passa: padrões, limites, valores inválidos (mensagem com o nome da variável, sem o valor), stub em produção recusado, chave mascarada em `__debugInfo`, `JsonLogger` grava `"x-api-key":"*****"` e `AuditRedactor` devolve `*****` para `anthropic_api_key`.
- `docker compose config` mostra `AI_MODEL: claude-sonnet-5-5` no serviço `app`.
- SUITE com `Failed: 0`; nenhum erro novo em relação a `baseline/php-lint.txt`.

**Validação**
- SUITE `| /usr/bin/grep -E 'AiConfigTest|Failed:'` (evidência: `AiConfigTest` OK e `Failed: 0`)
- `docker compose config 2>/dev/null | /usr/bin/grep -E "AI_MODEL|AI_PROVIDER|ANTHROPIC_BASE_URL"` (evidência: `AI_MODEL: claude-sonnet-5-5`, `AI_PROVIDER: anthropic`, `ANTHROPIC_BASE_URL: https://api.anthropic.com`)
- `/usr/bin/grep -c "ANTHROPIC_API_KEY=$" .env.example` (evidência: `1`)
- LINT dos 4 PHP tocados (evidência: `No syntax errors detected`)

### T-04 — Programas RBAC `AiSettingsForm` e `AiInteractionList` para o grupo 1 (seed + DML, verify e rollback preparados)

**Camada:** infra
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** simples
**Agente:** Jaspion

**Arquivos prováveis**
- `src/app/database/seeds/initial-application-programs.sql`
- `.claude/tasks/mar-20261007-1508-fase-8a-ai-gateway/sql/T-04-programs.sql`
- `.claude/tasks/mar-20261007-1508-fase-8a-ai-gateway/sql/T-04-programs.verify.sql`
- `.claude/tasks/mar-20261007-1508-fase-8a-ai-gateway/sql/T-04-programs.rollback.sql`

**Notas de implementação**
- **Modelos**: `.claude/tasks/mar-20261006-1347-fase-7b-documentos/sql/T-04-programs.sql`, `.verify.sql` e `.rollback.sql` e o bloco 7B do seed.
- **Concessão**: só o grupo 1 (`Template - Admin`, por id). Os grupos clínicos não recebem as telas de configuração; as ações de IA nas telas clínicas usam os programas já existentes (`EncounterView`, `ExamResultForm`), sem DML.
- `SET NAMES utf8mb4;` é a primeira instrução dos três arquivos. Sem id literal: cada INSERT deriva o id com `SELECT (SELECT COALESCE(MAX(id),0)+1 FROM t cur) ... FROM DUAL WHERE NOT EXISTS (...)`, uma linha por comando.
- `T-04-programs.sql`, em `START TRANSACTION`: 2 INSERTs em `system_program` + 2 concessões + SELECTs + `COMMIT`.
- `.verify.sql` (só SELECT): os 2 controllers; concessões por grupo (1 = 2, demais = 0); `COUNT(*)` de `system_program` e `system_group_program`.
- `.rollback.sql`, preparado e nunca executado sem nova aprovação: `DELETE` com `WHERE` por nome de controller em `system_group_program`, `system_user_program`, `system_program_method_role` e `system_program`; `COMMIT` comentado.
- **Seed**: recebe os mesmos programas e concessões, de forma idempotente, dentro da transação existente.

**Interface**
- Produz: programas `AiSettingsForm` (`Central Vet - AI Settings`) e `AiInteractionList` (`Central Vet - AI Interaction List`), cada um em `system_group_program` só do grupo 1
- Consome: nada

**Teste RED**
- sem teste: DML SQL preparada (programas, verify e rollback), sem execução; conferida por grep e, depois do bloqueio, pelo verify do orquestrador

**Critério de aceite**
- `/usr/bin/grep -oE "controller='(AiSettingsForm|AiInteractionList)'" src/app/database/seeds/initial-application-programs.sql | sort -u | wc -l` imprime `2`.
- Os três arquivos de `sql/` começam com `SET NAMES utf8mb4;`. `T-04-programs.sql` não tem id numérico literal nem `ROW_NUMBER`/`WITH` e só cita o grupo 1. O rollback não tem `DELETE` sem `WHERE`.
- Depois do bloqueio (orquestrador): o verify mostra 2 concessões no grupo 1 e 0 nos demais; `system_program` = anterior + 2 e `system_group_program` = anterior + 2, com os registros existentes preservados.

**Validação**
- `/usr/bin/grep -oE "controller='(AiSettingsForm|AiInteractionList)'" src/app/database/seeds/initial-application-programs.sql | sort -u | wc -l` (evidência: `2`)
- `cd /var/www/html/centralvet/.claude/tasks/mar-20261007-1508-fase-8a-ai-gateway && for f in sql/T-04-programs.sql sql/T-04-programs.verify.sql sql/T-04-programs.rollback.sql; do /usr/bin/grep -v '^--' $f | /usr/bin/grep -m1 -v '^[[:space:]]*$'; done` (evidência: três linhas `SET NAMES utf8mb4;`)
- `/usr/bin/grep -ciE "row_number|with recursive|values *\( *[0-9]{2,}" .claude/tasks/mar-20261007-1508-fase-8a-ai-gateway/sql/T-04-programs.sql` (evidência: `0`)
- `/usr/bin/grep -ciE "^delete from [a-z_]+;$" .claude/tasks/mar-20261007-1508-fase-8a-ai-gateway/sql/T-04-programs.rollback.sql` (evidência: `0`)

### T-05 — Fakes de gateway, interação, configuração, feature flag e contexto clínico

**Camada:** shared
**Dependências:** T-02
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Platão

**Arquivos prováveis**
- `src/tests/Support/FakeAiGateway.php`
- `src/tests/Support/FakeAiInteractionRepository.php`
- `src/tests/Support/FakeAiTenantSettingRepository.php`
- `src/tests/Support/FakeFeatureFlagRepository.php`
- `src/tests/Support/FakeAiClinicalContextQuery.php`
- `src/tests/Unit/AiFakesTest.php`

**Notas de implementação**
- Namespace `CentralVet\Tests\Support`, padrão dos fakes da 7B (`FakeGeneratedDocumentRepository`). Nenhum fake abre rede, PDO ou arquivo.
- `FakeAiGateway`: devolve o `$result` (padrão: texto `Resumo de teste`, provedor `fake`, modelo `claude-sonnet-5-5`, uso 100/50, custo 700, `stop_reason` `end_turn`) ou lança `$failure`; registra cada `AiCompletionRequest` recebido.
- `FakeAiInteractionRepository`: ids sequenciais; `find` e `latestDecidedFor` só enxergam o tenant do construtor (linha semeada de outro tenant → `null`); `saveDecision` devolve `false` se a cópia guardada não está mais em `pending_review`; `monthlyTokenUsage` devolve a soma das inseridas + o valor de `setMonthlyTokenUsage`.

**Interface**
- Produz: `FakeAiGateway::__construct(?AiCompletionResult $result = null, ?AiProviderException $failure = null, bool $configured = true)`, `FakeAiGateway::calls(): array`
- Produz: `FakeAiInteractionRepository::__construct(int $tenantId)`, `FakeAiInteractionRepository::seed(AiInteraction $interaction): void`, `FakeAiInteractionRepository::all(): array`, `FakeAiInteractionRepository::setMonthlyTokenUsage(int $tokens): void`
- Produz: `FakeAiTenantSettingRepository::__construct(?AiTenantSetting $setting = null)`, `FakeAiTenantSettingRepository::saved(): ?AiTenantSetting`
- Produz: `FakeFeatureFlagRepository::__construct(array $enabledFlags = [])`
- Produz: `FakeAiClinicalContextQuery::__construct(array $encounters = [], array $histories = [], array $examResults = [])`
- Consome: T-02 `AiGatewayInterface::complete(AiCompletionRequest $request): AiCompletionResult`, `AiInteractionRepositoryInterface::saveDecision(AiInteraction $interaction): bool`, `AiTenantSettingRepositoryInterface::save(AiTenantSetting $setting, int $systemUserId): void`, `FeatureFlagRepositoryInterface::isEnabled(string $flagKey): bool`, `AiClinicalContextQueryInterface::encounterContext(int $encounterId): ?array`

**Teste RED**
- `src/tests/Unit/AiFakesTest.php` — `FakeAiGateway` registra a chamada e lança a falha configurada, e `FakeAiInteractionRepository::find` devolve `null` para interação semeada de outro tenant, falhando antes porque os fakes não existem (comando: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php | /usr/bin/grep -E 'AiFakesTest|Failed:'`)

**Critério de aceite**
- `AiFakesTest` passa: cada fake implementa a interface da T-02 (`instanceof`), o gateway registra chamadas e lança a falha, o repositório isola o tenant e devolve `false` na segunda `saveDecision`, a flag ausente devolve `false`.
- SUITE com `Failed: 0`; nenhum erro novo em relação a `baseline/php-lint.txt`.

**Validação**
- SUITE `| /usr/bin/grep -E 'AiFakesTest|Failed:'` (evidência: `AiFakesTest` OK e `Failed: 0`)
- LINT dos 6 arquivos (evidência: `No syntax errors detected`)

### T-06 — Repositórios PDO de interação (uso mensal, decisão condicional), configuração e feature flag

**Camada:** backend
**Dependências:** T-01, T-02
**Paralelizável:** sim
**Complexidade:** alta
**Agente:** Athena

**Arquivos prováveis**
- `src/app/Core/Persistence/AiInteractionRepository.php`
- `src/app/Core/Persistence/AiTenantSettingRepository.php`
- `src/app/Core/Persistence/FeatureFlagRepository.php`
- `src/tests/Integration/AiRepositoryIntegrationTest.php`

**Notas de implementação**
- `__construct(TenantContext $context, PDO $connection)` e `TenantQuery::forTenant` em todo SQL (modelo: `GeneratedDocumentRepository` da 7B). Tenant do registro diferente do contexto → `TenantBoundaryViolation` no insert.
- `saveDecision`: `UPDATE ai_interaction SET status, final_text, decided_by_system_user_id, decided_at WHERE id = ? AND tenant_id = ? AND status = 'pending_review'`, devolve `rowCount() === 1`.
- `monthlyTokenUsage`: `SUM(input_tokens + output_tokens + cache_read_tokens + cache_write_tokens)` com `created_at >= :monthStart AND created_at < :nextMonth`, usando `ai_interaction_tenant_created_idx`; sem linhas → `0`.
- `monthlySummary`: agrupado por `purpose`, ordenado por `purpose`.
- `latestDecidedFor`: status `accepted` ou `edited`, `subject_type` = `AiPurpose::subjectType($purpose)`, mais recente primeiro.
- `AiTenantSettingRepository::save` e `FeatureFlagRepository::set`: `INSERT ... ON DUPLICATE KEY UPDATE` com `updated_by_system_user_id`.
- Integração no `centralvet_test` (padrão `MysqlIntegrationTestCase`), com dois tenants e limpeza no `tearDown`.

**Interface**
- Produz: `AiInteractionRepository::__construct(TenantContext $context, PDO $connection)` implementando `AiInteractionRepositoryInterface`
- Produz: `AiTenantSettingRepository::__construct(TenantContext $context, PDO $connection)` implementando `AiTenantSettingRepositoryInterface`
- Produz: `FeatureFlagRepository::__construct(TenantContext $context, PDO $connection)` implementando `FeatureFlagRepositoryInterface`
- Consome: T-02 `AiInteractionRepositoryInterface::insert(AiInteraction $interaction): AiInteraction`, `AiInteractionRepositoryInterface::saveDecision(AiInteraction $interaction): bool`, `AiInteractionRepositoryInterface::monthlyTokenUsage(DateTimeImmutable $monthStart): int`, `AiTenantSettingRepositoryInterface::save(AiTenantSetting $setting, int $systemUserId): void`, `FeatureFlagRepositoryInterface::set(string $flagKey, bool $enabled, int $systemUserId): void`
- Consome: T-01 `ai_interaction(id bigint unsigned AUTO_INCREMENT, tenant_id bigint unsigned, system_unit_id int, system_user_id int, purpose varchar(40), subject_type varchar(30), subject_id bigint unsigned, prompt_version varchar(40), provider varchar(30), model varchar(80) NULL, status varchar(20), input_sha256 char(64), input_tokens int unsigned NOT NULL DEFAULT 0, output_tokens int unsigned NOT NULL DEFAULT 0, cache_read_tokens int unsigned NOT NULL DEFAULT 0, cache_write_tokens int unsigned NOT NULL DEFAULT 0, cost_micro_usd bigint unsigned NULL, latency_ms int unsigned NULL, stop_reason varchar(30) NULL, provider_request_id varchar(120) NULL, error_code varchar(60) NULL, suggestion_text mediumtext NULL, final_text mediumtext NULL, decided_by_system_user_id int NULL, decided_at timestamp(6) NULL, correlation_id varchar(64), created_at, updated_at)`

**Teste RED**
- `src/tests/Integration/AiRepositoryIntegrationTest.php` — insere duas interações no tenant A e uma no tenant B; `monthlyTokenUsage` do tenant A soma só as dele, `find` do id do tenant B devolve `null` e a segunda `saveDecision` devolve `false`, falhando antes porque os repositórios não existem (comando: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php | /usr/bin/grep -E 'AiRepositoryIntegrationTest|Failed:'`)

**Critério de aceite**
- `AiRepositoryIntegrationTest` passa no `centralvet_test`: insert devolve id; `find` isola tenant; `saveDecision` condicional (`true` e depois `false`); `monthlyTokenUsage` ignora o mês anterior e o outro tenant; `monthlySummary` agrupa por finalidade com custo somado; `latestDecidedFor` ignora `pending_review`, `failed` e `discarded`; upsert de configuração e de flag (segunda gravação altera a mesma linha: `COUNT(*)` = 1).
- SUITE com `Failed: 0`; nenhum erro novo em relação a `baseline/php-lint.txt`.

**Validação**
- SUITE `| /usr/bin/grep -E 'AiRepositoryIntegrationTest|Failed:'` (evidência: `AiRepositoryIntegrationTest` OK e `Failed: 0`)
- `/usr/bin/grep -c "TenantQuery::forTenant\|tenant_id = " src/app/Core/Persistence/AiInteractionRepository.php` (evidência: ≥ 1 por método público)
- LINT dos 4 arquivos (evidência: `No syntax errors detected`)

### T-07 — Gateways: Anthropic Messages via curl, stub, não configurado e fábrica

**Camada:** backend
**Dependências:** T-02, T-03
**Paralelizável:** sim
**Complexidade:** alta
**Agente:** Naruto

**Arquivos prováveis**
- `src/app/Core/Ai/AnthropicMessagesGateway.php`
- `src/app/Core/Ai/StubAiGateway.php`
- `src/app/Core/Ai/UnconfiguredAiGateway.php`
- `src/app/Core/Ai/AiGatewayFactory.php`
- `src/tests/Unit/AnthropicMessagesGatewayTest.php`

**Notas de implementação**
- Referência da API: skill `claude-api` (`curl/examples.md`, `shared/error-codes.md`, `shared/model-migration.md` § Migrating to Claude Sonnet 5.5). Não use memória: IDs de modelo e headers vêm da skill.
- Pedido: `POST {baseUrl}/v1/messages`, headers `x-api-key: <chave>`, `anthropic-version: 2023-06-01`, `content-type: application/json` e, com fallback ligado, `anthropic-beta: server-side-fallback-2026-07-01`. Corpo: `{"model": <config>, "max_tokens": <config>, "system": [{"type": "text", "text": <system>}], "messages": [{"role": "user", "content": <userContent>}], "output_config": {"effort": <config>}}` + `"fallbacks": "default"` quando ligado. Sem `thinking`, `temperature`, `top_p`, `top_k`, `stream` nem prefill (o Sonnet 5.5 recusa `thinking` `disabled` e sampling fora do padrão com 400; omitir `thinking` roda adaptativo, e effort `low` limita o gasto).
- Resposta 200: concatena só os blocos `type = text` (ignora `thinking`); `usage.input_tokens`, `output_tokens`, `cache_read_input_tokens`, `cache_creation_input_tokens` (ausente = 0); `model` da resposta (pode ser o do fallback) define o custo por `AiModelPricing`; `stop_reason`: `end_turn`/`stop_sequence` → sucesso; `refusal` → `AiProviderException('ai_refused')`; `max_tokens` → `ai_truncated`; texto vazio → `ai_provider_error`. As falhas pós-200 levam `usage` e `model`.
- Erros: transporte com `error` `timeout` → `ai_timeout`; `connect` → `ai_provider_error`; 401/403 → `ai_auth_failed`; 429 → `ai_rate_limited`; 529 → `ai_overloaded`; 400/404/413 e ≥ 500 → `ai_provider_error`. Sem retentativa. `request-id` do header vai para `providerRequestId`.
- Transporte padrão com `curl_init` (padrão de `S3Client.php:118`): `CURLOPT_TIMEOUT` = `timeoutSeconds`, `CURLOPT_CONNECTTIMEOUT` = `connectTimeoutSeconds`, `CURLOPT_SSL_VERIFYPEER` ligado, `CURLOPT_HEADERFUNCTION` só para `request-id`. Nunca loga headers, corpo de pedido, corpo de resposta nem a chave; mensagem de exceção só com o motivo e o status.
- `StubAiGateway`: modelo e provedor `stub`, uso 0, custo 0; texto `[stub] <purpose> v<promptVersion>:` + as primeiras 160 letras de `userContent`; comportamento `timeout` → `AiProviderException('ai_timeout')`.
- `UnconfiguredAiGateway`: `isConfigured()` falso; `complete` lança `AiProviderException('ai_not_configured')`.
- `AiGatewayFactory::fromConfig`: `stub` → `StubAiGateway`; `anthropic` com chave → `AnthropicMessagesGateway`; sem chave → `UnconfiguredAiGateway`.

**Interface**
- Produz: `AnthropicMessagesGateway::__construct(AiConfig $config, ?Closure $transport = null)`, transporte `Closure(string $url, array $headers, string $body, int $timeoutSeconds, int $connectTimeoutSeconds): array{status: int, body: string, request_id: ?string, error: ?string}`, `AnthropicMessagesGateway::API_VERSION = '2023-06-01'`, `AnthropicMessagesGateway::FALLBACK_BETA = 'server-side-fallback-2026-07-01'`
- Produz: `StubAiGateway::__construct(string $behavior = 'ok')`, `UnconfiguredAiGateway::__construct()`, `AiGatewayFactory::fromConfig(AiConfig $config): AiGatewayInterface`
- Consome: T-02 `AiGatewayInterface::complete(AiCompletionRequest $request): AiCompletionResult`, `AiModelPricing::costMicroUsd(string $model, AiUsage $usage): ?int`, `AiProviderException(string $reason, ?int $httpStatus = null, ?AiUsage $usage = null, ?string $model = null)`
- Consome: T-03 `AiConfig::fromArray(array $env): self`, `AiConfig::apiKey(): ?string`, `AiConfig::fallbacksEnabled(): bool`, `AiConfig::stubBehavior(): string`

**Teste RED**
- `src/tests/Unit/AnthropicMessagesGatewayTest.php` — com transporte falso, o pedido sai com `anthropic-version: 2023-06-01`, `x-api-key`, `output_config.effort` `low`, `fallbacks` `default` e sem `thinking`; resposta 200 com `usage` 1000/500 e `model` `claude-sonnet-5-5` vira resultado com custo `7000`; status 429, 529, 401, timeout e `stop_reason` `refusal` viram `ai_rate_limited`, `ai_overloaded`, `ai_auth_failed`, `ai_timeout` e `ai_refused`, falhando antes porque o gateway não existe (comando: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php | /usr/bin/grep -E 'AnthropicMessagesGatewayTest|Failed:'`)

**Critério de aceite**
- `AnthropicMessagesGatewayTest` passa com os casos do RED + bloco `thinking` antes do `text` ignorado + `max_tokens` → `ai_truncated` com `usage` + `AI_FALLBACKS=off` sem header `anthropic-beta` nem campo `fallbacks` + mensagem de toda exceção sem a chave `sk-ant-test-f8a` + fábrica (`stub`, `anthropic` com e sem chave) + `StubAiGateway` com `timeout`.
- Nenhum teste chama a rede: o teste injeta o transporte em todo `new AnthropicMessagesGateway`.
- SUITE com `Failed: 0`; nenhum erro novo em relação a `baseline/php-lint.txt`.

**Validação**
- SUITE `| /usr/bin/grep -E 'AnthropicMessagesGatewayTest|Failed:'` (evidência: OK e `Failed: 0`)
- `/usr/bin/grep -n "new AnthropicMessagesGateway" src/tests/Unit/AnthropicMessagesGatewayTest.php | /usr/bin/grep -vc "function\|fn"` (evidência: `0`, todo construtor recebe transporte)
- `/usr/bin/grep -nE "error_log|->(info|warning|error|debug)\(" src/app/Core/Ai/AnthropicMessagesGateway.php` (evidência: nenhuma linha com `body`, `headers` ou `apiKey`)
- LINT dos 5 arquivos (evidência: `No syntax errors detected`)

### T-08 — Minimização de PII e catálogo de prompts versionados

**Camada:** backend
**Dependências:** T-02
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Spock

**Arquivos prováveis**
- `src/app/Core/Ai/PiiMinimizer.php`
- `src/app/Core/Ai/AiPromptCatalog.php`
- `src/tests/Unit/AiPromptCatalogTest.php`

**Notas de implementação**
- `PiiMinimizer::minimize`: troca, sem diferenciar maiúsculas e acentos, cada nome conhecido (inteiro e cada parte com 3+ letras) por `[NOME]`; e-mail por `[EMAIL]`; CPF (`000.000.000-00` ou 11 dígitos) por `[CPF]`; CNPJ (`00.000.000/0000-00` ou 14 dígitos) por `[CNPJ]`; telefone BR (`(11) 98765-4321`, `11987654321`, `+55 11 98765-4321`, fixo de 8 dígitos com DDD) por `[TELEFONE]`. Números de exame como `12.5` ou `120 mg/dL` ficam.
- `AiPromptCatalog::build` monta só dados clínicos: espécie, raça, sexo, idade em anos e meses calculada de `birth_date` (a data não é enviada), peso, textos do atendimento/histórico/exame, todos passados pelo `PiiMinimizer` com `[patient_name, tutor_name]`. Nunca envia ids, nome da clínica, contato ou data de nascimento.
- System prompt (fixo por finalidade e idioma; saída em pt-BR para `pt`, inglês para `en`): assistente de documentação para médico-veterinário; não diagnostica, não prescreve, não sugere conduta; usa só os fatos do texto; marca o que falta como "não informado"; texto simples com seções curtas; nada de dados pessoais.
  - `patient_summary`: seções "Identificação clínica", "Histórico relevante", "Medicações recentes", "Exames recentes", "Pontos de atenção registrados".
  - `anamnesis_organize`: seções "Queixa principal", "Histórico da queixa", "Alimentação e manejo", "Sinais relatados pelo tutor", "Medicações em uso", "Outras informações"; não acrescenta fato que não está no texto.
  - `exam_summary`: "Exame", "Achados principais", "Valores fora da referência informados no laudo", "Observações"; não interpreta além do laudo.
- Qualquer mudança de texto de prompt troca a versão (`.v1` → `.v2`).

**Interface**
- Produz: `PiiMinimizer::minimize(string $text, array $knownNames): string` com os marcadores `[NOME]`, `[EMAIL]`, `[TELEFONE]`, `[CPF]`, `[CNPJ]`
- Produz: `AiPromptCatalog::build(string $purpose, array $context, string $locale): AiCompletionRequest`, `AiPromptCatalog::version(string $purpose): string`, versões `patient_summary.v1`, `anamnesis_organize.v1`, `exam_summary.v1`; `$context` = `['encounter' => <encounterContext>, 'history' => <patientHistory>]` (resumo), `['encounter' => <encounterContext>, 'free_text' => string]` (anamnese) ou `['exam' => <examResultContext>]` (exame)
- Consome: T-02 `AiCompletionRequest(string $purpose, string $promptVersion, string $system, string $userContent)`, `AiClinicalContextQueryInterface::encounterContext(int $encounterId): ?array`, `AiClinicalContextQueryInterface::examResultContext(int $examResultId): ?array`

**Teste RED**
- `src/tests/Unit/AiPromptCatalogTest.php` — `build('anamnesis_organize', ...)` com texto livre contendo `F8A teste Tutor`, `f8a.teste@example.invalid`, `(11) 98765-4321` e `123.456.789-09` e paciente `F8A teste Paciente` devolve `userContent` sem nenhum desses valores e com `[NOME]`, `[EMAIL]`, `[TELEFONE]` e `[CPF]`, e `promptVersion` `anamnesis_organize.v1`, falhando antes porque as classes não existem (comando: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php | /usr/bin/grep -E 'AiPromptCatalogTest|Failed:'`)

**Critério de aceite**
- `AiPromptCatalogTest` passa com os casos do RED + `120 mg/dL` e `38.5` preservados + `birth_date` ausente do `userContent` e idade presente + as 3 finalidades com a versão certa + `locale` `en` com system prompt em inglês + finalidade desconhecida lança.
- SUITE com `Failed: 0`; nenhum erro novo em relação a `baseline/php-lint.txt`.

**Validação**
- SUITE `| /usr/bin/grep -E 'AiPromptCatalogTest|Failed:'` (evidência: OK e `Failed: 0`)
- LINT dos 3 arquivos (evidência: `No syntax errors detected`)
- Review Focus: `AiPromptCatalogTest` cobre o histórico (`patient_summary`) com o nome do paciente e do tutor nos textos dos atendimentos anteriores (evidência: `userContent` sem `F8A teste`)

### T-09 — Consulta PDO do contexto clínico autorizado (atendimento, histórico, exame)

**Camada:** backend
**Dependências:** T-01, T-02
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Arquimedes

**Arquivos prováveis**
- `src/app/Core/Persistence/AiClinicalContextQuery.php`
- `src/tests/Integration/AiClinicalContextQueryIntegrationTest.php`

**Notas de implementação**
- Tabelas (conferidas nas migrations 0002–0004): `encounter` (`system_unit_id`, `patient_id`, `status`, `started_at`, `anamnesis_text`, `physical_exam_text`, `diagnosis_text`, `clinical_plan_text`, `weight_kg`), `patient` (`name`, `species`, `breed`, `sex`, `birth_date`, `weight_kg`, `tutor_id`), `tutor` (`full_name`), `prescription`/`prescription_item`, `exam_request` (`encounter_id`, `exam_catalog_item_id`), `exam_result` (`structured_result_text`, `received_at`), `exam_catalog_item` (nome).
- Todo SQL com `TenantQuery::forTenant` em cada tabela do JOIN. Sujeito de outro tenant → `null`. `weight_kg` do atendimento tem precedência sobre o do paciente.
- Não seleciona `tutor.phone`, `tutor.email`, documento, endereço nem `stored_object_key`. O histórico exclui o atendimento atual e traz até 5 itens de cada tipo, mais recentes primeiro (modelo: `ClinicalSummaryReader`).

**Interface**
- Produz: `AiClinicalContextQuery::__construct(TenantContext $context, PDO $connection)` implementando `AiClinicalContextQueryInterface`
- Consome: T-02 `AiClinicalContextQueryInterface::encounterContext(int $encounterId): ?array`, `AiClinicalContextQueryInterface::patientHistory(int $patientId, ?int $excludeEncounterId): array`, `AiClinicalContextQueryInterface::examResultContext(int $examResultId): ?array`

**Teste RED**
- `src/tests/Integration/AiClinicalContextQueryIntegrationTest.php` — com paciente, 7 atendimentos e um exame no tenant A e um atendimento no tenant B, `encounterContext` do atendimento do tenant B devolve `null`, `patientHistory` devolve 5 atendimentos sem o atual e `examResultContext` traz `system_unit_id` do atendimento do pedido, falhando antes porque a classe não existe (comando: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php | /usr/bin/grep -E 'AiClinicalContextQueryIntegrationTest|Failed:'`)

**Critério de aceite**
- `AiClinicalContextQueryIntegrationTest` passa: chaves exatas dos três formatos da T-02; isolamento de tenant nos três métodos; limite de 5; sem as chaves `phone`, `email` ou `stored_object_key` em nenhum array devolvido.
- SUITE com `Failed: 0`; nenhum erro novo em relação a `baseline/php-lint.txt`.

**Validação**
- SUITE `| /usr/bin/grep -E 'AiClinicalContextQueryIntegrationTest|Failed:'` (evidência: OK e `Failed: 0`)
- `/usr/bin/grep -ciE "phone|email|stored_object_key" src/app/Core/Persistence/AiClinicalContextQuery.php` (evidência: `0`)
- LINT dos 2 arquivos (evidência: `No syntax errors detected`)

### T-10 — JS de ditado (Web Speech API) e CSS do painel de IA

**Camada:** frontend
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** simples
**Agente:** Tesla

**Arquivos prováveis**
- `src/app/templates/adminbs5/js/cv-ai-dictation.js`
- `src/app/templates/adminbs5/cv-components.css`
- `src/tests/Unit/AiDictationAssetTest.php`

**Notas de implementação**
- JS sem dependência, IIFE, `'use strict'`. `attach` liga o botão ao `SpeechRecognition`/`webkitSpeechRecognition` com `continuous = true`, `interimResults = true` e `lang` recebido (`pt-BR` ou `en-US`); acrescenta só os resultados finais ao fim do textarea; alterna o rótulo do botão entre iniciar/parar via `data-label-start`/`data-label-stop`; anuncia o estado num elemento `role="status"` ao lado. Sem suporte: botão `disabled`, `aria-disabled="true"`, `title` e texto de status com `unsupportedMessage`; devolve `false`. Erro `not-allowed` mostra a mensagem de permissão (`data-label-denied`). Nada de `fetch`, `XMLHttpRequest`, `MediaRecorder` ou `getUserMedia`: o áudio fica no navegador.
- CSS em `cv-components.css` (fora de `framework_hashes.php`): `.cv-ai-panel` (borda e fundo distintos), `.cv-ai-origin` (selo "Gerado por IA", texto alinhado à esquerda), `.cv-ai-actions` (botões com `min-height: var(--cv-touch-target, 44px)` e `min-width` igual), `.cv-ai-status`. Rótulos sempre à esquerda (`text-align: left`).

**Interface**
- Produz: `window.CvDictation.attach(buttonId, textareaId, lang, unsupportedMessage)` (devolve `true` com suporte e `false` sem), atributos `data-label-start`, `data-label-stop`, `data-label-denied` no botão
- Produz: classes CSS `.cv-ai-panel`, `.cv-ai-origin`, `.cv-ai-actions`, `.cv-ai-status`
- Consome: nada

**Teste RED**
- `src/tests/Unit/AiDictationAssetTest.php` — o arquivo `cv-ai-dictation.js` existe, define `window.CvDictation` com `attach`, cita `SpeechRecognition` e `webkitSpeechRecognition` e não contém `fetch(`, `XMLHttpRequest`, `MediaRecorder` nem `getUserMedia`; `cv-components.css` tem `.cv-ai-actions` com `min-height: var(--cv-touch-target`, falhando antes porque o arquivo e as classes não existem (comando: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php | /usr/bin/grep -E 'AiDictationAssetTest|Failed:'`)

**Critério de aceite**
- `AiDictationAssetTest` passa; `node --check src/app/templates/adminbs5/js/cv-ai-dictation.js` sai com código 0 (se `node` existir no host; senão, o smoke da Onda 4 confere que o console não tem mensagem de nível error).
- `cv-components.css` não tem `text-align: right` nem `center` nas classes novas.
- SUITE com `Failed: 0`.

**Validação**
- SUITE `| /usr/bin/grep -E 'AiDictationAssetTest|Failed:'` (evidência: OK e `Failed: 0`)
- `node --check src/app/templates/adminbs5/js/cv-ai-dictation.js; echo $?` (evidência: `0`)
- `/usr/bin/grep -A6 "\.cv-ai-" src/app/templates/adminbs5/cv-components.css | /usr/bin/grep -cE "text-align: *(right|center)"` (evidência: `0`)

### T-11 — `AiAssistService` (3 casos de uso, decisão, disponibilidade, cota, auditoria) e `forContext`

**Camada:** backend
**Dependências:** T-02, T-03, T-05, T-06, T-07, T-08, T-09
**Paralelizável:** sim
**Complexidade:** alta
**Agente:** Batman

**Arquivos prováveis**
- `src/app/Core/Application/AiAssistService.php`
- `src/tests/Unit/AiAssistServiceTest.php`

**Notas de implementação**
- Implementa `AiAssistantInterface`. Ordem de cada geração (sem chamada ao gateway até o passo 6):
  1. `TenantContext` fail-closed (`MissingTenantContext`).
  2. Carrega o sujeito (`encounterContext` ou `examResultContext`); `null` → `AiSubjectNotFoundException`.
  3. Autoriza `decide(new AuthorizationRequest($context, AiPurpose::hostAction($purpose), true, $context->tenantId(), <system_unit_id do sujeito>, 'ai_interaction', null, ['purpose' => $purpose]))->assertAllowed()`.
  4. Anamnese: `trim($freeText)` vazio → `AiUnavailableException('ai_empty_input')`; mais de `MAX_FREE_TEXT_CHARS` → `ai_input_too_long`.
  5. Disponibilidade: flag `ai_assistant` desligada → `ai_disabled`; termo `AiTerms::VERSION` não aceito → `ai_terms_pending`; `monthlyTokenUsage(início do mês UTC) >= monthlyTokenQuota()` → `ai_quota_exceeded`; `!$gateway->isConfigured()` → `ai_not_configured`. Nenhuma dessas grava interação.
  6. `AiPromptCatalog::build($purpose, $ctx, $locale)`, `input_sha256` = SHA-256 de `system + "\n" + userContent`, chamada com cronômetro.
  7. Sucesso: `recordSuggestion`, `insert`, auditoria `ai.suggestion.generated` e `AiSuggestion`. `AiProviderException`: `recordFailure` (com uso e modelo da exceção), `insert`, auditoria `ai.suggestion.failed` e `AiUnavailableException($e->reason())`.
- `patient_summary` usa `encounterContext` + `patientHistory(patient_id, encounter_id)`; `subject_id` = encounter. `exam_summary`: `subject_id` = exam_result.
- `decide`: `find` (tenant) → `null` → `AiInteractionNotFoundException`; autoriza `AiPurpose::decideAction` com `resourceUnitId` = `systemUnitId()` da interação (unidade diferente → `AuthorizationDenied`, que o controller mostra como a mesma mensagem de não encontrada); `decide(...)` do domínio; `saveDecision` `false` → `AiInteractionNotFoundException`; auditoria `ai.suggestion.decided` (`metadata`: `purpose`, `decision`, `interaction_id`, `final_length`).
- `isAvailable()`: flag ligada, termo atual aceito, cota não esgotada e gateway configurado; nunca lança por falta de flag/termo (devolve `false`); sem contexto de tenant devolve `false`.
- Auditoria por `AuditLogWriterInterface::record(new AuditEvent(...))` com `entityType` `ai_interaction`; `before/after/metadata` só com ids, finalidade, modelo, tokens, custo, decisão e código. Nunca texto de sugestão, texto final, entrada ou prompt.
- `forContext(TenantContext $context, PDO $connection, string $locale)`: monta os repositórios PDO da T-06, `AiClinicalContextQuery` (T-09), `AiGatewayFactory::fromConfig(AiConfig::fromEnvironment())` (T-07/T-03), `RbacAuthorizationService(new AdiantiProgramPermissionProvider(...), new PdoAuditLogWriter($connection))` e `PdoAuditLogWriter` (modelo: `EncounterView::makeEncounterService`). Config inválida → `AiUnavailableException('ai_not_configured')` registrada em `error_log` só com o nome da classe.

**Interface**
- Produz: `AiAssistService::__construct(AiGatewayInterface $gateway, AiInteractionRepositoryInterface $interactions, AiTenantSettingRepositoryInterface $settings, FeatureFlagRepositoryInterface $flags, AiClinicalContextQueryInterface $clinical, AuthorizationPolicyInterface $authorization, AuditLogWriterInterface $audit, TenantContext $context, string $locale = 'pt', ?Closure $clock = null)`
- Produz: `AiAssistService::forContext(TenantContext $context, PDO $connection, string $locale): self`, `AiAssistService::MAX_FREE_TEXT_CHARS = 8000`, ações de auditoria `ai.suggestion.generated`, `ai.suggestion.failed`, `ai.suggestion.decided`
- Consome: T-02 `AiAssistantInterface::summarizePatient(int $encounterId): AiSuggestion`, `AiAssistantInterface::organizeAnamnesis(int $encounterId, string $freeText): AiSuggestion`, `AiAssistantInterface::summarizeExamResult(int $examResultId): AiSuggestion`, `AiAssistantInterface::decide(int $interactionId, string $decision, ?string $finalText): AiInteraction`, `AiAssistantInterface::isAvailable(): bool`, `AiPurpose::hostAction(string $purpose): string`, `AiPurpose::decideAction(string $purpose): string`, `AiTerms::VERSION = '2026-10-v1'`
- Consome: T-05 `FakeAiGateway::__construct(?AiCompletionResult $result = null, ?AiProviderException $failure = null, bool $configured = true)`, `FakeAiInteractionRepository::__construct(int $tenantId)`, `FakeAiInteractionRepository::setMonthlyTokenUsage(int $tokens): void`, `FakeAiClinicalContextQuery::__construct(array $encounters = [], array $histories = [], array $examResults = [])`
- Consome: T-06 `AiInteractionRepository::__construct(TenantContext $context, PDO $connection)`
- Consome: T-07 `AiGatewayFactory::fromConfig(AiConfig $config): AiGatewayInterface`
- Consome: T-08 `AiPromptCatalog::build(string $purpose, array $context, string $locale): AiCompletionRequest`
- Consome: T-09 `AiClinicalContextQuery::__construct(TenantContext $context, PDO $connection)`
- Consome: T-03 `AiConfig::fromEnvironment(): self`

**Teste RED**
- `src/tests/Unit/AiAssistServiceTest.php` — com flag ligada, termo aceito e cota livre, `organizeAnamnesis` grava uma interação `pending_review` e devolve `AiSuggestion` com o texto do `FakeAiGateway`; com a flag desligada lança `AiUnavailableException` `ai_disabled` sem chamada ao gateway; `decide` de interação de outro tenant lança `AiInteractionNotFoundException`, falhando antes porque o serviço não existe (comando: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php | /usr/bin/grep -E 'AiAssistServiceTest|Failed:'`)

**Critério de aceite**
- `AiAssistServiceTest` passa com: os 3 casos de uso (finalidade, `subject_id`, versão de prompt, provedor e modelo na interação); `ai_disabled`, `ai_terms_pending` (termo de versão antiga), `ai_quota_exceeded` (`setMonthlyTokenUsage(300000)`), `ai_not_configured`, `ai_empty_input` e `ai_input_too_long` com `FakeAiGateway::calls()` vazio e nenhuma interação gravada; falha do provedor (`ai_timeout` com uso 10/0) grava interação `failed` com `error_code` e tokens e lança `AiUnavailableException` `ai_timeout`; autorização negada (política falsa) lança `AuthorizationDenied` sem chamada; `decide` aceito/editado/descartado; segunda decisão, outro tenant e id inexistente lançam `AiInteractionNotFoundException` com a mesma mensagem `AI suggestion not found`; auditoria gravada com `ai.suggestion.generated`/`failed`/`decided` e sem o texto da sugestão em `metadata`; `isAvailable()` falso em cada uma das 4 condições.
- SUITE com `Failed: 0`; nenhum erro novo em relação a `baseline/php-lint.txt`.

**Validação**
- SUITE `| /usr/bin/grep -E 'AiAssistServiceTest|Failed:'` (evidência: OK e `Failed: 0`)
- LINT dos 2 arquivos (evidência: `No syntax errors detected`)
- Review Focus: casos `decide` com id de outro tenant, de outra unidade (política que nega `resourceUnitId` ≠ unidade ativa), já decidido e inexistente → mesma mensagem e `FakeAiInteractionRepository::all()` sem alteração (evidência: linhas do teste OK)
- Review Focus: casos IA desligada, termo pendente e cota esgotada com `FakeAiGateway::calls()` = `[]` (evidência: linhas do teste OK)

### T-12 — `AiSettingsService` (ligar/desligar, termo, cota, uso e custo do mês) e `forContext`

**Camada:** backend
**Dependências:** T-02, T-05, T-06
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Saitama

**Arquivos prováveis**
- `src/app/Core/Application/AiSettingsService.php`
- `src/tests/Unit/AiSettingsServiceTest.php`

**Notas de implementação**
- Autorização: `AiSettingsForm::onReload` (`overview`), `AiSettingsForm::onSave` (`enable`, `disable`, `changeMonthlyQuota`), `AiInteractionList::onReload` (`recentInteractions`), todas com `requiresUnitScope: false` e `resourceTenantId` = tenant do contexto.
- `enable(true)` aceita o termo `AiTerms::VERSION` (se ainda não aceito) e liga a flag; `enable(false)` sem termo atual aceito → `AiUnavailableException('ai_terms_pending')` e a flag continua desligada. `disable()` só desliga a flag; o termo aceito fica registrado.
- `changeMonthlyQuota`: inteiro entre 0 e 100000000; fora → `InvalidArgumentException('Invalid monthly token quota')`.
- `overview`: sem linha em `ai_tenant_setting`, usa `AiTenantSetting::defaults`; mês corrente em UTC; `month_cost_micro_usd` soma o `cost_micro_usd` do `monthlySummary`.
- Auditoria: `ai.terms.accepted` (versão), `ai.settings.enabled`, `ai.settings.disabled`, `ai.settings.quota_changed` (antes/depois).

**Interface**
- Produz: `AiSettingsService::__construct(AiTenantSettingRepositoryInterface $settings, FeatureFlagRepositoryInterface $flags, AiInteractionRepositoryInterface $interactions, AuthorizationPolicyInterface $authorization, AuditLogWriterInterface $audit, TenantContext $context, ?Closure $clock = null)`, `AiSettingsService::forContext(TenantContext $context, PDO $connection): self`
- Produz: `AiSettingsService::overview(): array` com `array{enabled: bool, terms_version: ?string, terms_accepted_at: ?DateTimeImmutable, terms_current: bool, monthly_token_quota: int, month_tokens: int, month_cost_micro_usd: int, by_purpose: list<array{purpose: string, requests: int, tokens: int, cost_micro_usd: int}>}`, `AiSettingsService::enable(bool $acceptTerms): void`, `AiSettingsService::disable(): void`, `AiSettingsService::changeMonthlyQuota(int $tokens): void`, `AiSettingsService::recentInteractions(int $limit = 50): array`
- Consome: T-02 `AiTenantSetting::acceptTerms(string $version, int $systemUserId, DateTimeImmutable $now): void`, `AiInteractionRepositoryInterface::monthlySummary(DateTimeImmutable $monthStart): array`, `FeatureFlagRepositoryInterface::AI_ASSISTANT = 'ai_assistant'`
- Consome: T-05 `FakeAiTenantSettingRepository::__construct(?AiTenantSetting $setting = null)`, `FakeFeatureFlagRepository::__construct(array $enabledFlags = [])`
- Consome: T-06 `AiTenantSettingRepository::__construct(TenantContext $context, PDO $connection)`, `FeatureFlagRepository::__construct(TenantContext $context, PDO $connection)`

**Teste RED**
- `src/tests/Unit/AiSettingsServiceTest.php` — `enable(false)` sem termo lança `AiUnavailableException` `ai_terms_pending` e a flag continua desligada; `enable(true)` grava o termo `2026-10-v1` e liga a flag; `overview()` devolve cota 300000 e o custo somado do mês, falhando antes porque o serviço não existe (comando: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php | /usr/bin/grep -E 'AiSettingsServiceTest|Failed:'`)

**Critério de aceite**
- `AiSettingsServiceTest` passa com os casos do RED + `disable()` mantém o termo + `changeMonthlyQuota(-1)` e `100000001` lançam + cada mutação grava a ação de auditoria correspondente (`ai.terms.accepted`, `ai.settings.enabled`, `ai.settings.disabled`, `ai.settings.quota_changed`) + autorização negada lança `AuthorizationDenied` sem gravar.
- SUITE com `Failed: 0`; nenhum erro novo em relação a `baseline/php-lint.txt`.

**Validação**
- SUITE `| /usr/bin/grep -E 'AiSettingsServiceTest|Failed:'` (evidência: OK e `Failed: 0`)
- LINT dos 2 arquivos (evidência: `No syntax errors detected`)

### T-13 — Telas `AiSettingsForm` e `AiInteractionList`

**Camada:** frontend
**Dependências:** T-04, T-12
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Kratos

**Arquivos prováveis**
- `src/app/control/clinic/AiSettingsForm.php`
- `src/app/control/clinic/AiInteractionList.php`
- `src/tests/Integration/AiSettingsScreensIntegrationTest.php`

**Notas de implementação**
- Declaração do ditado (ruling do usuário, onda 2): o termo de IA da `AiSettingsForm` deve declarar que o áudio do ditado vai ao serviço de voz do fabricante do navegador (Web Speech).
- Modelo: `DocumentTemplateForm`/`DocumentTemplateList` e `CommunicationMessageList` (7A/7B): `CvPage::header`, `TTransaction::open('permission')` no controller, catches `AuthorizationDenied` → `MissingTenantContext` → `Exception` com `CvFormat::userError`.
- `AiSettingsForm`: interruptor "Ativar assistente de IA" (`ai_enabled`), texto do termo (`AiTerms::text` do idioma da sessão) num bloco rolável, caixa "Li e aceito o termo" (`accept_terms`, obrigatória para ligar se o termo atual não foi aceito), versão e data do aceite, cota mensal (`monthly_token_quota`, inteiro), cartão do mês (tokens usados / cota, custo em US$ com 4 casas a partir de micro-USD, pedidos por finalidade) e link para `AiInteractionList`. Mostra o modelo configurado (`AI_MODEL`) e se o provedor está configurado, nunca a chave.
- `AiInteractionList`: tabela `cv-table` das 50 mais recentes: data, usuário, finalidade, modelo, status, tokens (entrada/saída), custo, código de erro. Nunca o texto da sugestão ou final. Estado vazio.
- Rótulos e cabeçalhos à esquerda; botões com `cv-touch-target`. Textos com `_t('<en>')` e anotação no board.

**Interface**
- Produz: rota `AiSettingsForm` com `onReload` e `onSave` (POST `ai_enabled`, `accept_terms`, `monthly_token_quota`); rota `AiInteractionList` com `onReload`
- Consome: T-12 `AiSettingsService::forContext(TenantContext $context, PDO $connection): self`, `AiSettingsService::overview(): array`, `AiSettingsService::enable(bool $acceptTerms): void`, `AiSettingsService::disable(): void`, `AiSettingsService::changeMonthlyQuota(int $tokens): void`, `AiSettingsService::recentInteractions(int $limit = 50): array`
- Consome: T-04 programas `AiSettingsForm` (`Central Vet - AI Settings`) e `AiInteractionList` (`Central Vet - AI Interaction List`), cada um em `system_group_program` só do grupo 1

**Teste RED**
- `src/tests/Integration/AiSettingsScreensIntegrationTest.php` — em subprocesso com `require "init.php"` (padrão de `BedFormIntegrationTest`), `AiSettingsForm` renderiza os campos `ai_enabled`, `accept_terms` e `monthly_token_quota`, o texto do termo e nenhuma ocorrência do valor de `ANTHROPIC_API_KEY`; `AiInteractionList` renderiza estado vazio e, com uma interação semeada, não mostra o `suggestion_text` dela, falhando antes porque as telas não existem (comando: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php | /usr/bin/grep -E 'AiSettingsScreensIntegrationTest|Failed:'`)

**Critério de aceite**
- `AiSettingsScreensIntegrationTest` passa com os casos do RED + usuário sem o programa vê a mensagem de permissão negada + POST `ai_enabled=1` sem `accept_terms` mostra o aviso de termo pendente e a flag continua desligada + texto com `<script>` em `error_code` semeado aparece escapado.
- Os botões Salvar e Ver interações têm `cv-touch-target`; nenhum `text-align: right|center` inline.
- SUITE com `Failed: 0`; nenhum erro novo em relação a `baseline/php-lint.txt`.

**Validação**
- SUITE `| /usr/bin/grep -E 'AiSettingsScreensIntegrationTest|Failed:'` (evidência: OK e `Failed: 0`)
- `/usr/bin/grep -cE "text-align: *(right|center)" src/app/control/clinic/AiSettingsForm.php src/app/control/clinic/AiInteractionList.php` (evidência: `0` nos dois)
- LINT dos 3 arquivos (evidência: `No syntax errors detected`)

### T-14 — Atendimento: resumo do paciente e anamnese com ditado, revisão humana; remove a porta antiga e o no-op

**Camada:** frontend
**Dependências:** T-02, T-10, T-11
**Paralelizável:** sim
**Complexidade:** alta
**Agente:** Aang

**Arquivos prováveis**
- `src/app/control/clinic/EncounterView.php`
- `src/app/Core/Assistant/Contract/AiClinicalAssistantInterface.php`
- `src/app/Core/Assistant/NullAiClinicalAssistant.php`
- `src/tests/Unit/EncounterServiceTest.php`
- `src/tests/Integration/EncounterAiIntegrationTest.php`

**Notas de implementação**
- Declaração do ditado (ruling do usuário, onda 2): passar o aviso traduzido em `data-label-notice` no botão de ditado (o JS da T-10 o exibe junto ao botão com `aria-describedby`); o teste da tela confere o atributo.
- Remover as duas instâncias de `NullAiClinicalAssistant` (:312 e :1623), o método `onAcceptAiSummary` (substituído por `onAiDecide`), os arquivos `AiClinicalAssistantInterface.php` e `NullAiClinicalAssistant.php` (`git rm`) e os dois testes deles em `EncounterServiceTest.php` (:140-150 e o `use`). `suggestionsPanel` (próximos passos, 8A.2) sai junto.
- `aiPanel` passa a ser renderizado só quando `AiAssistService::forContext(...)->isAvailable()` é verdadeiro e o usuário tem a ação (falha do `forContext` → painel oculto). Duas seções, com o selo `.cv-ai-origin` "Gerado por IA — revise antes de salvar" e "Modelo <model> · prompt <versão>" em toda sugestão:
  - **Resumo do paciente**: botão "Resumir histórico" → `onAiPatientSummary`; sugestão num textarea editável `ai_final_text` com botões Aceitar, Salvar edição e Descartar (`onAiDecide`). O resumo aceito continua no bloco existente (`aiSummaryText`/`aiSummaryAcceptedAt`) com "Resumo gerado por IA, revisado em <data>".
  - **Organizar anamnese**: textarea `ai_free_text` (não autosalvo, fora de `DRAFT_FIELDS`), botão de ditado `ai_dictation_button` ligado por `window.CvDictation.attach('ai_dictation_button', 'ai_free_text', <pt-BR|en-US>, <mensagem>)` com `<script src="app/templates/adminbs5/js/cv-ai-dictation.js">`, botão "Organizar" → `onAiOrganizeAnamnesis` (POST). Sugestão editável com "Aplicar à anamnese" e Descartar. Depois de aplicar, um aviso "Anamnese contém texto organizado por IA em <data>" (via `latestDecided('anamnesis_organize', encounter_id)`).
- `onAiDecide` (POST `encounter_id`, `interaction_id`, `decision` ∈ `accepted|edited|discarded`, `ai_final_text`, `anamnesis_text`), numa transação: `AiAssistService::decide`; para `patient_summary` aceito/editado → `EncounterService::acceptAiSummary($encounterId, $finalText)`; para `anamnesis_organize` aceito/editado → `EncounterService::autosave($encounterId, ['anamnesis_text' => <anamnesis_text postado, trim>  === '' ? $final : <postado> . "\n\n" . $final])`; recarrega `EncounterView::onReload` com `encounter_id`. Interação de outro atendimento (`subjectId()` ≠ `encounter_id`) → `AiInteractionNotFoundException`.
- Falha (`AiUnavailableException`, `AiInteractionNotFoundException`, `AiSubjectNotFoundException`): `TMessage('warning', CvFormat::userError($e))` e a tela continua; nenhum `error_log` com texto clínico (só classe e motivo).
- Texto da sugestão sempre com `htmlspecialchars`; textarea com o texto escapado. Botões `cv-touch-target`; rótulos à esquerda; textos `_t('<en>')` com anotação no board.

**Interface**
- Produz: rotas `EncounterView&method=onAiPatientSummary&encounter_id=<id>`, `EncounterView&method=onAiOrganizeAnamnesis` (POST `encounter_id`, `ai_free_text`) e `EncounterView&method=onAiDecide` (POST `encounter_id`, `interaction_id`, `decision`, `ai_final_text`, `anamnesis_text`); campos `ai_free_text`, `ai_final_text`, botão `ai_dictation_button`
- Consome: T-11 `AiAssistService::forContext(TenantContext $context, PDO $connection, string $locale): self`
- Consome: T-02 `AiAssistantInterface::summarizePatient(int $encounterId): AiSuggestion`, `AiAssistantInterface::organizeAnamnesis(int $encounterId, string $freeText): AiSuggestion`, `AiAssistantInterface::decide(int $interactionId, string $decision, ?string $finalText): AiInteraction`, `AiAssistantInterface::latestDecided(string $purpose, int $subjectId): ?AiInteraction`, `AiAssistantInterface::isAvailable(): bool`
- Consome: T-10 `window.CvDictation.attach(buttonId, textareaId, lang, unsupportedMessage)`, classes CSS `.cv-ai-panel`, `.cv-ai-origin`, `.cv-ai-actions`, `.cv-ai-status`

**Teste RED**
- `src/tests/Integration/EncounterAiIntegrationTest.php` — em subprocesso, com IA ligada no tenant de teste e `AI_PROVIDER=stub`, a renderização do atendimento contém `ai_free_text`, `ai_dictation_button`, `cv-ai-dictation.js` e `cv-ai-origin`; `onAiDecide` de uma sugestão de anamnese aceita com `anamnesis_text` postado `Texto digitado` grava `encounter.anamnesis_text` = `Texto digitado` + linha em branco + sugestão; com IA desligada nenhum dos campos aparece, falhando antes porque os métodos e o painel não existem (comando: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php | /usr/bin/grep -E 'EncounterAiIntegrationTest|EncounterServiceTest|Failed:'`)

**Critério de aceite**
- `EncounterAiIntegrationTest` passa com os casos do RED + resumo aceito grava `ai_summary_text` e `ai_summary_accepted_at` + descarte não altera `encounter` + `AI_STUB_BEHAVIOR=timeout` mostra o aviso de IA indisponível e o atendimento continua renderizado + sugestão com `<script>alert(1)</script>` aparece escapada.
- `/usr/bin/grep -rn "NullAiClinicalAssistant\|AiClinicalAssistantInterface" src/app src/tests` não imprime nada.
- SUITE com `Failed: 0` (o total cai 2 pelos testes do no-op removidos e sobe pelos novos); nenhum erro novo em relação a `baseline/php-lint.txt`.

**Validação**
- SUITE `| /usr/bin/grep -E 'EncounterAiIntegrationTest|EncounterServiceTest|Failed:'` (evidência: OK e `Failed: 0`)
- `/usr/bin/grep -rn "NullAiClinicalAssistant\|AiClinicalAssistantInterface" src/app src/tests` (evidência: nenhuma linha)
- LINT de `EncounterView.php`, `EncounterServiceTest.php` e `EncounterAiIntegrationTest.php` (evidência: `No syntax errors detected`)
- Review Focus: caso `AI_STUB_BEHAVIOR=timeout` → tela com aviso, `anamnesis_text` e `ai_summary_text` iguais a antes, uma linha `failed` com `error_code` `ai_timeout` (evidência: linha do teste OK)
- Review Focus: caso anamnese com texto postado não salvo + aplicar → texto combinado gravado; o teste roda `EncounterService::autosave` com o `anamnesis_text` recarregado e o valor continua combinado (evidência: linha do teste OK)

### T-15 — Resultado de exame: resumo para revisão, original intacto

**Camada:** frontend
**Dependências:** T-02, T-10, T-11
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Darwin

**Arquivos prováveis**
- `src/app/control/clinic/ExamResultForm.php`
- `src/tests/Integration/ExamResultAiIntegrationTest.php`

**Notas de implementação**
- O painel de IA aparece só para um `exam_result` já salvo e com `structured_result_text` não vazio, e só com `isAvailable()` verdadeiro. Botão "Resumir exame" → `onAiExamSummary&exam_result_id=<id>`; sugestão editável com Aceitar, Salvar edição e Descartar (`onAiDecide`, POST `exam_result_id`, `interaction_id`, `decision`, `ai_final_text`).
- O resumo aceito/editado fica só em `ai_interaction.final_text`. A tela mostra o mais recente (`latestDecided('exam_summary', exam_result_id)`) num bloco `.cv-ai-panel` com o selo "Resumo gerado por IA, revisado em <data>" e a frase "O resultado original não foi alterado". Nenhuma escrita em `exam_result` ou `exam_request` pelo fluxo de IA.
- Interação de outro exame (`subjectId()` ≠ `exam_result_id`) → `AiInteractionNotFoundException`. Falhas viram `TMessage('warning', CvFormat::userError($e))` e o formulário continua. Escape, `cv-touch-target` e rótulos à esquerda como na T-14.

**Interface**
- Produz: rotas `ExamResultForm&method=onAiExamSummary&exam_result_id=<id>` e `ExamResultForm&method=onAiDecide` (POST `exam_result_id`, `interaction_id`, `decision`, `ai_final_text`)
- Consome: T-11 `AiAssistService::forContext(TenantContext $context, PDO $connection, string $locale): self`
- Consome: T-02 `AiAssistantInterface::summarizeExamResult(int $examResultId): AiSuggestion`, `AiAssistantInterface::decide(int $interactionId, string $decision, ?string $finalText): AiInteraction`, `AiAssistantInterface::latestDecided(string $purpose, int $subjectId): ?AiInteraction`, `AiAssistantInterface::isAvailable(): bool`
- Consome: T-10 classes CSS `.cv-ai-panel`, `.cv-ai-origin`, `.cv-ai-actions`, `.cv-ai-status`

**Teste RED**
- `src/tests/Integration/ExamResultAiIntegrationTest.php` — em subprocesso com `AI_PROVIDER=stub` e IA ligada, `onAiExamSummary` seguido de `onAiDecide` `edited` deixa `exam_result.structured_result_text` byte a byte igual ao de antes e a tela recarregada mostra o texto editado com `cv-ai-origin`, falhando antes porque os métodos não existem (comando: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php | /usr/bin/grep -E 'ExamResultAiIntegrationTest|Failed:'`)

**Critério de aceite**
- `ExamResultAiIntegrationTest` passa com o caso do RED + resultado sem texto estruturado não mostra o botão + descarte não exibe resumo + `interaction_id` de outro exame mostra `AI suggestion not found` + IA desligada esconde o painel.
- SUITE com `Failed: 0`; nenhum erro novo em relação a `baseline/php-lint.txt`.

**Validação**
- SUITE `| /usr/bin/grep -E 'ExamResultAiIntegrationTest|Failed:'` (evidência: OK e `Failed: 0`)
- `/usr/bin/grep -nE "UPDATE exam_result|->save\(" src/app/control/clinic/ExamResultForm.php` (evidência: nenhuma linha nova dentro de `onAiExamSummary`/`onAiDecide`)
- LINT dos 2 arquivos (evidência: `No syntax errors detected`)

### T-16 — Navegação: itens de IA no menu Settings

**Camada:** frontend
**Dependências:** T-04
**Paralelizável:** sim
**Complexidade:** simples
**Agente:** Jaspion

**Arquivos prováveis**
- `src/menu.xml`
- `src/tests/Integration/AiNavigationIntegrationTest.php`

**Notas de implementação**
- `src/menu.xml`, no bloco `_t{Settings}` (:124): submenu `_t{AI assistant}` com `_t{AI settings}` → `AiSettingsForm` e `_t{AI interactions}` → `AiInteractionList`, ícones Font Awesome já usados no menu.
- Modelo do teste: `src/tests/Integration/DocumentNavigationIntegrationTest.php` (lê `menu.xml` e confere as ações e os programas do seed).

**Interface**
- Produz: nada
- Consome: T-04 programas `AiSettingsForm` (`Central Vet - AI Settings`) e `AiInteractionList` (`Central Vet - AI Interaction List`), cada um em `system_group_program` só do grupo 1

**Teste RED**
- `src/tests/Integration/AiNavigationIntegrationTest.php` — `menu.xml` tem `<action>AiSettingsForm</action>` e `<action>AiInteractionList</action>` dentro de `_t{Settings}`, e o seed registra os dois controllers, falhando antes porque o menu não tem os itens (comando: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php | /usr/bin/grep -E 'AiNavigationIntegrationTest|Failed:'`)

**Critério de aceite**
- `AiNavigationIntegrationTest` passa; `xmllint --noout src/menu.xml` sai com código 0 (ou `php -r 'simplexml_load_file(...)'` no container, se `xmllint` faltar).
- SUITE com `Failed: 0`.

**Validação**
- SUITE `| /usr/bin/grep -E 'AiNavigationIntegrationTest|Failed:'` (evidência: OK e `Failed: 0`)
- `/usr/bin/grep -cE "<action>(AiSettingsForm|AiInteractionList)</action>" src/menu.xml` (evidência: `2`)

### T-17 — i18n pt/en das telas e mensagens de domínio da 8A

**Camada:** frontend
**Dependências:** T-11, T-12, T-13, T-14, T-15, T-16
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Levi

**Arquivos prováveis**
- `src/app/config/translations.json`
- `src/app/Core/Presentation/UserMessage.php`
- `src/tests/Unit/UserMessageTest.php`

**Notas de implementação**
- Declaração do ditado (ruling do usuário, onda 2): chave i18n pt/en do aviso de envio do áudio ao serviço de voz do navegador (`data-label-notice`) e do termo de IA.
- Fonte: as linhas `- [T-xx] i18n: <en> → <pt>` e `- [T-xx] i18n-domínio: <mensagem>` do board, mais `/usr/bin/grep -ohE "_t\('[^']+'\)" ` nos arquivos das T-13..T-16 e os itens de menu `_t{...}` da T-16.
- `translations.json`: objetos `{en, pt}` em ordem alfabética por `en`, sem duplicar chave existente.
- `UserMessage`: os 13 motivos de `AiUnavailableException::REASONS` (pt: ex. `ai_disabled` → "O assistente de IA está desligado para esta clínica."; `ai_timeout` → "A IA não respondeu a tempo. Continue manualmente e tente de novo mais tarde."), `AI suggestion not found` → "Sugestão de IA não encontrada.", `AI subject not found`, `Invalid monthly token quota`, `Unknown AI decision`. Nenhuma mensagem expõe código interno cru ao usuário.
- `UserMessageTest`: casos novos e totais (`STATIC`/`PATTERNS`) atualizados; `assertScreenKeysTranslated` cobre `AiSettingsForm`, `AiInteractionList`, `EncounterView` e `ExamResultForm`.

**Interface**
- Produz: nada
- Consome: nada

**Teste RED**
- `src/tests/Unit/UserMessageTest.php` — os casos novos (13 motivos de IA, `AI suggestion not found` e as chaves de tela das 4 telas) esperam tradução pt e falham antes por `Missing translation` (comando: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php | /usr/bin/grep -E 'UserMessageTest|Failed:'`)

**Critério de aceite**
- `UserMessageTest` passa; `python3 -c 'import json;json.load(open("src/app/config/translations.json"))'` sai com código 0; nenhuma chave `en` duplicada.
- SUITE com `Failed: 0`; nenhum erro novo em relação a `baseline/php-lint.txt`.

**Validação**
- SUITE `| /usr/bin/grep -E 'UserMessageTest|Failed:'` (evidência: OK e `Failed: 0`)
- `python3 -c 'import json,collections;d=json.load(open("src/app/config/translations.json"));c=collections.Counter(x["en"] for x in d);print([k for k,v in c.items() if v>1])'` (evidência: `[]`)
- LINT de `UserMessage.php` e `UserMessageTest.php` (evidência: `No syntax errors detected`)

### T-18 — Runbook de IA, ADR 0004, índice e passo da 0014/env na hospedagem 5.7

**Camada:** docs
**Dependências:** T-01, T-03, T-04, T-07, T-11
**Paralelizável:** sim
**Complexidade:** simples
**Agente:** Gandalf

**Arquivos prováveis**
- `docs/runbooks/ia-assistente.md`
- `docs/runbooks/README.md`
- `docs/runbooks/shared-hosting-mysql57.md`
- `docs/architecture/adr/0004-ai-gateway.md`

**Notas de implementação**
- Declaração do ditado (ruling do usuário, onda 2): runbook/ADR 0004 registram que o ditado usa Web Speech e o áudio vai ao serviço de voz do fabricante do navegador. Corrigir também o cabeçalho da 0014 / runbook: são 9 FKs (não 8).
- `ia-assistente.md`: os 3 casos de uso e a revisão humana; ativação por tenant (termo `2026-10-v1`, cota); variáveis `AI_*`/`ANTHROPIC_*` com padrões (da T-03), sem valor real de chave; como a chave entra só por env; custo (tabela de `AiModelPricing`, micro-USD, `AiInteractionList`); motivos de falha e o que fazer (`ai_auth_failed`, `ai_rate_limited`, `ai_overloaded`, `ai_timeout`, `ai_quota_exceeded`); minimização de PII; consultas SELECT de uso por tenant/mês; stub só fora de produção; desligar em incidente (flag).
- `shared-hosting-mysql57.md`: seção "Migration 0014" com o prefixo `18-` gerado pelo preparador, as variáveis `AI_*` no ambiente da hospedagem, exigência de `ext-curl` e de HTTPS de saída para `api.anthropic.com:443`.
- ADR `0004-ai-gateway.md` (formato das ADRs 0001–0003): contexto, decisão (gateway próprio, curl, síncrono, `claude-sonnet-5-5` com effort `low` configurável, fallback), alternativas (SDK oficial, fila) e consequências.
- `README.md` do runbooks: linha para `ia-assistente.md`.

**Interface**
- Produz: nada
- Consome: nada

**Teste RED**
- sem teste: documentação sem comportamento executável; conferida por grep

**Critério de aceite**
- `ia-assistente.md` cita as 11 variáveis da T-03, os 13 motivos de falha e o termo `2026-10-v1`, e não contém `sk-ant-` seguido de caracteres além de `...`.
- `shared-hosting-mysql57.md` tem a seção da 0014 com `18-` e `api.anthropic.com`.
- `docs/runbooks/README.md` lista `ia-assistente.md`; a ADR 0004 tem as seções Contexto, Decisão e Consequências.

**Validação**
- `/usr/bin/grep -cE "AI_PROVIDER|ANTHROPIC_API_KEY|ANTHROPIC_BASE_URL|AI_MODEL|AI_EFFORT|AI_MAX_OUTPUT_TOKENS|AI_TIMEOUT_SECONDS|AI_CONNECT_TIMEOUT_SECONDS|AI_FALLBACKS|AI_STUB_BEHAVIOR" docs/runbooks/ia-assistente.md` (evidência: ≥ 10)
- `/usr/bin/grep -nE "18-|api.anthropic.com" docs/runbooks/shared-hosting-mysql57.md` (evidência: as duas ocorrências na seção da 0014)
- `/usr/bin/grep -c "ia-assistente.md" docs/runbooks/README.md` (evidência: `1`)

### T-19 — Validação final ponta a ponta e SQL de limpeza `F8A teste`

**Camada:** qa
**Dependências:** T-17, T-18
**Paralelizável:** não
**Complexidade:** média
**Agente:** Sherlock

**Arquivos prováveis**
- `.claude/tasks/mar-20261007-1508-fase-8a-ai-gateway/sql/T-19-cleanup.sql`

**Notas de implementação**
- **Limpeza** (modelo: `.claude/tasks/mar-20261006-1347-fase-7b-documentos/sql/T-21-cleanup.sql`): `SET NAMES utf8mb4;`, `START TRANSACTION`, tabelas temporárias `tmp_f8a_*` com os ids dos pacientes `LIKE 'F8A teste%'` do tenant 1, dos tutores deles, dos atendimentos, pedidos e resultados de exame; `DELETE` de `ai_interaction` cujos `subject_type`/`subject_id` apontam para esses atendimentos e resultados; depois os registros clínicos de teste na ordem das FKs; `feature_flag`/`ai_tenant_setting` do tenant 1 devolvidos ao estado anotado em `notes.md § Bloqueios` (UPDATE/DELETE com `WHERE tenant_id = 1` e chave); SELECTs de contagem antes e depois; `COMMIT` comentado. `audit_log` fica (sem FK; registro de auditoria).
- **E2E** (feito pelo validador; ver Estratégia de execução no plan.md): Roteiro A (3 casos de uso + configuração, desktop e tablet 820×1180, ditado sem suporte) e Roteiro B (Review Focus, permissão negada, IA desligada, cota esgotada, `AI_STUB_BEHAVIOR=timeout`), ambos com o stub. Roteiro C (API real): o orquestrador pede a `ANTHROPIC_API_KEY` ao usuário antes da Onda 6, o usuário a põe no `.env` local e o orquestrador recria o app sem o override do stub; no máximo 3 chamadas (uma por caso de uso) no paciente `F8A teste Paciente`. A chave nunca é impressa: a conferência é por `grep -cF` com o valor lido do `.env` dentro do próprio comando. Depois do Roteiro C, o app volta ao stub.
- As `ai_interaction` do Roteiro C também entram na limpeza (mesmos sujeitos `F8A teste`).
- Executar a limpeza é só do orquestrador, com aprovação SQL.

**Interface**
- Produz: nada
- Consome: nada

**Teste RED**
- sem teste: task de validação E2E e SQL de limpeza preparada; a evidência é o roteiro do validador e o dry run do orquestrador

**Critério de aceite**
- `T-19-cleanup.sql` começa com `SET NAMES utf8mb4;`, tem `COMMIT` comentado, nenhum `DELETE` sem `WHERE` e todo `DELETE` de tabela clínica restrito ao tenant 1 e a ids de `tmp_f8a_*`.
- Roteiro A: as 4 telas renderizam em 1366×768 e 820×1180; console sem mensagem de nível error; rede sem resposta ≥ 400; botões de IA com altura ≥ 44 px; rótulos com `text-align` `left`/`start`; botão de ditado desabilitado com aviso no navegador sem Web Speech.
- Roteiro B: os 5 itens de Review Focus observados na tela e por SELECT em `ai_interaction`/`encounter`/`exam_result`; `docker compose logs --since 30m app` sem `f8a.teste@example.invalid`, `F8A teste Tutor`, `x-api-key` nem `sk-ant-`.
- Roteiro C: no máximo 3 linhas novas com `provider` `anthropic`, cada uma com `model` `claude-sonnet-5-5` (ou o de fallback), `input_tokens` > 0, `output_tokens` > 0, `cost_micro_usd` > 0 e `status` `pending_review` ou decidida; a contagem do valor da chave em `docker compose logs --since 30m app`, em `reviews/`, em `reports/` e em `SELECT` de `ai_interaction`/`audit_log` é `0`.
- Contagens de `encounter`, `exam_result`, `patient` e `audit_log` só crescem até a limpeza; depois do dry run da limpeza, voltam ao valor anotado antes do gate (exceto `audit_log`).

**Validação**
- `cd /var/www/html/centralvet/.claude/tasks/mar-20261007-1508-fase-8a-ai-gateway && /usr/bin/grep -v '^--' sql/T-19-cleanup.sql | /usr/bin/grep -m1 -v '^[[:space:]]*$'` (evidência: `SET NAMES utf8mb4;`)
- `/usr/bin/grep -ciE "^delete from [a-z_]+;$|^commit;" .claude/tasks/mar-20261007-1508-fase-8a-ai-gateway/sql/T-19-cleanup.sql` (evidência: `0`)
- Relatórios do validador dos Roteiros A e B em `reviews/` (evidência: cada item com captura ou SELECT)
- `docker compose logs --since 30m app 2>&1 | /usr/bin/grep -cE "f8a.teste@example.invalid|F8A teste Tutor|x-api-key|sk-ant-"` (evidência: `0`)
- `k=$(sed -n 's/^ANTHROPIC_API_KEY=//p' .env); docker compose logs --since 30m app 2>&1 | /usr/bin/grep -cF "$k"; /usr/bin/grep -rcF "$k" .claude/tasks/mar-20261007-1508-fase-8a-ai-gateway | /usr/bin/grep -v ':0$' | wc -l` (evidência: `0` e `0`, sem imprimir o valor)
- `SELECT provider, model, input_tokens, output_tokens, cost_micro_usd, status FROM ai_interaction WHERE tenant_id = 1 AND provider = 'anthropic'` (evidência: ≤ 3 linhas, todas com tokens e custo > 0)

## Legenda

- [ ] pendente
- [~] em andamento
- [x] concluído com evidência
- [!] bloqueado
