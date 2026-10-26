# Notas de execução

## Decisões tomadas
- 2026-10-26 · plano · onda — — Branch base `task/etapa-3-contratacao` (HEAD `992877a`) por pedido explícito do desenvolvedor nas premissas: as Etapas 1–3 ainda não foram integradas em `dev`/`main`. Produção declarada: `main` (o `branches.py` devolve `main` com fonte `padrão`; o projeto não declara branch de teste).
- 2026-10-26 · plano · onda — — Entrega preserva os commits por onda (sem `entrega.py`), conforme premissa e memória do usuário.
- 2026-10-26 · T-01/T-10 · onda 1 — Heartbeat e deduplicação de alertas em tabelas (`ops_heartbeat`, `ops_alert_state`, migration 0019, sem CHECK): o container app é read-only com `/tmp` efêmero e a hospedagem compartilhada só tem PHP+MySQL. A 0019 vai ao `centralvet_test` (padrão autorizado); dev e produção ficam para o runbook, com autorização.
- 2026-10-26 · T-04 · onda 1 — O reset de senha deixa o `MailService` do Adianti (`mail_from` no `SystemPreference`, protegido por hash) e passa ao `EmailProviderFactory` (`SMTP_FROM_ADDRESS`/`SMTP_FROM_NAME`), o mesmo do convite e dos alertas. Corpo em texto (o provedor usa `isHTML(false)`), com 2 chaves novas em `translations.json`. Não há mailpit no compose; o SMTP é validado por um servidor falso em processo (`FakeSmtpServer`, loopback), sem rede.
- 2026-10-26 · T-07 · onda 2 — O cookie `Secure` da sessão entra em `SessionHandlerFactory::createFromEnvironment()` (chamado por `index.php` e `engine.php` antes de `new TSession`), porque os dois são protegidos por hash. `X-Forwarded-Proto` só vale no PHP com `TRUST_FORWARDED_PROTO=true`; no compose, o nginx repassa `HTTPS=on` quando o proxy da frente manda `X-Forwarded-Proto: https`. O nginx só escuta em 127.0.0.1, então só o proxy local o alcança.
- 2026-10-26 · T-02 · onda 1 — `set_real_ip_from` sai para um arquivo incluído e escolhido por `NGINX_TRUSTED_PROXIES_FILE` (dev mantém as faixas RFC1918; o exemplo de produção traz o IP documental `203.0.113.10`). Fixar subnet na rede Docker exigiria recriar as redes do dev.
- 2026-10-26 · T-08 · onda 2 — Backup = um `.tar.gpg` (AES256, frase em `BACKUP_PASSPHRASE_FILE`, fora do repositório) com `MANIFEST`, `db.sql.gz`, `files.tar.gz`, `documents.tar.gz` e `minio.tar.gz` se houver o volume. Volumes lidos por `docker compose run --no-deps --entrypoint tar app`. Retenção padrão de 14 dias. Cópia externa por diretório montado ou S3-compatível (`aws s3 cp`), sem contratar serviço. No dev, sempre `--no-heartbeat` (a tabela não existe lá e seria escrita no banco de dev).
- 2026-10-26 · T-12 · onda 3 — Alertas só por e-mail ao `BILLING_OPERATOR_EMAIL` (canal da T-31), deduplicados por 6 h, mais o código 2 do `ops-monitor.php` para o cron. Limiares: reconciliador 45 min (cron de 15 min), backup 26 h (cron diário), fila parada 30 min, falhas de mensagem/documento nas últimas 24 h, pedido pago ou com falha de provisionamento há mais de 1 h, evento de cobrança `failed` ou `received` há mais de 1 h. O probe de storage usa o tenant 1 e `DOCUMENT_SYSTEM_USER_ID`.
- 2026-10-26 · T-13 · onda 3 — Retenção LGPD: `payload` esvaziado (`''`) após 30 dias para `processed`/`ignored`; a linha fica (o `MAX(event_created)` é o cursor do reconciliador e o `event_id` deduplica). `failed`/`received` não são esvaziados; o alerta `billing_event_failed` cobra a ação.
- 2026-10-26 · T-14 · onda 4 — CI vira `scripts/ci/run.sh` com projeto (`COMPOSE_PROJECT_NAME`) e env-file (`COMPOSE_ENV_FILES`) próprios, para rodar igual no GitHub Actions e localmente sem tocar o `.env` nem os volumes do dev. `provision.sh` e `prepare-adianti-bootstrap.sh` ganham variáveis de env-file e diretório de saída, sem mudar o comportamento padrão.
- 2026-10-26 · T-05/T-15 · ondas 1 e 4 — Release a partir de `git archive` do commit (pacote com `vendor/` para a hospedagem compartilhada; imagem `centralvet/app:<sha40>` do mesmo export para Docker/VPS). O rollback é ensaiado entre `992877a` (fim da Etapa 3) e o HEAD da etapa, no projeto `centralvet-drill` (porta 18081).

