#!/usr/bin/env bash
set -Eeuo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
TEST_ROOT="$(mktemp -d)"
trap 'rm -rf "$TEST_ROOT"' EXIT
DB_FILE="$TEST_ROOT/fin.sqlite"
BACKUP_DIR="$TEST_ROOT/fin-backups"

cd "$ROOT_DIR"
FIN_DB_FILE="$DB_FILE" php -r '
  require "api.php";
  $db = db();
  migrate($db);
  $envelope = save_envelope($db, ["name" => "Archive test", "account" => "Test", "role" => "daily"]);
  for ($offset = 0; $offset < 14; $offset++) {
      $date = (new DateTimeImmutable("2025-01-01"))->modify("+$offset months");
      save_month($db, ["year" => (int)$date->format("Y"), "month" => (int)$date->format("n")]);
  }
  $month = (int)$db->query("SELECT id FROM months WHERE year = 2025 AND month = 1")->fetchColumn();
  $stmt = $db->prepare("INSERT INTO envelope_movements (envelope_id, month_id, type, amount, note, movement_date) VALUES (?, ?, '\''opening_balance'\'', 1234.5, '\''Starting balance'\'', '\''2025-01-01'\'')");
  $stmt->execute([$envelope, $month]);
  $account = save_account_definition($db, ["name" => "Archive asset", "account_number" => "ARCHIVE-1", "currency" => "CURRENCY"]);
  record_account_balance_observation($db, $account, 1000, "2025-01-01");
  save_transaction($db, ["month_id" => $month, "envelope_id" => $envelope, "payment_account_id" => $account, "amount" => 100, "spent_date" => "2025-01-10"]);

'

FIN_DB_FILE="$DB_FILE" php api.php archive-preview --keep-months=12 > "$TEST_ROOT/preview.json"
php -r '
  $preview = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
  if (!$preview["cleanup_required"] || $preview["months"] !== 2 || $preview["cutoff"] !== "2025-03-01") exit(1);
' "$TEST_ROOT/preview.json"

FIN_DB_FILE="$DB_FILE" FIN_BACKUP_DIR="$BACKUP_DIR" php api.php archive --keep-months=12 > "$TEST_ROOT/result.json"
backup_file="$(find "$BACKUP_DIR" -maxdepth 1 -type f -name 'fin-archive-before-*.sqlite' -print -quit)"
test -n "$backup_file"
test -f "$backup_file.manifest.json"
test "$(stat -c '%a' "$BACKUP_DIR")" = "700"
test "$(stat -c '%a' "$backup_file")" = "600"
test "$(stat -c '%a' "$backup_file.manifest.json")" = "600"

FIN_TEST_DB="$DB_FILE" FIN_TEST_BACKUP="$backup_file" php -r '
  $live = new PDO("sqlite:" . getenv("FIN_TEST_DB"));
  $archive = new PDO("sqlite:" . getenv("FIN_TEST_BACKUP"));
  if ((int)$live->query("SELECT COUNT(*) FROM months")->fetchColumn() !== 12) exit(1);
  if ((int)$archive->query("SELECT COUNT(*) FROM months")->fetchColumn() !== 14) exit(1);
  if ((float)$live->query("SELECT SUM(amount) FROM envelope_movements")->fetchColumn() !== 1134.5) exit(1);
  if ((int)$live->query("SELECT COUNT(*) FROM transactions")->fetchColumn() !== 0) exit(1);
  if ((int)$archive->query("SELECT COUNT(*) FROM transactions")->fetchColumn() !== 1) exit(1);
  if ((float)$live->query("SELECT SUM(amount) FROM account_movements")->fetchColumn() !== -100.0) exit(1);
  if ((float)$archive->query("SELECT SUM(amount) FROM account_movements")->fetchColumn() !== -100.0) exit(1);
  if ((int)$live->query("SELECT COUNT(*) FROM account_events WHERE event_type='\''expense'\'' AND transaction_id IS NULL")->fetchColumn() !== 1) exit(1);
  if ((int)$archive->query("SELECT COUNT(*) FROM account_events WHERE event_type='\''expense'\'' AND transaction_id IS NOT NULL")->fetchColumn() !== 1) exit(1);
  if ((int)$live->query("SELECT COUNT(*) FROM account_balance_observations WHERE balance=1000.0 AND effective_date='\''2025-01-01'\''")->fetchColumn() !== 1) exit(1);
  if ((string)$live->query("PRAGMA integrity_check")->fetchColumn() !== "ok") exit(1);
  if ((string)$archive->query("PRAGMA integrity_check")->fetchColumn() !== "ok") exit(1);
  $manifest = json_decode(file_get_contents(getenv("FIN_TEST_BACKUP") . ".manifest.json"), true, 512, JSON_THROW_ON_ERROR);
  if (!hash_equals($manifest["sha256"], hash_file("sha256", getenv("FIN_TEST_BACKUP")))) exit(1);
'

echo 'archive preview, verified backup, twelve-month cleanup, and balance rollup passed'
