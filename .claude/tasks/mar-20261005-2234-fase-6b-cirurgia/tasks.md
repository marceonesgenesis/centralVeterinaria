# Tasks

| ID | Camada | Descrição | Dependências | Paralelizável | Complexidade | Agente | Status |
|---|---|---|---|---|---|---|---|
| T-01 | database | Preparar migration 0011 (6 tabelas + 2 CHECKs ampliados), verify e provision do banco de teste | — | sim | alta | Darwin | [x] |
| T-02 | backend | Domain de sala, cirurgia e equipe + contratos + exceção + tipos de conta e motivo de estoque | — | sim | alta | Platão | [x] |
| T-03 | backend | Domain de checklist (3 fases), evento e material + contratos | — | sim | média | Arquimedes | [x] |
| T-04 | infra | Programas RBAC das 9 telas e grupo `Clínico – Cirurgia` (seed + DML, verify e rollback preparados) | — | sim | média | Jaspion | [x] |
| T-05 | shared | Fakes dos 6 repositórios da cirurgia | T-02, T-03 | sim | média | Platão | [x] |
| T-06 | backend | Repositórios PDO (trava de sala, trava de status, UPDATE condicional) + integração | T-01, T-02, T-03 | sim | alta | Athena | [x] |
| T-07 | backend | `SurgeryRoomService` — cadastro de salas por unidade | T-02, T-05 | sim | simples | Jaspion | [x] |
| T-08 | backend | `SurgeryService` — agendamento, equipe, consentimento, pré-op, início, cancelamento, eventos | T-02, T-03, T-05 | sim | alta | Athena | [x] |
| T-09 | backend | `SurgeryChecklistService` — confirmação das fases | T-02, T-03, T-05 | sim | média | Arquimedes | [x] |
| T-10 | backend | `SurgeryMaterialService` — registro e remoção de materiais sob trava | T-02, T-03, T-05 | sim | média | Saitama | [x] |
| T-11 | backend | Conclusão integrada e retorno — `SurgeryCompletionService` + `addSourcedItem` com tipos de cirurgia | T-02, T-03, T-05 | sim | alta | Aang | [x] |
| T-12 | frontend | Telas de salas (`SurgeryRoomList`, `SurgeryRoomForm`) | T-06, T-07 | sim | média | Saitama | [x] |
| T-13 | frontend | Agendamento a partir do atendimento e troca de equipe (`SurgeryScheduleForm`) | T-06, T-07, T-08 | sim | média | Naruto | [x] |
| T-14 | frontend | Ficha da cirurgia (`SurgeryView`): status, cancelamento, conclusão, retorno, internar | T-06, T-08, T-09, T-10, T-11 | sim | alta | Aang | [x] |
| T-15 | frontend | Consentimento e eventos clínicos (`SurgeryConsentForm`, `SurgeryEventForm`) | T-06, T-08 | sim | média | Kratos | [x] |
| T-16 | frontend | Checklist (tablet) e materiais (`SurgeryChecklistForm`, `SurgeryMaterialForm`) + CSS `cv-checklist-*` | T-06, T-09, T-10 | sim | alta | Tesla | [x] |
| T-17 | frontend | Agenda cirúrgica do dia (`SurgeryList`) + `SurgeryAgendaView` | T-06, T-08 | sim | média | Batman | [x] |
| T-18 | frontend | Navegação: menu, abas `CvNav`, ação no `EncounterView` | T-04 | sim | simples | Jaspion | [x] |
| T-19 | frontend | i18n pt/en e mensagens de domínio da cirurgia | T-12, T-13, T-14, T-15, T-16, T-17, T-18 | sim | média | Levi | [x] |
| T-20 | docs | Runbook da cirurgia e seção 5.7 da 0011 | T-01, T-04, T-11 | sim | simples | Gandalf | [x] |
| T-21 | qa | Validação final ponta a ponta e SQL de limpeza `F6B teste` | T-19, T-20 | não | média | Spock | [ ] |

## Detalhamento

### T-01 — Preparar migration 0011 (6 tabelas + 2 CHECKs ampliados), verify e provision do banco de teste

**Camada:** database
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** alta
**Agente:** Darwin

**Arquivos prováveis**
- `src/app/database/migrations/20261005_0011_phase6b_surgery.sql`
- `src/app/database/migrations/20261005_0011_phase6b_surgery.verify.sql`
- `scripts/test-db/provision.sh`

**Notas de implementação**

Modelo: `src/app/database/migrations/20261005_0010_phase6a_hospitalization.sql` e seu `.verify.sql`. Cabeçalho `Migration: 20261005_0011_phase6b_surgery`, `Status: PREPARED ONLY. Do not execute without a fresh backup and explicit SQL approval.`, `Target: MySQL 8.0.x ... after 20261005_0010_phase6a_hospitalization`, seções Effects (contagem de tabelas, CHECKs, UNIQUEs, FKs), Risk e Rollback (`docs/runbooks/migration-rollback.md`). Última instrução: `INSERT INTO schema_migrations (version, checksum)` com o placeholder de 64 zeros. Tabelas com PK `id bigint unsigned AUTO_INCREMENT`, `tenant_id bigint unsigned`, `system_unit_id int`, `*_system_user_id int`, `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci`. Todo CHECK é `CONSTRAINT <nome> CHECK (...)` nomeado, ≤ 61 caracteres, só com colunas, literais, `IN`, `IS`, `NULL`, `NOT`, `AND`, `OR` e comparações (sem `BETWEEN`, `LIKE`, `CASE` nem funções). Todo `timestamp(6) NOT NULL` leva `DEFAULT CURRENT_TIMESTAMP(6)` (inclusive `scheduled_start_at`, `scheduled_end_at`, `checked_at`, `recorded_at`), e todo timestamp opcional é `timestamp(6) NULL DEFAULT NULL`. FKs `ON UPDATE RESTRICT ON DELETE RESTRICT`. Ampliação de CHECK em duas instruções (`DROP CHECK` e depois `ADD CONSTRAINT`). `provision.sh`: acrescentar a 0011 depois da linha 55 e trocar `0001..0010` por `0001..0011` no comentário da linha 10.

**Interface**
- Produz: `surgery_room(id, tenant_id, system_unit_id int, code varchar(30), name varchar(120), status varchar(20) DEFAULT 'active', created_at, updated_at)` com `surgery_room_unit_code_uq (tenant_id, system_unit_id, code)`, `surgery_room_status_ck` (`status IN ('active', 'inactive')`), FKs para `tenant` e `system_unit`
- Produz: `surgery(id, tenant_id, system_unit_id int, patient_id bigint unsigned, encounter_id bigint unsigned, room_id bigint unsigned, procedure_catalog_item_id bigint unsigned, procedure_name varchar(190), procedure_price_cents int unsigned, surgeon_system_user_id int, scheduled_by_system_user_id int, scheduled_start_at timestamp(6), scheduled_end_at timestamp(6), status varchar(20) DEFAULT 'scheduled', notes_text varchar(500) NULL, consent_signer_name varchar(190) NULL, consent_text text NULL, consent_recorded_at timestamp(6) NULL, consent_recorded_by_system_user_id int NULL, started_at timestamp(6) NULL, completed_at timestamp(6) NULL, completed_by_system_user_id int NULL, cancelled_at timestamp(6) NULL, cancelled_by_system_user_id int NULL, cancellation_reason_text varchar(500) NULL, followup_appointment_id bigint unsigned NULL, created_at, updated_at)` com `surgery_status_ck` (`status IN ('scheduled', 'pre_op', 'in_progress', 'completed', 'cancelled')`), `surgery_period_ck` (`scheduled_end_at > scheduled_start_at`), `surgery_consent_ck` (`(consent_recorded_at IS NULL AND consent_recorded_by_system_user_id IS NULL) OR (consent_recorded_at IS NOT NULL AND consent_recorded_by_system_user_id IS NOT NULL AND consent_signer_name IS NOT NULL AND consent_text IS NOT NULL)`), `surgery_started_ck` (`(status IN ('in_progress', 'completed') AND started_at IS NOT NULL) OR (status IN ('scheduled', 'pre_op', 'cancelled') AND started_at IS NULL)`), `surgery_completed_ck` (`(status = 'completed' AND completed_at IS NOT NULL AND completed_by_system_user_id IS NOT NULL) OR (status <> 'completed' AND completed_at IS NULL)`), `surgery_cancelled_ck` (`(status = 'cancelled' AND cancelled_at IS NOT NULL AND cancellation_reason_text IS NOT NULL) OR (status <> 'cancelled' AND cancelled_at IS NULL)`), índices `surgery_unit_start_idx (tenant_id, system_unit_id, scheduled_start_at)`, `surgery_room_start_idx (tenant_id, room_id, scheduled_start_at)`, `surgery_encounter_idx (tenant_id, encounter_id)`, FKs para `tenant`, `system_unit`, `patient`, `encounter`, `surgery_room`, `procedure_catalog_item`, `appointment` (`followup_appointment_id`) e `system_users` (5)
- Produz: `surgery_team(id, tenant_id, surgery_id, system_user_id int, role varchar(20), created_at)` com `surgery_team_member_uq (surgery_id, system_user_id, role)`, `surgery_team_role_ck` (`role IN ('surgeon', 'anesthetist', 'assistant', 'circulating')`), FKs para `tenant`, `surgery` e `system_users`
- Produz: `surgery_checklist(id, tenant_id, surgery_id, phase varchar(20), item_code varchar(40), checked_by_system_user_id int, checked_at timestamp(6), created_at)` com `surgery_checklist_item_uq (surgery_id, phase, item_code)`, `surgery_checklist_phase_ck` (`phase IN ('sign_in', 'time_out', 'sign_out')`), FKs para `tenant`, `surgery` e `system_users`
- Produz: `surgery_event(id, tenant_id, surgery_id, event_type varchar(30), recorded_by_system_user_id int, recorded_at timestamp(6), notes_text text NULL, created_at)` com `surgery_event_type_ck` (`event_type IN ('pre_op', 'anesthesia', 'intra_op', 'complication', 'post_op', 'scheduled', 'consent', 'checklist', 'status', 'material', 'cancellation', 'completion', 'followup')`), índice `surgery_event_surgery_recorded_idx (tenant_id, surgery_id, recorded_at)`, FKs para `tenant`, `surgery` e `system_users`
- Produz: `surgery_material(id, tenant_id, surgery_id, product_id bigint unsigned, quantity int unsigned, recorded_by_system_user_id int, recorded_at timestamp(6), created_at)` com `surgery_material_quantity_ck` (`quantity >= 1 AND quantity <= 9999`), índice `surgery_material_surgery_idx (tenant_id, surgery_id)`, FKs para `tenant`, `surgery`, `product` e `system_users`
- Produz: `ALTER TABLE encounter_account_item DROP CHECK encounter_account_item_source_type_ck` seguido de `ALTER TABLE encounter_account_item ADD CONSTRAINT encounter_account_item_source_type_ck CHECK (source_type IN ('procedure_execution', 'exam_request', 'manual', 'hospitalization_stay', 'hospitalization_administration', 'surgery_procedure', 'surgery_material'))`
- Produz: `ALTER TABLE stock_movement DROP CHECK stock_movement_reason_ck` seguido de `ALTER TABLE stock_movement ADD CONSTRAINT stock_movement_reason_ck CHECK (reason IN ('purchase_entry', 'procedure_consumption', 'sale_consumption', 'manual_adjustment', 'hospitalization_consumption', 'surgery_consumption'))`
- Consome: nada

**Teste RED**
- sem teste: migration SQL preparada e não executada (PREPARED ONLY); a estrutura é conferida por grep, pelo preparador 5.7 e, depois do bloqueio, pelo `.verify.sql` aplicado pelo orquestrador

**Critério de aceite**
- `grep -c "CREATE TABLE"` na 0011 imprime `6`; o arquivo contém `Status: PREPARED ONLY` e 64 zeros no `INSERT INTO schema_migrations`.
- O maior nome de CHECK tem no máximo 61 caracteres.
- O preparador 5.7 sobre a cópia `15-20261005_0011_phase6b_surgery.sql` imprime `Prepared` sem `Unsupported CHECK clause` e gera `DROP TRIGGER IF EXISTS` para os 2 CHECKs ampliados.
- `.verify.sql` só tem `SELECT` e lista as 6 tabelas, os 13 CHECKs (11 novos e 2 ampliados), as UNIQUEs e as contagens de `encounter_account_item` e `stock_movement`; toda consulta a `check_constraints` cita `table_name IN (...)` ou `table_name = '...'`.
- `scripts/test-db/provision.sh` lista `$migrations_dir/20261005_0011_phase6b_surgery.sql` depois da 0010.

**Validação**
- `/usr/bin/grep -c "CREATE TABLE" src/app/database/migrations/20261005_0011_phase6b_surgery.sql` (evidência: `6`)
- `/usr/bin/grep -oE "CONSTRAINT [a-z_]+_ck" src/app/database/migrations/20261005_0011_phase6b_surgery.sql | awk '{print length($2)}' | sort -n | tail -1` (evidência: número ≤ 61)
- `d=$(mktemp -d) && cp src/app/database/migrations/20261005_0011_phase6b_surgery.sql $d/15-20261005_0011_phase6b_surgery.sql && cp src/app/database/migrations/20261005_0011_phase6b_surgery.verify.sql $d/ && python3 scripts/prepare-mysql57.py $d $d/out && /usr/bin/grep -c "DROP TRIGGER IF EXISTS" $d/out/*.sql` (evidência: `Prepared` sem `Unsupported CHECK clause`; contagem ≥ 4)
- `/usr/bin/grep -ciE "^[[:space:]]*(insert|update|delete|alter|create|drop)" src/app/database/migrations/20261005_0011_phase6b_surgery.verify.sql` (evidência: `0`)
- `/usr/bin/grep -n "0011" scripts/test-db/provision.sh` (evidência: linha da 0011 depois da 0010)
- `python3 scripts/test-prepare-mysql57.py` (evidência: `Ran 8 tests`, igual a `baseline/test-prepare-mysql57.txt`)
- Depois do bloqueio (orquestrador): `.verify.sql` em `centralvet` e `centralvet_test` mostra as 6 tabelas e os 13 CHECKs; `SELECT COUNT(*) FROM encounter_account_item` e `SELECT COUNT(*) FROM stock_movement` iguais aos de antes (registros existentes preservados).

