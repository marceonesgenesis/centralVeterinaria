#!/usr/bin/env bash
# Docker-free test of scripts/ci/run.sh (Etapa 4, T-14). A fake `docker` at
# the front of PATH records, per call, COMPOSE_PROJECT_NAME, COMPOSE_ENV_FILES,
# the arguments and the stdin, and answers what the pipeline reads:
# `SELECT COUNT(*) ... SCHEMATA` (provision.sh) gets 0, the SELECT on
# schema_migrations (migration-status.sh) gets every version on disk and
# `tests/run.php` prints a passing summary. FAKE_DOCKER_FAIL makes any call
# whose arguments or stdin contain that text exit 1. No real Docker, no network.
# CI_SCRIPT_TESTS points the `script-tests` step at a stub (this file is in the
# default list, so the real list would recurse).
# Run from the project root: bash scripts/ci/run.test.sh
set -u

script_dir=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
project_root=$(cd "$script_dir/../.." && pwd)
run_script="$script_dir/run.sh"
migrations_dir="$project_root/src/app/database/migrations"
work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT

mkdir -p "$work/bin"
(cd "$migrations_dir" && ls 2*_[0-9][0-9][0-9][0-9]_*.sql | grep -v '\.verify\.sql$' | sed 's/\.sql$//') > "$work/versions"

cat > "$work/bin/docker" <<FAKE
#!/bin/sh
stdin=''
if [ ! -t 0 ]; then stdin=\$(cat); fi
{
    echo "CALL COMPOSE_PROJECT_NAME=\${COMPOSE_PROJECT_NAME:-} COMPOSE_ENV_FILES=\${COMPOSE_ENV_FILES:-} docker \$*"
    [ -z "\$stdin" ] || printf '%s\n' "\$stdin"
} >> "$work/docker-calls"
if [ -n "\${FAKE_DOCKER_FAIL:-}" ]; then
    case "\$* \$stdin" in *"\$FAKE_DOCKER_FAIL"*) echo "fake docker failure" >&2; exit 1 ;; esac
fi
case "\$* \$stdin" in
    *information_schema.SCHEMATA*) echo 0 ;;
    *schema_migrations*) cat "$work/versions" ;;
    *tests/run.php*) echo "Total: 1, Passed: 1, Failed: 0, Skipped: 0" ;;
esac
exit 0
FAKE
chmod +x "$work/bin/docker"

cat > "$work/stub.test.sh" <<'STUB'
echo "PASS  stub"
echo "Failed: 0"
STUB

root_env_hash() {
    if [ -f "$project_root/.env" ]; then sha256sum "$project_root/.env" | cut -d' ' -f1; else echo absent; fi
}

failures=0
pass() { echo "PASS  $1"; }
fail() { echo "FAIL  $1"; failures=$((failures + 1)); }

run_ci() {
    local workdir=$1
    shift
    : > "$work/docker-calls"
    output=$(cd "$project_root" && env "$@" PATH="$work/bin:$PATH" CI_PROJECT=centralvet-ci-test \
        CI_WORKDIR="$workdir" IMAGE_TAG=ci-test CI_SCRIPT_TESTS="$work/stub.test.sh" \
        bash "$run_script" </dev/null 2>&1)
    status=$?
}

# Line number of the first docker call whose arguments contain $1 (0 = none).
call_line() {
    grep -n '^CALL ' "$work/docker-calls" | grep -F -- "$1" | head -n 1 | cut -d: -f1 || true
}

if bash -n "$run_script" 2>/dev/null; then pass 'bash -n'; else fail 'bash -n'; fi

hash_before=$(root_env_hash)
workdir="$work/ci"
mkdir -p "$workdir"
echo stale > "$workdir/stale-file"
: > "$workdir/.ci-workdir"
run_ci "$workdir" FAKE_DOCKER_FAIL=

if [ "$status" -eq 0 ] && [ "$(printf '%s\n' "$output" | tail -n 1)" = 'ci: passed' ]; then
    pass 'success run ends with ci: passed and exit 0'
else
    fail "success run ends with ci: passed and exit 0 (exit $status): $(printf '%s' "$output" | tail -n 5)"
fi

steps=$(printf '%s\n' "$output" | sed -n 's/^ci: step //p' | tr '\n' ' ')
expected_steps='env guard build up migrator bootstrap provision schema lint script-tests suite '
if [ "$steps" = "$expected_steps" ]; then
    pass 'ci: step lines in order'
else
    fail "ci: step lines in order (got '$steps')"
fi

provision_line=$(call_line 'centralvet_migrator --default-character-set=utf8mb4 centralvet_test')
schema_line=$(call_line 'schema_migrations')
suite_line=$(call_line 'tests/run.php')
if [ -n "$provision_line" ] && [ -n "$schema_line" ] && [ -n "$suite_line" ] \
    && [ "$provision_line" -lt "$schema_line" ] && [ "$schema_line" -lt "$suite_line" ]; then
    pass 'provision.sh and migration-status.sh run before tests/run.php'
else
    fail "provision.sh and migration-status.sh run before tests/run.php (lines '$provision_line' '$schema_line' '$suite_line')"
