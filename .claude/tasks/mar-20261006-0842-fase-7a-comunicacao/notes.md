# Notas de execução

## Decisões tomadas
- 2026-10-06 · plano · onda 0 — Escopo pelo usuário: Fase 7A = Comunicação (PRD §8.20) + Central de Pendências (PRD §8.23), MVP. Documentos/PDF assíncrono e workflows configuráveis ficam na 7B.
- 2026-10-06 · plano · onda 0 — Canais pelo usuário: e-mail real por SMTP configurado por variáveis de ambiente (`.env.example` + `docker-compose.yml`; nunca `.env`); WhatsApp como link `wa.me` com texto pronto e envio manual registrado como `manual`; interface `MessageChannelProviderInterface` para a API oficial depois; provedor `log` (sandbox) como padrão local e dos gates.
- 2026-10-06 · plano · onda 0 — Status da mensagem: `queued`, `sent`, `failed`, `manual` (pedidos) + `cancelled` (descartada pelo atendente ou cancelada pelo worker por opt-out). Sem ele, o WhatsApp automático não enviado ficaria pendente para sempre na central.
- 2026-10-06 · plano · onda 0 — Central de Pendências sem tabela própria: `PendingItemQuery` lê 8 fontes por consulta; responsável e status vêm da fonte; o item some quando a tela de destino resolve a fonte. Atribuição e adiamento manuais ficam fora do MVP (exigiriam tabela de sobreposição, que entra depois sem refazer a consulta).
- 2026-10-06 · plano · onda 0 — Idempotência: `communication_message.dedupe_key` UNIQUE por tenant (`<purpose>:<source_type>:<source_id>:<channel>`), INSERT com tratamento de 1062 (nunca `INSERT IGNORE`, que no MySQL 8 engole violação de CHECK). Envio: claim condicional por `claimed_at` + transições `UPDATE ... WHERE status = <esperado>` com `rowCount` (lições 6A/6B). Retentativa e dead-letter são os da `RedisQueue` (5 tentativas); na última, `failed` com `last_error_code`.
- 2026-10-06 · plano · onda 0 — Publicação na fila só depois do commit (controller) + varredura de e-mails `queued` presos há mais de 10 min pelo agendador; o payload da fila é só `type` + `message_id` (sem dado pessoal).
- 2026-10-06 · plano · onda 0 — Agendador sem cron novo: tick no worker (`COMMUNICATION_SCHEDULER_INTERVAL_SECONDS`, padrão 3600, `0` desliga) e comando `bin/communication-scheduler.php` (gate e cron da hospedagem), ambos sobre `CommunicationScheduler::runOnce`. A janela D-1 usa `tenant.timezone`.
- 2026-10-06 · plano · onda 0 — Worker: PDO por job e por tick (`PdoConnectionFactory::fromEnvironment`), `TenantContext::authenticated($tenantId, COMMUNICATION_SYSTEM_USER_ID)` (padrão 1), porque o `TenantContext` exige usuário positivo e não é alterado; services do worker (geração e entrega) não autorizam (ator de sistema).
- 2026-10-06 · plano · onda 0 — Preferência por canal em `communication_preference` (origem `in_person|phone|written|online`, autor e data). Auditoria pelo `RbacAuthorizationService` com metadata sem contato. A base legal está na resposta (2) abaixo.
- 2026-10-06 · plano · onda 0 — Retorno: `appointment_followup` nova, gravada por `EncounterView::onScheduleFollowUp` via `AppointmentFollowupService`; o retorno da cirurgia vem de `surgery.followup_appointment_id`. `Appointment`, `AppointmentService`, `AppointmentRepository` e `SurgeryCompletionService` ficam intocados. Retornos anteriores à 7A não são reconhecidos.
- 2026-10-06 · plano · onda 0 — Cobrança: recebível não tem vencimento nem unidade; lembrete único por recebível e canal quando `created_at` passou de `COMMUNICATION_RECEIVABLE_REMINDER_DAYS` (7); unidade de `encounter_account.system_unit_id`.
- 2026-10-06 · plano · onda 0 — Templates por tenant, placeholders fechados (`MessageTemplateRenderer::PLACEHOLDERS`), um ativo por finalidade e canal; sem template ativo a automação usa `MessageTemplateDefaults` (pt-BR). Texto puro (sem HTML).
- 2026-10-06 · plano · onda 0 — Nomes: tabela `communication_message` e controllers `Communication*`, para não colidir com `system_message`, `app/control/communication` e `SystemMessage*` do Adianti. Controllers novos em `app/control/clinic` (varridos pelo `ControllerRawExceptionMessageTest`).
- 2026-10-06 · plano · onda 0 — Branch de trabalho `feat/fase-7a-comunicacao` (nome dado pelo orquestrador; o padrão da skill seria `task/fase-7a-comunicacao`), base `feat/fase-6b-cirurgia` @ `bf2178d`.
- 2026-10-06 · plano · onda 0 — Gates econômicos: Ondas 1–3 só LINT + SUITE (+ PYTEST57 na 1); Onda 4 com rebuild, comando do agendador, grep de dado pessoal no log do worker e smoke desktop das 7 telas; Onda 5 fetch em pt; Onda 6 E2E em dois disparos (roteiro A fluxo completo desktop + tablet; roteiro B Review Focus + permissão negada). Login admin no Playwright pelo orquestrador.
- 2026-10-06 · plano · onda 0 — Resposta do usuário (1), RBAC: as 7 telas vão para os grupos 1 (`Template - Admin`), 2 (`Template - Users`), 4 (`Clínico – Internação`) e 5 (`Clínico – Cirurgia`); o grupo 3 (`Application - Programs`) não. T-04: grupos 1 e 2 por id (fixos do Adianti, padrão `group_id = 1` das fases anteriores), 4 e 5 pelo nome exato; 28 concessões; verify com contagem por grupo (7/7/7/7 e 0 no grupo 3); rollback por nome de controller, sem tocar nos grupos.
- 2026-10-06 · plano · onda 0 — Resposta do usuário (2), LGPD: confirmação de agendamento e lembrete de retorno saem por legítimo interesse (enviados salvo opt-out do tutor no canal); vacina e cobrança exigem opt-in explícito por canal; o opt-out é sempre respeitado. A base legal é registrada por mensagem em `communication_message.legal_basis` (`legitimate_interest` | `consent`, CHECK `communication_message_legal_basis_ck`; a 0012 passa a ter 18 CHECKs). Decisão do planejador, por falta de informação: as finalidades manuais `custom` e `document_ready` usam `consent` (a demanda só liberou confirmação e retorno); uma mensagem manual de confirmação ou de retorno usa `legitimate_interest`. Regra única `CommunicationPreference::permitsSending` (T-02), aplicada em `MessageService::compose` (T-10), na geração (T-11, novo contador `skippedOptedOut` e chave `skipped_opted_out` no JSON da T-15) e no worker (T-12, que cancela com `opted_out` ou `consent_missing`).
- 2026-10-06 · T-01 · onda 1 — 4 UNIQUEs na 0012 (a Interface define 4; o critério dizia 3).
- 2026-10-06 · T-03 · onda 1 — Achado plano-mandou (deep-link aceitava qualquer chave; telefone/CPF/data de nascimento numéricos iam para a URL) corrigido: allowlist fechada `PendingItem::DEEP_LINK_KEYS` por destino com valores tipados (RED eb4832d + fix 7bd7b70); re-revisão rodada 2 aprovada. A T-08 deve usar só essas chaves.
- 2026-10-06 · T-02 · onda 1 — Desvio aceito: CommunicationPreferenceRepositoryInterface e AppointmentFollowupRepositoryInterface não estendem TenantRepositoryInterface.
- 2026-10-06 · T-07 · onda 2 — Quatro diferenças PDO×Fake aceitas (sem impacto nos contratos usados pelos services): save() só insere e remove() lança LogicException; link() valida tenant (TenantBoundaryViolation); varredura de presos por claimed_at; link repetido idempotente só no fake.
- 2026-10-06 · T-08 · onda 2 — PendingItemQuery e ReminderSourceQuery sem AbstractTenantRepository (TenantQuery::forTenant em cada SQL) aceito; instantes convertidos para date_default_timezone_get().

