#!/usr/bin/env bash
# Builds a release from an identified commit, never from the working tree (T-05).
#
#   scripts/release/build-release.sh [--image] <commit>
#
# - Exports <commit> with `git archive`, installs src/vendor with composer:2.8.11
#   (--no-dev --no-scripts) and writes var/releases/centralvet-<sha7>.tar.gz
#   (src/ + RELEASE_MANIFEST) plus its .sha256, for the shared hosting.
# - --image also builds centralvet/app:<sha40> and centralvet/worker:<sha40>
#   from the same export (Docker/VPS). Images are never pushed.
#
# RELEASE_REPO (default: project root) and RELEASE_OUT_DIR (default:
# var/releases under the project root) override source and destination.
# Exit codes: 0 ok, 1 build failure, 2 unknown commit / usage.
set -euo pipefail

script_dir=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
project_root=$(cd "$script_dir/../.." && pwd)

usage() {
    echo "usage: scripts/release/build-release.sh [--image] <commit>" >&2
    exit 2
}

build_image=0
commit=
while [ "$#" -gt 0 ]; do
    case "$1" in
        --image) build_image=1 ;;
        -h|--help) usage ;;
        -*) echo "build-release: unknown option $1" >&2; usage ;;
        *) [ -z "$commit" ] || usage; commit=$1 ;;
    esac
    shift
done
[ -n "$commit" ] || usage

repo=${RELEASE_REPO:-$project_root}
if [ -n "${RELEASE_OUT_DIR:-}" ]; then
    out_dir=$RELEASE_OUT_DIR
    out_display=$RELEASE_OUT_DIR
else
    out_dir=$project_root/var/releases
    out_display=var/releases
fi

if ! sha=$(git -C "$repo" rev-parse --verify --quiet "${commit}^{commit}" 2>/dev/null) \
    || [ "$(git -C "$repo" cat-file -t "$sha" 2>/dev/null)" != commit ]; then
    echo "build-release: commit not found: $commit" >&2
    exit 2
fi
sha7=${sha:0:7}

staging=$(mktemp -d)
trap 'rm -rf "$staging"' EXIT
export_dir=$staging/export
mkdir -p "$export_dir" "$out_dir"

echo "build-release: exporting $sha"
git -C "$repo" archive --format=tar "$sha" | tar -x -C "$export_dir"

if [ ! -f "$export_dir/src/composer.json" ] || [ ! -f "$export_dir/src/composer.lock" ]; then
    echo "build-release: commit $sha has no src/composer.json or src/composer.lock" >&2
    exit 1
fi

# The image is built from the clean export, before vendor/ is installed there:
# the Dockerfile installs its own dependencies in the `dependencies` stage.
if [ "$build_image" -eq 1 ]; then
    echo "build-release: building centralvet/app:$sha and centralvet/worker:$sha"
    docker build \
        -f docker/php/Dockerfile \
        --build-arg "APP_BUILD_ID=$sha" \
        -t "centralvet/app:$sha" \
        -t "centralvet/worker:$sha" \
        "$export_dir"
fi

echo "build-release: composer install (composer:2.8.11)"
docker run --rm \
    --user "$(id -u):$(id -g)" \
    -e COMPOSER_HOME=/tmp/composer \
    -v "$export_dir/src:/app" \
    -w /app \
    composer:2.8.11 \
    composer install --no-dev --no-interaction --prefer-dist --no-scripts --no-progress

if [ ! -f "$export_dir/src/vendor/autoload.php" ]; then
    echo "build-release: composer install did not produce src/vendor/autoload.php" >&2
    exit 1
fi

manifest=$export_dir/RELEASE_MANIFEST
{
    echo "commit=$sha"
    echo "built_at=$(date -u +%Y-%m-%dT%H:%M:%SZ)"
    echo "php_target=8.4"
    echo "composer_lock_sha256=$(sha256sum "$export_dir/src/composer.lock" | cut -d' ' -f1)"
    migrations_dir=$export_dir/src/app/database/migrations
    if [ -d "$migrations_dir" ]; then
        find "$migrations_dir" -maxdepth 1 -type f -name '2*.sql' ! -name '*verify*' -printf '%f\n' \
            | grep -E '^2[0-9]+_[0-9]+_.*[.]sql$' \
            | LC_ALL=C sort \
            | while IFS= read -r name; do
                version=$(printf '%s' "$name" | cut -d_ -f2)
                echo "migration $version sha256=$(sha256sum "$migrations_dir/$name" | cut -d' ' -f1)"
            done
    fi
} > "$manifest"

name=centralvet-$sha7.tar.gz
tmp_tar=$out_dir/.$name.tmp
tar -C "$export_dir" --sort=name --owner=0 --group=0 --numeric-owner \
    -czf "$tmp_tar" RELEASE_MANIFEST src
mv -f "$tmp_tar" "$out_dir/$name"
(cd "$out_dir" && sha256sum "$name" > "$name.sha256")

echo "release built: $out_display/$name commit=$sha"
