# Comunicação e Central de Pendências (Fase 7A)

A comunicação cobre o consentimento por canal, os templates, o e-mail
assíncrono (SMTP ou driver `log`), o WhatsApp por link `wa.me` com envio
manual, o histórico com status, os lembretes automáticos idempotentes e a
Central de Pendências, que lê as pendências das fontes existentes com
deep-link. O MVP não integra a API oficial do WhatsApp (a interface
`MessageChannelProviderInterface` deixa o ponto de extensão), não gera
documentos/PDF e não tem workflows configuráveis (Fase 7B).

> Nenhum passo com efeito real (DDL/DML) roda sem autorização SQL específica
> do usuário (`sql-write-approval`). Ver [`migrations.md`](./migrations.md).
> Este runbook não contém credencial: os valores sensíveis abaixo são
> sempre vazios ou placeholders.

## Visão geral

1. **Consentimento** (`TutorCommunicationForm`): uma preferência por tutor e
   canal (`email`, `whatsapp`), `opted_in` ou `opted_out`, com origem
   (`in_person`, `phone`, `written`, `online`), autor e data.
2. **Templates** (`MessageTemplateList`, `MessageTemplateForm`): por tenant,
   texto puro (sem HTML), placeholders fechados
   (`MessageTemplateRenderer::PLACEHOLDERS`), um ativo por finalidade e canal.
   Sem template ativo, a automação usa `MessageTemplateDefaults` (pt-BR).
3. **Mensagem manual** (`CommunicationComposeForm`): o atendente escolhe
   canal, finalidade e template, revisa o texto e registra. A regra de
   consentimento é a mesma da automação.
4. **Canais**: e-mail entra na fila e sai pelo worker; WhatsApp fica
   `queued` até o atendente abrir o link `wa.me` e marcar o envio manual.
5. **Fila e worker**: o controller publica o job na `RedisQueue` só depois
   do commit; o payload é só `type` + `message_id`, sem dado pessoal.
6. **Agendador**: gera os lembretes automáticos (ver abaixo).
7. **Histórico** (`CommunicationMessageList`, `CommunicationMessageView`):
   lista por unidade com filtros e a ficha da mensagem.
8. **Central de Pendências** (`PendingCenter`): lista unificada de
   pendências, sem tabela própria.

Telas (7 programas): `MessageTemplateList`, `MessageTemplateForm`,
`CommunicationMessageList`, `CommunicationMessageView`,
`CommunicationComposeForm`, `TutorCommunicationForm` e `PendingCenter`.
Concedidas aos grupos 1 (`Template - Admin`), 2 (`Template - Users`), 4
(`Clínico – Internação`) e 5 (`Clínico – Cirurgia`), 28 concessões; o grupo 3
(`Application - Programs`) não recebe. A DML é `T-04-programs.sql` da task,
aplicada só com autorização SQL.

## Variáveis de ambiente

Definidas em `.env.example` e `docker-compose.yml` (serviços `app` e
`worker`). Valores reais só no `.env` local do ambiente, nunca no
repositório.

| Variável | Padrão | Efeito |
|---|---|---|
| `COMMUNICATION_EMAIL_DRIVER` | `log` | `log` registra só referência, hash do destinatário e tamanhos, sem enviar; `smtp` envia de verdade. |
| `SMTP_HOST` | vazio | Servidor SMTP (obrigatório com `smtp`). |
| `SMTP_PORT` | `587` | Porta do servidor. |
| `SMTP_USERNAME` | vazio | Usuário; vazio desliga a autenticação. |
| `SMTP_PASSWORD` | vazio | Senha; só no `.env` do ambiente. |
| `SMTP_ENCRYPTION` | `tls` | `tls`, `ssl` ou `none`. Evite `none` com usuário: a credencial iria em claro. |
| `SMTP_FROM_ADDRESS` | `no-reply@example.invalid` | Remetente; troque por endereço válido do domínio. |
| `SMTP_FROM_NAME` | `Central Vet` | Nome do remetente. |
| `SMTP_TIMEOUT_SECONDS` | `10` | Tempo limite de conexão e envio. |
| `COMMUNICATION_SCHEDULER_INTERVAL_SECONDS` | `3600` | Intervalo do tick do agendador no worker; `0` desliga o tick. |
| `COMMUNICATION_RECEIVABLE_REMINDER_DAYS` | `7` | Dias desde a criação do recebível para o lembrete de cobrança. |
| `COMMUNICATION_SYSTEM_USER_ID` | `1` | Usuário de sistema (id positivo) usado pelo worker no `TenantContext`. |

### Configurar SMTP sem credencial no repositório