- 2026-10-26 · plano · revisão 1 — Respostas do usuário às perguntas do plano: (1) cópia externa por `BACKUP_OFFSITE_DIR` e S3-compatível genérico, testados só localmente; (2) alerta só por e-mail ao `BILLING_OPERATOR_EMAIL`, deduplicação de 6 h e código 2 do cron; (3) hospedagem alvo: as duas, Docker/VPS e compartilhada MySQL 5.7; (4) CI no GitHub Actions chamando `scripts/ci/run.sh`.
- 2026-10-26 · plano · revisão 1 — Dois alvos: release, preflight, `migration-status`, monitor e heartbeats funcionam nos dois. T-03 ganha `bin/migration-status.php` (PDO, mesma saída do shell); T-09 ganha `--with-db` (`db_version` 8.0/5.7, WARN em 5.7 sem `DB_STRICT_MODE=true`, `migrations_pending`); T-01 confere a 0019 no `prepare-mysql57.py`.
- 2026-10-26 · T-17 · onda 2 — Backup da hospedagem compartilhada por `php bin/hosting-backup.php` (sem Docker, `gpg` nem `mysqldump`): dump PDO com tabelas, dados em lotes e triggers; `PharData` para `files`/`documents`; mesmo MANIFEST v1 de T-08 (`project=hosting`, `minio=skipped`); cifra `CVBK1` (AES-256-CTR + HMAC-SHA256, PBKDF2 200000) por `openssl`; retenção, `BACKUP_OFFSITE_DIR` e heartbeat `backup`. A restauração automatizada do `.tar.gz.cvbk` é pela pilha Docker (`restore.sh`, T-11) e ensaiada em T-15; na hospedagem, procedimento documentado com `--decrypt` (T-16). O S3 a partir da hospedagem fica fora.
- 2026-10-26 · T-16 · onda 5 — Cron documentado nos dois formatos: crontab do host Docker e entradas do agendador do painel da hospedagem.
- 2026-10-26 · T-04 · onda 1 — O RED f512e7e tocou `PasswordResetFormsWiringTest.php`, fora do bloco Teste RED mas dentro de Arquivos prováveis; aceito, sem fix loop.
- 2026-10-26 · T-01 · onda 1 — Achado plano-mandou: timestamp NOT NULL sem DEFAULT viraria ON UPDATE no MySQL 5.7; corrigido (2a4c63c) com DEFAULT CURRENT_TIMESTAMP(6) sem ON UPDATE; a 0019 foi editada e reaplicada só no `centralvet_test`.
- 2026-10-26 · T-06 · onda 1 — Escopo extra autorizado: filtro por path em `BillingAcquisitionFlowIntegrationTest.php:195` (fecha a pendência calls[1] da Etapa 3) e limpeza de 62 tabelas com FK para tenant em `cleanup-zz-e3.sql` (5a7cc83).
- 2026-10-26 · plano · onda 1 — Ambiente: MySQL e Redis sobem com `docker compose up -d` quando preciso.
- 2026-10-26 · T-17 · onda 2 — O comando real com `--user 1000:33` (permissões de `var/drill` e do volume de documentos) é adaptação de ambiente, não falha; a T-16 deve registrá-lo no runbook.
- 2026-10-26 · T-17 · onda 2 — A correção da asserção RED da 0019 para a lista completa de versões de `schema_migrations` é legítima.
- 2026-10-26 · T-10 · onda 2 — O relatório cita o hash 31343ab por engano; a implementação é ce97252. Só divergência documental.
- 2026-10-26 · T-12 · onda 3 — `StorageException` na montagem do storage vira o alerta `storage_unavailable` (código 2); as demais exceções seguem com código 1. Validador aceitou.
- 2026-10-26 · T-11 · onda 3 — O restore recria o banco e substitui files/documents/minio, só no projeto alvo, protegido por RESTORE_CENTRALVET/RESTORE_CENTRALVET_DRILL.
- 2026-10-26 · T-11 · onda 3 — Saídas aditivas: linha `restore: timings` e RESTORE_MANIFEST_COPY.
- 2026-10-26 · T-11 · onda 3 — O `--decrypt` do .cvbk monta `src/` como :ro, porque a imagem dev não tem hosting-backup.php.
- 2026-10-26 · T-14 · onda 4 — A suíte do CI não monta `src/` e usa `composer dump-autoload --dev` em volume anônimo; o provision aplica o schema ao banco `centralvet` efêmero do projeto de CI (o script recusa CI_PROJECT=centralvet). Validador aceitou os dois.
- 2026-10-26 · T-15 · onda 4 — `sem teste:` no plano, sem RED.
- 2026-10-26 · plano · onda 4 — O gate foi interrompido pelo limite de sessão da API e retomado sem resíduo de projeto ou volume.