### T-02 — Domain de sala, cirurgia e equipe + contratos + exceção + tipos de conta e motivo de estoque

**Camada:** backend
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** alta
**Agente:** Platão

**Arquivos prováveis**
- `src/app/Core/Domain/SurgeryRoom.php`
- `src/app/Core/Domain/Surgery.php`
- `src/app/Core/Domain/SurgeryTeamMember.php`
- `src/app/Core/Domain/Contract/SurgeryRoomRepositoryInterface.php`
- `src/app/Core/Domain/Contract/SurgeryRepositoryInterface.php`
- `src/app/Core/Domain/Contract/SurgeryTeamRepositoryInterface.php`
- `src/app/Core/Domain/Exception/SurgeryRoomUnavailableException.php`
- `src/app/Core/Domain/EncounterAccountItem.php`
- `src/app/Core/Domain/StockMovement.php`
- `src/tests/Unit/SurgeryDomainTest.php`

**Notas de implementação**

Padrão de `Hospitalization.php`/`Bed.php`: `final class`, construtor privado, factory estática, `reconstitute(array $row)` com as chaves das colunas de T-01, `assignId(int)`, getters. Erro de entrada → `InvalidArgumentException` com mensagem crua em inglês; transição inválida → `InvalidStatusTransitionException`. As mensagens abaixo são literais (T-19 as mapeia em `UserMessage`). Os contratos estendem `CentralVet\Persistence\TenantRepositoryInterface` (`findById`, `save`, `remove`, `tenantId`). `loadedStatus()` devolve o status que veio de `reconstitute` (null em entidade nova) e não muda com as transições: é a base do UPDATE condicional de T-06.

**Interface**
- Produz: `SurgeryRoom::create(int $tenantId, int $systemUnitId, string $code, string $name): self` (code 1–30 após trim, name 1–120); `SurgeryRoom::STATUS_ACTIVE = 'active'`, `SurgeryRoom::STATUS_INACTIVE = 'inactive'`; `rename(string $name): void`, `activate(): void`, `deactivate(): void`; getters `id(): ?int`, `tenantId(): int`, `systemUnitId(): int`, `code(): string`, `name(): string`, `status(): string`, `isActive(): bool`
- Produz: `Surgery::schedule(int $tenantId, int $systemUnitId, int $patientId, int $encounterId, int $roomId, int $procedureCatalogItemId, string $procedureName, int $procedurePriceCents, int $surgeonSystemUserId, int $scheduledBySystemUserId, DateTimeImmutable $scheduledStartAt, DateTimeImmutable $scheduledEndAt, ?string $notesText): self` — fim ≤ início → `scheduled_end_at must be after scheduled_start_at`; mais de 24 h → `Surgery duration cannot exceed 24 hours`; notas > 500 → `notes_text must have at most 500 characters`; nome vazio → `procedure_name is required`; preço < 0 → `procedure_price_cents must be zero or positive`
- Produz: `Surgery::STATUS_SCHEDULED = 'scheduled'`, `Surgery::STATUS_PRE_OP = 'pre_op'`, `Surgery::STATUS_IN_PROGRESS = 'in_progress'`, `Surgery::STATUS_COMPLETED = 'completed'`, `Surgery::STATUS_CANCELLED = 'cancelled'`
- Produz: `recordConsent(string $signerName, string $consentText, int $recordedBySystemUserId, DateTimeImmutable $at): void` (só em `scheduled`/`pre_op`, senão `Surgery <id> is not open for pre-operative changes`; signatário vazio → `consent_signer_name is required`, > 190 → `consent_signer_name must have at most 190 characters`; texto vazio → `consent_text is required`; regravar substitui); `startPreOp(): void` (`scheduled` → `pre_op`, senão `Surgery <id> is not scheduled`); `start(DateTimeImmutable $at): void` (`pre_op` → `in_progress`, senão `Surgery <id> is not in pre-op`; sem consentimento → `Surgery <id> has no recorded consent`); `complete(DateTimeImmutable $at, int $completedBySystemUserId): void` (`in_progress` → `completed`, senão `Surgery <id> is not in progress`); `cancel(DateTimeImmutable $at, int $cancelledBySystemUserId, string $reasonText): void` (só `scheduled`/`pre_op`, senão `Surgery <id> cannot be cancelled in its current status`; motivo vazio → `cancellation_reason_text is required`); `linkFollowUp(int $appointmentId): void` (fora de `completed` → `Surgery <id> is not completed`; já ligado → `Surgery <id> already has a follow-up appointment`); `loadedStatus(): ?string`; `isOpenForPreOp(): bool`; `hasConsent(): bool`
- Produz: getters de `Surgery`: `id(): ?int`, `tenantId(): int`, `systemUnitId(): int`, `patientId(): int`, `encounterId(): int`, `roomId(): int`, `procedureCatalogItemId(): int`, `procedureName(): string`, `procedurePriceCents(): int`, `surgeonSystemUserId(): int`, `scheduledStartAt(): DateTimeImmutable`, `scheduledEndAt(): DateTimeImmutable`, `status(): string`, `notesText(): ?string`, `consentSignerName(): ?string`, `consentText(): ?string`, `consentRecordedAt(): ?DateTimeImmutable`, `startedAt(): ?DateTimeImmutable`, `completedAt(): ?DateTimeImmutable`, `cancelledAt(): ?DateTimeImmutable`, `cancellationReasonText(): ?string`, `followupAppointmentId(): ?int`
- Produz: `SurgeryTeamMember::create(int $tenantId, int $surgeryId, int $systemUserId, string $role): self`; `SurgeryTeamMember::ROLE_SURGEON = 'surgeon'`, `SurgeryTeamMember::ROLE_ANESTHETIST = 'anesthetist'`, `SurgeryTeamMember::ROLE_ASSISTANT = 'assistant'`, `SurgeryTeamMember::ROLE_CIRCULATING = 'circulating'`, `SurgeryTeamMember::ROLES`; papel desconhecido → `Unknown team role "<role>"`; getters `surgeryId(): int`, `systemUserId(): int`, `role(): string`
- Produz: `SurgeryRoomUnavailableException::booked(int $roomId): self` (mensagem `Surgery room <id> is already booked for this period`) e `SurgeryRoomUnavailableException::inactive(int $roomId): self` (mensagem `Surgery room <id> is not active`), `extends \DomainException`
- Produz: `SurgeryRoomRepositoryInterface` com `listByUnit(int $systemUnitId): array`, `findByCode(int $systemUnitId, string $code): ?object`, `lockForScheduling(int $roomId): bool` (trava a linha da sala do tenant; false se não existe)
- Produz: `SurgeryRepositoryInterface` com `listByUnitAndDay(int $systemUnitId, DateTimeImmutable $day): array` (início no dia, ordem `scheduled_start_at, id`), `hasOverlapInRoom(int $roomId, DateTimeImmutable $startAt, DateTimeImmutable $endAt, ?int $exceptSurgeryId): bool` (status `scheduled`/`pre_op`/`in_progress`, `start < endAt AND end > startAt`), `lockStatus(int $surgeryId): ?string` (status atual com trava; null se não existe); `save` de cirurgia existente só grava se o status no banco é `loadedStatus()`, senão `InvalidStatusTransitionException` com `Surgery <id> changed status concurrently`
- Produz: `SurgeryTeamRepositoryInterface` com `listBySurgery(int $surgeryId): array` e `replaceForSurgery(int $surgeryId, array $members): void`
- Produz: `EncounterAccountItem::TYPE_SURGERY_PROCEDURE = 'surgery_procedure'`, `EncounterAccountItem::TYPE_SURGERY_MATERIAL = 'surgery_material'` (incluídos em `TYPES`, `source_id` positivo obrigatório)
- Produz: `StockMovement::REASON_SURGERY_CONSUMPTION = 'surgery_consumption'` (incluído em `REASONS`)
- Consome: nada (segue o schema de T-01 § Interface, mesma onda)

**Teste RED**
- `src/tests/Unit/SurgeryDomainTest.php` — cirurgia agendada 2026-10-10 08:00–10:00: `start()` em `scheduled` lança `Surgery <id> is not in pre-op`; depois de `startPreOp()` sem consentimento lança `Surgery <id> has no recorded consent`; com consentimento passa a `in_progress`; `cancel()` em `in_progress` lança `cannot be cancelled`; `loadedStatus()` de `reconstitute` com `status=pre_op` continua `pre_op` após `start()`; fim antes do início lança `scheduled_end_at must be after scheduled_start_at`; `EncounterAccountItem::create(..., 'surgery_material', 7, ...)` é aceito. Falha hoje porque as classes e os tipos não existem (comando: `SUITE | /usr/bin/grep -E 'SurgeryDomainTest|Failed:'`)

**Critério de aceite**
- A SUITE lista `SurgeryDomainTest` com todos os métodos `PASS` e `Failed: 0`; `EncounterAccountServiceTest`, `StockServiceTest`, `HospitalizationDomainTest` e `CoreLayerDependencyTest` seguem `PASS`.
- Cada PHP tocado imprime `No syntax errors detected` no LINT (nenhum erro novo em relação a `baseline/php-lint.txt`).

**Validação**
- `SUITE | /usr/bin/grep -E 'SurgeryDomainTest|EncounterAccountServiceTest|StockServiceTest|HospitalizationDomainTest|CoreLayerDependencyTest|Failed:'` (evidência: `PASS` em todos e `Failed: 0`)
- LINT em cada arquivo de "Arquivos prováveis" (evidência: `No syntax errors detected` em todos)

### T-03 — Domain de checklist (3 fases), evento e material + contratos

**Camada:** backend
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Arquimedes

**Arquivos prováveis**
- `src/app/Core/Domain/SurgeryChecklist.php`
- `src/app/Core/Domain/SurgeryChecklistItem.php`
- `src/app/Core/Domain/SurgeryEvent.php`
- `src/app/Core/Domain/SurgeryMaterial.php`
- `src/app/Core/Domain/Contract/SurgeryChecklistRepositoryInterface.php`
- `src/app/Core/Domain/Contract/SurgeryEventRepositoryInterface.php`
- `src/app/Core/Domain/Contract/SurgeryMaterialRepositoryInterface.php`
- `src/tests/Unit/SurgeryChecklistDomainTest.php`

**Notas de implementação**

Mesmo padrão de T-02 (factory, `reconstitute`, `assignId`, mensagens literais em inglês). `SurgeryChecklist` é uma classe final só com constantes e métodos estáticos (catálogo fixo; sem banco). Códigos e rótulos abaixo são o contrato com T-16 (tela) e T-19 (traduções).

