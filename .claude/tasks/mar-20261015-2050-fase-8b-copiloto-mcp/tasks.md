# Tasks

| ID | Camada | Descrição | Dependências | Paralelizável | Complexidade | Agente | Status |
|---|---|---|---|---|---|---|---|
| T-01 | database | Migration 0015 (conversa, mensagem, `mcp_tool_call`, CHECKs de `ai_interaction`) e `provision.sh` | — | sim | média | Darwin | [x] |
| T-02 | backend | Gateway com tools: mensagens, `tools`, `tool_use`, stub roteirizado | — | sim | alta | Aang | [x] |
| T-03 | backend | Finalidades do copiloto, status `answered` e prompts versionados | — | sim | média | Platão | [x] |
| T-04 | backend | Núcleo de ferramentas: `AiTool`, contexto, resultado, validador, registry | — | sim | alta | Athena | [x] |
| T-05 | backend | Domínio e contratos de conversa e auditoria, com fakes | — | sim | média | Spock | [x] |
| T-06 | backend | Leitura clínica por tenant e unidade (`CopilotClinicalQuery`) | — | sim | alta | Sherlock | [x] |
| T-07 | frontend | Traduções pt/en das telas do copiloto | — | sim | simples | Maquiavel | [x] |
| T-08 | backend | `ToolPolicy` fail-closed, rate limit, limites de saída, PII e auditoria | T-04, T-05 | sim | alta | Jaspion | [x] |
| T-09 | backend | Repositórios PDO de conversa e de `mcp_tool_call` | T-01, T-05 | sim | média | Levi | [x] |
| T-10 | backend | Ferramentas `buscar_paciente`, `obter_paciente`, `obter_historico_atendimentos`, `obter_prontuario` | T-04, T-06 | sim | média | Yoda | [x] |
| T-11 | backend | Ferramentas `obter_prescricoes`, `obter_exames`, `consultar_vacinacao` | T-04, T-06 | sim | média | Naruto | [x] |
| T-12 | backend | Retenção de 90 dias das conversas (comando de cron) | T-01, T-05 | sim | média | Thanos | [x] |
| T-13 | backend | `AiCopilotService` (laço de tool use) e `ClinicalToolset` | T-02, T-03, T-04, T-05, T-06, T-08, T-09, T-10, T-11 | não | alta | Batman | [x] |
| T-14 | frontend | Painel do copiloto em cortina lateral (`AiCopilotPanel`) | T-07, T-13 | sim | alta | Tesla | [x] |
| T-15 | frontend | "Minhas conversas", auditoria de ferramentas e rótulos da 8A | T-03, T-07, T-09, T-13 | sim | média | Hegel | [x] |
| T-16 | frontend | Botão "Copiloto" em `PatientForm` e `EncounterView` | T-07, T-14 | sim | simples | Kratos | [x] |
| T-17 | frontend | Menu de IA, seed de programas e testes de navegação/chaves | T-14, T-15 | sim | média | Saitama | [x] |
| T-18 | docs | Runbook do copiloto (8B.1), ADR 0007 e MySQL 5.7 | T-01, T-12, T-13, T-14, T-15 | sim | média | Gandalf | [x] |
| T-19 | backend | Ferramentas `consultar_agenda` e `verificar_horarios` | T-04 | sim | alta | Arquimedes | [x] |
| T-20 | backend | Ferramenta `consultar_estoque` | T-04 | sim | média | Goku | [x] |
| T-21 | backend | Ferramentas `listar_debitos` e `indicadores_clinica` | T-04 | sim | alta | Ada | [x] |
| T-22 | frontend | Flag `ai_copilot_management`: serviço e interruptor em `AiSettingsForm` | T-04, T-07, T-15 | sim | média | Curie | [x] |
| T-23 | backend | `ManagementToolset`, registro no serviço e item "Copiloto de gestão" | T-07, T-13, T-17, T-19, T-20, T-21 | sim | média | Lamport | [x] |
| T-24 | docs | Runbook da 8B.2 (gestão) | T-18, T-19, T-20, T-21, T-22 | sim | simples | Gandalf | [x] |
| T-25 | backend | Correção: PII minimizada antes do corte de texto e visibilidade das ferramentas em cache | T-04, T-08, T-10, T-11, T-13 | sim | alta | Noether | [x] |
| T-26 | frontend | Correção: disponibilidade leve do copiloto nas telas hospedeiras | T-13, T-16 | sim | média | Hamilton | [x] |
| T-27 | backend | Correção: horários livres só de profissionais clínicos, distribuídos no dia | T-19 | sim | média | Gauss | [x] |
| T-28 | backend | Correção: histórico legível sem IA, resposta minimizada, uso e auditoria gravados sempre | T-25, T-26 | não | alta | Dijkstra | [x] |
| T-29 | frontend | Correção: painel e lista abrem conversa sem IA, mostram a resposta gravada e apagam só por POST | T-26, T-28 | não | alta | Knuth | [ ] |

## Detalhamento

### T-01 — Migration 0015 (conversa, mensagem, `mcp_tool_call`, CHECKs de `ai_interaction`) e `provision.sh`

**Camada:** database
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Darwin

Modelo: `20261007_0014_phase8a_ai_gateway.sql` (cabeçalho PREPARED ONLY com Target, Effects, Risk e Rollback; `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci`; `tenant_id bigint unsigned`, `system_unit_id int`, `*_user_id int`, `timestamp(6)` com `DEFAULT CURRENT_TIMESTAMP(6)`; constraints nomeadas `<tabela>_<x>_fk|_idx|_ck`; `INSERT INTO schema_migrations` com checksum de 64 zeros). Ampliação de CHECK existente como na 0010/0011: `ALTER TABLE ... DROP CHECK <nome>` e depois `ADD CONSTRAINT <nome> CHECK (...)`, cada um em sua instrução, para o preparador 5.7 trocar por `DROP TRIGGER IF EXISTS` + `CREATE TRIGGER`. CHECKs sem `BETWEEN`, `LIKE` nem funções.

**Arquivos prováveis**
- `src/app/database/migrations/20261015_0015_phase8b_copilot.sql`
- `src/app/database/migrations/20261015_0015_phase8b_copilot.verify.sql`
- `scripts/test-db/provision.sh`

**Interface**
- Produz: tabela `ai_conversation` (`id bigint unsigned AUTO_INCREMENT`, `tenant_id`, `system_unit_id`, `system_user_id`, `family varchar(20)`, `anchor_patient_id bigint unsigned NULL`, `title varchar(80) NOT NULL`, `created_at`, `updated_at`; `KEY ai_conversation_owner_idx (tenant_id, system_user_id, updated_at)`, `KEY ai_conversation_updated_idx (updated_at)`; FKs para `tenant`, `system_unit`, `system_users` RESTRICT e `anchor_patient_id` → `patient(id)` `ON DELETE SET NULL`; `ai_conversation_family_ck CHECK (family IN ('clinical', 'management'))`, `ai_conversation_anchor_ck CHECK (family = 'clinical' OR anchor_patient_id IS NULL)`)
- Produz: tabela `ai_conversation_message` (`id`, `tenant_id`, `conversation_id` FK `ON DELETE CASCADE`, `role varchar(20)`, `content mediumtext NOT NULL`, `tool_summary varchar(2000) NULL`, `failed tinyint(1) NOT NULL DEFAULT 0`, `created_at`; `KEY ai_conversation_message_conversation_idx (conversation_id, id)`; `ai_conversation_message_role_ck CHECK (role IN ('user', 'assistant'))`, `ai_conversation_message_failed_ck CHECK (failed IN (0, 1) AND (role = 'assistant' OR failed = 0))`)
- Produz: tabela `mcp_tool_call` (`id`, `tenant_id`, `system_unit_id`, `system_user_id`, `conversation_id bigint unsigned NULL` sem FK, `correlation_id varchar(64)`, `tool_name varchar(60)`, `input_minimized text NOT NULL`, `decision varchar(10)`, `deny_reason varchar(30) NULL`, `row_count int unsigned NOT NULL DEFAULT 0`, `truncated tinyint(1) NOT NULL DEFAULT 0`, `duration_ms int unsigned NOT NULL DEFAULT 0`, `created_at`; índices `(tenant_id, created_at)`, `(system_user_id, created_at)`, `(tool_name, created_at)`, `(correlation_id)`; `mcp_tool_call_decision_ck CHECK ((decision = 'allowed' AND deny_reason IS NULL) OR (decision = 'denied' AND deny_reason IS NOT NULL))`, `mcp_tool_call_truncated_ck CHECK (truncated IN (0, 1))`)
- Produz: CHECKs de `ai_interaction` ampliados: `purpose` + `copilot_clinical`, `copilot_management`; `subject_type` + `ai_conversation`; `status` + `answered`; `ai_interaction_failed_ck` aceita `status = 'answered' AND error_code IS NULL AND suggestion_text IS NULL`; `ai_interaction_decided_ck` e `ai_interaction_final_ck` tratam `answered` como `pending_review` (sem decisor, sem `final_text`)
- Produz: `provision.sh` com `$migrations_dir/20261015_0015_phase8b_copilot.sql` depois da 0014
- Consome: nada

**Teste RED**
- sem teste: migration pura e script de provisionamento; a prova é o preparador 5.7, o `--check` do provisionamento e o `.verify.sql` no `centralvet_test` depois do gate

**Critério de aceite**
- `python3 scripts/prepare-mysql57.py "$d" "$d/out"` com a 0015 copiada como `19-20261015_0015_phase8b_copilot.sql` sai com código 0 e o `.sql` gerado contém `CREATE TRIGGER` para `ai_conversation`, `ai_conversation_message`, `mcp_tool_call` e, para os 6 CHECKs de `ai_interaction`, `DROP TRIGGER IF EXISTS` antes do `CREATE TRIGGER` de mesmo nome.
- `python3 scripts/test-prepare-mysql57.py` termina com código 0.
- `bash scripts/test-db/provision.sh --check` lista a 0015 e termina com código 0.
- `.verify.sql` só tem `SELECT` (`/usr/bin/grep -ciE '^\s*(insert|update|delete|alter|create|drop)' <verify>` → `0`).

**Validação**
- `d=$(mktemp -d) && cp src/app/database/migrations/20261015_0015_phase8b_copilot.sql "$d/19-20261015_0015_phase8b_copilot.sql" && cp src/app/database/migrations/20261015_0015_phase8b_copilot.verify.sql "$d/" && python3 scripts/prepare-mysql57.py "$d" "$d/out" && /usr/bin/grep -n "TRIGGER IF EXISTS\|CREATE TRIGGER" "$d/out/19-20261015_0015_phase8b_copilot.sql"` (evidência: os 6 pares drop → create de `ai_interaction_*_ck` e os triggers das 3 tabelas novas)
- `python3 scripts/test-prepare-mysql57.py; echo "exit=$?"` (evidência: `exit=0`)
- `bash scripts/test-db/provision.sh --check; echo "exit=$?"` (evidência: `exit=0`)
- Depois do gate (orquestrador): `.verify.sql` no `centralvet_test` mostra as 3 tabelas com 0 linhas e as linhas anteriores de `ai_interaction` com a mesma contagem de antes da aplicação (nenhum registro perdido).

### T-02 — Gateway com tools: mensagens, `tools`, `tool_use`, stub roteirizado

**Camada:** backend
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** alta
**Agente:** Aang

Hoje `AnthropicMessagesGateway::parse` (:101-150) aceita só `end_turn`/`stop_sequence` e manda um único `user` com string (:68-74). Com `messages` e `tools` vazios o payload tem de continuar idêntico ao da 8A (assistente atual). Os testes desta task usam a finalidade `patient_summary` (as finalidades do copiloto chegam na T-03, mesma onda).

**Arquivos prováveis**
- `src/app/Core/Domain/AiCompletionRequest.php`
- `src/app/Core/Domain/AiCompletionResult.php`
- `src/app/Core/Domain/AiUsage.php`
- `src/app/Core/Ai/AnthropicMessagesGateway.php`
- `src/app/Core/Ai/StubAiGateway.php`
- `src/tests/Unit/AnthropicMessagesGatewayTest.php`
- `src/tests/Unit/StubAiGatewayScriptTest.php`

**Interface**
- Produz: `AiCompletionRequest::__construct(string $purpose, string $promptVersion, string $system, string $userContent, array $messages = [], array $tools = [])` — `$messages` é `list<array{role: 'user'|'assistant', content: string|list<array>}>` com blocos no formato da Messages API (`text`, `tool_use` com `id`/`name`/`input`, `tool_result` com `tool_use_id`/`content`/`is_error`); `$tools` é `list<array{name: string, description: string, input_schema: array}>`; com `$messages` não vazio o gateway ignora `$userContent`
- Produz: `AiCompletionResult::__construct(string $text, string $provider, string $model, AiUsage $usage, ?int $costMicroUsd, string $stopReason, ?string $providerRequestId, array $toolUses = [], array $content = [])` — `toolUses` é `list<array{id: string, name: string, input: array}>`; `content` são os blocos do assistente como vieram (para reenviar na rodada seguinte)
- Produz: `stopReason === 'tool_use'` aceito só quando a requisição tem `tools`; `text` pode ser vazio nesse caso; sem `tools`, `tool_use` continua `ai_provider_error`
- Produz: `AiUsage::plus(AiUsage $other): AiUsage`
- Produz: `StubAiGateway::scripted(array $steps): self` (`$steps` = `list<AiCompletionResult|AiProviderException>`, consumidos em ordem; passo a mais lança `AiProviderException('ai_provider_error')`) e `StubAiGateway::requests(): array` (as `AiCompletionRequest` recebidas); o stub sem roteiro com `messages` ecoa o texto da última mensagem `user`
- Consome: nada

**Teste RED**
- `src/tests/Unit/AnthropicMessagesGatewayTest.php` — requisição com `tools` e `messages` (um `tool_result` com `is_error: true`) serializa `tools` e as mensagens no body JSON do transporte, e a resposta `{"stop_reason":"tool_use","content":[{"type":"tool_use","id":"toolu_1","name":"buscar_paciente","input":{"termo":"Thor"}}]}` vira `AiCompletionResult` com `stopReason` `tool_use` e `toolUses[0]['name'] === 'buscar_paciente'`; falha antes porque o gateway devolve `ai_provider_error` e o construtor não aceita `tools` (comando: `SUITE | /usr/bin/grep -E 'AnthropicMessagesGateway|StubAiGatewayScript|Failed:'`)

**Critério de aceite**
- Body JSON com `tools` = definições recebidas e `messages` = lista recebida; sem `tools`/`messages`, o body é igual ao de hoje (teste compara o JSON de uma requisição da 8A antes/depois).
- `stop_reason: tool_use` com `tools` → `toolUses` com `id`, `name`, `input`; sem `tools` → `AiProviderException` `ai_provider_error`.
- `StubAiGateway::scripted([...])` devolve os passos em ordem e registra as requisições; passo extra → `ai_provider_error`.
- `AiUsage::plus` soma os 4 contadores.

**Validação**
- `SUITE | /usr/bin/grep -E 'AnthropicMessagesGateway|StubAiGatewayScript|AiAssistService|Failed:'` (evidência: `PASS` dos casos novos, os casos da 8A ainda `PASS` e `Failed: 0`)
- `LINT app/Core/Ai/AnthropicMessagesGateway.php`, `LINT app/Core/Ai/StubAiGateway.php`, `LINT app/Core/Domain/AiCompletionRequest.php` (evidência: `No syntax errors detected`)

### T-03 — Finalidades do copiloto, status `answered` e prompts versionados

**Camada:** backend
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Platão

`AiPurpose::ALL` continua com as 3 finalidades da 8A (`AiDomainTest.php:28` e o `match` de `AiPromptCatalog::build`). O copiloto grava uma linha de `ai_interaction` por pergunta, sem texto (`suggestion_text` e `final_text` nulos).

**Arquivos prováveis**
- `src/app/Core/Domain/AiPurpose.php`
- `src/app/Core/Domain/AiInteraction.php`
- `src/app/Core/Ai/AiPromptCatalog.php`
- `src/tests/Unit/AiDomainTest.php`
- `src/tests/Unit/AiPromptCatalogTest.php`

**Interface**
- Produz: `AiPurpose::COPILOT_CLINICAL = 'copilot_clinical'`, `AiPurpose::COPILOT_MANAGEMENT = 'copilot_management'`, `AiPurpose::COPILOT` (lista das duas), `AiPurpose::SUBJECT_CONVERSATION = 'ai_conversation'`; `AiPurpose::assertValid` aceita `ALL` e `COPILOT`; `AiPurpose::subjectType('copilot_clinical') === 'ai_conversation'`; `AiPurpose::hostAction('copilot_clinical') === 'AiCopilotPanel::onAsk'` (o mesmo para `copilot_management`); `AiPurpose::decideAction` com finalidade do copiloto lança `InvalidArgumentException`
- Produz: `AiInteraction::STATUS_ANSWERED = 'answered'` e `AiInteraction::recordAnswer(AiUsage $usage, ?int $costMicroUsd, string $provider, string $model, string $stopReason, ?string $providerRequestId, int $latencyMs): void` (status `answered`, `suggestion_text` nulo; só a partir de interação aguardando o provedor; `decide` em `answered` lança `InvalidArgumentException`)
- Produz: `AiPromptCatalog::copilotSystem(string $purpose, string $locale): string` e `AiPromptCatalog::VERSIONS` com `'copilot_clinical' => 'copilot_clinical.v1'` e `'copilot_management' => 'copilot_management.v1'`; `AiPromptCatalog::build` com finalidade do copiloto lança `InvalidArgumentException`
- Consome: nada

