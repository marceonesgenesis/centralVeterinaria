# Revisão final
Escopo: bc35d6d..HEAD (b2464f4), branch feat/fase-8b-copiloto-mcp, base main. Spec docs/superpowers/specs/2026-10-15-fase-8b-copiloto-mcp-design.md; aceite 8B.1/8B.2 do plan.md.
Verificado: AiCopilotService, ToolPolicy, ToolRegistry, ToolContext/Family, JsonSchemaValidator, 7 ferramentas clínicas, 5 de gestão (execute/assertInScope), CopilotClinical/Agenda/Stock/FinanceQuery, AiConversationRepository, McpToolCallRepository, AiToolAuditService, AiConversationHistoryService, AiConversationRetention + bin, migration 0015, AiCopilotPanel, AiConversationList, AiToolCallList, cv-ai-copilot.js, diffs de gateway/request/purpose/interaction/prompt/settings/PatientForm/EncounterView/menu. `git diff bc35d6d -- composer.json framework_hashes.php custom.css` = 0 linhas; CSS novo só com text-align:left.
Não verificado: SUITE não rodada nesta revisão (vale o gate da onda 7, 1676/1676); regex do PiiMinimizer para fragmentos; seed SQL e compatibilidade 5.7; runbooks/ADR (T-18, T-24); FakeAiConversationRepository/T-05; jsonObjects do gateway (T-02); CSS visual do switch (T-22).

