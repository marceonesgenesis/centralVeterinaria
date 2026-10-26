#!/usr/bin/env bash
# CI reproduzível (Etapa 4, T-14): a mesma pipeline no GitHub Actions e na
# máquina do desenvolvedor, num projeto compose e num env-file próprios.
#
#   [CI_PROJECT=centralvet-ci] [CI_WORKDIR=var/ci] [IMAGE_TAG=ci] scripts/ci/run.sh
#
# CI_WORKDIR (relativo à raiz do projeto) é apagado e recriado no início e
# recebe o .env gerado (segredos de `openssl rand`) e o bootstrap do Adianti.
# Exporta COMPOSE_PROJECT_NAME=$CI_PROJECT e COMPOSE_ENV_FILES=$CI_WORKDIR/.env:
# o .env da raiz nunca é lido nem escrito, e o projeto `centralvet` (dev) é
# recusado. Etapas, cada uma anunciada por `ci: step <nome>`:
#   env guard build up migrator bootstrap provision schema lint script-tests suite
# O projeto é derrubado com `down -v --remove-orphans` num trap (com os logs
# de mysql/redis antes, em falha). Última linha: `ci: passed` (código 0) ou
# `ci: failed step=<nome>` (código 1).
# CI_SCRIPT_TESTS (lista separada por espaço) substitui os testes de shell da
# etapa script-tests; usado por scripts/ci/run.test.sh para não recursar.
set -uo pipefail

project_root=$(CDPATH='' cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)
cd "$project_root" || exit 1

test_database=centralvet_test
ci_project=${CI_PROJECT:-centralvet-ci}
ci_workdir=${CI_WORKDIR:-var/ci}
image_tag=${IMAGE_TAG:-ci}
default_script_tests='scripts/test-db/provision-check.test.sh scripts/db/migration-status.test.sh scripts/backup.test.sh scripts/restore.test.sh scripts/release/build-release.test.sh scripts/release/smoke.test.sh scripts/ci/run.test.sh'
script_tests=${CI_SCRIPT_TESTS:-$default_script_tests}

current_step=''
compose_ready=0
services_up=0

if ! printf '%s' "$ci_project" | grep -Eq '^[a-z0-9][a-z0-9_-]{0,62}$' || [ "$ci_project" = centralvet ]; then
    echo "ci: invalid CI_PROJECT '$ci_project' (lowercase compose name, never the dev project centralvet)" >&2
    echo "ci: failed step=env"
    exit 1
fi
if ! printf '%s' "$image_tag" | grep -Eq '^[A-Za-z0-9_][A-Za-z0-9_.-]{0,127}$'; then
    echo "ci: invalid IMAGE_TAG '$image_tag'" >&2
    echo "ci: failed step=env"
    exit 1
fi

