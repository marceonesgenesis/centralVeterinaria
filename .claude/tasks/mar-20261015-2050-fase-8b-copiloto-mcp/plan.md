# Plano: Fase 8B — Copiloto com ferramentas MCP

## Objetivo
Entregar o copiloto conversacional interno do Central Vet (spec aprovada `docs/superpowers/specs/2026-10-15-fase-8b-copiloto-mcp-design.md`): um laço de tool use síncrono no PHP sobre o gateway da 8A, com ferramentas somente leitura com políticas (fail-closed, tenant + unidade + RBAC da tela, auditoria em `mcp_tool_call`, PII minimizada), histórico de conversa do autor com retenção de 90 dias, painel clínico (8B.1) e painel de gestão (8B.2).

## Premissas
- Branch de trabalho `feat/fase-8b-copiloto-mcp`, já em checkout (criada de `feat/fase-8a-ai-gateway` @ `2a77bef`; HEAD `bc35d6d` com a spec). Base `main`. Sem push.
- Migration `0015` compatível com MySQL 5.7 (via `scripts/prepare-mysql57.py`, prefixo `19-`). O plano só cria o arquivo; a aplicação no `centralvet_test` acontece no gate entre as Ondas 1 e 2, conduzida pelo orquestrador (skill `sql-write-approval`), como na 8A. Aplicar no `centralvet` (dev) ou em qualquer outro banco, e a DML de programas do seed, só com aprovação SQL explícita do usuário — fora deste plano.
- Sem dependência nova no composer (`src/composer.json` protegido por `framework_hashes.php`); validador de JSON Schema mínimo próprio.
- Entrega em duas partes no mesmo plano: 8B.1 = Ondas 1–5; 8B.2 = Ondas 6–7. Cada parte tem critério de aceite próprio (fim deste arquivo).
- TDD com RED por task; paralelismo só com arquivos disjuntos (checkout compartilhado, caminho exclusivo). Mensagens, telas e artefatos em português (pt/en no `translations.json`).
- Lacunas do modelo de dados resolvidas com o padrão abaixo (ver `notes.md § Decisões tomadas`; as que mudam escopo voltam como pergunta ao usuário):
  - `patient` não tem unidade. "Paciente da unidade" = tem pelo menos um `encounter` ou `appointment` com `system_unit_id` da unidade ativa; os registros clínicos devolvidos são só os de atendimentos da unidade (`prescription`, `exam_request`, `vaccination` herdam a unidade do `encounter`).
  - Não há coluna de nº de prontuário (usa-se `patient.id`), de condições crônicas (o campo sai da saída de `obter_paciente`) nem de motivo do atendimento (motivo = início da anamnese, 160 caracteres, minimizado).
  - `receivable` não tem vencimento nem unidade: vencimento = `created_at` + 7 dias, a mesma regra de `PendingItemQuery::openReceivables`; unidade pela `encounter_account.system_unit_id`.
  - Não existe dashboard clínico (`menu.xml` aponta para `CvShellController::onComingSoon`): o painel de gestão abre por um item "Copiloto de gestão" no submenu de IA.
  - Indicadores (atendimentos, ticket médio, novos pacientes, retorno) não existem prontos: query nova; a receita reaproveita `FinancialOverviewReader::totalsByType`.

## Escopo

### Incluso
- Gateway com tools: `AiCompletionRequest` com mensagens e `tools`, `AiCompletionResult` com `toolUses`, `AnthropicMessagesGateway` com `stop_reason: tool_use`, `StubAiGateway` roteirizado → T-02
- Finalidades `copilot_clinical`/`copilot_management`, status `answered` em `ai_interaction` e prompts versionados do copiloto → T-03
- Núcleo `src/app/Core/Ai/Tools/` (`AiTool`, `ToolContext`, `ToolResult`, `ToolRegistry`, validador JSON Schema) → T-04; `ToolPolicy` fail-closed com rate limit, limites de saída, PII e auditoria → T-08
- Migration `0015` (`ai_conversation`, `ai_conversation_message`, `mcp_tool_call`, CHECKs ampliados de `ai_interaction`) e `provision.sh` → T-01
- Domínio e repositórios de conversa e auditoria → T-05, T-09
- 7 ferramentas clínicas sobre query por unidade → T-06, T-10, T-11
- `AiCopilotService` (laço de no máximo 4 rodadas e 45 s, histórico, `ai_interaction`) → T-13
- Retenção de 90 dias por comando de cron → T-12
- Painel clínico (cortina lateral) e botões nas telas do paciente e do atendimento → T-14, T-16
- "Minhas conversas" e auditoria de ferramentas para o gestor → T-15
- Traduções pt/en, menu, seed de programas e testes de navegação → T-07, T-17
- Runbook `ia-assistente.md`, ADR 0007 e runbook do MySQL 5.7 → T-18
- 8B.2: 5 ferramentas de gestão → T-19, T-20, T-21; flag `ai_copilot_management` e interruptor em `AiSettingsForm` → T-04 (constante), T-22; painel de gestão e registro das ferramentas → T-23; runbook da gestão → T-24

### Excluído
- Ferramentas de escrita, confirmação humana e agentes (8C).
- Endpoint MCP externo e streaming de resposta.
- SDK ou dependência nova no composer; servidor Node.
- Executar a 0015 ou a DML de programas no `centralvet` (dev) ou em produção; push e PR.
- Apagar `mcp_tool_call` ou `ai_interaction` (são auditoria).

