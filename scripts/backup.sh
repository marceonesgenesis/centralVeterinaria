#!/usr/bin/env sh
set -eu

project_root=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
backup_dir="${BACKUP_DIR:-$project_root/var/backups}"
timestamp=$(date -u +%Y%m%dT%H%M%SZ)
backup_file="$backup_dir/centralvet-$timestamp.sql.gz"

mkdir -p "$backup_dir"
umask 077
temporary_sql=$(mktemp "$backup_dir/.centralvet-$timestamp.XXXXXX.sql")
temporary_gzip="$temporary_sql.gz"

cleanup() {
    rm -f "$temporary_sql" "$temporary_gzip"
}
trap cleanup EXIT HUP INT TERM

cd "$project_root"
docker compose exec -T mysql sh -ceu \
    'exec mysqldump --single-transaction --quick --routines --triggers -u root -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"' \
    > "$temporary_sql"

test -s "$temporary_sql"
gzip -9 -c "$temporary_sql" > "$temporary_gzip"
gzip -t "$temporary_gzip"
mv "$temporary_gzip" "$backup_file"
rm -f "$temporary_sql"
trap - EXIT HUP INT TERM
printf '%s\n' "Backup created: $backup_file"