Prompt do copiloto (pt e en): somente leitura; responda só com dados das ferramentas e diga quando não encontrou; não diagnostique, não prescreva; itens provisórios (rascunho, exame pendente de revisão) citados como tal; não inclua dados pessoais e mantenha marcadores como `[NOME]`; texto simples, curto, no idioma do usuário. O de gestão troca o contexto clínico por agenda, estoque, débitos e indicadores da unidade.

**Teste RED**
- `src/tests/Unit/AiDomainTest.php` — `AiInteraction::start(1, 1, 1, AiPurpose::COPILOT_CLINICAL, 10, 'copilot_clinical.v1', <sha>, 'corr-1')` seguido de `recordAnswer(new AiUsage(100, 20), 5, 'stub', 'stub', 'end_turn', null, 120)` deixa `status() === 'answered'`, `subjectType() === 'ai_conversation'` e `suggestionText() === null`; falha antes porque `AiPurpose::COPILOT_CLINICAL` não existe (comando: `SUITE | /usr/bin/grep -E 'AiDomain|AiPromptCatalog|Failed:'`)

**Critério de aceite**
- `count(AiPurpose::ALL) === 3` e `count(AiPurpose::COPILOT) === 2`.
- `recordAnswer` grava uso, custo, modelo, `stop_reason` e latência; `toRow()['status'] === 'answered'` e `toRow()['suggestion_text'] === null`; `reconstitute` aceita `answered`.
- `AiPromptCatalog::copilotSystem('copilot_clinical', 'pt')` contém "somente leitura" e `copilotSystem('copilot_management', 'en')` está em inglês; hashes dos dois prompts fixados em `AiPromptCatalogTest` como os da 8A.

**Validação**
- `SUITE | /usr/bin/grep -E 'AiDomain|AiPromptCatalog|AiAssistService|Failed:'` (evidência: `PASS` dos casos novos e da 8A, `Failed: 0`)
- `LINT app/Core/Domain/AiPurpose.php`, `LINT app/Core/Domain/AiInteraction.php`, `LINT app/Core/Ai/AiPromptCatalog.php` (evidência: `No syntax errors detected`)

### T-04 — Núcleo de ferramentas: `AiTool`, contexto, resultado, validador, registry

**Camada:** backend
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** alta
**Agente:** Athena

Namespace `CentralVet\Ai\Tools` em `src/app/Core/Ai/Tools/`. Contrato usado por T-08, T-10, T-11, T-13, T-19, T-20, T-21 e T-23. A flag de gestão nasce aqui só como constante (linha ausente = desligada).

**Arquivos prováveis**
- `src/app/Core/Ai/Tools/AiTool.php`
- `src/app/Core/Ai/Tools/ToolContext.php`
- `src/app/Core/Ai/Tools/ToolResult.php`
- `src/app/Core/Ai/Tools/ToolFamily.php`
- `src/app/Core/Ai/Tools/ToolFormat.php`
- `src/app/Core/Ai/Tools/JsonSchemaValidator.php`
- `src/app/Core/Ai/Tools/ToolRegistry.php`
- `src/app/Core/Ai/Tools/ToolEntityNotFoundException.php`
- `src/app/Core/Ai/Tools/ToolInputException.php`
- `src/app/Core/Domain/Contract/FeatureFlagRepositoryInterface.php`
- `src/tests/Support/FakeAiTool.php`
- `src/tests/Unit/JsonSchemaValidatorTest.php`
- `src/tests/Unit/ToolRegistryTest.php`

**Interface**
- Produz: `interface AiTool` com `name(): string`, `description(): string`, `inputSchema(): array`, `requiredPermission(): string`, `family(): string`, `label(string $lang): string`, `assertInScope(ToolContext $context, array $input): void` (lança `ToolEntityNotFoundException` para id fora do tenant/unidade) e `execute(ToolContext $context, array $input): ToolResult` (pode lançar `ToolInputException`)
- Produz: `ToolContext::create(TenantContext $tenant, string $correlationId, ?int $conversationId, string $purpose, ?int $anchorPatientId): ToolContext` (construtor privado; props readonly `tenantId`, `unitId` via `requireUnitId()`, `userId`, `correlationId`, `conversationId`, `purpose`, `anchorPatientId`)
- Produz: `new ToolResult(array $data, bool $truncated = false, array $freeTextFields = [], array $knownNames = [])` com props readonly `data` (`list<array<string, scalar|null>>`), `rowCount`, `truncated`, `freeTextFields`, `knownNames`; `ToolResult::MAX_ROWS = 50`
- Produz: `ToolFamily::CLINICAL = 'clinical'`, `ToolFamily::MANAGEMENT = 'management'`, `ToolFamily::ALL`, `ToolFamily::ALLOWLIST` (`clinical` → `buscar_paciente`, `obter_paciente`, `obter_historico_atendimentos`, `obter_prontuario`, `obter_prescricoes`, `obter_exames`, `consultar_vacinacao`; `management` → `consultar_agenda`, `verificar_horarios`, `consultar_estoque`, `listar_debitos`, `indicadores_clinica`), `ToolFamily::requiredFlags(string $family): array` (`clinical` → `[ai_assistant]`; `management` → `[ai_assistant, ai_copilot_management]`)
- Produz: `ToolFormat::firstName(?string $fullName): ?string`, `ToolFormat::age(?string $birthDate, DateTimeImmutable $today): ?string` (ex.: `3 anos`, `7 meses`), `ToolFormat::excerpt(?string $text, int $max): ?string`
- Produz: `JsonSchemaValidator::validate(array $schema, array $input): array` (lista de erros; vazia = válido). Suporta `type` (`object`, `string`, `integer`, `boolean`), `properties`, `required`, `enum`, `minimum`, `maximum`, `minLength`, `maxLength`, `format: date` (`YYYY-MM-DD` válido); chave de entrada fora de `properties` é erro; palavra-chave de schema não suportada lança `LogicException`
- Produz: `new ToolRegistry(array $tools, FeatureFlagRepositoryInterface $flags, AuthorizationPolicyInterface $authorization, TenantContext $context)`, `ToolRegistry::visible(string $family): array`, `ToolRegistry::definitions(string $family): array` (formato `tools` do T-02) e `ToolRegistry::find(string $family, string $name): ?AiTool` (só visível). Visível = nome na `ALLOWLIST` da família + todas as `requiredFlags` ligadas + `decide(new AuthorizationRequest(context: ..., action: $tool->requiredPermission(), requiresUnitScope: true, resourceTenantId: tenantId, resourceUnitId: unitId, entityType: 'mcp_tool', entityId: null, metadata: ['tool' => name]))->allowed()`
- Produz: `FeatureFlagRepositoryInterface::AI_COPILOT_MANAGEMENT = 'ai_copilot_management'`
- Produz: `new FakeAiTool(string $name, string $family, string $permission, ?ToolResult $result = null, ?Throwable $failure = null, bool $inScope = true)` e `FakeAiTool::executions(): int`
- Consome: nada

**Teste RED**
- `src/tests/Unit/ToolRegistryTest.php` — com `FakeFeatureFlagRepository(['ai_assistant' => true])` e `FakeAuthorizationPolicy(true)`, `visible('clinical')` devolve o `FakeAiTool('buscar_paciente', 'clinical', 'GlobalSearchController::onSearch')` e não devolve `FakeAiTool('consultar_agenda', 'management', 'AgendaView::onReload')` (falta `ai_copilot_management`) nem uma ferramenta fora da allowlist; com a política negando, `visible('clinical')` é vazio; falha antes porque `ToolRegistry` não existe (comando: `SUITE | /usr/bin/grep -E 'ToolRegistry|JsonSchemaValidator|Failed:'`)

**Critério de aceite**
- `ToolRegistry::visible` respeita allowlist, flags da família e RBAC (3 casos negativos e 1 positivo no teste); `definitions` devolve `name`, `description` e `input_schema` de cada visível.
- `JsonSchemaValidator::validate` devolve erro para tipo errado, `required` ausente, `enum` fora, `maximum` excedido, data `2026-02-30` e chave extra; lista vazia para entrada válida.
- `ToolContext::create` com `TenantContext` sem unidade lança a exceção de `requireUnitId()`.

**Validação**
- `SUITE | /usr/bin/grep -E 'ToolRegistry|JsonSchemaValidator|Failed:'` (evidência: `PASS` dos casos e `Failed: 0`)
- `for f in AiTool ToolContext ToolResult ToolFamily ToolFormat JsonSchemaValidator ToolRegistry; do LINT app/Core/Ai/Tools/$f.php; done` (evidência: 7 × `No syntax errors detected`)

### T-05 — Domínio e contratos de conversa e auditoria, com fakes

**Camada:** backend
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Spock

Padrão de entidade da 8A (`AiInteraction`: `start`/`reconstitute`/`toRow`/`assignId`) e de fake (`FakeAiInteractionRepository(int $tenantId)`). O texto que chega a estas classes já vem minimizado (responsabilidade do serviço).

**Arquivos prováveis**
- `src/app/Core/Domain/AiConversation.php`
- `src/app/Core/Domain/AiConversationMessage.php`
- `src/app/Core/Domain/McpToolCall.php`
- `src/app/Core/Domain/Contract/AiConversationRepositoryInterface.php`
- `src/app/Core/Domain/Contract/McpToolCallRepositoryInterface.php`
- `src/tests/Support/FakeAiConversationRepository.php`
- `src/tests/Support/FakeMcpToolCallRepository.php`
- `src/tests/Unit/AiConversationDomainTest.php`

**Interface**
- Produz: `AiConversation::start(int $tenantId, int $systemUnitId, int $systemUserId, string $family, ?int $anchorPatientId, string $title, DateTimeImmutable $now): self` (título cortado em `AiConversation::TITLE_MAX = 80` caracteres com `mb_substr`; `family` em `clinical`/`management`; âncora só em `clinical`), `reconstitute(array $row)`, `toRow()`, `assignId(int $id)`, `touch(DateTimeImmutable $now)` e getters `id()`, `tenantId()`, `systemUnitId()`, `systemUserId()`, `family()`, `anchorPatientId()`, `title()`, `createdAt()`, `updatedAt()`
- Produz: `AiConversationMessage::user(int $conversationId, string $content, DateTimeImmutable $now)`, `AiConversationMessage::assistant(int $conversationId, string $content, array $toolSummary, DateTimeImmutable $now)` (`$toolSummary` = `list<array{tool: string, label: string, count: int}>`, gravado como JSON com no máximo 2000 bytes), `AiConversationMessage::failed(int $conversationId, string $reason, DateTimeImmutable $now)` (papel `assistant`, `failed() === true`, conteúdo = código `ai_*`), `reconstitute`, `toRow` e getters `id()`, `conversationId()`, `role()`, `content()`, `toolSummary()`, `failed()`, `createdAt()`
- Produz: `McpToolCall::record(int $tenantId, int $systemUnitId, int $systemUserId, ?int $conversationId, string $correlationId, string $toolName, string $inputMinimized, string $decision, ?string $denyReason, int $rowCount, bool $truncated, int $durationMs): self`, `McpToolCall::DECISION_ALLOWED = 'allowed'`, `McpToolCall::DECISION_DENIED = 'denied'`, `McpToolCall::DENY_REASONS = ['not_offered', 'invalid_input', 'forbidden', 'not_found', 'rate_limited', 'error', 'timeout']`, `reconstitute`, `toRow`, getters
- Produz: `AiConversationRepositoryInterface` com `insert(AiConversation $conversation): AiConversation`, `findOwned(int $id): ?AiConversation` (tenant + unidade ativa + usuário do contexto; outro = `null`), `touch(AiConversation $conversation): void`, `listOwned(?string $family, ?int $anchorPatientId, int $limit): array` (mais recente primeiro), `deleteOwned(int $id): bool`, `appendMessage(AiConversationMessage $message): AiConversationMessage`, `lastMessages(int $conversationId, int $limit): array` (ordem cronológica)
- Produz: `McpToolCallRepositoryInterface` com `insert(McpToolCall $call): McpToolCall` e `search(?int $systemUserId, ?string $toolName, ?string $decision, ?DateTimeImmutable $from, ?DateTimeImmutable $to, int $limit): array` (tenant do contexto, mais recente primeiro)
- Produz: `new FakeAiConversationRepository(int $tenantId, int $systemUnitId, int $systemUserId)` com `FakeAiConversationRepository::messages(): array`; `new FakeMcpToolCallRepository(int $tenantId)` com `FakeMcpToolCallRepository::all(): array`
- Consome: nada

**Teste RED**
- `src/tests/Unit/AiConversationDomainTest.php` — `AiConversation::start(1, 2, 3, 'clinical', 7, str_repeat('a', 120), $now)` tem `title()` com 80 caracteres; `start(..., 'management', 7, ...)` lança `InvalidArgumentException`; `McpToolCall::record(..., 'denied', null, ...)` lança `InvalidArgumentException`; `FakeAiConversationRepository(1, 2, 3)::findOwned` de conversa do usuário 4 devolve `null`; falha antes porque as classes não existem (comando: `SUITE | /usr/bin/grep -E 'AiConversationDomain|Failed:'`)

**Critério de aceite**
- Invariantes: família válida, âncora só no clínico, título ≤ 80, decisão `denied` exige `deny_reason` em `DENY_REASONS`, `allowed` exige `deny_reason` nulo, `failed` só em `assistant`.
- Fakes: `findOwned`/`listOwned`/`deleteOwned` respeitam tenant, unidade e usuário; `lastMessages(…, 20)` devolve as 20 últimas em ordem cronológica; `search` filtra por usuário, ferramenta, decisão e período.

**Validação**
- `SUITE | /usr/bin/grep -E 'AiConversationDomain|Failed:'` (evidência: `PASS` e `Failed: 0`)
- `LINT app/Core/Domain/AiConversation.php`, `LINT app/Core/Domain/AiConversationMessage.php`, `LINT app/Core/Domain/McpToolCall.php` (evidência: `No syntax errors detected`)

### T-06 — Leitura clínica por tenant e unidade (`CopilotClinicalQuery`)

**Camada:** backend
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** alta
**Agente:** Sherlock

`patient` não tem unidade: paciente da unidade = `EXISTS` `encounter` ou `appointment` do paciente com `system_unit_id = TenantContext::requireUnitId()`. Registros clínicos (atendimentos, prescrições, exames, vacinas) só de atendimentos da unidade. Toda SELECT com `TenantQuery::forTenant` e o hint `/*+ MAX_EXECUTION_TIME(5000) */`. Reaproveite as junções de `ClinicalSummaryReader` (:34, :85), `AiClinicalContextQuery` e `PendingItemQuery::vaccinesDue` (:199). Esta task só lê tabelas existentes (roda na Onda 1).

**Arquivos prováveis**
- `src/app/Core/Domain/Contract/CopilotClinicalQueryInterface.php`
- `src/app/Core/Persistence/CopilotClinicalQuery.php`
- `src/tests/Support/FakeCopilotClinicalQuery.php`
- `src/tests/Integration/CopilotClinicalQueryIntegrationTest.php`

**Interface**
- Produz: `new CopilotClinicalQuery(TenantContext $context, PDO $connection)` implementando `CopilotClinicalQueryInterface` com os métodos abaixo
- Produz: `patientInUnit(int $patientId): bool` e `encounterInUnit(int $encounterId): bool`
- Produz: `searchPatients(string $term, int $limit): array` → `list<array{id: int, name: string, species: string, breed: ?string, birth_date: ?string, tutor_full_name: string}>` (termo só com dígitos = `patient.id`; senão `patient.name` ou `tutor.full_name` `LIKE %termo%`; só pacientes da unidade)
- Produz: `patient(int $patientId): ?array` → `array{id: int, name: string, species: string, breed: ?string, sex: ?string, birth_date: ?string, allergies: ?string, weight_kg: ?string, weight_measured_at: ?string, tutor_full_name: string}` (peso = último `encounter.weight_kg` da unidade, senão `patient.weight_kg`)
- Produz: `encounters(int $patientId, int $limit): array` → `list<array{id: int, started_at: string, status: string, anamnesis_text: ?string, diagnosis_text: ?string, clinical_plan_text: ?string}>` (mais recente primeiro)
- Produz: `encounter(int $encounterId): ?array` → `array{id: int, patient_id: int, started_at: string, status: string, anamnesis_text: ?string, physical_exam_text: ?string, diagnosis_text: ?string, clinical_plan_text: ?string, weight_kg: ?string}`
- Produz: `prescriptions(int $patientId, bool $onlyActive, DateTimeImmutable $today, int $limit): array` → `list<array{prescription_id: int, created_at: string, valid_until: ?string, status: string, medication_name: string, dose: ?string, dose_unit: ?string, route: ?string, frequency: ?string, duration: ?string}>` (ativas = `valid_until >= hoje` ou `valid_until` nulo com `created_at` nos últimos 30 dias)
- Produz: `exams(int $patientId, string $situacao, int $limit): array` com `$situacao` em `pendentes`/`liberados`/`todos` → `list<array{exam_request_id: int, exam_name: string, requested_at: string, status: string, pending_review: ?bool, structured_result_text: ?string, received_at: ?string}>` (pendentes = sem resultado ou `pending_review = 1`; liberados = resultado com `pending_review = 0`)
- Produz: `vaccinations(int $patientId, int $limit): array` → `list<array{vaccine_catalog_item_id: int, vaccine_name: string, applied_at: string, next_dose_at: ?string, superseded: bool}>` (`superseded` = existe aplicação posterior da mesma vacina para o paciente)
- Produz: `tutorNames(int $patientId): array` (nome completo do tutor, para o `PiiMinimizer`)
- Produz: `new FakeCopilotClinicalQuery()` com `FakeCopilotClinicalQuery::seed(string $method, array $rows): void` (por id para `patient`/`encounter`; lista para os demais), `FakeCopilotClinicalQuery::setInUnit(string $kind, int $id, bool $inUnit): void` (`patient`/`encounter`) e `FakeCopilotClinicalQuery::calls(): array`
- Consome: nada

