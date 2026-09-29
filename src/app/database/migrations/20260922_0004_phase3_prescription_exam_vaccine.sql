-- Central Vet Pro - Phase 3 clinic core (prescription, exam, vaccine)
-- Migration: 20260922_0004_phase3_prescription_exam_vaccine
-- Status: PREPARED ONLY. Do not execute without a fresh backup and explicit SQL approval.
-- Target: MySQL 8.0.x, database centralvet, after 20260922_0003_phase2_encounter has
-- been applied (tenant, system_unit, patient, appointment, encounter, system_users
-- and schema_migrations already exist).
--
-- Effects:
--   * creates prescription and prescription_item, tenant-scoped (tenant_id bigint
--     unsigned NOT NULL, FK to tenant(id)), prescription is a single aggregate
--     header linked to encounter/patient/professional, prescription_item is its
--     child table (medication/dose/unit/route/frequency/duration, free text, no
--     medication catalog in this phase);
--   * creates exam_catalog_item (tenant-scoped catalog of billable exams, with
--     optional partner and price), exam_request (a catalog item requested for a
--     patient inside an encounter) and exam_result (the 1:1 result of a request,
--     structured text and/or a stored object key, with a pending_review flag);
--   * creates vaccine_catalog_item (tenant-scoped catalog with a simple stock
--     counter, decremented by the application on each vaccination — not a full
--     inventory module), vaccine_protocol (the configurable dose schedule of a
--     catalog item) and vaccination (an actual application of a catalog item to
--     a patient inside an encounter, with lot/expiry/dose/next dose);
--   * adds FKs from prescription/exam_request/vaccination to encounter(id) and
--     patient(id); from exam_request to exam_catalog_item(id); from exam_result to
--     exam_request(id); from vaccination and vaccine_protocol to
--     vaccine_catalog_item(id); from prescription/exam_request/vaccination to
--     system_users(id) (professional); from prescription_item to prescription(id);
--   * creates 8 tables, 31 secondary indexes (including 4 UNIQUE), 22 foreign keys
--     and 4 CHECK constraints.
--
-- No data is seeded by this migration: all 8 tables start empty for every
-- existing tenant.
--
-- Risk: prescription/exam_result carry clinical free-text (orientation,
-- structured exam result) and exam_result may reference a stored object (file)
-- by key, reusing the Phase 0 per-tenant storage key scheme; no new PII beyond
-- what patient/tutor/encounter already store is added by these tables
-- themselves. Referential integrity to encounter/patient/system_users and to
-- the new catalog tables is enforced by FK, not just at the application layer.
-- vaccine_catalog_item.stock_quantity is an application-managed counter, not
-- enforced by a DB constraint (no CHECK preventing it from going negative,
-- since a defensive floor belongs in the Application service, not the schema).
--
-- MySQL DDL commits implicitly. On failure, stop and inspect; do not rerun blindly.
-- The literal migration checksum below MUST be replaced with the approved artifact's
-- SHA-256 by the migration executor before this file is applied.

