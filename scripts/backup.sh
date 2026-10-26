#!/usr/bin/env bash
# Full encrypted backup of a Central Vet Docker deployment (T-08).
#
# Usage: scripts/backup.sh [--project <name>] [--env-file <file>] [--no-heartbeat]
#
# Produces $BACKUP_DIR/centralvet-<AAAAMMDDTHHMMSSZ>.tar.gpg: a tar with MANIFEST,
# db.sql.gz, files.tar.gz (app_files), documents.tar.gz (app_documents) and,
# when the <project>_minio_data volume exists, minio.tar.gz; encrypted with
# `gpg --symmetric --cipher-algo AES256` using BACKUP_PASSPHRASE_FILE.
#
# Settings come from the environment and, when unset, from the env-file
# (default .env in the project root), which is read with grep, never sourced:
#   BACKUP_DIR (default var/backups), BACKUP_PASSPHRASE_FILE (required),
#   BACKUP_RETENTION_DAYS (default 14), BACKUP_OFFSITE_DIR,
#   BACKUP_OFFSITE_S3_URI, BACKUP_OFFSITE_S3_ENDPOINT, COMPOSE_PROJECT_NAME.
# Relative BACKUP_* paths are relative to the project root; --env-file is
# relative to the current directory.
#
# Exit codes: 0 created; 2 configuration (passphrase file missing or
# unreadable, invalid option or value); 3 dump or archive failure (nothing
# partial is left in BACKUP_DIR); 4 offsite copy failed (the local file stays).
#
# Without --no-heartbeat, a success is recorded in ops_heartbeat (job
# 'backup') with the mysql container's MYSQL_USER; a failed INSERT only
# prints "backup: heartbeat not recorded". On the dev database always pass
# --no-heartbeat.
set -uo pipefail

project_root=$(CDPATH= cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)
invocation_dir=$(pwd)

usage() {
    echo 'Usage: scripts/backup.sh [--project <name>] [--env-file <file>] [--no-heartbeat]' >&2
}

config_error() {
    echo "backup: $*" >&2
    exit 2
}

project=''
env_file=''
env_file_explicit=0
heartbeat=1

while [ "$#" -gt 0 ]; do
    case "$1" in
        --project)
            [ "$#" -ge 2 ] || { usage; config_error '--project needs a value'; }
            project=$2; shift 2 ;;
        --project=*) project=${1#--project=}; shift ;;
        --env-file)
            [ "$#" -ge 2 ] || { usage; config_error '--env-file needs a value'; }
            env_file=$2; env_file_explicit=1; shift 2 ;;
        --env-file=*) env_file=${1#--env-file=}; env_file_explicit=1; shift ;;
        --no-heartbeat) heartbeat=0; shift ;;
        -h|--help) usage; exit 0 ;;
        *) usage; config_error "invalid option: $1" ;;
    esac
done

if [ -z "$env_file" ]; then
    env_file="$project_root/.env"
elif [ "${env_file#/}" = "$env_file" ]; then
    env_file="$invocation_dir/$env_file"
fi
if [ "$env_file_explicit" -eq 1 ] && [ ! -r "$env_file" ]; then
    config_error "env file not readable: $env_file"
fi

