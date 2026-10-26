#!/usr/bin/env bash
# Docker-free test of scripts/cron-run.sh (T-18): with the maintenance flag
# var/maintenance/<project>.restore present, the job is skipped (exit 0);
# without it, the command runs and its exit code propagates.
# Run from the project root: bash scripts/cron-run.test.sh
set -u

script_dir=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
cron_run="$script_dir/cron-run.sh"
work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT

export CENTRALVET_MAINTENANCE_DIR="$work/maint"
mkdir -p "$work/maint"
cat > "$work/job" <<FAKE
#!/usr/bin/env bash
printf 'ran %s\n' "\$*" >> "$work/job-calls"
exit "\${FAKE_JOB_EXIT:-0}"
FAKE
chmod +x "$work/job"

failures=0
pass() { echo "PASS  $1"; }
fail() { echo "FAIL  $1"; failures=$((failures + 1)); }

# run_cron [args...] — environment overrides come from the caller.
run_cron() {
    : > "$work/job-calls"
    output=$(bash "$cron_run" "$@" 2>"$work/stderr")
    status=$?
    stderr=$(cat "$work/stderr")
}

if [ -f "$cron_run" ] && bash -n "$cron_run"; then pass 'bash -n cron-run.sh'; else fail 'bash -n cron-run.sh'; fi

# 1. Flag of the project present → skipped, exit 0, command not run.
printf 'pid=1\nstarted_at=2026-10-26T00:00:00Z\n' > "$work/maint/centralvet.restore"
COMPOSE_PROJECT_NAME=centralvet run_cron "$work/job" a b
if [ "$status" -eq 0 ] && [ "$output" = 'cron-run skipped reason=maintenance project=centralvet' ] && [ ! -s "$work/job-calls" ]; then
    pass 'with the flag prints cron-run skipped reason=maintenance and exits 0 without running the command'
else
    fail "flag present (exit $status, output: $output, calls: $(cat "$work/job-calls"), stderr: $stderr)"
fi

# 2. --project picks the flag of that project.
COMPOSE_PROJECT_NAME=other run_cron --project centralvet "$work/job"
if [ "$status" -eq 0 ] && [ "$output" = 'cron-run skipped reason=maintenance project=centralvet' ] && [ ! -s "$work/job-calls" ]; then
    pass '--project overrides COMPOSE_PROJECT_NAME'
else
    fail "--project (exit $status, output: $output)"
fi

# 3. The flag of another project does not skip; the exit code 3 propagates.
FAKE_JOB_EXIT=3 COMPOSE_PROJECT_NAME=centralvet-drill run_cron "$work/job" x y
if [ "$status" -eq 3 ] && [ "$(cat "$work/job-calls")" = 'ran x y' ] && [ -z "$output" ]; then
    pass 'without the flag of its project runs the command with its arguments and propagates exit 3'
else
    fail "no flag propagates 3 (exit $status, output: $output, calls: $(cat "$work/job-calls"))"
fi
rm -f "$work/maint/centralvet.restore"

# 4. Without any flag, exit 0 of the command.
COMPOSE_PROJECT_NAME=centralvet run_cron "$work/job"
if [ "$status" -eq 0 ] && [ "$(cat "$work/job-calls")" = 'ran ' ]; then pass 'without the flag runs the command (exit 0)'; else fail "no flag exit 0 (exit $status)"; fi

# 5. Usage errors exit 2 without running anything.
run_cron
if [ "$status" -eq 2 ]; then pass 'no command exits 2'; else fail "no command exits 2 (exit $status)"; fi
run_cron --project '../x' "$work/job"
if [ "$status" -eq 2 ] && [ ! -s "$work/job-calls" ]; then pass 'invalid project name exits 2'; else fail "invalid project exits 2 (exit $status)"; fi

echo "Failed: $failures"
[ "$failures" -eq 0 ]
