#!/usr/bin/env bash
# Docker-free test of build-release.sh (T-05). A fake `docker` on PATH stands in
# for the composer:2.8.11 container (it writes vendor/autoload.php into the
# mounted src/) and records `docker build` calls. Nothing touches the real
# Docker daemon or the network. Run: bash scripts/release/build-release.test.sh
set -u

script_dir=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
build="$script_dir/build-release.sh"
work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT

mkdir -p "$work/bin" "$work/repo" "$work/out"
cat > "$work/bin/docker" <<FAKE
#!/usr/bin/env bash
echo "docker \$*" >> "$work/docker-calls"
if [ "\${1:-}" = run ]; then
    mount=
    prev=
    for arg in "\$@"; do
        if [ "\$prev" = -v ] && [ "\${arg##*:}" = /app ]; then mount=\${arg%:/app}; fi
        prev=\$arg
    done
    [ -n "\$mount" ] || { echo 'fake docker: no -v <dir>:/app mount' >&2; exit 97; }
    mkdir -p "\$mount/vendor/composer"
    printf '<?php // fake autoload\n' > "\$mount/vendor/autoload.php"
    exit 0
fi
if [ "\${1:-}" = build ]; then
    ctx=\${@: -1}
    [ -f "\$ctx/docker/php/Dockerfile" ] || { echo 'fake docker: context without Dockerfile' >&2; exit 98; }
    echo "context \$ctx" >> "$work/docker-calls"
    # Like the real docker, a relative -f resolves against the current
    # directory, not against the build context.
    dockerfile=
    prev=
    for arg in "\$@"; do
        if [ "\$prev" = -f ]; then dockerfile=\$arg; fi
        prev=\$arg
    done
    [ -f "\$dockerfile" ] || { echo "fake docker: lstat \$dockerfile: no such file" >&2; exit 1; }
    echo "dockerfile \$(cat "\$dockerfile")" >> "$work/docker-calls"
    exit 0
fi
exit 97
FAKE
chmod +x "$work/bin/docker"

repo="$work/repo"
git -C "$repo" init -q
git -C "$repo" config user.email test@example.invalid
git -C "$repo" config user.name test
mkdir -p "$repo/src/app/database/migrations" "$repo/docker/php"
printf '{"name":"fake/app"}\n' > "$repo/src/composer.json"
printf '{"packages":[]}\n' > "$repo/src/composer.lock"
printf 'committed\n' > "$repo/src/index.txt"
printf 'FROM committed\n' > "$repo/docker/php/Dockerfile"
printf 'SELECT 1;\n' > "$repo/src/app/database/migrations/20260101_0001_first.sql"
printf 'SELECT 1;\n' > "$repo/src/app/database/migrations/20260101_0001_first.verify.sql"
printf 'SELECT 2;\n' > "$repo/src/app/database/migrations/20260102_0002_second.sql"
git -C "$repo" add -A
git -C "$repo" commit -qm initial
sha=$(git -C "$repo" rev-parse HEAD)
sha7=${sha:0:7}

# Working-tree noise that must never reach the release.
printf 'uncommitted\n' > "$repo/src/index.txt"
printf 'untracked\n' > "$repo/src/untracked.txt"
printf 'FROM uncommitted\n' > "$repo/docker/php/Dockerfile"

failures=0
pass() { echo "PASS  $1"; }
fail() { echo "FAIL  $1"; failures=$((failures + 1)); }

run_build() {
    PATH="$work/bin:$PATH" RELEASE_REPO="$repo" RELEASE_OUT_DIR="$work/out" bash "$build" "$@" 2>&1
}

if bash -n "$build" 2>/dev/null; then pass 'bash -n'; else fail 'bash -n'; fi

output=$(run_build HEAD)
status=$?
tarball="$work/out/centralvet-$sha7.tar.gz"
if [ "$status" -eq 0 ] && [ "$(printf '%s\n' "$output" | tail -n1)" = "release built: $tarball commit=$sha" ]; then
    pass 'build HEAD prints the release line'
else
    fail "build HEAD prints the release line (exit $status): $output"
fi

