-- Central Vet Pro - Round 2: registry fields (product, patient), prescription validity and
-- templates, payment method on the ledger, bank accounts and encounter pause
-- Migration: 20260930_0007_rodada2_cadastros_financeiro
-- Status: PREPARED ONLY. Do not execute without a fresh backup and explicit SQL approval.
-- Target: MySQL 8.0.x (>= 8.0.16, CHECK constraints enforced), database centralvet, after
-- 20260925_0006_phase5_financial has been applied (tenant, system_unit, system_users,
-- product, patient, prescription, encounter, financial_entry and schema_migrations
-- already exist).
--
-- Effects:
--   * product: adds sale_price_cents (int unsigned NULL, after unit_cost_cents) and code
--     (varchar(60) NULL) plus UNIQUE KEY product_tenant_code_uq (tenant_id, code); existing
--     rows get NULL in both columns, and multiple NULL codes per tenant are allowed by the
--     unique key;
--   * patient: adds allergies (text NULL), photo_object_key (varchar(512) NULL, key of the
--     photo in the existing S3-compatible storage, no FK to stored_object) and
--     photo_content_type (varchar(100) NULL); existing rows get NULL;
--   * prescription: adds valid_until (date NULL); existing rows get NULL;
--   * creates prescription_template (per-tenant named prescription template with optional
--     orientation text, authored by a system user, unique name per tenant);
--   * creates prescription_template_item (ordered medication lines of a template, same
--     column shapes as prescription_item, deleted together with the template via
--     ON DELETE CASCADE);
--   * financial_entry: adds payment_method (varchar(20) NULL) with CHECK
--     financial_entry_payment_method_ck (NULL or one of cash/debit_card/credit_card/pix/
--     bank_transfer), then backfills it from category for rows with
--     reference_type = 'payment' whose category is a valid payment method; manual and
--     payable entries keep NULL; category itself is not changed;
--   * creates bank_account (per-tenant, per-unit bank account with a manually informed
--     signed balance_cents and balance_updated_at, active flag, unique name per tenant and
--     unit);
--   * encounter: adds paused_at (timestamp(6) NULL) and paused_seconds (int unsigned NOT
--     NULL DEFAULT 0); existing rows get NULL / 0. No status value is added and the
--     existing encounter status CHECK is left untouched;
--   * creates 3 tables, adds 9 columns, 1 CHECK constraint, 4 UNIQUE constraints and
--     6 foreign keys (prescription_template -> tenant/system_users;
--     prescription_template_item -> tenant/prescription_template; bank_account ->
--     tenant/system_unit).
--
-- Data: the only DML is the payment_method backfill on financial_entry (payment rows only)
-- and the schema_migrations audit row. No row is removed; row counts of product, patient,
-- prescription, financial_entry and encounter must be the same before and after (checked
-- by the .verify.sql).
--
-- Risk: ALTER TABLE on product, patient, prescription, financial_entry and encounter
-- rebuilds or instantly alters those tables (small tables in this installation; brief
-- metadata lock). ADD UNIQUE KEY product_tenant_code_uq cannot fail on existing data
-- because every existing code is NULL. The CHECK is added before the backfill; the
-- backfill only writes values accepted by it. New PII: patient.allergies (clinical data)
-- and patient.photo_object_key (reference to an animal photo); both follow the existing
-- tenant isolation of patient. bank_account.balance_cents is informed by hand and is not
-- reconciled with financial_entry (documented decision in notes.md).
--
-- Rollback: preferred path is restoring the pre-migration backup (make backup, see
-- docs/runbooks/restore-backup.md and docs/runbooks/migration-rollback.md). If data
-- written after the migration must be kept, prepare a reverse migration 0008 that drops
-- the 3 new tables (prescription_template_item first), the CHECK, the unique key and the
-- 9 columns, with its own backup and explicit SQL approval.
--
-- MySQL DDL commits implicitly. On failure, stop and inspect information_schema; do not
-- rerun blindly.
-- The literal migration checksum below MUST be replaced with the approved artifact's
-- SHA-256 by the migration executor before this file is applied.

