#!/usr/bin/env sh
set -eu

project_root=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
cd "$project_root"

printf '%s\n' "== Compose configuration =="
docker compose config --quiet

printf '%s\n' "== Container status =="
docker compose ps

printf '%s\n' "== Health endpoints =="
http_port=${HTTP_PORT:-8081}
curl --fail --silent --show-error "http://127.0.0.1:$http_port/live"
printf '\n'
curl --fail --silent --show-error "http://127.0.0.1:$http_port/health"
printf '\n'

printf '%s\n' "== Recent logs =="
docker compose logs --tail=50 nginx app worker mysql redis