## Triagem
- [resolvida] T-19..T-21 marcação [x] espúria na onda 1 → tasks concluídas na onda 6 (notes.md Retomada)
- [aberta] T-03 recordAnswer/recordSuggestion sem guarda por isCopilot; falta teste de decide() em answered → AiInteraction.php (recordAnswer só checa assertAwaitingProvider); AiDomainTest.php:101-111 testa só recordAnswer
- [aberta] T-04 validador: schema agora validado no início (assertSupported), mas chave extra ecoa o nome e registry aceita nome duplicado → JsonSchemaValidator.php ("not allowed"), ToolRegistry.php construtor
- [aberta] T-07 contagem 32/33 e formato en/pt sem teste
- [aberta] T-02 jsonObjects troca [] por {} em qualquer profundidade; falta teste messages sem tools com tool_use → AnthropicMessagesGateway.php jsonObjects (não reavaliado)
- [aberta] T-01 .verify.sql sem índices auxiliares; RESTRICT da âncora (paciente com conversa não pode ser excluído) → 0015.sql ai_conversation_anchor_patient_fk
- [aberta] T-05 teste com + e reconstitute frágil (não reavaliado)
- [aberta] T-06 superseded estreito; draft como ativa tratado por ruling T-11 (provisorio=true); fixture otherUnitId
- [aberta] 0015 no centralvet (dev) depende de aprovação SQL; "nenhum push" não verificável por remoto
- [aberta] T-08 rate limit depois do escopo (fixado pela spec) → ToolPolicy.php:93-95
- [aberta] T-09 search tenant-wide (confirmado intencional em AiToolAuditService, requiresUnitScope:false); insert não confere unidade/usuário → McpToolCallRepository.php search/insert
- [aberta] T-10 truncated sempre false em buscar_paciente/histórico → BuscarPacienteTool execute (ToolResult($data,false)), ObterHistoricoAtendimentosTool execute
- [aberta] T-11 via/posologia/duracao fora de freeTextFields → ObterPrescricoesTool execute (ToolResult sem freeTextFields)
- [aberta] T-12 updated_at×created_at, MAX_BATCHES sem teste, sem trava de sobreposição
- [aberta] T-13 10º parâmetro ?PiiMinimizer diverge da Interface (ruling); relays de cota/limiter cumpridos
- [promovida] T-14 "onDelete com painel indisponível mostra 'Conversa não encontrada'": o conjunto da branch (AiConversationList abre pela cortina) torna o histórico do autor inacessível com IA indisponível → ver Achado 1; demais itens da T-14 (GET sem CSRF, espaços, foco) seguem abertos
- [aberta] T-15 teste de negação fraco; coluna Motivo com código cru; sem teste de XSS → AiToolCallList.php table (denyReason cru)
- [resolvida] Onda 4 menu/permissões de AiConversationList e AiToolCallList → menu.xml +3 itens e bloco 8B do seed (T-17)
- [aberta] T-16 link sem RBAC de onOpen; docblock órfão PatientForm.php:720-725; markup duplicado; error_log por render
- [aberta] T-17 seed só no grupo 1; 5.7 por inspeção
- [aberta] T-18 runbook 5.7 ambíguo; referência de códigos ai_* (não verificado)
- [aberta] Onda 5 SQL dev pendente de aprovação
- [aberta] T-19 verificar_horarios sem profissional_id corta em 50 e inclui todo usuário ativo do tenant → VerificarHorariosTool.php:109 (code-review item 5)
- [aberta] T-20 escape LIKE/expiring sem bordas/lote vencido
- [aberta] T-21 fuso, vencimento no dia, ticket_medio com receita sem atendimento
- [aberta] T-22 trilho do switch; flag provada só por fake
- [aberta] Onda 6 SQL dev pendente
- [aberta] T-23 nenhum teste falha se ManagementToolset sair de forContext (o teste de gestão com stub não chama ferramenta) → AiCopilotServiceForContextIntegrationTest.php:111
- [aberta] T-24 runbook linha 391 (ai_assistant também exigida) (não verificado)
- [aberta] Onda 7 SQL dev pendente
## Rulings
- plano · onda 0 — branch feat/fase-8b-copiloto-mcp a partir da 8A, base main, sem push
- plano · onda 0 — uma linha ai_interaction por pergunta, status answered, subject ai_conversation, 6 CHECKs ampliados
- plano · onda 0 — finalidades em AiPurpose::COPILOT fora de ALL; hostAction AiCopilotPanel::onAsk
- plano · onda 0 — requiredPermission por ferramenta = ação da tela (PrescriptionForm/ExamResultForm::__construct)
- plano · onda 0 — paciente da unidade = encounter ou appointment na unidade; fora = não encontrado
- plano · onda 0 — sem nº de prontuário/condições crônicas/motivo; prescrição ativa = validade ou 30 dias
- plano · onda 0 — vencimento do receivable = created_at + 7 dias
- plano · onda 0 — painel de gestão pelo item "Copiloto de gestão"
- plano · onda 0 — 4 respostas do usuário confirmam as premissas
- plano · onda 0 — timeout 5 s por hint MAX_EXECUTION_TIME + descarte na ToolPolicy
- plano · onda 0 — mcp_tool_call.conversation_id sem FK; âncora com FK (depois RESTRICT, T-01)
- plano · onda 0 — flag de gestão indisponível → ai_disabled; constante na onda 1
- plano · onda 0 — rótulos "Consultei" via AiTool::label, fora do translations.json
- T-02, T-03, T-04 · onda 1 — RED com arquivos extras de teste/suporte aceito
- T-05 · onda 1 — isFailed() em vez de failed()
- T-01 · onda 1 — 0015 aplicada no centralvet_test após correção 73f950e (âncora RESTRICT)
- T-01 · onda 1 — 3 FKs RESTRICT extras aceitas
- T-06 · onda 1 — superseded só na mesma unidade
- T-04 · onda 1 — validate static; fake de flags por lista
- T-07 · onda 1 — 32 chaves; ordenação exigida
- T-08, T-10, T-11 · onda 2 — fail-closed concentrado na ToolPolicy (toda exceção vira denied auditado)
- T-08 · onda 2 — 6º parâmetro ?PiiMinimizer null; rate limit depois do escopo
- T-10 · onda 2 — default de limite no código; alergias texto livre
- T-11 · onda 2 — sem patient_id → ToolInputException; draft com provisorio=true
- T-12 · onda 2 — batches só com exclusão; erro → error_log sem JSON
- T-13 · onda 3 — RuntimeException do limiter sem Redis aceita; painel trata como indisponível
- T-13 · onda 3 — chamada final com as mesmas tools e tool_choice none
- T-13 · onda 3 — 10º parâmetro ?PiiMinimizer null
- T-13 · onda 3 — orçamento antes das ferramentas; cota uma vez por pergunta
- T-15 · onda 4 — histórico (listar/abrir/apagar) independente de gateway/limiter/config (AiConversationHistoryService) — abrir não cumprido, ver Achado 1
- T-15 · onda 4 — teste de conversa da unidade anterior sem RED aceito
- T-15 · onda 4 — menu/permissões para a T-17
- T-16 · onda 5 — link sem conferir permissão de onOpen (sugestão)
- T-17 · onda 5 — id do vínculo = MAX(id)+posição; DML não aplicada no dev
- T-18 · onda 5 — runbook cita RATE_LIMIT_DRIVER=files, cota, cron, 0015, ADR
- onda 5 — 8B.1 completa
- T-19..T-22 · onda 6 — RED com fakes/interface extras aceito
- T-19 · onda 6 — busy() em todo o tenant
- T-21 · onda 6 — receita por SQL próprio
- T-22 · onda 6 — flag independente de ai_enabled; cv-touch-target no switch
- T-23 · onda 6 — registro das ferramentas de gestão com a T-23
- T-23 · onda 7 — teste extra no arquivo da T-13; seed inalterado
- T-24 · onda 7 — cruzada válida após correção só de docs (185bbdd)
## Achados
- [bloqueante] Histórico do autor inacessível com IA indisponível: AiCopilotPanel::load() devolve o motivo antes de carregar a conversa (AiCopilotPanel.php:235-240), e "Abrir" de Minhas conversas passa por essa cortina (AiConversationList openLink); com cota estourada/flag off/termo pendente/Redis fora (forContext, AiCopilotService.php:141-168) o autor não lê nenhuma conversa, e a resposta da pergunta que estoura a cota é gravada mas não exibida; se a IA ficar desligada > 90 dias a retenção apaga sem o autor ter acesso. Contradiz o ruling T-15 ("abrir não depende de gateway/limiter/config") → AiCopilotPanel.php:235, :168
- [bloqueante] PII ao provedor: texto cortado antes da minimização — ToolFormat::excerpt corta motivo (160) e resultado (400) dentro de execute (ObterHistoricoAtendimentosTool.php:100, ObterExamesTool.php:103) e só depois ToolPolicy::content aplica PiiMinimizer (ToolPolicy.php:179-187); um telefone/CPF ou o nome completo do tutor partidos no corte deixam de casar com o padrão/knownNames e seguem ao modelo. Minimizar antes de cortar. (Regex do PiiMinimizer para fragmentos não verificada.)
- [sugestão] Laço inteiro (até 45 s e 5 chamadas) dentro de uma transação 'permission'; falha tardia em load() faz rollback de ai_conversation, ai_interaction e mcp_tool_call (auditoria e pergunta perdidas) → AiCopilotPanel.php:168
- [sugestão] Resposta do assistente gravada sem PiiMinimizer, contra spec § 3.5 ("content já minimizado") → AiCopilotService.php:476
- [sugestão] Aceite 8B.1: AiCopilotServiceForContextIntegrationTest não assere linhas de mcp_tool_call com o correlation_id da ai_interaction (só o teste unitário com fakes, AiCopilotServiceTest.php:341) → AiCopilotServiceForContextIntegrationTest.php:64-100
- [sugestão] Exclusão por GET sem CSRF (AiCopilotPanel onDelete e AiConversationList onDelete com delete=1); padrão pré-existente (ServiceList::onDelete) → AiConversationList.php onDelete
- [sugestão] ToolRegistry::find refaz visible() (RBAC + auditoria de decisão) a cada chamada e no countTool → ToolRegistry.php find; AiCopilotService.php:514
- [sugestão] Botões das telas hospedeiras montam forContext completo (gateway, Redis, registry) a cada render → PatientForm.php:732, EncounterView.php copilotAvailable
- [sugestão] Duplicações: patientId() nas 7 ferramentas, copilotLink em PatientForm/EncounterView, conversas em AiCopilotService e AiConversationHistoryService

