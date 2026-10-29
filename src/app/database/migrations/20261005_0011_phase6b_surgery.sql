-- Central Vet Pro - Phase 6B surgery MVP (operating rooms, scheduling from an encounter
-- with team, consent, 3-phase checklist, clinical events, materials) and widened
-- account/stock CHECKs
-- Migration: 20261005_0011_phase6b_surgery
-- Status: PREPARED ONLY. Do not execute without a fresh backup and explicit SQL approval.
-- Target: MySQL 8.0.x, database centralvet, after 20261005_0010_phase6a_hospitalization
-- has been applied (tenant, system_unit, system_users, patient, encounter, appointment,
-- procedure_catalog_item, product, encounter_account_item, stock_movement and
-- schema_migrations already exist).
--
-- Effects:
--   * creates surgery_room (an operating room of a system unit, with status
--     active/inactive; it has NO column pointing to surgery, so there is no FK cycle);
--   * creates surgery (one surgery of a patient, always from an encounter, in a room, with
--     the scheduled period, the procedure name and price copied from the catalog, the
--     surgeon and scheduling users, consent columns, start/completion/cancellation data,
--     the follow-up appointment and status scheduled/pre_op/in_progress/completed/
--     cancelled);
--   * creates surgery_team (the members of a surgery with their role surgeon/
--     anesthetist/assistant/circulating);
--   * creates surgery_checklist (one row per confirmed checklist item of the phases
--     sign_in/time_out/sign_out);
--   * creates surgery_event (the timeline: clinical events and the system events of
--     scheduling, consent, checklist, status, material, cancellation, completion and
--     follow-up);
--   * creates surgery_material (materials of the stock used in a surgery, with quantity;
--     the stock is consumed and the account is charged only when the surgery completes);
--   * widens encounter_account_item_source_type_ck with 'surgery_procedure' and
--     'surgery_material', and stock_movement_reason_ck with 'surgery_consumption' (each
--     one as DROP CHECK followed by ADD CONSTRAINT in separate statements, so the MySQL
--     5.7 preparer can drop and recreate its triggers);
--   * adds FKs from surgery_room to tenant/system_unit; from surgery to tenant/
--     system_unit/patient/encounter/surgery_room/procedure_catalog_item/appointment
--     (followup) and system_users (surgeon, scheduled_by, consent_recorded_by,
--     completed_by, cancelled_by); from surgery_team to tenant/surgery/system_users; from
--     surgery_checklist to tenant/surgery/system_users; from surgery_event to tenant/
--     surgery/system_users; from surgery_material to tenant/surgery/product/system_users;
--   * creates 6 tables, 11 new CHECK constraints (surgery_room.status, surgery.status,
--     .period, .consent, .started, .completed, .cancelled, surgery_team.role,
--     surgery_checklist.phase, surgery_event.type, surgery_material.quantity), 2 altered
--     CHECK constraints (encounter_account_item.source_type, stock_movement.reason),
--     3 UNIQUE constraints and 27 foreign keys.
--
-- No data is seeded by this migration: the 6 new tables start empty for every existing
-- tenant, and existing encounter_account_item/stock_movement rows are preserved (the
-- widened CHECKs accept every value the previous ones accepted).
--
-- Risk: overlapping surgeries in the same room are NOT prevented by the database; the
-- Application service locks the surgery_room row (SELECT ... FOR UPDATE) and checks the
-- overlap of scheduled/pre_op/in_progress surgeries. Status transitions, the consent and
-- checklist prerequisites of start/complete and the 3 checklist phases' item lists are
-- Application rules. Between the DROP CHECK and the ADD CONSTRAINT of each widened CHECK
-- the table is briefly unconstrained; run the migration in a maintenance window. No new
-- PII category is introduced: consent_signer_name is the tutor's name already held in
-- the clinic's records, and free-text notes belong to the patient's clinical record.
--
-- Rollback: restore the backup taken before applying, or write a numbered reverse
-- migration (see docs/runbooks/migration-rollback.md). Do not drop the new tables by hand
-- once encounter_account_item or stock_movement rows reference surgery sources.
--
-- MySQL DDL commits implicitly. On failure, stop and inspect; do not rerun blindly.
-- The literal migration checksum below MUST be replaced with the approved artifact's
-- SHA-256 by the migration executor before this file is applied.

