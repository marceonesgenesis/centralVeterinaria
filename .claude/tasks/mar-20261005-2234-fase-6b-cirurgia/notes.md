# Notas de execução

## Decisões tomadas
- 2026-10-05 · plano · onda 0 — Cirurgia só a partir de atendimento (`surgery.encounter_id NOT NULL`), como a admissão da 6A: a conta da Fase 5 é por atendimento. Entrada por `EncounterView::PLAN_ACTIONS['surgery']` (T-18).
- 2026-10-05 · plano · onda 0 — Sala como cadastro novo `surgery_room` por unidade (não existe tabela de sala no banco; `appointment` não tem sala). Conflito de sala por trava `SELECT ... FOR UPDATE` na linha da sala + consulta de sobreposição (status `scheduled`/`pre_op`/`in_progress`). Conflito de agenda do cirurgião fica fora do MVP.
- 2026-10-05 · plano · onda 0 — `procedure_catalog_item` não tem tipo/categoria: qualquer procedimento ativo pode ser agendado como cirurgia; nome e `price_cents` copiados no agendamento (a cobrança usa a cópia). Nada de ALTER na tabela da Fase 4.
- 2026-10-05 · plano · onda 0 — Consentimento como colunas de `surgery` (signatário, texto integral aceito, data, quem registrou) + evento `consent`; regravar só antes do início. Sem assinatura digital/PDF (Fase 7). Texto padrão por chave de tradução `Surgery consent default text` (T-15/T-19).
- 2026-10-05 · plano · onda 0 — Checklist fixo em `SurgeryChecklist` (5 + 4 + 4 itens). `sign_in`/`time_out` em `pre_op`, `sign_out` em `in_progress`; `start` exige consentimento + `sign_in` + `time_out`; `complete` exige `sign_out`. Uma linha por item com UNIQUE `(surgery_id, phase, item_code)`: o toque duplo vira `Checklist phase "<fase>" is already confirmed for surgery <id>`.
- 2026-10-05 · plano · onda 0 — Materiais só em `in_progress`, removíveis até a conclusão; baixa de estoque (`surgery_consumption`, FEFO) e cobrança (`surgery_procedure` com `source_id` = cirurgia; `surgery_material` com `source_id` = material) **na conclusão**, como a alta da 6A. Estoque insuficiente recusa a conclusão inteira (TTransaction único em `SurgeryView::onComplete`). Item com valor 0 não é lançado; o estoque é baixado mesmo assim.
- 2026-10-05 · plano · onda 0 — Concorrência com as lições da 6A: `SurgeryRepository::save` de linha existente só grava se o status no banco ainda é `loadedStatus()` (com reconferência `FOR UPDATE` quando `rowCount` é 0); material, conclusão e retorno chamam `lockStatus` antes de ler e decidir (resolve o equivalente cirúrgico do achado "prescrição concorrente com a alta" da revisão final da 6A).
- 2026-10-05 · plano · onda 0 — Internação pós-operatória pela tela existente `HospitalizationAdmissionForm&encounter_id=&patient_id=` (6A inalterada), depois da conclusão, e não dentro da transação da conclusão; o motivo da internação não vai pela URL. Retorno via `AppointmentService::schedule` com o cirurgião, `followup_appointment_id` gravado sob trava.
- 2026-10-05 · plano · onda 0 — Cancelamento só em `scheduled`/`pre_op` (motivo obrigatório, só POST). Remarcação = cancelar e agendar de novo. Equipe trocável em `scheduled`/`pre_op` (`SurgeryScheduleForm&id=`).
- 2026-10-05 · plano · onda 0 — Programas (9, nomes ASCII em inglês); DML com ids de `COALESCE(MAX(id),0)+1`, `SET NAMES utf8mb4`, sem `ROW_NUMBER` (grupo definido pela resposta (1) abaixo).
- 2026-10-05 · plano · onda 0 — Migration `20261005_0011_phase6b_surgery` (6 tabelas, 11 CHECKs novos + 2 ampliados), compatível com o preparador 5.7 (prefixo `15-`), timestamps `NOT NULL` com `DEFAULT CURRENT_TIMESTAMP(6)`, CHECKs sem `BETWEEN`/`LIKE`/funções. `surgery_room` não tem coluna apontando para cirurgia (sem ciclo de FK).
- 2026-10-05 · plano · onda 0 — Gates econômicos: Ondas 1–3 só LINT + SUITE; Onda 4 smoke desktop por tela; Onda 5 fetch autenticado em pt; Onda 6 E2E em dois disparos de validador (roteiro A fluxo completo desktop+tablet; roteiro B Review Focus + permissão negada). Login admin no Playwright pelo orquestrador.
- 2026-10-05 · plano · onda 0 — Resposta do usuário (1): criar o grupo novo `Clínico – Cirurgia` (18 caracteres / 21 bytes) e conceder os 9 programas a ele e ao grupo 1 (Admin); nada para o grupo 4 `Clínico – Internação` nem para os grupos 2 e 3. T-04 ajustada no padrão da T-05 da 6A: INSERT do grupo com id de `MAX(id)+1` e `NOT EXISTS`, `SET NAMES utf8mb4`, verify com `CHAR_LENGTH`/`LENGTH` do nome e rollback com WHERE por nome.
- 2026-10-05 · plano · onda 0 — Resposta do usuário (2): sala como cadastro próprio `surgery_room` com recusa de horário sobreposto — confirmado, plano mantido.
- 2026-10-05 · plano · onda 0 — Resposta do usuário (3): baixa de estoque e cobrança dos materiais só na conclusão — confirmado, plano mantido.
- 2026-10-05 · plano · onda 0 — Resposta do usuário (4): internação pós-operatória abre a admissão da 6A depois de concluir, em transação separada — confirmado, plano mantido.
- 2026-10-05 · ambiente · onda 0 — Login admin no navegador com as credenciais do `.env` autorizado pelo usuário para toda a 6B; feito pelo orquestrador, senha nunca registrada.
- 2026-10-05 · plano · onda 0 — Branch de trabalho `feat/fase-6b-cirurgia` (nome dado pelo orquestrador; o padrão da skill seria `task/fase-6b-cirurgia`), base `feat/fase-6a-internacao` @ `09ce2d5`.
- 2026-10-06 · T-01..T-04 · onda 1 — Sem rulings novos além do ruling da T-03: público extra `SurgeryChecklist::assertItemOfPhase()` (reuso por entidade e service da T-09), contrato intacto.

