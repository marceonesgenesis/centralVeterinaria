-- Central Vet Pro - Etapa 4 (operation): job heartbeats and operator alert state
-- Migration: 20261026_0019_ops_monitoring
-- Status: PREPARED ONLY. Do not execute without a fresh backup and explicit SQL approval.
-- Target: MySQL 8.0.x, database centralvet, after 20261026_0018_billing_order_refund has
-- been applied (schema_migrations already exists). Also runs on MySQL 5.7 after
-- scripts/prepare-mysql57.py (no CHECK constraint; only the collation is converted).
--
-- Effects:
--   * creates ops_heartbeat: one row per job (backup, reconciler, monitor, retention)
--     with the time of its last successful run and a short detail;
--   * creates ops_alert_state: one row per alert key with the last time the operator
--     was e-mailed, the subject sent and how many times it was sent (deduplication);
--   * in total: 2 new tables, no foreign key, no CHECK, no change to existing tables.
--   * last_success_at and last_sent_at carry an explicit DEFAULT CURRENT_TIMESTAMP(6)
--     without ON UPDATE: on MySQL 5.7 with explicit_defaults_for_timestamp=OFF a NOT NULL
--     timestamp without DEFAULT would get ON UPDATE CURRENT_TIMESTAMP, and any UPDATE of
--     the row (e.g. only sent_count) would move the deduplication window. The application
--     always writes both columns explicitly; only updated_at auto-updates.
--
-- No data is seeded by this migration. Existing rows are preserved.
--
-- Risk: low; both tables are new and empty. They hold no PII (job names, timestamps,
-- alert keys and alert subjects only). The Etapa 4 jobs (ops-monitor, reconciler
-- heartbeat, backup heartbeat, LGPD retention) read and write these tables: apply this
-- migration before deploying them.
--
-- Rollback: restore the backup taken before applying, or write a numbered reverse
-- migration (see docs/runbooks/migration-rollback.md). Dropping both tables loses only
-- heartbeat and alert-deduplication state.
--
-- MySQL DDL commits implicitly. On failure, stop and inspect; do not rerun blindly.
-- The literal migration checksum below MUST be replaced with the approved artifact's
-- SHA-256 by the migration executor before this file is applied.

CREATE TABLE ops_heartbeat (
    job varchar(64) NOT NULL,
    last_success_at timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    detail varchar(190) NULL,
    updated_at timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (job)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE ops_alert_state (
    alert_key varchar(64) NOT NULL,
    last_sent_at timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    last_subject varchar(190) NOT NULL,
    sent_count int unsigned NOT NULL DEFAULT 1,
    PRIMARY KEY (alert_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT INTO schema_migrations (version, checksum)
VALUES ('20261026_0019_ops_monitoring',
        '0000000000000000000000000000000000000000000000000000000000000000' /* 64-char PLACEHOLDER: replace before applying */);
