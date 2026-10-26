#!/usr/bin/env bash
# Docker-free test of scripts/restore.sh and scripts/restore-drill.sh (T-11).
# A fake `docker` on PATH records every call (and the stdin of each mysql/tar
# call) and simulates `php bin/hosting-backup.php --decrypt`; gpg is real,
# with a throwaway GNUPGHOME and passphrase. Backups are built here in the
# T-08 format (MANIFEST v1 + parts, tar | gpg --symmetric).
# Run from the project root: bash scripts/restore.test.sh
set -u

script_dir=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
restore="$script_dir/restore.sh"
drill="$script_dir/restore-drill.sh"
work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT

export GNUPGHOME="$work/gnupg"
mkdir -p "$GNUPGHOME" "$work/bin" "$work/src/files/a" "$work/src/documents" "$work/src/minio" "$work/canon" "$work/backups" "$work/outside"
chmod 700 "$GNUPGHOME"
printf 'throwaway-test-passphrase-etapa4\n' > "$work/passphrase"
printf 'another-throwaway-passphrase\n' > "$work/wrong-passphrase"
printf 'COMPOSE_PROJECT_NAME=centralvet\n' > "$work/test.env"

printf 'one\n' > "$work/src/files/a/one.txt"
printf 'two\n' > "$work/src/files/two.txt"
printf 'doc\n' > "$work/src/documents/doc.pdf"
printf 'obj\n' > "$work/src/minio/obj.bin"
tar -czf "$work/canon/files.tar.gz" -C "$work/src/files" .
tar -czf "$work/canon/documents.tar.gz" -C "$work/src/documents" .
tar -czf "$work/canon/minio.tar.gz" -C "$work/src/minio" .
printf -- '-- canonical dump marker-7f3a\nCREATE TABLE tenant (id INT);\n' > "$work/canon/db.sql"
gzip -c "$work/canon/db.sql" > "$work/canon/db.sql.gz"

# make_parts <dir> <minio:yes|no>: MANIFEST v1 plus parts, as scripts/backup.sh writes them.
make_parts() {
    local dir=$1 minio=$2 part
    local parts=(db.sql.gz files.tar.gz documents.tar.gz)
    [ "$minio" = yes ] && parts+=(minio.tar.gz)
    mkdir -p "$dir"
    for part in "${parts[@]}"; do cp "$work/canon/$part" "$dir/$part"; done
    {
        echo 'format=centralvet-backup-v1'
        echo 'created_at=2026-10-26T00:00:00Z'
        echo 'commit=unknown'
        echo 'project=centralvet'
        echo 'mysql_database=centralvet'
        if [ "$minio" = yes ]; then echo 'minio=included'; else echo 'minio=skipped'; fi
        printf 'table_count=42\nrows tenant=3\nrows system_users=5\nrows billing_order=0\nrows communication_message=7\nrows generated_document=2\n'
        printf 'files_count=2\ndocuments_count=1\nmigration 0001\nmigration 0019\n'
        for part in "${parts[@]}"; do echo "sha256 $(sha256sum "$dir/$part" | cut -d' ' -f1) $part"; done
    } > "$dir/MANIFEST"
}

# make_gpg <output> <minio> [tamper]: encrypted .tar.gpg; tamper swaps files.tar.gz after hashing.
make_gpg() {
    local out=$1 minio=$2 tamper=${3:-no} dir
    dir=$(mktemp -d "$work/parts.XXXX")
    make_parts "$dir" "$minio"
    [ "$tamper" = yes ] && cp "$work/canon/documents.tar.gz" "$dir/files.tar.gz"
    local members=(MANIFEST db.sql.gz files.tar.gz documents.tar.gz)
    [ "$minio" = yes ] && members+=(minio.tar.gz)
    tar -cf - -C "$dir" "${members[@]}" | gpg --batch --yes --quiet --pinentry-mode loopback \
        --passphrase-file "$work/passphrase" --symmetric --cipher-algo AES256 -o "$out"
}

