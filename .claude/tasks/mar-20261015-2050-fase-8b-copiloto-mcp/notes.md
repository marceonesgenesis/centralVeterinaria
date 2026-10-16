# Notas de execução

## Decisões tomadas
- 2026-10-15 · plano · onda 0 — Branch `feat/fase-8b-copiloto-mcp` (já em checkout, de `feat/fase-8a-ai-gateway` @ `2a77bef`) e base `main`, por pedido explícito do desenvolvedor nas premissas: a 8B depende da 8A ainda não mesclada. Sem push.
- 2026-10-15 · plano · onda 0 — Uma linha de `ai_interaction` por pergunta do copiloto, com status novo `answered`, `subject_type = ai_conversation`, `subject_id` = conversa, uso e custo somados das rodadas e `suggestion_text`/`final_text` nulos. A 0015 amplia os 6 CHECKs de `ai_interaction` (purpose, subject, status, failed, decided, final). Motivo: a cota e o custo da 8A valem para o copiloto sem guardar texto fora da retenção de 90 dias.
- 2026-10-15 · plano · onda 0 — Finalidades do copiloto em `AiPurpose::COPILOT`, fora de `AiPurpose::ALL` (o `match` exaustivo de `AiPromptCatalog::build` e `AiDomainTest:28` dependem de `ALL`). `hostAction` do copiloto = `AiCopilotPanel::onAsk`.
- 2026-10-15 · plano · onda 0 — `requiredPermission` por ferramenta (ação da tela que mostra o mesmo dado): `buscar_paciente` → `GlobalSearchController::onSearch`; `obter_paciente` → `PatientForm::onEdit`; `obter_historico_atendimentos` e `obter_prontuario` → `EncounterView::onReload`; `obter_prescricoes` → `PrescriptionForm::__construct` (o histórico de prescrições é montado no construtor; a tela não tem `onReload`); `obter_exames` → `ExamResultForm::__construct` (tela do resultado; sem `onReload`); `consultar_vacinacao` → `VaccinationCardView::onReload`; `consultar_agenda` e `verificar_horarios` → `AgendaView::onReload`; `consultar_estoque` → `ProductList::onReload`; `listar_debitos` → `PendingReceivableList::onReload`; `indicadores_clinica` → `FinancialOverview::onFilter`. O `AdiantiProgramPermissionProvider` libera pela classe e só nega método marcado `false`, então `__construct` é aceito pelo `ACTION_PATTERN`.
- 2026-10-15 · plano · onda 0 — Paciente "da unidade" = tem `encounter` ou `appointment` na unidade ativa (`patient` não tem `system_unit_id`); registros clínicos só de atendimentos da unidade. Id fora disso = "não encontrado". Confirmado pelo usuário em 2026-10-15.
- 2026-10-15 · plano · onda 0 — Sem colunas de nº de prontuário (usa `patient.id`), condições crônicas (campo omitido) e motivo (início da anamnese, 160 caracteres). Prescrição "ativa" = `valid_until >= hoje` ou `valid_until` nulo com criação nos últimos 30 dias.
- 2026-10-15 · plano · onda 0 — Débitos: vencimento = `receivable.created_at + 7 dias` (regra de `PendingItemQuery::openReceivables`); o schema não tem vencimento. Confirmado pelo usuário em 2026-10-15.
- 2026-10-15 · plano · onda 0 — Painel de gestão abre pelo item "Copiloto de gestão" do submenu de IA (não existe dashboard clínico; `menu.xml:10-13` aponta para `CvShellController::onComingSoon`). Confirmado pelo usuário em 2026-10-15.
- 2026-10-15 · plano · onda 0 — Decisões do usuário (respostas às 4 perguntas do planejador): (1) paciente da unidade = com atendimento ou agendamento na unidade, devolvendo só registros clínicos dessa unidade; (2) vencimento do `receivable` = `created_at` + 7 dias, como a tela de pendências; (3) o painel de gestão abre pelo item "Copiloto de gestão" no submenu de IA; (4) `requiredPermission` de prescrições e exames = `PrescriptionForm::__construct` e `ExamResultForm::__construct`. Nenhuma task muda: o plano já seguia essas premissas.
- 2026-10-15 · plano · onda 0 — Timeout de 5 s por ferramenta: hint `/*+ MAX_EXECUTION_TIME(5000) */` nas SELECTs novas (MySQL 5.7.8+ e 8) e descarte pela `ToolPolicy` acima de 5000 ms (`deny_reason = timeout`); sem `pcntl`.
- 2026-10-15 · plano · onda 0 — `mcp_tool_call.conversation_id` sem FK (a retenção apaga conversas; a auditoria fica). `ai_conversation.anchor_patient_id` com FK `ON DELETE SET NULL` (não trava a exclusão de paciente).
- 2026-10-15 · plano · onda 0 — Flag de gestão indisponível → motivo `ai_disabled` (sem código `ai_*` novo, que exigiria tradução e `UserMessage`). A constante `AI_COPILOT_MANAGEMENT` nasce na Onda 1 (T-04); sem linha em `feature_flag`, fica desligada até a 8B.2.
- 2026-10-15 · plano · onda 0 — Nomes das ferramentas no "Consultei: …" vêm de `AiTool::label($lang)` (pt/en no Core) e ficam gravados em `tool_summary`; não entram no `translations.json`.
- 2026-10-15 · T-02, T-03, T-04 · onda 1 — RED inválido por arquivos de teste/suporte extras (StubAiGatewayScriptTest, AiPromptCatalogTest, FakeAiTool, JsonSchemaValidatorTest): aceito, estão em Arquivos prováveis e são testes/suportes.
- 2026-10-15 · T-05 · onda 1 — Getter `AiConversationMessage::isFailed()` em vez de `failed()` (PHP não permite estático e instância com o mesmo nome); o estático `failed(...)` é o que a T-13 consome.
- 2026-10-15 · T-01 · onda 1 — 0015 aplicada no centralvet_test com autorização do usuário: 1ª tentativa falhou (ERROR 3823, CHECK × FK SET NULL, nada aplicado); correção 73f950e (FK do paciente-âncora ON DELETE RESTRICT); 2ª aplicação ok (checksum a618bd14…, verify ok, ai_interaction 0→0). Banco de dev centralvet não tocado.
- 2026-10-15 · T-01 · onda 1 — 3 FKs RESTRICT extras (tenant/unidade/usuário) além da Interface: aceito.
- 2026-10-15 · T-06 · onda 1 — `superseded` de vacina só conta reforço aplicado na mesma unidade: aceito.
- 2026-10-15 · T-04 · onda 1 — `validate` é static; `FakeFeatureFlagRepository` recebe lista de flags ligadas: aceito.
- 2026-10-15 · T-07 · onda 1 — 32 chaves (spec dizia 33); ordenação do translations.json passou a ser exigida: T-22 deve inserir na posição ordenada.
- 2026-10-15 · T-08, T-10, T-11 · onda 2 — Fail-closed corrigido só na ToolPolicy: toda exceção vira denied auditado (invalid_input/not_found/rate_limited/error); falha do limiter nega; falha da auditoria ainda nega e loga. T-10 e T-11 sem mudança de código. Sugestões aplicadas junto: limiter atômico por INCR, teto 4096 bytes em input_minimized, corte UTF-8 seguro do nome.
- 2026-10-15 · T-08 · onda 2 — 6º parâmetro de ToolPolicy = ?PiiMinimizer null (construtor privado; usa estático); rate limit mantido depois do escopo, como fixa a spec.
- 2026-10-15 · T-10 · onda 2 — Default de `limite` no código; `alergias` texto livre.
- 2026-10-15 · T-11 · onda 2 — Sem patient_id e sem âncora → ToolInputException (negada pela política); só obter_exames passa tutorNames como knownNames; prescrição draft em "ativas" com provisorio=true; teste ajustado no commit de implementação sem mudar asserções.
- 2026-10-15 · T-12 · onda 2 — `batches` conta só lotes com exclusão; erro → error_log sem JSON.
- 2026-10-15 · T-13 · onda 3 — RuntimeException do ToolRateLimiter com driver redis indisponível: aceito; painel (T-14) trata como "indisponível"; runbook (T-18) cita RATE_LIMIT_DRIVER=files para hospedagem compartilhada.
- 2026-10-15 · T-13 · onda 3 — Chamada final sem tools com blocos tool_use no histórico dá 400 na API: corrigido com as mesmas tools + tool_choice none; stub rejeita tool_use sem tools como a API; tool_use residual vira texto "não consegui concluir". Escopo nos arquivos da T-02 autorizado.
- 2026-10-15 · T-13 · onda 3 — 10º parâmetro de AiCopilotService = ?PiiMinimizer = null.
- 2026-10-15 · T-13 · onda 3 — Sugestões aplicadas: orçamento checado antes das ferramentas; custo somado assertado. Cota checada uma vez por pergunta → documentar no runbook (T-18).
- 2026-10-15 · T-15 · onda 4 — Histórico (listar/abrir/apagar) não depende de gateway/limiter/config de IA (direito do autor de apagar os próprios dados): AiConversationHistoryService, arquivo novo autorizado fora de Arquivos prováveis.
- 2026-10-15 · T-15 · onda 4 — Teste de onOpen de conversa da unidade anterior/outro usuário → "Conversa não encontrada" sem mensagens: commit de cobertura sem RED aceito (passa contra o código atual); fixture liga a IA e teardown limpa.
- 2026-10-15 · T-15 · onda 4 — Menu e permissões de AiConversationList e AiToolCallList ficam para a T-17 (seed).
- 2026-10-15 · T-16 · onda 5 — Link "Copiloto" aparece sem conferir a permissão de `AiCopilotPanel::onOpen` (o painel nega ao abrir); sugestão, não bloqueante.
- 2026-10-15 · T-17 · onda 5 — Id do vínculo no seed 8B = MAX(id) + posição do grupo; teste extra roda o bloco em transação desfeita. A DML do bloco 8B não foi aplicada no centralvet (dev): depende de aprovação SQL do usuário.
- 2026-10-15 · T-18 · onda 5 — Runbook cita RATE_LIMIT_DRIVER=files, cota por pergunta, cron de retenção, 0015 e ADR 0007 (relays das ondas 3 e 4 cumpridos).
- 2026-10-15 · onda 5 — Fase 8B.1 (copiloto clínico) completa: T-01..T-18 [x]; resta a 8B.2 (T-19..T-24).
- 2026-10-15 · T-19..T-22 · onda 6 — RED inválido por fakes/interface/testes de integração extras no commit RED: aceito (arquivos de teste/contrato em Arquivos prováveis).
- 2026-10-15 · T-19 · onda 6 — `busy()` bloqueia o profissional em todo o tenant, como `assertNoConflict`: aceito.
- 2026-10-15 · T-21 · onda 6 — receita por SQL próprio com hint de 5 s, conferida contra `totalsByType`: aceito.
- 2026-10-15 · T-22 · onda 6 — flag `ai_copilot_management` independente de `ai_enabled`; `cv-touch-target` no rótulo do switch; gravação provada por fake: aceito.
- 2026-10-15 · T-23 · onda 6 — registro das ferramentas de gestão no `ManagementToolset` fica com a T-23.
- 2026-10-15 · T-23 · onda 7 — teste extra em AiCopilotServiceForContextIntegrationTest.php (arquivo da T-13) aceito; RED = ManagementToolsetTest + teste do menu; seed inalterado (AiCopilotPanel já concedido no bloco 8B).
- 2026-10-15 · T-24 · onda 7 — cruzada do gate continua válida após a correção posterior só de docs (185bbdd).

