#!/usr/bin/env bash
# Verified restore of a Central Vet backup into a Docker Compose project (T-11).
#
# Usage: scripts/restore.sh [--project <name>] [--env-file <file>] <file>
#
# Accepts, inside $BACKUP_DIR (default var/backups):
#   centralvet-*.tar.gpg      scripts/backup.sh (T-08), passphrase in BACKUP_PASSPHRASE_FILE;
#   centralvet-*.tar.gz.cvbk  bin/hosting-backup.php (T-17), decrypted by
#                             `php bin/hosting-backup.php --decrypt` in the app container;
#   *.sql.gz                  legacy database-only dump.
# The MANIFEST (format=centralvet-backup-v1) and every `sha256 <hex> <part>`
# are checked before any write. Then the database is recreated and loaded
# (mysql as the container's root), files.tar.gz replaces /var/www/html/src/files,
# documents.tar.gz replaces /var/www/html/var/documents and, with
# minio=included, minio.tar.gz replaces the <project>_minio_data volume.
#
# Requires CONFIRM_RESTORE=RESTORE_<PROJECT> (project upper-cased, '-' → '_').
# Settings come from the environment and, when unset, from the env-file
# (default .env in the project root, read with grep, never sourced):
# BACKUP_DIR, BACKUP_PASSPHRASE_FILE, COMPOSE_PROJECT_NAME. Relative BACKUP_*
# paths are relative to the project root; --env-file and <file> are relative
# to the current directory.
#
# RESTORE_MANIFEST_COPY=<path> (optional) receives a copy of the verified
# MANIFEST (used by scripts/restore-drill.sh).
#
# Exit codes: 0 restored; 2 usage or configuration; 3 confirmation missing;
# 5 wrong passphrase, failed decryption or integrity check (nothing written);
# 6 a restore step failed after writing started.
set -uo pipefail

project_root=$(CDPATH= cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)
invocation_dir=$(pwd)

usage() {
    echo 'Usage: scripts/restore.sh [--project <name>] [--env-file <file>] <file>' >&2
}

config_error() {
    echo "restore: $*" >&2
    exit 2
}

integrity_error() {
    echo "restore: $*" >&2
    exit 5
}

project=''
env_file=''
env_file_explicit=0
input=''

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
        -h|--help) usage; exit 0 ;;
        -*) usage; config_error "invalid option: $1" ;;
        *)
            [ -z "$input" ] || { usage; config_error 'only one backup file is accepted'; }
            input=$1; shift ;;
    esac
done
[ -n "$input" ] || { usage; exit 2; }

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