good="$work/backups/centralvet-20261026T000001Z.tar.gpg"
withminio="$work/backups/centralvet-20261026T000002Z.tar.gpg"
tampered="$work/backups/centralvet-20261026T000003Z.tar.gpg"
legacy="$work/backups/centralvet-20200101T000000Z.sql.gz"
cvbk="$work/backups/centralvet-20261026T000004Z.tar.gz.cvbk"
make_gpg "$good" no
make_gpg "$withminio" yes
make_gpg "$tampered" no yes
cp "$work/canon/db.sql.gz" "$legacy"
make_parts "$work/pkg" no
tar -czf "$work/canon/package.tar.gz" -C "$work/pkg" MANIFEST db.sql.gz files.tar.gz documents.tar.gz
printf 'CVBK1 opaque bytes\n' > "$cvbk"
cp "$good" "$work/outside/centralvet-20261026T000001Z.tar.gpg"

cat > "$work/bin/docker" <<FAKE
#!/usr/bin/env bash
calls="$work/docker-calls"
printf 'docker %s\n' "\$*" >> "\$calls"
n=\$(wc -l < "\$calls")
args=" \$* "
[ "\$1" = volume ] && exit 0
case "\$args" in
    *" up "*|*" down "*) exit 0 ;;
    *" exec "*)
        stdin=\$(cat)
        case "\$stdin" in
            *restore-drill-verify*)
                tenant=3; [ "\${FAKE_MISMATCH:-no}" = yes ] && tenant=2
                printf 'table_count=42\nrows tenant=%s\nrows system_users=5\nrows billing_order=0\nrows communication_message=7\nrows generated_document=2\nmigrations=2\n' "\$tenant"
                exit 0 ;;
        esac
        printf '%s\n' "\$stdin" > "$work/stdin.\$n"
        printf 'stdin.%s mysql-import\n' "\$n" >> "\$calls"
        exit 0 ;;
    *" run "*)
        case "\$args" in
            *" --decrypt "*)
                [ "\${FAKE_DECRYPT_FAIL:-no}" = yes ] && { echo 'backup: authentication failed' >&2; exit 5; }
                prev=''
                for a in "\$@"; do
                    if [ "\$prev" = -v ]; then
                        case "\$a" in *:/restore-out) cp "$work/canon/package.tar.gz" "\${a%:/restore-out}/package.tar.gz" ;; esac
                    fi
                    prev=\$a
                done
                exit 0 ;;
            *"tar -x"*)
                cat > "$work/stdin.\$n"
                for dest in /var/www/html/src/files /var/www/html/var/documents /restore-minio; do
                    case "\$args" in *" \$dest "*|*" \$dest") printf 'stdin.%s tar-x %s\n' "\$n" "\$dest" >> "\$calls" ;; esac
                done
                exit 0 ;;
            *restore-drill-count*)
                printf 'files_count=2\ndocuments_count=1\n'
                exit 0 ;;
        esac
        exit 98 ;;
esac
exit 97
FAKE
chmod +x "$work/bin/docker"

failures=0
pass() { echo "PASS  $1"; }
fail() { echo "FAIL  $1"; failures=$((failures + 1)); }

# run_restore [args...] — CONFIRM_RESTORE and other overrides come from the caller.
run_restore() {
    : > "$work/docker-calls"
    output=$(PATH="$work/bin:$PATH" BACKUP_DIR="$work/backups" BACKUP_PASSPHRASE_FILE="${PASSFILE:-$work/passphrase}" \
        bash "$restore" --env-file "$work/test.env" "$@" 2>"$work/stderr")
    status=$?
    stderr=$(cat "$work/stderr")
    last=$(printf '%s\n' "$output" | tail -n 1)
}

writes() { grep -cE 'mysql-import|tar-x' "$work/docker-calls"; }
stdin_of() { grep -E "$1" "$work/docker-calls" | head -n 1 | cut -d' ' -f1; }

