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