case "$ci_workdir" in
    /*) workdir=$ci_workdir ;;
    *) workdir=$project_root/$ci_workdir ;;
esac
workdir=${workdir%/}
case "$workdir" in
    ''|/|"$project_root"|"$project_root/src"|"$project_root/scripts"|*/..|*/../*)
        echo "ci: refusing CI_WORKDIR '$ci_workdir'" >&2
        echo "ci: failed step=env"
        exit 1 ;;
esac
ci_env=$workdir/.env

export COMPOSE_PROJECT_NAME=$ci_project
export COMPOSE_ENV_FILES=$ci_env

finish() {
    local status=$?
    trap - EXIT INT TERM
    if [ "$compose_ready" -eq 1 ]; then
        if [ "$status" -ne 0 ] && [ "$services_up" -eq 1 ]; then
            echo "ci: logs (mysql, redis)" >&2
            docker compose logs --no-color --tail=200 mysql redis >&2 || true
        fi
        if ! docker compose down -v --remove-orphans >&2; then
            echo "ci: down -v failed for project $ci_project" >&2
            if [ "$status" -eq 0 ]; then
                current_step=down
                status=1
            fi
        fi
    fi
    if [ "$status" -eq 0 ]; then
        echo 'ci: passed'
        exit 0
    fi
    echo "ci: failed step=${current_step:-env}"
    exit 1
}
trap finish EXIT
trap 'exit 1' INT TERM

step() {
    current_step=$1
    echo "ci: step $1"
}

fail() {
    echo "ci: $*" >&2
    exit 1
}

secret() {
    openssl rand -hex "$1" || fail 'openssl rand failed'
}

# Reads KEY=value from the CI env-file without sourcing it.
ci_env_value() {
    grep -E "^$1=" "$ci_env" | tail -n 1 | cut -d= -f2-
}

root_sql() {
    # shellcheck disable=SC2016
    docker compose exec -T mysql sh -ceu 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -uroot --batch --skip-column-names'
}

step env
rm -rf -- "$workdir" || fail "could not remove $workdir"
mkdir -p -- "$workdir" || fail "could not create $workdir"
umask 077
{
    echo "COMPOSE_PROJECT_NAME=$ci_project"
    echo 'APP_ENV=development'
    echo 'APP_DEBUG=false'
    echo "APP_SEED=$(secret 32)"
    echo 'APP_TIMEZONE=America/Fortaleza'
    echo 'MYSQL_DATABASE=centralvet'
    echo 'MYSQL_USER=centralvet'
    echo "MYSQL_PASSWORD=$(secret 24)"
    echo "MYSQL_ROOT_PASSWORD=$(secret 24)"
    echo 'MIGRATION_DB_USER=centralvet_migrator'
    echo "MIGRATION_DB_PASSWORD=$(secret 24)"
    echo 'IMAGE_REGISTRY=centralvet'
    echo "IMAGE_TAG=$image_tag"
} > "$ci_env" || fail "could not write $ci_env"
compose_ready=1

step guard
app_database=$(ci_env_value MYSQL_DATABASE)
[ -n "$app_database" ] || fail "MYSQL_DATABASE missing in $ci_env"
[ "$app_database" != "$test_database" ] \
    || fail "test database $test_database equals MYSQL_DATABASE"
[ "$(cd "$workdir" && pwd -P)" != "$(pwd -P)" ] || fail 'CI_WORKDIR resolves to the project root'

step build
docker compose build app worker || exit 1

step up
services_up=1
docker compose up -d --wait --wait-timeout 180 mysql redis || exit 1

step migrator
migration_user=$(ci_env_value MIGRATION_DB_USER)
migration_password=$(ci_env_value MIGRATION_DB_PASSWORD)
root_sql <<SQL || exit 1
CREATE USER IF NOT EXISTS '$migration_user'@'%' IDENTIFIED BY '$migration_password';
GRANT ALL PRIVILEGES ON \`$app_database\`.* TO '$migration_user'@'%';
SQL
unset migration_password

step bootstrap
BOOTSTRAP_OUTPUT_DIR="$workdir/sql-bootstrap" BOOTSTRAP_ENV_FILE="$ci_env" \
    bash scripts/prepare-adianti-bootstrap.sh || exit 1

step provision
TEST_DB_BOOTSTRAP_DIR="$workdir/sql-bootstrap" PROVISION_ENV_FILE="$ci_env" \
    sh scripts/test-db/provision.sh || exit 1
# Some integration tests boot Adianti (init.php) in a subprocess, which opens
# the application database (DB_DATABASE=centralvet), as in development. The
# CI application database is ephemeral: it gets the same schema files, in the
# same order, by the migration user.
schema_list=$(TEST_DB_BOOTSTRAP_DIR="$workdir/sql-bootstrap" sh scripts/test-db/provision.sh --list) || exit 1
migration_user=$(ci_env_value MIGRATION_DB_USER)
migration_password=$(ci_env_value MIGRATION_DB_PASSWORD)
for file in $schema_list; do
    echo "provision: ci application database $app_database - $file"
    docker compose exec -T -e MYSQL_PWD="$migration_password" mysql \
        mysql -u"$migration_user" --default-character-set=utf8mb4 "$app_database" < "$file" || exit 1
done
unset migration_password

step schema
bash scripts/db/migration-status.sh --database "$test_database" || exit 1

step lint
# The image is built from this checkout, so its src/ is the tree under test.
# shellcheck disable=SC2016
php_lint=$(docker compose run --rm --no-deps -T app sh -c \
    'cd /var/www/html/src && find app tests bin config -name "*.php" -print0 | xargs -0 -n1 php -l 2>&1')
php_lint_status=$?
php_lint_other=$(printf '%s\n' "$php_lint" | grep -v '^No syntax errors detected' | grep -v '^$' || true)
php_lint_files=$(printf '%s\n' "$php_lint" | grep -c '^No syntax errors detected' || true)
if [ "$php_lint_status" -ne 0 ] || [ -n "$php_lint_other" ]; then
    printf '%s\n' "$php_lint_other" >&2
    fail "php -l failed (exit $php_lint_status)"
fi
echo "lint: php -l ok files=$php_lint_files"
bash_failed=0
bash_files=0
while IFS= read -r -d '' file; do
    bash_files=$((bash_files + 1))
    bash -n "$file" || bash_failed=$((bash_failed + 1))
done < <(find scripts -name '*.sh' -print0 | LC_ALL=C sort -z)
[ "$bash_failed" -eq 0 ] || fail "bash -n failed in $bash_failed file(s)"
echo "lint: bash -n ok files=$bash_files"

step script-tests
for test_file in $script_tests; do
    echo "script-tests: $test_file"
    test_output=$(env -u COMPOSE_PROJECT_NAME -u COMPOSE_ENV_FILES bash "$test_file" </dev/null 2>&1)
    test_status=$?
    printf '%s\n' "$test_output"
    if [ "$test_status" -ne 0 ] || [ "$(printf '%s\n' "$test_output" | tail -n 1)" != 'Failed: 0' ]; then
        fail "$test_file failed (exit $test_status)"
    fi
done

step suite
# The image ships `composer install --no-dev --classmap-authoritative`, so the
# CentralVet\Tests\ autoload-dev mapping is missing for the tests that spawn
# `php -r` subprocesses. composer.lock has no dev packages: a non-authoritative
# `composer dump-autoload --dev` (no network) into an anonymous volume over
# src/vendor (seeded from the image, dropped by --rm) restores it without a
# bind mount of the host src/ (a fresh checkout has no src/vendor).
suite_log=$workdir/suite.log
# shellcheck disable=SC2016
docker compose run --rm --no-deps -T \
    -e COMPOSER_HOME=/tmp/composer \
    -v /var/www/html/src/vendor \
    -v "$project_root/.env.example:/var/www/html/.env.example:ro" \
    -v "$project_root/docker-compose.yml:/var/www/html/docker-compose.yml:ro" \
    -v "$project_root/docker:/var/www/html/docker:ro" \
    -v "$project_root/docs:/var/www/html/docs:ro" \
    -v "$project_root/scripts:/var/www/html/scripts:ro" \
    -v "$project_root/.github:/var/www/html/.github:ro" \
    app sh -c 'composer dump-autoload --dev --no-interaction --quiet && exec php tests/run.php' </dev/null 2>&1 | tee "$suite_log"
suite_status=${PIPESTATUS[0]}
[ "$suite_status" -eq 0 ] || fail "tests/run.php exited $suite_status"
grep -Eq 'Failed: 0(,|$)' "$suite_log" || fail 'tests/run.php did not report Failed: 0'

current_step=''
exit 0
