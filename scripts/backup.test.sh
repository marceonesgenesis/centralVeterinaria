#!/usr/bin/env bash
# Docker-free test of scripts/backup.sh (T-08). A fake `docker` (and `aws`) on
# PATH records every call and serves a canonical dump, canonical tars and
# canned MANIFEST lines; gpg is real, with a throwaway GNUPGHOME and passphrase.
# Run from the project root: bash scripts/backup.test.sh
set -u

script_dir=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
backup="$script_dir/backup.sh"
work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT

export GNUPGHOME="$work/gnupg"
mkdir -p "$GNUPGHOME" "$work/bin" "$work/src/files/a" "$work/src/documents" "$work/src/minio" "$work/canon"
chmod 700 "$GNUPGHOME"
printf 'throwaway-test-passphrase-etapa4\n' > "$work/passphrase"
: > "$work/test.env"

printf 'one\n' > "$work/src/files/a/one.txt"
printf 'two\n' > "$work/src/files/two.txt"
printf 'doc\n' > "$work/src/documents/doc.pdf"
printf 'obj\n' > "$work/src/minio/obj.bin"
tar -czf "$work/canon/files.tar.gz" -C "$work/src/files" .
tar -czf "$work/canon/documents.tar.gz" -C "$work/src/documents" .
tar -czf "$work/canon/minio.tar.gz" -C "$work/src/minio" .
printf -- '-- canonical dump\nCREATE TABLE tenant (id INT);\n' > "$work/canon/db.sql"

cat > "$work/bin/docker" <<FAKE
#!/usr/bin/env bash
calls="$work/docker-calls"
canon="$work/canon"
printf 'docker %s\n' "\$*" >> "\$calls"
args=" \$* "
if [ "\$1" = volume ]; then
    [ "\${FAKE_MINIO:-no}" = yes ] && { echo '[{"Name":"x"}]'; exit 0; }
    echo 'Error response from daemon: get x: no such volume' >&2
    exit 1
fi
case "\$args" in
    *" exec "*)
        stdin=\$(cat)
        printf 'stdin %s\n' "\$stdin" >> "\$calls"
        case "\$args" in
            *mysqldump*)
                [ "\${FAKE_DUMP_FAIL:-no}" = yes ] && { echo 'mysqldump: Got error' >&2; exit 2; }
                cat "\$canon/db.sql"; exit 0 ;;
        esac
        case "\$stdin" in
            *INSERT*) [ "\${FAKE_INSERT_FAIL:-no}" = yes ] && { echo "ERROR 1146: Table 'ops_heartbeat' doesn't exist" >&2; exit 1; }; exit 0 ;;
            *table_count*)
                printf 'mysql_database=centralvet\ntable_count=42\nrows tenant=3\nrows system_users=5\nrows billing_order=0\nrows communication_message=7\nrows generated_document=2\nmigration 0001\nmigration 0019\n'
                exit 0 ;;
        esac
        exit 0 ;;
    *" run "*)
        case "\$args" in
            *"/var/www/html/src/files"*) cat "\$canon/files.tar.gz" ;;
            *"/var/www/html/var/documents"*) cat "\$canon/documents.tar.gz" ;;
            *"/backup-minio"*) cat "\$canon/minio.tar.gz" ;;
            *) exit 98 ;;
        esac
        exit 0 ;;
esac
exit 97
FAKE
cat > "$work/bin/aws" <<FAKE
#!/usr/bin/env bash
printf 'aws %s\n' "\$*" >> "$work/aws-calls"
[ "\${FAKE_AWS_FAIL:-no}" = yes ] && exit 1
exit 0
FAKE
chmod +x "$work/bin/docker" "$work/bin/aws"

failures=0
pass() { echo "PASS  $1"; }
fail() { echo "FAIL  $1"; failures=$((failures + 1)); }

# run_backup <dir> [args...] — env overrides come from the caller's environment.
run_backup() {
    local dir=$1; shift
    : > "$work/docker-calls"
    mkdir -p "$dir"
    output=$(PATH="$work/bin:$PATH" BACKUP_DIR="$dir" bash "$backup" --env-file "$work/test.env" "$@" 2>"$work/stderr")
    status=$?
    stderr=$(cat "$work/stderr")
}

created_file() { ls "$1"/centralvet-*.tar.gpg 2>/dev/null | grep -v 20200101T000000Z | head -n 1; }

decrypt_to() {
    rm -rf "$2"; mkdir -p "$2"
    gpg --batch --quiet --pinentry-mode loopback --passphrase-file "$work/passphrase" -d "$1" 2>/dev/null | tar -xf - -C "$2"
}

if bash -n "$backup"; then pass 'bash -n'; else fail 'bash -n'; fi

# 1. Missing passphrase file → 2, nothing created.
run_backup "$work/b-nopass"
if [ "$status" -eq 2 ] && [ -z "$(ls -A "$work/b-nopass")" ]; then
    pass 'without BACKUP_PASSPHRASE_FILE exits 2 and creates nothing'
else
    fail "without BACKUP_PASSPHRASE_FILE exits 2 and creates nothing (exit $status, dir: $(ls -A "$work/b-nopass"))"
