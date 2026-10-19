# Plano: Fase 8A — AI Gateway e IA contextual (núcleo)

## Objetivo
Entregar o núcleo da Fase 8A do PRD (§ 9): um AI Gateway próprio e independente do provedor, com adaptador para a Anthropic Messages API (Claude), e três casos de uso com revisão humana obrigatória antes de persistir: resumo do paciente, organização da anamnese (texto livre ou ditado pelo navegador) e resumo de exame. A IA fica desligada por padrão em cada tenant. O admin liga, aceita o termo de tratamento de dados e define uma cota mensal de tokens. Cada chamada é registrada em `ai_interaction` com finalidade, versão de prompt, modelo, usuário, tokens, custo e decisão humana, sob RBAC, tenant e unidade.

## Premissas
- Repositório único `/var/www/html/centralvet`. Branch de trabalho `feat/fase-8a-ai-gateway`, criada pelo orquestrador a partir de `origin/main` @ `5e4b238` e já em checkout. Checkout compartilhado com caminho exclusivo, RED antes da implementação e trailers `Task: T-xx` / `Task: T-xx (RED)`. A rodada 4 (StorageFactory, CLAUDE.md § Branches) está na `dev` e não na `main`; a 8A não depende dela.
- Comandos rodados a partir de `/var/www/html/centralvet` (mesmas convenções da 7A/7B):
  - **LINT**: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php -l <arquivo relativo a src/>`.
  - **SUITE**: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php`. Não filtra testes; use `| /usr/bin/grep -E '<Classe>|Failed:'`. Roda no `centralvet_test`. SUITEs simultâneas podem dar falso FAIL em testes de Redis ou deadlock.
  - **PYTEST57**: `python3 scripts/test-prepare-mysql57.py`.
  - **PREP57**: `python3 scripts/prepare-mysql57.py src/app/database/migrations <scratch>/out57` (a 0014 vira `18-`).
  - **GATE de tela**: o orquestrador roda `docker compose build app && AI_PROVIDER=stub docker compose up -d app worker && docker compose restart nginx` e faz login de admin no Playwright, com autorização do usuário. O validador navega só em `http://127.0.0.1:8081`.
- **Provedor e modelo.** Claude pela Anthropic Messages API (`POST https://api.anthropic.com/v1/messages`, headers `x-api-key`, `anthropic-version: 2023-06-01`, `content-type: application/json`). O modelo vem de `AI_MODEL`, com padrão `claude-sonnet-5-5` (decisão do usuário; US$ 2/10 por MTok, leitura de cache US$ 0,20) e `output_config.effort` = `low` (`AI_EFFORT`): no Sonnet 5.5 o padrão é `high` e a skill `claude-api` indica `low` para chamada única de texto (resumo/organização), com `medium` como degrau se a qualidade pedir. Os outros modelos do catálogo de preços são `claude-opus-5-5` (US$ 4/20), `claude-haiku-4-5` (US$ 1/5), `claude-sonnet-5` (US$ 2/10; destino de fallback do Sonnet 5.5), `claude-opus-5` e `claude-opus-4-8` (US$ 5/25). O Sonnet 5.5 recusa `thinking: {type: "disabled"}` e sampling fora do padrão (400): o gateway omite `thinking`, `temperature`, `top_p` e `top_k` e lê só blocos `text`. Com `AI_FALLBACKS=default` (padrão) o gateway envia `fallbacks: "default"` com `anthropic-beta: server-side-fallback-2026-07-01` (no Sonnet 5.5 só essa forma, só na Claude API; recusas `cyber`/`frontier_llm` vão para `claude-sonnet-5`, as demais voltam como `refusal`), e o custo é calculado pelo `model` devolvido na resposta.
- **Chamada síncrona com timeout curto** (`AI_TIMEOUT_SECONDS` = 30, conexão 5 s, sem retentativa). Motivos em Decisões de arquitetura. A fila (`src/app/Core/Queue`, `bin/worker.php`) não é usada.
- **Cliente HTTP próprio com `curl`** (padrão de `src/app/Core/Storage/S3/S3Client.php`), atrás de `AiGatewayInterface`. Não se adiciona o SDK PHP oficial (`anthropic-ai/sdk`) porque `src/composer.json` está em `src/app/config/framework_hashes.php`. Confirmado pelo usuário (curl próprio, `composer.json` intocado).
- **Testes nunca chamam a rede.** O adaptador Anthropic recebe o transporte HTTP como `Closure` injetável. Os serviços usam `FakeAiGateway`. Os gates de tela usam `AI_PROVIDER=stub` (gateway determinístico, sem rede, recusado com `APP_ENV=production`). E2E com a API real só na Onda 6, com a `ANTHROPIC_API_KEY` que o usuário põe no `.env` local (o orquestrador pede a chave antes da Onda 6), poucas chamadas e a chave nunca logada.
- **Migration `0014`** (`20261007_0014_phase8a_ai_gateway.sql`): três tabelas (`feature_flag`, `ai_tenant_setting`, `ai_interaction`), compatíveis com o preparador 5.7 (prefixo `18-`), com placeholder de SHA-256 e `.verify.sql`. Preparada pela T-01 e aplicada só pelo orquestrador, com backup, `gzip -t`, SHA-256 e aprovação SQL explícita do usuário (skill `sql-write-approval`), no **bloqueio entre a Onda 1 e a Onda 2**: migration em `centralvet` e `centralvet_test`, DML de programas só em `centralvet`. Rollback segue `docs/runbooks/migration-rollback.md` (backup ou migration reversa numerada); o `.verify.sql` é preparado.
- **RBAC.** Dois programas novos (`AiSettingsForm`, `AiInteractionList`) só para o grupo 1 (`Template - Admin`), por DML preparada como a T-04 da 7B. As ações de IA nas telas clínicas autorizam pelo programa da própria tela (`EncounterView::onAiPatientSummary`, `EncounterView::onAiOrganizeAnamnesis`, `EncounterView::onAiDecide`, `ExamResultForm::onAiExamSummary`, `ExamResultForm::onAiDecide`), com `requiresUnitScope: true` e `resourceUnitId` = unidade do atendimento ou do exame: quem não vê a tela não usa a IA dela. `AdiantiProgramPermissionProvider::hasPermission` só entende `Classe::metodo`; ação com ponto (`ai.x`) seria sempre negada.
- **Ditado**: Web Speech API do navegador (`SpeechRecognition`/`webkitSpeechRecognition`), sem áudio no servidor. Sem suporte, o botão de ditado fica desabilitado com aviso e o texto livre continua digitável.
- **Telas** com `CvPage::header`, catches `AuthorizationDenied` → `MissingTenantContext` → `Exception` (`CvFormat::userError`), `CvFormat::e`/`htmlspecialchars` em todo texto vindo do banco ou da IA, texto livre só por POST, rótulos à esquerda (`CLAUDE.md`, `.docs/design-system.md` § Alinhamento de rótulos) e alvos `.cv-touch-target` ≥ 44 px.
- **i18n com escritor único** (T-17): até a Onda 5 as telas usam `_t('<en>')` e as exceptions têm texto em inglês; cada texto vai para o board como `- [T-xx] i18n: <en> → <pt>`.
- Registros dos gates levam o prefixo `F8A teste` (paciente `F8A teste Paciente`, tutor `F8A teste Tutor` com e-mail `f8a.teste@example.invalid`). O SQL de limpeza (T-19) os cobre, com as `ai_interaction` deles, e devolve `feature_flag`/`ai_tenant_setting` do tenant 1 ao estado anotado antes do gate. Só o orquestrador executa, com aprovação.
- Nada em `src/lib/adianti` nem nos arquivos de `src/app/config/framework_hashes.php` (inclui `src/composer.json` e `src/app/templates/adminbs5/custom.css`).