# Revisão final — Rodada 2
Escopo: bc35d6d..HEAD (e3198f0); correção = b2464f4..38b459f (ondas 8–10, T-25..T-29). Verificado por leitura do diff: ToolPolicy/ToolResult/ToolRegistry, ObterExames/ObterHistoricoAtendimentos, AiCopilotPanel, AiConversationList, AiConversationHistoryService, AiCopilotAvailability (estrutura), AiCopilotService (resposta minimizada, finally), CopilotAgendaQuery::schedulableProfessionals, PatientForm/EncounterView; nomes dos testes novos. `grep ToolFormat::excerpt src/app/Core/Ai/Tools` = só ToolPolicy.php:171 (depois do minimize).
Não verificado: SUITE não rodada nesta rodada (vale o gate da onda 10, 1704/1704); AiCopilotAvailability linha a linha; FreeSlotFinder::spread; VerificarHorariosTool; JS do confirm.

## Verificação dos itens da Rodada 1
- [resolvida] (promovida T-14 / Achado 1) histórico inacessível com IA indisponível → AiCopilotPanel::load lê pelo AiConversationHistoryService (RBAC onOpen, findOwned por autor/unidade/tenant) e só depois consulta AiCopilotAvailability, que apenas troca o formulário pelo motivo (renderPanel mostra conversationView quando há mensagens); onAsk que estoura a cota renderiza a conversa pelo id devolvido; testes testDisabledAiOpensTheConversationWithTheReasonInPlaceOfTheForm, testAskThatReachesTheQuotaShowsTheStoredAnswerAndTheQuotaReason, testConversationOpensWithTheAiDisabled
- [resolvida] (bloqueante Achado 2) PII cortada antes do minimizador → ferramentas devolvem texto integral e ToolResult::$excerptLengths; ToolPolicy::content minimiza e só então corta (ToolPolicy.php:161-174); testFreeTextIsMinimizedBeforeTheExcerptCut