- 2026-10-26 · T-18 · onda 6 — Correção da revisão final (bloqueante e promovida de T-11): o restore para os serviços que escrevem ou servem tráfego (`nginx`, `app`, `worker`, `minio`, só os que estão no ar) e os religa num `trap` de `EXIT` (também em falha), em vez de recusar rodar. Assim o operador não precisa parar e religar à mão, e o ensaio prova o religamento. Os crons do host Docker passam por `scripts/cron-run.sh`, que pula o job enquanto existe o flag `var/maintenance/<projeto>.restore`. Na hospedagem compartilhada, o runbook manda pausar o agendador do painel e pôr o site em manutenção (sem flag nos bins PHP, que não seria barato). Código 7 = restaurado, mas algum serviço não religou.
- 2026-10-26 · T-19/T-20 · onda 6 — O `CI_WORKDIR` só é aceito sob `var/ci/` ou num diretório temporário, e um diretório não vazio precisa do marcador `.ci-workdir`. Os `script-tests` passam a ser descobertos por `find`. O `build-release.sh` fixa `umask 022`. A correção dos códigos de saída de `cron.md`/`shared-hosting-mysql57.md` (pendência de T-16) fica com T-18, que é a escritora desses runbooks na onda. BASE da Onda 6: `36a7407`.
- 2026-10-26 · T-18 · onda 6 — Parar e religar os serviços em vez de recusar o restore (decisão do planejador). O flag `var/maintenance/<projeto>.restore`, com o `cron-run.sh`, faz os crons pularem; na hospedagem compartilhada isso é procedimento de painel.
- 2026-10-26 · T-18 · onda 6 — O desvio aditivo `CENTRALVET_MAINTENANCE_DIR` foi aceito pelo validador.

## Bloqueios
- nenhum
- 2026-10-26 · T-16 · onda 5 — Escopo extra autorizado em migrations.md, cirurgia.md, internacao.md e landing-leads.md: `gzip -t` trocado pela verificação atual.
- 2026-10-26 · T-16 · onda 5 — A instrução citava `scripts/restore.sh --decrypt`, que não existe; o implementador usou restore-drill.sh e o `--decrypt` do hosting-backup.php, desvio correto.
- 2026-10-26 · T-16 · onda 5 — Plano marcado como `sem teste:` (docs), sem RED.