for script in "$restore" "$drill"; do
    if [ -f "$script" ] && bash -n "$script"; then pass "bash -n $(basename "$script")"; else fail "bash -n $(basename "$script")"; fi
done

# 1. No confirmation → 3 and no docker call.
unset CONFIRM_RESTORE
run_restore "$good"
if [ "$status" -eq 3 ] && [ ! -s "$work/docker-calls" ]; then pass 'without CONFIRM_RESTORE exits 3 with no docker call'; else fail "without CONFIRM_RESTORE exits 3 (exit $status, calls: $(cat "$work/docker-calls"))"; fi

# 2. Tampered part → 5 before any write.
CONFIRM_RESTORE=RESTORE_CENTRALVET run_restore "$tampered"
if [ "$status" -eq 5 ] && [ "$(writes)" -eq 0 ] && [ ! -s "$work/docker-calls" ]; then
    pass 'sha256 diverging from the MANIFEST exits 5 with no mysql and no tar -x'
else
    fail "tampered part exits 5 without writes (exit $status, calls: $(cat "$work/docker-calls"), stderr: $stderr)"
fi

# 3. Wrong passphrase → 5 before any write.
PASSFILE="$work/wrong-passphrase" CONFIRM_RESTORE=RESTORE_CENTRALVET run_restore "$good"
if [ "$status" -eq 5 ] && [ ! -s "$work/docker-calls" ]; then pass 'wrong passphrase exits 5 with no docker call'; else fail "wrong passphrase exits 5 (exit $status)"; fi

# 4. Full restore of the .tar.gpg into the default project.
CONFIRM_RESTORE=RESTORE_CENTRALVET run_restore "$good"
db_in=$(stdin_of mysql-import); files_in=$(stdin_of 'tar-x /var/www/html/src/files'); docs_in=$(stdin_of 'tar-x /var/www/html/var/documents')
if [ "$status" -eq 0 ] && [ "$last" = "Restore completed from: $good project=centralvet parts=4" ]; then
    pass 'restore exits 0 with Restore completed from: <file> project=centralvet parts=4'
else
    fail "restore exits 0 with the final line (exit $status, last: $last, stderr: $stderr)"
fi
if [ -n "$db_in" ] && grep -qF 'marker-7f3a' "$work/$db_in" && grep -qF 'CREATE TABLE tenant (id INT);' "$work/$db_in"; then
    pass 'the uncompressed db.sql.gz reaches mysql'
else
    fail 'the uncompressed db.sql.gz reaches mysql'
fi
if [ -n "$files_in" ] && cmp -s "$work/$files_in" "$work/canon/files.tar.gz" && [ -n "$docs_in" ] && cmp -s "$work/$docs_in" "$work/canon/documents.tar.gz"; then
    pass 'files.tar.gz and documents.tar.gz reach tar -x of the project volumes'
else
    fail "files/documents reach tar -x (calls: $(cat "$work/docker-calls"))"
fi
if ! grep -q 'restore-minio' "$work/docker-calls" && [ "$(grep '^docker compose' "$work/docker-calls" | grep -vc -- '-p centralvet ')" -eq 0 ]; then
    pass 'minio=skipped restores no MinIO and every call uses -p centralvet'
else
    fail 'minio=skipped / -p centralvet'
fi

# 5. --project centralvet-drill: RESTORE_CENTRALVET is not enough; RESTORE_CENTRALVET_DRILL is.
CONFIRM_RESTORE=RESTORE_CENTRALVET run_restore --project centralvet-drill "$good"
if [ "$status" -eq 3 ] && [ ! -s "$work/docker-calls" ]; then pass '--project centralvet-drill refuses RESTORE_CENTRALVET (exit 3)'; else fail "--project centralvet-drill refuses RESTORE_CENTRALVET (exit $status)"; fi
CONFIRM_RESTORE=RESTORE_CENTRALVET_DRILL run_restore --project centralvet-drill "$withminio"
compose_calls=$(grep -c '^docker compose' "$work/docker-calls")
bad_calls=$(grep '^docker compose' "$work/docker-calls" | grep -vc -- '-p centralvet-drill ')
minio_in=$(stdin_of 'tar-x /restore-minio')
if [ "$status" -eq 0 ] && [ "$compose_calls" -gt 0 ] && [ "$bad_calls" -eq 0 ] \
    && [ "$last" = "Restore completed from: $withminio project=centralvet-drill parts=5" ]; then
    pass 'with --project centralvet-drill every call carries -p centralvet-drill'
