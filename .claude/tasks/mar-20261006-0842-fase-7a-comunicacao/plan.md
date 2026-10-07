# Plano: Fase 7A — Comunicação e Central de Pendências (MVP)

## Objetivo
Entregar o MVP do PRD §8.20 e §8.23. Do lado da comunicação: preferências e consentimento por canal do tutor, templates de mensagem por finalidade, e-mail real por SMTP (ou sandbox `log`) enviado de forma assíncrona pela fila Redis e pelo worker, WhatsApp por link `wa.me` com envio manual registrado, histórico com status, e lembretes automáticos (confirmação D-1, vacina a vencer, retorno, cobrança) gerados por job agendado sem duplicar. Do lado das pendências: uma tela única que lê por consulta as fontes já existentes, com prioridade, prazo, responsável e deep-link para resolver.

## Premissas
- Repositório único `/var/www/html/centralvet`. O orquestrador cria a branch de trabalho `feat/fase-7a-comunicacao` a partir de `feat/fase-6b-cirurgia` @ `bf2178d` (`repos.py --preparar`). O checkout é compartilhado, com caminho exclusivo, RED antes da implementação e trailers `Task: T-xx` / `Task: T-xx (RED)`.
- Comandos rodados de `/var/www/html/centralvet` (mesmas convenções da 6A/6B):
  - **LINT** = `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php -l <arquivo relativo a src/>`;
  - **SUITE** = `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php`. Não filtra: use `| /usr/bin/grep -E '<Classe>|Failed:'`. Roda no `centralvet_test`; não interromper; SUITEs simultâneas podem dar falso FAIL em testes Redis ou deadlock de outros arquivos. Classe nova aparece sem rebuild (PSR-4 do host). No fim da 6B: 751 testes, `Failed: 0`;
  - **PYTEST57** = `python3 scripts/test-prepare-mysql57.py`;
  - **GATE de tela**: o orquestrador roda `docker compose build app worker && docker compose up -d app worker && docker compose restart nginx`, faz login como admin no Playwright (com autorização do usuário; a senha do `.env` nunca é registrada) e o validador navega só em `http://127.0.0.1:8081` (nunca `localhost`).
- O worker (`src/bin/worker.php`) hoje é um placeholder: um `$handle` que só registra log, sem registro de tipos de job e sem acesso a banco (carrega só `vendor/autoload.php`). A 7A cria o primeiro job de negócio (`communication.message.send`), a conexão PDO por variáveis de ambiente (`PdoConnectionFactory`) e o agendador de lembretes. A imagem é `read_only` com `--classmap-authoritative`: worker e app só enxergam classes novas depois do rebuild do orquestrador.
- Não existe cron no `docker-compose.yml`. O job de lembretes roda de dois jeitos, com o mesmo código (`CommunicationScheduler::runOnce`): pelo próprio worker a cada `COMMUNICATION_SCHEDULER_INTERVAL_SECONDS` (padrão 3600; `0` desliga) e pelo comando `php bin/communication-scheduler.php` (gate, hospedagem compartilhada via cron). Duas execuções simultâneas não duplicam, porque a UNIQUE `(tenant_id, dedupe_key)` barra a segunda.
- Canais: o e-mail usa o provedor de `COMMUNICATION_EMAIL_DRIVER`, que é `log` (sandbox, padrão) ou `smtp` (PHPMailer, já em `composer.json`, configurado por `SMTP_*`). O SMTP vem de variáveis de ambiente e não de `SystemPreference`, como `app/lib/util/MailService.php` faz. As variáveis vão para `.env.example` e para o bloco `x-php-service` do `docker-compose.yml`, nunca para o `.env`. O WhatsApp do MVP é um link `wa.me` com o texto pronto, que o atendente abre e depois marca como enviado (status `manual`). A interface `MessageChannelProviderInterface` deixa o lugar pronto para uma API oficial.
- Status da mensagem: `queued`, `sent`, `failed` e `manual` (pedidos pelo usuário), mais `cancelled` (descartada pelo atendente, ou cancelada pelo worker quando o tutor saiu do opt-in antes do envio). Sem `cancelled`, um WhatsApp automático não enviado ficaria pendente para sempre.
- Base legal e consentimento (LGPD, decisão do usuário): `communication_preference` guarda o opt-in/opt-out por canal, com a origem (`in_person`, `phone`, `written`, `online`) e quem registrou. Cada mensagem grava `legal_basis`. Confirmação de agendamento e lembrete de retorno usam `legitimate_interest`: saem salvo opt-out do tutor no canal (sem linha = envia). Vacina, cobrança e as finalidades manuais `custom`/`document_ready` usam `consent`: exigem `opted_in` explícito no canal (sem linha = não envia). O opt-out bloqueia sempre, e o worker reconfere antes de enviar (`opted_out` ou `consent_missing`). E-mail e telefone do tutor só ficam em `tutor` e no snapshot `communication_message.recipient`: nunca em log, URL, payload de fila ou motivo de dead-letter. Os logs levam só ids e códigos de erro.
- Retorno: não existe marca de retorno em `appointment`. A 7A cria `appointment_followup` (ligação retorno → atendimento), gravada por `EncounterView::onScheduleFollowUp` dali em diante. O retorno da cirurgia (6B) já está em `surgery.followup_appointment_id`. Retornos agendados antes da 7A pelo atendimento não são reconhecidos (limite documentado).
- Recebível não tem vencimento nem unidade. A cobrança usa a idade (`receivable.created_at` mais de `COMMUNICATION_RECEIVABLE_REMINDER_DAYS` dias, padrão 7) e a unidade de `encounter_account.system_unit_id`. A vacina e o exame pegam a unidade de `encounter.system_unit_id`.
- Central de Pendências sem tabela própria: tudo é lido por consulta das fontes. A justificativa está em Decisões de arquitetura.
- RBAC (decisão do usuário): 7 programas novos, concedidos aos grupos 1 (`Template - Admin`), 2 (`Template - Users`), 4 (`Clínico – Internação`) e 5 (`Clínico – Cirurgia`). O grupo 3 (`Application - Programs`) não recebe nada. Os grupos 1 e 2 são localizados por id (fixos do Adianti) e os grupos 4 e 5 pelo nome. A autorização segue o padrão `AuthorizationRequest` com `requiresUnitScope: true` e `resourceUnitId` da mensagem ou da unidade ativa. Preferência e template são do tenant (sem unidade).
- Concorrência, com as lições da 6A/6B: toda transição de status de mensagem é um `UPDATE ... WHERE status = <esperado>` com conferência de `rowCount`. O claim do worker é um UPDATE condicional (`claimed_at`). A geração de lembretes é idempotente pela UNIQUE de `dedupe_key`.
- Migration `0012` e DML de `system_program` são preparadas pelas tasks e aplicadas só pelo orquestrador, com backup, `gzip -t`, SHA-256 e aprovação SQL explícita do usuário (skill `sql-write-approval`), no **bloqueio entre a Onda 1 e a Onda 2**: a migration em `centralvet` e `centralvet_test`, a DML só em `centralvet`. Nenhuma task executa SQL de escrita.
- Registros criados nos gates levam o prefixo `F7A teste` (tutor `F7A teste Tutor` com e-mail `f7a.teste@example.invalid`, paciente, templates e atendimentos de teste). O SQL de limpeza (T-23) os cobre, e só o orquestrador o executa, com aprovação. Os gates usam o driver `log` e nunca enviam e-mail real.
- Nada em `src/lib/adianti` nem em arquivo listado em `src/app/config/framework_hashes.php` (`index.php`, `engine.php`, `init.php`, `composer.json`, `app/lib/include|menu|util|validator`, `app/lib/widget/TAccordion.php`, `app/templates/adminbs5/*` exceto `cv-components.css`). Telas novas usam `CvPage::header`, nunca `TXMLBreadCrumb`. Controllers ficam em `app/control/clinic` (`app/control/communication` é do Adianti: `SystemMessage*`).
- Texto livre (corpo da mensagem, motivo) só por POST. Deep-links e rotas levam só ids, datas e códigos fixos.