fi
BACKUP_PASSPHRASE_FILE="$work/missing" run_backup "$work/b-nopass2"
if [ "$status" -eq 2 ] && [ -z "$(ls -A "$work/b-nopass2")" ]; then pass 'unreadable passphrase file exits 2'; else fail "unreadable passphrase file exits 2 (exit $status)"; fi

# 2. Invalid option → 2.
BACKUP_PASSPHRASE_FILE="$work/passphrase" run_backup "$work/b-opt" --bogus
if [ "$status" -eq 2 ]; then pass 'invalid option exits 2'; else fail "invalid option exits 2 (exit $status)"; fi

# 3. Full run: project, retention, offsite dir, heartbeat, minio skipped.
main="$work/b-main"
mkdir -p "$main" "$work/offsite"
old="$main/centralvet-20200101T000000Z.tar.gpg"
legacy="$main/centralvet-20200101T000000Z.sql.gz"
printf 'old\n' > "$old"; printf 'legacy\n' > "$legacy"
touch -d '20 days ago' "$old" "$legacy"
BACKUP_PASSPHRASE_FILE="$work/passphrase" BACKUP_RETENTION_DAYS=14 BACKUP_OFFSITE_DIR="$work/offsite" \
    run_backup "$main" --project centralvet-drill
new=$(created_file "$main")
last=$(printf '%s\n' "$output" | tail -n 1)
if [ "$status" -eq 0 ] && [ -n "$new" ] && printf '%s' "$last" | grep -Eq "^Backup created: $main/centralvet-[0-9]{8}T[0-9]{6}Z\.tar\.gpg parts=4 bytes=[0-9]+$"; then
    pass 'backup exits 0 and the last line is Backup created ... parts=4 bytes=N'
else
    fail "backup exits 0 and the last line is Backup created (exit $status, last: $last, stderr: $stderr)"
fi

if [ -n "$new" ] && decrypt_to "$new" "$work/x-main" && [ -f "$work/x-main/MANIFEST" ]; then
    parts_ok=1
    for part in db.sql.gz files.tar.gz documents.tar.gz; do
        [ -f "$work/x-main/$part" ] || parts_ok=0
        sum=$(sha256sum "$work/x-main/$part" | cut -d' ' -f1)
        grep -qx "sha256 $sum $part" "$work/x-main/MANIFEST" || parts_ok=0
    done
    if [ "$parts_ok" -eq 1 ]; then pass 'decrypts with the test passphrase; parts present and sha256 matches the MANIFEST'; else fail "parts/sha256 (manifest: $(tr '\n' '|' < "$work/x-main/MANIFEST"))"; fi

    manifest_ok=1
    for line in 'format=centralvet-backup-v1' 'project=centralvet-drill' 'mysql_database=centralvet' 'minio=skipped' \
        'table_count=42' 'rows tenant=3' 'rows generated_document=2' 'files_count=2' 'documents_count=1' 'migration 0019'; do
        grep -qx "$line" "$work/x-main/MANIFEST" || { manifest_ok=0; echo "      missing: $line"; }
    done
    grep -Eq '^created_at=[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$' "$work/x-main/MANIFEST" || { manifest_ok=0; echo '      bad created_at'; }
    grep -Eq '^commit=([0-9a-f]{40}|unknown)$' "$work/x-main/MANIFEST" || { manifest_ok=0; echo '      bad commit'; }
    if [ "$manifest_ok" -eq 1 ]; then pass 'MANIFEST v1 lines (minio=skipped without the volume)'; else fail 'MANIFEST v1 lines'; fi
    if [ ! -e "$work/x-main/minio.tar.gz" ] && [ "$(gzip -dc "$work/x-main/db.sql.gz")" = "$(cat "$work/canon/db.sql")" ]; then
        pass 'db.sql.gz is the dump and no minio.tar.gz without the volume'
    else
        fail 'db.sql.gz is the dump and no minio.tar.gz without the volume'
    fi
else
    fail 'decrypts with the test passphrase'
fi

if [ ! -e "$old" ] && [ -e "$legacy" ] && [ -n "$new" ] && printf '%s' "$output" | grep -qF "backup: pruned $old"; then
    pass 'retention prunes the 20-day-old .tar.gpg, keeps the new one and the legacy .sql.gz'
else
    fail "retention (old exists: $([ -e "$old" ] && echo y), legacy exists: $([ -e "$legacy" ] && echo y), output: $output)"
fi

offsite_copy="$work/offsite/$(basename "${new:-none}")"
if [ -n "$new" ] && [ -f "$offsite_copy" ] && [ "$(sha256sum < "$new")" = "$(sha256sum < "$offsite_copy")" ] \
    && printf '%s' "$output" | grep -qF "backup: offsite copy $offsite_copy"; then
    pass 'BACKUP_OFFSITE_DIR receives a copy with the same sha256'
else
    fail "BACKUP_OFFSITE_DIR copy (output: $output)"
fi