## Descobertas
- O CI atual falharia: `tests/run.php` recusa sem o `centralvet_test` ("Refusing to run: test MySQL database centralvet_test not found"), o `.env` gerado não tem `MIGRATION_DB_*`, o usuário de migration não existe no compose e a imagem só copia `src/` (testes que leem `docker/`, `docs/` e `.env.example` não acham os arquivos). Nenhuma etapa de lint.
- `scripts/test-db/verify.sql` compara o `centralvet_test` com o `centralvet`, que no CI está vazio: não serve como verificação de schema na CI (substituído por `migration-status.sh`).
- O banco de dev `centralvet` tem 16 linhas em `schema_migrations` (sem 0017 e 0018); o `centralvet_test` tem 0001–0018.
- O cookie de sessão (`PHPSESSID`) nunca recebe `Secure`; só o `cv_csrf` usa `isHttps()`, que confia em `X-Forwarded-Proto` de qualquer cliente.
- O `/health` cobre só MySQL e Redis; não há heartbeat de job de negócio além do arquivo do worker.
- A fumaça da Etapa 3 dependia de três arquivos do scratchpad da sessão `fc92cc27-...`, que já não existem; T-06 reconstrói a partir de `reports/T-22-fumaca.md`.
- `STORAGE_DRIVER` padrão é `s3` com o MinIO desligado por profile; documentos usam `DOCUMENT_STORAGE_DRIVER=local` (volume `app_documents`).
- [T-01] 0019 aplicada no `centralvet_test` (66b71cd); correção 2a4c63c: `last_success_at`/`last_sent_at` com DEFAULT CURRENT_TIMESTAMP(6) sem ON UPDATE (seguro no 5.7).
- [T-02] Contrato de proxies (445983e): `TRUST_FORWARDED_PROTO`, `NGINX_TRUSTED_PROXIES_FILE`, `HTTPS` repassado pelo nginx, variáveis `BACKUP_*` no `.env.example`, `.gitignore` com `var/releases/`, `var/ci/`, `var/drill/`.
- [T-03] `scripts/db/migration-status.sh` e `bin/migration-status.php` prontos (códigos 0/1/2); `MigrationStatus::lines()` público reutilizável pela T-09. Dev `centralvet`: applied=16, pending=3.
- [T-04] Reset de senha sai por `PasswordResetMailer` + `EmailProviderFactory` (`SMTP_FROM_*`); `FakeSmtpServer` disponível em `src/tests/Support`.
- [T-05] `scripts/release/build-release.sh [--image] <commit>` e `smoke.sh <base-url>` prontos; manifesto `migration <NNNN> sha256=<hex>`; passar SHA fixo, não `HEAD`.
- [T-06] `webhook-event.php` aceita `customer.subscription.deleted`, `--id` e `--timestamp`; runbook em `docs/runbooks/smoke-billing.md` (T-16 pode linkar no índice).
- [T-08] `scripts/backup.sh` em bash: `.tar.gpg` com MANIFEST v1 (`parts=` conta o MANIFEST: 4 sem MinIO, 5 com), heartbeat `detail` = basename, código 4 (cópia externa) sem heartbeat; caminhos `BACKUP_*` relativos à raiz do projeto. Backup real do dev em `var/drill/backups/centralvet-20261026T140417Z.tar.gpg`, frase em `var/drill/passphrase` (não apagar).
- [T-07] `CentralVet\Security\RequestScheme` (`isHttps`, `current`, `secureSessionCookie`); `SessionHandlerFactory::createFromEnvironment()` liga `session.cookie_secure` fora do CLI. T-16: em hospedagem sem nginx, `TRUST_FORWARDED_PROTO=true` só atrás de proxy que sobrescreve o cabeçalho.
- [T-09] `php bin/preflight.php [--trusted-proxies-file=] [--with-db]`: sai 1 com FAIL, 0 sem FAIL, 2 em argumento inválido; no dev fail=9 warn=1 (esperado).
- [T-10] `HeartbeatRepositoryInterface`, `AlertStateRepositoryInterface`, `PdoHeartbeatRepository`/`PdoAlertStateRepository`, `OperatorAlertNotifier` (reenvio após 21600 s); fakes em `src/tests/Support` (`FakeAlertStateRepository::sentCount`).
- [T-17] `php bin/hosting-backup.php [--no-heartbeat]` e `--decrypt <in> <out>`; pacote `centralvet-<ts>.tar.gz.cvbk` (CVBK1). Linhas `migration` com a versão completa; triggers sem DEFINER entre `DELIMITER ;;` (restaurar com o cliente `mysql`). T-11/T-15/T-16: rodar com `--user 1000:33` por causa do volume de documentos 0750 www-data e `var/drill` 0700; arquivos com umask 077.
- [T-13] `php bin/billing-event-retention.php` pronto (978a7e5): imprime `{"purged":n,"batches":n}`, heartbeat `billing-event-retention` com detail `purged=<n> batches=<n>`; fixa o fuso por `APP_TIMEZONE` (config/environment.php) como o reconciliador. `bin/billing-reconcile.php` grava `billing-reconcile` só no sucesso (detail = linha de contadores); falha → `error_log('billing-reconcile heartbeat_failed=<classe>')`, código 0. T-16: cron diário da retenção.
- [T-12] `php bin/ops-monitor.php` pronto (RED 1aacce2, impl. 132bb73): imprime `ops-monitor alerts=<n> notified=<n> keys=<chaves|->`, sai 0/2/1, grava heartbeat `ops-monitor` (detail = a linha sem o prefixo). Chaves de alerta: billing_reconcile_stale, backup_stale, billing_event_failed, billing_order_stuck, message_failed, message_queue_stalled, document_failed, document_queue_stalled, storage_unavailable. `StorageException` ao montar o storage (raiz local ausente) vira `storage_unavailable`, não código 1 — T-16 pode documentar.
- [T-11] `scripts/restore.sh [--project] [--env-file] <arquivo>` e `scripts/restore-drill.sh [--keep] <arquivo>` prontos (RED c8913ca, impl 73289c1). Códigos restore: 2 uso/config, 3 sem `CONFIRM_RESTORE=RESTORE_<PROJETO>`, 5 frase/integridade (nada escrito), 6 falha após iniciar escrita. Recria o banco antes do dump e substitui files/documents/minio. Aditivos: linha `restore: timings decrypt_seconds=<n> db_seconds=<n> files_seconds=<n>` antes da final e `RESTORE_MANIFEST_COPY=<caminho>`. Drill: env `var/drill/.env` gerado só se ausente (sobrescrevível por `RESTORE_DRILL_ENV_FILE`), sai 4 se o projeto não sobe, recusa `.sql.gz`, remove volumes `centralvet-drill_*` restantes. `.cvbk` decifrado com `--user $(id -u):$(id -g)` e `src/` montado `:ro` (imagem dev sem `hosting-backup.php`). Ensaio real T-08 e T-17: mismatches=0.
- [T-15] `scripts/release/rollback-drill.sh <anterior> <novo> <backup>` e `docs/runbooks/ensaio-restauracao-rollback.md` prontos (5a2cefd). Medidos: restore-drill .tar.gpg elapsed_seconds=20, .cvbk elapsed_seconds=13, rollback seconds=16, total_seconds=74, smoke_failures=0. `build-release.sh --image` com umask 077 gera imagem cujo php-fpm não lê a própria config (app unhealthy): construir sempre com umask 022 (T-16 documenta; T-14 atenção no CI). O backup do dev tem 0015/0018/0019 pendentes (não 0017).
- [T-14] `scripts/ci/run.sh` pronto (RED 4d0d7c1, impl 97717be): execução real local `ci: passed` (2390/0 FAIL, schema pending=0 unknown=0). Desvios: suíte sem bind de `src/` (imagem do checkout + `composer dump-autoload --dev` em volume anônimo sobre src/vendor) e `provision` aplica o schema também ao `centralvet` efêmero da CI (testes que sobem init.php leem DB_DATABASE). `provision.sh --list` novo; `PROVISION_ENV_FILE`, `BOOTSTRAP_OUTPUT_DIR`, `BOOTSTRAP_ENV_FILE`, `CI_SCRIPT_TESTS`. T-16: `docs/runbooks/tests.md` ainda descreve a CI antiga.
- [T-16] Runbooks prontos: cron, release, observabilidade, lgpd-retencao; restore-backup, tests (CI), environments, shared-hosting-mysql57 atualizados; `--user 1000:33` do hosting-backup entra em cron.md e shared-hosting-mysql57.md (69352f7, 8cf19e5); `gzip -t` removido dos runbooks; T-31 (mark_refunded) corrigida.