**Teste RED**
- `src/tests/Integration/CopilotClinicalQueryIntegrationTest.php` — com tenant próprio, a unidade A (contexto) e uma segunda unidade B (`otherUnitId` como em `ExamResultAiIntegrationTest.php:715`), um paciente só com atendimento na B: `patientInUnit` → `false`, `patient` → `null`, `searchPatients('<nome>', 20)` sem ele; outro paciente com atendimento na A e na B: `encounters` devolve só o da A; paciente de outro tenant → `null`; falha antes porque `CopilotClinicalQuery` não existe (comando: `SUITE | /usr/bin/grep -E 'CopilotClinicalQuery|Failed:'`)

**Critério de aceite**
- Nenhum método devolve linha de outro tenant ou de atendimento de outra unidade (casos do teste).
- `searchPatients('123', 20)` encontra o paciente `id = 123` da unidade; `limit` é respeitado.
- `prescriptions(..., true, ...)` exclui prescrição com `valid_until` no passado; `exams(..., 'pendentes', ...)` inclui resultado com `pending_review = 1`; `vaccinations` marca `superseded` quando há reforço posterior.
- Nenhuma coluna de CPF, telefone, e-mail ou endereço do tutor sai da query (só `tutor.full_name`).

**Validação**
- `SUITE | /usr/bin/grep -E 'CopilotClinicalQuery|Failed:'` (evidência: `PASS` e `Failed: 0`)
- `LINT app/Core/Persistence/CopilotClinicalQuery.php` (evidência: `No syntax errors detected`)
- `/usr/bin/grep -c "MAX_EXECUTION_TIME(5000)" src/app/Core/Persistence/CopilotClinicalQuery.php` (evidência: número igual ao de SELECTs do arquivo)

### T-07 — Traduções pt/en das telas do copiloto

**Camada:** frontend
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** simples
**Agente:** Maquiavel

`src/app/config/translations.json` é uma lista de `{"en", "pt"}` ordenada por `en` (1411 entradas). Chaves já existentes (`Send`, `Delete`, `Open`, `Title`, `Reason`, `Period`, `User`, `Date`, `Close`, `From`, `To`, `Status`) são reaproveitadas, não duplicadas. Esta lista é o contrato de textos das T-14, T-15, T-16, T-23 (o interruptor da 8B.2 entra na T-22).

**Arquivos prováveis**
- `src/app/config/translations.json`
- `src/tests/Unit/CopilotTranslationsTest.php`

**Interface**
- Produz: chaves `en` → `pt`: `Copilot` → `Copiloto`; `Clinical copilot` → `Copiloto clínico`; `Management copilot` → `Copiloto de gestão`; `Ask the copilot` → `Pergunte ao copiloto`; `Your question` → `Sua pergunta`; `Thinking…` → `Pensando…`; `New conversation` → `Nova conversa`; `Delete conversation` → `Apagar conversa`; `Conversation deleted` → `Conversa apagada`; `Conversation not found` → `Conversa não encontrada`; `My conversations` → `Minhas conversas`; `Previous conversations` → `Conversas anteriores`; `Consulted` → `Consultei`; `The answer failed; you can send the question again` → `A resposta falhou; você pode reenviar a pergunta`; `Answer generated by AI from system data. Check before using.` → `Resposta gerada por IA a partir dos dados do sistema. Confira antes de usar.`; `Tool audit` → `Auditoria de ferramentas`; `Tool` → `Ferramenta`; `Decision` → `Decisão`; `Allowed` → `Permitida`; `Denied` → `Negada`; `Rows` → `Linhas`; `Truncated` → `Truncado`; `Duration (ms)` → `Duração (ms)`; `Family` → `Família`; `Clinical` → `Clínica`; `Management` → `Gestão`; `Answered` → `Respondida`; `Patient not found` → `Paciente não encontrado`; `Correlation id` → `Id de correlação`; `Filter` → `Filtrar`; `No conversations yet` → `Nenhuma conversa ainda`; `No tool calls in the period` → `Nenhuma chamada de ferramenta no período`
- Consome: nada

**Teste RED**
- `src/tests/Unit/CopilotTranslationsTest.php` — lê `translations.json` e exige cada chave `en` da lista acima com o `pt` exato, a lista ainda ordenada por `en` e nenhuma chave `en` duplicada; falha antes porque `Copilot` não existe (comando: `SUITE | /usr/bin/grep -E 'CopilotTranslations|UserMessage|Failed:'`)

**Critério de aceite**
- As 33 chaves existem com o `pt` da lista; `json_decode` do arquivo não é `null`; ordenação por `en` preservada; zero duplicatas.

**Validação**
- `SUITE | /usr/bin/grep -E 'CopilotTranslations|UserMessage|Failed:'` (evidência: `PASS` de `CopilotTranslationsTest`, `UserMessageTest` ainda `PASS`, `Failed: 0`)
- `python3 -c "import json;d=json.load(open('src/app/config/translations.json'));print(len(d))"` (evidência: 1411 + número de chaves novas)

### T-08 — `ToolPolicy` fail-closed, rate limit, limites de saída, PII e auditoria

**Camada:** backend
**Dependências:** T-04, T-05
**Paralelizável:** sim
**Complexidade:** alta
**Agente:** Jaspion

Ordem das checagens (spec § 3.1), qualquer falha nega e grava `denied`: (1) a ferramenta está em `$offeredNames` e `ToolRegistry::find` a encontra, senão `not_offered`; (2) `JsonSchemaValidator`, senão `invalid_input`; (3) RBAC `requiredPermission` com tenant e unidade do contexto, senão `forbidden`; (4) `AiTool::assertInScope`, senão `not_found`; (5) `ToolRateLimiter::attempt`, senão `rate_limited`. Depois executa: `ToolInputException` → `invalid_input`; outra exceção → `error` (`error_log` com classe e `correlation_id`, nunca a mensagem nem o input); acima de `TIMEOUT_MS` → `timeout` (resultado descartado). Saída: corta em `ToolResult::MAX_ROWS` e em `MAX_BYTES` serializados (remove linhas do fim e marca `truncated`); cada campo de `freeTextFields` passa por `PiiMinimizer::minimize($texto, $knownNames)`. `input_minimized` = `json_encode(AuditRedactor::redact($input))` com cada string do input passada pelo `PiiMinimizer`.

**Arquivos prováveis**
- `src/app/Core/Ai/Tools/ToolPolicy.php`
- `src/app/Core/Ai/Tools/ToolOutcome.php`
- `src/app/Core/Ai/Tools/ToolRateLimiter.php`
- `src/tests/Unit/ToolPolicyTest.php`

**Interface**
- Produz: `new ToolPolicy(ToolRegistry $registry, JsonSchemaValidator $validator, AuthorizationPolicyInterface $authorization, McpToolCallRepositoryInterface $calls, ToolRateLimiter $rateLimiter, PiiMinimizer $minimizer, ?Closure $monotonicMs = null)` e `ToolPolicy::execute(ToolContext $context, string $family, string $toolName, array $input, array $offeredNames): ToolOutcome`; `ToolPolicy::TIMEOUT_MS = 5000`, `ToolPolicy::MAX_BYTES = 16384`
- Produz: `ToolOutcome` com props readonly `toolName`, `isError`, `content` (JSON enviado ao modelo como `tool_result`: `{"rows": [...], "row_count": n, "truncated": bool}` ou `{"error": "<mensagem>"}`), `decision`, `denyReason`, `rowCount`, `truncated`; mensagens de erro ao modelo: `sem permissão` (`not_offered`, `forbidden`), `não encontrado` (`not_found`), `limite atingido` (`rate_limited`), `entrada inválida` (`invalid_input`), `erro ao consultar` (`error`, `timeout`)
- Produz: `new ToolRateLimiter(LoginRateLimiter $limiter)`, `ToolRateLimiter::fromEnvironment(): self` (backend de `RATE_LIMIT_DRIVER` como `LoginRateLimiter::fromEnvironment`, `ToolRateLimiter::MAX_CALLS = 30`, `ToolRateLimiter::DECAY_SECONDS = 600`, prefixo `centralvet:ai-tool:`) e `ToolRateLimiter::attempt(int $tenantId, int $userId): bool`
- Consome: T-04 `interface AiTool`, T-04 `ToolContext::create(TenantContext $tenant, string $correlationId, ?int $conversationId, string $purpose, ?int $anchorPatientId): ToolContext`, T-04 `ToolRegistry::find(string $family, string $name): ?AiTool`, T-04 `JsonSchemaValidator::validate(array $schema, array $input): array`, T-04 `ToolEntityNotFoundException`, T-04 `ToolInputException`, T-04 `ToolResult::MAX_ROWS = 50`, T-04 `new FakeAiTool(string $name, string $family, string $permission, ?ToolResult $result = null, ?Throwable $failure = null, bool $inScope = true)`, T-05 `McpToolCall::record(int $tenantId, int $systemUnitId, int $systemUserId, ?int $conversationId, string $correlationId, string $toolName, string $inputMinimized, string $decision, ?string $denyReason, int $rowCount, bool $truncated, int $durationMs): self`, T-05 `McpToolCall::DENY_REASONS = ['not_offered', 'invalid_input', 'forbidden', 'not_found', 'rate_limited', 'error', 'timeout']`, T-05 `new FakeMcpToolCallRepository(int $tenantId)`

**Teste RED**
- `src/tests/Unit/ToolPolicyTest.php` — um caso por motivo (`not_offered`, `invalid_input`, `forbidden`, `not_found`, `rate_limited`, `error`, `timeout`) com `FakeAiTool`, `FakeAuthorizationPolicy`, `FakeMcpToolCallRepository` e `ToolRateLimiter` sobre `FakeRedis`: cada um devolve `isError === true`, grava 1 linha `denied` com o `deny_reason` e `FakeAiTool::executions() === 0` nos 5 primeiros; ordem: entrada inválida + RBAC negado → `invalid_input`; a 31ª chamada em 10 min → `rate_limited`; resultado com 60 linhas → 50 e `truncated`; campo livre `"Tutor Maria Souza 85 98765-4321"` com `knownNames ['Maria Souza']` chega ao `content` sem `Maria Souza` e sem `98765-4321`; falha antes porque `ToolPolicy` não existe (comando: `SUITE | /usr/bin/grep -E 'ToolPolicy|Failed:'`)

**Critério de aceite**
- Os 7 motivos geram `ToolOutcome::isError` e uma linha `denied` com o motivo; a chamada permitida gera uma linha `allowed` com `row_count`, `truncated` e `duration_ms`.
- O `content` de erro nunca traz mensagem de exceção, nome de classe nem SQL (asserção sobre um `RuntimeException('SQLSTATE secret')`).
- `content` serializado ≤ 16384 bytes em todo caso; `input_minimized` sem os dígitos do telefone do input.

**Validação**
- `SUITE | /usr/bin/grep -E 'ToolPolicy|Failed:'` (evidência: `PASS` dos casos e `Failed: 0`)
- Review Focus: caso "ferramenta sem permissão da tela, pedida pelo modelo → `denied`/`not_offered`" (evidência: nome do método no relatório)
- `LINT app/Core/Ai/Tools/ToolPolicy.php`, `LINT app/Core/Ai/Tools/ToolRateLimiter.php` (evidência: `No syntax errors detected`)

### T-09 — Repositórios PDO de conversa e de `mcp_tool_call`

**Camada:** backend
**Dependências:** T-01, T-05
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Levi

Roda depois do gate (0015 no `centralvet_test`). Padrão de `AiInteractionRepository` (`TenantQuery::forTenant`, construtor `(TenantContext, PDO)`); `findOwned`/`listOwned`/`deleteOwned` filtram `tenant_id`, `system_unit_id = requireUnitId()` e `system_user_id = userId()`. `appendMessage` grava `tenant_id` do contexto e só em conversa do próprio dono (senão `InvalidArgumentException`); `touch` atualiza `updated_at`.

**Arquivos prováveis**
- `src/app/Core/Persistence/AiConversationRepository.php`
- `src/app/Core/Persistence/McpToolCallRepository.php`
- `src/tests/Integration/CopilotRepositoryIntegrationTest.php`

**Interface**
- Produz: `new AiConversationRepository(TenantContext $context, PDO $connection)` e `new McpToolCallRepository(TenantContext $context, PDO $connection)`
- Consome: T-05 `AiConversationRepositoryInterface`, T-05 `McpToolCallRepositoryInterface`, T-05 `AiConversation::start(int $tenantId, int $systemUnitId, int $systemUserId, string $family, ?int $anchorPatientId, string $title, DateTimeImmutable $now): self`, T-05 `McpToolCall::record(int $tenantId, int $systemUnitId, int $systemUserId, ?int $conversationId, string $correlationId, string $toolName, string $inputMinimized, string $decision, ?string $denyReason, int $rowCount, bool $truncated, int $durationMs): self`

**Teste RED**
- `src/tests/Integration/CopilotRepositoryIntegrationTest.php` — conversa do usuário U na unidade A: `findOwned` com contexto (U, A) devolve a conversa; com (U, B), (V, A) e outro tenant devolve `null`; `deleteOwned` apaga e as mensagens somem por cascata; `McpToolCallRepository::search(null, 'buscar_paciente', 'denied', ...)` devolve só a linha negada do tenant; falha antes porque as classes não existem (comando: `SUITE | /usr/bin/grep -E 'CopilotRepository|Failed:'`)

**Critério de aceite**
- Após `deleteOwned`, `SELECT COUNT(*) FROM ai_conversation_message WHERE conversation_id = <id>` → `0`; linhas de `mcp_tool_call` com o mesmo `conversation_id` continuam (contagem antes = depois).
- `lastMessages(<id>, 20)` com 25 mensagens devolve as 20 últimas em ordem cronológica.
- Nenhuma leitura cruza tenant, unidade ou usuário (casos do teste).

**Validação**
- `SUITE | /usr/bin/grep -E 'CopilotRepository|Failed:'` (evidência: `PASS` e `Failed: 0`)
- `LINT app/Core/Persistence/AiConversationRepository.php`, `LINT app/Core/Persistence/McpToolCallRepository.php` (evidência: `No syntax errors detected`)

### T-10 — Ferramentas `buscar_paciente`, `obter_paciente`, `obter_historico_atendimentos`, `obter_prontuario`

**Camada:** backend
**Dependências:** T-04, T-06
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Yoda

Namespace `CentralVet\Ai\Tools\Clinical`. Construtor `(CopilotClinicalQueryInterface $query, ?Closure $clock = null)`. Família `clinical`. Saída sem CPF, telefone, e-mail, endereço e só com o primeiro nome do tutor (`ToolFormat::firstName`). Textos clínicos em `freeTextFields`, nomes completos do tutor em `knownNames`. Motivo = `ToolFormat::excerpt(anamnesis_text, 160)` (não há coluna de motivo).

**Arquivos prováveis**
- `src/app/Core/Ai/Tools/Clinical/BuscarPacienteTool.php`
- `src/app/Core/Ai/Tools/Clinical/ObterPacienteTool.php`
- `src/app/Core/Ai/Tools/Clinical/ObterHistoricoAtendimentosTool.php`
- `src/app/Core/Ai/Tools/Clinical/ObterProntuarioTool.php`
- `src/tests/Unit/ClinicalToolsPatientTest.php`

