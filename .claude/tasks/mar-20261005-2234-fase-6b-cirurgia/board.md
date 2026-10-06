# Board — mar-20261005-2234-fase-6b-cirurgia

Log append-only de fatos que afetam outras tasks desta execução: contrato divergente, símbolo renomeado, arquivo compartilhado alterado, decisão que outra task precisa conhecer. Uma linha por fato, acrescentada por append com heredoc (abaixo; o delimitador entre aspas aceita qualquer caractere no fato); nunca edite ou remova linhas. Leia antes de começar uma task e antes de usar cada `Consome`. O fechador consolida as linhas em `notes.md § Descobertas`.

Formato: `- [T-NN] <fato>`

Append:

```bash
cat >> "/var/www/html/centralvet/.claude/tasks/mar-20261005-2234-fase-6b-cirurgia/board.md" <<'EOF'
- [T-NN] <fato>
EOF
```
- [T-03] Extra público além do contrato: `SurgeryChecklist::assertItemOfPhase(string $phase, string $itemCode): void` (fase desconhecida → `Unknown checklist phase "<phase>"`; código fora da fase → `Unknown checklist item "<code>"`), usado por `SurgeryChecklistItem::check`/`reconstitute`. As 3 entidades têm `reconstitute(array $row)` com chaves = colunas da 0011 e `tenantId()`; `SurgeryEvent::record` com notes null/vazio em tipo não clínico grava `notesText() === null` (texto é trim, limite em `mb_strlen`).
- [T-02] Surgery tem getters além da Interface para T-06 gravar: scheduledBySystemUserId(), consentRecordedBySystemUserId(), completedBySystemUserId(), cancelledBySystemUserId(), createdAt(), updatedAt(); SurgeryRoom e SurgeryTeamMember têm reconstitute(array $row) com as colunas da T-01. Transições inválidas (inclusive "has no recorded consent", "is not open for pre-operative changes", "already has a follow-up appointment") lançam InvalidStatusTransitionException.
