# Revisão final
Branch task/etapa-4-operacao (36a7407) contra task/etapa-3-contratacao (992877a). Critério da Etapa 4 (audit 2026-10-09 § Ordem recomendada): restauração comprovada; pipeline reproduzível; falhas recuperáveis e visíveis.

## Bloqueantes
- [bloqueante] Restauração real com o projeto no ar: restore.sh faz DROP/CREATE do banco e apaga e reextrai files, documents e o volume do MinIO sem parar app, worker, minio nem os crons, e o runbook oficial não manda parar. Worker, billing-reconcile e ops-monitor podem escrever durante o DROP e a carga, e o MinIO roda sobre o diretório que está sendo apagado. Só o rollback-drill para os serviços antes (rollback-drill.sh:214); o restore-drill sobe só mysql e redis, então o ensaio não exercita esse caminho → scripts/restore.sh:217-245; docs/runbooks/restore-backup.md:68-86

## Pendências promovidas
- [promovida] T-11: restore com o projeto vivo. A pendência pedia "parar antes ou documentar no runbook T-16". A T-16 publicou o procedimento oficial (§ 4) sem esse passo, e restore.sh segue sem parar nada. O conjunto da branch transformou a lacuna no procedimento de produção → scripts/restore.sh:217-245; docs/runbooks/restore-backup.md:68-86

## Triagem
- [aberta] Banco dev `centralvet` com 0017–0019 pendentes → item de Excluído; exige autorização do usuário
- [resolvida] T-01: cobertura do .verify.sql da 0019 → 20261026_0019_ops_monitoring.verify.sql:33-46 (unexpected_on_update_columns e not_null_timestamps_without_default)
- [aberta] T-02: o map de X-Forwarded-Proto aceita o header de qualquer cliente → docker/nginx/default.conf:13-16 (sem geo por $realip_remote_addr); mitigado por porta em 127.0.0.1 e proxy que sobrescreve
- [aberta] T-02: trusted-proxies.production.conf não é ignorado → `git check-ignore docker/nginx/trusted-proxies.production.conf` sai 1; .gitignore:14-16
- [aberta] T-03: sem teste dos códigos de saída de bin/migration-status.php → nenhum teste em src/tests cita o CLI
- [aberta] T-04: app/resources/system/admin/reset_password.html sem uso → o arquivo existe e nada o referencia
- [aberta] T-05: build real e fumaça feitos pela T-15 (ensaio smoke_failures=0); php_target=8.4 segue declarado e não conferido → scripts/release/build-release.sh:102
- [aberta] T-06: --id, --timestamp e customer.subscription.deleted sem teste → src/tests/Unit/SmokeWebhookEventTest.php
- [aberta] T-08: teste não hermético, projeto padrão por basename, tar com saída 1 aborta, contagens fora do snapshot → scripts/backup.test.sh (sem unset de BACKUP_*); scripts/backup.sh:93,173-174,154-165
- [aberta] T-09: cobertura parcial do preflight; APP_DEBUG=1 passa; trusted_proxies só reprova faixas literais → src/app/Core/Operations/ProductionPreflight.php:24,69,113-116,147-151
- [aberta] T-10: alert_key varchar(64) sem limite no notifier → as chaves atuais são constantes curtas; risco só para chaves novas (OperatorAlertNotifier.php:136-162)
- [aberta] T-17: parts=3 contra 4, sql_mode fixo nos triggers, decrypt em duas passadas, CLI sem teste de heartbeat e dos códigos 2/5 → src/app/Core/Operations/HostingBackup.php:153,168
- [resolvida] T-17: --user 1000:33 no runbook → docs/runbooks/cron.md:122-130; docs/runbooks/shared-hosting-mysql57.md:714-722
- [aberta] T-11: sem caso de parte trocada no .cvbk → scripts/restore.test.sh:229 só cobre FAKE_DECRYPT_FAIL; o HMAC é coberto por BackupCipherTest.php:66 e o sha256 das partes pelo caminho comum
- [promovida] T-11: restore com o projeto vivo → ver Bloqueantes
- [aberta] T-11: `trap 'exit 6'`/`'exit 4'` valem antes de qualquer escrita → scripts/restore.sh:152; scripts/restore-drill.sh:97
- [aberta] T-11: stderr de gpg/tar silenciado; disco cheio vira código 5 → scripts/restore.sh:160-162
- [aberta] T-11: decrypt do .cvbk usa o src/ do checkout; o runbook não registra → scripts/restore.sh:165-168; docs/runbooks/restore-backup.md:64-66
- [aberta] T-11: o uso do alvo restore-drill lista file.sql.gz, que o drill recusa → Makefile:37; scripts/restore-drill.sh:44-47
- [aberta] T-12: OpsMonitorWiringTest não cobre o catch de StorageException → src/tests/Unit/OpsMonitorWiringTest.php:17-64
- [aberta] T-12: o probe fica no storage se get lança depois do put → src/app/Core/Operations/OperationalMonitor.php:101-107
- [aberta] T-12: sem teste do ramo "conteúdo lido diferente" → OperationalMonitor.php:109; OperationalMonitorTest.php sem esse caso
- [aberta] T-05: build-release.sh não fixa umask 022 → scripts/release/build-release.sh:15-60; mitigado em docs/runbooks/release.md:12,32 e em rollback-drill.sh:144
- [resolvida] T-16: tests.md e build-versioning.md descreviam o CI antigo; o índice não linkava o ensaio → docs/runbooks/tests.md:22-49; build-versioning.md:3-26; docs/runbooks/README.md:13
- [aberta] O workflow .github/workflows/ci.yml nunca rodou no GitHub → sem push; só `ci: passed` local
- [aberta] T-14: rm -rf do workdir só recusa a raiz, src, scripts e `..`; CI_WORKDIR=var, docs ou .git é apagado, e var/ guarda os backups padrão (var/backups) → scripts/ci/run.sh:50-55,112
- [aberta] T-14: falha de openssl rand dentro de $(secret N) grava segredo vazio → scripts/ci/run.sh:97-98,119-126
- [aberta] T-14: CI_PROJECT só recusa `centralvet` → scripts/ci/run.sh:34
- [aberta] T-15: o bloco "saída" do runbook omite as linhas de timings do restore → docs/runbooks/ensaio-restauracao-rollback.md:44-71 (não reconferido; nenhum commit posterior conhecido)
- [aberta] T-15: pre_backup herda BACKUP_OFFSITE_* do ambiente → scripts/release/rollback-drill.sh:178-179
- [aberta] T-15: GRANT fixa `centralvet`.* → scripts/release/rollback-drill.sh:188 (funciona porque o env do drill usa MYSQL_DATABASE=centralvet)
- [aberta] T-15: senha do usuário de migration em `-e` do exec → scripts/release/rollback-drill.sh:207
- [aberta] T-16: códigos de saída imprecisos; cron.md e shared-hosting dizem "sai com código 2" na falha de permissão, mas hosting-backup.php sai 3 em exceção de escrita/leitura → docs/runbooks/cron.md:126; shared-hosting-mysql57.md:718; src/bin/hosting-backup.php:126-129
- [aberta] T-16: cron e restauração nunca executados em hospedagem compartilhada real → nenhum ambiente disponível

