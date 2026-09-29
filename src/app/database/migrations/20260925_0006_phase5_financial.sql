-- Central Vet Pro - Phase 5 integrated financial core (account, receivable, cash session,
-- payment, payable, financial entry)
-- Migration: 20260925_0006_phase5_financial
-- Status: PREPARED ONLY. Do not execute without a fresh backup and explicit SQL approval.
-- Target: MySQL 8.0.x, database centralvet, after 20260924_0005_phase4_procedure_stock_sale
-- has been applied (tenant, system_unit, system_users, tutor, patient, encounter,
-- procedure_execution and schema_migrations already exist).
--
-- Effects:
--   * creates encounter_account (the running bill for a single encounter: one row per
--     encounter, holding subtotal/discount/total in cents and an optional authorized
--     discount, with status open/closed/cancelled);
--   * creates encounter_account_item (each billable line added to an account, generically
--     sourced from a procedure_execution, an exam_request or a manual entry — source_id
--     intentionally has NO database foreign key, same simplification already accepted for
--     sale_item.item_reference_id in Phase 4, see Risk below);
--   * creates receivable (the amount owed by the tutor for a closed account, tracking
--     paid_cents against total_cents with status open/partially_paid/paid/cancelled);
--   * creates cash_session (a till session opened/closed by a system user at a system
--     unit, with opening/closing balances in cents);
--   * creates payment (a single payment against a receivable, tied to the cash session it
--     was taken in and the system user who took it, with a typed payment method);
--   * creates payable (an accounts-payable entry for a system unit — a bill to pay, with
--     an optional due date and status open/paid/cancelled);
--   * creates financial_entry (an immutable income/expense ledger row for a system unit,
--     with an optional generic reference to the document that caused it, same
--     reference_type/reference_id simplification as stock_movement in Phase 4);
--   * adds FKs from encounter_account to tenant/encounter/patient/tutor/system_unit/
--     system_users; from encounter_account_item to tenant/encounter_account; from
--     receivable to tenant/encounter_account/tutor; from cash_session to tenant/
--     system_unit/system_users (opened_by and closed_by); from payment to tenant/
--     receivable/cash_session/system_users; from payable to tenant/system_unit/
--     system_users; from financial_entry to tenant/system_unit/system_users;
--   * creates 7 tables, 11 CHECK constraints (encounter_account.status,
--     encounter_account.discount, encounter_account.total, encounter_account_item.
--     source_type, receivable.status, receivable.paid, cash_session.status,
--     payment.payment_method, payment.amount, payable.status, financial_entry.
--     entry_type), 3 UNIQUE constraints and 25 foreign keys.
--
-- No data is seeded by this migration: all 7 tables start empty for every existing
-- tenant.
--
-- Risk: encounter_account_item.source_id and financial_entry.reference_id intentionally
-- have NO database foreign key: they point to different tables depending on
-- source_type/reference_type and MySQL cannot express a polymorphic FK; referential
-- integrity for those columns is the Application service's responsibility (documented as
-- a simplification in notes.md, same pattern already accepted for stock_movement and
-- sale_item in Phase 4). encounter_account keeps subtotal/discount/total consistent via
-- CHECK constraints, but nothing in this schema keeps encounter_account.total_cents in
-- sync with the sum of encounter_account_item.amount_cents, or receivable.paid_cents in
-- sync with the sum of payment.amount_cents against it — both are the Application
-- service's responsibility, enforced by validating before writing and undoing partial
-- writes on failure (same discipline as SaleService in Phase 4). No new PII is
-- introduced: these tables reference encounter/patient/tutor/system_users, which already
-- carry whatever PII they carry.
--
-- MySQL DDL commits implicitly. On failure, stop and inspect; do not rerun blindly.
-- The literal migration checksum below MUST be replaced with the approved artifact's
-- SHA-256 by the migration executor before this file is applied.