- 2026-10-15 · plano · onda 8 — Ondas de correção 8–10 (revisão final + `/code-review` de `bc35d6d..b2464f4`, HEAD `b2464f4`): T-25 (PII minimizada antes do corte via `ToolResult::excerptLengths`; visibilidade em cache no `ToolRegistry`, `ToolPolicy` sem `AuthorizationPolicyInterface`), T-26 (`AiCopilotAvailability` leve nas telas hospedeiras), T-27 (horários livres), T-28 (histórico via `AiConversationHistoryService`, resposta minimizada, `ai_interaction` em `finally`, serviço sem transação), T-29 (painel/lista: leitura sem IA, resposta gravada exibida, `onAsk` em autocommit, exclusão só por POST). Fora: sugestões de duplicação do helper `patientId` e do `copilotLink`/docblock em `PatientForm`.
- 2026-10-15 · plano · onda 8 — T-27: o schema não marca papel clínico e a agenda (`AgendaView::listProfessionals`) usa todo usuário ativo do tenant. Profissional elegível para `verificar_horarios` sem `profissional_id` = usuário ativo do tenant com `appointment` na unidade ativa nos 90 dias antes do dia pedido ou no próprio dia (sem nenhum, volta à lista da agenda); limite de 50 repartido igualmente entre os profissionais, com horários espaçados no dia.
- 2026-10-16 · onda 8 · usuário — Regra de horários livres confirmada: elegível = usuário ativo do tenant com agendamento na unidade nos 90 dias anteriores ou no dia; fallback para a lista da agenda; 50 linhas repartidas por profissional ao longo do dia.
- 2026-10-16 · T-25 · onda 8 — linha de wiring em AiCopilotPanelIntegrationTest.php (fora da lista) aceita; ?PiiMinimizer=null mantido; ToolRegistry::registered() novo; ferramenta sem RBAC em offeredNames → forbidden; commit RED 189c6a1 sem Co-Authored-By (cosmético).
- 2026-10-16 · T-25 · onda 8 (rodada 1, plano-mandou) — teste de minimização antes do corte fortalecido (nenhum dígito do telefone/CPF), provado falhando com a ordem invertida.