ALTER TABLE product
    ADD COLUMN sale_price_cents int unsigned NULL AFTER unit_cost_cents,
    ADD COLUMN code varchar(60) NULL AFTER name,
    ADD UNIQUE KEY product_tenant_code_uq (tenant_id, code);

ALTER TABLE patient
    ADD COLUMN allergies text NULL AFTER notes,
    ADD COLUMN photo_object_key varchar(512) NULL AFTER allergies,
    ADD COLUMN photo_content_type varchar(100) NULL AFTER photo_object_key;

ALTER TABLE prescription
    ADD COLUMN valid_until date NULL AFTER orientation_text;

CREATE TABLE prescription_template (
    id bigint unsigned NOT NULL AUTO_INCREMENT,
    tenant_id bigint unsigned NOT NULL,
    name varchar(190) NOT NULL,
    orientation_text text NULL,
    created_by_system_user_id int NOT NULL,
    created_at timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY prescription_template_tenant_name_uq (tenant_id, name),
    KEY prescription_template_tenant_idx (tenant_id),
    KEY prescription_template_created_by_idx (created_by_system_user_id),
    CONSTRAINT prescription_template_tenant_fk FOREIGN KEY (tenant_id) REFERENCES tenant (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT prescription_template_created_by_fk FOREIGN KEY (created_by_system_user_id) REFERENCES system_users (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE prescription_template_item (
    id bigint unsigned NOT NULL AUTO_INCREMENT,
    tenant_id bigint unsigned NOT NULL,
    template_id bigint unsigned NOT NULL,
    position smallint unsigned NOT NULL,
    medication_name varchar(190) NOT NULL,
    dose varchar(40) NOT NULL,
    dose_unit varchar(20) NOT NULL,
    route varchar(40) NOT NULL,
    frequency varchar(60) NOT NULL,
    duration varchar(60) NOT NULL,
    created_at timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    KEY prescription_template_item_tenant_idx (tenant_id),
    KEY prescription_template_item_template_idx (template_id, position),
    CONSTRAINT prescription_template_item_tenant_fk FOREIGN KEY (tenant_id) REFERENCES tenant (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT prescription_template_item_template_fk FOREIGN KEY (template_id) REFERENCES prescription_template (id)
        ON UPDATE RESTRICT ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

ALTER TABLE financial_entry
    ADD COLUMN payment_method varchar(20) NULL AFTER category,
    ADD CONSTRAINT financial_entry_payment_method_ck
        CHECK (payment_method IS NULL OR payment_method IN ('cash', 'debit_card', 'credit_card', 'pix', 'bank_transfer'));

UPDATE financial_entry
SET payment_method = category
WHERE reference_type = 'payment'
  AND category IN ('cash', 'debit_card', 'credit_card', 'pix', 'bank_transfer');

CREATE TABLE bank_account (
    id bigint unsigned NOT NULL AUTO_INCREMENT,
    tenant_id bigint unsigned NOT NULL,
    system_unit_id int NOT NULL,
    name varchar(120) NOT NULL,
    bank_name varchar(120) NULL,
    balance_cents bigint NOT NULL DEFAULT 0,
    balance_updated_at timestamp(6) NULL,
    active tinyint(1) NOT NULL DEFAULT 1,
    created_at timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY bank_account_tenant_unit_name_uq (tenant_id, system_unit_id, name),
    KEY bank_account_tenant_idx (tenant_id),
    KEY bank_account_unit_idx (system_unit_id),
    KEY bank_account_tenant_unit_active_idx (tenant_id, system_unit_id, active),
    CONSTRAINT bank_account_tenant_fk FOREIGN KEY (tenant_id) REFERENCES tenant (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT bank_account_unit_fk FOREIGN KEY (system_unit_id) REFERENCES system_unit (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

ALTER TABLE encounter
    ADD COLUMN paused_at timestamp(6) NULL AFTER finished_at,
    ADD COLUMN paused_seconds int unsigned NOT NULL DEFAULT 0 AFTER paused_at;

INSERT INTO schema_migrations (version, checksum)
VALUES ('20260930_0007_rodada2_cadastros_financeiro',
        '0000000000000000000000000000000000000000000000000000000000000000' /* 64-char PLACEHOLDER: replace before applying */);
