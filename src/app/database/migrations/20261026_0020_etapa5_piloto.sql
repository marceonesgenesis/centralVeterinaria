-- Central Vet Pro - Etapa 5 (pilot): scheduled cancellation, legal acceptance and onboarding
-- Migration: 20261026_0020_etapa5_piloto
-- Status: PREPARED ONLY. Do not execute without a fresh backup and explicit SQL approval.
-- Target: MySQL 8.0.x, database centralvet, after 20261026_0019_ops_monitoring has
-- been applied (schema_migrations already exists). Also runs on MySQL 5.7 after
-- scripts/prepare-mysql57.py (no CHECK constraint; only the collation is converted).
--
-- Effects:
--   * adds to subscription, right after cancelled_at: cancel_at_period_end tinyint(1)
--     NOT NULL DEFAULT 0 (cancellation requested from the app, effective at the end of
--     the current period), cancel_requested_at timestamp(6) NULL and
--     cancel_requested_by int NULL (system_users.id of who requested it; no FK, like
--     the other audit columns that point to the Adianti user table);
--   * creates legal_acceptance: one row per user, document (terms, privacy) and
--     document version accepted, with tenant, time, IP and user agent
--     (UNIQUE legal_acceptance_user_doc_version_uq, FK legal_acceptance_tenant_fk);
--   * creates tenant_onboarding: one row per tenant with the time each first-steps
--     item was completed (clinic data confirmed, team reviewed, guide read)
--     (PK and FK tenant_onboarding_tenant_fk on tenant_id);
--   * in total: 3 new columns on subscription, 2 new tables, 2 foreign keys, no CHECK.
--   * existing subscription rows get cancel_at_period_end = 0 and NULL in the other
--     two columns: nothing scheduled for cancellation.
--   * accepted_at carries an explicit DEFAULT CURRENT_TIMESTAMP(6) without ON UPDATE:
--     on MySQL 5.7 with explicit_defaults_for_timestamp=OFF a NOT NULL timestamp without
--     DEFAULT would get ON UPDATE CURRENT_TIMESTAMP. The application always writes it.
--
-- No data is seeded by this migration. Existing rows are preserved.
--
-- Risk: low. ALTER TABLE subscription adds nullable/defaulted columns (online on 8.0;
-- table copy on 5.7, small table). legal_acceptance holds personal data (IP and user
-- agent of the accepting user) as LGPD evidence of consent; it is tenant-scoped and is
-- part of the tenant data export. The Etapa 5 code (cancellation, onboarding checklist,
-- legal acceptance at first login) reads and writes these objects: apply this migration
-- before deploying it.
--
-- Rollback: restore the backup taken before applying, or write a numbered reverse
-- migration (see docs/runbooks/migration-rollback.md). Dropping the tables and columns
-- loses the recorded acceptances, the onboarding progress and any scheduled cancellation.
--
-- MySQL DDL commits implicitly. On failure, stop and inspect; do not rerun blindly.
-- The literal migration checksum below MUST be replaced with the approved artifact's
-- SHA-256 by the migration executor before this file is applied.

ALTER TABLE subscription
    ADD COLUMN cancel_at_period_end tinyint(1) NOT NULL DEFAULT 0 AFTER cancelled_at,
    ADD COLUMN cancel_requested_at timestamp(6) NULL AFTER cancel_at_period_end,
    ADD COLUMN cancel_requested_by int NULL AFTER cancel_requested_at;

CREATE TABLE legal_acceptance (
    id bigint unsigned NOT NULL AUTO_INCREMENT,
    tenant_id bigint unsigned NOT NULL,
    system_user_id int NOT NULL,
    document varchar(20) NOT NULL,
    version varchar(40) NOT NULL,
    accepted_at timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    ip varchar(45) NULL,
    user_agent varchar(255) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY legal_acceptance_user_doc_version_uq (system_user_id, document, version),
    KEY legal_acceptance_tenant_idx (tenant_id),
    CONSTRAINT legal_acceptance_tenant_fk FOREIGN KEY (tenant_id) REFERENCES tenant (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE tenant_onboarding (
    tenant_id bigint unsigned NOT NULL,
    clinic_confirmed_at timestamp(6) NULL,
    team_reviewed_at timestamp(6) NULL,
    guide_read_at timestamp(6) NULL,
    created_at timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (tenant_id),
    CONSTRAINT tenant_onboarding_tenant_fk FOREIGN KEY (tenant_id) REFERENCES tenant (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT INTO schema_migrations (version, checksum)
VALUES ('20261026_0020_etapa5_piloto',
        '0000000000000000000000000000000000000000000000000000000000000000' /* 64-char PLACEHOLDER: replace before applying */);
