-- READ-ONLY verification for 20261005_0011_phase6b_surgery.
-- Safe to run only after the migration itself has been explicitly authorized.

SELECT version, checksum, applied_at
FROM schema_migrations
WHERE version = '20261005_0011_phase6b_surgery';

-- Tables (expected: 6 rows)
SELECT table_name, table_rows
FROM information_schema.tables
WHERE table_schema = DATABASE()
  AND table_name IN (
      'surgery_room', 'surgery', 'surgery_team',
      'surgery_checklist', 'surgery_event', 'surgery_material'
  )
ORDER BY table_name;

SELECT table_name, column_name, is_nullable, column_type, column_default, extra
FROM information_schema.columns
WHERE table_schema = DATABASE()
  AND table_name IN (
      'surgery_room', 'surgery', 'surgery_team',
      'surgery_checklist', 'surgery_event', 'surgery_material'
  )
ORDER BY table_name, ordinal_position;

-- UNIQUE constraints (expected: 3 rows)
SELECT table_name, constraint_name
FROM information_schema.table_constraints
WHERE table_schema = DATABASE()
  AND constraint_type = 'UNIQUE'
  AND table_name IN ('surgery_room', 'surgery_team', 'surgery_checklist')
  AND constraint_name IN ('surgery_room_unit_code_uq', 'surgery_team_member_uq', 'surgery_checklist_item_uq')
ORDER BY table_name, constraint_name;

-- Foreign keys of the new tables (expected: 27 rows)
SELECT table_name, constraint_name
FROM information_schema.table_constraints
WHERE table_schema = DATABASE()
  AND constraint_type = 'FOREIGN KEY'
  AND table_name IN (
      'surgery_room', 'surgery', 'surgery_team',
      'surgery_checklist', 'surgery_event', 'surgery_material'
  )
ORDER BY table_name, constraint_name;

-- Checks (expected: 13 rows: 11 new and the 2 widened ones, whose clauses must list
-- surgery_procedure/surgery_material and surgery_consumption)
SELECT tc.table_name, tc.constraint_name, cc.check_clause
FROM information_schema.table_constraints tc
JOIN information_schema.check_constraints cc
  ON cc.constraint_schema = tc.constraint_schema
 AND cc.constraint_name = tc.constraint_name
WHERE tc.table_schema = DATABASE()
  AND tc.table_name IN (
      'surgery_room', 'surgery', 'surgery_team',
      'surgery_checklist', 'surgery_event', 'surgery_material',
      'encounter_account_item', 'stock_movement'
  )
  AND tc.constraint_type = 'CHECK'
  AND tc.constraint_name IN (
      'surgery_room_status_ck',
      'surgery_status_ck', 'surgery_period_ck', 'surgery_consent_ck',
      'surgery_started_ck', 'surgery_completed_ck', 'surgery_cancelled_ck',
      'surgery_team_role_ck', 'surgery_checklist_phase_ck',
      'surgery_event_type_ck', 'surgery_material_quantity_ck',
      'encounter_account_item_source_type_ck', 'stock_movement_reason_ck'
  )
ORDER BY tc.table_name, tc.constraint_name;

-- Row counts (expected: encounter_account_item and stock_movement equal to the counts
-- recorded before the migration; the 6 new tables empty)
SELECT COUNT(*) AS encounter_account_item_rows FROM encounter_account_item;
SELECT COUNT(*) AS stock_movement_rows FROM stock_movement;
SELECT (SELECT COUNT(*) FROM surgery_room) AS surgery_room_rows,
       (SELECT COUNT(*) FROM surgery) AS surgery_rows,
       (SELECT COUNT(*) FROM surgery_team) AS surgery_team_rows,
       (SELECT COUNT(*) FROM surgery_checklist) AS surgery_checklist_rows,
       (SELECT COUNT(*) FROM surgery_event) AS surgery_event_rows,
       (SELECT COUNT(*) FROM surgery_material) AS surgery_material_rows;
