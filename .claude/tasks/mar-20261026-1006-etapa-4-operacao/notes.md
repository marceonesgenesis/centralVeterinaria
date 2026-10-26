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

## Bloqueios
- nenhum

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

## Pendências
- Banco dev `centralvet` com 3 migrations pendentes (0017, 0018, 0019); aplicar exige autorização do usuário.
- T-01: `.verify.sql` da 0019 — conferir a cobertura da checagem de ON UPDATE/timestamp sem default (sugestão da revisão; a correção 1 adicionou as duas consultas).
- T-02: o map de `X-Forwarded-Proto` aceita o header de qualquer cliente que alcance o nginx; condicionar a `$realip_remote_addr` com `geo`.
- T-02: `trusted-proxies.production.conf` (cópia do exemplo) fica dentro do repositório e não é ignorado pelo git.
- T-03: sem teste automatizado para os códigos de saída e a saída de `bin/migration-status.php`; o teste de "sem schema_migrations" monta PDO próprio em vez de reaproveitar o `MysqlIntegrationTestCase`.
- T-04: `app/resources/system/admin/reset_password.html` ficou sem uso; remover ou atribuir a uma task.
- T-05: o build real da imagem e a fumaça com a pilha no ar ficam para a T-15; `php_target=8.4` do manifesto é declarado, não verificado contra `config.platform.php` do lock.
- T-06: `--id`, `--timestamp` e `customer.subscription.deleted` sem teste em `SmokeWebhookEventTest`; uma FK nova para `tenant` exige um DELETE novo em `cleanup-zz-e3.sql`.

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
- Último status conhecido: onda 1 concluída (T-01 a T-06 [x]; gate SUITE 2327/2327; fix loop em T-01, T-05 e T-06).
- Próxima onda recomendada: 2 (T-07, T-08, T-09, T-10, T-17; T-12 e T-13 dependem da T-10)
