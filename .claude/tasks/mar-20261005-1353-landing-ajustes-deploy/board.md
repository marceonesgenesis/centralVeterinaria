# Board — mar-20261005-1353-landing-ajustes-deploy

Log append-only de fatos que afetam outras tasks desta execução: contrato divergente, símbolo renomeado, arquivo compartilhado alterado, decisão que outra task precisa conhecer. Uma linha por fato, acrescentada por append com heredoc (abaixo; o delimitador entre aspas aceita qualquer caractere no fato); nunca edite ou remova linhas. Leia antes de começar uma task e antes de usar cada `Consome`. O fechador consolida as linhas em `notes.md § Descobertas`.

Formato: `- [T-NN] <fato>`

Append:

```bash
cat >> "/var/www/html/centralvet/.claude/tasks/mar-20261005-1353-landing-ajustes-deploy/board.md" <<'EOF'
- [T-NN] <fato>
EOF
```
- [T-04] nginx (9b5a8e0): real_ip X-Forwarded-For (RFC1918 + 127.0.0.1, recursive) e absolute_redirect off no server; /lead.php não-POST → 405 JSON com Allow: POST no nginx (não chega ao PHP local). Grep de validação do .htaccess: usar /usr/bin/grep (o grep do zsh é função e dá 0).
- [T-02] LeadSubmissionHandler::precheck(string $method, array $headers, string $body): ?LeadResponse e CentralVet\Landing\LazyLeadStore (closure chamada só no 1º insert) prontos (f008b84); lead.php agora só abre Redis/PDO depois do precheck. handle() inalterado no contrato.
- [T-06] landing.html: cache-busters landing.css/translations.js/landing.js agora `?v=20261005-a11y` (i18n.js inalterado) e `#cart` com `inert` sem `aria-hidden` (c0117ea RED, aaefa40); LandingPageTest:67-68 atualizado. Quem mexer nesses arquivos deve partir desses valores.