**Interface**
- Produz: `SurgeryChecklist::PHASE_SIGN_IN = 'sign_in'`, `SurgeryChecklist::PHASE_TIME_OUT = 'time_out'`, `SurgeryChecklist::PHASE_SIGN_OUT = 'sign_out'`, `SurgeryChecklist::PHASES` (nessa ordem)
- Produz: `SurgeryChecklist::items(string $phase): array` (lista de códigos): `sign_in` → `patient_identity_confirmed`, `consent_confirmed`, `fasting_confirmed`, `anesthesia_equipment_checked`, `allergies_reviewed`; `time_out` → `team_introduced`, `procedure_and_site_confirmed`, `antibiotic_prophylaxis_reviewed`, `critical_steps_reviewed`; `sign_out` → `procedure_recorded`, `instrument_count_correct`, `specimens_labeled`, `recovery_plan_defined`; fase desconhecida → `Unknown checklist phase "<phase>"`
- Produz: `SurgeryChecklist::label(string $itemCode): string` com os rótulos em inglês, na mesma ordem: `Patient identity confirmed`, `Consent confirmed`, `Fasting confirmed`, `Anesthesia equipment checked`, `Allergies reviewed`, `Team introduced`, `Procedure and site confirmed`, `Antibiotic prophylaxis reviewed`, `Critical steps reviewed`, `Procedure recorded`, `Instrument count correct`, `Specimens labeled`, `Recovery plan defined`; e `SurgeryChecklist::phaseLabel(string $phase): string` → `Before induction`, `Before incision`, `Before leaving the room`; código desconhecido → `Unknown checklist item "<code>"`
- Produz: `SurgeryChecklist::assertComplete(string $phase, array $checkedItemCodes): void` — falta item → `All checklist items of phase "<phase>" must be checked`; código fora da fase → `Unknown checklist item "<code>"`
- Produz: `SurgeryChecklistItem::check(int $tenantId, int $surgeryId, string $phase, string $itemCode, int $checkedBySystemUserId, DateTimeImmutable $checkedAt): self`; getters `surgeryId(): int`, `phase(): string`, `itemCode(): string`, `checkedBySystemUserId(): int`, `checkedAt(): DateTimeImmutable`
- Produz: `SurgeryEvent::record(int $tenantId, int $surgeryId, string $eventType, int $recordedBySystemUserId, DateTimeImmutable $recordedAt, ?string $notesText): self`; `SurgeryEvent::TYPE_PRE_OP = 'pre_op'`, `TYPE_ANESTHESIA = 'anesthesia'`, `TYPE_INTRA_OP = 'intra_op'`, `TYPE_COMPLICATION = 'complication'`, `TYPE_POST_OP = 'post_op'`, `TYPE_SCHEDULED = 'scheduled'`, `TYPE_CONSENT = 'consent'`, `TYPE_CHECKLIST = 'checklist'`, `TYPE_STATUS = 'status'`, `TYPE_MATERIAL = 'material'`, `TYPE_CANCELLATION = 'cancellation'`, `TYPE_COMPLETION = 'completion'`, `TYPE_FOLLOWUP = 'followup'`; `SurgeryEvent::CLINICAL_TYPES` = `['pre_op', 'anesthesia', 'intra_op', 'complication', 'post_op']`; tipo clínico sem texto → `notes_text is required`; texto > 5000 → `notes_text must have at most 5000 characters`; tipo desconhecido → `Unknown surgery event type "<type>"`; getters `surgeryId(): int`, `eventType(): string`, `recordedBySystemUserId(): int`, `recordedAt(): DateTimeImmutable`, `notesText(): ?string`
- Produz: `SurgeryMaterial::record(int $tenantId, int $surgeryId, int $productId, int $quantity, int $recordedBySystemUserId, DateTimeImmutable $recordedAt): self`; quantidade fora de 1–9999 → `quantity must be between 1 and 9999`; getters `id(): ?int`, `surgeryId(): int`, `productId(): int`, `quantity(): int`, `recordedAt(): DateTimeImmutable`
- Produz: `SurgeryChecklistRepositoryInterface` com `listBySurgery(int $surgeryId): array`; `save` de item já existente (mesma cirurgia, fase e código) → `InvalidStatusTransitionException` com `Checklist phase "<phase>" is already confirmed for surgery <id>`
- Produz: `SurgeryEventRepositoryInterface` com `listBySurgery(int $surgeryId): array` (mais recente primeiro; `remove` lança `LogicException`)
- Produz: `SurgeryMaterialRepositoryInterface` com `listBySurgery(int $surgeryId): array` (ordem `recorded_at, id`)
- Consome: nada (segue o schema de T-01 § Interface, mesma onda)

**Teste RED**
- `src/tests/Unit/SurgeryChecklistDomainTest.php` — `SurgeryChecklist::items('time_out')` devolve os 4 códigos na ordem do contrato; `assertComplete('sign_in', ['patient_identity_confirmed'])` lança `All checklist items of phase "sign_in" must be checked`; `label('instrument_count_correct')` = `Instrument count correct`; `SurgeryEvent::record(..., 'complication', ..., '')` lança `notes_text is required`; `SurgeryMaterial::record(..., 0, ...)` lança `quantity must be between 1 and 9999`. Falha hoje porque as classes não existem (comando: `SUITE | /usr/bin/grep -E 'SurgeryChecklistDomainTest|Failed:'`)

**Critério de aceite**
- A SUITE lista `SurgeryChecklistDomainTest` com todos os métodos `PASS` e `Failed: 0`; `CoreLayerDependencyTest` segue `PASS`.
- Cada PHP tocado imprime `No syntax errors detected` no LINT.

**Validação**
- `SUITE | /usr/bin/grep -E 'SurgeryChecklistDomainTest|CoreLayerDependencyTest|Failed:'` (evidência: `PASS` e `Failed: 0`)
- LINT em cada arquivo de "Arquivos prováveis" (evidência: `No syntax errors detected` em todos)

### T-04 — Programas RBAC das 9 telas e grupo `Clínico – Cirurgia` (seed + DML, verify e rollback preparados)

**Camada:** infra
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Jaspion

**Arquivos prováveis**
- `src/app/database/seeds/initial-application-programs.sql`
- `.claude/tasks/mar-20261005-2234-fase-6b-cirurgia/sql/T-04-programs.sql`
- `.claude/tasks/mar-20261005-2234-fase-6b-cirurgia/sql/T-04-programs.verify.sql`
- `.claude/tasks/mar-20261005-2234-fase-6b-cirurgia/sql/T-04-programs.rollback.sql`

**Notas de implementação**

Decisão do usuário (notes.md § Decisões tomadas): grupo novo `Clínico – Cirurgia` com os 9 programas, além do grupo 1; o grupo 4 `Clínico – Internação` e os grupos 2 e 3 não recebem nada. Modelos: bloco "Fase 6A" do seed (linhas 422-557) e `.claude/tasks/mar-20261005-1521-fase-6a-internacao/sql/T-05-programs.sql`, `.verify.sql` e `.rollback.sql`.

Estado de referência (6A, `notes.md` da 6A § Bloqueios, só SELECT): `system_group` 4 (`id int NOT NULL` sem AUTO_INCREMENT; 4 = `Clínico – Internação`), `system_program` 117, `system_group_program` 127. A sessão padrão do cliente `mysql` usa `character_set_client=latin1` (encoding duplo da rodada 2).

Regras dos três arquivos SQL:
- **`SET NAMES utf8mb4;`** é a primeira instrução; o orquestrador aplica com `--default-character-set=utf8mb4`.
- **Sem id fixo.** Cada INSERT deriva o id no próprio comando, `INSERT INTO t (id, ...) SELECT (SELECT COALESCE(MAX(id),0)+1 FROM t cur), ... FROM DUAL WHERE NOT EXISTS (...)`, uma linha por comando (idempotente; 5.7: sem `ROW_NUMBER`, CTE nem variável de sessão para id).
- **Nome do grupo.** Exatamente `Clínico – Cirurgia` (í, travessão U+2013), 18 caracteres e 21 bytes em utf8mb4, localizado sempre por `name = 'Clínico – Cirurgia'` (id nunca literal).
- **Nomes de programas** ASCII em inglês.

`T-04-programs.sql` em `START TRANSACTION`:
1. INSERT do grupo `Clínico – Cirurgia` em `system_group` se não existir.
2. 9 INSERTs em `system_program`.
3. 9 concessões ao grupo 1 e 9 ao grupo novo em `system_group_program` (id do grupo por `SELECT id FROM system_group WHERE name = 'Clínico – Cirurgia'`).
4. SELECTs de conferência e `COMMIT`.

`T-04-programs.verify.sql` (só SELECT):
- o grupo novo existe uma vez, com `CHAR_LENGTH(name) = 18` e `LENGTH(name) = 21`;
- 9 controllers em `system_program`;
- concessões por grupo para os 9 controllers: grupo 1 = 9, `Clínico – Cirurgia` = 9, `Clínico – Internação` = 0, grupos 2 e 3 = 0;
- `COUNT(*)` de `system_program`, `system_group` e `system_group_program`.

`T-04-programs.rollback.sql` (preparado, nunca executado sem nova aprovação), em `START TRANSACTION`: SELECT prévio de concessões fora dos grupos 1 e novo; DELETE em `system_group_program` das linhas dos 9 controllers; DELETE em `system_user_group` do grupo novo; DELETE das concessões restantes do grupo novo; DELETE em `system_user_program` e `system_program_method_role` dos 9 controllers; DELETE do grupo novo em `system_group`; DELETE dos 9 controllers em `system_program`; SELECTs e `COMMIT` comentado. Todo DELETE tem `WHERE` por nome de controller ou de grupo; o preferido continua sendo restaurar o backup do bloqueio.

O seed (instalação nova) recebe o mesmo grupo, programas e concessões, de forma idempotente, dentro da transação existente.

**Interface**
- Produz: grupo `system_group.name = 'Clínico – Cirurgia'` (id derivado de `MAX(id)+1` na aplicação)
- Produz: programas `SurgeryRoomList` (`Central Vet - Surgery Room List`), `SurgeryRoomForm` (`Central Vet - Surgery Room Form`), `SurgeryList` (`Central Vet - Surgery List`), `SurgeryScheduleForm` (`Central Vet - Surgery Schedule Form`), `SurgeryView` (`Central Vet - Surgery View`), `SurgeryConsentForm` (`Central Vet - Surgery Consent Form`), `SurgeryEventForm` (`Central Vet - Surgery Event Form`), `SurgeryChecklistForm` (`Central Vet - Surgery Checklist Form`), `SurgeryMaterialForm` (`Central Vet - Surgery Material Form`), cada um em `system_group_program` do grupo 1 e do grupo `Clínico – Cirurgia`, e em nenhum outro
- Consome: nada

**Teste RED**
- sem teste: DML SQL preparada (grupo, programas, verify e rollback), sem execução; conferida por grep e, depois do bloqueio, pelo verify do orquestrador

**Critério de aceite**
- `/usr/bin/grep -oE "controller='Surgery[A-Za-z]*'" src/app/database/seeds/initial-application-programs.sql | sort -u | wc -l` imprime `9`; o seed contém `Clínico – Cirurgia` e segue numa transação só.
- Os três arquivos de `sql/` começam com `SET NAMES utf8mb4;`; `T-04-programs.sql` não tem id numérico literal nos INSERTs nem `ROW_NUMBER`/`WITH`, cria o grupo com `WHERE NOT EXISTS` e 9 concessões ao grupo 1 e 9 ao grupo localizado por `name = 'Clínico – Cirurgia'`.
- Nenhuma concessão aos grupos 2, 3 e `Clínico – Internação`; `T-04-programs.rollback.sql` não tem DELETE sem `WHERE` e cobre `system_group_program`, `system_user_group`, `system_group` e `system_program`.
- Depois do bloqueio (orquestrador), o verify mostra 9 concessões no grupo 1 e 9 em `Clínico – Cirurgia`, 0 em `Clínico – Internação` e nos grupos 2 e 3, o grupo com `CHAR_LENGTH(name) = 18` e `LENGTH(name) = 21`, e `COUNT(*)` de `system_program` = anterior + 9, `system_group` = anterior + 1 e `system_group_program` = anterior + 18, com os registros existentes preservados.

**Validação**
- `/usr/bin/grep -oE "controller='Surgery[A-Za-z]*'" src/app/database/seeds/initial-application-programs.sql | sort -u | wc -l` (evidência: `9`)
- `cd /var/www/html/centralvet/.claude/tasks/mar-20261005-2234-fase-6b-cirurgia && for f in sql/T-04-programs.sql sql/T-04-programs.verify.sql sql/T-04-programs.rollback.sql; do /usr/bin/grep -v '^--' $f | /usr/bin/grep -m1 -v '^[[:space:]]*$'; done` (evidência: três linhas `SET NAMES utf8mb4;`)
- `/usr/bin/grep -ciE "row_number|with recursive|values *\( *[0-9]{2,}" .claude/tasks/mar-20261005-2234-fase-6b-cirurgia/sql/T-04-programs.sql` (evidência: `0`)
- `/usr/bin/grep -c "Clínico – Internação" .claude/tasks/mar-20261005-2234-fase-6b-cirurgia/sql/T-04-programs.sql` (evidência: `0`, salvo em comentário de conferência)
- `/usr/bin/grep -ciE "delete from [a-z_]+ *;" .claude/tasks/mar-20261005-2234-fase-6b-cirurgia/sql/T-04-programs.rollback.sql` (evidência: `0`)
- Depois do bloqueio (orquestrador): `docker compose exec -T mysql sh -c 'mysql --default-character-set=utf8mb4 -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" centralvet' < .claude/tasks/mar-20261005-2234-fase-6b-cirurgia/sql/T-04-programs.verify.sql` (evidência: grupo 1 = 9, `Clínico – Cirurgia` = 9, `Clínico – Internação`/2/3 = 0, `CHAR_LENGTH` 18 e `LENGTH` 21, contagens = anterior + 9 / + 1 / + 18)
- Prova de acesso: não há usuário no grupo novo; a verificação é por banco, pelo verify acima, e os gates navegam como `admin` (grupo 1).

### T-05 — Fakes dos 6 repositórios da cirurgia

**Camada:** shared
**Dependências:** T-02, T-03
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Platão

**Arquivos prováveis**
- `src/tests/Support/FakeSurgeryRoomRepository.php`
- `src/tests/Support/FakeSurgeryRepository.php`
- `src/tests/Support/FakeSurgeryTeamRepository.php`
- `src/tests/Support/FakeSurgeryChecklistRepository.php`
- `src/tests/Support/FakeSurgeryEventRepository.php`
- `src/tests/Support/FakeSurgeryMaterialRepository.php`
- `src/tests/Unit/SurgeryFakesTest.php`

**Notas de implementação**

Padrão dos fakes da 6A (`FakeHospitalizationRepository`, `FakeBedRepository`): construtor `(int $tenantId, <Entidade> ...$seed)`, `public int $saveCount` zerado depois do seed, ids sequenciais via `assignId`, entidade de outro tenant invisível em `findById`. Os fakes espelham as regras do PDO de T-06 que os services dependem: save condicional, sobreposição, duplicidade de checklist, ordenações.