## Contexto técnico
- Camadas envolvidas: database, backend, frontend, docs, qa
- Projeto/base analisada: `/var/www/html/centralvet` (raiz de `git -C <DIR> rev-parse --show-toplevel`), branch `feat/fase-8b-copiloto-mcp` @ `bc35d6d`
- Integrações: provedor Anthropic só por `StubAiGateway` roteirizado ou transporte injetado nos testes; Redis (DB 15 nos testes) ou arquivo para o rate limit; MySQL 8.0.43 no Docker (`centralvet_test`)

## Baseline
- php-lint: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app sh -c 'find app/Core app/control app/lib/widget bin tests -name "*.php" -exec php -l {} \; 2>&1 | grep -v "^No syntax errors detected"'` em raiz → baseline/php-lint.txt (0 linhas). Critério das tasks: nenhum erro novo em relação a `baseline/php-lint.txt` (cada PHP tocado imprime `No syntax errors detected`).

## Exploração read-only
- Caminhos relevantes:
  - **Gateway 8A.** `src/app/Core/Domain/Contract/AiGatewayInterface.php:19` (`complete`, `isConfigured`, `providerName`); `src/app/Core/Domain/AiCompletionRequest.php:15` (só `purpose`, `promptVersion`, `system`, `userContent`); `src/app/Core/Domain/AiCompletionResult.php:13` (props readonly, sem `toolUses`); `src/app/Core/Ai/AnthropicMessagesGateway.php` (payload :68-74 com um único `user`; fallback :76-79; `parse` :101-150 aceita só `end_turn`/`stop_sequence` — hoje `tool_use` vira `ai_provider_error`; custo em `usageAndCost` :173); `src/app/Core/Ai/StubAiGateway.php:31` (eco `[stub] ...`, comportamento `timeout`); `src/app/Core/Domain/AiUsage.php`; `src/app/Core/Ai/AiPromptCatalog.php` (`VERSIONS` :27, `build` :156 com `match` exaustivo; hashes fixados em `AiPromptCatalogTest::testPromptTextIsPinnedToItsVersion`).
  - **Finalidade e interação.** `src/app/Core/Domain/AiPurpose.php` (`ALL` com 3; `subjectType`, `hostAction` :49, `decideAction` :61); `AiDomainTest.php:28` fixa `count(3, AiPurpose::ALL)`; `src/app/Core/Domain/AiInteraction.php` (`start` :68, `recordSuggestion` :173 exige texto, `recordFailure` :198, `STATUSES` :32). CHECKs `ai_interaction_purpose_ck`, `_subject_ck`, `_status_ck`, `_failed_ck`, `_decided_ck`, `_final_ck` em `src/app/database/migrations/20261007_0014_phase8a_ai_gateway.sql:137-142`.
  - **Disponibilidade e serviço 8A.** `src/app/Core/Application/AiAssistService.php` (ctor :73 com `?Closure $clock` por último; `forContext` :94; `unavailabilityReason` :306: flag → termo → cota → `isConfigured`; `generate` :330 com `CorrelationIdContext::current() ?? generate()`). Flags: `src/app/Core/Domain/Contract/FeatureFlagRepositoryInterface.php:13` (`AI_ASSISTANT`; linha ausente = desligado, flag nova = só uma constante).
  - **RBAC.** `AuthorizationRequest` (args nomeados `context, action, requiresUnitScope, resourceTenantId, resourceUnitId, entityType, entityId, metadata`; `ACTION_PATTERN` exige `Classe::metodo`); `AuthorizationDecision::allowed()`/`assertAllowed()`; `AdiantiProgramPermissionProvider.php:36` libera pela classe em `programs` e só nega método com `methods[class][method] === false`. `TenantContext::fromAuthenticatedSession(new AdiantiSessionContextSource())` nas telas.
  - **Rate limit.** `src/app/Core/Security/LoginRateLimiter.php` (ctor `(\Redis|FileRateLimitBackend, maxAttempts, decaySeconds, prefix)`, `fromEnvironment` :30 lê `RATE_LIMIT_DRIVER`; `tooManyAttempts`, `hit`); reuso com prefixo próprio em `src/lead.php:61`.
  - **Dados clínicos.** `patient` (sem unidade; `name`, `species`, `breed`, `birth_date`, `weight_kg`, `allergies`, `tutor_id`); `encounter.system_unit_id`, `weight_kg`, `anamnesis_text`, `physical_exam_text`, `diagnosis_text`, `clinical_plan_text`, `started_at`; `prescription` (`status` draft/issued, `valid_until`) + `prescription_item` (`medication_name`, `dose`, `dose_unit`, `route`, `frequency`, `duration`); `exam_request` (`status` requested/result_available, `requested_at`) + `exam_result` (`structured_result_text`, `pending_review`, `received_at`) + `exam_catalog_item.name`; `vaccination` (`applied_at`, `next_dose_at`) + `vaccine_catalog_item.name`. Leitores: `PatientRepository::search` :65, `ClinicalSummaryReader` (`findPatientWithTutor` :34, `listPrescriptionsByPatient` :85), `AiClinicalContextQuery` (só tenant), `PendingItemQuery::vaccinesDue` :199 (atraso).
  - **Telas e permissões** (a ação da tela que mostra o mesmo dado): `GlobalSearchController::onSearch` (:141), `PatientForm::onEdit` (:279), `EncounterView::onReload` (:2015, `historyCard` :1416), `PrescriptionForm::__construct` (:101; o histórico de prescrições do paciente é montado no construtor, `loadSummary` :308 — a tela não tem `onReload`), `ExamResultForm::__construct` (:54; mostra o resultado do exame), `VaccinationCardView::onReload` (:213), `AgendaView::onReload` (:60), `ProductList::onReload` (:109), `PendingReceivableList::onReload` (:154), `FinancialOverview::onFilter` (:101).
  - **Dados de gestão.** `AppointmentRepository::listByUnitAndDate` :86, `Appointment.php:29` (status agendado/confirmado/em_atendimento/atendido/cancelado/faltou), `AppointmentService::assertNoConflict` :275 (privado; bloqueiam agendado/confirmado/em_atendimento/atendido), `Service::durationMinutes`, `AgendaSlots` (07:00–19:00, passo 30, `slots()` :39), `AgendaView::listProfessionals` :274; `StockSalesOverviewReader::productStocks` :50 (soma todas as unidades — a ferramenta filtra a unidade), `stock_batch` (`system_unit_id`, `lot`, `expiry_date`, `quantity`), `product.minimum_stock_quantity`; `receivable` (`total_cents`, `paid_cents`, `status`, `tutor_id`, `encounter_account_id`), `PendingItemQuery::openReceivables` :357; `FinancialOverviewReader::totalsByType` :46.
  - **Telas da 8A.** `src/app/control/clinic/AiSettingsForm.php` (switch `TCheckButton('ai_enabled')` :37-39, `onSave` :79, `purposeLabel` :311), `AiInteractionList.php` (tabela `cv-table`, `statusLabel` :85, pontos de extensão `protected static`), `EncounterView.php` (3025 linhas; `pageHeader` :459-513, `headerButton` :531 com `TButton` que posta o formulário), `PatientForm.php` (ações de cabeçalho em `CvPage::header` :242-254), `CvPage::header` (`src/app/lib/widget/CvPage.php:22`). Cortina: `parent::setTargetContainer('adianti_right_panel')` (`SystemContactsList.php:27`), fechar com `TScript::create("Template.closeRightPanel()")` (`SystemUserForm.php:396`), abrir sem histórico com `register_state=false`.
  - **Assets e i18n.** `cv-components.css:888-963` (`.cv-ai-*`, `.cv-ai-status:empty`), `--cv-touch-target` (`custom.css:34`, protegido), regra de rótulos à esquerda (`custom.css:624-638`); JS por `TElement::tag('script', '', ['src' => ...])` (`EncounterView.php:1788`); `translations.json` (lista `en/pt` ordenada por `en`, 1411 entradas); `UserMessageTest.php:494-503` (controllers verificados) e :522 (`assertScreenKeysTranslated`).
  - **Banco, seed, menu, cron.** `src/app/database/migrations/` (par `.sql` + `.verify.sql`, cabeçalho PREPARED ONLY, placeholder de 64 zeros, `utf8mb4_0900_ai_ci`, `DROP CHECK` + `ADD CONSTRAINT` como na 0010/0011); `scripts/prepare-mysql57.py` e `scripts/test-prepare-mysql57.py`; `scripts/test-db/provision.sh:41-60` (lista fixa até a 0014); seed `src/app/database/seeds/initial-application-programs.sql` (bloco 8A :1011-1032, um único `COMMIT` :1034); `src/menu.xml:154-166` (submenu de IA em Settings); `AiNavigationIntegrationTest.php:65` exige exatamente 2 filhos; comandos de cron avulsos `src/bin/document-sweep.php` (JSON de contadores, exit 1 em erro; classe `forConnection(PDO)->runOnce()`).
  - **Testes.** Runner `src/tests/run.php` (sem filtro); `Support/Assert` estático; fakes `FakeAiGateway`, `FakeAiInteractionRepository`, `FakeAuthorizationPolicy(allowed, reason)`, `FakeFeatureFlagRepository`, `FakeAiTenantSettingRepository`, `SpyAuditLogWriter`, `FakeRedis`; integração herda `MysqlIntegrationTestCase` (transação + rollback; `createTenant()` privado, segunda unidade como `ExamResultAiIntegrationTest::otherUnitId` :715); telas em subprocesso `PHP_BINARY -r 'require "init.php"'` (`AiSettingsScreensIntegrationTest.php:66-92`, marcador JSON; `EncounterAiIntegrationTest.php:1268-1315` com `TSession` e `AI_PROVIDER=stub`).
- Padrões identificados: services recebem interfaces (repositórios, `AuthorizationPolicyInterface`, `TenantContext`) e `?Closure $clock` por último, com `forContext(TenantContext, PDO, ...)`; repositório = interface em `Domain/Contract/` + PDO em `Persistence/` com `TenantQuery::forTenant` + fake em `tests/Support/`; telas com pontos de extensão `protected static` para teste; ações de tela por `TAction` com POST; mensagens ao usuário por `CvFormat::userError`; texto clínico nunca em log, auditoria ou exceção.
- Scripts úteis: LINT e SUITE (em `agents/*.md § Ambiente`); `python3 scripts/prepare-mysql57.py` e `python3 scripts/test-prepare-mysql57.py`; `bash scripts/test-db/provision.sh --check`; sem build JS.
- Riscos identificados:
  - **Tool use quebra a 8A** se `AnthropicMessagesGateway` mudar o payload para quem não envia `tools` (T-02 mantém o payload byte a byte quando `messages`/`tools` estão vazios).
  - **`AiPurpose::ALL`** alimenta o `match` exaustivo de `AiPromptCatalog::build` e um teste com contagem 3: as finalidades do copiloto entram numa lista própria (`AiPurpose::COPILOT`), e `assertValid` aceita as duas.
  - **CHECKs de `ai_interaction`** rejeitam a finalidade e o status do copiloto até a 0015; testes de integração do copiloto só rodam depois do gate.
  - **Unidade do paciente** não existe no schema (regra das Premissas); id de outra unidade ou outro tenant devolve "não encontrado".
  - **Tempo de 5 s por ferramenta** não é preemptivo no PHP: queries novas levam o hint `/*+ MAX_EXECUTION_TIME(5000) */` e a `ToolPolicy` descarta resultado acima de 5000 ms (`deny_reason=timeout`).
  - **Arquivos compartilhados**, serializados em ondas: `translations.json` (T-07 → T-22), `AiSettingsForm.php` (T-15 → T-22), `AiCopilotService.php` (T-13 → T-23), `menu.xml` e `AiNavigationIntegrationTest.php` (T-17 → T-23), `ia-assistente.md` (T-18 → T-24).

## Mapa de arquivos

| Arquivo | Responsabilidade | Ação | Tasks que tocam |
|---|---|---|---|
| `src/app/database/migrations/20261015_0015_phase8b_copilot.sql` | Tabelas do copiloto e CHECKs ampliados de `ai_interaction` | criar | T-01 |
| `src/app/database/migrations/20261015_0015_phase8b_copilot.verify.sql` | Verificação só com SELECT | criar | T-01 |
| `scripts/test-db/provision.sh` | Inclui a 0015 no banco de teste | modificar | T-01 |
| `src/app/Core/Domain/AiCompletionRequest.php` | Mensagens e `tools` | modificar | T-02 |
| `src/app/Core/Domain/AiCompletionResult.php` | `toolUses` e `content` | modificar | T-02 |
| `src/app/Core/Domain/AiUsage.php` | Soma de uso entre rodadas | modificar | T-02 |
| `src/app/Core/Ai/AnthropicMessagesGateway.php` | Serializa `tools`/`tool_use`/`tool_result`, lê `stop_reason: tool_use` | modificar | T-02 |
| `src/app/Core/Ai/StubAiGateway.php` | Roteiro programável | modificar | T-02 |
| `src/tests/Unit/AnthropicMessagesGatewayTest.php` | Testes de tools no gateway | modificar | T-02 |
| `src/tests/Unit/StubAiGatewayScriptTest.php` | Testes do roteiro do stub | criar | T-02 |
| `src/app/Core/Domain/AiPurpose.php` | Finalidades do copiloto | modificar | T-03 |
| `src/app/Core/Domain/AiInteraction.php` | Status `answered` e `recordAnswer` | modificar | T-03 |
| `src/app/Core/Ai/AiPromptCatalog.php` | Prompts versionados do copiloto | modificar | T-03 |
| `src/tests/Unit/AiDomainTest.php` | Testes de finalidade e interação | modificar | T-03 |
| `src/tests/Unit/AiPromptCatalogTest.php` | Hashes dos prompts novos | modificar | T-03 |
| `src/app/Core/Ai/Tools/AiTool.php` | Interface da ferramenta | criar | T-04 |
| `src/app/Core/Ai/Tools/ToolContext.php` | Contexto imutável montado pelo servidor | criar | T-04 |
| `src/app/Core/Ai/Tools/ToolResult.php` | Resultado (`data`, `rowCount`, `truncated`) | criar | T-04 |
| `src/app/Core/Ai/Tools/ToolFamily.php` | Famílias, allowlist e flags | criar | T-04 |
| `src/app/Core/Ai/Tools/ToolFormat.php` | Primeiro nome, idade, data, trecho | criar | T-04 |
| `src/app/Core/Ai/Tools/JsonSchemaValidator.php` | Validador JSON Schema mínimo | criar | T-04 |
| `src/app/Core/Ai/Tools/ToolRegistry.php` | Ferramentas visíveis por família, flag e RBAC | criar | T-04 |
| `src/app/Core/Ai/Tools/ToolEntityNotFoundException.php` | Id fora do tenant/unidade | criar | T-04 |
| `src/app/Core/Ai/Tools/ToolInputException.php` | Entrada inválida detectada pela ferramenta | criar | T-04 |
| `src/app/Core/Domain/Contract/FeatureFlagRepositoryInterface.php` | Constante `AI_COPILOT_MANAGEMENT` | modificar | T-04 |
| `src/tests/Support/FakeAiTool.php` | Ferramenta fake para testes | criar | T-04 |
| `src/tests/Unit/JsonSchemaValidatorTest.php` | Testes do validador | criar | T-04 |
| `src/tests/Unit/ToolRegistryTest.php` | Visibilidade por flag e RBAC | criar | T-04 |
| `src/app/Core/Domain/AiConversation.php` | Conversa do autor | criar | T-05 |
| `src/app/Core/Domain/AiConversationMessage.php` | Mensagem da conversa | criar | T-05 |
| `src/app/Core/Domain/McpToolCall.php` | Linha de auditoria de ferramenta | criar | T-05 |
| `src/app/Core/Domain/Contract/AiConversationRepositoryInterface.php` | Contrato do repositório de conversas | criar | T-05 |
| `src/app/Core/Domain/Contract/McpToolCallRepositoryInterface.php` | Contrato do repositório de auditoria | criar | T-05 |
| `src/tests/Support/FakeAiConversationRepository.php` | Fake de conversas | criar | T-05 |
| `src/tests/Support/FakeMcpToolCallRepository.php` | Fake de auditoria | criar | T-05 |
| `src/tests/Unit/AiConversationDomainTest.php` | Testes do domínio e dos fakes | criar | T-05 |
| `src/app/Core/Domain/Contract/CopilotClinicalQueryInterface.php` | Contrato da leitura clínica por unidade | criar | T-06 |
| `src/app/Core/Persistence/CopilotClinicalQuery.php` | Leitura clínica com tenant + unidade | criar | T-06 |
| `src/tests/Support/FakeCopilotClinicalQuery.php` | Fake da leitura clínica | criar | T-06 |
| `src/tests/Integration/CopilotClinicalQueryIntegrationTest.php` | Integração da leitura clínica | criar | T-06 |
| `src/app/config/translations.json` ⚠ | Textos pt/en das telas (8B.1 e interruptor da 8B.2) | modificar | T-07, T-22 |
| `src/tests/Unit/CopilotTranslationsTest.php` | Chaves do copiloto traduzidas | criar | T-07 |
| `src/app/Core/Ai/Tools/ToolPolicy.php` | Portão fail-closed antes de cada `execute` | criar | T-08 |
| `src/app/Core/Ai/Tools/ToolOutcome.php` | Resultado da política para o laço | criar | T-08 |
| `src/app/Core/Ai/Tools/ToolRateLimiter.php` | 30 chamadas / 10 min por usuário | criar | T-08 |
| `src/tests/Unit/ToolPolicyTest.php` | Motivos de recusa, ordem, limites, PII | criar | T-08 |
| `src/app/Core/Persistence/AiConversationRepository.php` | PDO das conversas | criar | T-09 |
| `src/app/Core/Persistence/McpToolCallRepository.php` | PDO da auditoria | criar | T-09 |
| `src/tests/Integration/CopilotRepositoryIntegrationTest.php` | Integração dos dois repositórios | criar | T-09 |
| `src/app/Core/Ai/Tools/Clinical/BuscarPacienteTool.php` | `buscar_paciente` | criar | T-10 |
| `src/app/Core/Ai/Tools/Clinical/ObterPacienteTool.php` | `obter_paciente` | criar | T-10 |
| `src/app/Core/Ai/Tools/Clinical/ObterHistoricoAtendimentosTool.php` | `obter_historico_atendimentos` | criar | T-10 |
| `src/app/Core/Ai/Tools/Clinical/ObterProntuarioTool.php` | `obter_prontuario` | criar | T-10 |
| `src/tests/Unit/ClinicalToolsPatientTest.php` | Testes das 4 ferramentas | criar | T-10 |
| `src/app/Core/Ai/Tools/Clinical/ObterPrescricoesTool.php` | `obter_prescricoes` | criar | T-11 |
| `src/app/Core/Ai/Tools/Clinical/ObterExamesTool.php` | `obter_exames` | criar | T-11 |
| `src/app/Core/Ai/Tools/Clinical/ConsultarVacinacaoTool.php` | `consultar_vacinacao` | criar | T-11 |
| `src/tests/Unit/ClinicalToolsRecordsTest.php` | Testes das 3 ferramentas | criar | T-11 |
| `src/app/Core/Application/AiConversationRetention.php` | Apaga conversas com mais de 90 dias, em lotes | criar | T-12 |
| `src/bin/ai-conversation-retention.php` | Comando de cron | criar | T-12 |
| `src/tests/Integration/AiConversationRetentionIntegrationTest.php` | Retenção apaga conversas e mantém auditoria | criar | T-12 |
| `src/app/Core/Application/AiCopilotService.php` ⚠ | Laço de conversa | criar (T-13), modificar (T-23) | T-13, T-23 |
| `src/app/Core/Application/CopilotAnswer.php` | Resposta do laço para a tela | criar | T-13 |
| `src/app/Core/Ai/Tools/Clinical/ClinicalToolset.php` | Instancia as 7 ferramentas clínicas | criar | T-13 |
| `src/tests/Unit/AiCopilotServiceTest.php` | Laço com stub roteirizado | criar | T-13 |
| `src/tests/Integration/AiCopilotServiceForContextIntegrationTest.php` | `forContext` e gravação real | criar | T-13 |
| `src/app/control/clinic/AiCopilotPanel.php` | Cortina do copiloto | criar | T-14 |
| `src/app/templates/adminbs5/js/cv-ai-copilot.js` | Estado "pensando…" e envio único | criar | T-14 |
| `src/app/templates/adminbs5/cv-components.css` | Estilos `.cv-ai-copilot*` | modificar | T-14 |
| `src/tests/Integration/AiCopilotPanelIntegrationTest.php` | Painel abre, envia, lista, apaga | criar | T-14 |
| `src/app/control/clinic/AiConversationList.php` | "Minhas conversas" | criar | T-15 |
| `src/app/control/clinic/AiToolCallList.php` | Auditoria de ferramentas do gestor | criar | T-15 |
| `src/app/Core/Application/AiToolAuditService.php` | Busca na auditoria com RBAC | criar | T-15 |
| `src/app/control/clinic/AiInteractionList.php` | Rótulo do status `answered` | modificar | T-15 |
| `src/app/control/clinic/AiSettingsForm.php` ⚠ | Rótulos das finalidades do copiloto (T-15); interruptor da gestão (T-22) | modificar | T-15, T-22 |
| `src/tests/Integration/AiCopilotListsIntegrationTest.php` | Telas de conversas e auditoria | criar | T-15 |
| `src/app/control/clinic/PatientForm.php` | Botão "Copiloto" | modificar | T-16 |
| `src/app/control/clinic/EncounterView.php` | Botão "Copiloto" | modificar | T-16 |
| `src/tests/Integration/CopilotHostButtonsIntegrationTest.php` | Botões nas telas hospedeiras | criar | T-16 |
| `src/menu.xml` ⚠ | Itens do submenu de IA | modificar | T-17, T-23 |
| `src/app/database/seeds/initial-application-programs.sql` | Programas `AiCopilotPanel`, `AiConversationList`, `AiToolCallList` | modificar | T-17 |
| `src/tests/Integration/AiNavigationIntegrationTest.php` ⚠ | Filhos do submenu de IA | modificar | T-17, T-23 |
| `src/tests/Unit/UserMessageTest.php` | Controllers novos na verificação de chaves | modificar | T-17 |
| `docs/runbooks/ia-assistente.md` ⚠ | Runbook do copiloto (8B.1, depois 8B.2) | modificar | T-18, T-24 |
| `docs/architecture/adr/0007-copiloto-ferramentas-mcp.md` | ADR do copiloto | criar | T-18 |
| `docs/runbooks/shared-hosting-mysql57.md` | Migration 0015 e cron da retenção no 5.7 | modificar | T-18 |
| `src/app/Core/Domain/Contract/CopilotAgendaQueryInterface.php` | Contrato da agenda | criar | T-19 |
| `src/app/Core/Persistence/CopilotAgendaQuery.php` | Agenda e intervalos ocupados por unidade | criar | T-19 |
| `src/app/Core/Application/FreeSlotFinder.php` | Cálculo de horários livres | criar | T-19 |
| `src/app/Core/Ai/Tools/Management/ConsultarAgendaTool.php` | `consultar_agenda` | criar | T-19 |
| `src/app/Core/Ai/Tools/Management/VerificarHorariosTool.php` | `verificar_horarios` | criar | T-19 |
| `src/tests/Support/FakeCopilotAgendaQuery.php` | Fake da agenda | criar | T-19 |
| `src/tests/Unit/AgendaToolsTest.php` | Testes das ferramentas de agenda | criar | T-19 |
| `src/tests/Integration/CopilotAgendaQueryIntegrationTest.php` | Integração da agenda | criar | T-19 |
| `src/app/Core/Domain/Contract/CopilotStockQueryInterface.php` | Contrato do estoque | criar | T-20 |
| `src/app/Core/Persistence/CopilotStockQuery.php` | Estoque por unidade | criar | T-20 |
| `src/app/Core/Ai/Tools/Management/ConsultarEstoqueTool.php` | `consultar_estoque` | criar | T-20 |
| `src/tests/Support/FakeCopilotStockQuery.php` | Fake do estoque | criar | T-20 |
| `src/tests/Unit/StockToolTest.php` | Testes da ferramenta de estoque | criar | T-20 |
| `src/tests/Integration/CopilotStockQueryIntegrationTest.php` | Integração do estoque | criar | T-20 |
| `src/app/Core/Domain/Contract/CopilotFinanceQueryInterface.php` | Contrato de débitos e indicadores | criar | T-21 |
| `src/app/Core/Persistence/CopilotFinanceQuery.php` | Débitos e indicadores por unidade | criar | T-21 |
| `src/app/Core/Ai/Tools/Management/ListarDebitosTool.php` | `listar_debitos` | criar | T-21 |
| `src/app/Core/Ai/Tools/Management/IndicadoresClinicaTool.php` | `indicadores_clinica` | criar | T-21 |
| `src/tests/Support/FakeCopilotFinanceQuery.php` | Fake financeiro | criar | T-21 |
| `src/tests/Unit/FinanceToolsTest.php` | Testes das ferramentas financeiras | criar | T-21 |
| `src/tests/Integration/CopilotFinanceQueryIntegrationTest.php` | Integração financeira | criar | T-21 |
| `src/app/Core/Application/AiSettingsService.php` | Liga/desliga a flag de gestão | modificar | T-22 |
| `src/tests/Unit/AiSettingsServiceTest.php` | Testes da flag de gestão | modificar | T-22 |
| `src/tests/Integration/AiSettingsScreensIntegrationTest.php` | Interruptor na tela | modificar | T-22 |
| `src/app/Core/Ai/Tools/Management/ManagementToolset.php` | Instancia as 5 ferramentas de gestão | criar | T-23 |
| `src/tests/Unit/ManagementToolsetTest.php` | Registro das ferramentas de gestão | criar | T-23 |

Arquivos com ⚠ são serializados em ondas diferentes (ver Estratégia de execução).

## Decisões de arquitetura

| Decisão | Alternativas consideradas | Motivo da escolha |
|---|---|---|
| Uma linha de `ai_interaction` por pergunta (uso e custo somados das rodadas, `subject_type = ai_conversation`, status novo `answered`, `suggestion_text` nulo) | Uma linha por rodada; reaproveitar `accepted` com `final_text` | A cota e o custo da 8A continuam valendo; nenhum texto do copiloto fica em `ai_interaction` além da retenção de 90 dias; `accepted` exigiria decisor e texto final |
| Finalidades do copiloto em `AiPurpose::COPILOT`, fora de `AiPurpose::ALL` | Acrescentar em `ALL` | `ALL` alimenta o `match` exaustivo de `AiPromptCatalog::build`, o rótulo das telas da 8A e um teste com contagem fixa |
| `AiCompletionRequest` ganha `messages` e `tools` opcionais; com os dois vazios o payload é o da 8A | Classe de requisição nova para chat | Um só gateway, fallback e precificação; o assistente da 8A não muda |
| Checagem de id (passo 4) por `AiTool::assertInScope` antes do rate limit e do `execute` | Checar dentro de `execute` | A spec fixa a ordem existe → schema → RBAC → ids → rate limit |
| Timeout de 5 s: hint `MAX_EXECUTION_TIME(5000)` nas queries novas + descarte pela política acima de 5000 ms | `pcntl_alarm` | Sem extensão nova e sem processo contínuo na hospedagem compartilhada |
| `mcp_tool_call.conversation_id` sem FK | FK com `ON DELETE CASCADE`/`RESTRICT` | A retenção apaga conversas e a auditoria fica |
| `requiredPermission` de prescrições e exames = `PrescriptionForm::__construct` e `ExamResultForm::__construct` | `EncounterView::onReload` | São as telas que mostram o histórico de prescrições e o resultado do exame; elas montam o dado no construtor e não têm `onReload`; o RBAC libera pela classe |
| Painel = controller `AiCopilotPanel` na cortina `adianti_right_panel`, aberto por link (não `TButton`) | Painel embutido em cada tela | O `TButton` da `EncounterView` posta o formulário do atendimento; um controller único serve as duas famílias |
| Flag `ai_copilot_management` como constante criada na Onda 1 (T-04), sem UI até a 8B.2 | Criar só na 8B.2 | `ToolRegistry` e `AiCopilotService` checam a flag desde a 8B.1 sem depender da 8B.2; linha ausente = desligado |

## Diagrama de dependências

```text
Onda 1: T-01  T-02  T-03  T-04  T-05  T-06  T-07
        [gate: 0015 aplicada no centralvet_test + verify]