**Interface**
- Produz: `BuscarPacienteTool` — `name() === 'buscar_paciente'`, `requiredPermission() === 'GlobalSearchController::onSearch'`, entrada `termo` (string, 2–80, obrigatório), `limite` (integer 1–20, padrão 10); saída `id`, `nome`, `especie`, `raca`, `idade`, `tutor`
- Produz: `ObterPacienteTool` — `name() === 'obter_paciente'`, `requiredPermission() === 'PatientForm::onEdit'`, entrada `patient_id` (integer ≥ 1, opcional; padrão = `ToolContext::anchorPatientId`); saída `id`, `nome`, `especie`, `raca`, `sexo`, `idade`, `alergias`, `peso_kg`, `peso_em`, `tutor`
- Produz: `ObterHistoricoAtendimentosTool` — `name() === 'obter_historico_atendimentos'`, `requiredPermission() === 'EncounterView::onReload'`, entrada `patient_id` (opcional, padrão âncora), `limite` (1–10, padrão 5); saída `encounter_id`, `data`, `motivo`, `diagnostico`, `conduta`
- Produz: `ObterProntuarioTool` — `name() === 'obter_prontuario'`, `requiredPermission() === 'EncounterView::onReload'`, entrada `encounter_id` (integer ≥ 1, obrigatório); saída `encounter_id`, `data`, `anamnese`, `exame_fisico`, `diagnostico`, `plano`, `peso_kg`
- Produz: `assertInScope` usa `patientInUnit`/`encounterInUnit`; id ausente sem âncora → `ToolInputException`
- Consome: T-04 `interface AiTool`, T-04 `new ToolResult(array $data, bool $truncated = false, array $freeTextFields = [], array $knownNames = [])`, T-04 `ToolFormat::firstName(?string $fullName): ?string`, T-04 `ToolFormat::age(?string $birthDate, DateTimeImmutable $today): ?string`, T-04 `ToolFormat::excerpt(?string $text, int $max): ?string`, T-04 `ToolEntityNotFoundException`, T-04 `ToolInputException`, T-06 `patientInUnit(int $patientId): bool`, T-06 `encounterInUnit(int $encounterId): bool`, T-06 `searchPatients(string $term, int $limit): array`, T-06 `patient(int $patientId): ?array`, T-06 `encounters(int $patientId, int $limit): array`, T-06 `encounter(int $encounterId): ?array`, T-06 `tutorNames(int $patientId): array`, T-06 `new FakeCopilotClinicalQuery()`

**Teste RED**
- `src/tests/Unit/ClinicalToolsPatientTest.php` — com `FakeCopilotClinicalQuery`, `buscar_paciente` com tutor `Maria Souza Lima` devolve `tutor === 'Maria'` e nenhuma chave `cpf`, `phone`, `telefone`, `email`, `address`, `endereco`; `obter_prontuario` com `encounter_id` marcado fora da unidade (`setInUnit('encounter', 9, false)`) lança `ToolEntityNotFoundException` em `assertInScope`; `obter_paciente` sem `patient_id` usa a âncora; falha antes porque as classes não existem (comando: `SUITE | /usr/bin/grep -E 'ClinicalToolsPatient|Failed:'`)

**Critério de aceite**
- Cada ferramenta tem `name`, `requiredPermission`, schema válido para `JsonSchemaValidator` e `family() === 'clinical'`.
- `limite` acima do máximo é rejeitado pelo schema (`maximum`); resultado de 25 pacientes com `limite` 20 → 20 linhas.
- `freeTextFields` de `obter_prontuario` = `anamnese`, `exame_fisico`, `diagnostico`, `plano`; `knownNames` = nomes completos do tutor.

**Validação**
- `SUITE | /usr/bin/grep -E 'ClinicalToolsPatient|Failed:'` (evidência: `PASS` e `Failed: 0`)
- Review Focus: caso "`obter_prontuario` com `encounter_id` de outra unidade → não encontrado" (evidência: nome do método no relatório)
- `for f in BuscarPaciente ObterPaciente ObterHistoricoAtendimentos ObterProntuario; do LINT app/Core/Ai/Tools/Clinical/${f}Tool.php; done` (evidência: 4 × `No syntax errors detected`)

### T-11 — Ferramentas `obter_prescricoes`, `obter_exames`, `consultar_vacinacao`

**Camada:** backend
**Dependências:** T-04, T-06
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Naruto

Mesmo padrão da T-10 (construtor `(CopilotClinicalQueryInterface $query, ?Closure $clock = null)`, família `clinical`, `patient_id` opcional com padrão na âncora). Rascunho (`status = draft`) e exame `pending_review` saem com `provisorio: true`.

**Arquivos prováveis**
- `src/app/Core/Ai/Tools/Clinical/ObterPrescricoesTool.php`
- `src/app/Core/Ai/Tools/Clinical/ObterExamesTool.php`
- `src/app/Core/Ai/Tools/Clinical/ConsultarVacinacaoTool.php`
- `src/tests/Unit/ClinicalToolsRecordsTest.php`

**Interface**
- Produz: `ObterPrescricoesTool` — `name() === 'obter_prescricoes'`, `requiredPermission() === 'PrescriptionForm::__construct'`, entrada `patient_id`, `situacao` (enum `ativas`/`todas`, padrão `ativas`); saída `data`, `medicamento`, `dose`, `via`, `posologia`, `duracao`, `status`, `provisorio`, `validade`
- Produz: `ObterExamesTool` — `name() === 'obter_exames'`, `requiredPermission() === 'ExamResultForm::__construct'`, entrada `patient_id`, `situacao` (enum `pendentes`/`liberados`/`todos`, padrão `todos`); saída `exame`, `data`, `status`, `provisorio`, `resultado` (`ToolFormat::excerpt(structured_result_text, 400)`, em `freeTextFields`)
- Produz: `ConsultarVacinacaoTool` — `name() === 'consultar_vacinacao'`, `requiredPermission() === 'VaccinationCardView::onReload'`, entrada `patient_id`; saída `vacina`, `aplicada_em`, `proxima_dose`, `atrasada` (`true` se `next_dose_at < hoje` e não `superseded`)
- Consome: T-04 `interface AiTool`, T-04 `new ToolResult(array $data, bool $truncated = false, array $freeTextFields = [], array $knownNames = [])`, T-04 `ToolFormat::excerpt(?string $text, int $max): ?string`, T-04 `ToolEntityNotFoundException`, T-04 `ToolInputException`, T-06 `patientInUnit(int $patientId): bool`, T-06 `prescriptions(int $patientId, bool $onlyActive, DateTimeImmutable $today, int $limit): array`, T-06 `exams(int $patientId, string $situacao, int $limit): array`, T-06 `vaccinations(int $patientId, int $limit): array`, T-06 `tutorNames(int $patientId): array`, T-06 `new FakeCopilotClinicalQuery()`

**Teste RED**
- `src/tests/Unit/ClinicalToolsRecordsTest.php` — com relógio em `2026-10-15`, `consultar_vacinacao` com `next_dose_at = 2026-10-01` e `superseded = false` devolve `atrasada === true`, e com `superseded = true` devolve `false`; `obter_prescricoes` com item `draft` devolve `provisorio === true`; `obter_exames` com `pending_review = true` devolve `provisorio === true`; paciente fora da unidade → `ToolEntityNotFoundException`; falha antes porque as classes não existem (comando: `SUITE | /usr/bin/grep -E 'ClinicalToolsRecords|Failed:'`)

**Critério de aceite**
- As 3 ferramentas com `family() === 'clinical'`, schema aceito pelo validador, no máximo 50 linhas (`truncated` acima disso) e nenhuma chave de contato do tutor.
- `situacao` fora do enum é rejeitada pelo schema.

**Validação**
- `SUITE | /usr/bin/grep -E 'ClinicalToolsRecords|Failed:'` (evidência: `PASS` e `Failed: 0`)
- `for f in ObterPrescricoes ObterExames ConsultarVacinacao; do LINT app/Core/Ai/Tools/Clinical/${f}Tool.php; done` (evidência: 3 × `No syntax errors detected`)

### T-12 — Retenção de 90 dias das conversas (comando de cron)

**Camada:** backend
**Dependências:** T-01, T-05
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Thanos

Padrão de `DocumentSweeper` (`forConnection(PDO)->runOnce()`, lote limitado) e de `src/bin/document-sweep.php` (imprime JSON de contadores, exit 1 em erro). Sem contexto de tenant: corre todos os tenants. `DELETE FROM ai_conversation WHERE updated_at < :cutoff ORDER BY id LIMIT 500` em laço até lote vazio ou `MAX_BATCHES`; mensagens caem por cascata. Nunca toca `mcp_tool_call` nem `ai_interaction`.

**Arquivos prováveis**
- `src/app/Core/Application/AiConversationRetention.php`
- `src/bin/ai-conversation-retention.php`
- `src/tests/Integration/AiConversationRetentionIntegrationTest.php`

**Interface**
- Produz: `AiConversationRetention::forConnection(PDO $connection, ?Closure $clock = null): self`, `AiConversationRetention::runOnce(): array` → `array{deleted: int, batches: int}`, `AiConversationRetention::RETENTION_DAYS = 90`, `AiConversationRetention::BATCH_SIZE = 500`, `AiConversationRetention::MAX_BATCHES = 200`
- Produz: comando `php bin/ai-conversation-retention.php` → uma linha JSON `{"deleted":n,"batches":b}`, exit 0; exceção → `error_log` com a classe e exit 1
- Consome: nada

**Teste RED**
- `src/tests/Integration/AiConversationRetentionIntegrationTest.php` — com relógio fixo, conversa A com `updated_at` de 91 dias (2 mensagens e 1 linha de `mcp_tool_call`) e conversa B de 89 dias: `runOnce()` devolve `deleted >= 1`, A e as mensagens dela somem, B fica, a linha de `mcp_tool_call` e a de `ai_interaction` com `subject_id` de A ficam; falha antes porque `AiConversationRetention` não existe (comando: `SUITE | /usr/bin/grep -E 'AiConversationRetention|Failed:'`)

**Critério de aceite**
- Depois de `runOnce()`: `ai_conversation` sem A, com B; `ai_conversation_message` de A = 0; `mcp_tool_call` e `ai_interaction` com a mesma contagem de antes.
- O comando imprime `{"deleted":` e sai com código 0 no `centralvet_test`.

**Validação**
- `SUITE | /usr/bin/grep -E 'AiConversationRetention|Failed:'` (evidência: `PASS` e `Failed: 0`)
- `docker compose run --rm --no-deps -T -e DB_DATABASE=centralvet_test -v /var/www/html/centralvet/src:/var/www/html/src:ro app php bin/ai-conversation-retention.php; echo "exit=$?"` (evidência: linha `{"deleted":0,"batches":` e `exit=0`)
- `LINT app/Core/Application/AiConversationRetention.php`, `LINT bin/ai-conversation-retention.php` (evidência: `No syntax errors detected`)

### T-13 — `AiCopilotService` (laço de tool use) e `ClinicalToolset`

**Camada:** backend
**Dependências:** T-02, T-03, T-04, T-05, T-06, T-08, T-09, T-10, T-11
**Paralelizável:** não
**Complexidade:** alta
**Agente:** Batman

Fluxo de `ask` (spec § 3.3): (1) `requireUnitId`; família em `ToolFamily::ALL`; pergunta aparada, não vazia e ≤ `MAX_QUESTION_CHARS` (senão `InvalidArgumentException`); (2) RBAC `AiCopilotPanel::onAsk` com unidade; (3) `availability($family)` → `AiUnavailableException`; (4) clínico com âncora: `patientInUnit` senão `AiSubjectNotFoundException`; gestão com âncora → `InvalidArgumentException`; (5) conversa: `findOwned` (família igual) ou nova com título = 80 primeiros caracteres da pergunta minimizada; id de outro dono → `AiSubjectNotFoundException`; (6) pergunta minimizada (`PiiMinimizer::minimize($q, tutorNames(âncora))`) gravada como mensagem `user`; histórico = `lastMessages(id, HISTORY_LIMIT)` sem as falhas; (7) um `correlation_id` (`CorrelationIdContext::current() ?? CorrelationIdContext::generate()`) e um `AiInteraction::start(..., AiPurpose::COPILOT_*, conversationId, VERSIONS[...], sha256(pergunta minimizada), correlationId)`; (8) laço: gateway com `system` = `copilotSystem`, mensagens e `tools` = `definitions`; enquanto `stopReason === 'tool_use'`, até `MAX_ROUNDS` rodadas e `BUDGET_SECONDS`: cada `tool_use` por `ToolPolicy::execute` e `tool_result` (`is_error` = `ToolOutcome::isError`); limite atingido com tempo restante → uma chamada final sem `tools`; tempo esgotado → `TIMEOUT_TEXT[lang]` sem chamar o gateway; (9) sucesso: `recordAnswer` com uso e custo somados, mensagem `assistant` com `toolSummary`, `touch`; erro do provedor: `recordFailure` (como a 8A) e mensagem `failed` com o motivo; (10) `ai_interaction` inserido em todos os casos depois do início.

**Arquivos prováveis**
- `src/app/Core/Application/AiCopilotService.php`
- `src/app/Core/Application/CopilotAnswer.php`
- `src/app/Core/Ai/Tools/Clinical/ClinicalToolset.php`
- `src/tests/Unit/AiCopilotServiceTest.php`
- `src/tests/Integration/AiCopilotServiceForContextIntegrationTest.php`

**Interface**
- Produz: `new AiCopilotService(AiGatewayInterface $gateway, AiInteractionRepositoryInterface $interactions, AiTenantSettingRepositoryInterface $settings, FeatureFlagRepositoryInterface $flags, AiConversationRepositoryInterface $conversations, ToolRegistry $registry, ToolPolicy $policy, CopilotClinicalQueryInterface $clinical, AuthorizationPolicyInterface $authorization, PiiMinimizer $minimizer, TenantContext $context, string $locale = 'pt', ?Closure $clock = null, ?Closure $monotonicSeconds = null)`
- Produz: `AiCopilotService::forContext(TenantContext $context, PDO $connection, string $locale): self` (gateway de `AiConfig::fromEnvironment()`, repositórios PDO, `ToolRateLimiter::fromEnvironment()`, RBAC como `AiAssistService::forContext`; registra `ClinicalToolset::create(...)`)
- Produz: `AiCopilotService::ask(string $family, ?int $conversationId, ?int $anchorPatientId, string $question): CopilotAnswer`
- Produz: `AiCopilotService::availability(string $family): ?string` (ordem: `ai_assistant` → termo `AiTerms::VERSION` → cota do mês no fuso da aplicação → `isConfigured`; gestão também exige `ai_copilot_management`, senão `ai_disabled`)
- Produz: `AiCopilotService::assertAnchor(int $patientId): void` (RBAC `AiCopilotPanel::onOpen`; fora da unidade → `AiSubjectNotFoundException`)
- Produz: `AiCopilotService::conversations(?string $family, ?int $anchorPatientId, int $limit): array`, `AiCopilotService::conversation(int $id): ?array` → `array{conversation: AiConversation, messages: list<AiConversationMessage>}` (RBAC `AiCopilotPanel::onOpen`) e `AiCopilotService::deleteConversation(int $id): bool` (RBAC `AiCopilotPanel::onDelete`)
- Produz: `CopilotAnswer` com props readonly `conversationId` (int), `text` (string), `toolSummary` (`list<array{tool: string, label: string, count: int}>`), `failed` (bool), `reason` (`?string`, código `ai_*`)
- Produz: `AiCopilotService::MAX_ROUNDS = 4`, `AiCopilotService::BUDGET_SECONDS = 45`, `AiCopilotService::HISTORY_LIMIT = 20`, `AiCopilotService::MAX_QUESTION_CHARS = 2000`, `AiCopilotService::TIMEOUT_TEXT` (`pt` = `Não consegui concluir a consulta, tente uma pergunta mais específica.`)
- Produz: `ClinicalToolset::create(CopilotClinicalQueryInterface $query, ?Closure $clock = null): array` (as 7 ferramentas clínicas)
- Consome: T-02 `AiCompletionRequest::__construct(string $purpose, string $promptVersion, string $system, string $userContent, array $messages = [], array $tools = [])`, T-02 `stopReason === 'tool_use'`, T-02 `AiUsage::plus(AiUsage $other): AiUsage`, T-02 `StubAiGateway::scripted(array $steps): self`, T-03 `AiPurpose::COPILOT_CLINICAL = 'copilot_clinical'`, T-03 `AiPurpose::COPILOT_MANAGEMENT = 'copilot_management'`, T-03 `AiInteraction::recordAnswer(AiUsage $usage, ?int $costMicroUsd, string $provider, string $model, string $stopReason, ?string $providerRequestId, int $latencyMs): void`, T-03 `AiPromptCatalog::copilotSystem(string $purpose, string $locale): string`, T-04 `ToolContext::create(TenantContext $tenant, string $correlationId, ?int $conversationId, string $purpose, ?int $anchorPatientId): ToolContext`, T-04 `ToolRegistry::definitions(string $family): array`, T-04 `FeatureFlagRepositoryInterface::AI_COPILOT_MANAGEMENT = 'ai_copilot_management'`, T-05 `AiConversationRepositoryInterface`, T-05 `AiConversationMessage::assistant(int $conversationId, string $content, array $toolSummary, DateTimeImmutable $now)`, T-05 `AiConversationMessage::failed(int $conversationId, string $reason, DateTimeImmutable $now)`, T-05 `new FakeAiConversationRepository(int $tenantId, int $systemUnitId, int $systemUserId)`, T-06 `patientInUnit(int $patientId): bool`, T-06 `tutorNames(int $patientId): array`, T-08 `ToolPolicy::execute(ToolContext $context, string $family, string $toolName, array $input, array $offeredNames): ToolOutcome`, T-08 `ToolRateLimiter::fromEnvironment(): self`, T-09 `new AiConversationRepository(TenantContext $context, PDO $connection)`, T-09 `new McpToolCallRepository(TenantContext $context, PDO $connection)`, T-10 `BuscarPacienteTool`, T-10 `ObterPacienteTool`, T-10 `ObterHistoricoAtendimentosTool`, T-10 `ObterProntuarioTool`, T-11 `ObterPrescricoesTool`, T-11 `ObterExamesTool`, T-11 `ConsultarVacinacaoTool`

