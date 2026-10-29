#!/usr/bin/env bash
# Bounded integration test for scripts/restore-backup.sh.
#
# Sets up a temporary FIN tree with a temporary FIN_DB_FILE and temporary
# backup directory, builds two real backups (fin-upgrade-style and
# fin-archive-before-style) on top of synthetic state, mutates the live
# database, and restores each backup into the temporary database.
#
# Verifies (iteration 2):
#   - restored data equals the backup's pre-mutation data;
#   - the safety copy was written with the right naming style and mode;
#   - the original backup file stays in place, byte-identical, after
#     a restore;
#   - the restored file has the live file's mode (set 0640 first) and
#     the same group when it can be checked;
#   - a corrupt live database is still restored via the raw safety
#     copy fallback;
#   - the script refuses to run while another process holds the live
#     database open (skipped with a printed message if neither fuser
#     nor lsof is available);
#   - the iteration 1 refusal cases (missing file, corrupt file, newer
#     schema) still pass.
#
# Tests only against temporary paths; the production database and
# production backup directory are never touched.

set -Eeuo pipefail

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
TEST_ROOT="$(mktemp -d "${TMPDIR:-/tmp}/fin-restore-test.XXXXXX")"
# Clean up the temporary directory and any background process on exit.
# Do NOT force `exit 0` here; under `set -Eeuo pipefail` an aborted
# command must propagate as a non-zero test exit so the runner sees
# the failure. The cleanup is a best-effort `|| true` so a single
# cleanup failure cannot mask the real one.
trap '[ -n "${HOLDER_PID:-}" ] && { kill "$HOLDER_PID" 2>/dev/null || true; }; rm -rf "$TEST_ROOT" || true' EXIT

APP_DIR="$TEST_ROOT/app"
HOME_DIR="$TEST_ROOT/home"
mkdir -p "$APP_DIR/scripts" "$APP_DIR/data" "$HOME_DIR" "$TEST_ROOT/bin"

# Copy the application sources the script's probe needs (require_once
# __DIR__ . "/api.php" pulls in every file api.php requires at top of
# file). Production paths are never touched.
cp "$PROJECT_ROOT/api.php" \
   "$PROJECT_ROOT/report.php" \
   "$PROJECT_ROOT/recommendation.php" \
   "$PROJECT_ROOT/accounting.php" \
   "$PROJECT_ROOT/statement_import.php" \
   "$PROJECT_ROOT/statement_reconciliation.php" \
   "$PROJECT_ROOT/auth.php" \
   "$PROJECT_ROOT/config.php" \
   "$PROJECT_ROOT/currency.php" \
   "$PROJECT_ROOT/ecb_rates.php" \
   "$PROJECT_ROOT/VERSION" \
   "$APP_DIR/"
cp "$PROJECT_ROOT/scripts/restore-backup.sh" "$APP_DIR/scripts/"
chmod +x "$APP_DIR/scripts/restore-backup.sh"

DB_FILE="$APP_DIR/data/fin.sqlite"
DB_DIR="$(dirname "$DB_FILE")"
BACKUP_DIR="$TEST_ROOT/backups"
mkdir -p "$BACKUP_DIR"

# Run API/CLI commands from APP_DIR so api.php resolves next to the
# other source files we copied above.
cd "$APP_DIR"

run_restore() {
  HOME="$HOME_DIR" FIN_DB_FILE="$DB_FILE" FIN_BACKUP_DIR="$BACKUP_DIR" \
    "$APP_DIR/scripts/restore-backup.sh" "$@"
}

# Assert that a restore refuses: exact exit code, an "Error:" line, and a
# log naming the expected cause. The status is captured with `|| rc=$?` so
# `set -e` does not abort the test before the assertions run.
assert_refusal() {
  local expected_rc="$1"; local cause="$2"; local log="$3"
  shift 3
  local rc=0
  "$@" >"$log" 2>&1 || rc=$?
  if [[ "$rc" != "$expected_rc" ]]; then
    echo "expected refusal exit $expected_rc, got $rc"; cat "$log"; exit 1
  fi
  grep -q "^Error: " "$log" \
    || { echo "expected an 'Error:' line in $log"; cat "$log"; exit 1; }
  grep -qE "$cause" "$log" \
    || { echo "expected refusal cause matching /$cause/ in $log"; cat "$log"; exit 1; }
}

