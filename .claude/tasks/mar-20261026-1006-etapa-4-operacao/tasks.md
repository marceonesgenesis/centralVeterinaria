# Tasks

Origem: `.docs/audits/2026-10-09-prontidao-mvp.md` § "Operação e publicação" e § "Ordem recomendada e critérios de liberação" (Etapa 4 — Operação: restauração comprovada; pipeline reproduzível; falhas recuperáveis e visíveis), mais as pendências marcadas para a Etapa 4 em `.claude/tasks/mar-20261021-1554-etapa-3-contratacao/notes.md` (§ Riscos, § Pendências) e `reviews/final.md`. Caminhos relativos a `/var/www/html/centralvet`; comandos rodam dessa pasta.

Comandos usados em Teste RED e Validação:
- LINT = `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php -l <arquivo relativo a src/>` → `No syntax errors detected`.
- LINTALL = `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app sh -c 'cd /var/www/html/src && find app tests bin config -name "*.php" -print0 | xargs -0 -n1 php -l 2>&1 | grep -v "^No syntax errors detected" || true' 2>/dev/null` → compare com `baseline/php-lint-all.txt` (0 linhas: qualquer linha é nova).
- BASHN = `find scripts -name '*.sh' -exec bash -n {} \;` → compare com `baseline/bash-n.txt` (0 linhas).
- SUITE = `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro -v /var/www/html/centralvet/.env.example:/var/www/html/.env.example:ro -v /var/www/html/centralvet/docker-compose.yml:/var/www/html/docker-compose.yml:ro -v /var/www/html/centralvet/docker:/var/www/html/docker:ro -v /var/www/html/centralvet/docs:/var/www/html/docs:ro -v /var/www/html/centralvet/scripts:/var/www/html/scripts:ro -v /var/www/html/centralvet/.github:/var/www/html/.github:ro app php tests/run.php 2>&1 | /usr/bin/grep -E '<Classe>|Failed:'` (o runner imprime `  PASS  <Classe>::<método>` / `  FAIL  ...` e `Total: N, Passed: N, Failed: N, Skipped: N`; os testes MySQL saem `SKIP` com o MySQL fora do ar e `FAIL` com a 0019 ausente no `centralvet_test`). Testes que leem arquivos fora de `src/` usam `dirname(__DIR__, 3)` (padrão de `PhpRequestOrderTest`), que no container é `/var/www/html`.
- MYSQLTEST = `docker compose exec -T mysql sh -ceu 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -uroot --default-character-set=utf8mb4 --batch centralvet_test'` (root do container mysql, padrão autorizado desde a Etapa 2 para DDL no banco de teste; nunca contra `centralvet`).
- SHTEST = `bash <arquivo>.test.sh` a partir da raiz; segue `scripts/test-db/provision-check.test.sh`: `docker` (e, quando preciso, `curl`/`aws`/`git`) falso num diretório temporário à frente do `PATH`, uma linha `PASS  <caso>` ou `FAIL  <caso>` por caso, `Failed: <n>` no fim e código 0 só com `Failed: 0`. Nenhum SHTEST chama o Docker real nem a rede.
- Testes de integração estendem `CentralVet\Tests\Support\MysqlIntegrationTestCase` (`$this->pdo` em transação, rollback no `tearDown`, guarda contra commit).
- Credenciais: nenhuma chave, senha ou frase real em código, teste, log, relatório ou prompt. Em teste, prefixos de chave do Stripe (`sk_` + `live_`) são montados por concatenação. Frases de backup do ensaio ficam em `var/drill/` (ignorado pelo git, T-02), geradas com `openssl rand`.
- Escrita no `.env` da raiz e SQL de escrita no banco `centralvet` (dev) exigem autorização do usuário; nenhuma task depende disso. O `backup.sh` roda no dev com `--no-heartbeat`.
- Arquivos protegidos por hash (`src/app/config/framework_hashes.php`: `engine.php`, `index.php`, `init.php`, `app/lib/util/MailService.php`, `app/lib/util/TMail.php` etc.) não são editados.

| ID | Camada | Descrição | Dependências | Paralelizável | Complexidade | Agente | Status |
|---|---|---|---|---|---|---|---|
| T-01 | database | Migration 0019: heartbeat de jobs e estado de alertas | — | sim | simples | Darwin | [x] |
| T-02 | infra | Contrato de ambiente, proxies confiáveis do nginx e portas privadas | — | sim | média | Levi | [x] |
| T-03 | infra | Registro de migrations aplicadas (`migration-status.sh` e `bin/migration-status.php`) | — | sim | média | Arquimedes | [x] |
| T-04 | backend | SMTP validado para convite e reset, remetente configurável | — | sim | média | Aang | [x] |
| T-05 | infra | Artefato de release por commit e fumaça pós-implantação | — | sim | média | Batman | [x] |
| T-06 | qa | Fumaça da contratação versionada em `scripts/smoke/billing/` | — | sim | média | Maquiavel | [x] |
| T-07 | backend | Cookies Secure atrás do proxy (`RequestScheme`) | T-02 | sim | média | Yoda | [ ] |
| T-08 | infra | Backup completo: banco, arquivos, documentos e MinIO, cifrado, com retenção e cópia externa | T-01, T-02 | sim | alta | Naruto | [ ] |
| T-09 | backend | Preflight de produção e paridade de ambiente | T-02, T-03 | sim | média | Jaspion | [ ] |
| T-10 | backend | Heartbeat de jobs e alerta ao operador com deduplicação | T-01 | sim | média | Platão | [ ] |
| T-11 | infra | Restauração verificada e ensaio em projeto isolado | T-08, T-17 | sim | alta | Spock | [ ] |
| T-12 | backend | Monitor operacional e alertas (`bin/ops-monitor.php`) | T-10 | sim | alta | Sherlock | [ ] |
| T-13 | backend | Heartbeat do reconciliador e retenção LGPD de `billing_event.payload` | T-10 | sim | média | Athena | [ ] |
| T-14 | ci | CI reproduzível com banco de teste isolado, schema e lint | T-01, T-03, T-05, T-08, T-11 | sim | alta | Kratos | [ ] |
| T-15 | infra | Ensaio de rollback de release e registro dos tempos medidos | T-03, T-05, T-08, T-11, T-17 | sim | alta | Thanos | [ ] |
| T-16 | docs | Documentação operacional: runbooks, cron, alertas, release, LGPD | T-03, T-06, T-07, T-09, T-12, T-13, T-14, T-15, T-17 | não | média | Gandalf | [ ] |
| T-17 | backend | Backup da hospedagem compartilhada por PHP CLI (sem Docker) | T-01, T-02 | sim | alta | Goku | [ ] |

## Detalhamento

### T-01 — Migration 0019: heartbeat de jobs e estado de alertas

**Camada:** database
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** simples
**Agente:** Darwin

Cria as duas tabelas de operação que os jobs (backup, reconciliador, monitor, retenção) e o alerta usam. Segue o formato da 0017/0018: cabeçalho com efeito, risco e verificação; `INSERT INTO schema_migrations` com o placeholder de 64 zeros; `.verify.sql` só com `SELECT`. Sem `CHECK` (o preparador MySQL 5.7 de `scripts/prepare-mysql57.py` só precisa converter collation). Aplica no `centralvet_test` (padrão autorizado); nunca no `centralvet`.

**Arquivos prováveis**
- `src/app/database/migrations/20261026_0019_ops_monitoring.sql`
- `src/app/database/migrations/20261026_0019_ops_monitoring.verify.sql`
- `scripts/test-db/provision.sh`

**Interface**
- Produz: tabela `ops_heartbeat` (`job varchar(64) NOT NULL` PK, `last_success_at timestamp(6) NOT NULL`, `detail varchar(190) NULL`, `updated_at timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6)`), InnoDB utf8mb4 `utf8mb4_0900_ai_ci`
- Produz: tabela `ops_alert_state` (`alert_key varchar(64) NOT NULL` PK, `last_sent_at timestamp(6) NOT NULL`, `last_subject varchar(190) NOT NULL`, `sent_count int unsigned NOT NULL DEFAULT 1`)
- Produz: versão `20261026_0019_ops_monitoring` em `schema_migrations`; linha `$migrations_dir/20261026_0019_ops_monitoring.sql` ao fim de `schema_files` em `scripts/test-db/provision.sh`
- Consome: nada

**Teste RED**
- sem teste: migration pura; a prova é a aplicação no `centralvet_test`, o `.verify.sql` e as contagens antes e depois

**Critério de aceite**
- MYSQLTEST `< src/app/database/migrations/20261026_0019_ops_monitoring.sql` termina com código 0, e o `.verify.sql` pelo mesmo cliente mostra 1 linha em `schema_migrations` com `20261026_0019_ops_monitoring` e as colunas `job`, `last_success_at`, `detail`, `updated_at`, `alert_key`, `last_sent_at`, `last_subject`, `sent_count` em `information_schema.COLUMNS`.
- `SELECT COUNT(*) FROM tenant`, `system_users` e `schema_migrations` (menos a linha nova) têm o mesmo valor antes e depois.
- `grep -c '20261026_0019_ops_monitoring.sql' scripts/test-db/provision.sh` imprime `1` e `bash scripts/test-db/provision-check.test.sh` imprime `Failed: 0`.
- Hospedagem MySQL 5.7: com a 0019 copiada como `01-ops-monitoring.sql` num diretório temporário, `python3 scripts/prepare-mysql57.py <tmp>/in <tmp>/out` termina com código 0, e `grep -rl 'utf8mb4_unicode_ci' <tmp>/out` lista o SQL gerado, sem `utf8mb4_0900_ai_ci` (`grep -rc` = 0).

**Validação**
- `printf 'SELECT COUNT(*) FROM tenant; SELECT COUNT(*) FROM system_users; SELECT COUNT(*) FROM schema_migrations;\n' |` MYSQLTEST antes e depois de MYSQLTEST `< src/app/database/migrations/20261026_0019_ops_monitoring.sql`, seguido de MYSQLTEST `< src/app/database/migrations/20261026_0019_ops_monitoring.verify.sql` (evidência: contagens de `tenant` e `system_users` iguais, `schema_migrations` +1, as 8 colunas listadas)
- `bash scripts/test-db/provision-check.test.sh` (evidência: `Failed: 0`)
- o `prepare-mysql57.py` do critério num `mktemp -d` (evidência: código 0, collation convertida)
- BASHN (evidência: nenhuma linha em relação a `baseline/bash-n.txt`)

### T-02 — Contrato de ambiente, proxies confiáveis do nginx e portas privadas

**Camada:** infra
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Levi

Escritor único de `.env.example`, `docker-compose.yml` e `.gitignore` nesta etapa. Tira os quatro `set_real_ip_from` amplos do `default.conf` para um arquivo incluído, escolhido por variável (o de dev mantém as faixas atuais; o exemplo de produção traz só um IP documental), e faz o nginx repassar `HTTPS=on` ao PHP quando o proxy da frente informa `X-Forwarded-Proto: https`. Declara as variáveis de backup que T-08 lê. Não muda o comportamento do dev em HTTP.

**Arquivos prováveis**
- `.env.example`
- `docker-compose.yml`
- `docker/nginx/default.conf`
- `docker/nginx/trusted-proxies.dev.conf`
- `docker/nginx/trusted-proxies.production.conf.example`
- `.gitignore`
- `src/tests/Unit/ProxyTrustConfigTest.php`

**Interface**
- Produz: `TRUST_FORWARDED_PROTO` — `.env.example` `TRUST_FORWARDED_PROTO=false` (comentário: só `true` quando o PHP fica atrás de proxy que não passa `HTTPS` e só ele alcança o PHP) e `TRUST_FORWARDED_PROTO: ${TRUST_FORWARDED_PROTO:-false}` em `x-php-service.environment`
- Produz: `NGINX_TRUSTED_PROXIES_FILE` — volume do serviço `nginx` `${NGINX_TRUSTED_PROXIES_FILE:-./docker/nginx/trusted-proxies.dev.conf}:/etc/nginx/trusted-proxies.conf:ro` e linha comentada em `.env.example`
- Produz: `include /etc/nginx/trusted-proxies.conf;` no `server` de `default.conf`, no lugar dos 4 `set_real_ip_from` (que vão para `trusted-proxies.dev.conf`); `real_ip_header X-Forwarded-For;` e `real_ip_recursive on;` ficam no `default.conf`
- Produz: `map $http_x_forwarded_proto $cv_forwarded_https` (`https` → `on`, `default` → vazio) no contexto http e `fastcgi_param HTTPS $cv_forwarded_https if_not_empty;` em toda location com `fastcgi_pass`
- Produz: `trusted-proxies.production.conf.example` com `set_real_ip_from 203.0.113.10;` (IP documental RFC 5737) e comentário para trocar pelo IP do proxy real
- Produz: em `.env.example`, bloco de backup com `BACKUP_PASSPHRASE_FILE=` (vazio; caminho de arquivo fora do repositório), `BACKUP_RETENTION_DAYS=14`, `BACKUP_OFFSITE_DIR=`, `BACKUP_OFFSITE_S3_URI=`, `BACKUP_OFFSITE_S3_ENDPOINT=` e `# BACKUP_DIR=var/backups`
- Produz: `.gitignore` com `var/releases/`, `var/ci/` e `var/drill/`
- Consome: nada