if grep -q "INSERT INTO ops_heartbeat (job, last_success_at, detail) VALUES ('backup', NOW(6), '$(basename "${new:-none}")') ON DUPLICATE KEY UPDATE" "$work/docker-calls" \
    && grep -q 'MYSQL_USER' "$work/docker-calls"; then
    pass 'heartbeat INSERT into ops_heartbeat recorded with MYSQL_USER'
else
    fail "heartbeat INSERT recorded ($(grep -c INSERT "$work/docker-calls") inserts)"
fi

compose_calls=$(grep -c '^docker compose' "$work/docker-calls")
bad_calls=$(grep '^docker compose' "$work/docker-calls" | grep -vc -- '-p centralvet-drill')
if [ "$compose_calls" -gt 0 ] && [ "$bad_calls" -eq 0 ] && grep -q '^docker compose .*--env-file ' "$work/docker-calls" \
    && [ "$(grep '^docker compose' "$work/docker-calls" | grep -vc -- "--env-file $work/test.env")" -eq 0 ] \
    && grep -q '^docker volume inspect centralvet-drill_minio_data' "$work/docker-calls"; then
    pass '--project centralvet-drill and --env-file in every docker compose call'
else
    fail "--project in every compose call ($bad_calls of $compose_calls without it)"
fi

# 4. Heartbeat INSERT fails → still 0 with the warning.
FAKE_INSERT_FAIL=yes BACKUP_PASSPHRASE_FILE="$work/passphrase" run_backup "$work/b-hbfail"
if [ "$status" -eq 0 ] && printf '%s' "$stderr" | grep -qF 'backup: heartbeat not recorded' \
    && printf '%s\n' "$output" | tail -n 1 | grep -q '^Backup created: '; then
    pass 'failed heartbeat INSERT exits 0 with backup: heartbeat not recorded'
else
    fail "failed heartbeat INSERT (exit $status, stderr: $stderr)"
fi

# 5. --no-heartbeat → no INSERT at all.
BACKUP_PASSPHRASE_FILE="$work/passphrase" run_backup "$work/b-nohb" --no-heartbeat
if [ "$status" -eq 0 ] && ! grep -q 'INSERT' "$work/docker-calls"; then
    pass '--no-heartbeat sends no INSERT'
else
    fail "--no-heartbeat sends no INSERT (exit $status)"
fi

# 6. MinIO volume present → minio=included and minio.tar.gz.
FAKE_MINIO=yes BACKUP_PASSPHRASE_FILE="$work/passphrase" run_backup "$work/b-minio" --no-heartbeat
new=$(created_file "$work/b-minio")
if [ "$status" -eq 0 ] && [ -n "$new" ] && decrypt_to "$new" "$work/x-minio" && grep -qx 'minio=included' "$work/x-minio/MANIFEST" \
    && grep -qx "sha256 $(sha256sum "$work/x-minio/minio.tar.gz" | cut -d' ' -f1) minio.tar.gz" "$work/x-minio/MANIFEST" \
    && printf '%s\n' "$output" | tail -n 1 | grep -q 'parts=5 '; then
    pass 'with the MinIO volume: minio=included, minio.tar.gz and parts=5'
else
    fail "with the MinIO volume (exit $status, stderr: $stderr)"
fi

# 7. Dump failure → 3, nothing left in BACKUP_DIR.
FAKE_DUMP_FAIL=yes BACKUP_PASSPHRASE_FILE="$work/passphrase" run_backup "$work/b-dumpfail" --no-heartbeat
if [ "$status" -eq 3 ] && [ -z "$(ls -A "$work/b-dumpfail")" ]; then
    pass 'dump failure exits 3 and leaves nothing in BACKUP_DIR'
else
    fail "dump failure exits 3 and leaves nothing (exit $status, dir: $(ls -A "$work/b-dumpfail"))"
fi

# 8. S3-compatible offsite copy with endpoint, and its failure → 4 with the local file kept.
: > "$work/aws-calls"
BACKUP_PASSPHRASE_FILE="$work/passphrase" BACKUP_OFFSITE_S3_URI='s3://bucket/centralvet' BACKUP_OFFSITE_S3_ENDPOINT='http://127.0.0.1:9000' \
    run_backup "$work/b-s3" --no-heartbeat
new=$(created_file "$work/b-s3")
if [ "$status" -eq 0 ] && [ -n "$new" ] \
    && grep -qF "aws s3 cp $new s3://bucket/centralvet/$(basename "$new") --endpoint-url http://127.0.0.1:9000" "$work/aws-calls"; then
    pass 'BACKUP_OFFSITE_S3_URI copies with aws s3 cp --endpoint-url'
else
    fail "S3 offsite copy (exit $status, aws: $(cat "$work/aws-calls"))"
fi
FAKE_AWS_FAIL=yes BACKUP_PASSPHRASE_FILE="$work/passphrase" BACKUP_OFFSITE_S3_URI='s3://bucket/centralvet' \
    run_backup "$work/b-s3fail" --no-heartbeat
if [ "$status" -eq 4 ] && [ -n "$(created_file "$work/b-s3fail")" ]; then
    pass 'failed offsite copy exits 4 and keeps the local file'
else
    fail "failed offsite copy exits 4 (exit $status)"
fi

echo "Failed: $failures"
[ "$failures" -eq 0 ]
