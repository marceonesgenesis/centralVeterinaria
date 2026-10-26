# Plano: Etapa 4 — Operação (backup, CI, release, produção, alertas, SMTP, LGPD)

## Objetivo
Fechar a Etapa 4 do plano de conclusão do MVP (`.docs/audits/2026-10-09-prontidao-mvp.md`): restauração comprovada em ambiente isolado com tempo medido, pipeline de CI reproduzível com banco de teste isolado, release a partir de commit identificado com rollback ensaiado, configuração de produção verificada por preflight, e falhas de negócio recuperáveis e visíveis por alertas ao operador. Inclui as pendências da Etapa 3 marcadas para esta etapa (retenção LGPD do payload, heartbeat/cron do reconciliador, fumaça versionada, remetente de e-mail).

## Premissas
- Branch de trabalho `task/etapa-4-operacao`, criada a partir de `task/etapa-3-contratacao` (HEAD `992877a`): as Etapas 1–3 ainda não estão em `dev`/`main`. Produção: `main`.
- A entrega preserva os commits por onda (sem `entrega.py`); push e PR só a pedido do desenvolvedor.
- TDD com o teste RED commitado antes da implementação onde há código testável; testes no container `app` (PHP 8.4); scripts shell testados por `bash *.test.sh` com `docker` falso (padrão de `scripts/test-db/provision-check.test.sh`).
- Nenhuma credencial real; nenhum serviço externo pago contratado. Cópia externa do backup implementada para diretório montado e S3-compatível genérico (`aws s3 cp`), testada só com destino local e `aws` falso.
- Escrita no `.env` da raiz e SQL de escrita no banco `centralvet` (dev) só com autorização do usuário; nenhuma task depende disso. DDL no `centralvet_test` segue o padrão autorizado desde a Etapa 2.
- Canal de alerta (confirmado pelo usuário): só e-mail, ao `BILLING_OPERATOR_EMAIL` (o mesmo da T-31 da Etapa 3), com deduplicação de 6 h, mais o código 2 do cron.
- Cópia externa (confirmado): disco montado (`BACKUP_OFFSITE_DIR`) e S3-compatível genérico, testados só localmente.
- CI (confirmado): GitHub Actions chamando `scripts/ci/run.sh`.
- Hospedagem alvo (confirmado): as duas — Docker/VPS e hospedagem compartilhada MySQL 5.7. Release, preflight, `migration-status`, monitor e heartbeats funcionam nos dois (CLIs PHP por PDO; shell só onde há Docker). Backup e restauração automatizados na pilha Docker; na compartilhada, backup por CLI PHP sem Docker (dump PDO, `PharData`, cifra `openssl`) agendado no painel, restauração por procedimento documentado e ensaiada pela pilha Docker. Cron documentado como crontab do host e como agendador do painel.
- Nenhuma tela muda nesta etapa (as regras de rótulos à esquerda e 44 px não se aplicam).

## Escopo