## Bloqueios
- Bloqueio entre a Onda 1 e a Onda 2 (orquestrador, com aprovação SQL explícita do usuário pela skill `sql-write-approval`):
  1. `./scripts/backup.sh` e `gzip -t`.
  2. SHA-256 da 0011 numa cópia temporária (o arquivo commitado mantém o placeholder).
  3. Aplicar em `centralvet` e em `centralvet_test` com `MIGRATION_DB_USER` e rodar o `.verify.sql` nos dois.
  4. Registrar `COUNT(*)`/`MAX(id)` de `system_group`, `system_program` e `system_group_program` (esperado 4/117/127) e aplicar `sql/T-04-programs.sql` (grupo novo + 9 programas + 18 concessões) em `centralvet` com `--default-character-set=utf8mb4`; depois `sql/T-04-programs.verify.sql`. Rollback preparado: `sql/T-04-programs.rollback.sql`, com nova aprovação.
  5. Registrar contagens antes/depois de `encounter_account_item`, `stock_movement`, `appointment`, `hospitalization` e `system_program`.

  RESOLVIDO em 2026-10-06 (aprovação SQL explícita do usuário): backup var/backups/centralvet-20261006T021120Z.sql.gz (gzip -t ok); 0011 aplicada com MIGRATION_DB_USER em centralvet e centralvet_test a partir de cópia com SHA-256 12f7cfbc780221f843fd74a758cb734662f1f15837258f3f6231597b3bcded76 (commitado mantém placeholder); verify nos dois: 6 tabelas vazias, 11 CHECKs novos + 2 ampliados; T-04 DML: grupo id 5 'Clínico – Cirurgia' (18 chars/21 bytes), 9 programas, concessões grupo 1 = 9, grupo 5 = 9, grupos 2/3/4 = 0; system_group 4→5, system_program 117→126, system_group_program 127→145.
  Critério original: desbloqueia quando o `.verify.sql` lista as 6 tabelas e os 13 CHECKs (11 novos, 2 ampliados) nos dois bancos e o verify da T-04 mostra o grupo `Clínico – Cirurgia` (18 caracteres / 21 bytes), 9 concessões no grupo 1, 9 em `Clínico – Cirurgia` e 0 em `Clínico – Internação` e nos grupos 2 e 3.