**Teste RED**
- `src/tests/Unit/AiCopilotServiceTest.php` — com `StubAiGateway::scripted`, fakes da T-04/T-05/T-06 e relógio monotônico injetado: 0 rodadas (texto direto), 1 rodada (`tool_use` → texto), 4 rodadas seguidas de chamada final sem `tools` (a 5ª requisição tem `tools === []`), orçamento esgotado (monotônico salta 46 s → `TIMEOUT_TEXT['pt']` e nenhuma chamada extra), ferramenta negada no meio (o `tool_result` tem `is_error: true` e a resposta final chega), erro do provedor (`failed === true`, `reason === 'ai_overloaded'`, mensagem `failed` gravada) e gravação (2 mensagens, 1 `ai_interaction` `copilot_clinical` `answered` com uso somado das rodadas e o mesmo `correlation_id` das linhas de `FakeMcpToolCallRepository`); falha antes porque `AiCopilotService` não existe (comando: `SUITE | /usr/bin/grep -E 'AiCopilotService|Failed:'`)

**Critério de aceite**
- Os 7 cenários do teste passam; `availability` devolve `ai_disabled`, `ai_terms_pending`, `ai_quota_exceeded` e `ai_not_configured` na ordem da 8A e nenhum chama o gateway (`StubAiGateway::requests()` vazio).
- Gestão com `ai_assistant` ligada e `ai_copilot_management` desligada → `ai_disabled`.
- Conversa de outro usuário ou unidade → `AiSubjectNotFoundException`; âncora fora da unidade → `AiSubjectNotFoundException` sem nenhuma linha gravada.
- `AiCopilotServiceForContextIntegrationTest` (com `AI_PROVIDER=stub`, `APP_ENV=testing`): `forContext($context, $this->pdo, 'pt')` devolve o serviço; com flag e termo semeados, `ask('clinical', null, <paciente da unidade>, 'resumo')` grava 1 `ai_conversation`, 2 `ai_conversation_message` e 1 `ai_interaction` com `purpose = 'copilot_clinical'` e `status = 'answered'`.

**Validação**
- `SUITE | /usr/bin/grep -E 'AiCopilotService|Failed:'` (evidência: `PASS` dos casos de `AiCopilotServiceTest` e `AiCopilotServiceForContextIntegrationTest`, `Failed: 0`)
- Review Focus: caso "pergunta com telefone e CPF → título, mensagem e requisição ao gateway com `[TELEFONE]`/`[CPF]`" (evidência: nome do método no relatório e asserção sobre `StubAiGateway::requests()`)
- `LINT app/Core/Application/AiCopilotService.php`, `LINT app/Core/Ai/Tools/Clinical/ClinicalToolset.php` (evidência: `No syntax errors detected`)

### T-14 — Painel do copiloto em cortina lateral (`AiCopilotPanel`)

**Camada:** frontend
**Dependências:** T-07, T-13
**Paralelizável:** sim
**Complexidade:** alta
**Agente:** Tesla

`AiCopilotPanel extends TPage` com `parent::setTargetContainer('adianti_right_panel')` (padrão `SystemContactsList.php:27`); fechar com `TScript::create("Template.closeRightPanel()")`. Mostra: título (`Clinical copilot`/`Management copilot`), aviso de IA, a conversa atual (mensagens, com "Consultei: …" abaixo de cada resposta a partir de `toolSummary`), o formulário de pergunta, a lista de conversas anteriores do autor (clínico: do paciente âncora) com abrir, "Nova conversa" e "Apagar conversa". Indisponível → motivo da 8A (mesma tradução de `ai_*`), sem formulário. Âncora fora da unidade → `TMessage` `Patient not found` e o painel não abre. CSS só em `cv-components.css` (`.cv-ai-copilot*`; `custom.css` é protegido); rótulos à esquerda; alvos de toque com `cv-touch-target`. Live region `role="status"` com "Pensando…" e botão desabilitado entre o envio e a resposta (JS próprio incluído por `TElement::tag('script', '', ['src' => ...])` como `EncounterView.php:1788`).

**Arquivos prováveis**
- `src/app/control/clinic/AiCopilotPanel.php`
- `src/app/templates/adminbs5/js/cv-ai-copilot.js`
- `src/app/templates/adminbs5/cv-components.css`
- `src/tests/Integration/AiCopilotPanelIntegrationTest.php`

**Interface**
- Produz: `AiCopilotPanel::onOpen($param)` (params `family`, `anchor_patient_id`, `conversation_id`), `AiCopilotPanel::onAsk($param)` (POST do formulário `form_AiCopilotPanel` com `question`, `family`, `anchor_patient_id`, `conversation_id`), `AiCopilotPanel::onDelete($param)` (`conversation_id`), `AiCopilotPanel::onClose($param)`
- Produz: `AiCopilotPanel::linkFor(string $family, ?int $anchorPatientId): string` → `index.php?class=AiCopilotPanel&method=onOpen&family=<family>&register_state=false` mais `&anchor_patient_id=<id>` quando há âncora
- Produz: pontos de extensão `protected static function resolveTenantContext(): TenantContext`, `protected static function makeService(TenantContext $context, string $locale): AiCopilotService`, `protected static function transaction(callable $fn): mixed` (padrão de `AiInteractionList`)
- Produz: live region `<div id="cv-ai-copilot-status" class="cv-ai-status" role="status" aria-live="polite">` e botão de envio com `data-thinking-label` = `_t('Thinking…')`; o JS desabilita o botão e põe `aria-busy="true"` no formulário no primeiro submit
- Produz: hidden `submit_token` (`bin2hex(random_bytes(16))` a cada renderização do formulário); `onAsk` guarda o último token processado em `TSession` na chave `cv_ai_copilot_last_token` e, com token repetido, só re-renderiza a conversa sem chamar `ask`
- Consome: T-07 `Copilot` → `Copiloto`, T-07 `Clinical copilot` → `Copiloto clínico`, T-07 `Management copilot` → `Copiloto de gestão`, T-07 `Your question` → `Sua pergunta`, T-07 `Thinking…` → `Pensando…`, T-07 `New conversation` → `Nova conversa`, T-07 `Delete conversation` → `Apagar conversa`, T-07 `Previous conversations` → `Conversas anteriores`, T-07 `Consulted` → `Consultei`, T-07 `Patient not found` → `Paciente não encontrado`, T-13 `AiCopilotService::ask(string $family, ?int $conversationId, ?int $anchorPatientId, string $question): CopilotAnswer`, T-13 `AiCopilotService::availability(string $family): ?string`, T-13 `AiCopilotService::assertAnchor(int $patientId): void`, T-13 `AiCopilotService::conversations(?string $family, ?int $anchorPatientId, int $limit): array`, T-13 `AiCopilotService::deleteConversation(int $id): bool`

**Teste RED**
- `src/tests/Integration/AiCopilotPanelIntegrationTest.php` — em subprocesso (`PHP_BINARY -r 'require "init.php"'`, `AI_PROVIDER=stub`, sessão com programas, padrão de `AiSettingsScreensIntegrationTest.php:66-92`): `onOpen` clínico com âncora da unidade mostra `Copiloto clínico`, `Sua pergunta` e a live region `role="status"`; `onAsk` com `question` grava 2 mensagens e mostra a resposta do stub e `Consultei`; `onDelete` apaga a conversa; âncora de outra unidade mostra `Paciente não encontrado`; o fonte do controller e o CSS novo não têm `text-align:right|center` e todo botão tem `cv-touch-target`; falha antes porque `AiCopilotPanel` não existe (comando: `SUITE | /usr/bin/grep -E 'AiCopilotPanel|Failed:'`)

**Critério de aceite**
- HTML do painel com textos em pt (`Copiloto clínico`, `Sua pergunta`, `Apagar conversa`), live region `role="status"` e `aria-live="polite"`, nenhum rótulo com `text-align:right|center`.
- Após `onAsk`, `SELECT COUNT(*) FROM ai_conversation_message WHERE conversation_id = <id>` → `2`; após `onDelete`, `SELECT COUNT(*) FROM ai_conversation WHERE id = <id>` → `0`.
- IA desligada → o painel mostra o motivo e não renderiza `form_AiCopilotPanel`.

**Validação**
- `SUITE | /usr/bin/grep -E 'AiCopilotPanel|Failed:'` (evidência: `PASS` e `Failed: 0`)
- Review Focus: caso "envio duplo → uma pergunta, uma linha em `ai_interaction`; botão desabilitado com `Pensando…`" (evidência: asserção sobre o JS — `disabled` e `aria-busy` no submit — e contagem de `ai_interaction` após dois `onAsk` com o mesmo token de envio, no relatório)
- `LINT app/control/clinic/AiCopilotPanel.php` (evidência: `No syntax errors detected`)

### T-15 — "Minhas conversas", auditoria de ferramentas e rótulos da 8A

**Camada:** frontend
**Dependências:** T-03, T-07, T-09, T-13
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Hegel

`AiConversationList`: conversas do autor na unidade ativa (família, data, título), com abrir (link `AiCopilotPanel::linkFor` mais `conversation_id`) e apagar. `AiToolCallList` (gestor, grupo 1): filtros por usuário, ferramenta, decisão e período (`TForm` + `CvPage::filterBar`, padrão `CommunicationMessageList.php:151-203`); colunas data, usuário, ferramenta, decisão, motivo, linhas, truncado, duração, id de correlação — nunca `input_minimized` nem texto de conversa. Tabelas `cv-table` como `AiInteractionList`, com pontos de extensão `protected static`.

**Arquivos prováveis**
- `src/app/control/clinic/AiConversationList.php`
- `src/app/control/clinic/AiToolCallList.php`
- `src/app/Core/Application/AiToolAuditService.php`
- `src/app/control/clinic/AiInteractionList.php`
- `src/app/control/clinic/AiSettingsForm.php`
- `src/tests/Integration/AiCopilotListsIntegrationTest.php`

**Interface**
- Produz: `AiConversationList::onReload($param)` e `AiConversationList::onDelete($param)` (`conversation_id`)
- Produz: `AiToolCallList::onReload($param)` com filtros `user_id`, `tool_name`, `decision`, `date_from`, `date_to`
- Produz: `AiToolAuditService::forContext(TenantContext $context, PDO $connection): self` e `AiToolAuditService::search(array $filters, int $limit): array` (RBAC `AiToolCallList::onReload`, `requiresUnitScope: false`; tenant do contexto)
- Produz: `AiInteractionList::statusLabel('answered') === _t('Answered')`; `AiSettingsForm::purposeLabel('copilot_clinical') === _t('Clinical copilot')` e `purposeLabel('copilot_management') === _t('Management copilot')`
- Consome: T-03 `AiInteraction::STATUS_ANSWERED = 'answered'`, T-03 `AiPurpose::COPILOT_CLINICAL = 'copilot_clinical'`, T-03 `AiPurpose::COPILOT_MANAGEMENT = 'copilot_management'`, T-07 `My conversations` → `Minhas conversas`, T-07 `Tool audit` → `Auditoria de ferramentas`, T-07 `Answered` → `Respondida`, T-07 `Conversation not found` → `Conversa não encontrada`, T-07 `No conversations yet` → `Nenhuma conversa ainda`, T-07 `No tool calls in the period` → `Nenhuma chamada de ferramenta no período`, T-09 `new McpToolCallRepository(TenantContext $context, PDO $connection)`, T-13 `AiCopilotService::conversations(?string $family, ?int $anchorPatientId, int $limit): array`, T-13 `AiCopilotService::deleteConversation(int $id): bool`

**Teste RED**
- `src/tests/Integration/AiCopilotListsIntegrationTest.php` — em subprocesso: `AiConversationList::onReload` do usuário U mostra a conversa dele e não a do usuário V nem a da unidade B; `AiToolCallList::onReload` com `decision=denied` lista só as linhas negadas e o HTML não contém o `input_minimized` semeado (`"termo":"Thor"`) nem o texto da conversa; `AiInteractionList` mostra `Respondida` para uma linha `answered`; falha antes porque as telas não existem (comando: `SUITE | /usr/bin/grep -E 'AiCopilotLists|Failed:'`)

**Critério de aceite**
- `Minhas conversas` lista só conversas do autor na unidade ativa; apagar remove a linha (`SELECT COUNT(*)` → `0`).
- `Auditoria de ferramentas` filtra por usuário, ferramenta, decisão e período; HTML sem `input_minimized` e sem `content` de mensagem.
- Rótulos à esquerda, alvos de toque com `cv-touch-target`, textos em pt.

**Validação**
- `SUITE | /usr/bin/grep -E 'AiCopilotLists|AiSettingsScreens|Failed:'` (evidência: `PASS` e `Failed: 0`)
- Review Focus: caso "conversa da unidade anterior aberta depois da troca de unidade → `Conversa não encontrada`" (evidência: nome do método no relatório)
- `LINT app/control/clinic/AiConversationList.php`, `LINT app/control/clinic/AiToolCallList.php`, `LINT app/Core/Application/AiToolAuditService.php` (evidência: `No syntax errors detected`)

### T-16 — Botão "Copiloto" em `PatientForm` e `EncounterView`

**Camada:** frontend
**Dependências:** T-07, T-14
**Paralelizável:** sim
**Complexidade:** simples
**Agente:** Kratos

Link (não `TButton`: o `headerButton` da `EncounterView` posta o formulário do atendimento) com `generator="adianti"` para `AiCopilotPanel::linkFor('clinical', <patient_id>)`, nas ações de cabeçalho (`PatientForm` em `CvPage::header` :242-254, só em edição de paciente existente; `EncounterView::pageHeader` :459-513, com o `patient_id` do atendimento). Só aparece quando `AiCopilotService::availability('clinical') === null`; classe `cv-touch-target`.

**Arquivos prováveis**
- `src/app/control/clinic/PatientForm.php`
- `src/app/control/clinic/EncounterView.php`
- `src/tests/Integration/CopilotHostButtonsIntegrationTest.php`

**Interface**
- Produz: link com `data-cv-copilot="clinical"` e `href` de `AiCopilotPanel::linkFor('clinical', <patient_id>)` nas duas telas
- Consome: T-07 `Copilot` → `Copiloto`, T-14 `AiCopilotPanel::linkFor(string $family, ?int $anchorPatientId): string`

**Teste RED**
- `src/tests/Integration/CopilotHostButtonsIntegrationTest.php` — em subprocesso com IA ligada (flag e termo semeados), o HTML de `PatientForm::onEdit` e de `EncounterView::onReload` contém `data-cv-copilot="clinical"` com `class=AiCopilotPanel` e `anchor_patient_id=<id>`; com a flag desligada não contém; falha antes porque o link não existe (comando: `SUITE | /usr/bin/grep -E 'CopilotHostButtons|EncounterAi|Failed:'`)

**Critério de aceite**
- Link presente com IA disponível e ausente com IA indisponível nas duas telas; `EncounterAiIntegrationTest` continua `PASS`.

**Validação**
- `SUITE | /usr/bin/grep -E 'CopilotHostButtons|EncounterAi|Failed:'` (evidência: `PASS` e `Failed: 0`)
- `LINT app/control/clinic/PatientForm.php`, `LINT app/control/clinic/EncounterView.php` (evidência: `No syntax errors detected`)

### T-17 — Menu de IA, seed de programas e testes de navegação/chaves

**Camada:** frontend
**Dependências:** T-14, T-15
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Saitama

Submenu de IA em `src/menu.xml:154-166` ganha dois itens (com ícone). Seed: bloco "Fase 8B — Copiloto" antes do único `COMMIT` (:1034), idempotente (`INSERT ... SELECT MAX(id)+1 ... WHERE NOT EXISTS`, como o bloco 8A :1011-1032): programas `AiCopilotPanel` e `AiConversationList` para os grupos que já têm `EncounterView` (`INSERT ... SELECT` a partir de `system_group_program`), `AiToolCallList` só para o grupo 1. A DML não é executada aqui (aplicar no `centralvet` exige aprovação SQL do usuário).

**Arquivos prováveis**
- `src/menu.xml`
- `src/app/database/seeds/initial-application-programs.sql`
- `src/tests/Integration/AiNavigationIntegrationTest.php`
- `src/tests/Unit/UserMessageTest.php`

