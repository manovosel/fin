#!/usr/bin/env bash
set -Eeuo pipefail

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
TEST_ROOT="$(mktemp -d "${TMPDIR:-/tmp}/fin-update-test.XXXXXX")"
trap 'rm -rf "$TEST_ROOT"' EXIT

APP_DIR="$TEST_ROOT/app"
HOME_DIR="$TEST_ROOT/home"
REMOTE_DIR="$TEST_ROOT/remote.git"
mkdir -p "$APP_DIR/scripts" "$APP_DIR/data" "$HOME_DIR" "$TEST_ROOT/bin"
cp "$PROJECT_ROOT/api.php" "$PROJECT_ROOT/report.php" "$PROJECT_ROOT/recommendation.php" "$PROJECT_ROOT/accounting.php" "$PROJECT_ROOT/statement_import.php" "$PROJECT_ROOT/statement_reconciliation.php" "$PROJECT_ROOT/auth.php" "$PROJECT_ROOT/config.php" "$PROJECT_ROOT/currency.php" "$PROJECT_ROOT/ecb_rates.php" "$PROJECT_ROOT/VERSION" "$PROJECT_ROOT/composer.json" "$PROJECT_ROOT/composer.lock" "$APP_DIR/"
cp "$PROJECT_ROOT/scripts/update.sh" "$APP_DIR/scripts/"
cat > "$TEST_ROOT/bin/composer" <<'EOF'
#!/usr/bin/env bash
printf '%s\n' "$*" >> "${FIN_TEST_COMPOSER_LOG:?}"
EOF
chmod +x "$TEST_ROOT/bin/composer"
printf 'data/*\nconfig.local.php\n' > "$APP_DIR/.gitignore"
printf 'test\n' > "$APP_DIR/README.md"

cd "$APP_DIR"
git init -q
git config user.name 'FIN test'
git config user.email 'fin-test@example.invalid'
git add .
git commit -qm 'test release'
git init -q --bare "$REMOTE_DIR"
git remote add origin "$REMOTE_DIR"
git push -qu -u origin HEAD

cat > config.local.php <<EOF
<?php
return [
    'auth' => [
        'master_pin_file' => '$TEST_ROOT/master-pin.hash',
        'throttle_file' => '$TEST_ROOT/throttle.json',
        'file_group' => '',
        'pin_failure_delay_us' => 0,
    ],
];
EOF
FIN_DB_FILE="$APP_DIR/data/fin.sqlite" php api.php migrate >/dev/null
EXPECTED_APP_VERSION="$(< "$APP_DIR/VERSION")"
EXPECTED_DB_VERSION="$(FIN_DB_FILE="$APP_DIR/data/fin.sqlite" php api.php version | awk -F'database v' 'NF > 1 { print $2 }')"
test -n "$EXPECTED_APP_VERSION"
test -n "$EXPECTED_DB_VERSION"
if HOME="$HOME_DIR" COMPOSER_BIN="$TEST_ROOT/bin/composer" FIN_TEST_COMPOSER_LOG="$TEST_ROOT/composer.log" ./scripts/update.sh >"$TEST_ROOT/missing-pin.log" 2>&1; then
  echo 'update.sh should reject a missing Master PIN' >&2
  exit 1
fi
grep -q 'configure the server-managed Master PIN' "$TEST_ROOT/missing-pin.log"
test ! -d "$HOME_DIR/fin-backups"
printf '12345678\n12345678\n' | php api.php set-master-pin --stdin >/dev/null
printf 'dirty\n' >> README.md
if HOME="$HOME_DIR" COMPOSER_BIN="$TEST_ROOT/bin/composer" FIN_TEST_COMPOSER_LOG="$TEST_ROOT/composer.log" ./scripts/update.sh >"$TEST_ROOT/dirty.log" 2>&1; then
  echo 'update.sh should reject tracked local changes' >&2
  exit 1
fi
grep -q 'tracked files have local changes' "$TEST_ROOT/dirty.log"
git checkout -- README.md

HOME="$HOME_DIR" COMPOSER_BIN="$TEST_ROOT/bin/composer" FIN_TEST_COMPOSER_LOG="$TEST_ROOT/composer.log" ./scripts/update.sh >"$TEST_ROOT/update.log"
grep -q '^install --no-dev --prefer-dist --no-interaction --optimize-autoloader$' "$TEST_ROOT/composer.log"
grep -q "Database migrated to v$EXPECTED_DB_VERSION" "$TEST_ROOT/update.log"
grep -q "FIN Planner v$EXPECTED_APP_VERSION / database v$EXPECTED_DB_VERSION" "$TEST_ROOT/update.log"
backup_file="$(find "$HOME_DIR/fin-backups" -type f -name 'fin-upgrade-*.sqlite' -print -quit)"
test -n "$backup_file"

FIN_TEST_BACKUP="$backup_file" FIN_TEST_EXPECTED_DB_VERSION="$EXPECTED_DB_VERSION" php -r '
  $db = new PDO("sqlite:" . getenv("FIN_TEST_BACKUP"));
  $versions = $db->query("SELECT version FROM schema_migrations")->fetchAll(PDO::FETCH_COLUMN);
  usort($versions, "version_compare");
  $version = (string)end($versions);
  if ($version !== getenv("FIN_TEST_EXPECTED_DB_VERSION")) {
      fwrite(STDERR, "Backup database is invalid\n");
      exit(1);
  }
'

echo 'update.sh backup, safety check, pull, and migration passed'