## Escopo

### Incluso
- Migration `0012` (4 tabelas: `communication_preference`, `message_template`, `communication_message`, `appointment_followup`; 2 índices em tabelas existentes), `.verify.sql` e `provision.sh` → T-01.
- Domain de canais, finalidades, preferência, template (renderizador e textos padrão) e mensagem, com os contratos dos repositórios → T-02.
- Domain da Central de Pendências (item, prioridade, deep-link) e do candidato a lembrete, com os contratos das consultas → T-03.
- Programas RBAC das 7 telas (seed + DML, verify e rollback) → T-04.
- Provedores desacoplados (interface, sandbox `log`, SMTP por env, link `wa.me`), variáveis em `.env.example` e `docker-compose.yml` → T-05.
- Fakes → T-06; repositórios PDO (dedupe, claim, transições condicionais) e `PdoConnectionFactory` → T-07; consultas de pendências e de candidatos a lembrete → T-08.
- `CommunicationPreferenceService` e `MessageTemplateService` → T-09; `MessageService` (compor manual, marcar enviado, descartar, reenviar, link WhatsApp, histórico) e `MessageQueuePublisher` → T-10; `ReminderGenerationService` → T-11; `MessageDeliveryService` (worker: claim, reconferência de consentimento, retentativa) → T-12; `PendingCenterService` → T-13; ligação de retorno `AppointmentFollowupService` + `EncounterView::onScheduleFollowUp` → T-14.
- Worker e agendador: handler do job, `CommunicationScheduler`, `bin/worker.php`, `bin/communication-scheduler.php` → T-15.
- Telas: templates → T-16; histórico e ficha da mensagem → T-17; compor mensagem e preferências do tutor → T-18; Central de Pendências → T-19; navegação (menu, `CvNav`, ações no `TutorForm`) → T-20.
- i18n pt/en (`translations.json`, `UserMessage`) → T-21; runbook do módulo e seção 0012 da hospedagem 5.7 → T-22.
- Validação final ponta a ponta e SQL de limpeza dos registros `F7A teste` → T-23.

### Excluído
- Fase 7B: documentos/PDF assíncrono, storage de documentos, workflows configuráveis. O template `document_ready` existe, mas só para envio manual.
- API oficial do WhatsApp (Cloud API/BSP), SMS, push, inbox de respostas do tutor, webhooks de status de entrega, e-mail em HTML ou com anexos.
- Responsável ou status manual nas pendências (atribuir, adiar, marcar como resolvida sem resolver a fonte), notificações em tempo real e badge de contagem no menu.
- Alterar `appointment`, `AppointmentService`, `Appointment`, `AppointmentRepository`, `SurgeryCompletionService`, `MailService`/`TMail`, `SystemPreference` ou as telas `SystemMessage*` do Adianti.
- Configuração real de SMTP no `.env` e envio de e-mail real nos gates.
- Executar migration, DML ou SQL de limpeza (só o orquestrador, com aprovação).

## Contexto técnico
- Camadas envolvidas: database, backend, infra, frontend, shared (testes), docs, qa.
- Projeto/base analisada: `/var/www/html/centralvet` (repositório único; `git -C <DIR> rev-parse --show-toplevel` = `/var/www/html/centralvet`), branch `feat/fase-6b-cirurgia` @ `bf2178d`, Adianti 8.6, PHP 8.4, MySQL 8.0.43 local / 5.7 na hospedagem, Redis (fila), PHPMailer 6.
- Integrações: `RedisQueue`/`QueueInterface`/`QueueMessage` (fila existente), `src/bin/worker.php`, PHPMailer, `RbacAuthorizationService` + `PdoAuditLogWriter`, `TenantContext`, `EncounterView::onScheduleFollowUp`, telas de destino dos deep-links (`ExamResultForm`, `AgendaView`, `VaccinationCardView`, `HospitalizationView`, `PaymentForm`).

