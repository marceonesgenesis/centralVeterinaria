-- Central Vet Pro - Phase 6A hospitalization MVP (beds, admission, internal orders,
-- administration schedule, evolution/vitals events) and widened account/stock CHECKs
-- Migration: 20261005_0010_phase6a_hospitalization
-- Status: PREPARED ONLY. Do not execute without a fresh backup and explicit SQL approval.
-- Target: MySQL 8.0.x, database centralvet, after 20261001_0009_landing_lead
-- has been applied (tenant, system_unit, system_users, patient, encounter, product,
-- encounter_account_item, stock_movement and schema_migrations already exist).
--
-- Effects:
--   * creates bed (a hospitalization bed of a system unit, with its daily rate in cents and
--     status available/occupied/inactive; current_hospitalization_id points to the
--     admission occupying it and intentionally has NO foreign key, avoiding the
--     bed <-> hospitalization cycle at creation time);
--   * creates hospitalization (one admission of a patient, always from an encounter, into
--     a bed, with the responsible and admitting users, the daily rate copied from the bed
--     and status admitted/discharged);
--   * creates hospitalization_order (an internal prescription: medication, feeding or
--     procedure, with route, frequency in hours, a mandatory period and an optional stock
--     product with the quantity consumed per administration);
--   * creates hospitalization_administration (each scheduled administration of an order,
--     generated when the order is created, with status pending/done/skipped/cancelled and
--     who performed it);
--   * creates hospitalization_event (the timeline: admission, transfer, evolution, vitals
--     and discharge, with optional vital parameters and the beds of a transfer);
--   * widens encounter_account_item_source_type_ck with 'hospitalization_stay' and
--     'hospitalization_administration', and stock_movement_reason_ck with
--     'hospitalization_consumption' (each one as DROP CHECK followed by ADD CONSTRAINT in
--     separate statements, so the MySQL 5.7 preparer can drop and recreate its triggers);
--   * adds FKs from bed to tenant/system_unit; from hospitalization to tenant/system_unit/
--     patient/encounter/bed/system_users (responsible, admitted_by, discharged_by); from
--     hospitalization_order to tenant/hospitalization/product/system_users; from
--     hospitalization_administration to tenant/hospitalization/hospitalization_order/
--     system_users; from hospitalization_event to tenant/hospitalization/system_users/bed
--     (from_bed and to_bed);
--   * creates 5 tables, 14 new CHECK constraints (bed.status, bed.occupancy,
--     hospitalization.status, hospitalization.discharge, hospitalization_order.type,
--     .route, .status, .frequency, .period, .product, hospitalization_administration.
--     status, .performed, hospitalization_event.type, .pain), 2 altered CHECK constraints
--     (encounter_account_item.source_type, stock_movement.reason), 2 UNIQUE constraints
--     and 23 foreign keys.
--
-- No data is seeded by this migration: the 5 new tables start empty for every existing
-- tenant, and existing encounter_account_item/stock_movement rows are preserved (the
-- widened CHECKs accept every value the previous ones accepted).
--
-- Risk: bed.current_hospitalization_id has NO database foreign key; keeping it in sync
-- with hospitalization is the Application service's responsibility (conditional UPDATE on
-- status = 'available'), with bed_occupancy_ck guaranteeing that an occupied bed always
-- points to an admission and a free bed never does. hospitalization_order.ends_at >
-- starts_at is enforced, but the 30-day limit and the generated schedule are Application
-- rules. Between the DROP CHECK and the ADD CONSTRAINT of each widened CHECK the table is
-- briefly unconstrained; run the migration in a maintenance window. No new PII category
-- is introduced: free-text clinical notes (reason_text, discharge_summary_text,
-- notes_text) belong to the patient's clinical record, like encounter notes.
--
-- Rollback: restore the backup taken before applying, or write a numbered reverse
-- migration (see docs/runbooks/migration-rollback.md). Do not drop the new tables by hand
-- once encounter_account_item or stock_movement rows reference hospitalization sources.
--
-- MySQL DDL commits implicitly. On failure, stop and inspect; do not rerun blindly.
-- The literal migration checksum below MUST be replaced with the approved artifact's
-- SHA-256 by the migration executor before this file is applied.