**Teste RED**
- `src/tests/Unit/ProxyTrustConfigTest.php` — `docker/nginx/default.conf` não tem `set_real_ip_from` e tem `include /etc/nginx/trusted-proxies.conf;`; `trusted-proxies.dev.conf` tem `10.0.0.0/8`, `172.16.0.0/12`, `192.168.0.0/16` e `127.0.0.1`; o `.example` não tem nenhuma dessas faixas nem `0.0.0.0/0`; cada location com `fastcgi_pass` tem `fastcgi_param HTTPS $cv_forwarded_https if_not_empty;`; o `docker-compose.yml` monta `/etc/nginx/trusted-proxies.conf` no nginx, passa `TRUST_FORWARDED_PROTO` ao app e todo item de `ports:` começa com `"127.0.0.1:`; o `.env.example` tem `TRUST_FORWARDED_PROTO=false`, `BACKUP_PASSPHRASE_FILE=` e `BACKUP_RETENTION_DAYS=14` (comando: `SUITE com <Classe> = ProxyTrustConfigTest`)

**Critério de aceite**
- SUITE com `ProxyTrustConfigTest|PhpRequestOrderTest|PasswordResetFormsWiringTest` mostra `PASS` em todos os métodos e `Failed: 0`.
- `docker run --rm --add-host app:127.0.0.1 -v /var/www/html/centralvet/docker/nginx/default.conf:/etc/nginx/conf.d/default.conf:ro -v /var/www/html/centralvet/docker/nginx/trusted-proxies.dev.conf:/etc/nginx/trusted-proxies.conf:ro nginx:1.27.5-alpine nginx -t` imprime `test is successful`; o mesmo com `trusted-proxies.production.conf.example` montado no lugar do dev também.
- `docker compose config --quiet` sai com código 0 e `git check-ignore var/drill/x var/ci/x var/releases/x` lista os três.

**Validação**
- SUITE com `<Classe>` = `ProxyTrustConfigTest|PhpRequestOrderTest|PasswordResetFormsWiringTest` (evidência: `PASS` em cada método e `Failed: 0`)
- os dois `docker run ... nginx -t` do critério (evidência: `test is successful` nas duas saídas)
- `docker compose config --quiet; echo $?` (evidência: `0`) e `git check-ignore var/drill/x var/ci/x var/releases/x` (evidência: três linhas)
- LINT de `tests/Unit/ProxyTrustConfigTest.php` (evidência: `No syntax errors detected`)

### T-03 — Registro de migrations aplicadas (`migration-status.sh` e `bin/migration-status.php`)

**Camada:** infra
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Arquimedes

Compara as migrations numeradas do disco com `schema_migrations` de um banco, só lendo, nos dois alvos: o script shell para a pilha Docker (via `docker compose exec mysql`, usado pela CI em T-14 e pelo ensaio em T-15) e um CLI PHP por PDO para a hospedagem compartilhada (sem Docker nem cliente `mysql`), com a mesma saída. O shell respeita `COMPOSE_PROJECT_NAME`/`COMPOSE_ENV_FILES` do ambiente para mirar outro projeto. O PHP vai no pacote de release (as migrations ficam em `src/app/database/migrations/`).

**Arquivos prováveis**
- `scripts/db/migration-status.sh`
- `scripts/db/migration-status.test.sh`
- `src/app/Core/Operations/MigrationStatus.php`
- `src/bin/migration-status.php`
- `src/tests/Integration/MigrationStatusIntegrationTest.php`

**Interface**
- Produz: `scripts/db/migration-status.sh [--database <nome>]` — versões esperadas = nomes sem `.sql` de `src/app/database/migrations/2*_[0-9][0-9][0-9][0-9]_*.sql`, sem os `*.verify.sql`; lê só com `SELECT version FROM <nome>.schema_migrations` via `docker compose exec -T mysql` (root do container, `--batch --skip-column-names`); sem `--database`, usa `$MYSQL_DATABASE` do container mysql
- Produz: saída `applied <versão>` / `pending <versão>` (uma linha por arquivo, em ordem) e `unknown <versão>` (no banco sem arquivo), última linha `migration-status database=<nome> applied=<n> pending=<n> unknown=<n>`; código 0 com `pending=0 unknown=0`, 1 com algum pendente ou desconhecido, 2 em argumento inválido ou banco sem `schema_migrations`
- Produz: `final class CentralVet\Operations\MigrationStatus` com `__construct(PDO $pdo, string $migrationsDir)` e `report(): array` → `array{database: string, applied: list<string>, pending: list<string>, unknown: list<string>}` (mesma regra de nomes; `SELECT DATABASE()` e `SELECT version FROM schema_migrations`; `RuntimeException` sem a tabela)
- Produz: `php bin/migration-status.php` — só CLI, carrega `init.php` e `config/environment.php` como o `billing-reconcile.php`, conecta pelo `DB_*` do ambiente (o usuário de runtime basta) e imprime a mesma saída e os mesmos códigos do shell
- Consome: nada

**Teste RED**
- `scripts/db/migration-status.test.sh`, `src/tests/Integration/MigrationStatusIntegrationTest.php` — shell, com `docker` falso no `PATH` que devolve uma lista de versões: sem `20261026_0018_billing_order_refund` na lista, imprime `pending 20261026_0018_billing_order_refund` e sai 1; com `20990101_9999_x` a mais, imprime `unknown 20990101_9999_x` e sai 1; com a lista igual aos arquivos, a última linha tem `pending=0 unknown=0` e sai 0; `--database` sem valor sai 2; o docker falso nunca recebe `INSERT`, `UPDATE`, `DELETE`, `CREATE`, `DROP` nem `ALTER`; PHP, no `centralvet_test` e com um diretório temporário de migrations (cópia de 2 arquivos reais mais `20990101_9999_x.sql`), `report()` devolve `database` = `centralvet_test`, as 2 versões em `applied` e `20990101_9999_x` em `pending` (comando: `bash scripts/db/migration-status.test.sh && SUITE com <Classe> = MigrationStatusIntegrationTest`)

**Critério de aceite**
- SHTEST de `scripts/db/migration-status.test.sh` imprime `Failed: 0` e SUITE com `MigrationStatusIntegrationTest` mostra `PASS` em todos os métodos, sem `SKIP`, e `Failed: 0`.
- `scripts/db/migration-status.sh --database centralvet_test` imprime `applied 20261026_0018_billing_order_refund`, e a soma `applied+pending` da última linha é igual a `ls src/app/database/migrations/2*_[0-9][0-9][0-9][0-9]_*.sql | grep -vc verify`.
- `docker compose run --rm --no-deps -T -e DB_DATABASE=centralvet_test -v /var/www/html/centralvet/src:/var/www/html/src:ro app php bin/migration-status.php` imprime as mesmas linhas `applied`/`pending`/`unknown` que o shell com `--database centralvet_test` (`diff` vazio) e o mesmo código de saída.
- `scripts/db/migration-status.sh --database centralvet` (só SELECT no dev) termina com código 0 ou 1 e a última linha começa com `migration-status database=centralvet`.

**Validação**
- `bash scripts/db/migration-status.test.sh` e SUITE com `<Classe>` = `MigrationStatusIntegrationTest` (evidência: `Failed: 0` nas duas, `PASS` em cada método)
- `diff <(scripts/db/migration-status.sh --database centralvet_test) <(docker compose run ... app php bin/migration-status.php 2>/dev/null)` com o comando completo do critério (evidência: nenhuma linha de diferença)
- `scripts/db/migration-status.sh --database centralvet | tail -1` (evidência: linha `migration-status database=centralvet ...`)
- BASHN e LINT de `app/Core/Operations/MigrationStatus.php`, `bin/migration-status.php` e do teste (evidência: nenhuma linha em relação a `baseline/bash-n.txt`; `No syntax errors detected`)

### T-04 — SMTP validado para convite e reset, remetente configurável

**Camada:** backend
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Aang

Hoje o convite (T-13 da Etapa 3) sai pelo `SmtpEmailProvider` (`SMTP_*`, remetente `SMTP_FROM_ADDRESS`), mas o reset de senha sai pelo `MailService` do Adianti, que exige `mail_from` em `SystemPreference` (no dev: "E-mail de origem não configurado", engolido no `error_log`). O reset passa a usar o mesmo provedor, e um servidor SMTP falso em processo prova os dois envios de ponta a ponta. `MailService.php` é protegido por hash e não muda.

**Arquivos prováveis**
- `src/app/Core/Security/PasswordResetMailer.php`
- `src/app/control/admin/SystemRequestPasswordResetForm.php`
- `src/app/config/translations.json`
- `src/tests/Support/FakeSmtpServer.php`
- `src/tests/Integration/SmtpDeliveryIntegrationTest.php`
- `src/tests/Unit/PasswordResetMailerTest.php`
- `src/tests/Unit/PasswordResetFormsWiringTest.php`

**Interface**
- Produz: `final class CentralVet\Security\PasswordResetMailer` com `public const REFERENCE = 'password-reset'`, `__construct(MessageChannelProviderInterface $email, \Closure $translate)` e `send(string $email, string $name, string $link): ?string` — assunto `$translate('Password reset')`; corpo texto `$translate('Hello ^1, a password reset was requested for your Central Vet Pro user. To set a new password, open: ^2', $name, $link)` + `"\n\n"` + `$translate('If you did not request it, ignore this e-mail.')`; propaga `MessageDeliveryFailed`
- Produz: no `SystemRequestPasswordResetForm::onRequest`, a closure de envio chama `(new PasswordResetMailer(EmailProviderFactory::fromEnvironment(JsonLogger::fromEnvironment('password-reset')), Closure::fromCallable('_t')))->send($email, $name, $link)` no lugar de `MailService::send`
- Produz: chaves em `translations.json` (ordem por `en`, só `en`/`pt`): `Hello ^1, a password reset was requested for your Central Vet Pro user. To set a new password, open: ^2` → `Olá ^1, foi solicitada a troca de senha do seu usuário no Central Vet Pro. Para definir uma nova senha, abra: ^2`; `If you did not request it, ignore this e-mail.` → `Se você não fez esta solicitação, ignore este e-mail.`
- Produz: `CentralVet\Tests\Support\FakeSmtpServer` — `static start(): self` (escuta em `127.0.0.1`, porta livre, atende uma sessão SMTP sem TLS por `pcntl_fork` ou `proc_open`), `port(): int` e `stop(): string` (transcrição com os comandos do cliente e o DATA)
- Consome: nada

**Teste RED**
- `src/tests/Integration/SmtpDeliveryIntegrationTest.php`, `src/tests/Support/FakeSmtpServer.php`, `src/tests/Unit/PasswordResetMailerTest.php` — com `new SmtpEmailProvider(new SmtpConfig('127.0.0.1', <porta do fake>, '', '', 'none', 'clinica@example.test', 'Clinica Teste', 5))`, o convite de `AccountInviteService::send` e o e-mail de `PasswordResetMailer::send` chegam ao fake com `MAIL FROM:<clinica@example.test>`, `RCPT TO:<destino>`, `Subject:` com o texto traduzido de `Central Vet Pro invitation` / `Password reset` e o link no corpo; com a porta fechada, `deliver` lança `MessageDeliveryFailed` com `errorCode()` = `smtp_connect`; `PasswordResetMailerTest` (com `FakeEmailProvider`) confere destinatário, referência `password-reset`, assunto e link (comando: `SUITE com <Classe> = SmtpDeliveryIntegrationTest|PasswordResetMailerTest`)

**Critério de aceite**
- SUITE com `SmtpDeliveryIntegrationTest|PasswordResetMailerTest|PasswordResetFormsWiringTest|AccountInviteServiceTest|Etapa3MessagesTest|SecurityMvpMessagesTest` mostra `PASS` em todos os métodos (nenhum `SKIP` em `SmtpDeliveryIntegrationTest`) e `Failed: 0`.
- `grep -c 'MailService::send' src/app/control/admin/SystemRequestPasswordResetForm.php` imprime `0`, e `PasswordResetFormsWiringTest` tem um método que confere `PasswordResetMailer` no form.
- O remetente vem só de `SMTP_FROM_ADDRESS`/`SMTP_FROM_NAME`: `grep -c 'mail_from' src/app/control/admin/SystemRequestPasswordResetForm.php` imprime `0`.

