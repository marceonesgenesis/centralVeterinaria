-- READ-ONLY verification for 20260924_0005_phase4_procedure_stock_sale.
-- Safe to run only after the migration itself has been explicitly authorized.

SELECT version, checksum, applied_at
FROM schema_migrations
WHERE version = '20260924_0005_phase4_procedure_stock_sale';

SELECT table_name, table_rows
FROM information_schema.tables
WHERE table_schema = DATABASE()
  AND table_name IN (
      'product', 'stock_batch', 'stock_movement', 'procedure_catalog_item',
      'procedure_catalog_item_input', 'procedure_execution', 'sale', 'sale_item'
  )
ORDER BY table_name;

SELECT table_name, column_name, is_nullable, column_type
FROM information_schema.columns
WHERE table_schema = DATABASE()
  AND table_name IN (
      'product', 'stock_batch', 'stock_movement', 'procedure_catalog_item',
      'procedure_catalog_item_input', 'procedure_execution', 'sale', 'sale_item'
  )
ORDER BY table_name, ordinal_position;

SELECT (SELECT COUNT(*) FROM product) AS product_rows,
       (SELECT COUNT(*) FROM stock_batch) AS stock_batch_rows,
       (SELECT COUNT(*) FROM stock_movement) AS stock_movement_rows,
       (SELECT COUNT(*) FROM procedure_catalog_item) AS procedure_catalog_item_rows,
       (SELECT COUNT(*) FROM procedure_catalog_item_input) AS procedure_catalog_item_input_rows,
       (SELECT COUNT(*) FROM procedure_execution) AS procedure_execution_rows,
       (SELECT COUNT(*) FROM sale) AS sale_rows,
       (SELECT COUNT(*) FROM sale_item) AS sale_item_rows;

SELECT 'product_without_tenant' AS check_name, COUNT(*) AS violations
FROM product pr LEFT JOIN tenant tn ON tn.id = pr.tenant_id WHERE tn.id IS NULL
UNION ALL
SELECT 'stock_batch_without_tenant', COUNT(*)
FROM stock_batch sb LEFT JOIN tenant tn ON tn.id = sb.tenant_id WHERE tn.id IS NULL
UNION ALL
SELECT 'stock_batch_product_cross_tenant', COUNT(*)
FROM stock_batch sb JOIN product pr ON pr.id = sb.product_id WHERE pr.tenant_id <> sb.tenant_id
UNION ALL
SELECT 'stock_movement_without_tenant', COUNT(*)
FROM stock_movement sm LEFT JOIN tenant tn ON tn.id = sm.tenant_id WHERE tn.id IS NULL
UNION ALL
SELECT 'stock_movement_product_cross_tenant', COUNT(*)
FROM stock_movement sm JOIN product pr ON pr.id = sm.product_id WHERE pr.tenant_id <> sm.tenant_id
UNION ALL
SELECT 'stock_movement_batch_cross_tenant', COUNT(*)
FROM stock_movement sm JOIN stock_batch sb ON sb.id = sm.stock_batch_id WHERE sb.tenant_id <> sm.tenant_id
UNION ALL
SELECT 'stock_movement_invalid_type', COUNT(*)
FROM stock_movement WHERE movement_type NOT IN ('in', 'out', 'adjustment')
UNION ALL
SELECT 'stock_movement_invalid_reason', COUNT(*)
FROM stock_movement WHERE reason NOT IN ('purchase_entry', 'procedure_consumption', 'sale_consumption', 'manual_adjustment')
UNION ALL
SELECT 'procedure_catalog_item_without_tenant', COUNT(*)
FROM procedure_catalog_item pc LEFT JOIN tenant tn ON tn.id = pc.tenant_id WHERE tn.id IS NULL
UNION ALL
SELECT 'procedure_catalog_item_input_without_tenant', COUNT(*)
FROM procedure_catalog_item_input pi LEFT JOIN tenant tn ON tn.id = pi.tenant_id WHERE tn.id IS NULL
UNION ALL
SELECT 'procedure_catalog_item_input_item_cross_tenant', COUNT(*)
FROM procedure_catalog_item_input pi JOIN procedure_catalog_item pc ON pc.id = pi.procedure_catalog_item_id WHERE pc.tenant_id <> pi.tenant_id
UNION ALL
SELECT 'procedure_catalog_item_input_product_cross_tenant', COUNT(*)
FROM procedure_catalog_item_input pi JOIN product pr ON pr.id = pi.product_id WHERE pr.tenant_id <> pi.tenant_id
UNION ALL
SELECT 'procedure_catalog_item_input_invalid_quantity', COUNT(*)
FROM procedure_catalog_item_input WHERE quantity_per_execution < 1
UNION ALL
SELECT 'procedure_execution_without_tenant', COUNT(*)
FROM procedure_execution pe LEFT JOIN tenant tn ON tn.id = pe.tenant_id WHERE tn.id IS NULL
UNION ALL
SELECT 'procedure_execution_encounter_cross_tenant', COUNT(*)
FROM procedure_execution pe JOIN encounter e ON e.id = pe.encounter_id WHERE e.tenant_id <> pe.tenant_id
UNION ALL
SELECT 'procedure_execution_patient_cross_tenant', COUNT(*)
FROM procedure_execution pe JOIN patient p ON p.id = pe.patient_id WHERE p.tenant_id <> pe.tenant_id
UNION ALL
SELECT 'procedure_execution_item_cross_tenant', COUNT(*)
FROM procedure_execution pe JOIN procedure_catalog_item pc ON pc.id = pe.procedure_catalog_item_id WHERE pc.tenant_id <> pe.tenant_id
UNION ALL
SELECT 'sale_without_tenant', COUNT(*)
FROM sale sa LEFT JOIN tenant tn ON tn.id = sa.tenant_id WHERE tn.id IS NULL
UNION ALL
SELECT 'sale_tutor_cross_tenant', COUNT(*)
FROM sale sa JOIN tutor t ON t.id = sa.tutor_id WHERE t.tenant_id <> sa.tenant_id
UNION ALL
SELECT 'sale_patient_cross_tenant', COUNT(*)
FROM sale sa JOIN patient p ON p.id = sa.patient_id WHERE sa.patient_id IS NOT NULL AND p.tenant_id <> sa.tenant_id
UNION ALL
SELECT 'sale_encounter_cross_tenant', COUNT(*)
FROM sale sa JOIN encounter e ON e.id = sa.encounter_id WHERE sa.encounter_id IS NOT NULL AND e.tenant_id <> sa.tenant_id
UNION ALL
SELECT 'sale_invalid_status', COUNT(*)
FROM sale WHERE status NOT IN ('completed', 'cancelled')
UNION ALL
SELECT 'sale_item_without_tenant', COUNT(*)
FROM sale_item si LEFT JOIN tenant tn ON tn.id = si.tenant_id WHERE tn.id IS NULL
UNION ALL
SELECT 'sale_item_sale_cross_tenant', COUNT(*)
FROM sale_item si JOIN sale sa ON sa.id = si.sale_id WHERE sa.tenant_id <> si.tenant_id
UNION ALL
SELECT 'sale_item_invalid_type', COUNT(*)
FROM sale_item WHERE item_type NOT IN ('product', 'procedure')
UNION ALL
SELECT 'sale_item_invalid_quantity', COUNT(*)
FROM sale_item WHERE quantity < 1;
