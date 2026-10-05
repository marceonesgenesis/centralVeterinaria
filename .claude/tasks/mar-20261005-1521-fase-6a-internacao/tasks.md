# Tasks

| ID | Camada | Descrição | Dependências | Paralelizável | Complexidade | Agente | Status |
|---|---|---|---|---|---|---|---|
| T-01 | database | Preparar migration 0010 (5 tabelas + 2 CHECKs ampliados), verify e provision do banco de teste | — | sim | alta | Darwin | [x] |
| T-02 | infra | Preparador MySQL 5.7: `DROP CHECK` e verificação de CHECK por tabela | — | sim | média | Darwin | [x] |
| T-03 | backend | Domain de leito, internação e evento + contratos + tipos de conta e motivo de estoque | — | sim | alta | Platão | [x] |
| T-04 | backend | Domain de prescrição interna, administração e agenda + contratos | — | sim | alta | Arquimedes | [x] |
| T-05 | infra | Programas RBAC das 8 telas e grupo `Clínico – Internação` (seed + DML, verify e rollback) | — | sim | média | Jaspion | [x] |
| T-06 | shared | Fakes dos 5 repositórios da internação | T-03, T-04 | sim | média | Platão | [x] |
| T-07 | backend | Repositórios PDO (ocupação atômica de leito, flowboard) + integração | T-01, T-03, T-04 | sim | alta | Athena | [x] |
| T-08 | backend | `BedService` — cadastro de leitos por unidade | T-03, T-06 | sim | média | Jaspion | [x] |
| T-09 | backend | `HospitalizationService` — admissão, transferência, evolução, parâmetros | T-03, T-06 | sim | alta | Athena | [x] |
| T-10 | backend | `HospitalizationOrderService` — prescrição, suspensão, administração, flowboard | T-03, T-04, T-06 | sim | alta | Arquimedes | [x] |
| T-11 | backend | Alta integrada — `HospitalizationDischargeService` + `EncounterAccountService::addSourcedItem` | T-03, T-04, T-06 | sim | alta | Aang | [x] |
| T-12 | frontend | Telas de leitos (`BedList`, `BedForm`) | T-07, T-08 | sim | média | Saitama | [x] |
| T-13 | frontend | Tela de admissão a partir do atendimento | T-07, T-09 | sim | média | Naruto | [x] |
| T-14 | frontend | Ficha da internação com transferência e alta | T-07, T-09, T-10, T-11 | sim | alta | Aang | [x] |
| T-15 | frontend | Formulários de prescrição, administração e evolução/parâmetros | T-07, T-09, T-10 | sim | alta | Kratos | [x] |
| T-16 | frontend | Flowboard do turno (tablet) + `HospitalizationBoardView` + CSS `cv-board-*` | T-04, T-07, T-09, T-10 | sim | alta | Tesla | [x] |
| T-17 | frontend | Navegação: menu, abas `CvNav`, ação no `EncounterView` | T-05 | sim | média | Jaspion | [x] |
| T-18 | frontend | i18n pt/en e mensagens de domínio da internação | T-12, T-13, T-14, T-15, T-16, T-17 | sim | média | Levi | [ ] |
| T-19 | docs | Runbook da internação | T-01, T-02, T-05, T-11 | sim | simples | Gandalf | [ ] |
| T-20 | qa | Validação final ponta a ponta e SQL de limpeza `F6 teste` | T-18, T-19 | não | média | Spock | [ ] |

## Detalhamento

### T-01 — Preparar migration 0010 (5 tabelas + 2 CHECKs ampliados), verify e provision do banco de teste

**Camada:** database
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** alta
**Agente:** Darwin

**Arquivos prováveis**
- `src/app/database/migrations/20261005_0010_phase6a_hospitalization.sql`
- `src/app/database/migrations/20261005_0010_phase6a_hospitalization.verify.sql`
- `scripts/test-db/provision.sh`

**Notas de implementação**

Cabeçalho no padrão de `20260925_0006_phase5_financial.sql`: `Migration: 20261005_0010_phase6a_hospitalization`, `Status: PREPARED ONLY. Do not execute without a fresh backup and explicit SQL approval.`, `Target: MySQL 8.0.x ... after 20261001_0009_landing_lead`, e as seções Effects (contagem de tabelas, CHECKs, UNIQUEs, FKs), Risk e Rollback (restaurar o backup ou migration reversa numerada; ver `docs/runbooks/migration-rollback.md`). Última instrução: `INSERT INTO schema_migrations (version, checksum)` com o placeholder de 64 zeros. `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci`. Todo CHECK é `CONSTRAINT <nome> CHECK (...)` nomeado, com no máximo 61 caracteres, e usa só colunas, literais, `IN`, `IS`, `NULL`, `NOT`, `AND`, `OR` e operadores de comparação (sem `BETWEEN`, `LIKE` nem funções, por causa do preparador 5.7). Todo `timestamp(6) NOT NULL` leva `DEFAULT CURRENT_TIMESTAMP(6)`, e todo timestamp opcional é `timestamp(6) NULL DEFAULT NULL`, porque o 5.7 sem `explicit_defaults_for_timestamp` daria `ON UPDATE` implícito à primeira coluna.

**Interface**
- Produz: `bed(id bigint unsigned AI, tenant_id bigint unsigned, system_unit_id int, code varchar(30), name varchar(120), daily_rate_cents int unsigned DEFAULT 0, status varchar(20) DEFAULT 'available', current_hospitalization_id bigint unsigned NULL, created_at, updated_at)` com `bed_unit_code_uq (tenant_id, system_unit_id, code)`, `bed_status_ck` (`status IN ('available', 'occupied', 'inactive')`), `bed_occupancy_ck` (`(status = 'occupied' AND current_hospitalization_id IS NOT NULL) OR (status <> 'occupied' AND current_hospitalization_id IS NULL)`), FKs para `tenant` e `system_unit` (sem FK em `current_hospitalization_id`)
- Produz: `hospitalization(id, tenant_id, system_unit_id int, patient_id bigint unsigned, encounter_id bigint unsigned, bed_id bigint unsigned, responsible_system_user_id int, admitted_by_system_user_id int, reason_text varchar(500), expected_discharge_date date NULL, daily_rate_cents int unsigned, status varchar(20) DEFAULT 'admitted', admitted_at timestamp(6), discharged_at timestamp(6) NULL, discharged_by_system_user_id int NULL, discharge_summary_text text NULL, created_at, updated_at)` com `hospitalization_status_ck` (`status IN ('admitted', 'discharged')`), `hospitalization_discharge_ck` (`(status = 'admitted' AND discharged_at IS NULL) OR (status = 'discharged' AND discharged_at IS NOT NULL)`), índices `hospitalization_unit_status_idx (tenant_id, system_unit_id, status)`, `hospitalization_patient_status_idx (tenant_id, patient_id, status)`, FKs para `tenant`, `system_unit`, `patient`, `encounter`, `bed` e `system_users` (3)
- Produz: `hospitalization_order(id, tenant_id, hospitalization_id, order_type varchar(20), description_text varchar(255), product_id bigint unsigned NULL, quantity_per_administration int unsigned NULL, dose_text varchar(120), route varchar(20), frequency_hours smallint unsigned, starts_at timestamp(6), ends_at timestamp(6), status varchar(20) DEFAULT 'active', prescribed_by_system_user_id int, suspended_at timestamp(6) NULL, created_at, updated_at)` com `hospitalization_order_type_ck` (`order_type IN ('medication', 'feeding', 'procedure')`), `hospitalization_order_route_ck` (`route IN ('oral', 'iv', 'im', 'sc', 'topical', 'inhalation', 'other')`), `hospitalization_order_status_ck` (`status IN ('active', 'suspended')`), `hospitalization_order_frequency_ck` (`frequency_hours >= 1 AND frequency_hours <= 168`), `hospitalization_order_period_ck` (`ends_at > starts_at`), `hospitalization_order_product_ck` (`(product_id IS NULL AND quantity_per_administration IS NULL) OR (product_id IS NOT NULL AND quantity_per_administration >= 1)`), FKs para `tenant`, `hospitalization`, `product` e `system_users`
- Produz: `hospitalization_administration(id, tenant_id, hospitalization_id, order_id, scheduled_at timestamp(6), status varchar(20) DEFAULT 'pending', performed_at timestamp(6) NULL, performed_by_system_user_id int NULL, notes_text varchar(500) NULL, created_at, updated_at)` com `hospitalization_administration_order_scheduled_uq (order_id, scheduled_at)`, `hospitalization_administration_status_ck` (`status IN ('pending', 'done', 'skipped', 'cancelled')`), `hospitalization_administration_performed_ck` (`(status IN ('done', 'skipped') AND performed_at IS NOT NULL AND performed_by_system_user_id IS NOT NULL) OR (status IN ('pending', 'cancelled') AND performed_at IS NULL)`), índices `hospitalization_administration_hosp_sched_idx (tenant_id, hospitalization_id, scheduled_at)` e `hospitalization_administration_status_sched_idx (tenant_id, status, scheduled_at)`, FKs para `tenant`, `hospitalization`, `hospitalization_order` e `system_users`
- Produz: `hospitalization_event(id, tenant_id, hospitalization_id, event_type varchar(20), recorded_by_system_user_id int, recorded_at timestamp(6), notes_text text NULL, temperature_c decimal(4,1) NULL, heart_rate_bpm smallint unsigned NULL, respiratory_rate_rpm smallint unsigned NULL, weight_kg decimal(6,2) NULL, pain_score tinyint unsigned NULL, from_bed_id bigint unsigned NULL, to_bed_id bigint unsigned NULL, created_at)` com `hospitalization_event_type_ck` (`event_type IN ('admission', 'transfer', 'evolution', 'vitals', 'discharge')`), `hospitalization_event_pain_ck` (`pain_score IS NULL OR pain_score <= 10`), índice `hospitalization_event_hosp_recorded_idx (tenant_id, hospitalization_id, recorded_at)`, FKs para `tenant`, `hospitalization`, `system_users` e `bed` (2)
- Produz: `ALTER TABLE encounter_account_item DROP CHECK encounter_account_item_source_type_ck` seguido, em instrução separada, de `ALTER TABLE encounter_account_item ADD CONSTRAINT encounter_account_item_source_type_ck CHECK (source_type IN ('procedure_execution', 'exam_request', 'manual', 'hospitalization_stay', 'hospitalization_administration'))`
- Produz: `ALTER TABLE stock_movement DROP CHECK stock_movement_reason_ck` seguido de `ALTER TABLE stock_movement ADD CONSTRAINT stock_movement_reason_ck CHECK (reason IN ('purchase_entry', 'procedure_consumption', 'sale_consumption', 'manual_adjustment', 'hospitalization_consumption'))`
- Consome: nada

**Teste RED**
- sem teste: migration SQL preparada e não executada (PREPARED ONLY); a estrutura é conferida por grep e, depois do bloqueio, pelo `.verify.sql` aplicado pelo orquestrador