## Bloqueios
- Bloqueio entre a Onda 1 e a Onda 2 (orquestrador, com aprovação SQL explícita do usuário pela skill `sql-write-approval`):
  1. `./scripts/backup.sh` e `gzip -t`.
  2. SHA-256 da 0012 numa cópia temporária (o arquivo commitado mantém o placeholder).
  3. Aplicar em `centralvet` e em `centralvet_test` com `MIGRATION_DB_USER` e rodar o `.verify.sql` nos dois.
  4. Registrar `COUNT(*)`/`MAX(id)` de `system_program` e `system_group_program` (última referência da 6B: 126 programas, 145 concessões, 5 grupos) e aplicar `sql/T-04-programs.sql` (7 programas + 28 concessões: grupos 1, 2, `Clínico – Internação` e `Clínico – Cirurgia`) em `centralvet` com `--default-character-set=utf8mb4`; depois `sql/T-04-programs.verify.sql`. Rollback preparado: `sql/T-04-programs.rollback.sql`, com nova aprovação.
  5. Registrar contagens antes/depois de `appointment`, `vaccination`, `receivable`, `tutor`, `encounter` e `system_program`.

  **RESOLVIDO em 2026-10-06** (orquestrador, aprovação SQL explícita do usuário): backup var/backups/centralvet-20261006T123507Z.sql.gz (gzip -t ok); 0012 aplicada com MIGRATION_DB_USER em centralvet e centralvet_test via cópia com SHA-256 23a840dd9a2f7b735fc6644025271365414ca6a6701a4df97a97c37c89bec2c0 (arquivo commitado mantém o placeholder); verify nos dois: 4 tabelas vazias, CHECKs 11+3+4=18, índices vaccination_tenant_next_dose_idx e receivable_tenant_status_idx criados; T-04 DML: 7 programas, grupos 1/2/4/5 = 7 concessões cada, grupo 3 = 0; system_program 126→133, system_group_program 145→173.

  Critério de desbloqueio: o `.verify.sql` lista as 4 tabelas, os 18 CHECKs e os 2 índices novos nos dois bancos, e o verify da T-04 mostra 7 concessões em cada um dos grupos 1, 2, `Clínico – Internação` e `Clínico – Cirurgia` e 0 no grupo 3.

