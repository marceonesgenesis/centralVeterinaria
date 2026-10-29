-- READ-ONLY verification for 20261026_0020_etapa5_piloto.
-- Safe to run only after the migration itself has been explicitly authorized.

-- Migration record (expected: 1 row)
SELECT version, checksum, applied_at
FROM schema_migrations
WHERE version = '20261026_0020_etapa5_piloto';

-- New subscription columns (expected: 3 rows, right after cancelled_at:
-- cancel_at_period_end tinyint(1) NOT NULL DEFAULT 0, cancel_requested_at timestamp(6)
-- NULL, cancel_requested_by int NULL)
SELECT column_name, ordinal_position, is_nullable, column_type, column_default
FROM information_schema.COLUMNS
WHERE table_schema = DATABASE()
  AND table_name = 'subscription'
  AND column_name IN ('cancelled_at', 'cancel_at_period_end', 'cancel_requested_at', 'cancel_requested_by')
ORDER BY ordinal_position;

-- Existing subscriptions keep nothing scheduled (expected: 0)
SELECT COUNT(*) AS subscriptions_scheduled_for_cancellation
FROM subscription
WHERE cancel_at_period_end <> 0 OR cancel_requested_at IS NOT NULL OR cancel_requested_by IS NOT NULL;

-- Tables (expected: 2 rows, engine InnoDB, collation utf8mb4_0900_ai_ci on MySQL 8.0)
SELECT table_name, engine, table_collation
FROM information_schema.TABLES
WHERE table_schema = DATABASE()
  AND table_name IN ('legal_acceptance', 'tenant_onboarding')
ORDER BY table_name;

-- Columns (expected: 14 rows: legal_acceptance id, tenant_id, system_user_id, document,
-- version, accepted_at, ip, user_agent; tenant_onboarding tenant_id, clinic_confirmed_at,
-- team_reviewed_at, guide_read_at, created_at, updated_at)
SELECT table_name, column_name, is_nullable, column_type, column_default, extra
FROM information_schema.COLUMNS
WHERE table_schema = DATABASE()
  AND table_name IN ('legal_acceptance', 'tenant_onboarding')
ORDER BY table_name, ordinal_position;

-- Indexes (expected: legal_acceptance PRIMARY(id), legal_acceptance_tenant_idx(tenant_id),
-- legal_acceptance_user_doc_version_uq(system_user_id, document, version) unique;
-- tenant_onboarding PRIMARY(tenant_id))
SELECT table_name, index_name, non_unique, seq_in_index, column_name
FROM information_schema.STATISTICS
WHERE table_schema = DATABASE()
  AND table_name IN ('legal_acceptance', 'tenant_onboarding')
ORDER BY table_name, index_name, seq_in_index;

-- Foreign keys (expected: 2 rows, both to tenant(id))
SELECT table_name, constraint_name, referenced_table_name, referenced_column_name
FROM information_schema.KEY_COLUMN_USAGE
WHERE table_schema = DATABASE()
  AND table_name IN ('legal_acceptance', 'tenant_onboarding')
  AND referenced_table_name IS NOT NULL
ORDER BY table_name;

-- Timestamp auto-update (expected: 0). Only tenant_onboarding.updated_at may carry
-- ON UPDATE CURRENT_TIMESTAMP.
SELECT COUNT(*) AS unexpected_on_update_columns
FROM information_schema.COLUMNS
WHERE table_schema = DATABASE()
  AND table_name IN ('legal_acceptance', 'tenant_onboarding')
  AND NOT (table_name = 'tenant_onboarding' AND column_name = 'updated_at')
  AND LOWER(extra) LIKE '%on update%';