**Interface**
- Produz: `new FakeSurgeryRoomRepository(int $tenantId, SurgeryRoom ...$seed)`, `new FakeSurgeryRepository(int $tenantId, Surgery ...$seed)`, `new FakeSurgeryTeamRepository(int $tenantId)`, `new FakeSurgeryChecklistRepository(int $tenantId)`, `new FakeSurgeryEventRepository(int $tenantId)`, `new FakeSurgeryMaterialRepository(int $tenantId, SurgeryMaterial ...$seed)`, todos com `public int $saveCount`
- Produz: `FakeSurgeryRepository::forceStatus(int $surgeryId, string $status): void` (simula outra aba mudando o status no banco: o próximo `save` da entidade carregada antes lança `Surgery <id> changed status concurrently`, e `lockStatus` devolve o status forçado)
- Produz: `FakeSurgeryRoomRepository::$lockCalls` (`public int`, incrementado em `lockForScheduling`)
- Consome: T-02 `SurgeryRepositoryInterface`, T-02 `lockStatus(int $surgeryId): ?string`, T-02 `hasOverlapInRoom(int $roomId, DateTimeImmutable $startAt, DateTimeImmutable $endAt, ?int $exceptSurgeryId): bool`, T-03 `SurgeryChecklistRepositoryInterface`, T-03 `listBySurgery(int $surgeryId): array`

**Teste RED**
- `src/tests/Unit/SurgeryFakesTest.php` — duas cirurgias na sala 1 (08:00–10:00 e `hasOverlapInRoom(1, 09:00, 11:00, null)`) → true, e false com a primeira cancelada; `forceStatus(id, 'cancelled')` faz o `save` de uma cópia `pre_op` lançar `changed status concurrently`; segundo `save` do mesmo item de checklist lança `is already confirmed`; `listBySurgery` de eventos devolve o mais recente primeiro. Falha hoje porque os fakes não existem (comando: `SUITE | /usr/bin/grep -E 'SurgeryFakesTest|Failed:'`)

**Critério de aceite**
- A SUITE lista `SurgeryFakesTest` `PASS` e `Failed: 0`.
- Cada PHP tocado imprime `No syntax errors detected` no LINT.

**Validação**
- `SUITE | /usr/bin/grep -E 'SurgeryFakesTest|Failed:'` (evidência: `PASS` e `Failed: 0`)
- LINT nos 7 arquivos (evidência: `No syntax errors detected`)

### T-06 — Repositórios PDO (trava de sala, trava de status, UPDATE condicional) + integração

**Camada:** backend
**Dependências:** T-01, T-02, T-03
**Paralelizável:** sim
**Complexidade:** alta
**Agente:** Athena

**Arquivos prováveis**
- `src/app/Core/Persistence/SurgeryRoomRepository.php`
- `src/app/Core/Persistence/SurgeryRepository.php`
- `src/app/Core/Persistence/SurgeryTeamRepository.php`
- `src/app/Core/Persistence/SurgeryChecklistRepository.php`
- `src/app/Core/Persistence/SurgeryEventRepository.php`
- `src/app/Core/Persistence/SurgeryMaterialRepository.php`
- `src/tests/Integration/SurgeryRepositoryIntegrationTest.php`

**Notas de implementação**

Padrão de `HospitalizationRepository.php`/`BedRepository.php` (`extends AbstractTenantRepository`, `tenantQuery()`, `assertEntityTenant()`), construtor `(TenantContext $context, PDO $connection)`. Regras:
- `SurgeryRepository::save` existente: `UPDATE surgery SET ... WHERE <tenantQuery> AND id = ? AND status = <loadedStatus>`; com `rowCount() === 0`, reconfere com `SELECT status ... FOR UPDATE` (MySQL devolve 0 quando os valores não mudam): status diferente de `loadedStatus` → `InvalidStatusTransitionException` `Surgery <id> changed status concurrently`.
- `lockStatus`: `SELECT status FROM surgery WHERE tenant_id = ? AND id = ? FOR UPDATE`.
- `SurgeryRoomRepository::lockForScheduling`: `SELECT id FROM surgery_room WHERE tenant_id = ? AND id = ? FOR UPDATE`.
- `hasOverlapInRoom`: `scheduled_start_at < :end AND scheduled_end_at > :start AND status IN ('scheduled','pre_op','in_progress')`, excluindo `id = :except`.
- `SurgeryChecklistRepository::save`: INSERT; `PDOException` de SQLSTATE 23000 na UNIQUE `surgery_checklist_item_uq` vira `InvalidStatusTransitionException` `Checklist phase "<phase>" is already confirmed for surgery <id>`.
- `SurgeryEventRepository`: só INSERT (`remove` lança `LogicException`).
- `SurgeryTeamRepository::replaceForSurgery`: `DELETE ... WHERE tenant_id = ? AND surgery_id = ?` + INSERTs.
- Datas em `Y-m-d H:i:s.u`; todo SELECT/UPDATE/DELETE parte de `tenantQuery()`.

**Interface**
- Produz: `new SurgeryRoomRepository(TenantContext $context, PDO $connection)`, `new SurgeryRepository(TenantContext $context, PDO $connection)`, `new SurgeryTeamRepository(TenantContext $context, PDO $connection)`, `new SurgeryChecklistRepository(TenantContext $context, PDO $connection)`, `new SurgeryEventRepository(TenantContext $context, PDO $connection)`, `new SurgeryMaterialRepository(TenantContext $context, PDO $connection)` (namespace `CentralVet\Persistence`)
- Consome: T-02 `lockStatus(int $surgeryId): ?string`, T-02 `lockForScheduling(int $roomId): bool`, T-02 `replaceForSurgery(int $surgeryId, array $members): void`, T-03 `listBySurgery(int $surgeryId): array`

**Teste RED**
- `src/tests/Integration/SurgeryRepositoryIntegrationTest.php` — no `centralvet_test` (`MysqlIntegrationTestCase`, rollback no `tearDown`): sala + cirurgia salvas e relidas com todos os campos; `hasOverlapInRoom` true para intervalo sobreposto e false depois de cancelar; save de cópia `pre_op` depois de outra cópia gravar `cancelled` lança `changed status concurrently`; segundo INSERT do mesmo item de checklist lança `is already confirmed`; cirurgia de outro tenant não aparece em `findById`. Falha hoje porque os repositórios não existem (comando: `SUITE | /usr/bin/grep -E 'SurgeryRepositoryIntegrationTest|Failed:'`)

**Critério de aceite**
- A SUITE lista `SurgeryRepositoryIntegrationTest` com todos os métodos `PASS` (não `SKIP`) e `Failed: 0`; `HospitalizationRepositoryIntegrationTest` segue `PASS`.
- `/usr/bin/grep -c "tenantQuery" ` em cada repositório novo imprime ≥ 1.
- Cada PHP tocado imprime `No syntax errors detected` no LINT.

**Validação**
- `SUITE | /usr/bin/grep -E 'SurgeryRepositoryIntegrationTest|HospitalizationRepositoryIntegrationTest|Failed:'` (evidência: `PASS`, nenhum `SKIP` da classe nova, `Failed: 0`)
- `/usr/bin/grep -c "tenantQuery" src/app/Core/Persistence/Surgery*Repository.php` (evidência: ≥ 1 por arquivo)
- LINT nos 7 arquivos (evidência: `No syntax errors detected`)

### T-07 — `SurgeryRoomService` — cadastro de salas por unidade

**Camada:** backend
**Dependências:** T-02, T-05
**Paralelizável:** sim
**Complexidade:** simples
**Agente:** Jaspion

**Arquivos prováveis**
- `src/app/Core/Application/SurgeryRoomService.php`
- `src/tests/Unit/SurgeryRoomServiceTest.php`

**Notas de implementação**

Espelho de `BedService.php`: a unidade vem de `TenantContext::requireUnitId()`; autoriza com `requiresUnitScope: true`, `resourceUnitId` da sala persistida (create: unidade do contexto), `entityType: 'surgery_room'`. Sala inexistente/de outro tenant → `CrossTenantReferenceException` `room_id <id> was not found for the authenticated tenant`.

**Interface**
- Produz: `SurgeryRoomService::__construct(SurgeryRoomRepositoryInterface $rooms, AuthorizationPolicyInterface $authorization, TenantContext $context)`
- Produz: `create(string $code, string $name, string $action): SurgeryRoom` (código repetido na unidade → `A surgery room with code "<code>" already exists in this unit`), `update(int $roomId, string $name, string $action): SurgeryRoom`, `deactivate(int $roomId, string $action): SurgeryRoom`, `activate(int $roomId, string $action): SurgeryRoom`, `get(int $roomId, string $action): SurgeryRoom`, `listForCurrentUnit(string $action): array`, `listActiveForUnit(int $systemUnitId, string $action): array`
- Consome: T-02 `SurgeryRoom::create(int $tenantId, int $systemUnitId, string $code, string $name): self`, T-02 `findByCode(int $systemUnitId, string $code): ?object`, T-02 `listByUnit(int $systemUnitId): array`

**Teste RED**
- `src/tests/Unit/SurgeryRoomServiceTest.php` — `create('F6B-1', 'Sala 1', ...)` grava sala ativa na unidade do contexto; repetir o código lança `A surgery room with code "F6B-1" already exists in this unit`; `listActiveForUnit` omite a sala desativada; política negando lança `AuthorizationDenied` sem gravar (`saveCount` 0). Falha hoje porque o service não existe (comando: `SUITE | /usr/bin/grep -E 'SurgeryRoomServiceTest|Failed:'`)

**Critério de aceite**
- A SUITE lista `SurgeryRoomServiceTest` `PASS` e `Failed: 0`.
- Cada PHP tocado imprime `No syntax errors detected` no LINT.

**Validação**
- `SUITE | /usr/bin/grep -E 'SurgeryRoomServiceTest|Failed:'` (evidência: `PASS` e `Failed: 0`)
- LINT nos 2 arquivos (evidência: `No syntax errors detected`)

### T-08 — `SurgeryService` — agendamento, equipe, consentimento, pré-op, início, cancelamento, eventos

**Camada:** backend
**Dependências:** T-02, T-03, T-05
**Paralelizável:** sim
**Complexidade:** alta
**Agente:** Athena

**Arquivos prováveis**
- `src/app/Core/Application/SurgeryService.php`
- `src/tests/Unit/SurgeryServiceTest.php`

**Notas de implementação**

Modelo: `HospitalizationService.php` (construtor com `?Closure $clock = null` por último; `authorize` com `requiresUnitScope: true`, `resourceUnitId` = `system_unit_id` do atendimento no agendamento e da cirurgia persistida depois; `entityType: 'surgery'`). Ordem do `schedule`:
1. Atendimento do tenant (senão `CrossTenantReferenceException` `encounter_id <id> was not found for the authenticated tenant`) e autorização na unidade dele.
2. Conta do atendimento, se existir, aberta (mensagem `Encounter account <id> cannot be modified: status is "<status>", not "open"`, igual ao `EncounterAccountService`).
3. Procedimento ativo do tenant (senão `procedure_catalog_item_id <id> was not found for the authenticated tenant`); nome e `priceCents()` copiados.
4. `durationMinutes` em 15–1440 (senão `duration_minutes must be between 15 and 1440`); fim = início + duração.
5. Cirurgião ativo no tenant (`surgeon_system_user_id must be an active user of this tenant`) e cada membro (`Team member <id> must be an active user of this tenant`); papel desconhecido → mensagem de T-02; o cirurgião entra com papel `surgeon` se não vier na lista; pares repetidos são descartados.
6. `lockForScheduling(roomId)` (false → `room_id <id> was not found for the authenticated tenant`); sala de outra unidade → `Surgery room <id> belongs to another unit`; inativa → `SurgeryRoomUnavailableException::inactive`; `hasOverlapInRoom` → `SurgeryRoomUnavailableException::booked`.
7. `save`, `replaceForSurgery` e evento `scheduled`.

`start` exige, além do consentimento (Domain), as fases `sign_in` e `time_out` completas em `listBySurgery` do checklist (todos os códigos de `SurgeryChecklist::items`), senão `InvalidStatusTransitionException` `Checklist phase "<phase>" is not confirmed for surgery <id>`. Cada transição grava evento `status` (notas = novo status) e cancelamento grava `cancellation` (notas = motivo). `recordClinicalEvent` aceita só `SurgeryEvent::CLINICAL_TYPES` (senão `Unknown surgery event type "<type>"`) e recusa cirurgia cancelada com `Surgery <id> is cancelled`. `replaceTeam` só com `isOpenForPreOp()`, senão `Surgery <id> is not open for pre-operative changes`.

**Interface**
- Produz: `SurgeryService::__construct(SurgeryRepositoryInterface $surgeries, SurgeryRoomRepositoryInterface $rooms, SurgeryTeamRepositoryInterface $team, SurgeryChecklistRepositoryInterface $checklist, SurgeryEventRepositoryInterface $events, EncounterRepositoryInterface $encounters, EncounterAccountRepositoryInterface $accounts, ProcedureCatalogRepositoryInterface $procedures, TenantUserDirectoryInterface $users, AuthorizationPolicyInterface $authorization, TenantContext $context, ?Closure $clock = null)`
- Produz: `schedule(int $encounterId, int $roomId, int $procedureCatalogItemId, int $surgeonSystemUserId, DateTimeImmutable $scheduledStartAt, int $durationMinutes, array $team, ?string $notesText, string $action): Surgery` com `$team` = `list<array{system_user_id: int, role: string}>`
- Produz: `replaceTeam(int $surgeryId, array $team, string $action): array`, `recordConsent(int $surgeryId, string $signerName, string $consentText, string $action): Surgery`, `startPreOp(int $surgeryId, string $action): Surgery`, `start(int $surgeryId, string $action): Surgery`, `cancel(int $surgeryId, string $reasonText, string $action): Surgery`, `recordClinicalEvent(int $surgeryId, string $eventType, string $notesText, string $action): SurgeryEvent`
- Produz: `get(int $surgeryId, string $action): Surgery`, `listTeam(int $surgeryId, string $action): array`, `listEvents(int $surgeryId, string $action): array`, `listForDay(DateTimeImmutable $day, string $action): array` (unidade do contexto), `listProcedures(string $action): array` (`ProcedureCatalogItem` ativos)
- Consome: T-02 `Surgery::schedule(int $tenantId, int $systemUnitId, int $patientId, int $encounterId, int $roomId, int $procedureCatalogItemId, string $procedureName, int $procedurePriceCents, int $surgeonSystemUserId, int $scheduledBySystemUserId, DateTimeImmutable $scheduledStartAt, DateTimeImmutable $scheduledEndAt, ?string $notesText): self`, T-02 `lockForScheduling(int $roomId): bool`, T-02 `hasOverlapInRoom(int $roomId, DateTimeImmutable $startAt, DateTimeImmutable $endAt, ?int $exceptSurgeryId): bool`, T-02 `SurgeryRoomUnavailableException::booked(int $roomId): self`, T-02 `replaceForSurgery(int $surgeryId, array $members): void`, T-03 `SurgeryChecklist::items(string $phase): array`, T-03 `SurgeryEvent::CLINICAL_TYPES`, T-05 `FakeSurgeryRepository::forceStatus(int $surgeryId, string $status): void`

