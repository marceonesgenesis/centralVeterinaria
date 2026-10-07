-- READ-ONLY verification for 20261006_0012_phase7a_communication.
-- Safe to run only after the migration itself has been explicitly authorized.

SELECT version, checksum, applied_at
FROM schema_migrations
WHERE version = '20261006_0012_phase7a_communication';

-- Tables (expected: 4 rows)
SELECT table_name, table_rows
FROM information_schema.tables
WHERE table_schema = DATABASE()
  AND table_name IN (
      'communication_preference', 'message_template',
      'communication_message', 'appointment_followup'
  )
ORDER BY table_name;

SELECT table_name, column_name, is_nullable, column_type, column_default, extra
FROM information_schema.columns
WHERE table_schema = DATABASE()
  AND table_name IN (
      'communication_preference', 'message_template',
      'communication_message', 'appointment_followup'
  )
ORDER BY table_name, ordinal_position;

-- UNIQUE constraints (expected: 4 rows; communication_message_tenant_dedupe_uq is the
-- idempotency key of the automatic reminders)
SELECT table_name, constraint_name
FROM information_schema.table_constraints
WHERE table_schema = DATABASE()
  AND constraint_type = 'UNIQUE'
  AND table_name IN (
      'communication_preference', 'message_template',
      'communication_message', 'appointment_followup'
  )
  AND constraint_name IN (
      'communication_preference_tutor_channel_uq', 'message_template_tenant_name_uq',
      'communication_message_tenant_dedupe_uq', 'appointment_followup_appointment_uq'
  )
ORDER BY table_name, constraint_name;

-- Foreign keys of the new tables (expected: 18 rows)
SELECT table_name, constraint_name
FROM information_schema.table_constraints
WHERE table_schema = DATABASE()
  AND constraint_type = 'FOREIGN KEY'
  AND table_name IN (
      'communication_preference', 'message_template',
      'communication_message', 'appointment_followup'
  )
ORDER BY table_name, constraint_name;

-- Checks (expected: 18 rows)
SELECT tc.table_name, tc.constraint_name, cc.check_clause
FROM information_schema.table_constraints tc
JOIN information_schema.check_constraints cc
  ON cc.constraint_schema = tc.constraint_schema
 AND cc.constraint_name = tc.constraint_name
WHERE tc.table_schema = DATABASE()
  AND tc.table_name IN (
      'communication_preference', 'message_template', 'communication_message'
  )
  AND tc.constraint_type = 'CHECK'
  AND tc.constraint_name IN (
      'communication_preference_channel_ck', 'communication_preference_status_ck',
      'communication_preference_source_ck',
      'message_template_purpose_ck', 'message_template_channel_ck',
      'message_template_status_ck', 'message_template_subject_ck',
      'communication_message_status_ck', 'communication_message_channel_ck',
      'communication_message_origin_ck', 'communication_message_purpose_ck',
      'communication_message_source_ck', 'communication_message_legal_basis_ck',
      'communication_message_sent_ck', 'communication_message_manual_ck',
      'communication_message_failed_ck', 'communication_message_cancelled_ck',
      'communication_message_subject_ck'
  )
ORDER BY tc.table_name, tc.constraint_name;

-- New indexes on existing tables (expected: 4 rows: vaccination_tenant_next_dose_idx
-- (tenant_id, next_dose_at) and receivable_tenant_status_idx (tenant_id, status))
SELECT table_name, index_name, seq_in_index, column_name
FROM information_schema.statistics
WHERE table_schema = DATABASE()
  AND table_name IN ('vaccination', 'receivable')
  AND index_name IN ('vaccination_tenant_next_dose_idx', 'receivable_tenant_status_idx')
ORDER BY table_name, index_name, seq_in_index;

-- Row counts (expected: vaccination, receivable and appointment equal to the counts
-- recorded before the migration; the 4 new tables empty)
SELECT COUNT(*) AS vaccination_rows FROM vaccination;
SELECT COUNT(*) AS receivable_rows FROM receivable;
SELECT COUNT(*) AS appointment_rows FROM appointment;
SELECT (SELECT COUNT(*) FROM communication_preference) AS communication_preference_rows,
       (SELECT COUNT(*) FROM message_template) AS message_template_rows,
       (SELECT COUNT(*) FROM communication_message) AS communication_message_rows,
       (SELECT COUNT(*) FROM appointment_followup) AS appointment_followup_rows;