## Descobertas
- Exploração: `src/bin/worker.php` é placeholder (sem tipos de job, sem banco); não há cron no compose; `MailService`/`TMail` leem SMTP de `SystemPreference` (não usados); `FakeRedis` não tem lista nem zset (fila testada por `FakeQueue`); `appointment` não marca retorno; `receivable` sem vencimento e sem unidade; vários repositórios antigos marcados "DO NOT WIRE YET" (a 7A usa consultas próprias em vez deles).

- Onda 1: board consolidado em notas por task (contratos T-02 com símbolos extras; provedores T-05 com códigos smtp_connect/smtp_auth/smtp_recipient_rejected/smtp_error; 12 variáveis em .env.example/docker-compose; programas T-04 com 7 controllers; mensagens i18n-domínio registradas no board para a T-21). Rebuild/restart do worker/app fica com o orquestrador.
- Onda 2: board consolidado por task (fakes T-06 com extras seed()/all()/simulateConcurrentTransition; PDO T-07 com PdoConnectionFactory::fromEnvironment e 3 mensagens i18n-domínio; consultas T-08 com ReminderCandidate::variables incluindo tutor_name). Services da onda 3 usam só insertIfNew + transições.
- Onda 3: board consolidado por task (T-09 NOT_RECORDED e save de template sem status assume active; T-10 not-found vira CrossTenantReferenceException, cancel grava motivo discarded, listForUnit limite 200; T-11 ordem de descarte opt-out→sem consent→sem contato→permitsSending e ReminderRunSummary::toArray(); T-12 constantes RESULT_*/CODE_*, reference msg-<id>, cancel perdido em corrida devolve skipped; T-14 link() na mesma transação de schedule(); 17 mensagens i18n-domínio novas para a T-21).

