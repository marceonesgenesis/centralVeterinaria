# Board — mar-20261005-1457-landing-a11y-i18n

Log append-only de fatos que afetam outras tasks desta execução: contrato divergente, símbolo renomeado, arquivo compartilhado alterado, decisão que outra task precisa conhecer. Uma linha por fato, acrescentada por append com heredoc (abaixo; o delimitador entre aspas aceita qualquer caractere no fato); nunca edite ou remova linhas. Leia antes de começar uma task e antes de usar cada `Consome`. O fechador consolida as linhas em `notes.md § Descobertas`.

Formato: `- [T-NN] <fato>`

Append:

```bash
cat >> "/var/www/html/centralvet/.claude/tasks/mar-20261005-1457-landing-a11y-i18n/board.md" <<'EOF'
- [T-NN] <fato>
EOF
```
- [T-04] Bloqueada: o classificador do modo automático negou a edição de T-09-cleanup-landing-lead.sql ([Instruction Poisoning]); o arquivo continua igual a 5b632bf, sem commit da T-04.
