#!/usr/bin/env bash
set -Eeuo pipefail

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
TEST_ROOT="$(mktemp -d "${TMPDIR:-/tmp}/fin-refresh-test.XXXXXX")"
writer_pid=""
trap '[[ -z "$writer_pid" ]] || kill "$writer_pid" 2>/dev/null || true; rm -rf "$TEST_ROOT"' EXIT

APP_FILES=(api.php report.php recommendation.php accounting.php statement_import.php statement_reconciliation.php auth.php config.php currency.php ecb_rates.php VERSION)

make_case() {
  local name="$1"
  local root="$TEST_ROOT/$name"
  local app="$root/app"
  local remote="$root/remote"
  mkdir -p "$app/scripts" "$app/data" "$remote/data" "$root/fake-bin" "$root/home" "$root/auth" "$root/backups" "$root/remote-backups"
  cp "${APP_FILES[@]/#/$PROJECT_ROOT/}" "$app/"
  cp "${APP_FILES[@]/#/$PROJECT_ROOT/}" "$remote/"
  cp "$PROJECT_ROOT/scripts/refresh-dev-from-prod.sh" "$app/scripts/"
  chmod +x "$app/scripts/refresh-dev-from-prod.sh"
  printf 'config.local.php\ndata/*\n!data/.htaccess\n' > "$app/.gitignore"
  printf 'deny\n' > "$app/data/.htaccess"
  cat > "$app/config.local.php" <<EOF
<?php
return [
    'auth' => [
        'master_pin_file' => '$root/auth/master-pin.hash',
        'throttle_file' => '$root/auth/throttle.json',
        'file_group' => '',
        'pin_failure_delay_us' => 0,
    ],
    'webauthn_rp_id' => 'localhost',
    'webauthn_origin' => 'http://localhost:8080',
];
EOF
  php -r 'file_put_contents($argv[1], password_hash("12345678", PASSWORD_ARGON2ID) . PHP_EOL); chmod($argv[1], 0600);' "$root/auth/master-pin.hash"

  (
    cd "$app"
    git init -q
    git config user.name 'FIN refresh test'
    git config user.email 'refresh-test@example.invalid'
    git add .
    git commit -qm 'synthetic dev checkout'
    FIN_DB_FILE="$app/data/fin.sqlite" php api.php migrate >/dev/null
    FIN_DB_FILE="$app/data/fin.sqlite" php -r '
      require "api.php";
      $db = db();
      $principal = $db->prepare("UPDATE auth_principal SET user_handle=:handle,created_at=\"2026-01-01 00:00:00\" WHERE id=1");
      $principal->bindValue(":handle", str_repeat("D", 32), PDO::PARAM_LOB);
      $principal->execute();
      $credential = $db->prepare("INSERT INTO webauthn_credentials (credential_id,public_key,sign_count,transports,label,created_at,last_used_at) VALUES (:id,:key,7,:transports,:label,:created,:used)");
      $credential->execute([":id" => "dev-credential-id", ":key" => "dev-public-key", ":transports" => "[\"internal\"]", ":label" => "DEV DEVICE", ":created" => "2026-01-02 00:00:00", ":used" => "2026-01-03 00:00:00"]);
      save_month($db, ["year" => 2025, "month" => 1, "notes" => "DEV DOMAIN MARKER"]);
      save_envelope($db, ["name" => "Dev envelope", "account" => "Dev", "role" => "daily"]);
    '
  )

  (
    cd "$remote"
    FIN_DB_FILE="$remote/data/fin.sqlite" php api.php migrate >/dev/null
    FIN_DB_FILE="$remote/data/fin.sqlite" php -r '
      require "api.php";
      $db = db();
      $principal = $db->prepare("UPDATE auth_principal SET user_handle=:handle,created_at=\"2026-02-01 00:00:00\" WHERE id=1");
      $principal->bindValue(":handle", str_repeat("P", 32), PDO::PARAM_LOB);
      $principal->execute();
      $credential = $db->prepare("INSERT INTO webauthn_credentials (credential_id,public_key,sign_count,transports,label,created_at) VALUES (:id,:key,11,:transports,:label,:created)");
      $credential->execute([":id" => "prod-credential-id", ":key" => "prod-public-key", ":transports" => "[]", ":label" => "PROD DEVICE", ":created" => "2026-02-02 00:00:00"]);
      save_month($db, ["year" => 2026, "month" => 8, "notes" => "PRODUCTION DOMAIN MARKER"]);
      save_envelope($db, ["name" => "Production envelope", "account" => "Production", "role" => "daily"]);
    '
  )

  cat > "$root/fake-bin/ssh" <<'EOF'
#!/usr/bin/env bash
set -Eeuo pipefail
[[ "${FIN_REFRESH_TEST_MODE:-}" == "1" ]] || { echo 'fake SSH requires test mode' >&2; exit 90; }
printf 'SSH' >> "${FIN_TEST_TRANSPORT_LOG:?}"
printf '\t%q' "$@" >> "$FIN_TEST_TRANSPORT_LOG"
printf '\n' >> "$FIN_TEST_TRANSPORT_LOG"
[[ "${1:-}" != '--' ]] || shift
host="${1:-}"; shift
[[ "$host" == 'fake-prod' ]] || { echo 'unexpected fake SSH host' >&2; exit 91; }
command="${1:-}"
if [[ "$command" == rm\ -f\ --* ]]; then
  bash -c "$command"
  exit
fi
output="$(bash -c "$command")"
if [[ "${FIN_TEST_REMOTE_MUTATE:-}" == 'integrity' ]]; then
  snapshot="$(FIN_JSON="$output" php -r '$v=json_decode(getenv("FIN_JSON"),true,512,JSON_THROW_ON_ERROR);echo $v["snapshot_file"];')"
  printf 'BROKEN-SQLITE-PAGE' | dd of="$snapshot" bs=1 seek=120 conv=notrunc status=none
  checksum="$(sha256sum "$snapshot" | awk '{print $1}')"
  output="$(FIN_JSON="$output" FIN_SHA="$checksum" php -r '$v=json_decode(getenv("FIN_JSON"),true,512,JSON_THROW_ON_ERROR);$v["sha256"]=getenv("FIN_SHA");echo json_encode($v,JSON_UNESCAPED_SLASHES);')"
fi
printf '%s\n' "$output"
EOF

  cat > "$root/fake-bin/scp" <<'EOF'
#!/usr/bin/env bash
set -Eeuo pipefail
[[ "${FIN_REFRESH_TEST_MODE:-}" == "1" ]] || { echo 'fake SCP requires test mode' >&2; exit 92; }
printf 'SCP' >> "${FIN_TEST_TRANSPORT_LOG:?}"
printf '\t%q' "$@" >> "$FIN_TEST_TRANSPORT_LOG"
printf '\n' >> "$FIN_TEST_TRANSPORT_LOG"
[[ "${1:-}" != '--' ]] || shift
source_spec="${1:-}"; destination="${2:-}"
[[ "$source_spec" == fake-prod:* ]] || { echo 'fake SCP permits remote source only' >&2; exit 93; }
[[ "$destination" != *:* ]] || { echo 'fake SCP refuses remote destination' >&2; exit 94; }
cp -- "${source_spec#fake-prod:}" "$destination"
if [[ "${FIN_TEST_SCP_MODE:-}" == 'checksum' ]]; then printf 'changed' >> "$destination"; fi
EOF
  chmod +x "$root/fake-bin/ssh" "$root/fake-bin/scp"
}

