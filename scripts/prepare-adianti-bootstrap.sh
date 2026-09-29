#!/usr/bin/env bash

set -euo pipefail

ROOT_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
SOURCE_SQL="$ROOT_DIR/src/app/database/permission.sql"
OUTPUT_DIR="$ROOT_DIR/var/sql-bootstrap"
OUTPUT_SQL="$OUTPUT_DIR/permission.sanitized.sql"
PERMISSION_MYSQL_SQL="$OUTPUT_DIR/01-permission.mysql.sql"
COMMUNICATION_MYSQL_SQL="$OUTPUT_DIR/02-communication.mysql.sql"
LOG_MYSQL_SQL="$OUTPUT_DIR/03-log.mysql.sql"
MANIFEST="$OUTPUT_DIR/manifest.txt"
ENV_FILE="$ROOT_DIR/.env"

umask 077
mkdir -p "$OUTPUT_DIR"

if [[ -f "$MANIFEST" && "${FORCE_PREPARE:-0}" != '1' ]]; then
    echo 'Bootstrap already prepared; refusing to rotate the local administrator credential' >&2
    exit 1
fi

original_seed_count=$(grep -Eic '^INSERT INTO ' "$SOURCE_SQL")
if [[ "$original_seed_count" -ne 145 ]]; then
    echo "Unexpected permission.sql seed count: $original_seed_count" >&2
    exit 1
fi

admin_password=$(openssl rand -base64 48 | tr -d '\n')
admin_hash=$(ADMIN_PASSWORD="$admin_password" php -r '
    $password = getenv("ADMIN_PASSWORD");
    $hash = password_hash($password, PASSWORD_BCRYPT);
    if (!password_verify($password, $hash)) { exit(1); }
    echo $hash;
')

temp_sql=$(mktemp "$OUTPUT_DIR/permission.XXXXXX")
ADMIN_HASH="$admin_hash" perl -00 -ne '
    next if /INSERT INTO system_users/ && /\n\s*\x27user\x27,/;
    s/^.*system_user_group.*login=\x27user\x27.*\n//mg;
    s/^.*system_user_unit.*login=\x27user\x27.*\n//mg;
    s/\$2y\$10\$xuR3XEc3J6tpv7myC9gPj\.Ab5GacSeHSZoYUTYtOg\.cEc22G\.iBwa/$ENV{ADMIN_HASH}/g;
    print;
' "$SOURCE_SQL" > "$temp_sql"

sanitized_seed_count=$(grep -Eic '^INSERT INTO ' "$temp_sql")
if [[ "$sanitized_seed_count" -ne 141 ]]; then
    echo "Unexpected sanitized seed count: $sanitized_seed_count" >&2
    exit 1
fi

if grep -Eq "login='user'|^[[:space:]]*'user',[[:space:]]*$|MUYN29LOSHrCSGhr" "$temp_sql"; then
    echo 'Demo user or association remains in sanitized SQL' >&2
    exit 1
fi

if grep -q 'xuR3XEc3J6tpv7my' "$temp_sql"; then
    echo 'Default administrator hash remains in sanitized SQL' >&2
    exit 1
fi

mv "$temp_sql" "$OUTPUT_SQL"
chmod 600 "$OUTPUT_SQL"

# The template uses SQLite-style `---` headings, which are invalid in MySQL.
# Normalize comments only; the executable SQL remains byte-for-byte equivalent.
sed 's/^---/-- /' "$OUTPUT_SQL" > "$PERMISSION_MYSQL_SQL"
sed 's/^---/-- /' "$ROOT_DIR/src/app/database/communication.sql" > "$COMMUNICATION_MYSQL_SQL"
sed 's/^---/-- /' "$ROOT_DIR/src/app/database/log.sql" > "$LOG_MYSQL_SQL"
chmod 600 "$PERMISSION_MYSQL_SQL" "$COMMUNICATION_MYSQL_SQL" "$LOG_MYSQL_SQL"

temp_env=$(mktemp "$ROOT_DIR/.env.XXXXXX")
awk '!/^CENTRALVET_ADMIN_PASSWORD=/' "$ENV_FILE" > "$temp_env"
printf 'CENTRALVET_ADMIN_PASSWORD=%s\n' "$admin_password" >> "$temp_env"
mv "$temp_env" "$ENV_FILE"
chmod 600 "$ENV_FILE"

{
    printf 'generated_at_utc=%s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
    printf 'source=%s\n' 'src/app/database/permission.sql'
    printf 'source_sha256=%s\n' "$(sha256sum "$SOURCE_SQL" | cut -d' ' -f1)"
    printf 'sanitized_sha256=%s\n' "$(sha256sum "$OUTPUT_SQL" | cut -d' ' -f1)"
    printf 'permission_mysql_sha256=%s\n' "$(sha256sum "$PERMISSION_MYSQL_SQL" | cut -d' ' -f1)"
    printf 'communication_mysql_sha256=%s\n' "$(sha256sum "$COMMUNICATION_MYSQL_SQL" | cut -d' ' -f1)"
    printf 'log_mysql_sha256=%s\n' "$(sha256sum "$LOG_MYSQL_SQL" | cut -d' ' -f1)"
    printf 'source_seed_count=%s\n' "$original_seed_count"
    printf 'sanitized_seed_count=%s\n' "$sanitized_seed_count"
    printf 'removed_demo_seed_rows=4\n'
    printf 'admin_password_storage=.env:CENTRALVET_ADMIN_PASSWORD\n'
} > "$MANIFEST"
chmod 600 "$MANIFEST"

unset admin_password admin_hash ADMIN_PASSWORD
echo 'Sanitized bootstrap prepared without printing credentials'
