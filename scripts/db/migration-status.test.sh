#!/usr/bin/env bash
# Docker-free test of migration-status.sh (Etapa 4, T-03). A fake `docker` on
# PATH answers `printenv MYSQL_DATABASE` and the SELECT on schema_migrations
# with the version list in $work/versions, and records every call (arguments
# and stdin) so the test can prove the script only reads.
# Run from the project root: bash scripts/db/migration-status.test.sh
set -u

script_dir=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
status_script="$script_dir/migration-status.sh"
migrations_dir="$script_dir/../../src/app/database/migrations"
work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT

mkdir -p "$work/bin"
cat > "$work/bin/docker" <<FAKE
#!/bin/sh
echo "docker \$*" >> "$work/docker-calls"
if [ ! -t 0 ]; then cat >> "$work/docker-calls"; fi
case "\$*" in
    *"printenv MYSQL_DATABASE"*) echo centralvet_fake ;;
    *schema_migrations*)
        if [ -f "$work/no-table" ]; then
            echo "ERROR 1146 (42S02): Table doesn't exist" >&2
            exit 1
        fi
        cat "$work/versions" ;;
    *) exit 97 ;;
esac
FAKE
chmod +x "$work/bin/docker"

# Versions expected from disk: the same rule the script must apply.
all_versions=$(cd "$migrations_dir" && ls 2*_[0-9][0-9][0-9][0-9]_*.sql | grep -v '\.verify\.sql$' | sed 's/\.sql$//')
file_count=$(printf '%s\n' "$all_versions" | grep -c .)

failures=0

run_status() {
    output=$(PATH="$work/bin:$PATH" bash "$status_script" "$@" 2>&1)
    status=$?
}

check() {
    local label=$1 expected_exit=$2 expected_text=$3
    shift 3
    run_status "$@"
    if [ "$status" -ne "$expected_exit" ] || ! printf '%s\n' "$output" | grep -qF -- "$expected_text"; then
        echo "FAIL  $label (exit $status, expected $expected_exit and '$expected_text'): $(printf '%s' "$output" | tail -3)"
        failures=$((failures + 1))
    else
        echo "PASS  $label"
    fi
}

if bash -n "$status_script" 2>/dev/null; then echo "PASS  bash -n"; else echo "FAIL  bash -n"; failures=$((failures + 1)); fi

printf '%s\n' "$all_versions" | grep -v '^20261026_0018_billing_order_refund$' > "$work/versions"
check 'missing 0018 is pending and exits 1' 1 'pending 20261026_0018_billing_order_refund' --database centralvet_test

{ printf '%s\n' "$all_versions"; echo 20990101_9999_x; } > "$work/versions"
check 'extra version in the database is unknown and exits 1' 1 'unknown 20990101_9999_x' --database centralvet_test

printf '%s\n' "$all_versions" > "$work/versions"
check 'same list as the files exits 0' 0 "migration-status database=centralvet_test applied=$file_count pending=0 unknown=0" --database centralvet_test
if [ "$(printf '%s\n' "$output" | grep -c '^applied ')" -eq "$file_count" ] \
    && printf '%s\n' "$output" | tail -1 | grep -q '^migration-status '; then
    echo "PASS  one applied line per file and the summary is the last line"
else
    echo "FAIL  one applied line per file and the summary is the last line: $output"
    failures=$((failures + 1))
fi

check 'without --database uses MYSQL_DATABASE of the mysql container' 0 'migration-status database=centralvet_fake'
check '--database without a value exits 2' 2 'usage'  --database
check 'unknown argument exits 2' 2 'usage' --bogus
check 'invalid database name exits 2' 2 'usage' --database 'x;DROP'

touch "$work/no-table"
check 'database without schema_migrations exits 2' 2 'schema_migrations' --database centralvet_test
rm -f "$work/no-table"

if grep -Eiwq 'INSERT|UPDATE|DELETE|CREATE|DROP|ALTER|TRUNCATE|REPLACE|GRANT' "$work/docker-calls" 2>/dev/null; then
    echo "FAIL  docker only receives reads: $(grep -Eiw 'INSERT|UPDATE|DELETE|CREATE|DROP|ALTER|TRUNCATE|REPLACE|GRANT' "$work/docker-calls")"
    failures=$((failures + 1))
elif [ ! -s "$work/docker-calls" ]; then
    echo "FAIL  docker only receives reads: docker was never called"
    failures=$((failures + 1))
else
    echo "PASS  docker only receives reads"
fi

echo "Failed: $failures"
[ "$failures" -eq 0 ]
