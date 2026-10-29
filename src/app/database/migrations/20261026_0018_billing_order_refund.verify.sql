-- READ-ONLY verification for 20261026_0018_billing_order_refund.
-- Safe to run only after the migration itself has been explicitly authorized.

-- Migration record (expected: 1 row)
SELECT version, checksum, applied_at
FROM schema_migrations
WHERE version = '20261026_0018_billing_order_refund';

-- New columns (expected: 3 rows, in this order, all is_nullable = YES:
-- subscription_cancelled_at timestamp(6), stripe_refund_id varchar(64),
-- refunded_at timestamp(6))
SELECT table_name, column_name, is_nullable, column_type, column_default, extra
FROM information_schema.COLUMNS
WHERE table_schema = DATABASE()
  AND table_name = 'billing_order'
  AND column_name IN ('subscription_cancelled_at', 'stripe_refund_id', 'refunded_at')
ORDER BY ordinal_position;

-- Widened CHECK (expected: 1 row whose check_clause contains 'refunded')
SELECT tc.table_name, tc.constraint_name, cc.check_clause
FROM information_schema.TABLE_CONSTRAINTS tc
JOIN information_schema.CHECK_CONSTRAINTS cc
  ON cc.constraint_schema = tc.constraint_schema
 AND cc.constraint_name = tc.constraint_name
WHERE tc.table_schema = DATABASE()
  AND tc.table_name = 'billing_order'
  AND tc.constraint_type = 'CHECK'
  AND tc.constraint_name = 'billing_order_status_ck'
  AND cc.check_clause LIKE '%refunded%';

-- Violations (expected: 0)
SELECT COUNT(*) AS billing_order_status_violations
FROM billing_order
WHERE status NOT IN ('pending', 'checkout_open', 'paid', 'provisioned', 'provisioning_failed', 'expired', 'refunded');
