# Plano: Pendências da Fase 8B (Copiloto e ferramentas MCP)

## Objetivo
Fechar as pendências `[aberta]` da Triagem e as `[sugestão]` ainda abertas dos Achados da Revisão final — Rodada 2 da Fase 8B (`.claude/tasks/mar-20261015-2050-fase-8b-copiloto-mcp/reviews/final.md`): corrigir com teste RED o que é defeito, cobrir com teste o que falta, documentar o que é regra e registrar com motivo o que não se faz.

## Premissas
- Projeto `/var/www/html/centralvet`, branch `feat/fase-8b-copiloto-mcp` @ `14139af`, árvore limpa; SUITE atual 1704/1704 (`src/tests/run.php`, PHP 8.4 no container `app`).
- Branch de trabalho `task/fase-8b-pendencias` a partir de `feat/fase-8b-copiloto-mcp` @ `14139af`, que é também a branch base (decisão do usuário em 2026-10-19): o código a corrigir não está em `main`.
- Nenhum SQL é executado: a 0015 e a DML do bloco 8B no `centralvet` (dev) e em produção dependem de autorização SQL expressa do usuário (pendência à parte, notes.md § Pendências). A SUITE só escreve no `centralvet_test`, em transação.
- Sem token CSRF global (padrão do projeto), sem endpoint MCP externo, sem dependência nova no composer, sem push/PR, sem apagar `mcp_tool_call`/`ai_interaction`.
- Restrições da 8B mantidas: rótulos alinhados à esquerda, alvos de toque ≥ 44 px (`/var/www/html/centralvet/CLAUDE.md`), textos por `_t()` com pt/en, artefatos em pt-BR.

## Escopo

### Incluso
Triagem (cada item `[aberta]` da Rodada 2 e cada `[sugestão]` aberta dos Achados) → decisão:
- 8B T-03 guarda por `isCopilot` em `recordAnswer`/`recordSuggestion`; teste de `decide` inválido em `answered` → corrigir, T-01.
- 8B T-04 chave extra ecoa o nome; nome duplicado ignorado no `ToolRegistry`; teste de allowlist fraco → corrigir, T-02.
- 8B T-02 `jsonObjects` em qualquer profundidade; teste de `messages` sem `tools` → corrigir, T-03.
- 8B T-05 `+` no teste; `reconstitute` frágil → corrigir, T-04.
- 8B T-10 `truncated` sempre false; histórico fora da unidade sem teste → corrigir, T-05.
- 8B T-11 via/posologia/duracao fora de `freeTextFields`; fixture `received`; casos `requested`/âncora fora → corrigir, T-06. Duplicação `patientId()` (Achados) → trait, T-06.
- 8B T-06 fixture `otherUnitId` de outro tenant → corrigir, T-07.
- 8B T-19 profissional inativo vira not found em `consultar_agenda`; 8B T-27 fronteira de 90 dias e outro tenant → corrigir, T-08.
- 8B T-20 escape LIKE, bordas de expiring, lote vencido → corrigir, T-09.
- 8B T-21 fuso do `ListarDebitosTool`; paciente novo na A → corrigir, T-10; vencimento no dia e `ticket_medio` → documentar, T-24.
- 8B T-26 closure de configuração sem teste → corrigir, T-11.
- 8B T-28 `ai_interaction` depois da resposta, falha sem log → logar com `correlation_id`, T-12.
- 8B T-23 nenhum teste falha sem `ManagementToolset` no `forContext` → teste, T-13.
- 8B T-09 insert sem conferir unidade/usuário e `conversation_id` → corrigir, T-14.
- 8B T-12 `updated_at`×`created_at`, `MAX_BATCHES` sem teste, sem trava → corrigir, T-15; DELETE em lote → documentar, T-24.
- 8B T-14 pergunta só com espaços; 8B T-29 RBAC do painel não exercido, pergunta perdida com `AiUnavailableException`, `onDelete` antes da âncora; Achado "onAsk perde o texto" → corrigir, T-16. Foco/live region → T-22.
- 8B T-07 formato en/pt sem teste → teste, T-17.
- 8B T-22 flag provada só por fake → teste, T-18.
- 8B T-01 `.verify.sql` sem os índices auxiliares → corrigir, T-19; RESTRICT da âncora → documentar, T-24.
- 8B T-25 `excerptLengths ⊆ freeTextFields`; `ToolPolicy` sem conferir o `ToolContext`; Achado docblock de `knownNames` → corrigir, T-20 (com T-02).
- 8B T-15 Motivo cru, teste de negação fraco, sem XSS → corrigir, T-21.
- 8B T-16 markup duplicado e docblock órfão; duplicação `copilotLink` (Achados) → T-23.
- 8B T-18 runbook 5.7 ambíguo e referência `ai_*`; 8B T-24 runbook da flag; 8B T-17 seed só no grupo 1 → documentar, T-24.
- Onda 4 — correção (code-review), achados do `/code-review` da branch: negação cross-context da T-20 não auditada por causa da guarda da T-14 → T-25; `onAsk` com `AiUnavailableException` perde conversa/lista/ações → T-26; `obter_prescricoes` sem `knownNames` → T-27; falha do `fopen` da trava da retenção tratada como sobreposição → T-28.

