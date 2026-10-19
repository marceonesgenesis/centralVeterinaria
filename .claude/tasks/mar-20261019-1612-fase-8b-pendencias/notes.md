# Notas de execução

## Decisões tomadas
- 2026-10-19 · plano · onda 0 — Triagem da Revisão final — Rodada 2 da 8B (`mar-20261015-2050-fase-8b-copiloto-mcp/reviews/final.md`): cada `[aberta]` da Triagem e cada `[sugestão]` aberta dos Achados virou task (plan.md § Incluso), documentação (T-24) ou "não fazer" com o motivo abaixo.
- 2026-10-19 · plano · onda 0 — Branch de trabalho `task/fase-8b-pendencias` criada a partir de `feat/fase-8b-copiloto-mcp` @ `14139af`, com branch base `feat/fase-8b-copiloto-mcp` (não `main`, que não tem o código da 8B): pedido explícito do usuário em 2026-10-19, em resposta à pergunta do planejador (o `branches.py` dá `main`, fonte `padrão`).
- 2026-10-19 · plano · onda 0 — Não fazer: rate limit depois de `assertInScope` (8B T-08) — fixado pela spec e pelo ruling T-08 · onda 2; sondagem de id alheio dá `not_found` sem dado.
- 2026-10-19 · plano · onda 0 — Não fazer: `McpToolCallRepository::search` tenant-wide (8B T-09) — intencional, `AiToolAuditService` com `requiresUnitScope: false` (tela do gestor do tenant).
- 2026-10-19 · plano · onda 0 — Não fazer: 10º parâmetro `?PiiMinimizer` de `AiCopilotService` (8B T-13) e 6º da `ToolPolicy` — rulings T-08 · onda 2 e T-13 · onda 3; a divergência é só do texto do plano fechado da 8B.
- 2026-10-19 · plano · onda 0 — Não fazer: `superseded` de vacina só na mesma unidade e prescrição `draft` como ativa com `provisorio = true` (8B T-06) — rulings T-06 · onda 1 e T-11 · onda 2. Só a fixture `otherUnitId` é corrigida (T-07).
- 2026-10-19 · plano · onda 0 — Não fazer: ferramenta sem RBAC em `offeredNames` → `forbidden` em vez de `not_offered` (8B T-25) — ruling T-25 · onda 8; no fluxo real `definitions()` a omite.
- 2026-10-19 · plano · onda 0 — Não fazer: link "Copiloto" conferir RBAC de `AiCopilotPanel::onOpen` (8B T-16) — ruling T-16 · onda 5; `RbacAuthorizationService::decide` grava `audit_log` a cada decisão, então checar no render gravaria uma linha por exibição de PatientForm/EncounterView; o painel nega ao abrir.
- 2026-10-19 · plano · onda 0 — Não fazer: `iterable $tools` × `array` no `ToolRegistry` (8B T-04) — alargamento compatível; `$clock` sem uso em ObterProntuarioTool/ObterExamesTool — a assinatura `(CopilotClinicalQueryInterface, ?Closure $clock)` é uniforme entre as ferramentas clínicas (board da onda 2 da 8B).
- 2026-10-19 · plano · onda 0 — Não fazer: asserção do JS por `str_contains` (8B T-14) — o projeto não tem runner de JS e Playwright/navegador é proibido aos agentes; a T-22 segue o mesmo padrão.
- 2026-10-19 · plano · onda 0 — Não fazer: RED do cenário 3 da 8B T-28 que já passava e `AiConversationHistoryServiceTest` sem flag desligada — cobertura de regressão aceita no ruling; o serviço de histórico não recebe flag, termo, cota nem gateway.
- 2026-10-19 · plano · onda 0 — Não fazer: duplicação `AiCopilotAvailability::reason` × `AiCopilotService::availability` (8B T-26) — já resolvida (o serviço delega, AiCopilotService.php:296-299); duplicação de conversas entre `AiCopilotService` e `AiConversationHistoryService` — as leituras já delegam (:322-345); o resíduo (`findOwned`/`lastMessages`/`authorize` no `ask`) autoriza `onAsk`, não `onOpen`.
- 2026-10-19 · plano · onda 0 — Não fazer: corrigir "33 chaves" (8B T-07) e `ON DELETE SET NULL` em tasks.md:48 da 8B — textos de plano fechado; os rulings (32 chaves; RESTRICT em 73f950e) valem e ficam registrados aqui. O RESTRICT entra no runbook (T-24).
- 2026-10-19 · plano · onda 0 — Fora por premissa: token CSRF na exclusão (padrão do projeto, `ServiceList::onDelete`); endpoint MCP externo; dependência nova no composer; push/PR; apagar `mcp_tool_call`/`ai_interaction`.
- 2026-10-19 · plano · onda 0 — Lote vencido sai de validade/lote em `abaixo_minimo`/`termo` por `CURDATE()` (data da sessão MySQL, a mesma das gravações), sem mudar a interface da consulta (T-09).
- 2026-10-19 · plano · onda 0 — Falha do insert da `ai_interaction` depois da resposta gravada: log com `correlation_id` e relança (T-12); a ordem da gravação fica pelo ruling T-28 · onda 9.
- 2026-10-19 · T-02 · onda 1 — validador apontou RED inválido (be0025f tocou também JsonSchemaValidatorTest.php, dentro dos Arquivos prováveis); aceito pelo orquestrador; revisor confirmou o RED.
- 2026-10-19 · T-08 · onda 1 — validador apontou RED inválido (3175920 tocou também FakeCopilotAgendaQuery.php, dentro dos Arquivos prováveis); aceito pelo orquestrador; revisor confirmou o RED.
- 2026-10-19 · T-16 · onda 1 — desvios aceitos pelo revisor: RuntimeException na montagem vira AiUnavailableException('ai_provider_error'); parâmetro $fallbackReason de load removido.
- 2026-10-19 · plano · onda 1 — linha "Branch base" de plan.md reformatada pelo orquestrador (repos.py exige só o nome); ponto de partida 14139af movido para linha própria.

