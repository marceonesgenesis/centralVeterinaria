-- Central Vet Pro - Phase 7A communication MVP (per-channel consent of the tutor, message
-- templates, message history with status and idempotent reminders, the follow-up link
-- of an appointment scheduled from an encounter) and the indexes the reminder and
-- pending-items queries read
-- Migration: 20261006_0012_phase7a_communication
-- Status: PREPARED ONLY. Do not execute without a fresh backup and explicit SQL approval.
-- Target: MySQL 8.0.x, database centralvet, after 20261005_0011_phase6b_surgery
-- has been applied (tenant, system_unit, system_users, tutor, patient, appointment,
-- encounter, vaccination, receivable and schema_migrations already exist).
--
-- Effects:
--   * creates communication_preference (one row per tenant, tutor and channel email/
--     whatsapp, with status opted_in/opted_out, the consent source in_person/phone/
--     written/online, the user who recorded it and when);
--   * creates message_template (templates per tenant, with purpose, channel, name,
--     subject (required for email), plain-text body and status active/inactive);
--   * creates communication_message (one message to a tutor: purpose, channel, origin
--     automation/manual, optional source appointment/vaccination/receivable, the
--     per-tenant dedupe_key of automatic reminders, the legal basis legitimate_interest/
--     consent, recipient, subject, body, status queued/sent/failed/manual/cancelled with
--     attempt count, last error code, provider data and the claimed/sent/failed/
--     cancelled timestamps and users); it is named communication_message so it does not
--     collide with Adianti's system_message;
--   * creates appointment_followup (the link between a follow-up appointment and the
--     encounter that scheduled it; one row per appointment);
--   * adds the index vaccination_tenant_next_dose_idx (tenant_id, next_dose_at) on
--     vaccination and receivable_tenant_status_idx (tenant_id, status) on receivable,
--     each one in its own ALTER TABLE ... ADD KEY statement;
--   * adds FKs from communication_preference to tenant/tutor/system_users; from
--     message_template to tenant/system_users (created_by, updated_by); from
--     communication_message to tenant/system_unit/tutor/patient/message_template/
--     system_users (manual_sent_by, cancelled_by, created_by); from appointment_followup
--     to tenant/appointment/encounter/system_users;
--   * creates 4 tables, 18 CHECK constraints (communication_preference.channel, .status,
--     .source; message_template.purpose, .channel, .status, .subject;
--     communication_message.status, .channel, .origin, .purpose, .source, .legal_basis,
--     .sent, .manual, .failed, .cancelled, .subject), 4 UNIQUE constraints
--     (communication_preference_tutor_channel_uq, message_template_tenant_name_uq,
--     communication_message_tenant_dedupe_uq, appointment_followup_appointment_uq),
--     18 foreign keys and 2 new indexes on existing tables (vaccination, receivable).
--
-- No data is seeded by this migration: the 4 new tables start empty for every existing
-- tenant (without an active template the Application uses its built-in pt-BR defaults),
-- and existing vaccination, receivable and appointment rows are preserved (only indexes
-- are added to them).
--
-- Risk: the 2 ADD KEY statements build an index on vaccination and receivable (online
-- in InnoDB, but run the migration in a maintenance window). One active template per
-- purpose and channel, status transitions of a message, the claim by claimed_at and the
-- consent rules (legitimate interest unless opted out; explicit opt-in for vaccine and
-- receivable reminders) are Application rules. communication_message stores the
-- recipient (e-mail or phone) and the message body: they are personal data already held
-- in the clinic's records and must never be copied to logs, queue payloads or URLs.
-- Follow-up appointments scheduled before this migration are not linked.
--
-- Rollback: restore the backup taken before applying, or write a numbered reverse
-- migration (see docs/runbooks/migration-rollback.md). Do not drop the new tables by hand
-- once messages have been sent: communication_message is the communication history.
--
-- MySQL DDL commits implicitly. On failure, stop and inspect; do not rerun blindly.
-- The literal migration checksum below MUST be replaced with the approved artifact's
-- SHA-256 by the migration executor before this file is applied.