else
    fail "--project centralvet-drill ($bad_calls of $compose_calls without it, exit $status, last: $last, stderr: $stderr)"
fi
if [ -n "$minio_in" ] && cmp -s "$work/$minio_in" "$work/canon/minio.tar.gz" && grep -q 'centralvet-drill_minio_data:/restore-minio' "$work/docker-calls"; then
    pass 'minio=included restores minio.tar.gz into <project>_minio_data'
else
    fail 'minio=included restores minio.tar.gz'
fi

# 6. Legacy .sql.gz still restores.
CONFIRM_RESTORE=RESTORE_CENTRALVET run_restore "$legacy"
db_in=$(stdin_of mysql-import)
if [ "$status" -eq 0 ] && [ -n "$db_in" ] && grep -qF 'marker-7f3a' "$work/$db_in" && ! grep -q 'tar-x' "$work/docker-calls" \
    && [ "$last" = "Restore completed from: $legacy project=centralvet parts=1" ]; then
    pass 'legacy .sql.gz restores the database only'
else
    fail "legacy .sql.gz (exit $status, last: $last, stderr: $stderr)"
fi

# 7. .tar.gz.cvbk goes through hosting-backup.php --decrypt and restores the same parts.
CONFIRM_RESTORE=RESTORE_CENTRALVET run_restore "$cvbk"
db_in=$(stdin_of mysql-import); files_in=$(stdin_of 'tar-x /var/www/html/src/files')
if [ "$status" -eq 0 ] && grep -q '^docker compose .* run --rm --no-deps -T .* app php bin/hosting-backup.php --decrypt ' "$work/docker-calls" \
    && [ -n "$db_in" ] && grep -qF 'marker-7f3a' "$work/$db_in" && [ -n "$files_in" ] && cmp -s "$work/$files_in" "$work/canon/files.tar.gz" \
    && grep -q 'tar-x /var/www/html/var/documents' "$work/docker-calls" \
    && [ "$last" = "Restore completed from: $cvbk project=centralvet parts=4" ]; then
    pass '.tar.gz.cvbk is decrypted by hosting-backup.php --decrypt and restored'
else
    fail ".tar.gz.cvbk restore (exit $status, last: $last, stderr: $stderr, calls: $(cat "$work/docker-calls"))"
fi
FAKE_DECRYPT_FAIL=yes CONFIRM_RESTORE=RESTORE_CENTRALVET run_restore "$cvbk"
if [ "$status" -eq 5 ] && [ "$(writes)" -eq 0 ] && ! grep -q ' exec ' "$work/docker-calls"; then
    pass 'failing --decrypt exits 5 with no write'
else
    fail "failing --decrypt exits 5 (exit $status, calls: $(cat "$work/docker-calls"))"
fi

# 8. A file outside BACKUP_DIR is refused.
CONFIRM_RESTORE=RESTORE_CENTRALVET run_restore "$work/outside/centralvet-20261026T000001Z.tar.gpg"
if [ "$status" -eq 2 ] && [ ! -s "$work/docker-calls" ]; then pass 'file outside BACKUP_DIR exits 2'; else fail "file outside BACKUP_DIR exits 2 (exit $status)"; fi