## Baseline
- php-lint: `php -l` (via LINT com `sh -c`) em todo `.php` de `app/Core`, `app/control/clinic`, `app/lib/widget`, `bin`, `tests/Unit`, `tests/Support` e `tests/Integration` (505 arquivos), só as linhas diferentes de `No syntax errors detected`, em raiz → baseline/php-lint.txt (0 linhas). Critério: nenhum erro novo em relação a `baseline/php-lint.txt`, ou seja, `No syntax errors detected` em cada PHP tocado.
- test-prepare-mysql57: `python3 scripts/test-prepare-mysql57.py` em raiz → baseline/test-prepare-mysql57.txt (5 linhas: `Ran 8 tests`, `OK`).

## Exploração read-only
- Caminhos relevantes:
  - Fila e worker: `src/app/Core/Queue/{QueueInterface,QueueMessage,RedisQueue}.php` (`push(string $queue, array $payload, ?int $tenantId, ?string $correlationId, int $delaySeconds = 0, int $maxAttempts = 5): string`; `QueueMessage` com `payload`, `tenantId`, `attempts`, `maxAttempts`; `fail()` com backoff `base*2^(n-1)` e dead-letter), `src/bin/worker.php` (loop `recoverDue` → `pop` → `$handle` → `ack`/`fail($message, $e->getMessage())`; heartbeat `/tmp/centralvet-worker.heartbeat`), `src/tests/Integration/RedisQueueIntegrationTest.php`. `FakeRedis` não tem métodos de lista ou zset.
  - Config e infra: `docker-compose.yml` (`x-php-service` → `environment`; serviços `app`, `worker` = `php bin/worker.php`, `redis`, `mysql`, `nginx`, `minio`; sem cron), `.env.example` (seção do worker com `WORKER_QUEUE_NAMES`), `src/app/config/database.php` (`DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`), `JsonLogger::fromEnvironment` (redige chaves, não dados pessoais), `src/tests/Support/MysqlIntegrationTestCase.php` (PDO por env + `TestDatabase::resolveName`).
  - Fontes: `tutor` (`phone` NOT NULL, `email` NULL), `patient.tutor_id`, `appointment` (`scheduled_at`, status `agendado|confirmado|em_atendimento|atendido|cancelado|faltou`, `system_unit_id`, `professional_system_user_id`), `surgery.followup_appointment_id`, `vaccination.next_dose_at` (date; unidade por `encounter`), `receivable` (`tutor_id`, `total_cents`, `paid_cents`, status `open|partially_paid|paid|cancelled`, sem vencimento e sem unidade; unidade por `encounter_account.system_unit_id`), `exam_request` (status `requested|result_available`, `professional_system_user_id`), `exam_result.pending_review`, `hospitalization_administration` (`pending`, `scheduled_at`; atrasada = pendente 30 min depois de `scheduled_at`, `HospitalizationAdministration::classify()`), `hospitalization.responsible_system_user_id`, `tenant.timezone`.
  - Persistence: `AbstractTenantRepository` (`__construct(TenantContext $context)`, `tenantQuery()`, `assertEntityTenant()`); repositórios com `__construct(TenantContext $context, private readonly PDO $connection)`.
  - Telas: `control/clinic/TutorForm.php` (`TStandardForm`, `CvPage::header(_t('Tutor'), null, [...])` linha 80), `PendingExamResultList.php`/`PendingReceivableList.php` (deep-links `ExamResultForm&exam_request_id=`, `PaymentForm&receivable_id=` com `CvFormat::e`), `HospitalizationView&id=&tab=administrations`, `VaccinationCardView&patient_id=`, `AgendaView&date=Y-m-d`, `FinancialOverview`/`HospitalizationBoard` (painéis com `CvKpiCard`), `SurgeryList` (`.cv-touch-target`), `BedForm.php:227-240` (fábrica de service com `RbacAuthorizationService` + `PdoAuditLogWriter`).
  - Navegação e i18n: `src/menu.xml` (placeholder `_t{CRM / Communication}` → `CvShellController#method=onComingSoon#item=crm`, linhas 87-90; `Dashboard` linha 12), `src/app/lib/widget/CvNav.php` (grupos `finance`, `stock`, `services`, `hospitalization`, `surgery`, `prescription`), `app/config/translations.json` (1122 entradas, ordenadas por `en` sem caixa), `Core/Presentation/UserMessage.php` (totais travados em `tests/Unit/UserMessageTest.php:122-123`: 47/64), `ControllerRawExceptionMessageTest` (varre `clinic` e `log`), `Hospitalization/SurgeryNavigationIntegrationTest` (ordem do menu).
  - Banco e scripts: `src/app/database/migrations/20261005_0011_phase6b_surgery.sql` (modelo; placeholder de 64 zeros nas linhas 260-262), `scripts/test-db/provision.sh` (lista nas linhas 46-56, comentário na linha 10), `scripts/prepare-mysql57.py` (glob `[0-9][0-9]-*.sql`; a 0012 vira `16-`), `app/database/seeds/initial-application-programs.sql` (708 linhas; bloco 6B nas linhas 557-708), `.claude/tasks/mar-20261005-2234-fase-6b-cirurgia/sql/T-04-programs*.sql` e `T-21-cleanup.sql` (modelos), `docs/runbooks/README.md:15-16`, `docs/runbooks/shared-hosting-mysql57.md:164-190` (seção da 0011).
- Padrões identificados:
  - Services recebem repositórios por interface, `AuthorizationPolicyInterface`, `TenantContext` e `?Closure $clock = null` (último), e autorizam com `decide(new AuthorizationRequest(context:, action:, requiresUnitScope:, resourceUnitId:, entityType:, entityId:, metadata:))->assertAllowed()`. Services não abrem transação: o controller abre `TTransaction::open('permission')`.
  - Controller: `private const ACTION_X = 'Classe::método'`; catches `AuthorizationDenied` (texto fixo), `MissingTenantContext` ('An authenticated session with an active unit is required') e `Exception` (rollback + `error_log` + `CvFormat::userError($e)`); `ControllerRawExceptionMessageTest` reprova `getMessage()` na tela; nome/título vindos do banco com `CvFormat::e` (achado de XSS da T-15 da 6B).
  - Migration: `CONSTRAINT <tabela>_<x>_ck CHECK (...)` nomeado (≤ 61 caracteres), sem `BETWEEN`/`LIKE`/`CASE`/funções; `timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)`; opcionais `timestamp(6) NULL DEFAULT NULL`; FKs `ON UPDATE RESTRICT ON DELETE RESTRICT`.