snapshot_tables() {
  FIN_DB_FILE="$1" php -r '
    $db = new PDO("sqlite:" . getenv("FIN_DB_FILE"));
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $tables = ["accounts", "envelopes", "months", "transactions", "envelope_movements", "account_movements"];
    foreach ($tables as $t) {
        $count = (int)$db->query("SELECT COUNT(*) FROM " . $t)->fetchColumn();
        echo $t . "=" . $count . "\n";
    }
    $hash = hash_file("sha256", getenv("FIN_DB_FILE"));
    echo "sha256=" . $hash . "\n";
    echo "schema=" . $db->query("SELECT version FROM schema_migrations ORDER BY version DESC LIMIT 1")->fetchColumn() . "\n";
    echo "integrity=" . $db->query("PRAGMA integrity_check")->fetchColumn() . "\n";
  '
}

# 1. Seed the live DB with synthetic state for the upgrade-style backup.
echo "scenario 1: seed live DB with synthetic state and build fin-upgrade-style backup"
FIN_DB_FILE="$DB_FILE" php -r '
  require "api.php";
  $db = db();
  migrate($db);
  save_envelope($db, ["name" => "Upgrade envelope", "account" => "Upgrade", "role" => "daily"]);
  save_month($db, ["year" => 2025, "month" => 1]);
  $env = (int)$db->query("SELECT id FROM envelopes LIMIT 1")->fetchColumn();
  $month = (int)$db->query("SELECT id FROM months LIMIT 1")->fetchColumn();
  save_transaction($db, ["month_id" => $month, "envelope_id" => $env, "amount" => 100, "spent_date" => "2025-01-15"]);
  save_account_definition($db, ["name" => "Upgrade asset", "account_number" => "UPG-1", "currency" => "CURRENCY"]);
'
EXPECTED_APP_VERSION="$(< "$APP_DIR/VERSION")"
EXPECTED_DB_VERSION="$(FIN_DB_FILE="$DB_FILE" php api.php version | awk -F'database v' 'NF > 1 { print $2 }')"
test -n "$EXPECTED_APP_VERSION" || { echo "could not read app version from $APP_DIR/VERSION"; exit 1; }
test -n "$EXPECTED_DB_VERSION" || { echo "could not read database version from api.php version"; exit 1; }

# Build a fin-upgrade-style backup.
UPGRADE_BACKUP_SRC="$TEST_ROOT/fin-upgrade-source.sqlite"
FIN_DB_FILE="$DB_FILE" php -r '
  $db = new PDO("sqlite:" . getenv("FIN_DB_FILE"));
  $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $db->exec("VACUUM INTO " . $db->quote($argv[1]));
' "$UPGRADE_BACKUP_SRC"
UPGRADE_BACKUP="$BACKUP_DIR/fin-upgrade-test.sqlite"
cp "$UPGRADE_BACKUP_SRC" "$UPGRADE_BACKUP"
chmod 600 "$UPGRADE_BACKUP"
UPGRADE_SNAPSHOT="$(snapshot_tables "$UPGRADE_BACKUP")"
UPGRADE_SHA="$(printf '%s\n' "$UPGRADE_SNAPSHOT" | awk -F= '/^sha256=/{print $2}')"

# Apply a distinctive mode so the restore can be checked for mode
# preservation. The mode is a per-installation convention, not FIN's
# responsibility, so the script must copy whatever the live file has.
chmod 0640 "$DB_FILE"

# Mutate the live DB so the restore must revert the change.
FIN_DB_FILE="$DB_FILE" php -r '
  require "api.php";
  $db = db();
  save_envelope($db, ["name" => "Mutated envelope", "account" => "Mutated", "role" => "daily"]);
'
MUTATED_ENVELOPES="$(FIN_DB_FILE="$DB_FILE" php -r '
  $db = new PDO("sqlite:" . getenv("FIN_DB_FILE"));
  echo (int)$db->query("SELECT COUNT(*) FROM envelopes")->fetchColumn();
