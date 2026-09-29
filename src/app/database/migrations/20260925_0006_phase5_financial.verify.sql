-- READ-ONLY verification for 20260925_0006_phase5_financial.
-- Safe to run only after the migration itself has been explicitly authorized.

SELECT version, checksum, applied_at
FROM schema_migrations
WHERE version = '20260925_0006_phase5_financial';

SELECT table_name, table_rows
FROM information_schema.tables
WHERE table_schema = DATABASE()
  AND table_name IN (
      'encounter_account', 'encounter_account_item', 'receivable', 'cash_session',
      'payment', 'payable', 'financial_entry'
  )
ORDER BY table_name;

SELECT table_name, column_name, is_nullable, column_type
FROM information_schema.columns
WHERE table_schema = DATABASE()
  AND table_name IN (
      'encounter_account', 'encounter_account_item', 'receivable', 'cash_session',
      'payment', 'payable', 'financial_entry'
  )
ORDER BY table_name, ordinal_position;

SELECT (SELECT COUNT(*) FROM encounter_account) AS encounter_account_rows,
       (SELECT COUNT(*) FROM encounter_account_item) AS encounter_account_item_rows,
       (SELECT COUNT(*) FROM receivable) AS receivable_rows,
       (SELECT COUNT(*) FROM cash_session) AS cash_session_rows,
       (SELECT COUNT(*) FROM payment) AS payment_rows,
       (SELECT COUNT(*) FROM payable) AS payable_rows,
       (SELECT COUNT(*) FROM financial_entry) AS financial_entry_rows;

SELECT 'encounter_account_without_tenant' AS check_name, COUNT(*) AS violations
FROM encounter_account ea LEFT JOIN tenant tn ON tn.id = ea.tenant_id WHERE tn.id IS NULL
UNION ALL
SELECT 'encounter_account_encounter_cross_tenant', COUNT(*)
FROM encounter_account ea JOIN encounter e ON e.id = ea.encounter_id WHERE e.tenant_id <> ea.tenant_id
UNION ALL
SELECT 'encounter_account_patient_cross_tenant', COUNT(*)
FROM encounter_account ea JOIN patient p ON p.id = ea.patient_id WHERE p.tenant_id <> ea.tenant_id
UNION ALL
SELECT 'encounter_account_tutor_cross_tenant', COUNT(*)
FROM encounter_account ea JOIN tutor t ON t.id = ea.tutor_id WHERE t.tenant_id <> ea.tenant_id
UNION ALL
SELECT 'encounter_account_invalid_status', COUNT(*)
FROM encounter_account WHERE status NOT IN ('open', 'closed', 'cancelled')
UNION ALL
SELECT 'encounter_account_discount_exceeds_subtotal', COUNT(*)
FROM encounter_account WHERE discount_cents > subtotal_cents
UNION ALL
SELECT 'encounter_account_total_mismatch', COUNT(*)
FROM encounter_account WHERE total_cents <> subtotal_cents - discount_cents
UNION ALL
SELECT 'encounter_account_item_without_tenant', COUNT(*)
FROM encounter_account_item eai LEFT JOIN tenant tn ON tn.id = eai.tenant_id WHERE tn.id IS NULL
UNION ALL
SELECT 'encounter_account_item_account_cross_tenant', COUNT(*)
FROM encounter_account_item eai JOIN encounter_account ea ON ea.id = eai.account_id WHERE ea.tenant_id <> eai.tenant_id
UNION ALL
SELECT 'encounter_account_item_invalid_source_type', COUNT(*)
FROM encounter_account_item WHERE source_type NOT IN ('procedure_execution', 'exam_request', 'manual')
UNION ALL
SELECT 'receivable_without_tenant', COUNT(*)
FROM receivable r LEFT JOIN tenant tn ON tn.id = r.tenant_id WHERE tn.id IS NULL
UNION ALL
SELECT 'receivable_encounter_account_cross_tenant', COUNT(*)
FROM receivable r JOIN encounter_account ea ON ea.id = r.encounter_account_id WHERE ea.tenant_id <> r.tenant_id
UNION ALL
SELECT 'receivable_tutor_cross_tenant', COUNT(*)
FROM receivable r JOIN tutor t ON t.id = r.tutor_id WHERE t.tenant_id <> r.tenant_id
UNION ALL
SELECT 'receivable_invalid_status', COUNT(*)
FROM receivable WHERE status NOT IN ('open', 'partially_paid', 'paid', 'cancelled')
UNION ALL
SELECT 'receivable_paid_exceeds_total', COUNT(*)
FROM receivable WHERE paid_cents > total_cents
UNION ALL
SELECT 'cash_session_without_tenant', COUNT(*)
FROM cash_session cs LEFT JOIN tenant tn ON tn.id = cs.tenant_id WHERE tn.id IS NULL
UNION ALL
SELECT 'cash_session_invalid_status', COUNT(*)
FROM cash_session WHERE status NOT IN ('open', 'closed')
UNION ALL
SELECT 'payment_without_tenant', COUNT(*)
FROM payment pm LEFT JOIN tenant tn ON tn.id = pm.tenant_id WHERE tn.id IS NULL
UNION ALL
SELECT 'payment_receivable_cross_tenant', COUNT(*)
FROM payment pm JOIN receivable r ON r.id = pm.receivable_id WHERE r.tenant_id <> pm.tenant_id
UNION ALL
SELECT 'payment_cash_session_cross_tenant', COUNT(*)
FROM payment pm JOIN cash_session cs ON cs.id = pm.cash_session_id WHERE cs.tenant_id <> pm.tenant_id
UNION ALL
SELECT 'payment_invalid_method', COUNT(*)
FROM payment WHERE payment_method NOT IN ('cash', 'debit_card', 'credit_card', 'pix', 'bank_transfer')
UNION ALL
SELECT 'payment_invalid_amount', COUNT(*)
FROM payment WHERE amount_cents < 1
UNION ALL
SELECT 'payable_without_tenant', COUNT(*)
FROM payable pa LEFT JOIN tenant tn ON tn.id = pa.tenant_id WHERE tn.id IS NULL
UNION ALL
SELECT 'payable_invalid_status', COUNT(*)
FROM payable WHERE status NOT IN ('open', 'paid', 'cancelled')
UNION ALL
SELECT 'financial_entry_without_tenant', COUNT(*)
FROM financial_entry fe LEFT JOIN tenant tn ON tn.id = fe.tenant_id WHERE tn.id IS NULL
UNION ALL
SELECT 'financial_entry_invalid_type', COUNT(*)
FROM financial_entry WHERE entry_type NOT IN ('income', 'expense');