- Scripts úteis: LINT, SUITE, PYTEST57 (Premissas); `python3 scripts/prepare-mysql57.py <privdir> <outdir>`; `./scripts/backup.sh`; `docker compose exec -T worker php bin/communication-scheduler.php` (a partir da Onda 4, depois do rebuild).
- Riscos identificados:
  - Worker sem banco: um PDO criado por job e por tick (sem conexão longa que expira) e o `TenantContext` do job montado com `COMMUNICATION_SYSTEM_USER_ID` (padrão 1), porque `TenantContext::authenticated` exige usuário positivo (T-15).
  - `worker.php` repassa `$jobException->getMessage()` ao dead-letter: a exceção de entrega carrega só o código (`Message delivery failed: smtp_connect`), nunca `ErrorInfo` do PHPMailer, que pode trazer endereços (T-05/T-12).
  - Corrida entre a geração por comando e pelo worker: a UNIQUE `communication_message_tenant_dedupe_uq` e o tratamento de 1062 em `insertIfNew` (T-01/T-07/T-11).
  - Redelivery do Redis (`recoverDue`) ou job duplicado pelo varredor de presas: claim condicional e status final (T-07/T-12).
  - Opt-out entre o enfileiramento e o envio: o worker reconfere e cancela com `opted_out` (T-12).
  - Fuso: a janela D-1 usa `tenant.timezone` e as consultas seguem a conversão de `AppointmentRepository::listByUnitAndDate` (T-08/T-11).
  - `UserMessageTest` trava os totais de `STATIC`/`PATTERNS` e exige tradução dos `_t` das telas: T-21 atualiza os números.
  - Navegação: `HospitalizationNavigationIntegrationTest` e `SurgeryNavigationIntegrationTest` conferem ordem e itens do `menu.xml`. T-20 roda os dois.
  - Compartilhados com um escritor só: `docker-compose.yml`, `.env.example` (T-05); `provision.sh` (T-01); seed de programas (T-04); `EncounterView.php` (T-14); `src/bin/worker.php` (T-15); `cv-components.css` (T-19); `menu.xml`, `CvNav.php`, `TutorForm.php` (T-20); `translations.json`, `UserMessage.php`, `UserMessageTest.php` (T-21); `docs/runbooks/README.md`, `shared-hosting-mysql57.md` (T-22).

## Mapa de arquivos