')"
test "$MUTATED_ENVELOPES" = "2" || { echo "pre-restore mutation failed: $MUTATED_ENVELOPES envelopes"; exit 1; }
MUTATED_SNAPSHOT_BEFORE="$(snapshot_tables "$DB_FILE")"
LIVE_MODE_BEFORE="$(stat -c '%a' "$DB_FILE")"
LIVE_GROUP_BEFORE="$(stat -c '%g' "$DB_FILE")"
LIVE_GROUP_NAME_BEFORE="$(stat -c '%G' "$DB_FILE")"
test "$LIVE_MODE_BEFORE" = "640" || { echo "test setup: live DB mode should be 0640, got $LIVE_MODE_BEFORE"; exit 1; }

# Snapshot the original backup file's SHA so we can prove it stays
# byte-identical after the restore.
UPGRADE_BACKUP_SHA_BEFORE="$(sha256sum "$UPGRADE_BACKUP" | awk '{print $1}')"

# 2. Restore from the fin-upgrade-style backup.
echo "scenario 2: restore from fin-upgrade-style backup and verify byte-identical backup, mode, group"
restore_log="$TEST_ROOT/restore-upgrade.log"
run_restore "$UPGRADE_BACKUP" >"$restore_log" 2>&1
restore_rc=$?
test "$restore_rc" = "0" || { echo "restore rejected valid upgrade backup (rc=$restore_rc)"; cat "$restore_log"; exit 1; }

grep -q "Restored: $DB_FILE" "$restore_log" \
  || { echo "expected 'Restored' line in restore log"; cat "$restore_log"; exit 1; }
grep -q "^Post-restore version:" "$restore_log" \
  || { echo "expected post-restore version line in restore log"; cat "$restore_log"; exit 1; }
grep -q "FIN Planner v$EXPECTED_APP_VERSION / database v$EXPECTED_DB_VERSION" "$restore_log" \
  || { echo "expected post-restore version banner in restore log"; cat "$restore_log"; exit 1; }
grep -q "^Post-restore Master PIN status:" "$restore_log" \
  || { echo "expected post-restore Master PIN line in restore log"; cat "$restore_log"; exit 1; }
grep -q "Original backup file kept in place" "$restore_log" \
  || { echo "expected backup-kept line in restore log"; cat "$restore_log"; exit 1; }

RESTORED_SNAPSHOT="$(snapshot_tables "$DB_FILE")"
RESTORED_SHA="$(printf '%s\n' "$RESTORED_SNAPSHOT" | awk -F= '/^sha256=/{print $2}')"
test "$RESTORED_SHA" = "$UPGRADE_SHA" || { echo "restored DB does not match backup"; exit 1; }

# Verify the safety copy naming style, mode, and logical contents.
SAFETY_FILE="$(find "$BACKUP_DIR" -maxdepth 1 -type f -name 'fin-restore-before-*.sqlite' -print -quit)"
test -n "$SAFETY_FILE" || { echo "pre-restore safety copy not written"; exit 1; }
test "$(stat -c '%a' "$SAFETY_FILE")" = "600" || { echo "safety copy mode is not 0600: $(stat -c '%a' "$SAFETY_FILE")"; exit 1; }
SAFETY_SNAPSHOT="$(snapshot_tables "$SAFETY_FILE")"
# Compare logical content (counts, schema, integrity); file hashes
# differ because VACUUM INTO rewrites the on-disk layout.
SAFETY_BODY="$(printf '%s\n' "$SAFETY_SNAPSHOT" | grep -v '^sha256=')"
MUTATED_BODY="$(printf '%s\n' "$MUTATED_SNAPSHOT_BEFORE" | grep -v '^sha256=')"
test "$SAFETY_BODY" = "$MUTATED_BODY" || { echo "safety copy logical content differs from pre-restore DB"; exit 1; }

# Iteration 2: original backup file is still in place and byte-identical.
echo "scenario 2a: original backup file is byte-identical after the restore"
UPGRADE_BACKUP_SHA_AFTER="$(sha256sum "$UPGRADE_BACKUP" | awk '{print $1}')"
test "$UPGRADE_BACKUP_SHA_BEFORE" = "$UPGRADE_BACKUP_SHA_AFTER" \
  || { echo "original backup file was modified by the restore"; exit 1; }