## Escopo

### Incluso
- Migration `0014` (`feature_flag`, `ai_tenant_setting`, `ai_interaction`), `.verify.sql` e `provision.sh` → T-01.
- Domain e contratos da IA (finalidades, interação, configuração do tenant, termo, uso, pedido/resultado de completude, preços por modelo, exceções, `AiGatewayInterface`, repositórios, consulta clínica, `AiAssistantInterface`) → T-02.
- `AiConfig` por env, variáveis em `.env.example`/`docker-compose.yml` e mascaramento de `x-api-key`/`anthropic_api_key` em `JsonLogger` e `AuditRedactor` → T-03.
- Programas RBAC `AiSettingsForm` e `AiInteractionList` (seed + DML, verify e rollback preparados) → T-04.
- Fakes → T-05. Repositórios PDO (interação, configuração, flag, uso mensal) → T-06. Gateways (Anthropic via curl, stub, não configurado) e fábrica → T-07. Minimização de PII e catálogo de prompts versionados → T-08. Consulta do contexto clínico autorizado → T-09. JS de ditado e CSS do painel de IA → T-10.
- `AiAssistService` (gerar os 3 casos de uso, decidir, disponibilidade, auditoria, cota) → T-11. `AiSettingsService` (ligar/desligar, termo, cota, uso e custo do mês) → T-12.
- Telas: configuração de IA e lista de interações → T-13. Atendimento (resumo do paciente, organizar anamnese com ditado, aceitar/editar/descartar; remove `NullAiClinicalAssistant`) → T-14. Resultado de exame (resumo para revisão, original intacto) → T-15. Menu → T-16.
- i18n pt/en → T-17. Runbook de IA, ADR 0004, passo da 0014 e env na hospedagem 5.7 → T-18.
- Validação final ponta a ponta e SQL de limpeza `F8A teste` → T-19.

### Excluído
- Busca natural, próximos passos/possibilidades e rascunho ao tutor (8A.2); servidor MCP (8B); MCP Actions/agents (8C).
- Áudio no servidor, transcrição pelo provedor ou gravação de áudio.
- Chamada assíncrona pela fila/worker, streaming de resposta e retentativa automática.
- Persistência sem ação humana, decisão clínica automática ou alteração do resultado original do exame.
- Prompt caching (o prompt de sistema fica abaixo do mínimo cacheável do modelo) e Batch API.
- SDK PHP oficial via composer (ver Decisões); cotas por plano SaaS, cobrança e limite em dinheiro (a cota é em tokens).
- Expurgo/retenção automática de `ai_interaction` e tela de edição do termo.
- Executar migration, DML ou SQL de limpeza (só o orquestrador, com aprovação); E2E com a API real antes da Onda 6 ou além das 3 chamadas previstas (uma por caso de uso).

## Contexto técnico
- Camadas envolvidas: database, backend, infra, frontend, shared (testes), docs, qa.
- Projeto/base analisada: `/var/www/html/centralvet` (repositório único; `git -C <DIR> rev-parse --show-toplevel` = `/var/www/html/centralvet`), branch `feat/fase-8a-ai-gateway` @ `5e4b238`, Adianti 8.6, PHP 8.4 (`php:8.4.13-fpm-bookworm`), MySQL 8.0 local / 5.7 na hospedagem, Redis (fila, não usado pela 8A).
- Integrações: Anthropic Messages API (HTTPS de saída para `api.anthropic.com`); `TenantContext`, `RbacAuthorizationService` + `AdiantiProgramPermissionProvider` + `PdoAuditLogWriter`/`AuditEvent`/`AuditRedactor`, `EncounterService::acceptAiSummary`/`autosave`, `ClinicalSummaryReader`, `ExamService`/`ExamResult`, `JsonLogger`.

