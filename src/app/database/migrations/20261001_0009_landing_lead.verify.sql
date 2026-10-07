-- READ-ONLY verification for 20261001_0009_landing_lead.
-- Safe to run only after the migration itself has been explicitly authorized.
-- Only SELECT statements. Compare the counts below with the ones recorded in notes.md
-- (Bloqueios) before the migration.

SELECT version, checksum, applied_at
FROM schema_migrations
WHERE version = '20261001_0009_landing_lead';

-- Table (expected: 1 row, InnoDB, utf8mb4_0900_ai_ci)
SELECT table_name, engine, table_collation
FROM information_schema.tables
WHERE table_schema = DATABASE()
  AND table_name = 'landing_lead';

-- Columns (expected: 15 rows, none named tenant_id)
SELECT ordinal_position, column_name, column_type, is_nullable, column_default
FROM information_schema.columns
WHERE table_schema = DATABASE()
  AND table_name = 'landing_lead'
ORDER BY ordinal_position;

SELECT COUNT(*) AS landing_lead_columns,
       SUM(column_name = 'tenant_id') AS tenant_id_columns
FROM information_schema.columns
WHERE table_schema = DATABASE()
  AND table_name = 'landing_lead';

-- Indexes (expected: 3 rows: created_at; plan_id, created_at)
SELECT index_name, seq_in_index, column_name
FROM information_schema.statistics
WHERE table_schema = DATABASE()
  AND table_name = 'landing_lead'
  AND index_name IN ('landing_lead_created_idx', 'landing_lead_plan_idx')
ORDER BY index_name, seq_in_index;

-- Checks (expected: 2 rows)
SELECT tc.constraint_name, cc.check_clause
FROM information_schema.table_constraints tc
JOIN information_schema.check_constraints cc
  ON cc.constraint_schema = tc.constraint_schema
 AND cc.constraint_name = tc.constraint_name
WHERE tc.table_schema = DATABASE()
  AND tc.table_name = 'landing_lead'
  AND tc.constraint_type = 'CHECK'
  AND tc.constraint_name IN ('landing_lead_price_ck', 'landing_lead_vets_ck');

-- Row counts (expected: equal to the counts recorded before the migration;
-- system_program grows by 1 only after sql/T-02-programs.sql)
SELECT COUNT(*) AS tenant_rows FROM tenant;
SELECT COUNT(*) AS patient_rows FROM patient;
SELECT COUNT(*) AS tutor_rows FROM tutor;
SELECT COUNT(*) AS system_program_rows FROM system_program;
