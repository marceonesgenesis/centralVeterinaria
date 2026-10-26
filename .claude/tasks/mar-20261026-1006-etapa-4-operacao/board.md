# Board — mar-20261026-1006-etapa-4-operacao

Log append-only de fatos que afetam outras tasks desta execução: contrato divergente, símbolo renomeado, arquivo compartilhado alterado, decisão que outra task precisa conhecer. Uma linha por fato, acrescentada por append com heredoc (abaixo; o delimitador entre aspas aceita qualquer caractere no fato); nunca edite ou remova linhas. Leia antes de começar uma task e antes de usar cada `Consome`. O fechador consolida as linhas em `notes.md § Descobertas`.

Formato: `- [T-NN] <fato>`

Append:

```bash
cat >> "/var/www/html/centralvet/.claude/tasks/mar-20261026-1006-etapa-4-operacao/board.md" <<'EOF'
- [T-NN] <fato>
EOF
```
- [T-01] 0019 aplicada no centralvet_test (commit 66b71cd): ops_heartbeat(job PK, last_success_at, detail NULL, updated_at) e ops_alert_state(alert_key PK, last_sent_at, last_subject, sent_count DEFAULT 1) existem; schema_migrations do teste tem 19 linhas; provision.sh já lista a 0019.
- [T-05] `scripts/release/build-release.sh [--image] <commit>` e `scripts/release/smoke.sh <base-url>` prontos. Manifesto: `migration <NNNN> sha256=<hex>` (versão = segundo campo do nome, ex. `0019`). Imagem construída do export limpo (antes do `composer install` do pacote), com `-t centralvet/app:<sha40> -t centralvet/worker:<sha40>`. Passe o SHA fixo, não `HEAD`, quando outros agentes comitam em paralelo. `var/releases/` ainda aparece como `??` no `git status` até a T-02 ignorá-lo.
- [T-02] Contrato entregue (445983e): `TRUST_FORWARDED_PROTO=false` no .env.example e `TRUST_FORWARDED_PROTO: ${TRUST_FORWARDED_PROTO:-false}` no x-php-service; nginx monta `${NGINX_TRUSTED_PROXIES_FILE:-./docker/nginx/trusted-proxies.dev.conf}:/etc/nginx/trusted-proxies.conf:ro`; `map $http_x_forwarded_proto $cv_forwarded_https` + `fastcgi_param HTTPS $cv_forwarded_https if_not_empty;` nas 3 locations com fastcgi_pass; .env.example com BACKUP_PASSPHRASE_FILE=, BACKUP_RETENTION_DAYS=14, BACKUP_OFFSITE_DIR=, BACKUP_OFFSITE_S3_URI=, BACKUP_OFFSITE_S3_ENDPOINT= e `# BACKUP_DIR=var/backups`; .gitignore com var/releases/, var/ci/, var/drill/ (atende o relay da T-05).
- [T-03] `scripts/db/migration-status.sh [--database <nome>]` e `php bin/migration-status.php` prontos (saída e códigos 0/1/2 do contrato). Extra: `MigrationStatus::lines(array $report): list<string>` (público, estático) formata a saída igual ao shell — T-09 pode reutilizar. O shell manda o SQL por `-e` (nome validado `^[A-Za-z0-9_]{1,64}$`), não por stdin. Dev `centralvet` hoje: applied=16 pending=3 (0017, 0018, 0019) → código 1.
- [T-04] Reset de senha sai por `CentralVet\Security\PasswordResetMailer` + `EmailProviderFactory` (referência `password-reset`, remetente `SMTP_FROM_ADDRESS`/`SMTP_FROM_NAME`); `MailService`/`mail_from` não são mais usados no `SystemRequestPasswordResetForm`. `CentralVet\Tests\Support\FakeSmtpServer` (start/port/stop) disponível para testes de SMTP em loopback.
- [T-06] `webhook-event.php` aceita também `customer.subscription.deleted` (cenário (k) do roteiro) e as opções aditivas `--id <evt_zz_e3_...>` (reentrega/duplicado) e `--timestamp <unix>` (fora da tolerância); `customer.created` continua saindo 2. Runbook em `docs/runbooks/smoke-billing.md` (T-16 pode linkar no índice de runbooks).
- [T-01] Correção 1 (2a4c63c): ops_heartbeat.last_success_at e ops_alert_state.last_sent_at agora têm DEFAULT CURRENT_TIMESTAMP(6) sem ON UPDATE (seguro no MySQL 5.7); a 0019 foi reaplicada no centralvet_test. T-10 continua gravando as duas colunas explicitamente.