## Baseline
- php-lint: `php -l` (via LINT com `sh -c`, `find app/Core app/control app/lib/widget bin tests -name "*.php"`) em raiz → baseline/php-lint.txt (0 linhas; 734 arquivos com `No syntax errors detected`). Critério: nenhum erro novo em relação a `baseline/php-lint.txt`, ou seja, `No syntax errors detected` em cada PHP tocado.
- test-prepare-mysql57: `python3 scripts/test-prepare-mysql57.py` em raiz → baseline/test-prepare-mysql57.txt (5 linhas: `Ran 8 tests`, `OK`).

## Exploração read-only
- Caminhos relevantes:
  - **IA hoje.** `src/app/Core/Assistant/Contract/AiClinicalAssistantInterface.php` (`summarizePatientHistory(int $patientId): ?string`, `suggestNextSteps(int $encounterId): array`) e `src/app/Core/Assistant/NullAiClinicalAssistant.php` (final, no-op). Instanciados com `new` em `src/app/control/clinic/EncounterView.php:312` (`loadEncounterData`) e `:1623` (`onAcceptAiSummary`, que recalcula o resumo no servidor e chama `EncounterService::acceptAiSummary`). `aiPanel()`/`suggestionsPanel()` (`:1378-1450`) existem e não são renderizados. Testes em `src/tests/Unit/EncounterServiceTest.php:8,140-150`.
  - **Atendimento.** `Domain/Encounter` (`anamnesis_text`, `ai_summary_text`, `ai_summary_accepted_at`; `acceptAiSummary(string, DateTimeImmutable)`), `Application/EncounterService` (`autosave(int $id, array $draft)`, `acceptAiSummary(int $id, string $summaryText)`), autosave de 20 s do formulário `form_EncounterView_<id>` (`DRAFT_FIELDS`).
  - **Histórico.** `Persistence/ClinicalSummaryReader` (`findPatientWithTutor`, `findLatestEncounter`, `listPrescriptionsByPatient`, `listExamRequestsByEncounter`) e `Application/ClinicalSummaryService`.
  - **Exames.** `Domain/ExamRequest`, `Domain/ExamResult` (`structuredResultText()`, `storedObjectKey()`, `pendingReview()`), `control/clinic/ExamResultForm.php` (`onSave`, `makeExamService` :278, `resolveTenantContext` :318).
  - **Autorização e auditoria.** `Authorization/AuthorizationRequest` (`ACTION_PATTERN`; construtor `(TenantContext, string $action, bool $requiresUnitScope, ?int $resourceTenantId, ?int $resourceUnitId, string $entityType, string|int|null $entityId, array $metadata, ?string $ip, ?string $ua)`), `Authorization/Contract/AuthorizationPolicyInterface`, `Audit/Contract/AuditLogWriterInterface`, `Audit/AuditEvent`, `Audit/AuditRedactor::SENSITIVE_KEYS` (:18), `Observability/Logging/JsonLogger::SENSITIVE_KEYS` (:22, sem `x-api-key` nem `anthropic_api_key`).
  - **HTTP.** `Storage/S3/S3Client.php:118` (`curl_init`), único cliente HTTP; sem Guzzle.
  - **Config.** `fromEnvironment()` com `getenv()` (`SmtpConfig`, `EmailProviderFactory`, `DocumentStorageFactory`).
  - **Banco.** `src/app/database/migrations/20261006_0013_phase7b_documents.sql` (modelo, PREPARED ONLY, placeholder de 64 zeros) e `.verify.sql`; `scripts/prepare-mysql57.py`; `scripts/test-db/provision.sh` (lista fixa 0001..0013, comentário na linha 10); seed `src/app/database/seeds/initial-application-programs.sql`; modelos `.claude/tasks/mar-20261006-1347-fase-7b-documentos/sql/T-04-programs*.sql` e `T-21-cleanup.sql`.
  - **Telas.** `src/app/lib/widget/CvPage.php:22` (`CvPage::header`), `src/menu.xml:124` (`_t{Settings}`), `src/app/templates/adminbs5/cv-components.css:741` (`.cv-touch-target`), `src/app/templates/adminbs5/js/cv-shell.js` (JS do projeto fora do hash), `src/app/config/translations.json`, `src/app/Core/Presentation/UserMessage.php`, `src/tests/Unit/UserMessageTest.php`, `src/tests/Integration/DocumentNavigationIntegrationTest.php` (modelo de navegação).
- Padrões identificados:
  - Services recebem repositórios por interface, `AuthorizationPolicyInterface`, `TenantContext` e `?Closure $clock = null` por último, e autorizam com `decide(new AuthorizationRequest(...))->assertAllowed()` (a decisão já audita). Services não abrem transação: o controller abre (`TTransaction::open('permission')`).
  - Repositórios: `__construct(TenantContext $context, PDO $connection)` e `TenantQuery::forTenant` em todo SQL; transições por UPDATE condicional + `rowCount`.
  - Sem container de DI: controllers montam serviços em `make*()` estáticos; a 8A expõe `AiAssistService::forContext` e `AiSettingsService::forContext` para não duplicar a montagem.
  - Migration: CHECK nomeado (≤ 61 caracteres) sem `BETWEEN`/`LIKE`/`CASE`/funções/`NOT IN`; `timestamp(6)`; FKs `ON UPDATE RESTRICT ON DELETE RESTRICT`; `utf8mb4_0900_ai_ci`.