CREATE TABLE exam_catalog_item (
    id bigint unsigned NOT NULL AUTO_INCREMENT,
    tenant_id bigint unsigned NOT NULL,
    name varchar(190) NOT NULL,
    partner_name varchar(190) NULL,
    price_cents int unsigned NOT NULL,
    active tinyint(1) NOT NULL DEFAULT 1,
    created_at timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY exam_catalog_item_tenant_name_uq (tenant_id, name),
    KEY exam_catalog_item_tenant_idx (tenant_id),
    KEY exam_catalog_item_tenant_active_idx (tenant_id, active),
    CONSTRAINT exam_catalog_item_tenant_fk FOREIGN KEY (tenant_id) REFERENCES tenant (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE vaccine_catalog_item (
    id bigint unsigned NOT NULL AUTO_INCREMENT,
    tenant_id bigint unsigned NOT NULL,
    name varchar(190) NOT NULL,
    manufacturer varchar(190) NULL,
    stock_quantity int unsigned NOT NULL DEFAULT 0,
    active tinyint(1) NOT NULL DEFAULT 1,
    created_at timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY vaccine_catalog_item_tenant_name_uq (tenant_id, name),
    KEY vaccine_catalog_item_tenant_idx (tenant_id),
    KEY vaccine_catalog_item_tenant_active_idx (tenant_id, active),
    CONSTRAINT vaccine_catalog_item_tenant_fk FOREIGN KEY (tenant_id) REFERENCES tenant (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE prescription (
    id bigint unsigned NOT NULL AUTO_INCREMENT,
    tenant_id bigint unsigned NOT NULL,
    encounter_id bigint unsigned NOT NULL,
    patient_id bigint unsigned NOT NULL,
    professional_system_user_id int NOT NULL,
    orientation_text text NULL,
    status varchar(20) NOT NULL DEFAULT 'draft',
    created_at timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    KEY prescription_tenant_idx (tenant_id),
    KEY prescription_tenant_status_idx (tenant_id, status),
    KEY prescription_encounter_idx (encounter_id),
    KEY prescription_patient_idx (patient_id),
    KEY prescription_professional_idx (professional_system_user_id),
    CONSTRAINT prescription_tenant_fk FOREIGN KEY (tenant_id) REFERENCES tenant (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT prescription_encounter_fk FOREIGN KEY (encounter_id) REFERENCES encounter (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT prescription_patient_fk FOREIGN KEY (patient_id) REFERENCES patient (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT prescription_professional_fk FOREIGN KEY (professional_system_user_id) REFERENCES system_users (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT prescription_status_ck CHECK (status IN ('draft', 'issued'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE prescription_item (
    id bigint unsigned NOT NULL AUTO_INCREMENT,
    tenant_id bigint unsigned NOT NULL,
    prescription_id bigint unsigned NOT NULL,
    medication_name varchar(190) NOT NULL,
    dose varchar(40) NOT NULL,
    dose_unit varchar(20) NOT NULL,
    route varchar(40) NOT NULL,
    frequency varchar(60) NOT NULL,
    duration varchar(60) NOT NULL,
    created_at timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    KEY prescription_item_tenant_idx (tenant_id),
    KEY prescription_item_prescription_idx (prescription_id),
    CONSTRAINT prescription_item_tenant_fk FOREIGN KEY (tenant_id) REFERENCES tenant (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT prescription_item_prescription_fk FOREIGN KEY (prescription_id) REFERENCES prescription (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE exam_request (
    id bigint unsigned NOT NULL AUTO_INCREMENT,
    tenant_id bigint unsigned NOT NULL,
    encounter_id bigint unsigned NOT NULL,
    patient_id bigint unsigned NOT NULL,
    exam_catalog_item_id bigint unsigned NOT NULL,
    professional_system_user_id int NOT NULL,
    status varchar(20) NOT NULL DEFAULT 'requested',
    requested_at timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    KEY exam_request_tenant_idx (tenant_id),
    KEY exam_request_tenant_status_idx (tenant_id, status),
    KEY exam_request_encounter_idx (encounter_id),
    KEY exam_request_patient_idx (patient_id),
    KEY exam_request_exam_catalog_item_idx (exam_catalog_item_id),
    KEY exam_request_professional_idx (professional_system_user_id),
    CONSTRAINT exam_request_tenant_fk FOREIGN KEY (tenant_id) REFERENCES tenant (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT exam_request_encounter_fk FOREIGN KEY (encounter_id) REFERENCES encounter (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT exam_request_patient_fk FOREIGN KEY (patient_id) REFERENCES patient (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT exam_request_exam_catalog_item_fk FOREIGN KEY (exam_catalog_item_id) REFERENCES exam_catalog_item (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT exam_request_professional_fk FOREIGN KEY (professional_system_user_id) REFERENCES system_users (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT exam_request_status_ck CHECK (status IN ('requested', 'result_available'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE exam_result (
    id bigint unsigned NOT NULL AUTO_INCREMENT,
    tenant_id bigint unsigned NOT NULL,
    exam_request_id bigint unsigned NOT NULL,
    structured_result_text text NULL,
    stored_object_key varchar(255) NULL,
    pending_review tinyint(1) NOT NULL DEFAULT 1,
    received_at timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    created_at timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY exam_result_exam_request_uq (exam_request_id),
    KEY exam_result_tenant_idx (tenant_id),
    KEY exam_result_tenant_pending_review_idx (tenant_id, pending_review),
    CONSTRAINT exam_result_tenant_fk FOREIGN KEY (tenant_id) REFERENCES tenant (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT exam_result_exam_request_fk FOREIGN KEY (exam_request_id) REFERENCES exam_request (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE vaccine_protocol (
    id bigint unsigned NOT NULL AUTO_INCREMENT,
    tenant_id bigint unsigned NOT NULL,
    vaccine_catalog_item_id bigint unsigned NOT NULL,
    dose_number int unsigned NOT NULL,
    interval_days_from_previous int unsigned NULL,
    created_at timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY vaccine_protocol_item_dose_number_uq (vaccine_catalog_item_id, dose_number),
    KEY vaccine_protocol_tenant_idx (tenant_id),
    KEY vaccine_protocol_vaccine_catalog_item_idx (vaccine_catalog_item_id),
    CONSTRAINT vaccine_protocol_tenant_fk FOREIGN KEY (tenant_id) REFERENCES tenant (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT vaccine_protocol_vaccine_catalog_item_fk FOREIGN KEY (vaccine_catalog_item_id) REFERENCES vaccine_catalog_item (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT vaccine_protocol_dose_number_ck CHECK (dose_number >= 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE vaccination (
    id bigint unsigned NOT NULL AUTO_INCREMENT,
    tenant_id bigint unsigned NOT NULL,
    encounter_id bigint unsigned NOT NULL,
    patient_id bigint unsigned NOT NULL,
    vaccine_catalog_item_id bigint unsigned NOT NULL,
    lot varchar(60) NULL,
    expiry_date date NULL,
    dose_number int unsigned NOT NULL,
    professional_system_user_id int NOT NULL,
    applied_at timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    next_dose_at date NULL,
    created_at timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    KEY vaccination_tenant_idx (tenant_id),
    KEY vaccination_tenant_patient_applied_at_idx (tenant_id, patient_id, applied_at),
    KEY vaccination_encounter_idx (encounter_id),
    KEY vaccination_patient_idx (patient_id),
    KEY vaccination_vaccine_catalog_item_idx (vaccine_catalog_item_id),
    KEY vaccination_professional_idx (professional_system_user_id),
    CONSTRAINT vaccination_tenant_fk FOREIGN KEY (tenant_id) REFERENCES tenant (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT vaccination_encounter_fk FOREIGN KEY (encounter_id) REFERENCES encounter (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT vaccination_patient_fk FOREIGN KEY (patient_id) REFERENCES patient (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT vaccination_vaccine_catalog_item_fk FOREIGN KEY (vaccine_catalog_item_id) REFERENCES vaccine_catalog_item (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT vaccination_professional_fk FOREIGN KEY (professional_system_user_id) REFERENCES system_users (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT vaccination_dose_number_ck CHECK (dose_number >= 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT INTO schema_migrations (version, checksum)
VALUES ('20260922_0004_phase3_prescription_exam_vaccine',
        '48d4b814b999194a0af672d3b5be5a1936214c694f484ee06a575797caf94825');