Onda 2: T-04,T-05 → T-08 ; T-01,T-05 → T-09 ; T-04,T-06 → T-10 ; T-04,T-06 → T-11 ; T-01,T-05 → T-12
Onda 3: T-02,T-03,T-05,T-06,T-08,T-09,T-10,T-11 → T-13
Onda 4: T-07,T-13 → T-14 ; T-03,T-07,T-09,T-13 → T-15
Onda 5: T-07,T-14 → T-16 ; T-14,T-15 → T-17 ; T-01,T-12,T-13,T-14,T-15 → T-18   (aceite 8B.1)
Onda 6: T-04 → T-19 ; T-04 → T-20 ; T-04 → T-21 ; T-04,T-07,T-15 → T-22
Onda 7: T-07,T-13,T-17,T-19,T-20,T-21 → T-23 ; T-18,T-19,T-20,T-21,T-22 → T-24   (aceite 8B.2)
```

## Estratégia de execução
- Branch de trabalho: `feat/fase-8b-copiloto-mcp`
- Observação: branch pedida pelo desenvolvedor, já em checkout em `/var/www/html/centralvet` (depende da 8A ainda não mesclada em `main`).
- Branch base: `main`
- Commits da onda: cada implementador commita os próprios caminhos (`git commit -- <caminhos>`); com `worktree por agente` eles chegam pelos merges de `wave<N>/T-XX`. O fechador usa a skill `new-commit --auto` só se sobrou algo sem commit e sempre registra o estado das tasks num `chore(tasks): registra commits da onda N`. Esses commits são internos: a entrega da Fase 5 (`entrega.py`) os desfaz e deixa o código staged para o desenvolvedor revisar; commit final e push só a pedido dele.
- Isolamento em ondas com edições paralelas: caminho exclusivo (checkout compartilhado; arquivos com ⚠ serializados em ondas diferentes; uma SUITE por agente, falha de arquivo alheio é ruído)
- Gate entre a Onda 1 e a Onda 2 (bloqueio, conduzido pelo orquestrador): aplicar `20261015_0015_phase8b_copilot.sql` no `centralvet_test` (premissa do usuário: banco de testes) pela skill `sql-write-approval`, com o usuário de migration, cópia temporária com o checksum real e o `.verify.sql` em seguida (3 tabelas vazias; CHECKs de `ai_interaction` com `copilot_clinical` e `answered`). As Ondas 2+ rodam integração contra essas tabelas. `centralvet` (dev) e a DML de programas da T-17 ficam pendentes de aprovação SQL do usuário.
- 8B.1 termina na Onda 5 (aceite próprio abaixo); a 8B.2 abre na Onda 6 só com o aceite da 8B.1 verde.

## Ondas de execução

### Onda 1
- T-01, T-02, T-03, T-04, T-05, T-06, T-07 — migration, gateway com tools, finalidades/prompts, núcleo de ferramentas, domínio de conversa/auditoria, leitura clínica por unidade, traduções (arquivos disjuntos); depois, gate da 0015 no `centralvet_test`

### Onda 2
- T-08, T-09, T-10, T-11, T-12 — política, repositórios PDO, ferramentas clínicas, retenção

### Onda 3
- T-13 — `AiCopilotService` e conjunto clínico

### Onda 4
- T-14, T-15 — painel e listas (conversas, auditoria)

### Onda 5
- T-16, T-17, T-18 — botões nas telas hospedeiras, menu/seed/testes de navegação, documentação; validação do aceite da 8B.1

### Onda 6
- T-19, T-20, T-21, T-22 — ferramentas de gestão e interruptor da flag (8B.2)

### Onda 7
- T-23, T-24 — registro das ferramentas de gestão e entrada do painel; runbook da gestão; depois, validação cruzada e revisão final (Fase 5)

## Agentes

| Agente | subagent_type | model | Tasks |
|---|---|---|---|
| Sun Tzu — orquestrador | — | — | todas |
| Darwin | general-purpose | inherit | T-01 |
| Aang | general-purpose | inherit | T-02 |
| Platão | general-purpose | inherit | T-03 |
| Athena | general-purpose | inherit | T-04 |
| Spock | general-purpose | inherit | T-05 |
| Sherlock | general-purpose | inherit | T-06 |
| Maquiavel | general-purpose | inherit | T-07 |
| Jaspion | general-purpose | inherit | T-08 |
| Levi | general-purpose | inherit | T-09 |
| Yoda | general-purpose | inherit | T-10 |
| Naruto | general-purpose | inherit | T-11 |
| Thanos | general-purpose | inherit | T-12 |
| Batman | general-purpose | inherit | T-13 |
| Tesla | general-purpose | inherit | T-14 |
| Hegel | general-purpose | inherit | T-15 |
| Kratos | general-purpose | inherit | T-16 |
| Saitama | general-purpose | inherit | T-17 |
| Gandalf | geduc:documentador | sonnet | T-18, T-24 |
| Arquimedes | general-purpose | inherit | T-19 |
| Goku | general-purpose | inherit | T-20 |
| Ada | general-purpose | inherit | T-21 |
| Curie | general-purpose | inherit | T-22 |
| Lamport | general-purpose | inherit | T-23 |

## Review Focus
- Pergunta "o tutor ligou do 85 98765-4321, CPF 123.456.789-09; as vacinas do Thor estão em dia?" → `ai_conversation.title` e `ai_conversation_message.content` gravam `[TELEFONE]`/`[CPF]`, nunca os dígitos; o texto enviado ao gateway também → T-13
- Profissional clica "Enviar" duas vezes seguidas (ou Enter + clique) → uma só pergunta, uma só linha em `ai_interaction`; botão desabilitado e live region "Pensando…" até a resposta → T-14
- Grupo sem o programa `VaccinationCardView` pergunta "quais vacinas estão atrasadas?" e o modelo pede `consultar_vacinacao` mesmo assim → a ferramenta não está em `tools`, a chamada vira `denied`/`not_offered` e a resposta não traz dado de vacina → T-08
- Usuário troca a unidade ativa e abre em "Minhas conversas" uma conversa criada na unidade anterior → "Conversa não encontrada", nenhuma mensagem exibida → T-15
- Modelo pede `obter_prontuario` com `encounter_id` de um atendimento de outra unidade do mesmo tenant → `tool_result` com `is_error` "não encontrado", `mcp_tool_call` `denied`/`not_found`, nenhum campo do atendimento na resposta → T-10

## Critérios gerais de aceite
- SUITE inteira com `Failed: 0` ao fim de cada onda.
- `php -l` sem erro novo em relação a `baseline/php-lint.txt` em todo PHP tocado.
- Nenhum SQL executado fora do gate da Onda 1 no `centralvet_test`; nenhum push (`git -C /var/www/html/centralvet status -sb` sem `ahead` sobre remoto, se houver).
- Sem dependência nova: `git -C /var/www/html/centralvet diff bc35d6d -- src/composer.json src/app/config/framework_hashes.php src/app/templates/adminbs5/custom.css` sem nenhuma linha.
- **Aceite da 8B.1 (fim da Onda 5):** com o stub roteirizado, `AiCopilotServiceTest` cobre 0, 1 e 4 rodadas, limite de rodadas, orçamento de 45 s, ferramenta negada no meio do laço e erro do provedor; `AiCopilotServiceForContextIntegrationTest` grava `ai_conversation`, 2 linhas de `ai_conversation_message`, linhas de `mcp_tool_call` com o mesmo `correlation_id` da linha `copilot_clinical` de `ai_interaction`; paciente de outra unidade devolve "não encontrado"; `AiConversationRetentionIntegrationTest` apaga a conversa de 91 dias e mantém `mcp_tool_call`/`ai_interaction`; `AiCopilotPanelIntegrationTest` abre, envia, lista e apaga com textos em pt; `AiNavigationIntegrationTest` com os 4 itens de IA.
- **Aceite da 8B.2 (fim da Onda 7):** as 5 ferramentas de gestão aparecem em `tools` só com `ai_assistant` e `ai_copilot_management` ligadas e o RBAC das telas `AgendaView`, `ProductList`, `PendingReceivableList` e `FinancialOverview`; cada uma filtra pela unidade ativa e não devolve CPF, telefone, e-mail nem endereço; o interruptor de `AiSettingsForm` grava a flag; o painel de gestão abre pelo item "Copiloto de gestão".
- Smoke manual com `ANTHROPIC_API_KEY` real (roteiro no runbook, uma pergunta por painel) fica para o desenvolvedor, fora da execução automatizada.
