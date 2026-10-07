-- Checks centralvet_test after scripts/test-db/provision.sh (T-05).
-- SELECT only; run with any user that can read both schemas, e.g.
--   docker compose exec -T mysql sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot -t' < scripts/test-db/verify.sql

-- 1. Tables of centralvet missing in centralvet_test (expected: no rows).
SELECT dev.TABLE_NAME AS missing_in_centralvet_test
FROM information_schema.TABLES dev
LEFT JOIN information_schema.TABLES test
       ON test.TABLE_SCHEMA = 'centralvet_test'
      AND test.TABLE_NAME = dev.TABLE_NAME
WHERE dev.TABLE_SCHEMA = 'centralvet'
  AND test.TABLE_NAME IS NULL
ORDER BY dev.TABLE_NAME;

-- 2. Seed rows the integration tests rely on.
SELECT
    (SELECT COUNT(*) FROM centralvet_test.system_users) AS system_users,
    (SELECT COUNT(*) FROM centralvet_test.system_unit) AS system_unit,
    (SELECT COUNT(*) FROM centralvet_test.tenant) AS tenant;

-- 3. Applied migrations (files with a zero checksum record zeros here).
SELECT version, checksum, applied_at
FROM centralvet_test.schema_migrations
ORDER BY version;