run_refresh() {
  local root="$1"; shift
  env \
    HOME="$root/home" \
    FIN_BACKUP_DIR="$root/backups" \
    FIN_PROD_SSH='fake-prod' \
    FIN_PROD_ROOT="$root/remote" \
    FIN_PROD_PHP='php' \
    FIN_PROD_BACKUP_DIR="$root/remote-backups" \
    FIN_REFRESH_CONFIRM='REPLACE_DEV' \
    FIN_REFRESH_TEST_MODE=1 \
    FIN_REFRESH_FAKE_TRANSPORT_DIR="$root/fake-bin" \
    FIN_SSH_BIN="$root/fake-bin/ssh" \
    FIN_SCP_BIN="$root/fake-bin/scp" \
    FIN_TEST_TRANSPORT_LOG="$root/transport.log" \
    PHP_BIN='/usr/bin/php' \
    "$@" \
    "$root/app/scripts/refresh-dev-from-prod.sh"
}

assert_dev_unchanged() {
  local root="$1"
  FIN_DB="$root/app/data/fin.sqlite" php -r '
    $db=new PDO("sqlite:".getenv("FIN_DB"));
    if ((int)$db->query("SELECT COUNT(*) FROM months WHERE notes=\"DEV DOMAIN MARKER\"")->fetchColumn() !== 1) exit(1);
    if ((int)$db->query("SELECT COUNT(*) FROM months WHERE notes=\"PRODUCTION DOMAIN MARKER\"")->fetchColumn() !== 0) exit(1);
    if ((string)$db->query("SELECT hex(user_handle) FROM auth_principal WHERE id=1")->fetchColumn() !== strtoupper(bin2hex(str_repeat("D",32)))) exit(1);
    if ((int)$db->query("SELECT COUNT(*) FROM webauthn_credentials WHERE label=\"DEV DEVICE\"")->fetchColumn() !== 1) exit(1);
  '
}