## Bloqueios
- Gate entre a Onda 1 e a Onda 2: aplicar `20261015_0015_phase8b_copilot.sql` no `centralvet_test` (orquestrador, skill `sql-write-approval`, usuário de migration, cópia temporária com o checksum real, `.verify.sql` depois). Desbloqueia quando o verify do `centralvet_test` for anotado aqui (3 tabelas novas vazias; contagem de `ai_interaction` igual à de antes).
- Fora do plano, só com aprovação SQL do usuário: 0015 no `centralvet` (dev) e a DML do bloco 8B do seed (T-17). Sem isso, o smoke manual nas telas reais não roda.

## Descobertas
- Onda 1 (board): `AnthropicMessagesGateway::parse` só aceita `tool_use` com `tools` na requisição (T-02); `StubAiGateway::scripted()/requests()`, `AiUsage::plus`; `JsonSchemaValidator::validate` static; `AiPurpose::COPILOT*`, `AiInteraction::recordAnswer`; `isFailed()` (T-05); `CopilotClinicalQueryInterface::EXAMS_*`; translations.json ordenado por `en` (32 chaves); `ai_conversation.anchor_patient_id` FK RESTRICT (T-01).
- `AnthropicMessagesGateway::parse` trata `stop_reason: tool_use` como `ai_provider_error` (:101-150); a T-02 só aceita `tool_use` quando a requisição tem `tools`.
- `AiNavigationIntegrationTest.php:65` fixa os filhos do submenu de IA; T-17 e T-23 atualizam o teste.
- Hash de `custom.css` e `layout.html` já diverge em `framework_hashes.php` (pré-existente); nada da 8B toca esses arquivos.
- `scripts/test-db/provision.sh` recusa banco existente: a 0015 entra no `centralvet_test` pelo gate, não por reprovisionamento.
- Onda 2 (board): `ToolPolicy::execute` nunca lança (exceções viram denied auditado; MAX_INPUT_BYTES=4096; `ToolRateLimiter::attempt` atômico); `PiiMinimizer` sem instância, T-13 passa `null` no 6º parâmetro; repositórios `(TenantContext, PDO)` com `insert` checando só tenant, `touch` alheio no-op; ferramentas clínicas `(CopilotClinicalQueryInterface, ?Closure $clock)`; `AiConversationRetention::forConnection(PDO, ?Closure)->runOnce()` → {deleted, batches}.
- Onda 3 (board): T-13 `AiCopilotService` com 10º parâmetro `?PiiMinimizer` (passe null); `CopilotAnswer.text=''` se failed; `forContext` conecta no Redis e lança RuntimeException sem Redis; `AiCompletionRequest` 7º parâmetro `?array $toolChoice`; `StubAiGateway` lança ai_provider_error (400) para tool_use/tool_result sem tools; chamada final com as mesmas tools e tool_choice none; cota checada uma vez por pergunta (até 5 chamadas).
- Onda 4 (board): T-14 `AiCopilotPanel::linkFor($family, ?$anchor)`; `transaction(callable $fn)` passa a conexão; link abre a cortina via generator; RuntimeException do limiter = painel indisponível (relay da onda 3 cumprido). T-15 `AiConversationList` usa `AiCopilotPanel::linkFor(...).'&conversation_id=<id>'`; histórico via `AiConversationHistoryService::forContext(TenantContext, PDO)` sem gateway/limiter/flag/termo/cota.
- Onda 5 (board): T-16 link `data-cv-copilot="clinical"` em PatientForm (só edição) e EncounterView, some sem IA disponível; T-17 submenu de IA com 4 filhos e bloco 8B do seed (id = MAX(id)+posição; T-23 atualiza `Assert::same` de 4 filhos e usa o mesmo marcador).
- Onda 6 (board): T-19 `busy()` em todo o tenant, `FreeSlotFinder` exige início > agora; T-20 `ConsultarEstoqueTool::FILTER_*`; T-21 `CopilotFinanceQueryInterface::OVERDUE|UPCOMING`; T-22 `AiSettingsService::setCopilotManagement(bool)` e `overview()['copilot_management']`. T-23 monta `new XTool(new CopilotXQuery($ctx,$pdo))`.

