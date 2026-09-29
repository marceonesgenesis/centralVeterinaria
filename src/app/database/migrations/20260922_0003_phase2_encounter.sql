-- Central Vet Pro - Phase 2 clinic core (encounter, single aggregate)
-- Migration: 20260922_0003_phase2_encounter
-- Status: PREPARED ONLY. Do not execute without a fresh backup and explicit SQL approval.
-- Target: MySQL 8.0.x, database centralvet, after 20260921_0002_phase1_clinic_core
-- has been applied (tenant, system_unit, patient, appointment, system_users and
-- schema_migrations already exist).
--
-- Effects:
--   * creates encounter, tenant-scoped (tenant_id bigint unsigned NOT NULL,
--     FK to tenant(id)), as a single aggregate table (anamnesis, vital signs,
--     physical exam, diagnosis and clinical plan as direct columns, not
--     separate tables) so autosave can persist the whole draft with a single
--     UPDATE;
--   * adds FKs from encounter to system_unit, patient, appointment (nullable)
--     and system_users (professional);
--   * encounter.appointment_id is nullable (a walk-in encounter may start
--     without a prior appointment);
--   * creates 1 table, 6 secondary indexes, 4 foreign keys and 1 CHECK
--     constraint.
--
-- No data is seeded by this migration: encounter starts empty for every
-- existing tenant.
--
-- Risk: encounter carries clinical free-text (anamnesis, physical exam,
-- diagnosis, clinical plan, AI summary) and vital signs; no PII beyond what
-- patient/tutor already store is added by this table itself. Referential
-- integrity to patient/appointment/system_unit/system_users is enforced by
-- FK, not just at the application layer.
--
-- MySQL DDL commits implicitly. On failure, stop and inspect; do not rerun blindly.
-- The literal migration checksum below MUST be replaced with the approved artifact's
-- SHA-256 by the migration executor before this file is applied.

CREATE TABLE encounter (
    id bigint unsigned NOT NULL AUTO_INCREMENT,
    tenant_id bigint unsigned NOT NULL,
    system_unit_id int NOT NULL,
    patient_id bigint unsigned NOT NULL,
    appointment_id bigint unsigned NULL,
    professional_system_user_id int NOT NULL,
    status varchar(20) NOT NULL DEFAULT 'in_progress',
    started_at timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    finished_at timestamp(6) NULL,
    anamnesis_text text NULL,
    temperature_c decimal(4,1) NULL,
    heart_rate_bpm int unsigned NULL,
    respiratory_rate_mpm int unsigned NULL,
    weight_kg decimal(6,2) NULL,
    mucous_membranes varchar(60) NULL,
    capillary_refill_seconds decimal(4,1) NULL,
    physical_exam_text text NULL,
    diagnosis_text text NULL,
    clinical_plan_text text NULL,
    ai_summary_text text NULL,
    ai_summary_accepted_at timestamp(6) NULL,
    created_at timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    KEY encounter_tenant_idx (tenant_id),
    KEY encounter_tenant_unit_status_idx (tenant_id, system_unit_id, status),
    KEY encounter_patient_idx (patient_id),
    KEY encounter_appointment_idx (appointment_id),
    KEY encounter_professional_idx (professional_system_user_id),
    KEY encounter_tenant_started_idx (tenant_id, started_at),
    CONSTRAINT encounter_tenant_fk FOREIGN KEY (tenant_id) REFERENCES tenant (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT encounter_unit_fk FOREIGN KEY (system_unit_id) REFERENCES system_unit (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT encounter_patient_fk FOREIGN KEY (patient_id) REFERENCES patient (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT encounter_appointment_fk FOREIGN KEY (appointment_id) REFERENCES appointment (id)
        ON UPDATE RESTRICT ON DELETE SET NULL,
    CONSTRAINT encounter_professional_fk FOREIGN KEY (professional_system_user_id) REFERENCES system_users (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT encounter_status_ck CHECK (status IN ('in_progress', 'finished'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT INTO schema_migrations (version, checksum)
VALUES ('20260922_0003_phase2_encounter',
        'eacc567ba71c16e994458b2d89d8974e572b4a3abde000339d065e41610caec0');