**Teste RED**
- `src/tests/Unit/SurgeryServiceTest.php` — com os Fakes de T-05 e da 6A: agendamento de 120 min às 08:00 na sala 1 grava `scheduled`, preço copiado do procedimento e equipe com o cirurgião `surgeon` + anestesista; segundo agendamento às 09:00 na mesma sala lança `Surgery room 1 is already booked for this period` sem gravar (`testOverlappingScheduleInSameRoomIsRefused`); `start` em `pre_op` sem consentimento lança `has no recorded consent` e, com consentimento mas sem `time_out`, lança `Checklist phase "time_out" is not confirmed for surgery <id>`, com status ainda `pre_op` (`testStartRequiresConsentAndChecklist`); `forceStatus` para `cancelled` antes do `startPreOp` faz lançar `changed status concurrently`. Falha hoje porque o service não existe (comando: `SUITE | /usr/bin/grep -E 'SurgeryServiceTest|Failed:'`)

**Critério de aceite**
- A SUITE lista `SurgeryServiceTest` `PASS` e `Failed: 0`.
- Casos de Review Focus 1 e 5 cobertos por `testOverlappingScheduleInSameRoomIsRefused` e `testStartRequiresConsentAndChecklist` (`PASS`).
- Cada PHP tocado imprime `No syntax errors detected` no LINT.

**Validação**
- `SUITE | /usr/bin/grep -E 'SurgeryServiceTest|Failed:'` (evidência: `PASS` e `Failed: 0`)
- Review Focus: `testOverlappingScheduleInSameRoomIsRefused` e `testStartRequiresConsentAndChecklist` (evidência: `PASS` nas duas linhas)
- LINT nos 2 arquivos (evidência: `No syntax errors detected`)

### T-09 — `SurgeryChecklistService` — confirmação das fases

**Camada:** backend
**Dependências:** T-02, T-03, T-05
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Arquimedes

**Arquivos prováveis**
- `src/app/Core/Application/SurgeryChecklistService.php`
- `src/tests/Unit/SurgeryChecklistServiceTest.php`

**Notas de implementação**

Regras de `confirmPhase`: carrega e autoriza (unidade da cirurgia, `entityType: 'surgery'`); `sign_in` e `time_out` só em `pre_op`, `sign_out` só em `in_progress` (senão `InvalidStatusTransitionException` `Checklist phase "<phase>" cannot be confirmed while surgery <id> is <status>`); `time_out` exige `sign_in` completo (`Checklist phase "sign_in" is not confirmed for surgery <id>`); fase já completa → `Checklist phase "<phase>" is already confirmed for surgery <id>`; `SurgeryChecklist::assertComplete`; um `SurgeryChecklistItem` por código, todos com o mesmo `checked_at` e usuário; evento `checklist` com notas = fase. A corrida de toque duplo chega ao repositório (UNIQUE) e propaga a mesma mensagem; o controller desfaz a transação.

**Interface**
- Produz: `SurgeryChecklistService::__construct(SurgeryRepositoryInterface $surgeries, SurgeryChecklistRepositoryInterface $checklist, SurgeryEventRepositoryInterface $events, AuthorizationPolicyInterface $authorization, TenantContext $context, ?Closure $clock = null)`
- Produz: `confirmPhase(int $surgeryId, string $phase, array $checkedItemCodes, string $action): array` (itens gravados)
- Produz: `phaseStatus(int $surgeryId, string $action): array` → por fase de `SurgeryChecklist::PHASES`, `array{confirmed: bool, checked_by_system_user_id: ?int, checked_at: ?DateTimeImmutable}`
- Consome: T-03 `SurgeryChecklist::assertComplete(string $phase, array $checkedItemCodes): void`, T-03 `SurgeryChecklistItem::check(int $tenantId, int $surgeryId, string $phase, string $itemCode, int $checkedBySystemUserId, DateTimeImmutable $checkedAt): self`, T-03 `listBySurgery(int $surgeryId): array`, T-02 `Surgery::STATUS_PRE_OP = 'pre_op'`

**Teste RED**
- `src/tests/Unit/SurgeryChecklistServiceTest.php` — cirurgia em `pre_op`: `confirmPhase('sign_in', <5 códigos>)` grava 5 itens e `phaseStatus` marca `sign_in` confirmado; repetir lança `Checklist phase "sign_in" is already confirmed for surgery <id>` sem gravar (`saveCount` inalterado); `time_out` antes de `sign_in` lança `is not confirmed`; `sign_out` em `pre_op` lança `cannot be confirmed while surgery`; lista incompleta lança `All checklist items of phase`. Falha hoje porque o service não existe (comando: `SUITE | /usr/bin/grep -E 'SurgeryChecklistServiceTest|Failed:'`)

**Critério de aceite**
- A SUITE lista `SurgeryChecklistServiceTest` `PASS` e `Failed: 0`.
- Cada PHP tocado imprime `No syntax errors detected` no LINT.

**Validação**
- `SUITE | /usr/bin/grep -E 'SurgeryChecklistServiceTest|Failed:'` (evidência: `PASS` e `Failed: 0`)
- LINT nos 2 arquivos (evidência: `No syntax errors detected`)

### T-10 — `SurgeryMaterialService` — registro e remoção de materiais sob trava

**Camada:** backend
**Dependências:** T-02, T-03, T-05
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Saitama

**Arquivos prováveis**
- `src/app/Core/Application/SurgeryMaterialService.php`
- `src/tests/Unit/SurgeryMaterialServiceTest.php`

**Notas de implementação**

`addMaterial`/`removeMaterial`: primeiro `lockStatus(surgeryId)` (trava a linha; null → `surgery_id <id> was not found for the authenticated tenant`), depois carrega e autoriza (unidade da cirurgia), e só então decide: status travado diferente de `in_progress` → `InvalidStatusTransitionException` `Surgery <id> is not in progress`. Produto ativo do tenant (`ProductRepositoryInterface::findById` + `isActive()`, senão `product_id <id> was not found for the authenticated tenant`). Não confere saldo (a baixa é na conclusão, T-11). Evento `material` com notas `<nome do produto> × <qtd>` (remoção: `removido: <nome> × <qtd>`). Material de outra cirurgia/tenant → `material_id <id> was not found for the authenticated tenant`.

**Interface**
- Produz: `SurgeryMaterialService::__construct(SurgeryRepositoryInterface $surgeries, SurgeryMaterialRepositoryInterface $materials, SurgeryEventRepositoryInterface $events, ProductRepositoryInterface $products, AuthorizationPolicyInterface $authorization, TenantContext $context, ?Closure $clock = null)`
- Produz: `addMaterial(int $surgeryId, int $productId, int $quantity, string $action): SurgeryMaterial`, `removeMaterial(int $materialId, string $action): void`, `listMaterials(int $surgeryId, string $action): array` (`array{material: SurgeryMaterial, product_name: string}`), `listActiveProducts(string $action): array`
- Consome: T-02 `lockStatus(int $surgeryId): ?string`, T-03 `SurgeryMaterial::record(int $tenantId, int $surgeryId, int $productId, int $quantity, int $recordedBySystemUserId, DateTimeImmutable $recordedAt): self`, T-05 `FakeSurgeryRepository::forceStatus(int $surgeryId, string $status): void`

**Teste RED**
- `src/tests/Unit/SurgeryMaterialServiceTest.php` — cirurgia `in_progress`: `addMaterial(id, produto, 2)` grava material e evento `material`; depois de `forceStatus(id, 'completed')` (conclusão concorrente), `addMaterial` lança `Surgery <id> is not in progress` e nenhum material novo é gravado (`testMaterialAfterConcurrentCompletionIsRefused`); `removeMaterial` em cirurgia `completed` lança a mesma mensagem; produto inativo lança `was not found`. Falha hoje porque o service não existe (comando: `SUITE | /usr/bin/grep -E 'SurgeryMaterialServiceTest|Failed:'`)

**Critério de aceite**
- A SUITE lista `SurgeryMaterialServiceTest` `PASS` e `Failed: 0`.
- Caso de Review Focus 2 coberto por `testMaterialAfterConcurrentCompletionIsRefused` (`PASS`).
- Cada PHP tocado imprime `No syntax errors detected` no LINT.

**Validação**
- `SUITE | /usr/bin/grep -E 'SurgeryMaterialServiceTest|Failed:'` (evidência: `PASS` e `Failed: 0`)
- Review Focus: `testMaterialAfterConcurrentCompletionIsRefused` (evidência: `PASS`)
- LINT nos 2 arquivos (evidência: `No syntax errors detected`)

### T-11 — Conclusão integrada e retorno — `SurgeryCompletionService` + `addSourcedItem` com tipos de cirurgia

**Camada:** backend
**Dependências:** T-02, T-03, T-05
**Paralelizável:** sim
**Complexidade:** alta
**Agente:** Aang

**Arquivos prováveis**
- `src/app/Core/Application/SurgeryCompletionService.php`
- `src/app/Core/Application/EncounterAccountService.php`
- `src/tests/Unit/SurgeryCompletionServiceTest.php`

**Notas de implementação**

Modelo: `HospitalizationDischargeService.php`. Em `EncounterAccountService.php`, só a lista do `in_array` de `addSourcedItem` (linha ~327) ganha `EncounterAccountItem::TYPE_SURGERY_PROCEDURE` e `EncounterAccountItem::TYPE_SURGERY_MATERIAL`; construtor e demais métodos intactos. Ordem de `complete`:
1. `lockStatus` (null → `surgery_id <id> was not found for the authenticated tenant`); carrega e autoriza (unidade da cirurgia).
2. Status travado diferente de `in_progress` → `Surgery <id> is not in progress`.
3. `sign_out` completo no checklist, senão `Checklist phase "sign_out" is not confirmed for surgery <id>`.
4. `$accounts->openOrGet(encounterId, $action)`; conta não `open` → `InvalidStatusTransitionException` com a mensagem de `assertAccountOpen`.
5. Soma das quantidades por `product_id` → `StockService::consume(tenant, unidade, produto, soma, StockMovement::REASON_SURGERY_CONSUMPTION, 'surgery', id, usuário)`; `InsufficientStockException` propaga (nada gravado antes).
6. Item `surgery_procedure` (`source_id` = cirurgia, `Cirurgia — <procedure_name>`, `procedure_price_cents`), só se > 0.
7. Um item `surgery_material` por material (`source_id` = material, `<nome do produto> × <qtd>`, `sale_price_cents × qtd`), só se > 0.
8. `complete()` da cirurgia, `save`, evento `completion`.

`scheduleFollowUp`: `lockStatus` e recarga depois da trava; exige `completed` e sem retorno (mensagens de `linkFollowUp`); `AppointmentService::schedule(['patient_id' => paciente, 'service_id' => $serviceId, 'professional_system_user_id' => cirurgião, 'scheduled_at' => $scheduledAt, 'system_unit_id' => unidade da cirurgia], $action)`; `linkFollowUp`, `save`, evento `followup`. `SchedulingConflictException` propaga. No teste, `AppointmentService` real sobre `FakeAppointmentRepository`/`FakeServiceRepository`.

**Interface**
- Produz: `EncounterAccountService::addSourcedItem` aceita também `EncounterAccountItem::TYPE_SURGERY_PROCEDURE` e `EncounterAccountItem::TYPE_SURGERY_MATERIAL` (assinatura inalterada)
- Produz: `SurgeryCompletionService::__construct(SurgeryRepositoryInterface $surgeries, SurgeryChecklistRepositoryInterface $checklist, SurgeryMaterialRepositoryInterface $materials, SurgeryEventRepositoryInterface $events, ProductRepositoryInterface $products, EncounterAccountService $accounts, StockService $stock, AppointmentService $appointments, AuthorizationPolicyInterface $authorization, TenantContext $context, ?Closure $clock = null)`
- Produz: `complete(int $surgeryId, string $action): array` → `array{account_id: int, items_added: int, consumed_products: int}`
- Produz: `scheduleFollowUp(int $surgeryId, int $serviceId, DateTimeImmutable $scheduledAt, string $action): Appointment`
- Consome: T-02 `EncounterAccountItem::TYPE_SURGERY_PROCEDURE = 'surgery_procedure'`, T-02 `EncounterAccountItem::TYPE_SURGERY_MATERIAL = 'surgery_material'`, T-02 `StockMovement::REASON_SURGERY_CONSUMPTION = 'surgery_consumption'`, T-02 `lockStatus(int $surgeryId): ?string`, T-02 `linkFollowUp(int $appointmentId): void`, T-03 `SurgeryChecklist::items(string $phase): array`, T-05 `FakeSurgeryRepository::forceStatus(int $surgeryId, string $status): void`