- Scripts úteis: LINT, SUITE, PYTEST57 e PREP57 (Premissas); `./scripts/backup.sh` (orquestrador).
- Riscos identificados:
  - **Chave da API vazando.** `JsonLogger`/`AuditRedactor` não mascaram `x-api-key`. Mitigação: T-03 acrescenta as chaves; `AiConfig::__debugInfo` mascara; o gateway nunca loga headers, corpo de pedido nem de resposta; grep no log do gate (critério geral).
  - **PII ao provedor.** Texto livre e histórico podem conter nome, e-mail, telefone e CPF. Mitigação: a consulta clínica não seleciona contato do tutor; `PiiMinimizer` troca nomes conhecidos e padrões por marcadores; `ai_interaction` guarda só o SHA-256 da entrada (Review Focus).
  - **PHP-FPM preso** em chamada lenta. Mitigação: timeout total de 30 s e de conexão de 5 s, uma chamada por requisição, sem retentativa; falha vira aviso traduzido e a tela segue (Review Focus).
  - **Autosave sobrescrevendo a anamnese aplicada.** Mitigação: `onAiDecide` usa o `anamnesis_text` postado pelo próprio formulário como base, anexa o texto aceito e recarrega a tela (Review Focus).
  - **Decisão cruzada** (id de interação de outro tenant/unidade ou já decidida). Mitigação: busca por tenant, autorização pela unidade da interação, UPDATE condicional `status = 'pending_review'` e mensagem única "não encontrada" (Review Focus).
  - **Arquivos compartilhados entre módulos**, cada um com um escritor só: `.env.example`, `docker-compose.yml`, `JsonLogger.php`, `AuditRedactor.php` (T-03); `provision.sh` (T-01); seed de programas (T-04); `cv-components.css` (T-10); `EncounterView.php`, `EncounterServiceTest.php` e os dois arquivos antigos de `Core/Assistant` (T-14); `ExamResultForm.php` (T-15); `menu.xml` (T-16); `translations.json`, `UserMessage.php`, `UserMessageTest.php` (T-17); runbooks e ADRs (T-18).

## Mapa de arquivos

