#!/usr/bin/env sh
set -eu

if [ "$#" -ne 1 ]; then
    printf '%s\n' "Usage: $0 var/backups/file.sql.gz" >&2
    exit 2
fi

project_root=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
backup_root="$project_root/var/backups"
input_file=$(realpath "$1")

case "$input_file" in
    "$backup_root"/*.sql.gz) ;;
    *) printf '%s\n' "Restore accepts only .sql.gz files inside $backup_root" >&2; exit 2 ;;
esac

if [ ! -r "$input_file" ]; then
    printf '%s\n' "Backup is not readable: $input_file" >&2
    exit 2
fi

if [ "${CONFIRM_RESTORE:-}" != "RESTORE_CENTRALVET" ]; then
    printf '%s\n' "Restore overwrites database state. Re-run only after explicit approval:" >&2
    printf '%s\n' "CONFIRM_RESTORE=RESTORE_CENTRALVET $0 $1" >&2
    exit 3
fi

gzip -t "$input_file"
cd "$project_root"
gzip -dc "$input_file" | docker compose exec -T mysql sh -ceu \
    'exec mysql -u root -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"'

printf '%s\n' "Restore completed from: $input_file"