## Pendências
- Envio SMTP real só pode ser conferido com as credenciais do usuário (fora dos gates, que usam o driver `log`).
- Herdadas da 6B, fora do escopo: `QueueEntryView::onAdvance` com status não atualizado; admissões simultâneas do mesmo paciente sem guarda no banco.
- T-01: sugestões: dedupe_key NULL-ável sem CHECK para origin automation; source_type/source_id sem CHECK de par; retenção/expurgo de recipient e body_text (registrar no runbook T-22); sem índice para a varredura de e-mails queued presos; verify não lista os índices não únicos.
- T-02: sugestões: OutboundMessage::compose não confere base legal com MessagePurpose::legalBasisFor (vigiar T-10/T-11/T-12); TOKEN_PATTERN só [A-Za-z0-9_]; gate registrou 15 PASS/12 PHP, real 13/13.
- T-03: sugestões: gate registrou 18 PASS/5 PHP, real 19/6 (reproduzido 19 PASS; a sugestão de teste de __debugInfo foi coberta no RED eb4832d).
- T-05: sugestões: link wa.me leva telefone e corpo na URL (T-10/T-17: não logar nem persistir, sem GET da aplicação, rel="noopener noreferrer"); SMTP_ENCRYPTION=none com usuário envia credenciais em claro; SMTP_FROM_ADDRESS inválido classificado como smtp_recipient_rejected; retorno de send() ignorado; #[\SensitiveParameter] no password; faltam testes de CRLF, hash em maiúsculas e STARTTLS.
- T-06: sugestões: borda do claim do fake (10 min exatos) e insertIfNew com id divergem do PDO; simulateConcurrentTransition aceita status inválido.
- T-07: sugestões: T-10/T-11/T-12 usam só insertIfNew + transições (nunca save/remove); alinhar docblock/fake de listStaleQueuedEmailIds; fuso da sessão MySQL × PHP na varredura de presos (nota no runbook T-22 ou SET time_zone); teste de CHECK/FK com dedupe_key não nula; fábrica não testa EMULATE_PREPARES/FETCH_ASSOC.
- T-08: sugestões: fuso (cobrir na T-11); isolamento por unidade e fronteiras de janela sem fixture; appointmentsBetween sem índice (tenant_id, scheduled_at); failed sem failed_at vira agora.
- Onda 2 (validador): COUNT(*) de communication_message antes/depois da SUITE não reproduzido (relatório da T-07 declara 0/0).
- Onda 3 (validador): grep de dado pessoal nos logs do worker e contagens antes/depois ficam para as ondas 4/6.
- T-09: sugestões: um template ativo por finalidade/canal é check-then-act sem lock/UNIQUE; falta teste de edição que colida com outro ativo; auditoria de template sem metadata de status/finalidade/canal.
- T-10: sugestões: compose/renderTemplate aceitam template inativo; sem teste de e-mail com assunto vazio; sem teste de autorização por resourceUnitId da mensagem persistida; link wa.me leva telefone e corpo na URL (T-17 só abre, sem gravar nem logar).
- T-11: sugestões: candidato envenenado (compose lança InvalidArgumentException) derruba a execução do tenant, sem isolamento por candidato; volume em memória sem paginação (vigiar T-23); corrida real comando × tick coberta só pela UNIQUE.
- T-12: sugestões: retorno de markSent ignorado (claim expirado/cancelada no meio do envio devolve sent); cancel do worker exige só queued e pode cancelar mensagem já reivindicada por outro worker; outro tenant → skipped sem teste.
- T-13: sugestões: list e countsByType no mesmo render consultam duas vezes com now próprio (carregar uma vez na T-19); countsByType limitado a 200 por tipo sem indicar truncamento.
- T-14: sugestões: sem teste automatizado da ligação dentro de EncounterView::onScheduleFollowUp (atomicidade só por leitura; cobrir no roteiro A da T-23).