**Critério de aceite**
- `grep -c "CREATE TABLE" src/app/database/migrations/20261005_0010_phase6a_hospitalization.sql` imprime `5` e o arquivo contém `Status: PREPARED ONLY` e 64 zeros no `INSERT INTO schema_migrations`.
- `python3 scripts/prepare-mysql57.py` sobre uma cópia `14-20261005_0010_phase6a_hospitalization.sql` num diretório temporário (com a versão de T-02) não acusa `Unsupported CHECK clause` e gera `DROP TRIGGER IF EXISTS` para os 2 CHECKs alterados.
- `.verify.sql` só tem `SELECT` (`grep -ciE '^(insert|update|delete|alter|create|drop)' ` imprime `0`) e lista as 5 tabelas, os 16 CHECKs (14 novos e 2 alterados) e as contagens de `encounter_account_item` e `stock_movement`.
- `scripts/test-db/provision.sh` lista `$migrations_dir/20261005_0010_phase6a_hospitalization.sql` depois da 0009.

**Validação**
- `/usr/bin/grep -c "CREATE TABLE" src/app/database/migrations/20261005_0010_phase6a_hospitalization.sql` (evidência: `5`)
- `/usr/bin/grep -oE "CONSTRAINT [a-z_]+_ck" src/app/database/migrations/20261005_0010_phase6a_hospitalization.sql | awk '{print length($2)}' | sort -n | tail -1` (evidência: número ≤ 61)
- `d=$(mktemp -d) && cp src/app/database/migrations/20261005_0010_phase6a_hospitalization.sql $d/14-20261005_0010_phase6a_hospitalization.sql && python3 scripts/prepare-mysql57.py $d $d/out` (evidência: `Prepared 1 stages` sem `Unsupported CHECK clause`; com a versão de T-02, o `.sql` gerado contém `DROP TRIGGER IF EXISTS`)
- `/usr/bin/grep -n "0010" scripts/test-db/provision.sh` (evidência: linha da 0010 depois da 0009)
- Depois do bloqueio (orquestrador): `.verify.sql` em `centralvet` e `centralvet_test` mostra as 5 tabelas e os CHECKs; `SELECT COUNT(*) FROM encounter_account_item` e `SELECT COUNT(*) FROM stock_movement` iguais aos de antes (registros existentes preservados).

### T-02 — Preparador MySQL 5.7: `DROP CHECK` e verificação de CHECK por tabela

**Camada:** infra
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Darwin

**Arquivos prováveis**
- `scripts/prepare-mysql57.py`
- `scripts/test-prepare-mysql57.py`
- `docs/runbooks/shared-hosting-mysql57.md`

**Interface**
- Produz: `adapt_statement("ALTER TABLE t DROP CHECK x_ck")` → `['DROP TRIGGER IF EXISTS `x_ck_bi`', 'DROP TRIGGER IF EXISTS `x_ck_bu`']` e nenhum `ALTER` restante quando a instrução só tinha o `DROP CHECK`
- Produz: verificação 5.7 que troca a consulta de `information_schema.check_constraints` por `information_schema.TRIGGERS` com `EVENT_OBJECT_TABLE` tirado do literal `table_name = '<t>'` (ou `table_name IN ('<a>', '<b>')`) da própria consulta; sem esse literal, `ValueError`
- Consome: nada

**Teste RED**
- `scripts/test-prepare-mysql57.py` — `test_drop_check_becomes_drop_triggers` (`ALTER TABLE stock_movement DROP CHECK stock_movement_reason_ck` → dois `DROP TRIGGER IF EXISTS`) e `test_verify_uses_table_from_query` (consulta com `tc.table_name = 'bed'` → `EVENT_OBJECT_TABLE='bed'`) falham hoje: o primeiro mantém o `DROP CHECK` no ALTER e o segundo fixa `landing_lead` (comando: `python3 scripts/test-prepare-mysql57.py`)

**Critério de aceite**
- PYTEST57 imprime `Ran 8 tests` (ou mais) e nenhuma falha; os 6 testes da baseline continuam passando.
- Rodar o preparador sobre a cópia `14-...0010...sql` gera, para `encounter_account_item_source_type_ck`, `DROP TRIGGER IF EXISTS` dos dois triggers antes dos dois `CREATE TRIGGER` de mesmo nome.
- O `.verify.sql` da 0009 continua gerando `EVENT_OBJECT_TABLE='landing_lead'`.
- O runbook ganha a seção "Migration 0010 (internação)" com o comando de preparo e a ordem drop → create dos triggers.

**Validação**
- `python3 scripts/test-prepare-mysql57.py` (evidência: `Ran 8 tests` e linha final sem `FAILED`; comparar com `baseline/test-prepare-mysql57.txt`)
- `d=$(mktemp -d) && cp src/app/database/migrations/20261001_0009_landing_lead.verify.sql $d/ && cp var/sql-bootstrap/online/13-20261001_0009_landing_lead.sql $d/ && python3 scripts/prepare-mysql57.py $d $d/out && /usr/bin/grep -c "landing_lead" $d/out/manifest.json` (evidência: contagem ≥ 1, regressão da 0009)

### T-03 — Domain de leito, internação e evento + contratos + tipos de conta e motivo de estoque

**Camada:** backend
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** alta
**Agente:** Platão

**Arquivos prováveis**
- `src/app/Core/Domain/Bed.php`
- `src/app/Core/Domain/Hospitalization.php`
- `src/app/Core/Domain/HospitalizationEvent.php`
- `src/app/Core/Domain/Contract/BedRepositoryInterface.php`
- `src/app/Core/Domain/Contract/HospitalizationRepositoryInterface.php`
- `src/app/Core/Domain/Contract/HospitalizationEventRepositoryInterface.php`
- `src/app/Core/Domain/Exception/BedUnavailableException.php`
- `src/app/Core/Domain/Exception/PatientAlreadyHospitalizedException.php`
- `src/app/Core/Domain/EncounterAccountItem.php`
- `src/app/Core/Domain/StockMovement.php`
- `src/tests/Unit/HospitalizationDomainTest.php`

**Notas de implementação**

Padrão do Domain: `final class`, construtor privado, factory estática, `reconstitute(array $row)` com as chaves das colunas do schema de T-01 (`Interface`), `assignId(int)`, getters. Erro de entrada → `InvalidArgumentException` com mensagem crua em inglês; transição inválida → `InvalidStatusTransitionException`. As mensagens abaixo são literais (T-18 as mapeia em `UserMessage`).

**Interface**
- Produz: `Bed::create(int $tenantId, int $systemUnitId, string $code, string $name, int $dailyRateCents): self` (code 1–30 após trim, name 1–120, rate ≥ 0); `Bed::STATUS_AVAILABLE = 'available'`, `Bed::STATUS_OCCUPIED = 'occupied'`, `Bed::STATUS_INACTIVE = 'inactive'`; métodos `rename(string $name): void`, `changeDailyRate(int $dailyRateCents): void`, `deactivate(): void` (ocupado → `BedUnavailableException` com `Bed <id> is occupied and cannot be deactivated`), `activate(): void`; getters `id(): ?int`, `tenantId(): int`, `systemUnitId(): int`, `code(): string`, `name(): string`, `dailyRateCents(): int`, `status(): string`, `currentHospitalizationId(): ?int`, `isAvailable(): bool`
- Produz: `Hospitalization::admit(int $tenantId, int $systemUnitId, int $patientId, int $encounterId, int $bedId, int $responsibleSystemUserId, int $admittedBySystemUserId, string $reasonText, ?DateTimeImmutable $expectedDischargeDate, int $dailyRateCents, DateTimeImmutable $admittedAt): self` (reason 1–500, obrigatório → `reason_text is required`); `Hospitalization::STATUS_ADMITTED = 'admitted'`, `Hospitalization::STATUS_DISCHARGED = 'discharged'`; `moveToBed(int $bedId): void`; `discharge(DateTimeImmutable $at, int $dischargedBySystemUserId, string $summaryText): void`; `billableDays(DateTimeImmutable $until): int` = `max(1, ceil(horas/24))`; fora de `admitted`, `moveToBed`/`discharge` lançam `InvalidStatusTransitionException` com `Hospitalization <id> is not admitted`; getters `id()`, `tenantId()`, `systemUnitId()`, `patientId()`, `encounterId()`, `bedId()`, `responsibleSystemUserId()`, `reasonText()`, `expectedDischargeDate(): ?DateTimeImmutable`, `dailyRateCents()`, `status()`, `admittedAt(): DateTimeImmutable`, `dischargedAt(): ?DateTimeImmutable`, `dischargeSummaryText(): ?string`
- Produz: `HospitalizationEvent::record(int $tenantId, int $hospitalizationId, string $eventType, int $recordedBySystemUserId, DateTimeImmutable $recordedAt, string $notesText, ?float $temperatureC = null, ?int $heartRateBpm = null, ?int $respiratoryRateRpm = null, ?float $weightKg = null, ?int $painScore = null, ?int $fromBedId = null, ?int $toBedId = null): self`; `HospitalizationEvent::TYPE_ADMISSION = 'admission'`, `TYPE_TRANSFER = 'transfer'`, `TYPE_EVOLUTION = 'evolution'`, `TYPE_VITALS = 'vitals'`, `TYPE_DISCHARGE = 'discharge'`; evolução sem texto → `notes_text is required`; parâmetros sem nenhum valor → `At least one vital sign is required`; dor fora de 0–10 → `pain_score must be between 0 and 10`; transferência sem os dois leitos → `InvalidArgumentException`
- Produz: `BedUnavailableException::forBed(int $bedId): self` com mensagem `Bed <id> is not available`; `PatientAlreadyHospitalizedException::forPatient(int $patientId): self` com mensagem `Patient <id> already has an active hospitalization` (ambas `extends \DomainException`)
- Produz: `BedRepositoryInterface` (estende `TenantRepositoryInterface`) com `listByUnit(int $systemUnitId): array`, `findByCode(int $systemUnitId, string $code): ?object`, `occupy(int $bedId, int $hospitalizationId): bool`, `release(int $bedId, int $hospitalizationId): bool`
- Produz: `HospitalizationRepositoryInterface` com `findActiveByPatient(int $patientId): ?object`, `listActiveByUnit(int $systemUnitId): array`
- Produz: `HospitalizationEventRepositoryInterface` com `listByHospitalization(int $hospitalizationId): array` (mais recente primeiro)
- Produz: `EncounterAccountItem::TYPE_HOSPITALIZATION_STAY = 'hospitalization_stay'`, `EncounterAccountItem::TYPE_HOSPITALIZATION_ADMINISTRATION = 'hospitalization_administration'` (incluídos em `TYPES`, com `source_id` positivo obrigatório)
- Produz: `StockMovement::REASON_HOSPITALIZATION_CONSUMPTION = 'hospitalization_consumption'` (incluído em `REASONS`)
- Consome: nada (segue o schema fixado em T-01 § Interface, mesma onda)

**Teste RED**
- `src/tests/Unit/HospitalizationDomainTest.php` — `billableDays` de internação admitida em 2026-10-01 10:00 vale 1 até 2026-10-01 10:30 e 2 até 2026-10-03 09:00; `Bed::deactivate()` de leito ocupado lança `BedUnavailableException`; `moveToBed` depois de `discharge` lança `InvalidStatusTransitionException`; `EncounterAccountItem::create(..., 'hospitalization_stay', 7, ...)` é aceito. Falha hoje porque as classes e o tipo não existem (comando: `SUITE | /usr/bin/grep -E 'HospitalizationDomainTest|Failed:'`)

**Critério de aceite**
- A SUITE lista `HospitalizationDomainTest` com todos os métodos `PASS` e `Failed: 0`; `EncounterAccountServiceTest` e `StockServiceTest` seguem `PASS`.
- Cada PHP tocado imprime `No syntax errors detected` no LINT (nenhum erro novo em relação a `baseline/php-lint.txt`).
- `CoreLayerDependencyTest` segue `PASS` (Domain sem dependência de Presentation/Adianti).

