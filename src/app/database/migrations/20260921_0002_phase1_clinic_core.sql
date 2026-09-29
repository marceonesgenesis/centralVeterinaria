-- Central Vet Pro - Phase 1 clinic core (tutor, patient, service, agenda, queue)
-- Migration: 20260921_0002_phase1_clinic_core
-- Status: PREPARED ONLY. Do not execute without a fresh backup and explicit SQL approval.
-- Target: MySQL 8.0.x, database centralvet, after 20260920_0001_foundation_multitenancy
-- has been applied (tenant, system_unit.tenant_id and schema_migrations already exist).
--
-- Effects:
--   * creates tutor, patient, service, appointment and queue_entry, all
--     tenant-scoped (tenant_id bigint unsigned NOT NULL, FK to tenant(id));
--   * adds FKs from appointment/queue_entry to system_unit and system_users
--     (professional), from patient to tutor, and from appointment/queue_entry
--     to patient/service;
--   * queue_entry.appointment_id is nullable (walk-in check-in without a
--     prior appointment is a supported flow);
--   * creates 5 tables, 24 secondary indexes, 16 foreign keys, 3 unique
--     constraints and 3 CHECK constraints.
--
-- No compatibility data is seeded by this migration (unlike Phase 0's
-- compatibility tenant): tutor/patient/service/appointment/queue_entry start
-- empty for every existing tenant.
--
-- MySQL DDL commits implicitly. On failure, stop and inspect; do not rerun blindly.
-- The literal migration checksum below MUST be replaced with the approved artifact's
-- SHA-256 by the migration executor before this file is applied.

CREATE TABLE tutor (
    id bigint unsigned NOT NULL AUTO_INCREMENT,
    tenant_id bigint unsigned NOT NULL,
    public_id char(36) NOT NULL,
    full_name varchar(190) NOT NULL,
    document varchar(20) NULL,
    phone varchar(20) NOT NULL,
    email varchar(190) NULL,
    address varchar(255) NULL,
    created_at timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY tutor_public_id_uq (public_id),
    UNIQUE KEY tutor_tenant_document_uq (tenant_id, document),
    KEY tutor_tenant_idx (tenant_id),
    KEY tutor_tenant_full_name_idx (tenant_id, full_name),
    KEY tutor_tenant_phone_idx (tenant_id, phone),
    CONSTRAINT tutor_tenant_fk FOREIGN KEY (tenant_id) REFERENCES tenant (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE patient (
    id bigint unsigned NOT NULL AUTO_INCREMENT,
    tenant_id bigint unsigned NOT NULL,
    tutor_id bigint unsigned NOT NULL,
    name varchar(190) NOT NULL,
    species varchar(60) NOT NULL,
    breed varchar(120) NULL,
    sex char(1) NULL,
    birth_date date NULL,
    weight_kg decimal(6,2) NULL,
    color varchar(60) NULL,
    notes text NULL,
    created_at timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    KEY patient_tenant_idx (tenant_id),
    KEY patient_tenant_name_idx (tenant_id, name),
    KEY patient_tutor_idx (tutor_id),
    CONSTRAINT patient_tenant_fk FOREIGN KEY (tenant_id) REFERENCES tenant (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT patient_tutor_fk FOREIGN KEY (tutor_id) REFERENCES tutor (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT patient_sex_ck CHECK (sex IS NULL OR sex IN ('M', 'F', 'U'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE service (
    id bigint unsigned NOT NULL AUTO_INCREMENT,
    tenant_id bigint unsigned NOT NULL,
    name varchar(190) NOT NULL,
    category varchar(60) NULL,
    duration_minutes int unsigned NOT NULL,
    price_cents int unsigned NOT NULL,
    active tinyint(1) NOT NULL DEFAULT 1,
    created_at timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY service_tenant_name_uq (tenant_id, name),
    KEY service_tenant_idx (tenant_id),
    KEY service_tenant_active_idx (tenant_id, active),
    CONSTRAINT service_tenant_fk FOREIGN KEY (tenant_id) REFERENCES tenant (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE appointment (
    id bigint unsigned NOT NULL AUTO_INCREMENT,
    tenant_id bigint unsigned NOT NULL,
    system_unit_id int NOT NULL,
    patient_id bigint unsigned NOT NULL,
    service_id bigint unsigned NOT NULL,
    professional_system_user_id int NOT NULL,
    scheduled_at timestamp(6) NOT NULL,
    status varchar(20) NOT NULL DEFAULT 'agendado',
    created_at timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    KEY appointment_tenant_idx (tenant_id),
    KEY appointment_tenant_unit_scheduled_idx (tenant_id, system_unit_id, scheduled_at),
    KEY appointment_tenant_professional_scheduled_idx (tenant_id, professional_system_user_id, scheduled_at),
    KEY appointment_patient_idx (patient_id),
    KEY appointment_service_idx (service_id),
    CONSTRAINT appointment_tenant_fk FOREIGN KEY (tenant_id) REFERENCES tenant (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT appointment_unit_fk FOREIGN KEY (system_unit_id) REFERENCES system_unit (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT appointment_patient_fk FOREIGN KEY (patient_id) REFERENCES patient (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT appointment_service_fk FOREIGN KEY (service_id) REFERENCES service (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT appointment_professional_fk FOREIGN KEY (professional_system_user_id) REFERENCES system_users (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT appointment_status_ck CHECK (status IN ('agendado', 'confirmado', 'em_atendimento', 'atendido', 'cancelado', 'faltou'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE queue_entry (
    id bigint unsigned NOT NULL AUTO_INCREMENT,
    tenant_id bigint unsigned NOT NULL,
    system_unit_id int NOT NULL,
    patient_id bigint unsigned NOT NULL,
    appointment_id bigint unsigned NULL,
    professional_system_user_id int NOT NULL,
    status varchar(20) NOT NULL DEFAULT 'aguardando',
    checked_in_at timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    called_at timestamp(6) NULL,
    started_at timestamp(6) NULL,
    finished_at timestamp(6) NULL,
    created_at timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    KEY queue_entry_tenant_idx (tenant_id),
    KEY queue_entry_tenant_unit_status_idx (tenant_id, system_unit_id, status),
    KEY queue_entry_patient_idx (patient_id),
    KEY queue_entry_appointment_idx (appointment_id),
    KEY queue_entry_professional_idx (professional_system_user_id),
    CONSTRAINT queue_entry_tenant_fk FOREIGN KEY (tenant_id) REFERENCES tenant (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT queue_entry_unit_fk FOREIGN KEY (system_unit_id) REFERENCES system_unit (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT queue_entry_patient_fk FOREIGN KEY (patient_id) REFERENCES patient (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT queue_entry_appointment_fk FOREIGN KEY (appointment_id) REFERENCES appointment (id)
        ON UPDATE RESTRICT ON DELETE SET NULL,
    CONSTRAINT queue_entry_professional_fk FOREIGN KEY (professional_system_user_id) REFERENCES system_users (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT queue_entry_status_ck CHECK (status IN ('aguardando', 'em_atendimento', 'atendido', 'atrasado'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT INTO schema_migrations (version, checksum)
VALUES ('20260921_0002_phase1_clinic_core',
        '86225702a6196134abd1b9d406cf2228493e759eeb73b3b4f4889e7a02803d7c');
