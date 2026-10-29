# Board — mar-20261007-1405-rodada-4-divida-tecnica

Log append-only de fatos que afetam outras tasks desta execução: contrato divergente, símbolo renomeado, arquivo compartilhado alterado, decisão que outra task precisa conhecer. Uma linha por fato, acrescentada por append com heredoc (abaixo; o delimitador entre aspas aceita qualquer caractere no fato); nunca edite ou remova linhas. Leia antes de começar uma task e antes de usar cada `Consome`. O fechador consolida as linhas em `notes.md § Descobertas`.

Formato: `- [T-NN] <fato>`

Append:

```bash
cat >> "/var/www/html/centralvet/.claude/tasks/mar-20261007-1405-rodada-4-divida-tecnica/board.md" <<'EOF'
- [T-NN] <fato>
EOF
```
- [T-02] `EncounterDocumentService::__construct` ganhou o 5º argumento `?Closure $readerForProvider` (`fn (string $storageProvider): StorageInterface`), usado só em `download()` após as checagens; attach/discard/list seguem no `$storage` (commit 72b954c).
- [T-01] StorageFactory/FallbackReadStorage/LazyStorage prontas em src/app/Core/Storage/ (0236011) conforme a Interface do plano; FallbackReadStorage memoiza também a falha ao resolver o secundário (get/delete relançam a mesma exceção; exists devolve false). Nenhuma mensagem de exceção nova.