**Validação**
- `SUITE | /usr/bin/grep -E 'HospitalizationDomainTest|EncounterAccountServiceTest|StockServiceTest|CoreLayerDependencyTest|Failed:'` (evidência: `PASS` em todos e `Failed: 0`)
- LINT em cada arquivo de "Arquivos prováveis" (evidência: `No syntax errors detected` em todos)

### T-04 — Domain de prescrição interna, administração e agenda + contratos

**Camada:** backend
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** alta
**Agente:** Arquimedes

**Arquivos prováveis**
- `src/app/Core/Domain/HospitalizationOrder.php`
- `src/app/Core/Domain/HospitalizationAdministration.php`
- `src/app/Core/Domain/AdministrationSchedule.php`
- `src/app/Core/Domain/Contract/HospitalizationOrderRepositoryInterface.php`
- `src/app/Core/Domain/Contract/HospitalizationAdministrationRepositoryInterface.php`
- `src/tests/Unit/AdministrationScheduleTest.php`

**Interface**
- Produz: `AdministrationSchedule::generate(DateTimeImmutable $startsAt, DateTimeImmutable $endsAt, int $frequencyHours): array` (lista de `DateTimeImmutable` a partir de `startsAt`, passo de `frequencyHours`, fim exclusivo); `AdministrationSchedule::MAX_PERIOD_DAYS = 30`; erros `ends_at must be after starts_at`, `Prescription period cannot exceed 30 days`, `frequency_hours must be between 1 and 168` (`InvalidArgumentException`)
- Produz: `HospitalizationOrder::prescribe(int $tenantId, int $hospitalizationId, string $orderType, string $descriptionText, ?int $productId, ?int $quantityPerAdministration, string $doseText, string $route, int $frequencyHours, DateTimeImmutable $startsAt, DateTimeImmutable $endsAt, int $prescribedBySystemUserId): self`; `HospitalizationOrder::TYPE_MEDICATION = 'medication'`, `TYPE_FEEDING = 'feeding'`, `TYPE_PROCEDURE = 'procedure'`; `HospitalizationOrder::ROUTES = ['oral', 'iv', 'im', 'sc', 'topical', 'inhalation', 'other']` (public); `HospitalizationOrder::STATUS_ACTIVE = 'active'`, `STATUS_SUSPENDED = 'suspended'`; `suspend(DateTimeImmutable $at): void`; produto sem quantidade → `quantity_per_administration is required when a product is selected`; getters `id()`, `tenantId()`, `hospitalizationId()`, `orderType()`, `descriptionText()`, `productId(): ?int`, `quantityPerAdministration(): ?int`, `doseText()`, `route()`, `frequencyHours()`, `startsAt()`, `endsAt()`, `status()`
- Produz: `HospitalizationAdministration::schedule(int $tenantId, int $hospitalizationId, int $orderId, DateTimeImmutable $scheduledAt): self`; `HospitalizationAdministration::STATUS_PENDING = 'pending'`, `STATUS_DONE = 'done'`, `STATUS_SKIPPED = 'skipped'`, `STATUS_CANCELLED = 'cancelled'`; `markDone(DateTimeImmutable $at, int $performedBySystemUserId, string $notesText): void`; `markSkipped(DateTimeImmutable $at, int $performedBySystemUserId, string $notesText): void` (texto vazio → `notes_text is required`); `cancel(): void`; fora de `pending` → `InvalidStatusTransitionException` com `Administration <id> is not pending`; getters `id()`, `tenantId()`, `hospitalizationId()`, `orderId()`, `scheduledAt()`, `status()`, `performedAt(): ?DateTimeImmutable`, `performedBySystemUserId(): ?int`, `notesText(): ?string`
- Produz: `HospitalizationAdministration::classify(string $status, DateTimeImmutable $scheduledAt, ?DateTimeImmutable $performedAt, DateTimeImmutable $now): string` com `LATE_TOLERANCE_MINUTES = 30` e retornos `TIMELINESS_UPCOMING = 'upcoming'` (pendente, agora < horário), `TIMELINESS_DUE = 'due'` (pendente, horário ≤ agora ≤ horário + 30 min), `TIMELINESS_LATE = 'late'` (pendente, agora > horário + 30 min), `TIMELINESS_DONE = 'done'` (feito até horário + 30 min), `TIMELINESS_DONE_LATE = 'done_late'`, `TIMELINESS_SKIPPED = 'skipped'`, `TIMELINESS_CANCELLED = 'cancelled'`
- Produz: `HospitalizationOrderRepositoryInterface` com `listByHospitalization(int $hospitalizationId): array`
- Produz: `HospitalizationAdministrationRepositoryInterface` com `listByHospitalization(int $hospitalizationId): array`, `listPendingByOrder(int $orderId): array`, `listPendingByHospitalization(int $hospitalizationId): array`, `listBoardRows(int $systemUnitId, DateTimeImmutable $from, DateTimeImmutable $to): array`, cada linha `array{administration_id: int, hospitalization_id: int, patient_name: string, bed_code: string, order_type: string, description_text: string, dose_text: string, route: string, scheduled_at: string, status: string, performed_at: ?string}` (datas `Y-m-d H:i:s`). Entram só internações `admitted` da unidade: pendentes com `scheduled_at <= to` (inclui atrasadas antes de `from`) e `done`/`skipped` com `scheduled_at` entre `from` e `to`, ordenadas por `scheduled_at`
- Consome: nada (segue o schema fixado em T-01 § Interface, mesma onda)

**Teste RED**
- `src/tests/Unit/AdministrationScheduleTest.php` — `generate(2026-10-01 08:00, 2026-10-04 08:00, 8)` devolve 9 horários, de 08:00 de 01/10 a 00:00 de 04/10; período de 31 dias lança `Prescription period cannot exceed 30 days`; `classify('pending', 10:00, null, 10:31)` = `late`, `classify('pending', 10:00, null, 10:30)` = `due`, `classify('done', 10:00, 10:45, 11:00)` = `done_late`; `markDone` duas vezes lança `Administration <id> is not pending`. Falha hoje porque as classes não existem (comando: `SUITE | /usr/bin/grep -E 'AdministrationScheduleTest|Failed:'`)

**Critério de aceite**
- A SUITE lista `AdministrationScheduleTest` com todos os métodos `PASS` e `Failed: 0`.
- Cada PHP tocado imprime `No syntax errors detected` no LINT.

**Validação**
- `SUITE | /usr/bin/grep -E 'AdministrationScheduleTest|CoreLayerDependencyTest|Failed:'` (evidência: `PASS` e `Failed: 0`)
- LINT em cada arquivo de "Arquivos prováveis" (evidência: `No syntax errors detected`)

### T-05 — Programas RBAC das 8 telas e grupo `Clínico – Internação` (seed + DML, verify e rollback preparados)

**Camada:** infra
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Jaspion

**Arquivos prováveis**
- `src/app/database/seeds/initial-application-programs.sql`
- `.claude/tasks/mar-20261005-1521-fase-6a-internacao/sql/T-05-programs.sql`
- `.claude/tasks/mar-20261005-1521-fase-6a-internacao/sql/T-05-programs.verify.sql`
- `.claude/tasks/mar-20261005-1521-fase-6a-internacao/sql/T-05-programs.rollback.sql`

**Notas de implementação**

Modelos: o bloco de `EncounterAccountForm` no seed (idempotente, `COALESCE(MAX(id),0)+1 ... WHERE NOT EXISTS`) e `.claude/tasks/mar-20260930-0823-rodada-2-divida-cadastros-schema-acoes/sql/T-01-programs.sql` (cabeçalho com o estado lido por SELECT, `START TRANSACTION`, SELECT de conferência, `COMMIT`).

Estado lido em 2026-10-05 (só SELECT):
- `system_group` (`id int NOT NULL` sem AUTO_INCREMENT, `name varchar(256)`) tem `MAX(id)=3`: 1 `Template - Admin`, 2 `Template - Users`, 3 `Application - Programs`.
- `system_program` tem `MAX(id)=109`; `system_group_program` tem `MAX(id)=111`.
- A sessão do cliente `mysql` vem com `character_set_client=latin1`, o que explica o nome acentuado com encoding duplo da rodada 2.

Regras dos três arquivos SQL:
- **Sem id fixo.** Cada INSERT deriva o id no próprio comando, `INSERT INTO t (id, ...) SELECT (SELECT COALESCE(MAX(id),0)+1 FROM t cur), ... WHERE NOT EXISTS (...)`, uma linha por comando. Assim a DML é idempotente e não depende de o `MAX(id)` ter mudado.
- **Compatível com MySQL 5.7.** Sem `ROW_NUMBER()`, CTE nem variável de sessão para id.
- **`SET NAMES utf8mb4;`** é a primeira instrução dos três arquivos.
- **Nome do grupo.** É exatamente `Clínico – Internação` (í, travessão U+2013, ç, ã), com 20 caracteres e 25 bytes em utf8mb4, e é localizado sempre por `name = 'Clínico – Internação'`.
- **Nomes de programas** ASCII em inglês.

`T-05-programs.sql` (DML do banco atual, só o orquestrador aplica, no bloqueio entre as Ondas 1 e 2, com aprovação SQL), em `START TRANSACTION`:
1. INSERT do grupo `Clínico – Internação` em `system_group` se não existir.
2. 8 INSERTs em `system_program`.
3. 8 concessões ao grupo 1 e 8 ao grupo novo em `system_group_program` (o id do grupo novo vem de `SELECT id FROM system_group WHERE name = 'Clínico – Internação'`, nunca literal).
4. SELECTs de conferência.
5. `COMMIT`.

`T-05-programs.verify.sql` (só SELECT):
- o grupo novo existe uma vez, com `CHAR_LENGTH(name) = 20` e `LENGTH(name) = 25` (prova de que não houve encoding duplo);
- contagem dos 8 controllers em `system_program`;
- concessões por grupo para os 8 controllers: grupo 1 = 8, grupo novo = 8, grupos 2 e 3 = 0;
- `COUNT(*)` de `system_program`, `system_group` e `system_group_program`, para comparar com os valores de antes registrados em `notes.md § Bloqueios`.

`T-05-programs.rollback.sql` (preparado, nunca executado sem nova aprovação), em `START TRANSACTION`:
1. DELETE em `system_group_program` só das linhas dos 8 controllers nos grupos 1 e `Clínico – Internação`.
2. DELETE em `system_user_group` do grupo novo.
3. DELETE em `system_group_program` restantes do grupo novo.
4. DELETE do grupo novo em `system_group`.
5. DELETE dos 8 controllers em `system_program`.
6. SELECTs de conferência e `COMMIT` comentado.

Todo DELETE tem `WHERE` por nome de controller ou de grupo. O preferido continua sendo restaurar o backup do bloqueio.

O seed (`initial-application-programs.sql`, instalação nova) recebe o mesmo grupo e as mesmas concessões, de forma idempotente, dentro da transação que já existe.