## Pendências
- 2026-10-15 · T-19..T-21 · onda 1 — marcação [x] espúria anterior ao fechamento da onda 1, origem desconhecida, corrigida para [ ] (são da Onda 6).
- T-03: `recordAnswer`/`recordSuggestion` sem guarda por `AiPurpose::isCopilot`; falta teste de `decide` inválido em `answered`.
- T-04: JsonSchemaValidator valida schema de forma preguiçosa e ignora restrições fora do tipo; erro de chave extra ecoa o nome (PII); registry aceita nome duplicado; `iterable` vs `array`; teste de allowlist fraco (ToolRegistryTest:66).
- T-07: contagem "33 chaves" no plano (são 32); teste não garante formato en/pt das entradas.
- T-02: `jsonObjects` troca `[]` por `{}` em qualquer profundidade; falta teste de `messages` sem `tools` com resposta `tool_use`.
- T-01: `.verify.sql` não lista os 5 índices auxiliares de FK; registrar ruling RESTRICT no plano (tasks.md/notes.md ainda dizem SET NULL); justificativa da âncora sem atendimento (PatientForm::onEdit) não se sustenta.
- T-05: teste usa `+` em vez de `array_merge` (id nunca reconstituído); `reconstitute()` quebra com tool_summary malformado.
- T-06: prescrição `draft` conta como ativa (avaliar `status = 'issued'` na T-11); `superseded` mais estreito que a Interface; fixture `otherUnitId()` pode ser de outro tenant.
- Aplicação da 0015 no `centralvet` (dev) segue dependendo de aprovação SQL do usuário; "nenhum push" não verificável por remoto.
- T-08: rate limit depois de assertInScope (sondagem de ids alheios não consome cota; fixado pela spec).
- T-09: insert não confere unidade/usuário nem conversation_id; lacunas de teste (lastMessages outro tenant, deleteOwned outra unidade); `search` é tenant-wide: confirmar em T-15.
- T-10: `truncated` sempre false em buscar_paciente/historico (pedir limit+1); falta teste de histórico fora da unidade; `$clock` não usado em ObterProntuarioTool.
- T-11: via/posologia/duracao fora de freeTextFields; `$clock` não usado em ObterExamesTool; fixture de exame com status inválido e faltam casos requested/âncora fora da unidade.
- T-12: teste não distingue updated_at de created_at; laço de lotes/MAX_BATCHES sem teste; sem trava contra execuções sobrepostas.
- T-13: `AiCopilotService` 10º parâmetro `?PiiMinimizer` diverge da Interface em tasks.md (segue ruling da T-08); cota por pergunta (T-18) e RuntimeException do limiter (T-14) em relay no board.
- T-14: pergunta só com espaços cai no catch genérico (mensagem em inglês); texto digitado se perde em erro de infraestrutura no onAsk; catch RuntimeException amplo vira ai_provider_error; onAsk/onDelete aceitam GET sem CSRF; foco/live region após a resposta; onDelete com painel indisponível mostra "Conversa não encontrada" enganoso; asserção do JS é só str_contains.
- T-15: testToolCallListWithoutTheProgramIsDenied fraco (afirmar "Permissão negada"); coluna Motivo mostra código cru (not_found etc.); sem teste de XSS em título/nome; AiToolCallList mostra mensagem de unidade ativa sem exigir unidade.
- Onda 4: menu/permissões de AiConversationList e AiToolCallList dependem da T-17 (seed).
- T-16: link sem checar RBAC de `AiCopilotPanel::onOpen`; docblock de `resolveTenantContext` órfão em PatientForm (:720-773); markup do link duplicado nos dois controllers; `error_log` "copilot link hidden" a cada render sem IA configurada; PatientForm verifica disponibilidade também no postback de onSave.
- T-17: só o grupo 1 (Template - Admin) tem EncounterView, então o 8B concede só a ele; compatibilidade MySQL 5.7 do bloco 8B verificada por inspeção (sem imagem 5.7 local); `testEveryCopilotScreenKeyHasATranslation` não é RED de comportamento.
- T-18: runbook 5.7 ambíguo sobre `.verify.sql` e "mesma contagem" da ai_interaction; falta conferência da ordem DROP/CREATE TRIGGER dos 6 CHECKs (seção 0015); referência a códigos `ai_*` aponta para tabela errada em ia-assistente.md.
- Onda 5: aplicar 0015 e bloco 8B do seed no centralvet (dev) depende de aprovação SQL do usuário; "nenhum push" não verificável por remoto.
- T-19: verificar_horarios sem profissional_id corta em 50 linhas (a tarde some); profissional_id de usuário inativo vira not found em consultar_agenda.
- T-20: teste de escape LIKE não prova o escape; expiring sem casos de borda; lote vencido pode aparecer em abaixo_minimo/termo sem sinalização.
- T-21: ListarDebitosTool não normaliza fuso do relógio; vencimento por dia difere da tela no próprio dia e ticket_medio inclui receita sem atendimento (documentar no runbook T-24); falta caso de paciente novo na unidade A.
- T-22: `.cv-touch-target` no `.tswitch` deixa o trilho no topo da caixa de 44px (conferir visualmente); gravação da flag provada só por fake, sem FeatureFlagRepository real.
- Onda 6: 0015 e seed 8B no centralvet (dev) seguem dependendo de aprovação SQL; "nenhum push" não verificável por remoto.
- T-23: nenhum teste falha se `ManagementToolset::create` sair de `forContext`; asserir as definições management (5) e clinical sobre o registry montado (AiCopilotService.php:154-162).
- T-24: runbook linha 391 sugere que basta `ai_copilot_management`; o código exige `ai_assistant` (ai_enabled) ligada; "IA indisponível" gera ai_terms_pending/ai_quota_exceeded/ai_not_configured.
- Onda 7: 0015 e seed 8B no centralvet (dev) dependem de aprovação SQL; "nenhum push" não verificável por remoto.
- T-25: ToolResult aceita excerptLengths fora de freeTextFields (validar excerptLengths ⊆ freeTextFields); ToolPolicy não confere ToolContext contra o contexto do registry; semântica de forbidden vs not_offered mudou.
- T-26: AiCopilotAvailability::reason duplica AiCopilotService::availability; closure de configuração do forContext sem teste unitário próprio.
- T-27: sem teste da fronteira de 90 dias nem de agendamento no dia seguinte; schedulableProfessionals sem asserção de outro tenant.
- Onda 8: 0015 e seed 8B no centralvet (dev) dependem de aprovação SQL; "nenhum push" não verificável por remoto.

