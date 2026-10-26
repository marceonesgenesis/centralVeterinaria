#!/usr/bin/env bash
# Network-free test of smoke.sh (T-05). A fake `curl` on PATH answers by path.
# Run: bash scripts/release/smoke.test.sh
set -u

script_dir=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
smoke="$script_dir/smoke.sh"
work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT

mkdir -p "$work/bin"
cat > "$work/bin/curl" <<'FAKE'
#!/usr/bin/env bash
url=${@: -1}
case "$url" in
    */live) code=200 ;;
    */health) code=${FAKE_HEALTH_STATUS:-200} ;;
    */download.php\?file=*) code=404 ;;
    */app/config/application.php) code=403 ;;
    */index.php\?class=LoginForm) code=200 ;;
    */) code=200 ;;
    *) code=000 ;;
esac
printf '%s' "$code"
FAKE
chmod +x "$work/bin/curl"

failures=0
pass() { echo "PASS  $1"; }
fail() { echo "FAIL  $1"; failures=$((failures + 1)); }

if bash -n "$smoke" 2>/dev/null; then pass 'bash -n'; else fail 'bash -n'; fi

output=$(PATH="$work/bin:$PATH" bash "$smoke" http://app.test 2>&1)
status=$?
if [ "$status" -eq 0 ] && [ "$(printf '%s\n' "$output" | grep -c '^smoke pass ')" -eq 6 ] \
    && [ "$(printf '%s\n' "$output" | tail -n1)" = 'smoke passed=6 failed=0' ]; then
    pass 'healthy release: 6 smoke pass and exit 0'
else
    fail "healthy release: 6 smoke pass and exit 0 (exit $status): $output"
fi

output=$(PATH="$work/bin:$PATH" FAKE_HEALTH_STATUS=503 bash "$smoke" http://app.test/ 2>&1)
status=$?
if [ "$status" -eq 1 ] && printf '%s\n' "$output" | grep -qx 'smoke fail health status=503' \
    && [ "$(printf '%s\n' "$output" | tail -n1)" = 'smoke passed=5 failed=1' ]; then
    pass '/health 503: smoke fail health status=503 and exit 1'
else
    fail "/health 503: smoke fail health status=503 and exit 1 (exit $status): $output"
fi

echo "Failed: $failures"
[ "$failures" -eq 0 ]