## Riscos
- Volume de WhatsApp manual: com legítimo interesse, todo tutor sem opt-out e com telefone válido gera um WhatsApp `queued` de confirmação D-1 e de retorno, que aparece na Central de Pendências como "aguardando envio". Mitigação: o atendente envia ou descarta pela ficha; o volume é medido no roteiro A (T-23). Se pesar na operação, o próximo passo é um interruptor por canal nas automações (fora do MVP).
- `worker.php` repassa a mensagem da exceção ao dead-letter do Redis: exceção de entrega com dado pessoal vazaria. Mitigação: `MessageDeliveryFailed` só com código (T-05), `provider_error` para qualquer outra falha (T-12), grep no log do worker nos gates das Ondas 4 e 6.
- Worker com classmap autoritativo: classe nova só aparece depois do rebuild. Mitigação: gate da Onda 4 com `docker compose build app worker`; os testes rodam por PSR-4 do host.
- Corrida comando × tick do worker: UNIQUE de `dedupe_key` + teste de segunda execução (T-11) + integração da UNIQUE (T-07).
- Fuso da janela D-1: consulta na convenção de `AppointmentRepository::listByUnitAndDate`; teste com relógio fixo em America/Fortaleza (T-11).
- `EncounterView.php` (Fase 2/6B) ganha a gravação da ligação de retorno: regressão no agendamento de retorno. Mitigação: diff restrito a `onScheduleFollowUp` e à fábrica, SUITE inteira na Onda 3 e fluxo no roteiro A.
- `menu.xml` muda o item CRM e ganha `Pending items`: os testes de navegação da 6A/6B conferem a ordem. Mitigação: T-20 roda os três testes de navegação.
- `translations.json` (1122 entradas) e `UserMessage.php` com um escritor só (T-21, Onda 5); até lá "Message not found" é tolerado no smoke da Onda 4.
- Onda 3 e Onda 4 com 6 escritores e SUITEs simultâneas (falso FAIL em Redis/deadlock). Mitigação: cada agente filtra a própria classe; o validador roda a SUITE inteira sozinho no gate.

## Retomada
- Pasta: `.claude/tasks/mar-20261006-0842-fase-7a-comunicacao/`
- Sessões: f5fb58b5-22f0-470a-ab96-189c6d59a62c
- Branch de trabalho: feat/fase-7a-comunicacao (base: feat/fase-6b-cirurgia)
- BASE da onda 1: bf2178d
- Commits por onda:
  - Onda 1: BASE bf2178d → HEAD 7bd7b70 (cc8c534,3bf14d8,bcfa8c2,61fa5dd,3c6331b,52ba96d,6938b0a,0b40507,eb4832d,7bd7b70)
  - Onda 2: BASE dc7f430 → HEAD 812b4a3 (915a000,f71f723,4c37ddd,268109c,62c579d,812b4a3)
  - Onda 3: BASE 6458506 → HEAD 8830515 (d5a6193,f6dc87f,4b762ea,94cff24,cea3501,bfc8276,1a3e831,8f91496,4fb0b8a,ae23b40,91dd851,8830515)
- Último status conhecido: onda 3 concluída (T-09..T-14 [x]); SUITE 904/904, PYTEST57 OK, LINT 17
- Próxima onda recomendada: 4 — T-15..T-20 (T-15 a T-19 e T-20 conforme plan.md)