**Validação**
- SUITE com `<Classe>` = `SmtpDeliveryIntegrationTest|PasswordResetMailerTest|PasswordResetFormsWiringTest|AccountInviteServiceTest|Etapa3MessagesTest|SecurityMvpMessagesTest` (evidência: `PASS` em cada método e `Failed: 0`)
- os dois `grep -c` do critério (evidência: `0` e `0`)
- LINT de `app/Core/Security/PasswordResetMailer.php`, `app/control/admin/SystemRequestPasswordResetForm.php` e dos 4 arquivos de teste; `python3 -m json.tool src/app/config/translations.json > /dev/null` (evidência: `No syntax errors detected` em todos; json válido)

### T-05 — Artefato de release por commit e fumaça pós-implantação

**Camada:** infra
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Batman

Release a partir de commit identificado, nunca do working tree: o pacote (para a hospedagem compartilhada) sai do `git archive` com `vendor/` instalado no `composer:2.8.11`, e a imagem (para Docker/VPS) é construída a partir do mesmo export com a tag = SHA. O manifesto registra commit, migrations do commit e alvo de PHP (paridade). A fumaça HTTP pós-implantação é o mínimo de cada release e do ensaio de rollback (T-15).

**Arquivos prováveis**
- `scripts/release/build-release.sh`
- `scripts/release/build-release.test.sh`
- `scripts/release/smoke.sh`
- `scripts/release/smoke.test.sh`

**Interface**
- Produz: `scripts/release/build-release.sh [--image] <commit>` — resolve o commit com `git rev-parse --verify` e `git cat-file -t` = `commit` (código 2 se não existe); exporta o commit por `git archive`; roda `composer install --no-dev --no-interaction --prefer-dist --no-scripts --no-progress` no `composer:2.8.11` sobre o `src/` exportado; grava `var/releases/centralvet-<sha7>.tar.gz` (com `src/` e `RELEASE_MANIFEST`) e `var/releases/centralvet-<sha7>.tar.gz.sha256`; última linha `release built: var/releases/centralvet-<sha7>.tar.gz commit=<sha40>`
- Produz: `RELEASE_MANIFEST` com `commit=<sha40>`, `built_at=<UTC ISO-8601>`, `php_target=8.4`, `composer_lock_sha256=<hex>` e `migration <versão> sha256=<hex>` para cada migration numerada do commit
- Produz: `--image` constrói `centralvet/app:<sha40>` e `centralvet/worker:<sha40>` com `docker build -f docker/php/Dockerfile --build-arg APP_BUILD_ID=<sha40>` sobre o export do commit (nunca o working tree); `RELEASE_REPO` (padrão: raiz do projeto) e `RELEASE_OUT_DIR` (padrão `var/releases`) sobrescrevem origem e destino
- Produz: `scripts/release/smoke.sh <base-url>` — checks `live` (GET `/live` → 200), `health` (GET `/health` → 200), `landing` (GET `/` → 200), `login` (GET `/index.php?class=LoginForm` → 200), `download_blocked` (GET `/download.php?file=app/config/application.php` → 404) e `config_blocked` (GET `/app/config/application.php` ≠ 200); uma linha `smoke pass <check>` ou `smoke fail <check> status=<código>` por check; última linha `smoke passed=<n> failed=<n>`; código 1 com `failed>0`
- Consome: nada

**Teste RED**
- `scripts/release/build-release.test.sh`, `scripts/release/smoke.test.sh` — build: num repositório git temporário (`RELEASE_REPO`) com um commit que tem `src/composer.lock` e duas migrations numeradas, com `docker` falso, gera o `.tar.gz` cujo `RELEASE_MANIFEST` tem `commit=` igual ao `git rev-parse HEAD` do repositório temporário e 2 linhas `migration `; um arquivo alterado e não commitado não entra no tar; commit inexistente sai 2; `sha256sum -c` do `.sha256` passa; smoke: com `curl` falso, 6 linhas `smoke pass` e código 0; com `/health` respondendo 503, `smoke fail health status=503` e código 1 (comando: `bash scripts/release/build-release.test.sh && bash scripts/release/smoke.test.sh`)

**Critério de aceite**
- Os dois SHTEST imprimem `Failed: 0`.
- `scripts/release/build-release.sh HEAD` gera `var/releases/centralvet-<sha7>.tar.gz`; `tar -xOzf <arquivo> RELEASE_MANIFEST | grep -c '^migration '` é igual a `git ls-tree --name-only HEAD src/app/database/migrations/ | grep -E '/2[0-9]+_[0-9]+_.*[.]sql$' | grep -vc verify`; `tar -tzf <arquivo> | grep -c '^src/vendor/autoload.php$'` imprime `1`; `sha256sum -c` do `.sha256` termina com código 0.

**Validação**
- `bash scripts/release/build-release.test.sh && bash scripts/release/smoke.test.sh` (evidência: `Failed: 0` nas duas)
- `scripts/release/build-release.sh HEAD` seguido dos três comandos do critério (evidência: contagens iguais, `1`, código 0 do `sha256sum -c`)
- BASHN (evidência: nenhuma linha em relação a `baseline/bash-n.txt`)

### T-06 — Fumaça da contratação versionada em `scripts/smoke/billing/`

**Camada:** qa
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Maquiavel

Pendência da Etapa 3 (T-22, onda 6): o roteiro de fumaça (`.claude/tasks/mar-20261021-1554-etapa-3-contratacao/reports/T-22-fumaca.md`) dependia de três arquivos do scratchpad, que já não existem. Versiona o gerador de eventos assinados, o link de convite e a limpeza dos dados `ZZ-E3-`, e move o roteiro para um runbook com as lacunas apontadas: tempo mínimo de 3 s do `LeadFormToken`, limite de pedidos por IP (429) e limpeza da chave do limitador no Redis, e asserções filtradas por path em vez de `calls[1]`. A task só escreve arquivos: não roda a fumaça no dev.

**Arquivos prováveis**
- `scripts/smoke/billing/webhook-event.php`
- `scripts/smoke/billing/invite-link.php`
- `scripts/smoke/billing/cleanup-zz-e3.sql`
- `docs/runbooks/smoke-billing.md`
- `src/tests/Unit/SmokeWebhookEventTest.php`

**Interface**
- Produz: `php scripts/smoke/billing/webhook-event.php <tipo> <objeto-json> [--send <url>]` — `<tipo>` ∈ `checkout.session.completed`, `checkout.session.async_payment_succeeded`, `checkout.session.async_payment_failed`, `checkout.session.expired`, `invoice.paid`, `invoice.payment_failed`, `payment_intent.succeeded`, `charge.refunded`; monta `{"id":"evt_zz_e3_<aleatório>","object":"event","type":<tipo>,"created":<agora>,"livemode":false,"data":{"object":<objeto-json>}}`; assina com `StripeSignatureVerifier::header($payload, getenv('STRIPE_WEBHOOK_SECRET'), time())`; imprime `event_id=<id>`, `payload=<json em uma linha>` e `stripe_signature=<t=...,v1=...>`; com `--send`, faz o POST com o cabeçalho `Stripe-Signature` e imprime `HTTP <status> <corpo>`; código 2 com `STRIPE_WEBHOOK_SECRET` vazio ou tipo fora da lista; carrega `src/vendor/autoload.php` relativo ao próprio arquivo
- Produz: `php scripts/smoke/billing/invite-link.php <email>` (lê do banco do ambiente o pedido e imprime o link de convite, como o `t22_invite_link.php` do roteiro) e `scripts/smoke/billing/cleanup-zz-e3.sql` (limpeza dos dados `ZZ-E3-` com o bloco "6. Prova" só de `SELECT`)
- Produz: `docs/runbooks/smoke-billing.md` — o roteiro de `T-22-fumaca.md` com `$SCR` trocado por `scripts/smoke/billing`, as lacunas acima e o aviso de que roda só com autorização para o banco de dev
- Consome: nada

**Teste RED**
- `src/tests/Unit/SmokeWebhookEventTest.php` — um subprocesso `php /var/www/html/scripts/smoke/billing/webhook-event.php invoice.paid '{"id":"in_zz_e3_t"}'` com `STRIPE_WEBHOOK_SECRET=whsec_test_etapa4` imprime `payload=` e `stripe_signature=` que `(new StripeSignatureVerifier('whsec_test_etapa4'))->verify(<payload>, <assinatura>, time())` aceita, e o `payload` tem `"type":"invoice.paid"` e `"livemode":false`; sem o segredo, ou com o tipo `customer.created`, o código de saída é 2 (comando: `SUITE com <Classe> = SmokeWebhookEventTest`)

**Critério de aceite**
- SUITE com `SmokeWebhookEventTest` mostra `PASS` em todos os métodos e `Failed: 0`.
- `grep -c '\$SCR' docs/runbooks/smoke-billing.md` imprime `0`; o runbook cita `LeadFormToken`, `429` e a chave do limitador no Redis (`grep -c` de cada um ≥ 1).
- `cleanup-zz-e3.sql` só apaga linhas com marcador `ZZ-E3-`/`zz_e3`/`zz-e3`: `grep -iE '^(DELETE|UPDATE)' scripts/smoke/billing/cleanup-zz-e3.sql | grep -viE "zz[-_]e3" | wc -l` imprime `0`.

**Validação**
- SUITE com `<Classe>` = `SmokeWebhookEventTest` (evidência: `PASS` em cada método e `Failed: 0`)
- os `grep` do critério (evidência: `0`, contagens ≥ 1, `0`)
- LINT de `tests/Unit/SmokeWebhookEventTest.php`; `php -l scripts/smoke/billing/webhook-event.php scripts/smoke/billing/invite-link.php` no host (evidência: `No syntax errors detected`)

### T-07 — Cookies Secure atrás do proxy (`RequestScheme`)

**Camada:** backend
**Dependências:** T-02
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Yoda

Hoje o cookie de sessão (`PHPSESSID`) nunca sai com `Secure`, e `ApplicationAuthenticationService::isHttps()` confia em `X-Forwarded-Proto` de qualquer cliente. O esquema da requisição passa a vir de `HTTPS` (que o nginx de T-02 preenche a partir do proxy da frente), e `X-Forwarded-Proto` só conta com `TRUST_FORWARDED_PROTO=true` (hospedagem sem nginx). `engine.php` e `index.php` são protegidos por hash: o ajuste do cookie de sessão entra em `SessionHandlerFactory::createFromEnvironment()`, que ambos chamam antes de `new TSession`. Em HTTP (dev :8081) nada muda.

**Arquivos prováveis**
- `src/app/Core/Security/RequestScheme.php`
- `src/app/Core/Session/SessionHandlerFactory.php`
- `src/app/service/auth/ApplicationAuthenticationService.php`
- `src/tests/Unit/RequestSchemeTest.php`

**Interface**
- Produz: `final class CentralVet\Security\RequestScheme` com `public static function isHttps(array $server, bool $trustForwardedProto): bool` (true com `HTTPS` não vazio e diferente de `off`, sem diferenciar maiúsculas; senão, só com `$trustForwardedProto` e `HTTP_X_FORWARDED_PROTO` = `https`), `public static function current(): bool` (`isHttps($_SERVER, getenv('TRUST_FORWARDED_PROTO') === 'true')`) e `public static function secureSessionCookie(bool $https): void` (`ini_set('session.cookie_secure', '1')` quando true; nada quando false)
- Produz: primeira instrução de `SessionHandlerFactory::createFromEnvironment()`: `if (PHP_SAPI !== 'cli') { RequestScheme::secureSessionCookie(RequestScheme::current()); }`; `ApplicationAuthenticationService::isHttps()` devolve `RequestScheme::current()` e não lê mais `HTTP_X_FORWARDED_PROTO`
- Consome: T-02 `TRUST_FORWARDED_PROTO`, `fastcgi_param HTTPS $cv_forwarded_https if_not_empty;`

**Teste RED**
- `src/tests/Unit/RequestSchemeTest.php` — `isHttps(['HTTP_X_FORWARDED_PROTO' => 'https'], false)` é false e com `true` é true; `['HTTPS' => 'on']` e `['HTTPS' => 'ON']` são true; `['HTTPS' => 'off']` e `['HTTPS' => '']` são false; um subprocesso `php -r` que chama `RequestScheme::secureSessionCookie(true)` imprime `1` em `ini_get('session.cookie_secure')` e, com `false`, imprime o valor anterior (vazio ou `0`); o fonte de `ApplicationAuthenticationService.php` não contém `HTTP_X_FORWARDED_PROTO`; o fonte de `SessionHandlerFactory::createFromEnvironment` contém `RequestScheme::secureSessionCookie(RequestScheme::current())` antes do primeiro `return` (comando: `SUITE com <Classe> = RequestSchemeTest`)

**Critério de aceite**
- SUITE com `RequestSchemeTest|MutationGuardWiringTest|SessionRedisIntegrationTest|SubscriptionAccessGuardTest` mostra `PASS` em todos os métodos e `Failed: 0`.
- `grep -c 'HTTP_X_FORWARDED_PROTO' src/app/service/auth/ApplicationAuthenticationService.php` imprime `0`.
- Em HTTP sem `HTTPS` e sem `TRUST_FORWARDED_PROTO`, o subprocesso do teste mantém `session.cookie_secure` fora de `1` (o login do dev em `http://localhost:8081` continua recebendo o cookie de sessão).