**Interface**
- Produz: grupo `system_group.name = 'Clínico – Internação'` (id derivado de `MAX(id)+1` na aplicação)
- Produz: programas `BedList` (`Central Vet - Bed List`), `BedForm` (`Central Vet - Bed Form`), `HospitalizationBoard` (`Central Vet - Hospitalization Board`), `HospitalizationAdmissionForm` (`Central Vet - Hospitalization Admission Form`), `HospitalizationView` (`Central Vet - Hospitalization View`), `HospitalizationOrderForm` (`Central Vet - Hospitalization Order Form`), `HospitalizationAdministrationForm` (`Central Vet - Hospitalization Administration Form`), `HospitalizationEventForm` (`Central Vet - Hospitalization Event Form`), cada um em `system_group_program` do grupo 1 (`Template - Admin`) e do grupo `Clínico – Internação`, e em nenhum outro
- Consome: nada

**Teste RED**
- sem teste: DML SQL preparada (programas, grupo, verify e rollback), sem execução; conferida por grep e, depois do bloqueio, pelo verify do orquestrador

**Critério de aceite**
- `/usr/bin/grep -oE "controller='(Bed|Hospitalization)[A-Za-z]*'" src/app/database/seeds/initial-application-programs.sql | sort -u | wc -l` imprime `8`; o seed contém `Clínico – Internação` e segue numa transação só.
- Os três arquivos de `sql/` começam com `SET NAMES utf8mb4;`. `T-05-programs.sql` não tem id literal nos INSERTs, porque todos os ids saem de `COALESCE(MAX(id),0)+1`. Também não tem `ROW_NUMBER`/`WITH`, e cria 8 concessões para o grupo 1 e 8 para `Clínico – Internação`.
- Nenhuma concessão aos grupos 2 e 3: nos INSERTs de `system_group_program`, o grupo é `1` ou vem do SELECT por `name = 'Clínico – Internação'`.
- `T-05-programs.rollback.sql` não tem DELETE sem `WHERE` (`/usr/bin/grep -ciE "delete from [a-z_]+ *;"` imprime `0`) e cobre `system_group_program`, `system_user_group`, `system_group` e `system_program`.
- Depois do bloqueio (orquestrador), o `T-05-programs.verify.sql` mostra:
  - 8 linhas de concessão no grupo 1 e 8 no grupo `Clínico – Internação`;
  - 0 nos grupos 2 e 3;
  - o grupo com `CHAR_LENGTH(name) = 20` e `LENGTH(name) = 25`;
  - `COUNT(*)` de `system_program` = anterior + 8, de `system_group` = anterior + 1 e de `system_group_program` = anterior + 16, com os registros existentes preservados.

**Validação**
- `/usr/bin/grep -oE "controller='(Bed|Hospitalization)[A-Za-z]*'" src/app/database/seeds/initial-application-programs.sql | sort -u | wc -l` (evidência: `8`)
- `cd /var/www/html/centralvet/.claude/tasks/mar-20261005-1521-fase-6a-internacao && for f in sql/T-05-programs.sql sql/T-05-programs.verify.sql sql/T-05-programs.rollback.sql; do /usr/bin/grep -v '^--' $f | /usr/bin/grep -m1 -v '^[[:space:]]*$'; done` (evidência: três linhas `SET NAMES utf8mb4;`)
- `/usr/bin/grep -ciE "row_number|with recursive|values *\( *1[0-9]{2}" .claude/tasks/mar-20261005-1521-fase-6a-internacao/sql/T-05-programs.sql` (evidência: `0`, sem id fixo nem função de janela)
- `/usr/bin/grep -cE "^ *(SELECT|SET NAMES)" .claude/tasks/mar-20261005-1521-fase-6a-internacao/sql/T-05-programs.verify.sql` comparado com o total de instruções (evidência: só SELECT além do `SET NAMES`)
- Depois do bloqueio (orquestrador): `docker compose exec -T mysql sh -c 'mysql --default-character-set=utf8mb4 -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" centralvet' < .claude/tasks/mar-20261005-1521-fase-6a-internacao/sql/T-05-programs.verify.sql`, só leitura (evidência: grupo 1 = 8, `Clínico – Internação` = 8, grupos 2 e 3 = 0, `CHAR_LENGTH` 20 e `LENGTH` 25, contagens = anterior + 8 / + 1 / + 16)
- Prova de acesso: não há usuário em grupo clínico (o único usuário é `admin`, nos grupos 1, 2 e 3), então a verificação é por banco, pelo verify acima. O gate da Onda 4 navega como `admin` (grupo 1).

### T-06 — Fakes dos 5 repositórios da internação

**Camada:** shared
**Dependências:** T-03, T-04
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Platão

**Arquivos prováveis**
- `src/tests/Support/FakeBedRepository.php`
- `src/tests/Support/FakeHospitalizationRepository.php`
- `src/tests/Support/FakeHospitalizationOrderRepository.php`
- `src/tests/Support/FakeHospitalizationAdministrationRepository.php`
- `src/tests/Support/FakeHospitalizationEventRepository.php`
- `src/tests/Unit/HospitalizationFakesTest.php`

**Notas de implementação**

Padrão de `src/tests/Support/FakeEncounterAccountRepository.php`: `final class`, construtor `(int $tenantId, <Entidade> ...$seed)`, `save` atribui id incremental, filtro por tenant.

**Interface**
- Produz: `FakeBedRepository(int $tenantId, Bed ...$seed)` com `occupy` que só devolve `true` se o leito está `available` (e passa a `occupied` com o id da internação) e `release` que só devolve `true` se `current_hospitalization_id` confere
- Produz: `FakeHospitalizationRepository(int $tenantId, Hospitalization ...$seed)`, `FakeHospitalizationOrderRepository(int $tenantId, HospitalizationOrder ...$seed)`, `FakeHospitalizationEventRepository(int $tenantId, HospitalizationEvent ...$seed)`
- Produz: `FakeHospitalizationAdministrationRepository(int $tenantId, HospitalizationAdministration ...$seed)` com `seedBoardRows(array $rows): void`, cujas linhas `listBoardRows` devolve sem filtro
- Consome: T-03 `occupy(int $bedId, int $hospitalizationId): bool`, T-04 `listBoardRows(int $systemUnitId, DateTimeImmutable $from, DateTimeImmutable $to): array`

**Teste RED**
- `src/tests/Unit/HospitalizationFakesTest.php` — o segundo `occupy` do mesmo leito devolve `false`; `release` com id de internação errado devolve `false`; `findById` de outro tenant devolve `null`. Falha hoje porque os Fakes não existem (comando: `SUITE | /usr/bin/grep -E 'HospitalizationFakesTest|Failed:'`)

**Critério de aceite**
- A SUITE lista `HospitalizationFakesTest` `PASS` e `Failed: 0`; cada Fake implementa a interface correspondente (`instanceof` no teste).
- Cada PHP tocado imprime `No syntax errors detected` no LINT.

**Validação**
- `SUITE | /usr/bin/grep -E 'HospitalizationFakesTest|Failed:'` (evidência: `PASS` e `Failed: 0`)
- LINT nos 6 arquivos (evidência: `No syntax errors detected`)

### T-07 — Repositórios PDO (ocupação atômica de leito, flowboard) + integração

**Camada:** backend
**Dependências:** T-01, T-03, T-04
**Paralelizável:** sim
**Complexidade:** alta
**Agente:** Athena

**Arquivos prováveis**
- `src/app/Core/Persistence/BedRepository.php`
- `src/app/Core/Persistence/HospitalizationRepository.php`
- `src/app/Core/Persistence/HospitalizationOrderRepository.php`
- `src/app/Core/Persistence/HospitalizationAdministrationRepository.php`
- `src/app/Core/Persistence/HospitalizationEventRepository.php`
- `src/tests/Integration/HospitalizationRepositoryIntegrationTest.php`

**Notas de implementação**

Padrão de `src/app/Core/Persistence/EncounterAccountItemRepository.php`: construtor `(TenantContext $context, PDO $pdo)`, `extends AbstractTenantRepository`, toda consulta via `tenantQuery()`, `save` com `assertEntityTenant()`. Pré-requisito: 0010 aplicada no `centralvet_test` (bloqueio entre Ondas 1 e 2). O teste estende `MysqlIntegrationTestCase` (transação revertida) e cria as fixtures como `src/tests/Integration/EncounterRepositoryIntegrationTest.php`.

**Interface**
- Produz: `BedRepository::occupy` = `UPDATE bed SET status='occupied', current_hospitalization_id=:h WHERE id=:id AND tenant_id=:t AND status='available'` (devolve `rowCount() === 1`); `release` = `UPDATE bed SET status='available', current_hospitalization_id=NULL WHERE id=:id AND tenant_id=:t AND current_hospitalization_id=:h`; `save` de leito existente atualiza só `name`, `daily_rate_cents` e `status`, nunca `current_hospitalization_id`. Ao gravar `inactive`, o UPDATE leva `AND current_hospitalization_id IS NULL`; com 0 linhas, lança `BedUnavailableException`
- Produz: `HospitalizationAdministrationRepository::listBoardRows` com JOIN `hospitalization` (status `admitted`, `system_unit_id`), `patient.name`, `bed.code` e `hospitalization_order`, todas com `tenant_id` do contexto
- Consome: T-01 `hospitalization_administration_order_scheduled_uq (order_id, scheduled_at)`, T-03 `occupy(int $bedId, int $hospitalizationId): bool`, T-04 `listBoardRows(int $systemUnitId, DateTimeImmutable $from, DateTimeImmutable $to): array`

**Teste RED**
- `src/tests/Integration/HospitalizationRepositoryIntegrationTest.php` — no `centralvet_test`: dois `occupy` seguidos no mesmo leito devolvem `true` e `false`; `findById` de leito de outro tenant devolve `null`; `listBoardRows` traz a administração pendente atrasada de 3 h antes de `from` e não traz a de internação `discharged`. Falha hoje porque os repositórios não existem (comando: `SUITE | /usr/bin/grep -E 'HospitalizationRepositoryIntegrationTest|Failed:'`)

**Critério de aceite**
- A SUITE lista `HospitalizationRepositoryIntegrationTest` `PASS` (não `SKIP`) e `Failed: 0`; `MysqlIsolationGuardIntegrationTest` segue `PASS`.
- `/usr/bin/grep -L "tenantQuery\|tenant_id" src/app/Core/Persistence/{Bed,Hospitalization,HospitalizationOrder,HospitalizationAdministration,HospitalizationEvent}Repository.php` não imprime nenhum arquivo.
- Cada PHP tocado imprime `No syntax errors detected` no LINT.

**Validação**
- `SUITE | /usr/bin/grep -E 'HospitalizationRepositoryIntegrationTest|MysqlIsolationGuardIntegrationTest|Failed:'` (evidência: `PASS` e `Failed: 0`)
- `docker compose exec -T mysql sh -c 'mysql -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" centralvet_test -e "SELECT COUNT(*) FROM bed; SELECT COUNT(*) FROM hospitalization"'` só leitura (evidência: contagens iguais às de antes da SUITE: o teste não deixou linhas)
- LINT nos 6 arquivos (evidência: `No syntax errors detected`)

### T-08 — `BedService` — cadastro de leitos por unidade

**Camada:** backend
**Dependências:** T-03, T-06
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Jaspion

**Arquivos prováveis**
- `src/app/Core/Application/BedService.php`
- `src/tests/Unit/BedServiceTest.php`