fi

app_schema_line=$(call_line 'centralvet_migrator --default-character-set=utf8mb4 centralvet')
app_schema_calls=$(grep -c -- '--default-character-set=utf8mb4 centralvet$' "$work/docker-calls" || true)
test_schema_calls=$(grep -c -- '--default-character-set=utf8mb4 centralvet_test$' "$work/docker-calls" || true)
if [ -n "$app_schema_line" ] && [ "$app_schema_calls" -gt 0 ] && [ "$app_schema_calls" -eq "$test_schema_calls" ] \
    && [ "$app_schema_line" -lt "$suite_line" ]; then
    pass "provision also applies the schema files to the CI application database ($app_schema_calls files)"
else
    fail "provision also applies the schema files to the CI application database ($app_schema_calls vs $test_schema_calls)"
fi

if grep -F 'schema_migrations' "$work/docker-calls" | grep -q 'sh centralvet_test'; then
    pass 'schema step reads centralvet_test'
else
    fail 'schema step reads centralvet_test'
fi

compose_calls=$(grep -c '^CALL .* docker compose ' "$work/docker-calls" || true)
wrong_project=$(grep '^CALL .* docker compose ' "$work/docker-calls" | grep -vc '^CALL COMPOSE_PROJECT_NAME=centralvet-ci-test ' || true)
if [ "$compose_calls" -gt 0 ] && [ "$wrong_project" -eq 0 ]; then
    pass "every docker compose call has COMPOSE_PROJECT_NAME=centralvet-ci-test ($compose_calls calls)"
else
    fail "every docker compose call has COMPOSE_PROJECT_NAME=centralvet-ci-test ($compose_calls calls, $wrong_project wrong)"
fi

wrong_env=$(grep '^CALL .* docker compose ' "$work/docker-calls" | grep -vc " COMPOSE_ENV_FILES=$workdir/.env docker " || true)
if [ "$compose_calls" -gt 0 ] && [ "$wrong_env" -eq 0 ]; then
    pass 'every docker compose call has COMPOSE_ENV_FILES=$CI_WORKDIR/.env'
else
    fail "every docker compose call has COMPOSE_ENV_FILES=\$CI_WORKDIR/.env ($wrong_env wrong)"
fi

if grep -q 'docker compose down -v --remove-orphans' "$work/docker-calls"; then
    pass 'down -v --remove-orphans runs at the end'
else
    fail 'down -v --remove-orphans runs at the end'
fi

if [ ! -e "$workdir/stale-file" ]; then pass 'CI_WORKDIR is recreated'; else fail 'CI_WORKDIR is recreated'; fi
if [ -f "$workdir/.ci-workdir" ]; then pass 'recreated CI_WORKDIR keeps the .ci-workdir marker'; else fail 'recreated CI_WORKDIR keeps the .ci-workdir marker'; fi

ci_env="$workdir/.env"
env_ok=1
for line in 'MYSQL_DATABASE=centralvet' 'MIGRATION_DB_USER=centralvet_migrator' 'IMAGE_TAG=ci-test' 'COMPOSE_PROJECT_NAME=centralvet-ci-test'; do
    grep -qx -- "$line" "$ci_env" 2>/dev/null || env_ok=0
done
for key in MIGRATION_DB_PASSWORD MYSQL_PASSWORD MYSQL_ROOT_PASSWORD APP_SEED CENTRALVET_ADMIN_PASSWORD; do
    grep -Eq "^$key=.{16,}" "$ci_env" 2>/dev/null || env_ok=0
done
if [ "$env_ok" -eq 1 ]; then pass 'generated .env has project, database, migrator and random secrets'; else fail 'generated .env has project, database, migrator and random secrets'; fi

if [ -f "$workdir/sql-bootstrap/01-permission.mysql.sql" ]; then
    pass 'bootstrap written to $CI_WORKDIR/sql-bootstrap'
else
    fail 'bootstrap written to $CI_WORKDIR/sql-bootstrap'
fi

if grep -q "CREATE USER IF NOT EXISTS 'centralvet_migrator'" "$work/docker-calls" \
    && grep -q 'GRANT ALL PRIVILEGES ON `centralvet`.\* TO' "$work/docker-calls"; then
    pass 'migrator step creates the migration user and grants on centralvet'
else
    fail 'migrator step creates the migration user and grants on centralvet'
fi

if [ "$(root_env_hash)" = "$hash_before" ]; then pass 'root .env unchanged'; else fail 'root .env unchanged'; fi

# Failure in the provision step.
workdir2="$work/ci-fail"
run_ci "$workdir2" FAKE_DOCKER_FAIL='CREATE DATABASE `centralvet_test`'
if [ "$status" -eq 1 ] && [ "$(printf '%s\n' "$output" | tail -n 1)" = 'ci: failed step=provision' ]; then
    pass 'provision failure ends with ci: failed step=provision and exit 1'
else
    fail "provision failure ends with ci: failed step=provision and exit 1 (exit $status): $(printf '%s' "$output" | tail -n 3)"