**Validação**
- SUITE com `<Classe>` = `RequestSchemeTest|MutationGuardWiringTest|SessionRedisIntegrationTest|SubscriptionAccessGuardTest` (evidência: `PASS` em cada método e `Failed: 0`)
- `grep -c 'HTTP_X_FORWARDED_PROTO' src/app/service/auth/ApplicationAuthenticationService.php` (evidência: `0`)
- Review Focus: o método do `RequestSchemeTest` com `$_SERVER` sem `HTTPS` e `TRUST_FORWARDED_PROTO` ausente (evidência: `PASS`, valor diferente de `1`)
- LINT de `app/Core/Security/RequestScheme.php`, `app/Core/Session/SessionHandlerFactory.php`, `app/service/auth/ApplicationAuthenticationService.php` e do teste (evidência: `No syntax errors detected`)

### T-08 — Backup completo: banco, arquivos, documentos e MinIO, cifrado, com retenção e cópia externa

**Camada:** infra
**Dependências:** T-01, T-02
**Paralelizável:** sim
**Complexidade:** alta
**Agente:** Naruto

Reescreve `scripts/backup.sh` (hoje só `mysqldump` → `.sql.gz`) para um conjunto coerente: banco, volume `app_files` (`src/files`), volume `app_documents` (`var/documents`) e, se existir, o volume do MinIO; manifesto com checksums e contagens para a restauração verificar; cifragem simétrica com `gpg`; retenção; cópia externa por diretório montado ou S3-compatível; heartbeat em `ops_heartbeat` para o alerta de "backup ausente". Os arquivos são lidos por `docker compose run --rm --no-deps -T --entrypoint tar app` (funciona com o app parado; a imagem tem `tar`). `gpg`, `openssl`, `sha256sum` e `aws` rodam no host.

**Arquivos prováveis**
- `scripts/backup.sh`
- `scripts/backup.test.sh`

**Interface**
- Produz: `scripts/backup.sh [--project <nome>] [--env-file <arquivo>] [--no-heartbeat]` — `--project` vira `docker compose -p <nome>` e `--env-file` vira `--env-file <arquivo>` em toda chamada; variáveis lidas do ambiente e, na falta, do env-file (padrão `.env`) sem `source`: `BACKUP_DIR` (padrão `var/backups`), `BACKUP_PASSPHRASE_FILE`, `BACKUP_RETENTION_DAYS` (padrão 14), `BACKUP_OFFSITE_DIR`, `BACKUP_OFFSITE_S3_URI`, `BACKUP_OFFSITE_S3_ENDPOINT`
- Produz: arquivo `$BACKUP_DIR/centralvet-<AAAAMMDDTHHMMSSZ>.tar.gpg` — `gpg --batch --pinentry-mode loopback --passphrase-file "$BACKUP_PASSPHRASE_FILE" --symmetric --cipher-algo AES256` de um tar com `MANIFEST`, `db.sql.gz`, `files.tar.gz`, `documents.tar.gz` e, se o volume `<projeto>_minio_data` existe, `minio.tar.gz`
- Produz: `MANIFEST` com as linhas `format=centralvet-backup-v1`, `created_at=<UTC ISO-8601>`, `commit=<git rev-parse HEAD ou unknown>`, `project=<nome>`, `mysql_database=<nome>`, `minio=included` ou `minio=skipped`, `table_count=<n>`, `rows <tabela>=<n>` para `tenant`, `system_users`, `billing_order`, `communication_message` e `generated_document`, `files_count=<n>`, `documents_count=<n>`, `migration <versão>` por linha de `schema_migrations` e `sha256 <hex> <parte>` por parte
- Produz: saída `backup: pruned <arquivo>`, `backup: offsite copy <destino>`, `backup: heartbeat not recorded` (stderr) e última linha `Backup created: <caminho> parts=<n> bytes=<n>`; códigos 0 (criado), 2 (configuração: `BACKUP_PASSPHRASE_FILE` ausente ou ilegível, opção inválida), 3 (falha de dump ou de arquivo; nada parcial fica em `$BACKUP_DIR`), 4 (cópia externa falhou; o arquivo local fica)
- Produz: retenção — apaga `centralvet-*.tar.gpg` de `$BACKUP_DIR` com mais de `BACKUP_RETENTION_DAYS` dias, nunca o recém-criado; não toca os `.sql.gz` legados
- Produz: heartbeat — sem `--no-heartbeat`, `INSERT INTO ops_heartbeat (job, last_success_at, detail) VALUES ('backup', NOW(6), '<arquivo>') ON DUPLICATE KEY UPDATE last_success_at = VALUES(last_success_at), detail = VALUES(detail)` com `MYSQL_USER`/`MYSQL_PASSWORD` do container mysql; falha do INSERT não muda o código 0
- Consome: T-02 `BACKUP_PASSPHRASE_FILE`, `BACKUP_RETENTION_DAYS`, `BACKUP_OFFSITE_DIR`, `BACKUP_OFFSITE_S3_URI`, `BACKUP_OFFSITE_S3_ENDPOINT`, `var/drill/`; T-01 `ops_heartbeat`

**Teste RED**
- `scripts/backup.test.sh` — com `docker` falso (dump e tars canônicos, registro das chamadas) e `gpg` real com `GNUPGHOME` temporário: o `.tar.gpg` decifra com a frase do teste e traz `MANIFEST`, `db.sql.gz`, `files.tar.gz` e `documents.tar.gz` com `sha256` que confere; sem `BACKUP_PASSPHRASE_FILE`, sai 2 e não cria arquivo; um `centralvet-20200101T000000Z.tar.gpg` com mtime de 20 dias é apagado e o novo fica; `BACKUP_OFFSITE_DIR` recebe cópia com o mesmo sha256; o docker falso registra o INSERT em `ops_heartbeat`; com o INSERT falhando, sai 0 e imprime `backup: heartbeat not recorded`; com `--no-heartbeat`, nenhum INSERT; sem o volume do MinIO, o MANIFEST tem `minio=skipped`; `--project centralvet-drill` aparece em toda chamada `docker compose` (comando: `bash scripts/backup.test.sh`)

**Critério de aceite**
- SHTEST de `scripts/backup.test.sh` imprime `Failed: 0`.
- No dev, `umask 077; mkdir -p var/drill/backups; openssl rand -base64 48 > var/drill/passphrase` seguido de `BACKUP_DIR=var/drill/backups BACKUP_PASSPHRASE_FILE=var/drill/passphrase scripts/backup.sh --no-heartbeat` termina com `Backup created: var/drill/backups/centralvet-<data>.tar.gpg parts=<n> bytes=<n>`; decifrado, o `MANIFEST` tem `format=centralvet-backup-v1`, `rows tenant=` igual a `SELECT COUNT(*) FROM tenant` do dev e um `sha256` por parte que confere com `sha256sum`.
- O dev não recebe escrita: `SELECT COUNT(*) FROM centralvet.schema_migrations` igual antes e depois, e nenhum `INSERT` no log de chamadas da execução com `--no-heartbeat`.

**Validação**
- `bash scripts/backup.test.sh` (evidência: `Failed: 0`)
- a execução real do critério, depois `gpg --batch --pinentry-mode loopback --passphrase-file var/drill/passphrase -d <arquivo> | tar -xO MANIFEST` e `sha256sum` das partes extraídas em diretório temporário (evidência: linhas `format=`, `rows tenant=`, `sha256` iguais)
- Review Focus: o caso `minio=skipped` do SHTEST e, no dev, `docker volume inspect centralvet_minio_data` decide `minio=included` ou `skipped` no MANIFEST real (evidência: linha `minio=` coerente com o volume)
- BASHN (evidência: nenhuma linha em relação a `baseline/bash-n.txt`)

### T-09 — Preflight de produção e paridade de ambiente

**Camada:** backend
**Dependências:** T-02, T-03
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Jaspion

Checagem automatizada da configuração pública antes de cada implantação: debug desligado, URL pública em https, segredos fora dos padrões de dev, remetente e operador configurados, storage sem credencial do MinIO local, proxies confiáveis estreitos e paridade de PHP/extensões com a imagem homologada (PHP 8.4). Nunca imprime valores. Roda nos dois alvos: no container (`docker compose run ... app php bin/preflight.php`) e no CLI da hospedagem compartilhada (carrega `init.php`, que lê `hosting-environment.php`). Com `--with-db`, confere também a versão do MySQL (8.0 no Docker, 5.7 na hospedagem) e as migrations pendentes pelo `MigrationStatus` de T-03.

**Arquivos prováveis**
- `src/app/Core/Operations/PreflightFinding.php`
- `src/app/Core/Operations/ProductionPreflight.php`
- `src/bin/preflight.php`
- `src/tests/Unit/ProductionPreflightTest.php`

**Interface**
- Produz: `final class CentralVet\Operations\PreflightFinding` com `public const PASS = 'PASS'`, `WARN = 'WARN'`, `FAIL = 'FAIL'` e `__construct(public readonly string $level, public readonly string $code)`
- Produz: `final class CentralVet\Operations\ProductionPreflight` com `__construct(array $env, array $ini, string $phpVersion, array $extensions)` e `run(?string $trustedProxiesFile = null, ?PDO $pdo = null): array` (list de `PreflightFinding`, um por código, nesta ordem): `app_env` (FAIL se `APP_ENV` ≠ `production`), `app_debug` (FAIL se `APP_DEBUG` = `true`), `display_errors` (FAIL se `$ini['display_errors']` liga), `public_url_https` (FAIL se `APP_PUBLIC_URL` não começa com `https://`), `app_seed` (FAIL com menos de 32 caracteres ou igual ao valor de `.env.example`), `db_password` (FAIL vazio ou começando com `change-this`), `redis_password` (WARN vazio com `SESSION_DRIVER` ou `RATE_LIMIT_DRIVER` = `redis`), `smtp_driver` (FAIL se `COMMUNICATION_EMAIL_DRIVER` ≠ `smtp`), `smtp_from` (FAIL vazio ou terminando em `.invalid`), `operator_email` (FAIL se `BILLING_OPERATOR_EMAIL` vazio), `storage_credentials` (FAIL com `STORAGE_DRIVER` ou `DOCUMENT_STORAGE_DRIVER` = `s3` e `S3_ACCESS_KEY` vazio ou `minioadmin`, ou `S3_SECRET_KEY` vazio ou `minioadmin123`), `stripe_secret_key` (FAIL vazio; WARN sem prefixo `sk_live_`/`rk_live_`), `stripe_webhook_secret` (FAIL vazio), `trust_forwarded_proto` (WARN com `TRUST_FORWARDED_PROTO` = `true`), `ai_provider` (FAIL com `AI_PROVIDER` = `stub`), `php_version` (FAIL fora de `8.4.*`), `php_extensions` (FAIL sem algum de `pdo_mysql`, `intl`, `mbstring`, `gd`, `zip`, `bcmath`, `curl`, `openssl`; e sem `redis` quando algum driver é `redis`) e `trusted_proxies` (só com arquivo: FAIL ilegível ou com `0.0.0.0/0`, `10.0.0.0/8`, `172.16.0.0/12` ou `192.168.0.0/16` num `set_real_ip_from` não comentado)
- Produz: com PDO informado (o CLI passa o da aplicação só com `--with-db`), mais dois achados ao fim: `db_version` (FAIL se `SELECT VERSION()` não começa com `8.0.` nem `5.7.`; WARN em `5.7.` sem `DB_STRICT_MODE=true`) e `migrations_pending` (FAIL com `pending` ou `unknown` não vazio no `MigrationStatus::report()` sobre `app/database/migrations`)
- Produz: `php bin/preflight.php [--trusted-proxies-file=<caminho>] [--with-db]` — só CLI (`PHP_SAPI`), carrega `init.php`, monta com `getenv()`, `ini_get_all(null, false)`, `PHP_VERSION` e `get_loaded_extensions()`; imprime `<NÍVEL> <código>` por achado e a última linha `preflight fail=<n> warn=<n> pass=<n>`; código 1 com `fail>0`, 0 senão
- Consome: T-02 `TRUST_FORWARDED_PROTO`, `NGINX_TRUSTED_PROXIES_FILE`; T-03 `final class CentralVet\Operations\MigrationStatus`, `report(): array`