| Arquivo | Responsabilidade | Ação | Tasks que tocam |
|---|---|---|---|
| `src/app/database/migrations/20261006_0012_phase7a_communication.sql` | DDL das 4 tabelas e de 2 índices em tabelas existentes | criar | T-01 |
| `src/app/database/migrations/20261006_0012_phase7a_communication.verify.sql` | Verificação só com SELECT | criar | T-01 |
| `scripts/test-db/provision.sh` | Inclui a 0012 no banco de teste | modificar | T-01 |
| `src/app/Core/Domain/CommunicationChannel.php` | Canais `email`/`whatsapp` | criar | T-02 |
| `src/app/Core/Domain/MessagePurpose.php` | Finalidades das mensagens | criar | T-02 |
| `src/app/Core/Domain/CommunicationPreference.php` | Consentimento por canal | criar | T-02 |
| `src/app/Core/Domain/MessageTemplate.php` | Template por finalidade e canal | criar | T-02 |
| `src/app/Core/Domain/MessageTemplateRenderer.php` | Placeholders permitidos e renderização | criar | T-02 |
| `src/app/Core/Domain/MessageTemplateDefaults.php` | Textos padrão pt-BR por finalidade e canal | criar | T-02 |
| `src/app/Core/Domain/OutboundMessage.php` | Mensagem (status, dedupe, snapshot do destinatário) | criar | T-02 |
| `src/app/Core/Domain/Exception/CommunicationConsentRequiredException.php` | Tutor sem opt-in no canal | criar | T-02 |
| `src/app/Core/Domain/Contract/CommunicationPreferenceRepositoryInterface.php` | Contrato de preferência | criar | T-02 |
| `src/app/Core/Domain/Contract/MessageTemplateRepositoryInterface.php` | Contrato de template | criar | T-02 |
| `src/app/Core/Domain/Contract/OutboundMessageRepositoryInterface.php` | Contrato de mensagem (dedupe, claim, transições) | criar | T-02 |
| `src/app/Core/Domain/Contract/AppointmentFollowupRepositoryInterface.php` | Contrato de ligação de retorno | criar | T-02 |
| `src/tests/Unit/CommunicationDomainTest.php` | Testes do Domain de comunicação | criar | T-02 |
| `src/app/Core/Domain/PendingItem.php` | Item da Central de Pendências e deep-link | criar | T-03 |
| `src/app/Core/Domain/PendingItemPriority.php` | Regras de prioridade e status | criar | T-03 |
| `src/app/Core/Domain/ReminderCandidate.php` | Evento candidato a lembrete | criar | T-03 |
| `src/app/Core/Domain/Contract/PendingItemQueryInterface.php` | Contrato da consulta de pendências | criar | T-03 |
| `src/app/Core/Domain/Contract/ReminderSourceQueryInterface.php` | Contrato da consulta de candidatos | criar | T-03 |
| `src/tests/Unit/PendingItemDomainTest.php` | Testes de prioridade, status e deep-link | criar | T-03 |
| `src/app/database/seeds/initial-application-programs.sql` | 7 programas e concessões em instalação nova | modificar | T-04 |
| `.claude/tasks/mar-20261006-0842-fase-7a-comunicacao/sql/T-04-programs.sql` | DML dos 7 programas e das concessões | criar | T-04 |
| `.claude/tasks/mar-20261006-0842-fase-7a-comunicacao/sql/T-04-programs.verify.sql` | Verificação só com SELECT | criar | T-04 |
| `.claude/tasks/mar-20261006-0842-fase-7a-comunicacao/sql/T-04-programs.rollback.sql` | Reversão preparada com WHERE por nome | criar | T-04 |
| `src/app/Core/Communication/MessageChannelProviderInterface.php` | Interface de provedor de canal | criar | T-05 |
| `src/app/Core/Communication/OutgoingMessage.php` | Mensagem a entregar (destinatário, assunto, corpo, referência) | criar | T-05 |
| `src/app/Core/Communication/MessageDeliveryFailed.php` | Falha de entrega só com código | criar | T-05 |
| `src/app/Core/Communication/LogEmailProvider.php` | Sandbox `log` sem dado pessoal | criar | T-05 |
| `src/app/Core/Communication/SmtpConfig.php` | Configuração SMTP por env | criar | T-05 |
| `src/app/Core/Communication/SmtpEmailProvider.php` | Envio SMTP via PHPMailer | criar | T-05 |
| `src/app/Core/Communication/EmailProviderFactory.php` | Escolha do provedor por `COMMUNICATION_EMAIL_DRIVER` | criar | T-05 |
| `src/app/Core/Communication/WhatsAppLinkBuilder.php` | Normalização de telefone e link `wa.me` | criar | T-05 |
| `.env.example` | Variáveis de comunicação documentadas | modificar | T-05 |
| `docker-compose.yml` | Variáveis de comunicação no `x-php-service` | modificar | T-05 |
| `src/tests/Unit/CommunicationProviderTest.php` | Testes de provedores, config e link | criar | T-05 |
| `src/tests/Support/FakeCommunicationPreferenceRepository.php` | Dublê de preferência | criar | T-06 |
| `src/tests/Support/FakeMessageTemplateRepository.php` | Dublê de template | criar | T-06 |
| `src/tests/Support/FakeOutboundMessageRepository.php` | Dublê de mensagem (dedupe, transições condicionais) | criar | T-06 |
| `src/tests/Support/FakeAppointmentFollowupRepository.php` | Dublê de ligação de retorno | criar | T-06 |
| `src/tests/Support/FakePendingItemQuery.php` | Dublê da consulta de pendências | criar | T-06 |
| `src/tests/Support/FakeReminderSourceQuery.php` | Dublê da consulta de candidatos | criar | T-06 |
| `src/tests/Support/FakeQueue.php` | Dublê de `QueueInterface` | criar | T-06 |
| `src/tests/Support/FakeEmailProvider.php` | Dublê de provedor (sucesso ou falha por código) | criar | T-06 |
| `src/tests/Unit/CommunicationFakesTest.php` | Contrato dos dublês | criar | T-06 |
| `src/app/Core/Persistence/PdoConnectionFactory.php` | PDO por env para worker e comando | criar | T-07 |
| `src/app/Core/Persistence/CommunicationPreferenceRepository.php` | PDO de preferência (upsert) | criar | T-07 |
| `src/app/Core/Persistence/MessageTemplateRepository.php` | PDO de template | criar | T-07 |
| `src/app/Core/Persistence/OutboundMessageRepository.php` | PDO de mensagem (dedupe 1062, claim, UPDATE condicional) | criar | T-07 |
| `src/app/Core/Persistence/AppointmentFollowupRepository.php` | PDO de ligação de retorno | criar | T-07 |
| `src/tests/Integration/CommunicationRepositoryIntegrationTest.php` | Integração no `centralvet_test` | criar | T-07 |
| `src/app/Core/Persistence/PendingItemQuery.php` | Consulta das 8 fontes de pendência por unidade | criar | T-08 |
| `src/app/Core/Persistence/ReminderSourceQuery.php` | Consulta de candidatos a lembrete | criar | T-08 |
| `src/tests/Integration/CommunicationReadModelIntegrationTest.php` | Integração das consultas (inclusive isolamento por unidade) | criar | T-08 |
| `src/app/Core/Application/CommunicationPreferenceService.php` | Leitura e registro de consentimento | criar | T-09 |
| `src/app/Core/Application/MessageTemplateService.php` | Cadastro de templates (placeholders, um ativo por finalidade e canal) | criar | T-09 |
| `src/tests/Unit/CommunicationPreferenceServiceTest.php` | Testes de consentimento | criar | T-09 |
| `src/tests/Unit/MessageTemplateServiceTest.php` | Testes de templates | criar | T-09 |
| `src/app/Core/Application/MessageService.php` | Compor, marcar enviado, descartar, reenviar, link, histórico | criar | T-10 |
| `src/app/Core/Application/MessageQueuePublisher.php` | Publicação do job na fila | criar | T-10 |
| `src/tests/Unit/MessageServiceTest.php` | Testes do ciclo manual | criar | T-10 |
| `src/app/Core/Application/ReminderGenerationService.php` | Geração idempotente dos lembretes | criar | T-11 |
| `src/app/Core/Application/ReminderRunSummary.php` | Contagens de uma execução | criar | T-11 |
| `src/tests/Unit/ReminderGenerationServiceTest.php` | Testes de geração, consentimento e dedupe | criar | T-11 |
| `src/app/Core/Application/MessageDeliveryService.php` | Entrega no worker (claim, consentimento, retentativa) | criar | T-12 |
| `src/tests/Unit/MessageDeliveryServiceTest.php` | Testes de entrega | criar | T-12 |
| `src/app/Core/Application/PendingCenterService.php` | Lista, filtros e ordenação das pendências | criar | T-13 |
| `src/tests/Unit/PendingCenterServiceTest.php` | Testes da central | criar | T-13 |
| `src/app/Core/Application/AppointmentFollowupService.php` | Ligação retorno → atendimento | criar | T-14 |
| `src/app/control/clinic/EncounterView.php` | `onScheduleFollowUp` grava a ligação de retorno | modificar | T-14 |
| `src/tests/Unit/AppointmentFollowupServiceTest.php` | Testes da ligação | criar | T-14 |
| `src/app/Core/Communication/CommunicationJobHandler.php` | Handler do job `communication.message.send` | criar | T-15 |
| `src/app/Core/Communication/CommunicationScheduler.php` | Execução por tenant: lembretes, publicação, varredura | criar | T-15 |
| `src/bin/worker.php` | Despacho por `type` e tick do agendador | modificar | T-15 |
| `src/bin/communication-scheduler.php` | Comando de execução única | criar | T-15 |
| `src/tests/Unit/CommunicationWorkerTest.php` | Testes do handler e do agendador | criar | T-15 |
| `src/app/control/clinic/MessageTemplateList.php` | Lista de templates | criar | T-16 |
| `src/app/control/clinic/MessageTemplateForm.php` | Cadastro/edição de template | criar | T-16 |
| `src/tests/Integration/MessageTemplateScreensIntegrationTest.php` | Campos e ações das telas de template | criar | T-16 |
| `src/app/control/clinic/CommunicationMessageList.php` | Histórico com filtros e destinatário mascarado | criar | T-17 |
| `src/app/control/clinic/CommunicationMessageView.php` | Ficha: reenviar, abrir WhatsApp, marcar enviado, descartar | criar | T-17 |
| `src/tests/Integration/CommunicationMessageScreensIntegrationTest.php` | Ações por status, POST, escape | criar | T-17 |
| `src/app/control/clinic/CommunicationComposeForm.php` | Compor mensagem manual a partir do tutor | criar | T-18 |
| `src/app/control/clinic/TutorCommunicationForm.php` | Preferências e consentimento do tutor | criar | T-18 |
| `src/tests/Integration/CommunicationTutorScreensIntegrationTest.php` | Campos e ações das 2 telas | criar | T-18 |
| `src/app/control/clinic/PendingCenter.php` | Central de Pendências | criar | T-19 |
| `src/app/templates/adminbs5/cv-components.css` | Seção `cv-pending-*` | modificar | T-19 |
| `src/tests/Integration/PendingCenterIntegrationTest.php` | Estado vazio, deep-links, escape, touch | criar | T-19 |
| `src/menu.xml` | `Pending items` no topo; submenu de CRM / Communication | modificar | T-20 |
| `src/app/lib/widget/CvNav.php` | Grupo de abas `communication` | modificar | T-20 |
| `src/app/control/clinic/TutorForm.php` | Ações Comunicação e Enviar mensagem no cabeçalho | modificar | T-20 |
| `src/tests/Integration/CommunicationNavigationIntegrationTest.php` | Navegação registrada | criar | T-20 |
| `src/app/config/translations.json` | Chaves pt/en da 7A | modificar | T-21 |
| `src/app/Core/Presentation/UserMessage.php` | Mensagens de domínio da 7A | modificar | T-21 |
| `src/tests/Unit/UserMessageTest.php` | Casos e totais novos; `_t` das telas novas | modificar | T-21 |
| `docs/runbooks/comunicacao.md` | Fluxos, regras, env, schema, programas, operação | criar | T-22 |
| `docs/runbooks/README.md` | Índice dos runbooks | modificar | T-22 |
| `docs/runbooks/shared-hosting-mysql57.md` | Passo da 0012 e cron do agendador na hospedagem | modificar | T-22 |
| `.claude/tasks/mar-20261006-0842-fase-7a-comunicacao/sql/T-23-cleanup.sql` | Limpeza dos registros `F7A teste` (preparada) | criar | T-23 |