test -f "$UPGRADE_BACKUP" || { echo "original backup file was deleted by the restore"; exit 1; }

# Iteration 2: the restored file has the live file's mode (set 0640
# before restore) and the same group when it can be checked.
RESTORED_MODE="$(stat -c '%a' "$DB_FILE")"
test "$RESTORED_MODE" = "$LIVE_MODE_BEFORE" \
  || { echo "restored file mode is $RESTORED_MODE, expected $LIVE_MODE_BEFORE"; exit 1; }
RESTORED_GROUP_NAME="$(stat -c '%G' "$DB_FILE")"
test "$RESTORED_GROUP_NAME" = "$LIVE_GROUP_NAME_BEFORE" \
  || { echo "restored file group is $RESTORED_GROUP_NAME, expected $LIVE_GROUP_NAME_BEFORE"; exit 1; }

# 3. Restore the same upgrade-style backup a second time after
# re-mutating the live DB, to confirm the script tolerates repeated
# restore operations against fresh backup copies and keeps producing
# the safety copy.
echo "scenario 3: repeat upgrade-style restore with fresh backup copy and two safety copies"
UPGRADE_BACKUP_2="$BACKUP_DIR/fin-upgrade-test-2.sqlite"
cp "$UPGRADE_BACKUP_SRC" "$UPGRADE_BACKUP_2"
chmod 600 "$UPGRADE_BACKUP_2"
FIN_DB_FILE="$DB_FILE" php -r '
  require "api.php";
  $db = db();
  save_envelope($db, ["name" => "Will be reverted", "account" => "Reverted", "role" => "daily"]);
'
MUTATED_SNAPSHOT_BEFORE_2="$(snapshot_tables "$DB_FILE")"
run_restore "$UPGRADE_BACKUP_2" >"$TEST_ROOT/restore-upgrade-2.log" 2>&1
test "$?" = "0" || { echo "second upgrade-style restore failed"; cat "$TEST_ROOT/restore-upgrade-2.log"; exit 1; }
SAFETY_FILES_AFTER_2="$(find "$BACKUP_DIR" -maxdepth 1 -type f -name 'fin-restore-before-*.sqlite' | wc -l)"
test "$SAFETY_FILES_AFTER_2" = "2" || { echo "expected 2 safety files, got $SAFETY_FILES_AFTER_2"; exit 1; }
SAFETY_FILE_2_TAIL="$(find "$BACKUP_DIR" -maxdepth 1 -type f -name 'fin-restore-before-*.sqlite' | sort | tail -n 1)"
SAFETY_SNAPSHOT_2="$(snapshot_tables "$SAFETY_FILE_2_TAIL")"
SAFETY_BODY_2="$(printf '%s\n' "$SAFETY_SNAPSHOT_2" | grep -v '^sha256=')"
MUTATED_BODY_2="$(printf '%s\n' "$MUTATED_SNAPSHOT_BEFORE_2" | grep -v '^sha256=')"
test "$SAFETY_BODY_2" = "$MUTATED_BODY_2" || { echo "second safety copy logical content differs from pre-restore DB"; exit 1; }

# 4. Restore from a fin-archive-before-style backup.
echo "scenario 4: restore from fin-archive-before-style backup"
FIN_DB_FILE="$DB_FILE" php -r '
  require "api.php";
  $db = db();
  // wipe to start clean for the archive-style scenario
  foreach (["transactions","account_movements","account_events","envelope_movements","account_balance_observations","envelope_balance_observations"] as $t) {
      $db->exec("DELETE FROM " . $t);
  }
  foreach (["accounts","envelopes","months"] as $t) {
      $db->exec("DELETE FROM " . $t);
  }
'
FIN_DB_FILE="$DB_FILE" php -r '
  require "api.php";
  $db = db();
  save_envelope($db, ["name" => "Archive envelope", "account" => "Archive", "role" => "daily"]);
  save_month($db, ["year" => 2025, "month" => 1]);
  save_account_definition($db, ["name" => "Archive asset", "account_number" => "ARCH-1", "currency" => "CURRENCY"]);
