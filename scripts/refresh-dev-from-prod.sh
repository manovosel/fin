#!/usr/bin/env bash
set -Eeuo pipefail

umask 077

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DATA_DIR="$ROOT_DIR/data"
DEV_DB="$DATA_DIR/fin.sqlite"
BACKUP_DIR="${FIN_BACKUP_DIR:-${HOME:?Set HOME or FIN_BACKUP_DIR}/fin-backups}"
PHP_BIN="${PHP_BIN:-php}"
SSH_BIN="${FIN_SSH_BIN:-ssh}"
SCP_BIN="${FIN_SCP_BIN:-scp}"
PROD_SSH="${FIN_PROD_SSH:-}"
PROD_ROOT="${FIN_PROD_ROOT:-/var/www/html/fin}"
PROD_PHP="${FIN_PROD_PHP:-php}"
PROD_BACKUP_DIR="${FIN_PROD_BACKUP_DIR:-}"
TEST_MODE="${FIN_REFRESH_TEST_MODE:-0}"
TEST_FAIL_AT="${FIN_REFRESH_TEST_FAIL_AT:-}"

stage_file=""
probe_file=""
remote_snapshot=""
installed=0

fail() {
  echo "Error: $*" >&2
  exit 1
}

quote_remote() {
  printf '%q' "$1"
}

remote_remove_snapshot() {
  [[ -n "$remote_snapshot" ]] || return 0
  local quoted command
  quoted="$(quote_remote "$remote_snapshot")"
  command="rm -f -- $quoted"
  if "$SSH_BIN" -- "$PROD_SSH" "$command" >/dev/null; then
    remote_snapshot=""
    return 0
  fi
  echo "Warning: remote refresh snapshot cleanup failed: $remote_snapshot" >&2
  return 1
}

cleanup() {
  local status=$?
  [[ -z "$probe_file" || ! -e "$probe_file" ]] || rm -f -- "$probe_file" "$probe_file-wal" "$probe_file-shm" "$probe_file-journal"
  if [[ $installed -eq 0 && -n "$stage_file" && -e "$stage_file" ]]; then
    rm -f -- "$stage_file" "$stage_file-wal" "$stage_file-shm" "$stage_file-journal"
  fi
  if [[ -n "$remote_snapshot" ]]; then
    remote_remove_snapshot || true
  fi
  exit "$status"
}
trap cleanup EXIT