## Riscos
- Tool use altera o payload do assistente da 8A → T-02 compara o body JSON de uma requisição sem `tools` antes/depois.
- Vazamento entre unidades pela ausência de unidade em `patient` → regra única em `CopilotClinicalQuery` (T-06) e teste com duas unidades e dois tenants.
- PII no histórico → pergunta e título minimizados antes de gravar; Review Focus T-13.
- Custo/latência: até 5 chamadas ao provedor por pergunta (4 rodadas + final) dentro de 45 s; PHP-FPM ocupado por até 45 s (documentar no runbook, T-18).
- Concorrência de SUITEs na mesma onda (falso FAIL de Redis/deadlock) → cada agente roda só os próprios testes filtrados por `grep`.

## Retomada
- Pasta: `.claude/tasks/mar-20261015-2050-fase-8b-copiloto-mcp/`
- Sessões: 7ae7100e-fb56-40d1-b5f9-954b0e3e5744
- Branch de trabalho: feat/fase-8b-copiloto-mcp (base: main)
- BASE da onda 1: bc35d6d
- Commits por onda:
  - Onda 1: BASE bc35d6d → HEAD 73f950e (73f950e, 3d7defe, 992df54, fb5a5b7, 555966d, 760dd08, 2f4530d, 205e5d1, 8a89e0d, 95c3ce3, 1fc7ab5, ac009bd, 9cecaf0, 08d5937)
  - Onda 2: BASE 7713431 → HEAD 120a196 (120a196, 6cfbab8, c9007d9, 1f62358, 954be65, 8d5ce21, 7ca2db8, fb35ee2, 9d50f98, 0d1216a, d675483, 1356001)
  - Onda 3: BASE 4ece9e1 → HEAD 3d76c96 (3d76c96, a823d73, 51e662b, dd595b3)
  - Onda 4: BASE 6ef9ffd → HEAD 69382a1 (69382a1, 99b8e46, 110fd8d, b1a6036, 8c736b8, beaf682, d620a57)
  - Onda 5: BASE b016bf4 → HEAD 09e41bc (09e41bc, a7dc809, 6a0d75d, ded9224, cfec6a4)
  - Onda 6: BASE 93bc397 → HEAD ff42a18 (ff42a18, 67789bd, a96f54a, 5ff7bc7, bac7f0b, d1827a7, 1063c3d, 358b4a4)
  - Onda 7: BASE ec862a2 → HEAD 185bbdd (f2005cf, 93a1031, 89038e4, 185bbdd)
  - Onda 8: BASE b2464f4 → HEAD 5acfbb1 (ea33281, 21c4587, 189c6a1, 8ea9e28, 9ccfe8c, 13b24af, 5acfbb1)
- Último status conhecido: onda 8 concluída; T-25..T-27 [x]; SUITE 1689/1689; T-28 e T-29 pendentes
- Próxima onda recomendada: 9 — T-28 (depende de T-25, T-26), depois onda 10 — T-29