**Interface**
- Produz: `BedService::__construct(BedRepositoryInterface $beds, AuthorizationPolicyInterface $authorization, TenantContext $context)`
- Produz: `create(string $code, string $name, int $dailyRateCents, string $action): Bed` (unidade = `$context->requireUnitId()`; código repetido na unidade → `InvalidArgumentException` com `A bed with code "<code>" already exists in this unit`); `update(int $bedId, string $name, int $dailyRateCents, string $action): Bed`; `deactivate(int $bedId, string $action): Bed`; `activate(int $bedId, string $action): Bed`; `get(int $bedId, string $action): Bed`; `listForCurrentUnit(string $action): array`; `listAvailableForUnit(int $systemUnitId, string $action): array`
- Produz: leito inexistente no tenant → `CrossTenantReferenceException` com `Bed <id> not found for this tenant`; toda mutação autoriza com `resourceUnitId` = `systemUnitId()` do leito persistido, `entityType: 'bed'`
- Consome: T-03 `Bed::create(int $tenantId, int $systemUnitId, string $code, string $name, int $dailyRateCents): self`, T-03 `findByCode(int $systemUnitId, string $code): ?object`

**Teste RED**
- `src/tests/Unit/BedServiceTest.php` — `create` com código já usado na unidade lança `A bed with code "L1" already exists in this unit`; `update` de leito de outra unidade com `FakeAuthorizationPolicy(allowed: false)` lança `AuthorizationDenied` sem gravar; `deactivate` de leito ocupado lança `BedUnavailableException`. Falha hoje porque o service não existe (comando: `SUITE | /usr/bin/grep -E 'BedServiceTest|Failed:'`)

**Critério de aceite**
- A SUITE lista `BedServiceTest` `PASS` e `Failed: 0`; o teste confere em `FakeAuthorizationPolicy->requests` o `resourceUnitId` do leito persistido.
- Cada PHP tocado imprime `No syntax errors detected` no LINT.

**Validação**
- `SUITE | /usr/bin/grep -E 'BedServiceTest|Failed:'` (evidência: `PASS` e `Failed: 0`)
- LINT nos 2 arquivos (evidência: `No syntax errors detected`)

### T-09 — `HospitalizationService` — admissão, transferência, evolução, parâmetros

**Camada:** backend
**Dependências:** T-03, T-06
**Paralelizável:** sim
**Complexidade:** alta
**Agente:** Athena

**Arquivos prováveis**
- `src/app/Core/Application/HospitalizationService.php`
- `src/tests/Unit/HospitalizationServiceTest.php`

**Interface**
- Produz: `HospitalizationService::__construct(HospitalizationRepositoryInterface $hospitalizations, BedRepositoryInterface $beds, HospitalizationEventRepositoryInterface $events, EncounterRepositoryInterface $encounters, EncounterAccountRepositoryInterface $accounts, TenantUserDirectoryInterface $users, AuthorizationPolicyInterface $authorization, TenantContext $context, ?\Closure $clock = null)` (`$clock` devolve `DateTimeImmutable`; nulo = `new DateTimeImmutable()`)
- Produz: `admit(int $encounterId, int $bedId, int $responsibleSystemUserId, string $reasonText, ?string $expectedDischargeDate, string $action): Hospitalization`. A data é `Y-m-d` ou nula. A ordem das checagens:
  1. Atendimento do tenant, senão `CrossTenantReferenceException`.
  2. Autoriza com `resourceUnitId` = unidade do atendimento.
  3. Leito ativo da mesma unidade, senão `BedUnavailableException`.
  4. Responsável membro ativo (`isActiveMember`), senão `InvalidArgumentException` com `responsible_system_user_id must be an active user of this tenant`.
  5. Paciente sem internação `admitted`, senão `PatientAlreadyHospitalizedException`.
  6. Conta do atendimento, se existir, `open`, senão `InvalidStatusTransitionException` com `Encounter account <id> cannot be modified: status is "<status>", not "open"`.
  7. Salva a internação com `daily_rate_cents` do leito.
  8. `occupy`; com `false`, lança `BedUnavailableException`, e o controller desfaz a transação.
  9. Grava o evento `admission`.
- Produz: `transfer(int $hospitalizationId, int $toBedId, string $action): Hospitalization`. Ocupa o novo leito, libera o antigo, chama `moveToBed` e grava o evento `transfer` com `from_bed_id`/`to_bed_id`; leito indisponível → `BedUnavailableException`.
- Produz: `recordEvolution(int $hospitalizationId, string $notesText, string $action): HospitalizationEvent`; `recordVitals(int $hospitalizationId, ?float $temperatureC, ?int $heartRateBpm, ?int $respiratoryRateRpm, ?float $weightKg, ?int $painScore, string $notesText, string $action): HospitalizationEvent`. Ambos exigem internação `admitted`.
- Produz: `get(int $hospitalizationId, string $action): Hospitalization` (inexistente → `CrossTenantReferenceException` com `Hospitalization <id> not found for this tenant`); `listEvents(int $hospitalizationId, string $action): array`; `listActiveForCurrentUnit(string $action): array`. Leituras autorizam com a unidade da internação ou do contexto.
- Consome: T-03 `Hospitalization::admit(int $tenantId, int $systemUnitId, int $patientId, int $encounterId, int $bedId, int $responsibleSystemUserId, int $admittedBySystemUserId, string $reasonText, ?DateTimeImmutable $expectedDischargeDate, int $dailyRateCents, DateTimeImmutable $admittedAt): self`, T-03 `occupy(int $bedId, int $hospitalizationId): bool`, T-03 `findActiveByPatient(int $patientId): ?object`

**Teste RED**
- `src/tests/Unit/HospitalizationServiceTest.php` — admitir paciente já internado lança `PatientAlreadyHospitalizedException`; admitir em leito já ocupado lança `Bed <id> is not available` e nenhum evento é gravado; transferir libera o leito antigo (`available`) e ocupa o novo (`occupied`) com evento `transfer`; leito de outra unidade lança `BedUnavailableException`. Falha hoje porque o service não existe (comando: `SUITE | /usr/bin/grep -E 'HospitalizationServiceTest|Failed:'`)

**Critério de aceite**
- A SUITE lista `HospitalizationServiceTest` `PASS` e `Failed: 0`, incluindo o caso de Review Focus: duas admissões no mesmo leito, a segunda recusada com `Bed <id> is not available` e só um evento `admission` no Fake.
- Cada PHP tocado imprime `No syntax errors detected` no LINT.

**Validação**
- `SUITE | /usr/bin/grep -E 'HospitalizationServiceTest|Failed:'` (evidência: `PASS` e `Failed: 0`)
- Teste do Review Focus em `HospitalizationServiceTest`: `testSecondAdmissionToSameBedIsRejected` (evidência: `PASS`)
- Teste de escopo de unidade em `HospitalizationServiceTest`: `testGetFromOtherUnitIsDenied` (`FakeAuthorizationPolicy(allowed: false)` → `AuthorizationDenied`, com `resourceUnitId` da internação persistida em `requests`) (evidência: `PASS`; cobre o Review Focus de outra unidade quando o banco local tem uma unidade só)
- LINT nos 2 arquivos (evidência: `No syntax errors detected`)

### T-10 — `HospitalizationOrderService` — prescrição, suspensão, administração, flowboard

**Camada:** backend
**Dependências:** T-03, T-04, T-06
**Paralelizável:** sim
**Complexidade:** alta
**Agente:** Arquimedes

**Arquivos prováveis**
- `src/app/Core/Application/HospitalizationOrderService.php`
- `src/tests/Unit/HospitalizationOrderServiceTest.php`

**Interface**
- Produz: `HospitalizationOrderService::__construct(HospitalizationOrderRepositoryInterface $orders, HospitalizationAdministrationRepositoryInterface $administrations, HospitalizationRepositoryInterface $hospitalizations, ProductRepositoryInterface $products, AuthorizationPolicyInterface $authorization, TenantContext $context, ?\Closure $clock = null)`
- Produz: `prescribe(int $hospitalizationId, string $orderType, string $descriptionText, ?int $productId, ?int $quantityPerAdministration, string $doseText, string $route, int $frequencyHours, DateTimeImmutable $startsAt, DateTimeImmutable $endsAt, string $action): HospitalizationOrder`. Exige internação `admitted`; produto do tenant e ativo, senão `CrossTenantReferenceException`; grava uma administração `pending` por horário de `AdministrationSchedule::generate`.
- Produz: `suspend(int $orderId, string $action): int` (cancela as administrações pendentes com horário ≥ agora e devolve quantas)
- Produz: `recordAdministration(int $administrationId, string $outcome, string $notesText, string $action): HospitalizationAdministration`, com `$outcome` ∈ `'done'`, `'skipped'` (outro valor → `InvalidArgumentException`); exige internação `admitted`; segunda chamada → `Administration <id> is not pending`
- Produz: `getAdministration(int $administrationId, string $action): HospitalizationAdministration`; `listOrders(int $hospitalizationId, string $action): array`; `listAdministrations(int $hospitalizationId, string $action): array`
- Produz: `boardRowsForCurrentUnit(int $windowHours, string $action): array`. Usa `from` = agora − 12 h e `to` = agora + `$windowHours`. Devolve as linhas de `listBoardRows` acrescidas de `timeliness` (`HospitalizationAdministration::classify`).
- Produz: toda mutação autoriza com `resourceUnitId` da internação persistida, `entityType: 'hospitalization'`
- Consome: T-04 `AdministrationSchedule::generate(DateTimeImmutable $startsAt, DateTimeImmutable $endsAt, int $frequencyHours): array`, T-04 `HospitalizationAdministration::classify(string $status, DateTimeImmutable $scheduledAt, ?DateTimeImmutable $performedAt, DateTimeImmutable $now): string`, T-04 `listBoardRows(int $systemUnitId, DateTimeImmutable $from, DateTimeImmutable $to): array`

**Teste RED**
- `src/tests/Unit/HospitalizationOrderServiceTest.php` — prescrever 8/8 h de 01/10 08:00 a 04/10 08:00 grava 9 administrações `pending`; `suspend` às 01/10 20:00 (relógio injetado) cancela 7 e devolve `7`; `recordAdministration(..., 'done', ...)` duas vezes lança `Administration <id> is not pending`; prescrição em internação `discharged` lança `Hospitalization <id> is not admitted`. Falha hoje porque o service não existe (comando: `SUITE | /usr/bin/grep -E 'HospitalizationOrderServiceTest|Failed:'`)

**Critério de aceite**
- A SUITE lista `HospitalizationOrderServiceTest` `PASS` e `Failed: 0`; `boardRowsForCurrentUnit` devolve `timeliness` = `late` para pendente 31 min atrasada no relógio injetado.
- Cada PHP tocado imprime `No syntax errors detected` no LINT.

**Validação**
- `SUITE | /usr/bin/grep -E 'HospitalizationOrderServiceTest|Failed:'` (evidência: `PASS` e `Failed: 0`)
- LINT nos 2 arquivos (evidência: `No syntax errors detected`)

### T-11 — Alta integrada — `HospitalizationDischargeService` + `EncounterAccountService::addSourcedItem`

**Camada:** backend
**Dependências:** T-03, T-04, T-06
**Paralelizável:** sim
**Complexidade:** alta
**Agente:** Aang

**Arquivos prováveis**
- `src/app/Core/Application/HospitalizationDischargeService.php`
- `src/app/Core/Application/EncounterAccountService.php`
- `src/tests/Unit/HospitalizationDischargeServiceTest.php`

