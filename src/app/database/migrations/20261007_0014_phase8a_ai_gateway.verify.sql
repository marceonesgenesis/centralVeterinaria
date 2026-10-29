-- READ-ONLY verification for 20261007_0014_phase8a_ai_gateway.
-- Safe to run only after the migration itself has been explicitly authorized.

SELECT version, checksum, applied_at
FROM schema_migrations
WHERE version = '20261007_0014_phase8a_ai_gateway';

-- Tables (expected: 3 rows)
SELECT table_name, table_rows
FROM information_schema.tables
WHERE table_schema = DATABASE()
  AND table_name IN ('feature_flag', 'ai_tenant_setting', 'ai_interaction')
ORDER BY table_name;

SELECT table_name, column_name, is_nullable, column_type, column_default, extra
FROM information_schema.columns
WHERE table_schema = DATABASE()
  AND table_name IN ('feature_flag', 'ai_tenant_setting', 'ai_interaction')
ORDER BY table_name, ordinal_position;

-- Uniqueness keys (expected: 2 rows; feature_flag_tenant_key_uq keeps one flag per
-- tenant and key, and the ai_tenant_setting primary key keeps one setting per tenant)
SELECT table_name, constraint_name, constraint_type
FROM information_schema.table_constraints
WHERE table_schema = DATABASE()
  AND (
      (table_name = 'feature_flag' AND constraint_type = 'UNIQUE'
          AND constraint_name = 'feature_flag_tenant_key_uq')
      OR (table_name = 'ai_tenant_setting' AND constraint_type = 'PRIMARY KEY')
  )
ORDER BY table_name, constraint_name;

-- Foreign keys of the new tables (expected: 8 rows)
SELECT table_name, constraint_name
FROM information_schema.table_constraints
WHERE table_schema = DATABASE()
  AND constraint_type = 'FOREIGN KEY'
  AND table_name IN ('feature_flag', 'ai_tenant_setting', 'ai_interaction')
ORDER BY table_name, constraint_name;

-- Checks (expected: 8 rows)
SELECT tc.table_name, tc.constraint_name, cc.check_clause
FROM information_schema.table_constraints tc
JOIN information_schema.check_constraints cc
  ON cc.constraint_schema = tc.constraint_schema
 AND cc.constraint_name = tc.constraint_name
WHERE tc.table_schema = DATABASE()
  AND tc.table_name IN ('feature_flag', 'ai_tenant_setting', 'ai_interaction')
  AND tc.constraint_type = 'CHECK'
  AND tc.constraint_name IN (
      'feature_flag_enabled_ck', 'ai_tenant_setting_terms_ck',
      'ai_interaction_purpose_ck', 'ai_interaction_subject_ck',
      'ai_interaction_status_ck', 'ai_interaction_failed_ck',
      'ai_interaction_decided_ck', 'ai_interaction_final_ck'
  )
ORDER BY tc.table_name, tc.constraint_name;

-- Indexes of ai_interaction (expected: ai_interaction_tenant_created_idx (tenant_id,
-- created_at), ai_interaction_tenant_user_idx (tenant_id, system_user_id, created_at),
-- ai_interaction_subject_idx (tenant_id, subject_type, subject_id, purpose, created_at)
-- and ai_interaction_tenant_purpose_idx (tenant_id, purpose, created_at))
SELECT table_name, index_name, seq_in_index, column_name
FROM information_schema.statistics
WHERE table_schema = DATABASE()
  AND table_name IN ('ai_interaction')
  AND index_name IN (
      'ai_interaction_tenant_created_idx', 'ai_interaction_tenant_user_idx',
      'ai_interaction_subject_idx', 'ai_interaction_tenant_purpose_idx'
  )
ORDER BY table_name, index_name, seq_in_index;

-- Row counts (expected: encounter, exam_result, patient and audit_log equal to the
-- counts recorded before the migration; the 3 new tables empty)
SELECT COUNT(*) AS encounter_rows FROM encounter;
SELECT COUNT(*) AS exam_result_rows FROM exam_result;
SELECT COUNT(*) AS patient_rows FROM patient;
SELECT COUNT(*) AS audit_log_rows FROM audit_log;
SELECT (SELECT COUNT(*) FROM feature_flag) AS feature_flag_rows,
       (SELECT COUNT(*) FROM ai_tenant_setting) AS ai_tenant_setting_rows,
       (SELECT COUNT(*) FROM ai_interaction) AS ai_interaction_rows;