manifest=$(tar -xOzf "$tarball" RELEASE_MANIFEST 2>/dev/null)
if printf '%s\n' "$manifest" | grep -qx "commit=$sha"; then pass 'manifest commit equals git rev-parse HEAD'; else fail "manifest commit equals git rev-parse HEAD: $manifest"; fi
if [ "$(printf '%s\n' "$manifest" | grep -c '^migration ')" -eq 2 ]; then pass 'manifest lists 2 migrations'; else fail "manifest lists 2 migrations: $manifest"; fi
if printf '%s\n' "$manifest" | grep -qx 'php_target=8.4' \
    && printf '%s\n' "$manifest" | grep -qE '^built_at=[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$' \
    && printf '%s\n' "$manifest" | grep -qx "composer_lock_sha256=$(sha256sum "$repo/src/composer.lock" | cut -d' ' -f1)" \
    && printf '%s\n' "$manifest" | grep -qx "migration 0001 sha256=$(printf 'SELECT 1;\n' | sha256sum | cut -d' ' -f1)"; then
    pass 'manifest has php_target, built_at, composer_lock_sha256 and migration hashes'
else
    fail "manifest has php_target, built_at, composer_lock_sha256 and migration hashes: $manifest"
fi

if [ "$(tar -xOzf "$tarball" src/index.txt 2>/dev/null)" = committed ] \
    && ! tar -tzf "$tarball" 2>/dev/null | grep -q 'src/untracked.txt'; then
    pass 'uncommitted and untracked changes stay out of the tar'
else
    fail 'uncommitted and untracked changes stay out of the tar'
fi

if [ "$(tar -tzf "$tarball" 2>/dev/null | grep -c '^src/vendor/autoload.php$')" -eq 1 ]; then pass 'tar has src/vendor/autoload.php'; else fail 'tar has src/vendor/autoload.php'; fi

if (cd "$work/out" && sha256sum -c "centralvet-$sha7.tar.gz.sha256" >/dev/null 2>&1); then pass 'sha256sum -c passes'; else fail 'sha256sum -c passes'; fi

output=$(run_build 0123456789abcdef0123456789abcdef01234567)
status=$?
if [ "$status" -eq 2 ]; then pass 'unknown commit exits 2'; else fail "unknown commit exits 2 (exit $status): $output"; fi

: > "$work/docker-calls"
output=$(run_build --image HEAD)
status=$?
calls=$(cat "$work/docker-calls")
if [ "$status" -eq 0 ] \
    && printf '%s\n' "$calls" | grep -q "^docker build .*-t centralvet/app:$sha" \
    && printf '%s\n' "$calls" | grep -q "^docker build .*-t centralvet/worker:$sha" \
    && printf '%s\n' "$calls" | grep -q "^docker build .*--build-arg APP_BUILD_ID=$sha" \
    && printf '%s\n' "$calls" | grep -q '^docker build .*-f docker/php/Dockerfile' \
    && ! printf '%s\n' "$calls" | grep -qx "context $repo"; then
    pass '--image builds app and worker tagged with the sha from the export'
else
    fail "--image builds app and worker tagged with the sha from the export (exit $status): $output / $calls"
fi

: > "$work/docker-calls"
output=$(cd "$work" && run_build --image HEAD)
status=$?
calls=$(cat "$work/docker-calls")
if [ "$status" -eq 0 ] && printf '%s\n' "$calls" | grep -qx 'dockerfile FROM committed'; then
    pass '--image uses the Dockerfile from the export, from any cwd'
else
    fail "--image uses the Dockerfile from the export, from any cwd (exit $status): $output / $calls"
fi

: > "$work/docker-calls"
output=$(cd "$repo" && run_build --image HEAD)
status=$?
calls=$(cat "$work/docker-calls")
if [ "$status" -eq 0 ] && printf '%s\n' "$calls" | grep -qx 'dockerfile FROM committed'; then
    pass '--image ignores the working-tree Dockerfile when run from the repo'
else
    fail "--image ignores the working-tree Dockerfile when run from the repo (exit $status): $output / $calls"
fi

# T-20: the package must not inherit a restrictive umask from the operator.
rm -f "$tarball"
output=$(umask 077; run_build HEAD)
status=$?
listing=$(tar -tvzf "$tarball" 2>/dev/null)
bad_files=$(printf '%s\n' "$listing" | grep '^-' | grep -vc '^-rw-r--r-- ')
bad_dirs=$(printf '%s\n' "$listing" | grep '^d' | grep -vc '^drwxr-xr-x ')
if [ "$status" -eq 0 ] && [ -n "$listing" ] && [ "$bad_files" -eq 0 ] && [ "$bad_dirs" -eq 0 ]; then
    pass 'umask 077: files -rw-r--r-- and dirs drwxr-xr-x in the tar'
else
    fail "umask 077: files -rw-r--r-- and dirs drwxr-xr-x in the tar (exit $status, bad files=$bad_files dirs=$bad_dirs): $(printf '%s\n' "$listing" | grep -E '^-rw-------|^drwx------' | head -n3)"
fi

echo "Failed: $failures"
[ "$failures" -eq 0 ]