## Descobertas
- [T-03] `SurgeryChecklist::assertItemOfPhase` público (extra ao contrato); entidades do checklist com `reconstitute(array)` e `tenantId()`; `SurgeryEvent::record` com notes vazio em tipo não clínico grava null.
- [T-02] `Surgery` com getters extras (scheduledBy/consentRecordedBy/completedBy/cancelledBy, createdAt/updatedAt); `SurgeryRoom` e `SurgeryTeamMember` com `reconstitute(array)`; transições inválidas lançam `InvalidStatusTransitionException`.

## Pendências
- Herdadas da 6A e fora do escopo: Central de Pendências (PRD §8.23); `docs/runbooks/migrations.md` cita `$MIGRATION_USER`; admissões simultâneas do mesmo paciente sem guarda no banco.
- T-01: [sugestão] FKs de coluna única não garantem consistência tenant/unidade (T-06/T-08 devem validar); `surgery_cancelled_ck` não exige autor do cancelamento; consulta de sobreposição de sala sem limite inferior de janela varre histórico.
- T-02: [sugestão] `recordConsent` sem limite de tamanho de `consent_text` (coluna text); `SurgeryRoom::rename` sem teste.
- T-03: [sugestão] `SurgeryChecklistItem::reconstitute` falha se código sair do catálogo; `assertComplete` caminho feliz com `Assert::true(true)`.
- T-04: [sugestão] rollback só apaga concessões nos grupos 1 e Cirurgia (FK pode parar o script); lacunas de `frontpage_id`/`tenant_group`; nome sem `COLLATE utf8mb4_bin` casa sem acento.

## Riscos
- `EncounterAccountService.php` (Fase 5) muda a lista de tipos de `addSourcedItem` (T-11): regressão na alta da 6A. Mitigação: `EncounterAccountServiceTest` e `HospitalizationDischargeServiceTest` na validação de T-11; diff restrito à lista.
- Conclusão não atômica fora do controller (services não abrem transação). Mitigação: `SurgeryView::onComplete` com `TTransaction` único; Review Focus 3 conferido no roteiro B da T-21.
- Banco de teste sem a 0011 faz `SurgeryRepositoryIntegrationTest` falhar. Mitigação: o bloqueio aplica nos dois bancos e T-06 exige `PASS`.
- Hospedagem 5.7 com triggers dos 2 CHECKs ampliados já criados pela 0010. Mitigação: o preparador gera `DROP TRIGGER IF EXISTS` antes do `CREATE TRIGGER`; T-20 documenta.
- `translations.json` (951 entradas) e `UserMessage.php` com um escritor só (T-19, Onda 5); até lá "Message not found" é tolerado no smoke da Onda 4.
- Onda 4 com 7 escritores e SUITEs simultâneas (falso FAIL em Redis/deadlock). Mitigação: cada agente filtra a própria classe; o validador roda a SUITE inteira sozinho no gate.
- Limite de turnos do validador no E2E. Mitigação: gate final dividido em dois disparos e smoke da Onda 4 só desktop.

## Retomada
- Pasta: `.claude/tasks/mar-20261005-2234-fase-6b-cirurgia/`
- Sessões: f5fb58b5-22f0-470a-ab96-189c6d59a62c
- Branch de trabalho: feat/fase-6b-cirurgia (base: feat/fase-6a-internacao)
- BASE da onda 1: 09ce2d5
- Commits por onda:
  - Onda 1: BASE 09ce2d5 → HEAD 1451d0d (be36b80, 6952e1d, e8d6c93, 3936273, e416aa6, 1451d0d)
- Último status conhecido: onda 1 concluída (T-01..T-04 [x]); 0011 e DML RBAC aplicadas em centralvet e centralvet_test; SUITE 643/643.
- Próxima onda recomendada: onda 2
