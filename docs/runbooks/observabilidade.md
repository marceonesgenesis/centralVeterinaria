# Observabilidade operacional e alertas

O `bin/ops-monitor.php` (cron a cada 15 minutos, ver [`cron.md`](./cron.md))
avalia 9 condições e avisa o operador por e-mail. Não há painel nem SaaS: o
canal é o e-mail, mais o `error_log`.

## Destinatário e envio

- `BILLING_OPERATOR_EMAIL`: endereço do operador, no ambiente do cron. Vazio:
  nada é enviado, o alerta só vai ao `error_log`, e o preflight acusa
  `operator_email` como FAIL em produção.
- O envio usa o mesmo provedor SMTP do convite e do reset de senha
  (`COMMUNICATION_EMAIL_DRIVER=smtp`, `SMTP_*`, remetente `SMTP_FROM_ADDRESS`).
  Com `COMMUNICATION_EMAIL_DRIVER=log` nenhum e-mail sai: a falha de entrega vai
  ao log como `{"event":"ops_alert_delivery_failed","key":...,"error":...}`.
- **Deduplicação de 6 h**: cada chave de alerta é enviada no máximo uma vez a
  cada 6 horas (tabela `ops_alert_state`). Enquanto a condição persiste, o
  e-mail se repete a cada 6 h.

## Saída e código de saída

```text
ops-monitor alerts=<n> notified=<n> keys=<chave,chave|->
```

Sai 0 sem alertas, 2 com alertas (o cron registra a falha) e 1 em exceção
(`ops-monitor error=<classe>`). `alerts` conta as condições ativas e `notified`
os e-mails enviados na rodada (as demais estavam dentro da janela de 6 h). Cada
rodada grava o heartbeat `ops-monitor`.

## As 9 chaves

| Chave | Dispara quando | O que fazer |
|---|---|---|
| `billing_reconcile_stale` | o reconciliador não tem sucesso registrado há mais de 45 min (ou nunca) | conferir o cron de `bin/billing-reconcile.php` e o log; ver "Reconciliação da cobrança" em [`shared-hosting-mysql57.md`](./shared-hosting-mysql57.md) |
| `backup_stale` | último backup bem-sucedido há mais de 26 h (ou nunca) | rodar o backup à mão, conferir `BACKUP_*`, a frase e o espaço em disco; ver [`restore-backup.md`](./restore-backup.md) |
| `billing_event_failed` | evento de cobrança `failed`, ou `received` há mais de 1 h | ler o log do webhook e do reconciliador; reprocessar pelo reconciliador; `failed` nunca é esvaziado pela retenção, a ação é sua |
| `billing_order_stuck` | pedido pago ou com falha de provisionamento há mais de 1 h | ver o passo no log (`[LoginConflictRefund]`, provisionamento) e a próxima rodada do reconciliador; avisar o cliente se preciso |
| `message_failed` | mensagens de comunicação com falha nas últimas 24 h | ver [`comunicacao.md`](./comunicacao.md); conferir `SMTP_*` |
| `message_queue_stalled` | mensagens na fila há mais de 30 min | worker parado ou Redis fora; ver `worker.log` |
| `document_failed` | documentos com falha nas últimas 24 h | ver [`documentos.md`](./documentos.md) |
| `document_queue_stalled` | documentos na fila há mais de 30 min | worker ou varredor parados |
| `storage_unavailable` | o probe grava, lê e apaga `ops-probe/heartbeat.txt` no storage de documentos e falha | pasta `DOCUMENT_STORAGE_LOCAL_ROOT` ausente ou sem permissão, ou bucket S3 inacessível; sem a raiz local o monitor não sai 1: vira esta chave |

O probe usa o tenant 1 e `DOCUMENT_SYSTEM_USER_ID`. Os limiares estão em
`CentralVet\Operations\OperationalMonitor` (reconciliador 45 min com cron de
15 min; backup 26 h com cron diário).

## Validar o canal de alerta

1. Em um ambiente que **não** seja produção, defina `BILLING_OPERATOR_EMAIL`
   com um endereço seu e `COMMUNICATION_EMAIL_DRIVER=smtp` com o SMTP de teste.
2. Rode `php bin/ops-monitor.php`: com `ops_heartbeat` vazio, saem
   `billing_reconcile_stale` e `backup_stale`, a linha termina em
   `alerts=2 notified=2` e o e-mail chega.
3. Rode de novo: `notified=0` (deduplicação de 6 h).

## Preflight de produção

`php bin/preflight.php [--trusted-proxies-file=<caminho>] [--with-db]` confere
a configuração sem imprimir valores. Ver [`release.md`](./release.md) e
[`environments.md`](./environments.md).

## Outros sinais

- `docker compose ps` e `make health`: saúde dos serviços.
- `GET /health` na fumaça do release (`scripts/release/smoke.sh`).
- Logs JSON da aplicação, do worker e dos scripts de cron (sem dado pessoal).
