#!/usr/bin/env bash
# Restore a FIN SQLite backup over the current database.
#
# Usage: scripts/restore-backup.sh BACKUP_FILE
#
# IMPORTANT: stop the web server (and any other process that opens the
# SQLite database) before running this script. The script refuses to run
# while another process has the live database or its -wal/-shm/-journal
# sidecars open, but it cannot detect every interpreter on every host;
# stopping the application is the safe default.
#
# The script:
#   1. Refuses if BACKUP_FILE does not exist, is not a SQLite database,
#      fails PRAGMA integrity_check, or carries a schema version newer
#      than the current code's. Validation runs on a stage COPY in the
#      live database's directory so the original backup file stays in
#      place, byte-identical.
#   2. Writes a mode-0600 safety copy of the current database under
#      $HOME/fin-backups (or $FIN_BACKUP_DIR) named
#      fin-restore-before-YYYYMMDD-HHMMSS[-N].sqlite (VACUUM INTO when
#      the live database is readable, otherwise raw copies of the
#      database file and any -wal/-shm/-journal sidecars named
#      fin-restore-before-YYYYMMDD-HHMMSS-raw.{sqlite,-wal,-shm,-journal}),
#      so the restore is itself reversible. The safety copy is also kept.
#   3. Removes stale -wal, -shm and -journal sidecar files for the target
#      database.
#   4. Renames the staged COPY over the live database atomically (rename
#      within one directory). The original backup file is untouched.
#   5. Applies the live database's owner/group/mode to the restored file
#      when a live database existed (or the documented web-served
#      convention when it did not), and prints the resulting owner,
#      group and mode. Aborts before the replace if it cannot apply them.
#   6. Prints post-restore checks (php api.php version, master-pin-status).
#   7. Prints what a restore does NOT bring back (everything outside the
#      SQLite file).
#
# Development: set FIN_DB_FILE to a temporary database path and
# FIN_BACKUP_DIR to a temporary directory before running this script.
# The script never touches the production database or the production
# backup directory.

set -Eeuo pipefail

umask 077

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DB_FILE="${FIN_DB_FILE:-$ROOT_DIR/data/fin.sqlite}"
DB_DIR="$(dirname "$DB_FILE")"
BACKUP_DIR="${FIN_BACKUP_DIR:-$HOME/fin-backups}"
PHP_BIN="${PHP_BIN:-php}"

cd "$ROOT_DIR"

fail() {
  echo "Error: $*" >&2
  exit 1
}

# Default attributes used when the script must create a new live
# database file (no existing one to copy from). The exact owner/group/
# mode of the live database is whatever the operator already established
# on the host, so this is only a fallback. Mode 0660 lets the group
# (the web server) write to the database; mode 0640 would give it
# read-only access. Owner is the current user (the script operator),
# which lets the operator `chmod` and `chgrp` the file. Group is the
# group of the live database's directory; on a properly set up web
# install that directory is `setgid` (`g+s`), so files created inside
# inherit the directory's group automatically.
DEFAULT_LIVE_MODE="0660"