fi
if grep -q 'tests/run.php' "$work/docker-calls"; then
    fail 'provision failure never calls tests/run.php'
else
    pass 'provision failure never calls tests/run.php'
fi
if grep -q 'docker compose down -v --remove-orphans' "$work/docker-calls"; then
    pass 'provision failure still tears the project down'
else
    fail 'provision failure still tears the project down'
fi

if [ "$(root_env_hash)" = "$hash_before" ]; then pass 'root .env unchanged after failure'; else fail 'root .env unchanged after failure'; fi

# CI_WORKDIR guard (T-19), in a throwaway project: a copy of run.sh plus stubs
# for the scripts it calls, var/backups/x.tar.gpg, docs/ and .git/.
proj="$work/proj"
mkdir -p "$proj/scripts/ci" "$proj/scripts/test-db" "$proj/scripts/db" "$proj/scripts/x" \
    "$proj/var/backups" "$proj/docs" "$proj/.git"
cp "$run_script" "$proj/scripts/ci/run.sh"
seed_proj() {
    mkdir -p "$proj/var/backups" "$proj/docs" "$proj/.git"
    echo backup > "$proj/var/backups/x.tar.gpg"
    echo doc > "$proj/docs/keep.md"
    echo ref > "$proj/.git/HEAD"
}
printf '#!/usr/bin/env bash\nexit 0\n' > "$proj/scripts/prepare-adianti-bootstrap.sh"
printf '#!/bin/sh\nexit 0\n' > "$proj/scripts/test-db/provision.sh"
printf '#!/usr/bin/env bash\nexit 0\n' > "$proj/scripts/db/migration-status.sh"
cat > "$proj/scripts/x/y.test.sh" <<STUB
: > "$work/y-test-ran"
echo "PASS  y"
echo "Failed: 0"
STUB

run_proj() {
    : > "$work/docker-calls"
    output=$(cd "$proj" && env -u CI_SCRIPT_TESTS PATH="$work/bin:$PATH" CI_PROJECT=centralvet-ci-test \
        CI_WORKDIR="$1" IMAGE_TAG=ci-test FAKE_DOCKER_FAIL= bash "$proj/scripts/ci/run.sh" </dev/null 2>&1)
    status=$?
}

refused() {
    local label=$1 probe=$2
    seed_proj
    run_proj "$3"
    if [ "$status" -eq 1 ] && [ "$(printf '%s\n' "$output" | tail -n 1)" = 'ci: failed step=env' ] \
        && printf '%s\n' "$output" | grep -qF "ci: refusing CI_WORKDIR '$3'" \
        && [ ! -s "$work/docker-calls" ] && [ -e "$probe" ]; then
        pass "CI_WORKDIR=$label is refused before rm and docker"
    else
        fail "CI_WORKDIR=$label is refused before rm and docker (exit $status, probe $( [ -e "$probe" ] && echo kept || echo GONE)): $(printf '%s' "$output" | tail -n 3)"
    fi
}

refused var "$proj/var/backups/x.tar.gpg" var
refused docs "$proj/docs/keep.md" docs
refused .git "$proj/.git/HEAD" .git
refused / "$proj/var/backups/x.tar.gpg" /
refused '. (project root)' "$proj/var/backups/x.tar.gpg" .
refused 'var/ci/../backups' "$proj/var/backups/x.tar.gpg" var/ci/../backups
unmarked=$(mktemp -d)
echo keep > "$unmarked/file"
refused 'temporary dir with a file and no .ci-workdir' "$unmarked/file" "$unmarked"
rm -rf "$unmarked"

rm -f "$work/y-test-ran"
seed_proj
run_proj var/ci/local
if printf '%s\n' "$output" | grep -qx 'ci: step build' && [ -f "$proj/var/ci/local/.ci-workdir" ]; then
    pass 'CI_WORKDIR=var/ci/local reaches ci: step build and leaves .ci-workdir'
else
    fail "CI_WORKDIR=var/ci/local reaches ci: step build and leaves .ci-workdir (exit $status): $(printf '%s' "$output" | tail -n 3)"
fi
if [ -e "$work/y-test-ran" ] && printf '%s\n' "$output" | grep -qx 'script-tests: scripts/x/y.test.sh'; then
    pass 'script-tests runs every scripts/**/*.test.sh found by find'
else
    fail "script-tests runs every scripts/**/*.test.sh found by find (exit $status): $(printf '%s' "$output" | tail -n 3)"
fi
if [ -f "$proj/var/backups/x.tar.gpg" ]; then pass 'var/backups survives the var/ci/local run'; else fail 'var/backups survives the var/ci/local run'; fi

workflow="$project_root/.github/workflows/ci.yml"
if grep -q 'scripts/ci/run.sh' "$workflow" && ! grep -q 'exec -T app php tests/run.php' "$workflow"; then
    pass 'ci.yml runs scripts/ci/run.sh and not exec -T app php tests/run.php'
else
    fail 'ci.yml runs scripts/ci/run.sh and not exec -T app php tests/run.php'
fi

echo "Failed: $failures"
[ "$failures" -eq 0 ]