expect_failure() {
  local name="$1"; shift
  make_case "$name"
  local root="$TEST_ROOT/$name"
  if run_refresh "$root" "$@" >"$root/output.log" 2>&1; then
    echo "$name should fail" >&2
    exit 1
  fi
  ! grep -q '^READY$' "$root/output.log"
  assert_dev_unchanged "$root"
  if [[ -f "$root/transport.log" ]] && grep -q 'refresh-snapshot' "$root/transport.log"; then
    test -z "$(find "$root/remote-backups" -maxdepth 1 -type f -name 'fin-prod-dev-refresh-*.sqlite' -print -quit)"
  fi
}

# Complete successful flow.
make_case success
success="$TEST_ROOT/success"
production_source_hash="$(sha256sum "$success/remote/data/fin.sqlite" | awk '{print $1}')"
dev_config_hash="$(sha256sum "$success/app/config.local.php" | awk '{print $1}')"
dev_pin_hash="$(sha256sum "$success/auth/master-pin.hash" | awk '{print $1}')"
[[ -z "$(git -C "$success/app" status --short)" ]]
run_refresh "$success" >"$success/output.log" 2>&1
[[ "$(sha256sum "$success/remote/data/fin.sqlite" | awk '{print $1}')" == "$production_source_hash" ]]
[[ "$(sha256sum "$success/app/config.local.php" | awk '{print $1}')" == "$dev_config_hash" ]]
[[ "$(sha256sum "$success/auth/master-pin.hash" | awk '{print $1}')" == "$dev_pin_hash" ]]
[[ -z "$(git -C "$success/app" status --short)" ]]
grep -q '^READY$' "$success/output.log"
grep -q '^DEV authentication: preserved$' "$success/output.log"
test "$(stat -c '%a' "$success/app/data/fin.sqlite")" = '600'
test "$(stat -c '%a' "$success/backups")" = '700'
backup="$(find "$success/backups" -maxdepth 1 -type f -name 'fin-dev-before-prod-refresh-*.sqlite' -print -quit)"
test -n "$backup"
test "$(stat -c '%a' "$backup")" = '600'
test -z "$(find "$success/remote-backups" -maxdepth 1 -type f -name 'fin-prod-dev-refresh-*.sqlite' -print -quit)"
grep -q $'^SCP\t--\tfake-prod:' "$success/transport.log"
grep -Fq 'rm\ -f\ --\ ' "$success/transport.log"
FIN_DB="$success/app/data/fin.sqlite" FIN_BACKUP="$backup" php -r '
  $db=new PDO("sqlite:".getenv("FIN_DB"));
  $backup=new PDO("sqlite:".getenv("FIN_BACKUP"));
  if ((int)$db->query("SELECT COUNT(*) FROM months WHERE notes=\"PRODUCTION DOMAIN MARKER\"")->fetchColumn() !== 1) exit(1);
  if ((int)$db->query("SELECT COUNT(*) FROM months WHERE notes=\"DEV DOMAIN MARKER\"")->fetchColumn() !== 0) exit(1);
  if ((int)$db->query("SELECT COUNT(*) FROM envelopes WHERE name=\"Production envelope\"")->fetchColumn() !== 1) exit(1);
  if ((string)$db->query("SELECT hex(user_handle) FROM auth_principal WHERE id=1")->fetchColumn() !== strtoupper(bin2hex(str_repeat("D",32)))) exit(1);
  if ((int)$db->query("SELECT COUNT(*) FROM webauthn_credentials WHERE label=\"DEV DEVICE\"")->fetchColumn() !== 1) exit(1);
  if ((int)$db->query("SELECT COUNT(*) FROM webauthn_credentials WHERE label=\"PROD DEVICE\"")->fetchColumn() !== 0) exit(1);
  if ((int)$backup->query("SELECT COUNT(*) FROM months WHERE notes=\"DEV DOMAIN MARKER\"")->fetchColumn() !== 1) exit(1);
  if ((string)$db->query("PRAGMA integrity_check")->fetchColumn() !== "ok") exit(1);
  if ($db->query("PRAGMA foreign_key_check")->fetch()) exit(1);