env_value() {
    local value
    value=$(grep -E "^$1=" "$env_file" 2>/dev/null | tail -n 1 | cut -d= -f2-) || value=''
    value=${value#\"}
    value=${value%\"}
    printf '%s' "$value"
}

# setting <NAME> [default]: environment first, then the env-file, then default.
setting() {
    local value=${!1:-}
    [ -n "$value" ] || value=$(env_value "$1")
    printf '%s' "${value:-${2:-}}"
}

absolute() {
    case "$1" in
        /*) printf '%s' "$1" ;;
        *) printf '%s/%s' "$project_root" "$1" ;;
    esac
}

[ -n "$project" ] || project=$(setting COMPOSE_PROJECT_NAME "$(basename "$project_root")")
printf '%s' "$project" | grep -Eq '^[a-z0-9][a-z0-9_-]*$' || config_error "invalid project name: $project"

backup_dir_display=$(setting BACKUP_DIR var/backups)
backup_dir_display=${backup_dir_display%/}
backup_dir=$(absolute "$backup_dir_display")
passphrase_file=$(setting BACKUP_PASSPHRASE_FILE)
retention_days=$(setting BACKUP_RETENTION_DAYS 14)
offsite_dir=$(setting BACKUP_OFFSITE_DIR)
offsite_s3_uri=$(setting BACKUP_OFFSITE_S3_URI)
offsite_s3_endpoint=$(setting BACKUP_OFFSITE_S3_ENDPOINT)

[ -n "$passphrase_file" ] || config_error 'BACKUP_PASSPHRASE_FILE is not set'
passphrase_file=$(absolute "$passphrase_file")
[ -f "$passphrase_file" ] && [ -r "$passphrase_file" ] && [ -s "$passphrase_file" ] \
    || config_error "BACKUP_PASSPHRASE_FILE is missing, empty or unreadable: $passphrase_file"
printf '%s' "$retention_days" | grep -Eq '^[1-9][0-9]*$' || config_error "BACKUP_RETENTION_DAYS must be a positive integer: $retention_days"
for tool in docker gpg tar gzip sha256sum; do
    command -v "$tool" >/dev/null 2>&1 || config_error "required command not found: $tool"
done
if [ -n "$offsite_s3_uri" ]; then
    command -v aws >/dev/null 2>&1 || config_error 'BACKUP_OFFSITE_S3_URI is set but aws is not installed'
fi

compose_args=(-p "$project")
if [ "$env_file_explicit" -eq 1 ] || [ -f "$env_file" ]; then
    compose_args+=(--env-file "$env_file")
fi

compose() {
    docker compose "${compose_args[@]}" "$@"
}

cd "$project_root" || exit 3
umask 077

timestamp=$(date -u +%Y%m%dT%H%M%SZ)
created_at="${timestamp:0:4}-${timestamp:4:2}-${timestamp:6:2}T${timestamp:9:2}:${timestamp:11:2}:${timestamp:13:2}Z"
name="centralvet-$timestamp.tar.gpg"
final="$backup_dir/$name"
final_display="$backup_dir_display/$name"

mkdir -p "$backup_dir" || { echo "backup: cannot create $backup_dir" >&2; exit 3; }
stage=$(mktemp -d "$backup_dir/.centralvet-$timestamp.XXXXXX") || { echo "backup: cannot create a staging directory in $backup_dir" >&2; exit 3; }
trap 'rm -rf "$stage"' EXIT
trap 'exit 3' HUP INT TERM
mkdir "$stage/parts"
parts_dir="$stage/parts"

archive_error() {
    echo "backup: $*" >&2
    exit 3
}

# --- database -------------------------------------------------------------
compose exec -T mysql sh -ceu \
    'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysqldump --single-transaction --quick --routines --triggers --no-tablespaces -uroot "$MYSQL_DATABASE"' \
    </dev/null | gzip -9 > "$parts_dir/db.sql.gz" || archive_error 'mysqldump failed'
gzip -t "$parts_dir/db.sql.gz" 2>/dev/null || archive_error 'db.sql.gz is corrupt'
[ -n "$(gzip -dc "$parts_dir/db.sql.gz" | head -c 1)" ] || archive_error 'mysqldump produced an empty dump'

db_lines=$(compose exec -T mysql sh -ceu \
    'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -uroot --batch --skip-column-names "$MYSQL_DATABASE"' <<'SQL'
SELECT CONCAT('mysql_database=', DATABASE());
SELECT CONCAT('table_count=', COUNT(*)) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE';
SELECT CONCAT('rows tenant=', COUNT(*)) FROM tenant;
SELECT CONCAT('rows system_users=', COUNT(*)) FROM system_users;
SELECT CONCAT('rows billing_order=', COUNT(*)) FROM billing_order;
SELECT CONCAT('rows communication_message=', COUNT(*)) FROM communication_message;
SELECT CONCAT('rows generated_document=', COUNT(*)) FROM generated_document;
SELECT CONCAT('migration ', version) FROM schema_migrations ORDER BY version;
SQL
) || archive_error 'database inventory query failed'
printf '%s\n' "$db_lines" | grep -q '^table_count=' || archive_error 'database inventory is incomplete'

# --- volumes --------------------------------------------------------------
# tar_volume <part> <path inside the app container> [extra compose run args...]
tar_volume() {
    local part=$1 path=$2
    shift 2
    compose run --rm --no-deps -T "$@" --entrypoint tar app -czf - -C "$path" . \
        </dev/null > "$parts_dir/$part" || archive_error "archiving $path failed"
    gzip -t "$parts_dir/$part" 2>/dev/null || archive_error "$part is corrupt"
}

count_entries() {
    local listing
    listing=$(tar -tzf "$parts_dir/$1") || archive_error "cannot list $1"
    printf '%s\n' "$listing" | grep -v '/$' | grep -c . || true
}

tar_volume files.tar.gz /var/www/html/src/files
tar_volume documents.tar.gz /var/www/html/var/documents
files_count=$(count_entries files.tar.gz)
documents_count=$(count_entries documents.tar.gz)
printf "%s %s" "$files_count" "$documents_count" | grep -Eq "^[0-9]+ [0-9]+$" || archive_error "cannot count archived files"

minio=skipped
if docker volume inspect "${project}_minio_data" >/dev/null 2>&1; then
    minio=included
    tar_volume minio.tar.gz /backup-minio -v "${project}_minio_data:/backup-minio:ro"
fi

# --- manifest -------------------------------------------------------------
commit=$(git -C "$project_root" rev-parse HEAD 2>/dev/null) || commit=unknown
printf '%s' "$commit" | grep -Eq '^[0-9a-f]{40}$' || commit=unknown

parts=(db.sql.gz files.tar.gz documents.tar.gz)
[ "$minio" = included ] && parts+=(minio.tar.gz)

{
    echo 'format=centralvet-backup-v1'
    echo "created_at=$created_at"
    echo "commit=$commit"
    echo "project=$project"
    printf '%s\n' "$db_lines" | grep '^mysql_database='
    echo "minio=$minio"
    printf '%s\n' "$db_lines" | grep -E '^(table_count=|rows )'
    echo "files_count=$files_count"
    echo "documents_count=$documents_count"
    printf '%s\n' "$db_lines" | grep '^migration ' || true
    for part in "${parts[@]}"; do
        echo "sha256 $(sha256sum "$parts_dir/$part" | cut -d' ' -f1) $part"
    done
} > "$parts_dir/MANIFEST" || archive_error 'cannot write MANIFEST'

# --- encrypt --------------------------------------------------------------
tar -cf - -C "$parts_dir" MANIFEST "${parts[@]}" \
    | gpg --batch --yes --quiet --pinentry-mode loopback --passphrase-file "$passphrase_file" \
        --symmetric --cipher-algo AES256 -o "$stage/$name" \
    || archive_error 'encryption failed'
mv "$stage/$name" "$final" || archive_error "cannot move the backup to $final"
part_count=$((${#parts[@]} + 1))
bytes=$(stat -c %s "$final")
rm -rf "$stage"
trap - EXIT HUP INT TERM

# --- retention ------------------------------------------------------------
while IFS= read -r -d '' old; do
    [ "$old" = "$final" ] && continue
    rm -f "$old" && echo "backup: pruned $backup_dir_display/$(basename "$old")"
done < <(find "$backup_dir" -maxdepth 1 -type f -name 'centralvet-*.tar.gpg' -mmin "+$((retention_days * 1440))" -print0)

# --- offsite copy ---------------------------------------------------------
offsite_failed=0
if [ -n "$offsite_dir" ]; then
    offsite_dir=${offsite_dir%/}
    offsite_target="$offsite_dir/$name"
    offsite_abs=$(absolute "$offsite_target")
    if cp "$final" "$offsite_abs.partial" && mv "$offsite_abs.partial" "$offsite_abs" \
        && [ "$(sha256sum < "$final")" = "$(sha256sum < "$offsite_abs")" ]; then
        echo "backup: offsite copy $offsite_target"
    else
        rm -f "$offsite_abs.partial"
        echo "backup: offsite copy failed $offsite_target" >&2
        offsite_failed=1
    fi
fi
if [ -n "$offsite_s3_uri" ]; then
    s3_target="${offsite_s3_uri%/}/$name"
    s3_args=(s3 cp "$final" "$s3_target")
    [ -n "$offsite_s3_endpoint" ] && s3_args+=(--endpoint-url "$offsite_s3_endpoint")
    if aws "${s3_args[@]}" >/dev/null; then
        echo "backup: offsite copy $s3_target"
    else
        echo "backup: offsite copy failed $s3_target" >&2
        offsite_failed=1
    fi
fi
if [ "$offsite_failed" -eq 1 ]; then
    echo "backup: local backup kept at $final_display" >&2
    exit 4
fi

# --- heartbeat ------------------------------------------------------------
if [ "$heartbeat" -eq 1 ]; then
    printf "INSERT INTO ops_heartbeat (job, last_success_at, detail) VALUES ('backup', NOW(6), '%s') ON DUPLICATE KEY UPDATE last_success_at = VALUES(last_success_at), detail = VALUES(detail);\n" "$name" \
        | compose exec -T mysql sh -ceu \
            'MYSQL_PWD="$MYSQL_PASSWORD" exec mysql -u"$MYSQL_USER" --batch "$MYSQL_DATABASE"' >/dev/null 2>&1 \
        || echo 'backup: heartbeat not recorded' >&2
fi

echo "Backup created: $final_display parts=$part_count bytes=$bytes"