## Pendências
- Banco dev `centralvet` com 3 migrations pendentes (0017, 0018, 0019); aplicar exige autorização do usuário.
- T-01: `.verify.sql` da 0019 — conferir a cobertura da checagem de ON UPDATE/timestamp sem default (sugestão da revisão; a correção 1 adicionou as duas consultas).
- T-02: o map de `X-Forwarded-Proto` aceita o header de qualquer cliente que alcance o nginx; condicionar a `$realip_remote_addr` com `geo`.
- T-02: `trusted-proxies.production.conf` (cópia do exemplo) fica dentro do repositório e não é ignorado pelo git.
- T-03: sem teste automatizado para os códigos de saída e a saída de `bin/migration-status.php`; o teste de "sem schema_migrations" monta PDO próprio em vez de reaproveitar o `MysqlIntegrationTestCase`.
- T-04: `app/resources/system/admin/reset_password.html` ficou sem uso; remover ou atribuir a uma task.
- T-05: o build real da imagem e a fumaça com a pilha no ar ficam para a T-15; `php_target=8.4` do manifesto é declarado, não verificado contra `config.platform.php` do lock.
- T-06: `--id`, `--timestamp` e `customer.subscription.deleted` sem teste em `SmokeWebhookEventTest`; uma FK nova para `tenant` exige um DELETE novo em `cleanup-zz-e3.sql`.
- T-08: teste não hermético (herda `BACKUP_*` do ambiente; `unset` no topo de `scripts/backup.test.sh`); projeto padrão do `-p` é o `basename` da raiz, mas o compose usa `centralvet` (`scripts/backup.sh:93`); qualquer saída ≠ 0 do tar do volume aborta o backup (tar 1 "file changed"); as contagens do inventário não saem do mesmo snapshot do dump (a T-11 deve tolerar ou derivar do dump).
- T-09: cobertura do `ProductionPreflightTest` sem várias regras (public_url_https, app_seed, db_password, redis_password, smtp_driver, operator_email, stripe, trust_forwarded_proto, ai_provider, display_errors, `migrations_pending` com pendência); `trusted_proxies` só reprova as 4 faixas literais (`0.0.0.0/1`, `10.0.0.0/7`, `::/0` passam); `display_errors` reflete o PHP CLI (T-16 registra no runbook); `APP_DEBUG=1` passa como PASS.
- T-10: `alert_key`/`job` são `varchar(64)` e o notifier não limita a chave (chave > 64 faz `markSent` lançar depois da entrega e o e-mail volta a cada cron).
- T-17: `parts=` imprime 3 contra 4 da T-08; o dump fixa `SQL_MODE` e não restaura o `sql_mode` de cada trigger; `decryptFile` confere o HMAC e decifra em duas passadas (decifrar para `.part` numa passada); CLI sem teste automatizado para heartbeat bem-sucedido e códigos 2/5; o heartbeat nunca foi testado contra banco real.
- T-17: o `--user 1000:33` do comando real deve entrar no runbook da T-16 (ruling da onda 2).
- T-11: teste de adulteração só no `.tar.gpg`; faltam casos de parte trocada no `.cvbk` (scripts/restore.test.sh:242-248).
- T-11: restore com projeto vivo: app/worker/minio rodando durante DROP DATABASE e reextração do minio; parar antes ou documentar no runbook T-16 (restore.sh:213-240).
- T-11: `trap 'exit 6'` (restore.sh:167) e `trap 'exit 4'` (restore-drill.sh:96) valem também antes de qualquer escrita; Ctrl-C na verificação sai com código errado.
- T-11: stderr de gpg/tar silenciado; disco cheio no staging vira "wrong passphrase" (código 5) (restore.sh:175-177).
- T-11: decrypt do .cvbk usa o `src/` do checkout, não o da imagem de release; registrar no runbook T-16 (restore.sh:181-189).
- T-11: uso do alvo `restore-drill` lista `file.sql.gz`, que o drill recusa (Makefile:37, restore-drill.sh:44-47).
- T-12: `OpsMonitorWiringTest` não cobre o `catch (StorageException` nem o storage que lança (OpsMonitorWiringTest.php:15-64).
- T-12: probe fica no storage se `get` lança após `put`; usar `finally` com delete tolerante (OperationalMonitor.php:557-563).
- T-12: sem teste unitário do ramo "conteúdo lido diferente do gravado" de storage_unavailable (OperationalMonitor.php:565).
- T-05: `build-release.sh` herda a umask (com 077 a app sobe unhealthy); fixar `umask 022`.
- T-16: `docs/runbooks/tests.md` e `build-versioning.md` descrevem o CI antigo; o índice precisa linkar `ensaio-restauracao-rollback.md`.
- O workflow do GitHub Actions (`.github/workflows/ci.yml`) nunca rodou no GitHub (sem push).
- T-14: `rm -rf "$workdir"` só recusa `/`, raiz, src, scripts e `..`; restringir a var/ci* (scripts/ci/run.sh:50-55,113).
- T-14: falha de `openssl rand` dentro de `$(secret N)` grava .env com segredo vazio sem `ci: failed step=env` (run.sh:96-98,117-127).
- T-14: CI_PROJECT só recusa `centralvet`; outro projeto local (ex. centralvet-drill) teria volumes destruídos pelo `down -v` (run.sh:34).
- T-15: bloco "saída" do rollback no runbook omite as linhas `rollback-drill restore after=... timings` (runbook:44-71).
- T-15: pre_backup herda BACKUP_OFFSITE_DIR/BACKUP_OFFSITE_S3_URI do ambiente; esvaziar as duas na chamada (rollback-drill.sh:186-187).
- T-15: GRANT do usuário de migration fixa `centralvet`.* em vez de "$MYSQL_DATABASE" (rollback-drill.sh:198 vs :218).
- T-15: senha do usuário de migration vai em `-e` do `docker compose exec`, visível em `ps` (rollback-drill.sh:217).
- T-16: cron.md e shared-hosting-mysql57.md descrevem códigos de saída de forma imprecisa: em hosting-backup.php falha de escrita/leitura sai 3 (não 2) e falha da trava só sai 1 no billing-event-retention (ops-monitor, billing-reconcile e hosting-backup saem 0 com `skipped_locked`).
- T-16: cron e restauração não foram executados em hospedagem compartilhada real.
- T-18: flag órfão (SIGKILL/queda do host) faz o cron-run pular todos os jobs com código 0, sem alerta; não confere `pid=` nem idade (scripts/cron-run.sh:50).
- T-18: projeto padrão diverge: cron-run cai em `centralvet`, restore.sh em `basename` da raiz (cron-run.sh:46, restore.sh:116).
- T-18: sem teste para falha de `compose ps` (código 2), falha de `compose stop` (código 6) e INT/TERM durante a carga; cron-run sem teste do projeto vindo do `.env`.
- T-18: citar `CENTRALVET_MAINTENANCE_DIR` em docs/runbooks/restore-backup.md § 4.
- T-19: transição local: `var/ci/local` antigo sem `.ci-workdir` é recusado até apagar `var/ci` uma vez à mão.
- T-19: runbook tests.md:32-34 não descreve a restrição do `CI_WORKDIR` nem a descoberta por find.
- T-19: guarda de symlink vale só para o último componente; diretório intermediário simbólico passa (run.sh:92-93).
- T-19: `for test_file in $script_tests` quebra com espaço no nome (run.sh:248).
- T-19: `CI_WORKDIR=var` foi rodado pelo implementador, não pelo validador na raiz real.
- T-20: contorno de umask redundante em docs/runbooks/release.md:12,32 e scripts/release/rollback-drill.sh:144; fixture do teste sem bit de execução nem vendor/ real.
- Onda 6: o exit code dos drills (restore-drill) não foi capturado no gate; mismatches=0 bastou.

