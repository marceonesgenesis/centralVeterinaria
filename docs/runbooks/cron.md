# Agendamento (cron)

Todas as rotinas periódicas do Central Vet Pro em um só lugar, para os dois
alvos de implantação: o **host Docker/VPS** (crontab do host) e a **hospedagem
compartilhada com MySQL 5.7** (agendador do painel). Os scripts são os mesmos;
muda só o ponto de partida do comando.

| Rotina | Script | Frequência | Falha visível por |
|---|---|---|---|
| Reconciliação da cobrança | `bin/billing-reconcile.php` | a cada 15 min | alerta `billing_reconcile_stale` |
| Monitor operacional | `bin/ops-monitor.php` | a cada 15 min | código 2 com alertas; e-mail ao operador |
| Retenção do payload de cobrança (LGPD) | `bin/billing-event-retention.php` | diária, 03:15 | código 1 no log do cron |
| Retenção das conversas do copiloto | `bin/ai-conversation-retention.php` | diária, 03:30 | código 1 no log do cron |
| Varredor de documentos presos | `bin/document-sweep.php` | a cada 5 min | alerta `document_queue_stalled` |
| Lembretes de comunicação | `bin/communication-scheduler.php` | de hora em hora | alerta `message_queue_stalled` |
| Fila de e-mails e documentos | `bin/worker.php --once` | a cada minuto (só sem o container `worker`) | alertas `message_*` e `document_*` |
| Backup diário (Docker/VPS) | `scripts/backup.sh` | diária, 02:00 | alerta `backup_stale` (26 h) |
| Backup diário (hospedagem) | `bin/hosting-backup.php` | diária, 02:00 | alerta `backup_stale` (26 h) |

Regras comuns:

- Os scripts de `bin/` são só CLI (respondem 404 por HTTP) e ficam a partir da
  raiz de `src/`. Os que gravam heartbeat (`billing-reconcile`,
  `billing-event-retention`, `ops-monitor`, `backup`) usam a tabela
  `ops_heartbeat` da migration 0019 (ver
  [`shared-hosting-mysql57.md`](./shared-hosting-mysql57.md)).
- Execuções sobrepostas não acumulam: cada script trava com `flock` no
  diretório temporário e a segunda execução sai 0 (ou, sem o arquivo de trava,
  1 com "lock file unavailable").
- O ambiente do cron precisa das mesmas variáveis do app (`DB_*`, `STRIPE_*`,
  `SMTP_*`/`COMMUNICATION_*`, `DOCUMENT_*`, `BILLING_OPERATOR_EMAIL`,
  `APP_PUBLIC_URL`, `BACKUP_*`). Nunca coloque segredo na linha do crontab.
- Os horários seguem o fuso do servidor; os scripts de cobrança fixam
  `APP_TIMEZONE` internamente.
- Saídas trazem ids, códigos e contadores, sem dado pessoal. Redirecione para
  um arquivo de log com rotação.

Faltou rodar uma rotina? O `ops-monitor` avisa por e-mail (ver
[`observabilidade.md`](./observabilidade.md)); como ele próprio é um cron,
confira também o `last_success_at` de `ops-monitor` em `ops_heartbeat` quando
ficar dias sem alerta algum.

## Crontab do host Docker/VPS

Substitua `/srv/centralvet` pela raiz do projeto (onde fica o
`docker-compose.yml` e o `.env`). Os scripts PHP rodam dentro do container
`app` (`exec -T`, sem TTY); os de shell rodam no host, porque falam com o
Docker. O `backup.sh` lê `BACKUP_*` do `.env` (ver
[`restore-backup.md`](./restore-backup.md)).

```cron
# Reconciliação da cobrança (a cada 15 minutos)
*/15 * * * * cd /srv/centralvet && docker compose exec -T app php bin/billing-reconcile.php >> /var/log/centralvet/billing-reconcile.log 2>&1
# Monitor operacional e alertas ao operador (a cada 15 minutos; sai 2 quando há alerta)
*/15 * * * * cd /srv/centralvet && docker compose exec -T app php bin/ops-monitor.php >> /var/log/centralvet/ops-monitor.log 2>&1
# Retenção LGPD do payload dos eventos de cobrança (diária, 03:15)
15 3 * * * cd /srv/centralvet && docker compose exec -T app php bin/billing-event-retention.php >> /var/log/centralvet/billing-event-retention.log 2>&1
# Retenção de 90 dias das conversas do copiloto (diária, 03:30)
30 3 * * * cd /srv/centralvet && docker compose exec -T app php bin/ai-conversation-retention.php >> /var/log/centralvet/ai-conversation-retention.log 2>&1
# Varredor de documentos presos em queued (a cada 5 minutos)
*/5 * * * * cd /srv/centralvet && docker compose exec -T app php bin/document-sweep.php >> /var/log/centralvet/document-sweep.log 2>&1
# Backup diário cifrado, com retenção e cópia externa (02:00)
0 2 * * * cd /srv/centralvet && scripts/backup.sh >> /var/log/centralvet/backup.log 2>&1
```