# 9. restore-drill.sh: only centralvet-drill, verify lines, done line, down -v at the end.
run_drill() {
    : > "$work/docker-calls"
    output=$(PATH="$work/bin:$PATH" BACKUP_DIR="$work/backups" BACKUP_PASSPHRASE_FILE="$work/passphrase" \
        RESTORE_DRILL_ENV_FILE="$work/drill.env" CONFIRM_RESTORE= bash "$drill" "$@" 2>"$work/stderr")
    status=$?
    stderr=$(cat "$work/stderr")
    last=$(printf '%s\n' "$output" | tail -n 1)
}
run_drill "$withminio"
compose_calls=$(grep -c '^docker compose' "$work/docker-calls")
bad_calls=$(grep '^docker compose' "$work/docker-calls" | grep -vc -- '-p centralvet-drill ')
last_compose=$(grep '^docker compose' "$work/docker-calls" | tail -n 1)
if [ "$status" -eq 0 ] && [ "$compose_calls" -gt 0 ] && [ "$bad_calls" -eq 0 ]; then
    pass 'restore-drill uses only -p centralvet-drill'
else
    fail "restore-drill uses only -p centralvet-drill ($bad_calls of $compose_calls without it, exit $status, stderr: $stderr)"
fi
if printf '%s' "$last" | grep -Eq '^restore-drill done elapsed_seconds=[0-9]+ decrypt_seconds=[0-9]+ db_seconds=[0-9]+ files_seconds=[0-9]+ mismatches=0$' \
    && printf '%s\n' "$output" | grep -qx 'restore-drill verify table_count manifest=42 restored=42' \
    && printf '%s\n' "$output" | grep -qx 'restore-drill verify rows tenant manifest=3 restored=3' \
    && printf '%s\n' "$output" | grep -qx 'restore-drill verify files_count manifest=2 restored=2' \
    && printf '%s\n' "$output" | grep -qx 'restore-drill verify documents_count manifest=1 restored=1' \
    && printf '%s\n' "$output" | grep -qx 'restore-drill verify migrations manifest=2 restored=2'; then
    pass 'restore-drill prints the verify lines and restore-drill done ... mismatches=0'
else
    fail "restore-drill verify/done lines (last: $last, output: $output)"
fi
if printf '%s' "$last_compose" | grep -q -- ' down -v' && grep -q '^docker compose .* up -d --wait mysql redis' "$work/docker-calls"; then
    pass 'restore-drill starts mysql redis with --wait and ends with down -v of centralvet-drill'
else
    fail "restore-drill up/down (last compose: $last_compose)"
fi
if grep -qx 'COMPOSE_PROJECT_NAME=centralvet-drill' "$work/drill.env" && grep -qx 'HTTP_PORT=18081' "$work/drill.env" \
    && grep -Eq '^MYSQL_ROOT_PASSWORD=[0-9a-f]{32,}$' "$work/drill.env" && [ "$(stat -c %a "$work/drill.env")" = 600 ]; then
    pass 'drill env-file generated with random secrets, centralvet-drill and port 18081 (mode 600)'
else
    fail 'drill env-file generated'
fi
if grep -q 'CONFIRM\|RESTORE_CENTRALVET ' "$work/docker-calls"; then fail 'no confirmation leaks into docker args'; else pass 'no confirmation leaks into docker args'; fi

FAKE_MISMATCH=yes run_drill "$withminio"
last_compose=$(grep '^docker compose' "$work/docker-calls" | tail -n 1)
if [ "$status" -eq 1 ] && printf '%s' "$last" | grep -q 'mismatches=1$' && printf '%s' "$last_compose" | grep -q -- ' down -v'; then
    pass 'a count mismatch exits 1 and still runs down -v'
else
    fail "mismatch exits 1 (exit $status, last: $last)"
fi

run_drill --keep "$good"
if [ "$status" -eq 0 ] && ! grep -q -- ' down ' "$work/docker-calls"; then pass '--keep leaves the drill project up'; else fail "--keep (exit $status)"; fi

echo "Failed: $failures"
[ "$failures" -eq 0 ]
