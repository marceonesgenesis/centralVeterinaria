-- READ-ONLY verification for 20260921_0002_phase1_clinic_core.
-- Safe to run only after the migration itself has been explicitly authorized.

SELECT version, checksum, applied_at
FROM schema_migrations
WHERE version = '20260921_0002_phase1_clinic_core';

SELECT table_name, table_rows
FROM information_schema.tables
WHERE table_schema = DATABASE()
  AND table_name IN ('tutor', 'patient', 'service', 'appointment', 'queue_entry')
ORDER BY table_name;

SELECT table_name, column_name, is_nullable, column_type
FROM information_schema.columns
WHERE table_schema = DATABASE()
  AND table_name IN ('tutor', 'patient', 'service', 'appointment', 'queue_entry')
ORDER BY table_name, ordinal_position;

SELECT (SELECT COUNT(*) FROM tutor) AS tutor_rows,
       (SELECT COUNT(*) FROM patient) AS patient_rows,
       (SELECT COUNT(*) FROM service) AS service_rows,
       (SELECT COUNT(*) FROM appointment) AS appointment_rows,
       (SELECT COUNT(*) FROM queue_entry) AS queue_entry_rows;

SELECT 'tutor_without_tenant' AS check_name, COUNT(*) AS violations
FROM tutor t LEFT JOIN tenant tn ON tn.id = t.tenant_id WHERE tn.id IS NULL
UNION ALL
SELECT 'patient_without_tenant', COUNT(*)
FROM patient p LEFT JOIN tenant tn ON tn.id = p.tenant_id WHERE tn.id IS NULL
UNION ALL
SELECT 'patient_tutor_cross_tenant', COUNT(*)
FROM patient p JOIN tutor t ON t.id = p.tutor_id WHERE t.tenant_id <> p.tenant_id
UNION ALL
SELECT 'service_without_tenant', COUNT(*)
FROM service s LEFT JOIN tenant tn ON tn.id = s.tenant_id WHERE tn.id IS NULL
UNION ALL
SELECT 'appointment_without_tenant', COUNT(*)
FROM appointment a LEFT JOIN tenant tn ON tn.id = a.tenant_id WHERE tn.id IS NULL
UNION ALL
SELECT 'appointment_patient_cross_tenant', COUNT(*)
FROM appointment a JOIN patient p ON p.id = a.patient_id WHERE p.tenant_id <> a.tenant_id
UNION ALL
SELECT 'appointment_service_cross_tenant', COUNT(*)
FROM appointment a JOIN service s ON s.id = a.service_id WHERE s.tenant_id <> a.tenant_id
UNION ALL
SELECT 'appointment_unit_cross_tenant', COUNT(*)
FROM appointment a JOIN system_unit u ON u.id = a.system_unit_id WHERE u.tenant_id <> a.tenant_id
UNION ALL
SELECT 'appointment_invalid_status', COUNT(*)
FROM appointment
WHERE status NOT IN ('agendado', 'confirmado', 'em_atendimento', 'atendido', 'cancelado', 'faltou')
UNION ALL
SELECT 'queue_entry_without_tenant', COUNT(*)
FROM queue_entry q LEFT JOIN tenant tn ON tn.id = q.tenant_id WHERE tn.id IS NULL
UNION ALL
SELECT 'queue_entry_patient_cross_tenant', COUNT(*)
FROM queue_entry q JOIN patient p ON p.id = q.patient_id WHERE p.tenant_id <> q.tenant_id
UNION ALL
SELECT 'queue_entry_appointment_cross_tenant', COUNT(*)
FROM queue_entry q JOIN appointment a ON a.id = q.appointment_id WHERE a.tenant_id <> q.tenant_id
UNION ALL
SELECT 'queue_entry_unit_cross_tenant', COUNT(*)
FROM queue_entry q JOIN system_unit u ON u.id = q.system_unit_id WHERE u.tenant_id <> q.tenant_id
UNION ALL
SELECT 'queue_entry_invalid_status', COUNT(*)
FROM queue_entry
WHERE status NOT IN ('aguardando', 'em_atendimento', 'atendido', 'atrasado');