Nenhum arquivo é tocado por mais de uma task. Os compartilhados entre módulos (`docker-compose.yml`, `.env.example`, `provision.sh`, seed de programas, `EncounterView.php`, `worker.php`, `cv-components.css`, `menu.xml`, `CvNav.php`, `TutorForm.php`, `translations.json`, `UserMessage.php`, `UserMessageTest.php` e os runbooks compartilhados) têm uma task dona cada.

## Decisões de arquitetura

| Decisão | Alternativas consideradas | Motivo da escolha |
|---|---|---|
| Central de Pendências **sem tabela própria**: `PendingItemQuery` lê as fontes a cada abertura (exame sem resultado, resultado pendente de análise, retorno, vacina a vencer, administração atrasada, mensagem falha, WhatsApp aguardando envio, recebível em aberto); status e responsável derivados da fonte | Tabela `pending_item` alimentada por eventos; tabela só para atribuição manual | Sem estado duplicado, o item some quando a tela de destino resolve a fonte (sem "pendência fantasma" e sem sincronizar). O PRD pede responsável e status, e os dois saem da fonte (profissional solicitante, responsável da internação). Atribuição e adiamento manuais ficam fora do MVP: entram depois com uma tabela de sobreposição, sem refazer a consulta |
| Mensagem em `communication_message` (outbox + histórico) com `dedupe_key` UNIQUE por tenant (`<purpose>:<source_type>:<source_id>:<channel>`) | Tabela de log separada da fila; checar "já enviado" antes de inserir | Uma linha por evento e canal é a idempotência pedida. O INSERT que esbarra em 1062 vira "duplicado" (corrida entre o comando e o worker). O histórico e o status de envio são a mesma linha. O nome evita confusão com `system_message` do Adianti |
| Envio assíncrono pela `RedisQueue` existente (fila `default`, payload `{type: communication.message.send, message_id}`), com claim condicional (`UPDATE ... SET claimed_at WHERE status='queued' AND (claimed_at IS NULL OR claimed_at < agora-10min)`) | Enviar na requisição; nova fila dedicada | O payload não leva dado pessoal. Redelivery e job duplicado esbarram no claim. A retentativa e o dead-letter são os da fila (5 tentativas). Na última, a mensagem vira `failed` com `last_error_code` |
| Publicação depois do commit pelo controller e varredura de presas pelo agendador (e-mail `queued` há mais de 10 min sem claim volta à fila) | Publicar dentro da transação | O Redis não participa da transação MySQL: publicar antes do commit poderia entregar um id inexistente. A varredura cobre push falho ou worker parado |
| Provedores atrás de `MessageChannelProviderInterface`; e-mail `log` (padrão) ou `smtp` (PHPMailer, env `SMTP_*`); WhatsApp como link `wa.me` montado na ficha, com status `manual` registrado pelo atendente | `MailService`/`TMail` (SMTP em `SystemPreference`); API do WhatsApp já no MVP | Decisão do usuário: SMTP por env, e a interface deixa o lugar da API oficial. `log` permite gate e ambiente local sem envio real |
| Preferência em `communication_preference` (uma linha por tutor e canal, `opted_in`/`opted_out`, origem e autor) + `communication_message.legal_basis` (`legitimate_interest` para confirmação e retorno; `consent` para o resto), com a regra única `CommunicationPreference::permitsSending`; auditado pelo `RbacAuthorizationService` com metadados sem contato | Colunas em `tutor`; opt-in para tudo; base legal só no código | Decisão do usuário: legítimo interesse (salvo opt-out) para confirmação e retorno, opt-in explícito para vacina e cobrança. A base fica registrada por mensagem para auditoria LGPD. Não mexe em `TutorRepository`. Uma regra só, usada na composição, na geração e no worker |
| Templates por tenant (`message_template`), placeholders fechados em `MessageTemplateRenderer::PLACEHOLDERS`, um ativo por finalidade e canal; sem template ativo, a automação usa `MessageTemplateDefaults` | Templates obrigatórios antes da automação; placeholders livres | A automação funciona desde a instalação. Placeholder desconhecido é recusado no cadastro. Texto puro (sem HTML) não tem injeção no e-mail |
| `appointment_followup` (retorno → atendimento) gravada por `EncounterView::onScheduleFollowUp` via `AppointmentFollowupService`; o retorno da cirurgia vem de `surgery.followup_appointment_id` | Coluna em `appointment` + mudar `Appointment`/`AppointmentService`/`AppointmentRepository` | Não mexe na agenda (Fase 1) nem na 6B: uma tabela nova e uma chamada no controller |
| Agendador no worker (tick por `COMMUNICATION_SCHEDULER_INTERVAL_SECONDS`) e comando `bin/communication-scheduler.php`, os dois sobre `CommunicationScheduler::runOnce` | Serviço cron novo no compose | Sem infraestrutura nova. A hospedagem compartilhada usa cron no comando. A dedupe torna as execuções concorrentes inofensivas |
| Worker monta um PDO por job e por tick (`PdoConnectionFactory::fromEnvironment`) e o `TenantContext` do job com `COMMUNICATION_SYSTEM_USER_ID` | Conexão longa; mudar `TenantContext` | Evita conexão expirada no loop longo. `TenantContext::authenticated` exige usuário positivo e não é alterado. Services do worker não autorizam (ator de sistema) |
| Rotas fixas entre telas (abaixo) | Cada tela decide seus parâmetros | As telas da Onda 4 se ligam por URL sem depender umas das outras |

