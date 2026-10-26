#!/usr/bin/env bash
# Release rollback drill in the isolated Compose project centralvet-drill (T-15).
#
#   scripts/release/rollback-drill.sh <commit-anterior> <commit-novo> <backup>
#
# Phases (each one prints `rollback-drill phase=<fase> seconds=<n>`):
#   build       build-release.sh --image for both commits (images centralvet/app:<sha40>)
#   restore     restore.sh <backup> into centralvet-drill (env-file var/drill/.env)
#   deploy_old  up app worker nginx with IMAGE_TAG=<sha anterior>, then smoke.sh
#   pre_backup  backup.sh --project centralvet-drill --no-heartbeat (pre-migration)
#   migrate     every `pending` of migration-status.sh, applied from <commit-novo>
#               by a migration user created only for this drill
#   deploy_new  up app worker nginx with IMAGE_TAG=<sha novo>, then smoke.sh
#   rollback    stop app worker nginx, restore the pre-migration backup,
#               up with IMAGE_TAG=<sha anterior>, then smoke.sh
# After restore, migrate and rollback, migration-status.sh is printed as
# `rollback-drill migration-status after=<fase>` followed by its lines.
# Last line: `rollback-drill done total_seconds=<n> smoke_failures=<n>`
# (smoke_failures = sum of the failed checks of the three smoke runs).
#
# <backup> lives in BACKUP_DIR and is decrypted with BACKUP_PASSPHRASE_FILE
# (environment, as for restore.sh; default var/drill/backups and
# var/drill/passphrase). The pre-migration backup goes to a temporary
# directory under var/drill, removed at the end. RESTORE_DRILL_ENV_FILE
# overrides the env-file (it must set COMPOSE_PROJECT_NAME=centralvet-drill;
# scripts/restore-drill.sh generates it when absent). Only centralvet-drill is
# written; it is removed with `down -v` (plus leftover centralvet-drill_*
# volumes) on exit.
#
# Exit codes: 0 done with smoke_failures=0; 1 smoke failures; 2 usage or
# configuration; 3 a phase failed (the failing command's output is shown).
set -uo pipefail

project_root=$(CDPATH= cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)
invocation_dir=$(pwd)
project=centralvet-drill
base_url=http://127.0.0.1:18081

usage() {
    echo 'Usage: scripts/release/rollback-drill.sh <commit-anterior> <commit-novo> <backup>' >&2
}

[ "$#" -eq 3 ] || { usage; exit 2; }
old_ref=$1
new_ref=$2
backup=$3

old_sha=$(git -C "$project_root" rev-parse --verify --quiet "${old_ref}^{commit}") \
    || { echo "rollback-drill: commit not found: $old_ref" >&2; exit 2; }
new_sha=$(git -C "$project_root" rev-parse --verify --quiet "${new_ref}^{commit}") \
    || { echo "rollback-drill: commit not found: $new_ref" >&2; exit 2; }
[ "$old_sha" != "$new_sha" ] || { echo 'rollback-drill: the two commits are the same' >&2; exit 2; }

