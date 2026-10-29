#!/usr/bin/env bash
set -Eeuo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DB_FILE="$ROOT_DIR/data/fin.sqlite"
BACKUP_DIR="${FIN_BACKUP_DIR:-$HOME/fin-backups}"
PHP_BIN="${PHP_BIN:-php}"
COMPOSER_BIN="${COMPOSER_BIN:-composer}"

cd "$ROOT_DIR"

if ! git rev-parse --is-inside-work-tree >/dev/null 2>&1; then
  echo "Error: $ROOT_DIR is not a Git working tree." >&2
  exit 1
fi

if ! git diff --quiet || ! git diff --cached --quiet; then
  echo "Error: tracked files have local changes. Commit or discard them before updating." >&2
  exit 1
fi

if ! "$PHP_BIN" api.php master-pin-status --require >/dev/null; then
  echo "Error: configure the server-managed Master PIN before updating: sudo php api.php set-master-pin" >&2
  exit 1
fi

if [[ -f "$DB_FILE" ]]; then
  mkdir -p "$BACKUP_DIR"
  chmod 700 "$BACKUP_DIR"
  timestamp="$(date +%Y%m%d-%H%M%S)"
  backup_file="$BACKUP_DIR/fin-upgrade-$timestamp.sqlite"
  if [[ -e "$backup_file" ]]; then
    backup_file="$BACKUP_DIR/fin-upgrade-$timestamp-$$.sqlite"
  fi

  FIN_SOURCE_DB="$DB_FILE" FIN_TARGET_DB="$backup_file" "$PHP_BIN" -r '
    $source = getenv("FIN_SOURCE_DB");
    $target = getenv("FIN_TARGET_DB");
    if (!$source || !$target) {
        fwrite(STDERR, "Missing backup path.\n");
        exit(1);
    }
    $db = new PDO("sqlite:" . $source);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec("VACUUM INTO " . $db->quote($target));
  '
  chmod 600 "$backup_file"
  echo "Database backup created: $backup_file"
else
  echo "No existing database found; skipping backup."
fi

echo "Pulling latest code..."
git pull --ff-only

if [[ -f composer.json ]]; then
  if ! command -v "$COMPOSER_BIN" >/dev/null 2>&1; then
    echo "Error: Composer is required to install runtime dependencies." >&2
    exit 1
  fi
  echo "Installing runtime dependencies..."
  "$COMPOSER_BIN" install --no-dev --prefer-dist --no-interaction --optimize-autoloader
fi

echo "Applying database migrations..."
"$PHP_BIN" api.php migrate

echo "Installed versions:"
"$PHP_BIN" api.php version