**Teste RED**
- `src/tests/Unit/ProductionPreflightTest.php` — um env de produção fictício completo (prefixos `sk_` + `live_` montados por concatenação) dá 0 `FAIL`; cada regra isolada vira `FAIL` com o próprio código: `APP_DEBUG=true` → `app_debug`, `SMTP_FROM_ADDRESS=no-reply@example.invalid` → `smtp_from`, `S3_ACCESS_KEY=minioadmin` → `storage_credentials`, versão `8.3.0` → `php_version`, sem `intl` → `php_extensions`, arquivo temporário com `set_real_ip_from 10.0.0.0/8;` → `trusted_proxies`, o mesmo com a linha comentada → `trusted_proxies` PASS; com um PDO de teste (`NullPdo` ou dublê) cujo `SELECT VERSION()` devolve `5.6.51` → `db_version` FAIL e `5.7.44` com `DB_STRICT_MODE=true` → `db_version` PASS; sem PDO, nenhum achado `db_version`/`migrations_pending`; um subprocesso `php bin/preflight.php` com `APP_SEED` e `STRIPE_SECRET_KEY` fictícios no ambiente não imprime nenhum dos dois valores e termina com a linha `preflight fail=` (comando: `SUITE com <Classe> = ProductionPreflightTest`)

**Critério de aceite**
- SUITE com `ProductionPreflightTest` mostra `PASS` em todos os métodos e `Failed: 0`.
- No dev, `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro -v /var/www/html/centralvet/docker/nginx/trusted-proxies.dev.conf:/tmp/tp.conf:ro app php bin/preflight.php --trusted-proxies-file=/tmp/tp.conf` termina com código 1 e imprime `FAIL app_env`, `FAIL trusted_proxies`, `PASS php_version` e a última linha `preflight fail=<n> warn=<n> pass=<n>`; com `-e DB_DATABASE=centralvet_test` e `--with-db`, imprime `PASS db_version` e `migrations_pending` coerente com `scripts/db/migration-status.sh --database centralvet_test`; a saída não contém o valor de `APP_SEED` do `.env` (conferir com `grep -cF` do valor lido em memória, sem imprimi-lo).

**Validação**
- SUITE com `<Classe>` = `ProductionPreflightTest` (evidência: `PASS` em cada método e `Failed: 0`)
- o `docker compose run ... php bin/preflight.php` do critério, com `echo $?` (evidência: `1`, as três linhas citadas e a linha final)
- LINT de `app/Core/Operations/PreflightFinding.php`, `app/Core/Operations/ProductionPreflight.php`, `bin/preflight.php` e do teste (evidência: `No syntax errors detected`)

### T-10 — Heartbeat de jobs e alerta ao operador com deduplicação

**Camada:** backend
**Dependências:** T-01
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Platão

Contratos e persistência que o monitor (T-12), o reconciliador e a retenção (T-13) usam. O alerta reaproveita o canal da T-31 da Etapa 3: e-mail ao `BILLING_OPERATOR_EMAIL` pelo `MessageChannelProviderInterface` (`EmailProviderFactory`), sem PII no corpo, com deduplicação por chave para o cron de 15 min não repetir o mesmo alerta. `LoginConflictRefundService::alertOperator` não muda (regressão de dinheiro fora do escopo).

**Arquivos prováveis**
- `src/app/Core/Operations/Contract/HeartbeatRepositoryInterface.php`
- `src/app/Core/Operations/Contract/AlertStateRepositoryInterface.php`
- `src/app/Core/Persistence/PdoHeartbeatRepository.php`
- `src/app/Core/Persistence/PdoAlertStateRepository.php`
- `src/app/Core/Operations/OperatorAlertNotifier.php`
- `src/tests/Support/FakeHeartbeatRepository.php`
- `src/tests/Support/FakeAlertStateRepository.php`
- `src/tests/Unit/OperatorAlertNotifierTest.php`
- `src/tests/Integration/OpsRepositoriesIntegrationTest.php`

**Interface**
- Produz: `interface CentralVet\Operations\Contract\HeartbeatRepositoryInterface` com `JOB_BILLING_RECONCILE = 'billing-reconcile'`, `JOB_BACKUP = 'backup'`, `JOB_OPS_MONITOR = 'ops-monitor'`, `JOB_BILLING_EVENT_RETENTION = 'billing-event-retention'`, `record(string $job, DateTimeImmutable $at, string $detail): void` e `lastSuccess(string $job): ?DateTimeImmutable`
- Produz: `interface CentralVet\Operations\Contract\AlertStateRepositoryInterface` com `lastSentAt(string $alertKey): ?DateTimeImmutable` e `markSent(string $alertKey, string $subject, DateTimeImmutable $at): void`
- Produz: `CentralVet\Persistence\PdoHeartbeatRepository(PDO $pdo)` sobre `ops_heartbeat` (upsert `ON DUPLICATE KEY UPDATE`; `detail` cortado em 190 caracteres; horários no fuso do PHP, formato `Y-m-d H:i:s.u`) e `CentralVet\Persistence\PdoAlertStateRepository(PDO $pdo)` sobre `ops_alert_state` (`markSent` incrementa `sent_count`)
- Produz: `final class CentralVet\Operations\OperatorAlertNotifier` com `RESEND_AFTER_SECONDS = 21600`, `__construct(AlertStateRepositoryInterface $state, MessageChannelProviderInterface $email, string $operatorEmail)` e `notify(string $alertKey, string $subject, string $body, DateTimeImmutable $now): bool` — entrega `OutgoingMessage(trim($operatorEmail), $subject, $body, 'ops-alert-' . $alertKey)` e grava `markSent`, devolvendo true; com e-mail vazio, `error_log('[ops-alert] ' . json_encode(['event' => 'ops_alert_no_recipient', 'key' => $alertKey]))` e false; a menos de 21600 s do último envio da chave, false sem entregar; com `MessageDeliveryFailed`, `error_log` com `ops_alert_delivery_failed`, a chave e `errorCode()`, false e estado intacto
- Produz: `CentralVet\Tests\Support\FakeHeartbeatRepository` (`record`, `lastSuccess`, `records(): array` com `job`, `at`, `detail`) e `CentralVet\Tests\Support\FakeAlertStateRepository` (implementa a interface em memória)
- Consome: T-01 `ops_heartbeat`, `ops_alert_state`

**Teste RED**
- `src/tests/Unit/OperatorAlertNotifierTest.php`, `src/tests/Integration/OpsRepositoriesIntegrationTest.php` — notifier (com `FakeEmailProvider` e `FakeAlertStateRepository`): o primeiro `notify('backup_stale', ...)` entrega 1 mensagem ao operador com referência `ops-alert-backup_stale` e devolve true; outro 1 h depois devolve false sem entregar; outro 7 h depois entrega; com operador vazio, 0 entregas e false; com `failWith('smtp_connect')`, false e `lastSentAt` continua null; integração no `centralvet_test`: `record` duas vezes do mesmo job deixa 1 linha com o segundo horário e `lastSuccess` devolve esse horário; `markSent` duas vezes deixa `sent_count = 2` (comando: `SUITE com <Classe> = OperatorAlertNotifierTest|OpsRepositoriesIntegrationTest`)

**Critério de aceite**
- SUITE com `OperatorAlertNotifierTest|OpsRepositoriesIntegrationTest` mostra `PASS` em todos os métodos, sem `SKIP` na classe de integração, e `Failed: 0`.
- `grep -c 'ops-alert-' src/app/Core/Operations/OperatorAlertNotifier.php` ≥ 1 e o corpo do e-mail é só o `$body` recebido (o notifier não acrescenta dado de cliente).

**Validação**
- SUITE com `<Classe>` = `OperatorAlertNotifierTest|OpsRepositoriesIntegrationTest` (evidência: `PASS` em cada método e `Failed: 0`)
- Review Focus: o método de reenvio dentro de 6 h do `OperatorAlertNotifierTest` (evidência: `PASS`)
- LINT dos 5 arquivos de produção e dos 4 de teste (evidência: `No syntax errors detected`)

### T-11 — Restauração verificada e ensaio em projeto isolado

**Camada:** infra
**Dependências:** T-08
**Paralelizável:** sim
**Complexidade:** alta
**Agente:** Spock

`scripts/restore.sh` passa a restaurar o conjunto de T-08 (banco, arquivos, documentos, MinIO) e o `.tar.gz.cvbk` da hospedagem compartilhada (T-17) em qualquer projeto compose, verificando checksums antes de qualquer escrita; o `.sql.gz` legado segue aceito. `scripts/restore-drill.sh` restaura num projeto isolado (`centralvet-drill`, volumes próprios, env próprio em `var/drill/.env`), mede o tempo de cada fase, confere as contagens do MANIFEST contra o restaurado e derruba o projeto. Nunca toca o projeto `centralvet` sem a confirmação dele.

**Arquivos prováveis**
- `scripts/restore.sh`
- `scripts/restore.test.sh`
- `scripts/restore-drill.sh`
- `Makefile`

**Interface**
- Produz: `scripts/restore.sh [--project <nome>] [--env-file <arquivo>] <arquivo>` — aceita `centralvet-*.tar.gpg` (`format=centralvet-backup-v1`, frase em `BACKUP_PASSPHRASE_FILE`), `centralvet-*.tar.gz.cvbk` (decifrado por `docker compose run --rm --no-deps -T app php bin/hosting-backup.php --decrypt` com o arquivo montado; falha de autenticação → código 5) e `*.sql.gz` legado, ambos dentro de `$BACKUP_DIR` (padrão `var/backups`); exige `CONFIRM_RESTORE=RESTORE_<PROJETO>` (nome do projeto em maiúsculas com `-` → `_`; padrão `RESTORE_CENTRALVET`), senão código 3; frase errada ou `sha256` divergente do MANIFEST → código 5 antes de qualquer comando de escrita no Docker; restaura o banco (`mysql` como root do container), `files.tar.gz` em `/var/www/html/src/files`, `documents.tar.gz` em `/var/www/html/var/documents` (substituindo o conteúdo) e `minio.tar.gz` se `minio=included`; última linha `Restore completed from: <arquivo> project=<nome> parts=<n>`
- Produz: `scripts/restore-drill.sh [--keep] <arquivo>` — projeto `centralvet-drill`, env-file `var/drill/.env` gerado com segredos aleatórios (`openssl rand`), `COMPOSE_PROJECT_NAME=centralvet-drill` e `HTTP_PORT=18081`; sobe `mysql redis` com `--wait`; chama `restore.sh --project centralvet-drill --env-file var/drill/.env` com `CONFIRM_RESTORE=RESTORE_CENTRALVET_DRILL`; imprime `restore-drill verify <item> manifest=<n> restored=<n>` para `table_count`, cada `rows <tabela>`, `files_count`, `documents_count` e `migrations`; última linha `restore-drill done elapsed_seconds=<n> decrypt_seconds=<n> db_seconds=<n> files_seconds=<n> mismatches=<n>`; código 1 com `mismatches>0`; no fim, `down -v` só do `centralvet-drill` (salvo `--keep`)
- Produz: alvo `restore-drill` no `Makefile` (`./scripts/restore-drill.sh "$(FILE)"`, com a mesma mensagem de uso do alvo `restore`)
- Consome: T-17 `php bin/hosting-backup.php --decrypt <entrada> <saída>`, `centralvet-<AAAAMMDDTHHMMSSZ>.tar.gz.cvbk`; T-08 `format=centralvet-backup-v1`, `table_count=<n>`, `files_count=<n>`, `documents_count=<n>`, `sha256 <hex> <parte>`, `minio=included`, `scripts/backup.sh [--project <nome>] [--env-file <arquivo>] [--no-heartbeat]`

**Teste RED**
- `scripts/restore.test.sh` — com `docker` falso e um `.tar.gpg` montado no próprio teste no formato de T-08: sem `CONFIRM_RESTORE`, sai 3; com uma parte adulterada, sai 5 e o docker falso não recebe `mysql` nem `tar -x`; com a confirmação, o conteúdo descompactado de `db.sql.gz` chega ao `mysql` e `files.tar.gz` ao `tar -x` do projeto; com `--project centralvet-drill`, toda chamada leva `-p centralvet-drill` e a confirmação exigida é `RESTORE_CENTRALVET_DRILL`; o `.sql.gz` legado segue restaurando; um `.tar.gz.cvbk` passa pelo `--decrypt` (simulado pelo docker falso) e restaura as mesmas partes, e o `--decrypt` falhando dá código 5 sem escrita; `restore-drill.sh` com o docker falso só usa `-p centralvet-drill`, imprime `restore-drill done` e termina com `down -v` desse projeto (comando: `bash scripts/restore.test.sh`)

**Critério de aceite**
- SHTEST de `scripts/restore.test.sh` imprime `Failed: 0`.
- `BACKUP_DIR=var/drill/backups BACKUP_PASSPHRASE_FILE=var/drill/passphrase scripts/restore-drill.sh var/drill/backups/<arquivo de T-08>` imprime `restore-drill done ... mismatches=0` e termina com código 0; depois, `docker volume ls -q --filter name=centralvet-drill` não lista nada e `docker compose ps --services --status running` do projeto `centralvet` lista os mesmos serviços de antes.