**Interface**
- Produz: `EncounterAccountService::addSourcedItem(int $accountId, string $sourceType, int $sourceId, string $descriptionText, int $amountCents, string $action): ?EncounterAccountItem`. Aceita só `TYPE_HOSPITALIZATION_STAY` e `TYPE_HOSPITALIZATION_ADMINISTRATION` (outro → `InvalidArgumentException`); conta não `open` → mensagem de `assertAccountOpen`; par `(sourceType, sourceId)` já na conta → `null`, sem gravar; atualiza os totais. Construtor e demais métodos inalterados.
- Produz: `HospitalizationDischargeService::__construct(HospitalizationRepositoryInterface $hospitalizations, BedRepositoryInterface $beds, HospitalizationOrderRepositoryInterface $orders, HospitalizationAdministrationRepositoryInterface $administrations, HospitalizationEventRepositoryInterface $events, ProductRepositoryInterface $products, EncounterAccountService $accounts, StockService $stock, AuthorizationPolicyInterface $authorization, TenantContext $context, ?\Closure $clock = null)`
- Produz: `discharge(int $hospitalizationId, string $summaryText, string $action): array`, que devolve `array{account_id: int, items_added: int, consumed_products: int, billable_days: int}`, na ordem:
  1. Carrega e autoriza (unidade da internação).
  2. Exige `admitted`.
  3. `$accounts->openOrGet(encounterId, $action)`; conta não `open` → `InvalidStatusTransitionException` com a mensagem de `assertAccountOpen`.
  4. Soma `quantity_per_administration` das administrações `done` por `product_id` e chama `StockService::consume(tenant, unidade, produto, soma, StockMovement::REASON_HOSPITALIZATION_CONSUMPTION, 'hospitalization', id, usuário)`; `InsufficientStockException` propaga.
  5. Item `hospitalization_stay` (`source_id` = internação, `Internação — <n> diária(s) (leito <code>)`, `n × daily_rate_cents`), só se o valor for > 0.
  6. Um item `hospitalization_administration` por administração `done` com produto (`<nome do produto> — <dd/mm/aaaa HH:ii>`, `sale_price_cents × quantidade`), só se o valor for > 0.
  7. Cancela as administrações pendentes.
  8. `discharge` da internação e `save`.
  9. `release` do leito.
  10. Evento `discharge` com o resumo.
- Consome: T-03 `EncounterAccountItem::TYPE_HOSPITALIZATION_STAY = 'hospitalization_stay'`, T-03 `StockMovement::REASON_HOSPITALIZATION_CONSUMPTION = 'hospitalization_consumption'`, T-03 `billableDays(DateTimeImmutable $until): int`, T-04 `listByHospitalization(int $hospitalizationId): array`

**Teste RED**
- `src/tests/Unit/HospitalizationDischargeServiceTest.php` — com `EncounterAccountService` e `StockService` reais sobre Fakes: internação de 50 h a R$ 100/dia com 3 administrações `done` de produto a R$ 12 (1 un.) → conta com item `hospitalization_stay` de 30000 e 3 itens de 1200, estoque baixado em 3 com motivo `hospitalization_consumption`, leito `available`, internação `discharged`; segunda alta lança `Hospitalization <id> is not admitted`; conta `closed` lança a mensagem de `assertAccountOpen` sem movimento de estoque. Falha hoje porque o service e `addSourcedItem` não existem (comando: `SUITE | /usr/bin/grep -E 'HospitalizationDischargeServiceTest|Failed:'`)

**Critério de aceite**
- A SUITE lista `HospitalizationDischargeServiceTest` `PASS`, `EncounterAccountServiceTest` `PASS` (sem regressão em `syncAutomaticItems`/`addManualItem`) e `Failed: 0`.
- Caso de Review Focus coberto: conta fechada antes da alta → exceção, `FakeStockMovementRepository` sem movimento novo, internação ainda `admitted`.
- Cada PHP tocado imprime `No syntax errors detected` no LINT.

**Validação**
- `SUITE | /usr/bin/grep -E 'HospitalizationDischargeServiceTest|EncounterAccountServiceTest|StockServiceTest|Failed:'` (evidência: `PASS` e `Failed: 0`)
- Teste do Review Focus: `testDischargeRefusedWhenAccountClosedTouchesNoStock` (evidência: `PASS`)
- LINT nos 3 arquivos (evidência: `No syntax errors detected`)

### T-12 — Telas de leitos (`BedList`, `BedForm`)

**Camada:** frontend
**Dependências:** T-07, T-08
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Saitama

**Arquivos prováveis**
- `src/app/control/clinic/BedList.php`
- `src/app/control/clinic/BedForm.php`
- `src/tests/Integration/BedFormIntegrationTest.php`

**Notas de implementação**

Padrão de `src/app/control/clinic/ProductList.php`/`ProductForm.php` e de `EncounterAccountForm.php` (`resolveTenantContext()` e `makeBedService()` próprios, `TTransaction::open('permission')`, `catch AuthorizationDenied` → permissão negada, domínio → `TMessage('error', CvFormat::userError($e))`, sucesso → `TToast` + `__adianti_goto_page`). Valor da diária com `MoneyInput`. Abas `CvNav::tabs('hospitalization', 'beds')` (grupo criado por T-17; até lá a chamada fica atrás de `try` que omite as abas). Textos em `_t('<en>')`, anotados no board.

**Interface**
- Produz: rotas `index.php?class=BedList` e `index.php?class=BedForm` (`&id=<bed_id>` edita); `BedList` com colunas Código, Nome, Diária, Situação (`CvBadge`: disponível/ocupado/inativo), ações Editar/Ativar/Desativar, estado vazio `cv-state--empty` com botão "Novo leito"
- Produz: `BedForm` com campos `code`, `name`, `daily_rate` (texto em reais) e `onSave`; na edição `code` fica só leitura
- Consome: T-08 `create(string $code, string $name, int $dailyRateCents, string $action): Bed`, T-08 `listForCurrentUnit(string $action): array`

**Teste RED**
- `src/tests/Integration/BedFormIntegrationTest.php` — subprocesso `php -r` com `require "init.php"` (padrão de `src/tests/Integration/AppointmentFormPostIntegrationTest.php`) cria `new BedForm([])` e lê o `form` por Reflection: os campos `code`, `name` e `daily_rate` existem. Falha hoje porque `BedForm` não existe (comando: `SUITE | /usr/bin/grep -E 'BedFormIntegrationTest|Failed:'`)

**Critério de aceite**
- A SUITE lista `BedFormIntegrationTest` `PASS`, `ControllerRawExceptionMessageTest` `PASS` e `Failed: 0`.
- No gate (Playwright, tablet 820×1180): leito `F6 teste L1` criado com diária `100,00` aparece na `BedList` com badge "Disponível"; segundo cadastro com o mesmo código mostra a mensagem de código repetido; console sem mensagem de nível error e rede sem status ≥ 400.
- Cada PHP tocado imprime `No syntax errors detected` no LINT.

**Validação**
- `SUITE | /usr/bin/grep -E 'BedFormIntegrationTest|ControllerRawExceptionMessageTest|Failed:'` (evidência: `PASS` e `Failed: 0`)
- Gate Playwright em `http://127.0.0.1:8081/index.php?class=BedList` (evidência: snapshot com a linha `F6 teste L1`, `browser_console_messages` sem `error`, `browser_network_requests` sem ≥ 400)
- LINT nos 3 arquivos (evidência: `No syntax errors detected`)

### T-13 — Tela de admissão a partir do atendimento

**Camada:** frontend
**Dependências:** T-07, T-09
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Naruto

**Arquivos prováveis**
- `src/app/control/clinic/HospitalizationAdmissionForm.php`
- `src/tests/Integration/HospitalizationAdmissionFormIntegrationTest.php`

**Notas de implementação**

Mesmo padrão de controller de T-12. Leitos: `BedService::listAvailableForUnit` da unidade do atendimento (combo com `code — name`). Responsável: veterinários ativos do tenant (mesma fonte de `src/app/control/clinic/AppointmentForm.php` para profissionais), pré-selecionado com o profissional do atendimento. Sem leito disponível: estado vazio com link para `BedList`. Sucesso → `index.php?class=HospitalizationView&id=<novo id>`.

**Interface**
- Produz: rota `index.php?class=HospitalizationAdmissionForm&encounter_id=<id>&patient_id=<id>`; campos `encounter_id` (oculto), `bed_id`, `responsible_system_user_id`, `reason_text`, `expected_discharge_date`; `onSave` chama `HospitalizationService::admit` com a ação `'HospitalizationAdmissionForm::onSave'`
- Consome: T-09 `admit(int $encounterId, int $bedId, int $responsibleSystemUserId, string $reasonText, ?string $expectedDischargeDate, string $action): Hospitalization`

**Teste RED**
- `src/tests/Integration/HospitalizationAdmissionFormIntegrationTest.php` — subprocesso com `init.php` cria `new HospitalizationAdmissionForm([])` e lê o `form`: existem `encounter_id`, `bed_id`, `responsible_system_user_id`, `reason_text`, `expected_discharge_date`, e sem `encounter_id` a página não lança exceção. Falha hoje porque a classe não existe (comando: `SUITE | /usr/bin/grep -E 'HospitalizationAdmissionFormIntegrationTest|Failed:'`)

**Critério de aceite**
- A SUITE lista `HospitalizationAdmissionFormIntegrationTest` `PASS`, `ControllerRawExceptionMessageTest` `PASS` e `Failed: 0`.
- No gate: a partir de um atendimento `F6 teste`, a admissão no leito `F6 teste L1` redireciona para `HospitalizationView` e a `BedList` mostra o leito "Ocupado"; segunda admissão do mesmo paciente mostra a mensagem de paciente já internado; console sem mensagem de nível error, rede sem status ≥ 400.
- Cada PHP tocado imprime `No syntax errors detected` no LINT.

**Validação**
- `SUITE | /usr/bin/grep -E 'HospitalizationAdmissionFormIntegrationTest|ControllerRawExceptionMessageTest|Failed:'` (evidência: `PASS` e `Failed: 0`)
- Gate Playwright (evidência: URL final `class=HospitalizationView&id=`, badge "Ocupado" na `BedList`, console e rede limpos)
- `SELECT COUNT(*) FROM encounter` antes e depois do gate, só leitura (evidência: só cresce com os atendimentos `F6 teste`)

### T-14 — Ficha da internação com transferência e alta

**Camada:** frontend
**Dependências:** T-07, T-09, T-10, T-11
**Paralelizável:** sim
**Complexidade:** alta
**Agente:** Aang

**Arquivos prováveis**
- `src/app/control/clinic/HospitalizationView.php`
- `src/tests/Integration/HospitalizationViewIntegrationTest.php`

**Notas de implementação**

Página `TPage` com `CvPage::header` (paciente, leito, responsável, admitida em, previsão, badge de status) e abas `CvPage::tabs`:
- Prescrições: lista com suspender e link `HospitalizationOrderForm&hospitalization_id=`.
- Administrações: hoje e próximas, com badge de `classify` e botão `HospitalizationAdministrationForm&administration_id=`.
- Evolução e parâmetros: linha do tempo de `listEvents`, com links `HospitalizationEventForm&hospitalization_id=&type=vitals|evolution`.

Ações: Transferir (`onTransfer`, combo de leitos disponíveis da unidade) e Dar alta (`onDischarge`, confirmação `TQuestion` com resumo obrigatório). A alta roda dentro de um único `TTransaction::open('permission')`: qualquer exceção → `rollback`, mensagem via `CvFormat::userError`. Sucesso mostra o resumo de `discharge` (diárias, itens lançados) com link para `EncounterAccountForm`. Sem `id` → estado vazio `cv-state--empty` antes de resolver o tenant. Botões com altura ≥ 44 px (`--cv-touch-target`).