'
ARCHIVE_BACKUP="$BACKUP_DIR/fin-archive-before-2025-03-01-120000.sqlite"
FIN_DB_FILE="$DB_FILE" php -r '
  $db = new PDO("sqlite:" . getenv("FIN_DB_FILE"));
  $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $db->exec("VACUUM INTO " . $db->quote($argv[1]));
' "$ARCHIVE_BACKUP"
chmod 600 "$ARCHIVE_BACKUP"
ARCHIVE_SNAPSHOT="$(snapshot_tables "$ARCHIVE_BACKUP")"
ARCHIVE_SHA="$(printf '%s\n' "$ARCHIVE_SNAPSHOT" | awk -F= '/^sha256=/{print $2}')"

# Mutate
FIN_DB_FILE="$DB_FILE" php -r '
  require "api.php";
  $db = db();
  save_envelope($db, ["name" => "Archive mutation", "account" => "ArchiveMut", "role" => "daily"]);
'

restore_log="$TEST_ROOT/restore-archive.log"
run_restore "$ARCHIVE_BACKUP" >"$restore_log" 2>&1
test "$?" = "0" || { echo "restore rejected archive-style backup"; cat "$restore_log"; exit 1; }

RESTORED_ARCHIVE_SHA="$(snapshot_tables "$DB_FILE" | awk -F= '/^sha256=/{print $2}')"
test "$RESTORED_ARCHIVE_SHA" = "$ARCHIVE_SHA" || { echo "archive-style restore did not match backup"; exit 1; }

# Backup directory mode
test "$(stat -c '%a' "$BACKUP_DIR")" = "700" || { echo "backup directory mode is not 0700"; exit 1; }

# 5. Iteration 2: live database is unreadable (corrupt page header); the
#    script must fall back to a raw safety copy and still complete the
#    restore.
echo "scenario 5: raw safety-copy fallback when live database is unreadable"
rm -rf "$BACKUP_DIR"
mkdir -p "$BACKUP_DIR"

# Take a fresh upgrade-style backup of the current (post-archive) state
# so we have something to restore to.
UPGRADE_BACKUP_3="$BACKUP_DIR/fin-upgrade-raw-fallback.sqlite"
cp "$UPGRADE_BACKUP_SRC" "$UPGRADE_BACKUP_3"
chmod 600 "$UPGRADE_BACKUP_3"

# Corrupt the live database page header so VACUUM INTO fails.
printf 'XXXXXXXX' | dd of="$DB_FILE" bs=1 seek=100 conv=notrunc status=none

raw_fallback_log="$TEST_ROOT/restore-raw-fallback.log"
run_restore "$UPGRADE_BACKUP_3" >"$raw_fallback_log" 2>&1
raw_fallback_rc=$?
test "$raw_fallback_rc" = "0" || { echo "raw-fallback restore rejected (rc=$raw_fallback_rc)"; cat "$raw_fallback_log"; exit 1; }

# Raw safety copy should exist with the -raw suffix and mode 0600.
RAW_SAFETY="$(find "$BACKUP_DIR" -maxdepth 1 -type f -name 'fin-restore-before-*-raw.sqlite' -print -quit)"
test -n "$RAW_SAFETY" || { echo "raw safety copy was not written"; cat "$raw_fallback_log"; exit 1; }
test "$(stat -c '%a' "$RAW_SAFETY")" = "600" || { echo "raw safety copy mode is not 0600: $(stat -c '%a' "$RAW_SAFETY")"; exit 1; }
grep -q "Pre-restore safety copy (raw" "$raw_fallback_log" \
  || { echo "expected raw safety-copy banner in raw fallback log"; cat "$raw_fallback_log"; exit 1; }
grep -q "kind: raw" "$raw_fallback_log" \
  || { echo "expected raw safety-copy kind line in raw fallback log"; cat "$raw_fallback_log"; exit 1; }

# The restore itself still succeeded: the corrupted live DB has been
# replaced by a clean copy of the backup.
RAW_FALLBACK_INTEGRITY="$(FIN_DB_FILE="$DB_FILE" php -r '
  $db = new PDO("sqlite:" . getenv("FIN_DB_FILE"));
  echo $db->query("PRAGMA integrity_check")->fetchColumn();
