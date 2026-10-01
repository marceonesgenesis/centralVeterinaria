#!/usr/bin/env sh
# Creates the MySQL test database centralvet_test from the files on disk
# (T-05, rodada 3). Run ONCE, from /var/www/html/centralvet, only with a
# specific SQL approval from the user (see docs/runbooks/tests.md
# "Banco MySQL de teste"). It never touches the application database.
#
#   step 1 (root of the mysql container): CREATE DATABASE + GRANTs on
#          centralvet_test.* to MIGRATION_DB_USER and MYSQL_USER;
#   step 2 (migration user): Adianti base, missing Adianti FKs and the
#          migrations 0001..0008, in order, stopping at the first error.
#
# Refuses (exit 1) when centralvet_test already exists.
set -eu

test_database=centralvet_test
project_root=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
cd "$project_root"

# Reads KEY=value from .env without sourcing it (values are not shell code).
env_value() {
    value=$(grep -E "^$1=" .env 2>/dev/null | tail -n 1 | cut -d= -f2-) || value=''
    value=${value#\"}
    value=${value%\"}
    printf '%s' "$value"
}

if [ ! -f .env ]; then
    echo "provision: .env not found in $project_root" >&2
    exit 1
fi

migration_user=$(env_value MIGRATION_DB_USER)
migration_password=$(env_value MIGRATION_DB_PASSWORD)
runtime_user=$(env_value MYSQL_USER)
runtime_user=${runtime_user:-centralvet}

if [ -z "$migration_user" ] || [ -z "$migration_password" ]; then
    echo "provision: MIGRATION_DB_USER/MIGRATION_DB_PASSWORD missing in .env" >&2
    exit 1
fi

root_sql() {
    docker compose exec -T mysql sh -ceu 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -uroot --batch --skip-column-names'
}

exists=$(printf "SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = '%s';\n" "$test_database" | root_sql)

if [ "$exists" != "0" ]; then
    echo "provision: refusing to run, database $test_database already exists" >&2
    exit 1
fi

# Step 1: database and grants (root, once).
echo "provision: step 1 - CREATE DATABASE $test_database and GRANTs"
root_sql <<SQL
CREATE DATABASE \`$test_database\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON \`$test_database\`.* TO '$migration_user'@'%';
GRANT ALL PRIVILEGES ON \`$test_database\`.* TO '$runtime_user'@'%';
SQL

# Step 2: schema, same order as the development database, without the
# *.verify.sql files.
database_dir=src/app/database
migrations_dir=$database_dir/migrations

for file in \
    "$database_dir/permission.sql" \
    "$database_dir/communication.sql" \
    "$database_dir/log.sql" \
    "$migrations_dir/20260919_add_missing_adianti_foreign_keys.sql" \
    "$migrations_dir/20260920_0001_foundation_multitenancy.sql" \
    "$migrations_dir/20260921_0002_phase1_clinic_core.sql" \
    "$migrations_dir/20260922_0003_phase2_encounter.sql" \
    "$migrations_dir/20260922_0004_phase3_prescription_exam_vaccine.sql" \
    "$migrations_dir/20260924_0005_phase4_procedure_stock_sale.sql" \
    "$migrations_dir/20260925_0006_phase5_financial.sql" \
    "$migrations_dir/20260930_0007_rodada2_cadastros_financeiro.sql" \
    "$migrations_dir/20260930_0008_queue_entry_appointment_unique.sql"
do
    if [ ! -f "$file" ]; then
        echo "provision: missing $file" >&2
        exit 1
    fi

    echo "provision: step 2 - $file"
    docker compose exec -T -e MYSQL_PWD="$migration_password" mysql \
        mysql -u"$migration_user" --default-character-set=utf8mb4 "$test_database" < "$file"
done

echo "provision: done; now run scripts/test-db/verify.sql (SELECT only)"