### Incluso
- Backup completo: MySQL, volume `app_files`, volume `app_documents`, volume do MinIO quando existe; manifesto com checksums e contagens; cifragem `gpg` AES256; retenção; cópia externa; heartbeat (T-08).
- Backup da hospedagem compartilhada sem Docker: CLI PHP com dump PDO (tabelas, dados, triggers), arquivos e documentos, mesmo MANIFEST, cifra autenticada `openssl`, retenção, cópia para diretório e heartbeat; `--decrypt` para restaurar (T-17).
- Restauração verificada (checksums antes de escrever) dos dois formatos e ensaio em projeto isolado com tempo medido e contagens conferidas (T-11, T-15).
- CI: banco de teste isolado provisionado, verificação de schema antes da suíte, lint PHP e shell, testes dos scripts, nunca o banco da aplicação, mesmo script local e no GitHub Actions (T-14).
- Release: pacote (hospedagem) e imagem (Docker) a partir de commit identificado, manifesto com migrations, registro de migrations aplicadas nos dois alvos (`migration-status.sh` e `bin/migration-status.php`), 0019 compatível com o preparador MySQL 5.7, fumaça pós-implantação e ensaio de rollback com tempos (T-01, T-03, T-05, T-15).
- Produção: cookie de sessão `Secure` atrás do proxy, `X-Forwarded-Proto` só de proxy confiável, `set_real_ip_from` por arquivo selecionável com exemplo estreito de produção, portas publicadas só em 127.0.0.1 verificadas por teste, preflight de configuração e paridade nos dois alvos, com versão do MySQL e migrations pendentes (`--with-db`) (T-02, T-07, T-09).
- Observabilidade: alertas por e-mail com deduplicação para erro de pagamento/provisionamento, fila parada, mensagens e documentos com falha, backup ausente, storage indisponível e reconciliador sem rodar (heartbeat) (T-10, T-12, T-13).
- Comunicação: convite e reset pelo mesmo provedor SMTP com remetente `SMTP_FROM_ADDRESS`/`SMTP_FROM_NAME`, validados por servidor SMTP falso em processo (T-04).
- LGPD: retenção de 30 dias de `billing_event.payload` para eventos `processed`/`ignored`, sem apagar a linha (T-13).
- Pendências da Etapa 3: cron e heartbeat do reconciliador (T-13, T-16), fumaça versionada em `scripts/smoke/billing/` (T-06), remetente do reset no dev (T-04), passo `mark_refunded` do runbook (T-16).
- Documentação operacional: runbooks de backup, release, cron (crontab e agendador do painel), alertas, produção, CI, LGPD e hospedagem compartilhada, com a restauração na hospedagem (T-16).

### Excluído
- Contratação de bucket, SMTP, monitoramento ou CI pagos; configuração de credenciais reais.
- Executar a fumaça da contratação no banco de dev (exige autorização; T-06 só versiona o roteiro).
- Aplicar a 0019 no banco `centralvet` (dev) ou em produção (fica no runbook, com autorização).
- Alerta do fail-open do `SubscriptionAccessGuard` (o erro acontece quando o banco falha, e o monitor depende do banco; fica para coleta de log externa).
- Backup do bucket S3/R2 real de produção (versionamento/replicação do provedor) e do Redis (sessões e filas efêmeras).
- Restauração automatizada dentro da hospedagem compartilhada (fica como procedimento documentado; a restauração automatizada e o ensaio do formato `.cvbk` são pela pilha Docker); cópia S3 a partir da hospedagem (só `BACKUP_OFFSITE_DIR`).
- Refatorar `LoginConflictRefundService::alertOperator` para o novo notificador.
- Mudanças de UI; TLS/domínio reais.

## Contexto técnico
- Camadas envolvidas: database, backend, infra, ci, qa, docs
- Projeto/base analisada: `/var/www/html/centralvet` (raiz do repositório: `git -C <DIR> rev-parse --show-toplevel`)
- Integrações: SMTP (servidor falso nos testes), Stripe (só `StripeSignatureVerifier` no gerador da fumaça; sem rede), GitHub Actions, gpg/openssl/aws CLI no host; hospedagem compartilhada (PHP CLI + MySQL 5.7, sem Docker)