')"
test "$RAW_FALLBACK_INTEGRITY" = "ok" || { echo "restored DB after raw fallback is not ok: $RAW_FALLBACK_INTEGRITY"; exit 1; }

# 6. Iteration 2: refuse while another process holds the live database
#    open. Hold the live database open in a background process; if
#    neither fuser nor lsof is available, skip with a printed message.
echo "scenario 6: in-use refusal when another process holds the live database"
restore_log="$TEST_ROOT/in-use-refusal.log"
if command -v fuser >/dev/null 2>&1 || command -v lsof >/dev/null 2>&1; then
  bash -c "exec 9<>$DB_FILE; sleep 30" &
  HOLDER_PID=$!
  # Give the holder a moment to actually open the file.
  sleep 1
  # Asserted while the holder still holds the file. If the assertion
  # fails, the EXIT trap kills the holder and removes TEST_ROOT.
  assert_refusal 1 "is open by process" "$restore_log" \
    run_restore "$UPGRADE_BACKUP_3"
  kill "$HOLDER_PID" 2>/dev/null || true
  wait "$HOLDER_PID" 2>/dev/null || true
else
  printf 'SKIP: neither fuser nor lsof is available; in-use refusal case not exercised\n'
  # Force exit 0 from this iteration; do not fail the test on a missing
  # detection tool.
fi

# 6b. Iteration 3: matching owner and group case.
echo "scenario 6b: matching owner and group case (no chown/chgrp attempt)"
#     runs the script as the account that owns the live database and
#     the staged file already has the same owner and group, the script
#     must not attempt chown or chgrp (the setgid directory already
#     gave the staged file the right group). Verify the restore
#     succeeds and the resulting owner, group and mode are correct.
#     Set DB_DIR to setgid mode so the staged file inherits the right
#     group, mirroring the production convention.
chmod 2775 "$DB_DIR"
chmod 0640 "$DB_FILE"
DB_DIR_GROUP_GID="$(stat -c '%g' "$DB_DIR")"
LIVE_OWNER_UID="$(stat -c '%u' "$DB_FILE")"
LIVE_GROUP_GID="$(stat -c '%g' "$DB_FILE")"

# Run the restore as the operator (the current script user). If the
# script tried to chown or chgrp when the file already had the target
# values, the operation would either no-op or, more importantly, would
# NOT cause a failure. So we assert success and that the result is
# exactly the captured values.
match_log="$TEST_ROOT/restore-match-owner.log"
UPGRADE_BACKUP_4="$BACKUP_DIR/fin-upgrade-match.sqlite"
cp "$UPGRADE_BACKUP_SRC" "$UPGRADE_BACKUP_4"
chmod 600 "$UPGRADE_BACKUP_4"
run_restore "$UPGRADE_BACKUP_4" >"$match_log" 2>&1
match_rc=$?
test "$match_rc" = "0" \
  || { echo "matching owner/group restore rejected (rc=$match_rc)"; cat "$match_log"; exit 1; }
grep -q "Restored: $DB_FILE" "$match_log" \
  || { echo "expected 'Restored' line in match log"; cat "$match_log"; exit 1; }
! grep -q "Cannot apply" "$match_log" \
  || { echo "restore emitted an unexpected 'Cannot apply' error"; cat "$match_log"; exit 1; }
test "$(stat -c '%a' "$DB_FILE")" = "640" \
  || { echo "restored file mode changed after matching restore"; exit 1; }
test "$(stat -c '%u' "$DB_FILE")" = "$LIVE_OWNER_UID" \
  || { echo "restored file owner changed after matching restore"; exit 1; }
test "$(stat -c '%g' "$DB_FILE")" = "$LIVE_GROUP_GID" \
  || { echo "restored file group changed after matching restore"; exit 1; }