**Teste RED**
- `src/tests/Unit/SurgeryCompletionServiceTest.php` — com `EncounterAccountService`, `StockService` e `AppointmentService` reais sobre Fakes: cirurgia `in_progress` com `sign_out` confirmado, procedimento de 50000 e 2 materiais do mesmo produto (1 + 2 un. a 1200) → conta com `surgery_procedure` 50000 e itens `surgery_material` 1200 e 2400, estoque baixado em 3 com motivo `surgery_consumption`, cirurgia `completed`; segunda conclusão lança `is not in progress`; produto sem saldo lança `InsufficientStockException` com conta sem item novo e cirurgia `in_progress` (`testCompletionRefusedWithoutStockTouchesNothing`); `scheduleFollowUp` grava o agendamento do cirurgião e o segundo lança `already has a follow-up appointment`. Falha hoje porque o service não existe e `addSourcedItem` recusa `surgery_procedure` (comando: `SUITE | /usr/bin/grep -E 'SurgeryCompletionServiceTest|Failed:'`)

**Critério de aceite**
- A SUITE lista `SurgeryCompletionServiceTest` `PASS`, `EncounterAccountServiceTest` e `HospitalizationDischargeServiceTest` `PASS` (sem regressão) e `Failed: 0`.
- `git diff` de `EncounterAccountService.php` na task só altera a lista de tipos de `addSourcedItem`.
- Cada PHP tocado imprime `No syntax errors detected` no LINT.

**Validação**
- `SUITE | /usr/bin/grep -E 'SurgeryCompletionServiceTest|EncounterAccountServiceTest|HospitalizationDischargeServiceTest|StockServiceTest|Failed:'` (evidência: `PASS` e `Failed: 0`)
- `testCompletionRefusedWithoutStockTouchesNothing` (evidência: `PASS`)
- `git -C /var/www/html/centralvet diff --stat <BASE>..HEAD -- src/app/Core/Application/EncounterAccountService.php` (evidência: só inserções, ≤ 4 linhas)
- LINT nos 3 arquivos (evidência: `No syntax errors detected`)

### T-12 — Telas de salas (`SurgeryRoomList`, `SurgeryRoomForm`)

**Camada:** frontend
**Dependências:** T-06, T-07
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Saitama

**Arquivos prováveis**
- `src/app/control/clinic/SurgeryRoomList.php`
- `src/app/control/clinic/SurgeryRoomForm.php`
- `src/tests/Integration/SurgeryRoomFormIntegrationTest.php`

**Notas de implementação**

Espelho de `BedList.php`/`BedForm.php` da 6A já corrigidos (ações visíveis como botões, não dropdown; `cv-touch-target`). `makeSurgeryRoomService()` e `resolveTenantContext()` próprios, `TTransaction::open('permission')`, `private const ACTION_* = 'Classe::método'`, catches de `AuthorizationDenied`, `MissingTenantContext` e `Exception` (`CvFormat::userError`). Abas `CvNav::tabs('surgery', 'rooms')` atrás de `try` (grupo criado por T-18 na mesma onda). Textos em `_t('<en>')`, anotados no board.

**Interface**
- Produz: rotas `index.php?class=SurgeryRoomList` e `index.php?class=SurgeryRoomForm` (`&id=<room_id>` edita); lista com Código, Nome, Situação (`CvBadge`: ativa/inativa), ações Editar/Ativar/Desativar e estado vazio `cv-state--empty` com "Nova sala"; formulário com `code`, `name` e `onSave` (na edição `code` só leitura)
- Consome: T-07 `create(string $code, string $name, string $action): SurgeryRoom`, T-07 `listForCurrentUnit(string $action): array`

**Teste RED**
- `src/tests/Integration/SurgeryRoomFormIntegrationTest.php` — subprocesso com `require "init.php"` (padrão de `BedFormIntegrationTest`): `new SurgeryRoomForm([])` tem os campos `code` e `name`; as constantes `ACTION_*` de `SurgeryRoomList` e `SurgeryRoomForm` casam com `AuthorizationRequest::ACTION_PATTERN`. Falha hoje porque as classes não existem (comando: `SUITE | /usr/bin/grep -E 'SurgeryRoomFormIntegrationTest|Failed:'`)

**Critério de aceite**
- A SUITE lista `SurgeryRoomFormIntegrationTest` e `ControllerRawExceptionMessageTest` `PASS` e `Failed: 0`.
- No smoke da Onda 4 (desktop): sala `F6B teste S1` criada aparece na `SurgeryRoomList` com badge "Ativa"; console sem mensagem de nível error e rede sem status ≥ 400.
- Cada PHP tocado imprime `No syntax errors detected` no LINT.

**Validação**
- `SUITE | /usr/bin/grep -E 'SurgeryRoomFormIntegrationTest|ControllerRawExceptionMessageTest|Failed:'` (evidência: `PASS` e `Failed: 0`)
- Smoke Playwright em `http://127.0.0.1:8081/index.php?class=SurgeryRoomList` (evidência: linha `F6B teste S1`, console sem `error`, rede sem ≥ 400)
- LINT nos 3 arquivos (evidência: `No syntax errors detected`)

### T-13 — Agendamento a partir do atendimento e troca de equipe (`SurgeryScheduleForm`)

**Camada:** frontend
**Dependências:** T-06, T-07, T-08
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Naruto

**Arquivos prováveis**
- `src/app/control/clinic/SurgeryScheduleForm.php`
- `src/tests/Integration/SurgeryScheduleFormIntegrationTest.php`

**Notas de implementação**

Padrão de `HospitalizationAdmissionForm.php` (com o catch de `MissingTenantContext` que faltou lá). Modo agendar (`encounter_id`, `patient_id`): salas de `listActiveForUnit` da unidade do atendimento (`code — name`), procedimentos de `listProcedures`, profissionais ativos do tenant (mesma fonte de `AppointmentForm.php`), cirurgião pré-selecionado com o profissional do atendimento, data/hora (`DateTimeInput::parse`, d/m/Y H:i) e duração (padrão `duration_minutes` do procedimento, senão 60). Sem sala ativa: estado vazio com link para `SurgeryRoomList`. Modo equipe (`id`): só os 3 campos de equipe, salvos com `replaceTeam`. Sucesso → `index.php?class=SurgeryView&id=<id>`. `notes_text` só por POST.

**Interface**
- Produz: rotas `index.php?class=SurgeryScheduleForm&encounter_id=<id>&patient_id=<id>` e `index.php?class=SurgeryScheduleForm&id=<surgery_id>`; campos `encounter_id` (oculto), `room_id`, `procedure_catalog_item_id`, `surgeon_system_user_id`, `scheduled_start_at`, `duration_minutes`, `anesthetist_system_user_id`, `assistant_system_user_id`, `circulating_system_user_id`, `notes_text`; ações `'SurgeryScheduleForm::onSave'` e `'SurgeryScheduleForm::onSaveTeam'`
- Consome: T-08 `schedule(int $encounterId, int $roomId, int $procedureCatalogItemId, int $surgeonSystemUserId, DateTimeImmutable $scheduledStartAt, int $durationMinutes, array $team, ?string $notesText, string $action): Surgery`, T-08 `replaceTeam(int $surgeryId, array $team, string $action): array`, T-08 `listProcedures(string $action): array`, T-07 `listActiveForUnit(int $systemUnitId, string $action): array`

**Teste RED**
- `src/tests/Integration/SurgeryScheduleFormIntegrationTest.php` — subprocesso com `init.php`: `new SurgeryScheduleForm([])` tem os 10 campos do contrato e, sem `encounter_id` nem `id`, renderiza sem exceção; as `ACTION_*` casam com `AuthorizationRequest::ACTION_PATTERN`. Falha hoje porque a classe não existe (comando: `SUITE | /usr/bin/grep -E 'SurgeryScheduleFormIntegrationTest|Failed:'`)

**Critério de aceite**
- A SUITE lista `SurgeryScheduleFormIntegrationTest` e `ControllerRawExceptionMessageTest` `PASS` e `Failed: 0`.
- No smoke da Onda 4 (desktop): a partir de um atendimento, o agendamento na sala `F6B teste S1` redireciona para `class=SurgeryView&id=`; console sem mensagem de nível error e rede sem status ≥ 400.
- Cada PHP tocado imprime `No syntax errors detected` no LINT.

**Validação**
- `SUITE | /usr/bin/grep -E 'SurgeryScheduleFormIntegrationTest|ControllerRawExceptionMessageTest|Failed:'` (evidência: `PASS` e `Failed: 0`)
- Smoke Playwright (evidência: URL final `class=SurgeryView&id=`, console e rede limpos)
- LINT nos 2 arquivos (evidência: `No syntax errors detected`)

### T-14 — Ficha da cirurgia (`SurgeryView`): status, cancelamento, conclusão, retorno, internar

**Camada:** frontend
**Dependências:** T-06, T-08, T-09, T-10, T-11
**Paralelizável:** sim
**Complexidade:** alta
**Agente:** Aang

**Arquivos prováveis**
- `src/app/control/clinic/SurgeryView.php`
- `src/tests/Integration/SurgeryViewIntegrationTest.php`

**Notas de implementação**

Modelo: `HospitalizationView.php`. Cabeçalho `CvPage::header` (paciente, procedimento, sala, início/fim previstos, cirurgião, badge de status) e abas `CvPage::tabs`:
- Resumo: equipe (`listTeam`, papéis traduzidos), consentimento (signatário, data; link `SurgeryConsentForm&surgery_id=`), botão "Equipe" (`SurgeryScheduleForm&id=`) enquanto `isOpenForPreOp()`.
- Checklist: as 3 fases de `phaseStatus` com badge e link `SurgeryChecklistForm&surgery_id=&phase=`.
- Materiais: `listMaterials` com link `SurgeryMaterialForm&surgery_id=`.
- Eventos: linha do tempo de `listEvents` com links `SurgeryEventForm&surgery_id=&type=`.

Ações por status: `onStartPreOp` (scheduled), `onStart` (pre_op), `onAskCancel` → `onCancel` (scheduled/pre_op; motivo obrigatório só por POST, padrão `onAskDischarge`/`postedSummary`, a query string é ignorada), `onAskComplete` → `onComplete` (in_progress; `TQuestion` só com id), `onScheduleFollowUp` (completed, POST `followup_scheduled_at` + `followup_service_id`, serviços de `ServiceRepositoryInterface::listActive`). Concluída: botão "Internar no pós-operatório" → `index.php?class=HospitalizationAdmissionForm&encounter_id=<id>&patient_id=<id>` e resumo da conclusão (itens lançados, produtos baixados) com link para `EncounterAccountForm`. Cada ação roda em um único `TTransaction::open('permission')`; qualquer exceção → `rollback` e `CvFormat::userError`; captura `AuthorizationDenied` e `MissingTenantContext`. Sem `id` → `cv-state--empty` antes de resolver o tenant. Botões com `cv-touch-target`.

**Interface**
- Produz: rota `index.php?class=SurgeryView&id=<surgery_id>`; métodos `onStartPreOp`, `onStart`, `onAskCancel`, `onCancel` (POST `cancellation_reason_text`), `onAskComplete`, `onComplete`, `onScheduleFollowUp` (POST `followup_scheduled_at`, `followup_service_id`), com ações `'SurgeryView::onStartPreOp'`, `'SurgeryView::onStart'`, `'SurgeryView::onCancel'`, `'SurgeryView::onComplete'`, `'SurgeryView::onScheduleFollowUp'` e leitura `'SurgeryView::onReload'`; `SurgeryView::postedReason(): string` (lê `cancellation_reason_text` só de `$_POST`)
- Consome: T-08 `get(int $surgeryId, string $action): Surgery`, T-08 `listTeam(int $surgeryId, string $action): array`, T-08 `listEvents(int $surgeryId, string $action): array`, T-08 `startPreOp(int $surgeryId, string $action): Surgery`, T-08 `start(int $surgeryId, string $action): Surgery`, T-08 `cancel(int $surgeryId, string $reasonText, string $action): Surgery`, T-09 `phaseStatus(int $surgeryId, string $action): array`, T-10 `listMaterials(int $surgeryId, string $action): array`, T-11 `complete(int $surgeryId, string $action): array`, T-11 `scheduleFollowUp(int $surgeryId, int $serviceId, DateTimeImmutable $scheduledAt, string $action): Appointment`

**Teste RED**
- `src/tests/Integration/SurgeryViewIntegrationTest.php` — subprocesso com `init.php`: `new SurgeryView([])` + `show()` com buffer imprime `cv-state--empty` sem exceção; `onCancel` com `cancellation_reason_text` só na query string não o usa (`postedReason()` devolve vazio); o script da confirmação de cancelamento não contém o texto do motivo; as `ACTION_*` casam com `AuthorizationRequest::ACTION_PATTERN`. Falha hoje porque a classe não existe (comando: `SUITE | /usr/bin/grep -E 'SurgeryViewIntegrationTest|Failed:'`)

