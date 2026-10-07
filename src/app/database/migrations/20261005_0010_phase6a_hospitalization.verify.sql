-- READ-ONLY verification for 20261005_0010_phase6a_hospitalization.
-- Safe to run only after the migration itself has been explicitly authorized.

SELECT version, checksum, applied_at
FROM schema_migrations
WHERE version = '20261005_0010_phase6a_hospitalization';

-- Tables (expected: 5 rows)
SELECT table_name, table_rows
FROM information_schema.tables
WHERE table_schema = DATABASE()
  AND table_name IN (
      'bed', 'hospitalization', 'hospitalization_order',
      'hospitalization_administration', 'hospitalization_event'
  )
ORDER BY table_name;

SELECT table_name, column_name, is_nullable, column_type, column_default, extra
FROM information_schema.columns
WHERE table_schema = DATABASE()
  AND table_name IN (
      'bed', 'hospitalization', 'hospitalization_order',
      'hospitalization_administration', 'hospitalization_event'
  )
ORDER BY table_name, ordinal_position;

-- UNIQUE constraints (expected: 2 rows)
SELECT table_name, constraint_name
FROM information_schema.table_constraints
WHERE table_schema = DATABASE()
  AND constraint_type = 'UNIQUE'
  AND constraint_name IN ('bed_unit_code_uq', 'hospitalization_administration_order_scheduled_uq')
ORDER BY table_name, constraint_name;

-- Foreign keys of the new tables (expected: 23 rows)
SELECT table_name, constraint_name
FROM information_schema.table_constraints
WHERE table_schema = DATABASE()
  AND constraint_type = 'FOREIGN KEY'
  AND table_name IN (
      'bed', 'hospitalization', 'hospitalization_order',
      'hospitalization_administration', 'hospitalization_event'
  )
ORDER BY table_name, constraint_name;

-- Checks (expected: 16 rows: 14 new and the 2 widened ones, whose clauses must list
-- hospitalization_stay/hospitalization_administration and hospitalization_consumption)
SELECT tc.table_name, tc.constraint_name, cc.check_clause
FROM information_schema.table_constraints tc
JOIN information_schema.check_constraints cc
  ON cc.constraint_schema = tc.constraint_schema
 AND cc.constraint_name = tc.constraint_name
WHERE tc.table_schema = DATABASE()
  AND tc.table_name IN (
      'bed', 'hospitalization', 'hospitalization_order',
      'hospitalization_administration', 'hospitalization_event',
      'encounter_account_item', 'stock_movement'
  )
  AND tc.constraint_type = 'CHECK'
  AND tc.constraint_name IN (
      'bed_status_ck', 'bed_occupancy_ck',
      'hospitalization_status_ck', 'hospitalization_discharge_ck',
      'hospitalization_order_type_ck', 'hospitalization_order_route_ck',
      'hospitalization_order_status_ck', 'hospitalization_order_frequency_ck',
      'hospitalization_order_period_ck', 'hospitalization_order_product_ck',
      'hospitalization_administration_status_ck', 'hospitalization_administration_performed_ck',
      'hospitalization_event_type_ck', 'hospitalization_event_pain_ck',
      'encounter_account_item_source_type_ck', 'stock_movement_reason_ck'
  )
ORDER BY tc.table_name, tc.constraint_name;

-- Row counts (expected: encounter_account_item and stock_movement equal to the counts
-- recorded before the migration; the 5 new tables empty)
SELECT COUNT(*) AS encounter_account_item_rows FROM encounter_account_item;
SELECT COUNT(*) AS stock_movement_rows FROM stock_movement;
SELECT (SELECT COUNT(*) FROM bed) AS bed_rows,
       (SELECT COUNT(*) FROM hospitalization) AS hospitalization_rows,
       (SELECT COUNT(*) FROM hospitalization_order) AS hospitalization_order_rows,
       (SELECT COUNT(*) FROM hospitalization_administration) AS hospitalization_administration_rows,
       (SELECT COUNT(*) FROM hospitalization_event) AS hospitalization_event_rows;