**Interface**
- Produz: rota `index.php?class=HospitalizationView&id=<hospitalization_id>`; métodos `onTransfer` (parâmetros `id`, `to_bed_id`) e `onDischarge` (parâmetros `id`, `summary_text`), com ações `'HospitalizationView::onTransfer'` e `'HospitalizationView::onDischarge'`
- Consome: T-09 `get(int $hospitalizationId, string $action): Hospitalization`, T-09 `transfer(int $hospitalizationId, int $toBedId, string $action): Hospitalization`, T-10 `listAdministrations(int $hospitalizationId, string $action): array`, T-11 `discharge(int $hospitalizationId, string $summaryText, string $action): array`

**Teste RED**
- `src/tests/Integration/HospitalizationViewIntegrationTest.php` — subprocesso com `init.php`: `new HospitalizationView([])` seguido de `show()` com buffer imprime `cv-state--empty` e não lança exceção. Falha hoje porque a classe não existe (comando: `SUITE | /usr/bin/grep -E 'HospitalizationViewIntegrationTest|Failed:'`)

**Critério de aceite**
- A SUITE lista `HospitalizationViewIntegrationTest` `PASS`, `ControllerRawExceptionMessageTest` `PASS` e `Failed: 0`.
- No gate: a transferência de `F6 teste L1` para `F6 teste L2` aparece na linha do tempo e inverte os badges na `BedList`. Depois disso, a alta lança na conta do atendimento um item "Internação — N diária(s)" e um item por administração feita com produto, e o leito volta a "Disponível".
- Caso de Review Focus 1, produto administrado sem saldo: a alta mostra "Estoque insuficiente", a internação segue "Internado", o leito segue ocupado e a conta não ganha item.
- Caso de Review Focus 2, usuário de outra unidade com `HospitalizationView&id=<id>`: a página mostra permissão negada e nenhum dado do paciente.
- Cada PHP tocado imprime `No syntax errors detected` no LINT.

**Validação**
- `SUITE | /usr/bin/grep -E 'HospitalizationViewIntegrationTest|ControllerRawExceptionMessageTest|Failed:'` (evidência: `PASS` e `Failed: 0`)
- Gate Playwright, alta com estoque zerado (Review Focus) (evidência: mensagem de estoque insuficiente na tela; `SELECT status FROM hospitalization WHERE id=<id>` só leitura = `admitted`; `SELECT COUNT(*) FROM encounter_account_item WHERE source_type LIKE 'hospitalization%'` inalterado)
- Gate Playwright, outra unidade: trocar a unidade ativa pelo seletor do cabeçalho (`CvPage::header` com `unitSwitch`) e abrir a URL (evidência: mensagem de permissão negada, snapshot sem o nome do paciente `F6 teste`). Se o admin só tem uma unidade, o validador registra isso em Pendências e vale `testGetFromOtherUnitIsDenied` de T-09
- Gate Playwright, alta com saldo (evidência: `SELECT source_type, amount_cents FROM encounter_account_item WHERE account_id=<id>` só leitura com `hospitalization_stay` e `hospitalization_administration`; `SELECT status FROM bed WHERE code='F6 teste L2'` = `available`; `SELECT COUNT(*) FROM stock_movement WHERE reason='hospitalization_consumption'` cresceu)

### T-15 — Formulários de prescrição, administração e evolução/parâmetros

**Camada:** frontend
**Dependências:** T-07, T-09, T-10
**Paralelizável:** sim
**Complexidade:** alta
**Agente:** Kratos

**Arquivos prováveis**
- `src/app/control/clinic/HospitalizationOrderForm.php`
- `src/app/control/clinic/HospitalizationAdministrationForm.php`
- `src/app/control/clinic/HospitalizationEventForm.php`
- `src/tests/Integration/HospitalizationClinicalFormsIntegrationTest.php`

**Notas de implementação**

O padrão de controller é o de T-12, e cada formulário volta para `HospitalizationView&id=<hospitalization_id>`.
- `HospitalizationOrderForm`: tipo (medicação/alimentação/procedimento), descrição, produto opcional (produtos ativos do tenant) + quantidade por administração, dose, via (`HospitalizationOrder::ROUTES`), frequência em horas, início e fim (`DateTimeInput`, `dd/mm/aaaa hh:ii`).
- `HospitalizationAdministrationForm` (tablet): mostra paciente, item, dose, via e horário, com dois botões grandes "Feito" e "Não feito" e observação (obrigatória para "Não feito"). O botão de envio fica desabilitado após o primeiro toque (`TButton` + `disabled` no clique), e o servidor recusa o segundo envio com `Administration <id> is not pending`.
- `HospitalizationEventForm`: `type=vitals` (temperatura, FC, FR, peso, dor 0–10, observação) ou `type=evolution` (texto).

**Interface**
- Produz: rotas `index.php?class=HospitalizationOrderForm&hospitalization_id=<id>`, `index.php?class=HospitalizationAdministrationForm&administration_id=<id>`, `index.php?class=HospitalizationEventForm&hospitalization_id=<id>&type=vitals|evolution`; ações `'HospitalizationOrderForm::onSave'`, `'HospitalizationAdministrationForm::onDone'`, `'HospitalizationAdministrationForm::onSkip'`, `'HospitalizationEventForm::onSave'`
- Consome: T-10 `prescribe(int $hospitalizationId, string $orderType, string $descriptionText, ?int $productId, ?int $quantityPerAdministration, string $doseText, string $route, int $frequencyHours, DateTimeImmutable $startsAt, DateTimeImmutable $endsAt, string $action): HospitalizationOrder`, T-10 `recordAdministration(int $administrationId, string $outcome, string $notesText, string $action): HospitalizationAdministration`, T-09 `recordVitals(int $hospitalizationId, ?float $temperatureC, ?int $heartRateBpm, ?int $respiratoryRateRpm, ?float $weightKg, ?int $painScore, string $notesText, string $action): HospitalizationEvent`

**Teste RED**
- `src/tests/Integration/HospitalizationClinicalFormsIntegrationTest.php` — subprocesso com `init.php`: `HospitalizationOrderForm` tem `order_type`, `description_text`, `product_id`, `quantity_per_administration`, `dose_text`, `route`, `frequency_hours`, `starts_at`, `ends_at`; `HospitalizationEventForm` com `type=vitals` tem `temperature_c`, `heart_rate_bpm`, `respiratory_rate_rpm`, `weight_kg`, `pain_score`; a combo `route` tem exatamente as 7 vias de `HospitalizationOrder::ROUTES`. Falha hoje porque as classes não existem (comando: `SUITE | /usr/bin/grep -E 'HospitalizationClinicalFormsIntegrationTest|Failed:'`)

**Critério de aceite**
- A SUITE lista `HospitalizationClinicalFormsIntegrationTest` `PASS`, `ControllerRawExceptionMessageTest` `PASS` e `Failed: 0`.
- No gate: a prescrição `F6 teste dipirona` de 8/8 h por 24 h gera 3 administrações na aba Administrações. "Feito" na primeira muda o badge para feito. Um segundo POST do mesmo formulário (Review Focus) mostra `Administration <id> is not pending` traduzida, e `SELECT COUNT(*) FROM hospitalization_administration WHERE id=<id> AND status='done'` só leitura = 1. Parâmetros com dor 11 mostram a mensagem de faixa.
- Cada PHP tocado imprime `No syntax errors detected` no LINT.

**Validação**
- `SUITE | /usr/bin/grep -E 'HospitalizationClinicalFormsIntegrationTest|ControllerRawExceptionMessageTest|Failed:'` (evidência: `PASS` e `Failed: 0`)
- Gate Playwright do toque duplo, repetindo o POST via `browser_evaluate` com o mesmo `administration_id` (evidência: mensagem de não pendente e contagem 1 no SELECT)
- LINT nos 4 arquivos (evidência: `No syntax errors detected`)

### T-16 — Flowboard do turno (tablet) + `HospitalizationBoardView` + CSS `cv-board-*`

**Camada:** frontend
**Dependências:** T-04, T-07, T-09, T-10
**Paralelizável:** sim
**Complexidade:** alta
**Agente:** Tesla

**Arquivos prováveis**
- `src/app/control/clinic/HospitalizationBoard.php`
- `src/app/Core/Presentation/HospitalizationBoardView.php`
- `src/app/templates/adminbs5/cv-components.css`
- `src/tests/Unit/HospitalizationBoardViewTest.php`

**Notas de implementação**

O flowboard tem `CvPage::header` com o seletor de unidade e uma linha de KPIs (`CvKpiCard`: internados, atrasadas, próximas 2 h, feitas no turno). Abaixo vêm três colunas `cv-board-col`: Atrasadas, Próximas, Feitas. Cada cartão `cv-board-card` mostra paciente, leito, item e dose/via, com horário e badge, e o próprio cartão é o alvo de toque para `HospitalizationAdministrationForm&administration_id=`. Uma faixa lista os internados (cartão → `HospitalizationView&id=`). Estado vazio `cv-state--empty` quando a unidade não tem internados. Recarregamento por botão "Atualizar" (sem polling). CSS novo só numa seção `/* cv-board */` no fim de `cv-components.css`: colunas empilhadas abaixo de 768 px e alvos de toque com `min-height: var(--cv-touch-target)`. Nada em `custom.css` nem em `layout.html`.

**Interface**
- Produz: rota `index.php?class=HospitalizationBoard`
- Produz: `CentralVet\Presentation\HospitalizationBoardView::group(array $rows): array` (sem Adianti), que devolve `array{late: list<array>, upcoming: list<array>, done: list<array>, counts: array{late: int, upcoming: int, done: int}}`: `late` = `timeliness` `late`; `upcoming` = `due` ou `upcoming`; `done` = `done`, `done_late` ou `skipped`; `cancelled` fica fora; cada lista ordenada por `scheduled_at`
- Consome: T-10 `boardRowsForCurrentUnit(int $windowHours, string $action): array`, T-09 `listActiveForCurrentUnit(string $action): array`

**Teste RED**
- `src/tests/Unit/HospitalizationBoardViewTest.php` — `group` com 5 linhas (`late`, `due`, `upcoming`, `done_late`, `cancelled`) devolve `counts` = `late` 1, `upcoming` 2, `done` 1 e não inclui a cancelada. Falha hoje porque a classe não existe (comando: `SUITE | /usr/bin/grep -E 'HospitalizationBoardViewTest|Failed:'`)

**Critério de aceite**
- A SUITE lista `HospitalizationBoardViewTest` `PASS`, `CoreLayerDependencyTest` `PASS` e `Failed: 0`.
- No gate (tablet 820×1180 e desktop 1366×768): com a internação `F6 teste`, a coluna Atrasadas mostra a administração com horário mais de 30 min no passado (criada com início retroativo) e os KPIs batem com as colunas. Tocar o cartão abre o formulário de administração. Os cartões medem ≥ 44 px de altura (`browser_evaluate` com `getBoundingClientRect().height`). Console sem mensagem de nível error, rede sem status ≥ 400.
- `git diff --stat` da task não toca `custom.css` nem `layout.html`.