## Riscos
- Ensaios e CI local (T-11, T-14, T-15) constroem imagens e sobem projetos Docker próprios: demoram e disputam CPU. Mitigação: projetos `centralvet-ci-local` e `centralvet-drill` com `down -v` no fim; o orquestrador pode serializar a Onda 4.
- `tar` dentro de `docker compose run --entrypoint tar app` depende da imagem (Debian bookworm, tem `tar`); a restauração dos volumes precisa de permissão de escrita para o usuário da imagem. T-11 confirma no ensaio real.
- A cópia externa S3 só é testada com `aws` falso; o destino real depende de decisão do usuário (bucket e credencial).
- A 0019 precisa estar aplicada antes do `ops-monitor.php`, do heartbeat do reconciliador e da retenção em qualquer ambiente; sem ela, o heartbeat do reconciliador só registra `heartbeat_failed` no `error_log` (não derruba a reconciliação) e o monitor sai 1.
- O backup lê o banco com `mysqldump --single-transaction` e os volumes logo depois: arquivos enviados entre os dois passos podem ficar fora do conjunto (RPO de minutos), registrado no runbook.
- Backup PHP na hospedagem (T-17) depende de `memory_limit`/`max_execution_time` do CLI do painel e de espaço em disco da conta: dump e empacotamento em streaming, lotes de 500 linhas e cifra em blocos de 1 MiB; o runbook (T-16) manda conferir o tamanho e o tempo da primeira execução.
- Preflight `php_version` exige 8.4: uma hospedagem compartilhada com outra versão vai reprovar até a paridade ser resolvida.

