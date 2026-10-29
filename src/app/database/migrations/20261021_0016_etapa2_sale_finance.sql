-- Central Vet Pro - Etapa 2 (money and stock): a sale gets its own receivable, the
-- cancellation of a sale is traceable, payments can be reversed, form submissions are
-- idempotent and stock can be returned by a sale cancellation
-- Migration: 20261021_0016_etapa2_sale_finance
-- Status: PREPARED ONLY. Do not execute without a fresh backup and explicit SQL approval.
-- Target: MySQL 8.0.x, database centralvet, after 20261015_0015_phase8b_copilot has been
-- applied (tenant, system_users, encounter_account, sale, receivable, payment,
-- cash_session, stock_movement and schema_migrations already exist).
--
-- Effects:
--   * receivable.encounter_account_id becomes NULL-able (its UNIQUE
--     receivable_encounter_account_uq and its FK receivable_encounter_account_fk stay);
--   * adds receivable.sale_id (NULL-able) with UNIQUE receivable_sale_uq, FK
--     receivable_sale_fk -> sale and CHECK receivable_origin_ck: a receivable comes from
--     exactly one origin, an encounter account or a sale;
--   * adds sale.cancelled_at, sale.cancelled_by_system_user_id (FK sale_cancelled_by_fk
--     -> system_users) and sale.cancellation_reason, with CHECK sale_cancellation_ck: a
--     completed sale has none of the three, a cancelled sale has all three;
--   * creates payment_reversal (the reversal of one payment, recorded in the cash session
--     open at the time of the reversal; at most one per payment) with FKs to tenant/
--     payment/cash_session/system_users and CHECKs payment_reversal_amount_ck and
--     payment_reversal_method_ck (same list as payment_method_ck);
--   * creates submission_key (one row per accepted form submission token of a tenant and
--     scope sale/payment) with UNIQUE submission_key_uq (tenant_id, scope, token), FKs to
--     tenant/system_users and CHECK submission_key_scope_ck;
--   * widens stock_movement_reason_ck with 'sale_cancellation' (DROP CHECK followed by
--     ADD CONSTRAINT in separate statements, so the MySQL 5.7 preparer can drop and
--     recreate its triggers);
--   * in total: 2 new tables, 4 new columns on existing tables (receivable.sale_id,
--     sale.cancelled_at, sale.cancelled_by_system_user_id, sale.cancellation_reason),
--     1 column made NULL-able, 5 new CHECK constraints (receivable_origin_ck,
--     sale_cancellation_ck, payment_reversal_amount_ck, payment_reversal_method_ck,
--     submission_key_scope_ck), 1 widened CHECK (stock_movement_reason_ck), 3 UNIQUE
--     constraints (receivable_sale_uq, payment_reversal_payment_uq, submission_key_uq)
--     and 8 foreign keys (receivable_sale_fk, sale_cancelled_by_fk, 4 on payment_reversal,
--     2 on submission_key).
--
-- No data is seeded by this migration. Existing rows are preserved: every existing
-- receivable has an encounter_account_id and gets sale_id NULL (receivable_origin_ck
-- holds), and every existing sale must be 'completed' (sale_cancellation_ck needs the
-- cancellation columns on a 'cancelled' sale; the Application never cancelled a sale
-- before this migration). The widened stock CHECK accepts every value the previous one
-- accepted.
--
-- Risk: the ALTER TABLE statements on receivable and sale rebuild those tables (run in
-- a maintenance window). Between the DROP CHECK and the ADD CONSTRAINT of
-- stock_movement_reason_ck the table is briefly unconstrained. Reversing a payment into
-- the currently open cash session, the receivable/sale locking and the submission token
-- lifecycle are Application rules. No new PII category is introduced:
-- cancellation_reason and payment_reversal.reason are operational free text.
--
-- Rollback: restore the backup taken before applying, or write a numbered reverse
-- migration (see docs/runbooks/migration-rollback.md). Do not drop the new tables or
-- columns by hand once sale receivables, reversals or submission keys exist.
--
-- MySQL DDL commits implicitly. On failure, stop and inspect; do not rerun blindly.
-- The literal migration checksum below MUST be replaced with the approved artifact's
-- SHA-256 by the migration executor before this file is applied.

