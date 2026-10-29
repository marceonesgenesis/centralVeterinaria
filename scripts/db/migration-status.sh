#!/usr/bin/env bash
# Registro de migrations aplicadas (Etapa 4, T-03), pilha Docker.
#
#   scripts/db/migration-status.sh [--database <nome>]
#
# Compara as migrations numeradas de src/app/database/migrations/
# (2*_NNNN_*.sql, sem os *.verify.sql) com schema_migrations do banco, só
# lendo: `SELECT version FROM <nome>.schema_migrations` pelo root do
# container mysql (`docker compose exec -T mysql`). Sem --database, usa o
# $MYSQL_DATABASE do container mysql. COMPOSE_PROJECT_NAME e
# COMPOSE_ENV_FILES do ambiente escolhem outro projeto (CI, ensaio).
#
# Saída: `applied <versão>` / `pending <versão>` (uma linha por arquivo, em
# ordem), `unknown <versão>` (no banco sem arquivo) e, por último,
# `migration-status database=<nome> applied=<n> pending=<n> unknown=<n>`.
# Código 0 com pending=0 unknown=0; 1 com algum pendente ou desconhecido;
# 2 em argumento inválido, banco sem schema_migrations ou erro do docker.
# A mesma saída vem de `php bin/migration-status.php` (hospedagem sem Docker).
set -euo pipefail

project_root=$(CDPATH='' cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)
cd "$project_root"
migrations_dir=src/app/database/migrations

usage() {
    echo "usage: $0 [--database <nome>]" >&2
    exit 2
}

database=''
while [ $# -gt 0 ]; do
    case "$1" in
        --database)
            [ $# -ge 2 ] && [ -n "$2" ] || usage
            database=$2
            shift 2 ;;
        *) usage ;;
    esac
done

valid_name() {
    printf '%s' "$1" | grep -Eq '^[A-Za-z0-9_]{1,64}$'
}

if [ -n "$database" ]; then
    valid_name "$database" || usage
else
    database=$(docker compose exec -T mysql printenv MYSQL_DATABASE </dev/null | tr -d '\r') || {
        echo "migration-status: could not read MYSQL_DATABASE from the mysql container" >&2
        exit 2
    }
    valid_name "$database" || {
        echo "migration-status: invalid MYSQL_DATABASE in the mysql container" >&2
        exit 2
    }
fi

expected=$(cd "$migrations_dir" && for file in 2*_[0-9][0-9][0-9][0-9]_*.sql; do
    [ -f "$file" ] || continue
    case "$file" in *.verify.sql) continue ;; esac
    printf '%s\n' "${file%.sql}"
done | LC_ALL=C sort)

# The name is validated above and goes to the inner sh as $1 (no SQL from stdin).
# shellcheck disable=SC2016
if ! applied_raw=$(docker compose exec -T mysql sh -ceu \
    'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -uroot --default-character-set=utf8mb4 --batch --skip-column-names -e "SELECT version FROM \`$1\`.schema_migrations"' \
    sh "$database" </dev/null); then
    echo "migration-status: could not read $database.schema_migrations (missing table or database)" >&2
    exit 2
fi
applied_set=$(printf '%s\n' "$applied_raw" | tr -d '\r' | grep -v '^$' | LC_ALL=C sort -u || true)

n_applied=0 n_pending=0 n_unknown=0
while IFS= read -r version; do
    [ -n "$version" ] || continue
    if printf '%s\n' "$applied_set" | grep -qxF -- "$version"; then
        echo "applied $version"
        n_applied=$((n_applied + 1))
    else
        echo "pending $version"
        n_pending=$((n_pending + 1))
    fi
done <<<"$expected"

while IFS= read -r version; do
    [ -n "$version" ] || continue
    if ! printf '%s\n' "$expected" | grep -qxF -- "$version"; then
        echo "unknown $version"
        n_unknown=$((n_unknown + 1))
    fi
done <<<"$applied_set"

echo "migration-status database=$database applied=$n_applied pending=$n_pending unknown=$n_unknown"
[ "$n_pending" -eq 0 ] && [ "$n_unknown" -eq 0 ] || exit 1
exit 0
