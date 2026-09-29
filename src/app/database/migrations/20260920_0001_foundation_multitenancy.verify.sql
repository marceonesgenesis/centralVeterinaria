-- READ-ONLY verification for 20260920_0001_foundation_multitenancy.
-- Safe to run only after the migration itself has been explicitly authorized.

SELECT version, checksum, applied_at
FROM schema_migrations
WHERE version = '20260920_0001_foundation_multitenancy';

SELECT t.id, t.slug,
       (SELECT COUNT(*) FROM system_unit u WHERE u.tenant_id = t.id) AS units,
       (SELECT COUNT(*) FROM tenant_user tu WHERE tu.tenant_id = t.id) AS users,
       (SELECT COUNT(*) FROM tenant_group tg WHERE tg.tenant_id = t.id) AS `groups`,
       (SELECT COUNT(*) FROM tenant_role tr WHERE tr.tenant_id = t.id) AS roles
FROM tenant t
WHERE t.id = 1;

SELECT 'unit_without_tenant' AS check_name, COUNT(*) AS violations
FROM system_unit WHERE tenant_id IS NULL
UNION ALL
SELECT 'unit_unknown_tenant', COUNT(*)
FROM system_unit u LEFT JOIN tenant t ON t.id = u.tenant_id WHERE t.id IS NULL
UNION ALL
SELECT 'user_without_membership', COUNT(*)
FROM system_users u LEFT JOIN tenant_user tu ON tu.system_user_id = u.id WHERE tu.id IS NULL
UNION ALL
SELECT 'group_without_membership', COUNT(*)
FROM system_group g LEFT JOIN tenant_group tg ON tg.system_group_id = g.id WHERE tg.id IS NULL
UNION ALL
SELECT 'role_without_membership', COUNT(*)
FROM system_role r LEFT JOIN tenant_role tr ON tr.system_role_id = r.id WHERE tr.id IS NULL;

SELECT table_name, column_name, is_nullable, column_type
FROM information_schema.columns
WHERE table_schema = DATABASE()
  AND ((table_name = 'system_unit' AND column_name = 'tenant_id')
       OR table_name IN ('schema_migrations', 'tenant', 'tenant_user', 'tenant_group',
                         'tenant_role', 'audit_log', 'stored_object'))
ORDER BY table_name, ordinal_position;