if [[ $# -ne 1 ]]; then
  echo "Usage: $0 BACKUP_FILE" >&2
  exit 2
fi

BACKUP_FILE="$1"

echo "NOTICE: stop the web server (and any other process that opens the"
echo "        SQLite database) before running this script. The script will"
echo "        refuse to run while another process has the live database"
echo "        or its -wal/-shm/-journal sidecars open; it cannot detect"
echo "        every interpreter on every host."
echo

[[ -e "$BACKUP_FILE" ]] || fail "Backup file not found: $BACKUP_FILE"
[[ -f "$BACKUP_FILE" ]] || fail "Backup path is not a regular file: $BACKUP_FILE"
[[ -r "$BACKUP_FILE" ]] || fail "Backup file is not readable: $BACKUP_FILE"

# SQLite databases begin with the 16-byte "SQLite format 3\0" header.
# Command substitution strips a trailing NUL, so compare against the
# 15-byte ASCII prefix and confirm the 16th byte is a NUL.
header_prefix="$(head -c 15 -- "$BACKUP_FILE" 2>/dev/null || true)"
header_nul="$(head -c 16 -- "$BACKUP_FILE" 2>/dev/null | od -An -c | awk 'NR==1{print $NF}')"
[[ "$header_prefix" == "SQLite format 3" && "$header_nul" == "\\0" ]] || fail "Backup file is not a SQLite database: $BACKUP_FILE"

# Ensure the live database's directory exists and is writable so the
# staged COPY can land in the same filesystem (rename is atomic only
# within one filesystem).
[[ -d "$DB_DIR" ]] || fail "Live database directory does not exist: $DB_DIR"
[[ -w "$DB_DIR" ]] || fail "Live database directory is not writable: $DB_DIR"

PROBE_LOG="$(mktemp)"
trap 'rm -f "$PROBE_LOG" "${STAGED_COPY:-}"' EXIT INT TERM

# Stage the backup into the live DB's directory so we can validate the
# COPY without consuming the original. Rename within one directory is
# atomic; the staged COPY must therefore live in DB_DIR.
STAGED_COPY="$DB_DIR/.fin-restore-stage-$$-$(date +%s).sqlite"
cp -p -- "$BACKUP_FILE" "$STAGED_COPY" || fail "Could not stage backup copy at $DB_DIR/"
chmod 600 "$STAGED_COPY" || fail "Could not chmod 0600 staged copy"

# Validate the COPY (PRAGMA integrity_check and schema version). Capture
# all output and exit status so a refusal prints a single clean error
# line and we always clean up the staged COPY on failure. The status is
# captured explicitly with `|| PROBE_RC=$?`: a bare assignment from a
# failing command substitution aborts under `set -e` before PROBE_RC is
# ever assigned, which made a validation failure exit silently.
PROBE_RC=0
PROBE_OUTPUT="$(cd "$ROOT_DIR" && FIN_RESTORE_PROBE_BACKUP="$STAGED_COPY" FIN_RESTORE_PROBE_DB_FILE="$DB_FILE" "$PHP_BIN" -r '
  require_once __DIR__ . "/api.php";
  $backup = getenv("FIN_RESTORE_PROBE_BACKUP");
  $codeDbFile = getenv("FIN_RESTORE_PROBE_DB_FILE");

  try {
      $backupDb = new PDO("sqlite:" . $backup);
      $backupDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  } catch (Throwable $e) {
      fwrite(STDERR, "open:" . $e->getMessage() . "\n");
      exit(2);
  }
  try {
      $integrity = strtolower((string)$backupDb->query("PRAGMA integrity_check")->fetchColumn());
  } catch (Throwable $e) {
      fwrite(STDERR, "integrity-check:" . $e->getMessage() . "\n");
      exit(2);
  }
  if ($integrity !== "ok") {
      fwrite(STDERR, "integrity:" . $integrity . "\n");
      exit(2);
  }
  $tables = $backupDb->query("SELECT name FROM sqlite_master WHERE type = \"table\"")->fetchAll(PDO::FETCH_COLUMN);
  $hasMigrations = in_array("schema_migrations", $tables, true);
  $backupVersions = $hasMigrations
      ? $backupDb->query("SELECT version FROM schema_migrations")->fetchAll(PDO::FETCH_COLUMN)
      : [];
  if (!$backupVersions) {
      $backupVersion = "0";
  } else {
      usort($backupVersions, "version_compare");
      $backupVersion = (string)end($backupVersions);
  }

  // Determine the code schema version by running migrate() on an empty
  // scratch database. Use a temporary file in the same directory as the
  // target DB so SQLite can create WAL/SHM sidecars if it wants to.
  $scratchDir = dirname($codeDbFile);
  if (!is_dir($scratchDir)) {
      if (!mkdir($scratchDir, 0775, true) && !is_dir($scratchDir)) {
          fwrite(STDERR, "scratch-dir:" . $scratchDir . "\n");
          exit(2);
      }
  }
  $scratch = tempnam($scratchDir, ".fin-restore-probe-");
  if ($scratch === false) {
      fwrite(STDERR, "scratch-tempnam\n");
      exit(2);
  }
  @chmod($scratch, 0600);
  try {
      $codeDb = new PDO("sqlite:" . $scratch);
      $codeDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
      $codeDb->exec("PRAGMA foreign_keys = ON");
      migrate($codeDb);
      $codeVersions = $codeDb->query("SELECT version FROM schema_migrations")->fetchAll(PDO::FETCH_COLUMN);
      if (!$codeVersions) {
          fwrite(STDERR, "code-versions-empty\n");
          exit(2);
      }
      usort($codeVersions, "version_compare");
      $codeVersion = (string)end($codeVersions);
  } finally {
      @unlink($scratch);
      @unlink($scratch . "-wal");
      @unlink($scratch . "-shm");
      @unlink($scratch . "-journal");
  }

  echo "backup_version=" . $backupVersion . "\n";
  echo "code_version=" . $codeVersion . "\n";
' 2>"$PROBE_LOG")" || PROBE_RC=$?

if (( PROBE_RC != 0 )); then
  probe_detail="$(tr -d '\n' < "$PROBE_LOG")"
  fail "Backup file validation failed: $probe_detail"
fi

BACKUP_VERSION="$(printf '%s\n' "$PROBE_OUTPUT" | awk -F= '/^backup_version=/{print $2; exit}')"
CODE_VERSION="$(printf '%s\n' "$PROBE_OUTPUT" | awk -F= '/^code_version=/{print $2; exit}')"
[[ -n "$BACKUP_VERSION" && -n "$CODE_VERSION" ]] || fail "Could not determine schema versions from probe output"

# Refuse if the backup schema is newer than the running code.
if printf '%s\n%s\n' "$BACKUP_VERSION" "$CODE_VERSION" | "$PHP_BIN" -r '
  $stdin = stream_get_contents(STDIN);
  $lines = explode("\n", trim((string)$stdin));
  $backupVersion = $lines[0] ?? "";
  $codeVersion = $lines[1] ?? "";
  if (version_compare($backupVersion, $codeVersion, ">")) {
      fwrite(STDERR, "Backup schema version (" . $backupVersion . ") is newer than the running code (" . $codeVersion . ").\n");
      exit(1);
  }
' >"$PROBE_LOG" 2>&1; then
  :
else
  fail "$(tr -d '\n' < "$PROBE_LOG")"
fi

# Capture live DB's attributes so the restored file matches them. Done
# BEFORE any destructive step so a non-restore failure does not leave us
# without the source of truth for ownership/mode.
LIVE_EXISTS=0
LIVE_UID=""
LIVE_GID=""
LIVE_MODE=""
LIVE_OWNER_NAME=""
LIVE_GROUP_NAME=""
if [[ -f "$DB_FILE" ]]; then
  LIVE_EXISTS=1
  LIVE_UID="$(stat -c '%u' "$DB_FILE")"
  LIVE_GID="$(stat -c '%g' "$DB_FILE")"
  LIVE_MODE="$(stat -c '%a' "$DB_FILE")"
  LIVE_OWNER_NAME="$(stat -c '%U' "$DB_FILE")"
  LIVE_GROUP_NAME="$(stat -c '%G' "$DB_FILE")"
fi

# Refuse if another process has the live database or its sidecars open.
# fuser / lsof are best-effort; if neither tool exists, print a warning
# and continue. The "stop the web server first" notice above is the
# primary safety net.
in_use_check() {
  local target="$1"
  local label="$2"
  if command -v fuser >/dev/null 2>&1; then
    # fuser prints "<path>: <pid>" when something has the file open, or
    # nothing when nothing does. We capture the raw output so the
    # whitespace around the colon is preserved.
    local fuser_out
    fuser_out="$(fuser "$target" 2>&1 || true)"
    fuser_out="${fuser_out##*: }"   # strip up to the last "<path>: " prefix
    if [[ -n "$fuser_out" ]]; then
      # fuser_out is now "<pid>" (possibly several space-separated).
      local pids="${fuser_out// /,}"
      local proc
      proc="$(ps -o comm= -p "$pids" 2>/dev/null | tr '\n' ',' | sed 's/,$//')"
      fail "$label is open by process(es) pid=$pids command=$proc; stop the web server and any other process that uses $DB_FILE before retrying"
    fi
    return 0
  elif command -v lsof >/dev/null 2>&1; then
    local lsof_out
    lsof_out="$(lsof -- "$target" 2>/dev/null || true)"
    if [[ -n "$lsof_out" ]]; then
      # lsof prints a header line first; the rest are matching rows.
      local proc
      proc="$(printf '%s\n' "$lsof_out" | tail -n +2 | awk '{print $1 "(" $2 ")"}' | tr '\n' ',' | sed 's/,$//')"
      fail "$label is open by process(es) $proc; stop the web server and any other process that uses $DB_FILE before retrying"
    fi
    return 0
  else
    echo "WARNING: cannot verify that $label is not open; neither fuser nor lsof is available on this host. Stop the web server (and any other process that opens the SQLite database) before running this script."
    return 0
  fi
}

if [[ "$LIVE_EXISTS" == "1" ]]; then
  in_use_check "$DB_FILE" "live database $DB_FILE"
  for sidecar in "$DB_FILE-wal" "$DB_FILE-shm" "$DB_FILE-journal"; do
    [[ -e "$sidecar" ]] || continue
    in_use_check "$sidecar" "live sidecar $sidecar"
  done
fi

# Decide the target owner/group/mode that the restored file will get.
TARGET_UID="$LIVE_UID"
TARGET_GID="$LIVE_GID"
TARGET_MODE="$LIVE_MODE"
if [[ "$LIVE_EXISTS" == "0" ]]; then
  # No live database to copy from. Use the documented web-served
  # defaults: owner = current user (the script operator), group =
  # group of the live database's directory (so a setgid directory
  # inherits it onto new files), mode = 0660 (group-writable so the
  # web server can write).
  TARGET_UID="$(id -u)"
  TARGET_GID="$(stat -c '%g' "$DB_DIR")"
  TARGET_MODE="$DEFAULT_LIVE_MODE"
fi

# Stop BEFORE replacing anything if we cannot apply the required
# ownership to a file we own. chmod always works; chown requires root
# or the current owner; chgrp requires the current user to be in the
# target group (or root). Each operation is skipped when the staged
# file already has the target value (the normal case when the operator
# runs the script as the account that owns data/ and the data/
# directory is setgid).
current_uid="$(id -u)"
staged_owner_uid="$(stat -c '%u' "$STAGED_COPY")"
staged_group_gid="$(stat -c '%g' "$STAGED_COPY")"
if [[ "$staged_owner_uid" != "$TARGET_UID" && "$current_uid" != "0" && "$current_uid" != "$TARGET_UID" ]]; then
  fail "Cannot apply live database owner UID $TARGET_UID (current user is UID $current_uid); re-run as UID $TARGET_UID (or root) so the restored database keeps its ownership."
fi
if [[ "$staged_group_gid" != "$TARGET_GID" ]]; then
  # A setgid directory normally gives the staged file the right group
  # already, so this branch is only entered when the directory's group
  # differs from the target. Probe the operation on a temporary file
  # before any destructive step.
  probe_file="$DB_DIR/.fin-restore-probe-$$-$(date +%s%N)"
  touch "$probe_file"
  chmod 600 "$probe_file" || { rm -f "$probe_file"; fail "Could not prepare group probe file in $DB_DIR"; }
  if ! chgrp "$TARGET_GID" "$probe_file" 2>/dev/null; then
    rm -f "$probe_file"
    fail "Cannot apply live database group GID $TARGET_GID; re-run as a member of that group (or root)."
  fi
  rm -f "$probe_file"
fi

# 2. Safety copy of the current database, so this restore is itself
#    reversible. VACUUM INTO is preferred; raw copies of the database
#    file and any sidecars are a fallback when the live database is
#    corrupt or unreadable.
safety_backup=""
safety_kind="vacuum"
timestamp="$(date +%Y%m%d-%H%M%S)"
if [[ "$LIVE_EXISTS" == "1" ]]; then
  if [[ ! -d "$BACKUP_DIR" ]]; then
    mkdir -p "$BACKUP_DIR" || fail "Could not create backup directory: $BACKUP_DIR"
  fi
  if [[ -L "$BACKUP_DIR" ]]; then
    fail "Backup directory cannot be a symbolic link: $BACKUP_DIR"
  fi
  chmod 700 "$BACKUP_DIR" || fail "Could not chmod 0700 backup directory: $BACKUP_DIR"

  safety_backup="$BACKUP_DIR/fin-restore-before-$timestamp.sqlite"
  if [[ -e "$safety_backup" ]]; then
    safety_backup="$BACKUP_DIR/fin-restore-before-$timestamp-$$.sqlite"
  fi

  if FIN_SOURCE_DB="$DB_FILE" FIN_TARGET_DB="$safety_backup" "$PHP_BIN" -r '
    $source = getenv("FIN_SOURCE_DB");
    $target = getenv("FIN_TARGET_DB");
    if (!$source || !$target) {
        fwrite(STDERR, "Missing backup path.\n");
        exit(1);
    }
    $db = new PDO("sqlite:" . $source);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec("VACUUM INTO " . $db->quote($target));
  ' 2>"$PROBE_LOG"; then
    chmod 600 "$safety_backup" || fail "Could not chmod 0600 safety copy"
    echo "Pre-restore safety copy (VACUUM INTO): $safety_backup"
  else
    # VACUUM INTO may have created an empty target file before failing;
    # remove it so we do not leave a confusing half-written file behind.
    [[ -f "$safety_backup" ]] && ! [[ -s "$safety_backup" ]] && rm -f -- "$safety_backup"
    safety_kind="raw"
    raw_base="$BACKUP_DIR/fin-restore-before-$timestamp-raw"
    raw_db="$raw_base.sqlite"
    if ! cp -- "$DB_FILE" "$raw_db" 2>"$PROBE_LOG"; then
      fail "Could not write pre-restore safety copy (VACUUM INTO failed AND raw copy failed: $(tr -d '\n' < "$PROBE_LOG"))"
    fi
    chmod 600 "$raw_db" || fail "Could not chmod 0600 raw safety copy"
    raw_copied=("$raw_db")
    for ext in -wal -shm -journal; do
      if [[ -e "$DB_FILE$ext" ]]; then
        if cp -- "$DB_FILE$ext" "$raw_base.sqlite$ext" 2>>"$PROBE_LOG"; then
          chmod 600 "$raw_base.sqlite$ext" || true
          raw_copied+=("$raw_base.sqlite$ext")
        else
          fail "Raw safety copy of sidecar $DB_FILE$ext failed: $(tr -d '\n' < "$PROBE_LOG")"
        fi
      fi
    done
    safety_backup="$raw_db"
    echo "Pre-restore safety copy (raw, live database was unreadable for VACUUM INTO): $raw_db"
    echo "  sidecars: ${raw_copied[*]:1}"
  fi
else
  echo "No existing database at $DB_FILE; skipping pre-restore safety copy."
fi

# 3. Remove stale SQLite sidecar files for the target database so the
#    replaced file does not inherit journal state from the previous DB.
for sidecar in "$DB_FILE-wal" "$DB_FILE-shm" "$DB_FILE-journal"; do
  if [[ -e "$sidecar" ]]; then
    rm -f -- "$sidecar" && echo "Removed stale sidecar: $sidecar"
  fi
done

# 4. Atomic replace within the live DB's directory (rename is atomic
#    within one filesystem; both source and destination live in
#    $DB_DIR so this is safe).
mv -f -- "$STAGED_COPY" "$DB_FILE" || fail "Could not move staged copy into place: $STAGED_COPY -> $DB_FILE"
unset STAGED_COPY

# 5. Apply owner / group / mode to the restored file. chmod is done
#    first because it always succeeds when we own the file. chown and
#    chgrp are skipped when the staged file already has the target
#    owner/group (the normal case when the operator runs as the owner
#    and the data/ directory is setgid). They are applied only when
#    they differ from the target and the current user has permission.
chmod "$TARGET_MODE" "$DB_FILE" || fail "Could not chmod $TARGET_MODE restored database: $DB_FILE"
restored_owner_uid="$(stat -c '%u' "$DB_FILE")"
restored_group_gid="$(stat -c '%g' "$DB_FILE")"
if [[ "$restored_group_gid" != "$TARGET_GID" ]]; then
  if ! chgrp "$TARGET_GID" "$DB_FILE" 2>/dev/null; then
    fail "Could not apply group GID $TARGET_GID to restored database: $DB_FILE"
  fi
fi
if [[ "$restored_owner_uid" != "$TARGET_UID" && "$(id -u)" == "0" ]]; then
  chown "$TARGET_UID" "$DB_FILE" || fail "Could not apply owner UID $TARGET_UID to restored database: $DB_FILE"
fi

# Print the resulting owner, group and mode.
resulting_owner_name="$(stat -c '%U' "$DB_FILE")"
resulting_group_name="$(stat -c '%G' "$DB_FILE")"
resulting_mode="$(stat -c '%a' "$DB_FILE")"
echo "Restored: $DB_FILE (owner=$resulting_owner_name group=$resulting_group_name mode=$resulting_mode)"
echo "Original backup file kept in place: $BACKUP_FILE (not consumed)"

# 6. Post-restore checks (best-effort; do not fail the restore if these
#    fail).
echo
echo "Post-restore version:"
FIN_DB_FILE="$DB_FILE" "$PHP_BIN" api.php version || true

echo
echo "Post-restore Master PIN status:"
FIN_DB_FILE="$DB_FILE" "$PHP_BIN" api.php master-pin-status || true

if [[ -n "$safety_backup" ]]; then
  echo
  echo "Both safety copy and original backup are kept:"
  echo "  safety copy: $safety_backup (kind: $safety_kind)"
  echo "  backup file: $BACKUP_FILE"
  echo "If this restore is unwanted, reverse it with:"
  echo "  scripts/restore-backup.sh $safety_backup"
fi

# 7. What a restore does NOT bring back.
cat <<'EOF'

What this restore does NOT bring back (it only replaces the SQLite database):
  - Master PIN hash file (config.php['auth']['master_pin_file']).
  - Master PIN throttle file (config.php['auth']['throttle_file']).
  - Local configuration under config.local.php (gitignored).
  - Raw statement files under import/ (already-imported files remain in
    import/imported/). Saved Import Profiles in statement_import_profiles
    are stored in the database and travel with the backup.
  - Any files outside data/ (e.g. application code, vendor/, node_modules/).

The backup is a single-file snapshot. Anything that lives in another file
on the same installation must be restored separately.
EOF