Rotas fixadas (todas `index.php?class=...`, só ids, datas e códigos fixos):
- `MessageTemplateList`; `MessageTemplateForm` (`&id=<template_id>` para editar).
- `CommunicationMessageList` (filtros por POST); `CommunicationMessageView&id=<message_id>`.
- `CommunicationComposeForm&tutor_id=<id>` (opcional `&patient_id=<id>`).
- `TutorCommunicationForm&tutor_id=<id>`.
- `PendingCenter` (filtros `&type=<tipo>` e `&mine=1`).
- Deep-links da central: `ExamResultForm&exam_request_id=<id>&encounter_id=<id>`, `AgendaView&date=<Y-m-d>`, `VaccinationCardView&patient_id=<id>`, `HospitalizationView&id=<id>&tab=administrations`, `CommunicationMessageView&id=<id>`, `PaymentForm&receivable_id=<id>`.

## Diagrama de dependências

```text
Onda 1: T-01  T-02  T-03  T-04  T-05
        [bloqueio: 0012 em centralvet + centralvet_test; sql/T-04-programs.sql em centralvet]
Onda 2: T-06 (T-02,T-03,T-05)   T-07 (T-01,T-02)   T-08 (T-01,T-03)
Onda 3: T-09 (T-02,T-06)  T-10 (T-02,T-05,T-06)  T-11 (T-02,T-03,T-06)
        T-12 (T-02,T-05,T-06)  T-13 (T-03,T-06)  T-14 (T-02,T-06,T-07)
Onda 4: T-15 (T-05,T-07,T-08,T-10,T-11,T-12)  T-16 (T-07,T-09)  T-17 (T-05,T-07,T-10)
        T-18 (T-07,T-09,T-10)  T-19 (T-08,T-13)  T-20 (T-04)
Onda 5: T-21 (T-15..T-20)   T-22 (T-01,T-04,T-05,T-15)
Onda 6: T-23 (T-21,T-22)
```

