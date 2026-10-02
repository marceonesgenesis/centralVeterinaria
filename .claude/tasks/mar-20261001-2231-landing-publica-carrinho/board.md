# Board — mar-20261001-2231-landing-publica-carrinho

Log append-only de fatos que afetam outras tasks desta execução: contrato divergente, símbolo renomeado, arquivo compartilhado alterado, decisão que outra task precisa conhecer. Uma linha por fato, acrescentada por append com heredoc (abaixo; o delimitador entre aspas aceita qualquer caractere no fato); nunca edite ou remova linhas. Leia antes de começar uma task e antes de usar cada `Consome`. O fechador consolida as linhas em `notes.md § Descobertas`.

Formato: `- [T-NN] <fato>`

Append:

```bash
cat >> "/var/www/html/centralvet/.claude/tasks/mar-20261001-2231-landing-publica-carrinho/board.md" <<'EOF'
- [T-NN] <fato>
EOF
```
- [T-01] Contrato pronto (2023e96): `LandingCatalog::publicJson()` tem `consent` como objeto `{"version": CONSENT_VERSION, "text": CONSENT_TEXT}` e `vets` como objeto chave→rótulo (VETS_OPTIONS); `ufs` em ordem alfabética (AM antes de AP, diferente do rascunho).
- [T-01] `LeadSubmission::fromPayload`: `city` e `uf` ausentes no payload contam como `''` (opcionais); os demais campos ausentes ou não-string são inválidos. Chave de erro é o nome do campo do payload (`plan`, não `planId`).