1. No `.env` do ambiente (arquivo fora do controle de versão), preencha
   `COMMUNICATION_EMAIL_DRIVER=smtp`, `SMTP_HOST`, `SMTP_PORT`,
   `SMTP_USERNAME`, `SMTP_PASSWORD` (com o segredo do provedor) e
   `SMTP_FROM_ADDRESS`. Não copie esses valores para `.env.example`, para o
   `docker-compose.yml` nem para relatórios.
2. Recrie o `worker` (e o `app`) para ler o `.env`: `docker compose up -d
   --force-recreate worker`.
3. Com o driver `log`, nada sai; é o padrão local e dos gates. O envio SMTP
   real só se confere com as credenciais do operador.

Falhas de entrega gravam apenas um código em `last_error_code`:
`smtp_connect`, `smtp_auth`, `smtp_recipient_rejected`, `smtp_error` ou
`provider_error` (qualquer outra falha). Nunca a mensagem da exceção.

## Status da mensagem e transições

| Status | Significado |
|---|---|
| `queued` | Registrada; e-mail aguarda o worker, WhatsApp aguarda o atendente. |
| `sent` | E-mail entregue ao provedor (`sent_at`). |
| `manual` | WhatsApp enviado pelo atendente (`manual_sent_by_system_user_id`). |
| `failed` | E-mail esgotou as tentativas (`failed_at`, `last_error_code`). |
| `cancelled` | Descartada pelo atendente ou cancelada pelo worker por opt-out (`cancelled_at`). |

Transições: `queued → sent` (e-mail), `queued → manual` (só WhatsApp),
`queued → failed` (última tentativa), `queued → cancelled`. Sent, manual,
failed e cancelled são finais. Cada transição é um `UPDATE ... WHERE status =
<esperado>` conferido por `rowCount`: perder a corrida devolve `skipped`, sem
erro. Os CHECKs da 0012 impedem combinações incoerentes (por exemplo `manual`
fora do WhatsApp).

## Dedupe e idempotência

- `communication_message.dedupe_key` é UNIQUE por tenant, no formato
  `<purpose>:<source_type>:<source_id>:<channel>`. O INSERT trata o erro 1062
  (nunca `INSERT IGNORE`, que no MySQL 8 engole violação de CHECK). Rodar o
  agendador duas vezes, ou o comando junto com o tick do worker, não duplica.
- Mensagem manual não leva `dedupe_key`.
- Envio: o worker reivindica a mensagem por `claimed_at` (claim condicional);
  uma mensagem `queued` com claim de mais de 10 minutos volta a ser elegível.

## Retentativa e dead-letter

O job de e-mail usa a `RedisQueue`: 5 tentativas com backoff exponencial
(`QUEUE_BACKOFF_SECONDS`, padrão 5) e dead-letter depois da última. Na última
tentativa a mensagem vai para `failed` com `last_error_code`. O agendador
também varre e-mails `queued` presos há mais de 10 minutos e republica o job.

## Agendador

Gera, por tenant ativo, os lembretes automáticos e publica os e-mails criados
mais os presos em `queued`. A janela D-1 usa `tenant.timezone`. As etapas
(services, geração, varredura, publicação) são isoladas por tenant: falha num
tenant é registrada (`communication.scheduler.tenant_failed`, só `tenant_id`,
`stage` e classe) e o seguinte continua.

- Tick no worker: na partida e a cada
  `COMMUNICATION_SCHEDULER_INTERVAL_SECONDS`. **Desligar**: `0`.
- Comando (gate e cron):

```bash
docker compose exec -T worker php bin/communication-scheduler.php
```

Imprime o JSON de contadores (inclui `skipped_opted_out`); sai com 0 em
sucesso e 1 se algum tenant falhou ou a conexão caiu (só a classe da
exceção).

Programas de lembrete:

| Finalidade | Gatilho | Base legal |
|---|---|---|
| `appointment_confirmation` | agendamento no dia seguinte (D-1) | legítimo interesse |
| `return_reminder` | retorno agendado (ligado por `appointment_followup` ou `surgery.followup_appointment_id`) | legítimo interesse |
| `vaccine_due` | próxima dose (`next_dose_at`) próxima | consentimento |
| `receivable_open` | recebível em aberto há `COMMUNICATION_RECEIVABLE_REMINDER_DAYS` dias; um lembrete por recebível e canal | consentimento |

Finalidades manuais: `document_ready` e `custom` (consentimento). O texto
manual de confirmação ou de retorno usa legítimo interesse.

## LGPD

- **Base legal por finalidade**: confirmação de agendamento e retorno saem
  por legítimo interesse, salvo opt-out do tutor no canal; vacina, cobrança e
  mensagens manuais (`custom`, `document_ready`) exigem opt-in explícito por
  canal. O opt-out é sempre respeitado.
