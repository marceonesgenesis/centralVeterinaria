-- READ-ONLY verification for 20261026_0019_ops_monitoring.
-- Safe to run only after the migration itself has been explicitly authorized.

-- Migration record (expected: 1 row)
SELECT version, checksum, applied_at
FROM schema_migrations
WHERE version = '20261026_0019_ops_monitoring';

-- Tables (expected: 2 rows, engine InnoDB, collation utf8mb4_0900_ai_ci on MySQL 8.0)
SELECT table_name, engine, table_collation
FROM information_schema.TABLES
WHERE table_schema = DATABASE()
  AND table_name IN ('ops_heartbeat', 'ops_alert_state')
ORDER BY table_name;

-- Columns (expected: 8 rows: ops_alert_state alert_key, last_sent_at, last_subject,
-- sent_count; ops_heartbeat job, last_success_at, detail (nullable), updated_at)
SELECT table_name, column_name, is_nullable, column_type, column_default, extra
FROM information_schema.COLUMNS
WHERE table_schema = DATABASE()
  AND table_name IN ('ops_heartbeat', 'ops_alert_state')
ORDER BY table_name, ordinal_position;

-- Primary keys (expected: 2 rows: ops_alert_state.alert_key, ops_heartbeat.job)
SELECT table_name, column_name
FROM information_schema.KEY_COLUMN_USAGE
WHERE table_schema = DATABASE()
  AND table_name IN ('ops_heartbeat', 'ops_alert_state')
  AND constraint_name = 'PRIMARY'
ORDER BY table_name;

-- Timestamp auto-update (expected: 0 in both columns). Only ops_heartbeat.updated_at may
-- carry ON UPDATE CURRENT_TIMESTAMP; last_success_at and last_sent_at must not (a NOT NULL
-- timestamp without an explicit DEFAULT would get one on MySQL 5.7 with
-- explicit_defaults_for_timestamp=OFF and break the alert deduplication window)
SELECT (SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE table_schema = DATABASE()
          AND table_name IN ('ops_heartbeat', 'ops_alert_state')
          AND column_name <> 'updated_at'
          AND LOWER(extra) LIKE '%on update%') AS unexpected_on_update_columns,
       (SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE table_schema = DATABASE()
          AND table_name IN ('ops_heartbeat', 'ops_alert_state')
          AND data_type = 'timestamp'
          AND is_nullable = 'NO'
          AND column_default IS NULL) AS not_null_timestamps_without_default;