| Arquivo | Responsabilidade | Ação | Tasks que tocam |
|---|---|---|---|
| `src/app/database/migrations/20261007_0014_phase8a_ai_gateway.sql` | DDL de `feature_flag`, `ai_tenant_setting` e `ai_interaction` | criar | T-01 |
| `src/app/database/migrations/20261007_0014_phase8a_ai_gateway.verify.sql` | Verificação só com SELECT | criar | T-01 |
| `scripts/test-db/provision.sh` | Inclui a 0014 no banco de teste | modificar | T-01 |
| `src/app/Core/Domain/AiPurpose.php` | Finalidades, tipo de sujeito e ação RBAC por finalidade | criar | T-02 |
| `src/app/Core/Domain/AiInteraction.php` | Interação com IA (estado, uso, decisão humana) | criar | T-02 |
| `src/app/Core/Domain/AiTenantSetting.php` | Termo aceito e cota mensal do tenant | criar | T-02 |
| `src/app/Core/Domain/AiTerms.php` | Versão e texto pt/en do termo de tratamento de dados | criar | T-02 |
| `src/app/Core/Domain/AiUsage.php` | Tokens de entrada, saída e cache | criar | T-02 |
| `src/app/Core/Domain/AiCompletionRequest.php` | Pedido neutro ao gateway | criar | T-02 |
| `src/app/Core/Domain/AiCompletionResult.php` | Resultado neutro do gateway | criar | T-02 |
| `src/app/Core/Domain/AiSuggestion.php` | Sugestão devolvida à tela | criar | T-02 |
| `src/app/Core/Domain/AiModelPricing.php` | Preço por modelo e custo em micro-USD | criar | T-02 |
| `src/app/Core/Domain/Exception/AiUnavailableException.php` | IA indisponível com código | criar | T-02 |
| `src/app/Core/Domain/Exception/AiProviderException.php` | Falha do provedor com código, status e uso | criar | T-02 |
| `src/app/Core/Domain/Exception/AiInteractionNotFoundException.php` | Interação inexistente/fora do escopo/já decidida | criar | T-02 |
| `src/app/Core/Domain/Exception/AiSubjectNotFoundException.php` | Atendimento ou exame fora do tenant | criar | T-02 |
| `src/app/Core/Domain/Contract/AiGatewayInterface.php` | Contrato do gateway | criar | T-02 |
| `src/app/Core/Domain/Contract/AiInteractionRepositoryInterface.php` | Contrato da interação e do uso mensal | criar | T-02 |
| `src/app/Core/Domain/Contract/AiTenantSettingRepositoryInterface.php` | Contrato da configuração do tenant | criar | T-02 |
| `src/app/Core/Domain/Contract/FeatureFlagRepositoryInterface.php` | Contrato de feature flag por tenant | criar | T-02 |
| `src/app/Core/Domain/Contract/AiClinicalContextQueryInterface.php` | Contrato da consulta do contexto clínico | criar | T-02 |
| `src/app/Core/Assistant/Contract/AiAssistantInterface.php` | Porta da IA contextual usada pelas telas | criar | T-02 |
| `src/tests/Unit/AiDomainTest.php` | Testes do Domain de IA | criar | T-02 |
| `src/app/Core/Ai/AiConfig.php` | Configuração da IA por env, chave mascarada | criar | T-03 |
| `.env.example` | Variáveis `AI_*` e `ANTHROPIC_*` documentadas | modificar | T-03 |
| `docker-compose.yml` | Variáveis `AI_*` e `ANTHROPIC_*` no `x-php-service` | modificar | T-03 |
| `src/app/Core/Observability/Logging/JsonLogger.php` | Mascarar `x-api-key` e `anthropic_api_key` | modificar | T-03 |
| `src/app/Core/Audit/AuditRedactor.php` | Mascarar `x-api-key` e `anthropic_api_key` | modificar | T-03 |
| `src/tests/Unit/AiConfigTest.php` | Testes de config e mascaramento | criar | T-03 |
| `src/app/database/seeds/initial-application-programs.sql` | 2 programas e concessões em instalação nova | modificar | T-04 |
| `.claude/tasks/mar-20261007-1508-fase-8a-ai-gateway/sql/T-04-programs.sql` | DML dos 2 programas e 2 concessões | criar | T-04 |
| `.claude/tasks/mar-20261007-1508-fase-8a-ai-gateway/sql/T-04-programs.verify.sql` | Verificação só com SELECT | criar | T-04 |
| `.claude/tasks/mar-20261007-1508-fase-8a-ai-gateway/sql/T-04-programs.rollback.sql` | Reversão preparada por nome de controller | criar | T-04 |
| `src/tests/Support/FakeAiGateway.php` | Dublê do gateway (sucesso, falha, chamadas) | criar | T-05 |
| `src/tests/Support/FakeAiInteractionRepository.php` | Dublê da interação e do uso mensal | criar | T-05 |
| `src/tests/Support/FakeAiTenantSettingRepository.php` | Dublê da configuração do tenant | criar | T-05 |
| `src/tests/Support/FakeFeatureFlagRepository.php` | Dublê de feature flag | criar | T-05 |
| `src/tests/Support/FakeAiClinicalContextQuery.php` | Dublê do contexto clínico | criar | T-05 |
| `src/tests/Unit/AiFakesTest.php` | Contrato dos dublês | criar | T-05 |
| `src/app/Core/Persistence/AiInteractionRepository.php` | PDO da interação (insert, decisão condicional, uso mensal) | criar | T-06 |
| `src/app/Core/Persistence/AiTenantSettingRepository.php` | PDO da configuração (upsert) | criar | T-06 |
| `src/app/Core/Persistence/FeatureFlagRepository.php` | PDO de feature flag (upsert) | criar | T-06 |
| `src/tests/Integration/AiRepositoryIntegrationTest.php` | Integração no `centralvet_test` | criar | T-06 |
| `src/app/Core/Ai/AnthropicMessagesGateway.php` | Adaptador curl da Messages API | criar | T-07 |
| `src/app/Core/Ai/StubAiGateway.php` | Gateway determinístico sem rede (gates) | criar | T-07 |
| `src/app/Core/Ai/UnconfiguredAiGateway.php` | Gateway sem chave (`ai_not_configured`) | criar | T-07 |
| `src/app/Core/Ai/AiGatewayFactory.php` | Escolha do gateway por `AiConfig` | criar | T-07 |
| `src/tests/Unit/AnthropicMessagesGatewayTest.php` | Pedido, headers, mapeamento de erros e custo | criar | T-07 |
| `src/app/Core/Ai/PiiMinimizer.php` | Troca nomes e padrões de PII por marcadores | criar | T-08 |
| `src/app/Core/Ai/AiPromptCatalog.php` | Prompts versionados por finalidade e idioma | criar | T-08 |
| `src/tests/Unit/AiPromptCatalogTest.php` | Testes de minimização e prompts | criar | T-08 |
| `src/app/Core/Persistence/AiClinicalContextQuery.php` | Contexto clínico autorizado por tenant | criar | T-09 |
| `src/tests/Integration/AiClinicalContextQueryIntegrationTest.php` | Integração da consulta (tenant, limites, sem contato) | criar | T-09 |
| `src/app/templates/adminbs5/js/cv-ai-dictation.js` | Ditado pela Web Speech API e degradação | criar | T-10 |
| `src/app/templates/adminbs5/cv-components.css` | Painel e selo de IA, alvos de 44 px | modificar | T-10 |
| `src/tests/Unit/AiDictationAssetTest.php` | Contrato do JS (API pública, sem envio de áudio) | criar | T-10 |
| `src/app/Core/Application/AiAssistService.php` | Gerar, decidir, disponibilidade, cota e auditoria | criar | T-11 |
| `src/tests/Unit/AiAssistServiceTest.php` | Testes do serviço de IA | criar | T-11 |
| `src/app/Core/Application/AiSettingsService.php` | Ligar/desligar, termo, cota, uso e custo | criar | T-12 |
| `src/tests/Unit/AiSettingsServiceTest.php` | Testes da configuração | criar | T-12 |
| `src/app/control/clinic/AiSettingsForm.php` | Tela de configuração de IA do tenant | criar | T-13 |
| `src/app/control/clinic/AiInteractionList.php` | Lista de interações (uso e custo, sem texto) | criar | T-13 |
| `src/tests/Integration/AiSettingsScreensIntegrationTest.php` | Campos, ações, permissão e escape | criar | T-13 |
| `src/app/control/clinic/EncounterView.php` | Resumo do paciente e anamnese com IA | modificar | T-14 |
| `src/app/Core/Assistant/Contract/AiClinicalAssistantInterface.php` | Porta antiga da Fase 2 | remover | T-14 |
| `src/app/Core/Assistant/NullAiClinicalAssistant.php` | No-op antigo da Fase 2 | remover | T-14 |
| `src/tests/Unit/EncounterServiceTest.php` | Remove os 2 testes do no-op | modificar | T-14 |
| `src/tests/Integration/EncounterAiIntegrationTest.php` | Painéis, POST, aplicação na anamnese, degradação | criar | T-14 |
| `src/app/control/clinic/ExamResultForm.php` | Resumo de exame para revisão | modificar | T-15 |
| `src/tests/Integration/ExamResultAiIntegrationTest.php` | Resumo, decisão, original intacto | criar | T-15 |
| `src/menu.xml` | Itens de IA em Settings | modificar | T-16 |
| `src/tests/Integration/AiNavigationIntegrationTest.php` | Navegação registrada | criar | T-16 |
| `src/app/config/translations.json` | Chaves pt/en da 8A | modificar | T-17 |
| `src/app/Core/Presentation/UserMessage.php` | Mensagens de domínio da 8A | modificar | T-17 |
| `src/tests/Unit/UserMessageTest.php` | Casos e totais novos | modificar | T-17 |
| `docs/runbooks/ia-assistente.md` | Fluxos, env, custo, cota, operação e incidentes | criar | T-18 |
| `docs/runbooks/README.md` | Índice dos runbooks | modificar | T-18 |
| `docs/runbooks/shared-hosting-mysql57.md` | Passo da 0014 (`18-`), env `AI_*`, saída HTTPS e `ext-curl` | modificar | T-18 |
| `docs/architecture/adr/0004-ai-gateway.md` | ADR do gateway desacoplado | criar | T-18 |
| `.claude/tasks/mar-20261007-1508-fase-8a-ai-gateway/sql/T-19-cleanup.sql` | Limpeza dos registros `F8A teste` (preparada) | criar | T-19 |