**Interface**
- Produz: itens do submenu de IA com rótulo `_t` e ação: `AI settings` → `AiSettingsForm`, `AI interactions` → `AiInteractionList`, `My conversations` → `AiConversationList`, `Tool audit` → `AiToolCallList`
- Produz: `UserMessageTest` verifica as chaves `_t` de `AiCopilotPanel`, `AiConversationList` e `AiToolCallList`
- Consome: T-14 `AiCopilotPanel::onOpen($param)`, T-15 `AiConversationList::onReload($param)`, T-15 `AiToolCallList::onReload($param)`

**Teste RED**
- `src/tests/Integration/AiNavigationIntegrationTest.php` — o submenu de IA tem exatamente os 4 filhos acima, todos com ícone, e o seed contém `AiCopilotPanel`, `AiConversationList` e `AiToolCallList` dentro do bloco 8B antes do `COMMIT`; falha antes porque o menu tem 2 filhos (comando: `SUITE | /usr/bin/grep -E 'AiNavigation|UserMessage|Failed:'`)

**Critério de aceite**
- `AiNavigationIntegrationTest` com os 4 itens e `UserMessageTest` com os 3 controllers novos passam.
- O seed continua com um único `START TRANSACTION` e um único `COMMIT` (`/usr/bin/grep -c "^COMMIT" <seed>` → `1`).

**Validação**
- `SUITE | /usr/bin/grep -E 'AiNavigation|UserMessage|Failed:'` (evidência: `PASS` e `Failed: 0`)
- `xmllint --noout src/menu.xml; echo "exit=$?"` (evidência: `exit=0`; sem `xmllint`, `php -r 'var_dump(simplexml_load_file("menu.xml") !== false);'` no container → `bool(true)`)

### T-18 — Runbook do copiloto (8B.1), ADR 0007 e MySQL 5.7

**Camada:** docs
**Dependências:** T-01, T-12, T-13, T-14, T-15
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Gandalf

`docs/runbooks/ia-assistente.md` ganha a seção "Copiloto (Fase 8B)": casos de uso, as 7 ferramentas com `requiredPermission` (tabela do plano), limites (4 rodadas, 45 s, 30 chamadas/10 min, 50 linhas, 16 KB, 5 s), regra de unidade do paciente, auditoria (`mcp_tool_call`, `AiToolCallList`), histórico e retenção de 90 dias (comando e linha de crontab diária), status `answered` em `ai_interaction`, motivos de recusa, incidente (desligar `ai_assistant`) e o roteiro de smoke manual com chave real (uma pergunta clínica). ADR 0007 no formato da 0006 (Contexto, Decisão, Alternativas, Consequências). `shared-hosting-mysql57.md`: seção "Migration 0015 (copiloto)" com prefixo `19-`, os 6 CHECKs ampliados (drop → create) e o cron da retenção.

**Arquivos prováveis**
- `docs/runbooks/ia-assistente.md`
- `docs/architecture/adr/0007-copiloto-ferramentas-mcp.md`
- `docs/runbooks/shared-hosting-mysql57.md`

**Interface**
- Produz: seção `## Copiloto (Fase 8B)` em `ia-assistente.md` e `docs/architecture/adr/0007-copiloto-ferramentas-mcp.md` (Status: aceito, Data: 2026-10-15)
- Consome: T-12 `php bin/ai-conversation-retention.php`, T-13 `AiCopilotService::MAX_ROUNDS = 4`, T-13 `AiCopilotService::BUDGET_SECONDS = 45`

**Teste RED**
- sem teste: documentação

**Critério de aceite**
- `/usr/bin/grep -c "requiredPermission\|GlobalSearchController::onSearch\|VaccinationCardView::onReload" docs/runbooks/ia-assistente.md` ≥ 3; `/usr/bin/grep -n "ai-conversation-retention" docs/runbooks/ia-assistente.md docs/runbooks/shared-hosting-mysql57.md` mostra a linha de crontab nos dois.
- ADR 0007 existe e é citada no runbook; `shared-hosting-mysql57.md` tem a seção da 0015 com `19-`.
- Nenhuma chave real ou dado pessoal nos documentos (`/usr/bin/grep -n "sk-ant-" docs/runbooks/ia-assistente.md` sem linha com chave real).

**Validação**
- `/usr/bin/grep -n "## Copiloto (Fase 8B)\|0007-copiloto" docs/runbooks/ia-assistente.md` (evidência: a seção e o link da ADR)
- `/usr/bin/grep -n "Migration 0015\|19-20261015_0015" docs/runbooks/shared-hosting-mysql57.md` (evidência: a seção e o prefixo)

### T-19 — Ferramentas `consultar_agenda` e `verificar_horarios`

**Camada:** backend
**Dependências:** T-04
**Paralelizável:** sim
**Complexidade:** alta
**Agente:** Arquimedes

8B.2. Namespace `CentralVet\Ai\Tools\Management`. Agenda da unidade ativa (`appointment.system_unit_id`), profissionais = usuários ativos do tenant (`system_users.active = 'Y'` e `tenant_user`, como `AgendaView::listProfessionals` :274). Ocupado = status `agendado`, `confirmado`, `em_atendimento`, `atendido` com a duração do `service` (regra de `AppointmentService::assertNoConflict` :275). Grade de `AgendaSlots` (07:00–19:00, passo 30): horário livre se `[início, início + duração)` termina até 19:00, não cruza intervalo ocupado do profissional e, no dia de hoje, começa depois de agora. Queries com `/*+ MAX_EXECUTION_TIME(5000) */`.

**Arquivos prováveis**
- `src/app/Core/Domain/Contract/CopilotAgendaQueryInterface.php`
- `src/app/Core/Persistence/CopilotAgendaQuery.php`
- `src/app/Core/Application/FreeSlotFinder.php`
- `src/app/Core/Ai/Tools/Management/ConsultarAgendaTool.php`
- `src/app/Core/Ai/Tools/Management/VerificarHorariosTool.php`
- `src/tests/Support/FakeCopilotAgendaQuery.php`
- `src/tests/Unit/AgendaToolsTest.php`
- `src/tests/Integration/CopilotAgendaQueryIntegrationTest.php`

**Interface**
- Produz: `CopilotAgendaQueryInterface::appointments(DateTimeImmutable $from, DateTimeImmutable $to, ?int $professionalId, int $limit): array` → `list<array{scheduled_at: string, patient_name: string, service_name: ?string, duration_minutes: int, status: string, professional_id: int, professional_name: string}>`; `busy(DateTimeImmutable $day, ?int $professionalId): array` → `list<array{professional_id: int, start: string, end: string}>`; `professionals(): array` → `list<array{id: int, name: string}>`; `professionalExists(int $id): bool`
- Produz: `new CopilotAgendaQuery(TenantContext $context, PDO $connection)`, `new FakeCopilotAgendaQuery()` com `seed(string $method, array $rows)`
- Produz: `FreeSlotFinder::find(DateTimeImmutable $day, int $durationMinutes, array $busy, array $professionalIds, DateTimeImmutable $now): array` → `list<array{start: string, professional_id: int}>`
- Produz: `new ConsultarAgendaTool(CopilotAgendaQueryInterface $query, ?Closure $clock = null)` — `name() === 'consultar_agenda'`, `requiredPermission() === 'AgendaView::onReload'`, entrada `data` ou `data_inicio` + `data_fim` (`format: date`; intervalo ≤ 7 dias, senão `ToolInputException`), `profissional_id` opcional; saída `horario`, `paciente`, `tipo`, `status`, `profissional` (primeiro nome)
- Produz: `new VerificarHorariosTool(CopilotAgendaQueryInterface $query, ?Closure $clock = null)` — `name() === 'verificar_horarios'`, `requiredPermission() === 'AgendaView::onReload'`, entrada `data` (`format: date`), `duracao_min` (15–240), `profissional_id` opcional; saída `horario`, `profissional_id`, `profissional`
- Consome: T-04 `interface AiTool`, T-04 `new ToolResult(array $data, bool $truncated = false, array $freeTextFields = [], array $knownNames = [])`, T-04 `ToolFormat::firstName(?string $fullName): ?string`, T-04 `ToolEntityNotFoundException`, T-04 `ToolInputException`

**Teste RED**
- `src/tests/Unit/AgendaToolsTest.php` — `FreeSlotFinder::find` com agendamento 09:00–09:45 do profissional 5 e duração 30 não devolve 09:00 nem 09:30 para o 5 e devolve 10:00; duração 60 não devolve 18:30; `consultar_agenda` com `data_inicio=2026-10-01` e `data_fim=2026-10-10` lança `ToolInputException`; `profissional_id` inexistente → `ToolEntityNotFoundException` em `assertInScope`; falha antes porque as classes não existem (comando: `SUITE | /usr/bin/grep -E 'AgendaTools|CopilotAgendaQuery|Failed:'`)

**Critério de aceite**
- `CopilotAgendaQueryIntegrationTest`: agendamento da unidade B não aparece com contexto na A; agendamento `cancelado` não ocupa horário.
- Saída sem CPF, telefone, e-mail ou endereço; profissional e tutor só pelo primeiro nome; no máximo 50 linhas com `truncated`.

**Validação**
- `SUITE | /usr/bin/grep -E 'AgendaTools|CopilotAgendaQuery|Failed:'` (evidência: `PASS` e `Failed: 0`)
- `LINT app/Core/Persistence/CopilotAgendaQuery.php`, `LINT app/Core/Application/FreeSlotFinder.php`, `LINT app/Core/Ai/Tools/Management/ConsultarAgendaTool.php`, `LINT app/Core/Ai/Tools/Management/VerificarHorariosTool.php` (evidência: `No syntax errors detected`)

### T-20 — Ferramenta `consultar_estoque`

**Camada:** backend
**Dependências:** T-04
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Goku

8B.2. Saldo = `SUM(stock_batch.quantity)` só da unidade ativa (`StockSalesOverviewReader::productStocks` :50 soma todas as unidades; a ferramenta não). `abaixo_minimo` = `product.minimum_stock_quantity > 0` e saldo ≤ mínimo (inclui zerado); `vencendo_30d` = lotes da unidade com `quantity > 0` e `expiry_date` entre hoje e hoje + 30; `termo` = `product.name LIKE %termo%` (ou `code`). Só produtos `active`. Queries com `/*+ MAX_EXECUTION_TIME(5000) */`.

**Arquivos prováveis**
- `src/app/Core/Domain/Contract/CopilotStockQueryInterface.php`
- `src/app/Core/Persistence/CopilotStockQuery.php`
- `src/app/Core/Ai/Tools/Management/ConsultarEstoqueTool.php`
- `src/tests/Support/FakeCopilotStockQuery.php`
- `src/tests/Unit/StockToolTest.php`
- `src/tests/Integration/CopilotStockQueryIntegrationTest.php`

**Interface**
- Produz: `CopilotStockQueryInterface::belowMinimum(int $limit): array`, `expiring(DateTimeImmutable $today, int $days, int $limit): array`, `search(string $term, int $limit): array`, todas → `list<array{product_id: int, name: string, balance: string, minimum: ?string, next_expiry: ?string, lot: ?string}>`
- Produz: `new CopilotStockQuery(TenantContext $context, PDO $connection)`, `new FakeCopilotStockQuery()` com `seed(string $method, array $rows)`
- Produz: `new ConsultarEstoqueTool(CopilotStockQueryInterface $query, ?Closure $clock = null)` — `name() === 'consultar_estoque'`, `requiredPermission() === 'ProductList::onReload'`, entrada `filtro` (enum `abaixo_minimo`/`vencendo_30d`/`termo`, obrigatório), `termo` (2–60; obrigatório com `filtro=termo`, senão `ToolInputException`); saída `item`, `saldo`, `minimo`, `validade`, `lote`
- Consome: T-04 `interface AiTool`, T-04 `new ToolResult(array $data, bool $truncated = false, array $freeTextFields = [], array $knownNames = [])`, T-04 `ToolInputException`

**Teste RED**
- `src/tests/Unit/StockToolTest.php` — `filtro=termo` sem `termo` lança `ToolInputException`; `abaixo_minimo` com 60 itens no fake devolve 50 e `truncated`; falha antes porque `ConsultarEstoqueTool` não existe (comando: `SUITE | /usr/bin/grep -E 'StockTool|CopilotStockQuery|Failed:'`)

**Critério de aceite**
- `CopilotStockQueryIntegrationTest`: lote de 10 unidades na unidade B não entra no saldo da A; produto com mínimo 5 e saldo 3 na A aparece em `belowMinimum`; lote vencendo em 20 dias aparece em `expiring`, em 40 dias não.

**Validação**
- `SUITE | /usr/bin/grep -E 'StockTool|CopilotStockQuery|Failed:'` (evidência: `PASS` e `Failed: 0`)
- `LINT app/Core/Persistence/CopilotStockQuery.php`, `LINT app/Core/Ai/Tools/Management/ConsultarEstoqueTool.php` (evidência: `No syntax errors detected`)

### T-21 — Ferramentas `listar_debitos` e `indicadores_clinica`

**Camada:** backend
**Dependências:** T-04
**Paralelizável:** sim
**Complexidade:** alta
**Agente:** Ada

8B.2. Débitos: `receivable` com `status IN ('open', 'partially_paid')`, unidade por `encounter_account.system_unit_id` (junção de `PendingItemQuery::openReceivables` :357), valor em aberto = `total_cents - paid_cents`, vencimento = `created_at + 7 dias` (o schema não tem vencimento; mesma regra da tela de pendências). Indicadores no período, unidade ativa: `encounters` = atendimentos iniciados no período; `revenue_cents` = `FinancialOverviewReader::totalsByType(...)['income']`; `new_patients` = pacientes cujo primeiro atendimento na unidade cai no período; `returning_encounters` = atendimentos do período de pacientes com atendimento anterior na unidade. Períodos: `mes_atual`, `mes_anterior` (no fuso `date_default_timezone_get()`), `ultimos_30d`. Queries com `/*+ MAX_EXECUTION_TIME(5000) */`.

**Arquivos prováveis**
- `src/app/Core/Domain/Contract/CopilotFinanceQueryInterface.php`
- `src/app/Core/Persistence/CopilotFinanceQuery.php`
- `src/app/Core/Ai/Tools/Management/ListarDebitosTool.php`
- `src/app/Core/Ai/Tools/Management/IndicadoresClinicaTool.php`
- `src/tests/Support/FakeCopilotFinanceQuery.php`
- `src/tests/Unit/FinanceToolsTest.php`
- `src/tests/Integration/CopilotFinanceQueryIntegrationTest.php`

**Interface**
- Produz: `CopilotFinanceQueryInterface::openReceivables(string $situacao, DateTimeImmutable $today, int $limit): array` (`vencidos`/`a_vencer`) → `list<array{receivable_id: int, tutor_full_name: string, open_cents: int, due_date: string}>`; `indicators(DateTimeImmutable $from, DateTimeImmutable $to): array` → `array{encounters: int, revenue_cents: int, new_patients: int, returning_encounters: int}`
- Produz: `new CopilotFinanceQuery(TenantContext $context, PDO $connection)`, `new FakeCopilotFinanceQuery()` com `seed(string $method, array $rows)`
- Produz: `new ListarDebitosTool(CopilotFinanceQueryInterface $query, ?Closure $clock = null)` — `name() === 'listar_debitos'`, `requiredPermission() === 'PendingReceivableList::onReload'`, entrada `situacao` (enum `vencidos`/`a_vencer`), `limite` (1–50, padrão 20); saída `tutor` (primeiro nome), `valor` (`R$ 1.234,56`), `vencimento`, `dias_em_atraso`
- Produz: `new IndicadoresClinicaTool(CopilotFinanceQueryInterface $query, ?Closure $clock = null)` — `name() === 'indicadores_clinica'`, `requiredPermission() === 'FinancialOverview::onFilter'`, entrada `periodo` (enum `mes_atual`/`mes_anterior`/`ultimos_30d`); saída `periodo`, `atendimentos`, `receita`, `ticket_medio` (receita / atendimentos; `null` com 0 atendimentos), `novos_pacientes`, `retorno_percentual`
- Consome: T-04 `interface AiTool`, T-04 `new ToolResult(array $data, bool $truncated = false, array $freeTextFields = [], array $knownNames = [])`, T-04 `ToolFormat::firstName(?string $fullName): ?string`

**Teste RED**
- `src/tests/Unit/FinanceToolsTest.php` — `listar_debitos` com tutor `João Pedro Alves`, `open_cents 123456` e `due_date 2026-10-01` (relógio 2026-10-15) devolve `tutor === 'João'`, `valor === 'R$ 1.234,56'`, `dias_em_atraso === 14` e nenhuma chave de contato; `indicadores_clinica` com 0 atendimentos devolve `ticket_medio === null`; falha antes porque as classes não existem (comando: `SUITE | /usr/bin/grep -E 'FinanceTools|CopilotFinanceQuery|Failed:'`)

**Critério de aceite**
- `CopilotFinanceQueryIntegrationTest`: recebível de conta da unidade B não aparece na A; `paid` e `cancelled` não aparecem; `indicators` conta 1 paciente novo e 1 atendimento de retorno no cenário semeado.