**Validação**
- `bash scripts/restore.test.sh` (evidência: `Failed: 0`)
- o `restore-drill.sh` real do critério, com `echo $?` (evidência: linhas `restore-drill verify` com `manifest=` igual a `restored=`, linha final com `mismatches=0`, código 0)
- Review Focus: `restore.sh` com a frase errada contra o `.tar.gpg` real (`BACKUP_PASSPHRASE_FILE` apontando para um arquivo com outra frase, `--project centralvet-drill`) sai 5 e `docker compose -p centralvet-drill ps -q` continua vazio (evidência: código 5)
- `docker volume ls -q --filter name=centralvet-drill` e `docker compose ps` antes e depois (evidência: lista vazia; serviços do dev iguais)
- BASHN (evidência: nenhuma linha em relação a `baseline/bash-n.txt`)

### T-12 — Monitor operacional e alertas (`bin/ops-monitor.php`)

**Camada:** backend
**Dependências:** T-10
**Paralelizável:** sim
**Complexidade:** alta
**Agente:** Sherlock

"Serviço saudável não garante job de negócio processado": um job de cron a cada 15 min verifica heartbeats (reconciliador, backup), filas paradas e falhas de pagamento/provisionamento, mensagens e documentos, e a disponibilidade do storage, e alerta o operador por `OperatorAlertNotifier`. As contagens são globais (job de manutenção, sem contexto de tenant) e o resumo leva só números. As consultas ficam numa classe PDO própria, para as regras serem testadas com contagens falsas e o SQL no `centralvet_test`.

**Arquivos prováveis**
- `src/app/Core/Operations/Contract/OperationalCountsInterface.php`
- `src/app/Core/Persistence/PdoOperationalCounts.php`
- `src/app/Core/Operations/OperationalAlert.php`
- `src/app/Core/Operations/OperationalMonitor.php`
- `src/bin/ops-monitor.php`
- `src/tests/Unit/OperationalMonitorTest.php`
- `src/tests/Integration/PdoOperationalCountsIntegrationTest.php`
- `src/tests/Unit/OpsMonitorWiringTest.php`

**Interface**
- Produz: `interface CentralVet\Operations\Contract\OperationalCountsInterface` com `counts(DateTimeImmutable $now): array` — chaves `billing_event_failed` (`status = 'failed'` ou `status = 'received'` com `received_at` há mais de 3600 s), `billing_order_stuck` (`status IN ('paid', 'provisioning_failed')` com `updated_at` há mais de 3600 s), `message_failed` (`communication_message.status = 'failed'` com `failed_at` nas últimas 86400 s), `message_queue_stalled` (`status = 'queued'` com `created_at` há mais de 1800 s), `document_failed` (`generated_document.status = 'failed'` com `failed_at` nas últimas 86400 s), `document_queue_stalled` (`status = 'queued'` com `created_at` há mais de 1800 s), todas inteiras
- Produz: `CentralVet\Persistence\PdoOperationalCounts(PDO $pdo)` implementando a interface com `SELECT COUNT(*)` por chave
- Produz: `final class CentralVet\Operations\OperationalAlert` com `__construct(public readonly string $key, public readonly int $count, public readonly string $summary)`
- Produz: `final class CentralVet\Operations\OperationalMonitor` com `RECONCILE_MAX_AGE_SECONDS = 2700`, `BACKUP_MAX_AGE_SECONDS = 93600`, `PROBE_KEY = 'ops-probe/heartbeat.txt'`, `__construct(OperationalCountsInterface $counts, HeartbeatRepositoryInterface $heartbeats, ?StorageInterface $storage)` e `check(DateTimeImmutable $now): array` (list de `OperationalAlert`): `billing_reconcile_stale` (sem heartbeat `billing-reconcile` ou mais velho que 2700 s), `backup_stale` (idem `backup`, 93600 s), uma por chave de `counts` com valor > 0, e `storage_unavailable` (`put`/`get`/`delete` de `PROBE_KEY` lança ou devolve conteúdo diferente; com `$storage` null, não checa); `summary` em pt-BR só com chave, contagem e idade em minutos
- Produz: `php bin/ops-monitor.php` — só CLI, `flock` como o `billing-reconcile.php`, carrega `init.php`; monta `PdoOperationalCounts`, `PdoHeartbeatRepository`, storage `DocumentStorageFactory::fromEnvironment(TenantContext::authenticated(1, (int) (getenv('DOCUMENT_SYSTEM_USER_ID') ?: 1)))` e `OperatorAlertNotifier(new PdoAlertStateRepository($pdo), EmailProviderFactory::fromEnvironment(...), (string) (getenv('BILLING_OPERATOR_EMAIL') ?: ''))`; para cada alerta, `notify($alert->key, 'Central Vet Pro: alerta operacional ' . $alert->key, $alert->summary, $now)`; grava o heartbeat `ops-monitor`; imprime `ops-monitor alerts=<n> notified=<n> keys=<chaves separadas por vírgula ou ->`; código 0 sem alertas, 2 com alertas, 1 em exceção (`ops-monitor error=<classe>`)
- Consome: T-10 `HeartbeatRepositoryInterface`, `JOB_BILLING_RECONCILE = 'billing-reconcile'`, `JOB_BACKUP = 'backup'`, `JOB_OPS_MONITOR = 'ops-monitor'`, `CentralVet\Persistence\PdoHeartbeatRepository(PDO $pdo)`, `CentralVet\Persistence\PdoAlertStateRepository(PDO $pdo)`, `notify(string $alertKey, string $subject, string $body, DateTimeImmutable $now): bool`, `CentralVet\Tests\Support\FakeHeartbeatRepository`

**Teste RED**
- `src/tests/Unit/OperationalMonitorTest.php`, `src/tests/Integration/PdoOperationalCountsIntegrationTest.php` — monitor com contagens falsas, `FakeHeartbeatRepository` e storage de teste: sem heartbeat `billing-reconcile` → alerta `billing_reconcile_stale`; heartbeat de 10 min → sem ele; heartbeat `backup` de 30 h → `backup_stale`; `document_queue_stalled = 3` → alerta com `count` 3; storage que lança `StorageException` no `put` → `storage_unavailable`; tudo zerado, heartbeats recentes e storage em ordem → lista vazia; nenhum `summary` contém `@`; integração no `centralvet_test`: cada chave de `counts` devolve inteiro ≥ 0, e um `billing_event` inserido com `status = 'failed'` aumenta `billing_event_failed` em 1, um `received` de 2 h também, e um `billing_order` `paid` com `updated_at` de 2 h aumenta `billing_order_stuck` em 1 (comando: `SUITE com <Classe> = OperationalMonitorTest|PdoOperationalCountsIntegrationTest`)

**Critério de aceite**
- SUITE com `OperationalMonitorTest|PdoOperationalCountsIntegrationTest|OpsMonitorWiringTest` mostra `PASS` em todos os métodos, sem `SKIP` na integração, e `Failed: 0`.
- `OpsMonitorWiringTest` confere no fonte de `bin/ops-monitor.php`: guarda `PHP_SAPI`, `flock`, `BILLING_OPERATOR_EMAIL`, `JOB_OPS_MONITOR` e os códigos de saída 0/2/1.
- `docker compose run --rm --no-deps -T -e DB_DATABASE=centralvet_test -e COMMUNICATION_EMAIL_DRIVER=log -e BILLING_OPERATOR_EMAIL= -e DOCUMENT_STORAGE_DRIVER=local -e DOCUMENT_STORAGE_LOCAL_ROOT=/tmp/ops-probe -v /var/www/html/centralvet/src:/var/www/html/src:ro app php bin/ops-monitor.php` contra o banco de teste (0019 aplicada) termina com código 2 e a linha `ops-monitor alerts=<n> notified=0 keys=` contendo `billing_reconcile_stale` e `backup_stale`.

**Validação**
- SUITE com `<Classe>` = `OperationalMonitorTest|PdoOperationalCountsIntegrationTest|OpsMonitorWiringTest` (evidência: `PASS` em cada método e `Failed: 0`)
- o `docker compose run ... php bin/ops-monitor.php` do critério com `echo $?` (evidência: `2` e a linha `ops-monitor alerts=`); depois, MYSQLTEST `<<< "SELECT job FROM ops_heartbeat WHERE job='ops-monitor'; DELETE FROM ops_heartbeat WHERE job='ops-monitor';"` (limpeza no banco de teste; evidência: 1 linha `ops-monitor`)
- Review Focus: com `BILLING_OPERATOR_EMAIL` vazio, a saída tem `notified=0` e o `error_log` traz `ops_alert_no_recipient` (evidência: linhas no stderr do comando acima)
- LINT dos 5 arquivos de produção e dos 3 de teste (evidência: `No syntax errors detected`)

### T-13 — Heartbeat do reconciliador e retenção LGPD de `billing_event.payload`

**Camada:** backend
**Dependências:** T-10
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Athena

Duas pendências da Etapa 3. (1) O reconciliador grava heartbeat a cada rodada bem-sucedida: sem cron, o alerta `billing_reconcile_stale` de T-12 dispara. (2) `billing_event.payload` guarda o JSON do Stripe (com e-mail do cliente) e nunca é relido depois do processamento: uma retenção de 30 dias esvazia o payload dos eventos `processed`/`ignored`, sem apagar a linha. `latestEventCreated()` usa `MAX(event_created)` como cursor do reconciliador e a deduplicação usa `event_id`, por isso a linha fica. `failed`/`received` não são esvaziados (o alerta de T-12 cobra a ação). Segue o padrão de `AiConversationRetention` e de `bin/ai-conversation-retention.php`.

**Arquivos prováveis**
- `src/bin/billing-reconcile.php`
- `src/app/Core/Billing/BillingEventPayloadRetention.php`
- `src/bin/billing-event-retention.php`
- `src/tests/Integration/BillingEventPayloadRetentionIntegrationTest.php`
- `src/tests/Unit/BillingCronWiringTest.php`

**Interface**
- Produz: `final class CentralVet\Billing\BillingEventPayloadRetention` com `RETENTION_DAYS = 30`, `BATCH_SIZE = 500`, `MAX_BATCHES = 200`, `public static function forConnection(PDO $connection, ?Closure $clock = null, int $batchSize = self::BATCH_SIZE, int $maxBatches = self::MAX_BATCHES): self` (`InvalidArgumentException` com lote ou máximo < 1) e `runOnce(): array` → `array{purged: int, batches: int}`, em lotes de `UPDATE billing_event SET payload = '' WHERE status IN ('processed', 'ignored') AND received_at < :cutoff AND payload <> '' ORDER BY id LIMIT <lote>` até um lote curto ou `MAX_BATCHES`; nunca `DELETE`
- Produz: `php bin/billing-event-retention.php` — só CLI, `flock`, imprime `{"purged":<n>,"batches":<n>}` e grava o heartbeat `billing-event-retention`; com o lock ocupado, `{"skipped":"locked"}` e código 0; em exceção, só a classe no `error_log` e código 1
- Produz: `bin/billing-reconcile.php` grava `(new PdoHeartbeatRepository($pdo))->record(HeartbeatRepositoryInterface::JOB_BILLING_RECONCILE, new DateTimeImmutable(), <linha de contadores>)` só no caminho de sucesso, antes do `exit(0)`; falha do heartbeat vira `error_log('billing-reconcile heartbeat_failed=<classe>')` e o código segue 0
- Consome: T-10 `CentralVet\Persistence\PdoHeartbeatRepository(PDO $pdo)`, `JOB_BILLING_RECONCILE = 'billing-reconcile'`, `JOB_BILLING_EVENT_RETENTION = 'billing-event-retention'`

**Teste RED**
- `src/tests/Integration/BillingEventPayloadRetentionIntegrationTest.php`, `src/tests/Unit/BillingCronWiringTest.php` — no `centralvet_test`, três eventos inseridos (`processed` com `received_at` de 31 dias, `processed` de 1 dia, `failed` de 31 dias) com relógio injetado: `runOnce()` devolve `purged` = 1 e só o primeiro fica com `payload = ''`; `COUNT(*)` de `billing_event` e `BillingEventRepository::latestEventCreated()` iguais antes e depois; a segunda chamada devolve `purged` = 0; `forConnection($pdo, null, 0)` lança `InvalidArgumentException`; wiring: no fonte de `bin/billing-reconcile.php`, `JOB_BILLING_RECONCILE` aparece depois de `->run(` e antes do último `exit(0)`, e não dentro do `catch`; `bin/billing-event-retention.php` tem guarda `PHP_SAPI`, `flock` e `JOB_BILLING_EVENT_RETENTION` (comando: `SUITE com <Classe> = BillingEventPayloadRetentionIntegrationTest|BillingCronWiringTest`)

**Critério de aceite**
- SUITE com `BillingEventPayloadRetentionIntegrationTest|BillingCronWiringTest|BillingReconcilerIntegrationTest|LoginConflictRefundIntegrationTest|BillingAcquisitionFlowIntegrationTest` mostra `PASS` em todos os métodos, sem `SKIP` nas classes de integração, e `Failed: 0`.
- `docker compose run --rm --no-deps -T -e DB_DATABASE=centralvet_test -v /var/www/html/centralvet/src:/var/www/html/src:ro app php bin/billing-event-retention.php` imprime uma linha `{"purged":<n>,"batches":<n>}` e termina com código 0; MYSQLTEST mostra 1 linha `billing-event-retention` em `ops_heartbeat` (removida em seguida, banco de teste).