- **Regra única**: `CommunicationPreference::permitsSending`, aplicada na
  composição manual, na geração automática e no worker. O worker cancela a
  mensagem com `opted_out` ou `consent_missing` se a preferência mudou depois
  do enfileiramento.
- **Registro**: a base legal usada fica em `communication_message.legal_basis`
  (`legitimate_interest` ou `consent`); a origem do consentimento, o autor e a
  data ficam em `communication_preference`. A alteração é auditada pelo
  `RbacAuthorizationService` com metadata sem contato.
- **Onde ficam os dados pessoais**: e-mail ou telefone em
  `communication_message.recipient` e o texto em `body_text` (histórico da
  clínica, já dado pessoal do cadastro). Retenção e expurgo desses campos não
  estão definidos no MVP; defina a política antes de produção.
- **O que nunca vai para log, `error_log`, URL, payload de fila, mensagem de
  exceção ou relatório**: e-mail, telefone, corpo e assunto. Os logs trazem
  só ids e códigos; o driver `log` registra hash do destinatário. Exceção: o
  link `wa.me` leva telefone e texto na URL por natureza; ele é aberto pelo
  navegador do atendente, nunca gravado nem logado, e abre com
  `rel="noopener noreferrer"`.

## Central de Pendências

`PendingCenter` não tem tabela própria: `PendingItemQuery` lê 8 fontes por
consulta, limitadas por tipo, restritas ao tenant e à unidade ativa.
Responsável e status vêm da fonte e o item some quando a tela de destino
resolve a fonte. Uma tabela de sobreposição (atribuir, adiar) exigiria
manutenção própria e ficou fora do MVP; ela entra depois sem refazer a
consulta.

| Tipo | Fonte | Vencimento (`dueAt`) |
|---|---|---|
| `exam_result` | exame solicitado sem resultado | solicitação + 72 h |
| `exam_review` | resultado recebido sem revisão | recebimento + 24 h |
| `return_appointment` | retorno agendado ou confirmado (de 7 dias atrás a 2 dias à frente) | `scheduled_at` |
| `vaccine_due` | próxima dose até hoje + 7 dias sem aplicação posterior | `next_dose_at` 00:00 |
| `hospitalization_administration` | administração pendente com mais de 30 min | agendada + 30 min |
| `message_failed` | mensagem `failed` | `failed_at` |
| `message_whatsapp_manual` | WhatsApp `queued` | criação + 4 h |
| `receivable_open` | recebível em aberto ou parcialmente pago | criação + 7 dias |

Prioridade (`PendingItemPriority::classify`): `urgent` para administração de
internação; `high` se vencido; `normal` se vence em até 24 h; `low` depois.
Status do item: `overdue` quando `agora > dueAt`, senão `open`. O deep-link
só usa classes e chaves da allowlist `PendingItem::DEEP_LINK_KEYS`, com
valores tipados, sem texto livre nem dado pessoal na URL.

## Banco (migration 0012)

`20261006_0012_phase7a_communication.sql` (com `.verify.sql`) cria 4 tabelas:
`communication_preference`, `message_template`, `communication_message` e
`appointment_followup`; 18 CHECKs, 4 UNIQUEs
(`communication_preference_tutor_channel_uq`,
`message_template_tenant_name_uq`, `communication_message_tenant_dedupe_uq`,
`appointment_followup_appointment_uq`), 18 chaves estrangeiras e 2 índices em
tabelas existentes (`vaccination_tenant_next_dose_idx`,
`receivable_tenant_status_idx`). Nenhum dado é semeado. Rollback: restaurar o
backup anterior ou migration reversa numerada
([`migration-rollback.md`](./migration-rollback.md)); não apague as tabelas à
mão depois de mensagens enviadas, porque `communication_message` é o
histórico. Hospedagem MySQL 5.7: ver
[`shared-hosting-mysql57.md`](./shared-hosting-mysql57.md).

## Limites conhecidos

- Retornos agendados antes da 7A não são reconhecidos (sem linha em
  `appointment_followup`).
- Recebível não tem vencimento nem unidade: o lembrete usa `created_at` e a
  unidade vem de `encounter_account.system_unit_id`.
- WhatsApp automático não é enviado: gera mensagem `queued` que aparece na
  Central como aguardando envio; com legítimo interesse o volume pode pesar
  na operação.
- Varredura de presos compara `claimed_at` com o relógio do MySQL: mantenha
  o fuso da sessão coerente com o do PHP.
- Um template ativo por finalidade e canal é regra da aplicação, sem UNIQUE
  no banco.
- Tick síncrono no worker: muitos tenants ou candidatos podem atrasar o
  heartbeat do worker.
