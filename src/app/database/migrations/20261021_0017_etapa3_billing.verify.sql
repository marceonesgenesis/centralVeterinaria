-- READ-ONLY verification for 20261021_0017_etapa3_billing.
-- Safe to run only after the migration itself has been explicitly authorized.

-- Migration record (expected: 1 row)
SELECT version, checksum, applied_at
FROM schema_migrations
WHERE version = '20261021_0017_etapa3_billing';

-- Tables (expected: 4 rows)
SELECT table_name, table_rows
FROM information_schema.tables
WHERE table_schema = DATABASE()
  AND table_name IN ('billing_order', 'subscription', 'subscription_invoice', 'billing_event')
ORDER BY table_name;

-- Columns (expected: 71 rows: billing_order 25, subscription 15, subscription_invoice 19,
-- billing_event 12)
SELECT table_name, column_name, is_nullable, column_type, column_default, extra
FROM information_schema.columns
WHERE table_schema = DATABASE()
  AND table_name IN ('billing_order', 'subscription', 'subscription_invoice', 'billing_event')
ORDER BY table_name, ordinal_position;

-- UNIQUE constraints (expected: 9 rows)
SELECT table_name, constraint_name
FROM information_schema.table_constraints
WHERE table_schema = DATABASE()
  AND constraint_type = 'UNIQUE'
  AND table_name IN ('billing_order', 'subscription', 'subscription_invoice', 'billing_event')
  AND constraint_name IN (
      'billing_order_public_id_uq', 'billing_order_checkout_uq',
      'subscription_billing_order_uq', 'subscription_tenant_uq', 'subscription_stripe_uq',
      'subscription_invoice_stripe_uq', 'subscription_invoice_local_key_uq',
      'subscription_invoice_pi_uq', 'billing_event_provider_event_uq'
  )
ORDER BY table_name, constraint_name;

-- Foreign keys (expected: 4 rows)
SELECT table_name, constraint_name
FROM information_schema.table_constraints
WHERE table_schema = DATABASE()
  AND constraint_type = 'FOREIGN KEY'
  AND table_name IN ('billing_order', 'subscription', 'subscription_invoice')
  AND constraint_name IN (
      'billing_order_tenant_fk', 'subscription_billing_order_fk',
      'subscription_tenant_fk', 'subscription_invoice_subscription_fk'
  )
ORDER BY table_name, constraint_name;

-- Secondary indexes (expected: 6 rows, one per index name)
SELECT DISTINCT table_name, index_name
FROM information_schema.statistics
WHERE table_schema = DATABASE()
  AND table_name IN ('billing_order', 'subscription', 'subscription_invoice', 'billing_event')
  AND index_name IN (
      'billing_order_status_idx', 'subscription_grace_idx', 'subscription_pix_due_idx',
      'subscription_invoice_open_idx', 'billing_event_status_idx', 'billing_event_created_idx'
  )
ORDER BY table_name, index_name;

-- Checks (expected: 9 rows)
SELECT tc.table_name, tc.constraint_name, cc.check_clause
FROM information_schema.table_constraints tc
JOIN information_schema.check_constraints cc
  ON cc.constraint_schema = tc.constraint_schema
 AND cc.constraint_name = tc.constraint_name
WHERE tc.table_schema = DATABASE()
  AND tc.table_name IN ('billing_order', 'subscription', 'subscription_invoice', 'billing_event')
  AND tc.constraint_type = 'CHECK'
  AND tc.constraint_name IN (
      'billing_order_plan_ck', 'billing_order_method_ck', 'billing_order_status_ck',
      'subscription_plan_ck', 'subscription_status_ck', 'subscription_method_ck',
      'subscription_invoice_status_ck', 'subscription_invoice_ref_ck',
      'billing_event_status_ck'
  )
ORDER BY tc.table_name, tc.constraint_name;

-- Timestamp auto-update (expected: 0 in both columns). Only updated_at may carry
-- ON UPDATE CURRENT_TIMESTAMP; a NOT NULL timestamp without an explicit DEFAULT would get
-- one on MySQL 5.7 with explicit_defaults_for_timestamp=OFF (terms_accepted_at, paid_at...)
SELECT (SELECT COUNT(*) FROM information_schema.columns
        WHERE table_schema = DATABASE()
          AND table_name IN ('billing_order', 'subscription', 'subscription_invoice', 'billing_event')
          AND column_name <> 'updated_at'
          AND LOWER(extra) LIKE '%on update%') AS unexpected_on_update_columns,
       (SELECT COUNT(*) FROM information_schema.columns
        WHERE table_schema = DATABASE()
          AND table_name IN ('billing_order', 'subscription', 'subscription_invoice', 'billing_event')
          AND data_type = 'timestamp'
          AND is_nullable = 'NO'
          AND column_default IS NULL) AS not_null_timestamps_without_default;

-- Violations (expected: 0 in every column)
SELECT (SELECT COUNT(*) FROM subscription_invoice
        WHERE stripe_invoice_id IS NULL AND local_key IS NULL) AS invoice_ref_violations,
       (SELECT COUNT(*) FROM subscription s
        JOIN billing_order o ON o.id = s.billing_order_id
        WHERE o.plan_id <> s.plan_id OR o.payment_method <> s.payment_method) AS subscription_order_mismatches,
       (SELECT COUNT(*) FROM subscription
        WHERE payment_method = 'pix' AND stripe_subscription_id IS NOT NULL) AS pix_stripe_subscription_violations;

-- Row counts (expected: the 4 new tables empty right after the migration)
SELECT (SELECT COUNT(*) FROM billing_order) AS billing_order_rows,
       (SELECT COUNT(*) FROM subscription) AS subscription_rows,
       (SELECT COUNT(*) FROM subscription_invoice) AS subscription_invoice_rows,
       (SELECT COUNT(*) FROM billing_event) AS billing_event_rows;