**Validação**
- SUITE com `<Classe>` = `BillingEventPayloadRetentionIntegrationTest|BillingCronWiringTest|BillingReconcilerIntegrationTest|LoginConflictRefundIntegrationTest|BillingAcquisitionFlowIntegrationTest` (evidência: `PASS` em cada método e `Failed: 0`)
- o `docker compose run ... php bin/billing-event-retention.php` do critério com `echo $?`, depois MYSQLTEST `<<< "SELECT job FROM ops_heartbeat WHERE job='billing-event-retention'; DELETE FROM ops_heartbeat WHERE job='billing-event-retention';"` (evidência: a linha JSON, `0`, 1 linha do job)
- Review Focus: o método do teste de integração que compara `COUNT(*)` e `latestEventCreated()` antes e depois (evidência: `PASS`)
- LINT de `bin/billing-reconcile.php`, `app/Core/Billing/BillingEventPayloadRetention.php`, `bin/billing-event-retention.php` e dos 2 testes (evidência: `No syntax errors detected`)

### T-14 — CI reproduzível com banco de teste isolado, schema e lint

**Camada:** ci
**Dependências:** T-01, T-03, T-05, T-08, T-11
**Paralelizável:** sim
**Complexidade:** alta
**Agente:** Kratos

Hoje o `ci.yml` sobe o MySQL mas não provisiona o `centralvet_test`, e `tests/run.php` recusa ("Refusing to run: test MySQL database centralvet_test not found"); o CI também não tem lint, o `.env` gerado não tem `MIGRATION_DB_*`, o usuário de migration não existe no compose e os testes que leem arquivos fora de `src/` não acham os arquivos na imagem. A pipeline passa a ser um script versionado (`scripts/ci/run.sh`) que roda igual no GitHub Actions e na máquina do desenvolvedor, num projeto compose próprio e com env-file próprio — nunca o `.env` da raiz nem o banco da aplicação. Mexe em `provision.sh` (já alterado por T-01 na Onda 1) e em `prepare-adianti-bootstrap.sh` só para aceitar env-file e diretório de saída.

**Arquivos prováveis**
- `.github/workflows/ci.yml`
- `scripts/ci/run.sh`
- `scripts/ci/run.test.sh`
- `scripts/test-db/provision.sh`
- `scripts/prepare-adianti-bootstrap.sh`

**Interface**
- Produz: `scripts/ci/run.sh` — `CI_PROJECT` (padrão `centralvet-ci`) e `CI_WORKDIR` (padrão `var/ci`; apagado e recriado no início); exporta `COMPOSE_PROJECT_NAME=$CI_PROJECT` e `COMPOSE_ENV_FILES=$CI_WORKDIR/.env`; gera `$CI_WORKDIR/.env` com segredos de `openssl rand`, `MYSQL_DATABASE=centralvet`, `MIGRATION_DB_USER=centralvet_migrator`, `MIGRATION_DB_PASSWORD`, `IMAGE_TAG=${IMAGE_TAG:-ci}`; nunca lê nem escreve o `.env` da raiz
- Produz: etapas, nesta ordem, cada uma anunciada por `ci: step <nome>`: `env`, `guard` (falha se o banco de teste `centralvet_test` for igual a `MYSQL_DATABASE`), `build` (`app worker`), `up` (`mysql redis` com `--wait`), `migrator` (`CREATE USER IF NOT EXISTS` + `GRANT` em `centralvet`.* pelo root do container), `bootstrap` (`prepare-adianti-bootstrap.sh` com `BOOTSTRAP_OUTPUT_DIR=$CI_WORKDIR/sql-bootstrap` e `BOOTSTRAP_ENV_FILE=$CI_WORKDIR/.env`), `provision` (`provision.sh` com `TEST_DB_BOOTSTRAP_DIR=$CI_WORKDIR/sql-bootstrap` e `PROVISION_ENV_FILE=$CI_WORKDIR/.env`), `schema` (`scripts/db/migration-status.sh --database centralvet_test` com código 0), `lint` (`php -l` de todo `.php` de `app tests bin config` no container, sem nenhuma linha fora de `No syntax errors detected`, e `bash -n` de todo `scripts/**/*.sh`), `script-tests` (`bash` de `scripts/test-db/provision-check.test.sh`, `scripts/db/migration-status.test.sh`, `scripts/backup.test.sh`, `scripts/restore.test.sh`, `scripts/release/build-release.test.sh`, `scripts/release/smoke.test.sh`, `scripts/ci/run.test.sh`), `suite` (`docker compose run --rm --no-deps -T` com os mesmos mounts do SUITE e `php tests/run.php`, exigindo `Failed: 0`); `down -v --remove-orphans` do projeto num `trap`; última linha `ci: passed` (código 0) ou `ci: failed step=<nome>` (código 1)
- Produz: `scripts/test-db/provision.sh` lê as credenciais de `${PROVISION_ENV_FILE:-.env}`; `scripts/prepare-adianti-bootstrap.sh` aceita `BOOTSTRAP_OUTPUT_DIR` (padrão `var/sql-bootstrap`) e `BOOTSTRAP_ENV_FILE` (padrão `.env`), sem mudar o comportamento padrão
- Produz: `.github/workflows/ci.yml` com um job que roda `scripts/ci/run.sh` com `CI_PROJECT=centralvet-ci` e `IMAGE_TAG` = SHA do commit (`github.sha`), mantendo `concurrency`, `timeout-minutes` e os logs em falha
- Consome: T-03 `scripts/db/migration-status.sh [--database <nome>]`, `pending=0 unknown=0`; T-01 `$migrations_dir/20261026_0019_ops_monitoring.sql`

**Teste RED**
- `scripts/ci/run.test.sh` — com `docker` falso que registra argumentos e ambiente, `CI_PROJECT=centralvet-ci-test` e `CI_WORKDIR` temporário: as linhas `ci: step` saem na ordem `env guard build up migrator bootstrap provision schema lint script-tests suite`; `provision.sh` e `migration-status.sh --database centralvet_test` vêm antes de `tests/run.php`; toda chamada `docker compose` registra `COMPOSE_PROJECT_NAME=centralvet-ci-test`; o sha256 do `.env` da raiz é o mesmo antes e depois; com o docker falso falhando na etapa `provision`, a saída termina com `ci: failed step=provision`, código 1 e nenhuma chamada a `tests/run.php`; `.github/workflows/ci.yml` contém `scripts/ci/run.sh` e não contém `exec -T app php tests/run.php` (comando: `bash scripts/ci/run.test.sh`)

**Critério de aceite**
- SHTEST de `scripts/ci/run.test.sh` imprime `Failed: 0`, e `bash scripts/test-db/provision-check.test.sh` imprime `Failed: 0`.
- Execução real local: `CI_PROJECT=centralvet-ci-local CI_WORKDIR=var/ci/local IMAGE_TAG=ci-local scripts/ci/run.sh` termina com `ci: passed`, código 0, a etapa `schema` com `pending=0 unknown=0` e a `suite` com `Failed: 0`; depois, `docker volume ls -q --filter name=centralvet-ci-local` não lista nada e o sha256 do `.env` da raiz e a lista de `docker compose ps` do projeto `centralvet` são os mesmos de antes.
- `python3 -c "import yaml,sys; yaml.safe_load(open('.github/workflows/ci.yml'))"` termina com código 0.

**Validação**
- `bash scripts/ci/run.test.sh` e `bash scripts/test-db/provision-check.test.sh` (evidência: `Failed: 0` nas duas)
- `sha256sum .env; docker compose ps --services --status running` antes e depois de `CI_PROJECT=centralvet-ci-local CI_WORKDIR=var/ci/local IMAGE_TAG=ci-local scripts/ci/run.sh` (evidência: `ci: step schema` seguido de `pending=0 unknown=0`, `Failed: 0`, `ci: passed`, hashes e serviços iguais)
- `docker volume ls -q --filter name=centralvet-ci-local` (evidência: nenhuma linha)
- o `yaml.safe_load` do critério e BASHN (evidência: código 0; nenhuma linha em relação a `baseline/bash-n.txt`)

### T-15 — Ensaio de rollback de release e registro dos tempos medidos

**Camada:** infra
**Dependências:** T-03, T-05, T-08, T-11
**Paralelizável:** sim
**Complexidade:** alta
**Agente:** Thanos

Prova os critérios "restauração comprovada" e "rollback ensaiado" com números. Num projeto isolado (`centralvet-drill`, porta 18081, volumes e env próprios), a partir de um backup real do dev: implanta o commit anterior (`992877a`, fim da Etapa 3), aplica as migrations pendentes com um usuário de migration criado só no ensaio, implanta o commit novo, e faz o rollback (restauração do backup pré-migration + imagem anterior), com fumaça e `migration-status` em cada fase. Registra no runbook os tempos de `restore-drill.sh` (T-11) para os dois formatos de backup (o `.tar.gpg` da pilha Docker de T-08 e o `.tar.gz.cvbk` da hospedagem de T-17) e deste ensaio. Escreve só no projeto `centralvet-drill`; o `centralvet` (dev) só é lido pelo `backup.sh --no-heartbeat`.

**Arquivos prováveis**
- `scripts/release/rollback-drill.sh`
- `docs/runbooks/ensaio-restauracao-rollback.md`

**Interface**
- Produz: `scripts/release/rollback-drill.sh <commit-anterior> <commit-novo> <backup>` — constrói as imagens dos dois commits com `build-release.sh --image`; restaura `<backup>` no `centralvet-drill` (`restore.sh --project centralvet-drill --env-file var/drill/.env`); sobe `app worker nginx` com `IMAGE_TAG=<sha anterior>`; `smoke.sh http://127.0.0.1:18081`; backup pré-migration (`backup.sh --project centralvet-drill --env-file var/drill/.env --no-heartbeat`); aplica cada `pending` de `migration-status.sh` (com `COMPOSE_PROJECT_NAME=centralvet-drill`) pelo usuário de migration do ensaio; sobe `IMAGE_TAG=<sha novo>`; smoke; rollback (restore do backup pré-migration + `IMAGE_TAG=<sha anterior>`); smoke e `migration-status`; imprime `rollback-drill phase=<fase> seconds=<n>` para `build`, `restore`, `deploy_old`, `pre_backup`, `migrate`, `deploy_new`, `rollback` e a última linha `rollback-drill done total_seconds=<n> smoke_failures=<n>`; `down -v` do `centralvet-drill` no fim; código 1 com `smoke_failures>0`
- Produz: `docs/runbooks/ensaio-restauracao-rollback.md` — data, commits, tamanho do backup, saída de `restore-drill done ...` e de cada `rollback-drill phase=...`, RTO medido (restauração e rollback) e RPO (intervalo do cron de backup), e os passos para repetir
- Consome: T-17 `centralvet-<AAAAMMDDTHHMMSSZ>.tar.gz.cvbk`; T-11 `scripts/restore-drill.sh [--keep] <arquivo>`, `CONFIRM_RESTORE=RESTORE_<PROJETO>`; T-05 `scripts/release/smoke.sh <base-url>`, `scripts/release/build-release.sh [--image] <commit>`; T-03 `scripts/db/migration-status.sh [--database <nome>]`; T-08 `scripts/backup.sh [--project <nome>] [--env-file <arquivo>] [--no-heartbeat]`

**Teste RED**
- sem teste: roteiro operacional executado de verdade no projeto isolado; a prova são os tempos medidos, a fumaça e o `migration-status` de cada fase

**Critério de aceite**
- `BACKUP_DIR=var/drill/backups BACKUP_PASSPHRASE_FILE=var/drill/passphrase scripts/restore-drill.sh <backup do dev>` imprime `restore-drill done ... mismatches=0`; o mesmo com `BACKUP_DIR=var/drill/backups-hosting` e o `.tar.gz.cvbk` de T-17 também.
- `scripts/release/rollback-drill.sh 992877a HEAD <backup do dev>` imprime as 7 linhas `rollback-drill phase=` e `rollback-drill done total_seconds=<n> smoke_failures=0`; depois do `migrate`, `migration-status` mostra `pending 20261026_0019_ops_monitoring` ausente; depois do `rollback`, mostra `pending 20261026_0019_ops_monitoring`.
- `docs/runbooks/ensaio-restauracao-rollback.md` traz os segundos medidos das três execuções (dois formatos de backup e o rollback) (iguais aos da saída) e `docker volume ls -q --filter name=centralvet-drill` não lista nada no fim.