'

# Transfer and source validation failures leave DEV untouched and never print READY.
expect_failure checksum FIN_TEST_SCP_MODE=checksum
expect_failure integrity FIN_TEST_REMOTE_MUTATE=integrity
make_case foreign-key
fk="$TEST_ROOT/foreign-key"
FIN_DB="$fk/remote/data/fin.sqlite" php -r '$db=new PDO("sqlite:".getenv("FIN_DB"));$db->exec("PRAGMA foreign_keys=OFF");$db->exec("UPDATE envelopes SET default_account_id=999999");'
if run_refresh "$fk" >"$fk/output.log" 2>&1; then echo 'foreign-key should fail' >&2; exit 1; fi
! grep -q '^READY$' "$fk/output.log"
assert_dev_unchanged "$fk"
test -z "$(find "$fk/remote-backups" -maxdepth 1 -type f -name 'fin-prod-dev-refresh-*.sqlite' -print -quit)"

make_case newer
newer="$TEST_ROOT/newer"
FIN_DB="$newer/remote/data/fin.sqlite" php -r '$db=new PDO("sqlite:".getenv("FIN_DB"));$db->exec("INSERT INTO schema_migrations(version,name) VALUES (\"99.0\",\"Future schema\")");'
if run_refresh "$newer" >"$newer/output.log" 2>&1; then echo 'newer source should fail' >&2; exit 1; fi
grep -q 'is newer than candidate' "$newer/output.log"
! grep -q '^READY$' "$newer/output.log"
assert_dev_unchanged "$newer"

# Candidate/replacement failure paths.
expect_failure migration FIN_REFRESH_TEST_FAIL_AT=migration
expect_failure post-integrity FIN_REFRESH_TEST_FAIL_AT=post-migration-integrity
expect_failure target-version FIN_REFRESH_TEST_FAIL_AT=target-version
expect_failure replacement FIN_REFRESH_TEST_FAIL_AT=replace

make_case backup-failure
backup_failure="$TEST_ROOT/backup-failure"
printf 'not a directory\n' > "$backup_failure/bad-backup-path"
if env FIN_BACKUP_DIR="$backup_failure/bad-backup-path" \
  HOME="$backup_failure/home" FIN_PROD_SSH=fake-prod FIN_PROD_ROOT="$backup_failure/remote" FIN_PROD_PHP=php \
  FIN_PROD_BACKUP_DIR="$backup_failure/remote-backups" FIN_REFRESH_CONFIRM=REPLACE_DEV FIN_REFRESH_TEST_MODE=1 \
  FIN_REFRESH_FAKE_TRANSPORT_DIR="$backup_failure/fake-bin" FIN_SSH_BIN="$backup_failure/fake-bin/ssh" FIN_SCP_BIN="$backup_failure/fake-bin/scp" \
  FIN_TEST_TRANSPORT_LOG="$backup_failure/transport.log" PHP_BIN=/usr/bin/php \
  "$backup_failure/app/scripts/refresh-dev-from-prod.sh" >"$backup_failure/output.log" 2>&1; then
  echo 'backup failure should fail' >&2; exit 1