# 6c. Iteration 3: missing-live-database case.
echo "scenario 6c: missing-live-database case (mode 0660, directory group)"
#     to copy attributes from, the script must create the restored file
#     with mode 0660 (group-writable so the web server can write) and
#     the group of the live database's directory (the setgid bit on a
#     well-configured install gives this to new files automatically).
rm -f "$DB_FILE"
NO_LIVE_BACKUP="$BACKUP_DIR/fin-upgrade-no-live.sqlite"
cp "$UPGRADE_BACKUP_SRC" "$NO_LIVE_BACKUP"
chmod 600 "$NO_LIVE_BACKUP"
no_live_log="$TEST_ROOT/restore-no-live.log"
run_restore "$NO_LIVE_BACKUP" >"$no_live_log" 2>&1
no_live_rc=$?
test "$no_live_rc" = "0" \
  || { echo "no-live restore rejected (rc=$no_live_rc)"; cat "$no_live_log"; exit 1; }
NO_LIVE_MODE="$(stat -c '%a' "$DB_FILE")"
NO_LIVE_GROUP_GID="$(stat -c '%g' "$DB_FILE")"
NO_LIVE_GROUP_NAME="$(stat -c '%G' "$DB_FILE")"
test "$NO_LIVE_MODE" = "660" \
  || { echo "no-live restored file mode is $NO_LIVE_MODE, expected 660"; exit 1; }
test "$NO_LIVE_GROUP_GID" = "$DB_DIR_GROUP_GID" \
  || { echo "no-live restored file group GID is $NO_LIVE_GROUP_GID, expected $DB_DIR_GROUP_GID (DB_DIR group $DB_DIR_GROUP_NAME)"; exit 1; }
grep -q "Original backup file kept in place" "$no_live_log" \
  || { echo "expected backup-kept line in no-live log"; cat "$no_live_log"; exit 1; }

# 7. Iteration 1 refusal cases still pass.
echo "scenario 7: refusal cases (missing file, non-SQLite, integrity_check, newer schema)"
missing_log="$TEST_ROOT/missing.log"
assert_refusal 1 "Backup file not found" "$missing_log" \
  run_restore "/nonexistent/fin-restore-missing.sqlite"

corrupt_log="$TEST_ROOT/corrupt.log"
echo "this is not a sqlite database" > "$TEST_ROOT/corrupt.sqlite"
assert_refusal 1 "not a SQLite database" "$corrupt_log" \
  run_restore "$TEST_ROOT/corrupt.sqlite"

integrity_log="$TEST_ROOT/integrity.log"
# Create a SQLite database that fails integrity_check by corrupting the
# page header bytes.
rm -f "$TEST_ROOT/corrupt.sqlite"
tmp_db="$TEST_ROOT/integrity-bad.sqlite"
php -r '
  $tmp = $argv[1];
  $db = new PDO("sqlite:" . $tmp);
  $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $db->exec("CREATE TABLE x (y INTEGER)");
  $db->exec("INSERT INTO x VALUES (1)");
' "$tmp_db"
# Overwrite bytes in the SQLite page-header zone so PRAGMA integrity_check
# fails (database disk image becomes malformed).
printf 'XXXXXXXX' | dd of="$tmp_db" bs=1 seek=100 conv=notrunc status=none
# The refusal must be loud: exit 1 plus an Error line naming the integrity
# failure and carrying the SQLite message.
assert_refusal 1 "integrity|malformed" "$integrity_log" \
  run_restore "$tmp_db"

future_log="$TEST_ROOT/future.log"
FUTURE_BACKUP="$TEST_ROOT/future-schema.sqlite"
php -r '
  $tmp = $argv[1];
  $db = new PDO("sqlite:" . $tmp);
  $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $db->exec("CREATE TABLE IF NOT EXISTS schema_migrations (version TEXT PRIMARY KEY, name TEXT NOT NULL, applied_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)");
  $db->exec("INSERT INTO schema_migrations (version, name) VALUES (\"1.0\", \"baseline\")");
  $db->exec("INSERT INTO schema_migrations (version, name) VALUES (\"2.23\", \"v2.23\")");
  $db->exec("INSERT INTO schema_migrations (version, name) VALUES (\"99.99\", \"future\")");
' "$FUTURE_BACKUP"
assert_refusal 1 "newer than the running code" "$future_log" \
  run_restore "$FUTURE_BACKUP"

echo 'all restore-backup.sh scenarios passed'