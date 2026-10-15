-- Central Vet Pro - Phase 8A AI gateway (provider-independent AI gateway with the
-- contextual assistant: patient summary, anamnesis organization and exam summary, always
-- reviewed by a human before anything is persisted; AI is off by default per tenant)
-- Migration: 20261007_0014_phase8a_ai_gateway
-- Status: PREPARED ONLY. Do not execute without a fresh backup and explicit SQL approval.
-- Target: MySQL 8.0.x, database centralvet, after 20261006_0013_phase7b_documents
-- has been applied (tenant, system_unit, system_users, encounter, exam_result and
-- schema_migrations already exist).
--
-- Effects:
--   * creates feature_flag (generic per-tenant feature switch: flag_key and enabled 0/1,
--     plus the user who last changed it; a missing row means the feature is off, so the
--     AI assistant flag 'ai_assistant' starts off for every tenant);
--   * creates ai_tenant_setting (one row per tenant: accepted AI terms version, when and
--     by whom they were accepted, and the monthly token quota, default 300000);
--   * creates ai_interaction (one AI call: tenant, unit, user, purpose
--     patient_summary/anamnesis_organize/exam_summary, polymorphic subject
--     encounter/exam_result with its id, prompt version, provider, model returned by the
--     provider, status pending_review/accepted/edited/discarded/failed, SHA-256 of the
--     input, input/output/cache tokens, cost in micro-USD, latency, stop reason, provider
--     request id, error code, suggestion text, final reviewed text, the reviewing user and
--     decision timestamp, and the correlation id);
--   * adds FKs from feature_flag to tenant/system_users (updated_by); from
--     ai_tenant_setting to tenant/system_users (terms_accepted_by, updated_by); from
--     ai_interaction to tenant/system_unit/system_users (system_user_id,
--     decided_by_system_user_id);
--   * creates 3 tables, 8 CHECK constraints (feature_flag.enabled;
--     ai_tenant_setting.terms; ai_interaction.purpose, .subject, .status, .failed,
--     .decided, .final), 2 uniqueness keys (feature_flag_tenant_key_uq and the
--     ai_tenant_setting primary key on tenant_id) and 8 foreign keys.
--
-- No data is seeded by this migration: the 3 new tables start empty, so AI stays off
-- for every existing tenant until an administrator enables it and accepts the terms.
-- Existing encounter, exam_result, patient and audit_log rows are preserved (no existing
-- table is changed).
--
-- Risk: low; only new tables are created. ai_interaction has no FK to its subject
-- (polymorphic encounter or exam_result; tenant and unit checks are Application rules)
-- and never stores the input sent to the provider, only its SHA-256. suggestion_text and
-- final_text hold clinical text already present in the clinic's records and must never
-- be copied to logs, audit metadata, queue payloads or URLs. The monthly quota is
-- enforced by the Application (sum of tokens of the month read before each call).
-- Retention and purge of suggestion_text are not defined by this migration.
--
-- Rollback: restore the backup taken before applying, or write a numbered reverse
-- migration (see docs/runbooks/migration-rollback.md). Do not drop the new tables by hand
-- once AI has been used: ai_interaction is the usage, cost and review history.
--
-- MySQL DDL commits implicitly. On failure, stop and inspect; do not rerun blindly.
-- The literal migration checksum below MUST be replaced with the approved artifact's
-- SHA-256 by the migration executor before this file is applied.