CREATE TABLE communication_preference (
    id bigint unsigned NOT NULL AUTO_INCREMENT,
    tenant_id bigint unsigned NOT NULL,
    tutor_id bigint unsigned NOT NULL,
    channel varchar(20) NOT NULL,
    status varchar(20) NOT NULL,
    consent_source varchar(20) NOT NULL,
    changed_by_system_user_id int NOT NULL,
    changed_at timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    created_at timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY communication_preference_tutor_channel_uq (tenant_id, tutor_id, channel),
    KEY communication_preference_tutor_idx (tutor_id),
    KEY communication_preference_changed_by_idx (changed_by_system_user_id),
    CONSTRAINT communication_preference_tenant_fk FOREIGN KEY (tenant_id) REFERENCES tenant (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT communication_preference_tutor_fk FOREIGN KEY (tutor_id) REFERENCES tutor (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT communication_preference_changed_by_fk FOREIGN KEY (changed_by_system_user_id) REFERENCES system_users (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT communication_preference_channel_ck CHECK (channel IN ('email', 'whatsapp')),
    CONSTRAINT communication_preference_status_ck CHECK (status IN ('opted_in', 'opted_out')),
    CONSTRAINT communication_preference_source_ck CHECK (consent_source IN ('in_person', 'phone', 'written', 'online'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE message_template (
    id bigint unsigned NOT NULL AUTO_INCREMENT,
    tenant_id bigint unsigned NOT NULL,
    purpose varchar(30) NOT NULL,
    channel varchar(20) NOT NULL,
    name varchar(120) NOT NULL,
    subject varchar(190) NULL,
    body_text text NOT NULL,
    status varchar(20) NOT NULL DEFAULT 'active',
    created_by_system_user_id int NOT NULL,
    updated_by_system_user_id int NULL,
    created_at timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY message_template_tenant_name_uq (tenant_id, name),
    KEY message_template_purpose_idx (tenant_id, purpose, channel, status),
    KEY message_template_created_by_idx (created_by_system_user_id),
    KEY message_template_updated_by_idx (updated_by_system_user_id),
    CONSTRAINT message_template_tenant_fk FOREIGN KEY (tenant_id) REFERENCES tenant (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT message_template_created_by_fk FOREIGN KEY (created_by_system_user_id) REFERENCES system_users (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT message_template_updated_by_fk FOREIGN KEY (updated_by_system_user_id) REFERENCES system_users (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT message_template_purpose_ck CHECK (purpose IN ('appointment_confirmation', 'vaccine_due', 'return_reminder', 'receivable_open', 'document_ready', 'custom')),
    CONSTRAINT message_template_channel_ck CHECK (channel IN ('email', 'whatsapp')),
    CONSTRAINT message_template_status_ck CHECK (status IN ('active', 'inactive')),
    CONSTRAINT message_template_subject_ck CHECK (channel <> 'email' OR subject IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE communication_message (
    id bigint unsigned NOT NULL AUTO_INCREMENT,
    tenant_id bigint unsigned NOT NULL,
    system_unit_id int NOT NULL,
    tutor_id bigint unsigned NOT NULL,
    patient_id bigint unsigned NULL,
    template_id bigint unsigned NULL,
    purpose varchar(30) NOT NULL,
    channel varchar(20) NOT NULL,
    origin varchar(20) NOT NULL,
    source_type varchar(30) NULL,
    source_id bigint unsigned NULL,
    dedupe_key varchar(120) NULL,
    legal_basis varchar(30) NOT NULL,
    recipient varchar(190) NOT NULL,
    subject varchar(190) NULL,
    body_text text NOT NULL,
    status varchar(20) NOT NULL DEFAULT 'queued',
    attempt_count int unsigned NOT NULL DEFAULT 0,
    last_error_code varchar(60) NULL,
    provider varchar(30) NULL,
    provider_message_id varchar(190) NULL,
    claimed_at timestamp(6) NULL DEFAULT NULL,
    sent_at timestamp(6) NULL DEFAULT NULL,
    failed_at timestamp(6) NULL DEFAULT NULL,
    cancelled_at timestamp(6) NULL DEFAULT NULL,
    manual_sent_by_system_user_id int NULL,
    cancelled_by_system_user_id int NULL,
    created_by_system_user_id int NULL,
    created_at timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY communication_message_tenant_dedupe_uq (tenant_id, dedupe_key),
    KEY communication_message_unit_status_idx (tenant_id, system_unit_id, status, created_at),
    KEY communication_message_tutor_idx (tenant_id, tutor_id, created_at),
    KEY communication_message_unit_idx (system_unit_id),
    KEY communication_message_tutor_fk_idx (tutor_id),
    KEY communication_message_patient_idx (patient_id),
    KEY communication_message_template_idx (template_id),
    KEY communication_message_manual_sent_by_idx (manual_sent_by_system_user_id),
    KEY communication_message_cancelled_by_idx (cancelled_by_system_user_id),
    KEY communication_message_created_by_idx (created_by_system_user_id),
    CONSTRAINT communication_message_tenant_fk FOREIGN KEY (tenant_id) REFERENCES tenant (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT communication_message_unit_fk FOREIGN KEY (system_unit_id) REFERENCES system_unit (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT communication_message_tutor_fk FOREIGN KEY (tutor_id) REFERENCES tutor (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT communication_message_patient_fk FOREIGN KEY (patient_id) REFERENCES patient (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT communication_message_template_fk FOREIGN KEY (template_id) REFERENCES message_template (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT communication_message_manual_sent_by_fk FOREIGN KEY (manual_sent_by_system_user_id) REFERENCES system_users (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT communication_message_cancelled_by_fk FOREIGN KEY (cancelled_by_system_user_id) REFERENCES system_users (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT communication_message_created_by_fk FOREIGN KEY (created_by_system_user_id) REFERENCES system_users (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT communication_message_status_ck CHECK (status IN ('queued', 'sent', 'failed', 'manual', 'cancelled')),
    CONSTRAINT communication_message_channel_ck CHECK (channel IN ('email', 'whatsapp')),
    CONSTRAINT communication_message_origin_ck CHECK (origin IN ('automation', 'manual')),
    CONSTRAINT communication_message_purpose_ck CHECK (purpose IN ('appointment_confirmation', 'vaccine_due', 'return_reminder', 'receivable_open', 'document_ready', 'custom')),
    CONSTRAINT communication_message_source_ck CHECK (source_type IS NULL OR source_type IN ('appointment', 'vaccination', 'receivable')),
    CONSTRAINT communication_message_legal_basis_ck CHECK (legal_basis IN ('legitimate_interest', 'consent')),
    CONSTRAINT communication_message_sent_ck CHECK ((status IN ('sent', 'manual') AND sent_at IS NOT NULL) OR (status IN ('queued', 'failed', 'cancelled') AND sent_at IS NULL)),
    CONSTRAINT communication_message_manual_ck CHECK ((status = 'manual' AND channel = 'whatsapp' AND manual_sent_by_system_user_id IS NOT NULL) OR (status <> 'manual' AND manual_sent_by_system_user_id IS NULL)),
    CONSTRAINT communication_message_failed_ck CHECK ((status = 'failed' AND failed_at IS NOT NULL AND last_error_code IS NOT NULL) OR (status <> 'failed' AND failed_at IS NULL)),
    CONSTRAINT communication_message_cancelled_ck CHECK ((status = 'cancelled' AND cancelled_at IS NOT NULL) OR (status <> 'cancelled' AND cancelled_at IS NULL)),
    CONSTRAINT communication_message_subject_ck CHECK (channel <> 'email' OR subject IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE appointment_followup (
    id bigint unsigned NOT NULL AUTO_INCREMENT,
    tenant_id bigint unsigned NOT NULL,
    appointment_id bigint unsigned NOT NULL,
    encounter_id bigint unsigned NOT NULL,
    created_by_system_user_id int NOT NULL,
    created_at timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY appointment_followup_appointment_uq (appointment_id),
    KEY appointment_followup_encounter_idx (tenant_id, encounter_id),
    KEY appointment_followup_encounter_fk_idx (encounter_id),
    KEY appointment_followup_created_by_idx (created_by_system_user_id),
    CONSTRAINT appointment_followup_tenant_fk FOREIGN KEY (tenant_id) REFERENCES tenant (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT appointment_followup_appointment_fk FOREIGN KEY (appointment_id) REFERENCES appointment (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT appointment_followup_encounter_fk FOREIGN KEY (encounter_id) REFERENCES encounter (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT appointment_followup_created_by_fk FOREIGN KEY (created_by_system_user_id) REFERENCES system_users (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

ALTER TABLE vaccination
    ADD KEY vaccination_tenant_next_dose_idx (tenant_id, next_dose_at);

ALTER TABLE receivable
    ADD KEY receivable_tenant_status_idx (tenant_id, status);

INSERT INTO schema_migrations (version, checksum)
VALUES ('20261006_0012_phase7a_communication',
        '0000000000000000000000000000000000000000000000000000000000000000' /* 64-char PLACEHOLDER: replace before applying */);
