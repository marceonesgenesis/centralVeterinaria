# Board — mar-20261015-2050-fase-8b-copiloto-mcp

Log append-only de fatos que afetam outras tasks desta execução: contrato divergente, símbolo renomeado, arquivo compartilhado alterado, decisão que outra task precisa conhecer. Uma linha por fato, acrescentada por append com heredoc (abaixo; o delimitador entre aspas aceita qualquer caractere no fato); nunca edite ou remova linhas. Leia antes de começar uma task e antes de usar cada `Consome`. O fechador consolida as linhas em `notes.md § Descobertas`.

Formato: `- [T-NN] <fato>`

Append:

```bash
cat >> "/var/www/html/centralvet/.claude/tasks/mar-20261015-2050-fase-8b-copiloto-mcp/board.md" <<'EOF'
- [T-NN] <fato>
EOF
```
- [T-07] translations.json agora está inteiro em ordem case-insensitive por `en` (strcasecmp) e `CopilotTranslationsTest` reprova qualquer entrada fora de ordem ou duplicada: T-22 e demais que acrescentarem chaves devem inseri-las na posição ordenada, não no fim. São 32 chaves novas (1443 entradas), não 33.
- [T-02] Gateway com tools pronto (205e5d1): `AiCompletionRequest(..., array $messages = [], array $tools = [])`, `AiCompletionResult(..., array $toolUses = [], array $content = [])`, `AiUsage::plus`, `StubAiGateway::scripted()/requests()`. O gateway converte `input`/`properties` vazios em `{}` ao serializar; `content` vem decodificado como array (reenviar como veio).
- [T-02] SUITE completa morre com fatal "Cannot redeclare CentralVet\Domain\AiConversationMessage::failed()" (AiConversationMessage.php:162) em 2026-10-15 21:24 — arquivo de outra task.
- [T-03] Pronto (fb5a5b7): `AiPurpose::COPILOT_CLINICAL|COPILOT_MANAGEMENT|COPILOT|SUBJECT_CONVERSATION`, helper extra `AiPurpose::isCopilot()`; `AiInteraction::STATUS_ANSWERED` + `recordAnswer(...)`; `AiPromptCatalog::copilotSystem($purpose,$locale)` e `VERSIONS[copilot_*] = copilot_*.v1`. `build` e `decideAction` com finalidade do copiloto lançam `InvalidArgumentException`.
- [T-05] Getter de falha da mensagem é `AiConversationMessage::isFailed(): bool`, não `failed()`: PHP não aceita método estático e de instância com o mesmo nome, e o estático `AiConversationMessage::failed(int, string, DateTimeImmutable)` (consumido pela T-13) foi mantido. T-09/T-14/T-15: usem `isFailed()`.
- [T-05] `AiConversationMessage::toRow()` não traz `tenant_id` (o repositório preenche pelo contexto); `failed()` exige conteúdo `^ai_[a-z0-9_]+$`. `toRow()` de `AiConversation` traz `created_at`/`updated_at` (`Y-m-d H:i:s.u`); o de `McpToolCall` não traz `created_at`. Fakes: `insert()` aceita qualquer dono (semente); leituras/append só do dono do construtor.
- [T-06] `CopilotClinicalQueryInterface` expõe constantes `EXAMS_PENDING`/`EXAMS_RELEASED`/`EXAMS_ALL` ('pendentes'/'liberados'/'todos'); `exams()` com outro valor lança `InvalidArgumentException`. `tutorNames()` devolve `list<string>` (vazia fora da unidade). `superseded` em `vaccinations()` considera só aplicações posteriores em atendimentos da mesma unidade. Sem unidade ativa, todo método lança `MissingTenantContext` (requireUnitId). Fake: `seed('tutorNames', [patientId => list<string>])`; `calls()` = list<{method, args}>.
- [T-06] SUITE completa hoje morre com fatal em `AiConversationMessage::failed()` redeclarado (arquivo em andamento de outra task) antes da suíte Integration; T-06 validada montando só o seu teste em tests/Integration (ver reports/T-06.md).
- [T-04] Núcleo pronto (555966d): `JsonSchemaValidator::validate` é static; aceita também `description` por propriedade; schema raiz deve ser `type: object`, sem objetos aninhados. `ToolRegistry::visible` lança InvalidArgumentException para família fora de `ToolFamily::ALL`. `FakeFeatureFlagRepository` recebe lista de flags ligadas (`[FeatureFlagRepositoryInterface::AI_ASSISTANT]`), não mapa `=> true`. Extra: `ToolFamily::isAllowed($family, $name)`.
- [T-01] ai_conversation.anchor_patient_id FK agora ON DELETE RESTRICT (não SET NULL): MySQL 8 erro 3823 com coluna em CHECK. Paciente com conversa não pode ser apagado fisicamente (já era bloqueado por encounter/appointment).