CREATE TABLE surgery_room (
    id bigint unsigned NOT NULL AUTO_INCREMENT,
    tenant_id bigint unsigned NOT NULL,
    system_unit_id int NOT NULL,
    code varchar(30) NOT NULL,
    name varchar(120) NOT NULL,
    status varchar(20) NOT NULL DEFAULT 'active',
    created_at timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY surgery_room_unit_code_uq (tenant_id, system_unit_id, code),
    KEY surgery_room_unit_idx (system_unit_id),
    KEY surgery_room_tenant_unit_status_idx (tenant_id, system_unit_id, status),
    CONSTRAINT surgery_room_tenant_fk FOREIGN KEY (tenant_id) REFERENCES tenant (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT surgery_room_unit_fk FOREIGN KEY (system_unit_id) REFERENCES system_unit (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT surgery_room_status_ck CHECK (status IN ('active', 'inactive'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE surgery (
    id bigint unsigned NOT NULL AUTO_INCREMENT,
    tenant_id bigint unsigned NOT NULL,
    system_unit_id int NOT NULL,
    patient_id bigint unsigned NOT NULL,
    encounter_id bigint unsigned NOT NULL,
    room_id bigint unsigned NOT NULL,
    procedure_catalog_item_id bigint unsigned NOT NULL,
    procedure_name varchar(190) NOT NULL,
    procedure_price_cents int unsigned NOT NULL,
    surgeon_system_user_id int NOT NULL,
    scheduled_by_system_user_id int NOT NULL,
    scheduled_start_at timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    scheduled_end_at timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    status varchar(20) NOT NULL DEFAULT 'scheduled',
    notes_text varchar(500) NULL,
    consent_signer_name varchar(190) NULL,
    consent_text text NULL,
    consent_recorded_at timestamp(6) NULL DEFAULT NULL,
    consent_recorded_by_system_user_id int NULL,
    started_at timestamp(6) NULL DEFAULT NULL,
    completed_at timestamp(6) NULL DEFAULT NULL,
    completed_by_system_user_id int NULL,
    cancelled_at timestamp(6) NULL DEFAULT NULL,
    cancelled_by_system_user_id int NULL,
    cancellation_reason_text varchar(500) NULL,
    followup_appointment_id bigint unsigned NULL,
    created_at timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    KEY surgery_unit_start_idx (tenant_id, system_unit_id, scheduled_start_at),
    KEY surgery_room_start_idx (tenant_id, room_id, scheduled_start_at),
    KEY surgery_encounter_idx (tenant_id, encounter_id),
    KEY surgery_unit_idx (system_unit_id),
    KEY surgery_patient_idx (patient_id),
    KEY surgery_encounter_fk_idx (encounter_id),
    KEY surgery_room_idx (room_id),
    KEY surgery_procedure_idx (procedure_catalog_item_id),
    KEY surgery_surgeon_idx (surgeon_system_user_id),
    KEY surgery_scheduled_by_idx (scheduled_by_system_user_id),
    KEY surgery_consent_by_idx (consent_recorded_by_system_user_id),
    KEY surgery_completed_by_idx (completed_by_system_user_id),
    KEY surgery_cancelled_by_idx (cancelled_by_system_user_id),
    KEY surgery_followup_idx (followup_appointment_id),
    CONSTRAINT surgery_tenant_fk FOREIGN KEY (tenant_id) REFERENCES tenant (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT surgery_unit_fk FOREIGN KEY (system_unit_id) REFERENCES system_unit (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT surgery_patient_fk FOREIGN KEY (patient_id) REFERENCES patient (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT surgery_encounter_fk FOREIGN KEY (encounter_id) REFERENCES encounter (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT surgery_room_fk FOREIGN KEY (room_id) REFERENCES surgery_room (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT surgery_procedure_fk FOREIGN KEY (procedure_catalog_item_id) REFERENCES procedure_catalog_item (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT surgery_followup_fk FOREIGN KEY (followup_appointment_id) REFERENCES appointment (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT surgery_surgeon_fk FOREIGN KEY (surgeon_system_user_id) REFERENCES system_users (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT surgery_scheduled_by_fk FOREIGN KEY (scheduled_by_system_user_id) REFERENCES system_users (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT surgery_consent_by_fk FOREIGN KEY (consent_recorded_by_system_user_id) REFERENCES system_users (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT surgery_completed_by_fk FOREIGN KEY (completed_by_system_user_id) REFERENCES system_users (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT surgery_cancelled_by_fk FOREIGN KEY (cancelled_by_system_user_id) REFERENCES system_users (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT surgery_status_ck CHECK (status IN ('scheduled', 'pre_op', 'in_progress', 'completed', 'cancelled')),
    CONSTRAINT surgery_period_ck CHECK (scheduled_end_at > scheduled_start_at),
    CONSTRAINT surgery_consent_ck CHECK ((consent_recorded_at IS NULL AND consent_recorded_by_system_user_id IS NULL) OR (consent_recorded_at IS NOT NULL AND consent_recorded_by_system_user_id IS NOT NULL AND consent_signer_name IS NOT NULL AND consent_text IS NOT NULL)),
    CONSTRAINT surgery_started_ck CHECK ((status IN ('in_progress', 'completed') AND started_at IS NOT NULL) OR (status IN ('scheduled', 'pre_op', 'cancelled') AND started_at IS NULL)),
    CONSTRAINT surgery_completed_ck CHECK ((status = 'completed' AND completed_at IS NOT NULL AND completed_by_system_user_id IS NOT NULL) OR (status <> 'completed' AND completed_at IS NULL)),
    CONSTRAINT surgery_cancelled_ck CHECK ((status = 'cancelled' AND cancelled_at IS NOT NULL AND cancellation_reason_text IS NOT NULL) OR (status <> 'cancelled' AND cancelled_at IS NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE surgery_team (
    id bigint unsigned NOT NULL AUTO_INCREMENT,
    tenant_id bigint unsigned NOT NULL,
    surgery_id bigint unsigned NOT NULL,
    system_user_id int NOT NULL,
    role varchar(20) NOT NULL,
    created_at timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY surgery_team_member_uq (surgery_id, system_user_id, role),
    KEY surgery_team_tenant_surgery_idx (tenant_id, surgery_id),
    KEY surgery_team_user_idx (system_user_id),
    CONSTRAINT surgery_team_tenant_fk FOREIGN KEY (tenant_id) REFERENCES tenant (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT surgery_team_surgery_fk FOREIGN KEY (surgery_id) REFERENCES surgery (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT surgery_team_user_fk FOREIGN KEY (system_user_id) REFERENCES system_users (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT surgery_team_role_ck CHECK (role IN ('surgeon', 'anesthetist', 'assistant', 'circulating'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE surgery_checklist (
    id bigint unsigned NOT NULL AUTO_INCREMENT,
    tenant_id bigint unsigned NOT NULL,
    surgery_id bigint unsigned NOT NULL,
    phase varchar(20) NOT NULL,
    item_code varchar(40) NOT NULL,
    checked_by_system_user_id int NOT NULL,
    checked_at timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    created_at timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY surgery_checklist_item_uq (surgery_id, phase, item_code),
    KEY surgery_checklist_tenant_surgery_idx (tenant_id, surgery_id),
    KEY surgery_checklist_checked_by_idx (checked_by_system_user_id),
    CONSTRAINT surgery_checklist_tenant_fk FOREIGN KEY (tenant_id) REFERENCES tenant (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT surgery_checklist_surgery_fk FOREIGN KEY (surgery_id) REFERENCES surgery (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT surgery_checklist_checked_by_fk FOREIGN KEY (checked_by_system_user_id) REFERENCES system_users (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT surgery_checklist_phase_ck CHECK (phase IN ('sign_in', 'time_out', 'sign_out'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE surgery_event (
    id bigint unsigned NOT NULL AUTO_INCREMENT,
    tenant_id bigint unsigned NOT NULL,
    surgery_id bigint unsigned NOT NULL,
    event_type varchar(30) NOT NULL,
    recorded_by_system_user_id int NOT NULL,
    recorded_at timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    notes_text text NULL,
    created_at timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    KEY surgery_event_surgery_recorded_idx (tenant_id, surgery_id, recorded_at),
    KEY surgery_event_surgery_idx (surgery_id),
    KEY surgery_event_recorded_by_idx (recorded_by_system_user_id),
    CONSTRAINT surgery_event_tenant_fk FOREIGN KEY (tenant_id) REFERENCES tenant (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT surgery_event_surgery_fk FOREIGN KEY (surgery_id) REFERENCES surgery (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT surgery_event_recorded_by_fk FOREIGN KEY (recorded_by_system_user_id) REFERENCES system_users (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT surgery_event_type_ck CHECK (event_type IN ('pre_op', 'anesthesia', 'intra_op', 'complication', 'post_op', 'scheduled', 'consent', 'checklist', 'status', 'material', 'cancellation', 'completion', 'followup'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE surgery_material (
    id bigint unsigned NOT NULL AUTO_INCREMENT,
    tenant_id bigint unsigned NOT NULL,
    surgery_id bigint unsigned NOT NULL,
    product_id bigint unsigned NOT NULL,
    quantity int unsigned NOT NULL,
    recorded_by_system_user_id int NOT NULL,
    recorded_at timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    created_at timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    KEY surgery_material_surgery_idx (tenant_id, surgery_id),
    KEY surgery_material_surgery_fk_idx (surgery_id),
    KEY surgery_material_product_idx (product_id),
    KEY surgery_material_recorded_by_idx (recorded_by_system_user_id),
    CONSTRAINT surgery_material_tenant_fk FOREIGN KEY (tenant_id) REFERENCES tenant (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT surgery_material_surgery_fk FOREIGN KEY (surgery_id) REFERENCES surgery (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT surgery_material_product_fk FOREIGN KEY (product_id) REFERENCES product (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT surgery_material_recorded_by_fk FOREIGN KEY (recorded_by_system_user_id) REFERENCES system_users (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT surgery_material_quantity_ck CHECK (quantity >= 1 AND quantity <= 9999)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

ALTER TABLE encounter_account_item DROP CHECK encounter_account_item_source_type_ck;

ALTER TABLE encounter_account_item
    ADD CONSTRAINT encounter_account_item_source_type_ck CHECK (source_type IN ('procedure_execution', 'exam_request', 'manual', 'hospitalization_stay', 'hospitalization_administration', 'surgery_procedure', 'surgery_material'));

ALTER TABLE stock_movement DROP CHECK stock_movement_reason_ck;

ALTER TABLE stock_movement
    ADD CONSTRAINT stock_movement_reason_ck CHECK (reason IN ('purchase_entry', 'procedure_consumption', 'sale_consumption', 'manual_adjustment', 'hospitalization_consumption', 'surgery_consumption'));

INSERT INTO schema_migrations (version, checksum)
VALUES ('20261005_0011_phase6b_surgery',
        '0000000000000000000000000000000000000000000000000000000000000000' /* 64-char PLACEHOLDER: replace before applying */);
