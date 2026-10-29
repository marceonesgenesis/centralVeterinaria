#!/usr/bin/env bash
# Wrapper for the host crontab entries of the Docker stack (T-18).
#
# Usage: scripts/cron-run.sh [--project <name>] <command> [arguments...]
#
# While scripts/restore.sh restores the project, it keeps the maintenance flag
# var/maintenance/<project>.restore; with that file present this wrapper
# prints `cron-run skipped reason=maintenance project=<project>` and exits 0
# without running the command. Otherwise it execs the command, so the exit
# code is the command's own.
#
# Project: --project, else COMPOSE_PROJECT_NAME from the environment, else
# from the .env in the project root (read with grep, never sourced), else
# centralvet. CENTRALVET_MAINTENANCE_DIR overrides var/maintenance.
# Exit codes: 2 usage; otherwise 0 (skipped) or the command's exit code.
set -uo pipefail

project_root=$(CDPATH= cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)

usage() {
    echo 'Usage: scripts/cron-run.sh [--project <name>] <command> [arguments...]' >&2
    exit 2
}

project=''
while [ "$#" -gt 0 ]; do
    case "$1" in
        --project)
            [ "$#" -ge 2 ] || usage
            project=$2; shift 2 ;;
        --project=*) project=${1#--project=}; shift ;;
        --) shift; break ;;
        *) break ;;
    esac
done
[ "$#" -gt 0 ] || usage

if [ -z "$project" ]; then
    project=${COMPOSE_PROJECT_NAME:-}
fi
if [ -z "$project" ] && [ -f "$project_root/.env" ]; then
    project=$(grep -E '^COMPOSE_PROJECT_NAME=' "$project_root/.env" 2>/dev/null | tail -n 1 | cut -d= -f2-)
    project=${project#\"}
    project=${project%\"}
fi
project=${project:-centralvet}
printf '%s' "$project" | grep -Eq '^[a-z0-9][a-z0-9_-]*$' || { echo "cron-run: invalid project name: $project" >&2; exit 2; }

flag="${CENTRALVET_MAINTENANCE_DIR:-$project_root/var/maintenance}/$project.restore"
if [ -e "$flag" ]; then
    echo "cron-run skipped reason=maintenance project=$project"
    exit 0
fi

exec "$@"