## Bloqueios
- nenhum

## Descobertas
- `translations.json` tem 1444 entradas, todas com exatamente `en` e `pt` (conferido no planejamento); a T-17 trava isso por teste.
- O erro de chave extra do `JsonSchemaValidator` não sai do processo hoje (`ToolPolicy.php:85` só testa `!== []`); a T-02 o torna genérico por defesa.
- O `error_log` "copilot link hidden" de PatientForm/EncounterView só ocorre em exceção (sem tenant, erro de banco); a configuração inválida é logada pela closure de `AiCopilotAvailability::forContext`.
- [T-NN] <fato>
- [T-16] AiCopilotPanel: onAsk deixa AiUnavailableException subir a perform (com $draft → renderMessageOnly com motivo e rascunho); load perdeu o parâmetro $fallbackReason; helper privado assertAnchorInUnit usado por load e onDelete; teste ganhou T14::$historyAllow e T14::$buildReason (d469fbb).
- [T-02] ToolRegistry::sameContext(ToolContext $context): bool disponível (compara tenantId, userId e unitId com o TenantContext do registry; unitId null no registry dá false). Construtor lança \LogicException('Duplicate tool name: <name>'). Erro de chave extra do JsonSchemaValidator agora é 'unexpected property: not allowed'.
- [T-12] FakeAiInteractionRepository ganhou failNextInsert(\Throwable $e) (lança uma vez, sem gravar); AiCopilotService::recordInteraction loga "interaction not recorded after the answer" com correlation_id antes de relançar (9a58512).
- [T-08] CopilotAgendaQueryInterface::professionalExists ganhou 2º parâmetro opcional `bool $includeInactive = false`; FakeCopilotAgendaQuery aceita seed('inactive_professionals') e grava args [id, includeInactive] em calls().
- [T-06] Nova trait `CentralVet\Ai\Tools\Clinical\ResolvesPatientInUnit` (patientId) usada por ObterPrescricoesTool/ObterExamesTool/ConsultarVacinacaoTool; ObterPrescricoesTool agora devolve freeTextFields ['via','posologia','duracao'] (relevante para T-20).
- [T-05] BuscarPacienteTool/ObterHistoricoAtendimentosTool pedem $limit+1 à consulta e marcam truncated real.
- [T-14] AiConversationRepository::insert e McpToolCallRepository::insert agora lançam TenantBoundaryViolation se unidade/usuário divergem do contexto (e, no mcp_tool_call, conversation_id inexistente no tenant): fixtures que gravam linha de outro usuário/unidade precisam de repositório com o contexto desse usuário/unidade.
- [T-15] AiConversationRetention::forConnection($pdo, $clock, $batchSize, $maxBatches) (>= 1); bin imprime {"skipped":"locked"} e sai 0 quando travado (flock em sys_get_temp_dir()/centralvet-ai-conversation-retention-<md5(__DIR__)>.lock) — para o runbook (T-24).