Nenhum arquivo é tocado por mais de uma task. Os compartilhados entre módulos têm uma task dona cada (lista em Riscos).

## Decisões de arquitetura

| Decisão | Alternativas consideradas | Motivo da escolha |
|---|---|---|
| `AiGatewayInterface::complete(AiCompletionRequest): AiCompletionResult` neutro, com `AnthropicMessagesGateway` (curl), `StubAiGateway` e `UnconfiguredAiGateway`, escolhidos por `AiGatewayFactory::fromConfig` | Chamar a API direto nos serviços; SDK no serviço | PRD § 9 pede provedor desacoplado; troca de provedor é um adaptador novo. O stub permite gate de tela sem rede e sem chave |
| Cliente HTTP próprio com `curl` e transporte injetável (`?Closure $transport`) | SDK PHP oficial `anthropic-ai/sdk` (padrão da skill `claude-api`); Guzzle | `src/composer.json` está em `framework_hashes.php` (regra das fases anteriores: não editar). Só um endpoint (`POST /v1/messages`, não streaming) é usado; o projeto já tem o padrão curl do `S3Client`. Sem dependência nova na hospedagem compartilhada. Confirmado pelo usuário; trocar pelo SDK mudaria só o adaptador |
| Síncrono com timeout de 30 s (conexão 5 s), sem retentativa | Fila `QueueInterface` + worker e polling | A revisão humana acontece na mesma interação; na hospedagem o worker roda por cron `--once` (minutos de latência) e exige Redis. Timeout curto e 1 chamada por requisição limitam o PHP-FPM preso; falha vira aviso e a tela segue |
| Padrão `claude-sonnet-5-5` com `effort` `low`, configurável por `AI_MODEL`/`AI_EFFORT`; `fallbacks: "default"` ligado | `claude-opus-5-5` (padrão da skill) ou `claude-haiku-4-5` | Decisão do usuário (custo); effort `low` conforme a skill `claude-api` para chamada única de texto no Sonnet 5.5. Troca de modelo é só env. Fallback evita recusa seca nas categorias que o Sonnet 5.5 encaminha |
| Tabela genérica `feature_flag` (flag `ai_assistant`, ausência = desligado) + `ai_tenant_setting` (termo e cota) | Coluna em `tenant`; uma tabela só de IA | O PRD § 13 lista `feature_flag` na governança de IA; 8B/8C reaproveitam. Termo e cota são específicos da IA. Nada de ALTER em tabela existente |
| `ai_interaction` guarda finalidade, versão de prompt, provedor, modelo devolvido, usuário, unidade, sujeito, tokens (entrada, saída, cache), custo em micro-USD, latência, código de erro, `stop_reason`, id do pedido do provedor, sugestão, texto final e decisão; da entrada só o SHA-256 | Guardar o prompt completo; não guardar a sugestão | Requisito do PRD (finalidade, versão, modelo, usuário, confirmação, custo); a sugestão fica no banco para a aceitação ser feita pelo id no servidor (sem confiar em texto ecoado pelo cliente) e para auditoria da edição; o prompt minimizado não é guardado |
| Cota mensal em tokens (`input + output + cache`) somada de `ai_interaction` no mês corrente (UTC), checada antes da chamada; padrão 300000 | Tabela de agregação por mês; cota em dinheiro | Índice `(tenant_id, created_at)` torna a soma barata; uma fonte de verdade. A corrida entre duas chamadas simultâneas pode ultrapassar a cota em uma chamada (aceito, registrado em Riscos de notes.md) |
| Decisão humana por `decide(interactionId, accepted|edited|discarded, finalText)`, UPDATE condicional em `pending_review`; quem persiste no atendimento é o controller, na mesma transação (`EncounterService::acceptAiSummary` para o resumo; `EncounterService::autosave` com `anamnesis_text` = postado + texto aceito para a anamnese). O resumo de exame fica só em `ai_interaction.final_text` | Serviço de IA gravando no atendimento; resumo de exame em coluna nova de `exam_result` | IA não persiste sozinha; o resultado original do exame não muda; sem ALTER em tabela clínica |
| Ações de IA autorizadas pelo programa da tela que as hospeda (`EncounterView::onAi*`, `ExamResultForm::onAi*`) com unidade do sujeito; configuração por programas novos só do grupo 1 | Programas próprios por caso de uso | "Mesmas permissões da UI" (demanda); o provider só entende `Classe::metodo` |
| Porta nova `AiAssistantInterface` implementada por `AiAssistService`; a porta antiga e o `NullAiClinicalAssistant` saem na T-14 junto com o último uso | Reescrever a porta antiga na Onda 1 | Mudar a porta antiga na Onda 1 quebraria `EncounterView` (chamadas em :312 e :1623) até a Onda 4 |