## Triagem
- [resolvida] T-19..T-21 marcação [x] espúria → corrigida na onda 1
- [resolvida] T-14 onDelete com painel indisponível mostra "Conversa não encontrada" → load não depende mais da disponibilidade
- [resolvida] Onda 4 menu/permissões → T-17
- [resolvida] T-19 verificar_horarios corta em 50 com todo usuário do tenant → T-27 schedulableProfessionals (tenant + unidade ativa, CopilotAgendaQuery.php) e limite repartido
- [resolvida] Onda 9 AiCopilotPanel.php:168 transação em volta do ask → onAsk em connection() autocommit (perform $autocommit)
- [resolvida] Fechamento: 29 tasks [x], bloqueantes da rodada 1 fechados (confirmado acima)
- [resolvida] Rodada 1 Achado 2 (PII antes do corte) → ver acima
- [aberta] T-03 recordAnswer/recordSuggestion sem guarda isCopilot; decide() em answered sem teste
- [aberta] T-04 chave extra ecoa o nome; nome duplicado agora ignorado em silêncio no ToolRegistry::entry (isset($tools[$name]))
- [aberta] T-07 contagem 32/33 e formato en/pt sem teste
- [aberta] T-02 jsonObjects em qualquer profundidade
- [aberta] T-01 .verify.sql sem índices auxiliares; RESTRICT da âncora
- [aberta] T-05 teste com + e reconstitute frágil
- [aberta] T-06 superseded estreito; fixture otherUnitId
- [aberta] 0015 no dev depende de aprovação SQL; "nenhum push" não verificável
- [aberta] T-08 rate limit depois do escopo (spec)
- [aberta] T-09 insert não confere unidade/usuário; search tenant-wide intencional
- [aberta] T-10 truncated sempre false em buscar_paciente/histórico
- [aberta] T-11 via/posologia/duracao fora de freeTextFields
- [aberta] T-12 updated_at×created_at, MAX_BATCHES sem teste, sem trava de sobreposição
- [aberta] T-13 10º parâmetro ?PiiMinimizer (ruling)
- [aberta] T-14 demais: pergunta só com espaços, foco/live region, asserção do JS por str_contains; exclusão agora só por POST, ainda sem token CSRF (padrão do projeto)
- [aberta] T-15 teste de negação fraco; Motivo com código cru; sem teste de XSS
- [aberta] T-16 link sem RBAC de onOpen; docblock órfão; markup duplicado (excluído das ondas 8–10)
- [aberta] T-17 seed só no grupo 1; 5.7 por inspeção
- [aberta] T-18 runbook 5.7 ambíguo; referência ai_*
- [aberta] Onda 5 SQL dev
- [aberta] T-19 profissional inativo vira not found em consultar_agenda
- [aberta] T-20 escape LIKE/expiring/lote vencido
- [aberta] T-21 fuso, vencimento no dia, ticket_medio
- [aberta] T-22 trilho do switch; flag provada por fake
- [aberta] Onda 6 SQL dev
- [aberta] T-23 nenhum teste falha se ManagementToolset sair de forContext
- [aberta] T-24 runbook linha 391
- [aberta] Onda 7 SQL dev
- [aberta] T-25 excerptLengths ⊆ freeTextFields não validado; ToolPolicy não confere ToolContext contra o contexto do registry (mesmo TenantContext em forContext); forbidden×not_offered
- [aberta] T-26 AiCopilotAvailability::reason duplica availability (AiCopilotService agora delega a ela)
- [aberta] T-27 fronteira de 90 dias/dia seguinte; outro tenant sem asserção
- [aberta] Onda 8 SQL dev
- [aberta] T-28 ai_interaction inserida depois de appendMessage+touch
- [aberta] T-28 RED do cenário 3 já passava
- [aberta] Onda 9 SQL dev
- [aberta] T-29 RBAC real do painel não exercido; pergunta perdida quando makeService lança AiUnavailableException antes de gravar (AiCopilotPanel onAsk → load sem conversa); onDelete apaga antes de checar âncora (só conversa do próprio autor: sem vazamento)
- [aberta] Onda 10 SQL dev
## Rulings
- Rulings da Rodada 1 (plano · onda 0 até T-24 · onda 7) mantidos; T-15 · onda 4 (histórico independente de IA) agora cumprido no painel
- plano · onda 8 — ondas de correção 8–10: T-25 PII antes do corte e visibilidade em cache, T-26 disponibilidade leve, T-27 horários livres, T-28 histórico sem IA/resposta minimizada/finally, T-29 painel sem IA/autocommit/POST; duplicações patientId/copilotLink fora
- plano · onda 8 — T-27 elegível = usuário ativo do tenant com appointment na unidade em 90 dias ou no dia; fallback à lista da agenda; 50 repartidos
- usuário · onda 8 — regra de horários livres confirmada
- T-25 · onda 8 — wiring em AiCopilotPanelIntegrationTest aceito; ?PiiMinimizer=null; registered(); sem RBAC em offeredNames → forbidden; RED sem Co-Authored-By
- T-25 · onda 8 — teste de minimização antes do corte fortalecido (plano-mandou)
- T-28 · onda 9 — fixture ANSWER do painel ajustada pela T-28
- T-28 · onda 9 — interação em finally após a mensagem; uso sempre contado
- T-28 · onda 9 — conversations() com filtros family/anchor
- T-29 · onda 10 — window.confirm no lugar de TQuestion
- T-29 · onda 10 — makeService com ?PDO; teste de autocommit por "2 mensagens gravadas"
- T-29 · onda 10 — onAsk relança AuthorizationDenied/MissingTenantContext/TenantBoundaryViolation/PDO; pergunta preservada em campo somente leitura
## Achados
- [sugestão] Sugestões da Rodada 1 resolvidas: transação longa (autocommit), resposta sem PiiMinimizer (AiCopilotService minimiza antes de gravar), correlation_id na integração (testToolCallsAndTheInteractionShareTheCorrelationId), find refazendo RBAC (cache no ToolRegistry), forContext nos botões (AiCopilotAvailability); abertas: duplicações (excluídas pelo plano), CSRF sem token
- [sugestão] Docblock de ToolResult diz que knownNames são nomes que a minimização "may keep", mas PiiMinimizer::minimize os mascara → ToolResult.php:9-14, PiiMinimizer.php:136-139
- [sugestão] Painel com IA indisponível e conversa sem mensagens esconde também a área de conversa; ok, mas onAsk com AiUnavailableException na montagem perde o texto digitado (difere do caminho de negação, que preserva) → AiCopilotPanel.php onAsk
- nenhum bloqueante