## Rulings
- plano · — — Branch base task/etapa-3-contratacao (992877a); produção main
- plano · — — Entrega preserva os commits por onda (sem entrega.py)
- T-01/T-10 · onda 1 — Heartbeat e deduplicação em tabelas (0019); a 0019 só no centralvet_test
- T-04 · onda 1 — Reset de senha pelo EmailProviderFactory (SMTP_FROM_*); SMTP validado por FakeSmtpServer
- T-07 · onda 2 — Cookie Secure em SessionHandlerFactory::createFromEnvironment(); X-Forwarded-Proto só com TRUST_FORWARDED_PROTO=true
- T-02 · onda 1 — set_real_ip_from em arquivo escolhido por NGINX_TRUSTED_PROXIES_FILE
- T-08 · onda 2 — Backup .tar.gpg AES256 com MANIFEST; retenção 14 d; offsite por diretório e S3; --no-heartbeat no dev
- T-12 · onda 3 — Alertas só por e-mail ao BILLING_OPERATOR_EMAIL, deduplicação de 6 h, código 2; limiares definidos
- T-13 · onda 3 — Retenção LGPD: payload '' após 30 d para processed/ignored; a linha fica
- T-14 · onda 4 — CI em scripts/ci/run.sh com projeto e env-file próprios
- T-05/T-15 · ondas 1 e 4 — Release por git archive (pacote + imagem por SHA40); rollback ensaiado em centralvet-drill
- plano · revisão 1 — Respostas do usuário: offsite dir/S3, alerta por e-mail, duas hospedagens, GitHub Actions
- plano · revisão 1 — Dois alvos: migration-status.php, preflight --with-db, 0019 no prepare-mysql57.py
- T-17 · onda 2 — Backup da hospedagem em PHP (CVBK1); restauração automatizada pela pilha Docker; S3 da hospedagem fora
- T-16 · onda 5 — Cron documentado como crontab e como agendador do painel
- T-04 · onda 1 — RED tocou PasswordResetFormsWiringTest.php (em Arquivos prováveis); aceito
- T-01 · onda 1 — plano-mandou: DEFAULT CURRENT_TIMESTAMP(6) sem ON UPDATE (2a4c63c)
- T-06 · onda 1 — Escopo extra: filtro por path no teste da Etapa 3 e limpeza de 62 tabelas (5a7cc83)
- plano · onda 1 — MySQL e Redis sobem com docker compose up -d quando preciso
- T-17 · onda 2 — --user 1000:33 é adaptação de ambiente; a T-16 registra
- T-17 · onda 2 — Correção da asserção RED da 0019 legítima
- T-10 · onda 2 — Hash 31343ab citado por engano; a implementação é ce97252
- T-12 · onda 3 — StorageException na montagem vira storage_unavailable (código 2)
- T-11 · onda 3 — Restore recria o banco e substitui os volumes só no projeto alvo (RESTORE_<PROJETO>)
- T-11 · onda 3 — Saídas aditivas: restore: timings e RESTORE_MANIFEST_COPY
- T-11 · onda 3 — --decrypt do .cvbk monta src/ :ro
- T-14 · onda 4 — Suíte sem bind de src/ (dump-autoload --dev em volume anônimo); schema aplicado também ao centralvet efêmero
- T-15 · onda 4 — sem teste:, sem RED
- plano · onda 4 — Gate retomado após o limite da API, sem resíduo
- T-16 · onda 5 — Escopo extra: gzip -t trocado em migrations, cirurgia, internacao e landing-leads (registrado em § Bloqueios)
- T-16 · onda 5 — restore-drill.sh e hosting-backup --decrypt no lugar do inexistente restore.sh --decrypt (registrado em § Bloqueios)
- T-16 · onda 5 — sem teste: (docs), sem RED (registrado em § Bloqueios)