## Retomada
- Pasta: `.claude/tasks/mar-20261026-1006-etapa-4-operacao/`
- Sessões: b5eee13a-a154-4bef-8500-0b8809d420de
- Branch de trabalho: task/etapa-4-operacao (base: task/etapa-3-contratacao)
- BASE da onda 1: 992877a
- Commits por onda:
  - Onda 1: BASE 992877a → HEAD 5a7cc83 (66b71cd, 078436f, 11ae944, aea3174, e2c7664, 2646242, f512e7e, 445983e, 04e09a7, 360fd8e, 0c42ef8, c126fa8, f65190b, 50534eb, 2a4c63c, 5a7cc83)
  - Onda 2: BASE 0e8bd4f → HEAD 94ff476 (3dd059f, 523f277, 6ea7874, 343d933, 4956a7e, 1f4bfa2, 02b5d30, ce97252, 31343ab, af394ef, 0e7f03b, 94ff476)
  - Onda 3: BASE 797304e → HEAD 132bb73 (132bb73, 73289c1, c8913ca, 978a7e5, 1aacce2, fb58218)
  - Onda 4: BASE 1a3d9b0 → HEAD 97717be (4d0d7c1, 5a2cefd, 97717be)
  - Onda 5: BASE 617f43f → HEAD 8cf19e5 (69352f7, 8cf19e5)
  - Onda 6: BASE 36a7407 → HEAD a5e7494 (a5e7494, 0146f22, fe2d69d, 82047d5, 6615d56, e0cd822, d31808f)
- Último status conhecido: onda 6 concluída (T-18, T-19, T-20 [x]; SUITE 2390/2390, SHTESTs Failed: 0, LINT e BASHN 0 linhas; restore-drill real nos dois formatos com mismatches=0). Falta a nova revisão final.
- Próxima onda recomendada: nenhuma (revisão final)