## Estratégia de execução
- Branch de trabalho: `feat/fase-7a-comunicacao`
- Branch base: `feat/fase-6b-cirurgia`
- Ponto de partida: `feat/fase-6b-cirurgia` @ `bf2178d`. O orquestrador cria a branch de trabalho com `repos.py --preparar`. O nome `feat/` foi dado pelo orquestrador em vez de `task/fase-7a-comunicacao`.
- Commits da onda: cada implementador commita os próprios caminhos (`git commit -- <caminhos>`); com `worktree por agente` eles chegam pelos merges de `wave<N>/T-XX`. O fechador usa a skill `new-commit --auto` só se sobrou algo sem commit e sempre registra o estado das tasks num `chore(tasks): registra commits da onda N`.
- Isolamento em ondas com edições paralelas: caminho exclusivo — nenhum arquivo é dividido entre tasks; os compartilhados entre módulos têm um escritor só (Mapa de arquivos).
- Bloqueio entre a Onda 1 e a Onda 2 (orquestrador, com aprovação SQL): backup + `gzip -t`, SHA-256 da 0012 numa cópia, aplicação em `centralvet` e `centralvet_test` com `MIGRATION_DB_USER` e `.verify.sql` nos dois; `sql/T-04-programs.sql` (7 programas + 28 concessões aos grupos 1, 2, `Clínico – Internação` e `Clínico – Cirurgia`) + `.verify.sql` em `centralvet` com `mysql --default-character-set=utf8mb4`. Últimas contagens conhecidas, do fim da 6B: `system_group` 5, `system_program` 126, `system_group_program` 145 (reconferir por SELECT). As contagens antes/depois de `appointment`, `vaccination`, `receivable`, `tutor`, `system_program` e `system_group_program` vão para `notes.md § Bloqueios`. A Onda 2 não abre sem isso: T-07 e T-08 rodam integração no `centralvet_test`.
- Gates econômicos (limite de turnos do validador):
  - Ondas 1–3: LINT dos arquivos da onda + uma SUITE inteira (+ PYTEST57 e preparador 5.7 sobre a 0012 na Onda 1). Sem navegador.
  - Onda 4: rebuild + login admin pelo orquestrador; SUITE + `docker compose exec -T worker php bin/communication-scheduler.php` (saída JSON com contagens, exit 0) + `docker compose logs --since 5m worker` sem e-mail/telefone (grep pelo domínio `example.invalid` e pelo telefone de teste = 0) + smoke Playwright **só desktop (1366×768)** abrindo cada uma das 7 telas uma vez (render, console com 0 mensagens de nível error, rede sem resposta ≥ 400). Fluxos completos ficam para a T-23.
  - Onda 5: SUITE + fetch autenticado em pt das 7 telas (nenhum `Message not found`).
  - Onda 6 (T-23): E2E em **dois disparos de validador**: roteiro A (fluxo completo em desktop e tablet 820×1180) e roteiro B (os 5 itens de Review Focus + permissão negada).
- i18n: até a Onda 5, as telas usam `_t('<en>')` e anotam `- [T-xx] i18n: <en> → <pt>` no board. "Message not found" no gate da Onda 4 é aceito até a T-21.

## Ondas de execução

### Onda 1
- T-01
- T-02
- T-03
- T-04
- T-05

### Onda 2
- T-06
- T-07
- T-08

### Onda 3
- T-09
- T-10
- T-11
- T-12
- T-13
- T-14

### Onda 4
- T-15
- T-16
- T-17
- T-18
- T-19
- T-20

### Onda 5
- T-21
- T-22

### Onda 6
- T-23

## Agentes

| Agente | subagent_type | model | Tasks |
|---|---|---|---|
| Sun Tzu — orquestrador | — | — | todas |
| Darwin | general-purpose | inherit | T-01 |
| Platão | general-purpose | inherit | T-02, T-06 |
| Arquimedes | general-purpose | inherit | T-03, T-13 |
| Jaspion | general-purpose | inherit | T-04, T-09, T-20 |
| Tesla | general-purpose | inherit | T-05, T-12 |
| Athena | general-purpose | inherit | T-07, T-10 |
| Sherlock | general-purpose | inherit | T-08, T-11 |
| Aang | general-purpose | inherit | T-14, T-15 |
| Saitama | general-purpose | inherit | T-16 |
| Kratos | general-purpose | inherit | T-17 |
| Naruto | general-purpose | inherit | T-18 |
| Batman | general-purpose | inherit | T-19 |
| Levi | general-purpose | inherit | T-21 |
| Gandalf | geduc:documentador | sonnet | T-22 |
| Spock | general-purpose | inherit | T-23 |

## Review Focus
- Agendador executado duas vezes no mesmo dia (comando manual enquanto o worker roda o tick) → uma mensagem por evento e canal; a segunda execução conta `duplicates` e não cria linhas → T-11
- Tutor passa para `opted_out` depois de a mensagem de e-mail ter entrado na fila → o worker não chama o provedor, a mensagem fica `cancelled` com `last_error_code` `opted_out` e o job é confirmado (sem retentativa) → T-12
- SMTP inacessível em todas as tentativas → depois da 5ª a mensagem fica `failed` com `last_error_code` `smtp_connect` e aparece na Central de Pendências; nem o motivo do dead-letter no Redis nem o log do worker contêm o e-mail ou o corpo → T-12
- Dois toques em "Marcar como enviado" (duas abas) num WhatsApp na fila → uma transição para `manual`; o segundo recebe `Message <id> is no longer awaiting manual send` traduzida → T-10
- Nome de paciente ou tutor e corpo de template com `<script>` → texto escapado na Central de Pendências, no histórico e na ficha da mensagem, e o link `wa.me` com o texto codificado por `rawurlencode` → T-19

## Critérios gerais de aceite
- SUITE com `Failed: 0` e `Total` maior ou igual ao da BASE da onda somado aos testes novos.
- Nenhum erro novo em relação a `baseline/php-lint.txt`: cada PHP tocado imprime `No syntax errors detected`.
- PYTEST57 com `Ran 8 tests` (ou mais) e nenhuma falha.
- Toda query nova filtra `tenant_id` (`tenantQuery()` ou parâmetro explícito), e toda mutação feita por tela autoriza com `resourceUnitId` da mensagem persistida ou da unidade ativa.
- Nenhum e-mail, telefone ou corpo de mensagem em log, URL, payload de fila ou motivo de dead-letter (grep nos logs do worker do gate = 0).
- Toda tela nova mostra estado vazio, erro traduzido via `CvFormat::userError` e permissão negada (captura `AuthorizationDenied` e `MissingTenantContext`). Botões de ação no tablet têm altura ≥ 44 px (`cv-touch-target`). Texto livre só por POST.
- Contagens de `appointment`, `vaccination`, `receivable`, `tutor`, `encounter` e `system_program` antes e depois do bloqueio e dos gates: as linhas existentes continuam lá (só crescem até a limpeza).