backup_dir=$(absolute "$(setting BACKUP_DIR var/backups)")
backup_dir=$(realpath -m "$backup_dir")
case "$input" in /*) ;; *) input="$invocation_dir/$input" ;; esac
input_file=$(realpath -m "$input")

case "$input_file" in
    "$backup_dir"/centralvet-*.tar.gpg) kind=gpg ;;
    "$backup_dir"/centralvet-*.tar.gz.cvbk) kind=cvbk ;;
    "$backup_dir"/*.sql.gz) kind=legacy ;;
    *) config_error "restore accepts only centralvet-*.tar.gpg, centralvet-*.tar.gz.cvbk or *.sql.gz inside $backup_dir" ;;
esac
[ -f "$input_file" ] && [ -r "$input_file" ] || config_error "backup is not readable: $input_file"

expected_confirm="RESTORE_$(printf '%s' "$project" | tr '[:lower:]-' '[:upper:]_')"
if [ "${CONFIRM_RESTORE:-}" != "$expected_confirm" ]; then
    echo "Restore overwrites the database and the volumes of project $project. Re-run only after explicit approval:" >&2
    echo "CONFIRM_RESTORE=$expected_confirm $0 --project $project $input" >&2
    exit 3
fi

passphrase_file=''
if [ "$kind" != legacy ]; then
    passphrase_file=$(setting BACKUP_PASSPHRASE_FILE)
    [ -n "$passphrase_file" ] || config_error 'BACKUP_PASSPHRASE_FILE is not set'
    passphrase_file=$(absolute "$passphrase_file")
    [ -f "$passphrase_file" ] && [ -r "$passphrase_file" ] && [ -s "$passphrase_file" ] \
        || config_error "BACKUP_PASSPHRASE_FILE is missing, empty or unreadable: $passphrase_file"
fi
for tool in docker tar gzip sha256sum; do
    command -v "$tool" >/dev/null 2>&1 || config_error "required command not found: $tool"
done
[ "$kind" = gpg ] && { command -v gpg >/dev/null 2>&1 || config_error 'required command not found: gpg'; }

compose_args=(-p "$project")
if [ "$env_file_explicit" -eq 1 ] || [ -f "$env_file" ]; then
    compose_args+=(--env-file "$env_file")
fi

compose() {
    docker compose "${compose_args[@]}" "$@"
}

cd "$project_root" || exit 2
umask 077
stage=$(mktemp -d "${TMPDIR:-/tmp}/centralvet-restore.XXXXXX") || config_error 'cannot create a staging directory'
trap 'rm -rf "$stage"' EXIT
trap 'exit 6' HUP INT TERM
parts_dir="$stage/parts"
mkdir "$parts_dir"

# --- decrypt and verify (no write to the project yet) ---------------------
phase_start=$SECONDS
case "$kind" in
    gpg)
        gpg --batch --quiet --pinentry-mode loopback --passphrase-file "$passphrase_file" -d "$input_file" 2>/dev/null \
            | tar -xf - -C "$parts_dir" 2>/dev/null \
            || integrity_error 'decryption failed (wrong passphrase or damaged file)'
        ;;
    cvbk)
        # The image may predate bin/hosting-backup.php: mount this checkout's src read-only.
        chmod 700 "$stage"
        compose run --rm --no-deps -T --user "$(id -u):$(id -g)" \
            -v "$project_root/src:/var/www/html/src:ro" \
            -v "$input_file:/restore-in/backup.tar.gz.cvbk:ro" \
            -v "$passphrase_file:/restore-passphrase:ro" \
            -v "$stage:/restore-out" \
            -e BACKUP_PASSPHRASE_FILE=/restore-passphrase \
            app php bin/hosting-backup.php --decrypt /restore-in/backup.tar.gz.cvbk /restore-out/package.tar.gz \
            </dev/null >/dev/null || integrity_error 'decryption failed (wrong passphrase or damaged file)'
        tar -xzf "$stage/package.tar.gz" -C "$parts_dir" 2>/dev/null || integrity_error 'decrypted package is not a valid tar.gz'
        rm -f "$stage/package.tar.gz"
        ;;
    legacy)
        cp "$input_file" "$parts_dir/db.sql.gz" || config_error "cannot read $input_file"
        ;;
esac

manifest="$parts_dir/MANIFEST"
parts=(db.sql.gz)
minio=skipped
if [ "$kind" != legacy ]; then
    [ -f "$manifest" ] || integrity_error 'MANIFEST is missing'
    grep -qx 'format=centralvet-backup-v1' "$manifest" || integrity_error 'MANIFEST is not format=centralvet-backup-v1'
    grep -qx 'minio=included' "$manifest" && minio=included
    parts=(db.sql.gz files.tar.gz documents.tar.gz)
    [ "$minio" = included ] && parts+=(minio.tar.gz)
    while read -r tag sum part extra; do
        [ "$tag" = sha256 ] || continue
        [ -z "${extra:-}" ] || integrity_error "malformed sha256 line for $part"
        case "$part" in db.sql.gz|files.tar.gz|documents.tar.gz|minio.tar.gz) ;; *) integrity_error "unexpected part in MANIFEST: $part" ;; esac
        [ -f "$parts_dir/$part" ] || integrity_error "part listed in MANIFEST is missing: $part"
        [ "$(sha256sum "$parts_dir/$part" | cut -d' ' -f1)" = "$sum" ] || integrity_error "sha256 mismatch: $part"
    done < "$manifest"
    for part in "${parts[@]}"; do
        grep -Eq "^sha256 [0-9a-f]{64} $part\$" "$manifest" || integrity_error "MANIFEST has no sha256 for $part"
    done
fi
for part in "${parts[@]}"; do
    gzip -t "$parts_dir/$part" 2>/dev/null || integrity_error "$part is not a valid gzip"
done
decrypt_seconds=$((SECONDS - phase_start))

if [ -n "${RESTORE_MANIFEST_COPY:-}" ] && [ -f "$manifest" ]; then
    cp "$manifest" "$RESTORE_MANIFEST_COPY" || config_error "cannot write RESTORE_MANIFEST_COPY: $RESTORE_MANIFEST_COPY"
fi

restore_error() {
    echo "restore: $* (project $project may be partially restored)" >&2
    exit 6
}

# --- database -------------------------------------------------------------
phase_start=$SECONDS
gzip -dc "$parts_dir/db.sql.gz" | compose exec -T mysql sh -ceu '
MYSQL_PWD="$MYSQL_ROOT_PASSWORD"; export MYSQL_PWD
{
    printf "DROP DATABASE IF EXISTS \`%s\`;\nCREATE DATABASE \`%s\`;\nUSE \`%s\`;\n" "$MYSQL_DATABASE" "$MYSQL_DATABASE" "$MYSQL_DATABASE"
    cat
} | mysql -uroot --default-character-set=utf8mb4' || restore_error 'database restore failed'
db_seconds=$((SECONDS - phase_start))

# --- volumes --------------------------------------------------------------
# replace_dir <part> <dir inside the app container> [extra compose run args...]
replace_dir() {
    local part=$1 dir=$2
    shift 2
    compose run --rm --no-deps -T "$@" --entrypoint sh app -ceu \
        'find "$1" -mindepth 1 -delete && tar -xzf - --no-same-owner -C "$1"' restore "$dir" \
        < "$parts_dir/$part" >/dev/null || restore_error "restoring $part into $dir failed"
}

phase_start=$SECONDS
if [ "$kind" != legacy ]; then
    replace_dir files.tar.gz /var/www/html/src/files
    replace_dir documents.tar.gz /var/www/html/var/documents
    if [ "$minio" = included ]; then
        # MinIO data is root-owned; the app user cannot write a fresh volume.
        replace_dir minio.tar.gz /restore-minio --user 0:0 -v "${project}_minio_data:/restore-minio"
    fi
fi
files_seconds=$((SECONDS - phase_start))

part_count=${#parts[@]}
[ "$kind" != legacy ] && part_count=$((part_count + 1))
echo "restore: timings decrypt_seconds=$decrypt_seconds db_seconds=$db_seconds files_seconds=$files_seconds"
echo "Restore completed from: $input_file project=$project parts=$part_count"