CREATE TABLE bed (
    id bigint unsigned NOT NULL AUTO_INCREMENT,
    tenant_id bigint unsigned NOT NULL,
    system_unit_id int NOT NULL,
    code varchar(30) NOT NULL,
    name varchar(120) NOT NULL,
    daily_rate_cents int unsigned NOT NULL DEFAULT 0,
    status varchar(20) NOT NULL DEFAULT 'available',
    current_hospitalization_id bigint unsigned NULL,
    created_at timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY bed_unit_code_uq (tenant_id, system_unit_id, code),
    KEY bed_unit_idx (system_unit_id),
    KEY bed_tenant_unit_status_idx (tenant_id, system_unit_id, status),
    CONSTRAINT bed_tenant_fk FOREIGN KEY (tenant_id) REFERENCES tenant (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT bed_unit_fk FOREIGN KEY (system_unit_id) REFERENCES system_unit (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT bed_status_ck CHECK (status IN ('available', 'occupied', 'inactive')),
    CONSTRAINT bed_occupancy_ck CHECK ((status = 'occupied' AND current_hospitalization_id IS NOT NULL) OR (status <> 'occupied' AND current_hospitalization_id IS NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE hospitalization (
    id bigint unsigned NOT NULL AUTO_INCREMENT,
    tenant_id bigint unsigned NOT NULL,
    system_unit_id int NOT NULL,
    patient_id bigint unsigned NOT NULL,
    encounter_id bigint unsigned NOT NULL,
    bed_id bigint unsigned NOT NULL,
    responsible_system_user_id int NOT NULL,
    admitted_by_system_user_id int NOT NULL,
    reason_text varchar(500) NOT NULL,
    expected_discharge_date date NULL,
    daily_rate_cents int unsigned NOT NULL,
    status varchar(20) NOT NULL DEFAULT 'admitted',
    admitted_at timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    discharged_at timestamp(6) NULL DEFAULT NULL,
    discharged_by_system_user_id int NULL,
    discharge_summary_text text NULL,
    created_at timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    KEY hospitalization_unit_status_idx (tenant_id, system_unit_id, status),
    KEY hospitalization_patient_status_idx (tenant_id, patient_id, status),
    KEY hospitalization_unit_idx (system_unit_id),
    KEY hospitalization_patient_idx (patient_id),
    KEY hospitalization_encounter_idx (encounter_id),
    KEY hospitalization_bed_idx (bed_id),
    KEY hospitalization_responsible_idx (responsible_system_user_id),
    KEY hospitalization_admitted_by_idx (admitted_by_system_user_id),
    KEY hospitalization_discharged_by_idx (discharged_by_system_user_id),
    CONSTRAINT hospitalization_tenant_fk FOREIGN KEY (tenant_id) REFERENCES tenant (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT hospitalization_unit_fk FOREIGN KEY (system_unit_id) REFERENCES system_unit (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT hospitalization_patient_fk FOREIGN KEY (patient_id) REFERENCES patient (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT hospitalization_encounter_fk FOREIGN KEY (encounter_id) REFERENCES encounter (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT hospitalization_bed_fk FOREIGN KEY (bed_id) REFERENCES bed (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT hospitalization_responsible_fk FOREIGN KEY (responsible_system_user_id) REFERENCES system_users (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT hospitalization_admitted_by_fk FOREIGN KEY (admitted_by_system_user_id) REFERENCES system_users (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT hospitalization_discharged_by_fk FOREIGN KEY (discharged_by_system_user_id) REFERENCES system_users (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT hospitalization_status_ck CHECK (status IN ('admitted', 'discharged')),
    CONSTRAINT hospitalization_discharge_ck CHECK ((status = 'admitted' AND discharged_at IS NULL) OR (status = 'discharged' AND discharged_at IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE hospitalization_order (
    id bigint unsigned NOT NULL AUTO_INCREMENT,
    tenant_id bigint unsigned NOT NULL,
    hospitalization_id bigint unsigned NOT NULL,
    order_type varchar(20) NOT NULL,
    description_text varchar(255) NOT NULL,
    product_id bigint unsigned NULL,
    quantity_per_administration int unsigned NULL,
    dose_text varchar(120) NOT NULL,
    route varchar(20) NOT NULL,
    frequency_hours smallint unsigned NOT NULL,
    starts_at timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    ends_at timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    status varchar(20) NOT NULL DEFAULT 'active',
    prescribed_by_system_user_id int NOT NULL,
    suspended_at timestamp(6) NULL DEFAULT NULL,
    created_at timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    KEY hospitalization_order_tenant_hosp_idx (tenant_id, hospitalization_id),
    KEY hospitalization_order_hosp_idx (hospitalization_id),
    KEY hospitalization_order_product_idx (product_id),
    KEY hospitalization_order_prescribed_by_idx (prescribed_by_system_user_id),
    CONSTRAINT hospitalization_order_tenant_fk FOREIGN KEY (tenant_id) REFERENCES tenant (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT hospitalization_order_hosp_fk FOREIGN KEY (hospitalization_id) REFERENCES hospitalization (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT hospitalization_order_product_fk FOREIGN KEY (product_id) REFERENCES product (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT hospitalization_order_prescribed_by_fk FOREIGN KEY (prescribed_by_system_user_id) REFERENCES system_users (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT hospitalization_order_type_ck CHECK (order_type IN ('medication', 'feeding', 'procedure')),
    CONSTRAINT hospitalization_order_route_ck CHECK (route IN ('oral', 'iv', 'im', 'sc', 'topical', 'inhalation', 'other')),
    CONSTRAINT hospitalization_order_status_ck CHECK (status IN ('active', 'suspended')),
    CONSTRAINT hospitalization_order_frequency_ck CHECK (frequency_hours >= 1 AND frequency_hours <= 168),
    CONSTRAINT hospitalization_order_period_ck CHECK (ends_at > starts_at),
    CONSTRAINT hospitalization_order_product_ck CHECK ((product_id IS NULL AND quantity_per_administration IS NULL) OR (product_id IS NOT NULL AND quantity_per_administration >= 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE hospitalization_administration (
    id bigint unsigned NOT NULL AUTO_INCREMENT,
    tenant_id bigint unsigned NOT NULL,
    hospitalization_id bigint unsigned NOT NULL,
    order_id bigint unsigned NOT NULL,
    scheduled_at timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    status varchar(20) NOT NULL DEFAULT 'pending',
    performed_at timestamp(6) NULL DEFAULT NULL,
    performed_by_system_user_id int NULL,
    notes_text varchar(500) NULL,
    created_at timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY hospitalization_administration_order_scheduled_uq (order_id, scheduled_at),
    KEY hospitalization_administration_hosp_sched_idx (tenant_id, hospitalization_id, scheduled_at),
    KEY hospitalization_administration_status_sched_idx (tenant_id, status, scheduled_at),
    KEY hospitalization_administration_hosp_idx (hospitalization_id),
    KEY hospitalization_administration_performed_by_idx (performed_by_system_user_id),
    CONSTRAINT hospitalization_administration_tenant_fk FOREIGN KEY (tenant_id) REFERENCES tenant (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT hospitalization_administration_hosp_fk FOREIGN KEY (hospitalization_id) REFERENCES hospitalization (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT hospitalization_administration_order_fk FOREIGN KEY (order_id) REFERENCES hospitalization_order (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT hospitalization_administration_performed_by_fk FOREIGN KEY (performed_by_system_user_id) REFERENCES system_users (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT hospitalization_administration_status_ck CHECK (status IN ('pending', 'done', 'skipped', 'cancelled')),
    CONSTRAINT hospitalization_administration_performed_ck CHECK ((status IN ('done', 'skipped') AND performed_at IS NOT NULL AND performed_by_system_user_id IS NOT NULL) OR (status IN ('pending', 'cancelled') AND performed_at IS NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE hospitalization_event (
    id bigint unsigned NOT NULL AUTO_INCREMENT,
    tenant_id bigint unsigned NOT NULL,
    hospitalization_id bigint unsigned NOT NULL,
    event_type varchar(20) NOT NULL,
    recorded_by_system_user_id int NOT NULL,
    recorded_at timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    notes_text text NULL,
    temperature_c decimal(4,1) NULL,
    heart_rate_bpm smallint unsigned NULL,
    respiratory_rate_rpm smallint unsigned NULL,
    weight_kg decimal(6,2) NULL,
    pain_score tinyint unsigned NULL,
    from_bed_id bigint unsigned NULL,
    to_bed_id bigint unsigned NULL,
    created_at timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    KEY hospitalization_event_hosp_recorded_idx (tenant_id, hospitalization_id, recorded_at),
    KEY hospitalization_event_hosp_idx (hospitalization_id),
    KEY hospitalization_event_recorded_by_idx (recorded_by_system_user_id),
    KEY hospitalization_event_from_bed_idx (from_bed_id),
    KEY hospitalization_event_to_bed_idx (to_bed_id),
    CONSTRAINT hospitalization_event_tenant_fk FOREIGN KEY (tenant_id) REFERENCES tenant (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT hospitalization_event_hosp_fk FOREIGN KEY (hospitalization_id) REFERENCES hospitalization (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT hospitalization_event_recorded_by_fk FOREIGN KEY (recorded_by_system_user_id) REFERENCES system_users (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT hospitalization_event_from_bed_fk FOREIGN KEY (from_bed_id) REFERENCES bed (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT hospitalization_event_to_bed_fk FOREIGN KEY (to_bed_id) REFERENCES bed (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT hospitalization_event_type_ck CHECK (event_type IN ('admission', 'transfer', 'evolution', 'vitals', 'discharge')),
    CONSTRAINT hospitalization_event_pain_ck CHECK (pain_score IS NULL OR pain_score <= 10)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

ALTER TABLE encounter_account_item DROP CHECK encounter_account_item_source_type_ck;

ALTER TABLE encounter_account_item
    ADD CONSTRAINT encounter_account_item_source_type_ck CHECK (source_type IN ('procedure_execution', 'exam_request', 'manual', 'hospitalization_stay', 'hospitalization_administration'));

ALTER TABLE stock_movement DROP CHECK stock_movement_reason_ck;

ALTER TABLE stock_movement
    ADD CONSTRAINT stock_movement_reason_ck CHECK (reason IN ('purchase_entry', 'procedure_consumption', 'sale_consumption', 'manual_adjustment', 'hospitalization_consumption'));

INSERT INTO schema_migrations (version, checksum)
VALUES ('20261005_0010_phase6a_hospitalization',
        '0000000000000000000000000000000000000000000000000000000000000000' /* 64-char PLACEHOLDER: replace before applying */);
