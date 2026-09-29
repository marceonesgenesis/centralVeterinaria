-- READ-ONLY verification for 20260922_0003_phase2_encounter.
-- Safe to run only after the migration itself has been explicitly authorized.

SELECT version, checksum, applied_at
FROM schema_migrations
WHERE version = '20260922_0003_phase2_encounter';

SELECT table_name, table_rows
FROM information_schema.tables
WHERE table_schema = DATABASE()
  AND table_name = 'encounter';

SELECT table_name, column_name, is_nullable, column_type
FROM information_schema.columns
WHERE table_schema = DATABASE()
  AND table_name = 'encounter'
ORDER BY ordinal_position;

SELECT (SELECT COUNT(*) FROM encounter) AS encounter_rows;

SELECT 'encounter_without_tenant' AS check_name, COUNT(*) AS violations
FROM encounter e LEFT JOIN tenant tn ON tn.id = e.tenant_id WHERE tn.id IS NULL
UNION ALL
SELECT 'encounter_patient_cross_tenant', COUNT(*)
FROM encounter e JOIN patient p ON p.id = e.patient_id WHERE p.tenant_id <> e.tenant_id
UNION ALL
SELECT 'encounter_unit_cross_tenant', COUNT(*)
FROM encounter e JOIN system_unit u ON u.id = e.system_unit_id WHERE u.tenant_id <> e.tenant_id
UNION ALL
SELECT 'encounter_appointment_cross_tenant', COUNT(*)
FROM encounter e JOIN appointment a ON a.id = e.appointment_id WHERE a.tenant_id <> e.tenant_id
UNION ALL
SELECT 'encounter_invalid_status', COUNT(*)
FROM encounter
WHERE status NOT IN ('in_progress', 'finished')
UNION ALL
SELECT 'encounter_finished_without_finished_at', COUNT(*)
FROM encounter
WHERE status = 'finished' AND finished_at IS NULL;