Rotas fixadas (todas `index.php?class=...`, só ids e códigos fixos; textos só por POST):
- `AiSettingsForm` (ações `onSave` com `ai_enabled`, `accept_terms`, `monthly_token_quota`); `AiInteractionList`.
- `EncounterView&method=onAiPatientSummary&encounter_id=<id>`; `EncounterView&method=onAiOrganizeAnamnesis` (POST `encounter_id`, `ai_free_text`); `EncounterView&method=onAiDecide` (POST `encounter_id`, `interaction_id`, `decision`, `ai_final_text`, `anamnesis_text`).
- `ExamResultForm&method=onAiExamSummary&exam_result_id=<id>`; `ExamResultForm&method=onAiDecide` (POST `exam_result_id`, `interaction_id`, `decision`, `ai_final_text`).

## Diagrama de dependências

```text
Onda 1: T-01  T-02  T-03  T-04
        [bloqueio: 0014 em centralvet + centralvet_test; sql/T-04-programs.sql em centralvet]
Onda 2: T-05 (T-02)  T-06 (T-01,T-02)  T-07 (T-02,T-03)  T-08 (T-02)  T-09 (T-01,T-02)  T-10
Onda 3: T-11 (T-02,T-03,T-05,T-06,T-07,T-08,T-09)  T-12 (T-02,T-05,T-06)
Onda 4: T-13 (T-04,T-12)  T-14 (T-02,T-10,T-11)  T-15 (T-02,T-10,T-11)  T-16 (T-04)
Onda 5: T-17 (T-11..T-16)   T-18 (T-01,T-03,T-04,T-07,T-11)
Onda 6: T-19 (T-17,T-18)
```

## Estratégia de execução
- Branch de trabalho: `feat/fase-8a-ai-gateway`
- Branch base: `main`
- Ponto de partida: `origin/main` @ `5e4b238`; a branch já existe e está em checkout (criada pelo orquestrador). O nome `feat/` foi dado pelo orquestrador em vez de `task/fase-8a-ai-gateway`.
- Commits da onda: cada implementador commita os próprios caminhos (`git commit -- <caminhos>`); com `worktree por agente` eles chegam pelos merges de `wave<N>/T-XX`. O fechador usa a skill `new-commit --auto` só se sobrou algo sem commit e sempre registra o estado das tasks num `chore(tasks): registra commits da onda N`. Esses commits são internos: a entrega da Fase 5 (`entrega.py`) os desfaz e deixa o código staged para o desenvolvedor revisar; commit final e push só a pedido dele.
- Isolamento em ondas com edições paralelas: caminho exclusivo. Nenhum arquivo é dividido entre tasks; os compartilhados entre módulos têm um escritor só (Mapa de arquivos).
- **Bloqueio entre a Onda 1 e a Onda 2** (orquestrador, com aprovação SQL):
  1. Backup (`./scripts/backup.sh`) + `gzip -t`.
  2. SHA-256 da 0014 numa cópia (o placeholder de 64 zeros é trocado só na cópia aplicada).
  3. Aplicação em `centralvet` e `centralvet_test` com `MIGRATION_DB_USER`, e `.verify.sql` nos dois.
  4. `sql/T-04-programs.sql` (2 programas + 2 concessões) + `.verify.sql` em `centralvet` com `--default-character-set=utf8mb4`. Últimas contagens conhecidas, do fim da 7B: `system_program` 137 e `system_group_program` 189 (reconferir por SELECT).
  5. Contagens antes/depois de `encounter`, `exam_result`, `patient`, `audit_log`, `system_program` e `system_group_program` vão para `notes.md § Bloqueios`.

  A Onda 2 não abre sem isso: T-06 e T-09 rodam integração no `centralvet_test`.
- Gates econômicos (limite de turnos do validador):
  - Ondas 1–3: LINT dos arquivos da onda + uma SUITE inteira (+ PYTEST57 e PREP57 sobre a 0014 na Onda 1). Sem navegador.
  - Onda 4:
    - Rebuild (`build app` + `AI_PROVIDER=stub docker compose up -d app worker` + `restart nginx`) e login admin pelo orquestrador.
    - SUITE.
    - Pela tela, com o stub: ligar a IA do tenant 1 com aceite do termo em `AiSettingsForm`; no atendimento do paciente `F8A teste Paciente`, gerar e aceitar o resumo, organizar uma anamnese e aplicar; no resultado de exame dele, gerar e editar o resumo; `AiInteractionList` mostra as 3 interações com modelo `stub`.
    - `docker compose logs --since 10m app` sem `f8a.teste@example.invalid`, sem `F8A teste Tutor` e sem `x-api-key`.
    - Smoke Playwright **só desktop (1366×768)** das telas tocadas: render, console sem mensagem de nível error, rede sem resposta ≥ 400.
    - Antes de ligar a IA, o orquestrador anota por SELECT o estado de `feature_flag`/`ai_tenant_setting` do tenant 1 em `notes.md § Bloqueios` (insumo da limpeza).
  - Onda 5: SUITE + fetch autenticado em pt das telas tocadas (nenhum `Message not found`).
  - Onda 6 (T-19): E2E em **dois disparos de validador**. Roteiro A: os 3 casos de uso + configuração em desktop e tablet 820×1180, com ditado degradado no navegador sem Web Speech. Roteiro B: os itens de Review Focus + permissão negada + IA desligada + cota esgotada + stub com `AI_STUB_BEHAVIOR=timeout`. Disparo C (API real): o orquestrador pede a `ANTHROPIC_API_KEY` ao usuário antes da Onda 6; o usuário a põe no `.env` local; o orquestrador recria o app sem o override do stub (`docker compose up -d app worker` + `restart nginx`) e o validador faz no máximo 3 chamadas (uma por caso de uso) com o paciente `F8A teste Paciente`, conferindo por SELECT `provider` `anthropic`, `model` `claude-sonnet-5-5` (ou o de fallback), tokens > 0 e `cost_micro_usd` > 0 em `ai_interaction`, e que nenhum log, relatório ou tabela contém o valor da chave (contagem por `grep -cF` sem imprimir o valor). Depois, o app volta ao stub.
