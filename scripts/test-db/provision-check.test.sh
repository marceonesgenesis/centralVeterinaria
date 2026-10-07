#!/usr/bin/env bash
# Database-free test of provision.sh --check (T-05, correção 1). A fake
# `docker` on PATH records any call and fails, so this test can never touch
# MySQL. Run: bash scripts/test-db/provision-check.test.sh
set -u

script_dir=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
provision="$script_dir/provision.sh"
work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT

mkdir -p "$work/bin" "$work/good" "$work/dashes" "$work/empty"
cat > "$work/bin/docker" <<FAKE
#!/bin/sh
echo "docker \$*" >> "$work/docker-calls"
exit 97
FAKE
chmod +x "$work/bin/docker"

for name in 01-permission.mysql.sql 02-communication.mysql.sql 03-log.mysql.sql; do
    printf -- '-- ok\nSELECT 1;\n' > "$work/good/$name"
    printf -- '-- ok\nSELECT 1;\n' > "$work/dashes/$name"
done
printf -- '--- sqlite heading\nSELECT 1;\n' > "$work/dashes/01-permission.mysql.sql"

failures=0

check() {
    local label=$1 expected_exit=$2 expected_text=$3 dir=$4 output status
    output=$(PATH="$work/bin:$PATH" TEST_DB_BOOTSTRAP_DIR="$dir" sh "$provision" --check 2>&1)
    status=$?

    if [ "$status" -ne "$expected_exit" ] || ! printf '%s' "$output" | grep -qF -- "$expected_text"; then
        echo "FAIL  $label (exit $status, expected $expected_exit and '$expected_text'): $output"
        failures=$((failures + 1))
    else
        echo "PASS  $label"
    fi
}

if bash -n "$provision"; then echo "PASS  bash -n"; else echo "FAIL  bash -n"; failures=$((failures + 1)); fi

check 'valid bootstrap files pass the check' 0 'provision: check ok' "$work/good"
check 'a file with a --- heading is refused' 1 'starts a line with ---' "$work/dashes"
check 'missing bootstrap files point to prepare-adianti-bootstrap.sh' 1 'scripts/prepare-adianti-bootstrap.sh' "$work/empty"

if [ -s "$work/docker-calls" ]; then
    echo "FAIL  --check called docker: $(cat "$work/docker-calls")"
    failures=$((failures + 1))
else
    echo "PASS  --check never calls docker"
fi

echo "Failed: $failures"
[ "$failures" -eq 0 ]