### Excluído
- Ondas 5–10 "SQL dev" e "0015 no dev": aplicar a 0015 e a DML do bloco 8B no `centralvet` (dev) ou produção — exige autorização SQL expressa (pendência à parte).
- Token CSRF na exclusão (8B T-14, Achados): padrão do projeto, fora por premissa.
- "nenhum push" não verificável por remoto: nada a fazer (sem push nesta rodada também).
- Não fazer, com motivo (notes.md § Decisões tomadas): 8B T-08 rate limit depois do escopo (fixado pela spec e ruling T-08 · onda 2); 8B T-09 `search` tenant-wide (intencional, `requiresUnitScope: false`); 8B T-13 10º parâmetro `?PiiMinimizer` (ruling T-13 · onda 3); 8B T-06 `superseded` na mesma unidade e `draft` como ativa (rulings T-06 · onda 1 e T-11 · onda 2); 8B T-25 forbidden × not_offered (ruling T-25 · onda 8); 8B T-16 link sem RBAC de `onOpen` (ruling T-16 · onda 5; `RbacAuthorizationService::decide` grava auditoria a cada chamada, então checar no render geraria uma linha de `audit_log` por exibição); 8B T-04 `iterable` × `array` (alargamento compatível); 8B T-14 asserção do JS por `str_contains` (sem runner de JS; Playwright proibido); 8B T-28 RED do cenário 3 que já passava (cobertura de regressão aceita); 8B T-26 duplicação `reason` × `availability` (já resolvida: o serviço delega); duplicação de conversas entre `AiCopilotService` e `AiConversationHistoryService` (os métodos de leitura já delegam; o resíduo é o caminho do `ask`, que autoriza `onAsk`); contagem "33 chaves" (8B T-07) e `SET NULL` em tasks.md:48 da 8B (texto de plano fechado; os rulings valem).
- Verificação manual, fora do código: trilho do switch `.tswitch` + `cv-touch-target` (8B T-22, conferir no navegador); bloco 8B do seed no MySQL 5.7 (8B T-17, sem imagem 5.7 local).

## Contexto técnico
- Camadas envolvidas: backend, frontend, database, docs, qa
- Projeto/base analisada: `/var/www/html/centralvet` (raiz de `git -C <DIR> rev-parse --show-toplevel`), branch `feat/fase-8b-copiloto-mcp` @ `14139af`
- Integrações: provedor Anthropic só por `StubAiGateway`/transporte injetado nos testes; MySQL `centralvet_test` e Redis da SUITE

