# Retenção de dados pessoais (LGPD)

O que expira sozinho, quando, por qual rotina e o que fica. As rotinas são
agendadas conforme [`cron.md`](../runbooks/cron.md).

## Payload dos eventos de cobrança: 30 dias

`billing_event.payload` guarda o JSON recebido do Stripe, que contém dado
pessoal (e-mail e nome do responsável e identificadores do cliente no Stripe).

- **Rotina**: `php bin/billing-event-retention.php`, diária (03:15 no exemplo do
  cron). Esvazia o `payload` (grava `''`) dos eventos `processed` e `ignored`
  com `received_at` há mais de 30 dias
  (`BillingEventPayloadRetention::RETENTION_DAYS`), em lotes.
- **Saída**: `{"purged":<n>,"batches":<n>}` e heartbeat `billing-event-retention`
  (`purged=<n> batches=<n>`). Trava ocupada: `{"skipped":"locked"}`, código 0.
  Erro: só a classe da exceção no `error_log`, código 1.
- **O que fica**: a linha do evento (`provider`, `event_id`, tipo, status,
  `received_at`, `event_created`), sem o payload. O `event_id` único deduplica
  reentregas e o maior `event_created` é o cursor do reconciliador: apagar a
  linha quebraria os dois.
- **O que não é esvaziado**: eventos `failed` e `received` (ainda precisam de
  análise). O alerta `billing_event_failed` cobra a ação do operador
  ([`observabilidade.md`](../runbooks/observabilidade.md)); depois de tratado e
  marcado `processed`/`ignored`, a retenção alcança a linha.

## Conversas do copiloto: 90 dias

`bin/ai-conversation-retention.php`, diária (03:30): apaga as conversas sem
atividade há mais de 90 dias (as mensagens vão em cascata). A auditoria
(`mcp_tool_call`, `ai_interaction`) fica. Ver
[`ia-assistente.md`](../runbooks/ia-assistente.md).

## O que não expira por rotina

- `billing_order` (pedido, responsável, aceite dos termos), `subscription` e
  `subscription_invoice`: base contratual e fiscal; ficam enquanto a conta
  existir. Pedido, e-mail do responsável e aceite são dado pessoal: atenda
  pedidos de titular caso a caso, com autorização e registro.
- Dados clínicos e cadastrais da clínica (tutores, pacientes, prontuário): o
  tenant é o controlador; a retenção segue a política da clínica.
- Mensagens de comunicação e consentimento: ver
  [`comunicacao.md`](../runbooks/comunicacao.md).
- Logs e `error_log`: trazem só ids, códigos e contadores, sem dado pessoal;
  aplique a rotação do servidor.

## Backups

Os backups contêm todo o banco e os arquivos, inclusive os payloads ainda
dentro da janela de 30 dias e dados que a retenção já removeu **depois** do
backup: o backup é cifrado e expira por `BACKUP_RETENTION_DAYS` (padrão 14),
então um dado esvaziado some dos backups em até esse prazo, mais o tempo que a
cópia externa for mantida. Defina a retenção da cópia externa igual ou menor que
o prazo que a política exige, e não restaure um backup antigo sem rodar a
retenção de novo logo em seguida (ver
[`restore-backup.md`](../runbooks/restore-backup.md)).

## Conferir

```sql
-- eventos com payload acima de 30 dias que a retenção deveria ter esvaziado
SELECT status, COUNT(*) FROM billing_event
WHERE received_at < NOW() - INTERVAL 30 DAY AND payload <> ''
GROUP BY status;
```

Só `failed` e `received` devem aparecer; se `processed` ou `ignored` aparecerem,
confira o heartbeat `billing-event-retention` em `ops_heartbeat` e o log do cron.