**Validação**
- `SUITE | /usr/bin/grep -E 'HospitalizationBoardViewTest|CoreLayerDependencyTest|Failed:'` (evidência: `PASS` e `Failed: 0`)
- Gate Playwright com `browser_resize` 820×1180 (evidência: snapshot com as 3 colunas e altura ≥ 44 nos cartões)
- `git -C /var/www/html/centralvet diff --name-only <BASE>..HEAD -- src/app/templates/adminbs5/` (evidência: só `cv-components.css`)

### T-17 — Navegação: menu, abas `CvNav`, ação no `EncounterView`

**Camada:** frontend
**Dependências:** T-05
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Jaspion

**Arquivos prováveis**
- `src/app/control/clinic/EncounterView.php`
- `src/menu.xml`
- `src/app/lib/widget/CvNav.php`
- `src/tests/Integration/HospitalizationNavigationIntegrationTest.php`

**Interface**
- Produz: `EncounterView::PLAN_ACTIONS['hospitalization'] = ['Hospitalize', 'fa:procedures', 'HospitalizationAdmissionForm']` (uma linha nova, nada mais no arquivo)
- Produz: em `src/menu.xml`, `<menuitem label='_t{Hospitalization}'>` com `<icon>fas:bed fa-fw</icon>` e `<action>HospitalizationBoard</action>`, logo antes de `_t{Surgeries}`, e item `_t{Beds}` → `BedList` no submenu de Configurações
- Produz: `CvNav::group('hospitalization')` com `'board' => ['Board', 'index.php?class=HospitalizationBoard']` e `'beds' => ['Beds', 'index.php?class=BedList']`
- Consome: nada (rotas fixadas em plan.md § Decisões de arquitetura; programas de T-05)

**Teste RED**
- `src/tests/Integration/HospitalizationNavigationIntegrationTest.php` — subprocesso com `init.php`: `CvNav::group('hospitalization')` devolve as hrefs `index.php?class=HospitalizationBoard` e `index.php?class=BedList`; a constante `PLAN_ACTIONS` de `EncounterView` (Reflection) tem `hospitalization` com alvo `HospitalizationAdmissionForm`; `src/menu.xml` contém `<action>HospitalizationBoard</action>` e `<action>BedList</action>`. Falha hoje: `CvNav` lança `Unknown CvNav group` e as entradas não existem (comando: `SUITE | /usr/bin/grep -E 'HospitalizationNavigationIntegrationTest|Failed:'`)

**Critério de aceite**
- A SUITE lista `HospitalizationNavigationIntegrationTest` `PASS` e `Failed: 0`.
- `xmllint --noout src/menu.xml` sai com código 0.
- `git diff` de `EncounterView.php` na task tem uma linha adicionada e nenhuma removida.
- No gate: o menu lateral mostra "Internação" abrindo o flowboard, e o plano clínico de um atendimento mostra "Internar" abrindo a admissão com `encounter_id` e `patient_id`.

**Validação**
- `SUITE | /usr/bin/grep -E 'HospitalizationNavigationIntegrationTest|Failed:'` (evidência: `PASS` e `Failed: 0`)
- `xmllint --noout src/menu.xml; echo $?` (evidência: `0`)
- `git -C /var/www/html/centralvet diff --numstat <BASE>..HEAD -- src/app/control/clinic/EncounterView.php` (evidência: `1	0`)

### T-18 — i18n pt/en e mensagens de domínio da internação

**Camada:** frontend
**Dependências:** T-12, T-13, T-14, T-15, T-16, T-17
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Levi

**Arquivos prováveis**
- `src/app/config/translations.json`
- `src/app/Core/Presentation/UserMessage.php`
- `src/tests/Unit/UserMessageTest.php`

**Notas de implementação**

Fontes das chaves:
- Linhas `- [T-xx] i18n: <en> → <pt>` do board.
- Varredura de `_t('...')` nos controllers novos e no `menu.xml` (`_t{...}`).
- Rótulos de `CvNav` e `PLAN_ACTIONS` (`Hospitalize` → `Internar`).

Inserção em ordem alfabética de `en` (sem distinção de caixa), sem `en` duplicado.

Mensagens de domínio novas em `UserMessage`, que já vêm cobertas por `PATTERNS`/`STATIC`:
- `Bed <id> is not available`
- `Bed <id> is occupied and cannot be deactivated`
- `Patient <id> already has an active hospitalization`
- `Hospitalization <id> is not admitted`
- `Administration <id> is not pending`
- `A bed with code "<code>" already exists in this unit`
- `Encounter account <id> cannot be modified: status is "<status>", not "open"`
- `ends_at must be after starts_at`
- `Prescription period cannot exceed 30 days`
- `frequency_hours must be between 1 and 168`
- `quantity_per_administration is required when a product is selected`
- `At least one vital sign is required`
- `pain_score must be between 0 and 10`
- `responsible_system_user_id must be an active user of this tenant`

Também amplia `(?:Appointment|Patient|Tutor|Product|Payable)` em `Record not found` com `Bed|Hospitalization|Administration|Order`.

**Interface**
- Produz: chaves `en`/`pt` de todas as strings de tela da Onda 4 (ex.: `Hospitalization` → `Internação`, `Beds` → `Leitos`, `Board` → `Quadro`, `Hospitalize` → `Internar`, `Discharge` → `Dar alta`, `Transfer bed` → `Transferir leito`, `Done` → `Feito`, `Not done` → `Não feito`)
- Consome: T-03 `Bed <id> is not available`, T-04 `Administration <id> is not pending`

**Teste RED**
- `src/tests/Unit/UserMessageTest.php` — `UserMessage::resolve('Bed 7 is not available')`, `resolve('Administration 3 is not pending')` e `resolve('Hospitalization 9 not found for this tenant')` devolvem chave não nula, e cada chave nova tem entrada em `translations.json`. Falha hoje porque os padrões não existem (comando: `SUITE | /usr/bin/grep -E 'UserMessageTest|Failed:'`)

**Critério de aceite**
- A SUITE lista `UserMessageTest` e `CvFormatUserErrorTest` `PASS` e `Failed: 0`.
- `python3 -c "import json;d=json.load(open('src/app/config/translations.json'));e=[x['en'] for x in d];print(len(e)-len(set(e)), e==sorted(e,key=str.lower))"` imprime `0 True`.
- No gate em pt: nenhuma tela da internação mostra `Message not found` nem texto em inglês nos rótulos, botões e mensagens dos fluxos de T-12 a T-17.

**Validação**
- `SUITE | /usr/bin/grep -E 'UserMessageTest|CvFormatUserErrorTest|Failed:'` (evidência: `PASS` e `Failed: 0`)
- O `python3 -c` acima (evidência: `0 True`)
- `/usr/bin/grep -rhoE "_t\('[^']+'" src/app/control/clinic/Bed*.php src/app/control/clinic/Hospitalization*.php | sort -u` cruzado com as chaves `en` (evidência: nenhuma chave faltando)

### T-19 — Runbook da internação

**Camada:** docs
**Dependências:** T-01, T-02, T-05, T-11
**Paralelizável:** sim
**Complexidade:** simples
**Agente:** Gandalf

**Arquivos prováveis**
- `docs/runbooks/internacao.md`
- `docs/runbooks/README.md`

**Interface**
- Produz: `docs/runbooks/internacao.md` com:
  - fluxos (leito → admissão → prescrição → administração → flowboard → transferência → alta);
  - regras: diária, atraso de 30 min, agenda até 30 dias, baixa de estoque e cobrança na alta, recusa por estoque ou conta fechada;
  - tabelas da 0010 e CHECKs alterados;
  - os 8 programas RBAC;
  - procedimento de aplicação (MySQL 8 e 5.7) e de rollback;
  - limites do MVP (seção Excluído do plano).
- Produz: um link novo para o runbook em `docs/runbooks/README.md`.
- Consome: nada (lê `plan.md`, `tasks.md` e os arquivos entregues)

**Teste RED**
- sem teste: documentação

**Critério de aceite**
- `docs/runbooks/internacao.md` tem as seções `## Fluxos`, `## Regras`, `## Banco de dados`, `## Permissões`, `## Aplicação e rollback` e `## Limites do MVP`; cita `20261005_0010_phase6a_hospitalization`, `hospitalization_consumption` e os 8 controllers.
- `docs/runbooks/README.md` tem um link para `internacao.md`.

**Validação**
- `/usr/bin/grep -c "^## " docs/runbooks/internacao.md` (evidência: ≥ 6)
- `/usr/bin/grep -c "internacao.md" docs/runbooks/README.md` (evidência: ≥ 1)

### T-20 — Validação final ponta a ponta e SQL de limpeza `F6 teste`

**Camada:** qa
**Dependências:** T-18, T-19
**Paralelizável:** não
**Complexidade:** média
**Agente:** Spock

**Arquivos prováveis**
- `.claude/tasks/mar-20261005-1521-fase-6a-internacao/sql/T-20-cleanup.sql`

**Notas de implementação**

Prepara, sem executar, o SQL de limpeza dos registros de gate. Ele começa com SELECTs de contagem e termina com `START TRANSACTION` + DELETEs com `WHERE` explícito pelo prefixo `F6 teste` e pelos ids derivados, na ordem das FKs: administração → prescrição → evento → itens `hospitalization_*` da conta → movimentos `hospitalization_consumption` da internação → internação → leito, mais os atendimentos/pacientes/produtos `F6 teste` criados no gate. Depois vêm SELECTs de conferência e `COMMIT` comentado. O saldo de `stock_batch` baixado no gate volta por ajuste documentado no próprio arquivo. O roteiro E2E abaixo é executado pelo validador no gate da Onda 6.

**Interface**
- Produz: `sql/T-20-cleanup.sql` (só o orquestrador executa, com aprovação)
- Consome: nada

**Teste RED**
- sem teste: validação final e SQL de limpeza preparado; a prova é o gate E2E da Onda 6

**Critério de aceite**
- Os DELETEs de `T-20-cleanup.sql` têm `WHERE` com `F6 teste` ou ids vindos de SELECT por esse prefixo (`/usr/bin/grep -ciE "delete from [a-z_]+;"` imprime `0`).
- Gate E2E (desktop e tablet), fluxo completo com registros `F6 teste`: cadastrar leitos L1/L2 → internar a partir do atendimento → prescrever medicação com produto e alimentação → administrar (feito e não feito) → registrar parâmetros e evolução → ver atraso no flowboard → transferir → dar alta → conta do atendimento com diárias e medicações e estoque baixado. Os 5 itens de Review Focus reproduzidos, cada um com a reação descrita em plan.md.
- SUITE com `Failed: 0` e nenhum erro novo em relação a `baseline/php-lint.txt`. Contagens de `encounter`, `encounter_account_item`, `stock_movement` e `system_program` só cresceram desde a BASE da Onda 1.

**Validação**
- `SUITE | /usr/bin/grep -E 'Total|Failed:'` (evidência: `Failed: 0`)
- LINT nos PHP tocados pela branch (`git diff --name-only <BASE da onda 1>..HEAD -- '*.php'`) (evidência: `No syntax errors detected` em todos)
- `python3 scripts/test-prepare-mysql57.py` (evidência: `Ran 8 tests` ou mais, sem falha)
- Tabela do gate `Tela|Fluxos|Console|Rede|Erro na tela|Veredito|Task dona` com uma linha por tela das Ondas 4 (evidência: veredito aprovado em todas)

## Legenda

- [ ] pendente
- [~] em andamento
- [x] concluído com evidência
- [!] bloqueado