## Pendências
- Fora deste plano, só com autorização SQL expressa do usuário (skill `sql-write-approval`): aplicar `20261015_0015_phase8b_copilot.sql` (+ `.verify.sql`) e a DML do bloco 8B de `initial-application-programs.sql` no `centralvet` (dev) e, depois, em produção (pendências "Onda 5–10 SQL dev" e "0015 no dev" da 8B). Sem isso o smoke manual das telas reais não roda.
- Verificação visual manual: trilho do switch de `ai_copilot_management` (`.tswitch` + `cv-touch-target`, AiSettingsForm.php:46, cv-components.css:741) — conferir no navegador se o trilho fica centralizado na caixa de 44 px.
- Verificação manual: bloco 8B do seed no MySQL 5.7 (sem imagem 5.7 local; só inspeção).
- "Nenhum push" não verificável por remoto.
- T-01: docblocks de recordSuggestion/recordAnswer sem `@throws InvalidArgumentException` da guarda de finalidade (AiInteraction.php:177, :201).
- T-02: sem teste com duas chaves extras (JsonSchemaValidatorTest.php:88); docblock de sameContext cita "ToolPolicy denies otherwise", só vale com a T-20 (ToolRegistry.php:60).
- T-03: objeto vazio aninhado em input de tool_use volta como `[]` no replay; risco só para tool futura com schema aninhado (AnthropicMessagesGateway.php:131).
- T-04: isSummaryEntry aceita tool/label não escalares (Warning "Array to string conversion"); deveria descartar (AiConversationMessage.php:179).
- T-05: helper encounterRow() fora do arranjo de ClinicalToolsPatientTest.php:242; falhas restantes da SUITE são de ExamResultAiIntegrationTest (estado do banco de teste).
- T-06: trait ResolvesPatientInUnit sem contrato verificável de $this->query; ObterPrescricoesTool sem knownNames (tutorNames), inconsistente com ObterExamesTool.
- T-07: fixture tenant_b_encounter grava em unitA (tenant A), dado incoerente; montagem do DSN da 2ª conexão PDO duplicada de MysqlIntegrationTestCase::setUp.
- T-08: constante METHODS de FakeCopilotAgendaQuery mistura métodos e a chave de seed inactive_professionals (renomear, ex.: SEEDABLE).
- T-09: setUp com datas fixas dependentes de CURDATE() (lote de 2031-08-01 some a partir de 2031-08-02); abaixo_minimo/termo usam CURDATE() do MySQL e expiring o relógio da app: citar no runbook (T-24).
- T-10: no RED, dias_em_atraso=9 passaria sem a correção (usar vencimento 2031-03-10); otherUnitId() sem filtro por tenant em CopilotFinanceQueryIntegrationTest.php:292.
- T-11: ramo catch da closure de forContext (AiConfig inválido, false + error_log) e sua chamada a providerConfigured sem teste (AiCopilotAvailability.php:65).
- T-12: teste confere só a classe da exceção relançada e não assere a resposta gravada na conversa (AiCopilotServiceTest.php:644, :650).
- T-14: nenhum teste com repositórios reais grava mcp_tool_call numa pergunta de gestão; docblocks com linhas acima da largura em AiConversationRepository.php:21 e McpToolCallRepository.php:21.
- T-15: fopen que falha cai no ramo "locked" e sai 0 em silêncio; distinguir (error_log + exit 1) (ai-conversation-retention.php:24). Para o runbook (T-24): saída {"skipped":"locked"}.
- T-16: sem teste do RuntimeException na montagem → ai_provider_error nem de ai_quota_exceeded no pré-check; pergunta acima de MAX_QUESTION_CHARS ainda perde o rascunho (AiCopilotPanel.php:262).
- T-17: relatório cita 1444 entradas e o arquivo tem 1445 (sem violação; sem impacto).
- Validador: contagem no dev (SELECT COUNT somente-leitura) ai_interaction=0; mcp_tool_call inexistente (0015 não aplicada no dev); referência sem baseline anterior.

## Riscos
- T-14 pode quebrar fixtures que gravam conversa de outro usuário pelo repositório com o contexto errado → validação inclui as suítes de painel, listas, serviço e retenção; falha em arquivo alheio vai ao board.
- T-20 pode lançar em ferramenta que corte campo fora de `freeTextFields` → roda depois de T-05/T-06 e valida todas as suítes de ferramentas.
- SUITEs concorrentes na Onda 1 (7 agentes) → cada agente filtra pelas próprias classes; o gate roda a SUITE completa sozinho.

## Retomada
- Pasta: `.claude/tasks/mar-20261019-1612-fase-8b-pendencias/`
- Sessões: 661d3ed0-1c79-4f72-8f12-5a960468f359
- Branch de trabalho: task/fase-8b-pendencias (base: feat/fase-8b-copiloto-mcp)
- BASE da onda 1: 14139af
- Commits por onda:
  - Onda 1: BASE 14139af → HEAD 04ed023 (04ed023,ac80931,fd94ccc,3398e66,ffea790,cd8b614,1189d25,c1a5b4c,57f2c51,ebf8ede,dc2862d,7fcd26a,9a58512,d469fbb,efc0f18,91c83c9,ea33874,db330c4,be0025f,fcce474,d4e8e1f,be5f03d,970d815,b360d08,d740c20,1430b40,b8e4e6c,86031be,e3d147f,82c75f6,3175920,361a20b,0b0da3f)
- Último status conhecido: onda 1 concluída; T-01..T-19 [x] (SUITE 1750/1750, lint ok, revisões sem bloqueantes); T-20..T-24 [ ]
- Próxima onda recomendada: onda 2 — T-20, T-21 (T-16 pronta), T-22; depois T-23 e T-24