[[ $# -eq 0 ]] || fail "refresh takes no source or destination arguments"
[[ -n "$PROD_SSH" ]] || fail "set FIN_PROD_SSH to the production SSH source"
[[ "$PROD_SSH" != -* && "$PROD_SSH" != *$'\n'* && "$PROD_SSH" != *$'\r'* && "$PROD_SSH" != *[[:space:]]* ]] || fail "FIN_PROD_SSH is invalid"
[[ "$PROD_ROOT" = /* && "$PROD_ROOT" != *$'\n'* && "$PROD_ROOT" != *$'\r'* ]] || fail "FIN_PROD_ROOT must be an absolute remote path"
[[ "$PROD_PHP" =~ ^[A-Za-z0-9_./-]+$ ]] || fail "FIN_PROD_PHP must be a command name or absolute path without shell syntax"
if [[ -n "$PROD_BACKUP_DIR" ]]; then
  [[ "$PROD_BACKUP_DIR" = /* && "$PROD_BACKUP_DIR" != *$'\n'* && "$PROD_BACKUP_DIR" != *$'\r'* ]] || fail "FIN_PROD_BACKUP_DIR must be an absolute remote path"
fi
[[ "$ROOT_DIR" != "$PROD_ROOT" ]] || fail "refusing to run from the configured production root"
[[ -f "$ROOT_DIR/config.local.php" ]] || fail "development config.local.php is required"
[[ ! -L "$ROOT_DIR/config.local.php" ]] || fail "development config.local.php cannot be a symbolic link"
[[ -f "$DEV_DB" && ! -L "$DEV_DB" ]] || fail "current development database is required and cannot be a symbolic link"
[[ -d "$DATA_DIR" && ! -L "$DATA_DIR" ]] || fail "development data directory is invalid"

cd "$ROOT_DIR"
git rev-parse --is-inside-work-tree >/dev/null 2>&1 || fail "development checkout must be a Git working tree"
git check-ignore -q config.local.php || fail "config.local.php must remain ignored by Git"
git check-ignore -q data/fin.sqlite || fail "development runtime database must remain ignored by Git"

command -v "$PHP_BIN" >/dev/null 2>&1 || fail "PHP CLI is required"
command -v "$SSH_BIN" >/dev/null 2>&1 || fail "SSH transport is required"
command -v "$SCP_BIN" >/dev/null 2>&1 || fail "SCP transport is required"
command -v sha256sum >/dev/null 2>&1 || fail "sha256sum is required"

if [[ "$TEST_MODE" == "1" ]]; then
  fake_dir="${FIN_REFRESH_FAKE_TRANSPORT_DIR:-}"
  [[ -n "$fake_dir" && "$fake_dir" = /* ]] || fail "test mode requires FIN_REFRESH_FAKE_TRANSPORT_DIR"
  ssh_real="$(realpath "$(command -v "$SSH_BIN")")"
  scp_real="$(realpath "$(command -v "$SCP_BIN")")"
  fake_real="$(realpath "$fake_dir")"
  [[ "$ssh_real" == "$fake_real/"* && "$scp_real" == "$fake_real/"* ]] || fail "test mode refuses non-fake SSH/SCP transport"
elif [[ -n "$TEST_FAIL_AT" ]]; then
  fail "failure injection is available only in test mode"
fi

origin="$($PHP_BIN -r 'require "api.php"; echo (string)(app_config()["webauthn_origin"] ?? "");')"
origin_host="$(FIN_REFRESH_ORIGIN="$origin" "$PHP_BIN" -r '
$origin = getenv("FIN_REFRESH_ORIGIN") ?: "";
$host = parse_url($origin, PHP_URL_HOST);
if (!is_string($host) || $host === "") exit(1);
echo strtolower($host);
')" || fail "development WebAuthn origin is invalid"
case "$origin_host" in
  localhost|127.*|::1) ;;
  *) fail "refusing refresh without an explicit loopback development WebAuthn origin" ;;
esac

if ! "$PHP_BIN" api.php master-pin-status --require >/dev/null; then
  fail "development Master PIN configuration is not ready"
fi

if command -v fuser >/dev/null 2>&1; then
  if fuser "$DEV_DB" >/dev/null 2>&1; then
    fail "development database is open; stop the development runtime before refresh"
  fi
elif command -v lsof >/dev/null 2>&1; then
  if lsof "$DEV_DB" >/dev/null 2>&1; then
    fail "development database is open; stop the development runtime before refresh"
  fi
else
  fail "cannot verify development writer safety because neither fuser nor lsof is available"
fi

if [[ "${FIN_REFRESH_CONFIRM:-}" != "REPLACE_DEV" ]]; then
  [[ -t 0 ]] || fail "set FIN_REFRESH_CONFIRM=REPLACE_DEV for non-interactive destructive refresh"
  printf 'Replace the disposable DEV database from PROD source %s? Type REPLACE DEV: ' "$PROD_SSH" >&2
  read -r confirmation
  [[ "$confirmation" == "REPLACE DEV" ]] || fail "refresh cancelled"
fi

LOCAL_DB_TOOL=$(cat <<'PHP'
require "api.php";

function refresh_open_database(string $path, bool $writable = false): PDO
{
    if ($path === "" || !is_file($path) || is_link($path)) throw new RuntimeException("Refresh database path is invalid");
    $db = new PDO("sqlite:" . $path);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    if (!$writable) $db->exec("PRAGMA query_only = ON");
    return $db;
}

function refresh_quote_identifier(string $name): string
{
    return "\"" . str_replace("\"", "\"\"", $name) . "\"";
}

function refresh_tables(PDO $db): array
{
    return $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
}

function refresh_validate_auth(PDO $db): void
{
    $tables = refresh_tables($db);
    foreach (["auth_principal", "webauthn_credentials"] as $required) {
        if (!in_array($required, $tables, true)) throw new RuntimeException("Authentication schema is missing");
    }
    foreach ($tables as $table) {
        $foreignKeys = $db->query("PRAGMA foreign_key_list(" . refresh_quote_identifier((string)$table) . ")")->fetchAll();
        if (in_array($table, ["auth_principal", "webauthn_credentials"], true) && $foreignKeys !== []) {
            throw new RuntimeException("Authentication tables must remain financially independent");
        }
        foreach ($foreignKeys as $foreignKey) {
            if (in_array((string)$foreignKey["table"], ["auth_principal", "webauthn_credentials"], true)) {
                throw new RuntimeException("A domain table depends on authentication state");
            }
        }
    }
    $principals = $db->query("SELECT id,length(user_handle) AS handle_length FROM auth_principal")->fetchAll();
    if (count($principals) !== 1 || (int)$principals[0]["id"] !== 1 || (int)$principals[0]["handle_length"] !== 32) {
        throw new RuntimeException("Development authentication principal is invalid");
    }
}

function refresh_encode_value(mixed $value): string
{
    if ($value === null) return "N";
    if (is_int($value)) return "I" . $value;
    if (is_float($value)) return "F" . sprintf("%.17g", $value);
    return "S" . base64_encode((string)$value);
}

function refresh_fingerprint(PDO $db, bool $auth): string
{
    $context = hash_init("sha256");
    foreach (refresh_tables($db) as $table) {
        $isAuth = in_array($table, ["auth_principal", "webauthn_credentials"], true);
        if ($isAuth !== $auth) continue;
        $sql = (string)$db->query("SELECT sql FROM sqlite_master WHERE type='table' AND name=" . $db->quote((string)$table))->fetchColumn();
        hash_update($context, "T" . $table . "\0" . $sql . "\0");
        $query = "SELECT * FROM " . refresh_quote_identifier((string)$table) . " ORDER BY rowid";
        foreach ($db->query($query) as $row) {
            hash_update($context, "R");
            foreach ($row as $column => $value) hash_update($context, (string)$column . "=" . refresh_encode_value($value) . "\0");
        }
    }
    return hash_final($context);
}

function refresh_verify(PDO $db): array
{
    $integrity = (string)$db->query("PRAGMA integrity_check")->fetchColumn();
    if (strtolower($integrity) !== "ok") throw new RuntimeException("SQLite integrity check failed");
    if ($db->query("PRAGMA foreign_key_check")->fetch()) throw new RuntimeException("SQLite foreign-key check failed");
    $tables = refresh_tables($db);
    foreach (["schema_migrations", "months", "envelopes", "entries", "transactions", "accounts"] as $required) {
        if (!in_array($required, $tables, true)) throw new RuntimeException("Expected FIN core schema is missing");
    }
    refresh_validate_auth($db);
    return [
        "database_version" => database_version($db),
        "domain_fingerprint" => refresh_fingerprint($db, false),
        "auth_fingerprint" => refresh_fingerprint($db, true),
        "credential_count" => (int)$db->query("SELECT COUNT(*) FROM webauthn_credentials")->fetchColumn(),
    ];
}

$operation = getenv("FIN_REFRESH_OPERATION") ?: "";
if ($operation === "inspect") {
    $facts = refresh_verify(refresh_open_database(getenv("FIN_REFRESH_DB") ?: ""));
    $expectedAuth = getenv("FIN_REFRESH_EXPECT_AUTH") ?: "";
    $expectedDomain = getenv("FIN_REFRESH_EXPECT_DOMAIN") ?: "";
    if ($expectedAuth !== "" && !hash_equals($expectedAuth, $facts["auth_fingerprint"])) throw new RuntimeException("Preserved DEV authentication state does not match");
    if ($expectedDomain !== "" && !hash_equals($expectedDomain, $facts["domain_fingerprint"])) throw new RuntimeException("Installed financial/domain state does not match staged state");
    echo json_encode($facts, JSON_UNESCAPED_SLASHES), PHP_EOL;
    exit(0);
}
if ($operation === "backup") {
    create_sqlite_backup(getenv("FIN_REFRESH_SOURCE_DB") ?: "", getenv("FIN_REFRESH_TARGET_DB") ?: "");
    exit(0);
}
if ($operation === "preserve-auth") {
    $devPath = getenv("FIN_REFRESH_DEV_DB") ?: "";
    $stagePath = getenv("FIN_REFRESH_STAGE_DB") ?: "";
    $dev = refresh_open_database($devPath);
    $stage = refresh_open_database($stagePath, true);
    refresh_validate_auth($dev);
    refresh_validate_auth($stage);
    foreach (["auth_principal", "webauthn_credentials"] as $table) {
        $devColumns = $dev->query("PRAGMA table_info(" . refresh_quote_identifier($table) . ")")->fetchAll();
        $stageColumns = $stage->query("PRAGMA table_info(" . refresh_quote_identifier($table) . ")")->fetchAll();
        if ($devColumns !== $stageColumns) throw new RuntimeException("DEV and candidate authentication schemas differ");
    }
    $expectedAuth = refresh_fingerprint($dev, true);
    $domainBefore = refresh_fingerprint($stage, false);
    $stage->exec("PRAGMA foreign_keys = ON");
    $stage->exec("ATTACH DATABASE " . $stage->quote($devPath) . " AS dev_auth");
    try {
        $stage->beginTransaction();
        $stage->exec("DELETE FROM webauthn_credentials");
        $stage->exec("DELETE FROM auth_principal");
        $stage->exec("INSERT INTO auth_principal (id,user_handle,created_at) SELECT id,user_handle,created_at FROM dev_auth.auth_principal");
        $stage->exec("INSERT INTO webauthn_credentials (id,credential_id,public_key,sign_count,transports,label,created_at,last_used_at,revoked_at) SELECT id,credential_id,public_key,sign_count,transports,label,created_at,last_used_at,revoked_at FROM dev_auth.webauthn_credentials ORDER BY id");
        $stage->exec("DELETE FROM sqlite_sequence WHERE name='webauthn_credentials'");
        $devSequence = $stage->query("SELECT seq FROM dev_auth.sqlite_sequence WHERE name='webauthn_credentials'")->fetchColumn();
        if ($devSequence !== false) {
            $sequence = $stage->prepare("INSERT INTO sqlite_sequence(name,seq) VALUES ('webauthn_credentials',:seq)");
            $sequence->execute([":seq" => (int)$devSequence]);
        }
        $stage->commit();
    } catch (Throwable $error) {
        if ($stage->inTransaction()) $stage->rollBack();
        throw $error;
    } finally {
        $stage->exec("DETACH DATABASE dev_auth");
    }
    refresh_validate_auth($stage);
    $actualAuth = refresh_fingerprint($stage, true);
    if (!hash_equals($expectedAuth, $actualAuth)) throw new RuntimeException("DEV authentication restore verification failed");
    if (!hash_equals($domainBefore, refresh_fingerprint($stage, false))) throw new RuntimeException("Authentication restore altered financial/domain data");
    echo json_encode(["auth_fingerprint" => $actualAuth], JSON_UNESCAPED_SLASHES), PHP_EOL;
    exit(0);
}
throw new RuntimeException("Unknown local refresh operation");
PHP
)

json_values() {
  local json="$1" mode="$2"
  FIN_REFRESH_JSON="$json" FIN_REFRESH_JSON_MODE="$mode" "$PHP_BIN" -r '
$data = json_decode(getenv("FIN_REFRESH_JSON") ?: "", true, 512, JSON_THROW_ON_ERROR);
$mode = getenv("FIN_REFRESH_JSON_MODE");
$keys = $mode === "remote"
    ? ["snapshot_file", "sha256", "app_version", "database_version"]
    : ($mode === "facts" ? ["database_version", "domain_fingerprint", "auth_fingerprint", "credential_count"] : ["auth_fingerprint"]);
foreach ($keys as $key) {
    if (!array_key_exists($key, $data) || !is_scalar($data[$key])) exit(1);
    echo (string)$data[$key], PHP_EOL;
}
'
}

inspect_db() {
  local path="$1" expected_auth="${2:-}" expected_domain="${3:-}"
  FIN_REFRESH_OPERATION=inspect FIN_REFRESH_DB="$path" FIN_REFRESH_EXPECT_AUTH="$expected_auth" FIN_REFRESH_EXPECT_DOMAIN="$expected_domain" \
    "$PHP_BIN" -r "$LOCAL_DB_TOOL"
}

parse_version_output() {
  local output="$1"
  if [[ "$output" =~ ^FIN\ Planner\ v([^[:space:]]+)\ /\ database\ v([^[:space:]]+)$ ]]; then
    printf '%s\n%s\n' "${BASH_REMATCH[1]}" "${BASH_REMATCH[2]}"
    return 0
  fi
  return 1
}

version_is_newer() {
  FIN_REFRESH_LEFT="$1" FIN_REFRESH_RIGHT="$2" "$PHP_BIN" -r 'exit(version_compare(getenv("FIN_REFRESH_LEFT"), getenv("FIN_REFRESH_RIGHT"), ">") ? 0 : 1);'
}

mkdir -p "$DATA_DIR"
probe_file="$(mktemp "$DATA_DIR/.fin-refresh-probe.XXXXXX.sqlite")"
chmod 600 "$probe_file"
FIN_DB_FILE="$probe_file" "$PHP_BIN" api.php migrate >/dev/null
probe_version_output="$(FIN_DB_FILE="$probe_file" "$PHP_BIN" api.php version)"
mapfile -t probe_versions < <(parse_version_output "$probe_version_output")
[[ ${#probe_versions[@]} -eq 2 ]] || fail "candidate version output could not be verified"
candidate_app_version="${probe_versions[0]}"
candidate_db_version="${probe_versions[1]}"

initial_dev_json="$(inspect_db "$DEV_DB")" || fail "current DEV database/authentication validation failed"
mapfile -t initial_dev < <(json_values "$initial_dev_json" facts)
[[ ${#initial_dev[@]} -eq 4 ]] || fail "current DEV database facts could not be read"
old_dev_domain_fingerprint="${initial_dev[1]}"
dev_auth_fingerprint="${initial_dev[2]}"

remote_root_q="$(quote_remote "$PROD_ROOT")"
remote_db_q="$(quote_remote "$PROD_ROOT/data/fin.sqlite")"
remote_php_q="$(quote_remote "$PROD_PHP")"
remote_command="cd $remote_root_q && FIN_DB_FILE=$remote_db_q $remote_php_q api.php refresh-snapshot"
if [[ -n "$PROD_BACKUP_DIR" ]]; then
  remote_backup_q="$(quote_remote "--backup-dir=$PROD_BACKUP_DIR")"
  remote_command+=" $remote_backup_q"
fi

remote_output="$($SSH_BIN -- "$PROD_SSH" "$remote_command")" || fail "production snapshot command failed"
remote_json="$(FIN_REFRESH_REMOTE_OUTPUT="$remote_output" "$PHP_BIN" -r '
$lines = preg_split("/\R/", getenv("FIN_REFRESH_REMOTE_OUTPUT") ?: "");
for ($index = count($lines) - 1; $index >= 0; $index--) {
    $value = json_decode(trim((string)$lines[$index]), true);
    if (is_array($value) && isset($value["snapshot_file"], $value["sha256"], $value["app_version"], $value["database_version"])) {
        echo json_encode($value, JSON_UNESCAPED_SLASHES);
        exit(0);
    }
}
exit(1);
')" || fail "production snapshot metadata was invalid"
mapfile -t remote_meta < <(json_values "$remote_json" remote)
[[ ${#remote_meta[@]} -eq 4 ]] || fail "production snapshot metadata was incomplete"
remote_snapshot="${remote_meta[0]}"
remote_checksum="${remote_meta[1]}"
source_app_version="${remote_meta[2]}"
source_db_version="${remote_meta[3]}"
[[ "$remote_snapshot" = /* && "$(basename "$remote_snapshot")" =~ ^fin-prod-dev-refresh-[0-9]{8}-[0-9]{6}-[a-f0-9]{12}\.sqlite$ ]] || fail "production snapshot path was unsafe"
[[ "$remote_checksum" =~ ^[a-f0-9]{64}$ ]] || fail "production snapshot checksum was invalid"

stage_file="$(mktemp "$DATA_DIR/.fin-refresh-stage.XXXXXX.sqlite")"
chmod 600 "$stage_file"
git check-ignore -q "data/$(basename "$stage_file")" || fail "refresh staging path must remain ignored by Git"
"$SCP_BIN" -- "$PROD_SSH:$remote_snapshot" "$stage_file" || fail "production snapshot download failed"
[[ -f "$stage_file" && ! -L "$stage_file" ]] || fail "downloaded production snapshot is not a regular local file"
chmod 600 "$stage_file"
local_checksum="$(sha256sum "$stage_file" | awk '{print $1}')"
[[ "$local_checksum" == "$remote_checksum" ]] || fail "production snapshot checksum mismatch"
remote_remove_snapshot || fail "production snapshot could not be removed after download"

source_json="$(inspect_db "$stage_file")" || fail "downloaded production snapshot validation failed"
mapfile -t source_facts < <(json_values "$source_json" facts)
[[ ${#source_facts[@]} -eq 4 ]] || fail "downloaded production snapshot facts could not be read"
[[ "${source_facts[0]}" == "$source_db_version" ]] || fail "production snapshot DB version does not match remote metadata"
if version_is_newer "$source_db_version" "$candidate_db_version"; then
  fail "production DB v$source_db_version is newer than candidate DB v$candidate_db_version"
fi

if [[ "$TEST_MODE" == "1" && "$TEST_FAIL_AT" == "migration" ]]; then
  fail "injected candidate migration failure"
fi
FIN_DB_FILE="$stage_file" "$PHP_BIN" api.php migrate >/dev/null || fail "candidate migration failed"
if [[ "$TEST_MODE" == "1" && "$TEST_FAIL_AT" == "post-migration-integrity" ]]; then
  printf 'not-a-sqlite-database\n' > "$stage_file"
fi
if [[ "$TEST_MODE" == "1" && "$TEST_FAIL_AT" == "target-version" ]]; then
  FIN_REFRESH_DB="$stage_file" "$PHP_BIN" -r '$db=new PDO("sqlite:".getenv("FIN_REFRESH_DB"));$db->exec("INSERT INTO schema_migrations(version,name) VALUES (\"99.0\",\"injected\")");'
fi

stage_version_output="$(FIN_DB_FILE="$stage_file" "$PHP_BIN" api.php version)" || fail "candidate version command failed"
mapfile -t stage_versions < <(parse_version_output "$stage_version_output")
[[ ${#stage_versions[@]} -eq 2 ]] || fail "candidate staged version output could not be verified"
[[ "${stage_versions[0]}" == "$candidate_app_version" && "${stage_versions[1]}" == "$candidate_db_version" ]] || fail "staged DB did not reach the candidate app/DB version"

auth_restore_json="$(FIN_REFRESH_OPERATION=preserve-auth FIN_REFRESH_DEV_DB="$DEV_DB" FIN_REFRESH_STAGE_DB="$stage_file" "$PHP_BIN" -r "$LOCAL_DB_TOOL")" || fail "DEV authentication preservation failed"
mapfile -t restored_auth < <(json_values "$auth_restore_json" auth)
[[ ${#restored_auth[@]} -eq 1 && "${restored_auth[0]}" == "$dev_auth_fingerprint" ]] || fail "DEV authentication preservation did not match"

stage_json="$(inspect_db "$stage_file" "$dev_auth_fingerprint")" || fail "post-migration staged database verification failed"
mapfile -t stage_facts < <(json_values "$stage_json" facts)
[[ ${#stage_facts[@]} -eq 4 ]] || fail "staged database facts could not be read"
[[ "${stage_facts[0]}" == "$candidate_db_version" ]] || fail "staged target DB version mismatch"
stage_domain_fingerprint="${stage_facts[1]}"

if [[ -L "$BACKUP_DIR" || ( -e "$BACKUP_DIR" && ! -d "$BACKUP_DIR" ) ]]; then
  fail "DEV backup path is not a secure directory"
fi
mkdir -p "$BACKUP_DIR" || fail "could not create DEV backup directory"
chmod 700 "$BACKUP_DIR" || fail "could not secure DEV backup directory"
[[ "$(stat -c '%a' "$BACKUP_DIR")" == "700" ]] || fail "DEV backup directory permissions are not 0700"
backup_real="$(realpath "$BACKUP_DIR")"
case "$backup_real" in
  "$ROOT_DIR"|"$ROOT_DIR"/*) fail "DEV backups must be stored outside the FIN checkout" ;;
esac
timestamp="$(date -u +%Y%m%d-%H%M%S)"
old_dev_backup="$BACKUP_DIR/fin-dev-before-prod-refresh-$timestamp.sqlite"
[[ ! -e "$old_dev_backup" ]] || old_dev_backup="$BACKUP_DIR/fin-dev-before-prod-refresh-$timestamp-$$.sqlite"
FIN_REFRESH_OPERATION=backup FIN_REFRESH_SOURCE_DB="$DEV_DB" FIN_REFRESH_TARGET_DB="$old_dev_backup" "$PHP_BIN" -r "$LOCAL_DB_TOOL" || fail "old DEV database backup failed"
chmod 600 "$old_dev_backup"
[[ "$(stat -c '%a' "$old_dev_backup")" == "600" ]] || fail "old DEV backup permissions are not 0600"
inspect_db "$old_dev_backup" "$dev_auth_fingerprint" "$old_dev_domain_fingerprint" >/dev/null || fail "old DEV database backup verification failed"

if command -v fuser >/dev/null 2>&1; then
  if fuser "$DEV_DB" >/dev/null 2>&1; then
    fail "development database became active before replacement"
  fi
elif lsof "$DEV_DB" >/dev/null 2>&1; then
  fail "development database became active before replacement"
fi

rm -f -- "$DEV_DB-wal" "$DEV_DB-shm" "$DEV_DB-journal"
chmod 600 "$stage_file"
if [[ "$TEST_MODE" == "1" && "$TEST_FAIL_AT" == "replace" ]]; then
  fail "injected atomic replacement failure"
fi
mv -f -- "$stage_file" "$DEV_DB" || fail "atomic DEV database replacement failed"
stage_file=""
installed=1
chmod 600 "$DEV_DB"

final_verify() {
  if [[ "$TEST_MODE" == "1" && "$TEST_FAIL_AT" == "final-verify" ]]; then
    return 1
  fi
  local version_output
  version_output="$(FIN_DB_FILE="$DEV_DB" "$PHP_BIN" api.php version)" || return 1
  local versions=()
  mapfile -t versions < <(parse_version_output "$version_output")
  [[ ${#versions[@]} -eq 2 && "${versions[0]}" == "$candidate_app_version" && "${versions[1]}" == "$candidate_db_version" ]] || return 1
  inspect_db "$DEV_DB" "$dev_auth_fingerprint" "$stage_domain_fingerprint" >/dev/null || return 1
  [[ "$(stat -c '%a' "$DEV_DB")" == "600" ]] || return 1
}

rollback_dev() {
  local failed_path rollback_stage
  failed_path="$BACKUP_DIR/fin-dev-refresh-failed-$timestamp.sqlite"
  [[ ! -e "$failed_path" ]] || failed_path="$BACKUP_DIR/fin-dev-refresh-failed-$timestamp-$$.sqlite"
  mv -f -- "$DEV_DB" "$failed_path" || return 1
  chmod 600 "$failed_path"
  rollback_stage="$(mktemp "$DATA_DIR/.fin-refresh-rollback.XXXXXX.sqlite")"
  rm -f -- "$rollback_stage"
  FIN_REFRESH_OPERATION=backup FIN_REFRESH_SOURCE_DB="$old_dev_backup" FIN_REFRESH_TARGET_DB="$rollback_stage" "$PHP_BIN" -r "$LOCAL_DB_TOOL" || return 1
  chmod 600 "$rollback_stage"
  rm -f -- "$DEV_DB-wal" "$DEV_DB-shm" "$DEV_DB-journal"
  mv -f -- "$rollback_stage" "$DEV_DB" || return 1
  chmod 600 "$DEV_DB"
  inspect_db "$DEV_DB" "$dev_auth_fingerprint" "$old_dev_domain_fingerprint" >/dev/null || return 1
  echo "Failed candidate retained: $failed_path" >&2
}

if ! final_verify; then
  if rollback_dev; then
    fail "final DEV verification failed; previous DEV database restored"
  fi
  fail "final DEV verification failed and automatic rollback failed; use backup $old_dev_backup"
fi

installed=1

echo "Source version: FIN v$source_app_version / database v$source_db_version"
echo "Candidate version: FIN v$candidate_app_version / database v$candidate_db_version"
echo "Checksum: verified"
echo "Integrity: OK"
echo "Foreign keys: OK"
echo "DEV authentication: preserved"
echo "Old DEV backup: $old_dev_backup"
echo "DEV database: $DEV_DB"
echo "Reload or reopen DEV before authentication verification."
echo "READY"