**Validação**
- `SUITE | /usr/bin/grep -E 'FinanceTools|CopilotFinanceQuery|Failed:'` (evidência: `PASS` e `Failed: 0`)
- `LINT app/Core/Persistence/CopilotFinanceQuery.php`, `LINT app/Core/Ai/Tools/Management/ListarDebitosTool.php`, `LINT app/Core/Ai/Tools/Management/IndicadoresClinicaTool.php` (evidência: `No syntax errors detected`)

### T-22 — Flag `ai_copilot_management`: serviço e interruptor em `AiSettingsForm`

**Camada:** frontend
**Dependências:** T-04, T-07, T-15
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Curie

8B.2. Segundo switch em `AiSettingsForm` (padrão do `TCheckButton('ai_enabled')` :37-39, `setUseSwitch(true, 'blue')`), rótulo à esquerda, gravado no mesmo `onSave` (:79) dentro da transação. `AiSettingsService` autoriza `AiSettingsForm::onSave` (`authorize()` :218) e grava a flag com o usuário.

**Arquivos prováveis**
- `src/app/Core/Application/AiSettingsService.php`
- `src/app/control/clinic/AiSettingsForm.php`
- `src/app/config/translations.json`
- `src/tests/Unit/AiSettingsServiceTest.php`
- `src/tests/Integration/AiSettingsScreensIntegrationTest.php`

**Interface**
- Produz: `AiSettingsService::setCopilotManagement(bool $enabled): void` e a chave `copilot_management` (bool) em `AiSettingsService::overview()`
- Produz: campo `ai_copilot_management` (switch) em `AiSettingsForm`; tradução `Enable the management copilot (schedule, stock, debts and indicators)` → `Ligar o copiloto de gestão (agenda, estoque, débitos e indicadores)`
- Consome: T-04 `FeatureFlagRepositoryInterface::AI_COPILOT_MANAGEMENT = 'ai_copilot_management'`, T-07 `Management copilot` → `Copiloto de gestão`

**Teste RED**
- `src/tests/Unit/AiSettingsServiceTest.php` — `setCopilotManagement(true)` com `FakeFeatureFlagRepository` registra `ai_copilot_management` = `true` com o usuário do contexto e `overview()['copilot_management'] === true`; com a política negando `AiSettingsForm::onSave`, lança e não muda a flag; falha antes porque o método não existe (comando: `SUITE | /usr/bin/grep -E 'AiSettingsService|AiSettingsScreens|Failed:'`)

**Critério de aceite**
- `AiSettingsScreensIntegrationTest`: o HTML tem o switch `ai_copilot_management` com o rótulo em pt e `cv-touch-target`; o fonte não tem `text-align:right|center`.
- Ligar e desligar grava `feature_flag` (`flag_key = 'ai_copilot_management'`, `enabled` 1/0) sem alterar a linha de `ai_assistant`.

**Validação**
- `SUITE | /usr/bin/grep -E 'AiSettingsService|AiSettingsScreens|CopilotTranslations|Failed:'` (evidência: `PASS` e `Failed: 0`)
- `LINT app/Core/Application/AiSettingsService.php`, `LINT app/control/clinic/AiSettingsForm.php` (evidência: `No syntax errors detected`)

### T-23 — `ManagementToolset`, registro no serviço e item "Copiloto de gestão"

**Camada:** backend
**Dependências:** T-07, T-13, T-17, T-19, T-20, T-21
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Lamport

8B.2. `AiCopilotService::forContext` passa a registrar também `ManagementToolset::create(...)` (as queries PDO das T-19/T-20/T-21). O painel de gestão abre pelo item `Management copilot` no submenu de IA, com ação `AiCopilotPanel` e parâmetros `method=onOpen` e `family=management` no formato de ação do `menu.xml` (como o item do dashboard em `menu.xml:10-13`).

**Arquivos prováveis**
- `src/app/Core/Ai/Tools/Management/ManagementToolset.php`
- `src/app/Core/Application/AiCopilotService.php`
- `src/menu.xml`
- `src/tests/Integration/AiNavigationIntegrationTest.php`
- `src/tests/Unit/ManagementToolsetTest.php`

**Interface**
- Produz: `ManagementToolset::create(CopilotAgendaQueryInterface $agenda, CopilotStockQueryInterface $stock, CopilotFinanceQueryInterface $finance, ?Closure $clock = null): array` (as 5 ferramentas de gestão)
- Produz: item de menu `Management copilot` → `AiCopilotPanel` com `family=management`
- Consome: T-07 `Management copilot` → `Copiloto de gestão`, T-13 `AiCopilotService::forContext(TenantContext $context, PDO $connection, string $locale): self`, T-17 `My conversations` → `AiConversationList`, T-19 `new ConsultarAgendaTool(CopilotAgendaQueryInterface $query, ?Closure $clock = null)`, T-19 `new VerificarHorariosTool(CopilotAgendaQueryInterface $query, ?Closure $clock = null)`, T-19 `new CopilotAgendaQuery(TenantContext $context, PDO $connection)`, T-20 `new ConsultarEstoqueTool(CopilotStockQueryInterface $query, ?Closure $clock = null)`, T-20 `new CopilotStockQuery(TenantContext $context, PDO $connection)`, T-21 `new ListarDebitosTool(CopilotFinanceQueryInterface $query, ?Closure $clock = null)`, T-21 `new IndicadoresClinicaTool(CopilotFinanceQueryInterface $query, ?Closure $clock = null)`, T-21 `new CopilotFinanceQuery(TenantContext $context, PDO $connection)`

**Teste RED**
- `src/tests/Unit/ManagementToolsetTest.php` — `ManagementToolset::create` devolve 5 ferramentas com os nomes da `ToolFamily::ALLOWLIST['management']` e as permissões `AgendaView::onReload` (2), `ProductList::onReload`, `PendingReceivableList::onReload` e `FinancialOverview::onFilter`; com `ToolRegistry` sobre `FakeFeatureFlagRepository(['ai_assistant' => true, 'ai_copilot_management' => true])` e política permitindo, `definitions('management')` tem 5 entradas, e sem `ai_copilot_management` tem 0; falha antes porque `ManagementToolset` não existe (comando: `SUITE | /usr/bin/grep -E 'ManagementToolset|AiNavigation|AiCopilotService|Failed:'`)

**Critério de aceite**
- `AiNavigationIntegrationTest`: submenu de IA com 5 filhos (os 4 da T-17 + `Management copilot` → `AiCopilotPanel`).
- `AiCopilotServiceForContextIntegrationTest` (T-13) segue `PASS` e, com as duas flags ligadas, `ask('management', null, null, 'agenda de hoje')` grava `ai_interaction` com `purpose = 'copilot_management'`.

**Validação**
- `SUITE | /usr/bin/grep -E 'ManagementToolset|AiNavigation|AiCopilotService|Failed:'` (evidência: `PASS` e `Failed: 0`)
- `LINT app/Core/Ai/Tools/Management/ManagementToolset.php`, `LINT app/Core/Application/AiCopilotService.php` (evidência: `No syntax errors detected`)

### T-24 — Runbook da 8B.2 (gestão)

**Camada:** docs
**Dependências:** T-18, T-19, T-20, T-21, T-22
**Paralelizável:** sim
**Complexidade:** simples
**Agente:** Gandalf

Acrescenta à seção do copiloto em `ia-assistente.md` a subseção "Copiloto de gestão (8B.2)": flag `ai_copilot_management` e o interruptor em `AiSettingsForm`, as 5 ferramentas com `requiredPermission`, as regras (vencimento = criação + 7 dias, saldo por unidade, horários livres pela grade 07:00–19:00), o item de menu e o roteiro de smoke de gestão (uma pergunta com chave real).

**Arquivos prováveis**
- `docs/runbooks/ia-assistente.md`

**Interface**
- Produz: subseção `### Copiloto de gestão (8B.2)` em `ia-assistente.md`
- Consome: T-22 `AiSettingsService::setCopilotManagement(bool $enabled): void`

**Teste RED**
- sem teste: documentação

**Critério de aceite**
- `/usr/bin/grep -n "### Copiloto de gestão (8B.2)\|ai_copilot_management\|PendingReceivableList::onReload\|FinancialOverview::onFilter" docs/runbooks/ia-assistente.md` mostra as 4 ocorrências.

**Validação**
- `/usr/bin/grep -c "AgendaView::onReload\|ProductList::onReload\|PendingReceivableList::onReload\|FinancialOverview::onFilter" docs/runbooks/ia-assistente.md` (evidência: ≥ 4)

### T-25 — Correção: PII minimizada antes do corte de texto e visibilidade das ferramentas em cache

**Camada:** backend
**Dependências:** T-04, T-08, T-10, T-11, T-13
**Paralelizável:** sim
**Complexidade:** alta
**Agente:** Noether

Origem: revisão final (bloqueante 2) e `/code-review` de `bc35d6d..b2464f4` (item 7).

Achado 1 (bloqueante, PII): `ToolFormat::excerpt` corta o texto (160 em `ObterHistoricoAtendimentosTool.php:100`, 400 em `ObterExamesTool.php:103`) antes de `ToolPolicy::content` (`ToolPolicy.php:179-187`) passar o `PiiMinimizer`. Um corte no meio de `(85) 98765-4321`, de um CPF ou de um nome deixa um fragmento que o minimizador não reconhece e que vai ao modelo. Reprodução exigida: anamnese com 152 caracteres seguidos de `(85) 98765-4321` (o corte de 160 cai dentro do número) e resultado de exame com `123.456.789-09` atravessando a posição 400. O `content` enviado ao modelo não pode ter nenhum dígito do telefone ou do CPF. Veredito: corrigir. As ferramentas devolvem o texto completo; a política minimiza o texto inteiro e só depois corta. Isso vale para toda ferramenta que corta texto, hoje as duas citadas.

Achado 2 (code-review, eficiência e RBAC duplicado): `ToolRegistry::find` (:75-77) refaz `visible()` a cada lookup, o que repete o RBAC e grava `audit_log` por ferramenta, e `ToolPolicy` (:158) autoriza de novo. Reprodução exigida: uma pergunta com 3 `tool_use` gera, com `FakeAuthorizationPolicy`, exatamente 1 decisão por ferramenta ofertada. Veredito: corrigir. A visibilidade fica em cache por família na instância do registry (uma instância por requisição); a política deixa de chamar o RBAC e consulta o motivo em cache. O comportamento continua fail-closed: ferramenta não visível nunca executa.

**Arquivos prováveis**
- `src/app/Core/Ai/Tools/ToolResult.php`
- `src/app/Core/Ai/Tools/ToolRegistry.php`
- `src/app/Core/Ai/Tools/ToolPolicy.php`
- `src/app/Core/Ai/Tools/Clinical/ObterHistoricoAtendimentosTool.php`
- `src/app/Core/Ai/Tools/Clinical/ObterExamesTool.php`
- `src/app/Core/Application/AiCopilotService.php`
- `src/tests/Unit/ToolPolicyTest.php`
- `src/tests/Unit/ToolRegistryTest.php`
- `src/tests/Unit/ClinicalToolsPatientTest.php`
- `src/tests/Unit/ClinicalToolsRecordsTest.php`
- `src/tests/Unit/AiCopilotServiceTest.php`

**Interface**
- Produz: `new ToolResult(array $data, bool $truncated = false, array $freeTextFields = [], array $knownNames = [], array $excerptLengths = [])`. `$excerptLengths` é um `array<string, int>` de campo para máximo de caracteres. A `ToolPolicy` aplica `PiiMinimizer::minimize` ao texto completo de cada campo de `freeTextFields` e só depois `ToolFormat::excerpt($minimizado, $max)` nos campos de `excerptLengths`.
- Produz: `ObterHistoricoAtendimentosTool` devolve `motivo` com a anamnese completa e `excerptLengths` `['motivo' => 160]`. `ObterExamesTool` devolve `resultado` com o texto completo e `['resultado' => 400]`. Nenhuma ferramenta chama `ToolFormat::excerpt` em texto livre.
- Produz: `ToolRegistry::denialReason(string $family, string $name): ?string`, que devolve `null` (visível), `not_offered` (fora da allowlist ou com flag desligada) ou `forbidden` (RBAC negado). `visible()`, `definitions()`, `find()` e `denialReason()` usam o mesmo cache por família, com 1 `decide` por ferramenta por instância.
- Produz: `new ToolPolicy(ToolRegistry $registry, JsonSchemaValidator $validator, McpToolCallRepositoryInterface $calls, ToolRateLimiter $rateLimiter, PiiMinimizer $minimizer, ?Closure $monotonicMs = null)`. Sai o `AuthorizationPolicyInterface`. O passo 3 usa `ToolRegistry::denialReason` e devolve `forbidden`; a ordem e os motivos da T-08 ficam iguais.
- Consome: T-04 `ToolFormat::excerpt(?string $text, int $max): ?string`, T-08 `ToolPolicy::execute(ToolContext $context, string $family, string $toolName, array $input, array $offeredNames): ToolOutcome`, T-13 `AiCopilotService::forContext(TenantContext $context, PDO $connection, string $locale): self`

**Teste RED**
- `src/tests/Unit/ToolPolicyTest.php` — o `FakeAiTool` devolve `motivo` = 152 caracteres + `(85) 98765-4321 ligou` com `excerptLengths ['motivo' => 160]`, e `resultado` = 390 caracteres + `CPF 123.456.789-09` com `['resultado' => 400]`. O `content` não pode conter `98765`, `987`, `4321`, `123.456` nem `789-09`. Com 3 execuções de ferramentas visíveis, a `FakeAuthorizationPolicy` recebe exatamente 1 pedido por ferramenta ofertada. Hoje falha porque `ToolResult` não aceita `excerptLengths` e a política autoriza a cada chamada (comando: `SUITE | /usr/bin/grep -E 'ToolPolicy|ToolRegistry|ClinicalTools|AiCopilotService|Failed:'`)

**Critério de aceite**
- Os dois textos da reprodução chegam ao `content` sem nenhum dígito do telefone ou do CPF; o campo cortado tem no máximo 160 (ou 400) caracteres.
- `FakeAuthorizationPolicy::requests` tem 1 pedido por ferramenta da família, por instância de `ToolRegistry`, em uma pergunta com 3 `tool_use`; a `ToolPolicy` não tem dependência de `AuthorizationPolicyInterface` (`/usr/bin/grep -c "AuthorizationPolicyInterface" src/app/Core/Ai/Tools/ToolPolicy.php` → `0`).
- Os 7 motivos de recusa da T-08 continuam com o mesmo `deny_reason`.
- `/usr/bin/grep -rn "ToolFormat::excerpt" src/app/Core/Ai/Tools/Clinical src/app/Core/Ai/Tools/Management` → nenhuma linha.

**Validação**
- `SUITE | /usr/bin/grep -E 'ToolPolicy|ToolRegistry|ClinicalTools|AiCopilotService|Failed:'` (evidência: `PASS` dos casos novos e antigos, `Failed: 0`)
- `LINT app/Core/Ai/Tools/ToolPolicy.php`, `LINT app/Core/Ai/Tools/ToolRegistry.php`, `LINT app/Core/Ai/Tools/ToolResult.php` (evidência: `No syntax errors detected`)

### T-26 — Correção: disponibilidade leve do copiloto nas telas hospedeiras

**Camada:** frontend
**Dependências:** T-13, T-16
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Hamilton

Origem: `/code-review` de `bc35d6d..b2464f4` (item 8).

Achado: para decidir se o link aparece, `EncounterView::copilotAvailable` (:1695-1705) e `PatientForm::copilotLink` (:732-752) montam a cada renderização um `AiCopilotService::forContext` completo, com gateway, `ToolRateLimiter::fromEnvironment()` (Redis) e registry. Com o Redis fora do ar o link some, e cada renderização paga o custo inteiro. Reprodução exigida: `RATE_LIMIT_DRIVER=redis` com `REDIS_HOST` inválido, IA ligada e termo aceito. O link "Copiloto" tem de aparecer nas duas telas. Veredito: corrigir com uma checagem leve que lê só flag, termo, cota e configuração.

**Arquivos prováveis**
- `src/app/Core/Application/AiCopilotAvailability.php`
- `src/tests/Unit/AiCopilotAvailabilityTest.php`
- `src/app/control/clinic/PatientForm.php`
- `src/app/control/clinic/EncounterView.php`
- `src/tests/Integration/CopilotHostButtonsIntegrationTest.php`

**Interface**
- Produz: `new AiCopilotAvailability(FeatureFlagRepositoryInterface $flags, AiTenantSettingRepositoryInterface $settings, AiInteractionRepositoryInterface $interactions, Closure $configured, TenantContext $context, ?Closure $clock = null)` e `AiCopilotAvailability::forContext(TenantContext $context, PDO $connection): self`. `$configured` é `fn (): bool`. No `forContext`, ele testa se `AiConfig::fromEnvironment()` sai sem exceção e se o provedor é `stub` ou é `anthropic` com `apiKey() !== null` (a regra de `AnthropicMessagesGateway::isConfigured`), sem instanciar gateway, limiter nem registry.
- Produz: `AiCopilotAvailability::reason(string $family): ?string`, na ordem `ai_assistant` → termo `AiTerms::VERSION` → cota do mês no fuso da aplicação → configuração. Na gestão, exige também `ai_copilot_management`, senão `ai_disabled`. É a mesma regra de `AiCopilotService::availability`.
- Produz: `EncounterView` e `PatientForm` mostram o link quando `AiCopilotAvailability::forContext($context, TTransaction::get())->reason('clinical') === null`. A falha da checagem continua escondendo o link, com `error_log` só da classe.
- Consome: T-13 `AiCopilotService::availability(string $family): ?string`, T-16 `data-cv-copilot="clinical"`

