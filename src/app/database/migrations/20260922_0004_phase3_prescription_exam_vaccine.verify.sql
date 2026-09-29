-- READ-ONLY verification for 20260922_0004_phase3_prescription_exam_vaccine.
-- Safe to run only after the migration itself has been explicitly authorized.

SELECT version, checksum, applied_at
FROM schema_migrations
WHERE version = '20260922_0004_phase3_prescription_exam_vaccine';

SELECT table_name, table_rows
FROM information_schema.tables
WHERE table_schema = DATABASE()
  AND table_name IN (
      'prescription', 'prescription_item', 'exam_catalog_item',
      'exam_request', 'exam_result', 'vaccine_catalog_item',
      'vaccine_protocol', 'vaccination'
  )
ORDER BY table_name;

SELECT table_name, column_name, is_nullable, column_type
FROM information_schema.columns
WHERE table_schema = DATABASE()
  AND table_name IN (
      'prescription', 'prescription_item', 'exam_catalog_item',
      'exam_request', 'exam_result', 'vaccine_catalog_item',
      'vaccine_protocol', 'vaccination'
  )
ORDER BY table_name, ordinal_position;

SELECT (SELECT COUNT(*) FROM prescription) AS prescription_rows,
       (SELECT COUNT(*) FROM prescription_item) AS prescription_item_rows,
       (SELECT COUNT(*) FROM exam_catalog_item) AS exam_catalog_item_rows,
       (SELECT COUNT(*) FROM exam_request) AS exam_request_rows,
       (SELECT COUNT(*) FROM exam_result) AS exam_result_rows,
       (SELECT COUNT(*) FROM vaccine_catalog_item) AS vaccine_catalog_item_rows,
       (SELECT COUNT(*) FROM vaccine_protocol) AS vaccine_protocol_rows,
       (SELECT COUNT(*) FROM vaccination) AS vaccination_rows;

SELECT 'prescription_without_tenant' AS check_name, COUNT(*) AS violations
FROM prescription pr LEFT JOIN tenant tn ON tn.id = pr.tenant_id WHERE tn.id IS NULL
UNION ALL
SELECT 'prescription_encounter_cross_tenant', COUNT(*)
FROM prescription pr JOIN encounter e ON e.id = pr.encounter_id WHERE e.tenant_id <> pr.tenant_id
UNION ALL
SELECT 'prescription_patient_cross_tenant', COUNT(*)
FROM prescription pr JOIN patient p ON p.id = pr.patient_id WHERE p.tenant_id <> pr.tenant_id
UNION ALL
SELECT 'prescription_invalid_status', COUNT(*)
FROM prescription WHERE status NOT IN ('draft', 'issued')
UNION ALL
SELECT 'prescription_item_without_tenant', COUNT(*)
FROM prescription_item pi LEFT JOIN tenant tn ON tn.id = pi.tenant_id WHERE tn.id IS NULL
UNION ALL
SELECT 'prescription_item_prescription_cross_tenant', COUNT(*)
FROM prescription_item pi JOIN prescription pr ON pr.id = pi.prescription_id WHERE pr.tenant_id <> pi.tenant_id
UNION ALL
SELECT 'exam_catalog_item_without_tenant', COUNT(*)
FROM exam_catalog_item ec LEFT JOIN tenant tn ON tn.id = ec.tenant_id WHERE tn.id IS NULL
UNION ALL
SELECT 'exam_request_without_tenant', COUNT(*)
FROM exam_request er LEFT JOIN tenant tn ON tn.id = er.tenant_id WHERE tn.id IS NULL
UNION ALL
SELECT 'exam_request_encounter_cross_tenant', COUNT(*)
FROM exam_request er JOIN encounter e ON e.id = er.encounter_id WHERE e.tenant_id <> er.tenant_id
UNION ALL
SELECT 'exam_request_patient_cross_tenant', COUNT(*)
FROM exam_request er JOIN patient p ON p.id = er.patient_id WHERE p.tenant_id <> er.tenant_id
UNION ALL
SELECT 'exam_request_catalog_item_cross_tenant', COUNT(*)
FROM exam_request er JOIN exam_catalog_item ec ON ec.id = er.exam_catalog_item_id WHERE ec.tenant_id <> er.tenant_id
UNION ALL
SELECT 'exam_request_invalid_status', COUNT(*)
FROM exam_request WHERE status NOT IN ('requested', 'result_available')
UNION ALL
SELECT 'exam_result_without_tenant', COUNT(*)
FROM exam_result res LEFT JOIN tenant tn ON tn.id = res.tenant_id WHERE tn.id IS NULL
UNION ALL
SELECT 'exam_result_request_cross_tenant', COUNT(*)
FROM exam_result res JOIN exam_request er ON er.id = res.exam_request_id WHERE er.tenant_id <> res.tenant_id
UNION ALL
SELECT 'vaccine_catalog_item_without_tenant', COUNT(*)
FROM vaccine_catalog_item vc LEFT JOIN tenant tn ON tn.id = vc.tenant_id WHERE tn.id IS NULL
UNION ALL
SELECT 'vaccine_protocol_without_tenant', COUNT(*)
FROM vaccine_protocol vp LEFT JOIN tenant tn ON tn.id = vp.tenant_id WHERE tn.id IS NULL
UNION ALL
SELECT 'vaccine_protocol_item_cross_tenant', COUNT(*)
FROM vaccine_protocol vp JOIN vaccine_catalog_item vc ON vc.id = vp.vaccine_catalog_item_id WHERE vc.tenant_id <> vp.tenant_id
UNION ALL
SELECT 'vaccination_without_tenant', COUNT(*)
FROM vaccination va LEFT JOIN tenant tn ON tn.id = va.tenant_id WHERE tn.id IS NULL
UNION ALL
SELECT 'vaccination_encounter_cross_tenant', COUNT(*)
FROM vaccination va JOIN encounter e ON e.id = va.encounter_id WHERE e.tenant_id <> va.tenant_id
UNION ALL
SELECT 'vaccination_patient_cross_tenant', COUNT(*)
FROM vaccination va JOIN patient p ON p.id = va.patient_id WHERE p.tenant_id <> va.tenant_id
UNION ALL
SELECT 'vaccination_item_cross_tenant', COUNT(*)
FROM vaccination va JOIN vaccine_catalog_item vc ON vc.id = va.vaccine_catalog_item_id WHERE vc.tenant_id <> va.tenant_id;