CREATE TABLE encounter_account (
    id bigint unsigned NOT NULL AUTO_INCREMENT,
    tenant_id bigint unsigned NOT NULL,
    encounter_id bigint unsigned NOT NULL,
    patient_id bigint unsigned NOT NULL,
    tutor_id bigint unsigned NOT NULL,
    system_unit_id int NOT NULL,
    status varchar(20) NOT NULL DEFAULT 'open',
    subtotal_cents int unsigned NOT NULL,
    discount_cents int unsigned NOT NULL DEFAULT 0,
    discount_authorized_by_system_user_id int NULL,
    total_cents int unsigned NOT NULL,
    closed_at timestamp(6) NULL,
    created_at timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY encounter_account_encounter_uq (encounter_id),
    KEY encounter_account_tenant_idx (tenant_id),
    KEY encounter_account_patient_idx (patient_id),
    KEY encounter_account_tutor_idx (tutor_id),
    KEY encounter_account_unit_idx (system_unit_id),
    KEY encounter_account_discount_auth_idx (discount_authorized_by_system_user_id),
    KEY encounter_account_tenant_status_idx (tenant_id, status),
    CONSTRAINT encounter_account_tenant_fk FOREIGN KEY (tenant_id) REFERENCES tenant (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT encounter_account_encounter_fk FOREIGN KEY (encounter_id) REFERENCES encounter (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT encounter_account_patient_fk FOREIGN KEY (patient_id) REFERENCES patient (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT encounter_account_tutor_fk FOREIGN KEY (tutor_id) REFERENCES tutor (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT encounter_account_unit_fk FOREIGN KEY (system_unit_id) REFERENCES system_unit (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT encounter_account_discount_auth_fk FOREIGN KEY (discount_authorized_by_system_user_id) REFERENCES system_users (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT encounter_account_status_ck CHECK (status IN ('open', 'closed', 'cancelled')),
    CONSTRAINT encounter_account_discount_ck CHECK (discount_cents <= subtotal_cents),
    CONSTRAINT encounter_account_total_ck CHECK (total_cents = subtotal_cents - discount_cents)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE encounter_account_item (
    id bigint unsigned NOT NULL AUTO_INCREMENT,
    tenant_id bigint unsigned NOT NULL,
    account_id bigint unsigned NOT NULL,
    source_type varchar(30) NOT NULL,
    source_id bigint unsigned NULL,
    description_text varchar(255) NOT NULL,
    amount_cents int unsigned NOT NULL,
    created_at timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY encounter_account_item_account_source_uq (account_id, source_type, source_id),
    KEY encounter_account_item_tenant_idx (tenant_id),
    KEY encounter_account_item_account_idx (account_id),
    CONSTRAINT encounter_account_item_tenant_fk FOREIGN KEY (tenant_id) REFERENCES tenant (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT encounter_account_item_account_fk FOREIGN KEY (account_id) REFERENCES encounter_account (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT encounter_account_item_source_type_ck CHECK (source_type IN ('procedure_execution', 'exam_request', 'manual'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE receivable (
    id bigint unsigned NOT NULL AUTO_INCREMENT,
    tenant_id bigint unsigned NOT NULL,
    encounter_account_id bigint unsigned NOT NULL,
    tutor_id bigint unsigned NOT NULL,
    total_cents int unsigned NOT NULL,
    paid_cents int unsigned NOT NULL DEFAULT 0,
    status varchar(20) NOT NULL DEFAULT 'open',
    created_at timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY receivable_encounter_account_uq (encounter_account_id),
    KEY receivable_tenant_idx (tenant_id),
    KEY receivable_tutor_idx (tutor_id),
    CONSTRAINT receivable_tenant_fk FOREIGN KEY (tenant_id) REFERENCES tenant (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT receivable_encounter_account_fk FOREIGN KEY (encounter_account_id) REFERENCES encounter_account (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT receivable_tutor_fk FOREIGN KEY (tutor_id) REFERENCES tutor (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT receivable_status_ck CHECK (status IN ('open', 'partially_paid', 'paid', 'cancelled')),
    CONSTRAINT receivable_paid_ck CHECK (paid_cents <= total_cents)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE cash_session (
    id bigint unsigned NOT NULL AUTO_INCREMENT,
    tenant_id bigint unsigned NOT NULL,
    system_unit_id int NOT NULL,
    opened_by_system_user_id int NOT NULL,
    opening_balance_cents int unsigned NOT NULL DEFAULT 0,
    closed_by_system_user_id int NULL,
    closing_balance_cents int unsigned NULL,
    status varchar(10) NOT NULL DEFAULT 'open',
    opened_at timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    closed_at timestamp(6) NULL,
    created_at timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    KEY cash_session_tenant_idx (tenant_id),
    KEY cash_session_unit_idx (system_unit_id),
    KEY cash_session_opened_by_idx (opened_by_system_user_id),
    KEY cash_session_closed_by_idx (closed_by_system_user_id),
    KEY cash_session_tenant_unit_status_idx (tenant_id, system_unit_id, status),
    CONSTRAINT cash_session_tenant_fk FOREIGN KEY (tenant_id) REFERENCES tenant (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT cash_session_unit_fk FOREIGN KEY (system_unit_id) REFERENCES system_unit (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT cash_session_opened_by_fk FOREIGN KEY (opened_by_system_user_id) REFERENCES system_users (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT cash_session_closed_by_fk FOREIGN KEY (closed_by_system_user_id) REFERENCES system_users (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT cash_session_status_ck CHECK (status IN ('open', 'closed'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE payment (
    id bigint unsigned NOT NULL AUTO_INCREMENT,
    tenant_id bigint unsigned NOT NULL,
    receivable_id bigint unsigned NOT NULL,
    payment_method varchar(20) NOT NULL,
    amount_cents int unsigned NOT NULL,
    cash_session_id bigint unsigned NOT NULL,
    system_user_id int NOT NULL,
    paid_at timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    created_at timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    KEY payment_tenant_idx (tenant_id),
    KEY payment_receivable_idx (receivable_id),
    KEY payment_cash_session_idx (cash_session_id),
    KEY payment_system_user_idx (system_user_id),
    CONSTRAINT payment_tenant_fk FOREIGN KEY (tenant_id) REFERENCES tenant (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT payment_receivable_fk FOREIGN KEY (receivable_id) REFERENCES receivable (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT payment_cash_session_fk FOREIGN KEY (cash_session_id) REFERENCES cash_session (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT payment_system_user_fk FOREIGN KEY (system_user_id) REFERENCES system_users (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT payment_method_ck CHECK (payment_method IN ('cash', 'debit_card', 'credit_card', 'pix', 'bank_transfer')),
    CONSTRAINT payment_amount_ck CHECK (amount_cents >= 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE payable (
    id bigint unsigned NOT NULL AUTO_INCREMENT,
    tenant_id bigint unsigned NOT NULL,
    system_unit_id int NOT NULL,
    description_text varchar(255) NOT NULL,
    category varchar(60) NOT NULL,
    amount_cents int unsigned NOT NULL,
    due_date date NULL,
    status varchar(20) NOT NULL DEFAULT 'open',
    paid_at timestamp(6) NULL,
    system_user_id int NOT NULL,
    created_at timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    KEY payable_tenant_idx (tenant_id),
    KEY payable_unit_idx (system_unit_id),
    KEY payable_system_user_idx (system_user_id),
    KEY payable_tenant_status_idx (tenant_id, status),
    CONSTRAINT payable_tenant_fk FOREIGN KEY (tenant_id) REFERENCES tenant (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT payable_unit_fk FOREIGN KEY (system_unit_id) REFERENCES system_unit (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT payable_system_user_fk FOREIGN KEY (system_user_id) REFERENCES system_users (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT payable_status_ck CHECK (status IN ('open', 'paid', 'cancelled'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE financial_entry (
    id bigint unsigned NOT NULL AUTO_INCREMENT,
    tenant_id bigint unsigned NOT NULL,
    system_unit_id int NOT NULL,
    entry_type varchar(10) NOT NULL,
    category varchar(60) NOT NULL,
    amount_cents int unsigned NOT NULL,
    reference_type varchar(40) NULL,
    reference_id bigint unsigned NULL,
    occurred_at timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    system_user_id int NOT NULL,
    created_at timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    KEY financial_entry_tenant_idx (tenant_id),
    KEY financial_entry_unit_idx (system_unit_id),
    KEY financial_entry_system_user_idx (system_user_id),
    KEY financial_entry_reference_idx (reference_type, reference_id),
    KEY financial_entry_tenant_unit_occurred_idx (tenant_id, system_unit_id, occurred_at),
    CONSTRAINT financial_entry_tenant_fk FOREIGN KEY (tenant_id) REFERENCES tenant (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT financial_entry_unit_fk FOREIGN KEY (system_unit_id) REFERENCES system_unit (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT financial_entry_system_user_fk FOREIGN KEY (system_user_id) REFERENCES system_users (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT financial_entry_type_ck CHECK (entry_type IN ('income', 'expense'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT INTO schema_migrations (version, checksum)
VALUES ('20260925_0006_phase5_financial',
        '0000000000000000000000000000000000000000000000000000000000000000' /* 64-char PLACEHOLDER: replace before applying */);