ALTER TABLE receivable
    MODIFY encounter_account_id bigint unsigned NULL;

ALTER TABLE receivable
    ADD COLUMN sale_id bigint unsigned NULL AFTER encounter_account_id,
    ADD UNIQUE KEY receivable_sale_uq (sale_id),
    ADD CONSTRAINT receivable_sale_fk FOREIGN KEY (sale_id) REFERENCES sale (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    ADD CONSTRAINT receivable_origin_ck CHECK ((encounter_account_id IS NOT NULL AND sale_id IS NULL) OR (encounter_account_id IS NULL AND sale_id IS NOT NULL));

ALTER TABLE sale
    ADD COLUMN cancelled_at timestamp(6) NULL AFTER sold_at,
    ADD COLUMN cancelled_by_system_user_id int NULL AFTER cancelled_at,
    ADD COLUMN cancellation_reason varchar(500) NULL AFTER cancelled_by_system_user_id,
    ADD KEY sale_cancelled_by_idx (cancelled_by_system_user_id),
    ADD CONSTRAINT sale_cancelled_by_fk FOREIGN KEY (cancelled_by_system_user_id) REFERENCES system_users (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    ADD CONSTRAINT sale_cancellation_ck CHECK ((status = 'completed' AND cancelled_at IS NULL AND cancelled_by_system_user_id IS NULL AND cancellation_reason IS NULL) OR (status = 'cancelled' AND cancelled_at IS NOT NULL AND cancelled_by_system_user_id IS NOT NULL AND cancellation_reason IS NOT NULL));

CREATE TABLE payment_reversal (
    id bigint unsigned NOT NULL AUTO_INCREMENT,
    tenant_id bigint unsigned NOT NULL,
    payment_id bigint unsigned NOT NULL,
    cash_session_id bigint unsigned NOT NULL,
    payment_method varchar(20) NOT NULL,
    amount_cents int unsigned NOT NULL,
    reason varchar(500) NOT NULL,
    system_user_id int NOT NULL,
    reversed_at timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    created_at timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY payment_reversal_payment_uq (payment_id),
    KEY payment_reversal_tenant_idx (tenant_id),
    KEY payment_reversal_cash_session_idx (cash_session_id),
    KEY payment_reversal_system_user_idx (system_user_id),
    CONSTRAINT payment_reversal_tenant_fk FOREIGN KEY (tenant_id) REFERENCES tenant (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT payment_reversal_payment_fk FOREIGN KEY (payment_id) REFERENCES payment (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT payment_reversal_cash_session_fk FOREIGN KEY (cash_session_id) REFERENCES cash_session (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT payment_reversal_system_user_fk FOREIGN KEY (system_user_id) REFERENCES system_users (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT payment_reversal_method_ck CHECK (payment_method IN ('cash', 'debit_card', 'credit_card', 'pix', 'bank_transfer')),
    CONSTRAINT payment_reversal_amount_ck CHECK (amount_cents >= 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE submission_key (
    id bigint unsigned NOT NULL AUTO_INCREMENT,
    tenant_id bigint unsigned NOT NULL,
    scope varchar(20) NOT NULL,
    token char(32) NOT NULL,
    reference_id bigint unsigned NULL,
    system_user_id int NOT NULL,
    created_at timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY submission_key_uq (tenant_id, scope, token),
    KEY submission_key_system_user_idx (system_user_id),
    CONSTRAINT submission_key_tenant_fk FOREIGN KEY (tenant_id) REFERENCES tenant (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT submission_key_system_user_fk FOREIGN KEY (system_user_id) REFERENCES system_users (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT submission_key_scope_ck CHECK (scope IN ('sale', 'payment'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

ALTER TABLE stock_movement DROP CHECK stock_movement_reason_ck;

ALTER TABLE stock_movement
    ADD CONSTRAINT stock_movement_reason_ck CHECK (reason IN ('purchase_entry', 'procedure_consumption', 'sale_consumption', 'manual_adjustment', 'hospitalization_consumption', 'surgery_consumption', 'sale_cancellation'));

INSERT INTO schema_migrations (version, checksum)
VALUES ('20261021_0016_etapa2_sale_finance',
        '0000000000000000000000000000000000000000000000000000000000000000' /* 64-char PLACEHOLDER: replace before applying */);