**Critério de aceite**
- A SUITE lista `SurgeryViewIntegrationTest` e `ControllerRawExceptionMessageTest` `PASS` e `Failed: 0`.
- No smoke da Onda 4 (desktop): a ficha de uma cirurgia `F6B teste` mostra as 4 abas e o botão "Iniciar pré-op"; console sem mensagem de nível error e rede sem status ≥ 400.
- Caso de Review Focus 3 (roteiro B da T-21): conclusão com material sem saldo mostra "Estoque insuficiente", a cirurgia segue "Em andamento", a conta não ganha item e `stock_movement` não ganha linha.
- Cada PHP tocado imprime `No syntax errors detected` no LINT.

**Validação**
- `SUITE | /usr/bin/grep -E 'SurgeryViewIntegrationTest|ControllerRawExceptionMessageTest|Failed:'` (evidência: `PASS` e `Failed: 0`)
- Smoke Playwright em `class=SurgeryView&id=<id>` (evidência: abas e botão na snapshot, console e rede limpos)
- Review Focus 3 no gate final: `SELECT status FROM surgery WHERE id=<id>` só leitura = `in_progress`; `SELECT COUNT(*) FROM encounter_account_item WHERE source_type LIKE 'surgery%'` e `SELECT COUNT(*) FROM stock_movement WHERE reason='surgery_consumption'` inalterados (evidência: mesmos números antes e depois)
- LINT nos 2 arquivos (evidência: `No syntax errors detected`)

### T-15 — Consentimento e eventos clínicos (`SurgeryConsentForm`, `SurgeryEventForm`)

**Camada:** frontend
**Dependências:** T-06, T-08
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Kratos

**Arquivos prováveis**
- `src/app/control/clinic/SurgeryConsentForm.php`
- `src/app/control/clinic/SurgeryEventForm.php`
- `src/tests/Integration/SurgeryClinicalFormsIntegrationTest.php`

**Notas de implementação**

Padrão de `HospitalizationEventForm.php` (6A corrigido: rótulo pt no erro, dados mantidos, obrigatórios marcados). `SurgeryConsentForm`: `consent_signer_name` e `consent_text` (`TText`, pré-preenchido com `_t(SurgeryConsentForm::DEFAULT_TEXT_KEY)`, chave en `Surgery consent default text`, que T-19 traduz por um parágrafo pt), botão "Registrar aceite"; mostra o aceite já registrado (quem, quando). `SurgeryEventForm`: `type` validado contra `SurgeryEvent::CLINICAL_TYPES` (inválido → estado vazio com mensagem), `notes_text` (`TText`). Textos clínicos só por POST. Sucesso → `SurgeryView&id=<surgery_id>`.

**Interface**
- Produz: rotas `index.php?class=SurgeryConsentForm&surgery_id=<id>` e `index.php?class=SurgeryEventForm&surgery_id=<id>&type=<tipo clínico>`; ações `'SurgeryConsentForm::onSave'`, `'SurgeryConsentForm::onLoad'`, `'SurgeryEventForm::onSave'`, `'SurgeryEventForm::onLoad'`; chave `Surgery consent default text`
- Consome: T-08 `recordConsent(int $surgeryId, string $signerName, string $consentText, string $action): Surgery`, T-08 `recordClinicalEvent(int $surgeryId, string $eventType, string $notesText, string $action): SurgeryEvent`, T-08 `get(int $surgeryId, string $action): Surgery`

**Teste RED**
- `src/tests/Integration/SurgeryClinicalFormsIntegrationTest.php` — subprocesso com `init.php`: `SurgeryConsentForm` tem `consent_signer_name` e `consent_text`; `SurgeryEventForm` tem `notes_text` e, com `type=foo`, renderiza `cv-state--empty` sem exceção; as `ACTION_*` das duas classes casam com `AuthorizationRequest::ACTION_PATTERN`. Falha hoje porque as classes não existem (comando: `SUITE | /usr/bin/grep -E 'SurgeryClinicalFormsIntegrationTest|Failed:'`)

**Critério de aceite**
- A SUITE lista `SurgeryClinicalFormsIntegrationTest` e `ControllerRawExceptionMessageTest` `PASS` e `Failed: 0`.
- No smoke da Onda 4 (desktop): consentimento com signatário `F6B teste Tutor` aparece no Resumo da ficha; console sem mensagem de nível error e rede sem status ≥ 400; nenhuma URL da navegação contém o texto do consentimento.
- Cada PHP tocado imprime `No syntax errors detected` no LINT.

**Validação**
- `SUITE | /usr/bin/grep -E 'SurgeryClinicalFormsIntegrationTest|ControllerRawExceptionMessageTest|Failed:'` (evidência: `PASS` e `Failed: 0`)
- Smoke Playwright (evidência: `F6B teste Tutor` na ficha; `browser_network_requests` sem o texto do consentimento em URL)
- LINT nos 3 arquivos (evidência: `No syntax errors detected`)

### T-16 — Checklist (tablet) e materiais (`SurgeryChecklistForm`, `SurgeryMaterialForm`) + CSS `cv-checklist-*`

**Camada:** frontend
**Dependências:** T-06, T-09, T-10
**Paralelizável:** sim
**Complexidade:** alta
**Agente:** Tesla

**Arquivos prováveis**
- `src/app/control/clinic/SurgeryChecklistForm.php`
- `src/app/control/clinic/SurgeryMaterialForm.php`
- `src/app/templates/adminbs5/cv-components.css`
- `src/tests/Integration/SurgeryChecklistFormIntegrationTest.php`

**Notas de implementação**

`SurgeryChecklistForm`: título `_t(SurgeryChecklist::phaseLabel($phase))`, um checkbox grande por item (`_t(SurgeryChecklist::label($code))`, nome `items[]`, valor = código), botão "Confirmar fase" com `cv-touch-target`; fase já confirmada → mostra quem/quando, sem botão; fase inválida → estado vazio. `SurgeryMaterialForm`: combo de `listActiveProducts`, quantidade, botão "Adicionar" e lista de `listMaterials` com "Remover" (`TQuestion` só com ids), tudo em `in_progress`; fora disso, só leitura. Seção `/* cv-checklist */` nova no fim de `cv-components.css` (itens com área de toque ≥ 44 px usando `var(--cv-touch-target)`, estado marcado visível); não alterar seções existentes. Cada ação em um único `TTransaction`, catches de `AuthorizationDenied`, `MissingTenantContext` e `Exception`.

**Interface**
- Produz: rotas `index.php?class=SurgeryChecklistForm&surgery_id=<id>&phase=<fase>` e `index.php?class=SurgeryMaterialForm&surgery_id=<id>`; ações `'SurgeryChecklistForm::onConfirm'`, `'SurgeryChecklistForm::onLoad'`, `'SurgeryMaterialForm::onAdd'`, `'SurgeryMaterialForm::onRemove'`, `'SurgeryMaterialForm::onLoad'`; classes CSS `cv-checklist`, `cv-checklist__item`, `cv-checklist__item--checked`; `SurgeryChecklistForm::itemFields(string $phase): array` (um campo por código de `SurgeryChecklist::items`, classe `cv-checklist__item`)
- Consome: T-09 `confirmPhase(int $surgeryId, string $phase, array $checkedItemCodes, string $action): array`, T-09 `phaseStatus(int $surgeryId, string $action): array`, T-10 `addMaterial(int $surgeryId, int $productId, int $quantity, string $action): SurgeryMaterial`, T-10 `removeMaterial(int $materialId, string $action): void`, T-10 `listMaterials(int $surgeryId, string $action): array`, T-10 `listActiveProducts(string $action): array`

**Teste RED**
- `src/tests/Integration/SurgeryChecklistFormIntegrationTest.php` — subprocesso com `init.php`: `SurgeryChecklistForm` com `phase=time_out` e sem `surgery_id` não lança exceção; o método estático de montagem dos itens (`SurgeryChecklistForm::itemFields('time_out')`) devolve 4 campos com valores `team_introduced`…`critical_steps_reviewed` e classe `cv-checklist__item`; `phase=foo` renderiza `cv-state--empty`; `SurgeryMaterialForm` tem `product_id` e `quantity`; `cv-components.css` contém `.cv-checklist__item`. Falha hoje porque as classes e a seção não existem (comando: `SUITE | /usr/bin/grep -E 'SurgeryChecklistFormIntegrationTest|Failed:'`)

**Critério de aceite**
- A SUITE lista `SurgeryChecklistFormIntegrationTest` e `ControllerRawExceptionMessageTest` `PASS` e `Failed: 0`.
- `git diff` de `cv-components.css` na task só acrescenta linhas no fim do arquivo.
- Caso de Review Focus 4 (roteiro B da T-21, tablet 820×1180): dois toques em "Confirmar fase" deixam `SELECT COUNT(*) FROM surgery_checklist WHERE surgery_id=<id> AND phase='sign_in'` = 5, e o segundo envio mostra a mensagem de fase já confirmada.
- Cada PHP tocado imprime `No syntax errors detected` no LINT.

**Validação**
- `SUITE | /usr/bin/grep -E 'SurgeryChecklistFormIntegrationTest|ControllerRawExceptionMessageTest|Failed:'` (evidência: `PASS` e `Failed: 0`)
- `git -C /var/www/html/centralvet diff --numstat <BASE>..HEAD -- src/app/templates/adminbs5/cv-components.css` (evidência: coluna de remoções `0`)
- Review Focus 4 no gate final (evidência: contagem 5 e mensagem na tela; altura dos itens ≥ 44 px via `browser_evaluate`)
- LINT nos 3 PHP (evidência: `No syntax errors detected`)

### T-17 — Agenda cirúrgica do dia (`SurgeryList`) + `SurgeryAgendaView`

**Camada:** frontend
**Dependências:** T-06, T-08
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Batman

**Arquivos prováveis**
- `src/app/control/clinic/SurgeryList.php`
- `src/app/Core/Presentation/SurgeryAgendaView.php`
- `src/tests/Unit/SurgeryAgendaViewTest.php`

**Notas de implementação**

Padrão de `HospitalizationBoard.php` + `HospitalizationBoardView.php` (view-model puro, sem Adianti). `SurgeryList`: filtro de data (`date`, padrão hoje, setas dia anterior/próximo), KPIs por status (`CvKpiCard`), linhas ordenadas por início com horário, sala, paciente, procedimento, cirurgião, badge de status e link `SurgeryView&id=`; estado vazio. Leitura com ação `'SurgeryList::onReload'`; captura `AuthorizationDenied` e `MissingTenantContext`. Nomes de sala/paciente/cirurgião resolvidos por consultas em lote (sem N+1).

**Interface**
- Produz: rota `index.php?class=SurgeryList` (`&date=YYYY-MM-DD`); ação `'SurgeryList::onReload'`
- Produz: `SurgeryAgendaView::countByStatus(array $surgeries): array` (chaves `scheduled`, `pre_op`, `in_progress`, `completed`, `cancelled`, todas presentes) e `SurgeryAgendaView::sortByStart(array $surgeries): array`
- Consome: T-08 `listForDay(DateTimeImmutable $day, string $action): array`

**Teste RED**
- `src/tests/Unit/SurgeryAgendaViewTest.php` — 3 cirurgias (`scheduled` 10:00, `in_progress` 08:00, `cancelled` 09:00) → `countByStatus` = `scheduled 1, pre_op 0, in_progress 1, completed 0, cancelled 1`; `sortByStart` devolve 08:00, 09:00, 10:00. Falha hoje porque a classe não existe (comando: `SUITE | /usr/bin/grep -E 'SurgeryAgendaViewTest|Failed:'`)

**Critério de aceite**
- A SUITE lista `SurgeryAgendaViewTest` e `CoreLayerDependencyTest` `PASS` e `Failed: 0`.
- No smoke da Onda 4 (desktop): `SurgeryList` do dia da cirurgia `F6B teste` mostra a linha com link para a ficha e o KPI "Agendadas" = 1; console sem mensagem de nível error e rede sem status ≥ 400.
- Cada PHP tocado imprime `No syntax errors detected` no LINT.

**Validação**
- `SUITE | /usr/bin/grep -E 'SurgeryAgendaViewTest|CoreLayerDependencyTest|ControllerRawExceptionMessageTest|Failed:'` (evidência: `PASS` e `Failed: 0`)
- Smoke Playwright em `class=SurgeryList&date=<dia>` (evidência: linha e KPI na snapshot, console e rede limpos)
- LINT nos 3 arquivos (evidência: `No syntax errors detected`)

### T-18 — Navegação: menu, abas `CvNav`, ação no `EncounterView`

**Camada:** frontend
**Dependências:** T-04
**Paralelizável:** sim
**Complexidade:** simples
**Agente:** Jaspion

**Arquivos prováveis**
- `src/app/control/clinic/EncounterView.php`
- `src/menu.xml`
- `src/app/lib/widget/CvNav.php`
- `src/tests/Integration/SurgeryNavigationIntegrationTest.php`

**Notas de implementação**

`menu.xml`: no item existente `label='_t{Surgeries}'` (linhas 55-58), trocar só a `<action>` de `CvShellController#method=onComingSoon#item=surgeries` para `SurgeryList` (o rótulo e a posição depois da Internação são conferidos por `HospitalizationNavigationIntegrationTest`); acrescentar `_t{Surgery rooms}` → `SurgeryRoomList` no submenu de Configurações, logo depois de `_t{Beds}`. `EncounterView.php`: uma linha nova em `PLAN_ACTIONS`, nada mais.

**Interface**
- Produz: `EncounterView::PLAN_ACTIONS['surgery'] = ['Schedule surgery', 'fa:kit-medical', 'SurgeryScheduleForm']`
- Produz: `<action>SurgeryList</action>` no item `_t{Surgeries}` e item `_t{Surgery rooms}` → `<action>SurgeryRoomList</action>`
- Produz: `CvNav::group('surgery')` com `'list' => ['Surgeries', 'index.php?class=SurgeryList']` e `'rooms' => ['Rooms', 'index.php?class=SurgeryRoomList']`
- Consome: nada (rotas fixadas em plan.md § Decisões de arquitetura; programas de T-04)