- i18n: até a Onda 5, as telas usam `_t('<en>')` e anotam `- [T-xx] i18n: <en> → <pt>` no board. "Message not found" no gate da Onda 4 é aceito até a T-17.

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
- T-10

### Onda 3
- T-11
- T-12

### Onda 4
- T-13
- T-14
- T-15
- T-16

### Onda 5
- T-17
- T-18

### Onda 6
- T-19

## Agentes

| Agente | subagent_type | model | Tasks |
|---|---|---|---|
| Sun Tzu — orquestrador | — | — | todas |
| Darwin | general-purpose | inherit | T-01, T-15 |
| Platão | general-purpose | inherit | T-02, T-05 |
| Tesla | general-purpose | inherit | T-03, T-10 |
| Jaspion | general-purpose | inherit | T-04, T-16 |
| Athena | general-purpose | inherit | T-06 |
| Naruto | general-purpose | inherit | T-07 |
| Spock | general-purpose | inherit | T-08 |
| Arquimedes | general-purpose | inherit | T-09 |
| Batman | general-purpose | inherit | T-11 |
| Saitama | general-purpose | inherit | T-12 |
| Kratos | general-purpose | inherit | T-13 |
| Aang | general-purpose | inherit | T-14 |
| Levi | general-purpose | inherit | T-17 |
| Gandalf | geduc:documentador | sonnet | T-18 |
| Sherlock | general-purpose | inherit | T-19 |

## Review Focus
- `EncounterView&method=onAiDecide` ou `ExamResultForm&method=onAiDecide` com `interaction_id` de outro tenant, de outra unidade, já decidida ou inexistente → a mesma mensagem `AI suggestion not found`, nenhuma linha alterada em `ai_interaction`, `encounter` nem `exam_result` → T-11
- Texto livre com `F8A teste Tutor`, `f8a.teste@example.invalid`, `(11) 98765-4321` e `123.456.789-09`, e histórico com nome do paciente e do tutor → o `userContent` recebido pelo `FakeAiGateway` não contém nenhum desses valores (contém `[NOME]`, `[EMAIL]`, `[TELEFONE]`, `[CPF]`) e `ai_interaction` não tem coluna com a entrada (só `input_sha256`) → T-08
- Provedor com timeout, 429, 529 ou `stop_reason` `refusal` (stub `AI_STUB_BEHAVIOR=timeout` no gate) → a tela do atendimento continua aberta com aviso traduzido de IA indisponível, a anamnese e o resumo existentes ficam iguais, e `ai_interaction` ganha uma linha `failed` com `error_code` (`ai_timeout`, `ai_rate_limited`, `ai_overloaded`, `ai_refused`) → T-14
- Anamnese com texto digitado ainda não salvo pelo autosave e "Aplicar à anamnese" → `encounter.anamnesis_text` = texto postado + linha em branco + texto aceito; depois do recarregamento e de 25 s de autosave o campo continua com o texto combinado → T-14
- IA desligada, termo da versão atual não aceito ou cota do mês esgotada → os botões de IA não aparecem nas telas (`isAvailable()` falso) e um POST direto em `onAiOrganizeAnamnesis` devolve aviso com o código (`ai_disabled`, `ai_terms_pending`, `ai_quota_exceeded`) sem chamada ao gateway (`FakeAiGateway::calls()` vazio) → T-11

## Critérios gerais de aceite
- SUITE com `Failed: 0` e `Total` maior ou igual ao da BASE da onda somado aos testes novos.
- Nenhum erro novo em relação a `baseline/php-lint.txt`: cada PHP tocado imprime `No syntax errors detected`.
- PYTEST57 com `Ran 8 tests` (ou mais) e nenhuma falha.
- Toda query nova filtra `tenant_id` (`TenantQuery::forTenant`), e toda geração ou decisão autoriza com a ação `Classe::metodo` da tela hospedeira e `resourceUnitId` do sujeito ou da interação persistida.
- Nenhuma chamada de rede nos testes: `/usr/bin/grep -rln "api.anthropic.com" src/tests` imprime nada, e só `AnthropicMessagesGateway.php` e `AiConfig.php` citam o host em `src/app`.
- A chave da API nunca aparece em log, banco, auditoria, mensagem de exceção ou relatório: grep de `x-api-key` e do valor de teste `sk-ant-test-f8a` nos logs do gate e em `ai_interaction`/`audit_log` = 0.
- Toda sugestão exibida mostra a origem ("Gerado por IA", modelo e versão do prompt) e só é persistida por ação do usuário (aceitar, editar ou descartar).
- Toda tela nova ou tocada mostra estado vazio, erro traduzido via `CvFormat::userError` e permissão negada (captura `AuthorizationDenied` e `MissingTenantContext`). Rótulos à esquerda, botões ≥ 44 px, texto livre só por POST.
- Contagens de `encounter`, `exam_result`, `patient` e `audit_log` antes e depois do bloqueio e dos gates: as linhas existentes continuam lá (só crescem até a limpeza), e `exam_result.structured_result_text` do exame de teste é igual antes e depois do resumo.
