#!/usr/bin/env bash
# Post-deploy HTTP smoke test (T-05): the minimum for every release and for the
# rollback drill (T-15).
#
#   scripts/release/smoke.sh <base-url>
#
# One line per check (`smoke pass <check>` / `smoke fail <check> status=<code>`)
# and a final `smoke passed=<n> failed=<n>`. Exit 1 when any check fails.
set -u

if [ "$#" -ne 1 ] || [ -z "$1" ]; then
    echo "usage: scripts/release/smoke.sh <base-url>" >&2
    exit 2
fi
base=${1%/}

passed=0
failed=0

status_of() {
    local code
    code=$(curl -sS -o /dev/null -w '%{http_code}' --max-time "${SMOKE_TIMEOUT:-10}" "$base$1" 2>/dev/null) || true
    printf '%s' "${code:-000}"
}

# check <name> <path> <eq|ne> <expected status>
check() {
    local name=$1 path=$2 op=$3 expected=$4 code ok=0
    code=$(status_of "$path")
    case "$op" in
        eq) [ "$code" = "$expected" ] && ok=1 ;;
        ne) [ "$code" != "$expected" ] && [ "$code" != 000 ] && ok=1 ;;
    esac
    if [ "$ok" -eq 1 ]; then
        echo "smoke pass $name"
        passed=$((passed + 1))
    else
        echo "smoke fail $name status=$code"
        failed=$((failed + 1))
    fi
}

check live /live eq 200
check health /health eq 200
check landing / eq 200
check login '/index.php?class=LoginForm' eq 200
check download_blocked '/download.php?file=app/config/application.php' eq 404
check config_blocked /app/config/application.php ne 200

echo "smoke passed=$passed failed=$failed"
[ "$failed" -eq 0 ]
