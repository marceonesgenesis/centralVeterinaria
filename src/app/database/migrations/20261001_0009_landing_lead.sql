-- Central Vet Pro - Public landing: plan cart leads
-- Migration: 20261001_0009_landing_lead
-- Status: PREPARED ONLY. Do not execute without a fresh backup and explicit SQL approval.
-- Target: MySQL 8.0.x, database centralvet, after 20260930_0008_queue_entry_appointment_unique
-- has been applied (schema_migrations already exists).
--
-- Effects (in this order):
--   * creates table landing_lead: one row per lead sent by the public landing (POST /lead.php).
--     The table has no tenant column: a lead is pre-sale data of the platform operator, prior to
--     any clinic. Plan name and price are copied from LandingCatalog at request time (never
--     from the payload) and kept as recorded. LGPD consent: consent_version, consent_at and
--     consent_ip (clear text, varchar(45) fits IPv6);
--   * keys landing_lead_created_idx (created_at) and landing_lead_plan_idx (plan_id, created_at)
--     for the admin list filters (plan and period, newest first);
--   * checks landing_lead_price_ck (plan_price_cents > 0) and landing_lead_vets_ck
--     (vets_range in the 4 landing options).
--
-- Data: no existing table is changed. The only DML is the schema_migrations audit row.
-- COUNT(*) of tenant, patient, tutor and system_program must be the same before and after
-- (checked by the .verify.sql). Before applying, record those counts in notes.md (Bloqueios).
--
-- Risk: low. CREATE TABLE of a new, empty table; no lock on existing tables. The table holds
-- personal data (name, e-mail, phone, IP) with no retention period yet: purge is manual
-- (docs/runbooks/landing-leads.md).
--
-- Rollback: preferred path is restoring the pre-migration backup (make backup, see
-- docs/runbooks/restore-backup.md and docs/runbooks/migration-rollback.md). If leads written
-- after the migration must be discarded on purpose, a reverse migration (not drafted now),
-- with its own backup and explicit SQL approval, runs
--   DROP TABLE landing_lead;
--   DELETE FROM schema_migrations WHERE version = '20261001_0009_landing_lead';
-- Export the leads first (CSV of LandingLeadList) if they must be kept.
--
-- MySQL DDL commits implicitly. On failure, stop and inspect information_schema; do not
-- rerun blindly.
-- The literal migration checksum below MUST be replaced with the approved artifact's
-- SHA-256 by the migration executor before this file is applied.

CREATE TABLE landing_lead (
    id bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name varchar(120) NOT NULL,
    clinic_name varchar(160) NOT NULL,
    email varchar(160) NOT NULL,
    phone varchar(13) NOT NULL,
    vets_range varchar(8) NOT NULL,
    city varchar(80) NOT NULL DEFAULT '',
    uf char(2) NOT NULL DEFAULT '',
    plan_id varchar(20) NOT NULL,
    plan_name varchar(40) NOT NULL,
    plan_price_cents int unsigned NOT NULL,
    consent_version varchar(40) NOT NULL,
    consent_at timestamp(6) NOT NULL,
    consent_ip varchar(45) NOT NULL,
    created_at timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    KEY landing_lead_created_idx (created_at),
    KEY landing_lead_plan_idx (plan_id, created_at),
    CONSTRAINT landing_lead_price_ck CHECK (plan_price_cents > 0),
    CONSTRAINT landing_lead_vets_ck CHECK (vets_range IN ('1', '2-4', '5-10', '11+'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT INTO schema_migrations (version, checksum)
VALUES ('20261001_0009_landing_lead',
        '0000000000000000000000000000000000000000000000000000000000000000' /* 64-char PLACEHOLDER: replace before applying */);