O container `worker` já drena a fila e roda o agendador de comunicação e o
varredor no seu próprio ciclo; por isso o `worker.php --once` e o
`communication-scheduler.php` só entram no crontab do host se o container
`worker` estiver parado. O varredor de documentos acima é redundante com o do
worker e barato: mantenha-o como rede de segurança.

Se o `docker compose` do cron não achar o projeto, passe
`--project-directory /srv/centralvet` ou `COMPOSE_PROJECT_NAME`. `scripts/backup.sh`
sai 0 criado, 2 configuração, 3 falha de dump ou arquivo e 4 falha da cópia
externa (o arquivo local fica); só o 0 grava o heartbeat `backup`.

## Agendador do painel da hospedagem compartilhada

Sem Docker, `gpg` nem `mysqldump`: tudo roda por PHP CLI a partir da raiz de
`src/`. Cadastre cada linha no agendador do painel (o campo "comando" aceita o
`cd ... && php ...`; use o caminho do PHP 8.4 CLI que o painel indicar, em vez
de `php`, se o padrão for outro). Substitua `/caminho/da/aplicacao` e
`/caminho/dos/logs`.

```cron
# Reconciliação da cobrança (a cada 15 minutos)
*/15 * * * * cd /caminho/da/aplicacao/src && php bin/billing-reconcile.php >> /caminho/dos/logs/billing-reconcile.log 2>&1
# Monitor operacional e alertas ao operador (a cada 15 minutos; sai 2 quando há alerta)
*/15 * * * * cd /caminho/da/aplicacao/src && php bin/ops-monitor.php >> /caminho/dos/logs/ops-monitor.log 2>&1
# Retenção LGPD do payload dos eventos de cobrança (diária, 03:15)
15 3 * * * cd /caminho/da/aplicacao/src && php bin/billing-event-retention.php >> /caminho/dos/logs/billing-event-retention.log 2>&1
# Retenção de 90 dias das conversas do copiloto (diária, 03:30)
30 3 * * * cd /caminho/da/aplicacao/src && php bin/ai-conversation-retention.php >> /caminho/dos/logs/ai-conversation-retention.log 2>&1
# Varredor de documentos presos em queued (a cada 5 minutos)
*/5 * * * * cd /caminho/da/aplicacao/src && php bin/document-sweep.php >> /caminho/dos/logs/document-sweep.log 2>&1
# Lembretes automáticos de comunicação (de hora em hora)
0 * * * * cd /caminho/da/aplicacao/src && php bin/communication-scheduler.php >> /caminho/dos/logs/communication-scheduler.log 2>&1
# Envio dos e-mails e documentos da fila (a cada minuto; drena e encerra em até 50 s)
* * * * * cd /caminho/da/aplicacao/src && php bin/worker.php --once --max-seconds=50 --max-jobs=200 >> /caminho/dos/logs/worker.log 2>&1
# Backup diário cifrado da hospedagem (02:00)
0 2 * * * cd /caminho/da/aplicacao/src && php bin/hosting-backup.php >> /caminho/dos/logs/hosting-backup.log 2>&1
```

Notas da hospedagem:

- Se o painel só oferece frequência fixa (por exemplo, de hora em hora),
  ajuste as janelas: o limiar do `billing_reconcile_stale` é 45 min, então um
  reconciliador horário alerta o tempo todo. Peça ao provedor a frequência de
  15 minutos ou eleve o limiar em `OperationalMonitor::RECONCILE_MAX_AGE_SECONDS`
  (mudança de código, com teste).
- O `worker.php --once` só tem efeito com Redis acessível pela hospedagem; sem
  ele, deixe `COMMUNICATION_EMAIL_DRIVER=log` (ver a seção "Agendador e worker
  na hospedagem" de [`shared-hosting-mysql57.md`](./shared-hosting-mysql57.md)).
- `bin/hosting-backup.php` precisa de `BACKUP_PASSPHRASE_FILE` (arquivo fora
  do `public_html`, com permissão 0600) e de `DOCUMENT_STORAGE_LOCAL_ROOT`.
  A cópia externa é um diretório montado (`BACKUP_OFFSITE_DIR`); sem um,
  baixe os arquivos de `BACKUP_DIR` periodicamente por SFTP.
- Backup, restauração e `--decrypt` na hospedagem:
  [`shared-hosting-mysql57.md`](./shared-hosting-mysql57.md), seção "Backup e
  restauração na hospedagem".

## Conferir depois de agendar

1. Rode cada linha uma vez à mão e confirme o código 0 (o `ops-monitor` pode
   sair 2 se já houver alerta: leia a linha `ops-monitor alerts=<n> ...`).
2. Aguarde um ciclo e confira os heartbeats:

   ```sql
   SELECT job, last_success_at FROM ops_heartbeat ORDER BY job;
   ```

   Esperados: `backup`, `billing-event-retention`, `billing-reconcile` e
   `ops-monitor`.
3. Provoque um alerta de teste com um destinatário seu em
   `BILLING_OPERATOR_EMAIL` (ver [`observabilidade.md`](./observabilidade.md)).