## Baseline
- php-lint: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app sh -c 'find app/Core app/control app/lib/widget bin tests -name "*.php" -exec php -l {} \; 2>&1 | grep -v "^No syntax errors detected"' 2>/dev/null` em raiz → baseline/php-lint.txt (0 linhas). Critério das tasks: nenhum erro novo em relação a `baseline/php-lint.txt` (cada PHP tocado imprime `No syntax errors detected`).

## Exploração read-only
- Caminhos relevantes:
  - **Domínio/gateway.** `src/app/Core/Domain/AiInteraction.php` (recordSuggestion :177, recordAnswer :202, decide :249, assertAwaitingProvider :455); `AiPurpose::isCopilot` :54; `src/app/Core/Ai/AnthropicMessagesGateway.php` jsonObjects :121-137; `src/app/Core/Domain/AiConversationMessage.php` reconstitute :81, fitSummary :186.
  - **Ferramentas.** `src/app/Core/Ai/Tools/ToolRegistry.php` (construtor :36, `registered` :107, cache/duplicado :131, `TenantContext` privado :40); `ToolPolicy.php` execute :70 (escopo :93 antes do rate limit :95); `ToolResult.php` (docblock :10-11, validação :38-42); `JsonSchemaValidator.php:57`; `ToolContext` com `tenantId/unitId/userId` públicos readonly.
  - **Clínicas.** `Tools/Clinical/*` (truncated fixo em BuscarPaciente :111 e ObterHistorico :218; `patientId()` idêntico em ObterPrescricoes :107, ObterExames :110, ConsultarVacinacao :97); `CopilotClinicalQuery.php` (ativas :204, superseded :295).
  - **Gestão.** `CopilotAgendaQuery::professionalExists` :172 (`active = 'Y'`), usado por ConsultarAgenda :79 e VerificarHorarios :88; `CopilotStockQuery` (escape :79, withNextExpiry :103); `ListarDebitosTool.php:91`; `IndicadoresClinicaTool.php:106` (padrão de fuso).
  - **Serviços/persistência.** `AiCopilotService.php` (forContext :152-204, recordInteraction :500-518); `AiCopilotAvailability.php` (closure :64-76); repositórios `AiConversationRepository::insert` :32, `McpToolCallRepository::insert` :30; `AiConversationRetention` (constantes :26-27, runOnce); `src/bin/ai-conversation-retention.php`; trava em `src/bin/worker.php:129-135`.
  - **Telas.** `AiCopilotPanel.php` (onAsk :86-150, onDelete :158-188, perform :200-270, load :275, renderMessageOnly :349); `cv-ai-copilot.js` (live region :55-58, release :27-30); `AiToolCallList.php:239`; `PatientForm.php:721-770`, `EncounterView.php:1695-1729`.
  - **Docs/DB.** `docs/runbooks/shared-hosting-mysql57.md:363-376` (0011 :186-190); `docs/runbooks/ia-assistente.md` (códigos ai_* :118-131, motivos :345-361, flag :391-397, débitos :411); `20261015_0015_phase8b_copilot.verify.sql:55-69`; `scripts/prepare-mysql57.py:96-152`.
- Padrões identificados: serviços com repositórios por interface, `TenantContext` e `?Closure $clock` por último; integração herda `MysqlIntegrationTestCase` (transação com rollback); controllers Adianti só carregam em subprocesso com `require "init.php"`; `translations.json` ordenado por `en` e com `en` único (CopilotTranslationsTest); captura de `error_log` por `ini_set('error_log', ...)` (PatientServiceTest:446); leitura de propriedade privada em teste por `ReflectionProperty` (PatientServiceTest:566); ferramentas pedem `limit + 1` para `truncated`.
- Scripts úteis: LINT e SUITE (tasks.md, topo, e `agents/*.md § Ambiente`); sem build JS nem runner de JS.
- Riscos identificados:
  - **Insert com unidade/usuário (T-14)** pode quebrar fixtures que gravam conversa de "outro usuário" pelo repositório com o contexto errado; o implementador ajusta só o teste da própria task e registra no board o que falhar em arquivo alheio.
  - **Validação de `excerptLengths` (T-20)** lança em ferramenta que corte campo fora de `freeTextFields`: T-20 roda depois de T-05/T-06 e valida todas as suítes de ferramentas.
  - **`sameContext` (T-02/T-20)**: `ToolContext::unitId` vem de `requireUnitId()`, `TenantContext::unitId()` é `?int` — comparar com `===` contra o `int`.
  - **Arquivos compartilhados**, serializados em ondas: `ToolRegistry.php` (T-02 → consumido por T-20), `translations.json` (T-16 → T-21), `AiCopilotPanel.php` e `AiCopilotPanelIntegrationTest.php` (T-16 → T-22 → T-23).
  - **SUITEs concorrentes** na Onda 1 (até 7 agentes): `FAIL` de arquivo alheio, Redis ou deadlock é ruído; cada agente filtra pelas próprias classes.

## Mapa de arquivos

| Arquivo | Responsabilidade | Ação | Tasks que tocam |
|---|---|---|---|
| src/app/Core/Domain/AiInteraction.php | guardas de finalidade em recordAnswer/recordSuggestion | modificar | T-01 |
| src/tests/Unit/AiDomainTest.php | testes das guardas e de decide inválido em answered | modificar | T-01 |
| src/app/Core/Ai/Tools/ToolRegistry.php | ⚠ nome duplicado lança; `sameContext` (T-02); `contextFor` para auditar a negação cross-context (T-25) | modificar | T-02, T-25 |
| src/app/Core/Ai/Tools/JsonSchemaValidator.php | erro de chave extra sem o nome | modificar | T-02 |
| src/tests/Unit/ToolRegistryTest.php | duplicado, sameContext, allowlist exata | modificar | T-02 |
| src/tests/Unit/JsonSchemaValidatorTest.php | mensagem genérica de chave extra | modificar | T-02 |
| src/app/Core/Ai/AnthropicMessagesGateway.php | `{}` só em input de tool_use e input_schema.properties | modificar | T-03 |
| src/tests/Unit/AnthropicMessagesGatewayTest.php | profundidade e messages sem tools | modificar | T-03 |
| src/app/Core/Domain/AiConversationMessage.php | reconstitute tolerante a tool_summary inválido | modificar | T-04 |
| src/tests/Unit/AiConversationDomainTest.php | entrada inválida descartada; id reconstituído | modificar | T-04 |
| src/app/Core/Ai/Tools/Clinical/BuscarPacienteTool.php | truncated por limit+1 | modificar | T-05 |
| src/app/Core/Ai/Tools/Clinical/ObterHistoricoAtendimentosTool.php | truncated por limit+1 | modificar | T-05 |
| src/tests/Unit/ClinicalToolsPatientTest.php | truncamento e histórico fora da unidade | modificar | T-05 |
| src/app/Core/Ai/Tools/Clinical/ResolvesPatientInUnit.php | trait `patientId()` das ferramentas de registros | criar | T-06 |
| src/app/Core/Ai/Tools/Clinical/ObterPrescricoesTool.php | ⚠ freeTextFields via/posologia/duracao; usa a trait (T-06); knownNames dos tutores (T-27) | modificar | T-06, T-27 |
| src/app/Core/Ai/Tools/Clinical/ObterExamesTool.php | usa a trait | modificar | T-06 |
| src/app/Core/Ai/Tools/Clinical/ConsultarVacinacaoTool.php | usa a trait | modificar | T-06 |
| src/tests/Unit/ClinicalToolsRecordsTest.php | ⚠ freeTextFields, fixtures de status, casos requested/âncora fora (T-06); nome do tutor mascarado (T-27) | modificar | T-06, T-27 |
| src/tests/Integration/CopilotClinicalQueryIntegrationTest.php | unidades A e B do tenant A | modificar | T-07 |
| src/app/Core/Domain/Contract/CopilotAgendaQueryInterface.php | `professionalExists(int, bool $includeInactive = false)` | modificar | T-08 |
| src/app/Core/Persistence/CopilotAgendaQuery.php | filtro de ativo opcional | modificar | T-08 |
| src/app/Core/Ai/Tools/Management/ConsultarAgendaTool.php | aceita profissional inativo | modificar | T-08 |
| src/tests/Support/FakeCopilotAgendaQuery.php | `inactive_professionals` | modificar | T-08 |
| src/tests/Unit/AgendaToolsTest.php | inativo em consultar × verificar | modificar | T-08 |
| src/tests/Integration/CopilotAgendaQueryIntegrationTest.php | inativo, fronteiras de 90 dias, outro tenant | modificar | T-08 |
| src/app/Core/Persistence/CopilotStockQuery.php | próximo lote não vencido | modificar | T-09 |
| src/tests/Integration/CopilotStockQueryIntegrationTest.php | lote vencido, escape `_`, bordas de expiring | modificar | T-09 |
| src/app/Core/Ai/Tools/Management/ListarDebitosTool.php | dia no fuso da aplicação | modificar | T-10 |
| src/tests/Unit/FinanceToolsTest.php | relógio em UTC | modificar | T-10 |
| src/tests/Integration/CopilotFinanceQueryIntegrationTest.php | paciente novo na A com histórico na B | modificar | T-10 |
| src/app/Core/Application/AiCopilotAvailability.php | `providerConfigured(AiConfig)` | modificar | T-11 |
| src/tests/Unit/AiCopilotAvailabilityTest.php | casos de configuração | modificar | T-11 |
| src/app/Core/Application/AiCopilotService.php | log da falha do insert após a resposta | modificar | T-12 |
| src/tests/Unit/AiCopilotServiceTest.php | teste do log | modificar | T-12 |
| src/tests/Support/FakeAiInteractionRepository.php | falha programada no insert | modificar | T-12 |
| src/tests/Integration/AiCopilotServiceForContextIntegrationTest.php | ferramentas registradas por família | modificar | T-13 |
| src/app/Core/Persistence/AiConversationRepository.php | insert confere unidade/usuário | modificar | T-14 |
| src/app/Core/Persistence/McpToolCallRepository.php | insert confere unidade/usuário e conversation_id | modificar | T-14 |
| src/tests/Integration/CopilotRepositoryIntegrationTest.php | fronteiras e lacunas | modificar | T-14 |
| src/app/Core/Application/AiConversationRetention.php | lote e teto injetáveis | modificar | T-15 |
| src/bin/ai-conversation-retention.php | ⚠ trava `flock` (T-15); falha do `fopen` sai 1 com log (T-28) | modificar | T-15, T-28 |
| src/tests/Integration/AiConversationRetentionIntegrationTest.php | laço, teto, updated_at × created_at | modificar | T-15 |
| src/app/control/clinic/AiCopilotPanel.php | ⚠ pergunta em branco, rascunho, âncora antes de apagar (T-16); foco na resposta (T-22); `hostLink` (T-23); onAsk indisponível renderiza a conversa (T-26) | modificar | T-16, T-22, T-23, T-26 |
| src/app/config/translations.json | ⚠ aviso de pergunta em branco (T-16); rótulos de Motivo (T-21) | modificar | T-16, T-21 |
| src/tests/Integration/AiCopilotPanelIntegrationTest.php | ⚠ pergunta em branco, rascunho, âncora, RBAC negado (T-16); foco (T-22); conversa + motivo + rascunho no onAsk (T-26) | modificar | T-16, T-22, T-26 |
| src/tests/Unit/CopilotTranslationsTest.php | forma en/pt de todas as entradas | modificar | T-17 |
| src/tests/Integration/AiCopilotManagementFlagIntegrationTest.php | flag de gestão no FeatureFlagRepository real | criar | T-18 |
| src/app/database/migrations/20261015_0015_phase8b_copilot.verify.sql | 12 índices conferidos | modificar | T-19 |
| src/app/Core/Ai/Tools/ToolPolicy.php | ⚠ nega ToolContext de outro contexto (T-20); audita a negação com o contexto do registry (T-25) | modificar | T-20, T-25 |
| src/app/Core/Ai/Tools/ToolResult.php | excerptLengths ⊆ freeTextFields; docblock | modificar | T-20 |
| src/tests/Unit/ToolPolicyTest.php | contexto divergente | modificar | T-20 |
| src/tests/Unit/ToolResultTest.php | validação de excerptLengths | criar | T-20 |
| src/app/control/clinic/AiToolCallList.php | rótulos de Motivo | modificar | T-21 |
| src/tests/Integration/AiCopilotListsIntegrationTest.php | Motivo, negação, XSS | modificar | T-21 |
| src/app/templates/adminbs5/js/cv-ai-copilot.js | foco na resposta nova | modificar | T-22 |
| src/app/control/clinic/PatientForm.php | usa `hostLink`; docblock de resolveTenantContext | modificar | T-23 |
| src/app/control/clinic/EncounterView.php | usa `hostLink` | modificar | T-23 |
| docs/runbooks/ia-assistente.md | códigos ai_*, flag, débitos, ticket médio, retenção, seed, âncora, motivos | modificar | T-24 |
| docs/runbooks/shared-hosting-mysql57.md | § 0015 no 5.7 | modificar | T-24 |
| src/tests/Integration/ToolPolicyCrossContextIntegrationTest.php | negação cross-context gravada em mcp_tool_call | criar | T-25 |
| src/tests/Integration/AiConversationRetentionBinTest.php | bin: falha da trava × sobreposição | criar | T-28 |

Arquivo tocado por mais de uma task leva ⚠ na linha e a resolução (serializar em ondas diferentes, worktree por agente ou escritor único) fica em "Estratégia de execução"; caminho exclusivo não resolve arquivo compartilhado na mesma onda.

## Decisões de arquitetura

| Decisão | Alternativas consideradas | Motivo da escolha |
|---|---|---|
| `ToolRegistry::sameContext(ToolContext)` e negação `forbidden` na `ToolPolicy` | getter do `TenantContext` no registry; asserção que lança | mantém o contexto encapsulado e o "nunca lança" da `ToolPolicy` (toda falha vira denied auditado) |
| Nome duplicado no registry lança `LogicException` | manter o primeiro em silêncio | erro de montagem no código, não de entrada; descobrir no teste e não em produção |
| `jsonObjects` só nos dois caminhos que a API exige como objeto | manter a troca em qualquer profundidade | dados do modelo dentro do `input` voltam como vieram |
| `reconstitute` descarta entrada inválida de `tool_summary`; escrita continua estrita | lançar na leitura | uma linha corrompida não pode derrubar a conversa inteira; o rótulo "Consultei" é informativo |
| Trait `ResolvesPatientInUnit` só para a variante idêntica das 3 ferramentas de registros | classe base abstrata; unificar também ObterPaciente/ObterHistorico | as duas variantes têm regras diferentes (âncora, `is_int`); herança mudaria a hierarquia de `AiTool` |
| `professionalExists($id, true)` só em `consultar_agenda` | tirar o filtro de ativo para todos | inativo tem agendamentos a consultar, mas não deve receber horários livres |
| Lote vencido fora de validade/lote por `CURDATE()` | parâmetro `$today` na interface | não muda `CopilotStockQueryInterface`, fake nem ferramenta; a sessão MySQL é a mesma das gravações |
| Log com `correlation_id` antes de relançar a falha do insert da interação | inserir a interação antes da resposta e atualizar depois | mantém o ruling T-28 · onda 9 (interação em `finally` após a mensagem) e dá rastro sem PII |
| `insert` dos repositórios exige unidade e usuário do contexto; `conversation_id` só do tenant | exigir também o dono da conversa | a auditoria grava antes de a retenção/exclusão agir; dono já garantido pelo serviço |
| Trava da retenção por `flock` como o `worker.php` | `GET_LOCK` no MySQL | padrão já existente no projeto, sem SQL novo |
| Foco na última resposta (`tabindex="-1"`), sem texto novo de anúncio | live region com "Resposta recebida" | evita chave nova em `translations.json` na Onda 2 (colisão com T-21) e o leitor de tela lê o conteúdo focado |
| `AiCopilotPanel::hostLink` só com o markup; disponibilidade fica nas telas | helper que também checa disponibilidade e RBAC | RBAC no render grava auditoria a cada exibição (ruling T-16 · onda 5) |

## Diagrama de dependências

```text
Onda 1: T-01 T-02 T-03 T-04 T-05 T-06 T-07 T-08 T-09 T-10 T-11 T-12 T-13 T-14 T-15 T-16 T-17 T-18 T-19
T-02, T-05, T-06 → T-20
T-16 → T-21
T-16 → T-22 → T-23
T-08, T-09, T-15, T-21 → T-24
T-02, T-20 → T-25; T-23 → T-26; T-06 → T-27; T-15 → T-28
```

## Estratégia de execução
- Branch de trabalho: `task/fase-8b-pendencias`
- Branch base: `feat/fase-8b-copiloto-mcp`
- Ponto de partida: `14139af` (a 8B não está em `main` — decisão do usuário em 2026-10-19, notes.md).
- Commits da onda: cada implementador commita os próprios caminhos (`git commit -- <caminhos>`); com `worktree por agente` eles chegam pelos merges de `wave<N>/T-XX`. O fechador usa a skill `new-commit --auto` só se sobrou algo sem commit e sempre registra o estado das tasks num `chore(tasks): registra commits da onda N`. Esses commits são internos: a entrega da Fase 5 (`entrega.py`) os desfaz e deixa o código staged para o desenvolvedor revisar; commit final e push só a pedido dele.
- Isolamento em ondas com edições paralelas: caminho exclusivo — o Mapa não tem arquivo compartilhado dentro de uma onda; os ⚠ (`AiCopilotPanel.php`, `AiCopilotPanelIntegrationTest.php`, `translations.json`) estão serializados nas Ondas 1 → 2 → 3, e os da Onda 4 (`ToolRegistry.php`, `ToolPolicy.php`, `ObterPrescricoesTool.php`, `ClinicalToolsRecordsTest.php`, `ai-conversation-retention.php`, painel) têm a task original em onda anterior; as 4 tasks da Onda 4 têm arquivos disjuntos entre si. Cada agente roda só a SUITE filtrada pelas próprias classes; `FAIL` alheio, Redis ou deadlock com outras SUITEs no ar é ruído.

## Ondas de execução

### Onda 1
- T-01, T-02, T-03, T-04 (Platão — domínio, registry, gateway)
- T-05, T-06, T-07 (Darwin — ferramentas e consulta clínicas)
- T-08, T-09, T-10 (Arquimedes — agenda, estoque, financeiro)
- T-11, T-12, T-13 (Yoda — disponibilidade e serviço do copiloto)
- T-14, T-15 (Jaspion — repositórios e retenção)
- T-16 (Aang — painel)
- T-17, T-18, T-19 (Levi — testes de forma, flag real e verify)

### Onda 2
- T-20 (Spock — ToolPolicy e ToolResult)
- T-21 (Hegel — lista de chamadas de ferramenta)
- T-22 (Aang — foco no painel)

### Onda 3
- T-23 (Aang — `hostLink`)
- T-24 (Gandalf — runbooks)

### Onda 4 — correção (code-review)
- T-25 (Spock — auditoria da negação cross-context)
- T-26 (Aang — onAsk indisponível com a conversa)
- T-27 (Darwin — knownNames em obter_prescricoes)
- T-28 (Jaspion — falha da trava da retenção)

Validação e revisão: gate do validador ao fim de cada onda (SUITE completa sem filtro, 0 `FAIL`, total ≥ 1704 + testes novos) e revisão final da Fase 5.

## Agentes

| Agente | subagent_type | model | Tasks |
|---|---|---|---|
| Sun Tzu — orquestrador | — | — | todas |
| Platão | general-purpose | inherit | T-01, T-02, T-03, T-04 |
| Darwin | general-purpose | inherit | T-05, T-06, T-07, T-27 |
| Arquimedes | general-purpose | inherit | T-08, T-09, T-10 |
| Yoda | general-purpose | inherit | T-11, T-12, T-13 |
| Jaspion | general-purpose | inherit | T-14, T-15, T-28 |
| Aang | general-purpose | inherit | T-16, T-22, T-23, T-26 |
| Levi | general-purpose | inherit | T-17, T-18, T-19 |
| Spock | general-purpose | inherit | T-20, T-25 |
| Hegel | general-purpose | inherit | T-21 |
| Gandalf | geduc:documentador | sonnet | T-24 |

## Review Focus
- Pergunta nova no copiloto clínico e de gestão depois da T-14 (insert confere unidade/usuário e `conversation_id`) → conversa, mensagens e linhas de `mcp_tool_call` gravadas sem `TenantBoundaryViolation`, auditoria das ferramentas preservada → T-14
- Ferramentas clínicas com `excerptLengths` (obter_exames, obter_historico_atendimentos) depois da validação nova → `ToolResult` montado sem `InvalidArgumentException` e texto minimizado antes do corte → T-20
- Requisição com `tools` e bloco tool_use de `input` vazio depois da troca do `jsonObjects` → body com `"input":{}` e `"properties":{}`, sem 400 da API → T-03
- Envio de pergunta com IA ficando indisponível entre abrir e enviar (cota estourada no pré-check) → motivo exibido e pergunta digitada preservada em campo somente leitura → T-16
- Cron da retenção disparado de novo com a execução anterior ainda rodando → segunda execução imprime `{"skipped":"locked"}`, sai 0 e não apaga nada → T-15

## Critérios gerais de aceite
- SUITE completa (`docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php`) termina com `Failed: 0` e `Total` ≥ 1704 + testes novos, ao fim de cada onda.
- Nenhum erro novo de `php -l` em relação a `baseline/php-lint.txt`.
- `git -C /var/www/html/centralvet diff 14139af -- src/composer.json src/app/config/framework_hashes.php src/app/templates/adminbs5/custom.css src/lib/adianti` → 0 linhas.
- Nenhum `text-align:right|center` novo em rótulo ou cabeçalho de grid no diff; links e botões novos com `cv-touch-target`.
- Nenhum SQL fora da SUITE; contagem de linhas de `mcp_tool_call` e `ai_interaction` no `centralvet` (dev) igual antes e depois (nenhuma task escreve no dev).