## Baseline
- php-lint-all: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app sh -c 'cd /var/www/html/src && find app tests bin config -name "*.php" -print0 | xargs -0 -n1 php -l 2>&1 | grep -v "^No syntax errors detected" || true'` em raiz → baseline/php-lint-all.txt (0 linhas)
- bash-n: `find scripts -name '*.sh' -exec bash -n {} \;` em raiz → baseline/bash-n.txt (0 linhas)

## Exploração read-only
- Caminhos relevantes: `scripts/backup.sh` (só `mysqldump` → `var/backups/*.sql.gz`, sem cifra/retenção), `scripts/restore.sh` (`CONFIRM_RESTORE=RESTORE_CENTRALVET`), `scripts/test-db/provision.sh`, `scripts/test-db/verify.sql`, `scripts/test-db/provision-check.test.sh`, `scripts/prepare-adianti-bootstrap.sh` (escreve `.env` e `var/sql-bootstrap`), `.github/workflows/ci.yml` (sobe MySQL e roda `tests/run.php` sem provisionar o `centralvet_test`), `docker-compose.yml` (volumes `mysql_data`, `redis_data`, `minio_data`, `app_files`, `app_documents`; nginx em `127.0.0.1:8081`; MinIO por profile), `docker/nginx/default.conf` (`set_real_ip_from` 10/8, 172.16/12, 192.168/16, 127.0.0.1), `docker/php/conf.d/app.ini` (sem `session.cookie_secure`), `src/app/service/auth/ApplicationAuthenticationService.php:560` (`isHttps` confia em `X-Forwarded-Proto`), `src/app/Core/Session/SessionHandlerFactory.php`, `src/config/health.php` (MySQL e Redis), `src/bin/*.php` (reconciliador, worker, sweep, retenção IA), `src/app/Core/Billing/LoginConflictRefundService.php` (`alertOperator`, `BILLING_OPERATOR_EMAIL`), `src/app/Core/Communication/SmtpEmailProvider.php`, `SmtpConfig.php`, `EmailProviderFactory.php`, `src/app/control/admin/SystemRequestPasswordResetForm.php` (`MailService::send`), `src/app/Core/Application/AiConversationRetention.php`, migrations 0001–0018, `docs/runbooks/*`.
- Padrões identificados: PSR-4 `CentralVet\` → `src/app/Core/`; testes sem PHPUnit (`tests/run.php`, `Assert`, `MysqlIntegrationTestCase` em transação); bins com guarda `PHP_SAPI`, `flock` e saída em uma linha; migrations com placeholder de checksum e `.verify.sql`; testes de infra leem `dirname(__DIR__, 3)` (precisam dos mounts do SUITE); arquivos protegidos por `framework_hashes.php` (`engine.php`, `index.php`, `init.php`, `MailService.php`).
- Scripts úteis: SUITE/LINT/MYSQLTEST da Etapa 3 (estendidos em `tasks.md`), `make backup|restore|diagnose`, `scripts/prepare-mysql57.py`, `scripts/test-db/provision.sh --check`.
- Riscos identificados: o CI atual falha por banco de teste ausente e por arquivos fora de `src/`; restauração sem checksum pode escrever parcialmente; `.env.example`, `docker-compose.yml`, `provision.sh` e runbooks compartilhados por várias frentes; ensaios Docker pesados disputam CPU e precisam de projetos e portas próprios; `TenantContext` exigido pelo storage no monitor.

## Mapa de arquivos

| Arquivo | Responsabilidade | Ação | Tasks que tocam |
|---|---|---|---|
| `src/app/database/migrations/20261026_0019_ops_monitoring.sql` | tabelas `ops_heartbeat` e `ops_alert_state` | criar | T-01 |
| `src/app/database/migrations/20261026_0019_ops_monitoring.verify.sql` | verificação SELECT da 0019 | criar | T-01 |
| ⚠ `scripts/test-db/provision.sh` | lista de migrations do banco de teste; env-file configurável | modificar | T-01 (Onda 1), T-14 (Onda 4) |
| `.env.example` | contrato de variáveis (backup, proxy) | modificar | T-02 |
| `docker-compose.yml` | mount do arquivo de proxies, `TRUST_FORWARDED_PROTO` | modificar | T-02 |
| `docker/nginx/default.conf` | include de proxies confiáveis, `HTTPS` ao PHP | modificar | T-02 |
| `docker/nginx/trusted-proxies.dev.conf` | faixas do dev | criar | T-02 |
| `docker/nginx/trusted-proxies.production.conf.example` | exemplo estreito de produção | criar | T-02 |
| ⚠ `.gitignore` | `var/releases/`, `var/ci/`, `var/drill/` | modificar | T-02, T-18 (Onda 6) |
| `src/tests/Unit/ProxyTrustConfigTest.php` | teste da configuração de proxy e portas | criar | T-02 |
| `scripts/db/migration-status.sh` | migrations aplicadas × arquivos | criar | T-03 |
| `scripts/db/migration-status.test.sh` | teste com docker falso | criar | T-03 |
| `src/app/Core/Operations/MigrationStatus.php` | migrations aplicadas por PDO | criar | T-03 |
| `src/bin/migration-status.php` | CLI de migrations para a hospedagem | criar | T-03 |
| `src/tests/Integration/MigrationStatusIntegrationTest.php` | integração do `MigrationStatus` | criar | T-03 |
| `src/app/Core/Security/PasswordResetMailer.php` | e-mail de reset pelo provedor SMTP | criar | T-04 |
| `src/app/control/admin/SystemRequestPasswordResetForm.php` | troca `MailService::send` pelo mailer | modificar | T-04 |
| `src/app/config/translations.json` | 2 chaves do e-mail de reset | modificar | T-04 |
| `src/tests/Support/FakeSmtpServer.php` | servidor SMTP falso em processo | criar | T-04 |
| `src/tests/Integration/SmtpDeliveryIntegrationTest.php` | convite e reset de ponta a ponta no SMTP falso | criar | T-04 |
| `src/tests/Unit/PasswordResetMailerTest.php` | teste do mailer | criar | T-04 |
| `src/tests/Unit/PasswordResetFormsWiringTest.php` | wiring do form com o mailer | modificar | T-04 |
| ⚠ `scripts/release/build-release.sh` | pacote e imagem por commit, manifesto | criar | T-05, T-20 (Onda 6) |
| ⚠ `scripts/release/build-release.test.sh` | teste com repo temporário | criar | T-05, T-20 (Onda 6) |
| `scripts/release/smoke.sh` | fumaça HTTP pós-implantação | criar | T-05 |
| `scripts/release/smoke.test.sh` | teste com curl falso | criar | T-05 |
| `scripts/smoke/billing/webhook-event.php` | evento Stripe assinado para a fumaça | criar | T-06 |
| `scripts/smoke/billing/invite-link.php` | link de convite da fumaça | criar | T-06 |
| `scripts/smoke/billing/cleanup-zz-e3.sql` | limpeza dos dados `ZZ-E3-` | criar | T-06 |
| `docs/runbooks/smoke-billing.md` | roteiro da fumaça versionado | criar | T-06 |
| `src/tests/Unit/SmokeWebhookEventTest.php` | teste do gerador de eventos | criar | T-06 |
| `src/app/Core/Security/RequestScheme.php` | esquema da requisição e cookie Secure | criar | T-07 |
| `src/app/Core/Session/SessionHandlerFactory.php` | aplica o cookie Secure antes da sessão | modificar | T-07 |
| `src/app/service/auth/ApplicationAuthenticationService.php` | `isHttps` delega ao `RequestScheme` | modificar | T-07 |
| `src/tests/Unit/RequestSchemeTest.php` | teste do esquema e do cookie | criar | T-07 |
| `scripts/backup.sh` | backup completo cifrado | modificar | T-08 |
| `scripts/backup.test.sh` | teste com docker falso e gpg real | criar | T-08 |
| `src/app/Core/Operations/PreflightFinding.php` | achado do preflight | criar | T-09 |
| `src/app/Core/Operations/ProductionPreflight.php` | regras do preflight e paridade | criar | T-09 |
| `src/bin/preflight.php` | CLI do preflight | criar | T-09 |
| `src/tests/Unit/ProductionPreflightTest.php` | teste do preflight | criar | T-09 |
| `src/app/Core/Operations/Contract/HeartbeatRepositoryInterface.php` | contrato de heartbeat e nomes de job | criar | T-10 |
| `src/app/Core/Operations/Contract/AlertStateRepositoryInterface.php` | contrato do estado de alerta | criar | T-10 |
| `src/app/Core/Persistence/PdoHeartbeatRepository.php` | heartbeat em `ops_heartbeat` | criar | T-10 |
| `src/app/Core/Persistence/PdoAlertStateRepository.php` | estado em `ops_alert_state` | criar | T-10 |
| `src/app/Core/Operations/OperatorAlertNotifier.php` | e-mail ao operador com deduplicação | criar | T-10 |
| `src/tests/Support/FakeHeartbeatRepository.php` | fake de heartbeat | criar | T-10 |
| `src/tests/Support/FakeAlertStateRepository.php` | fake de estado de alerta | criar | T-10 |
| `src/tests/Unit/OperatorAlertNotifierTest.php` | teste do notificador | criar | T-10 |
| `src/tests/Integration/OpsRepositoriesIntegrationTest.php` | integração dos repositórios | criar | T-10 |
| ⚠ `scripts/restore.sh` | restauração verificada por projeto | modificar | T-11, T-18 (Onda 6) |
| ⚠ `scripts/restore.test.sh` | teste de restore e do ensaio | criar | T-11, T-18 (Onda 6) |
| ⚠ `scripts/restore-drill.sh` | ensaio de restauração isolado e medido | criar | T-11, T-18 (Onda 6) |
| `Makefile` | alvo `restore-drill` | modificar | T-11 |
| `src/app/Core/Operations/Contract/OperationalCountsInterface.php` | contrato das contagens operacionais | criar | T-12 |
| `src/app/Core/Persistence/PdoOperationalCounts.php` | consultas das contagens | criar | T-12 |
| `src/app/Core/Operations/OperationalAlert.php` | alerta operacional | criar | T-12 |
| `src/app/Core/Operations/OperationalMonitor.php` | regras dos alertas | criar | T-12 |
| `src/bin/ops-monitor.php` | CLI do monitor (cron) | criar | T-12 |
| `src/tests/Unit/OperationalMonitorTest.php` | teste das regras | criar | T-12 |
| `src/tests/Integration/PdoOperationalCountsIntegrationTest.php` | SQL das contagens no banco de teste | criar | T-12 |
| `src/tests/Unit/OpsMonitorWiringTest.php` | wiring do CLI | criar | T-12 |
| `src/bin/billing-reconcile.php` | heartbeat do reconciliador | modificar | T-13 |
| `src/app/Core/Billing/BillingEventPayloadRetention.php` | retenção LGPD do payload | criar | T-13 |
| `src/bin/billing-event-retention.php` | CLI da retenção | criar | T-13 |
| `src/tests/Integration/BillingEventPayloadRetentionIntegrationTest.php` | integração da retenção | criar | T-13 |
| `src/tests/Unit/BillingCronWiringTest.php` | wiring dos bins de cobrança | criar | T-13 |
| `.github/workflows/ci.yml` | job fino que chama o script de CI | modificar | T-14 |
| ⚠ `scripts/ci/run.sh` | pipeline reproduzível | criar | T-14, T-19 (Onda 6) |
| ⚠ `scripts/ci/run.test.sh` | teste da pipeline com docker falso | criar | T-14, T-19 (Onda 6) |
| `scripts/prepare-adianti-bootstrap.sh` | saída e env-file configuráveis | modificar | T-14 |
| `scripts/release/rollback-drill.sh` | ensaio de rollback medido | criar | T-15 |
| `docs/runbooks/ensaio-restauracao-rollback.md` | registro dos tempos medidos | criar | T-15 |
| `README.md` | Operação, Backup, CI/CD | modificar | T-16 |
| `docs/runbooks/README.md` | índice dos runbooks | modificar | T-16 |
| ⚠ `docs/runbooks/restore-backup.md` | backup e restauração novos | modificar | T-16, T-18 (Onda 6) |
| `docs/runbooks/build-versioning.md` | CI e imagem por SHA | modificar | T-16 |
| `docs/runbooks/migration-rollback.md` | rollback com `migration-status` e backup | modificar | T-16 |
| `docs/runbooks/environments.md` | produção: proxy, cookies, preflight | modificar | T-16 |
| ⚠ `docs/runbooks/shared-hosting-mysql57.md` | 0019, crons, preflight, correção `mark_refunded` | modificar | T-16, T-18 (Onda 6) |
| `docs/runbooks/tests.md` | CI local e testes de scripts | modificar | T-16 |
| `docs/runbooks/observabilidade.md` | alertas e heartbeats | criar | T-16 |
| ⚠ `docs/runbooks/cron.md` | crons versionados | criar | T-16, T-18 (Onda 6) |
| `docs/runbooks/release.md` | procedimento de release | criar | T-16 |
| `docs/security/lgpd-retencao.md` | retenção do payload de cobrança | criar | T-16 |
| `src/app/database/migrations/README.md` | `migration-status.sh` no procedimento | modificar | T-16 |
| `src/app/Core/Operations/BackupCipher.php` | cifra autenticada `CVBK1` (openssl) | criar | T-17 |
| `src/app/Core/Operations/HostingBackup.php` | dump PDO e empacotamento sem Docker | criar | T-17 |
| `src/bin/hosting-backup.php` | CLI de backup e `--decrypt` da hospedagem | criar | T-17 |
| `src/tests/Unit/BackupCipherTest.php` | teste da cifra | criar | T-17 |
| `src/tests/Integration/HostingBackupIntegrationTest.php` | integração do backup da hospedagem | criar | T-17 |

| `scripts/cron-run.sh` | pula o cron durante a restauração (flag de manutenção) | criar | T-18 |
| `scripts/cron-run.test.sh` | teste do wrapper de cron | criar | T-18 |

Arquivo tocado por mais de uma task leva ⚠ na linha e a resolução (serializar em ondas diferentes, worktree por agente ou escritor único) fica em "Estratégia de execução"; caminho exclusivo não resolve arquivo compartilhado na mesma onda.

## Decisões de arquitetura

| Decisão | Alternativas consideradas | Motivo da escolha |
|---|---|---|
| Heartbeat e estado de alerta em tabelas (0019) | arquivo em `/tmp` (como o worker); Redis | o app é read-only com `/tmp` efêmero; a hospedagem compartilhada só tem PHP+MySQL; o backup no host grava pelo container mysql |
| Backup como um `.tar.gpg` com MANIFEST (checksums, contagens, migrations) | dumps soltos; `openssl enc` | `gpg` simétrico autentica (MDC) e existe no host; o MANIFEST permite verificar antes de escrever e conferir o restaurado |
| Volumes lidos por `docker compose run --no-deps --entrypoint tar app` | `exec` no app; imagem extra (alpine/busybox) | funciona com o app parado e sem baixar imagem nova; o MinIO entra pelo volume `<projeto>_minio_data` |
| Restauração com `--project`/`--env-file` e confirmação `RESTORE_<PROJETO>` | restaurar só no projeto padrão | o ensaio isolado usa o mesmo caminho de código da restauração real; a confirmação por projeto evita restaurar o dev por engano |
| CI como `scripts/ci/run.sh` com projeto e env-file próprios | lógica no YAML | reproduzível na máquina do desenvolvedor sem tocar o `.env` nem os volumes do dev; o YAML fica fino |
| `migration-status.sh` só leitura, comparando arquivos × `schema_migrations` | runner de migrations | as migrations seguem manuais e autorizadas (README de migrations); o registro já existe na tabela |
| Release por `git archive` do commit (+ vendor) e imagem construída do mesmo export | `docker compose build` do working tree | artefato identificado pelo SHA, sem arquivos locais não commitados |
| `set_real_ip_from` em arquivo incluído escolhido por `NGINX_TRUSTED_PROXIES_FILE`; `HTTPS` repassado pelo nginx | `envsubst` em template; subnet fixa da rede Docker | não recria redes do dev; troca só um mount; produção usa o IP real do proxy |
| `X-Forwarded-Proto` no PHP só com `TRUST_FORWARDED_PROTO=true` | lista de IPs confiáveis no PHP | depois do `real_ip` o `REMOTE_ADDR` já é o visitante; o nginx é quem conhece o par direto |
| Cookie Secure aplicado em `SessionHandlerFactory::createFromEnvironment()` | `index.php`/`engine.php`; `app.ini` fixo | os dois primeiros são protegidos por hash; `cookie_secure=1` fixo quebraria o login em HTTP no dev |
| Reset de senha pelo `EmailProviderFactory` (texto) | configurar `mail_from` no `SystemPreference` | um só SMTP e um só remetente (`SMTP_FROM_ADDRESS`) para convite, reset e alertas; `MailService.php` é protegido |
| Retenção do payload: esvaziar (`''`) após 30 dias em `processed`/`ignored` | apagar a linha; reter `failed` | `MAX(event_created)` é o cursor do reconciliador e `event_id` deduplica; `failed`/`received` ainda podem precisar de análise |
| Monitor com contagens atrás de interface | SQL no próprio monitor | as regras ficam testáveis sem montar o grafo de FKs de mensagens e documentos |
| Alerta só por e-mail com deduplicação de 6 h | Slack/webhook; sem deduplicação | sem serviço pago; o cron de 15 min não inunda a caixa do operador (confirmado pelo usuário) |
| Dois alvos de hospedagem: CLIs PHP por PDO para o que roda nos dois (preflight, `migration-status.php`, monitor, heartbeats, retenção) | só shell com Docker | a compartilhada não tem Docker nem cliente `mysql`; o mesmo código vai no pacote de release |
| Backup da hospedagem por PHP (dump PDO com triggers, `PharData`, cifra `CVBK1` AES-256-CTR + HMAC-SHA256, PBKDF2) | `mysqldump`/`gpg` via `exec`; backup do painel | `exec` e binários costumam faltar; mesmo MANIFEST v1 e restauração automatizada pela pilha Docker; triggers são os CHECK do 5.7 |

## Diagrama de dependências

```text
T-01 → T-08 → T-11 → T-14 → T-16
T-01 → T-10 → T-12 → T-16
            T-10 → T-13 → T-16
T-02 → T-07 → T-16
T-02 → T-08
T-02 → T-09 → T-16
T-03 → T-14
T-03 → T-15 → T-16
T-05 → T-14
T-05 → T-15
T-08 → T-15
T-11 → T-15
T-04 (independente)
T-01 → T-17 → T-11
T-02 → T-17 → T-15
T-17 → T-16
T-03 → T-09
T-06 → T-16
T-11 → T-18 (Onda 6, correção); T-16 → T-18
T-14 → T-19 (Onda 6, correção)
T-05 → T-20 (Onda 6, correção)
```

## Estratégia de execução
- Branch de trabalho: `task/etapa-4-operacao`
- Branch base: `task/etapa-3-contratacao`
- Commits da onda: cada implementador commita os próprios caminhos (`git commit -- <caminhos>`) com o trailer da task. O fechador usa a skill `new-commit --auto` só se sobrou algo sem commit e sempre registra o estado das tasks num `chore(tasks): registra commits da onda N`. Por premissa do desenvolvedor, a entrega da Fase 5 preserva os commits por onda (não roda o `entrega.py`); push e PR só a pedido dele.
- Isolamento em ondas com edições paralelas: caminho exclusivo (cada agente só nos arquivos da própria task; o único arquivo ⚠, `scripts/test-db/provision.sh`, fica em ondas diferentes: T-01 na Onda 1 e T-14 na Onda 4)
- Pré-condição da Onda 2: a 0019 aplicada ao `centralvet_test` (T-01). Sem ela, os testes de integração de T-10, T-12 e T-13 saem `FAIL`.
- Pré-condição de T-08, T-17, T-11 e T-15: `var/drill/passphrase` criado com `openssl rand` (frase descartável, ignorada pelo git; quem chegar primeiro na Onda 2 cria, o outro reaproveita), `var/drill/backups/` com o backup real do dev feito por T-08 e `var/drill/backups-hosting/` com o de T-17 (ambos sem heartbeat). T-11 e T-15 usam esses arquivos.
- Pré-condição de T-05, T-14 e T-15: rede para o packagist (`composer:2.8.11`) e para baixar `nginx:1.27.5-alpine`/`mysql:8.0.43`/`redis:7.4.5-alpine` se faltarem. Os projetos `centralvet-ci-local` e `centralvet-drill` são distintos do `centralvet` e derrubados com `down -v` no fim; o ensaio usa a porta 18081.
- Onda 4 roda T-14 e T-15 em paralelo: ambos constroem imagens e sobem projetos Docker próprios (sem porta em comum); se a máquina ficar lenta, o orquestrador pode serializar.
- Onda 6 — correção (revisão final), BASE `36a7407`: origem `reviews/final.md` (bloqueante e pendência promovida de T-11; pendências abertas de T-14, T-05/T-15 e T-16). Cada arquivo ⚠ divide a task nova com uma task de onda anterior. Na onda, T-18, T-19 e T-20 têm caminhos disjuntos (caminho exclusivo). Validação cruzada da Onda 6: SUITE completo, LINTALL, BASHN e o `restore-drill.sh` real nos dois formatos (`var/drill/backups` e `var/drill/backups-hosting`), sempre no projeto isolado `centralvet-drill`, nunca no projeto nem no banco `centralvet`.
- Validação cruzada de cada onda: SUITE completo sem filtro de classe (`Failed: 0`, nenhuma classe de integração nova em `SKIP`), LINTALL contra `baseline/php-lint-all.txt` e BASHN contra `baseline/bash-n.txt`. Falhas intermitentes 1213/1205 no `centralvet_test` por suítes paralelas: rodar de novo isolado.

## Ondas de execução

### Onda 1
- T-01, T-02, T-03, T-04, T-05, T-06

### Onda 2
- T-07, T-08, T-09, T-10, T-17

### Onda 3
- T-11, T-12, T-13

### Onda 4
- T-14, T-15

### Onda 5
- T-16

### Onda 6 — correção (revisão final)
- T-18, T-19, T-20

## Agentes

| Agente | subagent_type | model | Tasks |
|---|---|---|---|
| Sun Tzu — orquestrador | — | — | todas |
| Darwin | general-purpose | inherit | T-01 |
| Levi | general-purpose | inherit | T-02 |
| Arquimedes | general-purpose | inherit | T-03 |
| Aang | general-purpose | inherit | T-04 |
| Batman | general-purpose | inherit | T-05 |
| Maquiavel | general-purpose | inherit | T-06 |
| Yoda | general-purpose | inherit | T-07 |
| Naruto | general-purpose | inherit | T-08 |
| Jaspion | general-purpose | inherit | T-09 |
| Platão | general-purpose | inherit | T-10 |
| Spock | general-purpose | inherit | T-11 |
| Sherlock | general-purpose | inherit | T-12 |
| Athena | general-purpose | inherit | T-13 |
| Kratos | general-purpose | inherit | T-14 |
| Thanos | general-purpose | inherit | T-15 |
| Goku | general-purpose | inherit | T-17 |
| Gandalf (docs) | geduc:documentador | sonnet | T-16 |
| Hegel | general-purpose | inherit | T-18 |
| Saitama | general-purpose | inherit | T-19 |
| Tesla | general-purpose | inherit | T-20 |

## Review Focus
- Login no dev por `http://localhost:8081` depois do cookie Secure (sem `HTTPS` e sem `TRUST_FORWARDED_PROTO`) → `session.cookie_secure` não liga e o cookie de sessão continua sendo aceito em HTTP → T-07
- Backup com o MinIO desligado ou sem o volume `<projeto>_minio_data` → o backup segue com `minio=skipped` no MANIFEST, código 0 → T-08
- Restauração com frase errada ou parte adulterada (`.tar.gpg` ou `.tar.gz.cvbk`) → código 5 antes de qualquer escrita no banco ou nos volumes do projeto → T-11
- Retenção do payload → nenhuma linha de `billing_event` apagada e `latestEventCreated()` (cursor do reconciliador) inalterado → T-13
- Cron do monitor a cada 15 min com o mesmo problema aberto, ou `BILLING_OPERATOR_EMAIL` vazio → no máximo 1 e-mail por chave a cada 6 h; sem destinatário, só `error_log` `ops_alert_no_recipient` → T-10

## Critérios gerais de aceite
- SUITE completo sem filtro de classe termina com `Failed: 0` ao fim de cada onda, sem classe de integração nova em `SKIP`.
- LINTALL e BASHN sem nenhuma linha nova em relação a `baseline/php-lint-all.txt` e `baseline/bash-n.txt`.
- `git diff task/etapa-3-contratacao..HEAD | grep -cE '^[+].*(sk_live_|sk_test_|rk_live_)[A-Za-z0-9]+'` imprime `0` (nenhuma chave nova; segredos de teste só no formato `whsec_test_etapa4`).
- `sha256sum .env` igual no início e no fim da execução; `SELECT COUNT(*) FROM centralvet.schema_migrations` igual no início e no fim.
- `restore-drill done ... mismatches=0` para os dois formatos de backup e `rollback-drill done ... smoke_failures=0` registrados em `docs/runbooks/ensaio-restauracao-rollback.md`.
- `scripts/ci/run.sh` termina com `ci: passed` localmente, e o `ci.yml` chama só esse script.