**Validação**
- as três execuções do critério com `echo $?` (evidência: `mismatches=0` nos dois formatos, `smoke_failures=0`, código 0 em todas, as linhas `pending` antes e depois do rollback)
- `grep -E 'elapsed_seconds|total_seconds' docs/runbooks/ensaio-restauracao-rollback.md` (evidência: os mesmos números da saída)
- `docker volume ls -q --filter name=centralvet-drill` e `docker compose ps --services --status running` antes e depois (evidência: nenhuma linha; serviços do dev iguais)
- BASHN (evidência: nenhuma linha em relação a `baseline/bash-n.txt`)

### T-16 — Documentação operacional: runbooks, cron, alertas, release, LGPD

**Camada:** docs
**Dependências:** T-06, T-07, T-09, T-12, T-13, T-14, T-15
**Paralelizável:** não
**Complexidade:** média
**Agente:** Gandalf

Escritor único dos runbooks compartilhados. Documenta: backup/restauração novos (formato, variáveis, frase fora do repositório, cópia externa, retenção, ensaio); release (artefato, imagem por SHA, manifesto, preflight, `migration-status`, smoke, rollback); cron versionado (reconciliador a cada 15 min, `ops-monitor` a cada 15 min, retenção do payload diária, backup diário — `scripts/backup.sh` no host Docker e `php bin/hosting-backup.php` na hospedagem —, além dos já existentes) como crontab do host Docker e como entradas do agendador do painel da hospedagem compartilhada; procedimento de restauração na hospedagem (decifrar com `--decrypt`, importar `db.sql.gz` pelo cliente disponível, repor `files`/`documents`) e o ensaio desse formato pela pilha Docker; `migration-status` e preflight `--with-db` nos dois alvos; alertas (chaves, limiares, `BILLING_OPERATOR_EMAIL`, deduplicação de 6 h); produção (cookies Secure, `TRUST_FORWARDED_PROTO`, `NGINX_TRUSTED_PROXIES_FILE`, preflight); CI (`scripts/ci/run.sh` local); LGPD (retenção de 30 dias do payload, o que fica); migration 0019 no runbook da hospedagem; e corrige a pendência T-31 da Etapa 3 (`mark_refunded` listado entre os passos de "Falha no Stripe" em `shared-hosting-mysql57.md`).

**Arquivos prováveis**
- `README.md`
- `docs/runbooks/README.md`
- `docs/runbooks/restore-backup.md`
- `docs/runbooks/build-versioning.md`
- `docs/runbooks/migration-rollback.md`
- `docs/runbooks/environments.md`
- `docs/runbooks/shared-hosting-mysql57.md`
- `docs/runbooks/tests.md`
- `docs/runbooks/observabilidade.md`
- `docs/runbooks/cron.md`
- `docs/runbooks/release.md`
- `docs/security/lgpd-retencao.md`
- `src/app/database/migrations/README.md`

**Interface**
- Produz: nada (só documentação)
- Consome: T-17 `php bin/hosting-backup.php [--no-heartbeat]`; T-03 `php bin/migration-status.php`; T-12 `php bin/ops-monitor.php`; T-13 `php bin/billing-event-retention.php`; T-09 `php bin/preflight.php [--trusted-proxies-file=<caminho>]`; T-15 `docs/runbooks/ensaio-restauracao-rollback.md`; T-06 `docs/runbooks/smoke-billing.md`

**Teste RED**
- sem teste: documentação; a prova são as referências conferidas por `grep` e a suíte que lê `docs/`

**Critério de aceite**
- `docs/runbooks/cron.md` tem uma linha de cron para cada um de `bin/billing-reconcile.php`, `bin/ops-monitor.php`, `bin/billing-event-retention.php`, `bin/ai-conversation-retention.php`, `bin/document-sweep.php`, `scripts/backup.sh` e `bin/hosting-backup.php` (`grep -c` de cada ≥ 1), com uma seção para o crontab do host Docker e outra para o agendador do painel da hospedagem compartilhada.
- `docs/runbooks/shared-hosting-mysql57.md` tem as seções de backup (`hosting-backup.php`), restauração (`--decrypt`), `bin/migration-status.php` e `bin/preflight.php --with-db` (`grep -c` de cada ≥ 1).
- `docs/runbooks/observabilidade.md` cita as 9 chaves de alerta de T-12 (`billing_reconcile_stale`, `backup_stale`, `billing_event_failed`, `billing_order_stuck`, `message_failed`, `message_queue_stalled`, `document_failed`, `document_queue_stalled`, `storage_unavailable`) e `BILLING_OPERATOR_EMAIL`.
- Todo link relativo `](./...)` e `](../...)` dos arquivos tocados aponta para arquivo existente (laço com `test -e` imprime 0 faltantes); `docs/runbooks/README.md` lista `cron.md`, `observabilidade.md`, `release.md`, `smoke-billing.md` e `ensaio-restauracao-rollback.md`.
- `grep -n 'mark_refunded' docs/runbooks/shared-hosting-mysql57.md` não aparece mais na lista de passos de "Falha no Stripe"; a seção "Migration 0019" existe.
- SUITE completo sem filtro de classe termina com `Failed: 0`.

**Validação**
- `for k in billing-reconcile.php ops-monitor.php billing-event-retention.php ai-conversation-retention.php document-sweep.php backup.sh hosting-backup.php; do grep -c "$k" docs/runbooks/cron.md; done` (evidência: nenhum `0`)
- `for k in billing_reconcile_stale backup_stale billing_event_failed billing_order_stuck message_failed message_queue_stalled document_failed document_queue_stalled storage_unavailable BILLING_OPERATOR_EMAIL; do grep -c "$k" docs/runbooks/observabilidade.md; done` (evidência: nenhum `0`)
- laço de links: para cada arquivo tocado, extrair os alvos `](./x)`/`](../x)` e rodar `test -e` relativo ao arquivo (evidência: `0` faltantes)
- SUITE sem filtro: `docker compose run ... app php tests/run.php 2>&1 | tail -3` (evidência: `Failed: 0`)

### T-17 — Backup da hospedagem compartilhada por PHP CLI (sem Docker)

**Camada:** backend
**Dependências:** T-01, T-02
**Paralelizável:** sim
**Complexidade:** alta
**Agente:** Goku

Na hospedagem compartilhada MySQL 5.7 não há Docker, `gpg` nem, em geral, `mysqldump`: o backup sai por um CLI PHP agendado no painel. Ele faz o dump por PDO (tabelas, dados em lotes e triggers, que o preparador 5.7 usa no lugar dos CHECK), empacota `src/files` e a pasta de documentos (`DOCUMENT_STORAGE_LOCAL_ROOT`, fora do `public_html`) com `PharData`, grava o mesmo `MANIFEST` v1 de T-08 e cifra com `openssl` em formato próprio autenticado. Retenção, cópia para `BACKUP_OFFSITE_DIR` e heartbeat seguem T-08. A restauração automatizada desse formato é pela pilha Docker (T-11, ensaio em T-15); na hospedagem, o procedimento é o do runbook (T-16), com o `--decrypt` deste CLI.

**Arquivos prováveis**
- `src/app/Core/Operations/BackupCipher.php`
- `src/app/Core/Operations/HostingBackup.php`
- `src/bin/hosting-backup.php`
- `src/tests/Unit/BackupCipherTest.php`
- `src/tests/Integration/HostingBackupIntegrationTest.php`

**Interface**
- Produz: `final class CentralVet\Operations\BackupCipher` com `MAGIC = 'CVBK1'`, `public static function encryptFile(string $input, string $output, string $passphrase): void` e `public static function decryptFile(string $input, string $output, string $passphrase): void` — formato `CVBK1` + salt (16 bytes) + IV (16 bytes) + AES-256-CTR em blocos de 1 MiB + HMAC-SHA256 (32 bytes) sobre cabeçalho e cifra; chaves de cifra e de MAC = metades de `hash_pbkdf2('sha256', $passphrase, $salt, 200000, 64, true)`; `decryptFile` confere o HMAC antes de gravar e lança `RuntimeException('backup authentication failed')` sem criar `$output` com frase errada ou byte alterado
- Produz: `final class CentralVet\Operations\HostingBackup` com `__construct(PDO $pdo, string $filesDir, string $documentsDir, string $backupDir, ?Closure $clock = null)` e `create(string $passphrase): string` (caminho de `$backupDir/centralvet-<AAAAMMDDTHHMMSSZ>.tar.gz.cvbk`) — tar.gz interno com `MANIFEST`, `db.sql.gz`, `files.tar.gz` e `documents.tar.gz` no formato `format=centralvet-backup-v1` de T-08 (com `project=hosting`, `minio=skipped`, `commit=` do arquivo `src/buildid` ou `unknown`); dump com `SET FOREIGN_KEY_CHECKS=0`, `SHOW CREATE TABLE` de cada tabela, `INSERT` em lotes de 500 linhas, `SHOW TRIGGERS` + `SHOW CREATE TRIGGER`; abre `START TRANSACTION WITH CONSISTENT SNAPSHOT` só quando o PDO não está em transação
- Produz: `php bin/hosting-backup.php [--no-heartbeat]` e `php bin/hosting-backup.php --decrypt <entrada> <saída>` — só CLI, `flock`, carrega `init.php`; lê `BACKUP_DIR` (padrão `var/backups` na raiz do projeto), `BACKUP_PASSPHRASE_FILE`, `BACKUP_RETENTION_DAYS` (padrão 14) e `BACKUP_OFFSITE_DIR`; mesmas linhas de saída de T-08 (`Backup created: <caminho> parts=<n> bytes=<n>`, `backup: pruned <arquivo>`, `backup: offsite copy <destino>`, `backup: heartbeat not recorded`) e mesmos códigos 0/2/3/4; `--decrypt` sai 5 em falha de autenticação; heartbeat `INSERT ... ON DUPLICATE KEY UPDATE` em `ops_heartbeat` com `job = 'backup'` pelo PDO da aplicação; retenção só sobre `centralvet-*.tar.gz.cvbk`
- Consome: T-01 `ops_heartbeat`; T-02 `BACKUP_PASSPHRASE_FILE`, `BACKUP_RETENTION_DAYS`, `BACKUP_OFFSITE_DIR`

**Teste RED**
- `src/tests/Unit/BackupCipherTest.php`, `src/tests/Integration/HostingBackupIntegrationTest.php` — cifra: um arquivo de 3 MiB cifrado e decifrado volta igual (`hash_file('sha256')`); com a frase errada ou um byte alterado no meio, `decryptFile` lança `RuntimeException` com `backup authentication failed` e o arquivo de saída não existe; o arquivo cifrado começa com `CVBK1`; integração no `centralvet_test` (dentro da transação do teste, sem abrir snapshot) com diretórios temporários de arquivos (2 arquivos) e documentos (1 arquivo): `create()` gera `.tar.gz.cvbk`; decifrado, o `MANIFEST` tem `format=centralvet-backup-v1`, `minio=skipped`, `rows tenant=` igual a `SELECT COUNT(*) FROM tenant`, `files_count=2`, `documents_count=1` e um `sha256` por parte que confere; `db.sql.gz` contém `CREATE TABLE` de `ops_heartbeat` e `INSERT INTO` de `tenant` (comando: `SUITE com <Classe> = BackupCipherTest|HostingBackupIntegrationTest`)

**Critério de aceite**
- SUITE com `BackupCipherTest|HostingBackupIntegrationTest` mostra `PASS` em todos os métodos, sem `SKIP`, e `Failed: 0`.
- No container, contra o dev só lendo: com `var/drill/passphrase` (criado como em T-08 se ainda não existe), `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro -v /var/www/html/centralvet/var/drill:/var/www/html/var/drill -e BACKUP_DIR=/var/www/html/var/drill/backups-hosting -e BACKUP_PASSPHRASE_FILE=/var/www/html/var/drill/passphrase app php bin/hosting-backup.php --no-heartbeat` termina com código 0 e `Backup created: /var/www/html/var/drill/backups-hosting/centralvet-<data>.tar.gz.cvbk`; `--decrypt` desse arquivo gera um tar.gz cujo `MANIFEST` tem `rows tenant=` igual a `SELECT COUNT(*) FROM tenant` do dev.
- `SELECT COUNT(*) FROM centralvet.schema_migrations` igual antes e depois (nenhuma escrita no dev).

**Validação**
- SUITE com `<Classe>` = `BackupCipherTest|HostingBackupIntegrationTest` (evidência: `PASS` em cada método e `Failed: 0`)
- a execução real do critério, depois `php bin/hosting-backup.php --decrypt` no mesmo container e `tar -xzOf <saída> MANIFEST` (evidência: `Backup created:`, linhas `format=` e `rows tenant=` iguais à contagem do dev)
- Review Focus: o método do `BackupCipherTest` com frase errada (evidência: `PASS`, sem arquivo de saída)
- LINT de `app/Core/Operations/BackupCipher.php`, `app/Core/Operations/HostingBackup.php`, `bin/hosting-backup.php` e dos 2 testes (evidência: `No syntax errors detected`)

## Legenda

- [ ] pendente
- [~] em andamento
- [x] concluído com evidência
- [!] bloqueado
