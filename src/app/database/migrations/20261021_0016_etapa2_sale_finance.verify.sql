-- READ-ONLY verification for 20261021_0016_etapa2_sale_finance.
-- Safe to run only after the migration itself has been explicitly authorized.

-- Migration record (expected: 1 row)
SELECT version, checksum, applied_at
FROM schema_migrations
WHERE version = '20261021_0016_etapa2_sale_finance';

-- Tables (expected: 2 rows)
SELECT table_name, table_rows
FROM information_schema.tables
WHERE table_schema = DATABASE()
  AND table_name IN ('payment_reversal', 'submission_key')
ORDER BY table_name;

SELECT table_name, column_name, is_nullable, column_type, column_default, extra
FROM information_schema.columns
WHERE table_schema = DATABASE()
  AND table_name IN ('payment_reversal', 'submission_key')
ORDER BY table_name, ordinal_position;

-- New columns on existing tables (expected: 4 rows: receivable.sale_id and the 3
-- cancellation columns of sale, all is_nullable = YES)
SELECT table_name, column_name, is_nullable, column_type
FROM information_schema.columns
WHERE table_schema = DATABASE()
  AND ((table_name = 'receivable' AND column_name = 'sale_id')
       OR (table_name = 'sale' AND column_name IN ('cancelled_at', 'cancelled_by_system_user_id', 'cancellation_reason')))
ORDER BY table_name, ordinal_position;

-- receivable.encounter_account_id is NULL-able (expected: 1 row, is_nullable = YES)
SELECT table_name, column_name, is_nullable, column_type
FROM information_schema.columns
WHERE table_schema = DATABASE()
  AND table_name = 'receivable'
  AND column_name = 'encounter_account_id';

-- UNIQUE constraints (expected: 4 rows: the 3 new ones and the kept
-- receivable_encounter_account_uq)
SELECT table_name, constraint_name
FROM information_schema.table_constraints
WHERE table_schema = DATABASE()
  AND constraint_type = 'UNIQUE'
  AND table_name IN ('receivable', 'payment_reversal', 'submission_key')
  AND constraint_name IN ('receivable_sale_uq', 'receivable_encounter_account_uq',
                          'payment_reversal_payment_uq', 'submission_key_uq')
ORDER BY table_name, constraint_name;

-- Foreign keys (expected: 9 rows: the 8 new ones and the kept
-- receivable_encounter_account_fk)
SELECT table_name, constraint_name
FROM information_schema.table_constraints
WHERE table_schema = DATABASE()
  AND constraint_type = 'FOREIGN KEY'
  AND table_name IN ('receivable', 'sale', 'payment_reversal', 'submission_key')
  AND constraint_name IN (
      'receivable_sale_fk', 'receivable_encounter_account_fk', 'sale_cancelled_by_fk',
      'payment_reversal_tenant_fk', 'payment_reversal_payment_fk',
      'payment_reversal_cash_session_fk', 'payment_reversal_system_user_fk',
      'submission_key_tenant_fk', 'submission_key_system_user_fk'
  )
ORDER BY table_name, constraint_name;

-- Checks (expected: 6 rows: 5 new and the widened stock_movement_reason_ck, whose
-- clause must list sale_cancellation)
SELECT tc.table_name, tc.constraint_name, cc.check_clause
FROM information_schema.table_constraints tc
JOIN information_schema.check_constraints cc
  ON cc.constraint_schema = tc.constraint_schema
 AND cc.constraint_name = tc.constraint_name
WHERE tc.table_schema = DATABASE()
  AND tc.table_name IN ('receivable', 'sale', 'payment_reversal', 'submission_key', 'stock_movement')
  AND tc.constraint_type = 'CHECK'
  AND tc.constraint_name IN (
      'receivable_origin_ck', 'sale_cancellation_ck',
      'payment_reversal_amount_ck', 'payment_reversal_method_ck',
      'submission_key_scope_ck', 'stock_movement_reason_ck'
  )
ORDER BY tc.table_name, tc.constraint_name;

-- Violations (expected: 0 in every column)
SELECT (SELECT COUNT(*) FROM receivable
        WHERE NOT ((encounter_account_id IS NOT NULL AND sale_id IS NULL)
                   OR (encounter_account_id IS NULL AND sale_id IS NOT NULL))) AS receivable_origin_violations,
       (SELECT COUNT(*) FROM sale
        WHERE NOT ((status = 'completed' AND cancelled_at IS NULL AND cancelled_by_system_user_id IS NULL AND cancellation_reason IS NULL)
                   OR (status = 'cancelled' AND cancelled_at IS NOT NULL AND cancelled_by_system_user_id IS NOT NULL AND cancellation_reason IS NOT NULL))) AS sale_cancellation_violations;

-- Row counts (expected: receivable and sale equal to the counts recorded before the
-- migration; the 2 new tables empty)
SELECT COUNT(*) AS receivable_rows FROM receivable;
SELECT COUNT(*) AS sale_rows FROM sale;
SELECT (SELECT COUNT(*) FROM payment_reversal) AS payment_reversal_rows,
       (SELECT COUNT(*) FROM submission_key) AS submission_key_rows;