## Achados
- [bloqueante] Restore sem parar os serviços do projeto alvo → scripts/restore.sh:217-245; docs/runbooks/restore-backup.md:68-86
- [sugestão] Falha visível depende do banco: com o MySQL fora, ops-monitor sai 1 sem e-mail e a visibilidade fica no código do cron (MAILTO ou log). Documentar um monitor externo do /health ou do heartbeat ops-monitor → src/bin/ops-monitor.php:230-233
- [sugestão] As três decisões da T-16 estão em notes.md § Bloqueios, abaixo de "nenhum", e não em § Decisões tomadas → notes.md § Bloqueios
- [sugestão] restore.sh por padrão usa o projeto do .env/basename. A confirmação RESTORE_<PROJETO> protege, mas o restore-drill e o rollback-drill fixam centralvet-drill, e o restore real não exige --project explícito → scripts/restore.sh:103,119-124

## Verificado
- Restore só no projeto alvo: confirmação RESTORE_<PROJETO>, MANIFEST e sha256 conferidos antes de qualquer escrita, código 5 sem escrita, arquivo fora de BACKUP_DIR recusado → scripts/restore.sh:111-124,183-205; restore.test.sh:229-236
- Drills isolados em centralvet-drill com down -v e remoção de volumes centralvet-drill_* → restore-drill.sh:27,75-87; rollback-drill.sh:83-97
- Segredos fora do repositório: BACKUP_PASSPHRASE_FILE vazio no .env.example; frase lida de arquivo; .env de CI e de drill gerados com openssl rand e umask 077; var/drill e var/ci ignorados → .env.example:184-197; .gitignore:14-16
- Duas hospedagens: backup.sh (Docker) e hosting-backup.php (PDO, CVBK1, mesmo MANIFEST v1); heartbeats e monitor por PDO; runbook da compartilhada com crons e --decrypt
- Alertas e dedup: 6 h por chave, markSent só após a entrega, sem destinatário só error_log; testes OperatorAlertNotifierTest:21-92. Fuso do heartbeat consistente (MySQL --default-time-zone=-03:00 e PHP APP_TIMEZONE)
- Retenção LGPD: UPDATE só em processed/ignored; o teste assere a contagem e o latestEventCreated() → BillingEventPayloadRetention.php:69-72; teste :48-59
- CI: ci.yml só chama scripts/ci/run.sh; o projeto centralvet é recusado
- Cookie Secure: RequestScheme e SessionHandlerFactory; X-Forwarded-Proto só com TRUST_FORWARDED_PROTO=true

## Não verificado
- Não reexecutei a suíte, o ci/run.sh, o restore-drill nem o rollback-drill: `ci: passed`, mismatches=0 e smoke_failures=0 vêm de notes.md e dos relatórios, não de execução nesta revisão
- Critérios gerais: grep de chaves sk_live/sk_test no diff, sha256sum .env e COUNT de schema_migrations no início e no fim
- Conteúdo de docs/runbooks/observabilidade.md e de ensaio-restauracao-rollback.md:44-71
- Restauração de um dump real gerado em MySQL 5.7 (só dumps do dev em 8.0 foram ensaiados)
- Execução do workflow no GitHub Actions
