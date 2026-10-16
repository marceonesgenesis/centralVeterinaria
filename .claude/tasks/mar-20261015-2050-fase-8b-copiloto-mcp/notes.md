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

## Bloqueios
- Gate entre a Onda 1 e a Onda 2: aplicar `20261015_0015_phase8b_copilot.sql` no `centralvet_test` (orquestrador, skill `sql-write-approval`, usuário de migration, cópia temporária com o checksum real, `.verify.sql` depois). Desbloqueia quando o verify do `centralvet_test` for anotado aqui (3 tabelas novas vazias; contagem de `ai_interaction` igual à de antes).
- Fora do plano, só com aprovação SQL do usuário: 0015 no `centralvet` (dev) e a DML do bloco 8B do seed (T-17). Sem isso, o smoke manual nas telas reais não roda.

## Descobertas
- Onda 1 (board): `AnthropicMessagesGateway::parse` só aceita `tool_use` com `tools` na requisição (T-02); `StubAiGateway::scripted()/requests()`, `AiUsage::plus`; `JsonSchemaValidator::validate` static; `AiPurpose::COPILOT*`, `AiInteraction::recordAnswer`; `isFailed()` (T-05); `CopilotClinicalQueryInterface::EXAMS_*`; translations.json ordenado por `en` (32 chaves); `ai_conversation.anchor_patient_id` FK RESTRICT (T-01).
- `AnthropicMessagesGateway::parse` trata `stop_reason: tool_use` como `ai_provider_error` (:101-150); a T-02 só aceita `tool_use` quando a requisição tem `tools`.
- `AiNavigationIntegrationTest.php:65` fixa os filhos do submenu de IA; T-17 e T-23 atualizam o teste.
- Hash de `custom.css` e `layout.html` já diverge em `framework_hashes.php` (pré-existente); nada da 8B toca esses arquivos.
- `scripts/test-db/provision.sh` recusa banco existente: a 0015 entra no `centralvet_test` pelo gate, não por reprovisionamento.

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
- Último status conhecido: onda 1 concluída (T-01..T-07 [x]); SUITE 1513/1513
- Próxima onda recomendada: onda 2 (T-08..T-12; T-19..T-21 ficam na Onda 6)
