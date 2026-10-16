-- Central Vet Pro - Phase 8B copilot (conversational assistant with read-only MCP-style
-- tools over the clinic's own records: clinical copilot anchored on a patient and a
-- management copilot; every tool call is authorized per user, tenant and unit and audited)
-- Migration: 20261015_0015_phase8b_copilot
-- Status: PREPARED ONLY. Do not execute without a fresh backup and explicit SQL approval.
-- Target: MySQL 8.0.x, database centralvet, after 20261007_0014_phase8a_ai_gateway
-- has been applied (tenant, system_unit, system_users, patient, ai_interaction and
-- schema_migrations already exist).
--
-- Effects:
--   * creates ai_conversation (one copilot conversation: tenant, unit, owner user, family
--     clinical/management, optional anchor patient for clinical conversations only, a
--     minimized title of up to 80 characters, created/updated timestamps);
--   * creates ai_conversation_message (the turns of a conversation: role user/assistant,
--     content, the "Consultei: ..." tool summary and a failed flag that only assistant
--     turns may set; deleted together with its conversation);
--   * creates mcp_tool_call (audit of each tool call: tenant, unit, user, conversation id
--     without FK so the audit survives conversation retention, correlation id, tool name,
--     minimized input, decision allowed/denied with the deny reason, row count, truncated
--     flag and duration);
--   * widens the 6 CHECKs of ai_interaction (each one as DROP CHECK followed by ADD
--     CONSTRAINT in separate statements, so the MySQL 5.7 preparer can drop and recreate
--     its triggers): purpose + 'copilot_clinical'/'copilot_management'; subject_type +
--     'ai_conversation'; status + 'answered'; failed_ck accepts an answered row without
--     error_code and without suggestion_text; decided_ck and final_ck treat 'answered'
--     like 'pending_review' (no decider, no decision time, no final_text);
--   * adds FKs from ai_conversation to tenant/system_unit/system_users (RESTRICT) and to
--     patient (anchor_patient_id, ON DELETE SET NULL); from ai_conversation_message to
--     tenant (RESTRICT) and ai_conversation (ON DELETE CASCADE); from mcp_tool_call to
--     tenant/system_unit/system_users (RESTRICT);
--   * creates 3 tables, 6 new CHECK constraints (ai_conversation.family, .anchor;
--     ai_conversation_message.role, .failed; mcp_tool_call.decision, .truncated), widens
--     6 existing CHECK constraints of ai_interaction and adds 9 foreign keys.
--
-- No data is seeded by this migration: the 3 new tables start empty. Existing
-- ai_interaction rows are preserved; every value they hold is still accepted by the
-- widened CHECKs (the new values are only additions).
--
-- Risk: medium. Between the DROP CHECK and the ADD CONSTRAINT of each widened CHECK,
-- ai_interaction is briefly unconstrained; run the migration in a maintenance window with
-- AI calls stopped. ai_conversation_message.content holds the user's question and the
-- assistant answer (clinical text from the clinic's records) and must never be copied to
-- logs, audit metadata, queue payloads or URLs; conversations are purged after 90 days by
-- the Application retention command (messages go by cascade). mcp_tool_call stores only
-- the minimized tool input and has no FK to ai_conversation on purpose, so the audit
-- trail outlives the purged conversation.
--
-- Rollback: restore the backup taken before applying, or write a numbered reverse
-- migration (see docs/runbooks/migration-rollback.md). Narrowing the ai_interaction
-- CHECKs back fails once rows with the copilot values exist; delete or migrate those
-- rows first. Do not drop mcp_tool_call by hand once the copilot has been used: it is
-- the tool access audit.
--
-- MySQL DDL commits implicitly. On failure, stop and inspect; do not rerun blindly.
-- The literal migration checksum below MUST be replaced with the approved artifact's
-- SHA-256 by the migration executor before this file is applied.

CREATE TABLE ai_conversation (
    id bigint unsigned NOT NULL AUTO_INCREMENT,
    tenant_id bigint unsigned NOT NULL,
    system_unit_id int NOT NULL,
    system_user_id int NOT NULL,
    family varchar(20) NOT NULL,
    anchor_patient_id bigint unsigned NULL,
    title varchar(80) NOT NULL,
    created_at timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    KEY ai_conversation_owner_idx (tenant_id, system_user_id, updated_at),
    KEY ai_conversation_updated_idx (updated_at),
    KEY ai_conversation_unit_idx (system_unit_id),
    KEY ai_conversation_user_idx (system_user_id),
    KEY ai_conversation_anchor_patient_idx (anchor_patient_id),
    CONSTRAINT ai_conversation_tenant_fk FOREIGN KEY (tenant_id) REFERENCES tenant (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT ai_conversation_unit_fk FOREIGN KEY (system_unit_id) REFERENCES system_unit (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT ai_conversation_user_fk FOREIGN KEY (system_user_id) REFERENCES system_users (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT ai_conversation_anchor_patient_fk FOREIGN KEY (anchor_patient_id) REFERENCES patient (id)
        ON UPDATE RESTRICT ON DELETE SET NULL,
    CONSTRAINT ai_conversation_family_ck CHECK (family IN ('clinical', 'management')),
    CONSTRAINT ai_conversation_anchor_ck CHECK (family = 'clinical' OR anchor_patient_id IS NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE ai_conversation_message (
    id bigint unsigned NOT NULL AUTO_INCREMENT,
    tenant_id bigint unsigned NOT NULL,
    conversation_id bigint unsigned NOT NULL,
    role varchar(20) NOT NULL,
    content mediumtext NOT NULL,
    tool_summary varchar(2000) NULL,
    failed tinyint(1) NOT NULL DEFAULT 0,
    created_at timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    KEY ai_conversation_message_conversation_idx (conversation_id, id),
    KEY ai_conversation_message_tenant_idx (tenant_id),
    CONSTRAINT ai_conversation_message_tenant_fk FOREIGN KEY (tenant_id) REFERENCES tenant (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT ai_conversation_message_conversation_fk FOREIGN KEY (conversation_id) REFERENCES ai_conversation (id)
        ON UPDATE RESTRICT ON DELETE CASCADE,
    CONSTRAINT ai_conversation_message_role_ck CHECK (role IN ('user', 'assistant')),
    CONSTRAINT ai_conversation_message_failed_ck CHECK (failed IN (0, 1) AND (role = 'assistant' OR failed = 0))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE mcp_tool_call (
    id bigint unsigned NOT NULL AUTO_INCREMENT,
    tenant_id bigint unsigned NOT NULL,
    system_unit_id int NOT NULL,
    system_user_id int NOT NULL,
    conversation_id bigint unsigned NULL,
    correlation_id varchar(64) NOT NULL,
    tool_name varchar(60) NOT NULL,
    input_minimized text NOT NULL,
    decision varchar(10) NOT NULL,
    deny_reason varchar(30) NULL,
    row_count int unsigned NOT NULL DEFAULT 0,
    truncated tinyint(1) NOT NULL DEFAULT 0,
    duration_ms int unsigned NOT NULL DEFAULT 0,
    created_at timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    KEY mcp_tool_call_tenant_created_idx (tenant_id, created_at),
    KEY mcp_tool_call_user_created_idx (system_user_id, created_at),
    KEY mcp_tool_call_tool_created_idx (tool_name, created_at),
    KEY mcp_tool_call_correlation_idx (correlation_id),
    KEY mcp_tool_call_unit_idx (system_unit_id),
    CONSTRAINT mcp_tool_call_tenant_fk FOREIGN KEY (tenant_id) REFERENCES tenant (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT mcp_tool_call_unit_fk FOREIGN KEY (system_unit_id) REFERENCES system_unit (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT mcp_tool_call_user_fk FOREIGN KEY (system_user_id) REFERENCES system_users (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT mcp_tool_call_decision_ck CHECK ((decision = 'allowed' AND deny_reason IS NULL) OR (decision = 'denied' AND deny_reason IS NOT NULL)),
    CONSTRAINT mcp_tool_call_truncated_ck CHECK (truncated IN (0, 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

ALTER TABLE ai_interaction DROP CHECK ai_interaction_purpose_ck;

ALTER TABLE ai_interaction
    ADD CONSTRAINT ai_interaction_purpose_ck CHECK (purpose IN ('patient_summary', 'anamnesis_organize', 'exam_summary', 'copilot_clinical', 'copilot_management'));

ALTER TABLE ai_interaction DROP CHECK ai_interaction_subject_ck;

ALTER TABLE ai_interaction
    ADD CONSTRAINT ai_interaction_subject_ck CHECK (subject_type IN ('encounter', 'exam_result', 'ai_conversation'));

ALTER TABLE ai_interaction DROP CHECK ai_interaction_status_ck;

ALTER TABLE ai_interaction
    ADD CONSTRAINT ai_interaction_status_ck CHECK (status IN ('pending_review', 'accepted', 'edited', 'discarded', 'failed', 'answered'));

ALTER TABLE ai_interaction DROP CHECK ai_interaction_failed_ck;

ALTER TABLE ai_interaction
    ADD CONSTRAINT ai_interaction_failed_ck CHECK ((status = 'failed' AND error_code IS NOT NULL AND suggestion_text IS NULL) OR (status = 'answered' AND error_code IS NULL AND suggestion_text IS NULL) OR (status IN ('pending_review', 'accepted', 'edited', 'discarded') AND error_code IS NULL AND suggestion_text IS NOT NULL));

ALTER TABLE ai_interaction DROP CHECK ai_interaction_decided_ck;

ALTER TABLE ai_interaction
    ADD CONSTRAINT ai_interaction_decided_ck CHECK ((status IN ('accepted', 'edited', 'discarded') AND decided_at IS NOT NULL AND decided_by_system_user_id IS NOT NULL) OR (status IN ('pending_review', 'failed', 'answered') AND decided_at IS NULL AND decided_by_system_user_id IS NULL));

ALTER TABLE ai_interaction DROP CHECK ai_interaction_final_ck;

ALTER TABLE ai_interaction
    ADD CONSTRAINT ai_interaction_final_ck CHECK ((status IN ('accepted', 'edited') AND final_text IS NOT NULL) OR (status IN ('pending_review', 'failed', 'discarded', 'answered') AND final_text IS NULL));

INSERT INTO schema_migrations (version, checksum)
VALUES ('20261015_0015_phase8b_copilot',
        '0000000000000000000000000000000000000000000000000000000000000000' /* 64-char PLACEHOLDER: replace before applying */);
