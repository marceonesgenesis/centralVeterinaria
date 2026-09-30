-- READ-ONLY verification for 20260930_0007_rodada2_cadastros_financeiro.
-- Safe to run only after the migration itself has been explicitly authorized.
-- Only SELECT statements. Compare the row counts below with the ones taken before the
-- migration (they must be equal).

SELECT version, checksum, applied_at
FROM schema_migrations
WHERE version = '20260930_0007_rodada2_cadastros_financeiro';

-- 9 new columns (expected: 9 rows)
SELECT table_name, column_name, is_nullable, column_type, column_default
FROM information_schema.columns
WHERE table_schema = DATABASE()
  AND (
      (table_name = 'product' AND column_name IN ('sale_price_cents', 'code'))
   OR (table_name = 'patient' AND column_name IN ('allergies', 'photo_object_key', 'photo_content_type'))
   OR (table_name = 'prescription' AND column_name = 'valid_until')
   OR (table_name = 'financial_entry' AND column_name = 'payment_method')
   OR (table_name = 'encounter' AND column_name IN ('paused_at', 'paused_seconds'))
  )
ORDER BY table_name, ordinal_position;

-- 3 new tables (expected: 3 rows)
SELECT table_name, table_rows
FROM information_schema.tables
WHERE table_schema = DATABASE()
  AND table_name IN ('prescription_template', 'prescription_template_item', 'bank_account')
ORDER BY table_name;

SELECT table_name, column_name, is_nullable, column_type
FROM information_schema.columns
WHERE table_schema = DATABASE()
  AND table_name IN ('prescription_template', 'prescription_template_item', 'bank_account')
ORDER BY table_name, ordinal_position;

-- New constraints (expected: product_tenant_code_uq, prescription_template_tenant_name_uq,
-- bank_account_tenant_unit_name_uq, financial_entry_payment_method_ck and 6 FKs)
SELECT table_name, constraint_name, constraint_type
FROM information_schema.table_constraints
WHERE table_schema = DATABASE()
  AND constraint_name IN (
      'product_tenant_code_uq',
      'prescription_template_tenant_name_uq',
      'bank_account_tenant_unit_name_uq',
      'financial_entry_payment_method_ck',
      'prescription_template_tenant_fk',
      'prescription_template_created_by_fk',
      'prescription_template_item_tenant_fk',
      'prescription_template_item_template_fk',
      'bank_account_tenant_fk',
      'bank_account_unit_fk'
  )
ORDER BY table_name, constraint_name;

SELECT constraint_name, delete_rule
FROM information_schema.referential_constraints
WHERE constraint_schema = DATABASE()
  AND constraint_name = 'prescription_template_item_template_fk';

-- Existing rows preserved (must equal the counts taken before the migration)
SELECT (SELECT COUNT(*) FROM product) AS product_rows,
       (SELECT COUNT(*) FROM patient) AS patient_rows,
       (SELECT COUNT(*) FROM prescription) AS prescription_rows,
       (SELECT COUNT(*) FROM financial_entry) AS financial_entry_rows,
       (SELECT COUNT(*) FROM encounter) AS encounter_rows;

-- Backfill of payment_method on payment entries (expected: 0)
SELECT COUNT(*) AS payment_entries_without_method
FROM financial_entry
WHERE reference_type = 'payment'
  AND payment_method IS NULL
  AND category IN ('cash', 'debit_card', 'credit_card', 'pix', 'bank_transfer');

-- Integrity checks (expected: 0 violations each)
SELECT 'financial_entry_invalid_payment_method' AS check_name, COUNT(*) AS violations
FROM financial_entry
WHERE payment_method IS NOT NULL
  AND payment_method NOT IN ('cash', 'debit_card', 'credit_card', 'pix', 'bank_transfer')
UNION ALL
SELECT 'financial_entry_payment_method_differs_from_category', COUNT(*)
FROM financial_entry
WHERE payment_method IS NOT NULL AND payment_method <> category
UNION ALL
SELECT 'encounter_paused_seconds_null', COUNT(*)
FROM encounter WHERE paused_seconds IS NULL
UNION ALL
SELECT 'prescription_template_without_tenant', COUNT(*)
FROM prescription_template pt LEFT JOIN tenant tn ON tn.id = pt.tenant_id WHERE tn.id IS NULL
UNION ALL
SELECT 'prescription_template_item_template_cross_tenant', COUNT(*)
FROM prescription_template_item pti JOIN prescription_template pt ON pt.id = pti.template_id
WHERE pt.tenant_id <> pti.tenant_id
UNION ALL
SELECT 'bank_account_without_tenant', COUNT(*)
FROM bank_account ba LEFT JOIN tenant tn ON tn.id = ba.tenant_id WHERE tn.id IS NULL
UNION ALL
SELECT 'bank_account_unit_cross_tenant', COUNT(*)
FROM bank_account ba JOIN system_unit su ON su.id = ba.system_unit_id WHERE su.tenant_id <> ba.tenant_id;
