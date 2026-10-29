-- READ-ONLY verification for 20261006_0013_phase7b_documents.
-- Safe to run only after the migration itself has been explicitly authorized.

SELECT version, checksum, applied_at
FROM schema_migrations
WHERE version = '20261006_0013_phase7b_documents';

-- Tables (expected: 2 rows)
SELECT table_name, table_rows
FROM information_schema.tables
WHERE table_schema = DATABASE()
  AND table_name IN ('document_template', 'generated_document')
ORDER BY table_name;

SELECT table_name, column_name, is_nullable, column_type, column_default, extra
FROM information_schema.columns
WHERE table_schema = DATABASE()
  AND table_name IN ('document_template', 'generated_document')
ORDER BY table_name, ordinal_position;

-- UNIQUE constraints (expected: 3 rows; generated_document_version_uq is the version
-- key per source and generated_document_stored_object_uq keeps one document per object)
SELECT table_name, constraint_name
FROM information_schema.table_constraints
WHERE table_schema = DATABASE()
  AND constraint_type = 'UNIQUE'
  AND table_name IN ('document_template', 'generated_document')
  AND constraint_name IN (
      'document_template_tenant_name_uq', 'generated_document_version_uq',
      'generated_document_stored_object_uq'
  )
ORDER BY table_name, constraint_name;

-- Foreign keys of the new tables (expected: 10 rows)
SELECT table_name, constraint_name
FROM information_schema.table_constraints
WHERE table_schema = DATABASE()
  AND constraint_type = 'FOREIGN KEY'
  AND table_name IN ('document_template', 'generated_document')
ORDER BY table_name, constraint_name;

-- Checks (expected: 9 rows)
SELECT tc.table_name, tc.constraint_name, cc.check_clause
FROM information_schema.table_constraints tc
JOIN information_schema.check_constraints cc
  ON cc.constraint_schema = tc.constraint_schema
 AND cc.constraint_name = tc.constraint_name
WHERE tc.table_schema = DATABASE()
  AND tc.table_name IN ('document_template', 'generated_document')
  AND tc.constraint_type = 'CHECK'
  AND tc.constraint_name IN (
      'document_template_kind_ck', 'document_template_status_ck',
      'generated_document_kind_ck', 'generated_document_source_ck',
      'generated_document_status_ck', 'generated_document_version_ck',
      'generated_document_notify_ck', 'generated_document_ready_ck',
      'generated_document_failed_ck'
  )
ORDER BY tc.table_name, tc.constraint_name;

-- Indexes of the new tables (expected: document_template_kind_idx (tenant_id, kind,
-- status), generated_document_unit_status_idx (tenant_id, system_unit_id, status,
-- created_at) and generated_document_patient_idx (tenant_id, patient_id, created_at))
SELECT table_name, index_name, seq_in_index, column_name
FROM information_schema.statistics
WHERE table_schema = DATABASE()
  AND table_name IN ('document_template', 'generated_document')
  AND index_name IN (
      'document_template_kind_idx', 'generated_document_unit_status_idx',
      'generated_document_patient_idx'
  )
ORDER BY table_name, index_name, seq_in_index;

-- Row counts (expected: patient, vaccination, prescription, surgery, stored_object and
-- communication_message equal to the counts recorded before the migration; the 2 new
-- tables empty)
SELECT COUNT(*) AS patient_rows FROM patient;
SELECT COUNT(*) AS vaccination_rows FROM vaccination;
SELECT COUNT(*) AS prescription_rows FROM prescription;
SELECT COUNT(*) AS surgery_rows FROM surgery;
SELECT COUNT(*) AS stored_object_rows FROM stored_object;
SELECT COUNT(*) AS communication_message_rows FROM communication_message;
SELECT (SELECT COUNT(*) FROM document_template) AS document_template_rows,
       (SELECT COUNT(*) FROM generated_document) AS generated_document_rows;