case "$backup" in /*) ;; *) backup="$invocation_dir/$backup" ;; esac
[ -r "$backup" ] || { echo "rollback-drill: backup not readable: $backup" >&2; exit 2; }
case "$backup" in
    *.tar.gpg|*.tar.gz.cvbk) ;;
    *) echo 'rollback-drill: <backup> must be a centralvet-*.tar.gpg or centralvet-*.tar.gz.cvbk' >&2; exit 2 ;;
esac

env_file=${RESTORE_DRILL_ENV_FILE:-$project_root/var/drill/.env}
case "$env_file" in /*) ;; *) env_file="$invocation_dir/$env_file" ;; esac
[ -r "$env_file" ] || { echo "rollback-drill: $env_file not found (run scripts/restore-drill.sh once to generate it)" >&2; exit 2; }
grep -qx "COMPOSE_PROJECT_NAME=$project" "$env_file" \
    || { echo "rollback-drill: $env_file does not set COMPOSE_PROJECT_NAME=$project" >&2; exit 2; }
for tool in docker git openssl curl; do
    command -v "$tool" >/dev/null 2>&1 || { echo "rollback-drill: required command not found: $tool" >&2; exit 2; }
done

export BACKUP_DIR=${BACKUP_DIR:-var/drill/backups}
export BACKUP_PASSPHRASE_FILE=${BACKUP_PASSPHRASE_FILE:-var/drill/passphrase}
# Read by migration-status.sh (and by any plain `docker compose` below).
export COMPOSE_PROJECT_NAME=$project
export COMPOSE_ENV_FILES=$env_file

cd "$project_root" || exit 2
umask 077
mkdir -p var/drill || exit 2
work=$(mktemp -d "$project_root/var/drill/rollback-drill.XXXXXX") || exit 2
work_rel=${work#"$project_root"/}
mkdir "$work/backups" || exit 2

compose() {
    docker compose -p "$project" --env-file "$env_file" "$@"
}

finish() {
    local code=$?
    compose down -v --remove-orphans >/dev/null 2>&1 || echo "rollback-drill: down -v of $project failed" >&2
    local volume
    while IFS= read -r volume; do
        case "$volume" in "${project}_"*) docker volume rm "$volume" >/dev/null 2>&1 ;; esac
    done < <(docker volume ls -q --filter "name=${project}_" 2>/dev/null)
    rm -rf "$work"
    exit "$code"
}
trap finish EXIT
trap 'exit 3' HUP INT TERM

started=$SECONDS
phase_started=$SECONDS
smoke_failures=0

phase_done() {
    echo "rollback-drill phase=$1 seconds=$((SECONDS - phase_started))"
    phase_started=$SECONDS
}

# run <log name> <command...>: output kept in $work/<log>.log, shown on failure.
run() {
    local log="$work/$1.log"
    shift
    if ! "$@" >"$log" 2>&1; then
        echo "rollback-drill: failed: $*" >&2
        tail -n 40 "$log" | sed 's/^/rollback-drill:   /' >&2
        exit 3
    fi
}

smoke() {
    local out failed
    out=$(bash scripts/release/smoke.sh "$base_url" 2>&1)
    printf '%s\n' "$out" | sed "s/^/rollback-drill smoke after=$1 /"
    failed=$(printf '%s\n' "$out" | sed -n 's/^smoke passed=[0-9]* failed=\([0-9]*\)$/\1/p' | tail -n 1)
    smoke_failures=$((smoke_failures + ${failed:-1}))
}

status() {
    echo "rollback-drill migration-status after=$1"
    bash scripts/db/migration-status.sh 2>&1
    true
}

deploy() {
    IMAGE_TAG=$1 run "deploy-$2" compose up -d --no-build --wait app worker nginx
}

restore() {
    CONFIRM_RESTORE=RESTORE_CENTRALVET_DRILL run "restore-$1" \
        bash scripts/restore.sh --project "$project" --env-file "$env_file" "$2"
    grep -E '^restore: timings' "$work/restore-$1.log" | sed "s/^restore:/rollback-drill restore after=$1/"
}

echo "rollback-drill start old=$old_sha new=$new_sha backup=$(basename "$backup") size_bytes=$(stat -c %s "$backup")"

# --- build ----------------------------------------------------------------
# The images copy the export as extracted: build with a normal umask, or
# php-fpm (www-data) cannot read its own configuration.
build() {
    (umask 022 && bash scripts/release/build-release.sh --image "$1")
}
run build-old build "$old_sha"
run build-new build "$new_sha"
phase_done build

# --- restore --------------------------------------------------------------
compose down -v --remove-orphans >/dev/null 2>&1
run up-data compose up -d --wait mysql redis
export IMAGE_TAG=$old_sha
restore initial "$backup"
phase_done restore
status restore

# --- deploy_old -----------------------------------------------------------
deploy "$old_sha" old
phase_done deploy_old
smoke deploy_old

# --- pre_backup -----------------------------------------------------------
BACKUP_DIR=$work_rel/backups run pre-backup \
    bash scripts/backup.sh --project "$project" --env-file "$env_file" --no-heartbeat
pre_backup=$(find "$work/backups" -maxdepth 1 -name 'centralvet-*.tar.gpg' | sort | tail -n 1)
[ -n "$pre_backup" ] || { echo 'rollback-drill: backup.sh produced no .tar.gpg' >&2; exit 3; }
echo "rollback-drill pre_backup file=$(basename "$pre_backup") size_bytes=$(stat -c %s "$pre_backup")"
phase_done pre_backup

# --- migrate --------------------------------------------------------------
migration_user=cv_drill_migration
migration_password=$(openssl rand -hex 24)
# Drill-only migration user: every privilege on the application schema only.
# shellcheck disable=SC2016
printf "CREATE USER IF NOT EXISTS '%s'@'%%' IDENTIFIED BY '%s';\nGRANT ALL PRIVILEGES ON \`centralvet\`.* TO '%s'@'%%';\n" \
    "$migration_user" "$migration_password" "$migration_user" > "$work/migration-user.sql"
run migration-user compose exec -T mysql sh -ceu 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -uroot' \
    < "$work/migration-user.sql"
rm -f "$work/migration-user.sql"

bash scripts/db/migration-status.sh > "$work/pending.txt" 2>&1
pending_status=$?
[ "$pending_status" -le 1 ] || { cat "$work/pending.txt" >&2; exit 3; }
applied_now=0
while IFS= read -r version; do
    sql_path="src/app/database/migrations/$version.sql"
    git cat-file -e "$new_sha:$sql_path" 2>/dev/null \
        || { echo "rollback-drill: $sql_path is pending but absent from $new_sha" >&2; exit 3; }
    git show "$new_sha:$sql_path" > "$work/$version.sql" || exit 3
    checksum=$(sha256sum "$work/$version.sql" | cut -d' ' -f1)
    # Replace the audit placeholder with the artifact's SHA-256 (runbook step 3).
    sed -i "s/'0\{64\}'/'$checksum'/" "$work/$version.sql"
    # shellcheck disable=SC2016
    run "migrate-$version" compose exec -T -e MIGRATION_DRILL_USER="$migration_user" -e MIGRATION_DRILL_PWD="$migration_password" \
        mysql sh -ceu 'MYSQL_PWD="$MIGRATION_DRILL_PWD" exec mysql -u"$MIGRATION_DRILL_USER" --default-character-set=utf8mb4 "$MYSQL_DATABASE"' \
        < "$work/$version.sql"
    echo "rollback-drill migrate applied=$version sha256=$checksum"
    applied_now=$((applied_now + 1))
done < <(sed -n 's/^pending \(.*\)$/\1/p' "$work/pending.txt")
echo "rollback-drill migrate count=$applied_now"
phase_done migrate
status migrate

# --- deploy_new -----------------------------------------------------------
export IMAGE_TAG=$new_sha
deploy "$new_sha" new
phase_done deploy_new
smoke deploy_new

# --- rollback -------------------------------------------------------------
run stop-new compose stop app worker nginx
export IMAGE_TAG=$old_sha
BACKUP_DIR=$work_rel/backups restore rollback "$pre_backup"
deploy "$old_sha" rollback
phase_done rollback
smoke rollback
status rollback

echo "rollback-drill done total_seconds=$((SECONDS - started)) smoke_failures=$smoke_failures"
[ "$smoke_failures" -eq 0 ] || exit 1
exit 0