**Teste RED**
- `src/tests/Integration/SurgeryNavigationIntegrationTest.php` — subprocesso com `init.php`: `CvNav::group('surgery')` devolve as hrefs `index.php?class=SurgeryList` e `index.php?class=SurgeryRoomList`; `PLAN_ACTIONS` de `EncounterView` (Reflection) tem `surgery` com alvo `SurgeryScheduleForm`; `src/menu.xml` contém `<action>SurgeryList</action>` e `<action>SurgeryRoomList</action>` e não contém `item=surgeries`. Falha hoje: `CvNav` lança `Unknown CvNav group` e as entradas não existem (comando: `SUITE | /usr/bin/grep -E 'SurgeryNavigationIntegrationTest|Failed:'`)

**Critério de aceite**
- A SUITE lista `SurgeryNavigationIntegrationTest` e `HospitalizationNavigationIntegrationTest` `PASS` e `Failed: 0`.
- `python3 -c "import xml.dom.minidom as m; m.parse('src/menu.xml')"` sai com código 0.
- `git diff` de `EncounterView.php` na task tem uma linha adicionada e nenhuma removida.

**Validação**
- `SUITE | /usr/bin/grep -E 'SurgeryNavigationIntegrationTest|HospitalizationNavigationIntegrationTest|Failed:'` (evidência: `PASS` e `Failed: 0`)
- `python3 -c "import xml.dom.minidom as m; m.parse('src/menu.xml')"; echo $?` (evidência: `0`)
- `git -C /var/www/html/centralvet diff --numstat <BASE>..HEAD -- src/app/control/clinic/EncounterView.php` (evidência: `1	0`)

### T-19 — i18n pt/en e mensagens de domínio da cirurgia

**Camada:** frontend
**Dependências:** T-12, T-13, T-14, T-15, T-16, T-17, T-18
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Levi

**Arquivos prováveis**
- `src/app/config/translations.json`
- `src/app/Core/Presentation/UserMessage.php`
- `src/tests/Unit/UserMessageTest.php`

**Notas de implementação**

Fontes das chaves: linhas `- [T-xx] i18n: <en> → <pt>` do board; varredura de `_t('...')` em `src/app/control/clinic/Surgery*.php` e de `_t{...}` no `menu.xml`; rótulos de `CvNav` e `PLAN_ACTIONS` (`Schedule surgery` → `Agendar cirurgia`); os 13 rótulos e 3 fases de `SurgeryChecklist` (T-03 § Interface); papéis (`surgeon` → Cirurgião, `anesthetist` → Anestesista, `assistant` → Auxiliar, `circulating` → Volante); status (Agendada, Pré-op, Em andamento, Concluída, Cancelada); `Surgery consent default text` → parágrafo pt do termo de consentimento. Inserção em ordem alfabética de `en` (sem distinção de caixa), sem `en` duplicado; chave já existente mantém a tradução.

Mensagens de domínio novas em `UserMessage` (`STATIC`/`PATTERNS` ancorados com `/D`, antes dos genéricos), uma chave sem id interno por mensagem: `Surgery room <id> is already booked for this period`, `Surgery room <id> is not active`, `Surgery room <id> belongs to another unit`, `A surgery room with code "<code>" already exists in this unit`, `Surgery <id> is not scheduled`, `Surgery <id> is not in pre-op`, `Surgery <id> is not in progress`, `Surgery <id> is not completed`, `Surgery <id> is cancelled`, `Surgery <id> has no recorded consent`, `Surgery <id> is not open for pre-operative changes`, `Surgery <id> cannot be cancelled in its current status`, `Surgery <id> changed status concurrently`, `Surgery <id> already has a follow-up appointment`, `Checklist phase "<phase>" is already confirmed for surgery <id>`, `Checklist phase "<phase>" is not confirmed for surgery <id>`, `Checklist phase "<phase>" cannot be confirmed while surgery <id> is <status>`, `All checklist items of phase "<phase>" must be checked`, `Surgery duration cannot exceed 24 hours`, `duration_minutes must be between 15 and 1440`, `scheduled_end_at must be after scheduled_start_at`, `quantity must be between 1 and 9999`, `consent_signer_name is required`, `consent_text is required`, `cancellation_reason_text is required`, `surgeon_system_user_id must be an active user of this tenant`, `Team member <id> must be an active user of this tenant`. Atualiza os totais travados de `STATIC`/`PATTERNS` e o teste de `_t` das telas (linha ~217) para incluir `Surgery*`.

**Interface**
- Produz: chaves `en`/`pt` de todas as strings de tela da Onda 4 (ex.: `Surgeries` → `Cirurgias`, `Surgery rooms` → `Salas cirúrgicas`, `Schedule surgery` → `Agendar cirurgia`, `Start pre-op` → `Iniciar pré-op`, `Start surgery` → `Iniciar cirurgia`, `Complete surgery` → `Concluir cirurgia`, `Hospitalize post-op` → `Internar no pós-operatório`, `Before induction` → `Antes da indução`)
- Consome: T-02 `SurgeryRoomUnavailableException::booked(int $roomId): self`, T-03 `SurgeryChecklist::label(string $itemCode): string`

**Teste RED**
- `src/tests/Unit/UserMessageTest.php` — `UserMessage::resolve('Surgery room 3 is already booked for this period')`, `resolve('Checklist phase "sign_in" is already confirmed for surgery 9')` e `resolve('Surgery 9 is not in progress')` devolvem chave não nula com tradução em `translations.json`, e todo `_t('…')` de `src/app/control/clinic/Surgery*.php` tem tradução. Falha hoje porque os padrões e as chaves não existem (comando: `SUITE | /usr/bin/grep -E 'UserMessageTest|Failed:'`)

**Critério de aceite**
- A SUITE lista `UserMessageTest` e `CvFormatUserErrorTest` `PASS` e `Failed: 0`.
- `python3 -c "import json;d=json.load(open('src/app/config/translations.json'));e=[x['en'] for x in d];print(len(e)-len(set(e)), e==sorted(e,key=str.lower))"` imprime `0 True`.
- No gate da Onda 5 (fetch autenticado em pt das 9 telas): nenhuma resposta contém `Message not found`.

**Validação**
- `SUITE | /usr/bin/grep -E 'UserMessageTest|CvFormatUserErrorTest|Failed:'` (evidência: `PASS` e `Failed: 0`)
- O `python3 -c` acima (evidência: `0 True`)
- `/usr/bin/grep -rhoE "_t\('[^']+'" src/app/control/clinic/Surgery*.php | sort -u` cruzado com as chaves `en` (evidência: nenhuma chave faltando)

### T-20 — Runbook da cirurgia e seção 5.7 da 0011

**Camada:** docs
**Dependências:** T-01, T-04, T-11
**Paralelizável:** sim
**Complexidade:** simples
**Agente:** Gandalf

**Arquivos prováveis**
- `docs/runbooks/cirurgia.md`
- `docs/runbooks/README.md`
- `docs/runbooks/shared-hosting-mysql57.md`

**Interface**
- Produz: `docs/runbooks/cirurgia.md` com fluxos (sala → agendamento → consentimento → pré-op → checklist → início → eventos/materiais → `sign_out` → conclusão → internação/retorno; cancelamento), regras (sobreposição de sala, requisitos de início e conclusão, baixa e cobrança na conclusão, recusa por estoque ou conta fechada, travas de concorrência), tabelas da 0011 e CHECKs ampliados, os 9 programas RBAC e o grupo `Clínico – Cirurgia`, aplicação (MySQL 8 e 5.7) e rollback, limites do MVP (Excluído do plano); a DML de programas é descrita no próprio runbook (não só apontada para `.claude/tasks/`, pendência T-19 da 6A)
- Produz: link para `cirurgia.md` em `docs/runbooks/README.md` e seção "Migration 0011 (cirurgia)" em `shared-hosting-mysql57.md` (prefixo `15-`, `DROP TRIGGER IF EXISTS` dos 2 CHECKs ampliados antes do `CREATE TRIGGER`)
- Consome: nada (lê `plan.md`, `tasks.md` e os arquivos entregues)

**Teste RED**
- sem teste: documentação

**Critério de aceite**
- `docs/runbooks/cirurgia.md` tem as seções `## Fluxos`, `## Regras`, `## Banco de dados`, `## Permissões`, `## Aplicação e rollback` e `## Limites do MVP`; cita `20261005_0011_phase6b_surgery`, `surgery_consumption` e os 9 controllers.
- `docs/runbooks/README.md` tem um link para `cirurgia.md`; `shared-hosting-mysql57.md` tem a seção da 0011.

**Validação**
- `/usr/bin/grep -c "^## " docs/runbooks/cirurgia.md` (evidência: ≥ 6)
- `/usr/bin/grep -oE "Surgery[A-Za-z]+(List|Form|View)" docs/runbooks/cirurgia.md | sort -u | wc -l` (evidência: `9`)
- `/usr/bin/grep -c "cirurgia.md" docs/runbooks/README.md; /usr/bin/grep -c "0011" docs/runbooks/shared-hosting-mysql57.md` (evidência: ≥ 1 em cada)

### T-21 — Validação final ponta a ponta e SQL de limpeza `F6B teste`

**Camada:** qa
**Dependências:** T-19, T-20
**Paralelizável:** não
**Complexidade:** média
**Agente:** Spock

**Arquivos prováveis**
- `.claude/tasks/mar-20261005-2234-fase-6b-cirurgia/sql/T-21-cleanup.sql`

**Notas de implementação**

Prepara, sem executar, o SQL de limpeza, no modelo de `.claude/tasks/mar-20261005-1521-fase-6a-internacao/sql/T-20-cleanup.sql` (tabelas temporárias de ids, `-- COMMIT;` no fim). Começa com SELECTs de contagem e segue com `START TRANSACTION` + DELETEs com `WHERE` explícito pelo prefixo `F6B teste` e pelos ids derivados, na ordem das FKs: `surgery_checklist`, `surgery_event`, `surgery_material`, `surgery_team` → itens `surgery_*` da conta e recálculo dos totais da conta por tabela derivada (lição do `encounter_account_total_ck` da 6A) → movimentos `surgery_consumption` e devolução do saldo dos lotes → `appointment` de retorno ligado às cirurgias → `surgery` → `surgery_room`, mais internação pós-operatória, atendimentos, pacientes, produtos e lotes `F6B teste` criados nos gates. Depois, SELECTs de conferência. Os registros de `audit_log` dos gates ficam fora (documentado no arquivo).

O validador executa o gate da Onda 6 em dois disparos:
- **Roteiro A** (desktop 1366×768 e tablet 820×1180): sala `F6B teste S1` → agendar a partir de um atendimento com equipe (cirurgião, anestesista, auxiliar, volante) → consentimento → iniciar pré-op → `sign_in` e `time_out` → iniciar → eventos anestesia/intercorrência → materiais → `sign_out` → concluir → conta com procedimento e materiais, estoque baixado → agendar retorno → internar no pós-operatório (leito `F6B teste L1`) → agenda do dia com a cirurgia "Concluída"; cancelamento de uma segunda cirurgia com motivo.
- **Roteiro B**: os 5 itens de Review Focus de `plan.md` (sobreposição pela tela; material após conclusão em outra aba; conclusão sem saldo; toque duplo no checklist; iniciar sem consentimento/`time_out`) e permissão negada na `SurgeryView` de outra unidade (sem segunda unidade para o admin, vale `SurgeryServiceTest` e o validador registra em Pendências).

**Interface**
- Produz: `sql/T-21-cleanup.sql` (só o orquestrador executa, com aprovação)
- Consome: nada

**Teste RED**
- sem teste: validação final e SQL de limpeza preparado; a prova é o gate E2E da Onda 6

**Critério de aceite**
- Os DELETEs de `T-21-cleanup.sql` têm `WHERE` com `F6B teste` ou ids vindos de SELECT por esse prefixo (`/usr/bin/grep -ciE "delete from [a-z_]+ *;"` imprime `0`), e o arquivo termina com `-- COMMIT;`.
- Roteiro A aprovado em desktop e tablet e roteiro B com a reação descrita em plan.md para cada item de Review Focus.
- SUITE com `Failed: 0`; nenhum erro novo em relação a `baseline/php-lint.txt`; PYTEST57 com `Ran 8 tests` ou mais e nenhuma falha.
- Contagens de `encounter`, `encounter_account_item`, `stock_movement`, `appointment`, `hospitalization` e `system_program` só cresceram desde a BASE da Onda 1.

**Validação**
- `SUITE | /usr/bin/grep -E 'Total|Failed:'` (evidência: `Failed: 0`)
- LINT nos PHP tocados pela branch (`git diff --name-only <BASE da onda 1>..HEAD -- '*.php'`) (evidência: `No syntax errors detected` em todos)
- `python3 scripts/test-prepare-mysql57.py` (evidência: `Ran 8 tests` ou mais, sem `FAILED`)
- Tabela do gate `Tela|Fluxos|Console|Rede|Erro na tela|Veredito|Task dona` com uma linha por tela das Ondas 4 (evidência: veredito aprovado em todas)
- Contagens só leitura antes/depois (evidência: nenhuma diminuiu)

## Legenda

- [ ] pendente
- [~] em andamento
- [x] concluído com evidência
- [!] bloqueado