CREATE TABLE feature_flag (
    id bigint unsigned NOT NULL AUTO_INCREMENT,
    tenant_id bigint unsigned NOT NULL,
    flag_key varchar(64) NOT NULL,
    enabled tinyint(1) NOT NULL DEFAULT 0,
    updated_by_system_user_id int NULL,
    created_at timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY feature_flag_tenant_key_uq (tenant_id, flag_key),
    KEY feature_flag_updated_by_idx (updated_by_system_user_id),
    CONSTRAINT feature_flag_tenant_fk FOREIGN KEY (tenant_id) REFERENCES tenant (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT feature_flag_updated_by_fk FOREIGN KEY (updated_by_system_user_id) REFERENCES system_users (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT feature_flag_enabled_ck CHECK (enabled IN (0, 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE ai_tenant_setting (
    tenant_id bigint unsigned NOT NULL,
    terms_version varchar(20) NULL,
    terms_accepted_at timestamp(6) NULL DEFAULT NULL,
    terms_accepted_by_system_user_id int NULL,
    monthly_token_quota int unsigned NOT NULL DEFAULT 300000,
    updated_by_system_user_id int NULL,
    created_at timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (tenant_id),
    KEY ai_tenant_setting_terms_by_idx (terms_accepted_by_system_user_id),
    KEY ai_tenant_setting_updated_by_idx (updated_by_system_user_id),
    CONSTRAINT ai_tenant_setting_tenant_fk FOREIGN KEY (tenant_id) REFERENCES tenant (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT ai_tenant_setting_terms_by_fk FOREIGN KEY (terms_accepted_by_system_user_id) REFERENCES system_users (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT ai_tenant_setting_updated_by_fk FOREIGN KEY (updated_by_system_user_id) REFERENCES system_users (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT ai_tenant_setting_terms_ck CHECK ((terms_version IS NULL AND terms_accepted_at IS NULL AND terms_accepted_by_system_user_id IS NULL) OR (terms_version IS NOT NULL AND terms_accepted_at IS NOT NULL AND terms_accepted_by_system_user_id IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE ai_interaction (
    id bigint unsigned NOT NULL AUTO_INCREMENT,
    tenant_id bigint unsigned NOT NULL,
    system_unit_id int NOT NULL,
    system_user_id int NOT NULL,
    purpose varchar(40) NOT NULL,
    subject_type varchar(30) NOT NULL,
    subject_id bigint unsigned NOT NULL,
    prompt_version varchar(40) NOT NULL,
    provider varchar(30) NOT NULL,
    model varchar(80) NULL,
    status varchar(20) NOT NULL,
    input_sha256 char(64) NOT NULL,
    input_tokens int unsigned NOT NULL DEFAULT 0,
    output_tokens int unsigned NOT NULL DEFAULT 0,
    cache_read_tokens int unsigned NOT NULL DEFAULT 0,
    cache_write_tokens int unsigned NOT NULL DEFAULT 0,
    cost_micro_usd bigint unsigned NULL,
    latency_ms int unsigned NULL,
    stop_reason varchar(30) NULL,
    provider_request_id varchar(120) NULL,
    error_code varchar(60) NULL,
    suggestion_text mediumtext NULL,
    final_text mediumtext NULL,
    decided_by_system_user_id int NULL,
    decided_at timestamp(6) NULL DEFAULT NULL,
    correlation_id varchar(64) NOT NULL,
    created_at timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    KEY ai_interaction_tenant_created_idx (tenant_id, created_at),
    KEY ai_interaction_tenant_user_idx (tenant_id, system_user_id, created_at),
    KEY ai_interaction_subject_idx (tenant_id, subject_type, subject_id, purpose, created_at),
    KEY ai_interaction_tenant_purpose_idx (tenant_id, purpose, created_at),
    KEY ai_interaction_unit_idx (system_unit_id),
    KEY ai_interaction_user_idx (system_user_id),
    KEY ai_interaction_decided_by_idx (decided_by_system_user_id),
    CONSTRAINT ai_interaction_tenant_fk FOREIGN KEY (tenant_id) REFERENCES tenant (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT ai_interaction_unit_fk FOREIGN KEY (system_unit_id) REFERENCES system_unit (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT ai_interaction_user_fk FOREIGN KEY (system_user_id) REFERENCES system_users (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT ai_interaction_decided_by_fk FOREIGN KEY (decided_by_system_user_id) REFERENCES system_users (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT ai_interaction_purpose_ck CHECK (purpose IN ('patient_summary', 'anamnesis_organize', 'exam_summary')),
    CONSTRAINT ai_interaction_subject_ck CHECK (subject_type IN ('encounter', 'exam_result')),
    CONSTRAINT ai_interaction_status_ck CHECK (status IN ('pending_review', 'accepted', 'edited', 'discarded', 'failed')),
    CONSTRAINT ai_interaction_failed_ck CHECK ((status = 'failed' AND error_code IS NOT NULL AND suggestion_text IS NULL) OR (status <> 'failed' AND error_code IS NULL AND suggestion_text IS NOT NULL)),
    CONSTRAINT ai_interaction_decided_ck CHECK ((status IN ('accepted', 'edited', 'discarded') AND decided_at IS NOT NULL AND decided_by_system_user_id IS NOT NULL) OR (status IN ('pending_review', 'failed') AND decided_at IS NULL AND decided_by_system_user_id IS NULL)),
    CONSTRAINT ai_interaction_final_ck CHECK ((status IN ('accepted', 'edited') AND final_text IS NOT NULL) OR (status IN ('pending_review', 'failed', 'discarded') AND final_text IS NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT INTO schema_migrations (version, checksum)
VALUES ('20261007_0014_phase8a_ai_gateway',
        '0000000000000000000000000000000000000000000000000000000000000000' /* 64-char PLACEHOLDER: replace before applying */);
