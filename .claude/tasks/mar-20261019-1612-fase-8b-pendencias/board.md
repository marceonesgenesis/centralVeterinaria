# Board — mar-20261019-1612-fase-8b-pendencias

Log append-only de fatos que afetam outras tasks desta execução: contrato divergente, símbolo renomeado, arquivo compartilhado alterado, decisão que outra task precisa conhecer. Uma linha por fato, acrescentada por append com heredoc (abaixo; o delimitador entre aspas aceita qualquer caractere no fato); nunca edite ou remova linhas. Leia antes de começar uma task e antes de usar cada `Consome`. O fechador consolida as linhas em `notes.md § Descobertas`.

Formato: `- [T-NN] <fato>`

Append:

```bash
cat >> "/var/www/html/centralvet/.claude/tasks/mar-20261019-1612-fase-8b-pendencias/board.md" <<'EOF'
- [T-NN] <fato>
EOF
```
- [T-16] AiCopilotPanel: onAsk deixa AiUnavailableException subir a perform (com $draft → renderMessageOnly com motivo e rascunho); load perdeu o parâmetro $fallbackReason; helper privado assertAnchorInUnit usado por load e onDelete; teste ganhou T14::$historyAllow e T14::$buildReason (d469fbb).
- [T-02] ToolRegistry::sameContext(ToolContext $context): bool disponível (compara tenantId, userId e unitId com o TenantContext do registry; unitId null no registry dá false). Construtor lança \LogicException('Duplicate tool name: <name>'). Erro de chave extra do JsonSchemaValidator agora é 'unexpected property: not allowed'.
- [T-12] FakeAiInteractionRepository ganhou failNextInsert(\Throwable $e) (lança uma vez, sem gravar); AiCopilotService::recordInteraction loga "interaction not recorded after the answer" com correlation_id antes de relançar (9a58512).
- [T-08] CopilotAgendaQueryInterface::professionalExists ganhou 2º parâmetro opcional `bool $includeInactive = false`; FakeCopilotAgendaQuery aceita seed('inactive_professionals') e grava args [id, includeInactive] em calls().
- [T-06] Nova trait `CentralVet\Ai\Tools\Clinical\ResolvesPatientInUnit` (patientId) usada por ObterPrescricoesTool/ObterExamesTool/ConsultarVacinacaoTool; ObterPrescricoesTool agora devolve freeTextFields ['via','posologia','duracao'] (relevante para T-20).
- [T-05] BuscarPacienteTool/ObterHistoricoAtendimentosTool pedem $limit+1 à consulta e marcam truncated real.
- [T-14] AiConversationRepository::insert e McpToolCallRepository::insert agora lançam TenantBoundaryViolation se unidade/usuário divergem do contexto (e, no mcp_tool_call, conversation_id inexistente no tenant): fixtures que gravam linha de outro usuário/unidade precisam de repositório com o contexto desse usuário/unidade.
- [T-15] AiConversationRetention::forConnection($pdo, $clock, $batchSize, $maxBatches) (>= 1); bin imprime {"skipped":"locked"} e sai 0 quando travado (flock em sys_get_temp_dir()/centralvet-ai-conversation-retention-<md5(__DIR__)>.lock) — para o runbook (T-24).
- [T-20] ToolPolicy::execute nega com forbidden (sem lookup/validação/execução da ferramenta) quando !registry->sameContext($context); ToolResult lança InvalidArgumentException('Excerpt lengths must name free-text fields') se chave de excerptLengths não está em freeTextFields.
- [T-22] AiCopilotPanel: estado de load aceita chave opcional 'focus' ('latest' só no onAsk respondido) → shell com data-cv-copilot-focus="latest"; conversationView marca a última mensagem do assistente (tabindex="-1", data-cv-copilot-latest="1"); cv-ai-copilot.js ganhou CvAiCopilot.focusLatest (984b2fe). T-23 mexe no mesmo arquivo.
- [T-21] translations.json ganhou 6 entradas (Call limit reached, Invalid tool input, Time limit exceeded, Tool not allowed, Tool not offered, Unexpected failure); `Record not found` já existia e foi reaproveitada (02d4e80).
- [T-23] AiCopilotPanel::hostLink(string $family, ?int $anchorPatientId): TElement monta o link "Copiloto" das telas hospedeiras; PatientForm::copilotLink devolve hostLink e EncounterView::copilotLink foi removido (render chama hostLink direto) (b5ac8ca).
- [T-25] ToolRegistry::contextFor(ToolContext $foreign): ToolContext (tenant/unidade/usuário do registry, correlation_id e purpose da chamada, sem conversa nem âncora); ToolPolicy audita a negação forbidden por !sameContext com esse contexto — a linha de mcp_tool_call sai com o usuário do registry e conversation_id NULL (6700d92).
- [T-26] AiCopilotPanel: onAsk trata AiUnavailableException (montagem/pré-check) e renderiza load(..., $fallbackReason) com 'draft' (textarea readonly via helper draftField); load voltou a ter ?string $fallbackReason = null (usado quando a 8A não aponta motivo); perform não usa mais renderMessageOnly para AiUnavailableException.