**Teste RED**
- `src/tests/Unit/AiCopilotAvailabilityTest.php` — com fakes, `reason('clinical')` devolve `ai_disabled`, `ai_terms_pending`, `ai_quota_exceeded` e `ai_not_configured` nessa ordem, e `reason('management')` sem `ai_copilot_management` devolve `ai_disabled`. Hoje falha porque `AiCopilotAvailability` não existe (comando: `SUITE | /usr/bin/grep -E 'AiCopilotAvailability|CopilotHostButtons|Failed:'`)

**Critério de aceite**
- `CopilotHostButtonsIntegrationTest`: com `RATE_LIMIT_DRIVER=redis` e `REDIS_HOST=invalid.local` no subprocesso, o HTML de `PatientForm::onEdit` e de `EncounterView::onReload` contém `data-cv-copilot="clinical"`; com a flag desligada, não contém.
- `/usr/bin/grep -c "AiCopilotService::forContext" src/app/control/clinic/EncounterView.php src/app/control/clinic/PatientForm.php` → `0` nos dois.

**Validação**
- `SUITE | /usr/bin/grep -E 'AiCopilotAvailability|CopilotHostButtons|EncounterAi|Failed:'` (evidência: `PASS` e `Failed: 0`)
- `LINT app/Core/Application/AiCopilotAvailability.php`, `LINT app/control/clinic/EncounterView.php`, `LINT app/control/clinic/PatientForm.php` (evidência: `No syntax errors detected`)

### T-27 — Correção: horários livres só de profissionais clínicos, distribuídos no dia

**Camada:** backend
**Dependências:** T-19
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Gauss

Origem: `/code-review` de `bc35d6d..b2464f4` (item 6).

Achado: sem `profissional_id`, `VerificarHorariosTool` (:105-121) usa todo usuário ativo do tenant (incluindo recepção e administração) e corta os primeiros 50 horários. O resultado fica com um ou dois profissionais e só a manhã. Reprodução exigida: 3 profissionais livres o dia inteiro (24 horários cada) e 1 usuário administrativo sem agenda na unidade. O resultado tem de cobrir os 3 profissionais da manhã ao fim da tarde e não pode citar o administrativo. Veredito: corrigir. O schema não marca papel clínico, e o critério da agenda (`AgendaView::listProfessionals`) só exige usuário ativo do tenant. A regra registrada é: profissional elegível = usuário ativo do tenant com pelo menos um `appointment` na unidade ativa nos 90 dias antes do dia pedido ou no próprio dia; sem nenhum elegível, volta à lista da agenda. O limite de 50 é repartido igualmente entre os profissionais, com horários espaçados pelo dia.

**Arquivos prováveis**
- `src/app/Core/Domain/Contract/CopilotAgendaQueryInterface.php`
- `src/app/Core/Persistence/CopilotAgendaQuery.php`
- `src/app/Core/Application/FreeSlotFinder.php`
- `src/app/Core/Ai/Tools/Management/VerificarHorariosTool.php`
- `src/tests/Support/FakeCopilotAgendaQuery.php`
- `src/tests/Unit/AgendaToolsTest.php`
- `src/tests/Integration/CopilotAgendaQueryIntegrationTest.php`

**Interface**
- Produz: `CopilotAgendaQueryInterface::schedulableProfessionals(DateTimeImmutable $day): array` → `list<array{id: int, name: string}>`, com a regra acima e o hint `/*+ MAX_EXECUTION_TIME(5000) */`.
- Produz: `FreeSlotFinder::spread(array $slots, int $limit): array`. A cota por profissional é `max(1, intdiv($limit, n))`, onde `n` é o número de profissionais com horário livre. Os horários escolhidos de cada profissional são os índices `round(i * (k - 1) / (cota - 1))` dos `k` livres dele. A saída vem ordenada por horário e depois por profissional, com no máximo `$limit` linhas.
- Produz: `VerificarHorariosTool` sem `profissional_id` usa `schedulableProfessionals` e `spread($slots, ToolResult::MAX_ROWS)`; `truncated` é `true` quando algum horário livre ficou de fora. Com `profissional_id`, o comportamento da T-19 não muda.
- Consome: T-19 `FreeSlotFinder::find(DateTimeImmutable $day, int $durationMinutes, array $busy, array $professionalIds, DateTimeImmutable $now): array`

**Teste RED**
- `src/tests/Unit/AgendaToolsTest.php` — com o fake devolvendo 3 profissionais elegíveis livres de 07:00 a 19:00 (duração 30), `verificar_horarios` sem `profissional_id` devolve no máximo 50 linhas, pelo menos 16 por profissional, e cada um com horário antes de 09:00 e depois de 17:00, com `truncated` `true`. Hoje falha porque o corte pega só os primeiros 50 (comando: `SUITE | /usr/bin/grep -E 'AgendaTools|CopilotAgendaQuery|Failed:'`)

**Critério de aceite**
- `CopilotAgendaQueryIntegrationTest`: um usuário ativo do tenant sem agendamento na unidade nos 90 dias não aparece em `schedulableProfessionals`; um com agendamento há 30 dias na unidade aparece; um com agendamento só na unidade B não aparece com o contexto na A.
- Reprodução do achado: 3 profissionais cobertos da manhã à tarde e o usuário administrativo ausente.

**Validação**
- `SUITE | /usr/bin/grep -E 'AgendaTools|CopilotAgendaQuery|Failed:'` (evidência: `PASS` e `Failed: 0`)
- `LINT app/Core/Persistence/CopilotAgendaQuery.php`, `LINT app/Core/Application/FreeSlotFinder.php`, `LINT app/Core/Ai/Tools/Management/VerificarHorariosTool.php` (evidência: `No syntax errors detected`)

### T-28 — Correção: histórico legível sem IA, resposta minimizada, uso e auditoria gravados sempre

**Camada:** backend
**Dependências:** T-25, T-26
**Paralelizável:** não
**Complexidade:** alta
**Agente:** Dijkstra

Origem: revisão final (bloqueante 1, parte do serviço, e o achado da resposta não minimizada) e `/code-review` de `bc35d6d..b2464f4` (itens 3 e 4).

Achado 1 (promovido): o histórico do autor só é lido depois de `availability()`. Com cota, flag, termo, configuração ou Redis indisponível, a conversa fica inacessível. Veredito: corrigir. Ler conversa existente usa só o histórico (tenant, unidade e autor) e independe da disponibilidade da IA.

Achado 2 (revisão final, spec § 3.5): a resposta do assistente é gravada sem minimizar (`AiCopilotService.php:476`). Reprodução exigida: o stub responde `Ligue para (85) 98765-4321 ou maria@ex.com`. A mensagem gravada e o `CopilotAnswer::text` têm de trazer `[TELEFONE]` e o marcador de e-mail, sem os dados. Veredito: corrigir.

Achado 3 (code-review): todo o laço roda numa transação (`AiCopilotPanel.php:168`), e uma exceção tardia desfaz `ai_interaction` e `mcp_tool_call`. Reprodução exigida: `appendMessage` da resposta lança `RuntimeException` depois de 1 `tool_use`. Mesmo assim, 1 linha de `ai_interaction` e 1 de `mcp_tool_call` ficam gravadas, e a exceção sobe. Veredito: corrigir. O serviço não abre transação e espera um PDO em autocommit (a tela passa a fornecê-lo na T-29). `ai_interaction` é inserida em `finally`, com `recordFailure('ai_provider_error', ...)` quando houve exceção inesperada. `mcp_tool_call` é gravada pela política a cada chamada.

**Arquivos prováveis**
- `src/app/Core/Application/AiConversationHistoryService.php`
- `src/tests/Unit/AiConversationHistoryServiceTest.php`
- `src/app/Core/Application/AiCopilotService.php`
- `src/tests/Unit/AiCopilotServiceTest.php`
- `src/tests/Integration/AiCopilotServiceForContextIntegrationTest.php`

**Interface**
- Produz: `AiConversationHistoryService::conversation(int $id): ?array` → `array{conversation: AiConversation, messages: list<AiConversationMessage>}`. Usa RBAC `AiCopilotPanel::onOpen` com unidade e só devolve conversa do dono no tenant e na unidade. Não consulta flag, termo, cota, gateway nem Redis.
- Produz: `AiCopilotService::conversations`, `AiCopilotService::conversation` e `AiCopilotService::deleteConversation` delegam ao `AiConversationHistoryService`, sem checar disponibilidade.
- Produz: `AiCopilotService::availability` delega a `AiCopilotAvailability`, montada com os repositórios do serviço e `fn (): bool => $this->gateway->isConfigured()`.
- Produz: a resposta passa por `PiiMinimizer::minimize($texto, tutorNames(âncora))` antes de `appendMessage` e de `CopilotAnswer::text`.
- Produz: `ask` não abre transação (o chamador passa PDO em autocommit). `ai_interaction` é inserida em `finally`, com `recordFailure` para exceção inesperada. `CopilotAnswer::conversationId` é sempre o id da conversa gravada, mesmo em falha.
- Consome: T-13 `AiCopilotService::ask(string $family, ?int $conversationId, ?int $anchorPatientId, string $question): CopilotAnswer`, T-25 `new ToolPolicy(ToolRegistry $registry, JsonSchemaValidator $validator, McpToolCallRepositoryInterface $calls, ToolRateLimiter $rateLimiter, PiiMinimizer $minimizer, ?Closure $monotonicMs = null)`, T-26 `AiCopilotAvailability::reason(string $family): ?string`, T-26 `new AiCopilotAvailability(FeatureFlagRepositoryInterface $flags, AiTenantSettingRepositoryInterface $settings, AiInteractionRepositoryInterface $interactions, Closure $configured, TenantContext $context, ?Closure $clock = null)`

**Teste RED**
- `src/tests/Unit/AiCopilotServiceTest.php` — o stub responde `Ligue para (85) 98765-4321 ou maria@ex.com`, e a mensagem gravada e o `CopilotAnswer::text` não contêm `98765` nem `maria@ex.com`. Com `appendMessage` da resposta lançando `RuntimeException` depois de 1 `tool_use`, `FakeAiInteractionRepository::all()` tem 1 linha, `FakeMcpToolCallRepository::all()` tem 1 linha e a exceção sobe. Com a flag desligada, `conversation(<id do autor>)` devolve as mensagens. Hoje falha porque a resposta é gravada crua e `conversation()` passa pela disponibilidade (comando: `SUITE | /usr/bin/grep -E 'AiCopilotService|AiConversationHistoryService|Failed:'`)

**Critério de aceite**
- Os três cenários da reprodução passam; `AiConversationHistoryServiceTest` lê a conversa do autor com IA indisponível e devolve `null` para outro usuário, outra unidade e outro tenant.
- `AiCopilotServiceForContextIntegrationTest`: depois de um `ask` com 1 `tool_use` roteirizado, as linhas de `mcp_tool_call` e a de `ai_interaction` têm o mesmo `correlation_id`. Após uma exceção forçada na gravação da resposta, `SELECT COUNT(*) FROM ai_interaction WHERE correlation_id = <id>` → `1`. A contagem de `ai_conversation` anterior ao teste é preservada (nenhuma conversa existente é perdida).

**Validação**
- `SUITE | /usr/bin/grep -E 'AiCopilotService|AiConversationHistoryService|Failed:'` (evidência: `PASS` e `Failed: 0`)
- Review Focus: caso "pergunta com telefone e CPF" (T-13) continua `PASS` (evidência: nome do método no relatório)
- `LINT app/Core/Application/AiCopilotService.php`, `LINT app/Core/Application/AiConversationHistoryService.php` (evidência: `No syntax errors detected`)

### T-29 — Correção: painel e lista abrem conversa sem IA, mostram a resposta gravada e apagam só por POST

**Camada:** frontend
**Dependências:** T-26, T-28
**Paralelizável:** não
**Complexidade:** alta
**Agente:** Knuth

Origem: revisão final (bloqueante 1, parte da tela) e `/code-review` de `bc35d6d..b2464f4` (itens 3 e 5).

Achado 1 (promovido): `AiCopilotPanel::load` (:235-240) devolve a indisponibilidade antes de carregar a conversa. Com isso, "Abrir" em "Minhas conversas" não mostra nada com IA indisponível, e a resposta da pergunta que estoura a cota é gravada mas não aparece. Reprodução exigida: (a) com a flag `ai_assistant` desligada, abrir a conversa do autor mostra as mensagens dela e o motivo traduzido no lugar do campo de pergunta; (b) com a cota a 1 token do fim, perguntar mostra a resposta gravada e, em seguida, o motivo `ai_quota_exceeded`. Veredito: corrigir. Abrir e ler usam `AiConversationHistoryService::conversation`. Só o formulário de pergunta depende de `AiCopilotAvailability::reason`. Depois de `ask()`, a conversa é renderizada pelo id devolvido, qualquer que seja a disponibilidade.

Achado 2 (code-review 3): `perform` (:168) abre `static::transaction` em volta do laço inteiro. Veredito: corrigir. `onAsk` usa um PDO em autocommit (`connection()`); leitura e exclusão continuam em transação curta.

Achado 3 (code-review 5): a exclusão em `AiConversationList` (:78, :211) é um link GET (`TAction` serializado) sem proteção CSRF. Reprodução exigida: GET em `index.php?class=AiConversationList&method=onDelete&conversation_id=<id>&delete=1` não pode apagar. Veredito: corrigir no padrão da 8A (`onAiExamSummary`: `TButton` com `setFormName` num `TForm`, enviado por POST). `onDelete` nas duas telas recusa método diferente de POST com aviso e sem apagar.

**Arquivos prováveis**
- `src/app/control/clinic/AiCopilotPanel.php`
- `src/app/control/clinic/AiConversationList.php`
- `src/tests/Integration/AiCopilotPanelIntegrationTest.php`
- `src/tests/Integration/AiCopilotListsIntegrationTest.php`

**Interface**
- Produz: `protected static function connection(): PDO` em `AiCopilotPanel`, com padrão `TConnection::open('permission')` em autocommit e usado só por `onAsk`, e `protected static function makeHistory(TenantContext $context, PDO $connection): AiConversationHistoryService`.
- Produz: com `reason !== null`, o painel renderiza conversa, lista anterior e "Apagar conversa", e põe o motivo `ai_*` traduzido no lugar de `form_AiCopilotPanel`.
- Produz: `AiConversationList` apaga por um `TForm` `form_AiConversationList_<id>` com hidden `conversation_id` e `TButton` (`setFormName`) para `onDelete`. `AiCopilotPanel::onDelete` e `AiConversationList::onDelete` só apagam com `$_SERVER['REQUEST_METHOD'] === 'POST'`.
- Consome: T-26 `AiCopilotAvailability::reason(string $family): ?string`, T-28 `AiConversationHistoryService::conversation(int $id): ?array`

**Teste RED**
- `src/tests/Integration/AiCopilotPanelIntegrationTest.php` — em subprocesso, com `ai_assistant` desligada, `onOpen` com `conversation_id` do autor mostra o texto das 2 mensagens semeadas e o motivo traduzido, sem o textarea `question`. Com `REQUEST_METHOD=GET`, `onDelete` mantém `SELECT COUNT(*) FROM ai_conversation WHERE id = <id>` em `1`. Hoje falha porque o painel devolve só o motivo e apaga por GET (comando: `SUITE | /usr/bin/grep -E 'AiCopilotPanel|AiCopilotLists|Failed:'`)

**Critério de aceite**
- As reproduções (a), (b) e a do GET passam. Na reprodução (b), o HTML contém o texto da resposta do stub e o motivo de cota.
- `AiCopilotListsIntegrationTest`: o HTML de "Minhas conversas" não tem `<a` com `method=onDelete`, cada linha tem `form_AiConversationList_<id>`, e o POST apaga a conversa (`COUNT(*)` → `0`).
- Uma subclasse com `connection()` que registra `inTransaction()` durante `onAsk` vê `false`.

**Validação**
- `SUITE | /usr/bin/grep -E 'AiCopilotPanel|AiCopilotLists|Failed:'` (evidência: `PASS` e `Failed: 0`)
- Review Focus: caso "envio duplo" (T-14) e "conversa da unidade anterior" (T-15) continuam `PASS` (evidência: nomes dos métodos no relatório)
- `LINT app/control/clinic/AiCopilotPanel.php`, `LINT app/control/clinic/AiConversationList.php` (evidência: `No syntax errors detected`)

## Legenda

- [ ] pendente
- [~] em andamento
- [x] concluído com evidência
- [!] bloqueado