fi
! grep -q '^READY$' "$backup_failure/output.log"
assert_dev_unchanged "$backup_failure"

make_case final-rollback
rollback="$TEST_ROOT/final-rollback"
if run_refresh "$rollback" FIN_REFRESH_TEST_FAIL_AT=final-verify >"$rollback/output.log" 2>&1; then
  echo 'final verification should fail' >&2; exit 1
fi
grep -q 'previous DEV database restored' "$rollback/output.log"
! grep -q '^READY$' "$rollback/output.log"
assert_dev_unchanged "$rollback"
test -n "$(find "$rollback/backups" -maxdepth 1 -type f -name 'fin-dev-refresh-failed-*.sqlite' -print -quit)"

# Active writer refusal happens before transport.
make_case active-writer
active="$TEST_ROOT/active-writer"
FIN_DB="$active/app/data/fin.sqlite" FIN_READY="$active/writer-ready" php -r '$db=new PDO("sqlite:".getenv("FIN_DB"));file_put_contents(getenv("FIN_READY"),"ready");sleep(30);' &
writer_pid=$!
for _ in $(seq 1 100); do [[ -f "$active/writer-ready" ]] && break; sleep 0.05; done
if run_refresh "$active" >"$active/output.log" 2>&1; then echo 'active writer should fail' >&2; exit 1; fi
kill "$writer_pid" 2>/dev/null || true
wait "$writer_pid" 2>/dev/null || true
writer_pid=""
grep -q 'development database is open' "$active/output.log"
! grep -q '^READY$' "$active/output.log"
test ! -f "$active/transport.log"
assert_dev_unchanged "$active"

# A production-like origin is refused before any transport command.
make_case production-origin
production_origin="$TEST_ROOT/production-origin"
perl -0pi -e "s#http://localhost:8080#https://prod.example.invalid#" "$production_origin/app/config.local.php"
if run_refresh "$production_origin" >"$production_origin/output.log" 2>&1; then
  echo 'production origin should fail' >&2; exit 1
fi
grep -q 'loopback development WebAuthn origin' "$production_origin/output.log"
! grep -q '^READY$' "$production_origin/output.log"
test ! -f "$production_origin/transport.log"
assert_dev_unchanged "$production_origin"

# Test mode cannot fall through to real transport when fakes are missing.
make_case missing-fake
missing="$TEST_ROOT/missing-fake"
if env HOME="$missing/home" FIN_PROD_SSH=fake-prod FIN_PROD_ROOT="$missing/remote" FIN_REFRESH_CONFIRM=REPLACE_DEV \
  FIN_REFRESH_TEST_MODE=1 FIN_REFRESH_FAKE_TRANSPORT_DIR="$missing/fake-bin" FIN_SSH_BIN=/usr/bin/ssh FIN_SCP_BIN=/usr/bin/scp \
  PHP_BIN=/usr/bin/php "$missing/app/scripts/refresh-dev-from-prod.sh" >"$missing/output.log" 2>&1; then
  echo 'test mode real transport guard should fail' >&2; exit 1
fi
grep -q 'test mode refuses non-fake SSH/SCP transport' "$missing/output.log"
! grep -q '^READY$' "$missing/output.log"
assert_dev_unchanged "$missing"

# Static direction guard: no updater/rsync/upload operation and SCP has fixed remote-to-local order.
! grep -q 'update\.sh' "$PROJECT_ROOT/scripts/refresh-dev-from-prod.sh"
! grep -q '\brsync\b' "$PROJECT_ROOT/scripts/refresh-dev-from-prod.sh"
! grep -q 'statement_drop_dir\|import/imported' "$PROJECT_ROOT/scripts/refresh-dev-from-prod.sh"
grep -Fq '"$SCP_BIN" -- "$PROD_SSH:$remote_snapshot" "$stage_file"' "$PROJECT_ROOT/scripts/refresh-dev-from-prod.sh"

printf 'PROD -> DEV refresh synthetic transport, migration, auth, safety, and rollback tests passed\n'
